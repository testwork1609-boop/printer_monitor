<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Jeśli nie ma danych użytkownika do zmiany hasła w sesji, przekieruj do logowania
if (!isset($_SESSION['force_change_user_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (strlen($new_password) < 6) {
        $error = 'Hasło musi mieć co najmniej 6 znaków.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Hasła nie są identyczne.';
    } else {
        // Zmień hasło w bazie
        $user_id = $_SESSION['force_change_user_id'];
        $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
        
        $stmt = $db->prepare("UPDATE users SET password_hash = ?, force_password_change = 0 WHERE id = ?");
        if ($stmt->execute([$new_hash, $user_id])) {
            // Zaloguj użytkownika
            $_SESSION['user_id'] = $user_id;
            $_SESSION['username'] = $_SESSION['force_change_username'];
            // Pobierz rolę użytkownika
            $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $_SESSION['role'] = $user['role'];
            $_SESSION['login_time'] = time();
            
            // Usuń dane tymczasowe
            unset($_SESSION['force_change_user_id']);
            unset($_SESSION['force_change_username']);
            
            // Przekieruj do dashboardu
            header('Location: index.php');
            exit;
        } else {
            $error = 'Błąd podczas zmiany hasła.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zmiana hasła - Monitor Drukarek</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .change-password-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
        }
        .change-password-box {
            background: white;
            padding: 48px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 420px;
        }
        .change-password-header {
            text-align: center;
            margin-bottom: 32px;
        }
        .change-password-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 8px;
        }
        .change-password-header p {
            color: var(--gray-600);
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: var(--gray-700);
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
        .btn-change {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 14px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-change:hover {
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
    </style>
</head>
<body>
    <div class="change-password-container">
        <div class="change-password-box">
            <div class="change-password-header">
                <h1>Wymagana zmiana hasła</h1>
                <p>Musisz ustawić nowe hasło, aby kontynuować.</p>
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
            
            <form method="POST">
                <div class="form-group">
                    <label for="new_password">Nowe hasło</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="new_password" name="new_password" required minlength="6">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Potwierdź nowe hasło</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
                    </div>
                </div>
                
                <button type="submit" class="btn-change">
                    <i class="fas fa-key"></i>
                    Zmień hasło
                </button>
            </form>
        </div>
    </div>
</body>
</html>