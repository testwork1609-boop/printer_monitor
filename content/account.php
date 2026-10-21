<?php
// content/account.php - Zarządzanie kontem użytkownika
global $db;
// Funkcja addMessage (jeśli nie istnieje globalnie)
if (!function_exists('addMessage')) {
    function addMessage($type, $text) {
        if (!isset($_SESSION['messages'])) $_SESSION['messages'] = [];
        if (count($_SESSION['messages']) >= 3) array_shift($_SESSION['messages']);
        $_SESSION['messages'][] = ['type' => $type, 'text' => $text];
    }
}
$user = getCurrentUser();
// Jeśli getCurrentUser zwróci null, ustaw pustą tablicę, aby uniknąć błędów
if (!is_array($user)) {
    $user = [];
}

// Sprawdź, czy to wymuszona zmiana hasła (parametr force) – tymczasowo wyłączone
// $force_change = isset($_GET['force']) && $_GET['force'] == 1;
// if ($force_change) {
//     addMessage('info', 'Musisz zmienić hasło przed kontynuowaniem.');
// }

// Obsługa zmiany hasła
if (isset($_POST['change_password'])) {
    $old_password = $_POST['old_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if ($new_password !== $confirm_password) {
        addMessage('error', 'Nowe hasła nie są identyczne');
    } elseif (strlen($new_password) < 6) {
        addMessage('error', 'Nowe hasło musi mieć co najmniej 6 znaków');
    } else {
        $result = changePassword($_SESSION['user_id'], $old_password, $new_password);
        if ($result['success']) {
            // Jeśli to była wymuszona zmiana, usuń flagę – tymczasowo wyłączone
            // if (isset($_SESSION['force_password_change'])) {
            //     unset($_SESSION['force_password_change']);
            //     $db->prepare("UPDATE users SET force_password_change = 0 WHERE id = ?")->execute([$_SESSION['user_id']]);
            // }
            addMessage('success', $result['message']);
            header('Location: index.php?page=account');
            exit;
        } else {
            addMessage('error', $result['message']);
        }
    }
}

// Obsługa aktualizacji profilu
if (isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    if ($full_name) {
        $stmt = $db->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
        $stmt->execute([$full_name, $email, $_SESSION['user_id']]);
        
        addMessage('success', 'Profil zaktualizowany');
        logActivity($_SESSION['user_id'], 'update_profile', 'account', 'Zaktualizowano profil');
        header('Location: index.php?page=account');
        exit;
    }
}

