<?php
// content/map.php - System mapy budynków (wersja MySQL)
global $db;

requirePermission('map', 'view');

$can_edit = hasPermission('map', 'edit');
$can_create = hasPermission('map', 'create');

// Sprawdź czy tabela map_objects istnieje (MySQL)
$stmt = $db->query("SHOW TABLES LIKE 'map_objects'");
$tableExists = $stmt->fetch();
if (!$tableExists) {
    createMapTables();
}

// Sprawdź czy tabela object_types istnieje
$stmt = $db->query("SHOW TABLES LIKE 'object_types'");
$objectTypesExists = $stmt->fetch();
if (!$objectTypesExists) {
    createObjectTypesTable();
}

// Pobierz typy obiektów
$stmt = $db->query("SELECT * FROM object_types ORDER BY name");
$object_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pobierz drukarki z bazy danych
$printers = [];
try {
    $stmt = $db->query("SHOW TABLES LIKE 'printers'");
    if ($stmt->fetch()) {
        $stmt = $db->query("SELECT p.*, s.status, s.materials_json, s.last_check 
                            FROM printers p 
                            LEFT JOIN printer_status s ON p.id = s.printer_id 
                            ORDER BY p.name");
        $printers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Błąd pobierania drukarek: " . $e->getMessage());
}

// Sprawdź strukturę tabeli map_objects (czy ma kolumnę printer_id)
$stmt = $db->query("SHOW COLUMNS FROM map_objects LIKE 'printer_id'");
$has_printer_id = $stmt->fetch() ? true : false;

// Pobierz obiekty z mapy
if ($has_printer_id) {
    $stmt = $db->query("SELECT mo.*, ot.name as type_name, ot.icon, ot.color, 
                        p.name as printer_name, p.ip as printer_ip, p.model as printer_model
                        FROM map_objects mo
                        LEFT JOIN object_types ot ON mo.type_code = ot.code
                        LEFT JOIN printers p ON mo.printer_id = p.id
                        ORDER BY mo.created_at DESC");
} else {
    $stmt = $db->query("SELECT mo.*, ot.name as type_name, ot.icon, ot.color 
                        FROM map_objects mo
                        LEFT JOIN object_types ot ON mo.type_code = ot.code
                        ORDER BY mo.created_at DESC");
}
$map_objects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pobierz szczegóły switchy jeśli istnieje tabela switch_ports
$switch_ports_exists = false;
$switches_with_ports = [];
try {
    $stmt = $db->query("SHOW TABLES LIKE 'switch_ports'");
    if ($stmt->fetch()) {
        $switch_ports_exists = true;
        // Pobierz wszystkie switche z mapy
        foreach ($map_objects as $obj) {
            if ($obj['type_code'] == 'switch') {
                // Pobierz porty dla tego switcha
                $stmt = $db->prepare("SELECT * FROM switch_ports WHERE switch_id = ? ORDER BY port_number");
                $stmt->execute([$obj['id']]);
                $ports = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $switches_with_ports[$obj['id']] = [
                    'device' => $obj,
                    'ports' => $ports,
                    'port_count' => count($ports)
                ];
            }
        }
    }
} catch (Exception $e) {
    error_log("Błąd pobierania portów switchy: " . $e->getMessage());
}

// Sprawdź czy tabela building_maps istnieje
$stmt = $db->query("SHOW TABLES LIKE 'building_maps'");
$building_maps_exists = $stmt->fetch();
$building_maps = [];
$building1_map = '';
$building2_map = '';

if ($building_maps_exists) {
    $stmt = $db->query("SELECT * FROM building_maps");
    $building_maps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($building_maps as $bm) {
        if ($bm['building_id'] == 1) $building1_map = $bm['map_image'] ?? '';
        if ($bm['building_id'] == 2) $building2_map = $bm['map_image'] ?? '';
    }
}

// Przygotuj obiekty według budynku
$building1_objects = [];
$building2_objects = [];
foreach ($map_objects as $obj) {
    if ($obj['building_id'] == 1) {
        $building1_objects[] = $obj;
    } else {
        $building2_objects[] = $obj;
    }
}

// Funkcja tworząca tabele (wersja MySQL)
function createMapTables() {
    global $db;
    
    try {
        // Tabela map_objects
        $db->exec("CREATE TABLE IF NOT EXISTS map_objects (
            id INT(11) PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            type_code VARCHAR(100) NOT NULL,
            building_id INT(11) NOT NULL DEFAULT 1,
            position_x DECIMAL(10,5) NOT NULL DEFAULT 50.0,
            position_y DECIMAL(10,5) NOT NULL DEFAULT 50.0,
            ip_address VARCHAR(45),
            location VARCHAR(255),
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            printer_id INT(11),
            description TEXT,
            FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE SET NULL
        )");
        
        // Sprawdź czy kolumna printer_id istnieje i dodaj jeśli nie ma
        $stmt = $db->query("SHOW COLUMNS FROM map_objects LIKE 'printer_id'");
        if (!$stmt->fetch()) {
            $db->exec("ALTER TABLE map_objects ADD COLUMN printer_id INT(11)");
        }
        
    } catch (Exception $e) {
        error_log("Błąd tworzenia tabel map_objects: " . $e->getMessage());
    }
}

function createObjectTypesTable() {
    global $db;
    
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS object_types (
            id INT(11) PRIMARY KEY AUTO_INCREMENT,
            code VARCHAR(100) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            icon VARCHAR(100) NOT NULL,
            color VARCHAR(20) NOT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Dodaj przykładowe typy
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
        
        $insert = $db->prepare("INSERT IGNORE INTO object_types (code, name, icon, color) VALUES (?, ?, ?, ?)");
        foreach ($types as $type) {
            $insert->execute($type);
        }
        
    } catch (Exception $e) {
        error_log("Błąd tworzenia tabel object_types: " . $e->getMessage());
    }
}

// Sprawdź czy istnieje tabela building_maps i utwórz jeśli nie
$stmt = $db->query("SHOW TABLES LIKE 'building_maps'");
if (!$stmt->fetch()) {
    $db->exec("CREATE TABLE IF NOT EXISTS building_maps (
        id INT(11) PRIMARY KEY AUTO_INCREMENT,
        building_id INT(11) NOT NULL UNIQUE,
        map_image VARCHAR(255),
        description TEXT,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}
?>
<!-- Reszta kodu HTML i JavaScript pozostaje bez zmian -->
<!-- ... (kontynuacja oryginalnego pliku od miejsca, gdzie zaczyna się HTML) ... -->

<div class="dashboard-header">
    <h1><i class="fas fa-map-marked-alt"></i> Mapa Budynków</h1>
    <p class="text-muted">Interaktywna mapa lokalizacji urządzeń</p>
</div>

<!-- Główny kontener -->
<div class="map-fullscreen-container">
    <!-- Zwinięty panel kontrolny -->
    <div class="map-controls-collapsed" id="controlsCollapsed">
        <button class="btn btn-primary btn-sm" onclick="toggleControls()">
            <i class="fas fa-sliders-h"></i> Pokaż kontrolki
        </button>
        <div class="quick-filters">
            <span class="filter-badge active" onclick="showFilters()">
                <i class="fas fa-filter"></i> Filtry
            </span>
            <?php if ($can_create): ?>
            <span class="filter-badge" onclick="showToolbox()">
                <i class="fas fa-toolbox"></i> Przybornik
            </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Rozwinięty panel kontrolny (domyślnie ukryty) -->
    <div class="map-controls-expanded" id="controlsExpanded" style="display: none;">
        <div class="controls-header">
            <h5><i class="fas fa-sliders-h"></i> Kontrolki mapy</h5>
            <button class="btn btn-outline btn-sm" onclick="toggleControls()">
                <i class="fas fa-times"></i> Zamknij
            </button>
        </div>
        
        <div class="controls-tabs">
            <div class="tab active" data-tab="filters">
                <i class="fas fa-filter"></i> Filtry
            </div>
            <?php if ($can_create): ?>
            <div class="tab" data-tab="toolbox">
                <i class="fas fa-toolbox"></i> Przybornik
            </div>
            <?php endif; ?>
            <div class="tab" data-tab="info">
                <i class="fas fa-info-circle"></i> Informacje
            </div>
        </div>

        <!-- Zakładka Filtry -->
        <div class="tab-content active" id="tabFilters">
            <div class="filters-compact">
                <p class="text-muted small mb-2">Wybierz typy urządzeń do wyświetlenia:</p>
                <div class="filter-checkboxes">
                    <?php foreach ($object_types as $type): ?>
                        <label class="filter-checkbox-label">
                            <input type="checkbox" 
                                   class="filter-checkbox" 
                                   value="<?php echo $type['code']; ?>"
                                   checked>
                            <span class="filter-icon-small" style="color: <?php echo htmlspecialchars($type['color']); ?>;">
                                <i class="fas <?php echo htmlspecialchars($type['icon']); ?>"></i>
                            </span>
                            <span class="filter-text-small"><?php echo htmlspecialchars($type['name']); ?></span>
                            <span class="filter-count" id="count_<?php echo $type['code']; ?>">0</span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Zakładka Przybornik -->
        <?php if ($can_create): ?>
        <div class="tab-content" id="tabToolbox">
            <p class="text-muted small mb-2">Wybierz typ urządzenia:</p>
            <div class="form-group mb-3">
                <select class="form-control form-control-sm" id="toolboxTypeSelect" onchange="changeToolboxType(this.value)">
                    <option value="">— Wybierz typ urządzenia —</option>
                    <?php foreach ($object_types as $type): ?>
                        <option value="<?php echo htmlspecialchars($type['code']); ?>">
                            <?php echo htmlspecialchars($type['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <?php if (count($printers) > 0): ?>
            <div id="printerSelection" style="display: none;">
                <p class="text-muted small mb-2">Wybierz drukarkę:</p>
                <div class="form-group mb-3">
                    <select class="form-control form-control-sm" id="printerSelect">
                        <option value="">— Wybierz drukarkę —</option>
                        <?php foreach ($printers as $printer): ?>
                            <option value="<?php echo $printer['id']; ?>">
                                <?php echo htmlspecialchars($printer['name'] . ' (' . $printer['ip'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="toolbox-compact" id="toolboxItems">
                <p class="text-muted small">Wybierz typ urządzenia z listy powyżej</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Zakładka Informacje -->
        <div class="tab-content" id="tabInfo">
            <div class="info-panel">
                <div class="info-item">
                    <span class="info-label">Status:</span>
                    <span class="info-value" id="modeStatus">Przeglądanie</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Zoom:</span>
                    <span class="info-value" id="zoomLevel">100%</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Urządzenia:</span>
                    <span class="info-value" id="objectCount">0</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Budynek:</span>
                    <span class="info-value" id="currentBuilding">K5</span>
                </div>
            </div>
            
            <div class="quick-actions-panel">
                <button class="btn btn-outline btn-sm btn-block" onclick="switchBuilding(1)">
                    <i class="fas fa-building"></i> K5
                </button>
                <button class="btn btn-outline btn-sm btn-block" onclick="switchBuilding(2)">
                    <i class="fas fa-building"></i> K3
                </button>
                <button class="btn btn-outline btn-sm btn-block" onclick="fitMapToContainer()">
                    <i class="fas fa-expand"></i> Dopasuj
                </button>
                <button class="btn btn-outline btn-sm btn-block" onclick="centerMap()">
                    <i class="fas fa-crosshairs"></i> Wyśrodkuj
                </button>
                <button class="btn btn-outline btn-sm btn-block" onclick="toggleGrid()" id="gridToggle">
                    <i class="fas fa-th"></i> Siatka <span id="gridSizeDisplay">(10px)</span>
                </button>
                <button class="btn btn-outline btn-sm btn-block" onclick="toggleSnap()" id="snapToggle">
                    <i class="fas fa-magnet"></i> Przyciąganie <span id="snapStatus">ON</span>
                </button>
                <div class="grid-settings" style="margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                    <label style="font-size: 11px; color: #6c757d; margin-bottom: 5px; display: block;">Rozmiar siatki:</label>
                    <input type="range" id="gridSizeSlider" min="5" max="50" value="10" step="5" style="width: 100%;" onchange="changeGridSize(this.value)">
                </div>
            </div>
        </div>
    </div>

    <!-- Kontener mapy -->
    <div class="map-container-fullscreen" id="mainMapContainer">
        <!-- Drop zone -->
        <div class="drop-zone-fullscreen" id="dropZone">
            <i class="fas fa-plus"></i>
            <span>Upuść tutaj</span>
        </div>

        <!-- Mapa budynku 1 -->
        <div class="map-canvas-fullscreen active" id="mapCanvas1">
            <?php if ($building1_map): ?>
                <div class="map-viewport-fullscreen" id="mapViewport1">
                    <img src="<?php echo htmlspecialchars($building1_map); ?>" 
                         class="map-image-fullscreen" 
                         alt="K5"
                         id="mapImage1"
                         onload="handleMapImageLoad()"
                         onerror="console.error('Nie udało się załadować mapy budynku 1')"
                         style="display: block; max-width: 100%; height: auto;">
                    
                    <?php foreach ($building1_objects as $obj): ?>
                        <div class="map-device-fullscreen 
                            <?php if ($obj['type_code'] == 'switch'): ?>map-device-switch<?php endif; ?>" 
                             data-id="<?php echo $obj['id']; ?>"
                             data-type="<?php echo htmlspecialchars($obj['type_code']); ?>"
                             data-name="<?php echo htmlspecialchars($obj['name']); ?>"
                             data-building="<?php echo $obj['building_id']; ?>"
                             data-editing="false"
                             style="left: <?php echo $obj['position_x']; ?>%; top: <?php echo $obj['position_y']; ?>%;"
                             oncontextmenu="showContextMenu(event, <?php echo $obj['id']; ?>)"
                             onclick="handleDeviceClick(event, <?php echo $obj['id']; ?>, '<?php echo htmlspecialchars($obj['type_code']); ?>')">
                            <div class="device-icon-fullscreen" style="color: <?php echo htmlspecialchars($obj['color']); ?>;">
                                <i class="fas <?php echo htmlspecialchars($obj['icon']); ?>"></i>
                            </div>
                            <div class="device-label-fullscreen">
                                <?php echo htmlspecialchars($obj['name']); ?>
                                <?php if (isset($obj['printer_name']) && $obj['printer_name']): ?>
                                    <small style="display: block; font-size: 9px;">(<?php echo htmlspecialchars($obj['printer_name']); ?>)</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-map-fullscreen">
                    <i class="fas fa-image"></i>
                    <h4>Brak mapy budynku</h4>
                    <p>Wgraj plan budynku w konfiguracji</p>
                    <?php if (hasPermission('config', 'edit')): ?>
                        <a href="index.php?page=map_config" class="btn btn-primary mt-2">
                            <i class="fas fa-upload"></i> Wgraj mapę
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Mapa budynku 2 -->
        <div class="map-canvas-fullscreen" id="mapCanvas2">
            <?php if ($building2_map): ?>
                <div class="map-viewport-fullscreen" id="mapViewport2">
                    <img src="<?php echo htmlspecialchars($building2_map); ?>" 
                         class="map-image-fullscreen" 
                         alt="K3"
                         id="mapImage2"
                         onload="handleMapImageLoad()"
                         onerror="console.error('Nie udało się załadować mapy budynku 2')"
                         style="display: block; max-width: 100%; height: auto;">
                    
                    <?php foreach ($building2_objects as $obj): ?>
                        <div class="map-device-fullscreen 
                            <?php if ($obj['type_code'] == 'switch'): ?>map-device-switch<?php endif; ?>" 
                             data-id="<?php echo $obj['id']; ?>"
                             data-type="<?php echo htmlspecialchars($obj['type_code']); ?>"
                             data-name="<?php echo htmlspecialchars($obj['name']); ?>"
                             data-building="<?php echo $obj['building_id']; ?>"
                             data-editing="false"
                             style="left: <?php echo $obj['position_x']; ?>%; top: <?php echo $obj['position_y'] ?>%;"
                             oncontextmenu="showContextMenu(event, <?php echo $obj['id']; ?>)"
                             onclick="handleDeviceClick(event, <?php echo $obj['id']; ?>, '<?php echo htmlspecialchars($obj['type_code']); ?>')">
                            <div class="device-icon-fullscreen" style="color: <?php echo htmlspecialchars($obj['color']); ?>;">
                                <i class="fas <?php echo htmlspecialchars($obj['icon']); ?>"></i>
                            </div>
                            <div class="device-label-fullscreen">
                                <?php echo htmlspecialchars($obj['name']); ?>
                                <?php if (isset($obj['printer_name']) && $obj['printer_name']): ?>
                                    <small style="display: block; font-size: 9px;">(<?php echo htmlspecialchars($obj['printer_name']); ?>)</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-map-fullscreen">
                    <i class="fas fa-image"></i>
                    <h4>Brak mapy budynku</h4>
                    <p>Wgraj plan budynku w konfiguracji</p>
                    <?php if (hasPermission('config', 'edit')): ?>
                        <a href="index.php?page=map_config" class="btn btn-primary mt-2">
                            <i class="fas fa-upload"></i> Wgraj mapę
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Kontrolki na mapie -->
        <div class="map-buttons-container">
            <?php if ($can_create): ?>
            <button class="map-button-add" onclick="openAddDeviceModal()" title="Dodaj urządzenie">
                <i class="fas fa-plus"></i>
            </button>
            <?php endif; ?>
            <button class="map-button-zoom" id="zoomIn" title="Przybliż">
                <i class="fas fa-search-plus"></i>
            </button>
            <button class="map-button-zoom" id="zoomOut" title="Oddal">
                <i class="fas fa-search-minus"></i>
            </button>
            <button class="map-button-reset" id="resetZoom" title="Resetuj zoom">
                <i class="fas fa-compress"></i>
            </button>
        </div>

        <!-- Legenda -->
        <div class="legend-minimal">
            <div class="legend-toggle" onclick="toggleLegend()">
                <i class="fas fa-key"></i>
            </div>
            <div class="legend-content" style="display: none;">
                <?php foreach ($object_types as $type): ?>
                    <div class="legend-item-minimal">
                        <span class="legend-dot" style="background: <?php echo htmlspecialchars($type['color']); ?>;"></span>
                        <span class="legend-text-minimal"><?php echo htmlspecialchars($type['name']); ?></span>
                    </div>
                <?php endforeach; ?>
                </div>
        </div>
    </div>

    <!-- Wyszukiwarka -->
    <div class="search-minimal">
        <div class="search-input-minimal">
            <i class="fas fa-search"></i>
            <input type="text" 
                   id="objectSearch" 
                   placeholder="Wyszukaj urządzenie..."
                   class="search-input">
        </div>
        <div class="search-results-minimal" id="searchResults"></div>
    </div>
</div>

<!-- Kontekstowe menu -->
<div class="context-menu-minimal" id="contextMenu">
    <div class="context-item" onclick="viewDeviceDetails()">
        <i class="fas fa-eye"></i> Podgląd
    </div>
    <div class="context-item" onclick="editDeviceData()">
        <i class="fas fa-edit"></i> Edytuj dane
    </div>
    <div class="context-item" onclick="enablePositionEditing()">
        <i class="fas fa-arrows-alt"></i> Edytuj pozycję
    </div>
    <div class="context-item delete" onclick="openDeleteModal()">
        <i class="fas fa-trash"></i> Usuń
    </div>
</div>

<!-- Modal dodawania/edycji urządzenia -->
<div class="modal" id="addDeviceModal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h5><i class="fas fa-plus-circle"></i> <span id="modalTitle">Dodaj urządzenie</span></h5>
            <button class="modal-close" onclick="closeAddDeviceModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="addDeviceForm">
            <input type="hidden" id="deviceId" name="id" value="0">
            <div class="modal-body">
                <div class="form-group">
                    <label for="deviceName" class="form-label">Nazwa urządzenia *</label>
                    <input type="text" class="form-control" id="deviceName" name="name" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="deviceType" class="form-label">Typ urządzenia *</label>
                        <select class="form-control" id="deviceType" name="type_code" required onchange="togglePrinterSelection()">
                            <option value="">— Wybierz typ —</option>
                            <?php foreach ($object_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type['code']); ?>">
                                    <?php echo htmlspecialchars($type['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="deviceBuilding" class="form-label">Budynek *</label>
                        <select class="form-control" id="deviceBuilding" name="building_id" required>
                            <option value="1">K5</option>
                            <option value="2">K3</option>
                        </select>
                    </div>
                </div>
                
                <?php if (count($printers) > 0): ?>
                <div class="form-group" id="printerSelectionField" style="display: none;">
                    <label for="devicePrinter" class="form-label">Wybierz drukarkę</label>
                    <select class="form-control" id="devicePrinter" name="printer_id">
                        <option value="">— Wybierz drukarkę —</option>
                        <?php foreach ($printers as $printer): ?>
                            <option value="<?php echo $printer['id']; ?>">
                                <?php echo htmlspecialchars($printer['name'] . ' (' . $printer['ip'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="deviceIP" class="form-label">Adres IP</label>
                    <input type="text" class="form-control" id="deviceIP" name="ip_address" placeholder="np. 192.168.1.100">
                </div>
                <div class="form-group">
                    <label for="deviceDescription" class="form-label">Opis</label>
                    <textarea class="form-control" id="deviceDescription" name="description" rows="2"></textarea>
                </div>
                <input type="hidden" id="deviceX" name="position_x" value="50">
                <input type="hidden" id="deviceY" name="position_y" value="50">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeAddDeviceModal()">Anuluj</button>
                <button type="submit" class="btn btn-primary" id="modalSubmitBtn">Dodaj urządzenie</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal potwierdzenia usunięcia -->
<div class="modal" id="deleteConfirmationModal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h5><i class="fas fa-exclamation-triangle"></i> Potwierdzenie usunięcia</h5>
            <button class="modal-close" onclick="closeDeleteModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <p>Czy na pewno chcesz usunąć urządzenie "<span id="deviceToDeleteName"></span>"?</p>
            <p class="text-muted small">Tej operacji nie można cofnąć.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeDeleteModal()">Anuluj</button>
            <button type="button" class="btn btn-danger" onclick="confirmDeleteDevice()">Usuń</button>
        </div>
    </div>
</div>

<!-- Panel podpowiedzi edycji pozycji -->
<div id="positionEditingHint" style="display: none; position: fixed; top: 20px; left: 50%; transform: translateX(-50%); background: #2563eb; color: white; padding: 10px 20px; border-radius: 6px; z-index: 10000; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
    <span>Tryb edycji pozycji włączony. Przeciągnij urządzenie, a następnie kliknij przycisk "Zakończ edycję".</span>
    <button onclick="disablePositionEditing()" style="margin-left: 15px; padding: 4px 12px; background: white; color: #2563eb; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">
        Zakończ edycję
    </button>
</div>

<!-- Modal szczegółów switcha -->
<div class="modal" id="switchDetailsModal" style="display: none;">
    <div class="modal-content" style="max-width: min(1000px, 95vw);">
        <div class="modal-header">
            <h5><i class="fas fa-network-wired"></i> <span id="switchModalTitle">Szczegóły Switcha</span></h5>
            <button class="modal-close" onclick="closeSwitchModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" style="padding: 0; max-height: min(70vh, 600px); overflow-y: auto;">
            <div id="switchDetailsContent">
                <!-- Tu będzie wczytywana zawartość z ajax/switch_details.php -->
                <div style="padding: 40px; text-align: center;">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p>Ładowanie szczegółów switcha...</p>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeSwitchModal()">Zamknij</button>
            <button type="button" class="btn btn-primary" onclick="editSwitchPorts()">Edytuj porty</button>
        </div>
    </div>
</div>

<!-- Modal edycji portów switcha -->
<div class="modal" id="editSwitchPortsModal" style="display: none;">
    <div class="modal-content" style="max-width: min(1200px, 95vw);">
        <div class="modal-header">
            <h5><i class="fas fa-edit"></i> Edycja portów switcha: <span id="editSwitchTitle"></span></h5>
            <button class="modal-close" onclick="closeEditSwitchModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" style="padding: 20px; max-height: min(70vh, 600px); overflow-y: auto;">
            <div id="editSwitchPortsContent">
                <!-- Tu będzie formularz edycji portów -->
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeEditSwitchModal()">Anuluj</button>
            <button type="button" class="btn btn-primary" onclick="saveSwitchPorts()">Zapisz zmiany</button>
        </div>
    </div>
</div>

<style>
/* Styl dla urządzeń typu switch */
.map-device-switch {
    cursor: pointer;
}

.map-device-switch:hover .device-icon-fullscreen {
    transform: scale(1.1);
    box-shadow: 0 0 10px rgba(139, 92, 246, 0.5);
}

/* Styl dla modal switcha */
.ports-container {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 20px;
    max-height: 500px;
    overflow-y: auto;
    padding: 10px;
}

.port-item {
    width: 80px;
    padding: 8px;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
}

.port-item:hover {
    border-color: #8b5cf6;
    background-color: #f5f3ff;
}

.port-number {
    font-weight: bold;
    font-size: 14px;
    color: #374151;
}

.port-status {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    margin: 5px auto;
}

.port-status.up {
    background-color: #10b981;
}

.port-status.down {
    background-color: #ef4444;
}

.port-status.disabled {
    background-color: #9ca3af;
}

.port-description {
    font-size: 11px;
    color: #6b7280;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.port-tooltip {
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: #1f2937;
    color: white;
    padding: 6px 10px;
    border-radius: 4px;
    font-size: 12px;
    white-space: nowrap;
    z-index: 1000;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.2s;
    margin-bottom: 5px;
}

.port-item:hover .port-tooltip {
    opacity: 1;
    visibility: visible;
}

/* Styl dla wizualizacji switcha */
.switch-visualization {
    display: flex;
    align-items: center;
    gap: 30px;
    padding: 20px;
}

.switch-image {
    flex: 0 0 300px;
    text-align: center;
}

.switch-image img {
    max-width: 100%;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.switch-ports {
    flex: 1;
}

.port-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 10px;
}

@media (max-width: 900px) {
    .switch-visualization {
        flex-direction: column;
    }
    
    .port-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

/* Styl dla linii pomocniczych - ZMIENIONE */
.guideline {
    position: absolute;
    z-index: 998;
    pointer-events: none;
    background: rgba(255, 0, 0, 0.5);
}

.guideline-vertical {
    left: 50%;
    top: 0;
    bottom: 0;
    width: 1px;
}

.guideline-horizontal {
    top: 50%;
    left: 0;
    right: 0;
    height: 1px;
}

.btn.active {
    background-color: var(--primary);
    color: white;
}

/* Siatka pomocnicza - NOWE STYLE */
.grid-overlay {
    pointer-events: none;
    z-index: 1;
}

.grid-dot {
    position: absolute;
    width: 3px;
    height: 3px;
    background: rgba(0, 150, 255, 0.3);
    border-radius: 50%;
    transform: translate(-50%, -50%);
    pointer-events: none;
}

.grid-line-vertical {
    position: absolute;
    width: 1px;
    height: 100%;
    background: rgba(0, 150, 255, 0.1);
    pointer-events: none;
    z-index: 1;
}

.grid-line-horizontal {
    position: absolute;
    height: 1px;
    width: 100%;
    background: rgba(0, 150, 255, 0.1);
    pointer-events: none;
    z-index: 1;
}

/* Podpowiedź przy przeciąganiu */
.snap-hint {
    position: absolute;
    background: rgba(37, 99, 235, 0.9);
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    z-index: 1000;
    white-space: nowrap;
}

/* Kropki siatki */
.grid-dot-snap {
    position: absolute;
    width: 6px;
    height: 6px;
    background: rgba(0, 100, 255, 0.5);
    border-radius: 50%;
    transform: translate(-50%, -50%);
    pointer-events: none;
    z-index: 997;
    box-shadow: 0 0 3px rgba(0, 100, 255, 0.7);
}

/* Styl dla przeciąganego urządzenia */
.map-device-fullscreen.dragging {
    opacity: 0.8;
    z-index: 10000 !important;
    transition: none;
}

.map-device-fullscreen.boundary-limit {
    animation: boundaryWarning 0.5s ease;
}

@keyframes boundaryWarning {
    0%, 100% { transform: translate(-50%, -50%); }
    25% { transform: translate(-50%, -50%) translateX(-2px); }
    75% { transform: translate(-50%, -50%) translateX(2px); }
}
</style>

<script>
// Dane
const mapObjects = <?php echo json_encode($map_objects); ?>;
const objectTypes = <?php echo json_encode($object_types); ?>;
const printersData = <?php echo json_encode($printers); ?>;
const switchesWithPorts = <?php echo json_encode($switches_with_ports); ?>;
const hasPrinterId = <?php echo $has_printer_id ? 'true' : 'false'; ?>;
const canEdit = <?php echo $can_edit ? 'true' : 'false'; ?>;
const canCreate = <?php echo $can_create ? 'true' : 'false'; ?>;
const switchPortsExists = <?php echo $switch_ports_exists ? 'true' : 'false'; ?>;

// Stan aplikacji
let currentBuilding = 1;
let zoom = 1;
let panX = 0;
let panY = 0;
let isDragging = false;
let draggedObject = null;
let isPanning = false;
let lastMouseX = 0;
let lastMouseY = 0;
let selectedDeviceId = null;
let selectedDeviceName = '';
let isDraggingFromToolbox = false;
let activeFilters = [];
let isPositionEditingMode = false;
let isDraggingDevice = false;
let dragStartX = 0;
let dragStartY = 0;
let deviceStartX = 0;
let deviceStartY = 0;
let currentSwitchId = null;

// Nowe zmienne dla siatki i przyciągania
let showGrid = false;
let snapToGrid = true;
let snapToObjects = true;
let gridSize = 10; // Rozmiar siatki w pikselach (na poziomie zoom=1)
let gridSizeSlider = 10;
let snapDistance = 5; // 5px odległości dla przyciągania
let gridLines = [];
let gridDots = [];

// Inicjalizacja
document.addEventListener('DOMContentLoaded', function() {
    initMap();
    initSearch();
    initFilters();
    if (canCreate) {
        initToolbox();
    }
    updateFilterCounts();
    updateDeviceCount();
    
    // Automatyczne dopasowanie mapy po załadowaniu - POPRAWIONE
    setTimeout(() => {
        autoFitMap(true);
    }, 100);
    
    // Stworzenie siatki
    createGrid();
    
    window.addEventListener('resize', function() {
        setTimeout(() => {
            autoFitMap(false);
            createGrid();
        }, 100);
    });
    
    document.addEventListener('click', hideContextMenu);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && isPositionEditingMode) {
            disablePositionEditing();
        }
    });
    
    // Inicjalizacja drag & drop dla urządzeń
    initDeviceDragAndDrop();
    
    // Aktualizuj wyświetlanie rozmiaru siatki
    document.getElementById('gridSizeDisplay').textContent = `(${gridSize}px)`;
    document.getElementById('gridSizeSlider').value = gridSizeSlider;
});

// Automatyczne dopasowanie mapy - POPRAWIONE WERSJA
function autoFitMap(forceFit = false) {
    const activeMap = document.querySelector('.map-canvas-fullscreen.active');
    if (!activeMap) return;
    
    const img = activeMap.querySelector('.map-image-fullscreen');
    if (!img) return;
    
    // Jeśli obrazek jest już załadowany
    if (img.complete && img.naturalWidth > 0) {
        performFit();
    } else {
        // Poczekaj na załadowanie obrazka
        img.addEventListener('load', performFit, { once: true });
        
        // Fallback timeout
        setTimeout(() => {
            if (img.complete) {
                performFit();
            }
        }, 1000);
    }
    
    function performFit() {
        fitMapToContainer();
        updateZoomLevel();
        
        // Odśwież siatkę po dopasowaniu
        setTimeout(() => {
            createGrid();
        }, 50);
    }
}

function debugGridPosition(x, y, buildingId) {
    console.log('Pozycja w pikselach:', x, y);
    console.log('Budynek:', buildingId);
    console.log('Zoom:', zoom);
    console.log('Pan:', panX, panY);
    console.log('Rozmiar siatki:', gridSize);
    
    const activeMap = document.querySelector(`#mapCanvas${buildingId}`);
    const img = activeMap?.querySelector('.map-image-fullscreen');
    if (img) {
        console.log('Rozmiar obrazka:', img.naturalWidth, img.naturalHeight);
    }
}

// Funkcja wywoływana po załadowaniu obrazka mapy
function handleMapImageLoad() {
    // Po załadowaniu obrazka, dopasuj mapę z użyciem requestAnimationFrame
    requestAnimationFrame(() => {
        autoFitMap(true);
        createGrid();
    });
}

// Przełączanie panelu kontrolnego
function toggleControls() {
    const collapsed = document.getElementById('controlsCollapsed');
    const expanded = document.getElementById('controlsExpanded');
    
    if (expanded.style.display === 'none') {
        collapsed.style.display = 'none';
        expanded.style.display = 'block';
    } else {
        expanded.style.display = 'none';
        collapsed.style.display = 'flex';
    }
}

function showFilters() {
    toggleControls();
    switchTab('filters');
}

function showToolbox() {
    toggleControls();
    switchTab('toolbox');
}

function switchTab(tabName) {
    document.querySelectorAll('.tab').forEach(tab => {
        tab.classList.remove('active');
        if (tab.dataset.tab === tabName) {
            tab.classList.add('active');
        }
    });
    
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
        if (content.id === 'tab' + tabName.charAt(0).toUpperCase() + tabName.slice(1)) {
            content.classList.add('active');
        }
    });
}

// Inicjalizacja mapy
function initMap() {
    document.getElementById('zoomIn').addEventListener('click', () => changeZoom(0.2));
    document.getElementById('zoomOut').addEventListener('click', () => changeZoom(-0.2));
    document.getElementById('resetZoom').addEventListener('click', () => {
        fitMapToContainer();
    });
    
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', function() {
            switchTab(this.dataset.tab);
        });
    });
    
    // Obsługa przeciągania mapy
    const mapContainer = document.querySelector('.map-container-fullscreen');
    mapContainer.addEventListener('mousedown', startMapPanning);
    document.addEventListener('mousemove', dragMap);
    document.addEventListener('mouseup', stopMapPanning);
}

function startMapPanning(e) {
    // Jeśli jest w trybie edycji pozycji - NIE PRZESUWAJ MAPY
    if (isPositionEditingMode) {
        return;
    }
    
    if (e.target.closest('.map-viewport-fullscreen') && !e.target.closest('.map-device-fullscreen')) {
        e.preventDefault();
        isPanning = true;
        lastMouseX = e.clientX;
        lastMouseY = e.clientY;
        document.body.style.cursor = 'grabbing';
        document.getElementById('modeStatus').textContent = 'Przesuwanie mapy';
    }
}

function dragMap(e) {
    if (isPanning && !isPositionEditingMode) {
        e.preventDefault();
        
        const deltaX = e.clientX - lastMouseX;
        const deltaY = e.clientY - lastMouseY;
        
        panX += deltaX / zoom;
        panY += deltaY / zoom;
        
        lastMouseX = e.clientX;
        lastMouseY = e.clientY;
        
        updateTransform();
        updateGridPosition();
    }
}

function stopMapPanning() {
    if (isPanning) {
        isPanning = false;
        document.body.style.cursor = '';
        document.getElementById('modeStatus').textContent = 'Przeglądanie';
    }
}

// Dopasuj mapę do kontenera
function fitMapToContainer() {
    const activeMap = document.querySelector('.map-canvas-fullscreen.active');
    if (!activeMap) return;
    
    const viewport = activeMap.querySelector('.map-viewport-fullscreen');
    const img = activeMap.querySelector('.map-image-fullscreen');
    
    if (!viewport || !img) return;
    
    // Resetuj zoom i pan
    zoom = 1;
    panX = 0;
    panY = 0;
    
    const container = document.querySelector('.map-container-fullscreen');
    const imgWidth = img.naturalWidth || img.width;
    const imgHeight = img.naturalHeight || img.height;
    
    if (imgWidth && imgHeight) {
        const containerWidth = container.clientWidth;
        const containerHeight = container.clientHeight;
        
        const scaleX = containerWidth / imgWidth;
        const scaleY = containerHeight / imgHeight;
        const scale = Math.min(scaleX, scaleY) * 0.95; // 95% kontenera
        
        if (scale > 0) {
            zoom = scale;
            updateTransform();
            updateZoomLevel();
            
            // Wyśrodkuj mapę
            const scaledWidth = imgWidth * zoom;
            const scaledHeight = imgHeight * zoom;
            
            panX = (containerWidth - scaledWidth) / (2 * zoom);
            panY = (containerHeight - scaledHeight) / (2 * zoom);
            
            updateTransform();
            
            // Odśwież siatkę
            createGrid();
        }
    }
}

// Przełączanie budynków
function switchBuilding(buildingId) {
    currentBuilding = buildingId;
    
    document.querySelectorAll('.map-canvas-fullscreen').forEach(canvas => {
        canvas.classList.remove('active');
    });
    document.getElementById(`mapCanvas${buildingId}`).classList.add('active');
    
    document.getElementById('currentBuilding').textContent = `Budynek ${buildingId}`;
    
    setTimeout(() => {
        updateFilterCounts();
        updateDeviceCount();
        autoFitMap(true);
        createGrid();
    }, 50);
}

// Kontrolki zoom
function changeZoom(delta) {
    const activeMap = document.querySelector('.map-canvas-fullscreen.active');
    const viewport = activeMap?.querySelector('.map-viewport-fullscreen');
    
    if (!viewport) return;
    
    zoom = Math.max(0.3, Math.min(5, zoom + delta));
    updateTransform();
    updateZoomLevel();
    updateGridPosition();
}

function updateTransform() {
    const activeMap = document.querySelector('.map-canvas-fullscreen.active');
    const viewport = activeMap?.querySelector('.map-viewport-fullscreen');
    
    if (!viewport) return;
    
    viewport.style.transform = `scale(${zoom}) translate(${panX}px, ${panY}px)`;
}

function updateZoomLevel() {
    document.getElementById('zoomLevel').textContent = Math.round(zoom * 100) + '%';
}

function centerMap() {
    const activeMap = document.querySelector('.map-canvas-fullscreen.active');
    const viewport = activeMap?.querySelector('.map-viewport-fullscreen');
    const img = activeMap?.querySelector('.map-image-fullscreen');
    
    if (!viewport || !img) return;
    
    const container = document.querySelector('.map-container-fullscreen');
    const imgWidth = img.naturalWidth || img.width;
    const imgHeight = img.naturalHeight || img.height;
    
    if (imgWidth && imgHeight) {
        const scaledWidth = imgWidth * zoom;
        const scaledHeight = imgHeight * zoom;
        
        panX = (container.clientWidth - scaledWidth) / (2 * zoom);
        panY = (container.clientHeight - scaledHeight) / (2 * zoom);
        
        viewport.style.transform = `scale(${zoom}) translate(${panX}px, ${panY}px)`;
        updateGridPosition();
    }
}

// SIATKA - POPRAWIONA WERSJA
function createGrid() {
    // Usuń starą siatkę
    clearGrid();
    
    const activeMap = document.querySelector('.map-canvas-fullscreen.active');
    const viewport = activeMap?.querySelector('.map-viewport-fullscreen');
    const img = activeMap?.querySelector('.map-image-fullscreen');
    
    if (!viewport || !img) return;
    
    const imgWidth = img.naturalWidth || img.width;
    const imgHeight = img.naturalHeight || img.height;
    
    if (!imgWidth || !imgHeight) return;
    
    // Oblicz rzeczywisty rozmiar siatki (w pikselach obrazka)
    const actualGridSize = gridSize / zoom;
    
    // Utwórz linie siatki
    createGridLines(viewport, imgWidth, imgHeight, actualGridSize);
    
    // Utwórz kropki siatki
    createGridDots(viewport, imgWidth, imgHeight, actualGridSize);
}

function createGridLines(viewport, imgWidth, imgHeight, gridSize) {
    // Linie pionowe
    for (let x = gridSize; x < imgWidth; x += gridSize) {
        const line = document.createElement('div');
        line.className = 'grid-line-vertical';
        line.style.left = x + 'px';
        line.style.top = '0';
        line.style.height = imgHeight + 'px';
        viewport.appendChild(line);
        gridLines.push(line);
    }
    
    // Linie poziome
    for (let y = gridSize; y < imgHeight; y += gridSize) {
        const line = document.createElement('div');
        line.className = 'grid-line-horizontal';
        line.style.top = y + 'px';
        line.style.left = '0';
        line.style.width = imgWidth + 'px';
        viewport.appendChild(line);
        gridLines.push(line);
    }
}

function createGridDots(viewport, imgWidth, imgHeight, gridSize) {
    for (let x = 0; x <= imgWidth; x += gridSize) {
        for (let y = 0; y <= imgHeight; y += gridSize) {
            const dot = document.createElement('div');
            dot.className = 'grid-dot';
            dot.style.left = x + 'px';
            dot.style.top = y + 'px';
            viewport.appendChild(dot);
            gridDots.push(dot);
        }
    }
}

function updateGridPosition() {
    // Aktualizuj pozycję siatki wraz z przesunięciem mapy
    const transform = `translate(${panX}px, ${panY}px)`;
    
    gridLines.forEach(line => {
        line.style.transform = transform;
    });
    
    gridDots.forEach(dot => {
        dot.style.transform = transform;
    });
}

function clearGrid() {
    gridLines.forEach(line => line.remove());
    gridDots.forEach(dot => dot.remove());
    gridLines = [];
    gridDots = [];
}

function toggleGrid() {
    showGrid = !showGrid;
    const gridToggle = document.getElementById('gridToggle');
    
    if (showGrid) {
        // Pokaż siatkę
        gridLines.forEach(line => line.style.display = 'block');
        gridDots.forEach(dot => dot.style.display = 'block');
        gridToggle.classList.add('active');
        gridToggle.innerHTML = '<i class="fas fa-th"></i> Siatka ON';
    } else {
        // Ukryj siatkę
        gridLines.forEach(line => line.style.display = 'none');
        gridDots.forEach(dot => dot.style.display = 'none');
        gridToggle.classList.remove('active');
        gridToggle.innerHTML = '<i class="fas fa-th"></i> Siatka OFF';
    }
}

function changeGridSize(value) {
    gridSizeSlider = parseInt(value);
    gridSize = gridSizeSlider;
    document.getElementById('gridSizeDisplay').textContent = `(${gridSize}px)`;
    createGrid();
    
    if (showGrid) {
        gridLines.forEach(line => line.style.display = 'block');
        gridDots.forEach(dot => dot.style.display = 'block');
    }
}

function toggleSnap() {
    snapToGrid = !snapToGrid;
    const snapToggle = document.getElementById('snapToggle');
    snapToggle.classList.toggle('active', snapToGrid);
    document.getElementById('snapStatus').textContent = snapToGrid ? 'ON' : 'OFF';
    
    showNotification('info', `Przyciąganie do siatki ${snapToGrid ? 'włączone' : 'wyłączone'}`);
}

// Funkcja sprawdzająca granice
function ensureWithinBoundaries(x, y, buildingId, deviceWidth = 36, deviceHeight = 36) {
    // Po prostu ogranicz do 0-100%
    return {
        x: Math.max(0, Math.min(100, x)),
        y: Math.max(0, Math.min(100, y))
    };
}

// PRZYCIĄGANIE DO SIATKI - POPRAWIONE
function snapToGridPosition(clientX, clientY, buildingId) {
    if (!snapToGrid) return { x: 50, y: 50 };
    
    const activeMap = document.querySelector(`#mapCanvas${buildingId}`);
    const img = activeMap?.querySelector('.map-image-fullscreen');
    
    if (!img) return { x: 50, y: 50 };
    
    // Pobierz rozmiar obrazka w pikselach
    const imgWidth = img.naturalWidth || img.width;
    const imgHeight = img.naturalHeight || img.height;
    
    if (!imgWidth || !imgHeight) return { x: 50, y: 50 };
    
    // Pobierz prostokąt obrazka na ekranie
    const imgRect = img.getBoundingClientRect();
    
    // Oblicz pozycję kursora względem obrazka (w pikselach obrazka)
    const imageX = ((clientX - imgRect.left) / imgRect.width) * 100;
    const imageY = ((clientY - imgRect.top) / imgRect.height) * 100;
    
    // Ogranicz do granic obrazka (0-100%)
    const clampedX = Math.max(0, Math.min(100, imageX));
    const clampedY = Math.max(0, Math.min(100, imageY));
    
    // Przyciągnij do siatki (przelicz gridSize na procenty)
    const gridStepPercent = (gridSize / imgRect.width) * 100;
    const snappedX = Math.round(clampedX / gridStepPercent) * gridStepPercent;
    const snappedY = Math.round(clampedY / gridStepPercent) * gridStepPercent;
    
    return ensureWithinBoundaries(snappedX, snappedY, buildingId);
}

// PRZYCIĄGANIE DO INNYCH OBIEKTÓW - POPRAWIONE
function snapToOtherObjects(x, y, currentDeviceId, buildingId) {
    if (!snapToObjects) return { x, y };
    
    let snappedX = x;
    let snappedY = y;
    let minDistance = 2; // 2% odległości
    
    const allDevices = document.querySelectorAll(`.map-device-fullscreen[data-building="${buildingId}"]`);
    
    allDevices.forEach(otherDevice => {
        if (otherDevice.dataset.id === currentDeviceId) return;
        
        const otherX = parseFloat(otherDevice.style.left);
        const otherY = parseFloat(otherDevice.style.top);
        
        // Sprawdź odległość poziomo
        if (Math.abs(x - otherX) < minDistance) {
            snappedX = otherX;
        }
        
        // Sprawdź odległość pionowo
        if (Math.abs(y - otherY) < minDistance) {
            snappedY = otherY;
        }
    });
    
    return { x: snappedX, y: snappedY };
}

function showSnapHint(message) {
    // Usuń istniejące podpowiedzi
    const existingHint = document.querySelector('.snap-hint');
    if (existingHint) existingHint.remove();
    
    // Utwórz nową podpowiedź
    const hint = document.createElement('div');
    hint.className = 'snap-hint';
    hint.textContent = message;
    hint.style.position = 'fixed';
    hint.style.top = '60px';
    hint.style.left = '50%';
    hint.style.transform = 'translateX(-50%)';
    
    document.body.appendChild(hint);
    
    // Automatyczne usunięcie po 1 sekundzie
    setTimeout(() => {
        if (hint.parentNode) hint.remove();
    }, 1000);
}

// Linie pomocnicze podczas przeciągania
function showGuidelines(device, x, y, buildingId) {
    hideGuidelines();
    
    const allDevices = document.querySelectorAll(`.map-device-fullscreen[data-building="${buildingId}"]`);
    const viewport = document.querySelector(`#mapViewport${buildingId}`);
    
    allDevices.forEach(otherDevice => {
        if (otherDevice === device) return;
        
        const otherX = parseFloat(otherDevice.style.left);
        const otherY = parseFloat(otherDevice.style.top);
        
        // Sprawdź wyrównanie poziome
        if (Math.abs(x - otherX) < 1) {
            createGuideline('vertical', x, viewport);
        }
        
        // Sprawdź wyrównanie pionowe
        if (Math.abs(y - otherY) < 1) {
            createGuideline('horizontal', y, viewport);
        }
    });
}

function createGuideline(type, position, viewport) {
    const guideline = document.createElement('div');
    guideline.className = `guideline guideline-${type}`;
    guideline.style.cssText = `
        position: absolute;
        ${type === 'vertical' ? 
            `left: ${position}%; top: 0; bottom: 0; width: 2px; background: rgba(255, 0, 0, 0.7);` : 
            `top: ${position}%; left: 0; right: 0; height: 2px; background: rgba(255, 0, 0, 0.7);`
        }
        z-index: 998;
        pointer-events: none;
    `;
    viewport.appendChild(guideline);
}

function hideGuidelines() {
    document.querySelectorAll('.guideline').forEach(guideline => {
        guideline.remove();
    });
}

// Inicjalizacja drag & drop dla urządzeń z siatką i przyciąganiem - POPRAWIONE
function initDeviceDragAndDrop() {
    document.addEventListener('mousedown', function(e) {
        if (!isPositionEditingMode) return;
        
        const device = e.target.closest('.map-device-fullscreen');
        if (!device || device.dataset.editing !== "true") return;
        
        e.preventDefault();
        e.stopPropagation();
        
        isDraggingDevice = true;
        device.classList.add('dragging');
        
        // Pobierz aktualną pozycję urządzenia w procentach
        const deviceX = parseFloat(device.style.left);
        const deviceY = parseFloat(device.style.top);
        
        // Zapisz początkową pozycję
        dragStartX = e.clientX;
        dragStartY = e.clientY;
        deviceStartX = deviceX;
        deviceStartY = deviceY;
        
        document.body.style.cursor = 'grabbing';
        
        function handleMouseMove(e) {
            if (!isDraggingDevice) return;
            
            const deviceId = device.dataset.id;
            const buildingId = device.dataset.building;
            
            // Oblicz przesunięcie
            const deltaX = e.clientX - dragStartX;
            const deltaY = e.clientY - dragStartY;
            
            // Przelicz przesunięcie na procenty
            const activeMap = document.querySelector(`#mapCanvas${buildingId}`);
            const img = activeMap?.querySelector('.map-image-fullscreen');
            if (!img) return;
            
            const imgRect = img.getBoundingClientRect();
            const deltaPercentX = (deltaX / imgRect.width) * 100;
            const deltaPercentY = (deltaY / imgRect.height) * 100;
            
            // Oblicz nową pozycję
            let newX = deviceStartX + deltaPercentX;
            let newY = deviceStartY + deltaPercentY;
            
            // Przyciągnij do siatki
            if (snapToGrid) {
                const snapped = snapToGridPosition(e.clientX, e.clientY, buildingId);
                newX = snapped.x;
                newY = snapped.y;
            }
            
            // Przyciągnij do innych obiektów
            if (snapToObjects) {
                const snapped = snapToOtherObjects(newX, newY, deviceId, buildingId);
                newX = snapped.x;
                newY = snapped.y;
            }
            
            // Sprawdź granice
            const finalPosition = ensureWithinBoundaries(newX, newY, buildingId);
            
            // Aktualizuj pozycję urządzenia
            device.style.left = finalPosition.x + '%';
            device.style.top = finalPosition.y + '%';
            
            // Pokaż linie pomocnicze
            showGuidelines(device, finalPosition.x, finalPosition.y, buildingId);
        }
        
        function handleMouseUp() {
            isDraggingDevice = false;
            device.classList.remove('dragging');
            document.body.style.cursor = '';
            
            hideGuidelines();
            hideSnapPoint();
            
            // Zapisz pozycję
            const deviceId = device.dataset.id;
            const x = parseFloat(device.style.left);
            const y = parseFloat(device.style.top);
            
            saveDevicePosition(deviceId, x, y);
            
            // Usuń nasłuchiwania
            document.removeEventListener('mousemove', handleMouseMove);
            document.removeEventListener('mouseup', handleMouseUp);
        }
        
        document.addEventListener('mousemove', handleMouseMove);
        document.addEventListener('mouseup', handleMouseUp);
    });
}

function showSnapPoint(x, y, buildingId) {
    hideSnapPoint();
    
    const activeMap = document.querySelector(`#mapCanvas${buildingId}`);
    const viewport = activeMap?.querySelector('.map-viewport-fullscreen');
    
    if (!viewport) return;
    
    const snapPoint = document.createElement('div');
    snapPoint.className = 'grid-dot-snap';
    snapPoint.style.left = x + '%';
    snapPoint.style.top = y + '%';
    snapPoint.id = 'currentSnapPoint';
    
    viewport.appendChild(snapPoint);
}

function hideSnapPoint() {
    const snapPoint = document.getElementById('currentSnapPoint');
    if (snapPoint) snapPoint.remove();
}

// Inicjalizacja przybornika
function initToolbox() {
    changeToolboxType('');
    
    const dropZone = document.getElementById('dropZone');
    const mapContainer = document.querySelector('.map-container-fullscreen');
    
    mapContainer.addEventListener('dragover', function(e) {
        if (isDraggingFromToolbox) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
            
            const rect = this.getBoundingClientRect();
            const x = ((e.clientX - rect.left) / rect.width) * 100;
            const y = ((e.clientY - rect.top) / rect.height) * 100;
            
            dropZone.style.left = x + '%';
            dropZone.style.top = y + '%';
            dropZone.classList.add('visible');
        }
    });
    
    mapContainer.addEventListener('dragleave', function() {
        dropZone.classList.remove('visible');
    });
    
    mapContainer.addEventListener('drop', function(e) {
        e.preventDefault();
        
        if (isDraggingFromToolbox) {
            const rect = this.getBoundingClientRect();
            const clientX = e.clientX;
            const clientY = e.clientY;
            
            try {
                const data = JSON.parse(e.dataTransfer.getData('text/plain'));
                
                // Przyciągnij do siatki
                const buildingId = currentBuilding;
                const snappedGrid = snapToGridPosition(clientX, clientY, buildingId);
                
                openAddDeviceModal(data, snappedGrid.x, snappedGrid.y);
            } catch (error) {
                console.error('Błąd przetwarzania danych:', error);
            }
            
            dropZone.classList.remove('visible');
        }
    });
}

function changeToolboxType(typeCode) {
    const toolboxItems = document.getElementById('toolboxItems');
    const printerSelection = document.getElementById('printerSelection');
    
    if (!typeCode) {
        toolboxItems.innerHTML = '<p class="text-muted small">Wybierz typ urządzenia z listy powyżej</p>';
        if (printerSelection) {
            printerSelection.style.display = 'none';
        }
        return;
    }
    
    const type = objectTypes.find(t => t.code === typeCode);
    if (!type) return;
    
    // Pokaż/ukryj wybór drukarki
    if (typeCode === 'printer' && printerSelection && printersData.length > 0) {
        printerSelection.style.display = 'block';
        toolboxItems.innerHTML = '<p class="text-muted small">Wybierz drukarkę z listy powyżej, a następnie przeciągnij na mapę</p>';
        return;
    } else if (printerSelection) {
        printerSelection.style.display = 'none';
    }
    
    // Utwórz element do przeciągania
    toolboxItems.innerHTML = `
        <div class="toolbox-item-compact" 
             draggable="true"
             data-type-code="${type.code}"
             data-type-name="${type.name}"
             data-icon="${type.icon}"
             data-color="${type.color}">
            <div class="toolbox-icon-compact" style="color: ${type.color};">
                <i class="fas ${type.icon}"></i>
            </div>
            <div class="toolbox-label-compact">${type.name}</div>
        </div>
    `;
    
    // Dodaj obsługę przeciągania
    const item = toolboxItems.querySelector('.toolbox-item-compact');
    item.addEventListener('dragstart', function(e) {
        isDraggingFromToolbox = true;
        this.style.opacity = '0.5';
        
        e.dataTransfer.setData('text/plain', JSON.stringify({
            typeCode: this.dataset.typeCode,
            typeName: this.dataset.typeName,
            icon: this.dataset.icon,
            color: this.dataset.color
        }));
        
        e.dataTransfer.effectAllowed = 'copy';
        document.getElementById('modeStatus').textContent = 'Dodawanie urządzenia';
    });
    
    item.addEventListener('dragend', function() {
        isDraggingFromToolbox = false;
        this.style.opacity = '1';
        document.getElementById('dropZone').classList.remove('visible');
        document.getElementById('modeStatus').textContent = 'Przeglądanie';
    });
}

// Obsługa kliknięcia na urządzenie
function handleDeviceClick(event, deviceId, deviceType) {
    // Zapobiegaj otwieraniu kontekstowego menu
    if (event.button !== 0) return; // Tylko lewy przycisk myszy
    
    // Jeśli to switch, otwórz modal ze szczegółami
    if (deviceType === 'switch') {
        event.stopPropagation();
        openSwitchModal(deviceId);
    }
}

// Otwórz modal ze szczegółami switcha
function openSwitchModal(switchId) {
    const device = mapObjects.find(obj => obj.id == switchId);
    if (!device) return;
    
    currentSwitchId = switchId;
    document.getElementById('switchModalTitle').textContent = `Switch: ${device.name}`;
    document.getElementById('switchDetailsModal').style.display = 'flex';
    
    // Załaduj szczegóły switcha
    loadSwitchDetails(switchId);
}

function closeSwitchModal() {
    document.getElementById('switchDetailsModal').style.display = 'none';
    currentSwitchId = null;
}

function loadSwitchDetails(switchId) {
    const content = document.getElementById('switchDetailsContent');
    
    fetch(`ajax/switch_details.php?switch_id=${switchId}`)
        .then(response => response.text())
        .then(html => {
            content.innerHTML = html;
        })
        .catch(error => {
            content.innerHTML = `
                <div style="padding: 40px; text-align: center; color: #ef4444;">
                    <i class="fas fa-exclamation-triangle fa-2x"></i>
                    <p>Błąd ładowania szczegółów switcha</p>
                    <small>${error.message}</small>
                </div>
            `;
        });
}

// Otwórz modal edycji portów
function editSwitchPorts() {
    if (!currentSwitchId) return;
    
    const device = mapObjects.find(obj => obj.id == currentSwitchId);
    if (!device) return;
    
    document.getElementById('editSwitchTitle').textContent = device.name;
    document.getElementById('editSwitchPortsModal').style.display = 'flex';
    
    // Załaduj formularz edycji portów
    loadEditSwitchPorts(currentSwitchId);
}

function closeEditSwitchModal() {
    document.getElementById('editSwitchPortsModal').style.display = 'none';
}

function loadEditSwitchPorts(switchId) {
    const content = document.getElementById('editSwitchPortsContent');
    
    fetch(`ajax/switch_edit_ports.php?switch_id=${switchId}`)
        .then(response => response.text())
        .then(html => {
            content.innerHTML = html;
        })
        .catch(error => {
            content.innerHTML = `
                <div style="padding: 20px; text-align: center; color: #ef4444;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Błąd ładowania formularza edycji</p>
                </div>
            `;
        });
}

// Zapisz zmiany portów
function saveSwitchPorts() {
    if (!currentSwitchId) {
        alert('Brak wybranego switcha');
        return;
    }
    
    const form = document.getElementById('editSwitchPortsForm');
    if (!form) {
        alert('Nie znaleziono formularza');
        return;
    }
    
    // Pokaż loader
    const submitBtn = form.querySelector('button[type="submit"]') || 
                     document.querySelector('#editSwitchPortsModal .btn-primary');
    if (submitBtn) {
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Zapisywanie...';
        submitBtn.disabled = true;
    }
    
    // Zbierz dane z formularza
    const formData = new FormData(form);
    
    // Dodaj informacje o aktualnym czasie
    formData.append('timestamp', new Date().getTime());
    formData.append('user_id', <?php echo $_SESSION['user_id'] ?? 0; ?>);
    
    fetch('ajax/switch_save_ports.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`Błąd HTTP: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // Sukces - zamknij modal i odśwież widok
            closeEditSwitchModal();
            
            // Pokaż komunikat sukcesu
            showNotification('success', data.message);
            
            // Odśwież widok switcha po krótkim opóźnieniu
            setTimeout(() => {
                loadSwitchDetails(currentSwitchId);
            }, 500);
            
        } else {
            // Błąd
            showNotification('error', data.message || 'Wystąpił błąd podczas zapisu');
        }
    })
    .catch(error => {
        console.error('Błąd zapisu portów:', error);
        showNotification('error', 'Błąd połączenia z serwerem: ' + error.message);
    })
    .finally(() => {
        // Przywróć przycisk
        if (submitBtn) {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    });
}

// EDYCJA POZYCJI URZĄDZENIA
function enablePositionEditing() {
    if (!canEdit) return;
    
    const device = document.querySelector(`.map-device-fullscreen[data-id="${selectedDeviceId}"]`);
    if (device) {
        isPositionEditingMode = true;
        device.dataset.editing = "true";
        device.style.cursor = 'move';
        device.style.zIndex = '1000';
        
        const icon = device.querySelector('.device-icon-fullscreen');
        if (icon) {
            icon.style.borderColor = '#2563eb';
            icon.style.borderWidth = '4px';
            icon.style.boxShadow = '0 0 0 4px rgba(37, 99, 235, 0.3)';
        }
        
        // Pokaż podpowiedź
        document.getElementById('positionEditingHint').style.display = 'block';
        
        hideContextMenu();
        document.getElementById('modeStatus').textContent = 'Edycja pozycji - przeciągnij urządzenie';
    }
}

function disablePositionEditing() {
    isPositionEditingMode = false;
    document.querySelectorAll('.map-device-fullscreen').forEach(device => {
        device.dataset.editing = "false";
        device.style.cursor = '';
        device.style.zIndex = '';
        const icon = device.querySelector('.device-icon-fullscreen');
        if (icon) {
            icon.style.borderColor = '';
            icon.style.borderWidth = '';
            icon.style.boxShadow = '';
        }
    });
    
    // Ukryj podpowiedź
    document.getElementById('positionEditingHint').style.display = 'none';
    
    document.getElementById('modeStatus').textContent = 'Przeglądanie';
}

function saveDevicePosition(deviceId, x, y) {
    fetch('ajax/map_operations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'update_position',
            object_id: deviceId,
            position_x: x,
            position_y: y
        })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            console.error('Błąd zapisu pozycji:', data.message);
            showNotification('error', 'Błąd zapisu pozycji: ' + data.message);
        } else {
            showNotification('success', 'Pozycja urządzenia zaktualizowana');
        }
    })
    .catch(error => {
        console.error('Błąd:', error);
        showNotification('error', 'Błąd połączenia z serwerem');
    });
}

