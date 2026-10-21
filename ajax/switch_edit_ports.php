<?php
// ajax/switch_edit_ports.php - Formularz edycji portów switcha

session_start();
require_once __DIR__ . '/../config.php';

global $db;

if (!isset($_SESSION['user_id'])) {
    echo '<div style="padding: 40px; text-align: center; color: #ef4444;">
            <i class="fas fa-exclamation-triangle"></i>
            <p>Brak autoryzacji</p>
          </div>';
    exit;
}

$switch_id = intval($_GET['switch_id'] ?? 0);

if ($switch_id <= 0) {
    echo '<div style="padding: 40px; text-align: center; color: #ef4444;">
            <i class="fas fa-exclamation-triangle"></i>
            <p>Nieprawidłowy switch</p>
          </div>';
    exit;
}

// Pobierz dane switcha
$stmt = $db->prepare("SELECT * FROM map_objects WHERE id = ?");
$stmt->execute([$switch_id]);
$switch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$switch) {
    echo '<div style="padding: 40px; text-align: center; color: #ef4444;">
            <i class="fas fa-exclamation-triangle"></i>
            <p>Switch nie istnieje</p>
          </div>';
    exit;
}

// Pobierz porty dla switcha
$stmt = $db->prepare("SELECT * FROM switch_ports WHERE switch_id = ? ORDER BY port_number");
$stmt->execute([$switch_id]);
$ports = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($ports)) {
    echo '<div style="padding: 40px; text-align: center; color: #ef4444;">
            <i class="fas fa-exclamation-triangle"></i>
            <p>Brak portów do edycji</p>
          </div>';
    exit;
}
?>

<style>
.ports-edit-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 15px;
    max-height: 500px;
    overflow-y: auto;
    padding: 10px;
    margin-bottom: 20px;
}

.port-edit-item {
    padding: 12px;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    background: white;
}

.port-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    padding-bottom: 8px;
    border-bottom: 1px solid #f3f4f6;
}

.port-number {
    font-weight: bold;
    color: #374151;
    font-size: 14px;
}

.port-form-group {
    margin-bottom: 8px;
}

.port-form-group label {
    display: block;
    font-size: 11px;
    color: #6b7280;
    margin-bottom: 3px;
}

.port-form-control {
    width: 100%;
    padding: 6px 8px;
    font-size: 12px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    background: white;
}

.port-form-control:focus {
    outline: none;
    border-color: #8b5cf6;
}

.port-status-badge {
    display: inline-block;
    padding: 2px 8px;
    font-size: 10px;
    border-radius: 12px;
    font-weight: bold;
}

.port-status-up {
    background: #dcfce7;
    color: #166534;
}

.port-status-down {
    background: #fee2e2;
    color: #991b1b;
}

.batch-edit-section {
    background: #f9fafb;
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
    border: 1px solid #e5e7eb;
}

.batch-edit-section h6 {
    margin: 0 0 10px 0;
    color: #374151;
}
</style>

