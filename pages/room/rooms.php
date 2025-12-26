<?php
session_start();
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
  header("Location: ../../pages/login/login.php");
  exit;
}
require_once '../../php/config/config.php';
$STATUSES_MAP = [
  'free' => 'Свободен',
  'booked' => 'Забронирован',
  'busy' => 'Занят',
  'repair' => 'На ремонте',
  'cleaning' => 'Убирается',
  'ready' => 'Готов к заселению',
  'need-cleaning' => 'Нуждается в уборке',
];
$CAPACITIES_MAP = [
  'one' => '1 чел.',
  'two' => '2 чел.',
  'three' => '3 чел.',
  'three-plus' => '3+ чел.',
  'four' => '4 чел.',
];
$limit = 10;
$page = (int) ($_GET['page'] ?? 1);
$page = max(1, $page);
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status-select'] ?? 'all-statuses';
$type_filter_id = $_GET['types-select'] ?? 'all-types';
$capacity_filter = $_GET['capacity-select'] ?? 'all-capacity';
$price_from = (int) ($_GET['from'] ?? 0);
$price_to = (int) ($_GET['to'] ?? 0);
$valid_sort_fields = [
  'room_number' => 'r.room_number',
  'status' => 'r.status',
  'capacity' => 'rt.capacity',
  'price_per_night' => 'rt.base_price',
];
$sort_by = $_GET['sort_by'] ?? 'room_number';
$sort_dir = strtoupper($_GET['sort_dir'] ?? 'ASC');
if (!array_key_exists($sort_by, $valid_sort_fields)) {
  $sort_by = 'room_number';
}
if (!in_array($sort_dir, ['ASC', 'DESC'])) {
  $sort_dir = 'ASC';
}
$dbconn = db_connect();
if (!$dbconn) {
  die("Ошибка подключения к базе данных.");
}
$room_types = [];
$type_query = pg_query($dbconn, "SELECT room_type_id, type_name FROM room_types ORDER BY type_name");
if ($type_query) {
  while ($row = pg_fetch_assoc($type_query)) {
    $room_types[] = $row;
  }
}
$fn_search = empty($search) ? null : $search;
$fn_status = ($status_filter === 'all-statuses' || !isset($STATUSES_MAP[$status_filter])) 
  ? null 
  : $STATUSES_MAP[$status_filter]; 
$fn_type_id = ($type_filter_id === 'all-types' || !is_numeric($type_filter_id)) 
  ? null 
  : (int)$type_filter_id;
$fn_capacity_min = null;
$fn_capacity_max = null;
if ($capacity_filter !== 'all-capacity' && array_key_exists($capacity_filter, $CAPACITIES_MAP)) {
  $capacity_value = match ($capacity_filter) {
    'one' => 1,
    'two' => 2,
    'three' => 3,
    'four' => 4,
    default => null,
  };
  if ($capacity_filter === 'three-plus') {
    $fn_capacity_min = 3;
  } elseif ($capacity_value !== null) {
    $fn_capacity_min = $capacity_value;
    $fn_capacity_max = $capacity_value;
  }
}
$fn_price_from = $price_from > 0 ? $price_from : null;
$fn_price_to = $price_to > 0 ? $price_to : null;
$fn_sort_by = $valid_sort_fields[$sort_by];
$fn_sort_dir = $sort_dir;
$total_rooms = 0;
$total_pages = 0;
$func_params = [
  $fn_search, $fn_status, $fn_type_id, $fn_capacity_min, $fn_capacity_max,
  $fn_price_from, $fn_price_to, $fn_sort_by, $fn_sort_dir, $limit, 0
];
$rooms_query_sql = "
  SELECT 
    room_number, room_type_id, floor, area, status, 
    type_name, capacity, base_price, price_per_night, total_count
  FROM get_filtered_rooms_info(
    $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11
  )