// Filtry
function initFilters() {
    const filterCheckboxes = document.querySelectorAll('.filter-checkbox');
    filterCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateActiveFilters();
            filterMapDevices();
        });
    });
}

function updateActiveFilters() {
    activeFilters = [];
    document.querySelectorAll('.filter-checkbox:checked').forEach(checkbox => {
        activeFilters.push(checkbox.value);
    });
}

function filterMapDevices() {
    const devices = document.querySelectorAll('.map-device-fullscreen');
    devices.forEach(device => {
        const type = device.dataset.type;
        const building = device.closest('.map-canvas-fullscreen').id.replace('mapCanvas', '');
        
        if (parseInt(building) === currentBuilding) {
            const shouldShow = activeFilters.length === 0 || activeFilters.includes(type);
            device.style.display = shouldShow ? 'block' : 'none';
        }
    });
}

function updateFilterCounts() {
    const buildingObjects = mapObjects.filter(obj => obj.building_id == currentBuilding);
    const total = buildingObjects.length;
    
    document.getElementById('objectCount').textContent = total;
    
    objectTypes.forEach(type => {
        const count = buildingObjects.filter(obj => obj.type_code === type.code).length;
        const countElement = document.getElementById(`count_${type.code}`);
        if (countElement) {
            countElement.textContent = count;
        }
    });
}

