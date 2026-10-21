<?php
// migrate_printers_counters.php - Dodanie kolumn liczników do bazy danych
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Tylko administrator
requireLogin();
if (!hasPermission('config', 'edit')) {
    die('Brak uprawnień');
}

global $db;

try {
    // Sprawdź czy kolumny już istnieją
    $stmt = $db->query("PRAGMA table_info(printers)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $columns_to_add = [
        'total_counter' => 'INTEGER DEFAULT 0',
        'black_counter' => 'INTEGER DEFAULT 0', 
        'color_counter' => 'INTEGER DEFAULT 0',
        'last_counter_update' => 'DATETIME'
    ];
    
    $existing_columns = [];
    foreach ($columns as $col) {
        $existing_columns[] = $col['name'];
    }
    
    $added_count = 0;
    foreach ($columns_to_add as $column_name => $column_type) {
        if (!in_array($column_name, $existing_columns)) {
            $db->exec("ALTER TABLE printers ADD COLUMN {$column_name} {$column_type}");
            echo "Dodano kolumnę: {$column_name}<br>";
            $added_count++;
        }
    }
    
    // Dodaj kolumnę do printer_status
    $stmt = $db->query("PRAGMA table_info(printer_status)");
    $columns_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $existing_status_columns = [];
    foreach ($columns_status as $col) {
        $existing_status_columns[] = $col['name'];
    }
    
    if (!in_array('page_counter', $existing_status_columns)) {
        $db->exec("ALTER TABLE printer_status ADD COLUMN page_counter INTEGER DEFAULT 0");
        echo "Dodano kolumnę page_counter do printer_status<br>";
        $added_count++;
    }
    
    if ($added_count > 0) {
        echo "<h3>Migracja zakończona sukcesem!</h3>";
        echo "Dodano {$added_count} kolumn.<br>";
        echo '<a href="index.php?page=dashboard">Powrót do dashboard</a>';
        
        logActivity($_SESSION['user_id'], 'database_migration', 'config', 
            "Dodano kolumny liczników wydruków do bazy danych");
    } else {
        echo "<h3>Wszystkie kolumny już istnieją!</h3>";
        echo '<a href="index.php?page=dashboard">Powrót do dashboard</a>';
    }
    
} catch (PDOException $e) {
    echo "<h3>Błąd migracji:</h3>";
    echo $e->getMessage();
    echo '<br><a href="index.php?page=dashboard">Powrót do dashboard</a>';
}
?>