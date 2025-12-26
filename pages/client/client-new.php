<?php
session_start();
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
  header("Location: ../../pages/login/login.php");
  exit;
}
require_once '../../php/config/config.php';
$COUNTRIES = [
  'russia' => 'Россия',
  'belarus' => 'Беларусь',
  'kazakhstan' => 'Казахстан'
];
$DOC_TYPES = [
  'passport' => 'Паспорт',
  'international-passport' => 'Загранпаспорт',
  'drivers-license' => 'Водительское удостоверение',
  'military-ticket' => 'Военный билет'
];
$GENDERS = [
  'male' => 'Мужской',
  'female' => 'Женский'
];
$errors = [];
$data = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $dbconn = db_connect();
  if (!$dbconn) {
    die("Ошибка подключения к базе данных.");
  }
  $data['last-name'] = trim($_POST['last-name'] ?? '');
  $data['first-name'] = trim($_POST['first-name'] ?? '');
  $data['patronymic'] = trim($_POST['patronymic'] ?? '');
  $data['date-birth'] = trim($_POST['date-birth'] ?? '');
  $data['sex-select'] = $_POST['sex-select'] ?? '';
  $data['country-select'] = $_POST['country-select'] ?? '';
  $data['phone'] = trim($_POST['phone'] ?? '');
  $data['email'] = trim(strtolower($_POST['email'] ?? ''));
  $data['type-select'] = $_POST['type-select'] ?? '';
  $data['series-and-number'] = trim($_POST['series-and-number'] ?? '');
  $data['issued'] = trim($_POST['issued'] ?? '');
  $data['date-issue'] = trim($_POST['date-issue'] ?? '');
  $name_regex = '/^[\p{L}\s\.\-]{2,}$/u';
  if (empty($data['last-name'])) {
    $errors['last-name'] = 'Фамилия обязательна.';
  } elseif (!preg_match($name_regex, $data['last-name'])) {
    $errors['last-name'] = 'Фамилия содержит недопустимые символы.';
  }
  if (empty($data['first-name'])) {
    $errors['first-name'] = 'Имя обязательно.';
  } elseif (!preg_match($name_regex, $data['first-name'])) {
    $errors['first-name'] = 'Имя содержит недопустимые символы.';
  }
  if (!empty($data['patronymic']) && !preg_match($name_regex, $data['patronymic'])) {
    $errors['patronymic'] = 'Отчество содержит недопустимые символы.';
  }
  if (empty($data['date-birth'])) {
    $errors['date-birth'] = 'Дата рождения обязательна.';
  } elseif (strtotime($data['date-birth']) > time()) {
    $errors['date-birth'] = 'Дата рождения не может быть в будущем.';
  }
  if (!array_key_exists($data['sex-select'], $GENDERS)) {
    $errors['sex-select'] = 'Недопустимое значение пола.';
  }
  if (!array_key_exists($data['country-select'], $COUNTRIES)) {
    $errors['country-select'] = 'Выберите страну.';
  }
  if (empty($data['phone'])) {
    $errors['phone'] = 'Телефон обязателен.';
  } elseif (!preg_match('/^\+?[\d\s\-\(\)]{7,30}$/', $data['phone'])) {
    $errors['phone'] = 'Неверный формат телефона.';
  }
  if (empty($data['email'])) {
    $errors['email'] = 'Email обязателен.';
  } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Неверный формат Email.';
  }
  if (!array_key_exists($data['type-select'], $DOC_TYPES)) {
    $errors['type-select'] = 'Выберите тип документа.';
  }
  if (empty($data['series-and-number'])) {
    $errors['series-and-number'] = 'Серия и номер обязательны.';
  }
  if (empty($data['issued'])) {
    $errors['issued'] = 'Поле "Кем выдан" обязательно.';
  }
  if (empty($data['date-issue'])) {
    $errors['date-issue'] = 'Дата выдачи обязательна.';
  } elseif (strtotime($data['date-issue']) > time()) {
    $errors['date-issue'] = 'Дата выдачи не может быть в будущем.';
  }
  if (!isset($errors['date-birth']) && !isset($errors['date-issue']) && strtotime($data['date-issue']) < strtotime($data['date-birth'])) {
    $errors['date-issue'] = 'Дата выдачи не может быть раньше даты рождения.';
  }
  if (empty($errors)) {
    $check_phone_query = pg_query_params($dbconn, "SELECT COUNT(*) FROM Clients WHERE phone = $1", array($data['phone']));
    if ($check_phone_query && pg_fetch_result($check_phone_query, 0, 0) > 0) {
      $errors['phone'] = 'Клиент с таким номером телефона уже зарегистрирован.';
    }
    $check_email_query = pg_query_params($dbconn, "SELECT COUNT(*) FROM Clients WHERE email = $1", array($data['email']));
    if ($check_email_query && pg_fetch_result($check_email_query, 0, 0) > 0) {
      $errors['email'] = 'Клиент с таким Email уже зарегистрирован.';
    }
    $check_document_query = pg_query_params($dbconn, "SELECT COUNT(*) FROM Documents WHERE series_number = $1 AND document_type = $2", array($data['series-and-number'], $DOC_TYPES[$data['type-select']]));
    if ($check_document_query && pg_fetch_result($check_document_query, 0, 0) > 0) {
      $errors['series-and-number'] = 'Документ с такими серией и номером уже зарегистрирован.';
    }
  }
  if (empty($errors)) {
    pg_query($dbconn, "BEGIN");
    try {
      $full_name = $data['last-name'] . ' ' . $data['first-name'];
      if (!empty($data['patronymic'])) {
        $full_name .= ' ' . $data['patronymic'];
      }
      $country_name = $COUNTRIES[$data['country-select']];
      $client_query = pg_query_params($dbconn, "INSERT INTO Clients (full_name, phone, email, country, date_of_birth, gender) VALUES ($1, $2, $3, $4, $5, $6) RETURNING client_id", array($full_name, $data['phone'], $data['email'], $country_name, $data['date-birth'], $GENDERS[$data['sex-select']]));
      if (!$client_query) {
        throw new Exception("Ошибка при создании клиента: " . pg_last_error($dbconn));
      }
      $client_id_row = pg_fetch_assoc($client_query);
      $new_client_id = $client_id_row['client_id'];
      $doc_type_name = $DOC_TYPES[$data['type-select']];
      $document_query = pg_query_params($dbconn, "INSERT INTO Documents (client_id, document_type, series_number, issued_by, issue_date) VALUES ($1, $2, $3, $4, $5)", array($new_client_id, $doc_type_name, $data['series-and-number'], $data['issued'], $data['date-issue']));
      if (!$document_query) {
        throw new Exception("Ошибка при добавлении документа: " . pg_last_error($dbconn));
      }
      pg_query($dbconn, "COMMIT");
      $_SESSION['success_message'] = "Клиент " . htmlspecialchars($full_name) . " успешно создан.";
      header("Location: clients.php");
      exit;
    } catch (Exception $e) {
      pg_query($dbconn, "ROLLBACK");
      $errors['db_error'] = "Ошибка при сохранении данных: " . $e->getMessage();
    }
  }
  if ($dbconn) {
    pg_close($dbconn);
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/../../images/hotel.ico">
  <title>Менедждер гостиницы. Создание клиента</title>
  <link rel="stylesheet" href="../../css/normalize.css">
  <link rel="stylesheet" href="../../css/reset.css">
  <link rel="stylesheet" href="../../css/fonts.css">
  <link rel="stylesheet" href="../../css/clients/client-new.css">
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
              <h2 class="main__title">Создание клиента</h2>
              <p class="main__desc">Заполните информацию о новом клиенте</p>
            </div>
            <a href="clients.php" class="main__cancel-button">Отмена</a>
          </div>
          <?php if (isset($errors['db_error'])): ?>
            <div class="db-error-message">
              <?php echo htmlspecialchars($errors['db_error']); ?>
            </div>
          <?php endif; ?>
          <form action="client-new.php" method="POST" class="client-form">
            <section class="client-form__card">
              <h3 class="client-form__title">Персональные данные</h3>
              <hr>
              <div class="client-form__card-personal-data">
                <div class="client-form__field">
                  <label for="last-name" class="client-form__label">Фамилия</label>
                  <input type="text" name="last-name" placeholder="Фамилия..." class="client-form__input <?php echo isset($errors['last-name']) ? 'client-form__input_invalid' : ''; ?>" value="<?php echo htmlspecialchars($data['last-name'] ?? ''); ?>" required>
                  <?php if (isset($errors['last-name'])): ?>
                    <p class="client-form__error"><?php echo htmlspecialchars($errors['last-name']); ?></p>
                  <?php endif; ?>
                </div>
                <div class="client-form__field">
                  <label for="first-name" class="client-form__label">Имя</label>
                  <input type="text" name="first-name" placeholder="Имя..." class="client-form__input <?php echo isset($errors['first-name']) ? 'client-form__input_invalid' : ''; ?>" value="<?php echo htmlspecialchars($data['first-name'] ?? ''); ?>" required>
                  <?php if (isset($errors['first-name'])): ?>
                    <p class="client-form__error"><?php echo htmlspecialchars($errors['first-name']); ?></p>
                  <?php endif; ?>
                </div>
                <div class="client-form__field">
                  <label for="patronymic" class="client-form__label">Отчество</label>
                  <input type="text" name="patronymic" placeholder="Отчество..." class="client-form__input <?php echo isset($errors['patronymic']) ? 'client-form__input_invalid' : ''; ?>" value="<?php echo htmlspecialchars($data['patronymic'] ?? ''); ?>">
                  <?php if (isset($errors['patronymic'])): ?>
                    <p class="client-form__error"><?php echo htmlspecialchars($errors['patronymic']); ?></p>
                  <?php endif; ?>
                </div>
                <div class="client-form__field">
                  <label for="date-birth" class="client-form__label">Дата рождения</label>
                  <input type="date" name="date-birth" class="client-form__input <?php echo isset($errors['date-birth']) ? 'client-form__input_invalid' : ''; ?>" value="<?php echo htmlspecialchars($data['date-birth'] ?? ''); ?>" required>
                  <?php if (isset($errors['date-birth'])): ?>
                    <p class="client-form__error"><?php echo htmlspecialchars($errors['date-birth']); ?></p>
                  <?php endif; ?>
                </div>
                <div class="client-form__field">
                  <label for="sex-select" class="client-form__label">Пол</label>
                  <select name="sex-select" class="client-form__select <?php echo isset($errors['sex-select']) ? 'client-form__select_invalid' : ''; ?>" required>
                    <?php foreach ($GENDERS as $value => $label): ?>
                      <option value="<?php echo htmlspecialchars($value); ?>" <?php echo ($data['sex-select'] ?? '') === $value ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($label); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <?php if (isset($errors['sex-select'])): ?>
                    <p class="client-form__error"><?php echo htmlspecialchars($errors['sex-select']); ?></p>
                  <?php endif; ?>
                </div>
                <div class="client-form__field">
                  <label for="country-select" class="client-form__label">Страна</label>
                  <select name="country-select" class="client-form__select <?php echo isset($errors['country-select']) ? 'client-form__select_invalid' : ''; ?>" required>
                    <?php foreach ($COUNTRIES as $value => $label): ?>
                      <option value="<?php echo htmlspecialchars($value); ?>" <?php echo ($data['country-select'] ?? '') === $value ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($label); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <?php if (isset($errors['country-select'])): ?>
                    <p class="client-form__error"><?php echo htmlspecialchars($errors['country-select']); ?></p>
                  <?php endif; ?>
                </div>
              </div>
            </section>
            <section class="client-form__card">
              <h3 class="client-form__title">Контактная информация</h3>
              <hr>
              <div class="client-form__card-contact">
                <div class="client-form__field">
                  <label for="phone" class="client-form__label">Телефон</label>
                  <input type="text" name="phone" placeholder="+7 (999) 123-45-67" class="client-form__input <?php echo isset($errors['phone']) ? 'client-form__input_invalid' : ''; ?>" value="<?php echo htmlspecialchars($data['phone'] ?? ''); ?>" required>
                  <?php if (isset($errors['phone'])): ?>
                    <p class="client-form__error"><?php echo htmlspecialchars($errors['phone']); ?></p>
                  <?php endif; ?>
                </div>
                <div class="client-form__field">
                  <label for="email" class="client-form__label">Email</label>
                  <input type="email" name="email" placeholder="ivan@example.com" class="client-form__input <?php echo isset($errors['email']) ? 'client-form__input_invalid' : ''; ?>" value="<?php echo htmlspecialchars($data['email'] ?? ''); ?>" required>
                  <?php if (isset($errors['email'])): ?>
                    <p class="client-form__error"><?php echo htmlspecialchars($errors['email']); ?></p>
                  <?php endif; ?>
                </div>
              </div>
            </section>
            <section class="client-form__card">
              <h3 class="client-form__title">Документы клиента</h3>
              <hr>
              <div class="client-form__card-documents">
                <div class="client-form__field">
                  <label for="type-select" class="client-form__label">Тип документа</label>
                  <select name="type-select" class="client-form__select <?php echo isset($errors['type-select']) ? 'client-form__select_invalid' : ''; ?>" required>
                    <?php foreach ($DOC_TYPES as $value => $label): ?>
                      <option value="<?php echo htmlspecialchars($value); ?>" <?php echo ($data['type-select'] ?? '') === $value ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($label); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <?php if (isset($errors['type-select'])): ?>
                    <p class="client-form__error"><?php echo htmlspecialchars($errors['type-select']); ?></p>
                  <?php endif; ?>
                </div>
                <div class="client-form__field">
                  <label for="series-and-number" class="client-form__label">Серия и номер</label>
                  <input type="text" name="series-and-number" placeholder="1234 567890" class="client-form__input <?php echo isset($errors['series-and-number']) ? 'client-form__input_invalid' : ''; ?>" value="<?php echo htmlspecialchars($data['series-and-number'] ?? ''); ?>" required>
                  <?php if (isset($errors['series-and-number'])): ?>
                    <p class="client-form__error"><?php echo htmlspecialchars($errors['series-and-number']); ?></p>
                  <?php endif; ?>
                </div>
                <div class="client-form__field">
                  <label for="issued" class="client-form__label">Кем выдан</label>
                  <input type="text" name="issued" placeholder="МВД России, Москва" class="client-form__input <?php echo isset($errors['issued']) ? 'client-form__input_invalid' : ''; ?>" value="<?php echo htmlspecialchars($data['issued'] ?? ''); ?>" required>
                  <?php if (isset($errors['issued'])): ?>
                    <p class="client-form__error"><?php echo htmlspecialchars($errors['issued']); ?></p>
                  <?php endif; ?>
                </div>
                <div class="client-form__field">
                  <label for="date-issue" class="client-form__label">Дата выдачи</label>
                  <input type="date" name="date-issue" class="client-form__input <?php echo isset($errors['date-issue']) ? 'client-form__input_invalid' : ''; ?>" value="<?php echo htmlspecialchars($data['date-issue'] ?? ''); ?>" required>
                  <?php if (isset($errors['date-issue'])): ?>
                    <p class="client-form__error"><?php echo htmlspecialchars($errors['date-issue']); ?></p>
                  <?php endif; ?>
                </div>
              </div>
            </section>
            <div class="client-form__buttons">
              <a href="clients.php" class="client-form__cancel-button">Отмена</a>
              <button type="submit" class="client-form__create-button">Создать клиента</button>
            </div>
          </form>
        </div>
      </main>
    </div>
  </div>
</body>
</html>