<?php
/**
 * check_requirements.php - Sprawdzanie wymagań systemowych
 * 
 * Uruchom ten plik przed instalacją aby sprawdzić czy Twój serwer
 * spełnia wszystkie wymagania techniczne
 * 
 * UŻYCIE: http://localhost/printer_monitor/check_requirements.php
 */

$requirements = [
    'php_version' => ['required' => '5.6.0', 'recommended' => '7.4.0'],
    'extensions' => [
        'pdo' => ['required' => true, 'description' => 'PDO (PHP Data Objects)'],
        'pdo_sqlite' => ['required' => true, 'description' => 'PDO SQLite Driver'],
        'snmp' => ['required' => false, 'description' => 'SNMP (komunikacja z drukarkami)'],
        'mbstring' => ['required' => false, 'description' => 'Multibyte String'],
        'json' => ['required' => true, 'description' => 'JSON'],
        'session' => ['required' => true, 'description' => 'Session'],
    ],
    'functions' => [
        'password_hash' => ['required' => true, 'description' => 'Password hashing (PHP 5.5+)'],
        'random_bytes' => ['required' => false, 'description' => 'Secure random generator (PHP 7.0+)'],
    ]
];

function checkPhpVersion() {
    global $requirements;
    $current = phpversion();
    $required = $requirements['php_version']['required'];
    $recommended = $requirements['php_version']['recommended'];
    
    return [
        'status' => version_compare($current, $required, '>='),
        'recommended' => version_compare($current, $recommended, '>='),
        'current' => $current,
        'required' => $required,
        'recommended_ver' => $recommended
    ];
}

function checkExtensions() {
    global $requirements;
    $results = [];
    
    foreach ($requirements['extensions'] as $ext => $info) {
        $loaded = extension_loaded($ext);
        $results[$ext] = [
            'status' => $loaded || !$info['required'],
            'loaded' => $loaded,
            'required' => $info['required'],
            'description' => $info['description']
        ];
    }
    
    return $results;
}

function checkFunctions() {
    global $requirements;
    $results = [];
    
    foreach ($requirements['functions'] as $func => $info) {
        $exists = function_exists($func);
        $results[$func] = [
            'status' => $exists || !$info['required'],
            'exists' => $exists,
            'required' => $info['required'],
            'description' => $info['description']
        ];
    }
    
    return $results;
}

function checkPermissions() {
    $dir = __DIR__;
    $writable = is_writable($dir);
    
    return [
        'directory' => $dir,
        'writable' => $writable
    ];
}

// Wykonaj sprawdzenia
$php = checkPhpVersion();
$extensions = checkExtensions();
$functions = checkFunctions();
$permissions = checkPermissions();

// Oblicz podsumowanie
$total_checks = 1; // PHP version
$passed_checks = $php['status'] ? 1 : 0;

foreach ($extensions as $ext) {
    $total_checks++;
    if ($ext['status']) $passed_checks++;
}

foreach ($functions as $func) {
    $total_checks++;
    if ($func['status']) $passed_checks++;
}

$total_checks++; // Permissions
if ($permissions['writable']) $passed_checks++;

