<?php
require_once __DIR__ . '/db.php';
require_once '/opt/asobi/shared/assets/php/auth.php';

try {
    $db = getDb();
    $method = $_SERVER['REQUEST_METHOD'];

    // ---- GET: コメント一覧取得 ----
    if ($method === 'GET') {
        $pageType = $_GET['page_type'] ?? '';
        $pageId   = (int)($_GET['page_id'] ?? 0);

        if (!$pageType) {
            jsonResponse(['error' => 'page_type is required'], 400);
        }

        $stmt = $db->prepare('
            SELECT id, page_type, page_id, user_id, username, display_name, avatar_url, content, created_at
            FROM comments
            WHERE page_type = ? AND page_id = ? AND status = ?
            ORDER BY created_at ASC
        ');
        $stmt->execute([$pageType, $pageId, 'active']);
        jsonResponse($stmt->fetchAll());
    }

    // ---- POST: コメント投稿 ----
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            jsonResponse(['error' => 'Invalid JSON'], 400);
        }

        // ログイン必須
        if (!asobiIsLoggedIn()) {
            jsonResponse(['error' => 'Login required'], 401);
        }

        $pageType = trim($input['page_type'] ?? '');
        $pageId   = (int)($input['page_id'] ?? 0);
        $content  = trim($input['content'] ?? '');

        $validTypes = ['pokemon', 'move', 'recipe', 'report_list'];
        if (!in_array($pageType, $validTypes)) {
            jsonResponse(['error' => 'Invalid page_type'], 400);
        }
        if ($content === '') {
            jsonResponse(['error' => 'Content is empty'], 400);
        }
        if (mb_strlen($content) > 1000) {
            jsonResponse(['error' => 'Content too long (max 1000)'], 400);
        }

        // 禁止ワードチェック
        if (function_exists('asobiCheckBanned') && asobiCheckBanned($content, 'content')['blocked']) {
            jsonResponse(['error' => '使用できない言葉が含まれています'], 400);
        }

        $user = asobiGetCurrentUser();
        $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // OS/ブラウザ判定（簡易）
        $os = '';
        $browser = '';
        if (preg_match('/Windows/i', $ua)) $os = 'Windows';
        elseif (preg_match('/Mac/i', $ua)) $os = 'Mac';
        elseif (preg_match('/iPhone|iPad/i', $ua)) $os = 'iOS';
        elseif (preg_match('/Android/i', $ua)) $os = 'Android';
        elseif (preg_match('/Linux/i', $ua)) $os = 'Linux';

        if (preg_match('/Edg/i', $ua)) $browser = 'Edge';
        elseif (preg_match('/Chrome/i', $ua)) $browser = 'Chrome';
        elseif (preg_match('/Firefox/i', $ua)) $browser = 'Firefox';
        elseif (preg_match('/Safari/i', $ua)) $browser = 'Safari';

        // IP取得
        $ip = '';
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) { $ip = explode(',', $_SERVER[$k])[0]; break; }
        }

        $stmt = $db->prepare('
            INSERT INTO comments (page_type, page_id, user_id, username, display_name, avatar_url, content, ip, user_agent, os, browser)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $pageType,
            $pageId,
            $user['id'],
            $user['username'],
            $user['display_name'] ?? $user['username'],
            $user['avatar_url'] ?? '',
            $content,
            $ip,
            $ua,
            $os,
            $browser,
        ]);

        $newId = $db->lastInsertId();
        $stmt2 = $db->prepare('SELECT id, page_type, page_id, user_id, username, display_name, avatar_url, content, created_at FROM comments WHERE id = ?');
        $stmt2->execute([$newId]);
        jsonResponse($stmt2->fetch());
    }

    // ---- DELETE: コメント削除 ----
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['error' => 'id is required'], 400);
        }

        if (!asobiIsLoggedIn()) {
            jsonResponse(['error' => 'Login required'], 401);
        }

        $stmt = $db->prepare('SELECT * FROM comments WHERE id = ?');
        $stmt->execute([$id]);
        $comment = $stmt->fetch();
        if (!$comment) {
            jsonResponse(['error' => 'Not found'], 404);
        }

        $user = asobiGetCurrentUser();
        $isAdmin = (($user['role'] ?? '') === 'admin');

        if ((int)$comment['user_id'] !== (int)$user['id'] && !$isAdmin) {
            jsonResponse(['error' => 'Permission denied'], 403);
        }

        $db->prepare('UPDATE comments SET status = ? WHERE id = ?')->execute(['deleted', $id]);
        jsonResponse(['ok' => true]);
    }

    jsonResponse(['error' => 'Method not allowed'], 405);

} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
