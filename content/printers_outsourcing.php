<?php
// content/printers_outsourcing.php - Zarządzanie drukarkami outsourcing
global $db;
requirePermission('printers_outsourcing', 'view');

// Funkcja addMessage (jeśli nie istnieje)
if (!function_exists('addMessage')) {
    function addMessage($type, $text) {
        if (!isset($_SESSION['messages'])) $_SESSION['messages'] = [];
        if (count($_SESSION['messages']) >= 3) array_shift($_SESSION['messages']);
        $_SESSION['messages'][] = ['type' => $type, 'text' => $text];
    }
}

// Sprawdź i ewentualnie dodaj brakujące kolumny w tabeli printers_outsourcing
try {
    $columns = $db->query("SHOW COLUMNS FROM printers_outsourcing")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('status', $columns)) {
        $db->exec("ALTER TABLE printers_outsourcing ADD COLUMN status ENUM('Magazyn','Produkcja','Zlikwidowane') DEFAULT 'Magazyn'");
    }
    if (!in_array('liquidation_date', $columns)) {
        $db->exec("ALTER TABLE printers_outsourcing ADD COLUMN liquidation_date DATE DEFAULT NULL");
    }
} catch (PDOException $e) {
    // Tabela prawdopodobnie nie istnieje – utwórz ją wraz z kolumnami
    $db->exec("CREATE TABLE IF NOT EXISTS printers_outsourcing (
        id INT PRIMARY KEY AUTO_INCREMENT,
        number_id VARCHAR(100) NOT NULL,
        device_type VARCHAR(255),
        brand VARCHAR(255),
        model VARCHAR(255),
        serial_number VARCHAR(255),
        area VARCHAR(255),
        status ENUM('Magazyn','Produkcja','Zlikwidowane') DEFAULT 'Magazyn',
        liquidation_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
}

// Obsługa dodawania/edycji
if (isset($_POST['save_printer_outsourcing']) && hasPermission('printers_outsourcing', isset($_POST['id']) && $_POST['id'] > 0 ? 'edit' : 'create')) {
    $id = intval($_POST['id'] ?? 0);
    $number_id = trim($_POST['number_id'] ?? '');
    $device_type = trim($_POST['device_type'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $serial_number = trim($_POST['serial_number'] ?? '');
    $area = trim($_POST['area'] ?? '');
    $status = $_POST['status'] ?? 'Magazyn';
    $liquidation_date = $_POST['liquidation_date'] ?? null;

    if (empty($number_id)) {
        addMessage('error', 'Numer ID jest wymagany');
        header('Location: index.php?page=printers_outsourcing');
        exit;
    }

    // Automatyczne ustawienie daty likwidacji, jeśli status = Zlikwidowane
    if ($status === 'Zlikwidowane') {
        if (empty($liquidation_date)) {
            $liquidation_date = date('Y-m-d');
        }
    } else {
        $liquidation_date = null;
    }

    if ($id > 0) {
        $stmt = $db->prepare("UPDATE printers_outsourcing SET number_id=?, device_type=?, brand=?, model=?, serial_number=?, area=?, status=?, liquidation_date=? WHERE id=?");
        $stmt->execute([$number_id, $device_type, $brand, $model, $serial_number, $area, $status, $liquidation_date, $id]);
        addMessage('success', 'Drukarka outsourcing zaktualizowana');
        logActivity($_SESSION['user_id'], 'update_printer_outsourcing', 'printers_outsourcing', "Zaktualizowano: $number_id");
    } else {
        $stmt = $db->prepare("INSERT INTO printers_outsourcing (number_id, device_type, brand, model, serial_number, area, status, liquidation_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$number_id, $device_type, $brand, $model, $serial_number, $area, $status, $liquidation_date]);
        addMessage('success', 'Drukarka outsourcing dodana');
        logActivity($_SESSION['user_id'], 'add_printer_outsourcing', 'printers_outsourcing', "Dodano: $number_id");
    }
    header('Location: index.php?page=printers_outsourcing');
    exit;
}

// Obsługa usuwania
if (isset($_GET['action']) && $_GET['action'] == 'delete' && hasPermission('printers_outsourcing', 'delete')) {
    $id = intval($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $db->prepare("DELETE FROM printers_outsourcing WHERE id=?");
        $stmt->execute([$id]);
        addMessage('success', 'Drukarka outsourcing usunięta');
        logActivity($_SESSION['user_id'], 'delete_printer_outsourcing', 'printers_outsourcing', "Usunięto ID: $id");
    }
    header('Location: index.php?page=printers_outsourcing');
    exit;
}

// Pobierz listę drukarek outsourcing
$stmt = $db->query("SELECT * FROM printers_outsourcing ORDER BY number_id");
$printers_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$can_create = hasPermission('printers_outsourcing', 'create');
$can_edit = hasPermission('printers_outsourcing', 'edit');
$can_delete = hasPermission('printers_outsourcing', 'delete');

// Statystyki (opcjonalnie, można dodać kafelki jak w innych modułach)
$total_count = count($printers_list);
$liquidated_count = 0;
foreach ($printers_list as $p) {
    if ($p['status'] === 'Zlikwidowane') $liquidated_count++;
}
?>

<div class="dashboard-header">
    <h1>Drukarki – Outsourcing</h1>
    <p class="text-muted">Zarządzanie drukarkami z outsourcingu</p>
</div>

<!-- Statystyki w kafelkach -->
<div class="stats-grid" style="margin-bottom: 32px;">
    <div class="stat-card">
        <div class="stat-icon" style="background: #eff6ff;">
            <i class="fas fa-print"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $total_count; ?></div>
            <div class="stat-label">Wszystkich drukarek</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: #fee2e2;">
            <i class="fas fa-trash-alt"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $liquidated_count; ?></div>
            <div class="stat-label">Zlikwidowane</div>
        </div>
    </div>
</div>

<!-- Tabela -->
<div class="table-section">
    <div class="table-header">
        <h2>Lista drukarek</h2>
        <div class="table-actions">
            <input type="text" class="search-input" placeholder="Szukaj..." id="searchInput">
            <?php if ($can_create): ?>
            <button class="btn btn-primary" onclick="openPrinterModal()">
                <i class="fas fa-plus"></i> Dodaj drukarkę
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-responsive">
        <table class="printer-table" id="printersTable">
            <thead>
                <tr>
                    <th data-sort="index">#</th>
                    <th data-sort="number_id">Numer ID</th>
                    <th data-sort="device_type">Typ urządzenia</th>
                    <th data-sort="brand">Marka</th>
                    <th data-sort="model">Model</th>
                    <th data-sort="serial_number">Numer seryjny</th>
                    <th data-sort="area">Obszar</th>
                    <th data-sort="status">Status</th>
                    <th data-sort="liquidation_date">Data likwidacji</th>
                    <?php if ($can_edit || $can_delete): ?>
                    <th>Akcje</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody id="printersTableBody">
                <?php foreach ($printers_list as $index => $p): ?>
                <tr data-id="<?php echo $p['id']; ?>">
                    <td><?php echo $index + 1; ?></td>
                    <td><?php echo htmlspecialchars($p['number_id']); ?></td>
                    <td><?php echo htmlspecialchars($p['device_type'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($p['brand'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($p['model'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($p['serial_number'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($p['area'] ?? '—'); ?></td>
                    <td>
                        <?php
                        $status_class = '';
                        $status_text = $p['status'] ?? 'Magazyn';
                        if ($status_text == 'Magazyn') $status_class = 'badge badge-info';
                        elseif ($status_text == 'Produkcja') $status_class = 'badge badge-success';
                        elseif ($status_text == 'Zlikwidowane') $status_class = 'badge badge-danger';
                        ?>
                        <span class="<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                    </td>
                    <td><?php echo $p['liquidation_date'] ? date('d.m.Y', strtotime($p['liquidation_date'])) : '—'; ?></td>
                    <?php if ($can_edit || $can_delete): ?>
                    <td>
                        <div class="action-icons">
                            <?php if ($can_edit): ?>
                            <span class="action-icon" onclick="editPrinter(<?php echo $p['id']; ?>)" title="Edytuj">
                                <i class="fas fa-edit"></i>
                            </span>
                            <?php endif; ?>
                            <?php if ($can_delete): ?>
                            <span class="action-icon delete" onclick="confirmDelete(<?php echo $p['id']; ?>)" title="Usuń">
                                <i class="fas fa-trash"></i>
                            </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($can_create || $can_edit): ?>
<!-- Modal dodawania/edycji drukarki outsourcing -->
<div class="modal" id="printerModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Dodaj drukarkę outsourcing</h3>
            <button class="modal-close" onclick="closePrinterModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="post" id="printerForm">
            <input type="hidden" name="id" id="printerId" value="0">
            <div class="modal-body">
                <div class="form-group">
                    <label for="number_id">Numer ID *</label>
                    <input type="text" class="form-control" id="number_id" name="number_id" required>
                </div>
                <div class="form-group">
                    <label for="device_type">Typ urządzenia</label>
                    <input type="text" class="form-control" id="device_type" name="device_type">
                </div>
                <div class="form-group">
                    <label for="brand">Marka</label>
                    <input type="text" class="form-control" id="brand" name="brand">
                </div>
                <div class="form-group">
                    <label for="model">Model</label>
                    <input type="text" class="form-control" id="model" name="model">
                </div>
                <div class="form-group">
                    <label for="serial_number">Numer seryjny</label>
                    <input type="text" class="form-control" id="serial_number" name="serial_number">
                </div>
                <div class="form-group">
                    <label for="area">Obszar</label>
                    <input type="text" class="form-control" id="area" name="area">
                </div>
                <!-- Status -->
                <div class="form-group">
                    <label for="status">Status</label>
                    <select class="form-control" id="status" name="status" onchange="toggleLiquidationField()">
                        <option value="Magazyn">Magazyn</option>
                        <option value="Produkcja">Produkcja</option>
                        <option value="Zlikwidowane">Zlikwidowane</option>
                    </select>
                </div>
                <!-- Data likwidacji (widoczna tylko dla statusu Zlikwidowane) -->
                <div class="form-group" id="liquidationField" style="display: none;">
                    <label for="liquidation_date">Data likwidacji</label>
                    <input type="date" class="form-control" id="liquidation_date" name="liquidation_date">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closePrinterModal()">Anuluj</button>
                <button type="submit" name="save_printer_outsourcing" class="btn btn-primary">Zapisz</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
const printersData = <?php echo json_encode($printers_list); ?>;
const canEdit = <?php echo $can_edit ? 'true' : 'false'; ?>;
const canDelete = <?php echo $can_delete ? 'true' : 'false'; ?>;

let currentSort = { column: 'number_id', direction: 'asc' };

function toggleLiquidationField() {
    const status = document.getElementById('status').value;
    const field = document.getElementById('liquidationField');
    if (status === 'Zlikwidowane') {
        field.style.display = 'block';
    } else {
        field.style.display = 'none';
        document.getElementById('liquidation_date').value = '';
    }
}

function openPrinterModal() {
    document.getElementById('modalTitle').textContent = 'Dodaj drukarkę outsourcing';
    document.getElementById('printerId').value = '0';
    document.getElementById('printerForm').reset();
    document.getElementById('status').value = 'Magazyn';
    toggleLiquidationField();
    document.getElementById('printerModal').classList.add('active');
}

function editPrinter(id) {
    const item = printersData.find(p => p.id == id);
    if (!item) return;
    document.getElementById('modalTitle').textContent = 'Edytuj drukarkę outsourcing';
    document.getElementById('printerId').value = item.id;
    document.getElementById('number_id').value = item.number_id;
    document.getElementById('device_type').value = item.device_type || '';
    document.getElementById('brand').value = item.brand || '';
    document.getElementById('model').value = item.model || '';
    document.getElementById('serial_number').value = item.serial_number || '';
    document.getElementById('area').value = item.area || '';
    document.getElementById('status').value = item.status || 'Magazyn';
    toggleLiquidationField();
    if (item.liquidation_date) {
        document.getElementById('liquidation_date').value = item.liquidation_date.split(' ')[0];
    }
    document.getElementById('printerModal').classList.add('active');
}

function confirmDelete(id) {
    if (confirm('Czy na pewno chcesz usunąć tę drukarkę?')) {
        window.location.href = '?page=printers_outsourcing&action=delete&id=' + id;
    }
}

function closePrinterModal() {
    document.getElementById('printerModal').classList.remove('active');
}

// Sortowanie
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
        if (column === 'liquidation_date') {
            valA = a[column] ? new Date(a[column]).getTime() : 0;
            valB = b[column] ? new Date(b[column]).getTime() : 0;
            return direction === 'asc' ? valA - valB : valB - valA;
        }
        valA = (a[column] || '').toLowerCase();
        valB = (b[column] || '').toLowerCase();
        if (valA < valB) return direction === 'asc' ? -1 : 1;
        if (valA > valB) return direction === 'asc' ? 1 : -1;
        return 0;
    });
    renderTable(sorted);
}

function renderTable(data) {
    const tbody = document.getElementById('printersTableBody');
    let html = '';
    data.forEach((item, index) => {
        let statusClass = '';
        if (item.status === 'Magazyn') statusClass = 'badge badge-info';
        else if (item.status === 'Produkcja') statusClass = 'badge badge-success';
        else if (item.status === 'Zlikwidowane') statusClass = 'badge badge-danger';
        else statusClass = 'badge badge-secondary';

        html += `<tr data-id="${item.id}">`;
        html += `<td>${index + 1}</td>`;
        html += `<td>${escapeHtml(item.number_id)}</td>`;
        html += `<td>${escapeHtml(item.device_type || '—')}</td>`;
        html += `<td>${escapeHtml(item.brand || '—')}</td>`;
        html += `<td>${escapeHtml(item.model || '—')}</td>`;
        html += `<td>${escapeHtml(item.serial_number || '—')}</td>`;
        html += `<td>${escapeHtml(item.area || '—')}</td>`;
        html += `<td><span class="${statusClass}">${escapeHtml(item.status || 'Magazyn')}</span></td>`;
        html += `<td>${item.liquidation_date ? new Date(item.liquidation_date).toLocaleDateString('pl-PL') : '—'}</td>`;
        if (canEdit || canDelete) {
            html += `<td><div class="action-icons">`;
            if (canEdit) html += `<span class="action-icon" onclick="editPrinter(${item.id})" title="Edytuj"><i class="fas fa-edit"></i></span>`;
            if (canDelete) html += `<span class="action-icon delete" onclick="confirmDelete(${item.id})" title="Usuń"><i class="fas fa-trash"></i></span>`;
            html += `</div></td>`;
        }
        html += `</tr>`;
    });
    tbody.innerHTML = html;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function updateSortIndicators(clickedTh, direction) {
    document.querySelectorAll('th[data-sort]').forEach(th => th.classList.remove('sort-asc', 'sort-desc'));
    clickedTh.classList.add(direction === 'asc' ? 'sort-asc' : 'sort-desc');
}

// Wyszukiwanie
document.getElementById('searchInput').addEventListener('input', function(e) {
    const term = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#printersTableBody tr');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(term) ? '' : 'none';
    });
});

// Zamknięcie modala tylko przez przycisk – usuwamy nasłuchiwanie na tło
// (żaden event nie zamyka modala po kliknięciu poza nim)

document.addEventListener('DOMContentLoaded', function() {
    initSorting();
    document.querySelectorAll('th[data-sort]').forEach(th => {
        th.style.cursor = 'pointer';
    });
});
</script>

<style>
.badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}
.badge-info {
    background: #cff3ff;
    color: #055160;
}
.badge-success {
    background: #d1e7dd;
    color: #0a3622;
}
.badge-danger {
    background: #f8d7da;
    color: #58151c;
}
.badge-secondary {
    background: #e2e3e5;
    color: #41464b;
}
</style>