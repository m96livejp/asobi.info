<?php
/**
 * サイト別プロフィール API
 * GET  → 現在のサイト用表示名を取得
 * POST → サイト用表示名を保存
 */

// CORS
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://dbd.asobi.info', 'https://pkq.asobi.info', 'https://tbt.asobi.info', 'https://aic.asobi.info', 'https://asobi.info'];
if (in_array($origin, $allowed)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/users_db.php';

$userId = $_SESSION['asobi_user_id'] ?? null;
session_write_close();

if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'ログインが必要です']);
    exit;
}

// サイト判定（優先順: GETパラメータ → Origin ヘッダー）
$site = '';
if (!empty($_GET['site'])) {
    $site = $_GET['site'];
} elseif ($origin) {
    $site = parse_url($origin, PHP_URL_HOST) ?: '';
}
// 許可ドメインのみ
$allowedHosts = ['asobi.info', 'dbd.asobi.info', 'pkq.asobi.info', 'tbt.asobi.info', 'aic.asobi.info'];
if (!in_array($site, $allowedHosts)) {
    http_response_code(400);
    echo json_encode(['error' => '不正なサイト']);
    exit;
}

$db = asobiUsersDb();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare('SELECT display_name FROM site_profiles WHERE user_id = ? AND site = ?');
    $stmt->execute([$userId, $site]);
    $row = $stmt->fetch();
    $siteName = $row ? $row['display_name'] : '';

    // asobi.info 本体の表示名もフォールバック用に返す
    $mainName = $_SESSION['asobi_user_name'] ?? '';
    // セッション閉じ済みなのでDBから取得
    $stmt2 = $db->prepare('SELECT display_name FROM users WHERE id = ?');
    $stmt2->execute([$userId]);
    $userRow = $stmt2->fetch();
    $mainName = $userRow ? ($userRow['display_name'] ?: '') : '';

    echo json_encode([
        'site'        => $site,
        'displayName' => $siteName,
        'mainName'    => $mainName,
    ], JSON_UNESCAPED_UNICODE);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = trim($input['display_name'] ?? '');

    if (mb_strlen($name) < 1 || mb_strlen($name) > 30) {
        http_response_code(400);
        echo json_encode(['error' => '名前は1〜30文字で入力してください']);
        exit;
    }

    $stmt = $db->prepare('
        INSERT INTO site_profiles (user_id, site, display_name, updated_at)
        VALUES (?, ?, ?, datetime("now","localtime"))
        ON CONFLICT(user_id, site) DO UPDATE SET display_name = excluded.display_name, updated_at = excluded.updated_at
    ');
    $stmt->execute([$userId, $site, $name]);

    echo json_encode([
        'site'        => $site,
        'displayName' => $name,
    ], JSON_UNESCAPED_UNICODE);
}
