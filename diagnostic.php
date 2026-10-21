<?php
/**
 * diagnostic.php - Narzędzie diagnostyczne bazy danych
 * 
 * Sprawdza stan bazy i pomaga znaleźć problemy
 * UŻYCIE: http://localhost/printer_monitor/diagnostic.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Tylko dla adminów
if (!isLoggedIn() || !hasPermission('config', 'view')) {
    die("Brak uprawnień");
}

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnostyka Bazy Danych</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .diagnostic-container { max-width: 1200px; margin: 40px auto; padding: 20px; }
        .diagnostic-section { background: white; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: var(--shadow); }
        .diagnostic-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .diagnostic-table th, .diagnostic-table td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        .diagnostic-table th { background: #f5f5f5; font-weight: 600; }
        .issue { color: var(--danger); font-weight: 600; }
        .ok { color: var(--success); font-weight: 600; }
        .action-btn { padding: 8px 16px; background: var(--danger); color: white; border: none; border-radius: 4px; cursor: pointer; margin-right: 8px; }
        .action-btn:hover { opacity: 0.9; }
    </style>
</head>
<body>
    <div class="diagnostic-container">
        <h1>🔍 Diagnostyka Bazy Danych</h1>
        <p style="margin-bottom: 20px;">Narzędzie do wykrywania i naprawiania problemów z bazą danych</p>

        <!-- Statystyki tabel -->
        <div class="diagnostic-section">
            <h2>📊 Statystyki Tabel</h2>
            <table class="diagnostic-table">
                <thead>
                    <tr>
                        <th>Tabela</th>
                        <th>Liczba rekordów</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $tables = ['printers', 'printer_status', 'users', 'materials', 'material_history', 'activity_logs', 'sessions', 'permissions'];
                    foreach ($tables as $table) {
                        try {
                            $count = $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
                            echo "<tr>";
                            echo "<td><strong>$table</strong></td>";
                            echo "<td>$count</td>";
                            echo "<td class='ok'>✓ OK</td>";
                            echo "</tr>";
                        } catch (PDOException $e) {
                            echo "<tr>";
                            echo "<td><strong>$table</strong></td>";
                            echo "<td>-</td>";
                            echo "<td class='issue'>✗ Błąd: " . $e->getMessage() . "</td>";
                            echo "</tr>";
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- Drukarki bez statusu -->
        <div class="diagnostic-section">
            <h2>⚠️ Drukarki bez statusu</h2>
            <?php
            $stmt = $db->query("SELECT p.id, p.name, p.ip 
                                FROM printers p 
                                LEFT JOIN printer_status ps ON p.id = ps.printer_id 
                                WHERE ps.printer_id IS NULL");
            $missing_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($missing_status)) {
                echo "<p class='ok'>✓ Wszystkie drukarki mają status</p>";
            } else {
                echo "<p class='issue'>Znaleziono " . count($missing_status) . " drukarek bez statusu:</p>";
                echo "<table class='diagnostic-table'>";
                echo "<tr><th>ID</th><th>Nazwa</th><th>IP</th><th>Akcja</th></tr>";
                foreach ($missing_status as $printer) {
                    echo "<tr>";
                    echo "<td>{$printer['id']}</td>";
                    echo "<td>{$printer['name']}</td>";
                    echo "<td>{$printer['ip']}</td>";
                    echo "<td><a href='?action=create_status&id={$printer['id']}' class='action-btn'>Utwórz status</a></td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
            ?>
        </div>

        <!-- Duplikaty IP -->
        <div class="diagnostic-section">
            <h2>🔄 Duplikaty adresów IP</h2>
            <?php
            $stmt = $db->query("SELECT ip, COUNT(*) as count, GROUP_CONCAT(id) as ids, GROUP_CONCAT(name, ', ') as names
                                FROM printers 
                                GROUP BY ip 
                                HAVING count > 1");
            $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($duplicates)) {
                echo "<p class='ok'>✓ Brak duplikatów</p>";
            } else {
                echo "<p class='issue'>Znaleziono " . count($duplicates) . " zduplikowanych IP:</p>";
                echo "<table class='diagnostic-table'>";
                echo "<tr><th>IP</th><th>Liczba</th><th>IDs</th><th>Nazwy</th><th>Akcja</th></tr>";
                foreach ($duplicates as $dup) {
                    echo "<tr>";
                    echo "<td><strong>{$dup['ip']}</strong></td>";
                    echo "<td class='issue'>{$dup['count']}</td>";
                    echo "<td>{$dup['ids']}</td>";
                    echo "<td>{$dup['names']}</td>";
                    echo "<td><a href='?action=remove_duplicates&ip=" . urlencode($dup['ip']) . "' class='action-btn' onclick='return confirm(\"Usuńć duplikaty tego IP?\")'>Usuń duplikaty</a></td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
            ?>
        </div>

        <!-- Statusy bez drukarek -->
        <div class="diagnostic-section">
            <h2>👻 Statusy bez drukarek (orphaned records)</h2>
            <?php
            $stmt = $db->query("SELECT ps.printer_id 
                                FROM printer_status ps 
                                LEFT JOIN printers p ON ps.printer_id = p.id 
                                WHERE p.id IS NULL");
            $orphaned = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($orphaned)) {
                echo "<p class='ok'>✓ Brak osieroconych rekordów</p>";
            } else {
                echo "<p class='issue'>Znaleziono " . count($orphaned) . " osieroconych statusów:</p>";
                echo "<form method='post'>";
                echo "<button type='submit' name='clean_orphaned' class='action-btn'>Wyczyść osierocone statusy</button>";
                echo "</form>";
            }
            ?>
        </div>

        <!-- Akcje naprawcze -->
        <?php
        // Obsługa akcji
        if (isset($_GET['action'])) {
            $action = $_GET['action'];
            
            if ($action === 'create_status' && isset($_GET['id'])) {
                $printer_id = intval($_GET['id']);
                try {
                    $stmt = $db->prepare("INSERT INTO printer_status (printer_id, status, materials_json, last_check) VALUES (?, 'offline', '{}', datetime('now'))");
                    $stmt->execute([$printer_id]);
                    echo "<div class='diagnostic-section'><p class='ok'>✓ Utworzono status dla drukarki ID: $printer_id</p></div>";
                    echo "<meta http-equiv='refresh' content='2;url=diagnostic.php'>";
                } catch (PDOException $e) {
                    echo "<div class='diagnostic-section'><p class='issue'>✗ Błąd: " . $e->getMessage() . "</p></div>";
                }
            }
            
            if ($action === 'remove_duplicates' && isset($_GET['ip'])) {
                $ip = $_GET['ip'];
                try {
                    // Zostaw tylko pierwszy rekord, usuń resztę
                    $stmt = $db->prepare("SELECT id FROM printers WHERE ip = ? ORDER BY id ASC");
                    $stmt->execute([$ip]);
                    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    if (count($ids) > 1) {
                        $keep_id = array_shift($ids); // Pierwszy zostaje
                        
                        foreach ($ids as $delete_id) {
                            $stmt = $db->prepare("DELETE FROM printer_status WHERE printer_id = ?");
                            $stmt->execute([$delete_id]);
                            
                            $stmt = $db->prepare("DELETE FROM printers WHERE id = ?");
                            $stmt->execute([$delete_id]);
                        }
                        
                        echo "<div class='diagnostic-section'><p class='ok'>✓ Usunięto " . count($ids) . " duplikatów. Pozostawiono drukarkę ID: $keep_id</p></div>";
                        echo "<meta http-equiv='refresh' content='2;url=diagnostic.php'>";
                    }
                } catch (PDOException $e) {
                    echo "<div class='diagnostic-section'><p class='issue'>✗ Błąd: " . $e->getMessage() . "</p></div>";
                }
            }
        }
        
        if (isset($_POST['clean_orphaned'])) {
            try {
                $stmt = $db->query("DELETE FROM printer_status WHERE printer_id NOT IN (SELECT id FROM printers)");
                $deleted = $stmt->rowCount();
                echo "<div class='diagnostic-section'><p class='ok'>✓ Usunięto $deleted osieroconych statusów</p></div>";
                echo "<meta http-equiv='refresh' content='2;url=diagnostic.php'>";
            } catch (PDOException $e) {
                echo "<div class='diagnostic-section'><p class='issue'>✗ Błąd: " . $e->getMessage() . "</p></div>";
            }
        }
        ?>

        <div class="diagnostic-section">
            <h2>🔧 Akcje globalne</h2>
            <form method="post" onsubmit="return confirm('Czy na pewno? Ta akcja jest nieodwracalna!')">
                <button type="submit" name="rebuild_all_status" class="action-btn">Przebuduj wszystkie statusy</button>
                <p style="margin-top: 10px; color: var(--gray-600);">
                    Usuwa wszystkie statusy i tworzy nowe dla wszystkich drukarek
                </p>
            </form>
        </div>

        <?php
        if (isset($_POST['rebuild_all_status'])) {
            try {
                // Usuń wszystkie statusy
                $db->query("DELETE FROM printer_status");
                
                // Utwórz nowe dla wszystkich drukarek
                $stmt = $db->query("SELECT id FROM printers");
                $printer_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                $stmt_insert = $db->prepare("INSERT INTO printer_status (printer_id, status, materials_json, last_check) VALUES (?, 'offline', '{}', datetime('now'))");
                foreach ($printer_ids as $pid) {
                    $stmt_insert->execute([$pid]);
                }
                
                echo "<div class='diagnostic-section'><p class='ok'>✓ Przebudowano statusy dla " . count($printer_ids) . " drukarek</p></div>";
                echo "<meta http-equiv='refresh' content='2;url=diagnostic.php'>";
            } catch (PDOException $e) {
                echo "<div class='diagnostic-section'><p class='issue'>✗ Błąd: " . $e->getMessage() . "</p></div>";
            }
        }
        ?>

        <div style="margin-top: 40px; text-align: center;">
            <a href="index.php?page=devices" class="btn btn-primary">← Powrót do urządzeń</a>
        </div>
    </div>
</body>
</html>