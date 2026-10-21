<?php
/**
 * migrate_database.php - Skrypt migracji bazy danych
 * 
 * Uruchom ten plik raz po aktualizacji do wersji 2.0
 * Dodaje brakujące kolumny i tabele do istniejącej bazy danych
 * 
 * UŻYCIE: Otwórz w przeglądarce: http://localhost/printer_monitor/migrate_database.php
 */

// Prevent direct execution in production
$allow_migration = true; // Zmień na false po wykonaniu migracji

if (!$allow_migration) {
    die("Migracja została już wykonana. Zmień \$allow_migration na true w pliku migrate_database.php aby ponownie uruchomić.");
}

require_once __DIR__ . '/config.php';

echo "<!DOCTYPE html>
<html lang='pl'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Migracja Bazy Danych</title>
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
        <h1>🔄 Migracja Bazy Danych do Wersji 2.0</h1>
        <p>Ten skrypt zaktualizuje Twoją bazę danych o nowe funkcje systemu.</p>
";

$errors = 0;
$success = 0;

// Funkcja do sprawdzania czy kolumna istnieje
function columnExists($db, $table, $column) {
    $columns = $db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
    $existing_columns = array_column($columns, 'name');
    return in_array($column, $existing_columns);
}

// Funkcja do sprawdzania czy tabela istnieje
function tableExists($db, $table) {
    $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
    return $result->fetchColumn() !== false;
}

echo "<div class='step'><h3>Krok 1: Tworzenie kopii zapasowej</h3>";
try {
    $backup_file = __DIR__ . '/app_backup_' . date('Y-m-d_H-i-s') . '.db';
    copy(__DIR__ . '/app.db', $backup_file);
    echo "<div class='success'>✓ Utworzono kopię zapasową: " . basename($backup_file) . "</div>";
    $success++;
} catch (Exception $e) {
    echo "<div class='error'>✗ Błąd tworzenia kopii: " . $e->getMessage() . "</div>";
    $errors++;
}
echo "</div>";

echo "<div class='step'><h3>Krok 2: Aktualizacja tabeli 'printers'</h3>";
$printer_columns = [
    'model' => 'ALTER TABLE printers ADD COLUMN model TEXT',
    'total_prints' => 'ALTER TABLE printers ADD COLUMN total_prints INTEGER DEFAULT 0',
    'color_prints' => 'ALTER TABLE printers ADD COLUMN color_prints INTEGER DEFAULT 0',
    'mono_prints' => 'ALTER TABLE printers ADD COLUMN mono_prints INTEGER DEFAULT 0',
    'is_active' => 'ALTER TABLE printers ADD COLUMN is_active INTEGER DEFAULT 1'
];

foreach ($printer_columns as $column => $sql) {
    try {
        if (!columnExists($db, 'printers', $column)) {
            $db->exec($sql);
            echo "<div class='success'>✓ Dodano kolumnę: <code>$column</code></div>";
            $success++;
        } else {
            echo "<div class='info'>ℹ Kolumna <code>$column</code> już istnieje</div>";
        }
    } catch (PDOException $e) {
        echo "<div class='error'>✗ Błąd dodawania <code>$column</code>: " . $e->getMessage() . "</div>";
        $errors++;
    }
}
echo "</div>";

echo "<div class='step'><h3>Krok 3: Aktualizacja tabeli 'materials'</h3>";
$material_columns = [
    'min_quantity' => 'ALTER TABLE materials ADD COLUMN min_quantity INTEGER DEFAULT 0',
    'issued_to' => 'ALTER TABLE materials ADD COLUMN issued_to TEXT',
    'issued_date' => 'ALTER TABLE materials ADD COLUMN issued_date TEXT'
];

foreach ($material_columns as $column => $sql) {
    try {
        if (!columnExists($db, 'materials', $column)) {
            $db->exec($sql);
            echo "<div class='success'>✓ Dodano kolumnę: <code>$column</code></div>";
            $success++;
        } else {
            echo "<div class='info'>ℹ Kolumna <code>$column</code> już istnieje</div>";
        }
    } catch (PDOException $e) {
        echo "<div class='error'>✗ Błąd dodawania <code>$column</code>: " . $e->getMessage() . "</div>";
        $errors++;
    }
}
echo "</div>";

