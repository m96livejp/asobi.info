<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
asobiRequireAdmin();

$db = asobiUsersDb();

// セッションからフラッシュメッセージ取得
session_start();
$msg = $_SESSION['todo_msg'] ?? '';
unset($_SESSION['todo_msg']);

$defaultSiteList = [
    ['key' => 'common', 'label' => '全サイト共通'],
    ['key' => 'top',    'label' => 'asobi.info トップページ'],
    ['key' => 'dbd',    'label' => 'Dead by Daylight'],
    ['key' => 'pkq',    'label' => 'ポケモンクエスト'],
    ['key' => 'tbt',    'label' => 'Tournament Battle'],
    ['key' => 'aic',    'label' => 'AI チャット'],
    ['key' => 'game',   'label' => 'レトロゲーム情報'],
];
$siteListJson = asobiGetSetting('todo_sites', '');
$siteList = $siteListJson ? json_decode($siteListJson, true) : null;
if (!is_array($siteList) || empty($siteList)) {
    $siteList = $defaultSiteList;
}
$siteLabels = array_column($siteList, 'label', 'key');
$areaLabels     = ['general' => '一般', 'admin' => '管理'];
$priorityLabels = ['high' => '高', 'medium' => '中', 'low' => '低'];
$statusPresets  = ['未着手', '対応中', '確認待ち', '完了', '保留'];

// ステータスグループ定義
$statusGroups = [
    'pending' => ['label' => '処理前',  'statuses' => ['未着手']],
    'active'  => ['label' => '処理中',  'statuses' => ['対応中']],
    'review'  => ['label' => '確認待ち', 'statuses' => ['確認待ち', '保留']],
    'done'    => ['label' => '完了',    'statuses' => ['完了']],
];
$defaultGroup = 'review'; // デフォルト表示グループ（管理者対応が必要なもの）

// 追加
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $site     = $_POST['site'] ?? '';
    $area     = $_POST['area'] ?? 'general';
    $title    = trim(str_replace("\r\n", "\n", $_POST['title'] ?? ''));
    $priority = $_POST['priority'] ?? 'medium';
    $dueDate  = $_POST['due_date'] ?? '';

    if (!isset($siteLabels[$site])) {
        $_SESSION['todo_msg'] = 'error:サイトを選択してください';
        header('Location: /admin/todos.php'); exit;
    } elseif ($title === '') {
        $_SESSION['todo_msg'] = 'error:更新内容を入力してください';
        header('Location: /admin/todos.php'); exit;
    } elseif (!in_array($area, ['general','admin'], true)) {
        $_SESSION['todo_msg'] = 'error:区分が不正です';
        header('Location: /admin/todos.php'); exit;
    } elseif (!in_array($priority, ['low','medium','high'], true)) {
        $_SESSION['todo_msg'] = 'error:優先度が不正です';
        header('Location: /admin/todos.php'); exit;
    } else {
        $stmt = $db->prepare("INSERT INTO content_todos (site, area, title, priority, due_date) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$site, $area, $title, $priority, $dueDate ?: null]);
        $_SESSION['todo_msg'] = 'success:TODOを追加しました';
        $_SESSION['todo_last_site'] = $site;
        $_SESSION['todo_last_area'] = $area;
        $_SESSION['todo_last_priority'] = $priority;
        header('Location: /admin/todos.php'); exit;
    }
}

// ステータス変更（フリーフォーム）+ 時刻自動記録
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'status') {
    $id     = (int)($_POST['id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    if ($id > 0 && $status !== '') {
        $extra = '';
        if ($status === '対応中') {
            $extra = ", started_at = COALESCE(started_at, datetime('now','localtime'))";
        } elseif ($status === '完了') {
            $extra = ", completed_at = datetime('now','localtime')";
        }
        $db->prepare("UPDATE content_todos SET status = ?, updated_at = datetime('now','localtime'){$extra} WHERE id = ?")
           ->execute([$status, $id]);
        $_SESSION['todo_msg'] = 'success:対応状況を更新しました';
        header('Location: /admin/todos.php?sel=' . $id); exit;
    }
}

// 対応状況メモの更新
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'status_note') {
    $id   = (int)($_POST['id'] ?? 0);
    $note = trim($_POST['status_note'] ?? '');
    if ($id > 0) {
        $db->prepare("UPDATE content_todos SET status_note = ?, updated_at = datetime('now','localtime') WHERE id = ?")
           ->execute([$note, $id]);
        $_SESSION['todo_msg'] = 'success:対応状況メモを更新しました';
        header('Location: /admin/todos.php?sel=' . $id); exit;
    }
}

// 保留解除回答
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hold_answer') {
    $id     = (int)($_POST['id'] ?? 0);
    $answer = trim($_POST['hold_answer'] ?? '');
    if ($id > 0 && $answer !== '') {
        $db->prepare("UPDATE content_todos SET hold_answer = ?, status = '未着手', updated_at = datetime('now','localtime') WHERE id = ?")
           ->execute([$answer, $id]);
        $_SESSION['todo_msg'] = 'success:保留解除回答を記入し、ステータスを「未着手」に変更しました';
        header('Location: /admin/todos.php?sel=' . $id); exit;
    }
}

