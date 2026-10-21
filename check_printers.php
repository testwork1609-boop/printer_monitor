<?php
error_log("check_printers.php załadowany " . date('Y-m-d H:i:s'));
// check_printers.php - Sprawdzanie statusu drukarek via SNMP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include config tylko jeśli nie został jeszcze dołączony
if (!isset($db)) {
    include_once __DIR__ . '/config.php';
}

// Włącz logowanie błędów do pliku w katalogu głównym
ini_set('log_errors', 1);

error_reporting(E_ALL);

/**
 * Pobiera dane materiałów z drukarki via SNMP
 * @param string $ip Adres IP drukarki
 * @return array Tablica z poziomami materiałów lub pusta tablica w przypadku błędu
 */
function getPrinterData($ip) {
	error_log("getPrinterData(IP: $ip) - start");
    global $snmp_community;
    error_log("Próba SNMP dla IP: $ip");
    if (!function_exists('snmpwalk')) {
        error_log("SNMP nie jest włączone w PHP dla IP: $ip");
		error_log("getPrinterData(IP: $ip) - brak danych SNMP");
		return [];
    }
    
    $oids = [
        'descr' => '1.3.6.1.2.1.43.11.1.1.6', // Nazwy materiałów
        'level' => '1.3.6.1.2.1.43.11.1.1.9', // Bieżący poziom
        'max'   => '1.3.6.1.2.1.43.11.1.1.8', // Pojemność max
    ];

    try {
        // Próba pobrania nazw materiałów
        $names = @snmpwalk($ip, $snmp_community, $oids['descr'], 500000, 1);
        if (!$names || !is_array($names)) {
            error_log("Brak odpowiedzi SNMP dla IP: $ip");
			error_log("getPrinterData(IP: $ip) - brak danych");
            return [];
        }
        
        $levels = @snmpwalk($ip, $snmp_community, $oids['level'], 500000, 1);
        $maxes  = @snmpwalk($ip, $snmp_community, $oids['max'], 500000, 1);
        
        if (!is_array($levels)) $levels = [];
        if (!is_array($maxes)) $maxes = [];
        
        $data = [];
        foreach ($names as $i => $raw_name) {
            $name = strtolower(trim(preg_replace('/^[^:]+:\s*/', '', $raw_name)));
            
            $lvl = 0;
            $mx = 100;
            
            if (isset($levels[$i])) {
                $lvl = intval(trim(preg_replace('/^[^:]+:\s*/', '', $levels[$i])));
            }
            
            if (isset($maxes[$i])) {
                $mx = intval(trim(preg_replace('/^[^:]+:\s*/', '', $maxes[$i])));
            }
            
            // Oblicz procent; obsługa wartości specjalnych
            $percent = 0;
            if ($lvl == -2) {
                $percent = 100;
            } elseif ($lvl == -3 || $lvl < -3) {
                $percent = 'N/D';
            } elseif ($mx > 0 && $lvl >= 0) {
                $percent = round(($lvl / $mx) * 100);
            } else {
                $percent = 'N/D';
            }
            
            // Mapowanie na kategorie
            $category = 'other';
            
            if (strpos($name, 'black') !== false && (strpos($name, 'toner') !== false || strpos($name, 'cartridge') !== false)) {
                $category = 'black';
            } elseif (strpos($name, 'cyan') !== false && (strpos($name, 'toner') !== false || strpos($name, 'cartridge') !== false)) {
                $category = 'cyan';
            } elseif (strpos($name, 'magenta') !== false && (strpos($name, 'toner') !== false || strpos($name, 'cartridge') !== false)) {
                $category = 'magenta';
            } elseif (strpos($name, 'yellow') !== false && (strpos($name, 'toner') !== false || strpos($name, 'cartridge') !== false)) {
                $category = 'yellow';
            } elseif (strpos($name, 'imaging unit') !== false || strpos($name, 'drum') !== false) {
                if (strpos($name, 'black') !== false) {
                    $category = 'drum_black';
                } else {
                    $category = 'drum_color';
                }
            } elseif (strpos($name, 'waste') !== false) {
                $category = 'waste';
            } elseif (strpos($name, 'fuser') !== false) {
                $category = 'fuser';
            } elseif (strpos($name, 'transfer') !== false) {
                $category = 'transfer';
            } elseif (strpos($name, 'maintenance') !== false) {
                $category = 'maintenance';
            } elseif (strpos($name, 'roller') !== false) {
                $category = 'roller';
            }

            if ($category !== 'other') {
                $data[$category] = $percent;
            }
        }
        
        return $data;
        
    } catch (Exception $e) {
        error_log("Błąd SNMP dla IP $ip: " . $e->getMessage());
        return [];
    }
}

/**
 * Aktualizuje status wszystkich aktywnych drukarek w bazie
 * @return int Liczba zaktualizowanych drukarek (lub 0 w przypadku błędu)
 */
function checkAllPrinters() {
	error_log("checkAllPrinters rozpoczęta");
    global $db;
    
    try {
        // Ustawienie trybu WAL – zmniejsza blokady
        //$db->exec('PRAGMA journal_mode=WAL');
        
        // Pobierz listę aktywnych drukarek
        $stmt = $db->query("SELECT id, ip FROM printers WHERE is_active = 1");
        $printers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $updated_count = 0;
        
        foreach ($printers as $printer) {
            try {
				error_log("Sprawdzam drukarkę ID: {$printer['id']}, IP: {$printer['ip']}");
$data = getPrinterData($printer['ip']);
error_log("Dane dla IP {$printer['ip']}: " . print_r($data, true));
                $status = !empty($data) ? 'online' : 'offline';
                $materials_json = json_encode($data);
                $last_check = date('Y-m-d H:i:s');
                
                // Sprawdź czy istnieje wpis w printer_status
                $stmt_check = $db->prepare("SELECT printer_id FROM printer_status WHERE printer_id = ?");
                $stmt_check->execute([$printer['id']]);
                
                if ($stmt_check->fetchColumn()) {
                    // Aktualizacja istniejącego wpisu
                    $stmt_update = $db->prepare("UPDATE printer_status SET status = ?, materials_json = ?, last_check = NOW() WHERE printer_id = ?");
                    $stmt_update->execute([$status, $materials_json, $last_check, $printer['id']]);
                } else {
                    // Nowy wpis
                  $stmt_insert = $db->prepare("INSERT INTO printer_status (printer_id, status, materials_json, last_check) VALUES (?, ?, ?, NOW())");
                    $stmt_insert->execute([$printer['id'], $status, $materials_json, $last_check]);
                }
                
                $updated_count++;
                
            } catch (Exception $e) {
                error_log("Błąd podczas przetwarzania drukarki ID {$printer['id']} (IP: {$printer['ip']}): " . $e->getMessage());
                // Kontynuuj z następną drukarką
            }
        }
        error_log("checkAllPrinters zakończona, przetworzono: $updated_count");
        return $updated_count;
        
    } catch (PDOException $e) {
        error_log("Błąd PDO w checkAllPrinters: " . $e->getMessage());
        return 0;
    } catch (Exception $e) {
        error_log("Błąd ogólny w checkAllPrinters: " . $e->getMessage());
        return 0;
    } finally {
        // Opcjonalnie – zamknij połączenie, aby zwolnić blokadę
        // $db = null;
    }
}

?>