echo "<div class='step'><h3>Krok 4: Tworzenie nowych tabel</h3>";

// Tabela users
if (!tableExists($db, 'users')) {
    try {
        $db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            email TEXT,
            full_name TEXT,
            role TEXT NOT NULL DEFAULT 'user',
            avatar TEXT,
            is_active INTEGER DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now')),
            last_login TEXT,
            login_count INTEGER DEFAULT 0
        )");
        
        // Dodaj domyślnego admina
        $admin_hash = password_hash('admin123', PASSWORD_BCRYPT);
        $db->exec("INSERT INTO users (username, password_hash, full_name, role, is_active) 
                   VALUES ('admin', '$admin_hash', 'Administrator', 'admin', 1)");
        
        echo "<div class='success'>✓ Utworzono tabelę <code>users</code> z domyślnym kontem admin</div>";
        $success++;
    } catch (PDOException $e) {
        echo "<div class='error'>✗ Błąd tworzenia tabeli users: " . $e->getMessage() . "</div>";
        $errors++;
    }
} else {
    echo "<div class='info'>ℹ Tabela <code>users</code> już istnieje</div>";
}

// Tabela sessions
if (!tableExists($db, 'sessions')) {
    try {
        $db->exec("CREATE TABLE sessions (
            id TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL,
            ip_address TEXT,
            user_agent TEXT,
            created_at TEXT DEFAULT (datetime('now')),
            expires_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        echo "<div class='success'>✓ Utworzono tabelę <code>sessions</code></div>";
        $success++;
    } catch (PDOException $e) {
        echo "<div class='error'>✗ Błąd tworzenia tabeli sessions: " . $e->getMessage() . "</div>";
        $errors++;
    }
} else {
    echo "<div class='info'>ℹ Tabela <code>sessions</code> już istnieje</div>";
}

// Tabela permissions
if (!tableExists($db, 'permissions')) {
    try {
        $db->exec("CREATE TABLE permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            role TEXT NOT NULL,
            module TEXT NOT NULL,
            can_view INTEGER DEFAULT 0,
            can_create INTEGER DEFAULT 0,
            can_edit INTEGER DEFAULT 0,
            can_delete INTEGER DEFAULT 0,
            UNIQUE(role, module)
        )");
        
        // Dodaj domyślne uprawnienia
        $permissions = [
            ['admin', 'dashboard', 1, 1, 1, 1],
            ['admin', 'devices', 1, 1, 1, 1],
            ['admin', 'materials', 1, 1, 1, 1],
            ['admin', 'config', 1, 1, 1, 1],
            ['admin', 'users', 1, 1, 1, 1],
            ['superuser', 'dashboard', 1, 0, 0, 0],
            ['superuser', 'devices', 1, 1, 1, 0],
            ['superuser', 'materials', 1, 1, 1, 0],
            ['user', 'dashboard', 1, 0, 0, 0],
            ['user', 'devices', 1, 0, 0, 0],
            ['user', 'materials', 1, 0, 0, 0]
        ];
        
        foreach ($permissions as $perm) {
            $stmt = $db->prepare("INSERT INTO permissions (role, module, can_view, can_create, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute($perm);
        }
        
        echo "<div class='success'>✓ Utworzono tabelę <code>permissions</code> z domyślnymi uprawnieniami</div>";
        $success++;
    } catch (PDOException $e) {
        echo "<div class='error'>✗ Błąd tworzenia tabeli permissions: " . $e->getMessage() . "</div>";
        $errors++;
    }
} else {
    echo "<div class='info'>ℹ Tabela <code>permissions</code> już istnieje</div>";
}

// Pozostałe tabele (activity_logs, material_history, maintenance_schedule, system_alerts)
$other_tables = [
    'activity_logs' => "CREATE TABLE activity_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        action TEXT NOT NULL,
        module TEXT,
        details TEXT,
        ip_address TEXT,
        created_at TEXT DEFAULT (datetime('now')),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    )",
    'material_history' => "CREATE TABLE material_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        material_id TEXT NOT NULL,
        action TEXT NOT NULL,
        quantity_change INTEGER NOT NULL,
        quantity_after INTEGER NOT NULL,
        printer_id INTEGER,
        user_id INTEGER,
        notes TEXT,
        created_at TEXT DEFAULT (datetime('now')),
        FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE,
        FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE SET NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    )",
    'maintenance_schedule' => "CREATE TABLE maintenance_schedule (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        printer_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        scheduled_date TEXT NOT NULL,
        completed INTEGER DEFAULT 0,
        completed_date TEXT,
        notes TEXT,
        created_at TEXT DEFAULT (datetime('now')),
        FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE CASCADE
    )",
    'system_alerts' => "CREATE TABLE system_alerts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL,
        title TEXT NOT NULL,
        message TEXT NOT NULL,
        module TEXT,
        is_active INTEGER DEFAULT 1,
        created_at TEXT DEFAULT (datetime('now')),
        dismissed_at TEXT
    )"
];

