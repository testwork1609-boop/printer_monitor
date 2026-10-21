<?php
session_start();
$index = filter_input(INPUT_GET, 'index', FILTER_VALIDATE_INT);
if ($index !== false && isset($_SESSION['messages'][$index])) {
    unset($_SESSION['messages'][$index]);
    $_SESSION['messages'] = array_values($_SESSION['messages']);
}
header("Location: index.php?page=dashboard");
exit;
?>