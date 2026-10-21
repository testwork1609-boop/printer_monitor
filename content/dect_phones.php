<?php
// content/dect_phones.php - Zarządzanie telefonami DECT i ładowarkami
global $db;
requirePermission('dect_phones', 'view');

// Funkcja addMessage (jeśli nie istnieje)
if (!function_exists('addMessage')) {
    function addMessage($type, $text) {
        if (!isset($_SESSION['messages'])) $_SESSION['messages'] = [];
        if (count($_SESSION['messages']) >= 3) array_shift($_SESSION['messages']);
        $_SESSION['messages'][] = ['type' => $type, 'text' => $text];
    }
}

// Sprawdź i ewentualnie dodaj brakujące kolumny w tabeli dect_phones
try {
    $columns = $db->query("SHOW COLUMNS FROM dect_phones")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('lps', $columns)) {
        $db->exec("ALTER TABLE dect_phones ADD COLUMN lps VARCHAR(255) DEFAULT ''");
    }
    if (!in_array('status', $columns)) {
        $db->exec("ALTER TABLE dect_phones ADD COLUMN status ENUM('Magazyn','Produkcja','Zlikwidowane') DEFAULT 'Magazyn'");
    }
    if (!in_array('liquidation_date', $columns)) {
        $db->exec("ALTER TABLE dect_phones ADD COLUMN liquidation_date DATE DEFAULT NULL");
    }
    if (!in_array('type', $columns)) {
        $db->exec("ALTER TABLE dect_phones ADD COLUMN type ENUM('Telefon','Ładowarka') DEFAULT 'Telefon'");
    }
} catch (PDOException $e) {
    // Tabela prawdopodobnie nie istnieje – utwórz ją wraz z kolumnami
    $db->exec("CREATE TABLE IF NOT EXISTS dect_phones (
        id INT PRIMARY KEY AUTO_INCREMENT,
        sn_phone VARCHAR(255) NOT NULL,
        ipei_phone VARCHAR(255),
        sn_doc_station VARCHAR(255), -- pozostawiamy, ale nie używamy
        owner VARCHAR(255),
        received_date DATE,
        issue_date DATE,
        phone_number VARCHAR(50),
        exchange_info TEXT,
        lps VARCHAR(255) DEFAULT '',
        status ENUM('Magazyn','Produkcja','Zlikwidowane') DEFAULT 'Magazyn',
        liquidation_date DATE DEFAULT NULL,
        type ENUM('Telefon','Ładowarka') DEFAULT 'Telefon',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
}

// Obsługa dodawania/edycji
if (isset($_POST['save_dect']) && hasPermission('dect_phones', isset($_POST['id']) && $_POST['id'] > 0 ? 'edit' : 'create')) {
    $id = intval($_POST['id'] ?? 0);
    $sn_phone = trim($_POST['sn_phone'] ?? '');
    $ipei_phone = trim($_POST['ipei_phone'] ?? '');
    $owner = trim($_POST['owner'] ?? '');
    $received_date = $_POST['received_date'] ?? null;
    $issue_date = $_POST['issue_date'] ?? null;
    $phone_number = trim($_POST['phone_number'] ?? '');
    $exchange_info = trim($_POST['exchange_info'] ?? '');
    $lps = trim($_POST['lps'] ?? '');
    $status = $_POST['status'] ?? 'Magazyn';
    $type = $_POST['type'] ?? 'Telefon';

    // Walidacja w zależności od typu
    if ($type === 'Telefon') {
        if (empty($lps)) {
            addMessage('error', 'Dla telefonu pole LPS jest wymagane.');
            header('Location: index.php?page=dect_phones');
            exit;
        }
        if (empty($ipei_phone)) {
            addMessage('error', 'Dla telefonu numer IPEI jest wymagany.');
            header('Location: index.php?page=dect_phones');
            exit;
        }
    } // Ładowarka – brak wymagań

    // Walidacja unikalności IPEI (tylko dla telefonów)
    if ($type === 'Telefon' && !empty($ipei_phone)) {
        $stmt = $db->prepare("SELECT id FROM dect_phones WHERE ipei_phone = ? AND id != ? AND status != 'Zlikwidowane'");
        $stmt->execute([$ipei_phone, $id]);
        if ($stmt->fetch()) {
            addMessage('error', 'Numer IPEI już istnieje w systemie (aktywne urządzenie).');
            header('Location: index.php?page=dect_phones');
            exit;
        }
    }

    // Data likwidacji – tylko jeśli status = Zlikwidowane
    $liquidation_date = null;
    if ($status === 'Zlikwidowane') {
        $liquidation_date = $_POST['liquidation_date'] ?? null;
        if (empty($liquidation_date)) {
            addMessage('error', 'Przy statusie "Zlikwidowane" należy podać datę likwidacji.');
            header('Location: index.php?page=dect_phones');
            exit;
        }
    }

    if ($sn_phone) {
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE dect_phones SET sn_phone=?, ipei_phone=?, owner=?, received_date=?, issue_date=?, phone_number=?, exchange_info=?, lps=?, status=?, liquidation_date=?, type=? WHERE id=?");
            $stmt->execute([$sn_phone, $ipei_phone, $owner, $received_date, $issue_date, $phone_number, $exchange_info, $lps, $status, $liquidation_date, $type, $id]);
            addMessage('success', 'Urządzenie zaktualizowane');
            logActivity($_SESSION['user_id'], 'update_dect', 'dect_phones', "Zaktualizowano: $sn_phone");
        } else {
            $stmt = $db->prepare("INSERT INTO dect_phones (sn_phone, ipei_phone, owner, received_date, issue_date, phone_number, exchange_info, lps, status, liquidation_date, type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$sn_phone, $ipei_phone, $owner, $received_date, $issue_date, $phone_number, $exchange_info, $lps, $status, $liquidation_date, $type]);
            addMessage('success', 'Urządzenie dodane');
            logActivity($_SESSION['user_id'], 'add_dect', 'dect_phones', "Dodano: $sn_phone");
        }
        header('Location: index.php?page=dect_phones');
        exit;
    } else {
        addMessage('error', 'Numer seryjny jest wymagany');
    }
}

// Obsługa usuwania
if (isset($_GET['action']) && $_GET['action'] == 'delete' && hasPermission('dect_phones', 'delete')) {
    $id = intval($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $db->prepare("DELETE FROM dect_phones WHERE id=?");
        $stmt->execute([$id]);
        addMessage('success', 'Urządzenie usunięte');
        logActivity($_SESSION['user_id'], 'delete_dect', 'dect_phones', "Usunięto ID: $id");
    }
    header('Location: index.php?page=dect_phones');
    exit;
}

// Pobierz listę urządzeń
$stmt = $db->query("SELECT * FROM dect_phones ORDER BY sn_phone");
$dect_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$can_create = hasPermission('dect_phones', 'create');
$can_edit = hasPermission('dect_phones', 'edit');
$can_delete = hasPermission('dect_phones', 'delete');
?>

<div class="dashboard-header">
    <h1>Telefony DECT i ładowarki</h1>
    <p class="text-muted">Zarządzanie urządzeniami</p>
</div>

<!-- Tabela -->
<div class="table-section">
    <div class="table-header">
        <h2>Lista urządzeń</h2>
        <div class="table-actions">
            <input type="text" class="search-input" placeholder="Szukaj..." id="searchInput">
            <?php if ($can_create): ?>
            <button class="btn btn-primary" onclick="openDectModal()">
                <i class="fas fa-plus"></i> Dodaj urządzenie
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-responsive">
        <table class="printer-table" id="dectTable">
            <thead>
                <tr>
                    <th data-sort="index">#</th>
                    <th data-sort="lps">LPS</th>
                    <th data-sort="sn_phone">Numer seryjny</th>
                    <th data-sort="ipei_phone">IPEI</th>
                    <th data-sort="owner">Właściciel</th>
                    <th data-sort="received_date">Data przyjęcia</th>
                    <th data-sort="issue_date">Data wydania</th>
                    <th data-sort="phone_number">Numer telefonu</th>
                    <th data-sort="type">Typ</th>
                    <th data-sort="status">Status</th>
                    <th data-sort="liquidation_date">Data likwidacji</th>
                    <th data-sort="exchange_info">Uwagi</th>
                    <?php if ($can_edit || $can_delete): ?>
                    <th>Akcje</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody id="dectTableBody">
                <?php foreach ($dect_list as $index => $d): ?>
                <tr data-id="<?php echo $d['id']; ?>">
                    <td><?php echo $index + 1; ?></td>
                    <td><?php echo htmlspecialchars($d['lps'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($d['sn_phone']); ?></td>
                    <td><?php echo htmlspecialchars($d['ipei_phone'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($d['owner'] ?? '—'); ?></td>
                    <td><?php echo $d['received_date'] ? date('d.m.Y', strtotime($d['received_date'])) : 'Przed ewidencją'; ?></td>
                    <td><?php echo $d['issue_date'] ? date('d.m.Y', strtotime($d['issue_date'])) : 'N/D'; ?></td>
                    <td><?php echo htmlspecialchars($d['phone_number'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($d['type'] ?? 'Telefon'); ?></td>
                    <td>
                        <?php
                        $status_class = '';
                        $status_text = $d['status'] ?? 'Magazyn';
                        if ($status_text == 'Magazyn') $status_class = 'badge badge-info';
                        elseif ($status_text == 'Produkcja') $status_class = 'badge badge-success';
                        elseif ($status_text == 'Zlikwidowane') $status_class = 'badge badge-danger';
                        ?>
                        <span class="<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                    </td>
                    <td><?php echo $d['liquidation_date'] ? date('d.m.Y', strtotime($d['liquidation_date'])) : '—'; ?></td>
                    <td><?php echo htmlspecialchars($d['exchange_info'] ?? '—'); ?></td>
                    <?php if ($can_edit || $can_delete): ?>
                    <td>
                        <div class="action-icons">
                            <?php if ($can_edit): ?>
                            <span class="action-icon" onclick="editDect(<?php echo $d['id']; ?>)" title="Edytuj">
                                <i class="fas fa-edit"></i>
                            </span>
                            <?php endif; ?>
                            <?php if ($can_delete): ?>
                            <span class="action-icon delete" onclick="confirmDelete(<?php echo $d['id']; ?>)" title="Usuń">
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
<!-- Modal dodawania/edycji -->
<div class="modal" id="dectModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Dodaj urządzenie</h3>
            <button class="modal-close" onclick="closeDectModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="post" id="dectForm">
            <input type="hidden" name="id" id="dectId" value="0">
            <div class="modal-body">
                <!-- Typ -->
                <div class="form-group">
                    <label for="type">Typ</label>
                    <select class="form-control" id="type" name="type" onchange="toggleFields()">
                        <option value="Telefon">Telefon</option>
                        <option value="Ładowarka">Ładowarka</option>
                    </select>
                </div>
                <!-- LPS -->
                <div class="form-group" id="lpsGroup">
                    <label for="lps">LPS</label>
                    <input type="text" class="form-control" id="lps" name="lps">
                </div>
                <!-- Numer seryjny -->
                <div class="form-group">
                    <label for="sn_phone">Numer seryjny *</label>
                    <input type="text" class="form-control" id="sn_phone" name="sn_phone" required>
                </div>
                <!-- IPEI -->
                <div class="form-group" id="ipeiGroup">
                    <label for="ipei_phone">IPEI</label>
                    <input type="text" class="form-control" id="ipei_phone" name="ipei_phone">
                </div>
                <!-- Właściciel -->
                <div class="form-group">
                    <label for="owner">Właściciel</label>
                    <input type="text" class="form-control" id="owner" name="owner">
                </div>
                <!-- Daty -->
                <div class="form-row" style="display: flex; gap: 16px;">
                    <div class="form-group" style="flex: 1;">
                        <label for="received_date">Data przyjęcia</label>
                        <input type="date" class="form-control" id="received_date" name="received_date">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label for="issue_date">Data wydania</label>
                        <input type="date" class="form-control" id="issue_date" name="issue_date">
                    </div>
                </div>
                <!-- Numer telefonu -->
                <div class="form-group">
                    <label for="phone_number">Numer telefonu</label>
                    <input type="text" class="form-control" id="phone_number" name="phone_number">
                </div>
                <!-- Uwagi -->
                <div class="form-group">
                    <label for="exchange_info">Uwagi</label>
                    <textarea class="form-control" id="exchange_info" name="exchange_info" rows="2"></textarea>
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
                    <div style="display: flex; gap: 8px;">
                        <input type="date" class="form-control" id="liquidation_date" name="liquidation_date" style="flex: 1;">
                        <button type="button" class="btn btn-outline-secondary" onclick="setToday()">Dzisiaj</button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeDectModal()">Anuluj</button>
                <button type="submit" name="save_dect" class="btn btn-primary">Zapisz</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
const dectData = <?php echo json_encode($dect_list); ?>;
const canEdit = <?php echo $can_edit ? 'true' : 'false'; ?>;
const canDelete = <?php echo $can_delete ? 'true' : 'false'; ?>;

let currentSort = { column: 'sn_phone', direction: 'asc' };

function toggleFields() {
    const type = document.getElementById('type').value;
    const lpsGroup = document.getElementById('lpsGroup');
    const ipeiGroup = document.getElementById('ipeiGroup');
    if (type === 'Telefon') {
        lpsGroup.style.display = 'block';
        ipeiGroup.style.display = 'block';
        document.getElementById('lps').required = true;
        document.getElementById('ipei_phone').required = true;
    } else {
        lpsGroup.style.display = 'none';
        ipeiGroup.style.display = 'none';
        document.getElementById('lps').required = false;
        document.getElementById('ipei_phone').required = false;
    }
}

function toggleLiquidationField() {
    const status = document.getElementById('status').value;
    const field = document.getElementById('liquidationField');
    if (status === 'Zlikwidowane') {
        field.style.display = 'block';
    } else {
        field.style.display = 'none';
        // Opcjonalnie wyczyść wartość
        document.getElementById('liquidation_date').value = '';
    }
}

function setToday() {
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('liquidation_date').value = today;
}

function openDectModal() {
    document.getElementById('modalTitle').textContent = 'Dodaj urządzenie';
    document.getElementById('dectId').value = '0';
    document.getElementById('dectForm').reset();
    document.getElementById('type').value = 'Telefon';
    document.getElementById('status').value = 'Magazyn';
    toggleFields();
    toggleLiquidationField(); // ukryje pole daty likwidacji
    document.getElementById('dectModal').classList.add('active');
}

function editDect(id) {
    const item = dectData.find(d => d.id == id);
    if (!item) return;
    document.getElementById('modalTitle').textContent = 'Edytuj urządzenie';
    document.getElementById('dectId').value = item.id;
    document.getElementById('type').value = item.type || 'Telefon';
    document.getElementById('lps').value = item.lps || '';
    document.getElementById('sn_phone').value = item.sn_phone;
    document.getElementById('ipei_phone').value = item.ipei_phone || '';
    document.getElementById('owner').value = item.owner || '';
    document.getElementById('received_date').value = item.received_date ? item.received_date.split(' ')[0] : '';
    document.getElementById('issue_date').value = item.issue_date ? item.issue_date.split(' ')[0] : '';
    document.getElementById('phone_number').value = item.phone_number || '';
    document.getElementById('exchange_info').value = item.exchange_info || '';
    document.getElementById('status').value = item.status || 'Magazyn';
    toggleFields();
    toggleLiquidationField();

    // Ustaw datę likwidacji, jeśli istnieje
    if (item.liquidation_date) {
        document.getElementById('liquidation_date').value = item.liquidation_date.split(' ')[0];
    } else {
        document.getElementById('liquidation_date').value = '';
    }

    document.getElementById('dectModal').classList.add('active');
}

function confirmDelete(id) {
    if (confirm('Czy na pewno chcesz usunąć to urządzenie?')) {
        window.location.href = '?page=dect_phones&action=delete&id=' + id;
    }
}

function closeDectModal() {
    document.getElementById('dectModal').classList.remove('active');
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
    const sorted = [...dectData].sort((a, b) => {
        let valA, valB;
        if (column === 'index') {
            valA = dectData.indexOf(a) + 1;
            valB = dectData.indexOf(b) + 1;
            return direction === 'asc' ? valA - valB : valB - valA;
        }
        if (column === 'received_date' || column === 'issue_date' || column === 'liquidation_date') {
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
    const tbody = document.getElementById('dectTableBody');
    let html = '';
    data.forEach((item, index) => {
        let statusClass = '';
        if (item.status === 'Magazyn') statusClass = 'badge badge-info';
        else if (item.status === 'Produkcja') statusClass = 'badge badge-success';
        else if (item.status === 'Zlikwidowane') statusClass = 'badge badge-danger';
        else statusClass = 'badge badge-secondary';

        html += `<tr data-id="${item.id}">`;
        html += `<td>${index + 1}</td>`;
        html += `<td>${escapeHtml(item.lps || '—')}</td>`;
        html += `<td>${escapeHtml(item.sn_phone)}</td>`;
        html += `<td>${escapeHtml(item.ipei_phone || '—')}</td>`;
        html += `<td>${escapeHtml(item.owner || '—')}</td>`;
        html += `<td>${item.received_date ? new Date(item.received_date).toLocaleDateString('pl-PL') : 'Przed ewidencją'}</td>`;
        html += `<td>${item.issue_date ? new Date(item.issue_date).toLocaleDateString('pl-PL') : 'N/D'}</td>`;
        html += `<td>${escapeHtml(item.phone_number || '—')}</td>`;
        html += `<td>${escapeHtml(item.type || 'Telefon')}</td>`;
        html += `<td><span class="${statusClass}">${escapeHtml(item.status || 'Magazyn')}</span></td>`;
        html += `<td>${item.liquidation_date ? new Date(item.liquidation_date).toLocaleDateString('pl-PL') : '—'}</td>`;
        html += `<td>${escapeHtml(item.exchange_info || '—')}</td>`;
        if (canEdit || canDelete) {
            html += `<td><div class="action-icons">`;
            if (canEdit) html += `<span class="action-icon" onclick="editDect(${item.id})" title="Edytuj"><i class="fas fa-edit"></i></span>`;
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
    const rows = document.querySelectorAll('#dectTableBody tr');
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