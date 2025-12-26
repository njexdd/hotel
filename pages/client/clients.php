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
$limit = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$doc_filter = isset($_GET['types-select']) ? $_GET['types-select'] : 'all-types';
$country_filter = isset($_GET['countries-select']) ? $_GET['countries-select'] : 'all-countries';
$active_bookings_checked = isset($_GET['active-bookings']);
$safe_search_term = pg_escape_string($dbconn, $search_term);
$safe_doc_filter = pg_escape_string($dbconn, $doc_filter);
$safe_country_filter = pg_escape_string($dbconn, $country_filter);
$active_bookings_status = $active_bookings_checked ? 'TRUE' : 'FALSE';
$offset = ($current_page - 1) * $limit;
$clients_procedure_query = "
  SELECT * FROM get_filtered_clients(
    '{$safe_search_term}',
    '{$safe_doc_filter}',
    '{$safe_country_filter}',
    {$active_bookings_status},
    {$limit},
    {$offset}
  );
";
$clients_result = pg_query($dbconn, $clients_procedure_query);
if (!$clients_result) {
  die("Ошибка выполнения запроса клиентов: " . pg_last_error($dbconn) . "<br>Запрос: " . $clients_procedure_query);
}
$total_clients = 0;
$clients_data = [];
$current_count = pg_num_rows($clients_result);
while ($row = pg_fetch_assoc($clients_result)) {
  if ($total_clients === 0 && isset($row['total_count'])) {
    $total_clients = (int)$row['total_count'];
  }
  $clients_data[] = $row;
}
$total_pages = ceil($total_clients / $limit);
$current_page = min($current_page, max(1, $total_pages));
$countries_query = "SELECT DISTINCT country FROM Clients WHERE country IS NOT NULL AND country != '' ORDER BY country";
$countries_result = pg_query($dbconn, $countries_query);
$doc_types_query = "SELECT DISTINCT document_type FROM Documents WHERE document_type IS NOT NULL AND document_type != '' ORDER BY document_type";
$doc_types_result = pg_query($dbconn, $doc_types_query);
function build_pagination_url($params_array, $page_num) {
  $new_params = $params_array;
  $new_params['page'] = $page_num;
  $clean_params = array_filter($new_params, function($v, $k) {
    if ($k === 'page') {
      return true;
    }
    if ($k === 'active-bookings') {
      return !empty($v) && $v !== '0';
    }
    return $v !== '' && $v !== 'all-types' && $v !== 'all-countries';
  }, ARRAY_FILTER_USE_BOTH);
  if (count($clean_params) === 1 && isset($clean_params['page']) && (int)$clean_params['page'] === 1) {
    unset($clean_params['page']);
  }
  return '?' . http_build_query($clean_params);
}
$base_params = $_GET;
unset($base_params['page']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/../../images/hotel.ico">
  <title>Менедждер гостиницы. Клиенты</title>
  <link rel="stylesheet" href="../../css/normalize.css">
  <link rel="stylesheet" href="../../css/reset.css">
  <link rel="stylesheet" href="../../css/fonts.css">
  <link rel="stylesheet" href="../../css/clients/clients.css">
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
              <h2 class="main__title">Список клиентов</h2>
              <p class="main__desc">Управление информацией о клиентах</p>
            </div>
            <a href="client-new.php" class="main__add-button">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-plus w-4 h-4"><path d="M5 12h14"></path><path d="M12 5v14"></path></svg>
              Добавить клиента
            </a>
          </div>
          <form action="clients.php" method="GET">
            <section class="search">
              <input type="search" name="search" id="search-input" placeholder="Поиск по ФИО, телефону, email или номеру документа..." class="search__input" value="<?php echo htmlspecialchars($search_term); ?>">
              <div class="search__filters">
                <div class="search__filters-type">
                  <label for="types-select" class="search__label">Тип документа</label>
                  <select name="types-select" id="types-select" class="search__select" onchange="this.form.submit()">
                    <option value="all-types" class="search__option">Все типы</option>
                    <?php 
                    if ($doc_types_result) pg_result_seek($doc_types_result, 0);
                    while ($doc_row = pg_fetch_assoc($doc_types_result)): 
                      $selected = ($doc_filter === $doc_row['document_type']) ? 'selected' : '';
                    ?>
                      <option value="<?php echo htmlspecialchars($doc_row['document_type']); ?>" class="search__option" <?php echo $selected; ?>>
                        <?php echo htmlspecialchars($doc_row['document_type']); ?>
                      </option>
                    <?php endwhile; ?>
                  </select>
                </div>
                <div class="search__filters-country">
                  <label for="countries-select" class="search__label">Страна</label>
                  <select name="countries-select" id="countries-select" class="search__select" onchange="this.form.submit()">
                    <option value="all-countries" class="search__option">Все</option>
                    <?php 
                    if ($countries_result) pg_result_seek($countries_result, 0);
                    while ($country_row = pg_fetch_assoc($countries_result)): 
                      $selected = ($country_filter === $country_row['country']) ? 'selected' : '';
                    ?>
                      <option value="<?php echo htmlspecialchars($country_row['country']); ?>" class="search__option" <?php echo $selected; ?>>
                        <?php echo htmlspecialchars($country_row['country']); ?>
                      </option>
                    <?php endwhile; ?>
                  </select>
                </div>
                <div class="search__filters-bookings">
                  <input type="checkbox" name="active-bookings" id="active-bookings-checkbox" class="search__checkbox" <?php echo $active_bookings_checked ? 'checked' : ''; ?> onchange="this.form.submit()">
                  <label for="active-bookings-checkbox" class="search__label">Только с активными бронированиями</label>
                </div>
              </div>
              <button type="button" onclick="window.location.href='clients.php'" class="search__reset-button">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-rotate-ccw w-4 h-4"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path></svg>
                Сбросить фильтры
              </button>
              <button type="submit" style="display: none;">Применить поиск</button>
            </section>
          </form>
          <section class="clients">
            <table class="clients__table">
              <thead class="clients__table-header">
                <tr class="clients__table-row">
                  <th class="clients__table-head"><button class="clients__table-head-button">Клиент</button></th>
                  <th class="clients__table-head"><button class="clients__table-head-button">Документ</button></th>
                  <th class="clients__table-head"><button class="clients__table-head-button">Дата регистрации</button></th>
                  <th class="clients__table-head"><button class="clients__table-head-button">Активные бронирования</button></th>
                  <th class="clients__table-head">Действия</th>
                </tr>
              </thead>
              <tbody class="clients__table-body">
                <?php foreach ($clients_data as $client_row): 
                  $reg_date_formatted = date('d.m.Y', strtotime($client_row['registration_date']));
                ?>
                  <tr class="clients__table-row">
                    <td class="clients__table-cell">
                      <div class="clients__client">
                        <span><?php echo htmlspecialchars($client_row['full_name']); ?></span>
                        <span class="clients_gray"><?php echo htmlspecialchars($client_row['phone']); ?></span>
                        <span class="clients_gray"><?php echo htmlspecialchars($client_row['email']); ?></span>
                      </div>
                    </td>
                    <td class="clients__table-cell">
                      <div class="clients__document">
                        <span><?php echo htmlspecialchars($client_row['document_type']); ?></span>
                        <span class="clients_gray"><?php echo htmlspecialchars($client_row['series_number']); ?></span>
                      </div>
                    </td>
                    <td class="clients__table-cell"><?php echo $reg_date_formatted; ?></td>
                    <td class="clients__table-cell"><?php echo htmlspecialchars($client_row['active_bookings_count']); ?></td>
                    <td class="clients__table-cell">
                      <div class="clients__table-cell-actions">
                        <a href="client-card.php?id=<?php echo $client_row['client_id']; ?>" class="clients__table-cell-action">
                          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-eye w-4 h-4"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </section>
          <section class="pagination">
            <?php 
              $start_record = $total_clients > 0 ? $offset + 1 : 0;
              $end_record = $offset + $current_count;
            ?>
            <p class="pagination__text">Показано <?php echo $start_record; ?>-<?php echo $end_record; ?> из <?php echo $total_clients; ?> клиентов</p>
            <?php if ($total_pages > 1 || $total_clients > 0):?>
            <div class="pagination__nav">
              <?php if ($current_page > 1): ?>
                <?php $prev_url = build_pagination_url($base_params, $current_page - 1); ?>
                <a href="<?php echo htmlspecialchars($prev_url); ?>" class="pagination__button">Назад</a>
              <?php else: ?>
                <button disabled class="pagination__button pagination__button_disabled">Назад</button>
              <?php endif; ?>
              <div class="pagination__pages">
                <?php
                $start_page = max(1, $current_page - 2);
                $end_page = min($total_pages, $current_page + 2);
                if ($end_page - $start_page < 4) {
                  if ($current_page - 2 < 1) { $end_page = min($total_pages, $start_page + 4); }
                  elseif ($current_page + 2 > $total_pages) { $start_page = max(1, $end_page - 4); }
                }
                if ($start_page > 1) { echo '<span style="padding: 0 5px;">...</span>'; }
                for ($p = $start_page; $p <= $end_page; $p++):
                  $page_url = build_pagination_url($base_params, $p);
                ?>
                  <a href="<?php echo htmlspecialchars($page_url); ?>" class="pagination__pages-button <?php echo $p === $current_page ? 'pagination__pages-button_current' : ''; ?>">
                    <?php echo $p; ?>
                  </a>
                <?php endfor; 
                if ($end_page < $total_pages) { echo '<span style="padding: 0 5px;">...</span>'; }
                ?>
              </div>
              <?php if ($current_page < $total_pages): ?>
                <?php $next_url = build_pagination_url($base_params, $current_page + 1); ?>
                <a href="<?php echo htmlspecialchars($next_url); ?>" class="pagination__button">Вперёд</a>
              <?php else: ?>
                <button disabled class="pagination__button pagination__button_disabled">Вперёд</button>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </section>
        </div>
      </main>
    </div>
  </div>
<?php
if ($dbconn) { pg_close($dbconn); }
?>
</body>
</html>