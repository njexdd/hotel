<?php
session_start();

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
  header("Location: ../../pages/login/login.php");
  exit;
}

require_once '../config/config.php';

header('Content-Type: application/json');

$response = [
  'success' => false,
  'message' => '',
  'redirect' => ''
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  $response['message'] = 'Некорректный метод запроса';
  echo json_encode($response);
  exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['booking_id'])) {
  $response['message'] = 'ID бронирования не указан';
  echo json_encode($response);
  exit;
}

$booking_id = filter_var($data['booking_id'], FILTER_VALIDATE_INT);

if (!$booking_id) {
  $response['message'] = 'Некорректный ID бронирования';
  echo json_encode($response);
  exit;
}

try {
  $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
  $pdo = new PDO($dsn, DB_USER, DB_PASSWORD);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $pdo->beginTransaction();

  $sql = "SELECT room_number, booking_status FROM bookings WHERE booking_id = :booking_id";
  $stmt = $pdo->prepare($sql);
  $stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
  $stmt->execute();
  $booking_info = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$booking_info) {
    $pdo->rollBack();
    $response['message'] = 'Бронирование не найдено';
    echo json_encode($response);
    exit;
  }

  $room_number = $booking_info['room_number'];
  $booking_status = $booking_info['booking_status'];

  $sql_delete_payments = "DELETE FROM payments WHERE booking_id = :booking_id";
  $stmt_delete_payments = $pdo->prepare($sql_delete_payments);
  $stmt_delete_payments->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
  $stmt_delete_payments->execute();

  $sql_delete_booking = "DELETE FROM bookings WHERE booking_id = :booking_id";
  $stmt_delete_booking = $pdo->prepare($sql_delete_booking);
  $stmt_delete_booking->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
  $stmt_delete_booking->execute();

  $sql_check_room = "
    SELECT
      status,
      room_number
    FROM rooms
    WHERE room_number = :room_number
  ";

  $stmt_check_room = $pdo->prepare($sql_check_room);
  $stmt_check_room->bindParam(':room_number', $room_number);
  $stmt_check_room->execute();
  $room_info = $stmt_check_room->fetch(PDO::FETCH_ASSOC);

  if ($room_info) {
    $room_status = $room_info['status'];

    $sql_check_other_bookings = "
      SELECT COUNT(*) as active_count
      FROM bookings
      WHERE room_number = :room_number
      AND booking_status IN ('Активно', 'Подтверждено')
    ";

    $stmt_check = $pdo->prepare($sql_check_other_bookings);
    $stmt_check->bindParam(':room_number', $room_number);
    $stmt_check->execute();
    $result = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if ($result['active_count'] == 0) {
      $statuses_to_update = ['Забронирован', 'Занят', 'Подтвержден', 'Бронирование'];

      if (in_array($room_status, $statuses_to_update)) {
        $sql_update_room = "
          UPDATE rooms
          SET status = 'Свободен'
          WHERE room_number = :room_number
        ";

        $stmt_update = $pdo->prepare($sql_update_room);
        $stmt_update->bindParam(':room_number', $room_number);
        $stmt_update->execute();

        $room_status_updated = true;
      } else {
        $room_status_updated = false;
      }
    } else {
      $room_status_updated = false;
    }
  } else {
    $room_status_updated = false;
  }

  $pdo->commit();

  $success_message = 'Бронирование успешно удалено';
  if (isset($room_status_updated) && $room_status_updated) {
    $success_message .= '. Статус номера ' . $room_number . ' изменен на "Свободен"';
  }

  $response['success'] = true;
  $response['message'] = $success_message;
  $response['redirect'] = '../booking/bookings.php';
  $response['room_status_updated'] = $room_status_updated ?? false;
  $response['room_number'] = $room_number;

} catch (PDOException $e) {
  if (isset($pdo) && $pdo->inTransaction()) {
    $pdo->rollBack();
  }

  $response['message'] = 'Ошибка при удалении бронирования: ' . $e->getMessage();
  error_log("Delete booking error: " . $e->getMessage());
} catch (Exception $e) {
  if (isset($pdo) && $pdo->inTransaction()) {
    $pdo->rollBack();
  }

  $response['message'] = 'Ошибка: ' . $e->getMessage();
}

echo json_encode($response);
exit;
?>