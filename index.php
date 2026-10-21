<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

error_log("index.php: REQUEST_URI = " . $_SERVER['REQUEST_URI']);
error_log("index.php: GET = " . print_r($_GET, true));
// index.php - punkt wejścia aplikacji
ob_start(); // Rozpocznij buforowanie na samym początku
session_start();

// Include config i auth
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Wymaga zalogowania
requireLogin();

// Obsługa akcji PRZED wyświetlaniem contentu
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    switch ($action) {
        case 'clear_messages':
            $_SESSION['messages'] = [];
            header("Location: index.php?page=dashboard");
            exit;
            
        case 'dismiss_message':
            $index = filter_input(INPUT_GET, 'index', FILTER_VALIDATE_INT);
            if ($index !== false && isset($_SESSION['messages'][$index])) {
                unset($_SESSION['messages'][$index]);
                $_SESSION['messages'] = array_values($_SESSION['messages']);
            }
            header("Location: index.php?page=dashboard");
            exit;
            
        case 'scan':
            requirePermission('devices', 'create');
            include __DIR__ . '/scan_printers.php';
            exit;
            
        case 'discover':
            requirePermission('devices', 'create');
            include __DIR__ . '/discover_printers.php';
            exit;
            
        case 'check':
            requirePermission('devices', 'view');
            include __DIR__ . '/check_printers.php';
            $count = checkAllPrinters();
            
            // Jeśli żądanie AJAX
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'count' => $count, 'message' => "Sprawdzono $count drukarek"]);
                exit;
            }
            
            $_SESSION['messages'][] = ['type' => 'success', 'text' => "Sprawdzono $count drukarek"];
            header('Location: index.php?page=devices');
            exit;
            
        case 'delete':
            // Obsługiwane w odpowiednich modułach
            break;
    }
}

// Include header i sidebar
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

// Routing do content – usunięto FILTER_SANITIZE_STRING
$page = $_GET['page'] ?? 'dashboard';
$allowed_pages = [
    'dashboard',
    'devices',
    'materials',
    'map',
    'map_config',
    'users',
    'config_content',
    'account',
    'reports',
	 'ups',
    'dect_phones',
    'terminals',
    'peripherals',
    'printers_outsourcing'
];

if (!in_array($page, $allowed_pages)) {
    $page = 'dashboard';
}

// Sprawdź uprawnienia do modułu
$module_map = [
    'dashboard' => 'dashboard',
    'devices' => 'devices',
    'materials' => 'materials',
    'config_content' => 'config',
    'users' => 'users',
    'account' => 'dashboard',
    'map' => 'map',
    'map_config' => 'config',
    'reports' => 'reports',
	'ups' => 'ups',
    'dect_phones' => 'dect_phones',
    'terminals' => 'terminals',
    'peripherals' => 'peripherals',
    'printers_outsourcing' => 'printers_outsourcing'
];

$module = $module_map[$page] ?? 'dashboard';

if ($page !== 'account' && !hasPermission($module, 'view')) {
    require_once __DIR__ . '/no_permission.php';
    exit;
}

// Wyświetl main content z odpowiednią klasą
$main_class = 'main-content';
if ($page === 'devices') {
    $main_class .= ' devices-page';
}
if ($page === 'users') {
    $main_class .= ' users-page';
}
if ($page === 'dashboard') {
    $main_class .= ' dashboard-page';
}
if ($page === 'reports') {
    $main_class .= ' reports-page';
}
echo '<main class="' . $main_class . '" id="mainContent">';
echo '<div class="page-content">';

$content_file = __DIR__ . "/content/$page.php";
if (file_exists($content_file)) {
    include $content_file;
} else {
    echo '<div class="dashboard-header">';
    echo '<h1>Błąd 404</h1>';
    echo '<p>Strona nie została znaleziona.</p>';
    echo '</div>';
}

echo '</div>';
echo '</main>';

// Include footer
require_once __DIR__ . '/includes/footer.php';

ob_end_flush();
?>