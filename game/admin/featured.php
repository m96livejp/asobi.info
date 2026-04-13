<?php
$pageTitle = '注目ゲーム管理';
require_once __DIR__ . '/layout.php';
require_once dirname(__DIR__) . '/api/db.php';

$db = gameDb();
$msg = '';
$msgType = 'ok';

$platformLabels = ['nes'=>'ファミコン','snes'=>'スーパーファミコン','pce'=>'PCエンジン','md'=>'メガドライブ','msx'=>'MSX'];

// ─── ピン留め追加 ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_act'] ?? '') === 'add') {
    $gameId = (int)($_POST['game_id'] ?? 0);
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    if ($gameId > 0) {
        $game = $db->prepare("SELECT id, platform, title FROM games WHERE id = ?")->execute([$gameId]) ? $db->prepare("SELECT id, platform, title FROM games WHERE id = ?") : null;
        $stmt = $db->prepare("SELECT id, platform, title FROM games WHERE id = ?");
        $stmt->execute([$gameId]);
        $game = $stmt->fetch();
        if ($game) {
            try {
                $db->prepare("INSERT INTO featured_games (game_id, platform, sort_order) VALUES (?, ?, ?)")
                    ->execute([$gameId, $game['platform'], $sortOrder]);
                $msg = "「{$game['title']}」をピン留めしました";
            } catch (PDOException $e) {
                $msg = $e->getCode() == 23000 ? 'このゲームは既にピン留めされています' : $e->getMessage();
                $msgType = 'err';
            }
        } else {
            $msg = 'ゲームが見つかりません';
            $msgType = 'err';
        }
    }
}

// ─── 並び順更新 ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_act'] ?? '') === 'reorder') {
    $ids = $_POST['ids'] ?? [];
    $orders = $_POST['orders'] ?? [];
    $updated = 0;
    for ($i = 0; $i < count($ids); $i++) {
        $db->prepare("UPDATE featured_games SET sort_order = ? WHERE id = ?")
            ->execute([(int)($orders[$i] ?? 0), (int)$ids[$i]]);
        $updated++;
    }
    if ($updated > 0) $msg = "並び順を更新しました";
}

// ─── ピン留め解除 ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_act'] ?? '') === 'remove') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $db->prepare("DELETE FROM featured_games WHERE id = ?")->execute([$id]);
        $msg = 'ピン留めを解除しました';
    }
}

// ─── 現在のピン留め一覧 ───
$filterPlatform = $_GET['platform'] ?? '';
if (!in_array($filterPlatform, array_keys($platformLabels), true)) $filterPlatform = '';

$sql = "SELECT f.id, f.game_id, f.platform, f.sort_order, f.created_at, g.title, g.title_en, g.slug, g.publisher
        FROM featured_games f JOIN games g ON g.id = f.game_id";
$params = [];
if ($filterPlatform) {
    $sql .= " WHERE f.platform = ?";
    $params[] = $filterPlatform;
}
$sql .= " ORDER BY f.platform, f.sort_order, f.created_at";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$featured = $stmt->fetchAll();
?>
<div class="page-title">注目ゲーム管理</div>

<?php if ($msg): ?>
<div class="msg msg-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<p style="font-size:0.85rem;color:var(--text2);margin-bottom:16px;">
  ここでピン留めしたゲームは、各プラットフォームのトップページ「注目のゲーム」欄に優先表示されます。
  ピン留めがない場合はアクセス数ベースで自動選出されます。
</p>

<!-- ゲーム検索＆追加 -->
<div class="card" style="margin-bottom:20px;">
  <div style="font-weight:700;margin-bottom:12px;">ゲームをピン留め</div>
  <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
    <select id="search-platform" style="padding:7px 12px;border-radius:6px;border:1px solid var(--border);background:var(--bg);color:var(--text);font-size:0.85rem;">
      <option value="">全機種</option>
      <?php foreach ($platformLabels as $k => $v): ?>
      <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" id="search-q" placeholder="ゲームタイトルを検索..." style="flex:1;min-width:200px;">
    <button type="button" class="btn btn-primary" id="search-btn">検索</button>
  </div>
  <div id="search-results" style="display:none;max-height:300px;overflow-y:auto;border:1px solid var(--border);border-radius:6px;background:var(--bg);"></div>
</div>

<!-- フィルター -->
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:center;">
  <span style="font-size:0.85rem;color:var(--text2);">表示:</span>
  <a href="?platform=" class="btn btn-sm <?= !$filterPlatform ? 'btn-primary' : '' ?>" style="<?= $filterPlatform ? 'background:var(--bg3);color:var(--text2);' : '' ?>">全機種</a>
  <?php foreach ($platformLabels as $k => $v): ?>
  <a href="?platform=<?= $k ?>" class="btn btn-sm <?= $filterPlatform===$k ? 'btn-primary' : '' ?>" style="<?= $filterPlatform!==$k ? 'background:var(--bg3);color:var(--text2);' : '' ?>"><?= htmlspecialchars($v) ?></a>
  <?php endforeach; ?>
</div>

<!-- ピン留め一覧 -->
<?php if (empty($featured)): ?>
<div class="card" style="text-align:center;color:var(--text2);padding:40px;">
  ピン留めされたゲームはありません。上の検索からゲームを追加してください。
