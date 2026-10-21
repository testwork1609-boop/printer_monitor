<?php
// logout.php - Wylogowanie użytkownika
session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

logout();

header('Location: login.php?logout=1');
exit;
?>