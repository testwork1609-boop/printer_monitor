<?php
include 'config.php';

// Funkcja: Skanuje podany zakres IP
// Służy do wyszukiwania drukarek po portach i SNMP
// Przyjmuje: Brak argumentów (używa konfiguracji)
// Zwraca: Brak (dodaje do bazy)
function scanPrinters() {
    global $subnet, $start_ip, $end_ip, $snmp_community, $db;
    
    for ($i = $start_ip; $i <= $end_ip; $i++) {
        $ip = $subnet . $i;
        
        // Sprawdź SNMP
        $snmp_response = @snmp2_get($ip, $snmp_community, '1.3.6.1.2.1.1.1.0', 300000, 1);
        
        if ($snmp_response !== false && (stripos($snmp_response, 'printer') !== false || stripos($snmp_response, 'laser') !== false)) {
            $stmt_check = $db->prepare("SELECT COUNT(*) FROM printers WHERE ip = :ip");
            $stmt_check->execute([':ip' => $ip]);
            if ($stmt_check->fetchColumn() == 0) {
                $name = "Drukarka_$i";
                $stmt_insert = $db->prepare("INSERT INTO printers (name, ip, community) VALUES (:name, :ip, :community)");
                $stmt_insert->execute([':name' => $name, ':ip' => $ip, ':community' => $snmp_community]);
            }
        } else {
            // Sprawdź port 9100
            $fp = @fsockopen($ip, 9100, $errno, $errstr, 0.3);
            if ($fp) {
                fclose($fp);
                $stmt_check = $db->prepare("SELECT COUNT(*) FROM printers WHERE ip = :ip");
                $stmt_check->execute([':ip' => $ip]);
                if ($stmt_check->fetchColumn() == 0) {
                    $name = "Drukarka_$i";
                    $stmt_insert = $db->prepare("INSERT INTO printers (name, ip, community) VALUES (:name, :ip, :community)");
                    $stmt_insert->execute([':name' => $name, ':ip' => $ip, ':community' => $snmp_community]);
                }
            }
        }
    }
}

if (isset($_GET['action']) && $_GET['action'] == 'scan') {
    scanPrinters();
    header("Location: index.php?page=dashboard");
    exit;
}
?>