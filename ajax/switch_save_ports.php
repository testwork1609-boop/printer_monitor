<?php
// ajax/switch_save_ports.php - Zapis zmian portów switcha

session_start();
require_once __DIR__ . '/../config.php';

global $db;

// Ustawienie nagłówka JSON
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Brak autoryzacji']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Nieprawidłowa metoda']);
    exit;
}

// Włącz obsługę błędów dla debugowania
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    $switch_id = intval($_POST['switch_id'] ?? 0);

    if ($switch_id <= 0) {
        throw new Exception('Nieprawidłowy switch');
    }

    // Sprawdź czy switch istnieje
    $stmt = $db->prepare("SELECT id FROM map_objects WHERE id = ?");
    $stmt->execute([$switch_id]);
    if (!$stmt->fetch()) {
        throw new Exception('Switch nie istnieje');
    }

    // Sprawdź czy tabela switch_ports istnieje
    $tableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='switch_ports'")->fetch();
    if (!$tableExists) {
        // Utwórz tabelę jeśli nie istnieje
        $db->exec("CREATE TABLE IF NOT EXISTS switch_ports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            switch_id INTEGER NOT NULL,
            port_number INTEGER NOT NULL,
            port_name TEXT DEFAULT 'Port',
            port_type TEXT DEFAULT 'ethernet',
            port_speed TEXT DEFAULT '100M',
            port_status TEXT DEFAULT 'down',
            connected_to TEXT DEFAULT '',
            connected_device_type TEXT DEFAULT '',
            notes TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (switch_id) REFERENCES map_objects(id) ON DELETE CASCADE,
            UNIQUE(switch_id, port_number)
        )");
    }

    // Pobierz dane z formularza
    $port_ids = $_POST['port_id'] ?? [];
    $port_numbers = $_POST['port_number'] ?? [];
    $port_names = $_POST['port_name'] ?? [];
    $port_types = $_POST['port_type'] ?? [];
    $port_speeds = $_POST['port_speed'] ?? [];
    $port_statuses = $_POST['port_status'] ?? [];
    $connected_tos = $_POST['connected_to'] ?? [];
    $connected_device_types = $_POST['connected_device_type'] ?? [];
    $notes_array = $_POST['notes'] ?? [];

    // Sprawdź czy wszystkie tablice mają tę samą długość
    $count = count($port_ids);
    if ($count === 0 || 
        $count !== count($port_numbers) ||
        $count !== count($port_names) ||
        $count !== count($port_types) ||
        $count !== count($port_speeds) ||
        $count !== count($port_statuses) ||
        $count !== count($connected_tos) ||
        $count !== count($connected_device_types) ||
        $count !== count($notes_array)) {
        throw new Exception('Nieprawidłowa struktura danych portów');
    }

    $errors = [];
    $updated = 0;

    // Rozpocznij transakcję
    $db->beginTransaction();

    // Aktualizuj każdy port
    for ($i = 0; $i < $count; $i++) {
        try {
            $port_id = intval($port_ids[$i]);
            $port_number = intval($port_numbers[$i]);
            $port_name = trim($port_names[$i]);
            $port_type = trim($port_types[$i]);
            $port_speed = trim($port_speeds[$i]);
            $port_status = trim($port_statuses[$i]);
            $connected_to = trim($connected_tos[$i]);
            $connected_device_type = trim($connected_device_types[$i]);
            $note = trim($notes_array[$i]);

            // Walidacja
            if ($port_number < 1 || $port_number > 48) {
                throw new Exception("Port $port_number: Nieprawidłowy numer portu (1-48)");
            }

            if ($port_id > 0) {
                // Aktualizuj istniejący port
                $stmt = $db->prepare("UPDATE switch_ports SET 
                    port_name = :port_name, 
                    port_type = :port_type, 
                    port_speed = :port_speed, 
                    port_status = :port_status,
                    connected_to = :connected_to, 
                    connected_device_type = :connected_device_type, 
                    notes = :notes,
                    updated_at = datetime('now')
                    WHERE id = :port_id AND switch_id = :switch_id");
                
                $stmt->execute([
                    ':port_name' => $port_name ?: "Port $port_number",
                    ':port_type' => $port_type ?: 'ethernet',
                    ':port_speed' => $port_speed ?: '100M',
                    ':port_status' => $port_status ?: 'down',
                    ':connected_to' => $connected_to,
                    ':connected_device_type' => $connected_device_type,
                    ':notes' => $note,
                    ':port_id' => $port_id,
                    ':switch_id' => $switch_id
                ]);
            } else {
                // Dodaj nowy port (jeśli nie istnieje)
                $stmt = $db->prepare("INSERT OR REPLACE INTO switch_ports 
                    (switch_id, port_number, port_name, port_type, port_speed, port_status, 
                     connected_to, connected_device_type, notes) 
                    VALUES (:switch_id, :port_number, :port_name, :port_type, :port_speed, :port_status,
                     :connected_to, :connected_device_type, :notes)");
                
                $stmt->execute([
                    ':switch_id' => $switch_id,
                    ':port_number' => $port_number,
                    ':port_name' => $port_name ?: "Port $port_number",
                    ':port_type' => $port_type ?: 'ethernet',
                    ':port_speed' => $port_speed ?: '100M',
                    ':port_status' => $port_status ?: 'down',
                    ':connected_to' => $connected_to,
                    ':connected_device_type' => $connected_device_type,
                    ':notes' => $note
                ]);
            }
            
            $updated++;
            
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
            // Kontynuuj z następnym portem, ale zapisz błąd
            continue;
        }
    }

    // Zatwierdź transakcję
    $db->commit();

    // Logowanie aktywności
    if ($updated > 0 && function_exists('logActivity')) {
        logActivity($_SESSION['user_id'], 'update_switch_ports', 'map', 
                    "Zaktualizowano $updated portów dla switcha ID: $switch_id");
    }

    if (empty($errors)) {
        echo json_encode([
            'success' => true, 
            'message' => "Pomyślnie zaktualizowano $updated portów",
            'updated' => $updated
        ]);
    } else {
        echo json_encode([
            'success' => true, // Częściowe powodzenie
            'message' => "Zaktualizowano $updated portów, błędy: " . implode('; ', $errors),
            'updated' => $updated,
            'errors' => $errors
        ]);
    }

} catch (Exception $e) {
    // Wycofaj transakcję w przypadku błędu
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    
    error_log("Błąd w switch_save_ports.php: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Błąd serwera: ' . $e->getMessage()
    ]);
}
?>