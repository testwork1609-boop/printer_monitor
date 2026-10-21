<?php
// no_permission.php - Strona braku uprawnień
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Brak uprawnień - Monitor Drukarek</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .no-permission-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--light);
            padding: 20px;
        }
        
        .no-permission-box {
            text-align: center;
            max-width: 500px;
        }
        
        .permission-icon {
            width: 120px;
            height: 120px;
            background: #fef2f2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 60px;
            color: var(--danger);
        }
        
        .no-permission-box h1 {
            font-size: 32px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 12px;
        }
        
        .no-permission-box p {
            font-size: 16px;
            color: var(--gray-600);
            margin-bottom: 32px;
            line-height: 1.6;
        }
        
        .action-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
    <div class="no-permission-container">
        <div class="no-permission-box">
            <div class="permission-icon">
                <i class="fas fa-lock"></i>
            </div>
            
            <h1>Brak uprawnień</h1>
            <p>
                Nie masz odpowiednich uprawnień do przeglądania tej strony.<br>
                Jeśli uważasz, że to błąd, skontaktuj się z administratorem systemu.
            </p>
            
            <div class="action-buttons">
                <a href="index.php" class="btn btn-primary">
                    <i class="fas fa-home"></i>
                    Powrót do strony głównej
                </a>
                <a href="logout.php" class="btn btn-outline">
                    <i class="fas fa-sign-out-alt"></i>
                    Wyloguj się
                </a>
            </div>
        </div>
    </div>
</body>
</html>