<?php
session_start();
require_once '../../php/config/config.php';
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
  http_response_code(401);
  echo json_encode(['error' => 'Требуется авторизация']);
  exit;
}
if (!isset($_GET['transaction_id']) || !is_numeric($_GET['transaction_id'])) {
  http_response_code(400);
  echo json_encode(['error' => 'Некорректный ID транзакции']);
  exit;
}
$original_transaction_id = (int)$_GET['transaction_id'];
$conn = db_connect();
if (!$conn) {
  http_response_code(500);
  echo json_encode(['error' => 'Ошибка подключения к базе данных']);
  exit;
}
try {
  $procedure_query = "SELECT * FROM process_payment_refund($1)";
  $procedure_result = pg_query_params($conn, $procedure_query, [$original_transaction_id]);
  if (!$procedure_result) {
    $error = pg_last_error($conn);
    error_log("Ошибка вызова процедуры возврата: " . $error);
    if (strpos($error, 'already refunded') !== false || 
      strpos($error, 'уже был возвращен') !== false ||
      strpos($error, 'already returned') !== false) {
      http_response_code(400);
      echo json_encode([
        'success' => false,
        'error' => 'Возврат уже был оформлен ранее'
      ]);
    } else {
      throw new Exception('Ошибка выполнения операции возврата: ' . $error);
    }
    exit;
  }
  $result = pg_fetch_assoc($procedure_result);
  if (!$result) {
    throw new Exception('Процедура возврата не вернула результат');
  }
  $success = ($result['success'] === 't' || $result['success'] === true || $result['success'] === 'true');
  $new_transaction_id = $result['new_transaction_id'] ?? null;
  $error_message = $result['error_message'] ?? null;
  if ($success && $new_transaction_id) {
    echo json_encode([
      'success' => true,
      'message' => 'Возврат успешно оформлен',
      'new_transaction_id' => $new_transaction_id
    ]);
  } else {
    http_response_code(400);
    $user_friendly_error = match(true) {
      strpos($error_message ?? '', 'already refunded') !== false => 'Возврат уже был оформлен ранее',
      strpos($error_message ?? '', 'payment not found') !== false => 'Исходный платеж не найден',
      strpos($error_message ?? '', 'invalid payment status') !== false => 'Возврат можно оформить только для успешных платежей',
      strpos($error_message ?? '', 'amount must be positive') !== false => 'Сумма платежа должна быть положительной для возврата',
      default => $error_message ?: 'Неизвестная ошибка при оформлении возврата'
    };
    echo json_encode([
      'success' => false,
      'error' => $user_friendly_error
    ]);
  }
} catch (Exception $e) {
  error_log("Ошибка в process_refund.php: " . $e->getMessage());
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'error' => 'Внутренняя ошибка сервера'
  ]);
} finally {
  if (isset($conn) && $conn) {
    pg_close($conn);
  }
}
function check_refund_availability($transaction_id) {
  $conn = db_connect();
  if (!$conn) {
    return ['available' => false, 'error' => 'Ошибка подключения к БД'];
  }
  $query = "SELECT * FROM can_refund_payment($1)";
  $result = pg_query_params($conn, $query, [$transaction_id]);
  if (!$result) {
    pg_close($conn);
    return ['available' => false, 'error' => 'Ошибка проверки возможности возврата'];
  }
  $data = pg_fetch_assoc($result);
  pg_close($conn);
  return [
    'available' => ($data['can_refund'] === 't' || $data['can_refund'] === true),
    'reason' => $data['reason'] ?? 'Неизвестная причина',
    'original_amount' => $data['original_amount'] ?? 0,
    'payment_status' => $data['payment_status'] ?? 'Неизвестно',
    'client_name' => $data['client_name'] ?? 'Неизвестный клиент',
    'booking_id' => $data['booking_id'] ?? 0
  ];
}
?>