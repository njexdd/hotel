<?php

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '5432');
define('DB_NAME', getenv('DB_NAME') ?: 'hotel');
define('DB_USER', getenv('DB_USER') ?: 'postgres');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: 'Ilya12345');

function db_connect() {
  $connection_string = "host=" . DB_HOST . 
    " port=" . DB_PORT . 
    " dbname=" . DB_NAME . 
    " user=" . DB_USER . 
    " password=" . DB_PASSWORD;
  
  $dbconn = pg_connect($connection_string);
  
  if (!$dbconn) {
    error_log("Database connection failed: " . pg_last_error());
    return false;
  }
  
  return $dbconn;
}
?>