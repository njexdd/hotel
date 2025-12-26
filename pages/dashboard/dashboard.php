<?php
session_start();
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
  header("Location: ../../pages/login/login.php");
  exit;
}
require_once '../../php/config/config.php';
$dbconn = db_connect();
if (!$dbconn) {
  die("Ошибка подключения к базе данных.");
}
function get_dashboard_data($dbconn) {
  $query = "SELECT * FROM get_dashboard_counters()";
  $result = pg_query($dbconn, $query);
  if (!$result) {
    error_log("Ошибка при получении данных дашборда: " . pg_last_error($dbconn));
    return [
      'active_bookings_count' => 0,
      'available_rooms_count' => 0,
      'today_checkins_count' => 0,
      'today_checkouts_count' => 0,
    ];
  }
  $row = pg_fetch_assoc($result);
  return $row ?: [
    'active_bookings_count' => 0,
    'available_rooms_count' => 0,
    'today_checkins_count' => 0,
    'today_checkouts_count' => 0,
  ];
}
function get_upcoming_bookings($dbconn) {
  $query = "SELECT * FROM upcoming_bookings_view";
  $result = pg_query($dbconn, $query);
  if (!$result) {
    error_log("Ошибка при получении ближайших бронирований: " . pg_last_error($dbconn));
    return [];
  }
  $bookings = [];
  while ($row = pg_fetch_assoc($result)) {
    $bookings[] = $row;
  }
  return $bookings;
}
function get_room_occupancy_stats($dbconn) {
  $query = "SELECT * FROM room_occupancy_view";
  $result = pg_query($dbconn, $query);
  if (!$result) {
    error_log("Ошибка при получении статистики занятости: " . pg_last_error($dbconn));
    return [];
  }
  $stats = [];
  while ($row = pg_fetch_assoc($result)) {
    $stats[] = $row;
  }
  return $stats;
}
function formatStatusCss($status) {
  $status_map = [
    'Активно' => 'nearest__table-cell_active',
    'Подтверждено' => 'nearest__table-cell_confirmed',
    'Завершено' => 'nearest__table-cell_completed',
    'Отменено' => 'nearest__table-cell_cancelled',
    'Просрочено' => 'nearest__table-cell_overdue',
  ];
  return $status_map[$status] ?? 'nearest__table-cell_unknown';
}
$dashboard_data = get_dashboard_data($dbconn);
$active_bookings_count = $dashboard_data['active_bookings_count'];
$available_rooms_count = $dashboard_data['available_rooms_count'];
$today_checkins_count = $dashboard_data['today_checkins_count'];
$today_checkouts_count = $dashboard_data['today_checkouts_count'];
$upcoming_bookings = get_upcoming_bookings($dbconn);
$room_occupancy_stats = get_room_occupancy_stats($dbconn);
pg_close($dbconn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/../../images/hotel.ico">
  <title>Менеджер гостиницы. Dashboard</title>
  <link rel="stylesheet" href="../../css/normalize.css">
  <link rel="stylesheet" href="../../css/reset.css">
  <link rel="stylesheet" href="../../css/fonts.css">
  <link rel="stylesheet" href="../../css/dashboard/dashboard.css">
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
          <h2 class="main__title">Dashboard</h2>
          <p class="main__desc">Добро пожаловать в систему управления гостиницей</p>
          <section class="widgets">
            <div class="widgets__widget">
              <div class="widgets__widget-img widgets__widget-img_blue">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trending-up w-6 h-6 text-blue-600"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"></polyline><polyline points="16 7 22 7 22 13"></polyline></svg>
              </div>
              <p class="widgets__widget-text">Активные бронирования</p>
              <p class="widgets__widget-count"><?php echo $active_bookings_count; ?></p>
              <a href="../booking/bookings.php?booking-status=Активно" class="widgets__widget-link">
                Подробнее
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-right w-4 h-4"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>
              </a>
            </div>
            <div class="widgets__widget">
              <div class="widgets__widget-img widgets__widget-img_green">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-door-open w-6 h-6 text-green-600"><path d="M13 4h4a2 2 0 0 1 2 2v14"></path><path d="M2 20h20"></path><path d="M18 10h-6"></path><path d="M2 20v-7c0-2.2 1.8-4 4-4h14"></path><path d="M15 15h0.01"></path></svg>
              </div>
              <p class="widgets__widget-text">Доступные номера</p>
              <p class="widgets__widget-count"><?php echo $available_rooms_count; ?></p>
              <a href="../room/rooms.php?status-select=free" class="widgets__widget-link">
                Подробнее
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-right w-4 h-4"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>
              </a>
            </div>
            <div class="widgets__widget">
              <div class="widgets__widget-img widgets__widget-img_orange">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-users w-6 h-6 text-amber-600"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
              </div>
              <p class="widgets__widget-text">Сегодня заезды</p>
              <p class="widgets__widget-count"><?php echo $today_checkins_count; ?></p>
              <a href="../booking/bookings.php?check-in-from=<?php echo date('Y-m-d'); ?>&check-in-to=<?php echo date('Y-m-d'); ?>" class="widgets__widget-link">
                Подробнее
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-right w-4 h-4"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>
              </a>
            </div>
            <div class="widgets__widget">
              <div class="widgets__widget-img widgets__widget-img_purple">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chart-column w-6 h-6 text-purple-600"><path d="M3 3v16a2 2 0 0 0 2 2h16"></path><path d="M18 17V9"></path><path d="M13 17V5"></path><path d="M8 17v-3"></path></svg>
              </div>
              <p class="widgets__widget-text">Сегодня выезды</p>
              <p class="widgets__widget-count"><?php echo $today_checkouts_count; ?></p>
              <a href="../booking/bookings.php?check-out-from=<?php echo date('Y-m-d'); ?>&check-out-to=<?php echo date('Y-m-d'); ?>" class="widgets__widget-link">
                Подробнее
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-right w-4 h-4"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>
              </a>
            </div>
          </section>
          <section class="nearest">
            <h3 class="nearest__title">Ближайшие заезды и выезды</h3>
            <table class="nearest__table">
              <thead class="nearest__table-header">
                <tr class="nearest__table-row">
                  <th class="nearest__table-head">Клиент</th>
                  <th class="nearest__table-head">Номер</th>
                  <th class="nearest__table-head">Дата заезда</th>
                  <th class="nearest__table-head">Дата выезда</th>
                  <th class="nearest__table-head">Статус</th>
                  <th class="nearest__table-head">Действия</th>
                </tr>
              </thead>
              <tbody class="nearest__table-body">
                <?php if (!empty($upcoming_bookings)): ?>
                  <?php foreach ($upcoming_bookings as $booking): ?>
                    <tr class="nearest__table-row">
                      <td class="nearest__table-cell nearest__table-cell_bold">
                        <?php echo htmlspecialchars($booking['client_name']); ?>
                      </td>
                      <td class="nearest__table-cell">
                        <?php echo htmlspecialchars($booking['room_number']); ?>
                      </td>
                      <td class="nearest__table-cell">
                        <?php 
                          $check_in_display = htmlspecialchars($booking['check_in_date']);
                          if (!empty($booking['event_type']) && strpos($booking['event_type'], 'Сегодня Заезд') !== false) {
                            echo '<span style="color: #10b981; font-weight: 600;">Сегодня</span>';
                          } elseif (!empty($booking['event_type']) && strpos($booking['event_type'], 'Завтра Заезд') !== false) {
                            echo '<span style="color: #f59e0b; font-weight: 600;">Завтра</span>';
                          } else {
                            echo $check_in_display;
                          }
                        ?>
                      </td>
                      <td class="nearest__table-cell">
                        <?php 
                          $check_out_display = htmlspecialchars($booking['check_out_date']);
                          if (!empty($booking['event_type']) && strpos($booking['event_type'], 'Сегодня Выезд') !== false) {
                            echo '<span style="color: #ef4444; font-weight: 600;">Сегодня</span>';
                          } elseif (!empty($booking['event_type']) && strpos($booking['event_type'], 'Завтра Выезд') !== false) {
                            echo '<span style="color: #f59e0b; font-weight: 600;">Завтра</span>';
                          } else {
                            echo $check_out_display;
                          }
                        ?>
                      </td>
                      <td class="nearest__table-cell">
                        <span class="<?php echo formatStatusCss($booking['booking_status']); ?>">
                          <?php echo htmlspecialchars($booking['booking_status']); ?>
                        </span>
                      </td>
                      <td class="nearest__table-cell">
                        <a href="../booking/booking-card.php?id=<?php echo $booking['booking_id']; ?>" 
                           class="nearest__table-cell-link">
                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-external-link w-4 h-4">
                            <path d="M15 3h6v6"></path>
                            <path d="M10 14 21 3"></path>
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                          </svg>
                          Открыть
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr class="nearest__table-row">
                    <td colspan="6" class="nearest__table-cell" style="text-align: center; padding: 20px;">
                      Нет ближайших заездов или выездов на ближайшие 7 дней
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </section>
          <section class="status">
            <h3 class="status__title">Статус занятости номеров</h3>
            <table class="status__table">
              <thead class="status__table-header">
                <tr class="status__table-row">
                  <th class="status__table-head">Категория номера</th>
                  <th class="status__table-head">Всего номеров</th>
                  <th class="status__table-head status__table-head_green">Свободно</th>
                  <th class="status__table-head status__table-head_blue">Занято</th>
                  <th class="status__table-head status__table-head_orange">На уборке</th>
                  <th class="status__table-head status__table-head_red">На ремонте</th>
                </tr>
              </thead>
              <tbody class="status__table-body">
                <?php if (!empty($room_occupancy_stats)): ?>
                  <?php foreach ($room_occupancy_stats as $stat): ?>
                    <tr class="status__table-row">
                      <td class="status__table-cell"><?php echo htmlspecialchars($stat['category']); ?></td>
                      <td class="status__table-cell"><?php echo htmlspecialchars($stat['total_rooms']); ?></td>
                      <td class="status__table-cell status__table-cell_green">
                        <?php echo htmlspecialchars($stat['free_rooms']); ?>
                      </td>
                      <td class="status__table-cell status__table-cell_blue">
                        <?php echo htmlspecialchars($stat['occupied_rooms']); ?>
                      </td>
                      <td class="status__table-cell status__table-cell_orange">
                        <?php echo htmlspecialchars($stat['cleaning_rooms']); ?>
                      </td>
                      <td class="status__table-cell status__table-cell_red">
                        <?php echo htmlspecialchars($stat['repair_rooms']); ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr class="status__table-row">
                    <td colspan="6" class="status__table-cell" style="text-align: center; padding: 20px;">
                      Нет данных о статусе номеров
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </section>
        </div>
      </main>
    </div>
  </div>
</body>
</html>