</div>
<?php else: ?>
<form method="post">
  <input type="hidden" name="_act" value="reorder">
  <div class="card" style="padding:0;overflow:hidden;">
    <table>
      <thead>
        <tr>
          <th style="width:60px;">順序</th><th>機種</th><th>タイトル</th><th>メーカー</th><th>追加日</th><th style="width:80px;">操作</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($featured as $f): ?>
      <tr>
        <td>
          <input type="hidden" name="ids[]" value="<?= $f['id'] ?>">
          <input type="number" name="orders[]" value="<?= $f['sort_order'] ?>" style="width:60px;padding:4px 6px;text-align:center;">
        </td>
        <td><span style="font-size:0.78rem;"><?= htmlspecialchars($platformLabels[$f['platform']] ?? $f['platform']) ?></span></td>
        <td>
          <a href="/<?= $f['platform'] ?>/<?= htmlspecialchars($f['slug']) ?>.html" target="_blank" style="color:var(--text);text-decoration:none;">
            <?= htmlspecialchars($f['title']) ?>
          </a>
          <?php if ($f['title_en']): ?>
          <div style="font-size:0.75rem;color:var(--text2);"><?= htmlspecialchars($f['title_en']) ?></div>
          <?php endif; ?>
        </td>
        <td style="font-size:0.85rem;"><?= htmlspecialchars($f['publisher'] ?? '') ?></td>
        <td style="font-size:0.78rem;color:var(--text2);"><?= htmlspecialchars($f['created_at']) ?></td>
        <td>
          <button type="button" class="btn btn-danger btn-sm" onclick="confirmRemove(this, <?= $f['id'] ?>, '<?= htmlspecialchars($f['title'], ENT_QUOTES) ?>')">解除</button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div style="margin-top:12px;display:flex;gap:10px;align-items:center;">
    <button type="submit" class="btn btn-primary">並び順を保存</button>
    <span style="font-size:0.78rem;color:var(--text2);"><?= count($featured) ?> 件ピン留め中</span>
  </div>
</form>
<?php endif; ?>

<!-- 解除確認オーバーレイ -->
<div id="remove-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:1000;align-items:center;justify-content:center;">
  <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:28px;max-width:360px;width:90%;text-align:center;">
    <div style="font-size:1rem;font-weight:700;margin-bottom:8px;">ピン留めを解除しますか？</div>
    <div id="remove-title" style="font-size:0.88rem;color:var(--text2);margin-bottom:20px;"></div>
    <div style="display:flex;gap:10px;justify-content:center;">
      <button onclick="document.getElementById('remove-overlay').style.display='none'" class="btn" style="background:var(--bg3);color:var(--text2);">キャンセル</button>
      <form method="post" id="remove-form">
        <input type="hidden" name="_act" value="remove">
        <input type="hidden" name="id" id="remove-id" value="">
        <button type="submit" class="btn btn-danger">解除する</button>
      </form>
    </div>
  </div>
</div>

<script>
function confirmRemove(btn, id, title) {
  document.getElementById('remove-id').value = id;
  document.getElementById('remove-title').textContent = '「' + title + '」のピン留めを解除します。';
  document.getElementById('remove-overlay').style.display = 'flex';
}

// ゲーム検索
document.getElementById('search-btn').addEventListener('click', doSearch);
document.getElementById('search-q').addEventListener('keydown', function(e) {
  if (e.key === 'Enter') { e.preventDefault(); doSearch(); }
});

async function doSearch() {
  const q = document.getElementById('search-q').value.trim();
  const platform = document.getElementById('search-platform').value;
  const el = document.getElementById('search-results');
  if (!q) { el.style.display = 'none'; return; }

  el.style.display = 'block';
  el.innerHTML = '<div style="padding:12px;color:var(--text2);font-size:0.85rem;">検索中...</div>';

  try {
    const params = new URLSearchParams({ action: 'list', q, limit: '30' });
    if (platform) params.set('platform', platform);
    const res = await fetch('/api/games.php?' + params);
    const data = await res.json();
    if (!data.ok || data.games.length === 0) {
      el.innerHTML = '<div style="padding:12px;color:var(--text2);font-size:0.85rem;">該当するゲームが見つかりません</div>';
      return;
    }

    const labels = <?= json_encode($platformLabels, JSON_UNESCAPED_UNICODE) ?>;
    el.innerHTML = data.games.map(g => `
      <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid var(--border);font-size:0.85rem;">
        <span style="font-size:0.75rem;color:var(--text2);min-width:40px;">${labels[g.platform] || g.platform}</span>
        <span style="flex:1;">${esc(g.title)}${g.title_en ? ' <span style="color:var(--text2);font-size:0.78rem;">' + esc(g.title_en) + '</span>' : ''}</span>
        <form method="post" style="margin:0;">
          <input type="hidden" name="_act" value="add">
          <input type="hidden" name="game_id" value="${g.id}">
          <input type="hidden" name="sort_order" value="0">
          <button type="submit" class="btn btn-success btn-sm">ピン留め</button>
        </form>
      </div>
    `).join('');
  } catch (e) {
    el.innerHTML = '<div style="padding:12px;color:var(--danger);font-size:0.85rem;">エラーが発生しました</div>';
  }
}

function esc(s) {
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}
</script>

    </main>
  </div>
</body>
</html>