// 確認結果の更新
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'result') {
    $id         = (int)($_POST['id'] ?? 0);
    $resultType = $_POST['result_type'] ?? '';
    $ngReason   = trim($_POST['ng_reason'] ?? '');
    $result     = '';
    if ($resultType === 'OK') {
        $result = 'OK';
    } elseif ($resultType === 'NG') {
        $result = $ngReason ? 'NG: ' . $ngReason : 'NG';
    }
    if ($id > 0) {
        $db->prepare("UPDATE content_todos SET result = ?, updated_at = datetime('now','localtime') WHERE id = ?")
           ->execute([$result, $id]);
        // 自動遷移
        if ($result !== '') {
            $cur = $db->prepare("SELECT status FROM content_todos WHERE id = ?");
            $cur->execute([$id]);
            $curRow = $cur->fetch();
            // OK → 完了
            if ($curRow && $result === 'OK') {
                $db->prepare("UPDATE content_todos SET status = '完了', completed_at = datetime('now','localtime'), updated_at = datetime('now','localtime') WHERE id = ?")
                   ->execute([$id]);
                $_SESSION['todo_msg'] = 'success:確認結果をOKに更新し、ステータスを「完了」に変更しました';
                header('Location: /admin/todos.php'); exit;
            }
            // 確認待ち + NG → 未着手（対応メモにNG理由を自動追記）
            if ($curRow && $curRow['status'] === '確認待ち' && str_starts_with($result, 'NG')) {
                // 対応メモにNG理由を追記
                $noteStmt = $db->prepare("SELECT status_note FROM content_todos WHERE id = ?");
                $noteStmt->execute([$id]);
                $noteRow = $noteStmt->fetch();
                $currentNote = trim($noteRow['status_note'] ?? '');
                $ngAppend = '【NG: ' . date('m/d H:i') . '】' . ($ngReason ?: '理由なし');
                $newNote = $currentNote !== '' ? $currentNote . "\n\n" . $ngAppend : $ngAppend;
                $db->prepare("UPDATE content_todos SET status = '未着手', status_note = ?, result = '', updated_at = datetime('now','localtime') WHERE id = ?")
                   ->execute([$newNote, $id]);
                $_SESSION['todo_msg'] = 'success:確認結果をNGに更新し、ステータスを「未着手」に変更しました（対応メモにNG理由を追記）';
                header('Location: /admin/todos.php'); exit;
            }
        }
        $_SESSION['todo_msg'] = 'success:確認結果を更新しました';
        header('Location: /admin/todos.php?sel=' . $id); exit;
    }
}

// 削除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $db->prepare("DELETE FROM content_todos WHERE id = ?")->execute([$id]);
        $_SESSION['todo_msg'] = 'success:削除しました';
        header('Location: /admin/todos.php'); exit;
    }
}

// サイト追加
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_site') {
    $newKey   = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_POST['site_key'] ?? '')));
    $newLabel = trim($_POST['site_label'] ?? '');
    if (!$newKey || !$newLabel) {
        $_SESSION['todo_msg'] = 'error:サイトキーとラベルを入力してください';
    } elseif (strlen($newKey) > 20) {
        $_SESSION['todo_msg'] = 'error:キーは20文字以内で入力してください';
    } elseif (isset($siteLabels[$newKey])) {
        $_SESSION['todo_msg'] = 'error:そのキーは既に存在します';
    } else {
        $siteList[] = ['key' => $newKey, 'label' => $newLabel];
        asobiSetSetting('todo_sites', json_encode($siteList, JSON_UNESCAPED_UNICODE));
        $_SESSION['todo_msg'] = 'success:サイト「' . $newKey . '」を追加しました';
    }
    header('Location: /admin/todos.php'); exit;
}

// サイト削除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_site') {
    $delKey = $_POST['site_key'] ?? '';
    $cntStmt = $db->prepare("SELECT COUNT(*) FROM content_todos WHERE site = ?");
    $cntStmt->execute([$delKey]);
    $cnt = (int)$cntStmt->fetchColumn();
    if ($cnt > 0) {
        $_SESSION['todo_msg'] = 'error:このサイトにはTODOが' . $cnt . '件存在するため削除できません';
    } else {
        $siteList = array_values(array_filter($siteList, fn($s) => $s['key'] !== $delKey));
        asobiSetSetting('todo_sites', json_encode($siteList, JSON_UNESCAPED_UNICODE));
        $_SESSION['todo_msg'] = 'success:サイト「' . $delKey . '」を削除しました';
    }
    header('Location: /admin/todos.php'); exit;
}

// フィルター
$filterSite   = $_GET['site'] ?? '';
$filterArea   = $_GET['area'] ?? '';
$filterGroup  = $_GET['group'] ?? '';  // active, review, done, all
$hasGroupParam = isset($_GET['group']);

// グループ未指定ならデフォルトグループ（処理中）を適用
if (!$hasGroupParam) {
    $filterGroup = $defaultGroup;
}

// グループからステータス配列を決定
$filterStatuses = [];
if ($filterGroup !== 'all' && isset($statusGroups[$filterGroup])) {
    $filterStatuses = $statusGroups[$filterGroup]['statuses'];
}

$sql = "SELECT * FROM content_todos WHERE 1=1";
$params = [];
if ($filterSite && isset($siteLabels[$filterSite])) {
    $sql .= " AND site = ?";
    $params[] = $filterSite;
}
if ($filterArea && isset($areaLabels[$filterArea])) {
    $sql .= " AND area = ?";
    $params[] = $filterArea;
}
if (!empty($filterStatuses)) {
    $placeholders = implode(',', array_fill(0, count($filterStatuses), '?'));
    $sql .= " AND status IN ($placeholders)";
    $params = array_merge($params, $filterStatuses);
}
$sql .= " ORDER BY CASE priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 WHEN 'low' THEN 2 END, created_at ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$todos = $stmt->fetchAll();

// 選択中のアイテム
$selItem = null;
$selId = (int)($_GET['sel'] ?? 0);
if ($selId > 0) {
    $selStmt = $db->prepare("SELECT * FROM content_todos WHERE id = ?");
    $selStmt->execute([$selId]);
    $selItem = $selStmt->fetch();
}

