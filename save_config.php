<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Walidacja inputów
    $toner_threshold = filter_input(INPUT_POST, 'toner_threshold', FILTER_VALIDATE_INT) ?? 15;
    $snmp_community = filter_input(INPUT_POST, 'snmp_community', FILTER_SANITIZE_STRING) ?? 'public';
    $subnet = filter_input(INPUT_POST, 'subnet', FILTER_SANITIZE_STRING) ?? '192.168.152.';
    $start_ip = filter_input(INPUT_POST, 'start_ip', FILTER_VALIDATE_INT) ?? 200;
    $end_ip = filter_input(INPUT_POST, 'end_ip', FILTER_VALIDATE_INT) ?? 230;

    // Generuj nowy config.php
    $config_content = "<?php\n";
    $config_content .= "// Konfiguracja monitora drukarek\n\n";
    $config_content .= "\$email_to = \"admin@example.com\";\n";
    $config_content .= "\$email_subject = \"Uwaga: niski poziom tonera\";\n";
    $config_content .= "\$toner_threshold = $toner_threshold;\n";
    $config_content .= "\$snmp_community = \"" . addslashes($snmp_community) . "\";\n";
    $config_content .= "\$subnet = \"" . addslashes($subnet) . "\";\n";
    $config_content .= "\$start_ip = $start_ip;\n";
    $config_content .= "\$end_ip = $end_ip;\n";
    $config_content .= "\n?>";

    try {
        file_put_contents('config.php', $config_content);
        $_SESSION['messages'][] = ['type' => 'success', 'text' => 'Konfiguracja zapisana'];
    } catch (Exception $e) {
        $_SESSION['messages'][] = ['type' => 'error', 'text' => 'Błąd zapisu: ' . $e->getMessage()];
    }
}

header("Location: index.php?page=config_content");
exit;
?>