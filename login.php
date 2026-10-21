<?php
// login.php - Strona logowania z obsługą 2FA i wymuszonej zmiany hasła
session_start();
error_log("Redirect after login: " . ($_SESSION['redirect_after_login'] ?? 'index.php'));

if (isset($_SESSION['user_id']) && !isset($_SESSION['2fa_required'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$error = '';
$success = '';
$step = 'login'; // login, change_password, twofa

if (isset($_GET['expired'])) $error = 'Twoja sesja wygasła. Zaloguj się ponownie.';
if (isset($_GET['logout'])) $success = 'Zostałeś pomyślnie wylogowany.';

// Krok 1: Logowanie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['change_password']) && !isset($_POST['verify_2fa'])) {
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    if ($username && $password) {
        global $db;
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password_hash'])) {
            // Sprawdź, czy wymagana zmiana hasła
            if ($user['force_password_change']) {
                $_SESSION['temp_user_id'] = $user['id'];
                $_SESSION['temp_username'] = $user['username'];
                $_SESSION['temp_remember'] = $remember;
                $step = 'change_password';
            }
            // Sprawdź, czy użytkownik ma włączone 2FA (tylko admini)
            elseif ($user['role'] === 'admin' && $user['twofa_enabled']) {
                // Generuj kod 2FA
                generateTwoFACode($user['id']);
                $_SESSION['temp_user_id'] = $user['id'];
                $_SESSION['temp_username'] = $user['username'];
                $_SESSION['temp_remember'] = $remember;
                $step = 'twofa';
            }
            else {
                // Normalne logowanie
                $result = login($username, $password, $remember);
                if ($result['success']) {
                    $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
                    unset($_SESSION['redirect_after_login']);
                    header('Location: ' . $redirect);
                    exit;
                } else {
                    $error = $result['message'];
                }
            }
        } else {
            $error = 'Nieprawidłowa nazwa użytkownika lub hasło';
        }
    } else {
        $error = 'Wypełnij wszystkie pola';
    }
}

// Krok 2: Zmiana hasła
if (isset($_POST['change_password']) && isset($_SESSION['temp_user_id'])) {
    $new_password = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $temp_id = $_SESSION['temp_user_id'];
    
    if (strlen($new_password) < 6) {
        $error = 'Hasło musi mieć co najmniej 6 znaków';
    } elseif ($new_password !== $confirm) {
        $error = 'Hasła nie są identyczne';
    } else {
        global $db;
        $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
        $db->prepare("UPDATE users SET password_hash = ?, force_password_change = 0 WHERE id = ?")->execute([$new_hash, $temp_id]);
        
        // Po zmianie hasła, sprawdź czy admin z 2FA
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$temp_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user['role'] === 'admin' && $user['twofa_enabled']) {
            generateTwoFACode($temp_id);
            $step = 'twofa';
            // zachowaj temp_user_id
        } else {
            // Zaloguj normalnie
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['login_time'] = time();
            
            // Zapisz pełną nazwę w sesji
            if (!empty($user['full_name'])) {
                $_SESSION['user_full_name'] = $user['full_name'];
            }
            
            $db->prepare("UPDATE users SET login_count = login_count + 1, last_login = NOW() WHERE id = ?")->execute([$temp_id]);
            logActivity($temp_id, 'login', 'auth', 'Zalogowano po zmianie hasła');
            
            unset($_SESSION['temp_user_id'], $_SESSION['temp_username'], $_SESSION['temp_remember']);
            
            $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
            unset($_SESSION['redirect_after_login']);
            header('Location: ' . $redirect);
            exit;
        }
    }
    if ($error) $step = 'change_password';
}

// Krok 3: Weryfikacja 2FA
if (isset($_POST['verify_2fa']) && isset($_SESSION['temp_user_id'])) {
    $code = $_POST['code'] ?? '';
    $temp_id = $_SESSION['temp_user_id'];
    
    if (verifyTwoFACode($temp_id, $code)) {
        // Logowanie
        global $db;
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$temp_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['login_time'] = time();
        
        // Zapisz pełną nazwę w sesji
        if (!empty($user['full_name'])) {
            $_SESSION['user_full_name'] = $user['full_name'];
        }
        
        $db->prepare("UPDATE users SET login_count = login_count + 1, last_login = NOW() WHERE id = ?")->execute([$temp_id]);
        logActivity($temp_id, 'login', 'auth', 'Zalogowano z 2FA');
        
        unset($_SESSION['temp_user_id'], $_SESSION['temp_username'], $_SESSION['temp_remember']);
        
        $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
        unset($_SESSION['redirect_after_login']);
        header('Location: ' . $redirect);
        exit;
    } else {
        $error = 'Nieprawidłowy lub wygasły kod 2FA';
        $step = 'twofa';
    }
}

