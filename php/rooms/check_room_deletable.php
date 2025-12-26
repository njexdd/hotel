<?php
session_start();
require_once '../../php/config/config.php';

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user'])) {
  echo json_encode(['deletable' => false, 'message' => 'Не авторизован']);
  exit;
}

$room_number = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$room_number = htmlspecialchars($room_number ?? '', ENT_QUOTES, 'UTF-8');

if (!$room_number) {
  echo json_encode(['deletable' => false, 'message' => 'Неверный номер комнаты']);
  exit;
}

try {
  $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
  $pdo = new PDO($dsn, DB_USER, DB_PASSWORD);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  
  $sql_check_room = "
    SELECT 
      r.room_number,
      r.status as room_status,
      (
        SELECT COUNT(*) 
        FROM bookings 
        WHERE room_number = r.room_number 
        AND booking_status IN ('Активно', 'Подтверждено')
      ) as active_bookings
    FROM rooms r
    WHERE r.room_number = :room_number
  ";
  
  $stmt_check_room = $pdo->prepare($sql_check_room);
  $stmt_check_room->bindParam(':room_number', $room_number);
  $stmt_check_room->execute();
  $room_info = $stmt_check_room->fetch(PDO::FETCH_ASSOC);
  
  if (!$room_info) {
    echo json_encode(['deletable' => false, 'message' => 'Номер не найден']);
    exit;
  }
  
  $deletable = true;
  $message = '';
  
  if ($room_info['active_bookings'] > 0) {
    $deletable = false;
    $message = 'Есть активные бронирования. Сначала отмените или завершите их.';
  }
  
  $restricted_statuses = ['Занят', 'Забронирован'];
  if (in_array($room_info['room_status'], $restricted_statuses)) {
    if ($deletable) {
      $message = 'Внимание: номер в статусе "' . $room_info['room_status'] . '".';
    }
  }
  
  echo json_encode([
    'deletable' => $deletable,
    'message' => $message,
    'room_status' => $room_info['room_status'],
    'active_bookings' => (int)$room_info['active_bookings']
  ]);
  
} catch (PDOException $e) {
  error_log("Check room deletable error: " . $e->getMessage());
  echo json_encode(['deletable' => false, 'message' => 'Ошибка проверки']);
}
?>