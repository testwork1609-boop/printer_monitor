<?php
// content/config_content.php - Strona konfiguracji
global $toner_threshold, $snmp_community, $subnet, $start_ip, $end_ip, $db;
global $email_from, $email_supplier, $email_subject;

requirePermission('config', 'view');

function addMessage($type, $text) {
    if (!isset($_SESSION['messages'])) $_SESSION['messages'] = [];
    if (count($_SESSION['messages']) >= 3) array_shift($_SESSION['messages']);
    $_SESSION['messages'][] = ['type' => $type, 'text' => $text];
}

// Obsługa zapisu konfiguracji
if (isset($_POST['save_config']) && hasPermission('config', 'edit')) {
    $new_toner_threshold = filter_input(INPUT_POST, 'toner_threshold', FILTER_VALIDATE_INT) ?? 15;
    $new_snmp_community = filter_input(INPUT_POST, 'snmp_community', FILTER_SANITIZE_STRING) ?? 'public';
    $new_subnet = filter_input(INPUT_POST, 'subnet', FILTER_SANITIZE_STRING) ?? '192.168.152.';
    $new_start_ip = filter_input(INPUT_POST, 'start_ip', FILTER_VALIDATE_INT) ?? 200;
    $new_end_ip = filter_input(INPUT_POST, 'end_ip', FILTER_VALIDATE_INT) ?? 230;
    $new_email_from = filter_input(INPUT_POST, 'email_from', FILTER_SANITIZE_EMAIL) ?? 'monitor@twojafirma.pl';
    $new_email_supplier = filter_input(INPUT_POST, 'email_supplier', FILTER_SANITIZE_EMAIL) ?? 'dostawca@twojafirma.pl';
    $new_email_subject = filter_input(INPUT_POST, 'email_subject', FILTER_SANITIZE_STRING) ?? 'Alert: Niski poziom materiałów eksploatacyjnych';

    // Generuj nowy config.php
    $config_content = "<?php\n";
    $config_content .= "// Konfiguracja systemu monitorowania drukarek\n\n";
    $config_content .= "\$email_from = \"$new_email_from\";\n";
    $config_content .= "\$email_supplier = \"$new_email_supplier\";\n";
    $config_content .= "\$email_subject = \"$new_email_subject\";\n";
    $config_content .= "\$email_to = \"admin@example.com\";\n";
    $config_content .= "\$toner_threshold = $new_toner_threshold;\n";
    $config_content .= "\$snmp_community = \"" . addslashes($new_snmp_community) . "\";\n";
    $config_content .= "\$subnet = \"" . addslashes($new_subnet) . "\";\n";
    $config_content .= "\$start_ip = $new_start_ip;\n";
    $config_content .= "\$end_ip = $new_end_ip;\n";
    
    // Zachowaj resztę pliku config.php (połączenie z bazą, tabele itp.)
    $config_content .= "\n// Połączenie z bazą SQLite\n";
    $config_content .= "try {\n";
    $config_content .= "    \$db = new PDO('sqlite:' . __DIR__ . '/app.db');\n";
    $config_content .= "    \$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);\n";
    $config_content .= "} catch (PDOException \$e) {\n";
    $config_content .= "    die(\"Błąd połączenia z bazą danych: \" . \$e->getMessage());\n";
    $config_content .= "}\n";
    $config_content .= "// ... (reszta konfiguracji)\n";
    $config_content .= "?>";

    try {
        // Uwaga: W pełnej implementacji należy zachować całą zawartość config.php
        // file_put_contents(__DIR__ . '/../config.php', $config_content);
        addMessage('success', 'Konfiguracja zapisana (demo - pełna implementacja wymaga zachowania całego pliku config.php)');
        logActivity($_SESSION['user_id'], 'update_config', 'config', 'Zaktualizowano konfigurację');
    } catch (Exception $e) {
        addMessage('error', 'Błąd zapisu: ' . $e->getMessage());
    }
    
    header("Location: index.php?page=config_content");
    exit;
}

