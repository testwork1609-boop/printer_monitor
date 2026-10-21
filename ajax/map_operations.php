<?php
// ajax/map_operations.php - Operacje na mapie budynków

session_start();
require_once __DIR__ . '/../config.php';

global $db;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Brak autoryzacji']);
    exit;
}

// Sprawdź czy tabele istnieją
function tableExists($tableName) {
    global $db;
    $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$tableName]);
    return $stmt->fetch() !== false;
}

// Utwórz tabele jeśli nie istnieją
if (!tableExists('map_objects')) {
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
}

if (!tableExists('object_types')) {
    $db->exec("CREATE TABLE IF NOT EXISTS object_types (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        icon TEXT NOT NULL,
        color TEXT NOT NULL,
        description TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Dodaj domyślne typy
    $types = [
        ['printer', 'Drukarka', 'fa-print', '#2563eb'],
        ['computer', 'Komputer', 'fa-desktop', '#22c55e'],
        ['server', 'Serwer', 'fa-server', '#ef4444'],
        ['router', 'Router', 'fa-wifi', '#f59e0b'],
        ['switch', 'Switch', 'fa-network-wired', '#8b5cf6'],
        ['camera', 'Kamera', 'fa-video', '#10b981'],
        ['phone', 'Telefon', 'fa-phone', '#ec4899'],
        ['scanner', 'Skaner', 'fa-scanner', '#6366f1']
    ];
    
    foreach ($types as $type) {
        $stmt = $db->prepare("INSERT OR IGNORE INTO object_types (code, name, icon, color) VALUES (?, ?, ?, ?)");
        $stmt->execute($type);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        $data = $_POST;
    }
    
    $action = $data['action'] ?? '';
    
    try {
        switch ($action) {
            case 'add_object':
                echo json_encode(addObject($data));
                break;
                
            case 'update_object':
                echo json_encode(updateObject($data));
                break;
                
            case 'delete_object':
                echo json_encode(deleteObject($data));
                break;
                
            case 'update_position':
                echo json_encode(updatePosition($data));
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Nieznana akcja']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Błąd: ' . $e->getMessage()]);
    }
    exit;
}

function addObject($data) {
    global $db;
    
    $required = ['name', 'type_code', 'building_id'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            return ['success' => false, 'message' => 'Pole ' . $field . ' jest wymagane'];
        }
    }
    
    $name = trim($data['name']);
    $type_code = trim($data['type_code']);
    $building_id = intval($data['building_id']);
    $position_x = floatval($data['position_x'] ?? 50.0);
    $position_y = floatval($data['position_y'] ?? 50.0);
    $ip_address = trim($data['ip_address'] ?? '');
    $description = trim($data['description'] ?? '');
    $printer_id = isset($data['printer_id']) ? intval($data['printer_id']) : null;
    
    // Sprawdź czy typ istnieje
    $stmt = $db->prepare("SELECT COUNT(*) FROM object_types WHERE code = ?");
    $stmt->execute([$type_code]);
    if ($stmt->fetchColumn() == 0) {
        return ['success' => false, 'message' => 'Nieprawidłowy typ urządzenia'];
    }
    
    if ($building_id < 1 || $building_id > 2) {
        return ['success' => false, 'message' => 'Nieprawidłowy budynek'];
    }
    
    try {
        $stmt = $db->prepare("INSERT INTO map_objects 
            (name, type_code, building_id, position_x, position_y, ip_address, description, printer_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $name, $type_code, $building_id, $position_x, $position_y, 
            $ip_address, $description, $printer_id
        ]);
        
        return [
            'success' => true, 
            'message' => 'Urządzenie dodane',
            'id' => $db->lastInsertId()
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Błąd bazy: ' . $e->getMessage()];
    }
}

function updateObject($data) {
    global $db;
    
    if (empty($data['object_id'])) {
        return ['success' => false, 'message' => 'ID urządzenia jest wymagane'];
    }
    
    $id = intval($data['object_id']);
    
    // Pobierz aktualne dane
    $stmt = $db->prepare("SELECT * FROM map_objects WHERE id = ?");
    $stmt->execute([$id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$current) {
        return ['success' => false, 'message' => 'Urządzenie nie istnieje'];
    }
    
    $updates = [];
    $params = [];
    
    $fields = [
        'name' => 'name',
        'type_code' => 'type_code',
        'building_id' => 'building_id',
        'position_x' => 'position_x',
        'position_y' => 'position_y',
        'ip_address' => 'ip_address',
        'description' => 'description',
        'printer_id' => 'printer_id'
    ];
    
    foreach ($fields as $key => $field) {
        if (isset($data[$key]) && $data[$key] != $current[$field]) {
            $updates[] = "$field = ?";
            $params[] = $data[$key];
        }
    }
    
    if (empty($updates)) {
        return ['success' => true, 'message' => 'Brak zmian'];
    }
    
    $params[] = $id;
    
    try {
        $sql = "UPDATE map_objects SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        return ['success' => true, 'message' => 'Urządzenie zaktualizowane'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Błąd bazy: ' . $e->getMessage()];
    }
}

function updatePosition($data) {
    global $db;
    
    $required = ['object_id', 'position_x', 'position_y'];
    foreach ($required as $field) {
        if (!isset($data[$field])) {
            return ['success' => false, 'message' => 'Brakujące dane: ' . $field];
        }
    }
    
    $id = intval($data['object_id']);
    $position_x = floatval($data['position_x']);
    $position_y = floatval($data['position_y']);
    
    try {
        $stmt = $db->prepare("UPDATE map_objects SET position_x = ?, position_y = ? WHERE id = ?");
        $stmt->execute([$position_x, $position_y, $id]);
        
        return ['success' => true, 'message' => 'Pozycja zaktualizowana'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Błąd bazy: ' . $e->getMessage()];
    }
}

function deleteObject($data) {
    global $db;
    
    if (empty($data['object_id'])) {
        return ['success' => false, 'message' => 'ID urządzenia jest wymagane'];
    }
    
    $id = intval($data['object_id']);
    
    try {
        $stmt = $db->prepare("DELETE FROM map_objects WHERE id = ?");
        $stmt->execute([$id]);
        
        return ['success' => true, 'message' => 'Urządzenie usunięte'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Błąd bazy: ' . $e->getMessage()];
    }
}

// Domyślna odpowiedź
http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Metoda nieobsługiwana']);
?>