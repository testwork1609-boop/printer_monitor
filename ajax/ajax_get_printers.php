<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

requireLogin();
requirePermission('devices', 'view');

header('Content-Type: application/json');

try {
    $stmt = $db->query("SELECT p.*, s.status, s.materials_json, s.last_check 
                        FROM printers p 
                        LEFT JOIN printer_status s ON p.id = s.printer_id 
                        ORDER BY CAST(p.host_number AS INTEGER)");
    $printers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'printers' => $printers]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}