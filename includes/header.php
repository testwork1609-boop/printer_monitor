<?php
// includes/header.php - Nagłówek strony z menu użytkownika i nawigacją

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Pobierz dane bieżącego użytkownika z bazy (jeśli możliwe)
$current_user = function_exists('getCurrentUser') ? getCurrentUser() : null;
if (!is_array($current_user)) {
    $current_user = [];
}

// Priorytetowo użyj nazwy z sesji (aktualizowanej po zmianie profilu), inaczej z bazy, a jeśli brak, to z sesji username
$user_name = $_SESSION['user_full_name'] ?? ($current_user['full_name'] ?? $_SESSION['username'] ?? 'Użytkownik');

// Wyświetlana rola użytkownika – najpierw z sesji, potem z bazy
$user_role = $_SESSION['role'] ?? ($current_user['role'] ?? 'user');
$user_role_display = [
    'admin'     => 'Administrator',
    'superuser' => 'Super User',
    'user'      => 'Użytkownik'
][$user_role] ?? 'Użytkownik';

// Awatar (jeśli istnieje)
$user_avatar = $current_user['avatar'] ?? null;

// Inicjalizacja tablicy komunikatów w sesji, jeśli nie istnieje
if (!isset($_SESSION['messages'])) {
    $_SESSION['messages'] = [];
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor Drukarek</title>
    <!-- Główny arkusz stylów -->
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- Font Awesome 6 (ikony) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Dodatkowe style dla menu użytkownika i sidebara (jeśli nie ma w style.css) */
        .user-menu {
            position: relative;
            margin-left: auto;
        }
        .user-trigger {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 12px;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.2s;
            border: none;
            background: none;
        }
        .user-trigger:hover {
            background: var(--gray-100);
        }
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 16px;
            overflow: hidden;
        }
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .user-info {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }
        .user-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--gray-900);
        }
        .user-role {
            font-size: 12px;
            color: var(--gray-500);
        }
        .user-dropdown-icon {
            color: var(--gray-500);
            font-size: 12px;
            transition: transform 0.2s;
        }
        .user-trigger.active .user-dropdown-icon {
            transform: rotate(180deg);
        }
        .user-dropdown {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            min-width: 220px;
            display: none;
            z-index: 1000;
            border: 1px solid var(--gray-200);
        }
        .user-dropdown.active {
            display: block;
            animation: slideDown 0.2s ease;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .user-dropdown-header {
            padding: 16px;
            border-bottom: 1px solid var(--gray-200);
        }
        .user-dropdown-header .user-name {
            font-size: 16px;
            margin-bottom: 4px;
        }
        .user-dropdown-menu {
            padding: 8px;
        }
        .user-dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            color: var(--gray-700);
            text-decoration: none;
            border-radius: 6px;
            transition: background 0.2s;
            font-size: 14px;
        }
        .user-dropdown-item:hover {
            background: var(--gray-100);
        }
        .user-dropdown-item i {
            width: 20px;
            text-align: center;
            color: var(--gray-500);
        }
        .user-dropdown-item.logout {
            color: var(--danger);
        }
        .user-dropdown-item.logout i {
            color: var(--danger);
        }
        .dropdown-divider {
            height: 1px;
            background: var(--gray-200);
            margin: 8px 0;
        }
        @media (max-width: 768px) {
            .user-info {
                display: none;
            }
        }

        /* Sidebar (przeniesiony z osobnego pliku dla spójności) */
        .sidebar {
            position: fixed;
            left: 0;
            top: 64px;
            bottom: 0;
            width: 260px;
            background: white;
            padding: 24px 16px;
            box-shadow: var(--shadow-sm);
            transition: transform 0.3s ease;
            z-index: 999;
            border-right: 1px solid var(--gray-200);
            overflow-y: auto;
            transform: translateX(-100%); /* Domyślnie ukryty */
        }
        .sidebar-open {
            transform: translateX(0) !important;
        }
        .main-content {
            margin-left: 0;
            padding: 88px 24px 24px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
        }
        .sidebar-open ~ .main-content {
            margin-left: 260px;
        }
        @media (max-width: 768px) {
            .sidebar {
                width: 280px;
            }
            .sidebar-open ~ .main-content {
                margin-left: 0;
            }
            .sidebar-open::before {
                content: '';
                position: fixed;
                top: 64px;
                left: 280px;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 998;
            }
        }
    </style>
