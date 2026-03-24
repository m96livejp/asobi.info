<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
asobiRequireAdminApi();

$ip = trim($_GET['ip'] ?? '');
if ($ip === '') {
    http_response_code(400);
    echo json_encode(['error' => 'ip required']);
    exit;
}

$db = asobiUsersDb();

$logins = $db->prepare("
    SELECT l.created_at, l.username, l.browser, l.device, l.os
    FROM login_logs l
    WHERE l.ip = ?
    ORDER BY l.id DESC
    LIMIT 30
");
$logins->execute([$ip]);

$accesses = $db->prepare("
    SELECT a.created_at, a.host, a.path, a.browser, a.device, a.os, a.referer
    FROM access_logs a
    WHERE a.ip = ?
    ORDER BY a.id DESC
    LIMIT 50
");
$accesses->execute([$ip]);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ip'       => $ip,
    'logins'   => $logins->fetchAll(),
    'accesses' => $accesses->fetchAll(),
]);
