<?php
session_start();
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
  header("Location: ../../pages/login/login.php");
  exit;
}
require_once '../../php/config/config.php';
$room_number = $_GET['id'] ?? null;
if (!$room_number) {
  die("<h1>Ошибка: Номер комнаты не указан.</h1>");
}
$dbconn = db_connect();
if (!$dbconn) {
  die("<h1>Ошибка подключения к базе данных.</h1>");
}
$query_room_types_sql = "SELECT room_type_id, type_name, capacity FROM room_types ORDER BY room_type_id";
$room_types_result = pg_query($dbconn, $query_room_types_sql);
$all_room_types = $room_types_result ? pg_fetch_all($room_types_result) : [];
$query_all_amenities_sql = "SELECT amenity_name FROM amenities ORDER BY amenity_name";
$all_amenities_result = pg_query($dbconn, $query_all_amenities_sql);
$all_amenities = $all_amenities_result ? pg_fetch_all_columns($all_amenities_result) : [];
$STATUSES_MAP_REVERSE = [
  'Свободен' => 'free',
  'Забронирован' => 'booked',
  'Занят' => 'busy',
  'На ремонте' => 'repair',
  'Убирается' => 'cleaning',
  'Готов к заселению' => 'ready',
  'Нуждается в уборке' => 'need-cleaning',
];
$all_statuses = array_keys($STATUSES_MAP_REVERSE);
$CAPACITIES_MAP = [
  1 => '1 чел.',
  2 => '2 чел.',
  3 => '3 чел.',
  4 => '4 чел.',
  5 => '5 чел.',
];
function get_status_class($status) {
  return match ($status) {
    'Свободен' => 'data__status_free',
    'Готов к заселению' => 'data__status_ready',
    'Занят' => 'data__status_busy',
    'На ремонте' => 'data__status_repair',
    'Забронирован' => 'data__status_booked',
    'Убирается' => 'data__status_cleaning',
    'Нуждается в уборке' => 'data__status_need-cleaning',
    default => '',
  };
}
$query_room_sql = "
  SELECT 
    r.room_number,
    r.status,
    r.floor,
    r.area,
    rt.type_name, 
    rt.capacity, 
    rt.base_price,
    rt.room_type_id
  FROM rooms r
  JOIN room_types rt ON r.room_type_id = rt.room_type_id
  WHERE r.room_number = $1
";
$room_result = pg_query_params($dbconn, $query_room_sql, array($room_number));
$room_data = $room_result ? pg_fetch_assoc($room_result) : null;
if (!$room_data) {
  pg_close($dbconn);
  die("<h1>Ошибка: Номер комнаты {$room_number} не найден.</h1>");
}
$query_amenities_sql = "
  SELECT 
    a.amenity_name
  FROM room_amenities ra
  JOIN amenities a ON ra.amenity_id = a.amenity_id
  WHERE ra.room_number = $1
";
$amenities_result = pg_query_params($dbconn, $query_amenities_sql, array($room_number));
$room_amenities = $amenities_result ? pg_fetch_all_columns($amenities_result) : [];
$amenities_map = array_flip($room_amenities);
$query_history_sql = "
  SELECT 
    check_in_date, 
    check_out_date, 
    booking_status,
    full_name, 
    client_id
  FROM room_booking_history
  WHERE room_number = $1
  ORDER BY check_in_date DESC
  LIMIT 10