function updateDeviceCount() {
    const activeMap = document.querySelector('.map-canvas-fullscreen.active');
    const devices = activeMap?.querySelectorAll('.map-device-fullscreen') || [];
    document.getElementById('objectCount').textContent = devices.length;
}

// Wyszukiwarka
function initSearch() {
    const searchInput = document.getElementById('objectSearch');
    const searchResults = document.getElementById('searchResults');
    
    searchInput.addEventListener('input', function() {
        const term = this.value.toLowerCase().trim();
        
        if (term.length < 2) {
            searchResults.classList.remove('active');
            return;
        }
        
        const results = mapObjects.filter(obj => 
            (obj.name.toLowerCase().includes(term) || 
             (obj.ip_address && obj.ip_address.includes(term)) ||
             (obj.printer_name && obj.printer_name.toLowerCase().includes(term))) && 
            obj.building_id == currentBuilding &&
            (activeFilters.length === 0 || activeFilters.includes(obj.type_code))
        );
        
        if (results.length > 0) {
            searchResults.innerHTML = results.map(obj => `
                <div class="context-item" onclick="highlightDevice(${obj.id})">
                    <i class="fas ${obj.icon}" style="color: ${obj.color};"></i>
                    <span>${escapeHtml(obj.name)}</span>
                    <small>${escapeHtml(obj.type_name)}</small>
                </div>
            `).join('');
            searchResults.classList.add('active');
        } else {
            searchResults.innerHTML = '<div class="context-item" style="color: var(--gray-500); text-align: center;">Brak wyników</div>';
            searchResults.classList.add('active');
        }
    });
    
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.classList.remove('active');
        }
    });
}

