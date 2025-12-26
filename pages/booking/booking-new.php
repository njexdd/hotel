<?php
session_start();
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
  header("Location: ../../pages/login/login.php");
  exit;
}
require_once '../../php/config/config.php';
$dbconn = db_connect();
if (!$dbconn) {
  die("Ошибка подключения к базе данных");
}
function escape_string($dbconn, $string) {
  return pg_escape_string($dbconn, $string);
}
$selected_client_id = null;
$selected_room_number = null;
$check_in_date = '';
$check_out_date = '';
$error_message = '';
$success_message = '';
$available_rooms = [];
$nights = 0;
$total_amount = 0;
$daily_price = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['action'])) {
    switch ($_POST['action']) {
      case 'search_clients':
        $search_term = isset($_POST['search_term']) ? escape_string($dbconn, $_POST['search_term']) : '';
        $query = "SELECT client_id, full_name, phone, email
                         FROM clients
                         WHERE full_name ILIKE '%$search_term%'
                         OR phone ILIKE '%$search_term%'
                         OR email ILIKE '%$search_term%'
                         LIMIT 10";
        $result = pg_query($dbconn, $query);
        $clients = [];
        if ($result) {
          while ($row = pg_fetch_assoc($result)) {
            $clients[] = $row;
          }
        }
        echo json_encode($clients);
        exit;
      case 'get_available_rooms':
        $check_in = isset($_POST['check_in']) ? escape_string($dbconn, $_POST['check_in']) : '';
        $check_out = isset($_POST['check_out']) ? escape_string($dbconn, $_POST['check_out']) : '';
        if (empty($check_in) || empty($check_out)) {
          echo json_encode([]);
          exit;
        }
        $query = "SELECT r.room_number, r.status, rt.type_name, rt.capacity, rt.base_price
                         FROM rooms r
                         JOIN room_types rt ON r.room_type_id = rt.room_type_id
                         WHERE r.room_number NOT IN (
                             SELECT room_number
                             FROM bookings
                             WHERE NOT (check_out_date <= '$check_in' OR check_in_date >= '$check_out')
                             AND booking_status != 'Отменено'
                         )
                         AND r.status IN ('Свободен', 'Готов к заселению')
                         ORDER BY rt.base_price";
        $result = pg_query($dbconn, $query);
        $rooms = [];
        if ($result) {
          while ($row = pg_fetch_assoc($result)) {
            $room_number = escape_string($dbconn, $row['room_number']);
            $amenities_query = "SELECT a.amenity_name
                                           FROM room_amenities ra
                                           JOIN amenities a ON ra.amenity_id = a.amenity_id
                                           WHERE ra.room_number = '$room_number'";
            $amenities_result = pg_query($dbconn, $amenities_query);
            $amenities = [];
            if ($amenities_result) {
              while ($amenity = pg_fetch_assoc($amenities_result)) {
                $amenities[] = $amenity['amenity_name'];
              }
            }
            $row['amenities'] = implode(', ', $amenities);
            $rooms[] = $row;
          }
        }
        echo json_encode($rooms);
        exit;
      case 'create_booking':
        $client_id = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
        $room_number = isset($_POST['room_number']) ? escape_string($dbconn, $_POST['room_number']) : '';
        $check_in = isset($_POST['check_in']) ? escape_string($dbconn, $_POST['check_in']) : '';
        $check_out = isset($_POST['check_out']) ? escape_string($dbconn, $_POST['check_out']) : '';
        if (!$client_id || empty($room_number) || empty($check_in) || empty($check_out)) {
          echo json_encode(['success' => false, 'message' => 'Не все поля заполнены']);
          exit;
        }
        $check_query = "SELECT COUNT(*) as count
                               FROM bookings
                               WHERE room_number = '$room_number'
                               AND NOT (check_out_date <= '$check_in' OR check_in_date >= '$check_out')
                               AND booking_status != 'Отменено'";
        $check_result = pg_query($dbconn, $check_query);
        $check_row = $check_result ? pg_fetch_assoc($check_result) : ['count' => 0];
        if ($check_row['count'] > 0) {
          echo json_encode(['success' => false, 'message' => 'Номер уже забронирован на выбранные даты']);
          exit;
        }
        $status_query = "SELECT status FROM rooms WHERE room_number = '$room_number'";
        $status_result = pg_query($dbconn, $status_query);
        $status_row = $status_result ? pg_fetch_assoc($status_result) : ['status' => ''];
        $available_statuses = ['Свободен', 'Готов к заселению'];
        if (!in_array($status_row['status'], $available_statuses)) {
          echo json_encode(['success' => false, 'message' => 'Номер недоступен для бронирования. Текущий статус: ' . $status_row['status']]);
          exit;
        }
        $price_query = "SELECT rt.base_price
                               FROM rooms r
                               JOIN room_types rt ON r.room_type_id = rt.room_type_id
                               WHERE r.room_number = '$room_number'";
        $price_result = pg_query($dbconn, $price_query);
        $price_row = $price_result ? pg_fetch_assoc($price_result) : ['base_price' => 0];
        $daily_price = $price_row['base_price'];
        $check_in_obj = new DateTime($check_in);
        $check_out_obj = new DateTime($check_out);
        $nights = $check_in_obj->diff($check_out_obj)->days;
        $total_amount = $nights * $daily_price;
        $insert_query = "INSERT INTO bookings
                                (client_id, room_number, check_in_date, check_out_date,
                                 total_nights, total_amount, booking_status)
                                VALUES
                                ($client_id, '$room_number', '$check_in', '$check_out',
                                 $nights, $total_amount, 'Подтверждено')
                                RETURNING booking_id";
        $result = pg_query($dbconn, $insert_query);
        if ($result) {
          $row = pg_fetch_assoc($result);
          $booking_id = $row['booking_id'];
          $today = date('Y-m-d');
          if ($check_in <= $today) {
            $new_status = 'Занят';
          } else {
            $new_status = 'Забронирован';
          }
          pg_query($dbconn, "UPDATE rooms SET status = '$new_status' WHERE room_number = '$room_number'");
          echo json_encode(['success' => true, 'booking_id' => $booking_id]);
        } else {
          $error_msg = pg_last_error($dbconn);
          if (strpos($error_msg, 'bookings_pkey') !== false || strpos($error_msg, 'последовательности') !== false) {
            $fix_query = "SELECT setval('bookings_booking_id_seq', COALESCE((SELECT MAX(booking_id) FROM bookings), 0) + 1, false)";
            pg_query($fix_query);
            $result = pg_query($dbconn, $insert_query);
            if ($result) {
              $row = pg_fetch_assoc($result);
              $booking_id = $row['booking_id'];
              $today = date('Y-m-d');
              if ($check_in <= $today) {
                $new_status = 'Занят';
              } else {
                $new_status = 'Забронирован';
              }
              pg_query($dbconn, "UPDATE rooms SET status = '$new_status' WHERE room_number = '$room_number'");
              echo json_encode(['success' => true, 'booking_id' => $booking_id]);
            } else {
              echo json_encode(['success' => false, 'message' => 'Ошибка при создании бронирования после исправления sequence: ' . pg_last_error($dbconn)]);
            }
          } else {
            echo json_encode(['success' => false, 'message' => 'Ошибка при создании бронирования: ' . $error_msg]);
          }
        }
        exit;
    }
  }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_booking'])) {
  $selected_client_id = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
  $selected_room_number = isset($_POST['room_number']) ? $_POST['room_number'] : '';
  $check_in_date = isset($_POST['check_in']) ? $_POST['check_in'] : '';
  $check_out_date = isset($_POST['check_out']) ? $_POST['check_out'] : '';
  if (strtotime($check_out_date) <= strtotime($check_in_date)) {
    $error_message = "Дата выезда должна быть позже даты заезда";
  } else {
    $check_in_obj = new DateTime($check_in_date);
    $check_out_obj = new DateTime($check_out_date);
    $nights = $check_in_obj->diff($check_out_obj)->days;
    if ($selected_room_number) {
      $room_number_escaped = escape_string($dbconn, $selected_room_number);
      $room_query = "SELECT rt.base_price
                          FROM rooms r
                          JOIN room_types rt ON r.room_type_id = rt.room_type_id
                          WHERE r.room_number = '$room_number_escaped'";
      $room_result = pg_query($dbconn, $room_query);
      if ($room_result && $room_row = pg_fetch_assoc($room_result)) {
        $daily_price = $room_row['base_price'];
        $total_amount = $nights * $daily_price;
      }
    }
  }
}
$client_info = null;
if (isset($_GET['client_id'])) {
  $selected_client_id = (int)$_GET['client_id'];
  $query = "SELECT * FROM clients WHERE client_id = $selected_client_id";
  $result = pg_query($dbconn, $query);
  if ($result) {
    $client_info = pg_fetch_assoc($result);
  }
}
if ($check_in_date && $check_out_date && strtotime($check_out_date) > strtotime($check_in_date)) {
  $check_in_escaped = escape_string($dbconn, $check_in_date);
  $check_out_escaped = escape_string($dbconn, $check_out_date);
  $query = "SELECT r.room_number, r.status, rt.type_name, rt.capacity, rt.base_price
             FROM rooms r
             JOIN room_types rt ON r.room_type_id = rt.room_type_id
             WHERE r.room_number NOT IN (
                 SELECT room_number
                 FROM bookings
                 WHERE NOT (check_out_date <= '$check_in_escaped' OR check_in_date >= '$check_out_escaped')
                 AND booking_status != 'Отменено'
             )
             AND r.status IN ('Свободен', 'Готов к заселению')
             ORDER BY rt.base_price";
  $result = pg_query($dbconn, $query);
  if ($result) {
    while ($row = pg_fetch_assoc($result)) {
      $room_number_escaped = escape_string($dbconn, $row['room_number']);
      $amenities_query = "SELECT a.amenity_name
                               FROM room_amenities ra
                               JOIN amenities a ON ra.amenity_id = a.amenity_id
                               WHERE ra.room_number = '$room_number_escaped'";
      $amenities_result = pg_query($dbconn, $amenities_query);
      $amenities = [];
      if ($amenities_result) {
        while ($amenity = pg_fetch_assoc($amenities_result)) {
          $amenities[] = $amenity['amenity_name'];
        }
      }
      $row['amenities'] = implode(', ', $amenities);
      $available_rooms[] = $row;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/../../images/hotel.ico">
  <title>Менедждер гостиницы. Создание бронирования</title>
  <link rel="stylesheet" href="../../css/normalize.css">
  <link rel="stylesheet" href="../../css/reset.css">
  <link rel="stylesheet" href="../../css/fonts.css">
  <link rel="stylesheet" href="../../css/bookings/booking-new.css">
</head>
<body>
  <?php if ($error_message): ?>
    <script>alert("Ошибка: <?php echo htmlspecialchars($error_message, ENT_QUOTES); ?>");</script>
  <?php endif; ?>
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
              <h2 class="main__title">Создание бронирования</h2>
              <p class="main__desc">Создайте новое бронирование для гостя</p>
            </div>
            <a href="bookings.php" class="main__cancel-button">Отмена</a>
          </div>
          <?php if ($error_message): ?>
            <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
          <?php endif; ?>
          <?php if ($success_message): ?>
            <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
          <?php endif; ?>
          <form action="" method="POST" class="booking-form" id="bookingForm">
            <input type="hidden" name="client_id" id="client_id" value="<?php echo $selected_client_id; ?>">
            <input type="hidden" name="room_number" id="room_number" value="<?php echo htmlspecialchars($selected_room_number); ?>">
            <section class="booking-form__customers-choice">
              <h3 class="booking-form__title">Выбор клиента</h3>
              <div class="booking-form__field">
                <label for="client-search" class="booking-form__label">Поиск клиента</label>
                <input type="text" name="client-search" id="clientSearch" placeholder="Поиск по ФИО, телефону или email..." class="booking-form__input">
                <div class="search__results" id="searchResults"></div>
              </div>
              <div class="booking-form__client" id="clientInfo" style="<?php echo $client_info ? 'display: flex' : 'display: none'; ?>">
                <div class="booking-form__client-data">
                  <p class="booking-form__client-name">
                    <?php echo $client_info ? htmlspecialchars($client_info['full_name']) : ''; ?>
                  </p>
                  <p class="booking-form__client-contact">
                    <?php
                    if ($client_info) {
                      $contact = [];
                      if ($client_info['phone']) $contact[] = htmlspecialchars($client_info['phone']);
                      if ($client_info['email']) $contact[] = htmlspecialchars($client_info['email']);
                      echo implode(' • ', $contact);
                    }
                    ?>
                  </p>
                </div>
                <button type="button" class="booking-form__change-button" id="changeClient">Изменить</button>
              </div>
              <a href="../client/client-new.php" class="booking-form__create-user" id="createClient" style="<?php echo !$client_info ? 'display: flex' : 'display: none'; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-plus w-4 h-4"><path d="M5 12h14"></path><path d="M12 5v14"></path></svg>
                Создать клиента
              </a>
            </section>
            <section class="booking-form__dates" id="datesSection" style="<?php echo $selected_client_id ? 'display: block' : 'display: none'; ?>">
              <h3 class="booking-form__title">Даты проживания</h3>
              <div class="booking-form__fields">
                <div class="booking-form__field">
                  <label for="date-check-in" class="booking-form__label">Дата заезда</label>
                  <input type="date" name="date-check-in" id="dateCheckIn" class="booking-form__input"
                         value="<?php echo htmlspecialchars($check_in_date); ?>"
                         min="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="booking-form__field">
                  <label for="date-check-out" class="booking-form__label">Дата выезда</label>
                  <input type="date" name="date-check-out" id="dateCheckOut" class="booking-form__input"
                         value="<?php echo htmlspecialchars($check_out_date); ?>"
                         min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                </div>
              </div>
            </section>
            <section class="booking-form__room-selection" id="roomsSection" style="<?php echo ($check_in_date && $check_out_date) ? 'display: block' : 'display: none'; ?>">
              <h3 class="booking-form__title">Выбор номера</h3>
              <div class="booking-form__rooms" id="roomsContainer">
                <?php if ($available_rooms): ?>
                  <?php foreach ($available_rooms as $room): ?>
                    <div class="booking-form__room <?php echo $selected_room_number == $room['room_number'] ? 'booking-form__room_selected' : ''; ?>"
                         data-room="<?php echo htmlspecialchars($room['room_number']); ?>"
                         data-price="<?php echo $room['base_price']; ?>">
                      <div class="booking-form__room-card">
                        <div class="booking-form__room-parameters">
                          <p class="booking-form__room-number">Номер <?php echo htmlspecialchars($room['room_number']); ?></p>
                          <p class="booking-form__room-type"><?php echo htmlspecialchars($room['type_name']); ?></p>
                          <p class="booking-form__room-capacity"><?php echo $room['capacity']; ?> чел.</p>
                        </div>
                        <p class="booking-form__room-equipment"><?php echo htmlspecialchars($room['amenities']); ?></p>
                        <p class="booking-form__room-cost"><?php echo number_format($room['base_price'], 0, '', ' '); ?> ₽/ночь</p>
                      </div>
                      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-check w-6 h-6"
                           style="<?php echo $selected_room_number == $room['room_number'] ? 'display: block' : 'display: none'; ?>">
                        <path d="M20 6 9 17l-5-5"></path>
                      </svg>
                    </div>
                  <?php endforeach; ?>
                <?php else: ?>
                  <p>Нет доступных номеров на выбранные даты</p>
                <?php endif; ?>
              </div>
            </section>
            <section class="booking-form__calculation" id="calculationSection" style="<?php echo $selected_room_number ? 'display: block' : 'display: none'; ?>">
              <h3 class="booking-form__title">Расчёт стоимости</h3>
              <p class="booking-form__number-days">
                <span>Количество суток:</span>
                <span id="nightsCount"><?php echo $nights; ?> ноч(и)</span>
              </p>
              <p class="booking-form__price-day">
                <span>Цена за сутки:</span>
                <span id="dailyPrice"><?php echo number_format($daily_price, 0, '', ' '); ?> ₽</span>
              </p>
              <hr>
              <p class="booking-form__total">
                <span class="booking-form__total_bold">Итого:</span>
                <span class="booking-form__total_cost" id="totalAmount"><?php echo number_format($total_amount, 0, '', ' '); ?> ₽</span>
              </p>
            </section>
            <div class="booking-form__buttons">
              <a href="bookings.php" class="booking-form__cancel-button">Отмена</a>
              <button type="button" class="booking-form__create-button" id="createBooking"
                      <?php echo ($selected_client_id && $selected_room_number && $check_in_date && $check_out_date) ? '' : 'disabled'; ?>>
                Создать бронирование
              </button>
            </div>
          </form>
        </div>
      </main>
    </div>
  </div>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const clientSearch = document.getElementById('clientSearch');
      const searchResults = document.getElementById('searchResults');
      const clientInfo = document.getElementById('clientInfo');
      const createClientBtn = document.getElementById('createClient');
      const changeClientBtn = document.getElementById('changeClient');
      const clientIdInput = document.getElementById('client_id');
      const datesSection = document.getElementById('datesSection');
      const dateCheckIn = document.getElementById('dateCheckIn');
      const dateCheckOut = document.getElementById('dateCheckOut');
      const roomsSection = document.getElementById('roomsSection');
      const roomsContainer = document.getElementById('roomsContainer');
      const calculationSection = document.getElementById('calculationSection');
      const roomNumberInput = document.getElementById('room_number');
      const nightsCount = document.getElementById('nightsCount');
      const dailyPrice = document.getElementById('dailyPrice');
      const totalAmount = document.getElementById('totalAmount');
      const createBookingBtn = document.getElementById('createBooking');
      let searchTimeout;
      let selectedDailyPrice = 0;
      clientSearch.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const searchTerm = this.value.trim();
        if (searchTerm.length < 2) {
          searchResults.style.display = 'none';
          return;
        }
        searchTimeout = setTimeout(() => {
          fetch('', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=search_clients&search_term=' + encodeURIComponent(searchTerm)
          })
          .then(response => response.json())
          .then(clients => {
            searchResults.innerHTML = '';
            if (clients.length > 0) {
              clients.forEach(client => {
                const item = document.createElement('div');
                item.className = 'search__result-item';
                item.innerHTML = `
                  <p class="search__result-name">${client.full_name}</p>
                  <p class="search__result-phone">${client.phone}</p>
                `;
                item.addEventListener('click', () => {
                  selectClient(client);
                });
                searchResults.appendChild(item);
              });
              searchResults.style.display = 'block';
            } else {
              searchResults.style.display = 'none';
            }
          });
        }, 300);
      });
      document.addEventListener('click', function(e) {
        if (!clientSearch.contains(e.target) && !searchResults.contains(e.target)) {
          searchResults.style.display = 'none';
        }
      });
      function selectClient(client) {
        clientSearch.value = '';
        searchResults.style.display = 'none';
        clientIdInput.value = client.client_id;
        const clientName = clientInfo.querySelector('.booking-form__client-name');
        const clientContact = clientInfo.querySelector('.booking-form__client-contact');
        clientName.textContent = client.full_name;
        let contact = [];
        if (client.phone) contact.push(client.phone);
        if (client.email) contact.push(client.email);
        clientContact.textContent = contact.join(' • ');
        clientInfo.style.display = 'flex';
        createClientBtn.style.display = 'none';
        datesSection.style.display = 'block';
        resetBookingData();
      }
      changeClientBtn.addEventListener('click', function() {
        clientInfo.style.display = 'none';
        createClientBtn.style.display = 'flex';
        clientIdInput.value = '';
        datesSection.style.display = 'none';
        resetBookingData();
      });
      dateCheckIn.addEventListener('change', function() {
        if (this.value) {
          const minCheckOut = new Date(this.value);
          minCheckOut.setDate(minCheckOut.getDate() + 1);
          dateCheckOut.min = minCheckOut.toISOString().split('T')[0];
          if (dateCheckOut.value && dateCheckOut.value <= this.value) {
            dateCheckOut.value = '';
          }
        }
        updateRooms();
      });
      dateCheckOut.addEventListener('change', function() {
        if (dateCheckIn.value && this.value) {
          updateRooms();
        }
      });
      function updateRooms() {
        if (!dateCheckIn.value || !dateCheckOut.value) return;
        if (new Date(dateCheckOut.value) <= new Date(dateCheckIn.value)) {
          alert('Дата выезда должна быть позже даты заезда');
          return;
        }
        fetch('', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: 'action=get_available_rooms&check_in=' + dateCheckIn.value + '&check_out=' + dateCheckOut.value
        })
        .then(response => response.json())
        .then(rooms => {
          roomsContainer.innerHTML = '';
          if (rooms.length > 0) {
            rooms.forEach(room => {
              const roomElement = document.createElement('div');
              roomElement.className = 'booking-form__room';
              roomElement.dataset.room = room.room_number;
              roomElement.dataset.price = room.base_price;
              roomElement.innerHTML = `
                <div class="booking-form__room-card">
                  <div class="booking-form__room-parameters">
                    <p class="booking-form__room-number">Номер ${room.room_number}</p>
                    <p class="booking-form__room-type">${room.type_name}</p>
                    <p class="booking-form__room-capacity">${room.capacity} чел.</p>
                  </div>
                  <p class="booking-form__room-equipment">${room.amenities || ''}</p>
                  <p class="booking-form__room-cost">${parseInt(room.base_price).toLocaleString()} ₽/ночь</p>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-check w-6 h-6" style="display: none;">
                  <path d="M20 6 9 17l-5-5"></path>
                </svg>
              `;
              roomElement.addEventListener('click', () => selectRoom(roomElement));
              roomsContainer.appendChild(roomElement);
            });
            roomsSection.style.display = 'block';
          } else {
            roomsContainer.innerHTML = '<p>Нет доступных номеров на выбранные даты</p>';
            roomsSection.style.display = 'block';
            calculationSection.style.display = 'none';
          }
          roomNumberInput.value = '';
          selectedDailyPrice = 0;
          updateCalculation();
        });
      }
      function selectRoom(roomElement) {
        document.querySelectorAll('.booking-form__room').forEach(r => {
          r.classList.remove('booking-form__room_selected');
          r.querySelector('svg').style.display = 'none';
        });
        roomElement.classList.add('booking-form__room_selected');
        roomElement.querySelector('svg').style.display = 'block';
        roomNumberInput.value = roomElement.dataset.room;
        selectedDailyPrice = parseFloat(roomElement.dataset.price);
        calculationSection.style.display = 'block';
        updateCalculation();
        updateCreateButton();
      }
      function updateCalculation() {
        if (!dateCheckIn.value || !dateCheckOut.value || selectedDailyPrice === 0) return;
        const checkIn = new Date(dateCheckIn.value);
        const checkOut = new Date(dateCheckOut.value);
        const nights = Math.ceil((checkOut - checkIn) / (1000 * 60 * 60 * 24));
        const total = nights * selectedDailyPrice;
        nightsCount.textContent = `${nights} ноч(и)`;
        dailyPrice.textContent = `${selectedDailyPrice.toLocaleString()} ₽`;
        totalAmount.textContent = `${total.toLocaleString()} ₽`;
      }
      function updateCreateButton() {
        if (clientIdInput.value && roomNumberInput.value && dateCheckIn.value && dateCheckOut.value) {
          createBookingBtn.disabled = false;
        } else {
          createBookingBtn.disabled = true;
        }
      }
      function resetBookingData() {
        dateCheckIn.value = '';
        dateCheckOut.value = '';
        roomNumberInput.value = '';
        selectedDailyPrice = 0;
        roomsSection.style.display = 'none';
        calculationSection.style.display = 'none';
        roomsContainer.innerHTML = '';
        nightsCount.textContent = '0 ноч(и)';
        dailyPrice.textContent = '0 ₽';
        totalAmount.textContent = '0 ₽';
        updateCreateButton();
      }
      createBookingBtn.addEventListener('click', function() {
        if (!clientIdInput.value || !roomNumberInput.value || !dateCheckIn.value || !dateCheckOut.value) {
          alert('Пожалуйста, заполните все поля');
          return;
        }
        if (confirm('Вы уверены, что хотите создать бронирование?')) {
          fetch('', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=create_booking&client_id=' + clientIdInput.value +
                  '&room_number=' + roomNumberInput.value +
                  '&check_in=' + dateCheckIn.value +
                  '&check_out=' + dateCheckOut.value
          })
          .then(response => response.json())
          .then(result => {
            if (result.success) {
              alert('Бронирование успешно создано! ID: ' + result.booking_id);
              window.location.href = 'bookings.php';
            } else {
              alert('Ошибка: ' + result.message);
            }
          })
          .catch(error => {
            alert('Ошибка при отправке запроса');
          });
        }
      });
      const today = new Date().toISOString().split('T')[0];
      dateCheckIn.min = today;
      [dateCheckIn, dateCheckOut].forEach(input => {
        input.addEventListener('change', updateCreateButton);
      });
      updateCreateButton();
    });
  </script>
</body>
</html>