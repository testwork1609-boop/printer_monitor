<?php
// config.php - Konfiguracja systemu monitorowania drukarek (wersja MySQL)

// Konfiguracja email
$email_from = "monitor@twojafirma.pl";
$email_supplier = "dostawca@twojafirma.pl";
$email_subject = "Uwaga: niski poziom tonera"; // jeden, usunąłem duplikat
$toner_threshold = 15;
$snmp_community = "public";
$subnet = "192.168.152.";
$start_ip = 200;
$end_ip = 230;

// Połączenie z bazą MySQL – ZASTĄP DANYMI Z HOSTINGU
$db_host = 'localhost';        // najczęściej localhost
$db_name = 'host171356_system';       // nazwa bazy danych
$db_user = 'host171356_system';       // użytkownik bazy
$db_pass = 'w8NtJvNJjkjLpaz5yDCU';            // hasło

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("<h2>Błąd połączenia z bazą danych</h2><p>" . $e->getMessage() . "</p><p>Sprawdź dane dostępowe w pliku config.php</p>");
}

// Ustawienie strefy czasowej (opcjonalnie)
$db->exec("SET time_zone = '+01:00'");

// ------------------------------------------------------------
// Poniższe funkcje są wykonywane tylko gdy baza jest pusta
// (tworzenie domyślnego admina i uprawnień)
// ------------------------------------------------------------

// Domyślny użytkownik admin (jeśli nie istnieje)
$stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin'");
$stmt->execute();
if ($stmt->fetchColumn() == 0) {
    $admin_hash = password_hash('admin123', PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO users (username, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute(['admin', $admin_hash, 'Administrator', 'admin', 1]);
}

// Domyślne uprawnienia – jeśli tabela permissions jest pusta
$stmt = $db->query("SELECT COUNT(*) FROM permissions");
if ($stmt->fetchColumn() == 0) {
    $permissions_init = [
        ['admin', 'dashboard', 1, 1, 1, 1],
        ['admin', 'devices', 1, 1, 1, 1],
        ['admin', 'materials', 1, 1, 1, 1],
        ['admin', 'config', 1, 1, 1, 1],
        ['admin', 'users', 1, 1, 1, 1],
        ['admin', 'reports', 1, 1, 1, 1], // dodane
        ['superuser', 'dashboard', 1, 0, 0, 0],
        ['superuser', 'devices', 1, 1, 1, 0],
        ['superuser', 'materials', 1, 1, 1, 0],
        ['superuser', 'config', 0, 0, 0, 0],
        ['superuser', 'users', 0, 0, 0, 0],
        ['superuser', 'reports', 1, 0, 0, 0], // tylko podgląd
        ['user', 'dashboard', 1, 0, 0, 0],
        ['user', 'devices', 1, 0, 0, 0],
        ['user', 'materials', 1, 0, 0, 0],
        ['user', 'config', 0, 0, 0, 0],
        ['user', 'users', 0, 0, 0, 0],
        ['user', 'reports', 1, 0, 0, 0]  // tylko podgląd
    ];

    $insert = $db->prepare("INSERT INTO permissions (role, module, can_view, can_create, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($permissions_init as $perm) {
        $insert->execute($perm);
    }
}

// Migracja z JSON – tylko jeśli tabela printers jest pusta
$stmt = $db->query("SELECT COUNT(*) FROM printers");
if ($stmt->fetchColumn() == 0 && file_exists(__DIR__ . '/data/printers.json')) {
    $json_content = file_get_contents(__DIR__ . '/data/printers.json');
    $printers = json_decode($json_content, true);
    
    if (is_array($printers)) {
        $insert = $db->prepare("INSERT INTO printers (name, ip, community, host_number, location, hall) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($printers as $p) {
            try {
                $insert->execute([
                    $p['name'] ?? 'Nieznana',
                    $p['ip'] ?? '',
                    $p['community'] ?? 'public',
                    $p['host_number'] ?? '',
                    $p['location'] ?? '',
                    $p['hall'] ?? 'K3'
                ]);
            } catch (PDOException $e) {
                error_log("Błąd migracji drukarki: " . $e->getMessage());
            }
        }
    }
}
?>