function highlightDevice(deviceId) {
    const device = mapObjects.find(obj => obj.id == deviceId);
    if (device) {
        if (device.building_id != currentBuilding) {
            switchBuilding(device.building_id);
        }
        
        const marker = document.querySelector(`.map-device-fullscreen[data-id="${deviceId}"]`);
        if (marker) {
            const icon = marker.querySelector('.device-icon-fullscreen');
            const originalTransform = icon.style.transform;
            icon.style.transform = originalTransform + ' scale(1.3)';
            icon.style.boxShadow = '0 0 0 4px rgba(37, 99, 235, 0.3)';
            
            setTimeout(() => {
                icon.style.transform = originalTransform;
                icon.style.boxShadow = '';
            }, 2000);
        }
    }
}

// Legenda
function toggleLegend() {
    const content = document.querySelector('.legend-content');
    if (content.style.display === 'none') {
        content.style.display = 'block';
    } else {
        content.style.display = 'none';
    }
}

// Modal dodawania/edycji urządzenia
function openAddDeviceModal(typeData, x, y) {
    document.getElementById('modalTitle').textContent = 'Dodaj urządzenie';
    document.getElementById('modalSubmitBtn').textContent = 'Dodaj urządzenie';
    document.getElementById('deviceId').value = '0';
    document.getElementById('addDeviceForm').reset();
    
    if (typeData) {
        document.getElementById('deviceType').value = typeData.typeCode;
        document.getElementById('deviceName').value = typeData.typeName;
        
        // Jeśli przeciągnięto z przybornika drukarki
        if (typeData.printerId && printersData.length > 0) {
            const printer = printersData.find(p => p.id == typeData.printerId);
            if (printer) {
                document.getElementById('deviceName').value = printer.name;
                document.getElementById('deviceIP').value = printer.ip;
                document.getElementById('devicePrinter').value = printer.id;
            }
        }
        
        if (x && y) {
            document.getElementById('deviceX').value = x;
            document.getElementById('deviceY').value = y;
        }
    }
    
    document.getElementById('deviceBuilding').value = currentBuilding;
    togglePrinterSelection();
    
    document.getElementById('addDeviceModal').style.display = 'flex';
}

