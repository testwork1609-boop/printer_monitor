<?php
// ajax/switch_details.php - Szczegóły switcha z portami (ulepszona wersja)

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

// Sprawdź czy tabele switch_ports istnieje
$tableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='switch_ports'")->fetch();

if (!$tableExists) {
    echo '<div style="padding: 40px; text-align: center; color: #ef4444;">
            <i class="fas fa-exclamation-triangle"></i>
            <p>Tabela switch_ports nie istnieje. Uruchom migrację.</p>
          </div>';
    exit;
}

// Pobierz dane switcha
$stmt = $db->prepare("SELECT mo.*, ot.name as type_name, ot.icon, ot.color 
                      FROM map_objects mo
                      LEFT JOIN object_types ot ON mo.type_code = ot.code
                      WHERE mo.id = ?");
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

// Jeśli nie ma portów, utwórz domyślne (48 portów dla Cisco C1000-48P-4G-L)
if (empty($ports)) {
    // Utwórz domyślne porty
    $default_ports = [];
    for ($i = 1; $i <= 48; $i++) {
        $default_ports[] = [
            'port_number' => $i,
            'port_name' => "Port $i",
            'port_type' => 'ethernet',
            'port_speed' => $i <= 4 ? '1G' : '100M',
            'port_status' => 'down',
            'connected_to' => '',
            'connected_device_type' => '',
            'notes' => ''
        ];
    }
    
    // Zapisz domyślne porty do bazy
    foreach ($default_ports as $port) {
        $stmt = $db->prepare("INSERT INTO switch_ports 
                              (switch_id, port_number, port_name, port_type, port_speed, port_status, connected_to, connected_device_type, notes) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $switch_id,
            $port['port_number'],
            $port['port_name'],
            $port['port_type'],
            $port['port_speed'],
            $port['port_status'],
            $port['connected_to'],
            $port['connected_device_type'],
            $port['notes']
        ]);
    }
    
    // Pobierz ponownie porty
    $stmt = $db->prepare("SELECT * FROM switch_ports WHERE switch_id = ? ORDER BY port_number");
    $stmt->execute([$switch_id]);
    $ports = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Statystyki portów
$total_ports = count($ports);
$active_ports = count(array_filter($ports, function($p) { return $p['port_status'] == 'up'; }));
$down_ports = count(array_filter($ports, function($p) { return $p['port_status'] == 'down'; }));
$connected_ports = count(array_filter($ports, function($p) { return !empty($p['connected_to']); }));
$gigabit_ports = count(array_filter($ports, function($p) { return $p['port_speed'] == '1G'; }));
$fast_ports = count(array_filter($ports, function($p) { return $p['port_speed'] == '100M'; }));

// Model switcha (domyślny Cisco C1000-48P-4G-L)
$switch_model = "Cisco Switch C1000-48P-4G-L";
$switch_description = "48-portowy switch Gigabit Ethernet z 4 portami SFP";
$switch_image = "https://images.unsplash.com/photo-1563013544-824ae1b704d3?w=400&h=250&fit=crop&auto=format";

// Generuj HTML
?>
<div class="switch-details-container">
    <!-- Nagłówek z gradientem -->
    <div class="switch-header" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); color: white; padding: 25px; border-radius: 12px 12px 0 0; box-shadow: 0 4px 20px rgba(139, 92, 246, 0.3);">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="margin: 0; color: white; font-size: 24px; font-weight: 700;">
                    <i class="fas fa-network-wired" style="margin-right: 12px;"></i>
                    <?php echo htmlspecialchars($switch['name']); ?>
                </h3>
                <p style="margin: 8px 0 0 0; opacity: 0.9; font-size: 14px; display: flex; align-items: center; gap: 10px;">
                    <span class="chip" style="background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 20px; font-size: 12px;">
                        <i class="fas fa-microchip" style="margin-right: 5px;"></i>
                        <?php echo htmlspecialchars($switch_model); ?>
                    </span>
                    <?php if ($switch['ip_address']): ?>
                    <span class="chip" style="background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 20px; font-size: 12px;">
                        <i class="fas fa-wifi" style="margin-right: 5px;"></i>
                        <?php echo htmlspecialchars($switch['ip_address']); ?>
                    </span>
                    <?php endif; ?>
                </p>
            </div>
            <div style="text-align: right;">
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">
                    <i class="fas fa-info-circle" style="margin-right: 5px;"></i>
                    <?php echo htmlspecialchars($switch_description); ?>
                </div>
                <div style="font-size: 12px; opacity: 0.8;">
                    <i class="fas fa-calendar-alt" style="margin-right: 5px;"></i>
                    Ostatnia aktualizacja: <?php echo date('d.m.Y H:i'); ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Karty statystyk -->
    <div style="padding: 20px; background: #f8fafc; border-bottom: 1px solid #e5e7eb;">
        <div class="stats-grid">
            <div class="stat-card" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                <div class="stat-icon"><i class="fas fa-plug"></i></div>
                <div class="stat-value"><?php echo $total_ports; ?></div>
                <div class="stat-label">Wszystkie porty</div>
            </div>
            
            <div class="stat-card" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                <div class="stat-icon"><i class="fas fa-bolt"></i></div>
                <div class="stat-value"><?php echo $active_ports; ?></div>
                <div class="stat-label">Aktywne</div>
            </div>
            
            <div class="stat-card" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">
                <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                <div class="stat-value"><?php echo $down_ports; ?></div>
                <div class="stat-label">Nieaktywne</div>
            </div>
            
            <div class="stat-card" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                <div class="stat-icon"><i class="fas fa-link"></i></div>
                <div class="stat-value"><?php echo $connected_ports; ?></div>
                <div class="stat-label">Podłączone</div>
            </div>
            
            <div class="stat-card" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                <div class="stat-icon"><i class="fas fa-tachometer-alt"></i></div>
                <div class="stat-value"><?php echo $gigabit_ports; ?></div>
                <div class="stat-label">Gigabit</div>
            </div>
            
            <div class="stat-card" style="background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);">
                <div class="stat-icon"><i class="fas fa-tachometer-alt"></i></div>
                <div class="stat-value"><?php echo $fast_ports; ?></div>
                <div class="stat-label">Fast Ethernet</div>
            </div>
        </div>
    </div>
    
    <!-- Interaktywna wizualizacja switcha -->
    <div class="switch-visualization-enhanced" style="padding: 25px;">
        <!-- Kontrolki filtrowania -->
        <div class="filter-controls" style="margin-bottom: 20px; padding: 15px; background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
            <h5 style="margin: 0 0 15px 0; color: #374151; font-size: 16px;">
                <i class="fas fa-filter" style="margin-right: 8px; color: #8b5cf6;"></i>
                Filtruj porty
            </h5>
            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                <button class="filter-btn active" onclick="filterPorts('all')">
                    <i class="fas fa-th"></i> Wszystkie (<?php echo $total_ports; ?>)
                </button>
                <button class="filter-btn" onclick="filterPorts('up')">
                    <i class="fas fa-bolt" style="color: #10b981;"></i> Aktywne (<?php echo $active_ports; ?>)
                </button>
                <button class="filter-btn" onclick="filterPorts('down')">
                    <i class="fas fa-times" style="color: #ef4444;"></i> Nieaktywne (<?php echo $down_ports; ?>)
                </button>
                <button class="filter-btn" onclick="filterPorts('connected')">
                    <i class="fas fa-link" style="color: #3b82f6;"></i> Podłączone (<?php echo $connected_ports; ?>)
                </button>
                <button class="filter-btn" onclick="filterPorts('1G')">
                    <i class="fas fa-tachometer-alt" style="color: #f59e0b;"></i> Gigabit (<?php echo $gigabit_ports; ?>)
                </button>
                <button class="filter-btn" onclick="filterPorts('100M')">
                    <i class="fas fa-tachometer-alt" style="color: #ec4899;"></i> 100M (<?php echo $fast_ports; ?>)
                </button>
            </div>
        </div>
        
        <!-- Animowany switch 3D -->
        <div class="switch-3d-container">
            <div class="switch-3d">
                <!-- Przedni panel switcha -->
                <div class="switch-front">
                    <!-- LED status -->
                    <div class="switch-leds">
                        <div class="led led-power active"></div>
                        <div class="led led-status active"></div>
                        <div class="led led-link <?php echo $connected_ports > 0 ? 'active' : ''; ?>"></div>
                    </div>
                    
                    <!-- Logo -->
                    <div class="switch-logo">
                        <i class="fab fa-cisco" style="font-size: 24px; color: #00b0f4;"></i>
                        <span style="font-weight: bold; color: #00b0f4; margin-left: 5px;">CISCO</span>
                    </div>
                    
                    <!-- Porty SFP -->
                    <div class="sfp-ports">
                        <?php for ($i = 49; $i <= 52; $i++): ?>
                        <div class="sfp-port" data-port="<?php echo $i; ?>" onclick="showPortDetails(<?php echo $i; ?>)">
                            <div class="sfp-indicator"></div>
                            <div class="port-label">SFP<?php echo $i-48; ?></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                    
                    <!-- Główne porty Ethernet -->
                    <div class="ethernet-ports">
                        <?php 
                        $rows = 6;
                        $cols = 8;
                        $port_counter = 1;
                        
                        for ($row = 0; $row < $rows; $row++): 
                        ?>
                        <div class="port-row">
                            <?php for ($col = 0; $col < $cols; $col++): 
                                $port_num = $port_counter;
                                $port = $ports[$port_num-1] ?? null;
                                $status = $port ? $port['port_status'] : 'down';
                                $connected = $port && !empty($port['connected_to']);
                                $speed = $port ? $port['port_speed'] : '100M';
                            ?>
                            <div class="ethernet-port 
                                port-<?php echo $status; ?> 
                                <?php echo $connected ? 'connected' : ''; ?> 
                                speed-<?php echo strtolower($speed); ?>"
                                 data-port="<?php echo $port_num; ?>"
                                 onclick="showPortDetails(<?php echo $port_num; ?>)"
                                 onmouseenter="highlightPort(<?php echo $port_num; ?>)"
                                 onmouseleave="unhighlightPort(<?php echo $port_num; ?>)">
                                <div class="port-light"></div>
                                <div class="port-number"><?php echo str_pad($port_num, 2, '0', STR_PAD_LEFT); ?></div>
                                <?php if ($connected): ?>
                                <div class="connection-dot"></div>
                                <?php endif; ?>
                            </div>
                            <?php 
                                $port_counter++;
                            endfor; ?>
                        </div>
                        <?php endfor; ?>
                    </div>
                    
                    <!-- Etykiety -->
                    <div class="switch-labels">
                        <span>C1000-48P-4G-L</span>
                        <span>10/100/1000</span>
                    </div>
                </div>
            </div>
            
            <!-- Legenda -->
            <div class="switch-legend">
                <div class="legend-item">
                    <div class="legend-color active"></div>
                    <span>Port aktywny</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color inactive"></div>
                    <span>Port nieaktywny</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color connected"></div>
                    <span>Podłączony</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color gigabit"></div>
                    <span>Gigabit (1G)</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color fast"></div>
                    <span>Fast Ethernet (100M)</span>
                </div>
            </div>
        </div>
        
        <!-- Panel szczegółów portu -->
        <div id="portDetailsPanel" class="port-details-panel" style="display: none;">
            <div class="panel-header">
                <h5><i class="fas fa-info-circle"></i> Szczegóły portu <span id="selectedPortNumber">1</span></h5>
                <button onclick="closePortDetails()" class="close-btn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="portDetailsContent" class="panel-content">
                <!-- Zawartość wczytywana dynamicznie -->
            </div>
        </div>
        
        <!-- Tabela portów z wyszukiwaniem -->
        <div class="ports-table-container" style="margin-top: 30px;">
            <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h5 style="margin: 0; color: #374151; font-size: 16px;">
                    <i class="fas fa-table" style="margin-right: 8px; color: #8b5cf6;"></i>
                    Szczegółowa lista portów
                </h5>
                <div class="search-box" style="position: relative;">
                    <input type="text" id="portSearch" placeholder="Szukaj portu..." 
                           style="padding: 8px 35px 8px 15px; border: 1px solid #e5e7eb; border-radius: 6px; width: 250px;">
                    <i class="fas fa-search" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af;"></i>
                </div>
            </div>
            
            <div class="ports-table-wrapper" style="max-height: 400px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px;">
                <table class="ports-table">
                    <thead>
                        <tr>
                            <th style="width: 80px;">Port</th>
                            <th>Nazwa</th>
                            <th style="width: 100px;">Status</th>
                            <th style="width: 120px;">Prędkość</th>
                            <th>Podłączony do</th>
                            <th style="width: 120px;">Akcje</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ports as $port): 
                            $status_class = $port['port_status'] == 'up' ? 'status-up' : 
                                          ($port['port_status'] == 'down' ? 'status-down' : 'status-disabled');
                            $has_connection = !empty($port['connected_to']);
                        ?>
                        <tr class="port-row-data" data-port="<?php echo $port['port_number']; ?>"
                            data-status="<?php echo $port['port_status']; ?>"
                            data-speed="<?php echo $port['port_speed']; ?>"
                            data-connected="<?php echo $has_connection ? 'true' : 'false'; ?>">
                            <td style="text-align: center; font-weight: bold; font-family: monospace;">
                                <span class="port-badge"><?php echo str_pad($port['port_number'], 2, '0', STR_PAD_LEFT); ?></span>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <?php if ($has_connection): ?>
                                    <i class="fas fa-link" style="color: #3b82f6;"></i>
                                    <?php endif; ?>
                                    <span><?php echo htmlspecialchars($port['port_name']); ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <?php echo $port['port_status'] == 'up' ? 'Aktywny' : 
                                           ($port['port_status'] == 'down' ? 'Nieaktywny' : 'Wyłączony'); ?>
                                </span>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 5px;">
                                    <i class="fas fa-tachometer-alt" style="color: <?php echo $port['port_speed'] == '1G' ? '#f59e0b' : '#ec4899'; ?>;"></i>
                                    <span><?php echo htmlspecialchars($port['port_speed']); ?></span>
                                </div>
                            </td>
                            <td>
                                <?php if ($has_connection): ?>
                                <div class="connected-device">
                                    <i class="fas fa-desktop" style="margin-right: 5px; color: #6b7280;"></i>
                                    <span><?php echo htmlspecialchars($port['connected_to']); ?></span>
                                    <?php if (!empty($port['connected_device_type'])): ?>
                                    <span class="device-type">(<?php echo htmlspecialchars($port['connected_device_type']); ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <span class="text-muted">Nie podłączony</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn-action view" onclick="showPortDetails(<?php echo $port['port_number']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn-action edit" onclick="editPort(<?php echo $port['port_number']; ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn-action test" onclick="testPort(<?php echo $port['port_number']; ?>)">
                                        <i class="fas fa-bolt"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Podsumowanie -->
            <div class="table-summary" style="margin-top: 15px; padding: 12px; background: #f9fafb; border-radius: 6px; display: flex; justify-content: space-between;">
                <div>
                    <span class="text-muted">Wyświetlono:</span>
                    <strong><?php echo $total_ports; ?></strong> portów
                </div>
                <div>
                    <button class="btn-export" onclick="exportPorts()">
                        <i class="fas fa-download"></i> Eksportuj listę
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Nowe style dla ulepszonego widoku */

/* Karty statystyk */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 15px;
}

.stat-card {
    padding: 20px;
    border-radius: 12px;
    color: white;
    text-align: center;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    transition: transform 0.3s, box-shadow 0.3s;
    cursor: pointer;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.15);
}

.stat-icon {
    font-size: 24px;
    margin-bottom: 10px;
    opacity: 0.9;
}

.stat-value {
    font-size: 32px;
    font-weight: 700;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 14px;
    opacity: 0.9;
}

/* Przyciski filtrowania */
.filter-btn {
    padding: 8px 16px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 20px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
}

.filter-btn.active {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
    color: white;
    border-color: #8b5cf6;
}

.filter-btn:hover:not(.active) {
    background: #f3f4f6;
    border-color: #d1d5db;
}

/* Switch 3D */
.switch-3d-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 25px;
    margin: 30px 0;
}

