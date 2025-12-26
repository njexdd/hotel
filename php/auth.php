<?php
session_start();

if (isset($_SESSION['user']) && !empty($_SESSION['user'])) {
  header("Location: ../pages/dashboard/dashboard.html");
  exit;
}

$validEmail = "manager@hotel.com";
$validPassword = "123456";

$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

if ($email === $validEmail && $password === $validPassword) {
  $_SESSION['user'] = [
    'email' => $email
  ];

  header("Location: ../pages/dashboard/dashboard.php");
  exit;
} else {
  header("Location: ../pages/login/login.php");
  exit;
}