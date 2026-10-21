<?php
// content/reports.php - Raporty i statystyki materiałów eksploatacyjnych
global $db;
requirePermission('reports', 'view');

// Sprawdź czy tabele raportów istnieją
$tables = $db->query("SHOW TABLES LIKE 'material_alerts'")->fetchAll();
if (count($tables) == 0) {
    echo '<div class="dashboard-header">';
    echo '<h1>Raporty materiałów eksploatacyjnych</h1>';
    echo '<p class="text-muted">Baza danych nie została jeszcze skonfigurowana dla raportów.</p>';
    echo '<a href="install_reports_tables.php" class="btn btn-primary">Uruchom instalator</a>';
    echo '</div>';
    exit;
}

// Pobierz statystyki ogólne – POPRAWIONE julianday na DATEDIFF
$stmt = $db->query("SELECT 
    COUNT(*) as total_alerts,
    SUM(CASE WHEN resolved_date IS NOT NULL THEN 1 ELSE 0 END) as resolved,
    AVG(DATEDIFF(resolved_date, alert_date)) as avg_waiting_days
    FROM material_alerts");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Pobierz historię wymian
$stmt = $db->query("SELECT mr.*, p.name as printer_name, p.host_number 
                    FROM material_replacements mr
                    JOIN printers p ON mr.printer_id = p.id
                    ORDER BY mr.replacement_date DESC
                    LIMIT 50");
$replacements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pobierz aktualne alerty (nierozwiązane)
$stmt = $db->query("SELECT ma.*, p.name as printer_name, p.host_number 
                    FROM material_alerts ma
                    JOIN printers p ON ma.printer_id = p.id
                    WHERE ma.resolved_date IS NULL
                    ORDER BY ma.alert_date DESC");
$open_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Przygotuj dane do wykresu kołowego – alerty według typu materiału
$stmt = $db->query("SELECT material_type, COUNT(*) as count 
                    FROM material_alerts 
                    GROUP BY material_type 
                    ORDER BY count DESC");
$pie_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Przygotuj dane do wykresu liniowego – średni czas oczekiwania w ostatnich 30 dniach – POPRAWIONE
$stmt = $db->query("SELECT DATE(replacement_date) as day, AVG(days_waiting) as avg_days
                    FROM material_replacements
                    WHERE replacement_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    GROUP BY day
                    ORDER BY day");
$line_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
$line_labels = array_column($line_data, 'day');
$line_values = array_column($line_data, 'avg_days');

// Oblicz liczbę alertów bez materiału w magazynie
$alerts_without_stock = 0;
foreach ($open_alerts as $alert) {
    $stmt = $db->prepare("SELECT SUM(quantity) as total FROM materials WHERE color = ? AND quantity > 0");
    $stmt->execute([$alert['material_type']]);
    $stock = $stmt->fetch(PDO::FETCH_ASSOC);
    if (($stock['total'] ?? 0) == 0) {
        $alerts_without_stock++;
    }
}
?>
<!-- Reszta HTML bez zmian – kopiuj z poprzedniej wersji -->

<div class="dashboard-header">
    <h1>Raporty materiałów eksploatacyjnych</h1>
    <p class="text-muted">Statystyki zużycia i czasu oczekiwania na wymianę</p>
</div>

<div class="stats-grid" style="margin-bottom: 32px;">
    <div class="stat-card">
        <div class="stat-icon" style="background: #eff6ff;">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $stats['total_alerts'] ?? 0; ?></div>
            <div class="stat-label">Łączna liczba alertów</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: #f0fdf4;">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $stats['resolved'] ?? 0; ?></div>
            <div class="stat-label">Rozwiązane</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: #fef3c7;">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo round($stats['avg_waiting_days'] ?? 0, 1); ?></div>
            <div class="stat-label">Średni czas oczekiwania (dni)</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: #fee2e2;">
            <i class="fas fa-exclamation-circle"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $alerts_without_stock; ?></div>
            <div class="stat-label">Alerty bez materiału</div>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 32px;">
    <div class="table-section">
        <div class="table-header">
            <h2><i class="fas fa-chart-pie"></i> Alerty według typu materiału</h2>
        </div>
        <div style="padding: 20px;">
            <canvas id="pieChart" style="max-height: 300px;"></canvas>
        </div>
    </div>
    <div class="table-section">
        <div class="table-header">
            <h2><i class="fas fa-chart-line"></i> Średni czas oczekiwania (ostatnie 30 dni)</h2>
        </div>
        <div style="padding: 20px;">
            <canvas id="lineChart" style="max-height: 300px;"></canvas>
        </div>
    </div>
</div>

<!-- Aktywne alerty -->
<div class="table-section" style="margin-bottom: 32px;">
    <div class="table-header">
        <h2><i class="fas fa-bell"></i> Aktywne alerty (oczekujące na wymianę)</h2>
    </div>
    <div class="table-container">
        <table class="printer-table">
            <thead>
                <tr>
                    <th>Drukarka</th>
                    <th>Nr Hees</th>
                    <th>Materiał</th>
                    <th>Poziom alertu</th>
                    <th>Aktualny poziom</th>
                    <th>Data alertu</th>
                    <th>Dni oczekiwania</th>
                    <th>Dostępność w magazynie</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($open_alerts)): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted">Brak aktywnych alertów</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($open_alerts as $alert): 
                        $days = floor((time() - strtotime($alert['alert_date'])) / 86400);
                        
                        // Sprawdź dostępność w magazynie
                        $stmt = $db->prepare("SELECT SUM(quantity) as total FROM materials WHERE color = ? AND quantity > 0");
                        $stmt->execute([$alert['material_type']]);
                        $stock = $stmt->fetch(PDO::FETCH_ASSOC);
                        $stock_total = $stock['total'] ?? 0;
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($alert['printer_name']); ?></td>
                        <td><?php echo htmlspecialchars($alert['host_number']); ?></td>
                        <td><?php echo htmlspecialchars($alert['material_type']); ?></td>
                        <td><?php echo $alert['alert_level']; ?>%</td>
                        <td><?php echo $alert['current_level']; ?>%</td>
                        <td><?php echo date('d.m.Y H:i', strtotime($alert['alert_date'])); ?></td>
                        <td><strong><?php echo $days; ?></strong> dni</td>
                        <td>
                            <?php if ($stock_total > 0): ?>
                                <span style="color: #10b981;">✅ Dostępny (<?php echo $stock_total; ?>)</span>
                            <?php else: ?>
                                <span style="color: #ef4444; font-weight: bold;">❌ Brak na magazynie</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Historia wymian -->
<div class="table-section">
    <div class="table-header">
        <h2><i class="fas fa-history"></i> Historia wymian (ostatnie 50)</h2>
    </div>
    <div class="table-container">
        <table class="printer-table">
            <thead>
                <tr>
                    <th>Data wymiany</th>
                    <th>Drukarka</th>
                    <th>Nr Hees</th>
                    <th>Materiał</th>
                    <th>Data alertu</th>
                    <th>Czas oczekiwania (dni)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($replacements)): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted">Brak historii wymian</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($replacements as $r): ?>
                    <tr>
                        <td><?php echo date('d.m.Y H:i', strtotime($r['replacement_date'])); ?></td>
                        <td><?php echo htmlspecialchars($r['printer_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['host_number']); ?></td>
                        <td><?php echo htmlspecialchars($r['material_type']); ?></td>
                        <td><?php echo date('d.m.Y H:i', strtotime($r['alert_date'])); ?></td>
                        <td><strong><?php echo $r['days_waiting']; ?></strong> dni</td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Wykres kołowy
    const pieCtx = document.getElementById('pieChart').getContext('2d');
    new Chart(pieCtx, {
        type: 'pie',
        data: {
            labels: <?php echo json_encode(array_column($pie_data, 'material_type')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_column($pie_data, 'count')); ?>,
                backgroundColor: [
                    '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#6366f1'
                ]
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });

    // Wykres liniowy
    const lineCtx = document.getElementById('lineChart').getContext('2d');
    new Chart(lineCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($line_labels); ?>,
            datasets: [{
                label: 'Średni czas oczekiwania (dni)',
                data: <?php echo json_encode($line_values); ?>,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
});
</script>