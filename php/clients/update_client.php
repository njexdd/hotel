<?php
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
  echo json_encode(['success' => false, 'message' => 'Ошибка доступа: Требуется авторизация.']);
  exit;
}
require_once '../config/config.php';
$dbconn = db_connect();
if (!$dbconn) {
  echo json_encode(['success' => false, 'message' => 'Ошибка подключения к базе данных.']);
  exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['client_id'])) {
  echo json_encode(['success' => false, 'message' => 'Некорректный запрос.']);
  exit;
}
$client_id = (int)$_POST['client_id'];
$full_name = htmlspecialchars($_POST['full_name'] ?? '');
$phone = htmlspecialchars($_POST['phone'] ?? '');
$email = htmlspecialchars($_POST['email'] ?? '');
$country = htmlspecialchars($_POST['country'] ?? '');
$gender = htmlspecialchars($_POST['gender'] ?? 'Не указан');
$date_of_birth = $_POST['date_of_birth'] ?? null;
if (empty($full_name)) {
  echo json_encode(['success' => false, 'message' => 'ФИО не может быть пустым.']);
  pg_close($dbconn);
  exit;
}
if ($date_of_birth === '') {
  $date_of_birth = null;
} elseif ($date_of_birth !== null) {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_of_birth)) {
    echo json_encode(['success' => false, 'message' => 'Неверный формат даты рождения.']);
    pg_close($dbconn);
    exit;
  }
}
$document_type = htmlspecialchars($_POST['document_type'] ?? '');
$series_number = htmlspecialchars($_POST['series_number'] ?? '');
$issue_date = $_POST['issue_date'] ?? null;
$issued_by = htmlspecialchars($_POST['issued_by'] ?? '');
if ($issue_date === '') {
  $issue_date = null;
} elseif ($issue_date !== null) {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue_date)) {
    echo json_encode(['success' => false, 'message' => 'Неверный формат даты выдачи.']);
    pg_close($dbconn);
    exit;
  }
}
$check_phone = pg_query_params($dbconn,
  "SELECT COUNT(*) FROM Clients WHERE phone = $1 AND client_id != $2",
  array($phone, $client_id)
);
if (pg_fetch_result($check_phone, 0, 0) > 0) {
  echo json_encode(['success' => false, 'message' => 'Этот номер телефона уже зарегистрирован у другого клиента.']);
  pg_close($dbconn);
  exit;
}
$check_email = pg_query_params($dbconn,
  "SELECT COUNT(*) FROM Clients WHERE email = $1 AND client_id != $2",
  array(strtolower($email), $client_id)
);
if (pg_fetch_result($check_email, 0, 0) > 0) {
  echo json_encode(['success' => false, 'message' => 'Этот Email уже зарегистрирован у другого клиента.']);
  pg_close($dbconn);
  exit;
}
$check_doc = pg_query_params($dbconn,
  "SELECT COUNT(*) FROM Documents WHERE series_number = $1 AND document_type = $2 AND client_id != $3",
  array($series_number, $document_type, $client_id)
);
if (pg_fetch_result($check_doc, 0, 0) > 0) {
  echo json_encode(['success' => false, 'message' => 'Документ с такими серией и номером уже зарегистрирован.']);
  pg_close($dbconn);
  exit;
}
pg_query($dbconn, "BEGIN");
$success = true;
$error_message = '';
try {
  $client_update_sql = "
    UPDATE Clients
    SET full_name = $2, phone = $3, email = $4, country = $5, gender = $6, date_of_birth = $7
    WHERE client_id = $1
  ";
  $client_update_result = pg_query_params(
    $dbconn, 
    $client_update_sql, 
    array($client_id, $full_name, $phone, $email, $country, $gender, $date_of_birth)
  );
  if (!$client_update_result) {
    $success = false;
    $error_message = "Ошибка при обновлении данных клиента: " . pg_last_error($dbconn);
    throw new Exception($error_message);
  }
  $doc_exists_query = pg_query_params(
    $dbconn,
    "SELECT COUNT(*) FROM Documents WHERE client_id = $1",
    array($client_id)
  );
  $doc_exists = pg_fetch_result($doc_exists_query, 0, 0) > 0;
  $doc_has_data = !empty($document_type) || !empty($series_number) || $issue_date !== null || !empty($issued_by);
  if ($doc_has_data) {
    if ($doc_exists) {
      $doc_update_sql = "
        UPDATE Documents
        SET document_type = $2, series_number = $3, issue_date = $4, issued_by = $5
        WHERE client_id = $1
      ";
      $doc_update_result = pg_query_params(
        $dbconn, 
        $doc_update_sql, 
        array($client_id, $document_type, $series_number, $issue_date, $issued_by)
      );
      if (!$doc_update_result) {
        $success = false;
        $error_message = "Ошибка при обновлении данных документа: " . pg_last_error($dbconn);
        throw new Exception($error_message);
      }
    } else {
      $doc_insert_sql = "
        INSERT INTO Documents (client_id, document_type, series_number, issue_date, issued_by)
        VALUES ($1, $2, $3, $4, $5)
      ";
      $doc_insert_result = pg_query_params(
        $dbconn, 
        $doc_insert_sql, 
        array($client_id, $document_type, $series_number, $issue_date, $issued_by)
      );
      if (!$doc_insert_result) {
        $success = false;
        $error_message = "Ошибка при добавлении нового документа: " . pg_last_error($dbconn);
        throw new Exception($error_message);
      }
    }
  }
  pg_query($dbconn, "COMMIT");
  $message = "Данные клиента и документа успешно обновлены.";
} catch (Exception $e) {
  pg_query($dbconn, "ROLLBACK");
  $success = false;
  $message = $e->getMessage();
}
pg_close($dbconn);
echo json_encode(['success' => $success, 'message' => $message]);
?>