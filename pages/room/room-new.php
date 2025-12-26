<?php
session_start();
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
  header("Location: ../../pages/login/login.php");
  exit;
}
require_once '../../php/config/config.php';
function fetch_data($table) {
  $dbconn = db_connect();
  if (!$dbconn) {
    return [];
  }
  $query = "SELECT * FROM public.$table ORDER BY 1";
  $result = pg_query($dbconn, $query);
  $data = [];
  if ($result) {
    $data = pg_fetch_all($result);
    pg_free_result($result);
  }
  pg_close($dbconn);
  return $data ?: [];
}
$room_types = fetch_data('room_types');
$amenities = fetch_data('amenities');
$statuses = [
  'Свободен',
  'Забронирован',
  'Занят',
  'На уборке',
  'На ремонте',
  'Готов к заселению',
  'Нуждается в уборке'
];
$room_types_data = [];
foreach ($room_types as $type) {
  $room_types_data[$type['room_type_id']] = [
    'base_price' => $type['base_price'],
    'capacity' => $type['capacity']
  ];
}
$message = $_SESSION['room_message'] ?? '';
$is_error = $_SESSION['room_is_error'] ?? false;
unset($_SESSION['room_message'], $_SESSION['room_is_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/../../images/hotel.ico">
  <title>Менеджер гостиницы. Создание номера</title>
  <link rel="stylesheet" href="../../css/normalize.css">
  <link rel="stylesheet" href="../../css/reset.css">
  <link rel="stylesheet" href="../../css/fonts.css">
  <link rel="stylesheet" href="../../css/rooms/room-new.css">
  <style>
    .message-box {
      padding: 15px;
      margin-bottom: 20px;
      border-radius: 4px;
      font-size: 16px;
    }
    .success {
      background-color: #d4edda;
      color: #155724;
      border: 1px solid #c3e6cb;
    }
    .error {
      background-color: #f8d7da;
      color: #721c24;
      border: 1px solid #f5c6cb;
    }
    .room-form__readonly-value span {
      color: #333;
    }
    .room-form__readonly-value .placeholder {
      color: #999;
      font-style: italic;
    }
    .error-message {
      color: #dc3545;
      font-size: 14px;
      margin-top: 5px;
      display: none;
    }
    .room-form__input.error-border,
    .room-form__select.error-border {
      border-color: #dc3545;
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
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-layout-dashboard w-5 h-5">
                <rect width="7" height="9" x="3" y="3" rx="1"></rect>
                <rect width="7" height="5" x="14" y="3" rx="1"></rect>
                <rect width="7" height="9" x="14" y="12" rx="1"></rect>
                <rect width="7" height="5" x="3" y="16" rx="1"></rect>
              </svg>
              Dashboard
            </a>
          </li>
          <li class="nav__item">
            <a href="../client/clients.php" class="nav__item-link">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-users w-5 h-5">
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
              </svg>
              Клиенты
            </a>
          </li>
          <li class="nav__item">
            <a href="../room/rooms.php" class="nav__item-link">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-door-open w-5 h-5">
                <path d="M13 4h3a2 2 0 0 1 2 2v14"></path>
                <path d="M2 20h3"></path>
                <path d="M13 20h9"></path>
                <path d="M10 12v.01"></path>
                <path d="M13 4.562v16.157a1 1 0 0 1-1.242.97L5 20V5.562a2 2 0 0 1 1.515-1.94l4-1A2 2 0 0 1 13 4.561Z"></path>
              </svg>
              Номера
            </a>
          </li>
          <li class="nav__item">
            <a href="../booking/bookings.php" class="nav__item-link">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-calendar w-5 h-5">
                <path d="M8 2v4"></path>
                <path d="M16 2v4"></path>
                <rect width="18" height="18" x="3" y="4" rx="2"></rect>
                <path d="M3 10h18"></path>
              </svg>
              Бронирования
            </a>
          </li>
          <li class="nav__item">
            <a href="../payments/payments.php" class="nav__item-link">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-credit-card w-5 h-5">
                <rect width="20" height="14" x="2" y="5" rx="2"></rect>
                <line x1="2" x2="22" y1="10" y2="10"></line>
              </svg>
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
              <h2 class="main__title">Создание нового номера</h2>
              <p class="main__desc">Заполните информацию о новом номере</p>
            </div>
            <a href="rooms.php" class="main__cancel-button">Отмена</a>
          </div>
          <?php if (!empty($message)): ?>
            <div class="message-box <?php echo $is_error ? 'error' : 'success'; ?>">
              <?php echo htmlspecialchars($message); ?>
            </div>
          <?php endif; ?>
          <form action="../../php/rooms/create_room.php" method="POST" class="room-form" id="roomForm" onsubmit="return submitForm(event)">
            <section class="room-form__card">
              <h3 class="room-form__title">Общая информация</h3>
              <hr>
              <div class="room-form__card-general">
                <div class="room-form__field">
                  <label for="room-number" class="room-form__label">Номер комнаты</label>
                  <input type="text" name="room_number" id="room-number" placeholder="101" class="room-form__input" required oninput="validateRoomNumber()">
                  <div class="error-message" id="room-number-error">Номер комнаты должен быть положительным числом</div>
                </div>
                <div class="room-form__field">
                  <label for="floor" class="room-form__label">Этаж</label>
                  <input type="number" name="floor" id="floor" placeholder="1" class="room-form__input" min="0" required oninput="validateFloor()">
                  <div class="error-message" id="floor-error">Этаж должен быть положительным числом</div>
                </div>
                <div class="room-form__field">
                  <label for="area" class="room-form__label">Площадь кв. м.</label>
                  <input type="number" name="area" id="area" placeholder="25.50" class="room-form__input" step="0.01" min="0" required oninput="validateArea()">
                  <div class="error-message" id="area-error">Площадь должна быть положительным числом</div>
                </div>
                <div class="room-form__field">
                  <label for="room_type_id" class="room-form__label">Тип номера</label>
                  <select name="room_type_id" id="room_type_id" class="room-form__select" required onchange="updateRoomTypeInfo()">
                    <option value="" disabled selected>Выберите тип</option>
                    <?php foreach ($room_types as $type): ?>
                      <option value="<?php echo htmlspecialchars($type['room_type_id']); ?>">
                        <?php echo htmlspecialchars($type['type_name']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="error-message" id="room-type-error">Выберите тип номера</div>
                </div>
              </div>
            </section>
            <section class="room-form__card">
              <h3 class="room-form__title">Цена и вместимость</h3>
              <hr>
              <div class="room-form__card-price-and-capacity">
                <div class="room-form__field">
                  <label class="room-form__label">Базовая цена за сутки (₽)</label>
                  <div class="room-form__readonly-value" id="base_price_display">
                    <span class="placeholder">Выберите тип номера</span>
                  </div>
                  <input type="hidden" name="base_price" id="base_price" value="">
                </div>
                <div class="room-form__field">
                  <label class="room-form__label">Вместимость</label>
                  <div class="room-form__readonly-value" id="capacity_display">
                    <span class="placeholder">Выберите тип номера</span>
                  </div>
                  <input type="hidden" name="capacity" id="capacity" value="">
                </div>
              </div>
            </section>
            <section class="room-form__card">
              <h3 class="room-form__title">Статус номера</h3>
              <hr>
              <div class="room-form__card-status">
                <div class="room-form__field">
                  <label for="status" class="room-form__label">Статус</label>
                  <select name="status" id="status" class="room-form__select" required>
                    <option value="" disabled selected>Выберите статус</option>
                    <?php foreach ($statuses as $status_name): ?>
                      <option value="<?php echo htmlspecialchars($status_name); ?>">
                        <?php echo htmlspecialchars($status_name); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="error-message" id="status-error">Выберите статус номера</div>
                </div>
              </div>
            </section>
            <section class="room-form__card">
              <h3 class="room-form__title">Удобства и оснащение</h3>
              <hr>
              <div class="room-form__card-equipment">
                <?php
                foreach ($amenities as $amenity):
                  $id = htmlspecialchars($amenity['amenity_id']);
                  $name = htmlspecialchars($amenity['amenity_name']);
                ?>
                  <div class="room-form__field">
                    <input type="checkbox" name="amenities[]" id="amenity_<?php echo $id; ?>" value="<?php echo $id; ?>" class="room-form__checkbox">
                    <label for="amenity_<?php echo $id; ?>" class="room-form__label"><?php echo $name; ?></label>
                  </div>
                <?php endforeach; ?>
              </div>
            </section>
            <div class="room-form__buttons">
              <a href="rooms.php" class="room-form__cancel-button">Отмена</a>
              <button type="submit" class="room-form__create-button">Создать номер</button>
            </div>
          </form>
        </div>
      </main>
    </div>
  </div>
  <script>
    const roomTypesData = <?php echo json_encode($room_types_data); ?>;
    function updateRoomTypeInfo() {
      const select = document.getElementById('room_type_id');
      const basePriceDisplay = document.getElementById('base_price_display');
      const capacityDisplay = document.getElementById('capacity_display');
      const basePriceInput = document.getElementById('base_price');
      const capacityInput = document.getElementById('capacity');
      const selectedId = select.value;
      if (selectedId && roomTypesData[selectedId]) {
        const roomType = roomTypesData[selectedId];
        const formattedPrice = new Intl.NumberFormat('ru-RU', {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        }).format(roomType.base_price);
        basePriceDisplay.innerHTML = `<span>${formattedPrice} ₽</span>`;
        capacityDisplay.innerHTML = `<span>${roomType.capacity} чел.</span>`;
        basePriceInput.value = roomType.base_price;
        capacityInput.value = roomType.capacity;
      } else {
        basePriceDisplay.innerHTML = '<span class="placeholder">Выберите тип номера</span>';
        capacityDisplay.innerHTML = '<span class="placeholder">Выберите тип номера</span>';
        basePriceInput.value = '';
        capacityInput.value = '';
      }
    }
    function validateRoomNumber() {
      const roomNumberInput = document.getElementById('room-number');
      const errorElement = document.getElementById('room-number-error');
      const roomNumber = roomNumberInput.value.trim();
      const isValid = /^[1-9]\d*$/.test(roomNumber);
      if (!isValid && roomNumber !== '') {
        showError(roomNumberInput, errorElement, 'Номер комнаты должен быть положительным целым числом');
        return false;
      } else {
        hideError(roomNumberInput, errorElement);
        return true;
      }
    }
    function validateFloor() {
      const floorInput = document.getElementById('floor');
      const errorElement = document.getElementById('floor-error');
      const floor = parseFloat(floorInput.value);
      if (floor <= 0 && floorInput.value !== '') {
        showError(floorInput, errorElement, 'Этаж должен быть положительным числом');
        return false;
      } else {
        hideError(floorInput, errorElement);
        return true;
      }
    }
    function validateArea() {
      const areaInput = document.getElementById('area');
      const errorElement = document.getElementById('area-error');
      const area = parseFloat(areaInput.value);
      if (area <= 0 && areaInput.value !== '') {
        showError(areaInput, errorElement, 'Площадь должна быть положительным числом');
        return false;
      } else {
        hideError(areaInput, errorElement);
        return true;
      }
    }
    function validateRoomType() {
      const roomTypeSelect = document.getElementById('room_type_id');
      const errorElement = document.getElementById('room-type-error');
      if (!roomTypeSelect.value) {
        showError(roomTypeSelect, errorElement, 'Выберите тип номера');
        return false;
      } else {
        hideError(roomTypeSelect, errorElement);
        return true;
      }
    }
    function validateStatus() {
      const statusSelect = document.getElementById('status');
      const errorElement = document.getElementById('status-error');
      if (!statusSelect.value) {
        showError(statusSelect, errorElement, 'Выберите статус номера');
        return false;
      } else {
        hideError(statusSelect, errorElement);
        return true;
      }
    }
    function showError(inputElement, errorElement, message) {
      inputElement.classList.add('error-border');
      errorElement.textContent = message;
      errorElement.style.display = 'block';
    }
    function hideError(inputElement, errorElement) {
      inputElement.classList.remove('error-border');
      errorElement.style.display = 'none';
    }
    function validateForm() {
      const isRoomNumberValid = validateRoomNumber();
      const isFloorValid = validateFloor();
      const isAreaValid = validateArea();
      const isRoomTypeValid = validateRoomType();
      const isStatusValid = validateStatus();
      return isRoomNumberValid && isFloorValid && isAreaValid && isRoomTypeValid && isStatusValid;
    }
    async function submitForm(event) {
      event.preventDefault();
      if (!validateForm()) {
        alert('Пожалуйста, исправьте ошибки в форме.');
        return false;
      }
      const submitButton = document.querySelector('button[type="submit"]');
      const originalText = submitButton.textContent;
      submitButton.textContent = 'Создание...';
      submitButton.disabled = true;
      try {
        const formData = new FormData(document.getElementById('roomForm'));
        const response = await fetch('../../php/rooms/create_room.php', {
          method: 'POST',
          body: formData
        });
        const result = await response.json();
        if (result.success) {
          alert(result.message);
          window.location.href = 'rooms.php';
        } else {
          alert('Ошибка: ' + result.message);
          if (result.message.includes('уже существует')) {
            document.getElementById('room-number').focus();
            document.getElementById('room-number').select();
          }
        }
      } catch (error) {
        alert('Произошла ошибка при отправке формы: ' + error.message);
      } finally {
        submitButton.textContent = originalText;
        submitButton.disabled = false;
      }
      return false;
    }
    document.addEventListener('DOMContentLoaded', function() {
      updateRoomTypeInfo();
      document.getElementById('room_type_id').addEventListener('change', function() {
        updateRoomTypeInfo();
        validateRoomType();
      });
      document.getElementById('status').addEventListener('change', validateStatus);
      document.getElementById('room-number').addEventListener('blur', validateRoomNumber);
      document.getElementById('floor').addEventListener('blur', validateFloor);
      document.getElementById('area').addEventListener('blur', validateArea);
    });
  </script>
</body>
</html>