function togglePrinterSelection() {
    const typeSelect = document.getElementById('deviceType');
    const printerSelection = document.getElementById('printerSelectionField');
    
    if (!printerSelection) return;
    
    if (typeSelect.value === 'printer' && printersData.length > 0) {
        printerSelection.style.display = 'block';
    } else {
        printerSelection.style.display = 'none';
    }
}

function closeAddDeviceModal() {
    document.getElementById('addDeviceModal').style.display = 'none';
}

document.getElementById('addDeviceForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const deviceId = formData.get('id');
    const action = deviceId === '0' ? 'add_object' : 'update_object';
    
    const data = {
        action: action,
        object_id: deviceId,
        building_id: formData.get('building_id'),
        name: formData.get('name'),
        type_code: formData.get('type_code'),
        ip_address: formData.get('ip_address'),
        description: formData.get('description'),
        position_x: parseFloat(formData.get('position_x')),
        position_y: parseFloat(formData.get('position_y'))
    };
    
    // Dodaj printer_id jeśli wybrano drukarkę
    const printerId = formData.get('printer_id');
    if (printerId && hasPrinterId) {
        data.printer_id = printerId;
    }
    
    fetch('ajax/map_operations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            closeAddDeviceModal();
            location.reload();
        } else {
            alert('Błąd: ' + result.message);
        }
    })
    .catch(error => {
        console.error('Błąd:', error);
        alert('Błąd połączenia z serwerem');
    });
});

