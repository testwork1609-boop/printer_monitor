<?php
// content/map_config.php - Konfiguracja mapy i typów obiektów

// Sesja jest już rozpoczęta w index.php, więc nie trzeba jej zaczynać ponownie
// require_once __DIR__ . '/../config.php'; - już jest w index.php
// require_once __DIR__ . '/../auth.php'; - już jest w index.php

global $db;

requirePermission('config', 'view');

$can_edit = hasPermission('config', 'edit');

function addMessage($type, $text) {
    if (!isset($_SESSION['messages'])) $_SESSION['messages'] = [];
    if (count($_SESSION['messages']) >= 3) array_shift($_SESSION['messages']);
    $_SESSION['messages'][] = ['type' => $type, 'text' => $text];
}

// DEBUG: Dodaj informacje o przesyłaniu
error_log("DEBUG map_config: REQUEST_METHOD = " . $_SERVER['REQUEST_METHOD']);
error_log("DEBUG map_config: POST = " . print_r($_POST, true));
error_log("DEBUG map_config: FILES = " . print_r($_FILES, true));

// Obsługa wgrywania map budynków
if (isset($_POST['upload_map']) && $can_edit) {
    error_log("DEBUG: Rozpoczynam przetwarzanie uploadu dla budynku: " . ($_POST['building_id'] ?? 'brak'));
    
    $building_id = intval($_POST['building_id'] ?? 0);
    
    if ($building_id > 0 && isset($_FILES['map_file']) && $_FILES['map_file']['error'] == 0) {
        $allowed = ['image/jpeg', 'image/png', 'image/gif'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        $file = $_FILES['map_file'];
        
        error_log("DEBUG: Plik: " . $file['name'] . ", typ: " . $file['type'] . ", rozmiar: " . $file['size'] . ", error: " . $file['error']);
        
        if (!in_array($file['type'], $allowed)) {
            error_log("DEBUG: Nieprawidłowy format pliku: " . $file['type']);
            addMessage('error', 'Nieprawidłowy format pliku. Dozwolone: JPG, PNG, GIF');
        } elseif ($file['size'] > $max_size) {
            error_log("DEBUG: Plik zbyt duży: " . $file['size'] . " > " . $max_size);
            addMessage('error', 'Plik zbyt duży. Maksymalny rozmiar: 5MB');
        } else {
            // Utwórz katalog jeśli nie istnieje
            $upload_dir = __DIR__ . '/../uploads/maps/';
            error_log("DEBUG: Katalog: " . $upload_dir);
            
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
                error_log("DEBUG: Utworzono katalog");
            }
            
            // Usuń stary plik jeśli istnieje
            $stmt = $db->prepare("SELECT map_image FROM building_maps WHERE building_id = ?");
            $stmt->execute([$building_id]);
            $old_map = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($old_map && $old_map['map_image'] && file_exists(__DIR__ . '/../' . $old_map['map_image'])) {
                unlink(__DIR__ . '/../' . $old_map['map_image']);
                error_log("DEBUG: Usunięto stary plik: " . $old_map['map_image']);
            }
            
            // Zapisz nowy plik
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'building_' . $building_id . '_' . time() . '.' . $extension;
            $filepath = $upload_dir . $filename;
            
            error_log("DEBUG: Ścieżka docelowa: " . $filepath);
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $relative_path = 'uploads/maps/' . $filename;
                
                // Aktualizuj w bazie
                $stmt = $db->prepare("UPDATE building_maps SET map_image = ?, uploaded_at = datetime('now') WHERE building_id = ?");
                $stmt->execute([$relative_path, $building_id]);
                
                error_log("DEBUG: Plik zapisany, baza zaktualizowana: " . $relative_path);
                addMessage('success', "Mapa budynku $building_id została wgrana");
                logActivity($_SESSION['user_id'], 'upload_map', 'map', "Wgrano mapę budynku $building_id");
            } else {
                error_log("DEBUG: Błąd move_uploaded_file");
                addMessage('error', 'Błąd zapisu pliku. Kod błędu: ' . $file['error']);
            }
        }
    } else {
        error_log("DEBUG: Brak pliku lub błąd uploadu. Building ID: $building_id, file error: " . ($_FILES['map_file']['error'] ?? 'brak pliku'));
        addMessage('error', 'Błąd przesyłania pliku. Kod: ' . ($_FILES['map_file']['error'] ?? '0'));
    }
    
    // Tymczasowo wyłącz przekierowanie dla debugowania
    // header('Location: index.php?page=map_config');
    // exit;
    echo "<script>window.location.href='index.php?page=map_config';</script>";
    exit;
}

