<?php
// content/ups.php - Zarządzanie urządzeniami UPS
global $db;
requirePermission('ups', 'view');

// Funkcja addMessage (jeśli nie istnieje)
if (!function_exists('addMessage')) {
    function addMessage($type, $text) {
        if (!isset($_SESSION['messages'])) $_SESSION['messages'] = [];
        if (count($_SESSION['messages']) >= 3) array_shift($_SESSION['messages']);
        $_SESSION['messages'][] = ['type' => $type, 'text' => $text];
    }
}

// Obsługa dodawania/edycji
if (isset($_POST['save_ups']) && hasPermission('ups', isset($_POST['id']) && $_POST['id'] > 0 ? 'edit' : 'create')) {
    $id = intval($_POST['id'] ?? 0);
    $machine_name = trim($_POST['machine_name'] ?? '');
    $ups_number = trim($_POST['ups_number'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $check_date = $_POST['check_date'] ?? null;
    $replacement_date = $_POST['replacement_date'] ?? null;
    $notes = trim($_POST['notes'] ?? '');
    $cam_count = intval($_POST['cam_count'] ?? 0);
    $mde_count = intval($_POST['mde_count'] ?? 0);

    if ($machine_name) {
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE ups_devices SET machine_name=?, ups_number=?, model=?, check_date=?, replacement_date=?, notes=?, cam_count=?, mde_count=? WHERE id=?");
            $stmt->execute([$machine_name, $ups_number, $model, $check_date, $replacement_date, $notes, $cam_count, $mde_count, $id]);
            addMessage('success', 'UPS zaktualizowany');
            logActivity($_SESSION['user_id'], 'update_ups', 'ups', "Zaktualizowano UPS: $machine_name");
        } else {
            $stmt = $db->prepare("INSERT INTO ups_devices (machine_name, ups_number, model, check_date, replacement_date, notes, cam_count, mde_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$machine_name, $ups_number, $model, $check_date, $replacement_date, $notes, $cam_count, $mde_count]);
            addMessage('success', 'UPS dodany');
            logActivity($_SESSION['user_id'], 'add_ups', 'ups', "Dodano UPS: $machine_name");
        }
        header('Location: index.php?page=ups');
        exit;
    } else {
        addMessage('error', 'Nazwa maszyny jest wymagana');
    }
}

// Obsługa usuwania
if (isset($_GET['action']) && $_GET['action'] == 'delete' && hasPermission('ups', 'delete')) {
    $id = intval($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $db->prepare("DELETE FROM ups_devices WHERE id=?");
        $stmt->execute([$id]);
        addMessage('success', 'UPS usunięty');
        logActivity($_SESSION['user_id'], 'delete_ups', 'ups', "Usunięto UPS ID: $id");
    }
    header('Location: index.php?page=ups');
    exit;
}

// Pobierz listę UPS-ów
$stmt = $db->query("SELECT * FROM ups_devices ORDER BY machine_name");
$ups_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$can_create = hasPermission('ups', 'create');
$can_edit = hasPermission('ups', 'edit');
$can_delete = hasPermission('ups', 'delete');
?>

<div class="dashboard-header">
    <h1>UPS</h1>
    <p class="text-muted">Zarządzanie urządzeniami UPS</p>
</div>

<!-- Tabela -->
<div class="table-section">
    <div class="table-header">
        <h2>Lista UPS</h2>
        <div class="table-actions">
            <input type="text" class="search-input" placeholder="Szukaj..." id="searchInput">
            <?php if ($can_create): ?>
            <button class="btn btn-primary" onclick="openUpsModal()">
                <i class="fas fa-plus"></i> Dodaj UPS
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-responsive">
        <table class="printer-table" id="upsTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nazwa maszyny</th>
                    <th>Numer UPS</th>
                    <th>Model</th>
                    <th>Data sprawdzenia</th>
                    <th>Data wymiany</th>
                    <th>Uwagi</th>
                    <th>Ilość CAM</th>
                    <th>Ilość MDE</th>
                    <?php if ($can_edit || $can_delete): ?>
                    <th>Akcje</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody id="upsTableBody">
                <?php foreach ($ups_list as $index => $u): ?>
                <tr data-id="<?php echo $u['id']; ?>">
                    <td><?php echo $index + 1; ?></td>
                    <td><?php echo htmlspecialchars($u['machine_name']); ?></td>
                    <td><?php echo htmlspecialchars($u['ups_number'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($u['model'] ?? '—'); ?></td>
                    <td>
    <?php 
    $cd = $u['check_date'] ?? null;
    if ($cd && $cd != '0000-00-00' && $cd != '') {
        echo date('d.m.Y', strtotime($cd));
    } else {
        echo 'N/D';
    }
    ?>
</td>
<td>
    <?php 
    $rd = $u['replacement_date'] ?? null;
    if ($rd && $rd != '0000-00-00' && $rd != '') {
        echo date('d.m.Y', strtotime($rd));
    } else {
        echo 'N/D';
    }
    ?>
</td>
                    <td><?php echo htmlspecialchars($u['notes'] ?? '—'); ?></td>
                    <td><?php echo $u['cam_count']; ?></td>
                    <td><?php echo $u['mde_count']; ?></td>
                    <?php if ($can_edit || $can_delete): ?>
                    <td>
                        <div class="action-icons">
                            <?php if ($can_edit): ?>
                            <span class="action-icon" onclick="editUps(<?php echo $u['id']; ?>)" title="Edytuj">
                                <i class="fas fa-edit"></i>
                            </span>
                            <?php endif; ?>
                            <?php if ($can_delete): ?>
                            <span class="action-icon delete" onclick="confirmDelete(<?php echo $u['id']; ?>)" title="Usuń">
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
<!-- Modal dodawania/edycji UPS -->
<div class="modal" id="upsModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Dodaj UPS</h3>
            <button class="modal-close" onclick="closeModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="post" id="upsForm">
            <input type="hidden" name="id" id="upsId" value="0">
            <div class="modal-body">
                <div class="form-group">
                    <label for="machine_name">Nazwa maszyny *</label>
                    <input type="text" class="form-control" id="machine_name" name="machine_name" required>
                </div>
                <div class="form-group">
                    <label for="ups_number">Numer UPS</label>
                    <input type="text" class="form-control" id="ups_number" name="ups_number">
                </div>
                <div class="form-group">
                    <label for="model">Model</label>
                    <input type="text" class="form-control" id="model" name="model">
                </div>
                <div class="form-row" style="display: flex; gap: 16px;">
                    <div class="form-group" style="flex: 1;">
                        <label for="check_date">Data sprawdzenia</label>
                        <input type="date" class="form-control" id="check_date" name="check_date">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label for="replacement_date">Data wymiany</label>
                        <input type="date" class="form-control" id="replacement_date" name="replacement_date">
                    </div>
                </div>
                <div class="form-group">
                    <label for="notes">Uwagi</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                </div>
                <div class="form-row" style="display: flex; gap: 16px;">
                    <div class="form-group" style="flex: 1;">
                        <label for="cam_count">Ilość CAM</label>
                        <input type="number" class="form-control" id="cam_count" name="cam_count" value="0">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label for="mde_count">Ilość MDE</label>
                        <input type="number" class="form-control" id="mde_count" name="mde_count" value="0">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal()">Anuluj</button>
                <button type="submit" name="save_ups" class="btn btn-primary">Zapisz</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
const upsData = <?php echo json_encode($ups_list); ?>;

function openUpsModal() {
    document.getElementById('modalTitle').textContent = 'Dodaj UPS';
    document.getElementById('upsId').value = '0';
    document.getElementById('upsForm').reset();
    document.getElementById('upsModal').classList.add('active');
}

function editUps(id) {
    const item = upsData.find(u => u.id == id);
    if (!item) return;
    document.getElementById('modalTitle').textContent = 'Edytuj UPS';
    document.getElementById('upsId').value = item.id;
    document.getElementById('machine_name').value = item.machine_name;
    document.getElementById('ups_number').value = item.ups_number || '';
    document.getElementById('model').value = item.model || '';
    document.getElementById('check_date').value = item.check_date ? item.check_date.split(' ')[0] : '';
    document.getElementById('replacement_date').value = item.replacement_date ? item.replacement_date.split(' ')[0] : '';
    document.getElementById('notes').value = item.notes || '';
    document.getElementById('cam_count').value = item.cam_count;
    document.getElementById('mde_count').value = item.mde_count;
    document.getElementById('upsModal').classList.add('active');
}

function confirmDelete(id) {
    if (confirm('Czy na pewno chcesz usunąć ten UPS?')) {
        window.location.href = '?page=ups&action=delete&id=' + id;
    }
}

function closeModal() {
    document.getElementById('upsModal').classList.remove('active');
}

// Wyszukiwanie
document.getElementById('searchInput').addEventListener('input', function(e) {
    const term = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#upsTableBody tr');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(term) ? '' : 'none';
    });
});
</script>