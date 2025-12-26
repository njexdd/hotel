<?php
session_start();

error_reporting(0);
ini_set('display_errors', 0);

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => 'Не авторизован']);
  exit;
}

require_once '../../php/config/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['success' => false, 'message' => 'Некорректный метод запроса']);
  exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
  echo json_encode(['success' => false, 'message' => 'Неверный формат JSON данных']);
  exit;
}

if (!isset($data['room_number'])) {
  echo json_encode(['success' => false, 'message' => 'Номер комнаты не указан']);
  exit;
}

$room_number = filter_var($data['room_number'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$room_number = htmlspecialchars($room_number, ENT_QUOTES, 'UTF-8');

if (!$room_number) {
  echo json_encode(['success' => false, 'message' => 'Некорректный номер комнаты']);
  exit;
}

try {
  $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
  $pdo = new PDO($dsn, DB_USER, DB_PASSWORD);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  
  $pdo->beginTransaction();
  
  $sql_check_bookings = "
    SELECT COUNT(*) as active_count 
    FROM bookings 
    WHERE room_number = :room_number 
    AND booking_status IN ('Активно', 'Подтверждено')
  ";
  
  $stmt_check_bookings = $pdo->prepare($sql_check_bookings);
  $stmt_check_bookings->bindParam(':room_number', $room_number);
  $stmt_check_bookings->execute();
  $result = $stmt_check_bookings->fetch(PDO::FETCH_ASSOC);
  
  if ($result['active_count'] > 0) {
    $pdo->rollBack();
    echo json_encode([
      'success' => false, 
      'message' => 'Нельзя удалить номер с активными бронированиями. Сначала отмените или завершите бронирования.'
    ]);
    exit;
  }
  
  $sql_delete_payments = "
    DELETE FROM payments 
    WHERE booking_id IN (
      SELECT booking_id FROM bookings WHERE room_number = :room_number
    )
  ";
  $stmt_delete_payments = $pdo->prepare($sql_delete_payments);
  $stmt_delete_payments->bindParam(':room_number', $room_number);
  $stmt_delete_payments->execute();
  
  $sql_delete_bookings = "DELETE FROM bookings WHERE room_number = :room_number";
  $stmt_delete_bookings = $pdo->prepare($sql_delete_bookings);
  $stmt_delete_bookings->bindParam(':room_number', $room_number);
  $stmt_delete_bookings->execute();
  
  $sql_delete_amenities = "DELETE FROM room_amenities WHERE room_number = :room_number";
  $stmt_delete_amenities = $pdo->prepare($sql_delete_amenities);
  $stmt_delete_amenities->bindParam(':room_number', $room_number);
  $stmt_delete_amenities->execute();
  
  $sql_delete_room = "DELETE FROM rooms WHERE room_number = :room_number";
  $stmt_delete_room = $pdo->prepare($sql_delete_room);
  $stmt_delete_room->bindParam(':room_number', $room_number);
  $stmt_delete_room->execute();
  
  $rows_deleted = $stmt_delete_room->rowCount();
  
  $pdo->commit();
  
  if ($rows_deleted > 0) {
    echo json_encode([
      'success' => true,
      'message' => 'Номер ' . $room_number . ' успешно удален',
      'redirect' => '../room/rooms.php'
    ]);
  } else {
    echo json_encode([
      'success' => false,
      'message' => 'Номер ' . $room_number . ' не найден или уже был удален'
    ]);
  }
  
} catch (PDOException $e) {
  if (isset($pdo) && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  
  error_log("Delete room error: " . $e->getMessage());
  
  echo json_encode([
    'success' => false, 
    'message' => 'Ошибка при удалении. Пожалуйста, попробуйте позже.'
  ]);
} catch (Exception $e) {
  if (isset($pdo) && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  
  error_log("General error in delete_room.php: " . $e->getMessage());
  
  echo json_encode([
    'success' => false, 
    'message' => 'Произошла непредвиденная ошибка'
  ]);
}
?>