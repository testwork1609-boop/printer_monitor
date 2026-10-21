<?php
/**
 * check_headers.php - Sprawdza pliki pod kątem output przed header()
 * 
 * UŻYCIE: http://localhost/printer_monitor/check_headers.php
 */

$files_to_check = [
    'includes/header.php',
    'includes/footer.php',
    'includes/sidebar.php',
    'content/devices.php',
    'content/users.php',
    'content/dashboard.php',
    'content/materials.php',
    'content/account.php',
    'index.php',
    'config.php',
    'auth.php'
];

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Sprawdzanie plików - Headers</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .file { background: white; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .ok { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; font-weight: bold; }
        pre { background: #f9f9f9; padding: 10px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>🔍 Sprawdzanie plików pod kątem output</h1>
    <p>Szuka białych znaków, BOM i innego output przed &lt;?php</p>
    
    <?php foreach ($files_to_check as $file): ?>
        <div class="file">
            <h3><?php echo $file; ?></h3>
            <?php
            $path = __DIR__ . '/' . $file;
            
            if (!file_exists($path)) {
                echo "<p class='warning'>⚠️ Plik nie istnieje</p>";
                continue;
            }
            
            $content = file_get_contents($path);
            $first_chars = substr($content, 0, 10);
            
            // Sprawdź BOM
            $bom = substr($content, 0, 3);
            if ($bom === "\xEF\xBB\xBF") {
                echo "<p class='error'>❌ BŁĄD: Plik zawiera BOM (Byte Order Mark)</p>";
                echo "<p>Rozwiązanie: Zapisz plik jako UTF-8 BEZ BOM</p>";
            }
            
            // Sprawdź białe znaki przed <?php
            if (!preg_match('/^\<\?php/', $content)) {
                echo "<p class='error'>❌ BŁĄD: Plik NIE zaczyna się od &lt;?php</p>";
                echo "<p>Pierwsze 10 znaków (hex): ";
                for ($i = 0; $i < min(10, strlen($content)); $i++) {
                    echo dechex(ord($content[$i])) . " ";
                }
                echo "</p>";
                echo "<pre>Pierwsze znaki: " . htmlspecialchars($first_chars) . "</pre>";
            } else {
                echo "<p class='ok'>✓ OK: Plik zaczyna się poprawnie od &lt;?php</p>";
            }
            
            // Sprawdź czy jest jakiś output po ?>
            if (preg_match('/\?>\s+\S/', $content)) {
                echo "<p class='warning'>⚠️ UWAGA: Po ?> jest jakiś tekst (może powodować problemy)</p>";
            }
            
            // Policz ile razy występuje header(
            $header_count = substr_count($content, 'header(');
            if ($header_count > 0) {
                echo "<p>ℹ️ Plik zawiera $header_count wywołań header()</p>";
                
                // Sprawdź czy po header( jest exit
                preg_match_all('/header\([^)]+\);.*?$/m', $content, $matches);
                foreach ($matches[0] as $match) {
                    if (!preg_match('/exit|die/', $match)) {
                        echo "<p class='warning'>⚠️ header() bez exit; w linii: " . htmlspecialchars(substr($match, 0, 80)) . "</p>";
                    }
                }
            }
            ?>
        </div>
    <?php endforeach; ?>
    
    <div style="margin-top: 30px; padding: 20px; background: #e3f2fd; border-radius: 5px;">
        <h3>💡 Jak naprawić problemy:</h3>
        <ol>
            <li><strong>BOM w pliku:</strong> Otwórz w Notepad++, wybierz Encoding → UTF-8 (bez BOM), zapisz</li>
            <li><strong>Białe znaki przed &lt;?php:</strong> Usuń WSZYSTKIE znaki (spacje, enters) przed &lt;?php</li>
            <li><strong>Tekst po ?>:</strong> Usuń ?> na końcu pliku (niepotrzebne w PHP)</li>
            <li><strong>header() bez exit:</strong> Dodaj exit; zaraz po każdym header()</li>
        </ol>
    </div>
    
    <div style="margin-top: 20px; text-align: center;">
        <a href="index.php" style="padding: 10px 20px; background: #2563eb; color: white; text-decoration: none; border-radius: 5px;">
            ← Powrót do systemu
        </a>
    </div>
</body>
</html>