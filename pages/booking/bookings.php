<?php
  session_start();
  if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header("Location: ../../pages/login/login.php");
    exit;
  }
  require_once '../../php/config/config.php';
  $limit = 10;
  $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
  $offset = ($page - 1) * $limit;
  $search_client = trim($_GET['search-client'] ?? '');
  $search_room = trim($_GET['search-room'] ?? '');
  $booking_status = $_GET['booking-status'] ?? 'all-statuses';
  $room_type_id = filter_var($_GET['room-type'] ?? 0, FILTER_VALIDATE_INT);
  $check_in_from = $_GET['check-in-from'] ?? '';
  $check_in_to = $_GET['check-in-to'] ?? '';
  $check_out_from = $_GET['check-out-from'] ?? '';
  $check_out_to = $_GET['check-out-to'] ?? '';
  $sort_column = $_GET['sort'] ?? 'booking_id';
  $sort_order = $_GET['order'] ?? 'DESC';
  $allowed_sort_columns = ['booking_id', 'check_in_date', 'check_out_date', 'total_amount'];
  $sort_column = in_array($sort_column, $allowed_sort_columns) ? $sort_column : 'booking_id';
  $sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';
  $dbconn = db_connect();
  if (!$dbconn) {
    die("Ошибка подключения к базе данных.");
  }
  function get_bookings($dbconn, $limit, $offset, $params) {
    global $sort_column, $sort_order;
    $bookings = [];
    $total_count = 0;
    $query = "
        SELECT 
            out_booking_id, 
            out_room_number, 
            out_check_in_date, 
            out_check_out_date, 
            out_total_amount, 
            out_booking_status, 
            out_client_name, 
            out_client_phone, 
            out_room_type_name, 
            total_records
        FROM public.get_filtered_bookings(
            $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12
        )
    ";
    $query_params = [
      empty($params['search_client']) ? null : $params['search_client'],
      empty($params['search_room']) ? null : $params['search_room'],
      $params['booking_status'] === 'all-statuses' ? 'all-statuses' : $params['booking_status'],
      $params['room_type_id'],
      empty($params['check_in_from']) ? null : $params['check_in_from'],
      empty($params['check_in_to']) ? null : $params['check_in_to'],
      empty($params['check_out_from']) ? null : $params['check_out_from'],
      empty($params['check_out_to']) ? null : $params['check_out_to'],
      $sort_column,
      $sort_order,
      $limit,
      $offset,
    ];
    $result = pg_query_params($dbconn, $query, $query_params);
    if (!$result) {
      error_log("Ошибка при получении бронирований: " . pg_last_error($dbconn));
      return ['bookings' => [], 'total_count' => 0];
    }
    $all_results = pg_fetch_all($result);
    if (!empty($all_results)) {
      $total_count = $all_results[0]['total_records'];
      foreach ($all_results as $row) {
        $bookings[] = [
          'booking_id' => $row['out_booking_id'],
          'room_number' => $row['out_room_number'],
          'check_in_date' => $row['out_check_in_date'],
          'check_out_date' => $row['out_check_out_date'],
          'total_amount' => $row['out_total_amount'],
          'booking_status' => $row['out_booking_status'],
          'client_name' => $row['out_client_name'],
          'client_phone' => $row['out_client_phone'],
          'room_type_name' => $row['out_room_type_name'],
        ];
      }
    }
    return ['bookings' => $bookings, 'total_count' => $total_count];
  }
  function get_indicators($dbconn) {
    $query = "SELECT active_now, check_ins_today, check_outs_today, cancelled_today, future_bookings FROM public.v_booking_indicators";
    $result = pg_query($dbconn, $query);
    if (!$result) {
      error_log("Ошибка при получении индикаторов: " . pg_last_error($dbconn));
      return [
        'active_now' => 0,
        'check_ins_today' => 0,
        'check_outs_today' => 0,
        'cancelled_today' => 0,
        'future_bookings' => 0,
      ];
    }
    $indicators = pg_fetch_assoc($result);
    return $indicators ?: [
        'active_now' => 0,
        'check_ins_today' => 0,
        'check_outs_today' => 0,
        'cancelled_today' => 0,
        'future_bookings' => 0,
    ];
  }
  function get_room_types_for_filter($dbconn) {
    $query = "SELECT room_type_id, type_name FROM public.room_types ORDER BY type_name";
    $result = pg_query($dbconn, $query);
    return $result ? pg_fetch_all($result) : [];
  }
  $indicators = get_indicators($dbconn);
  $room_types = get_room_types_for_filter($dbconn);
  $filter_params = [
    'search_client' => $search_client,
    'search_room' => $search_room,
    'booking_status' => $booking_status,
    'room_type_id' => $room_type_id,
    'check_in_from' => $check_in_from,
    'check_in_to' => $check_in_to,
    'check_out_from' => $check_out_from,
    'check_out_to' => $check_out_to,
  ];
  $data = get_bookings($dbconn, $limit, $offset, $filter_params);
  $bookings = $data['bookings'];
  $total_bookings = $data['total_count'];
  $total_pages = ceil($total_bookings / $limit);
  pg_close($dbconn);
  function get_filter_link($new_params = []) {
    $params = array_merge($_GET, $new_params);
    if (!isset($new_params['page'])) {
      unset($params['page']);
    }
    $params = array_filter($params, fn($value) => $value !== '' && $value !== null);
    return '?' . http_build_query($params);
  }
  function get_status_class($status) {
    switch ($status) {
      case 'Активно': return 'bookings__table-cell_active';
      case 'Подтверждено': return 'bookings__table-cell_confirmed';
      case 'Завершено': return 'bookings__table-cell_completed';
      case 'Отменено': return 'bookings__table-cell_cancelled';
      case 'Просрочено': return 'bookings__table-cell_overdue';
      default: return '';
    }
  }
  $status_map = [
    'all-statuses' => 'Все статусы',
    'Активно' => 'Активно',
    'Подтверждено' => 'Подтверждено',
    'Завершено' => 'Завершено',
    'Отменено' => 'Отменено',
    'Просрочено' => 'Просрочено',
  ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/../../images/hotel.ico">
  <title>Менедждер гостиницы. Список бронирований</title>
  <link rel="stylesheet" href="../../css/normalize.css">
  <link rel="stylesheet" href="../../css/reset.css">
  <link rel="stylesheet" href="../../css/fonts.css">
  <link rel="stylesheet" href="../../css/bookings/bookings.css">
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
              <h2 class="main__title">Список бронирований</h2>
              <p class="main__desc">Управление бронированиями гостей</p>
            </div>
            <a href="booking-new.php" class="main__add-button">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-plus w-4 h-4"><path d="M5 12h14"></path><path d="M12 5v14"></path></svg>
              Добавить бронирование
            </a>
          </div>
          <section class="indicators">
            <div class="indicators__indicator">
              <div class="indicators__img indicator__img_green">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-circle-check w-5 h-5"><circle cx="12" cy="12" r="10"></circle><path d="m9 12 2 2 4-4"></path></svg>
              </div>
              <div class="indicators__content">
                <p class="indocators__title">Активных заездов сейчас</p>
                <p class="indicators__count"><?php echo $indicators['active_now']; ?></p>
              </div>
            </div>
            <div class="indicators__indicator">
              <div class="indicators__img indicator__img_blue">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-check w-5 h-5"><path d="M20 6 9 17l-5-5"></path></svg>
              </div>
              <div class="indicators__content">
                <p class="indocators__title">Заездов сегодня</p>
                <p class="indicators__count"><?php echo $indicators['check_ins_today']; ?></p>
              </div>
            </div>
            <div class="indicators__indicator">
              <div class="indicators__img indicator__img_orange">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-clock w-5 h-5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
              </div>
              <div class="indicators__content">
                <p class="indocators__title">Выездов сегодня</p>
                <p class="indicators__count"><?php echo $indicators['check_outs_today']; ?></p>
              </div>
            </div>
            <div class="indicators__indicator">
              <div class="indicators__img indicator__img_red">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-circle-x w-5 h-5"><circle cx="12" cy="12" r="10"></circle><path d="m15 9-6 6"></path><path d="m9 9 6 6"></path></svg>
              </div>
              <div class="indicators__content">
                <p class="indocators__title">Отменено сегодня</p>
                <p class="indicators__count"><?php echo $indicators['cancelled_today']; ?></p>
              </div>
            </div>
            <div class="indicators__indicator">
              <div class="indicators__img indicator__img_purple">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-calendar-check w-5 h-5"><path d="m16 2v4"></path><path d="M8 2v4"></path><path d="M21 14V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10"></path><path d="M3 10h18"></path><path d="m16 20 2 2 4-4"></path></svg>
              </div>
              <div class="indicators__content">
                <p class="indocators__title">Будущие брони</p>
                <p class="indicators__count"><?php echo $indicators['future_bookings']; ?></p>
              </div>
            </div>
          </section>
          <section class="search">
            <form method="GET" action="bookings.php" id="filter-form">
              <div class="search__inputs">
                <input type="search" name="search-client" placeholder="Поиск по ФИО клиента..." class="search__input" value="<?php echo htmlspecialchars($search_client); ?>">
                <input type="search" name="search-room" placeholder="Поиск по номеру комнаты..." class="search__input" value="<?php echo htmlspecialchars($search_room); ?>">
              </div>
              <div class="search__filters">
                <div class="search__filter">
                  <label for="booking-status" class="search__label">Статус</label>
                  <select name="booking-status" id="booking-status" class="search__select">
                    <?php foreach ($status_map as $value => $label): ?>
                      <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $booking_status === $value ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($label); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="search__filter">
                  <label for="room-type" class="search__label">Тип номера</label>
                  <select name="room-type" id="room-type" class="search__select">
                    <option value="0" class="search__option">Все типы</option>
                    <?php foreach ($room_types as $type): ?>
                      <option value="<?php echo htmlspecialchars($type['room_type_id']); ?>" <?php echo $room_type_id == $type['room_type_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($type['type_name']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="search__filter">
                  <label for="check-in-from" class="search__label">Заезд от</label>
                  <input type="date" name="check-in-from" class="search__date" value="<?php echo htmlspecialchars($check_in_from); ?>">
                </div>
                <div class="search__filter">
                  <label for="check-in-to" class="search__label">Заезд до</label>
                  <input type="date" name="check-in-to" class="search__date" value="<?php echo htmlspecialchars($check_in_to); ?>">
                </div>
                <div class="search__filter">
                  <label for="check-out-from" class="search__label">Выезд от</label>
                  <input type="date" name="check-out-from" class="search__date" value="<?php echo htmlspecialchars($check_out_from); ?>">
                </div>
                <div class="search__filter">
                  <label for="check-out-to" class="search__label">Выезд до</label>
                  <input type="date" name="check-out-to" class="search__date" value="<?php echo htmlspecialchars($check_out_to); ?>">
                </div>
              </div>
              <div class="search__buttons">
                <button type="submit" class="search__button">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-search w-4 h-4"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>
                  Поиск и фильтр
                </button>
                <a href="bookings.php" class="search__button">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-rotate-ccw w-4 h-4"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path></svg>
                  Сбросить фильтры
                </a>
              </div>
            </form>
          </section>
          <section class="bookings">
            <table class="bookings__table">
              <thead class="bookings__table-header">
                <tr class="bookings__table-row">
                  <th class="bookings__table-head">
                    <a href="<?php echo get_filter_link(['sort' => 'booking_id', 'order' => $sort_column === 'booking_id' && $sort_order === 'DESC' ? 'ASC' : 'DESC']); ?>" class="bookings__table-head-button">ID</a>
                  </th>
                  <th class="bookings__table-head">Клиент</th>
                  <th class="bookings__table-head">Номер</th>
                  <th class="bookings__table-head">Тип номера</th>
                  <th class="bookings__table-head">
                    <a href="<?php echo get_filter_link(['sort' => 'check_in_date', 'order' => $sort_column === 'check_in_date' && $sort_order === 'DESC' ? 'ASC' : 'DESC']); ?>" class="bookings__table-head-button">Дата заезда</a>
                  </th>
                  <th class="bookings__table-head">
                    <a href="<?php echo get_filter_link(['sort' => 'check_out_date', 'order' => $sort_column === 'check_out_date' && $sort_order === 'DESC' ? 'ASC' : 'DESC']); ?>" class="bookings__table-head-button">Дата выезда</a>
                  </th>
                  <th class="bookings__table-head">
                    <a href="<?php echo get_filter_link(['sort' => 'total_amount', 'order' => $sort_column === 'total_amount' && $sort_order === 'DESC' ? 'ASC' : 'DESC']); ?>" class="bookings__table-head-button">Сумма</a>
                  </th>
                  <th class="bookings__table-head">Статус</th>
                  <th class="bookings__table-head">Действия</th>
                </tr>
              </thead>
              <tbody class="bookings__table-body">
                <?php if (empty($bookings)): ?>
                  <tr class="payments__table-row">
                    <td class="payments__table-cell" colspan="9" style="text-align: center; padding: 20px;">Бронирований не найдено.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($bookings as $booking): ?>
                    <tr class="bookings__table-row">
                      <td class="bookings__table-cell bookings__table-cell_bold"><?php echo htmlspecialchars($booking['booking_id']); ?></td>
                      <td class="bookings__table-cell">
                        <div class="bookings__client">
                          <span class="bookings__table-cell_bold"><?php echo htmlspecialchars($booking['client_name']); ?></span>
                          <span class="bookings__table-cell_gray"><?php echo htmlspecialchars($booking['client_phone']); ?></span>
                        </div>
                      </td>
                      <td class="bookings__table-cell bookings__table-cell_bold"><?php echo htmlspecialchars($booking['room_number']); ?></td>
                      <td class="bookings__table-cell"><?php echo htmlspecialchars($booking['room_type_name']); ?></td>
                      <td class="bookings__table-cell"><?php echo date('d.m.Y', strtotime($booking['check_in_date'])); ?></td>
                      <td class="bookings__table-cell"><?php echo date('d.m.Y', strtotime($booking['check_out_date'])); ?></td>
                      <td class="bookings__table-cell bookings__table-cell_bold"><?php echo number_format($booking['total_amount'], 2, ',', ' '); ?> ₽</td>
                      <td class="bookings__table-cell">
                        <span class="<?php echo get_status_class($booking['booking_status']); ?>">
                          <?php echo htmlspecialchars($booking['booking_status']); ?>
                        </span>
                      </td>
                      <td class="bookings__table-cell">
                        <a href="booking-card.php?id=<?php echo htmlspecialchars($booking['booking_id']); ?>" class="bookings__table-cell-action">
                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-eye w-4 h-4"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </section>
          <section class="pagination">
            <?php
              $start_record = $offset + 1;
              $end_record = min($offset + $limit, $total_bookings);
            ?>
            <p class="pagination__text">Показано <?php echo $start_record; ?>-<?php echo $end_record; ?> из <?php echo $total_bookings; ?> бронирований</p>
            <div class="pagination__nav">
              <?php
                $prev_page = $page - 1;
                $prev_url = http_build_query(array_merge($_GET, ['page' => $prev_page]));
              ?>
              <?php if ($page > 1 && $total_bookings > 0): ?>
                <a href="?<?php echo $prev_url; ?>" class="pagination__button">Назад</a>
              <?php else: ?>
                <button disabled class="pagination__button pagination__button_disabled">Назад</button>
              <?php endif; ?>
              <div class="pagination__pages">
                <?php
                  $start_page = max(1, $page - 2);
                  $end_page = min($total_pages, $page + 2);
                  if ($start_page > 1) { echo '<span style="padding: 0 5px;">...</span>'; }
                  for ($p = $start_page; $p <= $end_page; $p++):
                    $page_url = http_build_query(array_merge($_GET, ['page' => $p]));
                ?>
                <a href="?<?php echo $page_url; ?>" class="pagination__pages-button <?php echo $p === $page ? 'pagination__pages-button_current' : ''; ?>">
                  <?php echo $p; ?>
                </a>
                <?php endfor;
                  if ($end_page < $total_pages) { echo '<span style="padding: 0 5px;">...</span>'; }
                ?>
              </div>
              <?php
                $next_page = $page + 1;
                $next_url = http_build_query(array_merge($_GET, ['page' => $next_page]));
              ?>
              <?php if ($page < $total_pages && $total_bookings > 0): ?>
                <a href="?<?php echo $next_url; ?>" class="pagination__button">Вперёд</a>
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