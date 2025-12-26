<?php
session_start();
require_once '../../php/config/config.php';
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
  header("Location: ../../pages/login/login.php");
  exit;
}
$conn = db_connect();
if (!$conn) {
  die("Ошибка подключения к базе данных.");
}
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;
$search_client = $_GET['search_client'] ?? '';
$search_booking = $_GET['search_booking'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$select_type = $_GET['select_type'] ?? 'all-types';
$select_status = $_GET['select_status'] ?? 'all-statuses';
$select_payment_method = $_GET['select_payment_method'] ?? 'all-methods';
$amount_from = $_GET['amount_from'] ?? '';
$amount_to = $_GET['amount_to'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'payment_date';
$sort_order = $_GET['sort_order'] ?? 'DESC';
$valid_sorts = ['payment_date', 'amount', 'payment_status'];
$sort_by = in_array($sort_by, $valid_sorts) ? $sort_by : 'payment_date';
$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';
$where_clauses = [];
$params = [];
$param_index = 1;
if ($search_client) {
  $where_clauses[] = "(client_full_name ILIKE $" . $param_index++ . " OR client_phone ILIKE $" . $param_index++ . ")";
  $params[] = '%' . $search_client . '%';
  $params[] = '%' . $search_client . '%';
}
if ($search_booking) {
  $where_clauses[] = "booking_id = $" . $param_index++ . "";
  $params[] = (int)$search_booking;
}
if ($date_from) {
  $where_clauses[] = "payment_date >= $" . $param_index++ . "";
  $params[] = $date_from;
}
if ($date_to) {
  $where_clauses[] = "payment_date < ($" . $param_index++ . "::date + interval '1 day')";
  $params[] = $date_to;
}
if ($select_type !== 'all-types') {
  $where_clauses[] = "operation_type = $" . $param_index++ . "";
  $params[] = $select_type; 
}
if ($select_status !== 'all-statuses') {
  $where_clauses[] = "payment_status = $" . $param_index++ . "";
  $params[] = $select_status;
}
if ($select_payment_method !== 'all-methods') {
  $where_clauses[] = "payment_method = $" . $param_index++ . "";
  $params[] = $select_payment_method;
}
if (is_numeric($amount_from) && $amount_from !== '') {
  $where_clauses[] = "amount >= $" . $param_index++ . "";
  $params[] = (float)$amount_from;
}
if (is_numeric($amount_to) && $amount_to !== '') {
  $where_clauses[] = "amount <= $" . $param_index++ . "";
  $params[] = (float)$amount_to;
}
$where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
$stats_query = "SELECT COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END), 0) AS total_refund_amount, COUNT(*) AS total_transactions, COALESCE(ROUND(AVG(amount), 2), 0) AS average_check FROM public.payments_view " . $where_sql;
$stats_result = pg_query_params($conn, $stats_query, $params);
$stats = pg_fetch_assoc($stats_result);
$total_refund_amount_display = abs($stats['total_refund_amount']);
$count_query = "SELECT COUNT(*) AS total_records FROM public.payments_view " . $where_sql;
$count_result = pg_query_params($conn, $count_query, $params);
$total_records = pg_fetch_result($count_result, 0, 'total_records');
$total_pages = ceil($total_records / $limit);
$start_record = $total_records > 0 ? $offset + 1 : 0;
$end_record = min($offset + $limit, $total_records);
$payments_query = "SELECT transaction_id, payment_date, payment_method, operation_type, amount, payment_status, booking_id, client_full_name FROM public.payments_view " . $where_sql . " ORDER BY " . $sort_by . " " . $sort_order . " LIMIT $" . $param_index++ . " OFFSET $" . $param_index++ . "";
$params[] = $limit;
$params[] = $offset;
$payments_result = pg_query_params($conn, $payments_query, $params);
$payments = pg_fetch_all($payments_result) ?: [];
function format_payment_amount($amount) {
  $abs_amount = abs($amount);
  $formatted_amount = number_format($abs_amount, 0, '', ' ') . ' ₽';
  $sign = '';
  $class = '';
  if ($amount > 0) {
    $sign = '+';
    $class = 'payments__table-cell_green';
  } elseif ($amount < 0) {
    $sign = '-';
    $class = 'payments__table-cell_red';
  }
  return "<span class=\"$class\">$sign $formatted_amount</span>";
}
function get_operation_type_name($type) {
  return $type;
}
function get_payment_method_name($method) {
  return $method;
}
function get_status_html($status) {
  $statuses = [
    'Успешно' => ['Успешно', 'green'],
    'Отклонено' => ['Отклонено', 'red'],
    'В обработке' => ['В обработке', 'orange'],
    'Возвращен' => ['Возвращен', 'gray'],
  ];
  list($name, $color) = $statuses[$status] ?? [$status, 'default'];
  $class = "payments__table-cell_status-" . $color;
  return "<span class=\"$class\">$name</span>";
}
pg_close($conn);
$current_query_params = $_GET;
unset($current_query_params['success'], $current_query_params['error'], $current_query_params['new_id']);
$base_redirect_url = 'payments.php?' . http_build_query($current_query_params);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/../../images/hotel.ico">
  <title>Менедждер гостиницы. Платежи</title>
  <link rel="stylesheet" href="../../css/normalize.css">
  <link rel="stylesheet" href="../../css/reset.css">
  <link rel="stylesheet" href="../../css/fonts.css">
  <link rel="stylesheet" href="../../css/payments/payments.css">
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
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-log-out w-4 h-4"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" x2="9" y1="12" y2="12"></line></svg>
          Выход
        </a>
      </header>
      <main class="main">
        <div class="container">
          <div class="main__content">
            <div class="main__text">
              <h2 class="main__title">Платежи</h2>
              <p class="main__desc">Управление платежами и операциями</p>
            </div>
            <div class="main__actions">
              <button class="main__export-button">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-download w-4 h-4"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" x2="12" y1="15" y2="3"></line></svg>
                Экспорт
              </button>
              <a href="payment-new.php" class="main__add-button">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-plus w-4 h-4"><path d="M5 12h14"></path><path d="M12 5v14"></path></svg>
                Добавить платеж
              </a>
            </div>
          </div>
          <section class="statistics">
            <div class="statistics__block">
              <div class="statistics__img statistics__img_red">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trending-down w-5 h-5"><polyline points="22 17 13.5 8.5 8.5 13.5 2 7"></polyline><polyline points="16 17 22 17 22 11"></polyline></svg>
              </div>
              <div class="statistics__text">
                <p class="statistics__title">Общая сумма возвратов</p>
                <p class="statistics__value"><?= number_format($total_refund_amount_display, 0, '', ' ') ?> ₽</p>
              </div>
            </div>
            <div class="statistics__block">
              <div class="statistics__img statistics__img_green">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-dollar-sign w-5 h-5"><line x1="12" x2="12" y1="2" y2="22"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
              </div>
              <div class="statistics__text">
                <p class="statistics__title">Количество транзакций</p>
                <p class="statistics__value"><?= number_format($stats['total_transactions'] ?? 0, 0, '', ' ') ?></p>
              </div>
            </div>
            <div class="statistics__block">
              <div class="statistics__img statistics__img_blue">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-calculator w-5 h-5"><rect width="16" height="20" x="4" y="2" rx="2"></rect><line x1="8" x2="16" y1="6" y2="6"></line><line x1="16" x2="16" y1="14" y2="18"></line><path d="M16 10h.01"></path><path d="M12 10h.01"></path><path d="M8 10h.01"></path><path d="M12 14h.01"></path><path d="M8 14h.01"></path><path d="M12 18h.01"></path><path d="M8 18h.01"></path></svg>
              </div>
              <div class="statistics__text">
                <p class="statistics__title">Средний чек</p>
                <p class="statistics__value"><?= number_format($stats['average_check'] ?? 0, 0, '', ' ') ?> ₽</p>
              </div>
            </div>
          </section>
          <?php if (isset($_GET['success'])): ?>
            <div style="padding: 15px; margin-bottom: 20px; background-color: #d1e7dd; border: 1px solid #badbcc; color: #0f5132; border-radius: 5px;">
              <?php if ($_GET['success'] === 'refund_created'): ?>
                Возврат успешно оформлен! Создана новая транзакция #<?= htmlspecialchars($_GET['new_id'] ?? '') ?>.
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if (isset($_GET['error'])): ?>
            <div style="padding: 15px; margin-bottom: 20px; background-color: #f8d7da; border: 1px solid #f5c2c7; color: #842029; border-radius: 5px;">
              <?php 
              $error_message = match($_GET['error'] ?? '') {
                'invalid_id' => 'Ошибка: Некорректный ID транзакции.',
                'db_connection_failed' => 'Ошибка подключения к базе данных.',
                'transaction_not_found' => 'Ошибка: Исходный платеж не найден.',
                'already_refunded' => 'Ошибка: Возврат уже был оформлен, либо сумма платежа неположительна.',
                'cannot_refund_status' => 'Ошибка: Возврат можно оформить только для платежей со статусом "Успешно".',
                'refund_db_failed' => 'Ошибка: Не удалось создать запись о возврате в базе данных.',
                default => 'Произошла неизвестная ошибка.',
              };
              echo $error_message;
              ?>
            </div>
          <?php endif; ?>
          <section class="search">
            <form method="GET" action="payments.php" class="search__form">
              <div class="search__field-inputs">
                <input type="text" name="search_client" placeholder="Поиск по ФИО клиента или телефону..." class="search__input" value="<?= htmlspecialchars($search_client) ?>">
                <input type="text" name="search_booking" placeholder="Поиск по номеру бронирования..." class="search__input" value="<?= htmlspecialchars($search_booking) ?>">
              </div>
              <div class="search__fields">
                <div class="search__field">
                  <label for="date_from" class="search__label">Дата от</label>
                  <input type="date" name="date_from" id="date_from" class="search__input-date" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="search__field">
                  <label for="date_to" class="search__label">Дата до</label>
                  <input type="date" name="date_to" id="date_to" class="search__input-date" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="search__field">
                  <label for="select-type" class="search__label">Тип операции</label>
                  <select name="select_type" id="select-type" class="search__input-select">
                    <option value="all-types" <?= $select_type === 'all-types' ? 'selected' : '' ?>>Все типы</option>
                    <option value="Оплата" <?= $select_type === 'Оплата' ? 'selected' : '' ?>>Оплата</option>
                    <option value="Предоплата" <?= $select_type === 'Предоплата' ? 'selected' : '' ?>>Предоплата</option>
                    <option value="Возврат" <?= $select_type === 'Возврат' ? 'selected' : '' ?>>Возврат</option>
                    <option value="Коррекция" <?= $select_type === 'Коррекция' ? 'selected' : '' ?>>Коррекция</option>
                  </select>
                </div>
                <div class="search__field">
                  <label for="select-status" class="search__label">Статус</label>
                  <select name="select_status" id="select-status" class="search__input-select">
                    <option value="all-statuses" <?= $select_status === 'all-statuses' ? 'selected' : '' ?>>Все статусы</option>
                    <option value="Успешно" <?= $select_status === 'Успешно' ? 'selected' : '' ?>>Успешно</option>
                    <option value="Отклонено" <?= $select_status === 'Отклонено' ? 'selected' : '' ?>>Отклонено</option>
                    <option value="В обработке" <?= $select_status === 'В обработке' ? 'selected' : '' ?>>В обработке</option>
                  </select>
                </div>
                <div class="search__field">
                  <label for="select-payment-method" class="search__label">Способ оплаты</label>
                  <select name="select_payment_method" id="select-payment-method" class="search__input-select">
                    <option value="all-methods" <?= $select_payment_method === 'all-methods' ? 'selected' : '' ?>>Все способы</option>
                    <option value="Наличными" <?= $select_payment_method === 'Наличными' ? 'selected' : '' ?>>Наличными</option>
                    <option value="Картой" <?= $select_payment_method === 'Картой' ? 'selected' : '' ?>>Картой</option>
                    <option value="Онлайн оплата" <?= $select_payment_method === 'Онлайн оплата' ? 'selected' : '' ?>>Онлайн оплата</option>
                    <option value="Банковский перевод" <?= $select_payment_method === 'Банковский перевод' ? 'selected' : '' ?>>Банковский перевод</option>
                  </select>
                </div>
                <div class="search__field">
                  <label for="amount-from" class="search__label">Сумма от</label>
                  <input type="text" name="amount_from" placeholder="0" class="search__input" value="<?= htmlspecialchars($amount_from) ?>">
                </div>
                <div class="search__field">
                  <label for="amount-to" class="search__label">Сумма до</label>
                  <input type="text" name="amount_to" placeholder="999999" class="search__input" value="<?= htmlspecialchars($amount_to) ?>">
                </div>
                <button type="submit" class="search__submit-button" style="display: none;">Применить фильтры</button>
              </div>
              <div class="search__buttons">
                <button type="submit" class="search__button">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-search w-4 h-4"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>
                  Поиск и фильтр
                </button>
                <a href="payments.php" class="search__button">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-rotate-ccw w-4 h-4"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path></svg>
                  Сбросить фильтры
                </a>
              </div>
            </form>
          </section>
          <section class="payments">
            <table class="payments__table">
              <thead class="payments__table-header">
                <tr class="payments__table-row">
                  <th class="payments__table-head">ID транзакции</th>
                  <th class="payments__table-head">
                    <?php 
                    $new_sort_order_date = ($sort_by === 'payment_date' && $sort_order === 'DESC') ? 'ASC' : 'DESC';
                    $sort_url_date = http_build_query(array_merge($_GET, ['sort_by' => 'payment_date', 'sort_order' => $new_sort_order_date, 'page' => 1]));
                    ?>
                    <a href="?<?= $sort_url_date ?>" class="payments__table-head-button">Дата и время</a>
                  </th>
                  <th class="payments__table-head">Клиент</th>
                  <th class="payments__table-head">Бронирование</th>
                  <th class="payments__table-head">Способ оплаты</th>
                  <th class="payments__table-head">Тип операции</th>
                  <th class="payments__table-head">
                    <?php 
                    $new_sort_order_amount = ($sort_by === 'amount' && $sort_order === 'DESC') ? 'ASC' : 'DESC';
                    $sort_url_amount = http_build_query(array_merge($_GET, ['sort_by' => 'amount', 'sort_order' => $new_sort_order_amount, 'page' => 1]));
                    ?>
                    <a href="?<?= $sort_url_amount ?>" class="payments__table-head-button">Сумма</a>
                  </th>
                  <th class="payments__table-head">
                    <?php 
                    $new_sort_order_status = ($sort_by === 'payment_status' && $sort_order === 'DESC') ? 'ASC' : 'DESC';
                    $sort_url_status = http_build_query(array_merge($_GET, ['sort_by' => 'payment_status', 'sort_order' => $new_sort_order_status, 'page' => 1]));
                    ?>
                    <a href="?<?= $sort_url_status ?>" class="payments__table-head-button">Статус</a>
                  </th>
                  <th class="payments__table-head">Действия</th>
                </tr>
              </thead>
              <tbody class="payments__table-body">
                <?php if (empty($payments)): ?>
                  <tr class="payments__table-row">
                    <td class="payments__table-cell" colspan="9" style="text-align: center; padding: 20px;">Платежей не найдено.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($payments as $payment): ?>
                    <tr class="payments__table-row">
                      <td class="payments__table-cell"><?= htmlspecialchars($payment['transaction_id']) ?></td>
                      <td class="payments__table-cell"><?= date('Y-m-d H:i', strtotime($payment['payment_date'])) ?></td>
                      <td class="payments__table-cell"><?= htmlspecialchars($payment['client_full_name']) ?></td>
                      <td class="payments__table-cell">
                        <a href="../booking/booking-detail.php?id=<?= htmlspecialchars($payment['booking_id']) ?>" title="Перейти к бронированию">
                          <?= htmlspecialchars($payment['booking_id']) ?>
                        </a>
                      </td>
                      <td class="payments__table-cell"><?= get_payment_method_name(htmlspecialchars($payment['payment_method'])) ?></td>
                      <td class="payments__table-cell"><?= get_operation_type_name(htmlspecialchars($payment['operation_type'])) ?></td>
                      <td class="payments__table-cell payments__table-cell_amount">
                        <?= format_payment_amount($payment['amount']) ?>
                      </td>
                      <td class="payments__table-cell payments__table-cell_status">
                        <?= get_status_html(htmlspecialchars($payment['payment_status'])) ?>
                      </td>
                      <td class="payments__table-cell">
                        <?php 
                        $can_refund = ($payment['payment_status'] === 'Успешно' && (float)$payment['amount'] > 0);
                        $transaction_id = htmlspecialchars($payment['transaction_id']);
                        if ($can_refund): ?>
                          <button class="payments__table-cell-action refund-button" data-transaction-id="<?= $transaction_id ?>" title="Оформить возврат">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-rotate-ccw w-4 h-4"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path></svg>
                          </button>
                        <?php else: ?>
                          <button class="payments__table-cell-action payments__table-cell-action_disabled" title="Возврат невозможен" disabled>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-rotate-ccw w-4 h-4"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path></svg>
                          </button>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </section>
          <section class="pagination">
            <p class="pagination__text">Показано <?= $start_record ?>-<?= $end_record ?> из <?= $total_records ?> платежей</p>
            <div class="pagination__nav">
              <?php
              $prev_page = $page - 1;
              $prev_url = http_build_query(array_merge($_GET, ['page' => $prev_page]));
              if ($page > 1 && $total_records > 0): ?>
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
              if ($page < $total_pages && $total_records > 0): ?>
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
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      document.querySelectorAll('.refund-button').forEach(function(button) {
        button.addEventListener('click', function(e) {
          e.preventDefault();
          const transactionId = this.getAttribute('data-transaction-id');
          const buttonElement = this;
          if (!confirm('Вы уверены, что хотите оформить полный возврат по транзакции #' + transactionId + '?')) {
            return false;
          }
          const originalHTML = buttonElement.innerHTML;
          buttonElement.innerHTML = '<span style="font-size: 12px;">Обработка...</span>';
          buttonElement.disabled = true;
          buttonElement.style.opacity = '0.7';
          const refundUrl = '../../php/payments/process_refund.php?transaction_id=' + encodeURIComponent(transactionId);
          fetch(refundUrl)
            .then(response => {
              if (response.status === 400) {
                return response.json().then(errorData => { throw new Error(errorData.error || 'Ошибка при оформлении возврата'); });
              }
              if (!response.ok) { throw new Error('Ошибка сервера: ' + response.status); }
              return response.json();
            })
            .then(data => {
              if (data.success) {
                alert('Возврат успешно оформлен! Создана транзакция возврата #' + data.new_transaction_id);
                window.location.reload();
              } else { throw new Error(data.error || 'Неизвестная ошибка'); }
            })
            .catch(error => {
              let userMessage = error.message;
              if (error.message.includes('Возврат уже был оформлен')) {
                userMessage = 'Ошибка: Возврат уже был оформлен ранее для этой транзакции';
              } else if (error.message.includes('Исходный платеж не найден')) {
                userMessage = 'Ошибка: Исходный платеж не найден';
              } else if (error.message.includes('только для успешных платежей')) {
                userMessage = 'Ошибка: Возврат можно оформить только для платежей со статусом "Успешно"';
              } else if (error.message.includes('сумма должна быть положительной')) {
                userMessage = 'Ошибка: Возврат невозможен - сумма платежа неположительна';
              }
              alert(userMessage);
              buttonElement.innerHTML = originalHTML;
              buttonElement.disabled = false;
              buttonElement.style.opacity = '1';
            });
        });
      });
    });
  </script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const exportBtn = document.querySelector('.main__export-button');
      if (!exportBtn) return;
      exportBtn.addEventListener('click', function () {
        const params = new URLSearchParams(window.location.search);
        window.location.href = '../../php/payments/export_payments_pdf.php?' + params.toString();
      });
    });
  </script>
</body>
</html>