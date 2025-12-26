<?php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'message' => 'Необходимо авторизоваться.']);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'message' => 'Метод не разрешен. Ожидается POST.']);
  exit;
}

require_once '../config/config.php';

$data = json_decode(file_get_contents("php://input"), true);

$booking_id = filter_var($data['booking_id'] ?? null, FILTER_VALIDATE_INT);
$check_in_date = $data['check_in_date'] ?? null;
$check_out_date = $data['check_out_date'] ?? null;
$total_nights = filter_var($data['total_nights'] ?? null, FILTER_VALIDATE_INT);
$total_amount = filter_var($data['total_amount'] ?? null, FILTER_VALIDATE_FLOAT);
$booking_status = $data['booking_status'] ?? null;

if (!$booking_id || !$check_in_date || !$check_out_date || $total_nights === false || $total_amount === false || !$booking_status) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'Недостаточно данных или неверный формат.']);
  exit;
}

if ($total_nights <= 0 || strtotime($check_out_date) <= strtotime($check_in_date)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'Неверный период проживания (дата выезда должна быть позже даты заезда).']);
  exit;
}

try {
  $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
  $pdo = new PDO($dsn, DB_USER, DB_PASSWORD);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $sql = "
    UPDATE bookings
    SET
      check_in_date = :check_in_date,
      check_out_date = :check_out_date,
      total_nights = :total_nights,
      total_amount = :total_amount,
      booking_status = :booking_status
    WHERE
      booking_id = :booking_id
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
  $stmt->bindParam(':check_in_date', $check_in_date);
  $stmt->bindParam(':check_out_date', $check_out_date);
  $stmt->bindParam(':total_nights', $total_nights, PDO::PARAM_INT);
  $stmt->bindParam(':total_amount', $total_amount);
  $stmt->bindParam(':booking_status', $booking_status);

  $stmt->execute();

  if ($stmt->rowCount() > 0) {
    echo json_encode(['success' => true, 'message' => 'Бронирование успешно обновлено.']);
  } else {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Бронирование не найдено или нет изменений для сохранения.']);
  }

} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Ошибка базы данных при обновлении: ' . $e->getMessage()]);
}
?>