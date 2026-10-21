<?php
// content/peripherals.php - Zarządzanie urządzeniami peryferyjnymi
global $db;
requirePermission('peripherals', 'view');

// Funkcja addMessage (jeśli nie istnieje)
if (!function_exists('addMessage')) {
    function addMessage($type, $text) {
        if (!isset($_SESSION['messages'])) $_SESSION['messages'] = [];
        if (count($_SESSION['messages']) >= 3) array_shift($_SESSION['messages']);
        $_SESSION['messages'][] = ['type' => $type, 'text' => $text];
    }
}

// Sprawdź i ewentualnie dodaj brakujące kolumny w tabeli peripherals
try {
    $columns = $db->query("SHOW COLUMNS FROM peripherals")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('type', $columns)) {
        $db->exec("ALTER TABLE peripherals ADD COLUMN type ENUM('Klawiatura','Klawiatura B/P','Mysz','Mysz B/P','Zestaw (M+K)') DEFAULT 'Klawiatura'");
    }
    if (!in_array('brand', $columns)) {
        $db->exec("ALTER TABLE peripherals ADD COLUMN brand VARCHAR(255) DEFAULT ''");
    }
    if (!in_array('model', $columns)) {
        $db->exec("ALTER TABLE peripherals ADD COLUMN model VARCHAR(255) DEFAULT ''");
    }
    if (!in_array('status', $columns)) {
        $db->exec("ALTER TABLE peripherals ADD COLUMN status ENUM('Magazyn','Produkcja','Zlikwidowane') DEFAULT 'Magazyn'");
    }
    if (!in_array('liquidation_date', $columns)) {
        $db->exec("ALTER TABLE peripherals ADD COLUMN liquidation_date DATE DEFAULT NULL");
    }
} catch (PDOException $e) {
    // Tabela prawdopodobnie nie istnieje – utwórz ją wraz z kolumnami
    $db->exec("CREATE TABLE IF NOT EXISTS peripherals (
        id INT PRIMARY KEY AUTO_INCREMENT,
        serial_number VARCHAR(255) NOT NULL,
        user_assigned VARCHAR(255),
        purchase_date DATE,
        issue_date DATE,
        type ENUM('Klawiatura','Klawiatura B/P','Mysz','Mysz B/P','Zestaw (M+K)') DEFAULT 'Klawiatura',
        brand VARCHAR(255) DEFAULT '',
        model VARCHAR(255) DEFAULT '',
        status ENUM('Magazyn','Produkcja','Zlikwidowane') DEFAULT 'Magazyn',
        liquidation_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
}

// Obsługa dodawania/edycji
if (isset($_POST['save_peripheral']) && hasPermission('peripherals', isset($_POST['id']) && $_POST['id'] > 0 ? 'edit' : 'create')) {
    $id = intval($_POST['id'] ?? 0);
    $serial_number = trim($_POST['serial_number'] ?? '');
    $user_assigned = trim($_POST['user_assigned'] ?? '');
    $purchase_date = $_POST['purchase_date'] ?? null;
    $issue_date = $_POST['issue_date'] ?? null;
    $type = $_POST['type'] ?? 'Klawiatura';
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $status = $_POST['status'] ?? 'Magazyn';
    $liquidation_date = $_POST['liquidation_date'] ?? null;

    // Walidacja
    if (empty($serial_number)) {
        addMessage('error', 'Numer seryjny jest wymagany');
        header('Location: index.php?page=peripherals');
        exit;
    }

    // Automatyczne ustawienie daty likwidacji, jeśli status = Zlikwidowane
    if ($status === 'Zlikwidowane') {
        if (empty($liquidation_date)) {
            $liquidation_date = date('Y-m-d'); // dzisiejsza data
        }
    } else {
        // Jeśli status nie jest Zlikwidowane, czyścimy datę likwidacji
        $liquidation_date = null;
    }

    if ($id > 0) {
        $stmt = $db->prepare("UPDATE peripherals SET serial_number=?, user_assigned=?, purchase_date=?, issue_date=?, type=?, brand=?, model=?, status=?, liquidation_date=? WHERE id=?");
        $stmt->execute([$serial_number, $user_assigned, $purchase_date, $issue_date, $type, $brand, $model, $status, $liquidation_date, $id]);
        addMessage('success', 'Urządzenie peryferyjne zaktualizowane');
        logActivity($_SESSION['user_id'], 'update_peripheral', 'peripherals', "Zaktualizowano: $serial_number");
    } else {
        $stmt = $db->prepare("INSERT INTO peripherals (serial_number, user_assigned, purchase_date, issue_date, type, brand, model, status, liquidation_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$serial_number, $user_assigned, $purchase_date, $issue_date, $type, $brand, $model, $status, $liquidation_date]);
        addMessage('success', 'Urządzenie peryferyjne dodane');
        logActivity($_SESSION['user_id'], 'add_peripheral', 'peripherals', "Dodano: $serial_number");
    }
    header('Location: index.php?page=peripherals');
    exit;
}

// Obsługa usuwania
if (isset($_GET['action']) && $_GET['action'] == 'delete' && hasPermission('peripherals', 'delete')) {
    $id = intval($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $db->prepare("DELETE FROM peripherals WHERE id=?");
        $stmt->execute([$id]);
        addMessage('success', 'Urządzenie peryferyjne usunięte');
        logActivity($_SESSION['user_id'], 'delete_peripheral', 'peripherals', "Usunięto ID: $id");
    }
    header('Location: index.php?page=peripherals');
    exit;
}

// Pobierz listę urządzeń peryferyjnych
$stmt = $db->query("SELECT * FROM peripherals ORDER BY serial_number");
$peripherals_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statystyki
$total_count = count($peripherals_list);
$liquidated_count = 0;
$total_usage_days = 0;
$items_with_dates = 0;

foreach ($peripherals_list as $p) {
    if ($p['status'] === 'Zlikwidowane' && !empty($p['purchase_date']) && !empty($p['liquidation_date'])) {
        $start = new DateTime($p['purchase_date']);
        $end = new DateTime($p['liquidation_date']);
        $days = $end->diff($start)->days;
        $total_usage_days += $days;
        $items_with_dates++;
        $liquidated_count++;
    } elseif ($p['status'] === 'Zlikwidowane') {
        $liquidated_count++;
    }
}

$avg_usage_days = $items_with_dates > 0 ? round($total_usage_days / $items_with_dates, 1) : 0;

$can_create = hasPermission('peripherals', 'create');
$can_edit = hasPermission('peripherals', 'edit');
$can_delete = hasPermission('peripherals', 'delete');
?>

<div class="dashboard-header">
    <h1>Urządzenia peryferyjne</h1>
    <p class="text-muted">Zarządzanie klawiaturami, myszami i zestawami</p>
</div>

<!-- Statystyki w kafelkach -->
<div class="stats-grid" style="margin-bottom: 32px;">
    <div class="stat-card">
        <div class="stat-icon" style="background: #eff6ff;">
            <i class="fas fa-boxes"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $total_count; ?></div>
            <div class="stat-label">Wszystkich urządzeń</div>
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
    <div class="stat-card">
        <div class="stat-icon" style="background: #fef3c7;">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $avg_usage_days; ?></div>
            <div class="stat-label">Średni czas użytkowania (dni)</div>
        </div>
    </div>
</div>

<!-- Tabela -->
<div class="table-section">
    <div class="table-header">
        <h2>Lista urządzeń peryferyjnych</h2>
        <div class="table-actions">
            <input type="text" class="search-input" placeholder="Szukaj..." id="searchInput">
            <?php if ($can_create): ?>
            <button class="btn btn-primary" onclick="openPeripheralModal()">
                <i class="fas fa-plus"></i> Dodaj urządzenie
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-responsive">
        <table class="printer-table" id="peripheralsTable">
            <thead>
                <tr>
                    <th data-sort="index">#</th>
                    <th data-sort="type">Typ</th>
                    <th data-sort="brand">Marka</th>
                    <th data-sort="model">Model</th>
                    <th data-sort="serial_number">Numer seryjny</th>
                    <th data-sort="user_assigned">Użytkownik</th>
                    <th data-sort="purchase_date">Data zakupu</th>
                    <th data-sort="issue_date">Data wydania</th>
                    <th data-sort="status">Status</th>
                    <th data-sort="liquidation_date">Data likwidacji</th>
                    <?php if ($can_edit || $can_delete): ?>
                    <th>Akcje</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody id="peripheralsTableBody">
                <?php foreach ($peripherals_list as $index => $p): ?>
                <tr data-id="<?php echo $p['id']; ?>">
                    <td><?php echo $index + 1; ?></td>
                    <td><?php echo htmlspecialchars($p['type'] ?? 'Klawiatura'); ?></td>
                    <td><?php echo htmlspecialchars($p['brand'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($p['model'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($p['serial_number']); ?></td>
                    <td><?php echo htmlspecialchars($p['user_assigned'] ?? '—'); ?></td>
                    <td><?php echo $p['purchase_date'] ? date('d.m.Y', strtotime($p['purchase_date'])) : 'N/D'; ?></td>
                    <td><?php echo $p['issue_date'] ? date('d.m.Y', strtotime($p['issue_date'])) : 'N/D'; ?></td>
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
                            <span class="action-icon" onclick="editPeripheral(<?php echo $p['id']; ?>)" title="Edytuj">
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
<!-- Modal dodawania/edycji urządzenia peryferyjnego -->
<div class="modal" id="peripheralModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Dodaj urządzenie peryferyjne</h3>
            <button class="modal-close" onclick="closePeripheralModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="post" id="peripheralForm">
            <input type="hidden" name="id" id="peripheralId" value="0">
            <div class="modal-body">
                <!-- Typ -->
                <div class="form-group">
                    <label for="type">Typ</label>
                    <select class="form-control" id="type" name="type">
                        <option value="Klawiatura">Klawiatura</option>
                        <option value="Klawiatura B/P">Klawiatura B/P</option>
                        <option value="Mysz">Mysz</option>
                        <option value="Mysz B/P">Mysz B/P</option>
                        <option value="Zestaw (M+K)">Zestaw (M+K)</option>
                    </select>
                </div>
                <!-- Marka -->
                <div class="form-group">
                    <label for="brand">Marka</label>
                    <input type="text" class="form-control" id="brand" name="brand">
                </div>
                <!-- Model -->
                <div class="form-group">
                    <label for="model">Model</label>
                    <input type="text" class="form-control" id="model" name="model">
                </div>
                <!-- Numer seryjny -->
                <div class="form-group">
                    <label for="serial_number">Numer seryjny *</label>
                    <input type="text" class="form-control" id="serial_number" name="serial_number" required>
                </div>
                <!-- Użytkownik -->
                <div class="form-group">
                    <label for="user_assigned">Użytkownik (komu wydano)</label>
                    <input type="text" class="form-control" id="user_assigned" name="user_assigned">
                </div>
                <!-- Daty -->
                <div class="form-row" style="display: flex; gap: 16px;">
                    <div class="form-group" style="flex: 1;">
                        <label for="purchase_date">Data zakupu</label>
                        <input type="date" class="form-control" id="purchase_date" name="purchase_date">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label for="issue_date">Data wydania</label>
                        <input type="date" class="form-control" id="issue_date" name="issue_date">
                    </div>
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
                <!-- Data likwidacji (widoczna tylko gdy status = Zlikwidowane) -->
                <div class="form-group" id="liquidationField" style="display: none;">
                    <label for="liquidation_date">Data likwidacji</label>
                    <input type="date" class="form-control" id="liquidation_date" name="liquidation_date">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closePeripheralModal()">Anuluj</button>
                <button type="submit" name="save_peripheral" class="btn btn-primary">Zapisz</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
const peripheralsData = <?php echo json_encode($peripherals_list); ?>;
const canEdit = <?php echo $can_edit ? 'true' : 'false'; ?>;
const canDelete = <?php echo $can_delete ? 'true' : 'false'; ?>;

let currentSort = { column: 'serial_number', direction: 'asc' };

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

function openPeripheralModal() {
    document.getElementById('modalTitle').textContent = 'Dodaj urządzenie peryferyjne';
    document.getElementById('peripheralId').value = '0';
    document.getElementById('peripheralForm').reset();
    document.getElementById('type').value = 'Klawiatura';
    document.getElementById('status').value = 'Magazyn';
    toggleLiquidationField();
    document.getElementById('peripheralModal').classList.add('active');
}

function editPeripheral(id) {
    const item = peripheralsData.find(p => p.id == id);
    if (!item) return;
    document.getElementById('modalTitle').textContent = 'Edytuj urządzenie peryferyjne';
    document.getElementById('peripheralId').value = item.id;
    document.getElementById('type').value = item.type || 'Klawiatura';
    document.getElementById('brand').value = item.brand || '';
    document.getElementById('model').value = item.model || '';
    document.getElementById('serial_number').value = item.serial_number;
    document.getElementById('user_assigned').value = item.user_assigned || '';
    document.getElementById('purchase_date').value = item.purchase_date ? item.purchase_date.split(' ')[0] : '';
    document.getElementById('issue_date').value = item.issue_date ? item.issue_date.split(' ')[0] : '';
    document.getElementById('status').value = item.status || 'Magazyn';
    toggleLiquidationField();
    if (item.liquidation_date) {
        document.getElementById('liquidation_date').value = item.liquidation_date.split(' ')[0];
    }
    document.getElementById('peripheralModal').classList.add('active');
}

function confirmDelete(id) {
    if (confirm('Czy na pewno chcesz usunąć to urządzenie?')) {
        window.location.href = '?page=peripherals&action=delete&id=' + id;
    }
}

function closePeripheralModal() {
    document.getElementById('peripheralModal').classList.remove('active');
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
    const sorted = [...peripheralsData].sort((a, b) => {
        let valA, valB;
        if (column === 'index') {
            valA = peripheralsData.indexOf(a) + 1;
            valB = peripheralsData.indexOf(b) + 1;
            return direction === 'asc' ? valA - valB : valB - valA;
        }
        if (column === 'purchase_date' || column === 'issue_date' || column === 'liquidation_date') {
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
    const tbody = document.getElementById('peripheralsTableBody');
    let html = '';
    data.forEach((item, index) => {
        let statusClass = '';
        if (item.status === 'Magazyn') statusClass = 'badge badge-info';
        else if (item.status === 'Produkcja') statusClass = 'badge badge-success';
        else if (item.status === 'Zlikwidowane') statusClass = 'badge badge-danger';
        else statusClass = 'badge badge-secondary';

        html += `<tr data-id="${item.id}">`;
        html += `<td>${index + 1}</td>`;
        html += `<td>${escapeHtml(item.type || 'Klawiatura')}</td>`;
        html += `<td>${escapeHtml(item.brand || '—')}</td>`;
        html += `<td>${escapeHtml(item.model || '—')}</td>`;
        html += `<td>${escapeHtml(item.serial_number)}</td>`;
        html += `<td>${escapeHtml(item.user_assigned || '—')}</td>`;
        html += `<td>${item.purchase_date ? new Date(item.purchase_date).toLocaleDateString('pl-PL') : 'N/D'}</td>`;
        html += `<td>${item.issue_date ? new Date(item.issue_date).toLocaleDateString('pl-PL') : 'N/D'}</td>`;
        html += `<td><span class="${statusClass}">${escapeHtml(item.status || 'Magazyn')}</span></td>`;
        html += `<td>${item.liquidation_date ? new Date(item.liquidation_date).toLocaleDateString('pl-PL') : '—'}</td>`;
        if (canEdit || canDelete) {
            html += `<td><div class="action-icons">`;
            if (canEdit) html += `<span class="action-icon" onclick="editPeripheral(${item.id})" title="Edytuj"><i class="fas fa-edit"></i></span>`;
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
    const rows = document.querySelectorAll('#peripheralsTableBody tr');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(term) ? '' : 'none';
    });
});

// Zamknięcie modala tylko przez przycisk
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