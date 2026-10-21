<?php
error_log("devices.php załadowany, GET: " . print_r($_GET, true));
error_log("devices.php - pełne URL: " . ($_SERVER['REQUEST_URI'] ?? 'brak'));
// content/devices.php - Zarządzanie urządzeniami
global $db;
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Funkcja addMessage
if (!function_exists('addMessage')) {
    function addMessage($type, $text) {
        if (!isset($_SESSION['messages'])) $_SESSION['messages'] = [];
        if (count($_SESSION['messages']) >= 3) array_shift($_SESSION['messages']);
        $_SESSION['messages'][] = ['type' => $type, 'text' => $text];
    }
}
// WSZYSTKIE AKCJE MUSZĄ BYĆ PRZED JAKIMKOLWIEK OUTPUTEM!

// Eksport do CSV
if (isset($_GET['action']) && $_GET['action'] == 'export_printers') {
    ob_clean();
    include __DIR__ . '/../check_printers.php';
    
    $stmt = $db->query("SELECT * FROM printers ORDER BY name");
    $printers_export = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=printers_' . date('Y-m-d') . '.csv');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Nazwa', 'IP', 'Model', 'Typ', 'Nr Hees', 'Hala', 'Lokalizacja', 'Aktywna']);
    
    foreach ($printers_export as $p) {
        fputcsv($output, [
            $p['id'],
            $p['name'],
            $p['ip'],
            $p['model'] ?? '',
            $p['printer_type'] ?? 'mono',
            $p['host_number'] ?? '',
            $p['hall'] ?? 'K3',
            $p['location'] ?? '',
            $p['is_active'] ? 'Tak' : 'Nie'
        ]);
    }
    
    fclose($output);
    exit;
}

