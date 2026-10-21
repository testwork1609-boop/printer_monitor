<?php
// auth.php - Autoryzacja i zarządzanie użytkownikami

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

global $db;

/**
 * Loguje użytkownika (ustawia sesję)
 */
function login($username, $password, $remember = false) {
    global $db;
    
    try {
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['login_time'] = time();
            
            // Zapisz pełną nazwę w sesji (jeśli istnieje)
            if (!empty($user['full_name'])) {
                $_SESSION['user_full_name'] = $user['full_name'];
            }
            
            // Przedłużenie sesji jeśli zapamiętaj
            if ($remember) {
                // opcjonalnie
            }
            
            // Aktualizuj licznik logowań
            $db->prepare("UPDATE users SET login_count = login_count + 1, last_login = NOW() WHERE id = ?")
               ->execute([$user['id']]);
            
            logActivity($user['id'], 'login', 'auth', 'Zalogowano');
            
            return ['success' => true, 'message' => 'Zalogowano pomyślnie'];
        } else {
            return ['success' => false, 'message' => 'Nieprawidłowa nazwa użytkownika lub hasło'];
        }
    } catch (PDOException $e) {
        error_log("Błąd logowania: " . $e->getMessage());
        return ['success' => false, 'message' => 'Błąd bazy danych'];
    }
}

/**
 * Wylogowuje użytkownika
 */
function logout() {
    if (isset($_SESSION['user_id'])) {
        logActivity($_SESSION['user_id'], 'logout', 'auth', 'Wylogowano');
    }
    $_SESSION = array();
    session_destroy();
}

/**
 * Sprawdza czy użytkownik jest zalogowany
 */
function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        if (strpos($_SESSION['redirect_after_login'], 'login.php') !== false) {
            $_SESSION['redirect_after_login'] = 'index.php';
        }
        header('Location: login.php');
        exit;
    }
}

/**
 * Sprawdza uprawnienia
 */
function hasPermission($module, $permission = 'view') {
    if (!isset($_SESSION['user_id'])) return false;
    if ($_SESSION['role'] === 'admin') return true;
    if ($_SESSION['role'] === 'superuser') {
        if (in_array($module, ['users', 'config'])) return false;
        return true;
    }
    // Dla zwykłego użytkownika – uprawnienia domyślne
    $default = getDefaultPermissions($_SESSION['role']);
    return isset($default[$module][$permission]) ? $default[$module][$permission] : false;
}

/**
 * Domyślne uprawnienia
 */
function getDefaultPermissions($role) {
    $permissions = [
        'dashboard' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'devices'   => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'materials' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'users'     => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
        'config'    => ['view' => false, 'edit' => false],
        'map'       => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'reports'   => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
        // Nowe moduły
        'ups'       => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
        'dect_phones' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
        'terminals' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
        'peripherals' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
        'printers_outsourcing' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
    ];
    
    if ($role === 'admin') {
        foreach ($permissions as $module => &$perms) {
            $perms['view'] = true;
            $perms['create'] = true;
            $perms['edit'] = true;
            $perms['delete'] = true;
        }
    } elseif ($role === 'superuser') {
        // Superuser ma pełny dostęp do większości, ale nie do users i config
        foreach ($permissions as $module => &$perms) {
            if (!in_array($module, ['users', 'config'])) {
                $perms['view'] = true;
                $perms['create'] = true;
                $perms['edit'] = true;
                $perms['delete'] = true;
            }
        }
        // Dodatkowo raporty tylko do odczytu
        $permissions['reports']['view'] = true;
        // Nowe moduły – tylko odczyt
        $permissions['ups']['view'] = true;
        $permissions['dect_phones']['view'] = true;
        $permissions['terminals']['view'] = true;
        $permissions['peripherals']['view'] = true;
        $permissions['printers_outsourcing']['view'] = true;
    } else {
        // Zwykły użytkownik – tylko podstawowe moduły do odczytu
        $permissions['dashboard']['view'] = true;
        $permissions['devices']['view'] = true;
        $permissions['materials']['view'] = true;
        $permissions['map']['view'] = true;
        $permissions['reports']['view'] = true; // opcjonalnie
        // Nowe moduły – brak dostępu
    }
    
    return $permissions;
}

/**
 * Wymaga konkretnego uprawnienia
 */
function requirePermission($module, $permission = 'view') {
    if (!hasPermission($module, $permission)) {
        http_response_code(403);
        include __DIR__ . '/no_permission.php';
        exit;
    }
}

/**
 * Zwraca dane użytkownika z bazy
 */
function getCurrentUser($id = null) {
    global $db;
    if ($id === null) {
        $id = $_SESSION['user_id'] ?? 0;
    }
    if (!$id) return null;
    
    try {
        $stmt = $db->prepare("SELECT id, username, full_name, email, role, is_active, login_count, last_login, created_at, twofa_enabled FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    } catch (PDOException $e) {
        error_log("Błąd pobierania użytkownika: " . $e->getMessage());
        return null;
    }
}

/**
 * Loguje aktywność
 */
function logActivity($user_id, $action, $module, $details = '') {
    global $db;
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $stmt = $db->prepare("INSERT INTO activity_logs (user_id, action, module, details, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$user_id, $action, $module, $details, $ip, $user_agent]);
        return true;
    } catch (PDOException $e) {
        error_log("Błąd logowania aktywności: " . $e->getMessage());
        return false;
    }
}

/**
 * Zmienia hasło użytkownika
 */
function changePassword($user_id, $old_password, $new_password) {
    global $db;
    try {
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !password_verify($old_password, $user['password_hash'])) {
            return ['success' => false, 'message' => 'Nieprawidłowe obecne hasło'];
        }
        
        $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
        $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$new_hash, $user_id]);
        logActivity($user_id, 'password_change', 'account', 'Zmiana hasła');
        return ['success' => true, 'message' => 'Hasło zostało zmienione'];
    } catch (PDOException $e) {
        error_log("Błąd zmiany hasła: " . $e->getMessage());
        return ['success' => false, 'message' => 'Błąd bazy danych'];
    }
}

/**
 * Generuje kod 2FA i wysyła e-mailem
 */
function generateTwoFACode($user_id) {
    global $db;
    $code = sprintf("%06d", random_int(0, 999999));
    $expires = date('Y-m-d H:i:s', time() + 600); // 10 minut
    $stmt = $db->prepare("UPDATE users SET twofa_code = ?, twofa_code_expires = ? WHERE id = ?");
    $stmt->execute([$code, $expires, $user_id]);
    
    $user = getCurrentUser($user_id);
    if ($user && $user['email']) {
        $subject = "Twój kod uwierzytelniania dwuskładnikowego";
        $message = "Twój kod to: $code. Ważny przez 10 minut.";
        mail($user['email'], $subject, $message); // Wymaga skonfigurowanego maila
    }
    return $code;
}

/**
 * Weryfikuje kod 2FA
 */
function verifyTwoFACode($user_id, $code) {
    global $db;
    $stmt = $db->prepare("SELECT twofa_code, twofa_code_expires FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($data && $data['twofa_code'] === $code && strtotime($data['twofa_code_expires']) > time()) {
        $db->prepare("UPDATE users SET twofa_code = NULL, twofa_code_expires = NULL WHERE id = ?")->execute([$user_id]);
        return true;
    }
    return false;
}
?>