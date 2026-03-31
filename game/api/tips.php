<?php
/**
 * 裏技・コード API
 * GET  ?action=list&game_id=1
 * POST ?action=post  body: {game_id, category, title, content}
 */
require_once __DIR__ . '/db.php';
require_once '/opt/asobi/shared/assets/php/auth.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === 'https://game.asobi.info') {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

$VALID_CATEGORIES = ['cheat' => '裏技', 'code' => 'コマンド', 'bug' => 'バグ技', 'other' => 'その他'];

// ─── GET: 裏技一覧 ───
if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $gameId = (int)($_GET['game_id'] ?? 0);
    if ($gameId <= 0) gameApiError(400, 'game_id required');

    $db = gameDb();
    $stmt = $db->prepare(
        "SELECT id, category, title, content, username, created_at FROM game_tips
         WHERE game_id = ? AND status = 'approved'
         ORDER BY category, created_at ASC"
    );
    $stmt->execute([$gameId]);
    $tips = $stmt->fetchAll();
    gameApiJson(['ok' => true, 'tips' => $tips, 'categories' => $VALID_CATEGORIES]);
}

// ─── POST: 裏技投稿 ───
if ($action === 'post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $gameId   = (int)($input['game_id'] ?? 0);
    $category = $input['category'] ?? 'cheat';
    $title    = trim($input['title'] ?? '');
    $content  = trim($input['content'] ?? '');

    if ($gameId <= 0) gameApiError(400, 'game_id required');
    if (!isset($VALID_CATEGORIES[$category])) gameApiError(400, 'Invalid category');
    if ($title === '') gameApiError(400, 'タイトルを入力してください');
    if (mb_strlen($title) > 100) gameApiError(400, 'タイトルは100文字以内で入力してください');
    if ($content === '') gameApiError(400, '内容を入力してください');
    if (mb_strlen($content) > 2000) gameApiError(400, '内容は2000文字以内で入力してください');

    // 禁止ワードチェック
    $banned = asobiCheckBanned($title . ' ' . $content, 'content');
    if ($banned['blocked']) gameApiError(400, 'この内容は投稿できません');

    $db = gameDb();
    $stmt = $db->prepare("SELECT id FROM games WHERE id = ?");
    $stmt->execute([$gameId]);
    if (!$stmt->fetch()) gameApiError(404, 'Game not found');

    $userId   = asobiIsLoggedIn() ? $_SESSION['asobi_user_id'] : null;
    $isAdmin  = asobiIsAdmin();
    $username = asobiIsLoggedIn() ? ($_SESSION['asobi_user_name'] ?? 'ユーザー') : 'ゲスト';
    $status   = $isAdmin ? 'approved' : 'pending';
    $now      = date('Y-m-d H:i:s');

    $db->prepare(
        "INSERT INTO game_tips (game_id, category, title, content, user_id, username, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([$gameId, $category, $title, $content, $userId, $username, $status, $now, $now]);

    $message = $isAdmin ? '裏技情報を投稿しました' : '裏技情報を受け付けました。審査後に公開されます';
    gameApiJson(['ok' => true, 'message' => $message, 'status' => $status]);
}

gameApiError(400, 'Invalid action');
