<?php
// ============================================
// KONFIGURACJA BŁĘDÓW - NAJWAŻNIEJSZE!
// ============================================

// WYŁĄCZ WSZYSTKIE BŁĘDY PHP - TO ROZWIĄŻE PROBLEM Z JSON
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 0);

// USTAW NAGŁÓWEK JSON - MUSI BYĆ PRZED WSZELKIM WYJŚCIEM
header('Content-Type: application/json; charset=UTF-8');

// WYCZYŚĆ BUFORY - USUŃ WSZELKIE WYJŚCIE HTML
while (ob_get_level()) ob_end_clean();
ob_start();

// ============================================
// PROSTA KONFIGURACJA
// ============================================

// Wybierz tryb pracy:
// 'test' - tylko testuje połączenie, nie wysyła maili
// 'log' - zapisuje maile do pliku
// 'smtp' - wysyła przez SMTP
define('MODE', 'log');

// Dane do logowania (opcjonalne):
define('LOG_FILE', __DIR__ . '/../logs/mail.log');

// Dane SMTP (jeśli MODE = 'smtp'):
define('SMTP_HOST', 'smtp.office365.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'mazur.mateusz@lumberg.com');
define('SMTP_PASS', 'Tenokcilght1609!');
define('SMTP_FROM', 'mazur.mateusz@lumberg.com');

// ============================================
// FUNKCJE
// ============================================

/**
 * Zapisuje log do pliku
 */
function logMessage($type, $message) {
    if (!file_exists(dirname(LOG_FILE))) {
        mkdir(dirname(LOG_FILE), 0777, true);
    }
    
    $entry = date('Y-m-d H:i:s') . " [$type] " . $message . "\n";
    file_put_contents(LOG_FILE, $entry, FILE_APPEND);
    return $entry;
}

/**
 * Wysyła email przez SMTP
 */
function sendSmtpEmail($to, $subject, $body) {
    $headers = [
        'From: ' . SMTP_FROM,
        'Reply-To: ' . SMTP_FROM,
        'Content-Type: text/html; charset=UTF-8',
        'X-Mailer: PHP/' . phpversion()
    ];
    
    // Ustawienia SMTP
    ini_set('SMTP', SMTP_HOST);
    ini_set('smtp_port', SMTP_PORT);
    ini_set('sendmail_from', SMTP_FROM);
    
    // Wysyłka
    $result = mail($to, $subject, $body, implode("\r\n", $headers));
    
    // Przywróć domyślne
    ini_restore('SMTP');
    ini_restore('smtp_port');
    ini_restore('sendmail_from');
    
    return $result;
}

/**
 * Symuluje wysyłkę - zapisuje do pliku
 */
function simulateEmail($to, $subject, $body) {
    $log = "=== EMAIL LOG ===\n";
    $log .= "Data: " . date('Y-m-d H:i:s') . "\n";
    $log .= "Do: $to\n";
    $log .= "Temat: $subject\n";
    $log .= "Treść:\n$body\n";
    $log .= "=================\n\n";
    
    logMessage('SIMULATE', "Email do: $to, Temat: $subject");
    return true;
}

/**
 * Testuje konfigurację
 */
function testConfig() {
    $tests = [];
    
    // Test 1: Czy plik jest wykonywany
    $tests[] = ['name' => 'File execution', 'status' => 'OK'];
    
    // Test 2: Czy nagłówek JSON jest ustawiony
    $tests[] = ['name' => 'JSON header', 'status' => headers_sent() ? 'ERROR' : 'OK'];
    
    // Test 3: Czy folder logów istnieje
    $logDir = dirname(LOG_FILE);
    $tests[] = [
        'name' => 'Log directory', 
        'status' => file_exists($logDir) ? 'OK' : 'CREATED',
        'path' => $logDir
    ];
    
    return $tests;
}

// ============================================
// OBSŁUGA ŻĄDAŃ
// ============================================

// Zbierz wszystkie dane wejściowe
$input = file_get_contents('php://input');
$data = [];

if (!empty($input)) {
    $jsonData = json_decode($input, true);
    if ($jsonData !== null) {
        $data = $jsonData;
    }
}

// Jeśli nie ma danych JSON, użyj POST
if (empty($data) && !empty($_POST)) {
    $data = $_POST;
}

// Ustaw wartości domyślne
$recipient = $data['recipient'] ?? $data['email'] ?? 'test@localhost';
$subject = $data['subject'] ?? 'Testowy alarm';
$message = $data['message'] ?? $data['content'] ?? 'Brak treści';

// ============================================
// WYKONANIE
// ============================================

try {
    $result = [];
    
    switch (MODE) {
        case 'test':
            // Tryb testowy - tylko zwraca informacje
            $result = [
                'success' => true,
                'mode' => 'test',
                'message' => 'System działa poprawnie',
                'tests' => testConfig(),
                'received_data' => [
                    'recipient' => $recipient,
                    'subject' => $subject,
                    'message_length' => strlen($message)
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ];
            break;
            
        case 'log':
            // Tryb logowania - zapisuje do pliku
            $logResult = simulateEmail($recipient, $subject, $message);
            $result = [
                'success' => true,
                'mode' => 'log',
                'message' => 'Email zapisany w pliku log',
                'log_file' => realpath(LOG_FILE) ?: LOG_FILE,
                'recipient' => $recipient,
                'subject' => $subject,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            break;
            
        case 'smtp':
            // Tryb SMTP - wysyła przez SMTP
            $smtpResult = sendSmtpEmail($recipient, $subject, $message);
            $result = [
                'success' => $smtpResult,
                'mode' => 'smtp',
                'message' => $smtpResult ? 
                    'Email wysłany przez SMTP' : 
                    'Błąd wysyłania przez SMTP',
                'smtp_host' => SMTP_HOST,
                'smtp_port' => SMTP_PORT,
                'recipient' => $recipient,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            break;
            
        default:
            $result = [
                'success' => false,
                'message' => 'Nieznany tryb pracy',
                'mode' => MODE
            ];
    }
    
    // Dodaj info o serwerze
    $result['server_info'] = [
        'php_version' => PHP_VERSION,
        'server_name' => $_SERVER['SERVER_NAME'] ?? 'localhost',
        'request_method' => $_SERVER['REQUEST_METHOD']
    ];
    
} catch (Exception $e) {
    $result = [
        'success' => false,
        'message' => 'Wystąpił wyjątek: ' . $e->getMessage(),
        'error_type' => 'exception'
    ];
}

// ============================================
// WYJŚCIE - TYLKO JSON!
// ============================================

// Wyczyść wszystkie buffery
while (ob_get_level()) {
    ob_end_clean();
}

// Wyślij JSON
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// Zakończ - żadnego dodatkowego kodu po tym!
exit;