// Pobierz ostatnie aktywności
$stmt = $db->prepare("SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$_SESSION['user_id']]);
$recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pobierz sesje użytkownika
$stmt = $db->prepare("SELECT * FROM sessions WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$_SESSION['user_id']]);
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Bezpieczne dane użytkownika z domyślnymi wartościami
$user_username = $user['username'] ?? '';
$user_full_name = $user['full_name'] ?? '';
$user_email = $user['email'] ?? '';
$user_role = $user['role'] ?? 'user';
$user_login_count = $user['login_count'] ?? 0;
$user_last_login = $user['last_login'] ?? null;

$role_display = [
    'admin' => 'Administrator',
    'superuser' => 'Super User',
    'user' => 'Użytkownik'
][$user_role] ?? 'Użytkownik';

$action_labels = [
    'login' => 'Logowanie',
    'logout' => 'Wylogowanie',
    'add_printer' => 'Dodanie drukarki',
    'update_printer' => 'Aktualizacja drukarki',
    'delete_printer' => 'Usunięcie drukarki',
    'add_material' => 'Dodanie materiału',
    'update_material' => 'Aktualizacja materiału',
    'password_change' => 'Zmiana hasła',
    'update_profile' => 'Aktualizacja profilu'
];
?>

<div class="dashboard-header">
    <h1>Moje konto</h1>
    <p class="text-muted">Zarządzaj swoim profilem i ustawieniami</p>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 24px;">
    
    <!-- Informacje o profilu -->
    <div class="table-section">
        <div class="table-header">
            <h2><i class="fas fa-user"></i> Informacje o profilu</h2>
        </div>
        <div style="padding: 24px;">
            <form method="post">
                <div class="form-group">
                    <label>Nazwa użytkownika</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($user_username); ?>" disabled>
                </div>
                
                <div class="form-group">
                    <label for="full_name">Pełna nazwa</label>
                    <input type="text" class="form-control" id="full_name" name="full_name" 
                           value="<?php echo htmlspecialchars($user_full_name); ?>">
                </div>
                
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" class="form-control" id="email" name="email" 
                           value="<?php echo htmlspecialchars($user_email); ?>">
                </div>
                
                <div class="form-group">
                    <label>Rola</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($role_display); ?>" disabled>
                </div>
                
                <div class="form-group">
                    <label>Liczba logowań</label>
                    <input type="text" class="form-control" value="<?php echo $user_login_count; ?>" disabled>
                </div>
                
                <div class="form-group">
                    <label>Ostatnie logowanie</label>
                    <input type="text" class="form-control" 
                           value="<?php echo $user_last_login ? date('d.m.Y H:i', strtotime($user_last_login)) : 'Nigdy'; ?>" disabled>
                </div>
                
                <button type="submit" name="update_profile" class="btn btn-primary">
                    <i class="fas fa-save"></i>
                    Zapisz zmiany
                </button>
            </form>
        </div>
    </div>
    
    <!-- Zmiana hasła -->
    <div class="table-section">
        <div class="table-header">
            <h2><i class="fas fa-lock"></i> Zmiana hasła</h2>
        </div>
        <div style="padding: 24px;">
            <form method="post">
                <div class="form-group">
                    <label for="old_password">Obecne hasło</label>
                    <input type="password" class="form-control" id="old_password" name="old_password" required>
                </div>
                
                <div class="form-group">
                    <label for="new_password">Nowe hasło</label>
                    <input type="password" class="form-control" id="new_password" name="new_password" 
                           required minlength="6">
                    <small class="text-muted">Minimum 6 znaków</small>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Potwierdź nowe hasło</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                           required minlength="6">
                </div>
                
                <button type="submit" name="change_password" class="btn btn-primary">
                    <i class="fas fa-key"></i>
                    Zmień hasło
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Ostatnie aktywności -->
<div class="table-section" style="margin-top: 24px;">
    <div class="table-header">
        <h2><i class="fas fa-history"></i> Ostatnie aktywności</h2>
    </div>
    <div class="table-container">
        <table class="printer-table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Akcja</th>
                    <th>Moduł</th>
                    <th>Szczegóły</th>
                    <th>Adres IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_activities)): ?>
                <tr>
                    <td colspan="5" class="text-center text-muted">Brak aktywności</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($recent_activities as $activity): ?>
                    <tr>
                        <td><?php echo date('d.m.Y H:i', strtotime($activity['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($action_labels[$activity['action']] ?? $activity['action']); ?></td>
                        <td><span class="hall-badge"><?php echo htmlspecialchars($activity['module'] ?? '—'); ?></span></td>
                        <td><?php echo htmlspecialchars($activity['details'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($activity['ip_address'] ?? '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Aktywne sesje -->
<div class="table-section" style="margin-top: 24px;">
    <div class="table-header">
        <h2><i class="fas fa-laptop"></i> Aktywne sesje</h2>
    </div>
    <div class="table-container">
        <table class="printer-table">
            <thead>
                <tr>
                    <th>Utworzona</th>
                    <th>Wygasa</th>
                    <th>Adres IP</th>
                    <th>Przeglądarka</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sessions as $session): 
                    $is_current = $session['id'] === ($_SESSION['session_id'] ?? '');
                    $is_expired = strtotime($session['expires_at']) < time();
                ?>
                <tr>
                    <td><?php echo date('d.m.Y H:i', strtotime($session['created_at'])); ?></td>
                    <td><?php echo date('d.m.Y H:i', strtotime($session['expires_at'])); ?></td>
                    <td><?php echo htmlspecialchars($session['ip_address'] ?? '—'); ?></td>
                    <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        <?php echo htmlspecialchars(substr($session['user_agent'] ?? '', 0, 50)); ?>
                    </td>
                    <td>
                        <?php if ($is_current): ?>
                            <span class="status-badge online">BIEŻĄCA</span>
                        <?php elseif ($is_expired): ?>
                            <span class="status-badge offline">WYGASŁA</span>
                        <?php else: ?>
                            <span class="status-badge online">AKTYWNA</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>