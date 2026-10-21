<?php
// ajax/dismiss_alert.php - Odrzucanie alertów systemowych
session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Wymaga zalogowania
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

// Pobierz dane
$data = json_decode(file_get_contents('php://input'), true);
$alert_id = intval($data['alert_id'] ?? 0);

if ($alert_id > 0) {
    try {
        $stmt = $db->prepare("UPDATE system_alerts SET is_active = 0, dismissed_at = datetime('now') WHERE id = ?");
        $stmt->execute([$alert_id]);
        
        logActivity($_SESSION['user_id'], 'dismiss_alert', 'dashboard', "Odrzucono alert: $alert_id");
        
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid alert ID']);
}
?>