.switch-3d {
    perspective: 1000px;
    width: 100%;
    max-width: 800px;
}

.switch-front {
    background: linear-gradient(135deg, #2d3748 0%, #4a5568 100%);
    border-radius: 12px;
    padding: 25px;
    position: relative;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    border: 1px solid #4a5568;
    transform-style: preserve-3d;
    transition: transform 0.5s;
}

.switch-front:hover {
    transform: rotateX(5deg) rotateY(5deg);
}

.switch-leds {
    position: absolute;
    top: 15px;
    left: 15px;
    display: flex;
    gap: 8px;
}

.led {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #9ca3af;
}

.led.active {
    animation: blink 2s infinite;
}

.led-power.active {
    background: #10b981;
    box-shadow: 0 0 8px #10b981;
}

.led-status.active {
    background: #3b82f6;
    box-shadow: 0 0 8px #3b82f6;
}

.led-link.active {
    background: #f59e0b;
    box-shadow: 0 0 8px #f59e0b;
}

.switch-logo {
    position: absolute;
    top: 15px;
    right: 15px;
    display: flex;
    align-items: center;
}

.sfp-ports {
    display: flex;
    justify-content: center;
    gap: 20px;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.sfp-port {
    width: 40px;
    height: 20px;
    background: #6b7280;
    border-radius: 4px;
    position: relative;
    cursor: pointer;
    transition: all 0.3s;
    border: 1px solid #4b5563;
}

.sfp-port:hover {
    background: #9ca3af;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

.sfp-indicator {
    position: absolute;
    top: 2px;
    right: 2px;
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: #9ca3af;
}

.sfp-port:hover .sfp-indicator {
    background: #3b82f6;
    box-shadow: 0 0 4px #3b82f6;
}

.port-label {
    position: absolute;
    bottom: -20px;
    left: 50%;
    transform: translateX(-50%);
    color: #9ca3af;
    font-size: 10px;
    white-space: nowrap;
}

.ethernet-ports {
    margin-top: 20px;
}

.port-row {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-bottom: 12px;
}

.ethernet-port {
    width: 35px;
    height: 25px;
    background: #4b5563;
    border-radius: 4px;
    position: relative;
    cursor: pointer;
    transition: all 0.3s;
    border: 1px solid #374151;
    display: flex;
    align-items: center;
    justify-content: center;
}

.ethernet-port:hover {
    transform: scale(1.1);
    z-index: 10;
}

.port-light {
    position: absolute;
    top: 2px;
    right: 2px;
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: #9ca3af;
}

.port-number {
    color: #d1d5db;
    font-size: 10px;
    font-weight: bold;
    font-family: monospace;
}

.connection-dot {
    position: absolute;
    bottom: -3px;
    left: 50%;
    transform: translateX(-50%);
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #3b82f6;
    box-shadow: 0 0 4px #3b82f6;
}

/* Statusy portów */
.port-up {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border-color: #10b981;
}

.port-up .port-light {
    background: #10b981;
    box-shadow: 0 0 6px #10b981;
    animation: pulse 1.5s infinite;
}

.port-down {
    background: linear-gradient(135deg, #4b5563 0%, #374151 100%);
}

.port-down:hover {
    background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
}

.connected {
    border-color: #3b82f6;
}

.speed-1g {
    border-left: 3px solid #f59e0b;
}

.speed-100m {
    border-left: 3px solid #ec4899;
}

.switch-labels {
    display: flex;
    justify-content: space-between;
    margin-top: 20px;
    color: #9ca3af;
    font-size: 11px;
}

.switch-legend {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    justify-content: center;
    padding: 15px;
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
}

.legend-color {
    width: 12px;
    height: 12px;
    border-radius: 3px;
}

.legend-color.active { background: #10b981; }
.legend-color.inactive { background: #4b5563; }
.legend-color.connected { background: #3b82f6; }
.legend-color.gigabit { background: #f59e0b; }
.legend-color.fast { background: #ec4899; }

/* Panel szczegółów portu */
.port-details-panel {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 90%;
    max-width: 500px;
    max-height: 80vh;
    background: white;
    border-radius: 12px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
    z-index: 10000;
    overflow: hidden;
    display: none;
}

.panel-header {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
    color: white;
    padding: 15px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.close-btn {
    background: none;
    border: none;
    color: white;
    font-size: 18px;
    cursor: pointer;
    padding: 5px;
}

.panel-content {
    padding: 20px;
    overflow-y: auto;
    max-height: calc(80vh - 60px);
}

/* Tabela portów */
.ports-table {
    width: 100%;
    border-collapse: collapse;
}

.ports-table th {
    background: #f9fafb;
    padding: 12px 15px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
}

.ports-table td {
    padding: 12px 15px;
    border-bottom: 1px solid #f3f4f6;
}

.ports-table tr:hover {
    background: #f9fafb;
}

.port-badge {
    display: inline-block;
    padding: 4px 8px;
    background: #f3f4f6;
    border-radius: 4px;
    font-family: monospace;
    font-size: 12px;
}

.status-badge {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}

.status-up {
    background: #d1fae5;
    color: #065f46;
}

.status-down {
    background: #fee2e2;
    color: #991b1b;
}

.status-disabled {
    background: #f3f4f6;
    color: #6b7280;
}

.connected-device {
    display: flex;
    align-items: center;
    gap: 5px;
}

.device-type {
    font-size: 11px;
    color: #9ca3af;
}

.action-buttons {
    display: flex;
    gap: 5px;
}

.btn-action {
    width: 32px;
    height: 32px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.btn-action.view {
    background: #dbeafe;
    color: #1d4ed8;
}

.btn-action.edit {
    background: #f3e8ff;
    color: #7c3aed;
}

.btn-action.test {
    background: #fef3c7;
    color: #d97706;
}

.btn-action:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.btn-export {
    padding: 8px 16px;
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    transition: transform 0.2s;
}

.btn-export:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
}

/* Animacje */
@keyframes blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

@keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(1.2); }
}

@keyframes slideIn {
    from { opacity: 0; transform: translate(-50%, -60%); }
    to { opacity: 1; transform: translate(-50%, -50%); }
}

/* Responsywność */
@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .filter-controls div {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-btn {
        width: 100%;
        justify-content: center;
    }
    
    .switch-legend {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .ports-table {
        display: block;
        overflow-x: auto;
    }
}
</style>

<script>
// Dane portów przekazane z PHP
const portsData = <?php echo json_encode($ports); ?>;
let currentPortFilter = 'all';

// Funkcje do obsługi interakcji
function highlightPort(portNumber) {
    const portElement = document.querySelector(`.ethernet-port[data-port="${portNumber}"]`);
    const tableRow = document.querySelector(`.port-row-data[data-port="${portNumber}"]`);
    
    if (portElement) {
        portElement.style.transform = 'scale(1.2)';
        portElement.style.boxShadow = '0 0 15px rgba(59, 130, 246, 0.5)';
        portElement.style.zIndex = '20';
    }
    
    if (tableRow) {
        tableRow.style.backgroundColor = '#e0e7ff';
    }
}

function unhighlightPort(portNumber) {
    const portElement = document.querySelector(`.ethernet-port[data-port="${portNumber}"]`);
    const tableRow = document.querySelector(`.port-row-data[data-port="${portNumber}"]`);
    
    if (portElement) {
        portElement.style.transform = '';
        portElement.style.boxShadow = '';
        portElement.style.zIndex = '';
    }
    
    if (tableRow) {
        tableRow.style.backgroundColor = '';
    }
}

function showPortDetails(portNumber) {
    const port = portsData.find(p => p.port_number == portNumber);
    if (!port) return;
    
    // Wypełnij panel szczegółów
    document.getElementById('selectedPortNumber').textContent = portNumber.toString().padStart(2, '0');
    
    const content = document.getElementById('portDetailsContent');
    content.innerHTML = `
        <div class="port-detail-section">
            <h6 style="color: #6b7280; margin-bottom: 15px; font-size: 14px;">Podstawowe informacje</h6>
            <div class="detail-grid">
                <div class="detail-item">
                    <label>Nazwa portu:</label>
                    <span class="detail-value">${escapeHtml(port.port_name)}</span>
                </div>
                <div class="detail-item">
                    <label>Status:</label>
                    <span class="detail-value status-${port.port_status}">
                        ${port.port_status == 'up' ? '✅ Aktywny' : '❌ Nieaktywny'}
                    </span>
                </div>
                <div class="detail-item">
                    <label>Typ portu:</label>
                    <span class="detail-value">${escapeHtml(port.port_type)}</span>
                </div>
                <div class="detail-item">
                    <label>Prędkość:</label>
                    <span class="detail-value speed-${port.port_speed.toLowerCase()}">
                        <i class="fas fa-tachometer-alt"></i> ${port.port_speed}
                    </span>
                </div>
            </div>
        </div>
        
        <div class="port-detail-section" style="margin-top: 20px;">
            <h6 style="color: #6b7280; margin-bottom: 15px; font-size: 14px;">Połączenia</h6>
            ${port.connected_to ? `
                <div class="connection-info">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                        <div style="width: 40px; height: 40px; background: #3b82f6; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-link" style="color: white; font-size: 20px;"></i>
                        </div>
                        <div>
                            <div style="font-weight: bold;">${escapeHtml(port.connected_to)}</div>
                            ${port.connected_device_type ? `<div style="font-size: 12px; color: #6b7280;">Typ: ${escapeHtml(port.connected_device_type)}</div>` : ''}
                        </div>
                    </div>
                </div>
            ` : `
                <div style="text-align: center; padding: 20px; background: #f9fafb; border-radius: 8px;">
                    <i class="fas fa-unlink" style="font-size: 32px; color: #9ca3af; margin-bottom: 10px;"></i>
                    <div style="color: #6b7280;">Port nie jest podłączony</div>
                </div>
            `}
        </div>
        
        ${port.notes ? `
        <div class="port-detail-section" style="margin-top: 20px;">
            <h6 style="color: #6b7280; margin-bottom: 15px; font-size: 14px;">Notatki</h6>
            <div style="padding: 15px; background: #f9fafb; border-radius: 8px; font-size: 14px;">
                ${escapeHtml(port.notes)}
            </div>
        </div>
        ` : ''}
        
        <div style="margin-top: 25px; display: flex; gap: 10px;">
            <button class="btn-action edit" onclick="editPort(${portNumber})" style="flex: 1;">
                <i class="fas fa-edit"></i> Edytuj port
            </button>
            <button class="btn-action test" onclick="testPort(${portNumber})" style="flex: 1;">
                <i class="fas fa-bolt"></i> Testuj połączenie
            </button>
        </div>
    `;
    
    // Pokaż panel z animacją
    const panel = document.getElementById('portDetailsPanel');
    panel.style.display = 'block';
    panel.style.animation = 'slideIn 0.3s ease-out';
}

function closePortDetails() {
    const panel = document.getElementById('portDetailsPanel');
    panel.style.animation = 'slideOut 0.3s ease-out';
    setTimeout(() => {
        panel.style.display = 'none';
    }, 300);
}

function filterPorts(filterType) {
    currentPortFilter = filterType;
    
    // Aktualizuj przyciski
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');
    
    // Filtruj wiersze tabeli
    document.querySelectorAll('.port-row-data').forEach(row => {
        const status = row.dataset.status;
        const speed = row.dataset.speed;
        const connected = row.dataset.connected;
        
        let show = false;
        
        switch(filterType) {
            case 'all':
                show = true;
                break;
            case 'up':
                show = status === 'up';
                break;
            case 'down':
                show = status === 'down';
                break;
            case 'connected':
                show = connected === 'true';
                break;
            case '1G':
                show = speed === '1G';
                break;
            case '100M':
                show = speed === '100M';
                break;
        }
        
        row.style.display = show ? '' : 'none';
    });
    
    // Filtruj porty w wizualizacji
    document.querySelectorAll('.ethernet-port, .sfp-port').forEach(port => {
        const portNumber = parseInt(port.dataset.port);
        const portData = portsData.find(p => p.port_number == portNumber);
        
        if (!portData) return;
        
        let show = false;
        
        switch(filterType) {
            case 'all':
                show = true;
                break;
            case 'up':
                show = portData.port_status === 'up';
                break;
            case 'down':
                show = portData.port_status === 'down';
                break;
            case 'connected':
                show = !!portData.connected_to;
                break;
            case '1G':
                show = portData.port_speed === '1G';
                break;
            case '100M':
                show = portData.port_speed === '100M';
                break;
        }
        
        port.style.opacity = show ? '1' : '0.3';
        port.style.pointerEvents = show ? 'auto' : 'none';
    });
}

function editPort(portNumber) {
    const port = portsData.find(p => p.port_number == portNumber);
    if (!port) return;
    
    // Tutaj można dodać logikę otwierania modalu edycji
    alert(`Edytowanie portu ${portNumber} - funkcja w budowie`);
    // W przyszłości: window.parent.editSwitchPorts(<?php echo $switch_id; ?>, portNumber);
}

function testPort(portNumber) {
    // Symulacja testu portu
    const portElement = document.querySelector(`.ethernet-port[data-port="${portNumber}"]`);
    const light = portElement?.querySelector('.port-light');
    
    if (light) {
        light.style.background = '#3b82f6';
        light.style.boxShadow = '0 0 10px #3b82f6';
        light.style.animation = 'pulse 0.5s 3';
        
        setTimeout(() => {
            const portData = portsData.find(p => p.port_number == portNumber);
            light.style.background = portData?.port_status === 'up' ? '#10b981' : '#9ca3af';
            light.style.boxShadow = portData?.port_status === 'up' ? '0 0 6px #10b981' : 'none';
            light.style.animation = '';
        }, 1500);
    }
    
    showNotification('info', `Testowanie portu ${portNumber}...`);
}

function exportPorts() {
    // Eksport danych portów do CSV
    let csv = 'Port,Nazwa,Status,Prędkość,Podłączony do,Typ urządzenia,Notatki\n';
    
    portsData.forEach(port => {
        csv += `"${port.port_number}","${port.port_name}","${port.port_status}","${port.port_speed}","${port.connected_to || ''}","${port.connected_device_type || ''}","${port.notes || ''}"\n`;
    });
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `switch_${<?php echo $switch_id; ?>}_ports_${new Date().toISOString().split('T')[0]}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    
    showNotification('success', 'Lista portów wyeksportowana pomyślnie');
}

// Wyszukiwanie w tabeli
document.getElementById('portSearch')?.addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    
    document.querySelectorAll('.port-row-data').forEach(row => {
        const portNumber = row.dataset.port;
        const portName = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
        const connectedTo = row.querySelector('td:nth-child(5)').textContent.toLowerCase();
        
        const matches = portNumber.includes(searchTerm) || 
                       portName.includes(searchTerm) || 
                       connectedTo.includes(searchTerm);
        
        row.style.display = matches ? '' : 'none';
    });
});

// Pomocnicze funkcje
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showNotification(type, message) {
    // Użyj istniejącej funkcji showNotification z map.php
    if (window.parent && window.parent.showNotification) {
        window.parent.showNotification(type, message);
    } else {
        alert(message);
    }
}

// Dodaj animacje CSS dla slideOut
if (!document.querySelector('#port-styles')) {
    const style = document.createElement('style');
    style.id = 'port-styles';
    style.textContent = `
        @keyframes slideOut {
            from { opacity: 1; transform: translate(-50%, -50%); }
            to { opacity: 0; transform: translate(-50%, -40%); }
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .detail-item {
            padding: 10px;
            background: #f9fafb;
            border-radius: 6px;
        }
        
        .detail-item label {
            display: block;
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 4px;
        }
        
        .detail-value {
            font-weight: 500;
            font-size: 14px;
        }
        
        .status-up { color: #10b981; }
        .status-down { color: #ef4444; }
        .speed-1g { color: #f59e0b; }
        .speed-100m { color: #ec4899; }
    `;
    document.head.appendChild(style);
}

// Inicjalizacja
document.addEventListener('DOMContentLoaded', function() {
    // Dodaj klasy statusów do portów
    document.querySelectorAll('.ethernet-port').forEach(portElement => {
        const portNumber = parseInt(portElement.dataset.port);
        const port = portsData.find(p => p.port_number == portNumber);
        
        if (port) {
            portElement.classList.add(`port-${port.port_status}`);
            if (port.connected_to) {
                portElement.classList.add('connected');
            }
            portElement.classList.add(`speed-${port.port_speed.toLowerCase()}`);
        }
    });
    
    // Obsługa kliknięcia poza panel szczegółów
    document.addEventListener('click', function(e) {
        const panel = document.getElementById('portDetailsPanel');
        if (panel && panel.style.display === 'block' && !panel.contains(e.target)) {
            closePortDetails();
        }
    });
});
</script>