$all_passed = $passed_checks === $total_checks;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sprawdzanie Wymagań Systemowych</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            text-align: center;
        }
        .header h1 {
            font-size: 32px;
            margin-bottom: 10px;
        }
        .header p {
            opacity: 0.9;
            font-size: 16px;
        }
        .summary {
            padding: 30px 40px;
            border-bottom: 2px solid #e5e7eb;
        }
        .summary-box {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 20px;
            border-radius: 12px;
        }
        .summary-box.success { background: #f0fdf4; border: 2px solid #22c55e; }
        .summary-box.warning { background: #fef3c7; border: 2px solid #f59e0b; }
        .summary-box.error { background: #fef2f2; border: 2px solid #ef4444; }
        .summary-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            flex-shrink: 0;
        }
        .success .summary-icon { background: #22c55e; }
        .warning .summary-icon { background: #f59e0b; }
        .error .summary-icon { background: #ef4444; }
        .summary-content h2 { margin-bottom: 5px; color: #111827; }
        .summary-content p { color: #6b7280; }
        .content {
            padding: 40px;
        }
        .section {
            margin-bottom: 30px;
        }
        .section h3 {
            color: #111827;
            margin-bottom: 15px;
            font-size: 20px;
        }
        .check-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        .check-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }
        .check-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }
        .check-icon.pass { background: #dcfce7; color: #166534; }
        .check-icon.fail { background: #fee2e2; color: #991b1b; }
        .check-icon.optional { background: #dbeafe; color: #1e40af; }
        .check-details {
            flex: 1;
        }
        .check-name {
            font-weight: 600;
            color: #111827;
            margin-bottom: 2px;
        }
        .check-desc {
            font-size: 13px;
            color: #6b7280;
        }
        .check-value {
            font-family: monospace;
            color: #6b7280;
            font-size: 14px;
        }
        .badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge.required { background: #fee2e2; color: #991b1b; }
        .badge.optional { background: #dbeafe; color: #1e40af; }
        .badge.recommended { background: #fef3c7; color: #92400e; }
        .footer {
            padding: 30px 40px;
            background: #f9fafb;
            text-align: center;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: transform 0.2s;
        }
        .btn:hover { transform: translateY(-2px); }
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 Sprawdzanie Wymagań</h1>
            <p>System Monitorowania Drukarek v2.0</p>
        </div>
        
        <div class="summary">
            <div class="summary-box <?php echo $all_passed ? 'success' : ($passed_checks > $total_checks * 0.7 ? 'warning' : 'error'); ?>">
                <div class="summary-icon">
                    <?php if ($all_passed): ?>
                        ✓
                    <?php elseif ($passed_checks > $total_checks * 0.7): ?>
                        ⚠
                    <?php else: ?>
                        ✗
                    <?php endif; ?>
                </div>
                <div class="summary-content">
                    <h2>
                        <?php if ($all_passed): ?>
                            Wszystkie wymagania spełnione!
                        <?php elseif ($passed_checks > $total_checks * 0.7): ?>
                            Większość wymagań spełniona
                        <?php else: ?>
                            Wymagania nie spełnione
                        <?php endif; ?>
                    </h2>
                    <p>Zaliczono <?php echo $passed_checks; ?> z <?php echo $total_checks; ?> sprawdzeń</p>
                </div>
            </div>
        </div>
        
        <div class="content">
            <!-- PHP Version -->
            <div class="section">
                <h3>Wersja PHP</h3>
                <div class="check-item">
                    <div class="check-info">
                        <div class="check-icon <?php echo $php['status'] ? 'pass' : 'fail'; ?>">
                            <?php echo $php['status'] ? '✓' : '✗'; ?>
                        </div>
                        <div class="check-details">
                            <div class="check-name">PHP <?php echo $php['current']; ?></div>
                            <div class="check-desc">
                                Wymagane: <?php echo $php['required']; ?> | 
                                Zalecane: <?php echo $php['recommended_ver']; ?>
                            </div>
                        </div>
                    </div>
                    <span class="badge <?php echo $php['recommended'] ? 'optional' : 'recommended'; ?>">
                        <?php echo $php['recommended'] ? 'Zalecana' : 'OK'; ?>
                    </span>
                </div>
            </div>
            
            <!-- Extensions -->
            <div class="section">
                <h3>Rozszerzenia PHP</h3>
                <?php foreach ($extensions as $name => $ext): ?>
                <div class="check-item">
                    <div class="check-info">
                        <div class="check-icon <?php echo $ext['loaded'] ? 'pass' : ($ext['required'] ? 'fail' : 'optional'); ?>">
                            <?php echo $ext['loaded'] ? '✓' : '✗'; ?>
                        </div>
                        <div class="check-details">
                            <div class="check-name"><?php echo $name; ?></div>
                            <div class="check-desc"><?php echo $ext['description']; ?></div>
                        </div>
                    </div>
                    <span class="badge <?php echo $ext['required'] ? 'required' : 'optional'; ?>">
                        <?php echo $ext['required'] ? 'Wymagane' : 'Opcjonalne'; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Functions -->
            <div class="section">
                <h3>Funkcje PHP</h3>
                <?php foreach ($functions as $name => $func): ?>
                <div class="check-item">
                    <div class="check-info">
                        <div class="check-icon <?php echo $func['exists'] ? 'pass' : ($func['required'] ? 'fail' : 'optional'); ?>">
                            <?php echo $func['exists'] ? '✓' : '✗'; ?>
                        </div>
                        <div class="check-details">
                            <div class="check-name"><?php echo $name; ?>()</div>
                            <div class="check-desc"><?php echo $func['description']; ?></div>
                        </div>
                    </div>
                    <span class="badge <?php echo $func['required'] ? 'required' : 'optional'; ?>">
                        <?php echo $func['required'] ? 'Wymagane' : 'Opcjonalne'; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Permissions -->
            <div class="section">
                <h3>Uprawnienia</h3>
                <div class="check-item">
                    <div class="check-info">
                        <div class="check-icon <?php echo $permissions['writable'] ? 'pass' : 'fail'; ?>">
                            <?php echo $permissions['writable'] ? '✓' : '✗'; ?>
                        </div>
                        <div class="check-details">
                            <div class="check-name">Uprawnienia do zapisu</div>
                            <div class="check-desc"><?php echo $permissions['directory']; ?></div>
                        </div>
                    </div>
                    <span class="badge required">Wymagane</span>
                </div>
            </div>
        </div>
        
        <div class="footer">
            <?php if ($all_passed): ?>
                <a href="index.php" class="btn">Przejdź do instalacji →</a>
                <p style="margin-top: 15px; color: #6b7280; font-size: 14px;">
                    Wszystko gotowe! Możesz rozpocząć instalację.
                </p>
            <?php else: ?>
                <p style="margin-bottom: 15px; color: #991b1b; font-weight: 600;">
                    Napraw wymagania przed instalacją
                </p>
                <button class="btn" disabled>Instalacja niedostępna</button>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>