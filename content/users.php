<?php
ob_start();
// content/users.php - Zarządzanie użytkownikami
global $db;

// Sprawdź uprawnienia
requirePermission('users', 'view');

if (!function_exists('addMessage')) {
    function addMessage($type, $text) {
        if (!isset($_SESSION['messages'])) $_SESSION['messages'] = [];
        if (count($_SESSION['messages']) >= 3) array_shift($_SESSION['messages']);
        $_SESSION['messages'][] = ['type' => $type, 'text' => $text];
    }
}

// Obsługa dodawania/edycji użytkownika
if (isset($_POST['save_user']) && hasPermission('users', isset($_POST['user_id']) && $_POST['user_id'] > 0 ? 'edit' : 'create')) {
    $user_id = intval($_POST['user_id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'user';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $force_change = isset($_POST['force_password_change']) ? 1 : 0;
    $password = $_POST['password'] ?? '';
    
    // Pobierz niestandardowe uprawnienia jeśli nie admin
    $custom_permissions = [];
    if ($role !== 'admin' && isset($_POST['permissions'])) {
        $custom_permissions = $_POST['permissions'];
    }
    
    if ($username && in_array($role, ['admin', 'superuser', 'user'])) {
        try {
            if ($user_id > 0) {
                // Edycja
                if ($password) {
                    $password_hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $db->prepare("UPDATE users SET username=?, full_name=?, email=?, role=?, is_active=?, force_password_change=?, password_hash=? WHERE id=?");
                    $stmt->execute([$username, $full_name, $email, $role, $is_active, $force_change, $password_hash, $user_id]);
                } else {
                    $stmt = $db->prepare("UPDATE users SET username=?, full_name=?, email=?, role=?, is_active=?, force_password_change=? WHERE id=?");
                    $stmt->execute([$username, $full_name, $email, $role, $is_active, $force_change, $user_id]);
                }
                
                // Aktualizuj niestandardowe uprawnienia
                if (!empty($custom_permissions)) {
                    updateCustomPermissions($user_id, $role, $custom_permissions);
                }
                
                addMessage('success', 'Użytkownik zaktualizowany');
                logActivity($_SESSION['user_id'], 'update_user', 'users', "Zaktualizowano użytkownika: $username");
            } else {
                // Dodawanie
                if (!$password) {
                    addMessage('error', 'Hasło jest wymagane dla nowego użytkownika');
                } else {
                    // Sprawdź czy username już istnieje
                    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    if ($stmt->fetchColumn() > 0) {
                        addMessage('error', 'Nazwa użytkownika już istnieje');
                    } else {
                        $password_hash = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $db->prepare("INSERT INTO users (username, full_name, email, role, is_active, force_password_change, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$username, $full_name, $email, $role, $is_active, $force_change, $password_hash]);
                        
                        $new_user_id = $db->lastInsertId();
                        
                        // Ustaw niestandardowe uprawnienia
                        if (!empty($custom_permissions)) {
                            updateCustomPermissions($new_user_id, $role, $custom_permissions);
                        }
                        
                        addMessage('success', 'Użytkownik dodany');
                        logActivity($_SESSION['user_id'], 'add_user', 'users', "Dodano użytkownika: $username");
                    }
                }
            }
            ob_end_clean();
            header('Location: index.php?page=users');
            exit;
        } catch (PDOException $e) {
            addMessage('error', 'Błąd zapisu: ' . $e->getMessage());
        }
    } else {
        addMessage('error', 'Nieprawidłowe dane');
    }
}

// Funkcja aktualizacji niestandardowych uprawnień
function updateCustomPermissions($user_id, $role, $permissions) {
    global $db;
    
    // Najpierw usuń stare niestandardowe uprawnienia tego użytkownika
    $stmt = $db->prepare("DELETE FROM permissions WHERE role = ?");
    $stmt->execute(["user_$user_id"]);
    
    // Dodaj nowe uprawnienia
    foreach ($permissions as $module => $perms) {
        $can_view = isset($perms['view']) ? 1 : 0;
        $can_create = isset($perms['create']) ? 1 : 0;
        $can_edit = isset($perms['edit']) ? 1 : 0;
        $can_delete = isset($perms['delete']) ? 1 : 0;
        
        $stmt = $db->prepare("INSERT OR REPLACE INTO permissions (role, module, can_view, can_create, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute(["user_$user_id", $module, $can_view, $can_create, $can_edit, $can_delete]);
    }
    
    // Zaktualizuj rolę użytkownika na specjalną
    $stmt = $db->prepare("UPDATE users SET role = ? WHERE id = ?");
    $stmt->execute(["user_$user_id", $user_id]);
}

// Obsługa usuwania
if (isset($_GET['action']) && $_GET['action'] == 'delete_user' && hasPermission('users', 'delete')) {
    $user_id = intval($_GET['id'] ?? 0);
    if ($user_id > 0 && $user_id != $_SESSION['user_id']) { // Nie można usunąć samego siebie
        $stmt = $db->prepare("SELECT username FROM users WHERE id=?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $db->prepare("DELETE FROM users WHERE id=?");
        $stmt->execute([$user_id]);
        
        addMessage('success', 'Użytkownik usunięty');
        logActivity($_SESSION['user_id'], 'delete_user', 'users', "Usunięto użytkownika: " . ($user['username'] ?? ''));
        ob_end_clean();
        header('Location: index.php?page=users');
        exit;
    } elseif ($user_id == $_SESSION['user_id']) {
        addMessage('error', 'Nie możesz usunąć samego siebie');
    }
}

// Pobierz listę użytkowników
$stmt = $db->query("SELECT u.*, 
                           (SELECT COUNT(*) FROM sessions s WHERE s.user_id = u.id AND s.expires_at > NOW()) as active_sessions
                    FROM users u 
                    ORDER BY u.created_at DESC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$role_labels = [
    'admin' => 'Administrator',
    'superuser' => 'Super User',
    'user' => 'Użytkownik'
];

$role_colors = [
    'admin' => 'style="background: #fee2e2; color: #991b1b;"',
    'superuser' => 'style="background: #fef3c7; color: #92400e;"',
    'user' => 'style="background: #e0e7ff; color: #3730a3;"'
];

$can_create = hasPermission('users', 'create');
$can_edit = hasPermission('users', 'edit');
$can_delete = hasPermission('users', 'delete');

// Lista modułów z etykietami (dla uprawnień) - DODAJ TUTAJ NOWE MODUŁY
$modules_labels = [
    'dashboard' => 'Dashboard',
    'devices' => 'Urządzenia',
    'materials' => 'Magazyn materiałów',
    'users' => 'Użytkownicy',
    'config' => 'Konfiguracja',
    'reports' => 'Raporty',
    'map' => 'Mapa',
    'ups' => 'UPS',
    'dect_phones' => 'Telefony DECT',
    'terminals' => 'Terminale',
    'peripherals' => 'Urządzenia peryferyjne',
    'printers_outsourcing' => 'Drukarki - Outsourcing'
];
?>

<div class="dashboard-header">
    <h1>Zarządzanie użytkownikami</h1>
    <p class="text-muted">Dodawaj, edytuj i zarządzaj użytkownikami systemu</p>
</div>

<!-- Statystyki -->
<div class="stats-grid" style="margin-bottom: 32px;">
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo count($users); ?></div>
            <div class="stat-label">Wszystkich użytkowników</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-user-check"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php 
                $active = 0;
                foreach ($users as $u) {
                    if ($u['is_active']) $active++;
                }
                echo $active;
            ?></div>
            <div class="stat-label">Aktywnych</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-user-shield"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php 
                $admins = 0;
                foreach ($users as $u) {
                    if ($u['role'] === 'admin') $admins++;
                }
                echo $admins;
            ?></div>
            <div class="stat-label">Administratorów</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php 
                $online = 0;
                foreach ($users as $u) {
                    if ($u['active_sessions'] > 0) $online++;
                }
                echo $online;
            ?></div>
            <div class="stat-label">Online teraz</div>
        </div>
    </div>
</div>

<!-- Tabela użytkowników -->
<div class="table-section">
    <div class="table-header">
        <h2>Lista użytkowników</h2>
        <div class="table-actions">
            <input type="text" class="search-input" placeholder="Szukaj..." id="searchInput">
            <?php if ($can_create): ?>
            <button class="btn btn-primary" onclick="openAddUserModal()">
                <i class="fas fa-plus"></i>
                Dodaj użytkownika
            </button>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="table-container">
        <table class="printer-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nazwa użytkownika</th>
                    <th>Pełna nazwa</th>
                    <th>Email</th>
                    <th>Rola</th>
                    <th>Wymuś zmianę hasła</th>
                    <th>Status</th>
                    <th>Logowania</th>
                    <th>Ostatnie logowanie</th>
                    <th>Data utworzenia</th>
                    <?php if ($can_edit || $can_delete): ?>
                    <th>Akcje</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody id="userTableBody">
                <?php foreach ($users as $index => $user): ?>
                <tr>
                    <td><?php echo $index + 1; ?></td>
                    <td>
                        <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                        <?php if ($user['active_sessions'] > 0): ?>
                            <span class="status-badge online" style="margin-left: 8px; font-size: 10px; padding: 2px 6px;">ONLINE</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($user['full_name'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($user['email'] ?? '—'); ?></td>
                    <td>
                        <span class="hall-badge" <?php echo $role_colors[$user['role']] ?? ''; ?>>
                            <?php echo $role_labels[$user['role']] ?? $user['role']; ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($user['force_password_change']): ?>
                            <span class="status-badge warning" style="background: #fef3c7; color: #92400e;">TAK</span>
                        <?php else: ?>
                            <span class="status-badge" style="background: #f3f4f6; color: #6b7280;">NIE</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="status-badge <?php echo $user['is_active'] ? 'online' : 'offline'; ?>">
                            <?php echo $user['is_active'] ? 'AKTYWNY' : 'NIEAKTYWNY'; ?>
                        </span>
                    </td>
                    <td><?php echo $user['login_count']; ?></td>
                    <td><?php echo $user['last_login'] ? date('d.m.Y H:i', strtotime($user['last_login'])) : '—'; ?></td>
                    <td><?php echo date('d.m.Y', strtotime($user['created_at'])); ?></td>
                    <?php if ($can_edit || $can_delete): ?>
                    <td>
                        <div class="action-icons">
                            <?php if ($can_edit): ?>
                            <span class="action-icon" onclick="editUser(<?php echo $user['id']; ?>)" title="Edytuj">
                                <i class="fas fa-edit"></i>
                            </span>
                            <?php endif; ?>
                            <?php if ($can_delete && $user['id'] != $_SESSION['user_id']): ?>
                            <span class="action-icon delete" onclick="confirmDeleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" title="Usuń">
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
<!-- Modal dodawania/edycji użytkownika -->
<div class="modal" id="userModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="userModalTitle">Dodaj użytkownika</h3>
            <button class="modal-close" onclick="closeUserModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="post" id="userForm">
            <div class="modal-body">
                <input type="hidden" name="user_id" id="editUserId" value="0">
                
                <div class="form-group">
                    <label for="username">Nazwa użytkownika *</label>
                    <input type="text" class="form-control" id="username" name="username" required>
                </div>
                
                <div class="form-group">
                    <label for="full_name">Pełna nazwa</label>
                    <input type="text" class="form-control" id="full_name" name="full_name">
                </div>
                
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" class="form-control" id="email" name="email">
                </div>
                
                <div class="form-group">
                    <label for="role">Rola *</label>
                    <select class="form-control" id="role" name="role" required onchange="togglePermissionsSection()">
                        <option value="user">Użytkownik</option>
                        <option value="superuser">Super User</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>
                
                <!-- Sekcja uprawnień szczegółowych (ukryta dla admina) -->
                <div id="permissionsSection" style="display: none;">
                    <div class="form-group">
                        <label style="font-weight: 600; margin-bottom: 12px; display: block;">Uprawnienia szczegółowe</label>
                        
                        <?php foreach ($modules_labels as $module => $label): ?>
                        <div style="background: #f9fafb; padding: 12px; border-radius: 6px; margin-bottom: 8px;">
                            <div style="font-weight: 500; margin-bottom: 8px;"><?php echo $label; ?></div>
                            <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                    <input type="checkbox" name="permissions[<?php echo $module; ?>][view]" value="1">
                                    <span>Odczyt</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                    <input type="checkbox" name="permissions[<?php echo $module; ?>][create]" value="1">
                                    <span>Tworzenie</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                    <input type="checkbox" name="permissions[<?php echo $module; ?>][edit]" value="1">
                                    <span>Edycja</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                    <input type="checkbox" name="permissions[<?php echo $module; ?>][delete]" value="1">
                                    <span>Usuwanie</span>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Hasło <span id="passwordRequired">*</span></label>
                    <input type="password" class="form-control" id="password" name="password" minlength="6">
                    <small class="text-muted" id="passwordHint">Minimum 6 znaków. Pozostaw puste, aby nie zmieniać.</small>
                </div>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="force_password_change" id="force_password_change" value="1" checked>
                        <span>Wymuś zmianę hasła przy następnym logowaniu</span>
                    </label>
                </div>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" id="is_active" checked>
                        <span>Konto aktywne</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeUserModal()">Anuluj</button>
                <button type="submit" name="save_user" class="btn btn-primary">
                    <i class="fas fa-save"></i>
                    Zapisz
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
const usersData = <?php echo json_encode($users); ?>;

function togglePermissionsSection() {
    const role = document.getElementById('role').value;
    const permissionsSection = document.getElementById('permissionsSection');
    
    if (role === 'admin') {
        permissionsSection.style.display = 'none';
    } else {
        permissionsSection.style.display = 'block';
    }
}

function openAddUserModal() {
    const modal = document.getElementById('userModal');
    const title = document.getElementById('userModalTitle');
    const form = document.getElementById('userForm');
    
    title.textContent = 'Dodaj użytkownika';
    form.reset();
    document.getElementById('editUserId').value = '0';
    document.getElementById('password').required = true;
    document.getElementById('passwordRequired').style.display = 'inline';
    document.getElementById('passwordHint').textContent = 'Minimum 6 znaków';
    document.getElementById('force_password_change').checked = true;
    document.getElementById('is_active').checked = true;
    
    // Przy dodawaniu nowego użytkownika, domyślnie rola to 'user', więc sekcja powinna być widoczna
    togglePermissionsSection();
    modal.classList.add('active');
}

function editUser(id) {
    const user = usersData.find(u => u.id == id);
    if (!user) return;
    
    const modal = document.getElementById('userModal');
    const title = document.getElementById('userModalTitle');
    
    title.textContent = 'Edytuj użytkownika';
    
    document.getElementById('editUserId').value = user.id;
    document.getElementById('username').value = user.username || '';
    document.getElementById('full_name').value = user.full_name || '';
    document.getElementById('email').value = user.email || '';
    document.getElementById('role').value = user.role || 'user';
    document.getElementById('force_password_change').checked = user.force_password_change == 1;
    document.getElementById('is_active').checked = user.is_active == 1;
    document.getElementById('password').value = '';
    document.getElementById('password').required = false;
    document.getElementById('passwordRequired').style.display = 'none';
    document.getElementById('passwordHint').textContent = 'Minimum 6 znaków. Pozostaw puste, aby nie zmieniać.';
    
    // Tutaj można by wczytać istniejące uprawnienia użytkownika (dla roli user_$id)
    // To jest bardziej złożone i wymaga osobnego zapytania do tabeli permissions
    // Na razie pozostawiamy to jako ćwiczenie dla administratora (ręczne zaznaczanie)
    
    // Upewnij się, że sekcja uprawnień jest odpowiednio pokazana/ukryta
    togglePermissionsSection();
    modal.classList.add('active');
}

function closeUserModal() {
    document.getElementById('userModal').classList.remove('active');
}

function confirmDeleteUser(id, username) {
    if (confirm('Czy na pewno chcesz usunąć użytkownika "' + username + '"?')) {
        window.location.href = '?page=users&action=delete_user&id=' + id;
    }
}

// Search functionality
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const rows = document.querySelectorAll('#userTableBody tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchTerm) ? '' : 'none';
        });
    });
}

// Close modal on outside click
window.addEventListener('click', function(event) {
    const modal = document.getElementById('userModal');
    if (event.target === modal) {
        closeUserModal();
    }
});
</script>
<?php ob_end_flush(); ?>