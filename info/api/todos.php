<?php
/**
 * TODO管理 API - asobi.info
 *
 * Claude連携用。APIキー認証（Bearer トークン）。
 * 管理画面(admin/todos.php)と同じ自動遷移ロジックを適用。
 */

require_once '/opt/asobi/shared/assets/php/api_auth.php';
asobiRequireApiKey();

$db = asobiTodosDb();
$action = $_GET['action'] ?? '';

$defaultSiteList = [
    ['key' => 'common', 'label' => '全サイト共通'],
    ['key' => 'top',    'label' => 'asobi.info トップページ'],
    ['key' => 'dbd',    'label' => 'Dead by Daylight'],
    ['key' => 'pkq',    'label' => 'ポケモンクエスト'],
    ['key' => 'tbt',    'label' => 'Tournament Battle'],
    ['key' => 'aic',    'label' => 'AI チャット'],
    ['key' => 'game',   'label' => 'レトロゲーム情報'],
];
$siteListJson = '';
try {
    $ss = $db->prepare("SELECT value FROM site_settings WHERE key = 'todo_sites'");
    $ss->execute();
    $ssRow = $ss->fetch();
    if ($ssRow) $siteListJson = $ssRow['value'];
} catch (Exception $e) {}
$siteList = $siteListJson ? json_decode($siteListJson, true) : null;
if (!is_array($siteList) || empty($siteList)) {
    $siteList = $defaultSiteList;
}
$siteLabels = array_column($siteList, 'label', 'key');
$validSites = array_column($siteList, 'key');

// ─── GET: 一覧取得 ───
if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $sql = "SELECT * FROM content_todos WHERE 1=1";
    $params = [];

    $site = $_GET['site'] ?? '';
    if ($site && in_array($site, $validSites, true)) {
        $sql .= " AND site = ?";
        $params[] = $site;
    }
    $area = $_GET['area'] ?? '';
    if ($area && in_array($area, ['general','admin'], true)) {
        $sql .= " AND area = ?";
        $params[] = $area;
    }
    $statusRaw = $_GET['status'] ?? '';
    if ($statusRaw) {
        $statuses = explode(',', $statusRaw);
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $sql .= " AND status IN ($placeholders)";
        $params = array_merge($params, $statuses);
    }

    $sql .= " ORDER BY CASE priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 WHEN 'low' THEN 2 END, created_at ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $todos = $stmt->fetchAll();

    _asobiApiJson(['ok' => true, 'todos' => $todos, 'count' => count($todos)]);
}

// ─── GET: 単一取得 ───
if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) _asobiApiError(400, 'id is required');

    $stmt = $db->prepare("SELECT * FROM content_todos WHERE id = ?");
    $stmt->execute([$id]);
    $todo = $stmt->fetch();
    if (!$todo) _asobiApiError(404, 'TODO not found');

    $todo['site_label'] = $siteLabels[$todo['site']] ?? $todo['site'];
    _asobiApiJson(['ok' => true, 'todo' => $todo]);
}

// ─── POST: 以降はJSON body ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}

// ─── POST: 新規追加 ───
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $site     = $input['site'] ?? '';
    $area     = $input['area'] ?? 'general';
    $title    = trim($input['title'] ?? '');
    $priority = $input['priority'] ?? 'medium';
    $dueDate  = $input['due_date'] ?? '';

    if (!in_array($site, $validSites, true)) _asobiApiError(400, 'Invalid site');
    if ($title === '') _asobiApiError(400, 'title is required');
    if (!in_array($area, ['general','admin'], true)) _asobiApiError(400, 'Invalid area');
    if (!in_array($priority, ['low','medium','high'], true)) _asobiApiError(400, 'Invalid priority');

    $stmt = $db->prepare("INSERT INTO content_todos (site, area, title, priority, due_date) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$site, $area, $title, $priority, $dueDate ?: null]);
    $newId = $db->lastInsertId();

    _asobiApiJson(['ok' => true, 'message' => 'TODOを追加しました', 'id' => (int)$newId]);
}

// ─── POST: ステータス更新 ───
if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($input['id'] ?? 0);
    $status = trim($input['status'] ?? '');
    if ($id <= 0) _asobiApiError(400, 'id is required');
    if ($status === '') _asobiApiError(400, 'status is required');

    // 【強制ルール】確認待ちへの遷移はstatus_note（対応メモ）必須
    // 対応メモなしの確認待ち遷移をAPI側で拒否する（何度も発生したため仕組みで防止）
    if ($status === '確認待ち') {
        $appendNote = trim($input['status_note'] ?? '');
        if ($appendNote === '') {
            _asobiApiError(400, '確認待ちにするにはstatus_note（対応メモ）が必須です。変更ファイル・実装内容を具体的に記述してください。');
        }
        // 内容のないメモも拒否
        if (mb_strlen($appendNote) < 10) {
            _asobiApiError(400, '対応メモが短すぎます（10文字以上必要）。変更ファイル・実装内容を具体的に記述してください。');
        }
    }

    $extra = '';
    if ($status === '対応中') {
        $extra = ", started_at = COALESCE(started_at, datetime('now','localtime'))";
    } elseif ($status === '完了') {
        $extra = ", completed_at = datetime('now','localtime')";
    }

    // status_note が同時に渡された場合は対応メモも更新（追記方式）
    if (isset($input['status_note']) && trim($input['status_note']) !== '') {
        $appendNote = trim($input['status_note']);
        $noteRow = $db->prepare("SELECT status_note FROM content_todos WHERE id = ?");
        $noteRow->execute([$id]);
        $existing = $noteRow->fetchColumn() ?: '';
        $newNote = $existing !== '' ? $existing . "\n\n" . $appendNote : $appendNote;
        $extra .= ', status_note = ' . $db->quote($newNote);
    }

    $db->prepare("UPDATE content_todos SET status = ?, updated_at = datetime('now','localtime'){$extra} WHERE id = ?")
       ->execute([$status, $id]);

    _asobiApiJson(['ok' => true, 'message' => '対応状況を更新しました', 'status' => $status]);
}