// Kontekstowe menu (PPM)
function showContextMenu(event, deviceId) {
    event.preventDefault();
    
    const device = mapObjects.find(obj => obj.id == deviceId);
    if (!device || !canEdit) return;
    
    selectedDeviceId = deviceId;
    selectedDeviceName = device.name;
    
    const contextMenu = document.getElementById('contextMenu');
    contextMenu.style.left = event.clientX + 'px';
    contextMenu.style.top = event.clientY + 'px';
    contextMenu.classList.add('active');
    
    return false;
}

function hideContextMenu() {
    const contextMenu = document.getElementById('contextMenu');
    if (contextMenu) {
        contextMenu.classList.remove('active');
    }
}

function viewDeviceDetails() {
    const device = mapObjects.find(obj => obj.id == selectedDeviceId);
    if (device) {
        let details = `Szczegóły urządzenia:\n\nNazwa: ${device.name}\nTyp: ${device.type_name}\n`;
        
        if (device.ip_address) details += `IP: ${device.ip_address}\n`;
        if (device.description) details += `Opis: ${device.description}\n`;
        if (device.printer_name) details += `Drukarka: ${device.printer_name}\n`;
        if (device.printer_ip) details += `IP drukarki: ${device.printer_ip}\n`;
        if (device.printer_model) details += `Model: ${device.printer_model}\n`;
        
        alert(details);
    }
    hideContextMenu();
}

