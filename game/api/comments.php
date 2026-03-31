<?php
/**
 * コメント API
 * GET  ?action=list&game_id=1
 * POST ?action=post  body: {game_id, content}
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

// ─── GET: コメント一覧 ───
if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $gameId = (int)($_GET['game_id'] ?? 0);
    if ($gameId <= 0) gameApiError(400, 'game_id required');

    $db = gameDb();
    $stmt = $db->prepare(
        "SELECT id, username, content, created_at FROM comments
         WHERE game_id = ? AND status = 'approved'
         ORDER BY created_at ASC"
    );
    $stmt->execute([$gameId]);
    $comments = $stmt->fetchAll();
    gameApiJson(['ok' => true, 'comments' => $comments]);
}

// ─── POST: コメント投稿 ───
if ($action === 'post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $gameId  = (int)($input['game_id'] ?? 0);
    $content = trim($input['content'] ?? '');

    if ($gameId <= 0) gameApiError(400, 'game_id required');
    if ($content === '') gameApiError(400, 'content required');
    if (mb_strlen($content) > 1000) gameApiError(400, 'コメントは1000文字以内で入力してください');

    // 禁止ワードチェック
    $banned = asobiCheckBanned($content, 'content');
    if ($banned['blocked']) gameApiError(400, 'このコメントは投稿できません');

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
        "INSERT INTO comments (game_id, user_id, username, content, status, ip, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([$gameId, $userId, $username, $content, $status, $_SERVER['REMOTE_ADDR'] ?? '', $now, $now]);

    $message = $isAdmin ? 'コメントを投稿しました' : 'コメントを受け付けました。審査後に公開されます';
    gameApiJson(['ok' => true, 'message' => $message, 'status' => $status]);
}

gameApiError(400, 'Invalid action');