// ─── POST: 対応メモ更新 ───
if ($action === 'update_note' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id   = (int)($input['id'] ?? 0);
    $note = trim($input['status_note'] ?? '');
    if ($id <= 0) _asobiApiError(400, 'id is required');

    $db->prepare("UPDATE content_todos SET status_note = ?, updated_at = datetime('now','localtime') WHERE id = ?")
       ->execute([$note, $id]);

    _asobiApiJson(['ok' => true, 'message' => '対応メモを更新しました']);
}

// ─── POST: 確認結果更新（自動遷移あり） ───
if ($action === 'update_result' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id       = (int)($input['id'] ?? 0);
    $result   = $input['result'] ?? '';
    $ngReason = trim($input['ng_reason'] ?? '');
    if ($id <= 0) _asobiApiError(400, 'id is required');

    $resultValue = '';
    if ($result === 'OK') {
        $resultValue = 'OK';
    } elseif ($result === 'NG') {
        $resultValue = $ngReason ? 'NG: ' . $ngReason : 'NG';
    }

    $db->prepare("UPDATE content_todos SET result = ?, updated_at = datetime('now','localtime') WHERE id = ?")
       ->execute([$resultValue, $id]);

    $newStatus = null;
    if ($resultValue !== '') {
        $cur = $db->prepare("SELECT status FROM content_todos WHERE id = ?");
        $cur->execute([$id]);
        $curRow = $cur->fetch();
        // OK → 完了
        if ($curRow && $resultValue === 'OK') {
            $db->prepare("UPDATE content_todos SET status = '完了', completed_at = datetime('now','localtime'), updated_at = datetime('now','localtime') WHERE id = ?")
               ->execute([$id]);
            $newStatus = '完了';
        }
        // 確認待ち + NG → 未着手（対応メモにNG理由を自動追記、確認結果リセット）
        elseif ($curRow && $curRow['status'] === '確認待ち' && str_starts_with($resultValue, 'NG')) {
            $noteStmt = $db->prepare("SELECT status_note FROM content_todos WHERE id = ?");
            $noteStmt->execute([$id]);
            $noteRow = $noteStmt->fetch();
            $currentNote = trim($noteRow['status_note'] ?? '');
            $ngAppend = '【NG: ' . date('m/d H:i') . '】' . ($ngReason ?: '理由なし');
            $newNote = $currentNote !== '' ? $currentNote . "\n\n" . $ngAppend : $ngAppend;
            $db->prepare("UPDATE content_todos SET status = '未着手', status_note = ?, result = '', updated_at = datetime('now','localtime') WHERE id = ?")
               ->execute([$newNote, $id]);
            $newStatus = '未着手';
        }
    }

    $msg = '確認結果を更新しました';
    if ($newStatus) $msg .= '（ステータス→' . $newStatus . '）';
    _asobiApiJson(['ok' => true, 'message' => $msg, 'result' => $resultValue, 'status' => $newStatus]);
}

// ─── POST: 保留解除回答（→未着手） ───
if ($action === 'hold_answer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($input['id'] ?? 0);
    $answer = trim($input['hold_answer'] ?? '');
    if ($id <= 0) _asobiApiError(400, 'id is required');
    if ($answer === '') _asobiApiError(400, 'hold_answer is required');

    $db->prepare("UPDATE content_todos SET hold_answer = ?, status = '未着手', updated_at = datetime('now','localtime') WHERE id = ?")
       ->execute([$answer, $id]);

    _asobiApiJson(['ok' => true, 'message' => '保留解除回答を記入し、ステータスを「未着手」に変更しました', 'status' => '未着手']);
}

// ─── POST: 削除 ───
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) _asobiApiError(400, 'id is required');

    $db->prepare("DELETE FROM content_todos WHERE id = ?")->execute([$id]);

    _asobiApiJson(['ok' => true, 'message' => '削除しました']);
}

// ─── POST: 放置中の「対応中」をリセット ───
// セッション開始時に呼び出す。指定分数以上更新されていない「対応中」を「未着手」に戻す。
if ($action === 'reset_stale' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $minutes = max(1, (int)($input['minutes'] ?? 60));

    $stmt = $db->prepare(
        "SELECT id, title FROM content_todos
         WHERE status = '対応中'
           AND updated_at < datetime('now','localtime','-' || ? || ' minutes')"
    );
    $stmt->execute([$minutes]);
    $stale = $stmt->fetchAll();

    if (!empty($stale)) {
        $db->prepare(
            "UPDATE content_todos
             SET status = '未着手', updated_at = datetime('now','localtime')
             WHERE status = '対応中'
               AND updated_at < datetime('now','localtime','-' || ? || ' minutes')"
        )->execute([$minutes]);
    }

    _asobiApiJson([
        'ok'      => true,
        'reset'   => count($stale),
        'items'   => array_column($stale, 'id'),
        'message' => count($stale) > 0
            ? count($stale) . '件の放置「対応中」を「未着手」に戻しました'
            : '放置された「対応中」はありませんでした',
    ]);
}

// ─── 不明なaction ───
_asobiApiError(400, 'Unknown action: ' . $action . '. Available: list, get, add, update_status, update_note, update_result, hold_answer, delete, reset_stale');