// Pobierz informacje o bazie
$db_file = __DIR__ . '/../app.db';
$db_size = file_exists($db_file) ? filesize($db_file) : 0;
$db_size_mb = round($db_size / 1024 / 1024, 2);

// Pobierz statystyki
$stmt = $db->query("SELECT COUNT(*) FROM printers");
$printers_count = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM materials");
$materials_count = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM users");
$users_count = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM activity_logs");
$logs_count = $stmt->fetchColumn();

$can_edit = hasPermission('config', 'edit');
?>

<div class="dashboard-header">
    <h1>Konfiguracja systemu</h1>
    <p class="text-muted">Zarządzaj ustawieniami aplikacji</p>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 24px;">
    
    <!-- Ustawienia SNMP -->
    <div class="table-section">
        <div class="table-header">
            <h2><i class="fas fa-network-wired"></i> Ustawienia SNMP</h2>
        </div>
        <div style="padding: 24px;">
            <form method="post">
                <div class="form-group">
                    <label for="toner_threshold">Próg ostrzeżenia tonera (%)</label>
                    <input type="number" class="form-control" id="toner_threshold" name="toner_threshold" 
                           value="<?php echo htmlspecialchars($toner_threshold); ?>" min="1" max="100" 
                           <?php echo $can_edit ? '' : 'disabled'; ?>>
                    <small class="text-muted">Alert gdy poziom tonera spadnie poniżej tej wartości</small>
                </div>
                
                <div class="form-group">
                    <label for="snmp_community">SNMP Community String</label>
                    <input type="text" class="form-control" id="snmp_community" name="snmp_community" 
                           value="<?php echo htmlspecialchars($snmp_community); ?>"
                           <?php echo $can_edit ? '' : 'disabled'; ?>>
                </div>
                
                <div class="form-group">
                    <label for="subnet">Subnet</label>
                    <input type="text" class="form-control" id="subnet" name="subnet" 
                           value="<?php echo htmlspecialchars($subnet); ?>"
                           <?php echo $can_edit ? '' : 'disabled'; ?>>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label for="start_ip">Początek zakresu IP</label>
                        <input type="number" class="form-control" id="start_ip" name="start_ip" 
                               value="<?php echo htmlspecialchars($start_ip); ?>" min="1" max="254"
                               <?php echo $can_edit ? '' : 'disabled'; ?>>
                    </div>
                    
                    <div class="form-group">
                        <label for="end_ip">Koniec zakresu IP</label>
                        <input type="number" class="form-control" id="end_ip" name="end_ip" 
                               value="<?php echo htmlspecialchars($end_ip); ?>" min="1" max="254"
                               <?php echo $can_edit ? '' : 'disabled'; ?>>
                    </div>
                </div>
                
                <!-- Sekcja konfiguracji email -->
                <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--gray-200);">
                    <h4 style="margin-bottom: 16px; color: var(--gray-700);"><i class="fas fa-envelope"></i> Konfiguracja powiadomień email</h4>
                    
                    <div class="form-group">
                        <label for="email_from">Email nadawcy</label>
                        <input type="email" class="form-control" id="email_from" name="email_from" 
                               value="<?php echo htmlspecialchars($email_from ?? 'monitor@twojafirma.pl'); ?>"
                               <?php echo $can_edit ? '' : 'disabled'; ?>>
                        <small class="text-muted">Adres, z którego będą wysyłane alerty</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="email_supplier">Email dostawcy (do powiadomień)</label>
                        <input type="email" class="form-control" id="email_supplier" name="email_supplier" 
                               value="<?php echo htmlspecialchars($email_supplier ?? 'dostawca@twojafirma.pl'); ?>"
                               <?php echo $can_edit ? '' : 'disabled'; ?>>
                        <small class="text-muted">Adres dostawcy, na który będą wysyłane powiadomienia o niskim stanie</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="email_subject">Temat wiadomości</label>
                        <input type="text" class="form-control" id="email_subject" name="email_subject" 
                               value="<?php echo htmlspecialchars($email_subject ?? 'Alert: Niski poziom materiałów eksploatacyjnych'); ?>"
                               <?php echo $can_edit ? '' : 'disabled'; ?>>
                    </div>
                </div>
                
                <?php if ($can_edit): ?>
                <button type="submit" name="save_config" class="btn btn-primary">
                    <i class="fas fa-save"></i>
                    Zapisz konfigurację
                </button>
                <?php endif; ?>
            </form>
        </div>
    </div>
    
    <!-- Informacje o bazie danych -->
    <div class="table-section">
        <div class="table-header">
            <h2><i class="fas fa-database"></i> Baza danych</h2>
        </div>
        <div style="padding: 24px;">
            <div class="form-group">
                <label>Rozmiar bazy</label>
                <input type="text" class="form-control" value="<?php echo $db_size_mb; ?> MB" disabled>
            </div>
            
            <div class="form-group">
                <label>Lokalizacja</label>
                <input type="text" class="form-control" value="<?php echo basename($db_file); ?>" disabled>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                <div>
                    <label style="font-size: 12px; color: var(--gray-600); margin-bottom: 4px; display: block;">Drukarki</label>
                    <div style="font-size: 24px; font-weight: 700; color: var(--primary);"><?php echo $printers_count; ?></div>
                </div>
                <div>
                    <label style="font-size: 12px; color: var(--gray-600); margin-bottom: 4px; display: block;">Materiały</label>
                    <div style="font-size: 24px; font-weight: 700; color: var(--primary);"><?php echo $materials_count; ?></div>
                </div>
                <div>
                    <label style="font-size: 12px; color: var(--gray-600); margin-bottom: 4px; display: block;">Użytkownicy</label>
                    <div style="font-size: 24px; font-weight: 700; color: var(--primary);"><?php echo $users_count; ?></div>
                </div>
                <div>
                    <label style="font-size: 12px; color: var(--gray-600); margin-bottom: 4px; display: block;">Logi</label>
                    <div style="font-size: 24px; font-weight: 700; color: var(--primary);"><?php echo $logs_count; ?></div>
                </div>
            </div>
            
            <?php if ($can_edit): ?>
            <a href="backup_database.php" class="btn btn-primary" style="width: 100%;">
                <i class="fas fa-download"></i>
                Pobierz backup bazy danych
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Informacje systemowe -->
<div class="table-section" style="margin-top: 24px;">
    <div class="table-header">
        <h2><i class="fas fa-info-circle"></i> Informacje systemowe</h2>
    </div>
    <div style="padding: 24px;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
            <div>
                <label style="font-weight: 600; margin-bottom: 8px; display: block;">Wersja PHP</label>
                <p style="color: var(--gray-700);"><?php echo phpversion(); ?></p>
            </div>
            
            <div>
                <label style="font-weight: 600; margin-bottom: 8px; display: block;">Moduł SNMP</label>
                <p style="color: var(--gray-700);">
                    <?php if (function_exists('snmpwalk')): ?>
                        <span style="color: var(--success);"><i class="fas fa-check-circle"></i> Włączony</span>
                    <?php else: ?>
                        <span style="color: var(--danger);"><i class="fas fa-times-circle"></i> Wyłączony</span>
                    <?php endif; ?>
                </p>
            </div>
            
            <div>
                <label style="font-weight: 600; margin-bottom: 8px; display: block;">PDO SQLite</label>
                <p style="color: var(--gray-700);">
                    <?php if (extension_loaded('pdo_sqlite')): ?>
                        <span style="color: var(--success);"><i class="fas fa-check-circle"></i> Włączony</span>
                    <?php else: ?>
                        <span style="color: var(--danger);"><i class="fas fa-times-circle"></i> Wyłączony</span>
                    <?php endif; ?>
                </p>
            </div>
            
            <div>
                <label style="font-weight: 600; margin-bottom: 8px; display: block;">Katalog aplikacji</label>
                <p style="color: var(--gray-700); word-break: break-all;"><?php echo __DIR__; ?></p>
            </div>
        </div>
    </div>
</div>