function editDeviceData() {
    const device = mapObjects.find(obj => obj.id == selectedDeviceId);
    if (!device) {
        hideContextMenu();
        return;
    }
    
    document.getElementById('modalTitle').textContent = 'Edytuj urządzenie';
    document.getElementById('modalSubmitBtn').textContent = 'Zapisz zmiany';
    document.getElementById('deviceId').value = device.id;
    document.getElementById('deviceName').value = device.name;
    document.getElementById('deviceType').value = device.type_code;
    document.getElementById('deviceBuilding').value = device.building_id;
    document.getElementById('deviceIP').value = device.ip_address || '';
    document.getElementById('deviceDescription').value = device.description || '';
    document.getElementById('deviceX').value = device.position_x;
    document.getElementById('deviceY').value = device.position_y;
    
    // Ustaw printer_id jeśli urządzenie ma powiązaną drukarkę
    if (device.printer_id && hasPrinterId) {
        const printerSelect = document.getElementById('devicePrinter');
        if (printerSelect) {
            printerSelect.value = device.printer_id;
        }
    }
    
    togglePrinterSelection();
    document.getElementById('addDeviceModal').style.display = 'flex';
    hideContextMenu();
}

// Usuwanie urządzenia
function openDeleteModal() {
    document.getElementById('deviceToDeleteName').textContent = selectedDeviceName;
    document.getElementById('deleteConfirmationModal').style.display = 'flex';
    hideContextMenu();
}

