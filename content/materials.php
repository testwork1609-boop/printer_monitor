<?php
ob_start();
// content/materials.php - Zarządzanie magazynem materiałów
global $db;

requirePermission('materials', 'view');

$material_types = [
    'toner' => 'Toner',
    'drum' => 'Bęben',
    'fuser' => 'Fuser/Utrwalacz',
    'transfer' => 'Zespół transferowy',
    'maintenance' => 'Zestaw serwisowy',
    'waste' => 'Pojemnik na zużyty toner',
    'roller' => 'Wały',
    'belt' => 'Pasek',
    'chip' => 'Chip/Układ',
    'kit' => 'Zestaw',
    'other' => 'Inny'
];

function addMessage($type, $text) {
    if (!isset($_SESSION['messages'])) $_SESSION['messages'] = [];
    if (count($_SESSION['messages']) >= 3) array_shift($_SESSION['messages']);
    $_SESSION['messages'][] = ['type' => $type, 'text' => $text];
}

// ------------------------------------------------------------
// SPRAWDZENIE I AKTUALIZACJA STRUKTURY BAZY DANYCH (TYLKO RAZ)
// ------------------------------------------------------------
if (!isset($_SESSION['db_structure_checked']) || $_SESSION['db_structure_checked'] !== true) {
    
    $max_retries = 5;
    $retry_delay = 200000; // 0.2 sekundy
    $success = false;
    
    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        $inTransaction = false;
        
        try {
            // Próba ustawienia trybu WAL – jeśli się nie uda, tylko logujemy
            try {
                $db->exec('PRAGMA journal_mode=WAL');
            } catch (Exception $e) {
                error_log("Nie można ustawić WAL w materials.php: " . $e->getMessage());
            }
            
            $db->exec('PRAGMA locking_mode = EXCLUSIVE');
            $db->beginTransaction();
            $inTransaction = true;
            
            // Sprawdź czy tabela materials istnieje
            $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='materials'")->fetch();
            
            if ($tables) {
                // Sprawdź kolumny
                $columns = $db->query("PRAGMA table_info(materials)")->fetchAll();
                $column_names = array_column($columns, 'name');
                
                // Dodaj brakujące kolumny (jeśli nie istnieją)
                if (!in_array('printer_id', $column_names)) {
                    $db->exec("ALTER TABLE materials ADD COLUMN printer_id INTEGER");
                }
                if (!in_array('printer_host_number', $column_names)) {
                    $db->exec("ALTER TABLE materials ADD COLUMN printer_host_number TEXT");
                }
                // Dodaj kolumnę color jeśli nie istnieje
                if (!in_array('color', $column_names)) {
                    $db->exec("ALTER TABLE materials ADD COLUMN color TEXT");
                }
                
                // Usuń kolumnę min_quantity jeśli istnieje (wymaga recreate table)
                if (in_array('min_quantity', $column_names)) {
                    // SQLite nie wspiera DROP COLUMN, więc tworzymy nową tabelę
                    $db->exec("CREATE TABLE materials_new (
                        id TEXT PRIMARY KEY,
                        type TEXT NOT NULL,
                        material_code TEXT NOT NULL,
                        printer_id INTEGER,
                        printer_name TEXT,
                        printer_model TEXT,
                        printer_host_number TEXT,
                        quantity INTEGER NOT NULL,
                        location TEXT,
                        notes TEXT,
                        color TEXT,
                        added_date DATETIME,
                        last_updated DATETIME,
                        issued_to TEXT,
                        issued_date DATETIME,
                        FOREIGN KEY (printer_id) REFERENCES printers(id)
                    )");
                    
                    // Skopiuj dane
                    $db->exec("INSERT INTO materials_new 
                        (id, type, material_code, printer_id, printer_name, printer_model, printer_host_number, 
                         quantity, location, notes, color, added_date, last_updated, issued_to, issued_date)
                        SELECT id, type, material_code, NULL, printer_name, printer_model, NULL,
                               quantity, location, notes, NULL, added_date, last_updated, issued_to, issued_date
                        FROM materials");
                    
                    // Zamień tabele
                    $db->exec("DROP TABLE materials");
                    $db->exec("ALTER TABLE materials_new RENAME TO materials");
                }
            } else {
                // Utwórz tabelę materials od podstaw
                $db->exec("CREATE TABLE IF NOT EXISTS materials (
                    id TEXT PRIMARY KEY,
                    type TEXT NOT NULL,
                    material_code TEXT NOT NULL,
                    printer_id INTEGER,
                    printer_name TEXT,
                    printer_model TEXT,
                    printer_host_number TEXT,
                    quantity INTEGER NOT NULL,
                    location TEXT,
                    notes TEXT,
                    color TEXT,
                    added_date DATETIME,
                    last_updated DATETIME,
                    issued_to TEXT,
                    issued_date DATETIME,
                    FOREIGN KEY (printer_id) REFERENCES printers(id)
                )");
            }
            
            // Sprawdź czy tabela material_history istnieje
            $tables_history = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='material_history'")->fetch();
            
            if (!$tables_history) {
                $db->exec("CREATE TABLE IF NOT EXISTS material_history (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    material_id TEXT NOT NULL,
                    action TEXT NOT NULL,
                    quantity_change INTEGER NOT NULL,
                    quantity_after INTEGER NOT NULL,
                    printer_id INTEGER,
                    user_id INTEGER NOT NULL,
                    notes TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (material_id) REFERENCES materials(id),
                    FOREIGN KEY (printer_id) REFERENCES printers(id),
                    FOREIGN KEY (user_id) REFERENCES users(id)
                )");
            }
            
            // Sprawdź czy tabela printers istnieje (dla kompletności)
            $tables_printers = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='printers'")->fetch();
            
            if (!$tables_printers) {
                $db->exec("CREATE TABLE IF NOT EXISTS printers (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    ip TEXT NOT NULL UNIQUE,
                    model TEXT,
                    printer_type TEXT DEFAULT 'mono',
                    host_number TEXT,
                    hall TEXT DEFAULT 'K3',
                    location TEXT,
                    is_active BOOLEAN DEFAULT 1,
                    added_date DATETIME DEFAULT CURRENT_TIMESTAMP
                )");
            }
            
            // Sprawdź czy tabela material_alerts istnieje
            $tables_alerts = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='material_alerts'")->fetch();
            if (!$tables_alerts) {
                $db->exec("CREATE TABLE material_alerts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    printer_id INTEGER NOT NULL,
                    material_type TEXT NOT NULL,
                    alert_level INTEGER NOT NULL,
                    current_level INTEGER NOT NULL,
                    alert_date DATETIME NOT NULL,
                    resolved_date DATETIME,
                    resolved_by_material_id TEXT,
                    FOREIGN KEY (printer_id) REFERENCES printers(id),
                    FOREIGN KEY (resolved_by_material_id) REFERENCES materials(id)
                )");
            }
            
            // Sprawdź czy tabela material_replacements istnieje
            $tables_replacements = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='material_replacements'")->fetch();
            if (!$tables_replacements) {
                $db->exec("CREATE TABLE material_replacements (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    printer_id INTEGER NOT NULL,
                    material_type TEXT NOT NULL,
                    old_material_id TEXT,
                    new_material_id TEXT,
                    alert_date DATETIME NOT NULL,
                    replacement_date DATETIME NOT NULL,
                    days_waiting INTEGER,
                    FOREIGN KEY (printer_id) REFERENCES printers(id),
                    FOREIGN KEY (old_material_id) REFERENCES materials(id),
                    FOREIGN KEY (new_material_id) REFERENCES materials(id)
                )");
            }
            
            // Zatwierdź transakcję
            $db->commit();
            $inTransaction = false;
            $db->exec('PRAGMA locking_mode = NORMAL');
            
            $_SESSION['db_structure_checked'] = true;
            $success = true;
            break; // Sukces, przerywamy pętlę
            
        } catch (PDOException $e) {
            // Wycofaj transakcję tylko jeśli była rozpoczęta
            if ($inTransaction) {
                $db->rollBack();
            }
            // Przywróć normalny locking mode (jeśli udało się ustawić exclusive)
            try {
                $db->exec('PRAGMA locking_mode = NORMAL');
            } catch (Exception $ex) {
                // ignorujemy
            }
            
            // Sprawdź czy to błąd blokady
            if (strpos($e->getMessage(), 'database table is locked') !== false) {
                if ($attempt < $max_retries) {
                    // Poczekaj i spróbuj ponownie
                    usleep($retry_delay * $attempt);
                    continue;
                } else {
                    error_log("Nie udało się zaktualizować struktury bazy po $max_retries próbach: " . $e->getMessage());
                }
            } else {
                // Inny błąd - loguj i nie próbuj ponownie
                error_log("Błąd struktury bazy: " . $e->getMessage());
                break;
            }
        }
    }
    
    // Jeśli nie udało się zaktualizować, ustaw sesję na false, żeby nie próbować przy każdym wejściu
    if (!$success) {
        $_SESSION['db_structure_checked'] = false;
        addMessage('warning', 'Nie udało się automatycznie sprawdzić struktury bazy danych. Skontaktuj się z administratorem.');
    }
}
// ------------------------------------------------------------
// KONIEC SEKCJI SPRAWDZANIA STRUKTURY
// ------------------------------------------------------------