<form id="editSwitchPortsForm">
    <input type="hidden" name="switch_id" value="<?php echo $switch_id; ?>">
    
    <div class="batch-edit-section">
        <h6><i class="fas fa-bolt"></i> Zbiorcza edycja</h6>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
            <div class="port-form-group">
                <label>Zmień status dla portów:</label>
                <select class="port-form-control" name="batch_status" onchange="applyBatchChange('status', this.value)">
                    <option value="">— Wybierz status —</option>
                    <option value="up">Aktywny (up)</option>
                    <option value="down">Nieaktywny (down)</option>
                </select>
            </div>
            <div class="port-form-group">
                <label>Zmień prędkość dla portów:</label>
                <select class="port-form-control" name="batch_speed" onchange="applyBatchChange('speed', this.value)">
                    <option value="">— Wybierz prędkość —</option>
                    <option value="10M">10 Mbps</option>
                    <option value="100M">100 Mbps</option>
                    <option value="1G">1 Gbps</option>
                    <option value="10G">10 Gbps</option>
                </select>
            </div>
        </div>
        <button type="button" class="btn btn-outline btn-sm" style="margin-top: 10px;" onclick="resetBatchChanges()">
            <i class="fas fa-undo"></i> Resetuj zmiany zbiorcze
        </button>
    </div>
    
    <div class="ports-edit-grid">
        <?php foreach ($ports as $port): ?>
        <div class="port-edit-item">
            <div class="port-header">
                <div class="port-number">Port <?php echo $port['port_number']; ?></div>
                <span class="port-status-badge port-status-<?php echo $port['port_status']; ?>">
                    <?php echo $port['port_status'] == 'up' ? 'AKTYWNY' : 'NIEAKTYWNY'; ?>
                </span>
            </div>
            
            <input type="hidden" name="port_id[]" value="<?php echo $port['id']; ?>">
            <input type="hidden" name="port_number[]" value="<?php echo $port['port_number']; ?>">
            
            <div class="port-form-group">
                <label>Nazwa portu:</label>
                <input type="text" class="port-form-control" name="port_name[]" 
                       value="<?php echo htmlspecialchars($port['port_name']); ?>"
                       placeholder="np. Serwer WWW">
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                <div class="port-form-group">
                    <label>Typ:</label>
                    <select class="port-form-control" name="port_type[]">
                        <option value="ethernet" <?php echo $port['port_type'] == 'ethernet' ? 'selected' : ''; ?>>Ethernet</option>
                        <option value="sfp" <?php echo $port['port_type'] == 'sfp' ? 'selected' : ''; ?>>SFP</option>
                        <option value="sfp+" <?php echo $port['port_type'] == 'sfp+' ? 'selected' : ''; ?>>SFP+</option>
                    </select>
                </div>
                
                <div class="port-form-group">
                    <label>Prędkość:</label>
                    <select class="port-form-control" name="port_speed[]">
                        <option value="10M" <?php echo $port['port_speed'] == '10M' ? 'selected' : ''; ?>>10 Mbps</option>
                        <option value="100M" <?php echo $port['port_speed'] == '100M' ? 'selected' : ''; ?>>100 Mbps</option>
                        <option value="1G" <?php echo $port['port_speed'] == '1G' ? 'selected' : ''; ?>>1 Gbps</option>
                        <option value="10G" <?php echo $port['port_speed'] == '10G' ? 'selected' : ''; ?>>10 Gbps</option>
                    </select>
                </div>
            </div>
            
            <div class="port-form-group">
                <label>Status:</label>
                <select class="port-form-control" name="port_status[]">
                    <option value="up" <?php echo $port['port_status'] == 'up' ? 'selected' : ''; ?>>Aktywny (up)</option>
                    <option value="down" <?php echo $port['port_status'] == 'down' ? 'selected' : ''; ?>>Nieaktywny (down)</option>
                    <option value="disabled" <?php echo $port['port_status'] == 'disabled' ? 'selected' : ''; ?>>Wyłączony</option>
                </select>
            </div>
            
            <div class="port-form-group">
                <label>Podłączony do:</label>
                <input type="text" class="port-form-control" name="connected_to[]" 
                       value="<?php echo htmlspecialchars($port['connected_to']); ?>"
                       placeholder="np. Serwer plików">
            </div>
            
            <div class="port-form-group">
                <label>Typ urządzenia:</label>
                <input type="text" class="port-form-control" name="connected_device_type[]" 
                       value="<?php echo htmlspecialchars($port['connected_device_type']); ?>"
                       placeholder="np. serwer, drukarka, komputer">
            </div>
            
            <div class="port-form-group">
                <label>Notatki:</label>
                <textarea class="port-form-control" name="notes[]" rows="2" 
                          placeholder="Dodatkowe informacje..."><?php echo htmlspecialchars($port['notes']); ?></textarea>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</form>

<script>
// Funkcje do obsługi zbiorczej edycji
function applyBatchChange(field, value) {
    if (!value) return;
    
    const form = document.getElementById('editSwitchPortsForm');
    
    if (field === 'status') {
        // Zastosuj dla wszystkich portów
        const statusSelects = form.querySelectorAll('select[name="port_status[]"]');
        statusSelects.forEach(select => {
            select.value = value;
            // Aktualizuj wizualną etykietę
            const badge = select.closest('.port-edit-item').querySelector('.port-status-badge');
            badge.textContent = value === 'up' ? 'AKTYWNY' : 'NIEAKTYWNY';
            badge.className = 'port-status-badge port-status-' + value;
        });
    } else if (field === 'speed') {
        // Zastosuj dla wszystkich portów
        const speedSelects = form.querySelectorAll('select[name="port_speed[]"]');
        speedSelects.forEach(select => {
            select.value = value;
        });
    }
}

function resetBatchChanges() {
    // Resetuj selektory zbiorcze
    document.querySelector('select[name="batch_status"]').value = '';
    document.querySelector('select[name="batch_speed"]').value = '';
}
</script>