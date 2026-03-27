<?php
/**
 * アクセスログ記録API（サブドメインからのCORSリクエスト用）
 */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://dbd.asobi.info', 'https://pkq.asobi.info', 'https://tbt.asobi.info', 'https://aic.asobi.info', 'https://asobi.info'];
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
    session_write_close();
    $ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ref    = asobiRefererDomain($_SERVER['HTTP_REFERER'] ?? '');
    $parsed = asobiParseUA($ua);
    $line   = json_encode([
        'host'       => $host,
        'path'       => $path,
        'user_id'    => $userId,
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
        'referer'    => $ref,
        'user_agent' => $ua,
        'browser'    => $parsed['browser'],
        'device'     => $parsed['device'],
        'os'         => $parsed['os'],
        'created_at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE) . "\n";
    $logFile = dirname(ASOBI_USERS_DB_PATH) . '/access_log_buffer.jsonl';
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    echo json_encode(['ok' => false]);
}
