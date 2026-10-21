<?php
// Test SNMP dla konkretnej drukarki
include 'config.php';

$ip = filter_input(INPUT_GET, 'ip', FILTER_VALIDATE_IP) ?? '192.168.152.216';
$community = filter_input(INPUT_GET, 'community') ?? $snmp_community;

echo "<h2>Test SNMP dla " . htmlspecialchars($ip) . "</h2>";
echo "<pre>";

// Sprawdź czy SNMP jest dostępne
if (!function_exists('snmp2_get')) {
    echo "SNMP nie jest włączone w PHP!\n";
    echo "Aby włączyć SNMP w XAMPP:\n";
    echo "1. Otwórz php.ini\n";
    echo "2. Znajdź: ;extension=snmp\n";
    echo "3. Usuń średnik: extension=snmp\n";
    echo "4. Zrestartuj Apache\n";
    exit;
}

// Test podstawowych OID
$oids = [
    'sysDescr' => '1.3.6.1.2.1.1.1.0',
    'sysName' => '1.3.6.1.2.1.1.5.0',
    'sysLocation' => '1.3.6.1.2.1.1.6.0',
    'Black Toner' => '1.3.6.1.2.1.43.11.1.1.9.1.1',
    'Cyan Toner' => '1.3.6.1.2.1.43.11.1.1.9.1.2',
    'Magenta Toner' => '1.3.6.1.2.1.43.11.1.1.9.1.3',
    'Yellow Toner' => '1.3.6.1.2.1.43.11.1.1.9.1.4',
];

foreach ($oids as $name => $oid) {
    try {
        $value = @snmp2_get($ip, $community, $oid, 1000000, 3);
        if ($value !== false) {
            echo "$name ($oid): " . htmlspecialchars($value) . "\n";
        } else {
            echo "$name: BRAK ODPOWIEDZI\n";
        }
    } catch (Exception $e) {
        echo "$name: BŁĄD - " . htmlspecialchars($e->getMessage()) . "\n";
    }
}

echo "</pre>";
?>