// Jeśli po kroku 2 mamy błąd, pokazujemy odpowiedni formularz
if (isset($_SESSION['temp_user_id'])) {
    global $db;
    $stmt = $db->prepare("SELECT role, twofa_enabled FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['temp_user_id']]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u && $u['role'] === 'admin' && $u['twofa_enabled']) {
        $step = 'twofa';
    } elseif ($u) {
        $step = 'change_password';
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logowanie - Monitor Drukarek</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
        }
        
        .login-box {
            background: white;
            padding: 48px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 420px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 32px;
        }
        
        .login-logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            color: white;
            font-size: 36px;
        }
        
        .login-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 8px;
        }
        
        .login-header p {
            color: var(--gray-600);
            font-size: 14px;
        }
        
        .login-form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .form-group label {
            font-weight: 500;
            color: var(--gray-700);
            font-size: 14px;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-wrapper i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
        }
        
        .input-wrapper input {
            width: 100%;
            padding: 12px 12px 12px 44px;
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .input-wrapper input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .remember-forgot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 14px;
        }
        
        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        
        .remember-me input[type="checkbox"] {
            cursor: pointer;
        }
        
        .forgot-password {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        
        .forgot-password:hover {
            text-decoration: underline;
        }
        
        .login-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 14px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
        }
        
        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        
        .alert-info {
            background: #dbeafe;
            color: #1e3a8a;
            border: 1px solid #b9d0fa;
        }
        
        .alert i {
            margin-right: 8px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <div class="login-header">
                <div class="login-logo">
                    <i class="fas fa-print"></i>
                </div>
                <h1>Monitor Drukarek</h1>
                <p>
                    <?php if ($step === 'change_password'): ?>
                        Utwórz nowe hasło
                    <?php elseif ($step === 'twofa'): ?>
                        Weryfikacja dwuskładnikowa
                    <?php else: ?>
                        Zaloguj się do swojego konta
                    <?php endif; ?>
                </p>
            </div>
            
            <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>
            
            <?php if ($step === 'login'): ?>
                <!-- Formularz logowania -->
                <form method="POST" class="login-form" autocomplete="off">
                    <div class="form-group">
                        <label for="username">Nazwa użytkownika</label>
                        <div class="input-wrapper">
                            <i class="fas fa-user"></i>
                            <input type="text" id="username" name="username" required autofocus>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Hasło</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="password" name="password" required>
                        </div>
                    </div>
                    
                    <div class="remember-forgot">
                        <label class="remember-me">
                            <input type="checkbox" name="remember">
                            <span>Zapamiętaj mnie</span>
                        </label>
                        <a href="#" class="forgot-password">Zapomniałeś hasła?</a>
                    </div>
                    
                    <button type="submit" class="login-btn">
                        <i class="fas fa-sign-in-alt"></i>
                        Zaloguj się
                    </button>
                </form>
                
            <?php elseif ($step === 'change_password'): ?>
                <!-- Formularz zmiany hasła -->
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Witaj <?php echo htmlspecialchars($_SESSION['temp_username'] ?? ''); ?>. Musisz zmienić hasło.
                </div>
                <form method="POST" class="login-form">
                    <div class="form-group">
                        <label for="new_password">Nowe hasło</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="new_password" name="new_password" required minlength="6" autocomplete="new-password">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Potwierdź nowe hasło</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="confirm_password" name="confirm_password" required minlength="6" autocomplete="new-password">
                        </div>
                    </div>
                    <button type="submit" name="change_password" class="login-btn">
                        <i class="fas fa-key"></i> Zmień hasło i kontynuuj
                    </button>
                </form>
                
            <?php elseif ($step === 'twofa'): ?>
                <!-- Formularz 2FA -->
                <div class="alert alert-info">
                    <i class="fas fa-shield-alt"></i> Wpisz kod wysłany na Twój adres e-mail.
                </div>
                <form method="POST" class="login-form">
                    <div class="form-group">
                        <label for="code">Kod weryfikacyjny</label>
                        <div class="input-wrapper">
                            <i class="fas fa-key"></i>
                            <input type="text" id="code" name="code" required pattern="[0-9]{6}" maxlength="6" autocomplete="off">
                        </div>
                    </div>
                    <button type="submit" name="verify_2fa" class="login-btn">
                        <i class="fas fa-check"></i> Weryfikuj
                    </button>
                </form>
            <?php endif; ?>
            
            <div style="margin-top: 24px; text-align: center; font-size: 12px; color: var(--gray-500);">
                <p>Domyślne konto: <strong>admin</strong> / <strong>admin123</strong></p>
            </div>
        </div>
    </div>
</body>
</html>