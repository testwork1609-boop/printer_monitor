<?php
// install_2fa.php - Dodanie kolumn dla 2FA
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die('Brak uprawnień.');
}

global $db;

try {
    // Dodaj kolumnę twofa_enabled
    $columns = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'name');
    
    if (!in_array('twofa_enabled', $columnNames)) {
        $db->exec("ALTER TABLE users ADD COLUMN twofa_enabled INTEGER DEFAULT 0");
        echo "✅ Kolumna 'twofa_enabled' dodana.<br>";
    } else {
        echo "ℹ️ Kolumna 'twofa_enabled' już istnieje.<br>";
    }
    
    if (!in_array('twofa_code', $columnNames)) {
        $db->exec("ALTER TABLE users ADD COLUMN twofa_code TEXT");
        echo "✅ Kolumna 'twofa_code' dodana.<br>";
    } else {
        echo "ℹ️ Kolumna 'twofa_code' już istnieje.<br>";
    }
    
    if (!in_array('twofa_code_expires', $columnNames)) {
        $db->exec("ALTER TABLE users ADD COLUMN twofa_code_expires DATETIME");
        echo "✅ Kolumna 'twofa_code_expires' dodana.<br>";
    } else {
        echo "ℹ️ Kolumna 'twofa_code_expires' już istnieje.<br>";
    }
    
    echo "<br>Instalacja zakończona. Możesz usunąć ten plik.";
    
} catch (PDOException $e) {
    echo "❌ Błąd: " . $e->getMessage();
}
?>