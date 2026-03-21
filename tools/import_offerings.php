<?php
require_once __DIR__ . '/../public_html/dbd/api/db.php';
$db = getDb();

$sql = file_get_contents(__DIR__ . '/../setup/dbd_offerings_data.sql');

// Remove comments
$lines = explode("\n", $sql);
$cleanLines = [];
foreach ($lines as $line) {
    $trimmed = trim($line);
    if (strpos($trimmed, '--') === 0) continue;
    $cleanLines[] = $line;
}
$sql = implode("\n", $cleanLines);

// Split by semicolons
$statements = array_filter(array_map('trim', explode(';', $sql)));
$count = 0;
foreach ($statements as $stmt) {
    if (empty($stmt)) continue;
    try {
        $db->exec($stmt);
        $count++;
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        echo "SQL: " . substr($stmt, 0, 80) . "...\n";
    }
}
echo "Executed $count statements\n";

$result = $db->query('SELECT COUNT(*) as cnt FROM offerings');
echo "Offerings in DB: " . $result->fetch()['cnt'] . "\n";
