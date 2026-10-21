<?php
// includes/sidebar.php
global $page; // zmienna z index.php określająca aktualną stronę

// Funkcja pomocnicza do zaznaczania aktywnego elementu
function isActive($item) {
    global $page;
    return $page === $item ? 'active' : '';
}

// Sprawdź, czy użytkownik ma dostęp do którejkolwiek z podstron spisu urządzeń
$hasAnyDeviceSubpage = hasPermission('ups', 'view') || hasPermission('dect_phones', 'view') || hasPermission('terminals', 'view') || hasPermission('peripherals', 'view') || hasPermission('printers_outsourcing', 'view');
?>

<aside class="sidebar" id="sidebar">
    <nav class="nav-menu">
        <!-- Dashboard -->
        <a href="index.php?page=dashboard" class="nav-item <?php echo isActive('dashboard'); ?>">
            <i class="fas fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>

        <!-- Urządzenia (główne) -->
        <a href="index.php?page=devices" class="nav-item <?php echo isActive('devices'); ?>">
            <i class="fas fa-print"></i>
            <span>Urządzenia</span>
        </a>

        <!-- Magazyn materiałów -->
        <a href="index.php?page=materials" class="nav-item <?php echo isActive('materials'); ?>">
            <i class="fas fa-boxes"></i>
            <span>Magazyn materiałów</span>
        </a>

        <!-- Raporty -->
        <a href="index.php?page=reports" class="nav-item <?php echo isActive('reports'); ?>">
            <i class="fas fa-chart-bar"></i>
            <span>Raporty</span>
        </a>

        <!-- Mapa -->
        <a href="index.php?page=map" class="nav-item <?php echo isActive('map'); ?>">
            <i class="fas fa-map-marked-alt"></i>
            <span>Mapa</span>
        </a>

        <!-- Rozwijana sekcja: Spis urządzeń -->
        <?php if ($hasAnyDeviceSubpage): ?>
        <div class="nav-section">
            <div class="nav-item dropdown-trigger" id="devicesDropdownTrigger">
                <i class="fas fa-list"></i>
                <span>Spis urządzeń</span>
                <i class="fas fa-chevron-down dropdown-icon"></i>
            </div>
            <div class="dropdown-menu" id="devicesDropdownMenu">
                <?php if (hasPermission('ups', 'view')): ?>
                <a href="index.php?page=ups" class="nav-item dropdown-item <?php echo isActive('ups'); ?>">
                    <i class="fas fa-bolt"></i>
                    <span>UPS</span>
                </a>
                <?php endif; ?>

                <?php if (hasPermission('dect_phones', 'view')): ?>
                <a href="index.php?page=dect_phones" class="nav-item dropdown-item <?php echo isActive('dect_phones'); ?>">
                    <i class="fas fa-phone"></i>
                    <span>Telefony DECT</span>
                </a>
                <?php endif; ?>

                <?php if (hasPermission('terminals', 'view')): ?>
                <a href="index.php?page=terminals" class="nav-item dropdown-item <?php echo isActive('terminals'); ?>">
                    <i class="fas fa-terminal"></i>
                    <span>Terminale</span>
                </a>
                <?php endif; ?>

                <?php if (hasPermission('peripherals', 'view')): ?>
                <a href="index.php?page=peripherals" class="nav-item dropdown-item <?php echo isActive('peripherals'); ?>">
                    <i class="fas fa-mouse"></i>
                    <span>Urządzenia peryferyjne</span>
                </a>
                <?php endif; ?>

                <?php if (hasPermission('printers_outsourcing', 'view')): ?>
                <a href="index.php?page=printers_outsourcing" class="nav-item dropdown-item <?php echo isActive('printers_outsourcing'); ?>">
                    <i class="fas fa-print"></i>
                    <span>Drukarki - Outsourcing</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Użytkownicy -->
        <?php if (hasPermission('users', 'view')): ?>
        <a href="index.php?page=users" class="nav-item <?php echo isActive('users'); ?>">
            <i class="fas fa-users"></i>
            <span>Użytkownicy</span>
        </a>
        <?php endif; ?>

        <!-- Konfiguracja -->
        <?php if (hasPermission('config', 'view')): ?>
        <a href="index.php?page=config_content" class="nav-item <?php echo isActive('config_content'); ?>">
            <i class="fas fa-cog"></i>
            <span>Konfiguracja</span>
        </a>
        <?php endif; ?>

        <!-- Moje konto -->
        <a href="index.php?page=account" class="nav-item <?php echo isActive('account'); ?>">
            <i class="fas fa-user"></i>
            <span>Moje konto</span>
        </a>
    </nav>
</aside>

<script>
// Obsługa rozwijanego menu w sidebarze
document.addEventListener('DOMContentLoaded', function() {
    const trigger = document.getElementById('devicesDropdownTrigger');
    const menu = document.getElementById('devicesDropdownMenu');
    
    if (trigger && menu) {
        // Sprawdź, czy któraś z podstron jest aktywna – jeśli tak, rozwiń menu
        const activeItems = menu.querySelectorAll('.nav-item.active');
        if (activeItems.length > 0) {
            menu.style.display = 'block';
            trigger.classList.add('expanded');
        } else {
            menu.style.display = 'none';
        }

        trigger.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (menu.style.display === 'none') {
                menu.style.display = 'block';
                trigger.classList.add('expanded');
            } else {
                menu.style.display = 'none';
                trigger.classList.remove('expanded');
            }
        });
    }
});
</script>

<style>
/* Dodatkowe style dla rozwijanego menu */
.nav-section {
    margin-bottom: 4px;
}
.dropdown-trigger {
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.dropdown-trigger .dropdown-icon {
    transition: transform 0.3s ease;
    font-size: 12px;
}
.dropdown-trigger.expanded .dropdown-icon {
    transform: rotate(180deg);
}
.dropdown-menu {
    margin-left: 16px; /* wcięcie podmenu */
    border-left: 1px dashed var(--gray-300);
    padding-left: 8px;
}
.dropdown-item {
    padding: 8px 12px;
    font-size: 13px;
}
.dropdown-item i {
    font-size: 14px;
}
</style>