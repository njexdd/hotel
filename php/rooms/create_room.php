<?php
error_reporting(0);
ini_set('display_errors', 0);
session_start();
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  return_json_response(false, 'Недопустимый метод запроса');
}
require_once '../config/config.php';
function return_json_response($success, $message, $room_number = '') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'success' => $success,
    'message' => $message,
    'room_number' => $room_number
  ], JSON_UNESCAPED_UNICODE);
  exit;
}
$room_number = trim($_POST['room_number'] ?? '');
$room_type_id = filter_var($_POST['room_type_id'] ?? null, FILTER_VALIDATE_INT);
$floor = filter_var($_POST['floor'] ?? null, FILTER_VALIDATE_INT);
$area = filter_var($_POST['area'] ?? null, FILTER_VALIDATE_FLOAT);
$status = trim($_POST['status'] ?? '');
$amenity_ids = $_POST['amenities'] ?? [];
$base_price = filter_var($_POST['base_price'] ?? null, FILTER_VALIDATE_FLOAT);
$capacity = filter_var($_POST['capacity'] ?? null, FILTER_VALIDATE_INT);
if (empty($room_number) || $room_type_id === false || empty($status) || $base_price === false || $capacity === false) {
  return_json_response(false, "Пожалуйста, заполните все обязательные поля (Номер комнаты, Тип, Цена, Вместимость, Статус).");
}
if ($area !== null && $area !== false && $area <= 0) {
  return_json_response(false, "Площадь должна быть положительным числом.");
}
if ($base_price !== false && $base_price <= 0) {
  return_json_response(false, "Базовая цена должна быть положительным числом.");
}
if ($floor !== null && $floor !== false && $floor < 0) {
  return_json_response(false, "Этаж должен быть положительным числом или нулем.");
}
$dbconn = db_connect();
if (!$dbconn) {
  return_json_response(false, "Не удалось подключиться к базе данных. Проверьте конфигурацию.");
}
$transaction_started = pg_query($dbconn, "BEGIN");
if (!$transaction_started) {
  return_json_response(false, "Не удалось начать транзакцию.");
}
try {
  $check_room_query = 'SELECT room_number FROM public.rooms WHERE room_number = $1';
  $check_room_result = pg_query_params($dbconn, $check_room_query, [$room_number]);
  if ($check_room_result && pg_num_rows($check_room_result) > 0) {
    throw new Exception("Данный номер комнаты уже существует");
  }
  $update_type_query = 'UPDATE public.room_types SET capacity = $1, base_price = $2 WHERE room_type_id = $3';
  $update_type_result = pg_query_params($dbconn, $update_type_query, [$capacity, $base_price, $room_type_id]);
  if (!$update_type_result) {
    throw new Exception("Ошибка при обновлении типа номера");
  }
  $insert_room_query = 'INSERT INTO public.rooms (room_number, room_type_id, floor, area, status) VALUES ($1, $2, $3, $4, $5)';
  $params = [
    $room_number,
    $room_type_id,
    $floor === false ? null : $floor,
    $area === false ? null : $area,
    $status
  ];
  $insert_room_result = pg_query_params($dbconn, $insert_room_query, $params);
  if (!$insert_room_result) {
    $error = pg_last_error($dbconn);
    if (strpos($error, 'duplicate') !== false || strpos($error, 'повторяющееся') !== false || strpos($error, 'rooms_pkey') !== false || strpos($error, 'уже существует') !== false) {
      throw new Exception("Данный номер комнаты уже существует");
    }
    throw new Exception("Ошибка при создании номера");
  }
  if (!empty($amenity_ids) && is_array($amenity_ids)) {
    foreach ($amenity_ids as $amenity_id) {
      $amenity_id = filter_var($amenity_id, FILTER_VALIDATE_INT);
      if ($amenity_id === false) continue;
      $insert_amenity_query = 'INSERT INTO public.room_amenities (room_number, amenity_id) VALUES ($1, $2)';
      $insert_amenity_result = pg_query_params($dbconn, $insert_amenity_query, [$room_number, $amenity_id]);
      if (!$insert_amenity_result) {
        error_log("Ошибка при добавлении удобства ID $amenity_id для комнаты $room_number");
      }
    }
  }
  $commit_result = pg_query($dbconn, "COMMIT");
  if (!$commit_result) {
    throw new Exception("Ошибка при завершении транзакции");
  }
  pg_close($dbconn);
  return_json_response(true, "Номер $room_number успешно создан!", $room_number);
} catch (Exception $e) {
  pg_query($dbconn, "ROLLBACK");
  pg_close($dbconn);
  return_json_response(false, $e->getMessage());
}
?>