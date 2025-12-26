<?php
session_start();
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
  header("Location: ../../pages/login/login.php");
  exit;
}
require_once '../../php/config/config.php';
$dbconn = db_connect();
if (!$dbconn) {
  die("Не удалось установить соединение с базой данных. Проверьте конфигурацию.");
}
$client_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($client_id <= 0) {
  header("Location: clients.php");
  exit;
}
$client_query = pg_query_params(
  $dbconn,
  "SELECT full_name, phone, email, country, gender, date_of_birth 
    FROM Clients 
    WHERE client_id = $1",
  array($client_id)
);
if (!$client_query || pg_num_rows($client_query) === 0) {
  die("Клиент с ID: {$client_id} не найден.");
}
$client_data = pg_fetch_assoc($client_query);
$doc_query = pg_query_params(
  $dbconn,
  "SELECT document_type, series_number, issue_date, issued_by 
    FROM Documents 
    WHERE client_id = $1 
    ORDER BY issue_date ASC 
    LIMIT 1",
  array($client_id)
);
if (!$doc_query) {
  die("Ошибка запроса документов: " . pg_last_error($dbconn));
}
$document_data = pg_fetch_assoc($doc_query);
$activity_query = pg_query_params(
  $dbconn,
  "SELECT
    total_bookings, active_bookings, total_stays, total_paid_amount
    FROM client_activity_summary_view
    WHERE client_id = $1",
  array($client_id)
);
$activity_data = pg_fetch_assoc($activity_query);
$activity_data = $activity_data ?: [
  'total_bookings' => 0,
  'active_bookings' => 0,
  'total_stays' => 0,
  'total_paid_amount' => 0
];
$full_name = htmlspecialchars($client_data['full_name'] ?? 'Неизвестный клиент');
$gender = $client_data['gender'] ?? 'Не указан';
$date_of_birth = $client_data['date_of_birth'] ?? '';
$dob_input_format = $date_of_birth ? date('Y-m-d', strtotime($date_of_birth)) : ''; 
$error_message = null; 
$success_message = null;
$phone = htmlspecialchars($client_data['phone'] ?? '');
$email = htmlspecialchars($client_data['email'] ?? '');
$country = htmlspecialchars($client_data['country'] ?? '');
$document_type = htmlspecialchars($document_data['document_type'] ?? '');
$series_number = htmlspecialchars($document_data['series_number'] ?? '');
$issue_date = $document_data['issue_date'] ?? '';
$issue_date_input_format = $issue_date ? date('Y-m-d', strtotime($issue_date)) : '';
$issued_by = htmlspecialchars($document_data['issued_by'] ?? '');
$countries_list = ['Беларусь', 'Россия', 'Казахстан'];
$document_types_list = ['Паспорт', 'Загранпаспорт', 'Водительское удостоверение', 'Военный билет'];
if (isset($_POST['action']) && $_POST['action'] === 'delete_client') {
  pg_query($dbconn, "BEGIN");
  $old_error_reporting = error_reporting(E_ALL & ~E_WARNING);
  try {
    $delete_query = @pg_query_params(
      $dbconn,
      "DELETE FROM Clients WHERE client_id = $1",
      array($client_id)
    );
    error_reporting($old_error_reporting);
    if ($delete_query === false) {
      $error = pg_last_error($dbconn);
      @pg_query($dbconn, "ROLLBACK");
      if (strpos($error, 'Нельзя удалить клиента с активными бронированиями') !== false) {
          preg_match('/Активных бронирований:\s*(\d+)/', $error, $matches);
          $error_message = "У клиента есть активные бронирования. Сначала отмените или завершите все активные бронирования.";
      } else {
          $error_message = "Ошибка при удалении клиента: " . htmlspecialchars($error);
      }
      throw new Exception($error_message);
    }
    pg_query($dbconn, "COMMIT");
    $_SESSION['message'] = "Клиент успешно удален.";
    header("Location: clients.php");
    exit;
  } catch (Exception $e) {
    error_reporting($old_error_reporting);
    @pg_query($dbconn, "ROLLBACK");
    $error_message = $e->getMessage();
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/../../images/hotel.ico">
  <title>Менедждер гостиницы. Карточка клиента</title>
  <link rel="stylesheet" href="../../css/normalize.css">
  <link rel="stylesheet" href="../../css/reset.css">
  <link rel="stylesheet" href="../../css/fonts.css">
  <link rel="stylesheet" href="../../css/clients/client-card.css">
</head>
<body>
  <div class="layout">
    <aside class="sidebar">
      <nav class="nav">
        <ul class="nav__list">
          <li class="nav__item">
            <a href="../dashboard/dashboard.php" class="nav__item-link">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-layout-dashboard w-5 h-5"><rect width="7" height="9" x="3" y="3" rx="1"></rect><rect width="7" height="5" x="14" y="3" rx="1"></rect><rect width="7" height="9" x="14" y="12" rx="1"></rect><rect width="7" height="5" x="3" y="16" rx="1"></rect></svg>
              Dashboard
            </a>
          </li>
          <li class="nav__item">
            <a href="../client/clients.php" class="nav__item-link">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-users w-5 h-5"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
              Клиенты
            </a>
          </li>
          <li class="nav__item">
            <a href="../room/rooms.php" class="nav__item-link">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-door-open w-5 h-5"><path d="M13 4h3a2 2 0 0 1 2 2v14"></path><path d="M2 20h3"></path><path d="M13 20h9"></path><path d="M10 12v.01"></path><path d="M13 4.562v16.157a1 1 0 0 1-1.242.97L5 20V5.562a2 2 0 0 1 1.515-1.94l4-1A2 2 0 0 1 13 4.561Z"></path></svg>
              Номера
            </a>
          </li>
          <li class="nav__item">
            <a href="../booking/bookings.php" class="nav__item-link">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-calendar w-5 h-5"><path d="M8 2v4"></path><path d="M16 2v4"></path><rect width="18" height="18" x="3" y="4" rx="2"></rect><path d="M3 10h18"></path></svg>
              Бронирования
            </a>
          </li>
          <li class="nav__item">
            <a href="../payments/payments.php" class="nav__item-link">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-credit-card w-5 h-5"><rect width="20" height="14" x="2" y="5" rx="2"></rect><line x1="2" x2="22" y1="10" y2="10"></line></svg>
              Платежи
            </a>
          </li>
        </ul>
      </nav>
    </aside>
    <div class="layout-main">
      <header class="header">
        <div class="header__logo">
          <div class="header__logo-img">
            <img src="../../images/logo/logo.png" alt="Логотип">
          </div>
          <h1 class="header__title">HotelHub</h1>
        </div>
        <a href="../../php/logout.php" class="header__exit">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-log-out w-4 h-4">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
            <polyline points="16 17 21 12 16 7"></polyline>
            <line x1="21" x2="9" y1="12" y2="12"></line>
          </svg>
          Выход
        </a>
      </header>
      <main class="main">
        <div class="container">
          <div class="main__content">
            <div class="main__text">
              <h2 class="main__title"><?php echo $full_name; ?></h2>
              <p class="main__desc">Профиль клиента</p>
            </div>
            <div class="main__actions">
              <button id="edit-button" class="main__button" onclick="toggleEditMode()">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-pen-line w-4 h-4"><path d="M12 20h9"></path><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"></path></svg>
                <span id="edit-button-text">Редактировать</span>
              </button>
              <button id="save-button" class="main__button main__button_green" style="display: none;" onclick="saveChanges()">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-save w-4 h-4"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"></path><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"></path><path d="M7 3v4a1 1 0 0 0 1 1h7"></path></svg>
                Сохранить изменения
              </button>
              <a href="../booking/booking-new.php?client_id=<?php echo $client_id; ?>" class="main__button" id="add-booking-button">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-plus w-4 h-4"><path d="M5 12h14"></path><path d="M12 5v14"></path></svg>
                Добавить бронирование
              </a>
              <a href="../payments/payment-new.php?client_id=<?php echo $client_id; ?>" class="main__button" id="add-payment-button">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-credit-card w-4 h-4"><rect width="20" height="14" x="2" y="5" rx="2"></rect><line x1="2" x2="22" y1="10" y2="10"></line></svg>
                Добавить платеж
              </a>
              <form method="POST" id="deleteForm" style="display:inline;">
                <input type="hidden" name="action" value="delete_client">
                <button type="submit" class="main__button main__button_red" onclick="return confirmDelete();" id="delete-button">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trash2 w-4 h-4"><path d="M3 6h18"></path><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path><line x1="10" x2="10" y1="11" y2="17"></line><line x1="14" x2="14" y1="11" y2="17"></line></svg>
                  Удалить
                </button>
              </form>
              <button id="cancel-button" class="main__button" style="display: none;" onclick="cancelChanges()">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x w-4 h-4"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg>
                Отменить изменения
              </button>
            </div>
          </div>
          <form id="client-edit-form" action="../../php/clients/update_client.php" method="POST">
            <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
            <section class="data">
              <div class="data__basic">
                <h3 class="data__title">Основные данные</h3>
                <hr>
                <div class="data__basic-data">
                  <div class="data__block">
                    <label for="full_name" class="data__label">ФИО</label>
                    <input name="full_name" id="full_name" type="text" class="data__input" value="<?php echo $full_name; ?>" readonly>
                  </div>
                  <div class="data__block">
                    <label for="gender" class="data__label">Пол</label>
                    <select name="gender" id="gender" class="data__select" disabled>
                      <option value="Мужской" <?php echo ($gender === 'Мужской') ? 'selected' : ''; ?>>Мужской</option>
                      <option value="Женский" <?php echo ($gender === 'Женский') ? 'selected' : ''; ?>>Женский</option>
                      <option value="Не указан" <?php echo ($gender !== 'Мужской' && $gender !== 'Женский') ? 'selected' : ''; ?>>Не указан</option>
                    </select>
                  </div>
                  <div class="data__block">
                    <label for="phone" class="data__label">Телефон</label>
                    <input name="phone" id="phone" type="tel" class="data__input" value="<?php echo $phone; ?>" readonly>
                  </div>
                  <div class="data__block">
                    <label for="date_of_birth" class="data__label">Дата рождения</label>
                    <input name="date_of_birth" id="date_of_birth" type="date" class="data__input" value="<?php echo $dob_input_format; ?>" readonly>
                  </div>
                  <div class="data__block">
                    <label for="country" class="data__label">Страна</label>
                    <select name="country" id="country" class="data__select" disabled>
                      <?php 
                      $is_country_in_list = false;
                      foreach ($countries_list as $option): 
                        $selected = ($country === $option) ? 'selected' : '';
                        if ($selected) $is_country_in_list = true;
                      ?>
                        <option value="<?php echo htmlspecialchars($option); ?>" <?php echo $selected; ?>>
                          <?php echo htmlspecialchars($option); ?>
                        </option>
                      <?php endforeach; ?>
                      <?php if (!$is_country_in_list && !empty($country)): ?>
                        <option value="<?php echo htmlspecialchars($country); ?>" selected>
                          <?php echo htmlspecialchars($country); ?> (Не в списке)
                        </option>
                      <?php endif; ?>
                    </select>
                  </div>
                  <div class="data__block">
                    <label for="email" class="data__label">Email</label>
                    <input name="email" id="email" type="email" class="data__input" value="<?php echo $email; ?>" readonly>
                  </div>
                </div>
              </div>
              <div class="data__activity">
                <h3 class="data__title">Показатели активности</h3>
                <hr>
                <div class="data__activity-data">
                  <div class="data__block">
                    <p class="data__text">Всего бронирований</p>
                    <p class="data__text data__text_bold"><?php echo (int)$activity_data['total_bookings']; ?></p>
                  </div>
                  <div class="data__block">
                    <p class="data__text">Активные бронирования</p>
                    <p class="data__text data__text_bold data__text_blue"><?php echo (int)$activity_data['active_bookings']; ?></p>
                  </div>
                  <div class="data__block">
                    <p class="data__text">Количество прибываний</p>
                    <p class="data__text data__text_bold"><?php echo (int)$activity_data['total_stays']; ?></p>
                  </div>
                  <div class="data__block">
                    <p class="data__text">Общая сумма выплат</p>
                    <p class="data__text data__text_bold data__text_green"><?php echo number_format($activity_data['total_paid_amount'], 0, '.', ' '); ?> ₽</p>
                  </div>
                  <div class="data__block">
                    <p class="data__text">Общий доход</p>
                    <p class="data__text data__text_bold data__text_dark-blue"><?php echo number_format($activity_data['total_paid_amount'], 0, '.', ' '); ?> ₽</p>
                  </div>
                </div>
              </div>
              <div class="data__documents">
                <h3 class="data__title">Документы</h3>
                <hr>
                <div class="data__documents-data">
                  <div class="data__block">
                    <label for="document_type" class="data__label">Тип документа</label>
                    <select name="document_type" id="document_type" class="data__select" disabled>
                      <?php 
                      $is_doc_type_in_list = false;
                      foreach ($document_types_list as $option): 
                        $selected = ($document_type === $option) ? 'selected' : '';
                        if ($selected) $is_doc_type_in_list = true;
                      ?>
                        <option value="<?php echo htmlspecialchars($option); ?>" <?php echo $selected; ?>>
                          <?php echo htmlspecialchars($option); ?>
                        </option>
                      <?php endforeach; ?>
                      <?php if (!$is_doc_type_in_list && !empty($document_type)): ?>
                        <option value="<?php echo htmlspecialchars($document_type); ?>" selected>
                          <?php echo htmlspecialchars($document_type); ?> (Другое)
                        </option>
                      <?php endif; ?>
                    </select>
                  </div>
                  <div class="data__block">
                    <label for="series_number" class="data__label">Серия/Номер</label>
                    <input name="series_number" id="series_number" type="text" class="data__input" value="<?php echo $series_number; ?>" readonly>
                  </div>
                  <div class="data__block">
                    <label for="issue_date" class="data__label">Дата выдачи</label>
                    <input name="issue_date" id="issue_date" type="date" class="data__input" value="<?php echo $issue_date_input_format; ?>" readonly>
                  </div>
                  <div class="data__block">
                    <label for="issued_by" class="data__label">Кем выдан</label>
                    <input name="issued_by" id="issued_by" type="text" class="data__input" value="<?php echo $issued_by; ?>" readonly>
                  </div>
                </div>
              </div>
            </section>
          </form>
        </div>
      </main>
    </div>
  </div>
<script>
  const editButton = document.getElementById('edit-button');
  const saveButton = document.getElementById('save-button');
  const deleteButton = document.getElementById('delete-button');
  const cancelButton = document.getElementById('cancel-button');
  const addBookingButton = document.getElementById('add-booking-button');
  const addPaymentButton = document.getElementById('add-payment-button');
  const form = document.getElementById('client-edit-form');
  const editableInputs = form.querySelectorAll('.data__input');
  const editableSelects = form.querySelectorAll('.data__select');
  let originalValues = {};
  function saveOriginalValues() {
    originalValues = {};
    editableInputs.forEach(input => {
      originalValues[input.name] = input.value;
    });
    editableSelects.forEach(select => {
      originalValues[select.name] = select.value;
    });
  }
  window.onload = saveOriginalValues;
  function setEditMode(isEditMode) {
    editableInputs.forEach(input => {
      input.readOnly = !isEditMode;
    });
    editableSelects.forEach(select => {
      select.disabled = !isEditMode;
    });
    editButton.style.display = isEditMode ? 'none' : 'flex';
    deleteButton.style.display = isEditMode ? 'none' : 'flex';
    addBookingButton.style.display = isEditMode ? 'none' : 'flex';
    addPaymentButton.style.display = isEditMode ? 'none' : 'flex';
    saveButton.style.display = isEditMode ? 'flex' : 'none';
    cancelButton.style.display = isEditMode ? 'flex' : 'none';
    if (isEditMode) {
      saveOriginalValues();
    }
  }
  function toggleEditMode() {
    setEditMode(true);
  }
  function saveChanges() {
    const formData = new FormData(form);
    fetch(form.action, {
      method: form.method,
      body: formData
    })
    .then(response => {
      const contentType = response.headers.get('content-type');
      if (!contentType || !contentType.includes('application/json')) {
        throw new TypeError("Ожидался JSON ответ от сервера.");
      }
      return response.json();
    })
    .then(data => {
      if (data.success) {
        alert(data.message || "Изменения успешно сохранены!");
        window.location.reload(); 
      } else {
        alert("Внимание: " + (data.message || "Неизвестная ошибка."));
      }
    })
    .catch(error => {
      console.error('Ошибка при отправке данных:', error);
      alert("Произошла ошибка сети или сервера: " + error.message);
    });
  }
  function cancelChanges() {
    editableInputs.forEach(input => {
      if (originalValues[input.name] !== undefined) {
        input.value = originalValues[input.name];
      }
    });
    editableSelects.forEach(select => {
      if (originalValues[select.name] !== undefined) {
        select.value = originalValues[select.name];
      }
    });
    setEditMode(false);
  }
  function confirmDelete() {
    return confirm("Вы уверены, что хотите удалить этого клиента?");
  }
  setEditMode(false);
  <?php if (isset($error_message)): ?>
    alert("<?php echo addslashes($error_message); ?>");
  <?php endif; ?>
</script>
<?php
if ($dbconn) {
  pg_close($dbconn);
}
?>
</body>
</html>