function closeDeleteModal() {
    document.getElementById('deleteConfirmationModal').style.display = 'none';
    selectedDeviceId = null;
    selectedDeviceName = '';
}

function confirmDeleteDevice() {
    if (!selectedDeviceId) return;
    
    if (!confirm('Czy na pewno chcesz usunąć to urządzenie?')) {
        return;
    }
    
    fetch('ajax/map_operations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'delete_object',
            object_id: selectedDeviceId
        })
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            closeDeleteModal();
            location.reload();
        } else {
            alert('Błąd usuwania urządzenia: ' + result.message);
        }
    })
    .catch(error => {
        console.error('Błąd:', error);
        alert('Błąd połączenia z serwerem');
    });
}

// Funkcje pomocnicze
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

// Funkcja do pokazywania powiadomień
function showNotification(type, message) {
    // Usuń istniejące powiadomienia
    const existing = document.querySelector('.custom-notification');
    if (existing) existing.remove();
    
    // Utwórz nowe powiadomienie
    const notification = document.createElement('div');
    notification.className = `custom-notification ${type}`;
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 15px 20px;
        border-radius: 6px;
        color: white;
        font-weight: 500;
        z-index: 10000;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        animation: slideIn 0.3s ease-out;
        max-width: 400px;
        display: flex;
        align-items: center;
        gap: 10px;
    `;
    
    // Ustaw kolor w zależności od typu
    if (type === 'success') {
        notification.style.background = 'linear-gradient(135deg, #10b981, #059669)';
    } else if (type === 'error') {
        notification.style.background = 'linear-gradient(135deg, #ef4444, #dc2626)';
    } else if (type === 'warning') {
        notification.style.background = 'linear-gradient(135deg, #f59e0b, #d97706)';
    } else {
        notification.style.background = 'linear-gradient(135deg, #3b82f6, #2563eb)';
    }
    
    // Dodaj ikonę
    const icon = document.createElement('i');
    if (type === 'success') {
        icon.className = 'fas fa-check-circle';
    } else if (type === 'error') {
        icon.className = 'fas fa-exclamation-circle';
    } else if (type === 'warning') {
        icon.className = 'fas fa-exclamation-triangle';
    } else {
        icon.className = 'fas fa-info-circle';
    }
    
    // Dodaj tekst
    const text = document.createElement('span');
    text.textContent = message;
    
    // Dodaj przycisk zamknięcia
    const closeBtn = document.createElement('button');
    closeBtn.innerHTML = '<i class="fas fa-times"></i>';
    closeBtn.style.cssText = `
        background: transparent;
        border: none;
        color: white;
        cursor: pointer;
        margin-left: auto;
        padding: 0;
        font-size: 14px;
    `;
    closeBtn.onclick = () => notification.remove();
    
    // Złóż elementy
    notification.appendChild(icon);
    notification.appendChild(text);
    notification.appendChild(closeBtn);
    
    document.body.appendChild(notification);
    
    // Automatyczne usunięcie po 5 sekundach
    setTimeout(() => {
        if (notification.parentNode) {
            notification.style.animation = 'slideOut 0.3s ease-out';
            setTimeout(() => notification.remove(), 300);
        }
    }, 5000);
    
    // Dodaj style animacji
    if (!document.querySelector('#notification-styles')) {
        const style = document.createElement('style');
        style.id = 'notification-styles';
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
    }
}

// Obsługa przeciągania drukarki z przybornika
const printerSelect = document.getElementById('printerSelect');
if (printerSelect) {
    printerSelect.addEventListener('change', function() {
        const printerId = this.value;
        if (!printerId) return;
        
        const printer = printersData.find(p => p.id == printerId);
        if (!printer) return;
        
        const toolboxItems = document.getElementById('toolboxItems');
        toolboxItems.innerHTML = `
            <div class="toolbox-item-compact" 
                 draggable="true"
                 data-type-code="printer"
                 data-type-name="${printer.name}"
                 data-icon="fa-print"
                 data-color="#2563eb"
                 data-printer-id="${printer.id}">
                <div class="toolbox-icon-compact" style="color: #2563eb;">
                    <i class="fas fa-print"></i>
                </div>
                <div class="toolbox-label-compact">${printer.name}</div>
            </div>
        `;
        
        // Dodaj obsługę przeciągania dla drukarki
        const item = toolboxItems.querySelector('.toolbox-item-compact');
        item.addEventListener('dragstart', function(e) {
            isDraggingFromToolbox = true;
            this.style.opacity = '0.5';
            
            e.dataTransfer.setData('text/plain', JSON.stringify({
                typeCode: this.dataset.typeCode,
                typeName: this.dataset.typeName,
                icon: this.dataset.icon,
                color: this.dataset.color,
                printerId: this.dataset.printerId
            }));
            
            e.dataTransfer.effectAllowed = 'copy';
            document.getElementById('modeStatus').textContent = 'Dodawanie drukarki';
        });
        
        item.addEventListener('dragend', function() {
            isDraggingFromToolbox = false;
            this.style.opacity = '1';
            document.getElementById('dropZone').classList.remove('visible');
            document.getElementById('modeStatus').textContent = 'Przeglądanie';
        });
    });
}

// Zamknij modal po kliknięciu poza
window.addEventListener('click', function(e) {
    const modals = ['addDeviceModal', 'deleteConfirmationModal', 'switchDetailsModal', 'editSwitchPortsModal'];
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (modal && e.target === modal) {
            if (modalId === 'switchDetailsModal') closeSwitchModal();
            else if (modalId === 'editSwitchPortsModal') closeEditSwitchModal();
            else modal.style.display = 'none';
        }
    });
});
</script>