foreach ($other_tables as $table_name => $create_sql) {
    if (!tableExists($db, $table_name)) {
        try {
            $db->exec($create_sql);
            echo "<div class='success'>✓ Utworzono tabelę <code>$table_name</code></div>";
            $success++;
        } catch (PDOException $e) {
            echo "<div class='error'>✗ Błąd tworzenia tabeli $table_name: " . $e->getMessage() . "</div>";
            $errors++;
        }
    } else {
        echo "<div class='info'>ℹ Tabela <code>$table_name</code> już istnieje</div>";
    }
}
echo "</div>";

echo "<div class='step'><h3>Krok 5: Tworzenie indeksów</h3>";
$indexes = [
    "CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id)",
    "CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at)",
    "CREATE INDEX IF NOT EXISTS idx_activity_logs_user ON activity_logs(user_id)",
    "CREATE INDEX IF NOT EXISTS idx_material_history_material ON material_history(material_id)",
    "CREATE INDEX IF NOT EXISTS idx_maintenance_schedule_printer ON maintenance_schedule(printer_id)"
];

foreach ($indexes as $index_sql) {
    try {
        $db->exec($index_sql);
        $success++;
    } catch (PDOException $e) {
        // Indeksy mogą już istnieć, nie zgłaszaj błędu
    }
}
echo "<div class='success'>✓ Utworzono indeksy dla wydajności</div>";
echo "</div>";

// Podsumowanie
echo "<div class='step'><h3>📊 Podsumowanie Migracji</h3>";
echo "<p><strong>Operacje zakończone sukcesem:</strong> $success</p>";
echo "<p><strong>Błędy:</strong> $errors</p>";

if ($errors == 0) {
    echo "<div class='success'>";
    echo "<h4>✓ Migracja zakończona pomyślnie!</h4>";
    echo "<p><strong>Domyślne konto:</strong></p>";
    echo "<ul>";
    echo "<li>Login: <code>admin</code></li>";
    echo "<li>Hasło: <code>admin123</code></li>";
    echo "</ul>";
    echo "<p><strong>WAŻNE:</strong> Zmień hasło administratora po pierwszym logowaniu!</p>";
    echo "<p><strong>Kolejne kroki:</strong></p>";
    echo "<ol>";
    echo "<li>Zmień \$allow_migration na <code>false</code> w pliku <code>migrate_database.php</code></li>";
    echo "<li>Usuń ten plik z serwera (opcjonalnie)</li>";
    echo "<li>Zaloguj się do systemu</li>";
    echo "</ol>";
    echo "</div>";
} else {
    echo "<div class='error'>";
    echo "<h4>⚠ Migracja zakończona z błędami</h4>";
    echo "<p>Sprawdź błędy powyżej. Możesz przywrócić bazę z kopii zapasowej jeśli coś poszło nie tak.</p>";
    echo "</div>";
}
echo "</div>";

echo "<a href='index.php' class='btn'>Przejdź do systemu →</a>";
echo "</div></body></html>";
?>