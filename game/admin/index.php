<?php
$pageTitle = 'ダッシュボード';
require_once __DIR__ . '/layout.php';
require_once dirname(__DIR__) . '/api/db.php';

$db = gameDb();
$totalGames    = (int)$db->query("SELECT COUNT(*) FROM games")->fetchColumn();
$totalComments = (int)$db->query("SELECT COUNT(*) FROM comments")->fetchColumn();
$pendingCount  = (int)$db->query("SELECT COUNT(*) FROM comments WHERE status = 'pending'")->fetchColumn();

$byPlatform = $db->query(
    "SELECT platform, COUNT(*) AS cnt FROM games GROUP BY platform ORDER BY cnt DESC"
)->fetchAll();

$platformLabels = ['nes'=>'ファミコン','snes'=>'スーパーファミコン','pce'=>'PCエンジン','md'=>'メガドライブ','msx'=>'MSX'];
?>
<div class="page-title">ダッシュボード</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:24px;">
  <div class="card" style="text-align:center;">
    <div style="font-size:2rem;font-weight:700;color:var(--accent);"><?= $totalGames ?></div>
    <div style="font-size:0.8rem;color:var(--text2);margin-top:4px;">登録ゲーム数</div>
  </div>
  <div class="card" style="text-align:center;">
    <div style="font-size:2rem;font-weight:700;color:var(--success);"><?= $totalComments ?></div>
    <div style="font-size:0.8rem;color:var(--text2);margin-top:4px;">コメント総数</div>
  </div>
  <div class="card" style="text-align:center;">
    <div style="font-size:2rem;font-weight:700;color:var(--warn);"><?= $pendingCount ?></div>
    <div style="font-size:0.8rem;color:var(--text2);margin-top:4px;">審査待ちコメント</div>
  </div>
</div>

<?php if ($pendingCount > 0): ?>
<div class="msg msg-ok" style="background:rgba(240,160,48,0.12);color:var(--warn);">
  審査待ちコメントが <?= $pendingCount ?> 件あります。
  <a href="/admin/comments.php" style="color:var(--warn);font-weight:700;">コメント審査へ →</a>
</div>
<?php endif; ?>

<div class="card">
  <div style="font-weight:700;margin-bottom:14px;">機種別ゲーム数</div>
  <?php foreach ($byPlatform as $row): ?>
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
    <div style="width:140px;font-size:0.85rem;"><?= htmlspecialchars($platformLabels[$row['platform']] ?? $row['platform']) ?></div>
    <div style="flex:1;background:var(--bg3);border-radius:4px;height:18px;overflow:hidden;">
      <div style="width:<?= $totalGames > 0 ? min(100, round($row['cnt'] / max(1,$totalGames) * 100)) : 0 ?>%;height:100%;background:var(--accent);"></div>
    </div>
    <div style="font-size:0.85rem;width:40px;text-align:right;"><?= $row['cnt'] ?></div>
  </div>
  <?php endforeach; ?>
  <?php if (empty($byPlatform)): ?>
  <p style="color:var(--text2);font-size:0.88rem;">まだゲームが登録されていません。<a href="/admin/games.php" style="color:var(--accent);">ゲームを追加する →</a></p>
  <?php endif; ?>
</div>

    </main>
  </div>
</body>
</html>
