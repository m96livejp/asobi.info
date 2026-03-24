<?php
/**
 * アクセスログ記録API（サブドメインからのCORSリクエスト用）
 */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://dbd.asobi.info', 'https://pkq.asobi.info', 'https://tbt.asobi.info', 'https://asobi.info'];
if (in_array($origin, $allowed)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$host = trim($body['host'] ?? '');
$path = trim($body['path'] ?? '/');

// ホスト検証
if (!preg_match('/^([a-z0-9\-]+\.)?asobi\.info$/i', $host)) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

// パス正規化
$path = '/' . ltrim(strtok($path, '?'), '/');

try {
    require_once __DIR__ . '/auth.php';
    $userId = asobiIsLoggedIn() ? $_SESSION['asobi_user_id'] : null;
    session_write_close(); // セッションロックを早期解放
    $db  = asobiUsersDb();
    $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ref = asobiRefererDomain($_SERVER['HTTP_REFERER'] ?? '');
    $parsed = asobiParseUA($ua);
    $db->prepare("INSERT INTO access_logs (host, path, user_id, ip, referer, user_agent, browser, device, os) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
       ->execute([
           $host,
           $path,
           $userId,
           $_SERVER['REMOTE_ADDR'] ?? '',
           $ref,
           $ua,
           $parsed['browser'],
           $parsed['device'],
           $parsed['os'],
       ]);
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    echo json_encode(['ok' => false]);
}
