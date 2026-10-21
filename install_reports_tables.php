<?php
// install_reports_tables.php - Instalator tabel dla raportów materiałów
// Uruchom po zalogowaniu jako administrator

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Tylko dla zalogowanych administratorów (dla bezpieczeństwa)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die('Brak uprawnień. Musisz być zalogowany jako administrator.');
}

global $db;

echo "<h1>Instalator raportów materiałów eksploatacyjnych</h1>";
echo "<pre>";

try {
    // 1. Sprawdź i utwórz tabelę material_alerts
    $tableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='material_alerts'")->fetch();
    if (!$tableExists) {
        $db->exec("CREATE TABLE material_alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            printer_id INTEGER NOT NULL,
            material_type TEXT NOT NULL,
            alert_level INTEGER NOT NULL,
            current_level INTEGER NOT NULL,
            alert_date DATETIME NOT NULL,
            resolved_date DATETIME,
            resolved_by_material_id TEXT,
            FOREIGN KEY (printer_id) REFERENCES printers(id),
            FOREIGN KEY (resolved_by_material_id) REFERENCES materials(id)
        )");
        echo "✅ Tabela 'material_alerts' utworzona.\n";
    } else {
        echo "ℹ️ Tabela 'material_alerts' już istnieje.\n";
    }

    // 2. Sprawdź i utwórz tabelę material_replacements
    $tableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='material_replacements'")->fetch();
    if (!$tableExists) {
        $db->exec("CREATE TABLE material_replacements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            printer_id INTEGER NOT NULL,
            material_type TEXT NOT NULL,
            old_material_id TEXT,
            new_material_id TEXT,
            alert_date DATETIME NOT NULL,
            replacement_date DATETIME NOT NULL,
            days_waiting INTEGER,
            FOREIGN KEY (printer_id) REFERENCES printers(id),
            FOREIGN KEY (old_material_id) REFERENCES materials(id),
            FOREIGN KEY (new_material_id) REFERENCES materials(id)
        )");
        echo "✅ Tabela 'material_replacements' utworzona.\n";
    } else {
        echo "ℹ️ Tabela 'material_replacements' już istnieje.\n";
    }

    // 3. Dodaj kolumnę 'color' do tabeli materials (jeśli nie istnieje)
    $columns = $db->query("PRAGMA table_info(materials)")->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'name');
    if (!in_array('color', $columnNames)) {
        $db->exec("ALTER TABLE materials ADD COLUMN color TEXT");
        echo "✅ Kolumna 'color' dodana do tabeli 'materials'.\n";
    } else {
        echo "ℹ️ Kolumna 'color' już istnieje w tabeli 'materials'.\n";
    }

    echo "\n🎉 Instalacja zakończona pomyślnie! Możesz teraz używać strony raportów.\n";
    echo "Pamiętaj, aby usunąć ten plik instalacyjny z serwera.\n";

} catch (PDOException $e) {
    echo "❌ Błąd: " . $e->getMessage() . "\n";
    error_log("Błąd instalacji tabel raportów: " . $e->getMessage());
}
echo "</pre>";
$columns = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
$columnNames = array_column($columns, 'name');
if (!in_array('force_password_change', $columnNames)) {
    $db->exec("ALTER TABLE users ADD COLUMN force_password_change INTEGER DEFAULT 0");
    echo "✅ Kolumna 'force_password_change' dodana do tabeli 'users'.\n";
} else {
    echo "ℹ️ Kolumna 'force_password_change' już istnieje.\n";
}
?>