<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
  echo json_encode(['success' => false, 'message' => 'Неавторизованный доступ.']);
  exit;
}

require_once '../config/config.php'; 
$dbconn = db_connect();
if (!$dbconn) {
  echo json_encode(['success' => false, 'message' => 'Ошибка подключения к базе данных.']);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['success' => false, 'message' => 'Неверный метод запроса.']);
  pg_close($dbconn);
  exit;
}

$room_number = $_POST['room_number'] ?? null;
$room_type_id = $_POST['room_type_id'] ?? null;
$capacity = $_POST['capacity'] ?? null;
$floor = $_POST['floor'] ?? null;
$area = $_POST['area'] ?? null;
$status_key = $_POST['status-select'] ?? null;
$base_price_raw = $_POST['base_price'] ?? null;

if (!$room_number || !$room_type_id || !$status_key) {
  echo json_encode(['success' => false, 'message' => 'Недостаточно данных для обновления.']);
  pg_close($dbconn);
  exit;
}

$room_type_id = (int)$room_type_id;
$capacity = (int)$capacity;
$floor = (int)$floor;
$area = is_numeric($area) ? (float)$area : null;
$base_price = is_numeric($base_price_raw) ? (float)$base_price_raw : null;

$STATUSES_MAP_REVERSE = [
  'Свободен' => 'free',
  'Забронирован' => 'booked',
  'Занят' => 'busy',
  'На ремонте' => 'repair',
  'Убирается' => 'cleaning',
  'Готов к заселению' => 'ready',
  'Нуждается в уборке' => 'need-cleaning',
];
$STATUSES_MAP_NORMAL = array_flip($STATUSES_MAP_REVERSE);
$status_name_for_db = $STATUSES_MAP_NORMAL[$status_key] ?? 'Свободен';


pg_query($dbconn, "BEGIN");

$update_type_sql = "
  UPDATE room_types
  SET 
    capacity = $1,
    base_price = $2
  WHERE room_type_id = $3
";
$update_type_params = array($capacity, $base_price, $room_type_id);

$type_update_success = pg_query_params($dbconn, $update_type_sql, $update_type_params);

if (!$type_update_success) {
  pg_query($dbconn, "ROLLBACK");
  echo json_encode(['success' => false, 'message' => 'Ошибка обновления типа номера: ' . pg_last_error($dbconn)]);
  pg_close($dbconn);
  exit;
}

$update_room_sql = "
  UPDATE rooms
  SET 
    room_type_id = $1,
    status = $2,
    floor = $3,
    area = $4
  WHERE room_number = $5
";
$update_room_params = array(
  $room_type_id, 
  $status_name_for_db,
  $floor,
  $area,
  $room_number
);

$room_update_success = pg_query_params($dbconn, $update_room_sql, $update_room_params);

if (!$room_update_success) {
  pg_query($dbconn, "ROLLBACK");
  echo json_encode(['success' => false, 'message' => 'Ошибка обновления данных номера: ' . pg_last_error($dbconn)]);
  pg_close($dbconn);
  exit;
}

$query_amenity_ids = "SELECT amenity_id, amenity_name FROM amenities";
$amenity_id_result = pg_query($dbconn, $query_amenity_ids);
$amenity_map = [];
if ($amenity_id_result) {
  while ($row = pg_fetch_assoc($amenity_id_result)) {
    $amenity_map[$row['amenity_name']] = $row['amenity_id'];
  }
}

$delete_amenities_sql = "DELETE FROM room_amenities WHERE room_number = $1";
$delete_success = pg_query_params($dbconn, $delete_amenities_sql, array($room_number));

if (!$delete_success) {
  pg_query($dbconn, "ROLLBACK");
  echo json_encode(['success' => false, 'message' => 'Ошибка очистки удобств: ' . pg_last_error($dbconn)]);
  pg_close($dbconn);
  exit;
}

$insert_success = true;
foreach ($amenity_map as $amenity_name => $amenity_id) {
  $post_key = 'amenity_' . $amenity_name;
  
  if (isset($_POST[$post_key]) && $_POST[$post_key] === 'on') {
    $insert_amenity_sql = "
      INSERT INTO room_amenities (room_number, amenity_id)
      VALUES ($1, $2)
    ";
    $insert_amenity_params = array($room_number, $amenity_id);
    
    $insert_result = pg_query_params($dbconn, $insert_amenity_sql, $insert_amenity_params);
    
    if (!$insert_result) {
      $insert_success = false;
      break; 
    }
  }
}

if (!$insert_success) {
  pg_query($dbconn, "ROLLBACK");
  echo json_encode(['success' => false, 'message' => 'Ошибка добавления удобств: ' . pg_last_error($dbconn)]);
  pg_close($dbconn);
  exit;
}

pg_query($dbconn, "COMMIT");

echo json_encode(['success' => true, 'message' => 'Номер комнаты успешно обновлен.']);
pg_close($dbconn);
?>