// Obsługa dodawania typu obiektu
if (isset($_POST['add_type']) && $can_edit) {
    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $icon = trim($_POST['icon'] ?? '');
    $color = trim($_POST['color'] ?? '#6b7280');
    
    if ($code && $name && $icon) {
        try {
            $stmt = $db->prepare("INSERT INTO object_types (code, name, icon, color) VALUES (?, ?, ?, ?)");
            $stmt->execute([$code, $name, $icon, $color]);
            
            addMessage('success', "Dodano typ: $name");
            logActivity($_SESSION['user_id'], 'add_object_type', 'map', "Dodano typ obiektu: $name");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'UNIQUE') !== false) {
                addMessage('error', 'Kod typu już istnieje');
            } else {
                addMessage('error', 'Błąd dodawania typu: ' . $e->getMessage());
            }
        }
    } else {
        addMessage('error', 'Wypełnij wszystkie wymagane pola');
    }
    
    header('Location: index.php?page=map_config');
    exit;
}

// Obsługa usuwania typu
if (isset($_GET['delete_type']) && $can_edit) {
    $type_id = intval($_GET['delete_type']);
    
    if ($type_id > 0) {
        // Sprawdź czy typ jest używany
        $stmt = $db->prepare("SELECT COUNT(*) FROM map_objects WHERE type_code = (SELECT code FROM object_types WHERE id = ?)");
        $stmt->execute([$type_id]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            addMessage('error', "Nie można usunąć typu - jest używany przez $count obiektów");
        } else {
            $stmt = $db->prepare("DELETE FROM object_types WHERE id = ?");
            $stmt->execute([$type_id]);
            
            addMessage('success', 'Typ usunięty');
            logActivity($_SESSION['user_id'], 'delete_object_type', 'map', "Usunięto typ obiektu ID: $type_id");
        }
    }
    
    header('Location: index.php?page=map_config');
    exit;
}

// Pobierz typy obiektów
$stmt = $db->query("SELECT ot.*, COUNT(mo.id) as usage_count 
                    FROM object_types ot
                    LEFT JOIN map_objects mo ON ot.code = mo.type_code
                    GROUP BY ot.id
                    ORDER BY ot.name");
$object_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pobierz mapy budynków
$stmt = $db->query("SELECT * FROM building_maps");
$building_maps = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="dashboard-header">
    <h1><i class="fas fa-cog"></i> Konfiguracja Mapy</h1>
    <p class="text-muted">Zarządzaj mapami budynków i typami obiektów</p>
</div>

<style>
.config-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
    gap: 24px;
    margin-top: 24px;
}

.config-section {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.config-section h2 {
    margin-bottom: 20px;
    color: var(--gray-900);
}

.map-preview {
    width: 100%;
    max-height: 300px;
    object-fit: contain;
    border: 2px solid var(--gray-200);
    border-radius: 8px;
    margin-top: 12px;
}

.type-list {
    display: grid;
    gap: 12px;
    margin-top: 20px;
}

.type-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px;
    background: var(--gray-50);
    border-radius: 8px;
    transition: all 0.2s;
}

.type-item:hover {
    background: var(--gray-100);
}

.type-icon-preview {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    font-size: 24px;
}

.type-info {
    flex: 1;
}

.type-name {
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: 4px;
}

.type-code {
    font-size: 12px;
    color: var(--gray-600);
    font-family: monospace;
    background: var(--gray-200);
    padding: 2px 8px;
    border-radius: 4px;
    display: inline-block;
}

.type-usage {
    font-size: 13px;
    color: var(--gray-600);
}

