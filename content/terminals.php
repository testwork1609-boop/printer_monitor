<?php
// content/terminals.php - Zarządzanie terminalami
global $db;
requirePermission('terminals', 'view');

// Funkcja addMessage (jeśli nie istnieje)
if (!function_exists('addMessage')) {
    function addMessage($type, $text) {
        if (!isset($_SESSION['messages'])) $_SESSION['messages'] = [];
        if (count($_SESSION['messages']) >= 3) array_shift($_SESSION['messages']);
        $_SESSION['messages'][] = ['type' => $type, 'text' => $text];
    }
}

// Obsługa dodawania/edycji
if (isset($_POST['save_terminal']) && hasPermission('terminals', isset($_POST['id']) && $_POST['id'] > 0 ? 'edit' : 'create')) {
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $device_type = trim($_POST['device_type'] ?? '');
    $owner = trim($_POST['owner'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $brand = trim($_POST['brand'] ?? '');

    if ($name) {
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE terminals SET name=?, device_type=?, owner=?, location=?, brand=? WHERE id=?");
            $stmt->execute([$name, $device_type, $owner, $location, $brand, $id]);
            addMessage('success', 'Terminal zaktualizowany');
            logActivity($_SESSION['user_id'], 'update_terminal', 'terminals', "Zaktualizowano terminal: $name");
        } else {
            $stmt = $db->prepare("INSERT INTO terminals (name, device_type, owner, location, brand) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $device_type, $owner, $location, $brand]);
            addMessage('success', 'Terminal dodany');
            logActivity($_SESSION['user_id'], 'add_terminal', 'terminals', "Dodano terminal: $name");
        }
        header('Location: index.php?page=terminals');
        exit;
    } else {
        addMessage('error', 'Nazwa terminala jest wymagana');
    }
}

// Obsługa usuwania
if (isset($_GET['action']) && $_GET['action'] == 'delete' && hasPermission('terminals', 'delete')) {
    $id = intval($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $db->prepare("DELETE FROM terminals WHERE id=?");
        $stmt->execute([$id]);
        addMessage('success', 'Terminal usunięty');
        logActivity($_SESSION['user_id'], 'delete_terminal', 'terminals', "Usunięto terminal ID: $id");
    }
    header('Location: index.php?page=terminals');
    exit;
}

// Pobierz listę terminali
$stmt = $db->query("SELECT * FROM terminals ORDER BY name");
$terminals_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$can_create = hasPermission('terminals', 'create');
$can_edit = hasPermission('terminals', 'edit');
$can_delete = hasPermission('terminals', 'delete');
?>

<div class="dashboard-header">
    <h1>Terminale</h1>
    <p class="text-muted">Zarządzanie terminalami</p>
</div>

<!-- Tabela -->
<div class="table-section">
    <div class="table-header">
        <h2>Lista terminali</h2>
        <div class="table-actions">
            <input type="text" class="search-input" placeholder="Szukaj..." id="searchInput">
            <?php if ($can_create): ?>
            <button class="btn btn-primary" onclick="openTerminalModal()">
                <i class="fas fa-plus"></i> Dodaj terminal
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-responsive">
        <table class="printer-table" id="terminalsTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nazwa</th>
                    <th>Typ urządzenia</th>
                    <th>Właściciel</th>
                    <th>Lokalizacja</th>
                    <th>Marka</th>
                    <?php if ($can_edit || $can_delete): ?>
                    <th>Akcje</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody id="terminalsTableBody">
                <?php foreach ($terminals_list as $index => $t): ?>
                <tr data-id="<?php echo $t['id']; ?>">
                    <td><?php echo $index + 1; ?></td>
                    <td><?php echo htmlspecialchars($t['name']); ?></td>
                    <td><?php echo htmlspecialchars($t['device_type'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($t['owner'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($t['location'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($t['brand'] ?? '—'); ?></td>
                    <?php if ($can_edit || $can_delete): ?>
                    <td>
                        <div class="action-icons">
                            <?php if ($can_edit): ?>
                            <span class="action-icon" onclick="editTerminal(<?php echo $t['id']; ?>)" title="Edytuj">
                                <i class="fas fa-edit"></i>
                            </span>
                            <?php endif; ?>
                            <?php if ($can_delete): ?>
                            <span class="action-icon delete" onclick="confirmDelete(<?php echo $t['id']; ?>)" title="Usuń">
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
<!-- Modal dodawania/edycji terminala -->
<div class="modal" id="terminalModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Dodaj terminal</h3>
            <button class="modal-close" onclick="closeModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="post" id="terminalForm">
            <input type="hidden" name="id" id="terminalId" value="0">
            <div class="modal-body">
                <div class="form-group">
                    <label for="name">Nazwa *</label>
                    <input type="text" class="form-control" id="name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="device_type">Typ urządzenia</label>
                    <input type="text" class="form-control" id="device_type" name="device_type">
                </div>
                <div class="form-group">
                    <label for="owner">Właściciel</label>
                    <input type="text" class="form-control" id="owner" name="owner">
                </div>
                <div class="form-group">
                    <label for="location">Lokalizacja</label>
                    <input type="text" class="form-control" id="location" name="location">
                </div>
                <div class="form-group">
                    <label for="brand">Marka urządzenia</label>
                    <input type="text" class="form-control" id="brand" name="brand">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal()">Anuluj</button>
                <button type="submit" name="save_terminal" class="btn btn-primary">Zapisz</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
const terminalsData = <?php echo json_encode($terminals_list); ?>;

function openTerminalModal() {
    document.getElementById('modalTitle').textContent = 'Dodaj terminal';
    document.getElementById('terminalId').value = '0';
    document.getElementById('terminalForm').reset();
    document.getElementById('terminalModal').classList.add('active');
}

function editTerminal(id) {
    const item = terminalsData.find(t => t.id == id);
    if (!item) return;
    document.getElementById('modalTitle').textContent = 'Edytuj terminal';
    document.getElementById('terminalId').value = item.id;
    document.getElementById('name').value = item.name;
    document.getElementById('device_type').value = item.device_type || '';
    document.getElementById('owner').value = item.owner || '';
    document.getElementById('location').value = item.location || '';
    document.getElementById('brand').value = item.brand || '';
    document.getElementById('terminalModal').classList.add('active');
}

function confirmDelete(id) {
    if (confirm('Czy na pewno chcesz usunąć ten terminal?')) {
        window.location.href = '?page=terminals&action=delete&id=' + id;
    }
}

function closeModal() {
    document.getElementById('terminalModal').classList.remove('active');
}

// Wyszukiwanie
document.getElementById('searchInput').addEventListener('input', function(e) {
    const term = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#terminalsTableBody tr');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(term) ? '' : 'none';
    });
});
</script>