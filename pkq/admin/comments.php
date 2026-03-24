<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/layout.php';

$db = getDb();

// 削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delId = (int)$_POST['delete_id'];
    $db->prepare('UPDATE comments SET status = ? WHERE id = ?')->execute(['deleted', $delId]);
    header('Location: /admin/comments.php?deleted=1');
    exit;
}

// 復元処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_id'])) {
    $restoreId = (int)$_POST['restore_id'];
    $db->prepare('UPDATE comments SET status = ? WHERE id = ?')->execute(['active', $restoreId]);
    header('Location: /admin/comments.php?restored=1');
    exit;
}

// フィルター
$statusFilter = $_GET['status'] ?? 'active';
$pageFilter   = $_GET['page_type'] ?? '';

$where = [];
$params = [];
if ($statusFilter) {
    $where[] = 'c.status = ?';
    $params[] = $statusFilter;
}
if ($pageFilter) {
    $where[] = 'c.page_type = ?';
    $params[] = $pageFilter;
}

$sql = 'SELECT c.* FROM comments c';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY c.created_at DESC LIMIT 200';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$comments = $stmt->fetchAll();

// 統計
$totalActive  = $db->query("SELECT COUNT(*) FROM comments WHERE status='active'")->fetchColumn();
$totalDeleted = $db->query("SELECT COUNT(*) FROM comments WHERE status='deleted'")->fetchColumn();

layout_head('コメント管理', 'comments');

if (isset($_GET['deleted'])) echo '<div class="alert alert-success">コメントを削除しました。</div>';
if (isset($_GET['restored'])) echo '<div class="alert alert-success">コメントを復元しました。</div>';

$pageTypeLabels = [
    'pokemon'     => 'ポケモン詳細',
    'move'        => 'わざ詳細',
    'recipe'      => '料理詳細',
    'report_list' => '料理結果一覧',
];
?>

<div style="display:flex;gap:12px;margin-bottom:16px;">
  <span class="badge badge-blue">有効: <?= $totalActive ?></span>
  <span class="badge badge-gray">削除済: <?= $totalDeleted ?></span>
</div>

<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
  <a href="/admin/comments.php?status=active" class="btn btn-sm <?= $statusFilter === 'active' ? 'btn-primary' : 'btn-secondary' ?>">有効</a>
  <a href="/admin/comments.php?status=deleted" class="btn btn-sm <?= $statusFilter === 'deleted' ? 'btn-primary' : 'btn-secondary' ?>">削除済</a>
  <a href="/admin/comments.php?status=" class="btn btn-sm <?= $statusFilter === '' ? 'btn-primary' : 'btn-secondary' ?>">すべて</a>
  <span style="margin-left:12px;color:#636e72;font-size:0.85rem;align-self:center;">ページ:</span>
  <a href="/admin/comments.php?status=<?= urlencode($statusFilter) ?>&page_type=" class="btn btn-sm <?= $pageFilter === '' ? 'btn-primary' : 'btn-secondary' ?>">全ページ</a>
  <?php foreach ($pageTypeLabels as $k => $v): ?>
  <a href="/admin/comments.php?status=<?= urlencode($statusFilter) ?>&page_type=<?= $k ?>" class="btn btn-sm <?= $pageFilter === $k ? 'btn-primary' : 'btn-secondary' ?>"><?= $v ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header">
    コメント一覧（<?= count($comments) ?>件）
  </div>
  <div class="card-body" style="padding:0;">
    <table class="tbl">
      <thead>
        <tr>
          <th>ID</th>
          <th>ページ</th>
          <th>ユーザー</th>
          <th>コメント</th>
          <th>IP</th>
          <th>環境</th>
          <th>投稿日時</th>
          <th>状態</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($comments)): ?>
        <tr><td colspan="9" style="text-align:center;color:#636e72;padding:40px;">コメントがありません</td></tr>
        <?php else: ?>
        <?php foreach ($comments as $c): ?>
        <tr>
          <td><?= $c['id'] ?></td>
          <td>
            <span class="badge badge-blue"><?= htmlspecialchars($pageTypeLabels[$c['page_type']] ?? $c['page_type']) ?></span>
            <br><small style="color:#636e72;">ID: <?= $c['page_id'] ?></small>
          </td>
          <td>
            <strong><?= htmlspecialchars($c['display_name'] ?: $c['username']) ?></strong>
            <br><small style="color:#636e72;">@<?= htmlspecialchars($c['username']) ?> (uid:<?= $c['user_id'] ?>)</small>
          </td>
          <td style="max-width:300px;white-space:pre-wrap;word-break:break-word;font-size:0.82rem;"><?= htmlspecialchars($c['content']) ?></td>
          <td style="font-size:0.78rem;color:#636e72;"><?= htmlspecialchars($c['ip'] ?? '') ?></td>
          <td style="font-size:0.78rem;color:#636e72;">
            <?= htmlspecialchars($c['os'] ?? '') ?><br><?= htmlspecialchars($c['browser'] ?? '') ?>
          </td>
          <td style="font-size:0.78rem;white-space:nowrap;"><?= $c['created_at'] ?></td>
          <td>
            <?php if ($c['status'] === 'active'): ?>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="delete_id" value="<?= $c['id'] ?>">
              <button type="submit" class="badge badge-blue" style="cursor:pointer;border:none;">有効</button>
            </form>
            <?php else: ?>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="restore_id" value="<?= $c['id'] ?>">
              <button type="submit" class="badge badge-gray" style="cursor:pointer;border:none;">削除済み</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_foot(); ?>