// 件数集計
$countRows = $db->query("SELECT status, COUNT(*) as cnt FROM content_todos GROUP BY status")->fetchAll();
$counts = [];
foreach ($countRows as $r) { $counts[$r['status']] = (int)$r['cnt']; }
$totalCount = array_sum($counts);

// グループ別件数
$groupCounts = [];
foreach ($statusGroups as $gKey => $gDef) {
    $groupCounts[$gKey] = 0;
    foreach ($gDef['statuses'] as $s) {
        $groupCounts[$gKey] += $counts[$s] ?? 0;
    }
}

// フィルタークエリ組み立てヘルパー
function buildQuery(array $overrides): string {
    $p = array_merge([
        'site'  => $_GET['site'] ?? '',
        'area'  => $_GET['area'] ?? '',
        'group' => $_GET['group'] ?? '',
    ], $overrides);
    $p = array_filter($p, fn($v) => $v !== '');
    return $p ? '?' . http_build_query($p) : '?';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TODO管理 - asobi.info</title>
  <link rel="stylesheet" href="/assets/css/common.css?v=20260327f">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Noto Sans JP', sans-serif;
      background: #f0f2f5;
      color: #1d2d3a;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    h1 { font-size: 1.4rem; margin-bottom: 8px; color: #1d2d3a; }
    .section-title { font-size: 1rem; font-weight: 600; color: #637080; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 1px solid #e0e4e8; }

    .stats-bar {
      display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap;
      font-size: 0.82rem; color: #637080;
    }
    .stats-bar span { white-space: nowrap; }
    .stats-bar .num { font-weight: 700; color: #1d2d3a; }

    .card-panel {
      background: #fff; border: 1px solid #e0e4e8; border-radius: 12px;
      padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .form-group { display: flex; flex-direction: column; gap: 6px; }
    .form-group label { font-size: 0.78rem; font-weight: 600; color: #637080; }
    .form-group input[type=text], .form-group input[type=date], .form-group textarea {
      padding: 9px 12px; border: 2px solid #e0e4e8; border-radius: 8px;
      font-size: 0.9rem; font-family: inherit; outline: none; transition: border-color 0.2s;
      resize: vertical;
    }
    .form-group input:focus, .form-group textarea:focus { border-color: #667eea; }
    .form-group select {
      padding: 9px 12px; border: 2px solid #e0e4e8; border-radius: 8px;
      font-size: 0.9rem; font-family: inherit; outline: none; transition: border-color 0.2s; background: #fff;
    }
    .form-group select:focus { border-color: #667eea; }
    .btn-primary {
      padding: 9px 20px; background: linear-gradient(135deg, #667eea, #764ba2);
      color: #fff; border: none; border-radius: 8px; font-size: 0.9rem;
      font-weight: 600; cursor: pointer; font-family: inherit; white-space: nowrap;
    }
    .btn-primary:hover { opacity: 0.88; }
    .btn-save {
      padding: 7px 16px; background: linear-gradient(135deg, #43a047, #2e7d32);
      color: #fff; border: none; border-radius: 8px; font-size: 0.85rem;
      font-weight: 600; cursor: pointer; font-family: inherit;
    }
    .btn-save:hover { opacity: 0.88; }

    .filters {
      display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px;
    }
    .filter-btn {
      padding: 6px 14px; border: 1px solid #e0e4e8; border-radius: 20px;
      background: #fff; font-size: 0.8rem; color: #637080; cursor: pointer;
      text-decoration: none; font-family: inherit; transition: all 0.15s;
    }
    .filter-btn:hover { border-color: #667eea; color: #667eea; }
    .filter-btn.active { background: #667eea; color: #fff; border-color: #667eea; }
    .filter-sep { width: 1px; background: #e0e4e8; margin: 0 4px; }

    .msg { padding: 10px 14px; border-radius: 8px; font-size: 0.875rem; margin-bottom: 20px; }
    .msg.success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
    .msg.error   { background: #fff1f2; border: 1px solid #fecdd3; color: #e11d48; }

    .todo-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .todo-table th { background: #f8fafc; font-size: 0.8rem; font-weight: 600; color: #637080; padding: 10px 12px; text-align: left; border-bottom: 2px solid #e0e4e8; white-space: nowrap; }
    .todo-table td { padding: 10px 12px; border-bottom: 1px solid #f0f2f5; font-size: 0.85rem; color: #1d2d3a; vertical-align: top; }
    .todo-table tr:last-child td { border-bottom: none; }
    .todo-table tr { cursor: pointer; transition: background 0.1s; }
    .todo-table tr:hover td { background: #f5f7fa; }
    .todo-table tr.selected td { background: #eef1f8; }

    .badge { display: inline-block; font-size: 0.72rem; font-weight: 600; padding: 2px 8px; border-radius: 10px; white-space: nowrap; }
    .badge-high    { background: rgba(231,76,60,0.15);  color: #e74c3c; }
    .badge-medium  { background: rgba(247,201,78,0.2);  color: #b78a00; }
    .badge-low     { background: rgba(76,175,80,0.15);  color: #388e3c; }
    .badge-site    { background: rgba(0,0,0,0.06); color: #1d2d3a; }
    .badge-general { background: rgba(102,126,234,0.12); color: #5567cc; }
    .badge-admin   { background: rgba(231,76,60,0.12);   color: #c0392b; }

    .btn-sm {
      padding: 4px 10px; border: 1px solid #e0e4e8; border-radius: 6px;
      font-size: 0.75rem; cursor: pointer; font-family: inherit; transition: background 0.15s;
      background: #fff; color: #637080;
    }
    .btn-sm:hover { background: #f0f2f5; }
    .btn-delete { color: #e74c3c; }
    .btn-delete:hover { background: #fff1f2; }

    .no-data { text-align: center; color: #9ba8b5; padding: 24px; }
    .todo-title { font-weight: 500; white-space: pre-wrap; line-height: 1.6; }
    .todo-title.completed { text-decoration: line-through; color: #9ba8b5; }
    .todo-note {
      margin-top: 6px; padding: 6px 10px; background: #f0f4ff; border-left: 3px solid #667eea;
      border-radius: 4px; font-size: 0.78rem; color: #4a5568; line-height: 1.5; white-space: pre-wrap;
    }

    /* 選択パネル */
    .sel-panel { border-left: 4px solid #667eea; }
    .sel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
    .sel-header .sel-title { font-size: 1rem; font-weight: 600; color: #1d2d3a; }
    .sel-close { font-size: 0.8rem; color: #667eea; text-decoration: none; }
    .sel-meta { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; font-size: 0.82rem; color: #637080; }
    .sel-meta .meta-label { font-weight: 600; color: #9ba8b5; margin-right: 4px; }
    .sel-content { background: #f8fafc; border: 1px solid #e0e4e8; border-radius: 8px; padding: 14px; margin-bottom: 16px; white-space: pre-wrap; line-height: 1.7; font-size: 0.9rem; }
    .sel-actions { display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-start; }
    .sel-actions .action-group { flex: 1; min-width: 200px; }
    .action-group-title { font-size: 0.78rem; font-weight: 600; color: #637080; margin-bottom: 8px; }

    /* フロー図 */
    .flow-chart { margin-bottom: 20px; padding: 16px 20px; background: #f8fafc; border: 1px solid #e0e4e8; border-radius: 10px; overflow-x: auto; }
    .flow-main { display: flex; align-items: center; gap: 0; }
    .flow-node {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 5px 12px; border-radius: 16px; font-size: 0.76rem; font-weight: 600;
      border: 2px solid #d0d5dd; background: #fff; color: #9ba8b5;
      white-space: nowrap; position: relative;
    }
    .flow-arrow { color: #d0d5dd; font-size: 0.75rem; padding: 0 4px; flex-shrink: 0; }
    .flow-node.done { border-color: #a7f3d0; background: #ecfdf5; color: #065f46; }
    .flow-node.current { border-color: #667eea; background: #eef1f8; color: #4338ca; box-shadow: 0 0 0 3px rgba(102,126,234,0.15); }
    .flow-node.skip { opacity: 0.35; }
    .flow-who {
      font-size: 0.58rem; font-weight: 700; padding: 1px 5px; border-radius: 8px; margin-left: 2px;
    }
    .flow-who.ai { background: #dbeafe; color: #1d4ed8; }
    .flow-who.admin { background: #fce7f3; color: #be185d; }
    /* 分岐（下段） */
    .flow-branches { display: flex; flex-direction: column; gap: 6px; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #e0e4e8; }
    .flow-branch { display: flex; align-items: center; gap: 0; }
    .flow-branch-label {
      font-size: 0.68rem; font-weight: 600; color: #9ba8b5; margin-right: 8px; white-space: nowrap;
    }
    .flow-v-arrow { color: #d0d5dd; font-size: 0.65rem; padding: 0 3px; }
    /* 凡例 */
    .flow-legend {
      display: flex; gap: 12px; font-size: 0.68rem; color: #9ba8b5; margin-top: 10px;
      padding-top: 8px; border-top: 1px solid #e0e4e8;
    }
    .flow-legend span { display: flex; align-items: center; gap: 3px; }
  </style>
</head>
<body>
  <?php $adminActivePage = 'todos'; require __DIR__ . '/_sidebar.php'; ?>

    <h1 style="font-size:1.4rem;margin-bottom:8px;">TODO管理</h1>
    <div class="stats-bar">
      <span>全 <span class="num"><?= $totalCount ?></span> 件</span>
      <?php foreach ($statusGroups as $gKey => $gDef): ?>
      <span><?= $gDef['label'] ?> <span class="num"><?= $groupCounts[$gKey] ?></span></span>
      <?php endforeach; ?>
    </div>

    <?php if ($msg): ?>
    <?php [$type, $text] = explode(':', $msg, 2); ?>
    <div class="msg <?= $type ?>"><?= $text ?></div>
    <?php endif; ?>

    <?php if ($selItem): ?>
    <!-- 選択アイテム詳細パネル -->
    <div class="card-panel sel-panel" id="sel-panel">
      <div class="sel-header">
        <span class="sel-title">TODO詳細 <span style="font-size:0.75rem;color:#9ba8b5;">#<?= $selItem['id'] ?></span></span>
        <a href="/admin/todos.php" class="sel-close">✕ 閉じる</a>
      </div>
      <div class="sel-meta">
        <span><span class="meta-label">対象:</span> <span class="badge badge-site"><?= htmlspecialchars($selItem['site']) ?></span> <?= $siteLabels[$selItem['site']] ?? '' ?></span>
        <span><span class="meta-label">区分:</span> <span class="badge badge-<?= $selItem['area'] ?? 'general' ?>"><?= $areaLabels[$selItem['area'] ?? 'general'] ?></span></span>
        <span><span class="meta-label">優先度:</span> <span class="badge badge-<?= $selItem['priority'] ?>"><?= $priorityLabels[$selItem['priority']] ?? $selItem['priority'] ?></span></span>
        <?php $due = $selItem['due_date'] ?? ''; ?>
        <?php if ($due): ?>
        <span><span class="meta-label">期限:</span> <?php
          $isOverdue = $due < date('Y-m-d') && !in_array($selItem['status'], ['完了', '保留'], true);
          echo '<span style="color:' . ($isOverdue ? '#e74c3c' : 'inherit') . ';">' . htmlspecialchars($due) . '</span>';
        ?></span>
        <?php endif; ?>
        <span><span class="meta-label">登録:</span> <?= substr($selItem['created_at'], 0, 10) ?></span>
        <?php if (!empty($selItem['started_at'])): ?>
        <span><span class="meta-label">開始:</span> <?= substr($selItem['started_at'], 0, 16) ?></span>
        <?php endif; ?>
        <?php if (!empty($selItem['completed_at'])): ?>
        <span><span class="meta-label">完了:</span> <?= substr($selItem['completed_at'], 0, 16) ?></span>
        <?php endif; ?>
      </div>
      <div class="sel-content"><?= htmlspecialchars($selItem['title']) ?></div>


      <?php $isLocked = $selItem['status'] === '対応中'; ?>
      <?php if ($isLocked): ?>
      <!-- 対応中: AI作業中のため編集不可 -->
      <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:16px 20px;margin-bottom:16px;">
        <div style="font-size:0.85rem;font-weight:600;color:#c2410c;margin-bottom:6px;">現在AIが対応中です</div>
        <div style="font-size:0.8rem;color:#9a3412;">ステータスが「<?= htmlspecialchars($selItem['status']) ?>」のため、管理者からの編集はできません。保留にする場合のみ変更可能です。</div>
      </div>
      <div class="sel-actions">
        <div class="action-group">
          <div class="action-group-title">対応状況</div>
          <form method="POST" action="" style="display:flex;gap:6px;align-items:center;margin-bottom:10px;">
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="id" value="<?= $selItem['id'] ?>">
            <input type="hidden" name="status" value="保留">
            <button type="button" class="btn-save" style="background:linear-gradient(135deg,#e8a838,#d48820);"
                    data-confirm="このTODOを保留にしますか？AIの作業が中断されます。" data-confirm-ok="保留にする">保留にする</button>
          </form>
          <?php if (($selItem['status_note'] ?? '') !== ''): ?>
          <div class="action-group-title">対応メモ</div>
          <div style="background:#f0f4ff;border-left:3px solid #667eea;border-radius:4px;padding:10px 14px;font-size:0.85rem;line-height:1.6;white-space:pre-wrap;"><?= htmlspecialchars($selItem['status_note']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php else: ?>
      <?php
        $resultRaw = $selItem['result'] ?? '';
        $isOk = $resultRaw === 'OK';
        $isNg = str_starts_with($resultRaw, 'NG');
        $ngReason = $isNg ? trim(substr($resultRaw, 2), ': ') : '';
        $isAiNote = !$isOk && !$isNg && $resultRaw !== '';
      ?>
      <?php
        // 番号を動的に割り当て（順序: 対応状況→更新内容→対応メモ→確認結果）
        $sn = 1;
        $n_status  = $sn++;                    // 常に①
        $n_ainote  = $isAiNote ? $sn++ : null; // 更新内容（AIが記入した対応内容、あれば）
        $holdAnswer = $selItem['hold_answer'] ?? '';
        $n_memo    = $sn++;                    // 対応メモ
        $n_result  = $sn++;                    // 確認結果（最後）
        $circled = ['①','②','③','④','⑤'];
      ?>
      <div class="sel-actions">
        <!-- ① 対応状況 -->
        <div class="action-group">
          <div class="action-group-title"><?= $circled[$n_status-1] ?> 対応状況</div>
          <form method="POST" action="" style="display:flex;gap:6px;align-items:center;margin-bottom:10px;">
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="id" value="<?= $selItem['id'] ?>">
            <input type="text" name="status" value="<?= htmlspecialchars($selItem['status']) ?>" list="status-list"
              style="padding:7px 10px;border:2px solid #e0e4e8;border-radius:8px;font-size:0.85rem;font-family:inherit;width:120px;">
            <button type="submit" class="btn-save">保存</button>
          </form>
        </div>

        <?php if ($isAiNote): ?>
        <!-- ② 更新内容（AI対応内容） -->
        <div class="action-group">
          <div class="action-group-title" style="color:#4338ca;"><?= $circled[$n_ainote-1] ?> 更新内容</div>
          <div style="background:#f0f4ff;border-left:3px solid #667eea;border-radius:0 8px 8px 0;padding:10px 14px;font-size:0.85rem;line-height:1.6;white-space:pre-wrap;"><?= htmlspecialchars($resultRaw) ?></div>
        </div>
        <?php endif; ?>

        <!-- 対応メモ -->
        <div class="action-group">
          <div class="action-group-title"><?= $circled[$n_memo-1] ?> 対応メモ</div>
          <form method="POST" action="">
            <input type="hidden" name="action" value="status_note">
            <input type="hidden" name="id" value="<?= $selItem['id'] ?>">
            <textarea name="status_note" rows="8" placeholder="対応状況の詳細メモ（自由記入）"
              style="width:100%;padding:9px 12px;border:2px solid #e0e4e8;border-radius:8px;font-size:0.85rem;font-family:inherit;resize:vertical;line-height:1.6;margin-bottom:8px;"><?= htmlspecialchars($selItem['status_note'] ?? '') ?></textarea>
            <button type="submit" class="btn-save">メモ保存</button>
          </form>
        </div>

        <!-- 確認結果（最後） -->
        <div class="action-group">
          <?php if ($selItem['status'] === '保留'): ?>
          <!-- 保留中：解除回答入力 -->
          <div class="action-group-title" style="color:#e8a838;">保留解除回答</div>
          <form method="POST" action="" style="margin-bottom:16px;">
            <input type="hidden" name="action" value="hold_answer">
            <input type="hidden" name="id" value="<?= $selItem['id'] ?>">
            <textarea name="hold_answer" rows="3" placeholder="保留理由に対する回答を入力" required
              style="width:100%;padding:9px 12px;border:2px solid #e8a838;border-radius:8px;font-size:0.85rem;font-family:inherit;resize:vertical;line-height:1.6;margin-bottom:8px;"><?= htmlspecialchars($holdAnswer) ?></textarea>
            <button type="submit" class="btn-save" style="background:linear-gradient(135deg,#e8a838,#d48820);">回答して未着手へ</button>
          </form>
          <?php elseif ($holdAnswer !== ''): ?>
          <!-- 保留解除済み：回答表示 -->
          <div class="action-group-title">保留解除回答</div>
          <div style="background:#fef9ee;border:1px solid #f0e0b8;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:0.85rem;line-height:1.6;white-space:pre-wrap;"><?= htmlspecialchars($holdAnswer) ?></div>
          <?php endif; ?>

          <div class="action-group-title"><?= $circled[$n_result-1] ?> 確認結果</div>
          <?php if ($isNg): ?>
          <div style="background:#fff1f2;border:1px solid #fecdd3;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:0.85rem;line-height:1.6;">
            <span style="font-weight:600;color:#e11d48;">NG</span>
            <?php if ($ngReason): ?>
            <div style="margin-top:6px;white-space:pre-wrap;color:#64748b;"><?= htmlspecialchars($ngReason) ?></div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <form method="POST" action="">
            <input type="hidden" name="action" value="result">
            <input type="hidden" name="id" value="<?= $selItem['id'] ?>">
            <input type="hidden" name="result_type" value="NG">
            <div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;margin-bottom:8px;">
              <textarea name="ng_reason" rows="3" placeholder="NG理由（詳細を記入）"
                style="padding:7px 10px;border:2px solid #e0e4e8;border-radius:8px;font-size:0.85rem;font-family:inherit;flex:1;min-width:200px;resize:vertical;line-height:1.6;"><?= htmlspecialchars($ngReason) ?></textarea>
              <button type="submit" class="btn-save" style="background:#e74c3c;">✗ NG送信</button>
            </div>
          </form>
          <div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;margin-bottom:8px;">
            <form method="POST" action="" style="margin:0;">
              <input type="hidden" name="action" value="result">
              <input type="hidden" name="id" value="<?= $selItem['id'] ?>">
              <input type="hidden" name="result_type" value="OK">
              <button type="submit" class="btn-save" style="background:#388e3c;">✓ OK（完了）</button>
            </form>
          </div>

          <!-- 削除 -->
          <div style="margin-top:20px;">
            <form method="POST" action="">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $selItem['id'] ?>">
              <button type="button" class="btn-sm btn-delete" data-confirm="このTODOを削除しますか？" data-confirm-ok="削除する">削除</button>
            </form>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!$selItem): ?>
    <!-- 追加ボタン＆折りたたみフォーム -->
    <div style="margin-bottom:16px;">
      <button class="btn-primary" id="todo-add-toggle" onclick="document.getElementById('todo-add-form').style.display=this.style.display='none';document.getElementById('todo-add-form').style.display='block';">＋ TODO追加</button>
    </div>
    <div class="card-panel" id="todo-add-form" style="display:none;">
      <div class="section-title" style="display:flex;justify-content:space-between;align-items:center;">
        <span>TODO追加</span>
        <a href="#" style="font-size:0.8rem;color:#667eea;text-decoration:none;" onclick="event.preventDefault();document.getElementById('todo-add-form').style.display='none';document.getElementById('todo-add-toggle').style.display='';">✕ 閉じる</a>
      </div>
      <form method="POST" action="">
        <input type="hidden" name="action" value="add">
        <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;">
          <div style="display:flex;flex-direction:column;gap:12px;min-width:160px;">
            <div class="form-group">
              <label>更新対象 <span style="color:#e74c3c;">*</span></label>
              <?php
                $lastSite     = $_SESSION['todo_last_site'] ?? '';
                $lastArea     = $_SESSION['todo_last_area'] ?? 'general';
                $lastPriority = $_SESSION['todo_last_priority'] ?? 'medium';
              ?>
              <select name="site" required>
                <option value="">選択...</option>
                <?php foreach ($siteLabels as $key => $label): ?>
                <option value="<?= $key ?>" <?= $lastSite === $key ? 'selected' : '' ?>><?= $key ?> - <?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>区分</label>
              <select name="area">
                <option value="general" <?= $lastArea === 'general' ? 'selected' : '' ?>>一般</option>
                <option value="admin" <?= $lastArea === 'admin' ? 'selected' : '' ?>>管理</option>
              </select>
            </div>
            <div class="form-group">
              <label>優先度</label>
              <select name="priority">
                <option value="medium" <?= $lastPriority === 'medium' ? 'selected' : '' ?>>中</option>
                <option value="high" <?= $lastPriority === 'high' ? 'selected' : '' ?>>高</option>
                <option value="low" <?= $lastPriority === 'low' ? 'selected' : '' ?>>低</option>
              </select>
            </div>
            <div class="form-group">
              <label>期日 <span style="color:#9ca3af;font-weight:400;">任意</span></label>
              <input type="date" name="due_date">
            </div>
          </div>
          <div class="form-group" style="flex:2;min-width:320px;">
            <label>更新内容 <span style="color:#e74c3c;">*</span></label>
            <textarea name="title" rows="10" placeholder="修正・改善内容を入力（複数行可）" required
              style="width:100%;line-height:1.6;"></textarea>
            <button type="submit" class="btn-primary" style="margin-top:10px;">追加</button>
          </div>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <!-- サイト管理 -->
    <details style="margin-bottom:12px;">
      <summary style="cursor:pointer;font-size:0.82rem;color:#667eea;padding:6px 0;user-select:none;">⚙️ サイト一覧を管理</summary>
      <div class="card-panel" style="margin-top:8px;padding:16px 20px;">
        <div class="section-title" style="margin-bottom:12px;">サイト管理</div>
        <table style="width:100%;border-collapse:collapse;font-size:0.82rem;margin-bottom:14px;">
          <thead>
            <tr style="background:#f8f9fb;">
              <th style="padding:7px 10px;text-align:left;border-bottom:1px solid #e0e4e8;color:#637080;">キー</th>
              <th style="padding:7px 10px;text-align:left;border-bottom:1px solid #e0e4e8;color:#637080;">ラベル</th>
              <th style="padding:7px 10px;border-bottom:1px solid #e0e4e8;"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($siteList as $s): ?>
            <tr>
              <td style="padding:7px 10px;border-bottom:1px solid #f0f2f5;font-family:monospace;"><?= htmlspecialchars($s['key']) ?></td>
              <td style="padding:7px 10px;border-bottom:1px solid #f0f2f5;"><?= htmlspecialchars($s['label']) ?></td>
              <td style="padding:7px 10px;border-bottom:1px solid #f0f2f5;">
                <form method="POST" action="" style="display:inline;">
                  <input type="hidden" name="action" value="remove_site">
                  <input type="hidden" name="site_key" value="<?= htmlspecialchars($s['key']) ?>">
                  <button type="submit" style="padding:3px 10px;font-size:0.75rem;background:#fee2e2;color:#e11d48;border:none;border-radius:4px;cursor:pointer;">削除</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <form method="POST" action="" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
          <input type="hidden" name="action" value="add_site">
          <div class="form-group" style="flex:0 0 130px;">
            <label>キー（英小文字・数字）</label>
            <input type="text" name="site_key" placeholder="例: pkq2" pattern="[a-z0-9_-]+" maxlength="20" required>
          </div>
          <div class="form-group" style="flex:1;min-width:180px;">
            <label>ラベル</label>
            <input type="text" name="site_label" placeholder="例: ポケモンクエスト2" maxlength="50" required>
          </div>
          <button type="submit" class="btn-primary" style="flex-shrink:0;">追加</button>
        </form>
      </div>
    </details>

    <!-- フィルター -->
    <div class="filters">
      <label style="font-size:12px;color:var(--sub);margin-right:2px;">サイト:</label>
      <select onchange="location.href=this.value" style="padding:6px 28px 6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg2) url('data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2210%22 height=%226%22><path d=%22M0 0l5 6 5-6z%22 fill=%22%239ca3af%22/></svg>') no-repeat right 8px center;color:var(--text);font-size:13px;cursor:pointer;-webkit-appearance:none;appearance:none;">
        <option value="<?= buildQuery(['site' => '']) ?>" <?= $filterSite === '' ? 'selected' : '' ?>>全サイト</option>
        <?php foreach ($siteLabels as $key => $label): ?>
        <option value="<?= buildQuery(['site' => $key]) ?>" <?= $filterSite === $key ? 'selected' : '' ?>><?= $key ?></option>
        <?php endforeach; ?>
      </select>
      <label style="font-size:12px;color:var(--sub);margin-right:2px;margin-left:8px;">区分:</label>
      <select onchange="location.href=this.value" style="padding:6px 28px 6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg2) url('data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2210%22 height=%226%22><path d=%22M0 0l5 6 5-6z%22 fill=%22%239ca3af%22/></svg>') no-repeat right 8px center;color:var(--text);font-size:13px;cursor:pointer;-webkit-appearance:none;appearance:none;">
        <option value="<?= buildQuery(['area' => '']) ?>" <?= $filterArea === '' ? 'selected' : '' ?>>全区分</option>
        <option value="<?= buildQuery(['area' => 'general']) ?>" <?= $filterArea === 'general' ? 'selected' : '' ?>>一般</option>
        <option value="<?= buildQuery(['area' => 'admin']) ?>" <?= $filterArea === 'admin' ? 'selected' : '' ?>>管理</option>
      </select>
      <div class="filter-sep"></div>
      <a href="<?= buildQuery(['group' => 'all']) ?>" class="filter-btn <?= $filterGroup === 'all' ? 'active' : '' ?>">全件</a>
      <?php foreach ($statusGroups as $gKey => $gDef): ?>
      <a href="<?= buildQuery(['group' => $gKey]) ?>" class="filter-btn <?= $filterGroup === $gKey ? 'active' : '' ?>"><?= $gDef['label'] ?> (<?= $groupCounts[$gKey] ?>)</a>
      <?php endforeach; ?>
    </div>

    <!-- テーブル -->
    <div class="section-title">一覧（<?= count($todos) ?>件）</div>
    <?php if (empty($todos)): ?>
    <div class="no-data">TODOはありません</div>
    <?php else: ?>
    <table class="todo-table">
      <thead>
        <tr style="cursor:default;">
          <th style="white-space:nowrap;">優先度/対象/ステータス</th>
          <th>更新内容/対応メモ/NG理由</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($todos as $t): ?>
        <tr class="<?= $selItem && $selItem['id'] == $t['id'] ? 'selected' : '' ?>"
            onclick="if(document.getElementById('todo-add-form').style.display!=='none')return;location.href='/admin/todos.php?sel=<?= $t['id'] ?>'">
          <td style="vertical-align:top;white-space:nowrap;width:1%;">
            <span class="badge badge-<?= $t['priority'] ?>"><?= $priorityLabels[$t['priority']] ?? $t['priority'] ?></span>
            <div style="margin-top:4px;">
              <span class="badge badge-site"><?= htmlspecialchars($t['site']) ?></span>
              <span class="badge badge-<?= $t['area'] ?? 'general' ?>" style="margin-left:2px;"><?= $areaLabels[$t['area'] ?? 'general'] ?></span>
            </div>
            <div style="margin-top:5px;font-size:0.8rem;color:#1d2d3a;"><?= htmlspecialchars($t['status']) ?></div>
            <div style="margin-top:6px;font-size:0.72rem;color:#9ba8b5;line-height:1.7;">
              <?php
                $due = $t['due_date'] ?? '';
                if ($due) {
                  $isOverdue = $due < date('Y-m-d') && !in_array($t['status'], ['完了', '保留'], true);
                  echo '<div style="color:' . ($isOverdue ? '#e74c3c' : '#667eea') . ';">📅 ' . htmlspecialchars($due) . '</div>';
                }
              ?>
              <div><?= substr($t['created_at'], 0, 10) ?> 登録</div>
              <?php if (!empty($t['started_at'])): ?>
              <div style="color:#667eea;"><?= substr($t['started_at'], 5, 11) ?> 開始</div>
              <?php endif; ?>
              <?php if (!empty($t['completed_at'])): ?>
              <div style="color:#388e3c;"><?= substr($t['completed_at'], 5, 11) ?> 完了</div>
              <?php endif; ?>
            </div>
          </td>
          <td style="vertical-align:top;">
            <span class="todo-title <?= $t['status'] === '完了' ? 'completed' : '' ?>"><?= htmlspecialchars($t['title']) ?></span>
            <?php if (($t['status_note'] ?? '') !== ''): ?>
            <div class="todo-note"><?= htmlspecialchars($t['status_note']) ?></div>
            <?php endif; ?>
            <?php
              $r = $t['result'] ?? '';
              if (str_starts_with($r, 'NG')):
                $ngText = trim(substr($r, 2), ': ');
            ?>
            <div style="margin-top:5px;padding:5px 9px;background:#fff1f2;border-left:3px solid #fca5a5;border-radius:4px;font-size:0.78rem;color:#e11d48;line-height:1.5;white-space:pre-wrap;">NG<?= $ngText ? ': ' . htmlspecialchars(mb_substr($ngText, 0, 80)) . (mb_strlen($ngText) > 80 ? '…' : '') : '' ?></div>
            <?php elseif ($r !== '' && $r !== 'OK'): ?>
            <!-- AI対応メモを一覧に表示（先頭100文字） -->
            <div style="margin-top:5px;padding:5px 9px;background:#f0f4ff;border-left:3px solid #667eea;border-radius:4px;font-size:0.78rem;color:#3730a3;line-height:1.5;white-space:pre-wrap;"><?= htmlspecialchars(mb_substr($r, 0, 100)) . (mb_strlen($r) > 100 ? '…' : '') ?></div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

  <datalist id="status-list">
    <?php foreach ($statusPresets as $sp): ?>
    <option value="<?= $sp ?>">
    <?php endforeach; ?>
  </datalist>

  <!-- 対応フロー説明 -->
  <div class="flow-chart" style="margin-top:32px;">
    <div style="font-size:0.75rem;font-weight:600;color:#9ba8b5;margin-bottom:10px;">対応フロー</div>
    <div class="flow-main">
      <span class="flow-node">未着手 <span class="flow-who ai">AI</span></span>
      <span class="flow-arrow">→</span>
      <span class="flow-node">対応中 <span class="flow-who ai">AI</span></span>
      <span class="flow-arrow">→</span>
      <span class="flow-node">確認待ち <span class="flow-who admin">管理者</span></span>
      <span class="flow-arrow">→</span>
      <span class="flow-node">完了</span>
    </div>
    <div class="flow-branches">
      <div class="flow-branch">
        <span class="flow-branch-label">保留ルート:</span>
        <span class="flow-v-arrow">↓</span>
        <span class="flow-node">保留 <span class="flow-who admin">管理者</span></span>
        <span class="flow-arrow">→</span>
        <span class="flow-node">未着手 <span class="flow-who ai">AI</span></span>
        <span class="flow-v-arrow">↑</span>
        <span style="font-size:0.68rem;color:#9ba8b5;">キューに戻る</span>
      </div>
      <div class="flow-branch">
        <span class="flow-branch-label">NGルート:</span>
        <span class="flow-v-arrow">↓</span>
        <span class="flow-node">NG <span class="flow-who admin">管理者</span></span>
        <span class="flow-arrow">→</span>
        <span class="flow-node">未着手 <span class="flow-who ai">AI</span></span>
        <span class="flow-v-arrow">↑</span>
        <span style="font-size:0.68rem;color:#9ba8b5;">キューに戻る</span>
      </div>
    </div>
    <div style="margin-top:10px;padding-top:8px;border-top:1px solid #e0e4e8;font-size:0.72rem;color:#9ba8b5;line-height:1.6;">
      <strong style="color:#637080;">ルール:</strong>
      AI対応後は「対応メモ（result）」に変更内容の詳細を必ず記入すること。<br>
      「OK」「完了」等の内容のないメモは禁止。ファイル名・変更箇所・実装内容を具体的に記述する。<br>
      NGの場合は「未着手」に戻してキューに再投入（再対応ステータスは使わない）。
    </div>
    <div class="flow-legend">
      <span><span class="flow-who ai">AI</span> Claude対応</span>
      <span><span class="flow-who admin">管理者</span> あなたが対応</span>
    </div>
  </div>

  </main>
  </div>

  <script src="/assets/js/common.js?v=20260327h"></script>
  <?php if ($selItem): ?>
  <script>document.getElementById('sel-panel').scrollIntoView({ behavior: 'smooth', block: 'start' });</script>
  <?php endif; ?>
</body>
</html>