";
$rooms_result = pg_query_params($dbconn, $rooms_query_sql, $func_params);
$rooms_with_count = $rooms_result ? pg_fetch_all($rooms_result) : [];
if (!empty($rooms_with_count)) {
  $total_rooms = (int)$rooms_with_count[0]['total_count'];
  $total_pages = ceil($total_rooms / $limit);
}
if ($page > $total_pages && $total_pages > 0) {
  $page = $total_pages;
}
$page = max(1, $page);
$offset = ($page - 1) * $limit;
if ($offset > 0 || empty($rooms_with_count)) {
  $func_params[10] = $offset; 
  $rooms_result = pg_query_params($dbconn, $rooms_query_sql, $func_params);
  $rooms_with_count = $rooms_result ? pg_fetch_all($rooms_result) : [];
}
$rooms = $rooms_with_count;
$next_bookings = [];
$room_numbers = array_column($rooms, 'room_number');
if (!empty($room_numbers)) {
  $booking_query_sql = "
    SELECT DISTINCT ON (b.room_number)
      b.room_number, 
      b.check_in_date
    FROM bookings b
    WHERE b.room_number = ANY($1) 
      AND (b.booking_status = 'Активно' OR b.check_in_date >= CURRENT_DATE)
    ORDER BY b.room_number, b.check_in_date ASC
  ";
  $booking_result = pg_query_params($dbconn, $booking_query_sql, array('{' . implode(',', $room_numbers) . '}'));
  if ($booking_result) {
    while ($row = pg_fetch_assoc($booking_result)) {
      $next_bookings[$row['room_number']] = date('d.m.Y', strtotime($row['check_in_date']));
    }
  }
}
pg_close($dbconn);
function get_status_class($status) {
  switch ($status) {
    case 'Свободен': return 'rooms__table-cell_free';
    case 'Готов к заселению': return 'rooms__table-cell_ready';
    case 'Занят': return 'rooms__table-cell_busy';
    case 'На ремонте': return 'rooms__table-cell_repair';
    case 'Забронирован': return 'rooms__table-cell_booked';
    case 'Убирается': return 'rooms__table-cell_cleaning';
    case 'Нуждается в уборке': return 'rooms__table-cell_need-cleaning';
    default: return '';
  }
}
function get_sort_link($current_field, $current_sort_by, $current_sort_dir, $get_params) {
  $next_dir = ($current_sort_by === $current_field && $current_sort_dir === 'ASC') ? 'DESC' : 'ASC';
  $new_params = $get_params;
  $new_params['sort_by'] = $current_field;
  $new_params['sort_dir'] = $next_dir;
  $new_params['page'] = 1;
  $clean_params = array_filter($new_params, fn($v) => 
    $v !== '' && $v !== 'all-statuses' && $v !== 'all-types' && $v !== 'all-capacity' && $v !== 0
  );
  return '?' . http_build_query($clean_params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/../../images/hotel.ico">
  <title>Менедждер гостиницы. Список номеров</title>
  <link rel="stylesheet" href="../../css/normalize.css">
  <link rel="stylesheet" href="../../css/reset.css">
  <link rel="stylesheet" href="../../css/fonts.css">
  <link rel="stylesheet" href="../../css/rooms/rooms.css">
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
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-log-out w-4 h-4"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" x2="9" y1="12" y2="12"></line></svg>
          Выход
        </a>
      </header>
      <main class="main">
        <div class="container">
          <div class="main__content">
            <div class="main__text">
              <h2 class="main__title">Список номеров</h2>
              <p class="main__desc">Управление номерами гостиницы</p>
            </div>
            <a href="room-new.php" class="main__add-button">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-plus w-4 h-4"><path d="M5 12h14"></path><path d="M12 5v14"></path></svg>
              Добавить номер
            </a>
          </div>
          <form method="GET" action="rooms.php" class="search">
            <input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sort_by); ?>">
            <input type="hidden" name="sort_dir" value="<?php echo htmlspecialchars($sort_dir); ?>">
            <input type="search" name="search" placeholder="Поиск по номеру комнаты, названию типа или этажу..." class="search__input" value="<?php echo htmlspecialchars($search); ?>">
            <div class="search__filters">
              <div class="search__filter">
                <label for="status-select" class="search__label">Статус</label>
                <select name="status-select" class="search__select">
                  <option value="all-statuses" <?php echo $status_filter === 'all-statuses' ? 'selected' : ''; ?>>Все статусы</option>
                  <?php foreach ($STATUSES_MAP as $key => $label): ?>
                    <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $status_filter === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="search__filter">
                <label for="types-select" class="search__label">Тип номера</label>
                <select name="types-select" class="search__select">
                  <option value="all-types" <?php echo $type_filter_id === 'all-types' ? 'selected' : ''; ?>>Все типы</option>
                  <?php foreach ($room_types as $type): ?>
                    <option value="<?php echo htmlspecialchars($type['room_type_id']); ?>" <?php echo (string)$type_filter_id === (string)$type['room_type_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($type['type_name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="search__filter">
                <label for="capacity-select" class="search__label">Вместимость</label>
                <select name="capacity-select" class="search__select">
                  <option value="all-capacity" <?php echo $capacity_filter === 'all-capacity' ? 'selected' : ''; ?>>Все</option>
                  <?php foreach ($CAPACITIES_MAP as $key => $label): ?>
                    <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $capacity_filter === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="search__filter">
                <label for="from" class="search__label">Цена от</label>
                <input type="number" name="from" placeholder="Мин. цена" class="search__price-input" value="<?php echo $price_from > 0 ? $price_from : ''; ?>">
              </div>
              <div class="search__filter">
                <label for="to" class="search__label">Цена до</label>
                <input type="number" name="to" placeholder="Макс. цена" class="search__price-input" value="<?php echo $price_to > 0 ? $price_to : ''; ?>">
              </div>
            </div>
            <div class="search__buttons">
              <button type="submit" class="search__button">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-search w-4 h-4"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>
                Поиск и фильтр
              </button>
              <a href="rooms.php" class="search__button">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-rotate-ccw w-4 h-4"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path></svg>
                Сбросить фильтры
              </a>
            </div>
          </form>
          <section class="rooms">
            <table class="rooms__table">
              <thead class="rooms__table-header">
                <tr class="rooms__table-row">
                  <th class="rooms__table-head">
                    <a href="<?php echo htmlspecialchars(get_sort_link('room_number', $sort_by, $sort_dir, $_GET)); ?>" class="rooms__table-head-button"><span>Номер комнаты</span></a>
                  </th>
                  <th class="rooms__table-head">
                    <a href="<?php echo htmlspecialchars(get_sort_link('status', $sort_by, $sort_dir, $_GET)); ?>" class="rooms__table-head-button"><span>Статус</span></a>
                  </th>
                  <th class="rooms__table-head">
                    <a href="<?php echo htmlspecialchars(get_sort_link('capacity', $sort_by, $sort_dir, $_GET)); ?>" class="rooms__table-head-button"><span>Вместимость</span></a>
                  </th>
                  <th class="rooms__table-head">
                    <a href="<?php echo htmlspecialchars(get_sort_link('price_per_night', $sort_by, $sort_dir, $_GET)); ?>" class="rooms__table-head-button"><span>Стоимость (за ночь)</span></a>
                  </th>
                  <th class="rooms__table-head">Текущий/Ближайший заезд</th>
                  <th class="rooms__table-head">Действия</th>
                </tr>
              </thead>
              <tbody class="rooms__table-body">
                <?php if (!empty($rooms)): ?>
                  <?php foreach ($rooms as $room): 
                    $floor_number = isset($room['room_number']) && is_numeric($room['room_number']) ? floor((int)$room['room_number'] / 100) : 'N/A';
                    $next_check_in = $next_bookings[$room['room_number']] ?? null;
                  ?>
                    <tr class="rooms__table-row">
                      <td class="rooms__table-cell">
                        <div class="rooms__room">
                          <span><?php echo htmlspecialchars($room['room_number']); ?></span>
                          <span class="rooms_gray"><span>Этаж <?php echo htmlspecialchars($floor_number); ?></span> • <span><?php echo htmlspecialchars($room['type_name']); ?></span></span>
                        </div>
                      </td>
                      <td class="rooms__table-cell">
                        <span class="<?php echo get_status_class($room['status']); ?>"><?php echo htmlspecialchars($room['status']); ?></span>
                      </td>
                      <td class="rooms__table-cell"><?php echo htmlspecialchars($room['capacity']); ?> чел.</td>
                      <td class="rooms__table-cell"><?php echo number_format($room['price_per_night'], 0, ',', ' '); ?> ₽</td>
                      <td class="rooms__table-cell rooms_gray"><?php echo $next_check_in ? htmlspecialchars($next_check_in) : 'Нет активных/будущих бронирований'; ?></td>
                      <td class="rooms__table-cell">
                        <div class="rooms__table-cell-actions">
                          <a href="room-card.php?id=<?php echo urlencode($room['room_number']); ?>" title="Посмотреть" class="rooms__table-cell-action">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-eye w-4 h-4"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"></path><circle cx="12" cy="12" r="3"></circle></svg>
                          </a>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr class="rooms__table-row"><td colspan="6" class="rooms__table-cell" style="text-align: center; padding: 20px;">Номера не найдены.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </section>
          <section class="pagination">
            <p class="pagination__text">Показано <?php echo count($rooms); ?> из <?php echo $total_rooms; ?> номеров</p>
            <div class="pagination__nav">
              <?php if ($page > 1 && $total_rooms > 0): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="pagination__button">Назад</a>
              <?php else: ?>
                <button disabled class="pagination__button pagination__button_disabled">Назад</button>
              <?php endif; ?>
              <div class="pagination__pages">
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                if ($start_page > 1) echo '<span style="padding: 0 5px;">...</span>';
                for ($p = $start_page; $p <= $end_page; $p++): ?>
                  <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>" class="pagination__pages-button <?php echo $p === $page ? 'pagination__pages-button_current' : ''; ?>"><?php echo $p; ?></a>
                <?php endfor;
                if ($end_page < $total_pages) echo '<span style="padding: 0 5px;">...</span>';
                ?>
              </div>
              <?php if ($page < $total_pages && $total_rooms > 0): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="pagination__button">Вперёд</a>
              <?php else: ?>
                <button disabled class="pagination__button pagination__button_disabled">Вперёд</button>
              <?php endif; ?>
            </div>
          </section>
        </div>
      </main>
    </div>
  </div>
</body>
</html>