// Obsługa akcji save_printer
if (isset($_POST['save_printer']) && hasPermission('devices', isset($_POST['index']) && $_POST['index'] > 0 ? 'edit' : 'create')) {
    include __DIR__ . '/../check_printers.php';
    
    $name = trim($_POST['name'] ?? '');
    $ip = trim($_POST['ip'] ?? '');
    $host_number = trim($_POST['host_number'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $hall = trim($_POST['hall'] ?? 'K3');
    $model = trim($_POST['model'] ?? '');
    $printer_type = trim($_POST['printer_type'] ?? 'mono');
    $index = intval($_POST['index'] ?? 0);

    if ($name && $ip) {
        try {
            // Sprawdź duplikaty IP
            if ($index > 0) {
                $stmt_dup = $db->prepare("SELECT COUNT(*) FROM printers WHERE ip = ? AND id != ?");
                $stmt_dup->execute([$ip, $index]);
            } else {
                $stmt_dup = $db->prepare("SELECT COUNT(*) FROM printers WHERE ip = ?");
                $stmt_dup->execute([$ip]);
            }
            
            if ($stmt_dup->fetchColumn() > 0) {
                addMessage('error', 'Drukarka o tym adresie IP już istnieje');
                ob_clean();
                header("Location: index.php?page=devices");
                exit;
            }
            
            if ($index > 0) {
                // EDYCJA
                $stmt = $db->prepare("UPDATE printers SET name=?, ip=?, host_number=?, location=?, hall=?, model=?, printer_type=? WHERE id=?");
                $stmt->execute([$name, $ip, $host_number, $location, $hall, $model, $printer_type, $index]);
                $printer_id = $index;
                addMessage('success', 'Drukarka zaktualizowana');
                logActivity($_SESSION['user_id'], 'update_printer', 'devices', "Zaktualizowano drukarkę: $name");
            } else {
                // DODAWANIE
                $stmt = $db->prepare("INSERT INTO printers (name, ip, host_number, location, hall, model, printer_type, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->execute([$name, $ip, $host_number, $location, $hall, $model, $printer_type]);
                $printer_id = $db->lastInsertId();
                addMessage('success', 'Drukarka dodana');
                logActivity($_SESSION['user_id'], 'add_printer', 'devices', "Dodano drukarkę: $name");
            }

            // Automatyczny odczyt SNMP
            $data = getPrinterData($ip);
            $status = !empty($data) ? 'online' : 'offline';
            $materials_json = json_encode($data);
            $last_check = date('Y-m-d H:i:s');

            $stmt_check = $db->prepare("SELECT printer_id FROM printer_status WHERE printer_id = ?");
            $stmt_check->execute([$printer_id]);
            
            if ($stmt_check->fetchColumn()) {
                $stmt_status = $db->prepare("UPDATE printer_status SET status = ?, materials_json = ?, last_check = ? WHERE printer_id = ?");
                $stmt_status->execute([$status, $materials_json, $last_check, $printer_id]);
            } else {
                $stmt_status = $db->prepare("INSERT INTO printer_status (printer_id, status, materials_json, last_check) VALUES (?, ?, ?, ?)");
                $stmt_status->execute([$printer_id, $status, $materials_json, $last_check]);
            }
            
        } catch (PDOException $e) {
            addMessage('error', 'Błąd zapisu: ' . $e->getMessage());
            error_log("Printer save error: " . $e->getMessage());
        }
        
        ob_clean();
        header("Location: index.php?page=devices");
        exit;
    } else {
        addMessage('error', 'Nieprawidłowe dane');
        ob_clean();
        header("Location: index.php?page=devices");
        exit;
    }
}

// Obsługa akcji delete
if (isset($_GET['action']) && $_GET['action'] == 'delete' && hasPermission('devices', 'delete')) {
    include __DIR__ . '/../check_printers.php';
    
    $id = intval($_GET['id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $db->prepare("SELECT name FROM printers WHERE id=?");
            $stmt->execute([$id]);
            $printer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $db->prepare("DELETE FROM printer_status WHERE printer_id=?");
            $stmt->execute([$id]);
            
            $stmt = $db->prepare("DELETE FROM printers WHERE id=?");
            $stmt->execute([$id]);
            
            addMessage('success', 'Drukarka usunięta: ' . ($printer['name'] ?? "ID: $id"));
            logActivity($_SESSION['user_id'], 'delete_printer', 'devices', "Usunięto drukarkę: " . ($printer['name'] ?? ''));
        } catch (PDOException $e) {
            addMessage('error', 'Błąd usuwania: ' . $e->getMessage());
            error_log("Delete printer error: " . $e->getMessage());
        }
    }
    ob_clean();
    header("Location: index.php?page=devices");
    exit;
}

// Obsługa importu z CSV
if (isset($_POST['import_printers']) && hasPermission('devices', 'create')) {
    include __DIR__ . '/../check_printers.php';
    
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
        fgetcsv($file); // Pomiń nagłówek
        
        $imported = 0;
        while (($data = fgetcsv($file)) !== false) {
            if (count($data) >= 7) {
                try {
                    $stmt_check = $db->prepare("SELECT COUNT(*) FROM printers WHERE ip = ?");
                    $stmt_check->execute([$data[2]]);
                    
                    if ($stmt_check->fetchColumn() == 0) {
                        $stmt = $db->prepare("INSERT INTO printers (name, ip, model, printer_type, host_number, hall, location) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $data[1],
                            $data[2],
                            $data[3] ?? '',
                            $data[4] ?? 'mono',
                            $data[5] ?? '',
                            $data[6] ?? 'K3',
                            $data[7] ?? ''
                        ]);
                        $imported++;
                    }
                } catch (PDOException $e) {
                    error_log("Import error: " . $e->getMessage());
                }
            }
        }
        fclose($file);
        
        addMessage('success', "Zaimportowano $imported drukarek");
        logActivity($_SESSION['user_id'], 'import_printers', 'devices', "Zaimportowano $imported drukarek");
    } else {
        addMessage('error', 'Błąd wczytywania pliku');
    }
    ob_clean();
    header("Location: index.php?page=devices");
    exit;
}

// Obsługa masowego usuwania (AJAX)
if (isset($_POST['bulk_delete']) && hasPermission('devices', 'delete')) {
    $ids_json = $_POST['ids'] ?? '';
    $ids = json_decode($ids_json, true);
    if (is_array($ids) && !empty($ids)) {
        include __DIR__ . '/../check_printers.php';
        $deleted = 0;
        foreach ($ids as $id) {
            $id = intval($id);
            if ($id > 0) {
                try {
                    $stmt = $db->prepare("DELETE FROM printer_status WHERE printer_id = ?");
                    $stmt->execute([$id]);
                    $stmt = $db->prepare("DELETE FROM printers WHERE id = ?");
                    $stmt->execute([$id]);
                    $deleted++;
                } catch (PDOException $e) {
                    error_log("Błąd usuwania drukarki ID $id: " . $e->getMessage());
                }
            }
        }
        echo json_encode(['success' => true, 'deleted' => $deleted]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Brak ID do usunięcia']);
        exit;
    }
}

// Sprawdzenie akcji ręcznego sprawdzania statusu – ale to już nie będzie używane, bo mamy AJAX
// Pozostawiamy dla kompatybilności
if (isset($_GET['action']) && $_GET['action'] == 'check' && hasPermission('devices', 'view')) {
    // Jeśli to żądanie AJAX – obsłużymy w index.php
    // Tutaj tylko przekierujemy do index.php, ale lepiej zostawić puste
    header("Location: index.php?page=devices");
    exit;
}
// KONIEC SEKCJI AKCJI

// Pobierz drukarki (pierwsze ładowanie)
$stmt = $db->query("SELECT p.*, s.status, s.materials_json, s.last_check 
                    FROM printers p 
                    LEFT JOIN printer_status s ON p.id = s.printer_id 
                    ORDER BY CAST(p.host_number AS INTEGER)");
$all_printers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Funkcja renderBar (bez zmian)
function renderBar($level, $label = '') {
    if ($level === 'N/D' || !is_numeric($level)) {
        return '<span class="text-muted">N/D</span>';
    }
    $level = (int)$level;
    $color = '#22c55e';
    if ($level <= 15) $color = '#ef4444';
    elseif ($level <= 30) $color = '#f59e0b';
    return '<div class="bar-container">
                <div class="bar" style="width: 60px;">
                    <div class="bar-fill" style="width: ' . $level . '%; background: ' . $color . ';"></div>
                </div>
                <span class="bar-label">' . $level . '%</span>
            </div>';
}

$material_columns = [
    'black' => 'B.T',
    'cyan' => 'C.T',
    'magenta' => 'M.T',
    'yellow' => 'Y.T',
    'drum_black' => 'B.I.U',
    'drum_color' => 'C.I.U',
    'waste' => 'W.T.C',
    'fuser' => 'F.U',
    'transfer' => 'T.U',
    'maintenance' => 'M.K',
    'roller' => 'R.T'
];

$material_icons = [
    'black' => '⬛',
    'cyan' => '🟦',
    'magenta' => '🟪',
    'yellow' => '🟨',
    'drum_black' => '⚫',
    'drum_color' => '⚪',
    'waste' => '🗑️',
    'fuser' => '🔥',
    'transfer' => '🔵',
    'maintenance' => '🔧',
    'roller' => '🖨️'
];

$can_create = hasPermission('devices', 'create');
$can_edit = hasPermission('devices', 'edit');
$can_delete = hasPermission('devices', 'delete');
?>
<div class="dashboard-header">
    <h1>Urządzenia</h1>
    <p class="text-muted">Lista drukarek i stan materiałów eksploatacyjnych</p>
</div>

<?php
// Obliczamy liczniki dla filtrów (PHP 7.2 - bez strzałek)
$online_count = count(array_filter($all_printers, function($p) { return ($p['status'] ?? '') === 'online'; }));
$offline_count = count(array_filter($all_printers, function($p) { return ($p['status'] ?? '') === 'offline'; }));
?>

<!-- Filtry statusu (zakładki) umieszczone obok nagłówka "Lista drukarek" -->
<div class="table-header">
    <div style="display: flex; align-items: center; gap: 24px;">
        <h2>Lista drukarek</h2>
        <div class="status-filters">
            <button class="filter-btn active" data-filter="all">Wszystkie <span class="count"><?php echo count($all_printers); ?></span></button>
            <button class="filter-btn" data-filter="online">Online <span class="count"><?php echo $online_count; ?></span></button>
            <button class="filter-btn" data-filter="offline">Offline <span class="count"><?php echo $offline_count; ?></span></button>
        </div>
    </div>
    <div class="table-actions">
        <input type="text" class="search-input" placeholder="Szukaj..." id="searchInput">
        <?php if (hasPermission('devices', 'view')): ?>
        <button class="btn btn-secondary btn-sm" id="checkStatusBtn" title="Sprawdź status">
            <i class="fas fa-sync"></i>
        </button>
        <?php endif; ?>
        <a href="?page=devices&action=export_printers" class="btn btn-secondary btn-sm" title="Eksport do CSV">
            <i class="fas fa-file-csv"></i>
        </a>
        <?php if ($can_create): ?>
        <button class="btn btn-secondary btn-sm" onclick="openImportModal()" title="Importuj z CSV">
            <i class="fas fa-file-upload"></i>
        </button>
        <button class="btn btn-primary btn-sm" onclick="openAddModal()" title="Dodaj drukarkę">
            <i class="fas fa-plus"></i>
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($can_edit || $can_delete): ?>
<!-- Pasek narzędzi zaznaczenia (widoczny tylko gdy zaznaczono) -->
<div id="selectionToolbar" class="selection-toolbar" style="display: none;">
    <span class="selected-info"><span id="selectedCount">0</span> zaznaczonych</span>
    <?php if ($can_edit): ?>
    <button class="btn btn-secondary" onclick="editSelected()" id="editSelectedBtn" disabled>Edytuj</button>
    <?php endif; ?>
    <?php if ($can_delete): ?>
    <button class="btn btn-secondary" onclick="deleteSelected()" id="deleteSelectedBtn" disabled>Usuń zaznaczone</button>
    <?php endif; ?>
    <button class="btn btn-secondary" onclick="clearSelection()">Anuluj</button>
</div>
<?php endif; ?>

<!-- Tabela -->
<div class="table-card">
    <!-- Kontener z przewijaniem (tylko poziomy pasek na dole) -->
    <div class="table-responsive" id="tableResponsive">
        <table class="printer-table" id="printerTable">
            <thead>
                <tr>
                    <?php if ($can_edit || $can_delete): ?>
                    <th class="checkbox-col"><input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)"></th>
                    <?php endif; ?>
                    <th data-sort="index">#</th>
                    <th data-sort="name">NAZWA</th>
                    <th data-sort="model">MODEL</th>
                    <th data-sort="ip">IP</th>
                    <th data-sort="host_number">NR. HEES</th>
                    <th data-sort="hall">HALA</th>
                    <th data-sort="location">LOKALIZACJA</th>
                    <th data-sort="status">STATUS</th>
                    <?php foreach ($material_columns as $key => $label): ?>
                        <th data-sort="<?php echo $key; ?>" title="<?php echo $key; ?>" class="material-col">
                            <span class="material-icon"><?php echo $material_icons[$key] ?? ''; ?></span>
                            <span class="material-label"><?php echo $label; ?></span>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody id="printerTableBody">
                <?php foreach ($all_printers as $index => $printer): 
                    $materials = json_decode($printer['materials_json'] ?? '{}', true);
                    if (!is_array($materials)) $materials = [];
                    $is_online = $printer['status'] === 'online';
                    $printer_type = $printer['printer_type'] ?? 'mono';
                    $type_color = $printer_type === 'color' ? '#8b5cf6' : '#6b7280';
                ?>
                <tr data-id="<?php echo $printer['id']; ?>" data-status="<?php echo $printer['status'] ?? 'offline'; ?>">
                    <?php if ($can_edit || $can_delete): ?>
                    <td class="checkbox-col"><input type="checkbox" class="row-select" value="<?php echo $printer['id']; ?>" onchange="updateSelectionToolbar()"></td>
                    <?php endif; ?>
                    <td><?php echo $index + 1; ?></td>
                    <td>
                        <div class="printer-name">
                            <span class="type-indicator" style="background: <?php echo $type_color; ?>;"></span>
                            <strong><?php echo htmlspecialchars($printer['name']); ?></strong>
                        </div>
                    </td>
                    <td><?php echo htmlspecialchars($printer['model'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($printer['ip']); ?></td>
                    <td><?php echo htmlspecialchars($printer['host_number'] ?? '—'); ?></td>
                    <td><span class="badge badge-hall"><?php echo htmlspecialchars($printer['hall'] ?? 'K3'); ?></span></td>
                    <td><?php echo htmlspecialchars($printer['location'] ?? '—'); ?></td>
                    <td>
                        <span class="status-indicator <?php echo $is_online ? 'online' : 'offline'; ?>" title="<?php echo $is_online ? 'Online' : 'Offline'; ?>"></span>
                    </td>
                    <?php foreach (array_keys($material_columns) as $key): ?>
                        <td class="materials-cell"><?php echo renderBar($materials[$key] ?? 'N/D'); ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pojedynczy pasek przewijania poziomego na dole -->
    <div class="horizontal-scrollbar" id="fixedHorizontalScrollbar">
        <div class="scrollbar-track" id="scrollbarTrack"></div>
    </div>
</div>

<?php if ($can_create || $can_edit): ?>
<!-- Modal for Add/Edit Printer -->
<div class="modal" id="printerModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Dodaj drukarkę</h3>
            <button class="modal-close" onclick="closeModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="post" id="printerForm">
            <div class="modal-body">
                <input type="hidden" name="index" id="editIndex" value="0">
                
                <div class="form-group">
                    <label for="printerName">Nazwa drukarki *</label>
                    <input type="text" class="form-control" id="printerName" name="name" required>
                </div>
                
                <div class="form-group">
                    <label for="printerIP">Adres IP *</label>
                    <input type="text" class="form-control" id="printerIP" name="ip" required 
                           pattern="^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$">
                </div>
                
                <div class="form-group">
                    <label for="printerModel">Model drukarki</label>
                    <input type="text" class="form-control" id="printerModel" name="model">
                </div>
                
                <div class="form-group">
                    <label for="printerType">Typ drukarki *</label>
                    <select class="form-control" id="printerType" name="printer_type" required>
                        <option value="mono">Mono (czarno-biała)</option>
                        <option value="color">Kolor</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="host_number">Numer Hees</label>
                    <input type="text" class="form-control" id="host_number" name="host_number">
                </div>
                
                <div class="form-group">
                    <label for="hall">Hala</label>
                    <select class="form-control" id="hall" name="hall">
                        <option value="K3">K3</option>
                        <option value="K5">K5</option>
                        <option value="IT">IT</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="location">Lokalizacja</label>
                    <input type="text" class="form-control" id="location" name="location" 
                           placeholder="np. Księgowość, Formy, Kadry">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Anuluj</button>
                <button type="submit" name="save_printer" class="btn btn-primary">
                    <i class="fas fa-save"></i>
                    Zapisz
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal importu -->
<?php if ($can_create): ?>
<div class="modal" id="importModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Import drukarek z CSV</h3>
            <button class="modal-close" onclick="closeModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="post" enctype="multipart/form-data">
            <div class="modal-body">
                <div class="form-group">
                    <label for="csv_file">Plik CSV</label>
                    <input type="file" class="form-control" name="csv_file" accept=".csv" required>
                    <small class="text-muted">
                        Format: ID, Nazwa, IP, Model, Typ, Nr Hees, Hala, Lokalizacja<br>
                        Pobierz <a href="?page=devices&action=export_printers">przykładowy plik</a>
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeImportModal()">Anuluj</button>
                <button type="submit" name="import_printers" class="btn btn-primary">
                    <i class="fas fa-upload"></i>
                    Importuj
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Loading Spinner (ukryty domyślnie) -->
<div id="loadingSpinner" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.8); z-index: 9999; align-items: center; justify-content: center;">
    <div style="text-align: center;">
        <div class="spinner"></div>
        <p style="margin-top: 10px; color: #333;">Aktualizacja danych...</p>
    </div>
</div>

<script>
// Przekaż dane drukarek do JavaScript
let printersData = <?php echo json_encode($all_printers); ?>;
const canEdit = <?php echo $can_edit ? 'true' : 'false'; ?>;
const canDelete = <?php echo $can_delete ? 'true' : 'false'; ?>;

// Funkcje pomocnicze
function showLoadingSpinner() {
    document.getElementById('loadingSpinner').style.display = 'flex';
}

function hideLoadingSpinner() {
    document.getElementById('loadingSpinner').style.display = 'none';
}

function showMessage(type, text) {
    const msgDiv = document.createElement('div');
    msgDiv.className = `message ${type}`;
    msgDiv.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${text}`;
    msgDiv.style.cssText = 'position: fixed; top: 20px; right: 20px; padding: 15px 20px; border-radius: 8px; color: white; z-index: 10000; animation: slideIn 0.3s;';
    if (type === 'success') msgDiv.style.background = '#10b981';
    else msgDiv.style.background = '#ef4444';
    document.body.appendChild(msgDiv);
    setTimeout(() => msgDiv.remove(), 3000);
}

// Funkcja do odświeżania danych tabeli z serwera
function refreshTableData() {
    fetch('ajax_get_printers.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                printersData = data.printers;
                renderTable(printersData);
                updateStats();
            } else {
                showMessage('error', data.message || 'Błąd odświeżania danych');
            }
        })
        .catch(error => {
            console.error('Błąd:', error);
            showMessage('error', 'Błąd połączenia przy odświeżaniu danych');
        });
}

// Funkcja do sprawdzania statusu przez AJAX
function performCheck(showSpinner = true) {
    if (showSpinner) showLoadingSpinner();
    
    fetch('index.php?page=devices&action=check&ajax=1', {
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        return response.json();
    })
    .then(data => {
        if (data.success) {
            showMessage('success', data.message || `Sprawdzono ${data.count} drukarek`);
            // Po zakończeniu sprawdzania odśwież dane
            refreshTableData();
        } else {
            showMessage('error', data.message || 'Błąd podczas sprawdzania statusu');
        }
    })
    .catch(error => {
        console.error('Błąd:', error);
        showMessage('error', 'Wystąpił błąd połączenia z serwerem');
    })
    .finally(() => {
        if (showSpinner) hideLoadingSpinner();
    });
}

// Synchronizacja przewijania
document.addEventListener('DOMContentLoaded', function() {
    const horizontalContainer = document.getElementById('tableResponsive');
    const fixedScrollbar = document.getElementById('fixedHorizontalScrollbar');
    const scrollbarTrack = document.getElementById('scrollbarTrack');
    const table = document.getElementById('printerTable');
    
    if (table && scrollbarTrack) {
        const setTrackWidth = function() {
            scrollbarTrack.style.width = table.offsetWidth + 'px';
        };
        setTrackWidth();
        
        horizontalContainer.addEventListener('scroll', function() {
            fixedScrollbar.scrollLeft = horizontalContainer.scrollLeft;
        });
        
        fixedScrollbar.addEventListener('scroll', function() {
            horizontalContainer.scrollLeft = fixedScrollbar.scrollLeft;
        });
        
        window.addEventListener('resize', setTrackWidth);
    }

    // Inicjalizacja sortowania
    initSorting();
    
    // Inicjalizacja filtrów (zakładki)
    initFilters();
    
    // Ustaw przycisk "Sprawdź status"
    document.getElementById('checkStatusBtn').addEventListener('click', function(e) {
        e.preventDefault();
        performCheck(true);
    });
    
    // Wykonaj pierwsze sprawdzenie od razu (bez spinnnera, bo strona się ładuje)
    // Ale możemy pokazać spinner, aby użytkownik wiedział, że coś się dzieje
    performCheck(true);
    
    // Ustaw interwał co 5 minut (300000 ms)
    setInterval(() => performCheck(false), 300000);
});

// Zmienne do sortowania
let currentSort = { column: 'name', direction: 'asc' };

function initSorting() {
    document.querySelectorAll('th[data-sort]').forEach(th => {
        th.addEventListener('click', function() {
            const column = this.dataset.sort;
            const sortColumn = (column === 'index') ? 'index' : column;
            const direction = (currentSort.column === sortColumn && currentSort.direction === 'asc') ? 'desc' : 'asc';
            currentSort = { column: sortColumn, direction };
            sortTable(sortColumn, direction);
            updateSortIndicators(this, direction);
        });
    });
}

function sortTable(column, direction) {
    const sorted = [...printersData].sort((a, b) => {
        let valA, valB;

        if (column === 'index') {
            valA = printersData.indexOf(a) + 1;
            valB = printersData.indexOf(b) + 1;
            return direction === 'asc' ? valA - valB : valB - valA;
        }

        const tonerColumns = ['black', 'cyan', 'magenta', 'yellow', 'drum_black', 'drum_color', 'waste', 'fuser', 'transfer', 'maintenance', 'roller'];
        if (tonerColumns.includes(column)) {
            valA = getTonerLevel(a, column);
            valB = getTonerLevel(b, column);
            if (valA !== null && valB !== null) {
                return direction === 'asc' ? valA - valB : valB - valA;
            } else {
                if (valA === null) return 1;
                if (valB === null) return -1;
                return 0;
            }
        }

        valA = a[column] || '';
        valB = b[column] || '';

        if (column === 'host_number') {
            const numA = parseInt(valA) || 0;
            const numB = parseInt(valB) || 0;
            return direction === 'asc' ? numA - numB : numB - numA;
        }

        valA = String(valA).toLowerCase();
        valB = String(valB).toLowerCase();
        if (valA < valB) return direction === 'asc' ? -1 : 1;
        if (valA > valB) return direction === 'asc' ? 1 : -1;
        return 0;
    });

    renderTable(sorted);
}

function getTonerLevel(printer, color) {
    try {
        const materials = JSON.parse(printer.materials_json || '{}');
        const level = materials[color];
        return (level !== undefined && level !== null) ? parseInt(level) : null;
    } catch {
        return null;
    }
}

function renderTable(data) {
    const tbody = document.getElementById('printerTableBody');
    let html = '';

    data.forEach((printer, index) => {
        const materials = JSON.parse(printer.materials_json || '{}');
        const is_online = printer.status === 'online';
        const printer_type = printer.printer_type || 'mono';
        const type_color = printer_type === 'color' ? '#8b5cf6' : '#6b7280';
        
        // Określenie poziomu tonera dla atrybutów danych
        let tonerLevel = 'ok';
        for (let c of ['black','cyan','magenta','yellow']) {
            if (materials[c] !== undefined && !isNaN(materials[c])) {
                if (materials[c] <= 15) { tonerLevel = 'critical'; break; }
                else if (materials[c] <= 30) { tonerLevel = 'low'; }
            }
        }

        html += `<tr data-id="${printer.id}" data-status="${printer.status || 'offline'}" data-toner-level="${tonerLevel}">`;
        
        if (canEdit || canDelete) {
            html += `<td class="checkbox-col"><input type="checkbox" class="row-select" value="${printer.id}" onchange="updateSelectionToolbar()"></td>`;
        }
        
        html += `<td>${index + 1}</td>`;
        html += `<td>
            <div class="printer-name">
                <span class="type-indicator" style="background: ${type_color};"></span>
                <strong>${escapeHtml(printer.name)}</strong>
            </div>
        </td>`;
        html += `<td>${escapeHtml(printer.model || '—')}</td>`;
        html += `<td>${escapeHtml(printer.ip)}</td>`;
        html += `<td>${escapeHtml(printer.host_number || '—')}</td>`;
        html += `<td><span class="badge badge-hall">${escapeHtml(printer.hall || 'K3')}</span></td>`;
        html += `<td>${escapeHtml(printer.location || '—')}</td>`;
        html += `<td><span class="status-indicator ${is_online ? 'online' : 'offline'}" title="${is_online ? 'Online' : 'Offline'}"></span></td>`;

        const tonerColumns = ['black', 'cyan', 'magenta', 'yellow', 'drum_black', 'drum_color', 'waste', 'fuser', 'transfer', 'maintenance', 'roller'];
        tonerColumns.forEach(col => {
            const level = materials[col];
            html += `<td class="materials-cell">`;
            html += renderBarJs(level);
            html += `</td>`;
        });

        html += `</tr>`;
    });

    tbody.innerHTML = html;
    updateSelectionToolbar();
}

function renderBarJs(level) {
    if (level === undefined || level === null || level === 'N/D') {
        return '<span class="text-muted">N/D</span>';
    }
    level = parseInt(level);
    let color = '#22c55e';
    if (level <= 15) color = '#ef4444';
    else if (level <= 30) color = '#f97316';

    return `<div class="bar-container">
                <div class="bar" style="width: 60px;">
                    <div class="bar-fill" style="width: ${level}%; background: ${color};"></div>
                </div>
                <span class="bar-label">${level}%</span>
            </div>`;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function updateSortIndicators(clickedTh, direction) {
    document.querySelectorAll('th[data-sort]').forEach(th => {
        th.classList.remove('sort-asc', 'sort-desc');
    });
    clickedTh.classList.add(direction === 'asc' ? 'sort-asc' : 'sort-desc');
}

function updateStats() {
    // Aktualizacja liczników filtrów (opcjonalnie)
    // Można odświeżyć cały nagłówek, ale to już bardziej skomplikowane
}

// Funkcje zaznaczania i paska narzędzi
function toggleSelectAll(source) {
    const checkboxes = document.querySelectorAll('.row-select');
    checkboxes.forEach(cb => cb.checked = source.checked);
    updateSelectionToolbar();
}

function updateSelectionToolbar() {
    const checkboxes = document.querySelectorAll('.row-select:checked');
    const count = checkboxes.length;
    const toolbar = document.getElementById('selectionToolbar');
    const selectedCountSpan = document.getElementById('selectedCount');
    const editBtn = document.getElementById('editSelectedBtn');
    const deleteBtn = document.getElementById('deleteSelectedBtn');
    
    if (count > 0) {
        toolbar.style.display = 'flex';
        selectedCountSpan.textContent = count;
        
        if (editBtn) {
            editBtn.disabled = (count !== 1);
        }
        if (deleteBtn) {
            deleteBtn.disabled = false;
        }
    } else {
        toolbar.style.display = 'none';
    }
}

function clearSelection() {
    const checkboxes = document.querySelectorAll('.row-select');
    checkboxes.forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    updateSelectionToolbar();
}

function editSelected() {
    const selected = document.querySelectorAll('.row-select:checked');
    if (selected.length !== 1) {
        alert('Wybierz dokładnie jedną drukarkę do edycji.');
        return;
    }
    const id = selected[0].value;
    editPrinter(id);
}

function deleteSelected() {
    const selected = document.querySelectorAll('.row-select:checked');
    if (selected.length === 0) return;
    
    if (!confirm('Czy na pewno chcesz usunąć ' + selected.length + ' zaznaczonych drukarek?')) {
        return;
    }
    
    const ids = Array.from(selected).map(cb => cb.value);
    
    fetch('index.php?page=devices', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'bulk_delete=1&ids=' + encodeURIComponent(JSON.stringify(ids))
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert('Błąd usuwania: ' + (data.message || 'nieznany błąd'));
        }
    })
    .catch(error => {
        console.error('Błąd:', error);
        alert('Wystąpił błąd połączenia.');
    });
}

// Filtrowanie tabeli po statusie
function initFilters() {
    const filterBtns = document.querySelectorAll('.filter-btn');
    filterBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            filterBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            const filter = this.dataset.filter;
            const rows = document.querySelectorAll('#printerTableBody tr');
            
            rows.forEach(row => {
                if (filter === 'all') {
                    row.style.display = '';
                } else {
                    const status = row.dataset.status;
                    row.style.display = (status === filter) ? '' : 'none';
                }
            });
            
            clearSelection();
        });
    });
}

// Funkcje modali
function openImportModal() {
    document.getElementById('importModal').classList.add('active');
}

function closeImportModal() {
    document.getElementById('importModal').classList.remove('active');
}

function editPrinter(id) {
    const printer = printersData.find(p => p.id == id);
    if (!printer) return;
    
    document.getElementById('modalTitle').textContent = 'Edytuj drukarkę';
    document.getElementById('editIndex').value = printer.id;
    document.getElementById('printerName').value = printer.name || '';
    document.getElementById('printerIP').value = printer.ip || '';
    document.getElementById('printerModel').value = printer.model || '';
    document.getElementById('printerType').value = printer.printer_type || 'mono';
    document.getElementById('host_number').value = printer.host_number || '';
    document.getElementById('hall').value = printer.hall || 'K3';
    document.getElementById('location').value = printer.location || '';
    
    document.getElementById('printerModal').classList.add('active');
}

function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Dodaj drukarkę';
    document.getElementById('editIndex').value = 0;
    document.getElementById('printerForm').reset();
    document.getElementById('printerType').value = 'mono';
    document.getElementById('hall').value = 'K3';
    document.getElementById('printerModal').classList.add('active');
}

function closeModal() {
    document.getElementById('printerModal').classList.remove('active');
    document.getElementById('importModal').classList.remove('active');
}

// Wyszukiwanie
document.getElementById('searchInput').addEventListener('input', function(e) {
    const term = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#printerTableBody tr');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(term) ? '' : 'none';
    });
});
</script>

<style>
/* Globalne style */
:root {
    --bg-primary: #f8fafc;
    --card-bg: #ffffff;
    --border-color: #e2e8f0;
    --text-primary: #1e293b;
    --text-secondary: #64748b;
    --accent-color: #3b82f6;
    --online-color: #10b981;
    --offline-color: #94a3b8;
    --low-color: #f97316;
    --critical-color: #ef4444;
}

/* Force full width for devices page */
.main-content.devices-page {
    padding-left: 0 !important;
    padding-right: 0 !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
    max-width: 100% !important;
    width: 100% !important;
}

.table-card {
    background: var(--card-bg);
    border-top: 1px solid var(--border-color);
    border-bottom: 1px solid var(--border-color);
    width: 100% !important;
    max-width: none !important;
    margin: 0 !important;
    border-radius: 0 !important;
}

.table-responsive {
    overflow-x: auto;
    width: 100% !important;
}

.printer-table {
    width: 100% !important;
    border-collapse: collapse;
    table-layout: auto;
    min-width: 1200px;
}

/* Reszta stylów (bez zmian) */
body {
    background: var(--bg-primary);
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: var(--text-primary);
    margin: 0;
    padding: 0;
}

.dashboard-header {
    padding: 0 24px 20px 24px;
}
.dashboard-header h1 {
    font-size: 1.75rem;
    font-weight: 600;
    margin-bottom: 0.25rem;
}
.dashboard-header p {
    color: var(--text-secondary);
    font-size: 0.95rem;
}

.status-filters {
    display: flex;
    gap: 8px;
}
.filter-btn {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 30px;
    padding: 6px 16px;
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
}
.filter-btn .count {
    background: #f1f5f9;
    border-radius: 20px;
    padding: 2px 8px;
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--text-secondary);
}
.filter-btn.active {
    border-color: var(--accent-color);
    background: #f0f9ff;
    color: var(--accent-color);
}
.filter-btn.active .count {
    background: #dbeafe;
    color: var(--accent-color);
}
.filter-btn:hover {
    border-color: #cbd5e1;
    background: #f8fafc;
}

.selection-toolbar {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 12px 20px;
    margin: 0 24px 20px 24px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}
.selection-toolbar .selected-info {
    font-weight: 500;
    color: var(--text-primary);
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 500;
    line-height: 1;
    border: 1px solid transparent;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}
.btn-primary {
    background: var(--accent-color);
    color: white;
    border-color: var(--accent-color);
}
.btn-primary:hover {
    background: #2563eb;
    border-color: #2563eb;
}
.btn-secondary {
    background: var(--card-bg);
    border-color: var(--border-color);
    color: var(--text-secondary);
}
.btn-secondary:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: var(--text-primary);
}
.btn-sm {
    padding: 6px 12px;
    font-size: 0.85rem;
}
.btn:disabled {
    opacity: 0.5;
    pointer-events: none;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 24px;
    border-bottom: 1px solid var(--border-color);
    flex-wrap: wrap;
    gap: 16px;
}
.table-header h2 {
    font-size: 1.2rem;
    font-weight: 600;
    margin: 0;
    white-space: nowrap;
}
.table-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}
.search-input {
    padding: 8px 14px;
    border: 1px solid var(--border-color);
    border-radius: 30px;
    font-size: 0.9rem;
    width: 200px;
    background: #f8fafc;
    transition: all 0.2s;
}
.search-input:focus {
    outline: none;
    border-color: var(--accent-color);
    background: white;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
}

.printer-table th {
    background: #f8fafc;
    padding: 14px 16px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-secondary);
    border-bottom: 1px solid var(--border-color);
    white-space: nowrap;
}
.printer-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-primary);
    vertical-align: middle;
    white-space: nowrap;
}
.printer-table tbody tr:hover {
    background: #f8fafc;
}
.checkbox-col {
    width: 40px;
    text-align: center;
}
.printer-name {
    display: flex;
    align-items: center;
    gap: 8px;
}
.type-indicator {
    width: 4px;
    height: 24px;
    border-radius: 2px;
    flex-shrink: 0;
}
.badge-hall {
    background: #f1f5f9;
    color: var(--text-secondary);
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    white-space: nowrap;
}
.status-indicator {
    display: inline-block;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--offline-color);
    transition: background 0.2s;
    cursor: help;
}
.status-indicator.online {
    background: var(--online-color);
    box-shadow: 0 0 0 2px rgba(16,185,129,0.2);
}
.status-indicator.offline {
    background: var(--offline-color);
}
.bar-container {
    display: flex;
    align-items: center;
    gap: 8px;
}
.bar {
    height: 6px;
    background: #e2e8f0;
    border-radius: 3px;
    overflow: hidden;
    width: 60px;
}
.bar-fill {
    height: 100%;
    border-radius: 3px;
}
.bar-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-secondary);
    min-width: 35px;
}
.material-col {
    text-align: center;
}
.material-icon {
    display: block;
    font-size: 1.1rem;
    margin-bottom: 4px;
}
.material-label {
    font-size: 0.7rem;
    font-weight: 500;
}

th[data-sort] {
    cursor: pointer;
    position: relative;
    padding-right: 24px;
}
th[data-sort].sort-asc::after,
th[data-sort].sort-desc::after {
    content: '';
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    width: 0;
    height: 0;
    border-left: 4px solid transparent;
    border-right: 4px solid transparent;
}
th[data-sort].sort-asc::after {
    border-bottom: 5px solid var(--text-secondary);
    border-top: none;
}
th[data-sort].sort-desc::after {
    border-top: 5px solid var(--text-secondary);
    border-bottom: none;
}

.horizontal-scrollbar {
    overflow-x: auto;
    overflow-y: hidden;
    height: 12px;
    background: transparent;
    margin: 4px 24px 0 24px;
}
.scrollbar-track {
    height: 6px;
    background: #e2e8f0;
    border-radius: 3px;
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    align-items: center;
    justify-content: center;
    z-index: 1000;
}
.modal.active {
    display: flex;
}
.modal-content {
    background: white;
    border-radius: 16px;
    width: 100%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 40px rgba(0,0,0,0.1);
}
.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-header h3 {
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0;
}
.modal-close {
    background: none;
    border: none;
    font-size: 1.25rem;
    cursor: pointer;
    color: var(--text-secondary);
}
.modal-body {
    padding: 24px;
}
.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--border-color);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}
.form-group {
    margin-bottom: 20px;
}
.form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 500;
    font-size: 0.9rem;
    color: var(--text-secondary);
}
.form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 0.95rem;
    transition: all 0.2s;
}
.form-control:focus {
    outline: none;
    border-color: var(--accent-color);
    box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
}

/* Spinner */
.spinner {
    border: 4px solid #f3f3f3;
    border-top: 4px solid #3498db;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    animation: spin 1s linear infinite;
    margin: 0 auto;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

@media (max-width: 768px) {
    .table-header {
        flex-direction: column;
        align-items: flex-start;
    }
    .table-actions {
        width: 100%;
        justify-content: space-between;
    }
    .search-input {
        width: 100%;
    }
    .selection-toolbar {
        margin-left: 16px;
        margin-right: 16px;
    }
    .horizontal-scrollbar {
        margin-left: 16px;
        margin-right: 16px;
    }
}
</style>