// Pobierz wszystkie drukarki dla selectów
$stmt_printers = $db->query("SELECT id, name, model, host_number FROM printers WHERE is_active = 1 ORDER BY name");
$all_printers = $stmt_printers->fetchAll(PDO::FETCH_ASSOC);

// Obsługa dodawania materiału
if (isset($_POST['add_material']) && hasPermission('materials', 'create')) {
    $type = filter_input(INPUT_POST, 'type', FILTER_SANITIZE_STRING);
    $material_code = filter_input(INPUT_POST, 'material_code', FILTER_SANITIZE_STRING);
    $printer_id = filter_input(INPUT_POST, 'printer_id', FILTER_VALIDATE_INT);
    $printer_name = filter_input(INPUT_POST, 'printer_name', FILTER_SANITIZE_STRING);
    $printer_model = filter_input(INPUT_POST, 'printer_model', FILTER_SANITIZE_STRING);
    $printer_host_number = filter_input(INPUT_POST, 'printer_host_number', FILTER_SANITIZE_STRING);
    $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
    $location = filter_input(INPUT_POST, 'location', FILTER_SANITIZE_STRING);
    $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING);
    $color = filter_input(INPUT_POST, 'color', FILTER_SANITIZE_STRING);
    
    if ($type && $material_code && $quantity > 0) {
        $material_id = uniqid('mat_');
        $stmt = $db->prepare("INSERT INTO materials (id, type, material_code, printer_id, printer_name, printer_model, printer_host_number, quantity, location, notes, color, added_date, last_updated) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([$material_id, $type, $material_code, $printer_id, $printer_name, $printer_model, $printer_host_number, $quantity, $location, $notes, $color]);
        
        // Dodaj wpis do historii
        $stmt = $db->prepare("INSERT INTO material_history (material_id, action, quantity_change, quantity_after, user_id, notes) 
                              VALUES (?, 'added', ?, ?, ?, 'Dodano do magazynu')");
        $stmt->execute([$material_id, $quantity, $quantity, $_SESSION['user_id']]);
        
        // Sprawdź alerty dla tej drukarki i koloru
        if ($printer_id && $color) {
            $stmt = $db->prepare("SELECT id, material_type, alert_date FROM material_alerts 
                                  WHERE printer_id = ? AND material_type = ? AND resolved_date IS NULL");
            $stmt->execute([$printer_id, $color]);
            $alert = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($alert) {
                // Zamknij alert
                $db->prepare("UPDATE material_alerts SET resolved_date = NOW(), resolved_by_material_id = ? WHERE id = ?")
                   ->execute([$material_id, $alert['id']]);
                
                // Oblicz dni oczekiwania
                $days = round((time() - strtotime($alert['alert_date'])) / 86400);
                
                // Zapisz w historii wymian
                $stmt = $db->prepare("INSERT INTO material_replacements 
                                      (printer_id, material_type, new_material_id, alert_date, replacement_date, days_waiting)
                                      VALUES (?, ?, ?, ?, NOW(), ?)");
                $stmt->execute([$printer_id, $color, $material_id, $alert['alert_date'], $days]);
                
                error_log("Zamknięto alert dla drukarki $printer_id, materiał $color, nowy materiał $material_id");
            }
        }
        
        addMessage('success', "Dodano materiał: $material_code");
        logActivity($_SESSION['user_id'], 'add_material', 'materials', "Dodano materiał: $material_code");
        ob_end_clean();
        header('Location: index.php?page=materials');
        exit;
    } else {
        addMessage('error', 'Wypełnij wymagane pola');
    }
}

// Obsługa wydawania materiału
if (isset($_POST['issue_material']) && hasPermission('materials', 'edit')) {
    $material_id = filter_input(INPUT_POST, 'material_id', FILTER_SANITIZE_STRING);
    $quantity = filter_input(INPUT_POST, 'issue_quantity', FILTER_VALIDATE_INT);
    $printer_id = filter_input(INPUT_POST, 'printer_id', FILTER_VALIDATE_INT);
    $issued_to = filter_input(INPUT_POST, 'issued_to', FILTER_SANITIZE_STRING);
    $notes = filter_input(INPUT_POST, 'issue_notes', FILTER_SANITIZE_STRING);
    
    if ($material_id && $quantity > 0) {
        // Pobierz aktualną ilość
        $stmt = $db->prepare("SELECT quantity FROM materials WHERE id = ?");
        $stmt->execute([$material_id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($current && $current['quantity'] >= $quantity) {
            $new_quantity = $current['quantity'] - $quantity;
            
            // Zaktualizuj materiał
            $stmt = $db->prepare("UPDATE materials SET quantity = ?, issued_to = ?, issued_date = NOW(), last_updated = NOW() WHERE id = ?");
            $stmt->execute([$new_quantity, $issued_to, $material_id]);
            
            // Dodaj do historii
            $stmt = $db->prepare("INSERT INTO material_history (material_id, action, quantity_change, quantity_after, printer_id, user_id, notes) 
                                  VALUES (?, 'issued', ?, ?, ?, ?, ?)");
            $stmt->execute([$material_id, -$quantity, $new_quantity, $printer_id, $_SESSION['user_id'], $notes]);
            
            addMessage('success', 'Materiał wydany');
            logActivity($_SESSION['user_id'], 'issue_material', 'materials', "Wydano materiał: $material_id");
            ob_end_clean();
            header('Location: index.php?page=materials');
            exit;
        } else {
            addMessage('error', 'Niewystarczająca ilość w magazynie');
        }
    }
}

// Obsługa edycji
if (isset($_POST['update_material']) && hasPermission('materials', 'edit')) {
    $material_id = filter_input(INPUT_POST, 'material_id', FILTER_SANITIZE_STRING);
    $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
    $location = filter_input(INPUT_POST, 'location', FILTER_SANITIZE_STRING);
    $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING);
    
    if ($material_id && $quantity >= 0) {
        // Pobierz starą ilość
        $stmt = $db->prepare("SELECT quantity FROM materials WHERE id = ?");
        $stmt->execute([$material_id]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $db->prepare("UPDATE materials SET quantity = ?, location = ?, notes = ?, last_updated = NOW() WHERE id = ?");
        $stmt->execute([$quantity, $location, $notes, $material_id]);
        
        // Jeśli zmieniono ilość, dodaj do historii
        if ($old && $old['quantity'] != $quantity) {
            $change = $quantity - $old['quantity'];
            $stmt = $db->prepare("INSERT INTO material_history (material_id, action, quantity_change, quantity_after, user_id, notes) 
                                  VALUES (?, 'adjusted', ?, ?, ?, 'Korekta magazynowa')");
            $stmt->execute([$material_id, $change, $quantity, $_SESSION['user_id']]);
        }
        
        addMessage('success', 'Materiał zaktualizowany');
        logActivity($_SESSION['user_id'], 'update_material', 'materials', "Zaktualizowano materiał: $material_id");
        ob_end_clean();
        header('Location: index.php?page=materials');
        exit;
    }
}

// Obsługa usuwania
if (isset($_GET['action']) && $_GET['action'] == 'delete_material' && hasPermission('materials', 'delete')) {
    $material_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_STRING);
    if ($material_id) {
        $stmt = $db->prepare("DELETE FROM materials WHERE id = ?");
        $stmt->execute([$material_id]);
        addMessage('success', 'Materiał usunięty');
        logActivity($_SESSION['user_id'], 'delete_material', 'materials', "Usunięto materiał: $material_id");
        ob_end_clean();
        header('Location: index.php?page=materials');
        exit;
    }
}

// Eksport do CSV - materiały
if (isset($_GET['action']) && $_GET['action'] == 'export_csv') {
    $stmt = $db->query("SELECT * FROM materials ORDER BY added_date DESC");
    $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=materials_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Typ', 'Kod materiału', 'Drukarka', 'Model', 'Nr Hees', 'Ilość', 'Lokalizacja', 'Notatki', 'Data dodania']);
    
    foreach ($materials as $m) {
        fputcsv($output, [
            $m['id'],
            $m['type'],
            $m['material_code'],
            $m['printer_name'],
            $m['printer_model'],
            $m['printer_host_number'],
            $m['quantity'],
            $m['location'],
            $m['notes'],
            $m['added_date']
        ]);
    }
    
    fclose($output);
    exit;
}

// Eksport do CSV - historia
if (isset($_GET['action']) && $_GET['action'] == 'export_history_csv') {
    $stmt = $db->query("SELECT 
        mh.id,
        m.material_code,
        mh.action,
        mh.quantity_change,
        mh.quantity_after,
        p.name as printer_name,
        u.full_name,  -- zmiana: pobieramy full_name
        mh.notes,
        mh.created_at,
        CASE 
            WHEN mh.action = 'added' THEN 'Dodano'
            WHEN mh.action = 'issued' THEN 'Wydano'
            WHEN mh.action = 'returned' THEN 'Zwrócono'
            WHEN mh.action = 'adjusted' THEN 'Korekta'
            ELSE mh.action
        END as action_pl
    FROM material_history mh
    LEFT JOIN materials m ON mh.material_id = m.id
    LEFT JOIN printers p ON mh.printer_id = p.id
    LEFT JOIN users u ON mh.user_id = u.id
    ORDER BY mh.created_at DESC");
    
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=material_history_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Kod materiału', 'Akcja (PL)', 'Akcja (EN)', 'Zmiana ilości', 'Stan po', 'Drukarka', 'Użytkownik', 'Notatki', 'Data transakcji']);
    
    foreach ($history as $h) {
        fputcsv($output, [
            $h['id'],
            $h['material_code'] ?? 'N/A',
            $h['action_pl'],
            $h['action'],
            $h['quantity_change'],
            $h['quantity_after'],
            $h['printer_name'] ?? 'N/A',
            $h['full_name'] ?? 'N/A', // używamy full_name
            $h['notes'] ?? '',
            $h['created_at']
        ]);
    }
    
    fclose($output);
    exit;
}

// Pobierz materiały z połączeniem z drukarkami
$stmt = $db->query("SELECT 
    m.*,
    p.name as printer_name,
    p.model as printer_model,
    p.host_number as printer_host_number
    FROM materials m
    LEFT JOIN printers p ON m.printer_id = p.id
    ORDER BY m.added_date DESC");
$materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statystyki
$total_items = count($materials);
$low_stock = 0;
$total_quantity = 0;

foreach ($materials as $m) {
    $total_quantity += $m['quantity'];
}

// Pobierz drukarki do select
$stmt = $db->query("SELECT id, name, model, host_number FROM printers WHERE is_active = 1 ORDER BY name");
$printers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$can_create = hasPermission('materials', 'create');
$can_edit = hasPermission('materials', 'edit');
$can_delete = hasPermission('materials', 'delete');
?>

<div class="dashboard-header">
    <h1>Magazyn materiałów</h1>
    <p class="text-muted">Zarządzaj stanem magazynowym materiałów eksploatacyjnych</p>
</div>

<!-- Statystyki -->
<div class="stats-grid" style="margin-bottom: 32px;">
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-boxes"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $total_items; ?></div>
            <div class="stat-label">Pozycji w magazynie</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-layer-group"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $total_quantity; ?></div>
            <div class="stat-label">Całkowita ilość</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-print"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo count($all_printers); ?></div>
            <div class="stat-label">Drukarek w systemie</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-tags"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo count(array_unique(array_column($materials, 'type'))); ?></div>
            <div class="stat-label">Typów materiałów</div>
        </div>
    </div>
</div>

<!-- Tabela materiałów -->
<div class="table-section">
    <div class="table-header">
        <h2>Stan magazynu</h2>
        <div class="table-actions">
            <input type="text" class="search-input" placeholder="🔍 Szukaj..." id="searchInput">
            <a href="?page=materials&action=export_csv" class="btn btn-outline">
                <i class="fas fa-file-csv"></i>
                Eksport materiałów
            </a>
            <a href="?page=materials&action=export_history_csv" class="btn btn-outline">
                <i class="fas fa-history"></i>
                Eksport historii
            </a>
            <?php if ($can_create): ?>
            <button class="btn btn-primary" onclick="openAddMaterialModal()">
                <i class="fas fa-plus"></i>
                Dodaj materiał
            </button>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Kontener z pionowym przewijaniem -->
    <div class="table-vertical-container">
        <div class="table-horizontal-container">
            <table class="printer-table" style="min-width: 1200px;">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Typ</th>
                        <th>Kod materiału</th>
                        <th>Drukarka</th>
                        <th>Model</th>
                        <th>Nr Hees</th>
                        <th>Ilość</th>
                        <th>Lokalizacja</th>
                        <th>Data dodania</th>
                        <?php if ($can_edit || $can_delete): ?>
                        <th>Akcje</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="materialsTableBody">
                    <?php foreach ($materials as $index => $material): ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td>
                            <span class="hall-badge"><?php echo htmlspecialchars($material_types[$material['type']] ?? $material['type']); ?></span>
                        </td>
                        <td><strong><?php echo htmlspecialchars($material['material_code']); ?></strong></td>
                        <td><?php echo htmlspecialchars($material['printer_name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($material['printer_model'] ?? '—'); ?></td>
                        <td>
                            <?php if (!empty($material['printer_host_number'])): ?>
                                <span class="hall-badge" style="background: #e0e7ff; color: #4338ca;">
                                    <?php echo htmlspecialchars($material['printer_host_number']); ?>
                                </span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo $material['quantity']; ?></strong>
                        </td>
                        <td><?php echo htmlspecialchars($material['location'] ?? '—'); ?></td>
                        <td><?php echo date('d.m.Y', strtotime($material['added_date'])); ?></td>
                        <?php if ($can_edit || $can_delete): ?>
                        <td>
                            <div class="action-icons">
                                <?php if ($can_edit): ?>
                                <span class="action-icon" onclick="issueMaterial('<?php echo $material['id']; ?>', <?php echo $material['quantity']; ?>)" title="Wydaj">
                                    <i class="fas fa-arrow-right"></i>
                                </span>
                                <span class="action-icon" onclick="editMaterial('<?php echo $material['id']; ?>')" title="Edytuj">
                                    <i class="fas fa-edit"></i>
                                </span>
                                <?php endif; ?>
                                <?php if ($can_delete): ?>
                                <span class="action-icon delete" onclick="confirmDeleteMaterial('<?php echo $material['id']; ?>', '<?php echo htmlspecialchars($material['material_code']); ?>')" title="Usuń">
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
    
    <!-- Stały pasek przewijania poziomego - zawsze na dole okna -->
    <div class="fixed-horizontal-scrollbar" id="fixedHorizontalScrollbar">
        <div class="scrollbar-track" id="scrollbarTrack"></div>
    </div>
</div>

<!-- Historia materiałów -->
<!-- Historia materiałów -->
<div class="table-section" style="margin-top: 32px;">
    <div class="table-header">
        <h2><i class="fas fa-history"></i> Historia transakcji (ostatnie 50)</h2>
    </div>
    
    <div class="table-vertical-container" style="height: 400px;">
        <div class="table-horizontal-container">
            <table class="printer-table" style="min-width: 1200px;">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Kod materiału</th>
                        <th>Akcja</th>
                        <th>Zmiana</th>
                        <th>Stan po</th>
                        <th>Drukarka</th>
                        <th>Użytkownik</th>
                        <th>Notatki</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $stmt = $db->query("SELECT 
                        mh.*,
                        m.material_code,
                        p.name as printer_name,
                        u.full_name,
                        u.username,
                        CASE 
                            WHEN mh.action = 'added' THEN 'Dodano'
                            WHEN mh.action = 'issued' THEN 'Wydano'
                            WHEN mh.action = 'returned' THEN 'Zwrócono'
                            WHEN mh.action = 'adjusted' THEN 'Korekta'
                            ELSE mh.action
                        END as action_pl
                        FROM material_history mh
                        LEFT JOIN materials m ON mh.material_id = m.id
                        LEFT JOIN printers p ON mh.printer_id = p.id
                        LEFT JOIN users u ON mh.user_id = u.id
                        ORDER BY mh.created_at DESC
                        LIMIT 50");
                    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (empty($history)):
                    ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted">Brak historii transakcji</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($history as $h): 
                            $action_colors = [
                                'added' => 'background: #dcfce7; color: #166534;',
                                'issued' => 'background: #dbeafe; color: #1e40af;',
                                'returned' => 'background: #fef3c7; color: #92400e;',
                                'adjusted' => 'background: #f3f4f6; color: #374151;'
                            ];
                            $action_color = $action_colors[$h['action']] ?? 'background: #f3f4f6; color: #374151;';
                            
                            // Wyświetl imię i nazwisko, jeśli dostępne, w przeciwnym razie nazwę użytkownika
                            $user_display = !empty($h['full_name']) ? $h['full_name'] : ($h['username'] ?? '—');
                        ?>
                        <tr>
                            <td>
                                <span class="text-muted" style="font-size: 12px;">
                                    <?php echo date('d.m.Y H:i', strtotime($h['created_at'])); ?>
                                </span>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($h['material_code'] ?? 'Usunięty'); ?></strong>
                            </td>
                            <td>
                                <span style="display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; <?php echo $action_color; ?>">
                                    <?php echo $h['action_pl']; ?>
                                </span>
                            </td>
                            <td style="<?php echo $h['quantity_change'] > 0 ? 'color: var(--success); font-weight: 600;' : 'color: var(--danger); font-weight: 600;'; ?>">
                                <?php echo $h['quantity_change'] > 0 ? '+' : ''; ?><?php echo $h['quantity_change']; ?>
                            </td>
                            <td>
                                <span class="hall-badge" style="background: #f3f4f6; color: #374151;">
                                    <?php echo $h['quantity_after']; ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($h['printer_name'])): ?>
                                    <span class="text-muted" style="font-size: 12px;">
                                        <?php echo htmlspecialchars($h['printer_name']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="text-muted" style="font-size: 12px;">
                                    <?php echo htmlspecialchars($user_display); ?>
                                </span>
                            </td>
                            <td>
                                <span class="text-muted" style="font-size: 12px;">
                                    <?php echo htmlspecialchars($h['notes'] ?? '—'); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="fixed-horizontal-scrollbar" id="fixedHorizontalScrollbarHistory">
        <div class="scrollbar-track" id="scrollbarTrackHistory"></div>
    </div>
</div>

<?php if ($can_create || $can_edit): ?>
<!-- Modal dodawania materiału -->
<div class="modal" id="addMaterialModal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>Dodaj materiał</h3>
            <button class="modal-close" onclick="closeModal('addMaterialModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="post" id="addMaterialForm">
            <div class="modal-body">
                <div class="form-group">
                    <label for="type">Typ materiału *</label>
                    <select class="form-control" name="type" id="materialType" required>
                        <?php foreach ($material_types as $key => $label): ?>
                        <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="material_code">Kod materiału *</label>
                    <input type="text" class="form-control" name="material_code" id="materialCode" required>
                </div>
                
                <div class="form-group">
                    <label for="printer_id">Drukarka</label>
                    <select class="form-control" name="printer_id" id="printerSelect" onchange="fillPrinterDetails()">
                        <option value="">— Wybierz —</option>
                        <?php foreach ($all_printers as $printer): ?>
                        <option value="<?php echo $printer['id']; ?>"
                                data-name="<?php echo htmlspecialchars($printer['name']); ?>"
                                data-model="<?php echo htmlspecialchars($printer['model']); ?>"
                                data-host-number="<?php echo htmlspecialchars($printer['host_number']); ?>">
                            <?php echo htmlspecialchars($printer['name']); ?> 
                            <?php if (!empty($printer['model'])): ?>
                                (<?php echo htmlspecialchars($printer['model']); ?>)
                            <?php endif; ?>
                            <?php if (!empty($printer['host_number'])): ?>
                                - Nr Hees: <?php echo htmlspecialchars($printer['host_number']); ?>
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Pola ukryte dla danych drukarki -->
                <input type="hidden" name="printer_name" id="printerName">
                <input type="hidden" name="printer_model" id="printerModel">
                <input type="hidden" name="printer_host_number" id="printerHostNumber">
                
                <div class="form-group">
                    <label>Informacje o drukarce:</label>
                    <div class="printer-info-box" id="printerInfoBox" style="padding: 12px; background: #f8fafc; border-radius: 6px; border: 1px solid #e5e7eb; margin-top: 8px;">
                        <div class="printer-info-item" style="margin-bottom: 4px;">
                            <strong>Wybrana drukarka:</strong> <span id="selectedPrinterName">—</span>
                        </div>
                        <div class="printer-info-item" style="margin-bottom: 4px;">
                            <strong>Model:</strong> <span id="selectedPrinterModel">—</span>
                        </div>
                        <div class="printer-info-item">
                            <strong>Nr Hees:</strong> <span id="selectedPrinterHostNumber">—</span>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="color">Kolor (jeśli dotyczy)</label>
                    <select class="form-control" name="color" id="color">
                        <option value="">— Brak —</option>
                        <option value="black">Czarny</option>
                        <option value="cyan">Niebieski</option>
                        <option value="magenta">Różowy</option>
                        <option value="yellow">Żółty</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="quantity">Ilość *</label>
                    <input type="number" class="form-control" name="quantity" id="quantity" min="1" required>
                </div>
                
                <div class="form-group">
                    <label for="location">Lokalizacja</label>
                    <input type="text" class="form-control" name="location" id="location" placeholder="np. Magazyn A, Półka 3">
                </div>
                
                <div class="form-group">
                    <label for="notes">Notatki</label>
                    <textarea class="form-control" name="notes" id="notes" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addMaterialModal')">Anuluj</button>
                <button type="submit" name="add_material" class="btn btn-primary">
                    <i class="fas fa-save"></i>
                    Dodaj
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal wydawania materiału -->
<div class="modal" id="issueMaterialModal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3>Wydaj materiał</h3>
            <button class="modal-close" onclick="closeModal('issueMaterialModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="material_id" id="issueMaterialId">
                
                <div class="form-group">
                    <label for="issue_quantity">Ilość do wydania *</label>
                    <input type="number" class="form-control" name="issue_quantity" id="issueQuantity" min="1" required>
                    <small class="text-muted">Dostępne: <span id="availableQuantity" style="font-weight: 600;">0</span></small>
                </div>
                
                <div class="form-group">
                    <label for="printer_id">Drukarka (opcjonalnie)</label>
                    <select class="form-control" name="printer_id" id="issuePrinterSelect">
                        <option value="">— Wybierz —</option>
                        <?php foreach ($all_printers as $printer): ?>
                        <option value="<?php echo $printer['id']; ?>">
                            <?php echo htmlspecialchars($printer['name']); ?> 
                            <?php if (!empty($printer['model'])): ?>
                                (<?php echo htmlspecialchars($printer['model']); ?>)
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="issued_to">Wydano dla (opcjonalnie)</label>
                    <input type="text" class="form-control" name="issued_to" id="issuedTo" placeholder="Imię i nazwisko">
                </div>
                
                <div class="form-group">
                    <label for="issue_notes">Notatki (opcjonalnie)</label>
                    <textarea class="form-control" name="issue_notes" id="issueNotes" rows="2" placeholder="Cel wydania, uwagi..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('issueMaterialModal')">Anuluj</button>
                <button type="submit" name="issue_material" class="btn btn-primary">
                    <i class="fas fa-arrow-right"></i>
                    Wydaj
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal edycji materiału -->
<div class="modal" id="editMaterialModal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3>Edytuj materiał</h3>
            <button class="modal-close" onclick="closeModal('editMaterialModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="post" id="editMaterialForm">
            <div class="modal-body">
                <input type="hidden" name="material_id" id="editMaterialId">
                
                <div class="form-group">
                    <label for="edit_quantity">Ilość *</label>
                    <input type="number" class="form-control" name="quantity" id="editQuantity" min="0" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_location">Lokalizacja</label>
                    <input type="text" class="form-control" name="location" id="editLocation">
                </div>
                
                <div class="form-group">
                    <label for="edit_notes">Notatki</label>
                    <textarea class="form-control" name="notes" id="editNotes" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editMaterialModal')">Anuluj</button>
                <button type="submit" name="update_material" class="btn btn-primary">
                    <i class="fas fa-save"></i>
                    Zapisz
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
const materialsData = <?php echo json_encode($materials); ?>;
const printersData = <?php echo json_encode($all_printers); ?>;

// Synchronizacja przewijania dla tabeli materiałów
document.addEventListener('DOMContentLoaded', function() {
    const horizontalContainer = document.querySelector('.table-horizontal-container');
    const fixedScrollbar = document.getElementById('fixedHorizontalScrollbar');
    const scrollbarTrack = document.getElementById('scrollbarTrack');
    const table = document.querySelector('.printer-table');
    
    if (table && scrollbarTrack) {
        const tableWidth = table.offsetWidth;
        scrollbarTrack.style.width = tableWidth + 'px';
        
        horizontalContainer.addEventListener('scroll', function() {
            fixedScrollbar.scrollLeft = horizontalContainer.scrollLeft;
        });
        
        fixedScrollbar.addEventListener('scroll', function() {
            horizontalContainer.scrollLeft = fixedScrollbar.scrollLeft;
        });
        
        window.addEventListener('resize', function() {
            const newTableWidth = table.offsetWidth;
            scrollbarTrack.style.width = newTableWidth + 'px';
        });
    }
    
    // Synchronizacja przewijania dla tabeli historii
    const historyContainer = document.querySelector('#historyTableContainer .table-horizontal-container');
    const fixedScrollbarHistory = document.getElementById('fixedHorizontalScrollbarHistory');
    const scrollbarTrackHistory = document.getElementById('scrollbarTrackHistory');
    const historyTable = document.querySelector('#historyTableContainer .printer-table');
    
    if (historyTable && scrollbarTrackHistory) {
        const historyTableWidth = historyTable.offsetWidth;
        scrollbarTrackHistory.style.width = historyTableWidth + 'px';
        
        if (historyContainer) {
            historyContainer.addEventListener('scroll', function() {
                fixedScrollbarHistory.scrollLeft = historyContainer.scrollLeft;
            });
            
            fixedScrollbarHistory.addEventListener('scroll', function() {
                historyContainer.scrollLeft = fixedScrollbarHistory.scrollLeft;
            });
            
            window.addEventListener('resize', function() {
                const newHistoryTableWidth = historyTable.offsetWidth;
                scrollbarTrackHistory.style.width = newHistoryTableWidth + 'px';
            });
        }
    }
});

function openAddMaterialModal() {
    document.getElementById('addMaterialModal').classList.add('active');
    // Zresetuj formularz
    document.getElementById('addMaterialForm').reset();
    document.getElementById('printerInfoBox').style.display = 'none';
}

function fillPrinterDetails() {
    const select = document.getElementById('printerSelect');
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption.value) {
        const printerName = selectedOption.getAttribute('data-name');
        const printerModel = selectedOption.getAttribute('data-model');
        const printerHostNumber = selectedOption.getAttribute('data-host-number');
        
        // Ustaw wartości w ukrytych polach
        document.getElementById('printerName').value = printerName || '';
        document.getElementById('printerModel').value = printerModel || '';
        document.getElementById('printerHostNumber').value = printerHostNumber || '';
        
        // Pokaż informacje w boxie
        document.getElementById('selectedPrinterName').textContent = printerName || '—';
        document.getElementById('selectedPrinterModel').textContent = printerModel || '—';
        document.getElementById('selectedPrinterHostNumber').textContent = printerHostNumber || '—';
        
        document.getElementById('printerInfoBox').style.display = 'block';
    } else {
        // Wyczyść pola jeśli nie wybrano drukarki
        document.getElementById('printerName').value = '';
        document.getElementById('printerModel').value = '';
        document.getElementById('printerHostNumber').value = '';
        
        document.getElementById('selectedPrinterName').textContent = '—';
        document.getElementById('selectedPrinterModel').textContent = '—';
        document.getElementById('selectedPrinterHostNumber').textContent = '—';
        
        document.getElementById('printerInfoBox').style.display = 'block';
    }
}

function issueMaterial(id, availableQty) {
    const material = materialsData.find(m => m.id === id);
    if (!material) return;
    
    if (availableQty <= 0) {
        alert('Brak dostępnej ilości tego materiału do wydania.');
        return;
    }
    
    document.getElementById('issueMaterialId').value = id;
    document.getElementById('availableQuantity').textContent = availableQty;
    document.getElementById('issueQuantity').max = availableQty;
    document.getElementById('issueQuantity').value = 1;
    document.getElementById('issueMaterialModal').classList.add('active');
}

function editMaterial(id) {
    const material = materialsData.find(m => m.id === id);
    if (!material) return;
    
    document.getElementById('editMaterialId').value = id;
    document.getElementById('editQuantity').value = material.quantity;
    document.getElementById('editLocation').value = material.location || '';
    document.getElementById('editNotes').value = material.notes || '';
    document.getElementById('editMaterialModal').classList.add('active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

function confirmDeleteMaterial(id, code) {
    if (confirm('Czy na pewno chcesz usunąć materiał "' + code + '"?')) {
        window.location.href = '?page=materials&action=delete_material&id=' + id;
    }
}

// Search
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const rows = document.querySelectorAll('#materialsTableBody tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchTerm) ? '' : 'none';
        });
    });
}

// Close modals on outside click
window.addEventListener('click', function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
    }
});

// Automatycznie wypełnij model po wybraniu drukarki
document.addEventListener('DOMContentLoaded', function() {
    const printerSelect = document.getElementById('printerSelect');
    if (printerSelect) {
        printerSelect.addEventListener('change', fillPrinterDetails);
    }
});
</script>
<?php ob_end_flush(); ?>