";
$history_result = pg_query_params($dbconn, $query_history_sql, array($room_number));
$booking_history = $history_result ? pg_fetch_all($history_result) : [];
pg_close($dbconn);
function is_amenity_checked($amenities_map, $amenity_name) {
  return isset($amenities_map[$amenity_name]) ? 'checked' : '';
}
function get_booking_status_class($status) {
  return match ($status) {
    'Активно' => 'data__booking-history_active',
    'Подтверждено' => 'data__booking-history_confirmed',
    'Завершено' => 'data__booking-history_completed',
    'Отменено' => 'data__booking-history_cancelled',
    'Просрочено' => 'data__booking-history_overdue',
    default => '',
  };
}
function format_client_name($full_name) {
  $full_name = trim($full_name);
  if (empty($full_name)) {
    return 'Неизвестный клиент';
  }
  $parts = explode(' ', $full_name);
  if (count($parts) >= 2) {
    $lastName = array_shift($parts);
    $initials = '';
    foreach ($parts as $name) {
      $initials .= mb_substr($name, 0, 1, 'UTF-8') . '.';
    }
    return $lastName . ' ' . trim($initials);
  }
  return $full_name;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/../../images/hotel.ico">
  <title>Менедждер гостиницы. Карточка номера</title>
  <link rel="stylesheet" href="../../css/normalize.css">
  <link rel="stylesheet" href="../../css/reset.css">
  <link rel="stylesheet" href="../../css/fonts.css">
  <link rel="stylesheet" href="../../css/rooms/room-card.css">
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
              <h2 class="main__title">Номер <?php echo htmlspecialchars($room_data['room_number']); ?> - <?php echo htmlspecialchars($room_data['type_name']); ?></h2>
              <p class="main__desc">Информация о номере</p>
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
              <button id="delete-button" class="main__button main__button_red" onclick="deleteRoom()">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trash2 w-4 h-4"><path d="M3 6h18"></path><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path><line x1="10" x2="10" y1="11" y2="17"></line><line x1="14" x2="14" y1="11" y2="17"></line></svg>
                <span id="delete-button-text">Удалить</span>
              </button>
              <button id="cancel-button" class="main__button" style="display: none;" onclick="cancelChanges()">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x w-4 h-4"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg>
                Отменить изменения
              </button>
            </div>
          </div>
          <form id="room-edit-form" action="../../php/rooms/update_room.php" method="POST">
            <section class="data">
              <div class="data__general">
                <h3 class="data__title">Общая информация</h3>
                <hr>
                <div class="data__general-data">
                  <div class="data__block">
                    <label for="room-number" class="data__label">Номер комнаты</label>
                    <input name="room_number" id="room-number" class="data__input" value="<?php echo htmlspecialchars($room_data['room_number']); ?>" readonly>
                  </div>
                  <div class="data__block">
                    <label for="type-select" class="data__label">Тип номера</label>
                    <select name="room_type_id" id="type-select" class="data__select" disabled>
<?php foreach ($all_room_types as $type): ?>
                      <option value="<?php echo htmlspecialchars($type['room_type_id']); ?>" data-capacity="<?php echo htmlspecialchars($type['capacity']); ?>" <?php echo ($type['room_type_id'] == $room_data['room_type_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($type['type_name']); ?>
                      </option>
<?php endforeach; ?>
                    </select>
                  </div>
                  <div class="data__block">
                    <label for="capacity-select" class="data__label">Количество гостей</label>
                    <select name="capacity" id="capacity-select" class="data__select" disabled>
<?php
  $current_capacity_key = (int)$room_data['capacity'];
  foreach ($CAPACITIES_MAP as $key => $label): 
    $selected = ($key == $current_capacity_key) ? 'selected' : '';
?>
                      <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $selected; ?>>
                        <?php echo htmlspecialchars($label); ?>
                      </option>
<?php endforeach; ?>
                    </select>
                  </div>
                  <div class="data__block">
                    <label for="floor" class="data__label">Этаж</label>
                    <input name="floor" id="floor" class="data__input" value="<?php echo htmlspecialchars($room_data['floor']); ?>" readonly>
                  </div>
                  <div class="data__block">
                    <label for="area" class="data__label">Площадь кв. м.</label>
                    <input name="area" id="area" class="data__input" value="<?php echo htmlspecialchars($room_data['area']); ?>" readonly>
                  </div>
                </div>
              </div>
              <div class="data__status">
                <h3 class="data__title">Статус номера</h3>
                <hr>
                <div class="data__status-data">
                  <div class="data__block">
                    <label for="status-select" class="data__label">Статус</label>
                    <select name="status-select" id="status-select" class="data__select" disabled>
<?php foreach ($all_statuses as $status_name): ?>
                      <option value="<?php echo htmlspecialchars($STATUSES_MAP_REVERSE[$status_name]); ?>" <?php echo ($status_name == $room_data['status']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($status_name); ?>
                      </option>
<?php endforeach; ?>
                    </select>
                  </div>
                  <span id="current-status-display" class="<?php echo get_status_class($room_data['status']); ?>">
                    <?php echo htmlspecialchars($room_data['status']); ?>
                  </span>
                </div>
              </div>
              <div class="data__price">
                <h3 class="data__title">Цена</h3>
                <hr>
                <div class="data__price-data">
                  <div class="data__block">
                    <label for="base_price" class="data__label">Базовая цена за сутки (₽)</label>
                    <input name="base_price" id="base_price" class="data__input" value="<?php echo number_format($room_data['base_price'], 0, '', ' '); ?>" readonly>
                  </div>
                </div>
              </div>
              <div class="data__conveniences">
                <h3 class="data__title">Удобства и оснащение</h3>
                <hr>
                <div class="data__conveniences-data">
                  <?php foreach ($all_amenities as $amenity_name): ?>
                  <div class="data__block">
                    <input type="checkbox" name="amenity_<?php echo htmlspecialchars($amenity_name); ?>" id="checkbox-<?php echo strtolower(str_replace([' ', '-'], '_', $amenity_name)); ?>" class="data__checkbox" <?php echo is_amenity_checked($amenities_map, $amenity_name); ?> disabled>
                    <label for="checkbox-<?php echo strtolower(str_replace([' ', '-'], '_', $amenity_name)); ?>" class="data__label">
                      <?php echo htmlspecialchars($amenity_name); ?>
                    </label>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </section>
          </form>
          <section class="data__booking-history">
            <h3 class="data__title">История бронирований</h3>
            <hr>
            <div class="data__booking-history-data">
              <table class="data__table">
                <thead class="data__table-header">
                  <tr class="data__table-row">
                    <th class="data__table-head">Дата заезда</th>
                    <th class="data__table-head">Дата выезда</th>
                    <th class="data__table-head">Клиент</th>
                    <th class="data__table-head">Статус</th>
                  </tr>
                </thead>
                <tbody class="data__table-body">
                  <?php if (!empty($booking_history)): ?>
                    <?php foreach ($booking_history as $booking): ?>
                    <tr class="data__table-row">
                      <td class="data__table-cell"><?php echo date('Y-m-d', strtotime($booking['check_in_date'])); ?></td>
                      <td class="data__table-cell"><?php echo date('Y-m-d', strtotime($booking['check_out_date'])); ?></td>
                      <td class="data__table-cell data__table-cell_bold">
                        <a href="../client/client-card.php?id=<?php echo urlencode($booking['client_id']); ?>">
                          <?php echo htmlspecialchars(format_client_name($booking['full_name'])); ?>
                        </a>
                      </td>
                      <td class="data__table-cell">
                        <span class="<?php echo get_booking_status_class($booking['booking_status']); ?>">
                          <?php echo htmlspecialchars($booking['booking_status']); ?>
                        </span>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                  <tr class="data__table-row">
                    <td colspan="4" class="data__table-cell" style="text-align: center;">
                      История бронирований для этого номера пуста.
                    </td>
                  </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </main>
    </div>
  </div>
<script>
  const editButton = document.getElementById('edit-button');
  const saveButton = document.getElementById('save-button');
  const deleteButton = document.getElementById('delete-button');
  const cancelButton = document.getElementById('cancel-button');
  const form = document.getElementById('room-edit-form');
  const editableInputs = document.querySelectorAll('.data__input');
  const editableSelects = document.querySelectorAll('.data__select');
  const editableCheckboxes = document.querySelectorAll('.data__checkbox');
  const roomNumberInput = document.querySelector('input[name="room_number"]');
  let originalValues = {};
  function saveOriginalValues() {
    editableInputs.forEach(input => {
      originalValues[input.name] = input.value;
    });
    editableSelects.forEach(select => {
      originalValues[select.name] = select.value;
    });
    editableCheckboxes.forEach(checkbox => {
      originalValues[checkbox.name] = checkbox.checked;
    });
  }
  window.onload = saveOriginalValues;
  function setEditMode(isEditMode) {
    editableInputs.forEach(input => {
      if (input !== roomNumberInput) {
        input.readOnly = !isEditMode;
      }
    });
    editableSelects.forEach(select => {
      select.disabled = !isEditMode;
    });
    editableCheckboxes.forEach(checkbox => {
      checkbox.disabled = !isEditMode;
    });
    editButton.style.display = isEditMode ? 'none' : 'flex';
    deleteButton.style.display = isEditMode ? 'none' : 'flex';
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
    const priceInput = document.getElementById('base_price');
    const areaInput = document.getElementById('area');
    formData.set('base_price', priceInput.value.replace(/\s/g, '').replace(',', '.'));
    formData.set('area', areaInput.value.replace(/\s/g, '').replace(',', '.'));
    fetch(form.action, {
      method: form.method,
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        alert("Изменения успешно сохранены!");
        window.location.reload(); 
      } else {
        alert("Ошибка сохранения: " + data.message);
      }
    })
    .catch(error => {
      console.error('Ошибка при отправке данных:', error);
      alert("Произошла ошибка сети или сервера.");
    })
    .finally(() => {
      setEditMode(false);
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
    editableCheckboxes.forEach(checkbox => {
      if (originalValues[checkbox.name] !== undefined) {
        checkbox.checked = originalValues[checkbox.name];
      }
    });
    setEditMode(false);
  }
  async function deleteRoom() {
    const roomNumber = "<?php echo htmlspecialchars($room_data['room_number']); ?>";
    try {
      const checkResponse = await fetch('../../php/rooms/check_room_deletable.php?id=' + encodeURIComponent(roomNumber));
      if (!checkResponse.ok) {
        throw new Error('Ошибка проверки: ' + checkResponse.status);
      }
      const checkResult = await checkResponse.json();
      if (!checkResult.deletable) {
        alert('Удаление невозможно:\n' + checkResult.message + '\n\nСтатус номера: ' + checkResult.room_status + '\nАктивных бронирований: ' + checkResult.active_bookings);
        return;
      }
    } catch (error) {
      console.error('Ошибка проверки:', error);
    }
    if (!confirm('Вы уверены, что хотите удалить номер ' + roomNumber + '?\n\nУдаление приведет к:\n• Удалению номера из базы данных\n• Удалению всех бронирований этого номера\n• Удалению всех связанных платежей\n• Удалению связей с удобствами')) {
      return;
    }
    const deleteButton = document.getElementById('delete-button');
    const deleteButtonText = document.getElementById('delete-button-text');
    const originalText = deleteButtonText.textContent;
    try {
      deleteButton.disabled = true;
      deleteButtonText.textContent = 'Удаление...';
      const response = await fetch('../../php/rooms/delete_room.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          room_number: roomNumber
        })
      });
      const contentType = response.headers.get('content-type');
      if (!contentType || !contentType.includes('application/json')) {
        throw new Error('Ожидался JSON ответ');
      }
      const result = await response.json();
      if (result.success) {
        alert(result.message);
        setTimeout(() => {
          window.location.href = result.redirect || '../room/rooms.php';
        }, 500);
      } else {
        alert('Ошибка удаления: ' + result.message);
        deleteButton.disabled = false;
        deleteButtonText.textContent = originalText;
      }
    } catch (error) {
      console.error('Ошибка сети или обработки JSON:', error);
      if (error.message.includes('JSON')) {
        alert('Ошибка на сервере. Пожалуйста, проверьте логи сервера.');
      } else {
        alert('Произошла критическая ошибка при попытке удаления: ' + error.message);
      }
      deleteButton.disabled = false;
      deleteButtonText.textContent = originalText;
    }
  }
</script>
</body>
</html>