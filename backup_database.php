<?php
// backup_database.php - Backup bazy danych SQLite
session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Wymaga uprawnień admina
requirePermission('config', 'edit');

$db_file = __DIR__ . '/app.db';
$backup_dir = __DIR__ . '/backups';
$backup_file = 'backup_' . date('Y-m-d_H-i-s') . '.db';

// Utwórz katalog backups jeśli nie istnieje
if (!file_exists($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

$backup_path = $backup_dir . '/' . $backup_file;

// Wykonaj kopię pliku SQLite
if (copy($db_file, $backup_path)) {
    logActivity($_SESSION['user_id'], 'backup_database', 'config', "Utworzono backup bazy danych: $backup_file");
    
    // Pobierz plik
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $backup_file . '"');
    header('Content-Length: ' . filesize($backup_path));
    readfile($backup_path);
    
    // Opcjonalnie usuń plik po pobraniu
    // unlink($backup_path);
    
    exit;
} else {
    $_SESSION['messages'][] = ['type' => 'error', 'text' => 'Błąd tworzenia backupu'];
    header('Location: index.php?page=config_content');
    exit;
}
?>