.type-actions {
    display: flex;
    gap: 8px;
}

.building-card {
    border: 2px solid var(--gray-200);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
}

.building-card h3 {
    margin-bottom: 12px;
    color: var(--gray-700);
}

.upload-area {
    border: 2px dashed var(--gray-300);
    border-radius: 8px;
    padding: 24px;
    text-align: center;
    background: var(--gray-50);
    transition: all 0.2s;
}

.upload-area:hover {
    border-color: var(--primary);
    background: var(--gray-100);
}

.upload-area input[type="file"] {
    display: none;
}

.upload-label {
    display: block;
    cursor: pointer;
    color: var(--primary);
    font-weight: 600;
}

.upload-label i {
    font-size: 32px;
    margin-bottom: 8px;
    display: block;
}

@media (max-width: 768px) {
    .config-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="config-grid">
    <!-- Mapy budynków -->
    <div class="config-section">
        <h2><i class="fas fa-image"></i> Mapy Budynków</h2>
        
        <?php foreach ($building_maps as $bm): ?>
        <div class="building-card">
            <h3>Budynek <?php echo $bm['building_id']; ?></h3>
            
            <?php if ($bm['map_image']): ?>
                <img src="<?php echo htmlspecialchars($bm['map_image']); ?>" 
                     class="map-preview" 
                     alt="Budynek <?php echo $bm['building_id']; ?>">
                <p style="margin-top: 8px; font-size: 12px; color: var(--gray-600);">
                    Wgrano: <?php echo date('d.m.Y H:i', strtotime($bm['uploaded_at'])); ?>
                </p>
            <?php else: ?>
                <p style="color: var(--gray-500); text-align: center; padding: 20px;">
                    Brak mapy - wgraj plan budynku
                </p>
            <?php endif; ?>
            
          <?php if ($can_edit): ?>
<form method="post" enctype="multipart/form-data" style="margin-top: 16px;" id="uploadForm<?php echo $bm['building_id']; ?>">
    <input type="hidden" name="building_id" value="<?php echo $bm['building_id']; ?>">
    <div class="upload-area">
        <input type="file" 
               name="map_file" 
               id="mapFile<?php echo $bm['building_id']; ?>" 
               accept="image/jpeg,image/png,image/gif"
               onchange="document.getElementById('uploadForm<?php echo $bm['building_id']; ?>').submit()">
        <label for="mapFile<?php echo $bm['building_id']; ?>" class="upload-label">
            <i class="fas fa-cloud-upload-alt"></i>
            <?php echo $bm['map_image'] ? 'Zmień mapę' : 'Wgraj mapę'; ?>
            <br>
            <small style="color: var(--gray-600);">JPG, PNG lub GIF (max 5MB)</small>
        </label>
    </div>
    <input type="hidden" name="upload_map" value="1">
</form>
<?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Typy obiektów -->
    <div class="config-section">
        <h2><i class="fas fa-shapes"></i> Typy Obiektów</h2>
        
        <?php if ($can_edit): ?>
        <button class="btn btn-primary" style="width: 100%; margin-bottom: 20px;" onclick="openAddTypeModal()">
            <i class="fas fa-plus"></i> Dodaj typ obiektu
        </button>
        <?php endif; ?>
        
        <div class="type-list">
            <?php foreach ($object_types as $type): ?>
            <div class="type-item">
                <div class="type-icon-preview" style="color: <?php echo htmlspecialchars($type['color']); ?>;">
                    <i class="fas <?php echo htmlspecialchars($type['icon']); ?>"></i>
                </div>
                <div class="type-info">
                    <div class="type-name"><?php echo htmlspecialchars($type['name']); ?></div>
                    <span class="type-code"><?php echo htmlspecialchars($type['code']); ?></span>
                    <div class="type-usage">
                        Użyty: <?php echo $type['usage_count']; ?> raz(y)
                    </div>
                </div>
                <?php if ($can_edit && $type['usage_count'] == 0): ?>
                <div class="type-actions">
                    <button class="btn btn-outline btn-sm" 
                            onclick="confirmDeleteType(<?php echo $type['id']; ?>, '<?php echo htmlspecialchars($type['name']); ?>')">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div style="margin-top: 24px; padding: 16px; background: #eff6ff; border-radius: 8px;">
            <h4 style="color: var(--primary); margin-bottom: 8px;">
                <i class="fas fa-lightbulb"></i> Jak dodać własne ikony?
            </h4>
            <p style="font-size: 13px; color: var(--gray-700); margin-bottom: 8px;">
                Ikony pochodzą z Font Awesome. Wyszukaj ikonę na:
            </p>
            <a href="https://fontawesome.com/search?m=free" 
               target="_blank" 
               class="btn btn-outline btn-sm">
                <i class="fas fa-external-link-alt"></i> Font Awesome Icons
            </a>
        </div>
    </div>
</div>

<?php if ($can_edit): ?>
<!-- Modal dodawania typu -->
<div class="modal" id="addTypeModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-plus"></i> Dodaj typ obiektu</h3>
            <button class="modal-close" onclick="closeAddTypeModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="post">
            <div class="modal-body">
                <div class="form-group">
                    <label for="typeName">Nazwa typu *</label>
                    <input type="text" class="form-control" id="typeName" name="name" required
                           placeholder="np. Telefon IP">
                </div>
                
                <div class="form-group">
                    <label for="typeCode">Kod typu *</label>
                    <input type="text" class="form-control" id="typeCode" name="code" required
                           placeholder="np. phone" pattern="[a-z_]+" 
                           title="Tylko małe litery i podkreślniki">
                    <small class="text-muted">Tylko małe litery i podkreślniki (bez spacji)</small>
                </div>
                
                <div class="form-group">
                    <label for="typeIcon">Ikona Font Awesome *</label>
                    <input type="text" class="form-control" id="typeIcon" name="icon" required
                           placeholder="np. fa-phone">
                    <small class="text-muted">
                        Wyszukaj na: <a href="https://fontawesome.com/search?m=free" target="_blank">fontawesome.com</a>
                    </small>
                </div>
                
                <div class="form-group">
                    <label for="typeColor">Kolor</label>
                    <input type="color" class="form-control" id="typeColor" name="color" value="#6b7280">
                </div>
                
                <div style="padding: 16px; background: var(--gray-50); border-radius: 8px; text-align: center;">
                    <p style="font-size: 12px; color: var(--gray-600); margin-bottom: 8px;">Podgląd:</p>
                    <div id="typePreview" style="display: inline-flex; align-items: center; justify-content: center; width: 48px; height: 48px; border-radius: 50%; background: white; box-shadow: 0 2px 4px rgba(0,0,0,0.1); font-size: 24px; color: #6b7280;">
                        <i class="fas fa-question"></i>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeAddTypeModal()">Anuluj</button>
                <button type="submit" name="add_type" class="btn btn-primary">
                    <i class="fas fa-save"></i> Dodaj typ
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openAddTypeModal() {
    document.getElementById('addTypeModal').classList.add('active');
}

function closeAddTypeModal() {
    document.getElementById('addTypeModal').classList.remove('active');
}

function confirmDeleteType(id, name) {
    if (confirm('Czy na pewno chcesz usunąć typ "' + name + '"?')) {
        window.location.href = '?page=map_config&delete_type=' + id;
    }
}

// Podgląd ikony i koloru
document.getElementById('typeIcon')?.addEventListener('input', function() {
    updatePreview();
});

document.getElementById('typeColor')?.addEventListener('input', function() {
    updatePreview();
});

function updatePreview() {
    const icon = document.getElementById('typeIcon').value || 'fa-question';
    const color = document.getElementById('typeColor').value;
    const preview = document.getElementById('typePreview');
    
    preview.innerHTML = '<i class="fas ' + icon + '"></i>';
    preview.style.color = color;
}

// Zamknij modal po kliknięciu poza nim
window.addEventListener('click', function(e) {
    const modal = document.getElementById('addTypeModal');
    if (e.target === modal) {
        closeAddTypeModal();
    }
});
</script>