</head>
<body>
    <!-- Górny pasek nawigacyjny -->
    <nav class="top-nav">
        <button class="nav-toggle" id="navToggle">
            <i class="fas fa-bars"></i>
        </button>
        <div class="logo">
            <i class="fas fa-print logo-icon"></i>
            <span>Monitor Drukarek</span>
        </div>

        <!-- Miejsce na ewentualne dodatkowe elementy -->
        <div class="nav-spacer"></div>

        <!-- Menu użytkownika -->
        <div class="user-menu">
            <button class="user-trigger" id="userTrigger">
                <div class="user-avatar">
                    <?php if ($user_avatar): ?>
                        <img src="<?php echo htmlspecialchars($user_avatar); ?>" alt="Avatar">
                    <?php else: ?>
                        <?php echo strtoupper(substr($user_name ?? '', 0, 1) ?: '?'); ?>
                    <?php endif; ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars($user_role_display); ?></div>
                </div>
                <i class="fas fa-chevron-down user-dropdown-icon"></i>
            </button>

            <div class="user-dropdown" id="userDropdown">
                <div class="user-dropdown-header">
                    <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars($user_role_display); ?></div>
                </div>
                <div class="user-dropdown-menu">
                    <a href="index.php?page=account" class="user-dropdown-item">
                        <i class="fas fa-user"></i>
                        <span>Moje konto</span>
                    </a>
                    <?php if (function_exists('hasPermission') && hasPermission('users', 'view')): ?>
                    <a href="index.php?page=users" class="user-dropdown-item">
                        <i class="fas fa-users"></i>
                        <span>Zarządzanie użytkownikami</span>
                    </a>
                    <?php endif; ?>
                    <?php if (function_exists('hasPermission') && hasPermission('config', 'view')): ?>
                    <a href="index.php?page=config_content" class="user-dropdown-item">
                        <i class="fas fa-cog"></i>
                        <span>Ustawienia</span>
                    </a>
                    <?php endif; ?>
                    <div class="dropdown-divider"></div>
                    <a href="logout.php" class="user-dropdown-item logout">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Wyloguj się</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Wyświetlanie komunikatów (messages) -->
    <?php if (!empty($_SESSION['messages'])): ?>
    <div class="messages">
        <?php foreach ($_SESSION['messages'] as $index => $msg): ?>
        <div class="message <?php echo htmlspecialchars($msg['type'] ?? 'info'); ?>">
            <i class="fas <?php echo ($msg['type'] ?? 'info') === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
            <span><?php echo htmlspecialchars($msg['text'] ?? ''); ?></span>
            <a href="?action=dismiss_message&index=<?php echo $index; ?>" class="message-close">
                <i class="fas fa-times"></i>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
        // Usuń komunikaty po wyświetleniu (tylko przy GET i braku akcji)
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['action'])) {
            $_SESSION['messages'] = [];
        }
    endif;
    ?>

    <!-- Skrypty JavaScript dla interakcji -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Obsługa rozwijanego menu użytkownika
            const userTrigger = document.getElementById('userTrigger');
            const userDropdown = document.getElementById('userDropdown');
            if (userTrigger && userDropdown) {
                userTrigger.addEventListener('click', function(e) {
                    e.stopPropagation();
                    this.classList.toggle('active');
                    userDropdown.classList.toggle('active');
                });
                // Zamknij po kliknięciu poza menu
                document.addEventListener('click', function(e) {
                    if (!userTrigger.contains(e.target) && !userDropdown.contains(e.target)) {
                        userTrigger.classList.remove('active');
                        userDropdown.classList.remove('active');
                    }
                });
            }

            // Obsługa wysuwania sidebara
            const navToggle = document.getElementById('navToggle');
            const sidebar = document.getElementById('sidebar');
            if (navToggle && sidebar) {
                sidebar.classList.remove('sidebar-open'); // na start zamknięty
                navToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    sidebar.classList.toggle('sidebar-open');
                });
                // Na małych ekranach zamknij po kliknięciu w link
                const navLinks = document.querySelectorAll('.nav-item');
                navLinks.forEach(link => {
                    link.addEventListener('click', function() {
                        if (window.innerWidth <= 768) {
                            sidebar.classList.remove('sidebar-open');
                        }
                    });
                });
                // Zamknij po kliknięciu w tło (mobile)
                document.addEventListener('click', function(e) {
                    if (window.innerWidth <= 768 && sidebar.classList.contains('sidebar-open')) {
                        if (!sidebar.contains(e.target) && !navToggle.contains(e.target)) {
                            sidebar.classList.remove('sidebar-open');
                        }
                    }
                });
            }
        });

        // Globalne funkcje do modali (jeśli używane)
        window.openAddModal = function() {
            const modal = document.getElementById('printerModal');
            if (modal) modal.classList.add('active');
        };
        window.closeModal = function() {
            ['printerModal', 'importModal', 'tonersModal'].forEach(id => {
                const modal = document.getElementById(id);
                if (modal) modal.classList.remove('active');
            });
        };
        window.openImportModal = function() {
            const modal = document.getElementById('importModal');
            if (modal) modal.classList.add('active');
        };
        window.closeImportModal = function() {
            const modal = document.getElementById('importModal');
            if (modal) modal.classList.remove('active');
        };
    </script>
</body>
</html>