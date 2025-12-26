<?php
session_start();
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
  header("Location: ../../pages/login/login.php");
  exit;
}
require_once '../../php/config/config.php';
$booking_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$booking_id) {
  header("Location: ../booking/bookings.php");
  exit;
}
function formatStatus($status_key) {
  $statuses = [
    'Активно'      => 'Активно',
    'Подтверждено' => 'Подтверждено',
    'Завершено'    => 'Завершено',
    'Отменено'     => 'Отменено',
    'Просрочено'   => 'Просрочено',
  ];
  return $statuses[$status_key] ?? 'Неизвестно';
}
function getStatusCssKey($status_ru) {
  $status_map = [
    'Активно'      => 'actively',
    'Подтверждено' => 'confirmed',
    'Завершено'    => 'completed',
    'Отменено'     => 'cancelled',
    'Просрочено'   => 'overdue',
  ];
  return $status_map[$status_ru] ?? 'unknown';
}
function formatCurrency($amount) {
  return number_format($amount, 0, '.', ' ') . ' ₽';
}
function getRoomStatusClass($room_status) {
  $status_map = [
    'Свободен'            => 'rooms__table-cell_free',
    'Готов к заселению'   => 'rooms__table-cell_ready',
    'Занят'               => 'booking-info__field-value_busy',
    'На ремонте'          => 'rooms__table-cell_repair',
    'Забронирован'        => 'rooms__table-cell_booked',
    'Убирается'           => 'rooms__table-cell_cleaning',
    'Нуждается в уборке'  => 'rooms__table-cell_need-cleaning',
  ];
  return $status_map[$room_status] ?? 'booking-info__field-value_busy';
}
try {
  $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
  $pdo = new PDO($dsn, DB_USER, DB_PASSWORD);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $sql = "
    SELECT
      b.booking_id, b.client_id, b.room_number, b.check_in_date, b.check_out_date,
      b.total_nights, b.total_amount, b.booking_status,
      c.full_name AS client_fio, c.phone AS client_phone, c.email AS client_email,
      r.status AS room_current_status,
      rt.type_name AS room_type, rt.capacity AS room_capacity, rt.base_price AS room_price_per_night,
      (
        SELECT 
          d.document_type || ' №' || d.series_number 
        FROM 
          documents d
        WHERE 
          d.client_id = c.client_id 
          AND d.series_number IS NOT NULL
        ORDER BY 
          CASE d.document_type 
            WHEN 'Паспорт' THEN 1
            WHEN 'Загранпаспорт' THEN 2
            ELSE 3
          END,
          d.document_id DESC
        LIMIT 1
      ) AS client_document_info
    FROM
      bookings b
    JOIN clients c ON b.client_id = c.client_id
    JOIN rooms r ON b.room_number = r.room_number
    JOIN room_types rt ON r.room_type_id = rt.room_type_id
    WHERE
      b.booking_id = :booking_id
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
  $stmt->execute();
  $booking_details = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$booking_details) {
    echo "Ошибка: Бронирование с ID $booking_id не найдено.";
    exit;
  }
  $sql_amenities = "
    SELECT
      STRING_AGG(a.amenity_name, ', ') AS amenities_list
    FROM
      room_amenities ra
    JOIN amenities a ON ra.amenity_id = a.amenity_id
    WHERE
      ra.room_number = :room_number
  ";
  $stmt_amenities = $pdo->prepare($sql_amenities);
  $stmt_amenities->bindParam(':room_number', $booking_details['room_number']);
  $stmt_amenities->execute();
  $amenities_result = $stmt_amenities->fetch(PDO::FETCH_ASSOC);
  $booking_details['room_amenities'] = $amenities_result['amenities_list'] ?? 'Не указано';
  $sql_total_bookings = "
    SELECT
      COUNT(booking_id) AS total_bookings
    FROM
      bookings
    WHERE
      client_id = :client_id
  ";
  $stmt_total_bookings = $pdo->prepare($sql_total_bookings);
  $stmt_total_bookings->bindParam(':client_id', $booking_details['client_id'], PDO::PARAM_INT);
  $stmt_total_bookings->execute();
  $total_bookings_result = $stmt_total_bookings->fetch(PDO::FETCH_ASSOC);
  $booking_details['client_total_bookings'] = $total_bookings_result['total_bookings'] ?? 0;
} catch (PDOException $e) {
  die("Ошибка БД: " . $e->getMessage());
}
$status_key = $booking_details['booking_status'];
$status_text = formatStatus($status_key);
$status_css_key = getStatusCssKey($status_key);
$room_status_class = getRoomStatusClass($booking_details['room_current_status']);
$initial_data = [
  'check_in_date'  => $booking_details['check_in_date'],
  'check_out_date' => $booking_details['check_out_date'],
  'total_nights'   => $booking_details['total_nights'],
  'total_amount'   => $booking_details['total_amount'],
  'base_price'     => $booking_details['room_price_per_night'],
  'status'         => $booking_details['booking_status']
];
$initial_data_json = json_encode($initial_data);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/../../images/hotel.ico">
  <title>Менедждер гостиницы. Карточка бронирования #<?php echo htmlspecialchars($booking_details['booking_id']); ?></title>
  <link rel="stylesheet" href="../../css/normalize.css">
  <link rel="stylesheet" href="../../css/reset.css">
  <link rel="stylesheet" href="../../css/fonts.css">
  <link rel="stylesheet" href="../../css/bookings/booking-card.css">
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
              <h2 class="main__title">Бронирование #<?php echo htmlspecialchars($booking_details['booking_id']); ?></h2>
              <p class="main__status main__status_<?php echo htmlspecialchars($status_css_key); ?>"><?php echo htmlspecialchars($status_text); ?></p>
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
              <button id="delete-button" class="main__button main__button_red" onclick="deleteBooking()">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trash2 w-4 h-4"><path d="M3 6h18"></path><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path><line x1="10" x2="10" y1="11" y2="17"></line><line x1="14" x2="14" y1="11" y2="17"></line></svg>
                <span id="delete-button-text">Удалить</span>
              </button>
              <button id="cancel-button" class="main__button" style="display: none;" onclick="cancelChanges()">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x w-4 h-4"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg>
                Отменить изменения
              </button>
            </div>
          </div>
          <section class="booking-info">
            <section class="booking-info__client">
              <header class="booking-info__header">
                <h3 class="booking-info__title">Информация о клиенте</h3>
                <a href="../client/client-card.php?id=<?php echo htmlspecialchars($booking_details['client_id']); ?>" class="booking-info__link">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-external-link w-4 h-4"><path d="M15 3h6v6"></path><path d="M10 14 21 3"></path><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path></svg>
                  Открыть карточку клиента
                </a>
              </header>
              <hr>
              <div class="booking-info__client-info">
                <div class="booking-info__field">
                  <p class="booking-info__field-title">ФИО</p>
                  <p class="booking-info__field-value booking-info__field-value_semibold"><?php echo htmlspecialchars($booking_details['client_fio']); ?></p>
                </div>
                <div class="booking-info__field">
                  <p class="booking-info__field-title">Телефон</p>
                  <p class="booking-info__field-value"><?php echo htmlspecialchars($booking_details['client_phone']); ?></p>
                </div>
                <div class="booking-info__field">
                  <p class="booking-info__field-title">Email</p>
                  <p class="booking-info__field-value"><?php echo htmlspecialchars($booking_details['client_email']); ?></p>
                </div>
                <div class="booking-info__field">
                  <p class="booking-info__field-title">Основной документ</p> 
                  <p class="booking-info__field-value"><?php echo htmlspecialchars($booking_details['client_document_info'] ?? 'Нет данных'); ?></p> 
                </div>
                <div class="booking-info__field">
                  <p class="booking-info__field-title">Всего бронирований</p>
                  <p class="booking-info__field-value"><?php echo htmlspecialchars($booking_details['client_total_bookings']); ?></p>
                </div>
              </div>
            </section>
            <section class="booking-info__room">
              <header class="booking-info__header">
                <h3 class="booking-info__title">Информация о номере</h3>
                <div class="booking-info__actions">
                  <a href="../room/room-card.php?id=<?php echo htmlspecialchars($booking_details['room_number']); ?>" class="booking-info__link">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-external-link w-4 h-4"><path d="M15 3h6v6"></path><path d="M10 14 21 3"></path><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path></svg>
                    Открыть карточку номера
                  </a>
                </div>
              </header>
              <hr>
              <div class="booking-info__room-info">
                <div class="booking-info__field">
                  <p class="booking-info__field-title">Номер комнаты</p>
                  <p class="booking-info__field-value booking-info__field-value_semibold"><?php echo htmlspecialchars($booking_details['room_number']); ?></p>
                </div>
                <div class="booking-info__field">
                  <p class="booking-info__field-title">Тип номера</p>
                  <p class="booking-info__field-value"><?php echo htmlspecialchars($booking_details['room_type']); ?></p>
                </div>
                <div class="booking-info__field">
                  <p class="booking-info__field-title">Вместимость</p>
                  <p class="booking-info__field-value"><?php echo htmlspecialchars($booking_details['room_capacity']); ?> чел.</p>
                </div>
                <div class="booking-info__field">
                  <p class="booking-info__field-title">Цена за ночь</p>
                  <p id="room-price-per-night" data-price="<?php echo htmlspecialchars($booking_details['room_price_per_night']); ?>" class="booking-info__field-value"><?php echo formatCurrency($booking_details['room_price_per_night']); ?></p>
                </div>
                <div class="booking-info__field">
                  <p class="booking-info__field-title">Статус номера</p>
                  <p class="booking-info__field-value">
                    <span class="<?php echo htmlspecialchars($room_status_class); ?>">
                      <?php echo htmlspecialchars($booking_details['room_current_status']); ?>
                    </span>
                  </p>
                </div>
                <div class="booking-info__field">
                  <p class="booking-info__field-title">Оснащение</p>
                  <p class="booking-info__field-value"><?php echo htmlspecialchars($booking_details['room_amenities']); ?></p>
                </div>
              </div>
            </section>
            <section class="booking-info__period">
              <header class="booking-info__header">
                <h3 class="booking-info__title">Период проживания</h3>
              </header>
              <hr>
              <div class="booking-info__period-info">
                <div class="booking-info__field">
                  <p class="booking-info__field-title booking-info__field-title_semibold">Дата заезда</p>
                  <input type="date" disabled id="check-in-date" name="from" 
                         value="<?php echo htmlspecialchars($booking_details['check_in_date']); ?>" 
                         class="booking-info__input" onchange="calculateNightsAndTotal()">
                </div>
                <div class="booking-info__field">
                  <p class="booking-info__field-title booking-info__field-title_semibold">Дата выезда</p>
                  <input type="date" disabled id="check-out-date" name="to" 
                         value="<?php echo htmlspecialchars($booking_details['check_out_date']); ?>" 
                         class="booking-info__input" onchange="calculateNightsAndTotal()">
                </div>
                <div class="booking-info__field">
                  <p class="booking-info__field-title booking-info__field-title_semibold">Количество суток</p>
                  <input type="text" disabled id="total-nights" name="number-days" class="booking-info__input" value="<?php echo htmlspecialchars($booking_details['total_nights']); ?>">
                </div>
              </div>
            </section>
            <section class="booking-info__cost">
              <header class="booking-info__header">
                <h3 class="booking-info__title">Стоимость бронирования</h3>
              </header>
              <hr>
              <div class="booking-info__cost-info">
                <div class="booking-info__field">
                  <p class="booking-info__field-title">Цена за ночь</p>
                  <p class="booking-info__field-value booking-info__field-value_bold"><?php echo formatCurrency($booking_details['room_price_per_night']); ?></p>
                </div>
                <div class="booking-info__field">
                  <p class="booking-info__field-title">Количество гостей</p>
                  <p class="booking-info__field-value booking-info__field-value_bold"><?php echo htmlspecialchars($booking_details['room_capacity']); ?></p>
                </div>
                <div class="booking-info__field">
                  <p class="booking-info__field-title">Итоговая стоимость</p>
                  <p id="total-amount-display" class="booking-info__field-value booking-info__field-value_cost"><?php echo formatCurrency($booking_details['total_amount']); ?></p>
                </div>
              </div>
            </section>
            <section class="booking-info__status">
              <header class="booking-info__header">
                <h3 class="booking-info__title">Управление статусом бронирования</h3>
              </header>
              <hr>
              <div class="booking-info__status-info">
                <div class="booking-info__field">
                  <p class="booking-info__field-title">Статус</p>
                  <div class="booking-info__status-block">
                    <select disabled name="status-select" id="status-select" class="booking-info__select">
                      <option value="Активно" <?php echo ($status_key == 'Активно' ? 'selected' : ''); ?>>Активно</option>
                      <option value="Подтверждено" <?php echo ($status_key == 'Подтверждено' ? 'selected' : ''); ?>>Подтверждено</option>
                      <option value="Завершено" <?php echo ($status_key == 'Завершено' ? 'selected' : ''); ?>>Завершено</option>
                      <option value="Отменено" <?php echo ($status_key == 'Отменено' ? 'selected' : ''); ?>>Отменено</option>
                      <option value="Просрочено" <?php echo ($status_key == 'Просрочено' ? 'selected' : ''); ?>>Просрочено</option>
                    </select>
                    <span id="status-indicator" class="booking-info__status-indicator booking-info__status_<?php echo htmlspecialchars($status_css_key); ?>"><?php echo htmlspecialchars($status_text); ?></span>
                  </div>
                </div>
              </div>
            </section>
          </section>
        </div>
      </main>
    </div>
  </div>
  <script>
    const INITIAL_DATA = <?php echo $initial_data_json; ?>;
    function formatCurrencyDisplay(amount) {
      return amount.toLocaleString('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }
    function setMinCheckOutDate() {
      const checkInInput = document.getElementById('check-in-date');
      const checkOutInput = document.getElementById('check-out-date');
      if (checkInInput.value) {
        const checkInDate = new Date(checkInInput.value);
        checkInDate.setDate(checkInDate.getDate() + 1);
        const minDateString = checkInDate.toISOString().split('T')[0];
        checkOutInput.min = minDateString;
        if (checkOutInput.value && checkOutInput.value < minDateString) {
          checkOutInput.value = minDateString;
        }
      } else {
        checkOutInput.min = '';
      }
    }
    function calculateNightsAndTotal() {
      const checkInInput = document.getElementById('check-in-date');
      const checkOutInput = document.getElementById('check-out-date');
      const nightsInput = document.getElementById('total-nights');
      const totalAmountDisplay = document.getElementById('total-amount-display');
      const pricePerNight = parseFloat(document.getElementById('room-price-per-night').getAttribute('data-price'));
      const checkInDate = new Date(checkInInput.value);
      const checkOutDate = new Date(checkOutInput.value);
      if (!checkInInput.value || !checkOutInput.value) {
        nightsInput.value = 0;
        totalAmountDisplay.textContent = formatCurrencyDisplay(0);
        return;
      }
      setMinCheckOutDate();
      if (checkOutDate <= checkInDate) {
        nightsInput.value = 0;
        totalAmountDisplay.textContent = 'Ошибка даты';
        alert('Дата выезда должна быть позже даты заезда минимум на один день.');
        return;
      }
      const timeDifference = checkOutDate.getTime() - checkInDate.getTime();
      const nights = Math.ceil(timeDifference / (1000 * 3600 * 24));
      const totalAmount = nights * pricePerNight;
      nightsInput.value = nights;
      totalAmountDisplay.textContent = formatCurrencyDisplay(totalAmount);
    }
    function toggleEditMode() {
      const isEditing = document.getElementById('edit-button').style.display === 'none';
      const editButton = document.getElementById('edit-button');
      const saveButton = document.getElementById('save-button');
      const deleteButton = document.getElementById('delete-button');
      const cancelButton = document.getElementById('cancel-button');
      const checkInInput = document.getElementById('check-in-date');
      const checkOutInput = document.getElementById('check-out-date');
      const statusSelect = document.getElementById('status-select');
      if (!isEditing) {
        editButton.style.display = 'none';
        saveButton.style.display = 'flex';
        deleteButton.style.display = 'none';
        cancelButton.style.display = 'flex';
        checkInInput.disabled = false;
        checkOutInput.disabled = false;
        statusSelect.disabled = false;
        setMinCheckOutDate();
        statusSelect.onchange = updateStatusIndicator;
      } else {
        editButton.style.display = 'flex';
        saveButton.style.display = 'none';
        deleteButton.style.display = 'flex';
        cancelButton.style.display = 'none';
        checkInInput.disabled = true;
        checkOutInput.disabled = true;
        statusSelect.disabled = true;
        checkOutInput.min = '';
        statusSelect.onchange = null;
      }
    }
    function cancelChanges() {
      document.getElementById('check-in-date').value = INITIAL_DATA.check_in_date;
      document.getElementById('check-out-date').value = INITIAL_DATA.check_out_date;
      document.getElementById('status-select').value = INITIAL_DATA.status;
      updateStatusIndicator();
      calculateNightsAndTotal();
      toggleEditMode();
    }
    function updateStatusIndicator() {
      const select = document.getElementById('status-select');
      const indicator = document.getElementById('status-indicator');
      const selectedStatus = select.value;
      let cssKey = 'unknown';
      let statusText = selectedStatus;
      const statusMap = {
        'Активно': 'actively',
        'Подтверждено': 'confirmed',
        'Завершено': 'completed',
        'Отменено': 'cancelled',
        'Просрочено': 'overdue',
      };
      cssKey = statusMap[selectedStatus] || 'unknown';
      indicator.textContent = statusText;
      indicator.className = `booking-info__status-indicator booking-info__status_${cssKey}`;
    }
    async function saveChanges() {
      calculateNightsAndTotal(); 
      const checkInInput = document.getElementById('check-in-date');
      const checkOutInput = document.getElementById('check-out-date');
      const totalNights = parseInt(document.getElementById('total-nights').value);
      const status = document.getElementById('status-select').value;
      const totalAmountText = document.getElementById('total-amount-display').textContent.replace(/[^0-9,.]/g, '').replace(',', '.').trim();
      const totalAmount = parseFloat(totalAmountText);
      if (totalNights <= 0 || isNaN(totalAmount)) {
        alert('Невозможно сохранить: Неверный период проживания или стоимость.');
        return;
      }
      const dataToSave = {
        booking_id: <?php echo $booking_id; ?>,
        check_in_date: checkInInput.value,
        check_out_date: checkOutInput.value,
        total_nights: totalNights,
        total_amount: totalAmount,
        booking_status: status
      };
      try {
        const response = await fetch('../../php/bookings/update_booking.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(dataToSave)
        });
        const result = await response.json();
        if (response.ok && result.success) {
          alert('Успех: ' + result.message);
          INITIAL_DATA.check_in_date = dataToSave.check_in_date;
          INITIAL_DATA.check_out_date = dataToSave.check_out_date;
          INITIAL_DATA.total_nights = dataToSave.total_nights;
          INITIAL_DATA.total_amount = dataToSave.total_amount;
          INITIAL_DATA.status = dataToSave.booking_status;
          updateStatusIndicator();
          toggleEditMode();
        } else {
          alert('Ошибка сохранения: ' + result.message);
        }
      } catch (error) {
        console.error('Ошибка сети или обработки JSON:', error);
        alert('Произошла критическая ошибка при попытке сохранения.');
      }
    }
    async function deleteBooking() {
      if (!confirm('Вы уверены, что хотите удалить это бронирование?\n\nУдаление приведет к:' +
                   '\n• Полному удалению бронирования' +
                   '\n• Удалению всех связанных платежей' +
                   '\n• Изменению статуса номера на "Свободен" (если нет других активных бронирований)')) {
        return;
      }
      const deleteButton = document.getElementById('delete-button');
      const deleteButtonText = document.getElementById('delete-button-text');
      const originalText = deleteButtonText.textContent;
      try {
        deleteButton.disabled = true;
        deleteButtonText.textContent = 'Удаление...';
        const response = await fetch('../../php/bookings/delete_booking.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                booking_id: <?php echo $booking_id; ?>
            })
        });
        const result = await response.json();
        if (result.success) {
            alert(result.message);
            window.location.href = result.redirect || '../booking/bookings.php';
        } else {
            alert('Ошибка удаления: ' + result.message);
            deleteButton.disabled = false;
            deleteButtonText.textContent = originalText;
        }
      } catch (error) {
        console.error('Ошибка сети или обработки JSON:', error);
        alert('Произошла критическая ошибка при попытке удаления.');
        deleteButton.disabled = false;
        deleteButtonText.textContent = originalText;
      }
    }
  </script>
</body>
</html>