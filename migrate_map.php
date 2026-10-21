<?php
/**
 * migrate_map.php - Migracja bazy danych dla systemu mapy budynków
 * 
 * Uruchom ten plik RAZ po dodaniu systemu mapy
 * URL: http://localhost/printer_monitor/migrate_map.php
 */

// Zapobiegaj wielokrotnemu uruchomieniu
$allow_migration = true; // Zmień na false po wykonaniu migracji

if (!$allow_migration) {
    die("Migracja została już wykonana. Zmień \$allow_migration na true w pliku migrate_map.php aby ponownie uruchomić.");
}

require_once __DIR__ . '/config.php';

echo "<!DOCTYPE html>
<html lang='pl'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Migracja - System Mapy</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; background: #f5f5f5; }
        .container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #2563eb; }
        .success { color: #22c55e; padding: 10px; background: #f0fdf4; border-left: 4px solid #22c55e; margin: 10px 0; }
        .error { color: #ef4444; padding: 10px; background: #fef2f2; border-left: 4px solid #ef4444; margin: 10px 0; }
        .info { color: #3b82f6; padding: 10px; background: #eff6ff; border-left: 4px solid #3b82f6; margin: 10px 0; }
        .step { margin: 15px 0; padding: 10px; background: #f9fafb; border-radius: 5px; }
        code { background: #e5e7eb; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
        .btn { display: inline-block; padding: 10px 20px; background: #2563eb; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
        .btn:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🗺️ Migracja Systemu Mapy Budynków</h1>
        <p>Ten skrypt utworzy niezbędne tabele i dane dla systemu mapy.</p>
";

$errors = 0;
$success = 0;

// Krok 1: Tworzenie tabeli map_objects
echo "<div class='step'><h3>Krok 1: Tworzenie tabeli 'map_objects'</h3>";
try {
    $db->exec("CREATE TABLE IF NOT EXISTS map_objects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        type_code TEXT NOT NULL,
        building_id INTEGER NOT NULL DEFAULT 1,
        position_x REAL NOT NULL DEFAULT 50.0,
        position_y REAL NOT NULL DEFAULT 50.0,
        ip_address TEXT,
        description TEXT,
        printer_id INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    echo "<div class='success'>✓ Utworzono tabelę 'map_objects'</div>";
    $success++;
} catch (PDOException $e) {
    echo "<div class='error'>✗ Błąd: " . $e->getMessage() . "</div>";
    $errors++;
}
echo "</div>";

// Krok 2: Tworzenie tabeli object_types
echo "<div class='step'><h3>Krok 2: Tworzenie tabeli 'object_types'</h3>";
try {
    $db->exec("CREATE TABLE IF NOT EXISTS object_types (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        icon TEXT NOT NULL,
        color TEXT NOT NULL,
        description TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    echo "<div class='success'>✓ Utworzono tabelę 'object_types'</div>";
    $success++;
} catch (PDOException $e) {
    echo "<div class='error'>✗ Błąd: " . $e->getMessage() . "</div>";
    $errors++;
}
echo "</div>";
// Krok dodatkowy: Tworzenie tabeli switch_ports
echo "<div class='step'><h3>Krok dodatkowy: Tworzenie tabeli 'switch_ports'</h3>";
try {
    $db->exec("CREATE TABLE IF NOT EXISTS switch_ports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        switch_id INTEGER NOT NULL,
        port_number INTEGER NOT NULL,
        port_name TEXT NOT NULL,
        port_type TEXT DEFAULT 'ethernet',
        port_speed TEXT DEFAULT '100M',
        port_status TEXT DEFAULT 'down',
        connected_to TEXT,
        connected_device_type TEXT,
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (switch_id) REFERENCES map_objects(id) ON DELETE CASCADE,
        UNIQUE(switch_id, port_number)
    )");
    
    echo "<div class='success'>✓ Utworzono tabelę 'switch_ports'</div>";
    $success++;
} catch (PDOException $e) {
    echo "<div class='error'>✗ Błąd tworzenia tabeli switch_ports: " . $e->getMessage() . "</div>";
    $errors++;
}
echo "</div>";

// Krok 3: Tworzenie tabeli building_maps
echo "<div class='step'><h3>Krok 3: Tworzenie tabeli 'building_maps'</h3>";
try {
    $db->exec("CREATE TABLE IF NOT EXISTS building_maps (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        building_id INTEGER NOT NULL UNIQUE,
        map_image TEXT NOT NULL,
        description TEXT,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    echo "<div class='success'>✓ Utworzono tabelę 'building_maps'</div>";
    $success++;
} catch (PDOException $e) {
    echo "<div class='error'>✗ Błąd: " . $e->getMessage() . "</div>";
    $errors++;
}
echo "</div>";

// Krok 4: Dodanie domyślnych typów obiektów
echo "<div class='step'><h3>Krok 4: Dodanie domyślnych typów obiektów</h3>";

$default_types = [
    ['printer', 'Drukarka', 'fa-print', '#2563eb'],
    ['computer', 'Komputer', 'fa-desktop', '#22c55e'],
    ['server', 'Serwer', 'fa-server', '#ef4444'],
    ['router', 'Router', 'fa-wifi', '#f59e0b'],
    ['switch', 'Switch', 'fa-network-wired', '#8b5cf6'],
    ['camera', 'Kamera', 'fa-video', '#10b981'],
    ['phone', 'Telefon', 'fa-phone', '#ec4899'],
    ['scanner', 'Skaner', 'fa-scanner', '#6366f1']
];

foreach ($default_types as $type) {
    try {
        $stmt = $db->prepare("INSERT OR IGNORE INTO object_types (code, name, icon, color) VALUES (?, ?, ?, ?)");
        $stmt->execute($type);
        echo "<div class='info'>ℹ️ Dodano typ: <code>{$type[1]}</code></div>";
        $success++;
    } catch (PDOException $e) {
        echo "<div class='error'>✗ Błąd dodawania typu {$type[1]}: " . $e->getMessage() . "</div>";
        $errors++;
    }
}
echo "</div>";

// Krok 5: Inicjalizacja rekordów dla budynków
echo "<div class='step'><h3>Krok 5: Inicjalizacja rekordów dla budynków</h3>";
try {
    $stmt = $db->prepare("INSERT OR IGNORE INTO building_maps (building_id, map_image) VALUES (?, '')");
    $stmt->execute([1]);
    $stmt->execute([2]);
    echo "<div class='success'>✓ Utworzono rekordy dla Budynku 1 i Budynku 2</div>";
    $success++;
} catch (PDOException $e) {
    echo "<div class='error'>✗ Błąd: " . $e->getMessage() . "</div>";
    $errors++;
}
echo "</div>";

// Krok 6: Tworzenie katalogu na mapy
echo "<div class='step'><h3>Krok 6: Tworzenie katalogu na pliki map</h3>";
$maps_dir = __DIR__ . '/uploads/maps';
if (!file_exists($maps_dir)) {
    try {
        mkdir($maps_dir, 0755, true);
        echo "<div class='success'>✓ Utworzono katalog: <code>uploads/maps/</code></div>";
        $success++;
    } catch (Exception $e) {
        echo "<div class='error'>✗ Błąd tworzenia katalogu: " . $e->getMessage() . "</div>";
        $errors++;
    }
} else {
    echo "<div class='info'>ℹ️ Katalog już istnieje: <code>uploads/maps/</code></div>";
}
echo "</div>";

// Krok 7: Dodanie uprawnień do modułu mapy
echo "<div class='step'><h3>Krok 7: Dodanie uprawnień dla modułu 'map'</h3>";
$permissions_map = [
    ['admin', 'map', 1, 1, 1, 1],
    ['superuser', 'map', 1, 1, 1, 0],
    ['user', 'map', 1, 0, 0, 0]
];

foreach ($permissions_map as $perm) {
    try {
        $stmt = $db->prepare("INSERT OR IGNORE INTO permissions (role, module, can_view, can_create, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute($perm);
        echo "<div class='info'>ℹ️ Dodano uprawnienia dla roli: <code>{$perm[0]}</code></div>";
        $success++;
    } catch (PDOException $e) {
        echo "<div class='error'>✗ Błąd: " . $e->getMessage() . "</div>";
        $errors++;
    }
}
echo "</div>";

// Podsumowanie
echo "<div class='step'><h3>📊 Podsumowanie Migracji</h3>";
echo "<p><strong>Operacje zakończone sukcesem:</strong> $success</p>";
echo "<p><strong>Błędy:</strong> $errors</p>";

if ($errors == 0) {
    echo "<div class='success'>";
    echo "<h4>✓ Migracja zakończona pomyślnie!</h4>";
    echo "<p><strong>Kolejne kroki:</strong></p>";
    echo "<ol>";
    echo "<li>Zmień \$allow_migration na <code>false</code> w pliku <code>migrate_map.php</code></li>";
    echo "<li>Przejdź do <strong>Konfiguracja → Zarządzanie typami obiektów</strong></li>";
    echo "<li>Wgraj plany budynków (JPG/PNG)</li>";
    echo "<li>Przejdź do zakładki <strong>Mapa</strong></li>";
    echo "<li>Zacznij dodawać obiekty!</li>";
    echo "</ol>";
    echo "</div>";
} else {
    echo "<div class='error'>";
    echo "<h4>⚠ Migracja zakończona z błędami</h4>";
    echo "<p>Sprawdź błędy powyżej i spróbuj ponownie.</p>";
    echo "</div>";
}
echo "</div>";

echo "<a href='index.php?page=map' class='btn'>Przejdź do Mapy →</a>";
echo "</div></body></html>";
?>