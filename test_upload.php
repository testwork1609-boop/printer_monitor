<?php
// test_upload.php - Test uploadu plików
session_start();
require_once 'config.php';

// Ustawienia dla testu
$upload_dir = __DIR__ . '/uploads/maps/';
$test_file = __DIR__ . '/test_image.jpg'; // Zmień na istniejący plik

// Test 1: Czy katalog istnieje i ma uprawnienia
echo "<h2>Test 1: Katalog</h2>";
if (!file_exists($upload_dir)) {
    echo "Katalog nie istnieje. Tworzę...<br>";
    mkdir($upload_dir, 0755, true);
}

if (is_writable($upload_dir)) {
    echo "✓ Katalog jest zapisywalny<br>";
} else {
    echo "✗ Katalog NIE jest zapisywalny<br>";
    echo "Uprawnienia: " . substr(sprintf('%o', fileperms($upload_dir)), -4) . "<br>";
}

// Test 2: Sprawdź ustawienia PHP
echo "<h2>Test 2: Ustawienia PHP</h2>";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "<br>";
echo "post_max_size: " . ini_get('post_max_size') . "<br>";
echo "max_file_uploads: " . ini_get('max_file_uploads') . "<br>";
echo "file_uploads: " . ini_get('file_uploads') . "<br>";

// Test 3: Prosty formularz uploadu
echo "<h2>Test 3: Formularz uploadu</h2>";
?>
<form method="post" enctype="multipart/form-data">
    <input type="file" name="test_file">
    <button type="submit" name="test_upload">Prześlij</button>
</form>

<?php
if (isset($_POST['test_upload'])) {
    echo "<h3>Wynik uploadu:</h3>";
    echo "<pre>";
    print_r($_FILES);
    echo "</pre>";
    
    if ($_FILES['test_file']['error'] === 0) {
        $target = $upload_dir . 'test_' . time() . '.jpg';
        if (move_uploaded_file($_FILES['test_file']['tmp_name'], $target)) {
            echo "✓ Plik zapisany: " . $target . "<br>";
        } else {
            echo "✗ Błąd zapisu pliku<br>";
        }
    }
}
?>