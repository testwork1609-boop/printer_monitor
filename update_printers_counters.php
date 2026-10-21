<?php
// update_printers_counters.php - Aktualizacja liczników wydruków z rzeczywistych danych SNMP
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Wymaga zalogowania i uprawnień
requireLogin();
if (!hasPermission('devices', 'view')) {
    die('Brak uprawnień');
}

// Funkcja do pobierania licznika stron z drukarki przez SNMP
function getPrinterPageCounter($ip, $community = 'public') {
    // Lista OID dla różnych producentów
    $oids = [
        '1.3.6.1.2.1.43.10.2.1.4.1.1', // Standardowy OID dla licznika wydruków (Total prints)
        '1.3.6.1.4.1.11.2.3.9.4.2.1.4.1.2.7.0', // HP LaserJet
        '1.3.6.1.4.1.1347.43.10.1.1.12.1.1', // Xerox
        '1.3.6.1.4.1.253.8.51.10.2.1.1.4.1', // Brother
        '1.3.6.1.4.1.641.6.2.3.1.1.0', // Lexmark
        '1.3.6.1.4.1.1248.1.2.2.1.1.1.6.1', // Canon
        '1.3.6.1.4.1.236.11.5.1.1.3.0', // Ricoh
        '1.3.6.1.4.1.367.3.2.1.2.19.1.0', // Kyocera
        '1.3.6.1.4.1.1602.1.11.1.3.1.4.143', // Konica Minolta
        '1.3.6.1.4.1.18334.1.1.1.5.7.2.0', // Oki
        '1.3.6.1.4.1.45.1.6.1.1.10.0', // Tally
        '1.3.6.1.2.1.43.10.2.1.4.1.1.1', // Inny standardowy OID (często używany)
    ];
    
    foreach ($oids as $oid) {
        try {
            $result = @snmp2_get($ip, $community, $oid, 1000000, 3);
            if ($result !== false) {
                $result = trim($result, '" ');
                // Czasem wartość może być w formie "INTEGER: 12345", więc usuwamy tekst
                if (preg_match('/\d+/', $result, $matches)) {
                    $result = $matches[0];
                }
                if (is_numeric($result)) {
                    return (int)$result;
                }
            }
        } catch (Exception $e) {
            // Kontynuuj do następnego OID
            continue;
        }
    }
    
    // Jeśli żaden OID nie zadziałał, spróbuj przez snmpwalk (może drukarka zwraca listę)
    try {
        $result = @snmp2_walk($ip, $community, '1.3.6.1.2.1.43.10.2.1.4', 1000000, 3);
        if (is_array($result) && !empty($result)) {
            foreach ($result as $value) {
                $value = trim($value, '" ');
                if (is_numeric($value)) {
                    return (int)$value;
                }
            }
        }
    } catch (Exception $e) {
        // Ignoruj błąd
    }
    
    return null;
}

// Pobierz wszystkie aktywne drukarki
global $db;
$stmt = $db->query("SELECT p.id, p.name, p.ip, p.printer_type, p.total_counter, p.black_counter, p.color_counter 
                    FROM printers p 
                    WHERE p.is_active = 1 
                    ORDER BY p.name");
$printers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$updated_count = 0;
$error_count = 0;
$results = [];

foreach ($printers as $printer) {
    $printer_id = $printer['id'];
    $ip = $printer['ip'];
    $current_total = $printer['total_counter'] ?? 0;
    
    try {
        // Pobierz aktualny licznik
        $new_counter = getPrinterPageCounter($ip);
        
        if ($new_counter !== null) {
            // Oblicz różnicę od ostatniego pomiaru
            $difference = $new_counter - $current_total;
            
            // Jeśli różnica jest dodatnia, aktualizuj
            if ($difference > 0) {
                // Dla drukarek kolorowych załóżmy 30% wydruków kolorowych
                // Dla mono załóżmy 10% wydruków kolorowych (jeśli to drukarka mono z opcją koloru)
                if ($printer['printer_type'] === 'color') {
                    $color_increment = floor($difference * 0.3);
                    $black_increment = $difference - $color_increment;
                } else {
                    $color_increment = floor($difference * 0.1);
                    $black_increment = $difference - $color_increment;
                }
                
                // Aktualizuj liczniki
                $new_total = $current_total + $difference;
                $new_black = ($printer['black_counter'] ?? 0) + $black_increment;
                $new_color = ($printer['color_counter'] ?? 0) + $color_increment;
                
                $stmt = $db->prepare("UPDATE printers SET 
                    total_counter = ?, 
                    black_counter = ?, 
                    color_counter = ?,
                    last_counter_update = datetime('now')
                    WHERE id = ?");
                $stmt->execute([$new_total, $new_black, $new_color, $printer_id]);
                
                // Aktualizuj również w printer_status
                $stmt = $db->prepare("UPDATE printer_status SET 
                    page_counter = ?
                    WHERE printer_id = ?");
                $stmt->execute([$new_total, $printer_id]);
                
                $results[] = [
                    'printer' => $printer['name'],
                    'status' => 'success',
                    'message' => "Zaktualizowano: +{$difference} wydruków (Czarno-białe: +{$black_increment}, Kolor: +{$color_increment})",
                    'new_total' => $new_total
                ];
                $updated_count++;
            } else {
                $results[] = [
                    'printer' => $printer['name'],
                    'status' => 'info',
                    'message' => "Brak zmian w liczniku: {$current_total} → {$new_counter}",
                    'new_total' => $new_counter
                ];
            }
        } else {
            $results[] = [
                'printer' => $printer['name'],
                'status' => 'warning',
                'message' => "Nie udało się odczytać licznika z drukarki"
            ];
            $error_count++;
        }
    } catch (Exception $e) {
        $results[] = [
            'printer' => $printer['name'],
            'status' => 'error',
            'message' => "Błąd: " . $e->getMessage()
        ];
        $error_count++;
    }
    
    // Małe opóźnienie żeby nie przeciążyć sieci
    usleep(100000); // 100ms
}

// Zaloguj aktywność
logActivity($_SESSION['user_id'], 'update_counters', 'devices', 
    "Zaktualizowano liczniki {$updated_count} drukarek, błędów: {$error_count}");

// Przekieruj z powrotem z informacjami
$_SESSION['messages'] = [
    [
        'type' => 'success',
        'text' => "Zaktualizowano liczniki {$updated_count} drukarek. Błędów: {$error_count}"
    ]
];

// Zapisz szczegółowe wyniki w sesji do wyświetlenia
$_SESSION['counter_update_results'] = $results;

header('Location: index.php?page=dashboard');
exit;
?>