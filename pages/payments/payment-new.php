<?php
session_start();
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
  header("Location: ../../pages/login/login.php");
  exit;
}
require_once '../../php/config/config.php';
$message = '';
$selected_client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
$client_info = null;
if ($selected_client_id > 0) {
  $dbconn_temp = db_connect();
  if ($dbconn_temp) {
    $query = "SELECT client_id, full_name, phone, email FROM clients WHERE client_id = $1";
    $result = pg_query_params($dbconn_temp, $query, array($selected_client_id));
    if ($result && pg_num_rows($result) > 0) {
      $client_info = pg_fetch_assoc($result);
      $selected_client_id = $client_info['client_id'];
    } else {
      $selected_client_id = null;
    }
    pg_close($dbconn_temp);
  }
}
function createPayment() {
  global $message;
  $payment_date = $_POST['payment-date'] ?? null;
  $method = $_POST['payment-method'] ?? null;
  $operation_type = $_POST['operation-type'] ?? null;
  $amount = $_POST['amount'] ?? null;
  $status = $_POST['status-select'] ?? null;
  $client_id = $_POST['client_id'] ?? null;
  $booking_id = $_POST['booking_id'] ?? null;
  if (!$payment_date || !$method || !$operation_type || !$amount || !$status || !$client_id || !$booking_id) {
    $message = '❌ Ошибка: Не все обязательные поля заполнены.';
    return;
  }
  $amount_float = filter_var($amount, FILTER_VALIDATE_FLOAT);
  if ($amount_float === false || $amount_float <= 0) {
    $message = '❌ Ошибка: Сумма платежа должна быть положительным числом.';
    return;
  }
  $user_id = $_SESSION['user']['user_id'] ?? 1;
  $payment_datetime = $payment_date;
  try {
    $timezone = new DateTimeZone('Europe/Moscow');
    $datetime = new DateTime($payment_date, $timezone);
    $current_time = new DateTime('now', $timezone);
    $datetime->setTime(
      $current_time->format('H'),
      $current_time->format('i'),
      $current_time->format('s')
    );
    $payment_datetime = $datetime->format('Y-m-d H:i:s');
  } catch (Exception $e) {
    error_log('Ошибка обработки даты платежа: ' . $e->getMessage());
  }
  $dbconn = db_connect();
  if (!$dbconn) {
    $message = '❌ Ошибка подключения к базе данных.';
    return;
  }
  $insert_query = "INSERT INTO payments (client_id, booking_id, payment_date, payment_method, operation_type, amount, payment_status) VALUES ($1, $2, $3, $4, $5, $6, $7)";
  $params = [$client_id, $booking_id, $payment_datetime, $method, $operation_type, $amount_float, $status];
  $result = @pg_query_params($dbconn, $insert_query, $params);
  if ($result) {
    $msg = "Платеж успешно зарегистрирован!";
    header("Location: payments.php?message=" . urlencode($msg));
    exit;
  } else {
    $error_message = pg_last_error($dbconn);
    if (strpos($error_message, 'ОШИБКА:') !== false) {
      preg_match('/ОШИБКА:\s*(.*?)(?=\s*CONTEXT:|$)/u', $error_message, $matches);
      $clean_error = isset($matches[1]) ? trim($matches[1]) : "Ошибка базы данных.";
    } else {
      $clean_error = "Произошла ошибка при сохранении платежа.";
    }
    $message = $clean_error;
  }
  pg_close($dbconn);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  createPayment();
}
function searchClients() {
  $query = isset($_GET['query']) ? trim($_GET['query']) : '';
  if (empty($query)) {
    echo json_encode([]);
    return;
  }
  $dbconn = db_connect();
  if (!$dbconn) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка подключения к БД.']);
    return;
  }
  $stmt = pg_prepare($dbconn, "search_client", "SELECT client_id, full_name, phone, email FROM clients WHERE full_name ILIKE $1 OR phone ILIKE $1 OR email ILIKE $1 LIMIT 10");
  if (!$stmt) {
    pg_close($dbconn);
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка подготовки запроса.']);
    return;
  }
  $searchTerm = '%' . $query . '%';
  $result = pg_execute($dbconn, "search_client", array($searchTerm));
  if (!$result) {
    pg_close($dbconn);
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка выполнения запроса.']);
    return;
  }
  $clients = pg_fetch_all($result);
  pg_close($dbconn);
  echo json_encode($clients ?: []);
}
function fetchClientBookings() {
  $client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
  if ($client_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Некорректный ID клиента.']);
    return;
  }
  $dbconn = db_connect();
  if (!$dbconn) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка подключения к БД.']);
    return;
  }
  $status_list = ['Подтверждено', 'Активно'];
  $status_in_clause = "'" . implode("','", $status_list) . "'";
  $stmt = pg_prepare($dbconn, "fetch_bookings", "SELECT booking_id, room_number, check_in_date, check_out_date, total_amount FROM bookings WHERE client_id = $1 AND booking_status IN ($status_in_clause) ORDER BY check_in_date DESC");
  if (!$stmt) {
    pg_close($dbconn);
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка подготовки запроса.']);
    return;
  }
  $result = pg_execute($dbconn, "fetch_bookings", array($client_id));
  if (!$result) {
    pg_close($dbconn);
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка выполнения запроса.']);
    return;
  }
  $bookings = pg_fetch_all($result);
  pg_close($dbconn);
  echo json_encode($bookings ?: []);
}
if (isset($_GET['action'])) {
  header('Content-Type: application/json');
  if ($_GET['action'] === 'search_client') {
    searchClients();
  } elseif ($_GET['action'] === 'fetch_bookings') {
    fetchClientBookings();
  }
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/../../images/hotel.ico">
  <title>Менедждер гостиницы. Создание платежа</title>
  <link rel="stylesheet" href="../../css/normalize.css">
  <link rel="stylesheet" href="../../css/reset.css">
  <link rel="stylesheet" href="../../css/fonts.css">
  <link rel="stylesheet" href="../../css/payments/payment-new.css">
  <style>
    .message-box {
      padding: 15px;
      margin-bottom: 20px;
      border-radius: 8px;
      font-weight: bold;
    }
    .message-box.success {
      background-color: #d4edda;
      color: #155724;
      border: 1px solid #c3e6cb;
    }
    .message-box.error {
      background-color: #f8d7da;
      color: #721c24;
      border: 1px solid #f5c6cb;
    }
  </style>
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
            <a href="../payments/payments.php" class="nav__item-link nav__item-link_current">
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
              <h2 class="main__title">Создание платежа</h2>
              <p class="main__desc">Добавьте новый платеж в систему</p>
            </div>
            <a href="payments.php" class="main__cancel-button">Отмена</a>
          </div>
          <form action="" class="payment-form" method="POST">
            <section class="payment-form__card">
              <h3 class="payment-form__title">Информация о платеже</h3>
              <hr>
              <div class="payment-form__card-info">
                <div class="payment-form__field">
                  <label for="payment-date" class="payment-form__label">Дата платежа</label>
                  <input type="date" name="payment-date" id="payment-date" class="payment-form__date" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="payment-form__field">
                  <label for="payment-method" class="payment-form__label">Способ оплаты</label>
                  <select name="payment-method" id="payment-method" class="payment-form__select" required>
                    <option value="" disabled selected>Выберите способ</option>
                    <option value="Наличные">Наличные</option>
                    <option value="Карта">Карта</option>
                    <option value="Онлайн">Онлайн оплата</option>
                    <option value="Банковский перевод">Банковский перевод</option>
                  </select>
                </div>
                <div class="payment-form__field">
                  <label for="operation-type" class="payment-form__label">Тип операции</label>
                  <select name="operation-type" id="operation-type" class="payment-form__select" required>
                    <option value="" disabled selected>Выберите тип</option>
                    <option value="Оплата">Оплата</option>
                    <option value="Предоплата">Предоплата</option>
                    <option value="Возврат">Возврат</option>
                    <option value="Коррекция">Коррекция</option>
                  </select>
                </div>
                <div class="payment-form__field">
                  <label for="amount" class="payment-form__label">Сумма платежа</label>
                  <input type="number" step="0.01" name="amount" id="amount" placeholder="0" class="payment-form__input" required>
                </div>
                <div class="payment-form__field">
                  <label for="status-select" class="payment-form__label">Статус</label>
                  <select name="status-select" id="status-select" class="payment-form__select" required>
                    <option value="" disabled selected>Выберите статус</option>
                    <option value="Успешно">Успешно</option>
                    <option value="Отклонено">Отклонено</option>
                    <option value="В обработке">В обработке</option>
                  </select>
                </div>
              </div>
            </section>
            <section class="payment-form__card">
              <h3 class="payment-form__title">Клиент и бронирование</h3>
              <hr>
              <div class="payment-form__card-client">
                <div class="payment-form__field" id="clientSearchWrapper">
                  <label for="client-search" class="payment-form__label">Клиент</label>
                  <input type="text" name="client-search" id="clientSearch" placeholder="Поиск по ФИО, телефону или email..." class="payment-form__input" autocomplete="off" style="<?php echo $client_info ? 'display: none;' : 'display: block;'; ?>">
                  <div class="search__results" id="searchResults"></div>
                  <div class="payment-form__client" id="clientInfo" style="<?php echo $client_info ? 'display: flex;' : 'display: none;'; ?>" data-client-id="<?php echo $client_info['client_id'] ?? ''; ?>">
                    <div class="payment-form__client-data">
                      <p class="payment-form__client-name" id="selectedClientName"><?php echo $client_info['full_name'] ?? ''; ?></p>
                      <p class="payment-form__client-contact" id="selectedClientContact">
                        <?php
                        $contact_parts = [];
                        if (!empty($client_info['phone'])) $contact_parts[] = $client_info['phone'];
                        if (!empty($client_info['email'])) $contact_parts[] = $client_info['email'];
                        echo implode(' • ', $contact_parts);
                        ?>
                      </p>
                    </div>
                    <button type="button" class="payment-form__change-button" id="changeClient">Изменить</button>
                  </div>
                  <input type="hidden" name="client_id" id="selectedClientId" required value="<?php echo $selected_client_id ?? ''; ?>">
                </div>
                <div class="payment-form__field">
                  <label for="booking-select" class="payment-form__label">Бронирование</label>
                  <select name="booking_id" id="bookingSelect" class="payment-form__select" <?php echo $selected_client_id ? '' : 'disabled'; ?> required>
                    <option value="" disabled selected>
                      <?php echo $selected_client_id ? 'Загрузка бронирований...' : 'Выберите бронирование'; ?>
                    </option>
                  </select>
                </div>
              </div>
            </section>
            <div class="payment-form__buttons">
              <a href="payments.php" class="payment-form__cancel-button">Отмена</a>
              <button type="submit" id="createPaymentButton" disabled class="payment-form__create-button">Создать платеж</button>
            </div>
          </form>
        </div>
      </main>
    </div>
  </div>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const clientSearch = document.getElementById('clientSearch');
      const searchResults = document.getElementById('searchResults');
      const clientInfoBlock = document.getElementById('clientInfo');
      const changeClientButton = document.getElementById('changeClient');
      const selectedClientName = document.getElementById('selectedClientName');
      const selectedClientContact = document.getElementById('selectedClientContact');
      const selectedClientId = document.getElementById('selectedClientId');
      const bookingSelect = document.getElementById('bookingSelect');
      const createPaymentButton = document.getElementById('createPaymentButton');
      document.getElementById('payment-date').valueAsDate = new Date();
      const checkFormValidity = () => {
        const isClientSelected = selectedClientId.value !== "";
        const isBookingSelected = bookingSelect.value !== "";
        const isAmountValid = parseFloat(document.getElementById('amount').value) > 0;
        const isMethodSelected = document.getElementById('payment-method').value !== "";
        const isOperationSelected = document.getElementById('operation-type').value !== "";
        const isStatusSelected = document.getElementById('status-select').value !== "";
        createPaymentButton.disabled = !(isClientSelected && isBookingSelected && isAmountValid && isMethodSelected && isOperationSelected && isStatusSelected);
      };
      document.getElementById('amount').addEventListener('input', checkFormValidity);
      document.getElementById('payment-method').addEventListener('change', checkFormValidity);
      document.getElementById('operation-type').addEventListener('change', checkFormValidity);
      document.getElementById('status-select').addEventListener('change', checkFormValidity);
      const toggleClientSelection = (select) => {
        clientSearch.style.display = select ? 'none' : 'block';
        clientInfoBlock.style.display = select ? 'flex' : 'none';
        searchResults.style.display = 'none';
        selectedClientId.value = select ? clientInfoBlock.dataset.clientId : '';
        bookingSelect.disabled = !select;
        if (!select) {
          bookingSelect.innerHTML = '<option value="" disabled selected>Выберите бронирование</option>';
          clientSearch.value = '';
        }
        checkFormValidity();
      };
      let searchTimeout;
      clientSearch.addEventListener('input', () => {
        clearTimeout(searchTimeout);
        const query = clientSearch.value.trim();
        if (query.length < 3) {
          searchResults.innerHTML = '';
          searchResults.style.display = 'none';
          return;
        }
        searchTimeout = setTimeout(async () => {
          try {
            const response = await fetch(`payment-new.php?action=search_client&query=${encodeURIComponent(query)}`);
            const clients = await response.json();
            searchResults.innerHTML = '';
            if (clients && clients.length > 0 && clientSearch.style.display !== 'none') {
              clients.forEach(client => {
                const item = document.createElement('div');
                item.classList.add('search__result-item');
                item.dataset.clientId = client.client_id;
                const contact = [client.phone, client.email].filter(Boolean).join(' • ');
                item.innerHTML = `<p class="search__result-name">${client.full_name}</p><p class="search__result-phone">${contact}</p>`;
                item.addEventListener('click', () => handleClientSelection(client));
                searchResults.appendChild(item);
              });
              searchResults.style.display = 'block';
            } else {
              searchResults.style.display = 'none';
            }
          } catch (error) {
            console.error('Ошибка при поиске клиента:', error);
            searchResults.style.display = 'none';
          }
        }, 300);
      });
      const handleClientSelection = (client) => {
        clientInfoBlock.dataset.clientId = client.client_id;
        selectedClientName.textContent = client.full_name;
        selectedClientContact.textContent = [client.phone, client.email].filter(Boolean).join(' • ');
        toggleClientSelection(true);
        fetchClientBookings(client.client_id);
      };
      changeClientButton.addEventListener('click', () => {
        toggleClientSelection(false);
      });
      const fetchClientBookings = async (clientId) => {
        bookingSelect.innerHTML = '<option value="" disabled selected>Загрузка бронирований...</option>';
        bookingSelect.disabled = true;
        bookingSelect.value = "";
        try {
          const response = await fetch(`payment-new.php?action=fetch_bookings&client_id=${clientId}`);
          const bookings = await response.json();
          bookingSelect.innerHTML = '';
          if (bookings.error) {
            console.error('Ошибка при загрузке бронирований:', bookings.error);
            bookingSelect.innerHTML = '<option value="" disabled selected>Ошибка загрузки</option>';
            return;
          }
          const defaultOption = document.createElement('option');
          defaultOption.value = '';
          defaultOption.disabled = true;
          defaultOption.selected = true;
          defaultOption.textContent = bookings.length > 0 ? 'Выберите бронирование' : 'Нет активных бронирований';
          bookingSelect.appendChild(defaultOption);
          if (bookings && bookings.length > 0) {
            bookings.forEach(booking => {
              const option = document.createElement('option');
              option.value = booking.booking_id;
              option.textContent = `№${booking.booking_id} - Комната ${booking.room_number || 'N/A'} (${booking.check_in_date} - ${booking.check_out_date})`;
              bookingSelect.appendChild(option);
            });
            bookingSelect.disabled = false;
          }
        } catch (error) {
          console.error('Ошибка AJAX при загрузке бронирований:', error);
          bookingSelect.innerHTML = '<option value="" disabled selected>Ошибка сети</option>';
        } finally {
          checkFormValidity();
        }
      };
      bookingSelect.addEventListener('change', checkFormValidity);
      document.addEventListener('click', (event) => {
        if (!clientSearch.contains(event.target) && !searchResults.contains(event.target) && !clientInfoBlock.contains(event.target)) {
          searchResults.style.display = 'none';
        }
      });
      const initialClientId = selectedClientId.value;
      if (initialClientId) {
        fetchClientBookings(initialClientId);
        bookingSelect.disabled = false;
      }
      checkFormValidity();
    });
  </script>
  <?php if (!empty($message)): ?>
  <script>
    alert(<?php echo json_encode($message); ?>);
  </script>
  <?php endif; ?>
</body>
</html>