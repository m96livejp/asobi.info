<?php
require_once __DIR__ . '/db.php';
require_once '/opt/asobi/shared/assets/php/auth.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}

$db = getDb();

// テーブル自動作成
$db->exec("CREATE TABLE IF NOT EXISTS user_pokedex (
    user_id    INTEGER NOT NULL,
    pokedex_no INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    PRIMARY KEY (user_id, pokedex_no)
)");

$total = (int)$db->query('SELECT COUNT(*) FROM pokemon')->fetchColumn();

// ログインチェック
if (!asobiIsLoggedIn()) {
    jsonResponse(['registered' => [], 'count' => 0, 'total' => $total]);
}

$userId = $_SESSION['asobi_user_id'];

if ($method === 'GET') {
    $stmt = $db->prepare('SELECT pokedex_no FROM user_pokedex WHERE user_id = ?');
    $stmt->execute([$userId]);
    $nos = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    jsonResponse(['registered' => $nos, 'count' => count($nos), 'total' => $total]);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $no = (int)($body['pokedex_no'] ?? 0);
    if ($no < 1 || $no > 151) {
        jsonResponse(['error' => 'Invalid pokedex_no'], 400);
    }

    // 現在の状態をチェック
    $stmt = $db->prepare('SELECT 1 FROM user_pokedex WHERE user_id = ? AND pokedex_no = ?');
    $stmt->execute([$userId, $no]);

    if ($stmt->fetch()) {
        $db->prepare('DELETE FROM user_pokedex WHERE user_id = ? AND pokedex_no = ?')
           ->execute([$userId, $no]);
        $registered = false;
    } else {
        $db->prepare('INSERT INTO user_pokedex (user_id, pokedex_no) VALUES (?, ?)')
           ->execute([$userId, $no]);
        $registered = true;
    }

    $countStmt = $db->prepare('SELECT COUNT(*) FROM user_pokedex WHERE user_id = ?');
    $countStmt->execute([$userId]);
    $count = (int)$countStmt->fetchColumn();

    jsonResponse(['pokedex_no' => $no, 'registered' => $registered, 'count' => $count, 'total' => $total]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
