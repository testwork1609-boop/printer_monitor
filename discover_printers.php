<?php
include 'config.php';

// Funkcja: Skanuje sieć w poszukiwaniu drukarek
// Służy do automatycznego wykrywania urządzeń
// Przyjmuje: Brak argumentów
// Zwraca: Brak (dodaje do bazy)
function discoverPrinters() {
    global $subnet, $start_ip, $end_ip, $snmp_community, $db;
    
    for ($i = $start_ip; $i <= $end_ip; $i++) {
        $ip = $subnet . $i;
        
        // Sprawdź SNMP
        $sysDescr = @snmp2_get($ip, $snmp_community, '1.3.6.1.2.1.1.1.0', 500000, 1);
        
        if ($sysDescr !== false) {
            $sysDescr = strtolower($sysDescr);
            $is_printer = strpos($sysDescr, 'printer') !== false || strpos($sysDescr, 'laser') !== false;
            
            if ($is_printer) {
                // Sprawdź czy istnieje w bazie
                $stmt_check = $db->prepare("SELECT COUNT(*) FROM printers WHERE ip = :ip");
                $stmt_check->execute([':ip' => $ip]);
                if ($stmt_check->fetchColumn() == 0) {
                    $name = "Drukarka_$i";
                    $stmt_insert = $db->prepare("INSERT INTO printers (name, ip, community) VALUES (:name, :ip, :community)");
                    $stmt_insert->execute([':name' => $name, ':ip' => $ip, ':community' => $snmp_community]);
                }
            }
        }
        
        // Sprawdź port 9100 jako fallback
        $fp = @fsockopen($ip, 9100, $errno, $errstr, 0.5);
        if ($fp) {
            fclose($fp);
            $stmt_check = $db->prepare("SELECT COUNT(*) FROM printers WHERE ip = :ip");
            $stmt_check->execute([':ip' => $ip]);
            if ($stmt_check->fetchColumn() == 0) {
                $name = "Drukarka_Port9100_$i";
                $stmt_insert = $db->prepare("INSERT INTO printers (name, ip, community) VALUES (:name, :ip, :community)");
                $stmt_insert->execute([':name' => $name, ':ip' => $ip, ':community' => $snmp_community]);
            }
        }
    }
}

if (isset($_GET['action']) && $_GET['action'] == 'discover') {
    discoverPrinters();
    header("Location: index.php?page=dashboard");
    exit;
}
?>