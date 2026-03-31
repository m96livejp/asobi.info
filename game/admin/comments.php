<?php
$pageTitle = 'コメント審査';
require_once __DIR__ . '/layout.php';
require_once dirname(__DIR__) . '/api/db.php';

$db = gameDb();
$msg = '';

// ─── 承認・却下 ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['action'])) {
    $id = (int)$_POST['id'];
    $act = $_POST['action'];
    if (in_array($act, ['approved','rejected'], true)) {
        $now = date('Y-m-d H:i:s');
        $db->prepare("UPDATE comments SET status = ?, updated_at = ? WHERE id = ?")
           ->execute([$act, $now, $id]);
        $msg = $act === 'approved' ? '承認しました' : '却下しました';
    }
}

$filter = $_GET['status'] ?? 'pending';
$validFilters = ['pending','approved','rejected','all'];
if (!in_array($filter, $validFilters, true)) $filter = 'pending';

$sql = "SELECT c.*, g.title AS game_title, g.platform FROM comments c JOIN games g ON g.id = c.game_id";
$params = [];
if ($filter !== 'all') {
    $sql .= " WHERE c.status = ?";
    $params[] = $filter;
}
$sql .= " ORDER BY c.created_at DESC LIMIT 100";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$comments = $stmt->fetchAll();

$platformLabels = ['nes'=>'FC','snes'=>'SFC','pce'=>'PCE','md'=>'MD','msx'=>'MSX'];
?>
<div class="page-title">コメント審査</div>

<?php if ($msg): ?>
<div class="msg msg-ok"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap;">
  <?php foreach (['pending'=>'審査待ち','approved'=>'承認済み','rejected'=>'却下済み','all'=>'すべて'] as $s=>$l): ?>
  <a href="?status=<?= $s ?>" class="btn <?= $filter===$s ? 'btn-primary' : '' ?>" style="<?= $filter!==$s ? 'background:var(--bg3);color:var(--text2);' : '' ?>"><?= $l ?></a>
  <?php endforeach; ?>
</div>

<?php if (empty($comments)): ?>
<div class="card"><p style="color:var(--text2);">コメントはありません。</p></div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:12px;">
<?php foreach ($comments as $c): ?>
<div class="card" style="padding:16px;">
  <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">
    <div style="flex:1;min-width:0;">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
        <span class="badge badge-<?= $c['status'] ?>"><?= $c['status'] === 'pending' ? '審査待ち' : ($c['status'] === 'approved' ? '承認済み' : '却下済み') ?></span>
        <span style="font-size:0.78rem;color:var(--text2);"><?= htmlspecialchars($c['created_at']) ?></span>
        <span style="font-size:0.78rem;color:var(--accent);">
          [<?= $platformLabels[$c['platform']] ?? $c['platform'] ?>] <?= htmlspecialchars($c['game_title']) ?>
        </span>
      </div>
      <div style="font-size:0.82rem;color:var(--text2);margin-bottom:6px;">
        投稿者: <strong style="color:var(--text);"><?= htmlspecialchars($c['username']) ?></strong>
        <?php if ($c['ip']): ?>
        &nbsp;IP: <code style="font-size:0.75rem;"><?= htmlspecialchars($c['ip']) ?></code>
        <?php endif; ?>
      </div>
      <div style="font-size:0.9rem;line-height:1.7;white-space:pre-wrap;word-break:break-word;background:var(--bg3);padding:10px 12px;border-radius:6px;"><?= htmlspecialchars($c['content']) ?></div>
    </div>
    <?php if ($c['status'] === 'pending'): ?>
    <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;">
      <form method="post">
        <input type="hidden" name="id" value="<?= $c['id'] ?>">
        <input type="hidden" name="action" value="approved">
        <button type="submit" class="btn btn-success btn-sm">承認</button>
      </form>
      <form method="post">
        <input type="hidden" name="id" value="<?= $c['id'] ?>">
        <input type="hidden" name="action" value="rejected">
        <button type="submit" class="btn btn-danger btn-sm">却下</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

    </main>
  </div>
</body>
</html>
