<?php
session_start();
if (isset($_SESSION['user']) && !empty($_SESSION['user'])) {
  header("Location: ../../pages/dashboard/dashboard.php");
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/../../images/hotel.ico">
  <title>Менеджер гостиницы. Авторизация</title>
  <link rel="stylesheet" href="../../css/normalize.css">
  <link rel="stylesheet" href="../../css/reset.css">
  <link rel="stylesheet" href="../../css/fonts.css">
  <link rel="stylesheet" href="../../css/login/login.css">
</head>
<body>
  <main class="auth">
    <div class="auth__content">
      <div class="logo">
        <img src="../../images/logo/logo.png" alt="Логотип" class="auth__img">
      </div>
      <div class="auth__text">
        <h1 class="auth__title">HotelHub</h1>
        <p class="auth__desc">Автоматизированное рабочее место менеджера гостиницы</p>
      </div>
      <form action="../../php/auth.php" method="POST" id="loginForm" class="auth__form">
        <div class="auth__email">
          <label for="email" class="auth__label">Email</label>
          <input type="email" name="email" id="email" placeholder="manager@hotel.com" class="auth__input">
        </div>
        <div class="auth__password">
          <label for="password" class="auth__label">Пароль</label>
          <input type="password" name="password" id="password" placeholder="••••••••" class="auth__input">
        </div>
        <button type="submit" disabled class="auth__login" id="loginButton">Войти</button>
      </form>
    </div>
  </main>
  <script src="../../js/login.js"></script>
</body>
</html>