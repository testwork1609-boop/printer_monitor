<?php
// content/dashboard.php - Dashboard informacyjny
global $db;

// Pobierz statystyki użytkowników
$stmt = $db->query("SELECT COUNT(*) as total, 
                           SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                           SUM(login_count) as total_logins
                    FROM users");
$user_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Status drukarek
$stmt = $db->query("SELECT p.id, p.name, p.model, ps.status 
                    FROM printers p 
                    LEFT JOIN printer_status ps ON p.id = ps.printer_id 
                    WHERE p.is_active = 1");
$printers_status = $stmt->fetchAll(PDO::FETCH_ASSOC);

$online_printers = 0;
foreach ($printers_status as $p) {
    if ($p['status'] === 'online') {
        $online_printers++;
    }
}
$offline_printers = count($printers_status) - $online_printers;

// Drukarki z szybkim zużyciem tonera
$stmt = $db->query("SELECT p.name, p.model, ps.materials_json, ps.last_check, p.hall, p.location
                    FROM printers p
                    LEFT JOIN printer_status ps ON p.id = ps.printer_id
                    WHERE p.is_active = 1 AND ps.status = 'online'");
$printers_materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

$low_toner_printers = [];
$critical_toner_printers = [];

foreach ($printers_materials as $printer) {
    $materials = json_decode($printer['materials_json'] ?? '{}', true);
    if (!is_array($materials)) continue;
    
    $low_colors = [];
    $critical_colors = [];
    
    foreach (['black', 'cyan', 'magenta', 'yellow'] as $color) {
        if (isset($materials[$color]) && is_numeric($materials[$color])) {
            $level = $materials[$color];
            if ($level <= 15) {
                $critical_colors[] = [
                    'color' => $color,
                    'level' => $level
                ];
            } elseif ($level <= 30) {
                $low_colors[] = [
                    'color' => $color,
                    'level' => $level
                ];
            }
        }
    }
    
    if (!empty($critical_colors)) {
        $critical_toner_printers[] = [
            'name' => $printer['name'],
            'model' => $printer['model'],
            'hall' => $printer['hall'],
            'location' => $printer['location'],
            'colors' => $critical_colors
        ];
    } elseif (!empty($low_colors)) {
        $low_toner_printers[] = [
            'name' => $printer['name'],
            'model' => $printer['model'],
            'hall' => $printer['hall'],
            'location' => $printer['location'],
            'colors' => $low_colors
        ];
    }
}

// Nadchodzące przeglądy/wymiany (7 dni) – POPRAWIONE NA MySQL
$stmt = $db->query("SELECT ms.*, p.name as printer_name, p.model 
                    FROM maintenance_schedule ms
                    JOIN printers p ON ms.printer_id = p.id
                    WHERE ms.completed = 0 
                    AND ms.scheduled_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)
                    ORDER BY ms.scheduled_date ASC
                    LIMIT 5");
$upcoming_maintenance = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Alerty systemowe
$stmt = $db->query("SELECT * FROM system_alerts 
                    WHERE is_active = 1 
                    ORDER BY created_at DESC 
                    LIMIT 3");
$system_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statystyki materiałów
$stmt = $db->query("SELECT COUNT(*) as total,
                           SUM(quantity) as total_quantity,
                           SUM(CASE WHEN quantity <= min_quantity AND min_quantity > 0 THEN 1 ELSE 0 END) as low_stock
                    FROM materials");
$material_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Statystyki aktywności (ostatnie 24h) – POPRAWIONE
$stmt = $db->query("SELECT COUNT(*) as activity_count,
                           COUNT(DISTINCT user_id) as active_users,
                           GROUP_CONCAT(DISTINCT module) as modules
                    FROM activity_logs 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
$activity_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// ... reszta kodu bez zmian (zmienne, HTML, modale, skrypty) ...
// Poniżej kontynuacja oryginalnego dashboard.php – pozostaje taka sama

$alert_type_colors = [
    'critical' => 'danger',
    'warning' => 'warning',
    'info' => 'primary'
];

$alert_type_icons = [
    'critical' => 'fa-exclamation-circle',
    'warning' => 'fa-exclamation-triangle',
    'info' => 'fa-info-circle'
];

$color_names = [
    'black' => 'Czarny',
    'cyan' => 'Niebieski',
    'magenta' => 'Różowy',
    'yellow' => 'Żółty'
];

$color_icons = [
    'black' => '⬛',
    'cyan' => '🟦',
    'magenta' => '🟪',
    'yellow' => '🟨'
];
?>

<!-- Reszta HTML bez zmian -->
<div class="dashboard-header">
    <h1>Dashboard</h1>
    <p class="text-muted">Przegląd systemu monitorowania drukarek</p>
</div>

<?php if (!empty($system_alerts)): ?>
<div class="alerts-section">
    <?php foreach ($system_alerts as $alert): ?>
    <div class="alert alert-<?php echo $alert_type_colors[$alert['type']] ?? 'info'; ?>" style="margin-bottom: 16px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <i class="fas <?php echo $alert_type_icons[$alert['type']] ?? 'fa-info-circle'; ?>" style="font-size: 24px;"></i>
            <div style="flex: 1;">
                <strong style="display: block; margin-bottom: 4px;"><?php echo htmlspecialchars($alert['title']); ?></strong>
                <span><?php echo htmlspecialchars($alert['message']); ?></span>
            </div>
            <button class="btn btn-outline btn-sm" onclick="dismissAlert(<?php echo $alert['id']; ?>)">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Główne statystyki -->
<div class="stats-grid" style="margin-bottom: 32px;">
    <div class="stat-card">
        <div class="stat-icon" style="background: #eff6ff; color: var(--primary);">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $user_stats['active']; ?></div>
            <div class="stat-label">Aktywni użytkownicy</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #f0fdf4; color: var(--success);">
            <i class="fas fa-print"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $online_printers; ?>/<?php echo count($printers_status); ?></div>
            <div class="stat-label">Drukarki online</div>
        </div>
    </div>
    
    <div class="stat-card" onclick="showLowTonerPrinters()" style="cursor: pointer;">
        <div class="stat-icon" style="background: #fef2f2; color: var(--danger);">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo count($low_toner_printers); ?></div>
            <div class="stat-label">Niskie tonery (≤30%)</div>
        </div>
    </div>
    
    <div class="stat-card" onclick="showCriticalTonerPrinters()" style="cursor: pointer;">
        <div class="stat-icon" style="background: #7f1d1d; color: white;">
            <i class="fas fa-skull-crossbones"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo count($critical_toner_printers); ?></div>
            <div class="stat-label">Krytyczne (≤15%)</div>
        </div>
    </div>
</div>

<!-- Statystyki aktywności -->
<div class="stats-grid" style="margin-bottom: 32px;">
    <div class="stat-card">
        <div class="stat-icon" style="background: #fef3c7; color: #d97706;">
            <i class="fas fa-history"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $activity_stats['activity_count'] ?? 0; ?></div>
            <div class="stat-label">Aktywności (24h)</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #fce7f3; color: #be185d;">
            <i class="fas fa-user-clock"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $activity_stats['active_users'] ?? 0; ?></div>
            <div class="stat-label">Użytkowników (24h)</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #ecfccb; color: #65a30d;">
            <i class="fas fa-boxes"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $material_stats['total'] ?? 0; ?></div>
            <div class="stat-label">Pozycji w magazynie</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #ffedd5; color: #ea580c;">
            <i class="fas fa-exclamation"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $material_stats['low_stock'] ?? 0; ?></div>
            <div class="stat-label">Niski stan magazynu</div>
        </div>
    </div>
</div>

<!-- Ostatnie aktywności systemowe -->
<div class="table-section" style="margin-bottom: 32px;">
    <div class="table-header">
        <h2><i class="fas fa-history"></i> Ostatnie aktywności systemowe</h2>
    </div>
    <div class="table-container">
        <?php
        $stmt = $db->query("SELECT a.*, u.username 
                            FROM activity_logs a 
                            LEFT JOIN users u ON a.user_id = u.id 
                            ORDER BY a.created_at DESC 
                            LIMIT 10");
        $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $action_labels = [
            'login' => 'Logowanie',
            'logout' => 'Wylogowanie',
            'add_printer' => 'Dodanie drukarki',
            'update_printer' => 'Aktualizacja drukarki',
            'delete_printer' => 'Usunięcie drukarki',
            'add_material' => 'Dodanie materiału',
            'update_material' => 'Aktualizacja materiału',
            'delete_material' => 'Usunięcie materiału',
            'add_user' => 'Dodanie użytkownika',
            'update_user' => 'Aktualizacja użytkownika',
            'delete_user' => 'Usunięcie użytkownika',
            'password_change' => 'Zmiana hasła',
            'update_profile' => 'Aktualizacja profilu',
            'update_config' => 'Aktualizacja konfiguracji',
            'check_printers' => 'Sprawdzenie drukarek',
            'scan_printers' => 'Skanowanie drukarek',
            'import_printers' => 'Import drukarek'
        ];
        ?>
        <table class="printer-table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Użytkownik</th>
                    <th>Akcja</th>
                    <th>Moduł</th>
                    <th>Szczegóły</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_activities)): ?>
                <tr>
                    <td colspan="5" class="text-center text-muted">Brak ostatnich aktywności</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($recent_activities as $activity): ?>
                    <tr>
                        <td><?php echo date('d.m.Y H:i', strtotime($activity['created_at'])); ?></td>
                        <td>
                            <?php if ($activity['username']): ?>
                                <span class="hall-badge"><?php echo htmlspecialchars($activity['username']); ?></span>
                            <?php else: ?>
                                <span class="text-muted">System</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($action_labels[$activity['action']] ?? $activity['action']); ?></td>
                        <td><span class="hall-badge" style="background: #e0e7ff;"><?php echo htmlspecialchars($activity['module'] ?? '—'); ?></span></td>
                        <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <?php echo htmlspecialchars($activity['details'] ?? '—'); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($upcoming_maintenance)): ?>
<div class="table-section" style="margin-bottom: 32px;">
    <div class="table-header">
        <h2><i class="fas fa-calendar-alt"></i> Nadchodzące przeglądy i wymiany</h2>
    </div>
    <div class="table-container">
        <table class="printer-table">
            <thead>
                <tr>
                    <th>Drukarka</th>
                    <th>Typ</th>
                    <th>Zaplanowana data</th>
                    <th>Pozostało dni</th>
                    <th>Notatki</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($upcoming_maintenance as $maintenance): 
                    $days_left = floor((strtotime($maintenance['scheduled_date']) - time()) / 86400);
                    $days_left = max(0, $days_left);
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($maintenance['printer_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($maintenance['type']); ?></td>
                    <td><?php echo date('d.m.Y', strtotime($maintenance['scheduled_date'])); ?></td>
                    <td>
                        <span class="status-badge <?php echo $days_left <= 2 ? 'offline' : ($days_left <= 7 ? 'offline' : 'online'); ?>" 
                              style="<?php echo $days_left <= 2 ? 'background: var(--danger);' : ($days_left <= 7 ? 'background: var(--warning);' : ''); ?>">
                            <?php echo $days_left; ?> dni
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars($maintenance['notes'] ?? '—'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ... dalsza część dashboard.php (modale, skrypty) ... -->
<!-- Reszta pliku pozostaje bez zmian – zachowaj oryginalny kod od tego miejsca -->


<!-- Informacja o braku statystyk wydruków -->
<div class="table-section" style="margin-bottom: 32px; background: #f8fafc;">
    <div style="padding: 32px; text-align: center;">
        <i class="fas fa-info-circle" style="font-size: 48px; color: var(--gray-400); margin-bottom: 16px;"></i>
        <h3 style="color: var(--gray-600); margin-bottom: 12px;">Statystyki wydruków</h3>
        <p class="text-muted" style="max-width: 600px; margin: 0 auto;">
            Statystyki dotyczące liczby wydruków nie są obecnie zbierane przez system.<br>
            Aby dodać tę funkcjonalność, skonfiguruj moduł SNMP w ustawieniach systemu.
        </p>
        <?php if (hasPermission('config', 'view')): ?>
        <a href="index.php?page=config_content" class="btn btn-outline" style="margin-top: 16px;">
            <i class="fas fa-cog"></i>
            Przejdź do konfiguracji
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Modal dla niskich tonerów -->
<div class="modal" id="lowTonerModal">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i> Drukarki z niskim tonerem (≤30%)</h3>
            <button class="modal-close" onclick="closeModal('lowTonerModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <?php if (empty($low_toner_printers)): ?>
                <div style="text-align: center; padding: 40px 24px;">
                    <i class="fas fa-check-circle" style="font-size: 48px; color: var(--success); margin-bottom: 16px;"></i>
                    <p class="text-muted">Brak drukarek z niskim tonerem!</p>
                    <small class="text-muted">Wszystkie tonery mają poziom powyżej 30%</small>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="printer-table" style="min-width: 600px;">
                        <thead>
                            <tr>
                                <th>Drukarka</th>
                                <th>Model</th>
                                <th>Hala</th>
                                <th>Lokalizacja</th>
                                <th>Tonery</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($low_toner_printers as $printer): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($printer['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($printer['model'] ?? 'N/D'); ?></td>
                                <td><span class="hall-badge"><?php echo htmlspecialchars($printer['hall'] ?? '—'); ?></span></td>
                                <td><?php echo htmlspecialchars($printer['location'] ?? '—'); ?></td>
                                <td>
                                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                        <?php foreach ($printer['colors'] as $color_info): ?>
                                        <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; background: #fef3c7; border-radius: 4px; font-size: 12px;">
                                            <?php echo $color_icons[$color_info['color']] ?? ''; ?>
                                            <?php echo htmlspecialchars($color_names[$color_info['color']] ?? $color_info['color']); ?>:
                                            <strong style="color: var(--warning);"><?php echo $color_info['level']; ?>%</strong>
                                        </span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="sendLowTonerAlert()">
                <i class="fas fa-paper-plane"></i>
                Wyślij powiadomienie
            </button>
            <button type="button" class="btn btn-primary" onclick="closeModal('lowTonerModal')">Zamknij</button>
        </div>
    </div>
</div>

<!-- Modal dla krytycznych tonerów -->
<div class="modal" id="criticalTonerModal">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header" style="background: #fef2f2; border-bottom: 1px solid var(--danger);">
            <h3 style="color: var(--danger);">
                <i class="fas fa-skull-crossbones"></i> KRYTYCZNY STAN TONERA! (≤15%)
            </h3>
            <button class="modal-close" onclick="closeModal('criticalTonerModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <?php if (empty($critical_toner_printers)): ?>
                <div style="text-align: center; padding: 40px 24px;">
                    <i class="fas fa-check-circle" style="font-size: 48px; color: var(--success); margin-bottom: 16px;"></i>
                    <p class="text-muted">Brak drukarek z krytycznym tonerem!</p>
                    <small class="text-muted">Wszystkie tonery mają poziom powyżej 15%</small>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="printer-table" style="min-width: 600px;">
                        <thead>
                            <tr style="background: #fef2f2;">
                                <th>Drukarka</th>
                                <th>Model</th>
                                <th>Hala</th>
                                <th>Lokalizacja</th>
                                <th>Tonery (krytyczne)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($critical_toner_printers as $printer): ?>
                            <tr style="background: #fef2f2;">
                                <td>
                                    <strong style="color: var(--danger);"><?php echo htmlspecialchars($printer['name']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($printer['model'] ?? 'N/D'); ?></td>
                                <td><span class="hall-badge"><?php echo htmlspecialchars($printer['hall'] ?? '—'); ?></span></td>
                                <td><?php echo htmlspecialchars($printer['location'] ?? '—'); ?></td>
                                <td>
                                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                        <?php foreach ($printer['colors'] as $color_info): ?>
                                        <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; background: #7f1d1d; color: white; border-radius: 4px; font-size: 12px;">
                                            <?php echo $color_icons[$color_info['color']] ?? ''; ?>
                                            <?php echo htmlspecialchars($color_names[$color_info['color']] ?? $color_info['color']); ?>:
                                            <strong style="color: white;"><?php echo $color_info['level']; ?>%</strong>
                                        </span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" style="background: #7f1d1d; color: white; border-color: #7f1d1d;" 
                    onclick="sendCriticalTonerAlert()">
                <i class="fas fa-skull-crossbones"></i>
                Wyślij alarm krytyczny
            </button>
            <button type="button" class="btn btn-primary" onclick="closeModal('criticalTonerModal')">Zamknij</button>
        </div>
    </div>
</div>

<script>
function dismissAlert(id) {
    fetch('ajax/dismiss_alert.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ alert_id: id })
    }).then(() => {
        location.reload();
    });
}

function showLowTonerPrinters() {
    document.getElementById('lowTonerModal').classList.add('active');
}

function showCriticalTonerPrinters() {
    document.getElementById('criticalTonerModal').classList.add('active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

function sendLowTonerAlert() {
    if (confirm('Czy na pewno chcesz wysłać powiadomienie o niskim tonerze do dostawcy?')) {
        fetch('ajax/send_alert.php?type=low', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Powiadomienie wysłane pomyślnie!');
                closeModal('lowTonerModal');
            } else {
                alert('Błąd wysyłania powiadomienia: ' + data.message);
            }
        })
        .catch(error => {
            alert('Błąd wysyłania powiadomienia: ' + error);
        });
    }
}

function sendCriticalTonerAlert() {
    if (confirm('Czy na pewno chcesz wysłać alarm krytyczny o tonerze do dostawcy?')) {
        fetch('ajax/send_alert.php?type=critical', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Alarm krytyczny wysłany pomyślnie!');
                closeModal('criticalTonerModal');
            } else {
                alert('Błąd wysyłania alarmu: ' + data.message);
            }
        })
        .catch(error => {
            alert('Błąd wysyłania alarmu: ' + error);
        });
    }
}

// Zamykanie modalów po kliknięciu poza
window.addEventListener('click', function(event) {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        if (event.target === modal) {
            modal.classList.remove('active');
        }
    });
});

// Zamykanie modalów klawiszem ESC
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            modal.classList.remove('active');
        });
    }
});
</script>