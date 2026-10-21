<?php
session_start();
require_once 'config.php';
require_once 'auth.php';
requireLogin();

global $db;

echo "<h2>Sprawdzanie tabel w bazie danych</h2>";

$required_tables = [
    'printers', 'printer_status', 'materials', 'users', 'sessions',
    'permissions', 'activity_logs', 'material_history', 'maintenance_schedule',
    'system_alerts', 'map_objects', 'object_types', 'building_maps',
    'switch_ports', 'material_replacement_history', 'material_alerts', 'material_replacements'
];

$existing_tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

foreach ($required_tables as $table) {
    if (in_array($table, $existing_tables)) {
        echo "✅ Tabela <strong>$table</strong> istnieje.<br>";
    } else {
        echo "❌ Tabela <strong>$table</strong> NIE istnieje!<br>";
    }
}

// Sprawdź liczbę rekordów w kluczowych tabelach
$counts = [
    'users' => "SELECT COUNT(*) FROM users",
    'printers' => "SELECT COUNT(*) FROM printers",
    'materials' => "SELECT COUNT(*) FROM materials"
];

echo "<h3>Liczba rekordów:</h3>";
foreach ($counts as $name => $sql) {
    try {
        $count = $db->query($sql)->fetchColumn();
        echo "$name: $count<br>";
    } catch (PDOException $e) {
        echo "$name: błąd - " . $e->getMessage() . "<br>";
    }
}
?>