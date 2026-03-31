<?php
$pageTitle = 'ゲーム管理';
require_once __DIR__ . '/layout.php';
require_once dirname(__DIR__) . '/api/db.php';

$db = gameDb();
$msg = '';
$msgType = 'ok';

$platformLabels = ['nes'=>'ファミコン','snes'=>'スーパーファミコン','pce'=>'PCエンジン','md'=>'メガドライブ','msx'=>'MSX'];
$VALID_PLATFORMS = array_keys($platformLabels);

// ─── 追加 ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_act'] ?? '') === 'add') {
    $platform = $_POST['platform'] ?? '';
    $slug     = trim($_POST['slug']  ?? '');
    $title    = trim($_POST['title'] ?? '');

    if (!in_array($platform, $VALID_PLATFORMS, true)) { $msg = '機種が無効です'; $msgType = 'err'; }
    elseif (!preg_match('/^[a-z0-9_\-]{1,80}$/', $slug)) { $msg = 'スラッグは小文字英数字・ハイフン・アンダースコアのみ、80文字以内'; $msgType = 'err'; }
    elseif ($title === '') { $msg = 'タイトルは必須です'; $msgType = 'err'; }
    else {
        try {
            $now = date('Y-m-d H:i:s');
            $releaseDate = trim($_POST['release_date'] ?? '') ?: null;
            $releaseYear = (int)($_POST['release_year'] ?? 0) ?: ($releaseDate ? (int)substr($releaseDate, 0, 4) : null);
            $db->prepare(
                "INSERT INTO games (platform, slug, title, title_en, title_kana, genre, developer, publisher, release_date, release_year, price, players, rom_size, catalog_no, description, sort_order, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $platform, $slug, $title,
                trim($_POST['title_en'] ?? ''),
                trim($_POST['title_kana'] ?? ''),
                trim($_POST['genre'] ?? ''),
                trim($_POST['developer'] ?? ''),
                trim($_POST['publisher'] ?? ''),
                $releaseDate,
                $releaseYear,
                (int)($_POST['price'] ?? 0) ?: null,
                trim($_POST['players'] ?? '') ?: null,
                trim($_POST['rom_size'] ?? '') ?: null,
                trim($_POST['catalog_no'] ?? '') ?: null,
                trim($_POST['description'] ?? ''),
                (int)($_POST['sort_order'] ?? 0),
                $now, $now,
            ]);
            $msg = "「{$title}」を追加しました";
        } catch (PDOException $e) {
            $msg = 'エラー: ' . ($e->getCode() == 23000 ? 'そのスラッグは既に使われています' : $e->getMessage());
            $msgType = 'err';
        }
    }
}

// ─── 削除 ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_act'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $db->prepare("DELETE FROM games WHERE id = ?")->execute([$id]);
        $msg = 'ゲームを削除しました';
    }
}

// ─── 一覧取得 ───
$filterPlatform = $_GET['platform'] ?? '';
if (!in_array($filterPlatform, $VALID_PLATFORMS, true)) $filterPlatform = '';
$q = trim($_GET['q'] ?? '');

$sql = "SELECT id, platform, slug, title, title_en, release_date, release_year, price, genre FROM games WHERE 1=1";
$params = [];
if ($filterPlatform) { $sql .= " AND platform = ?"; $params[] = $filterPlatform; }
if ($q !== '') {
    $sql .= " AND (title LIKE ? OR title_en LIKE ?)";
    $params[] = "%$q%"; $params[] = "%$q%";
}
$sql .= " ORDER BY platform, sort_order, title LIMIT 200";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$games = $stmt->fetchAll();
?>
<div class="page-title">ゲーム管理</div>

<?php if ($msg): ?>
<div class="msg msg-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- 追加フォーム -->
<details class="card" style="margin-bottom:20px;">
  <summary style="cursor:pointer;font-weight:700;font-size:0.95rem;">＋ ゲームを追加</summary>
  <form method="post" style="margin-top:16px;">
    <input type="hidden" name="_act" value="add">
    <div class="form-grid">
      <div class="form-row">
        <label class="form-label">機種 *</label>
        <select name="platform">
          <?php foreach ($platformLabels as $k => $v): ?>
          <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label class="form-label">スラッグ * (URL用: 小文字英数字・ハイフン)</label>
        <input type="text" name="slug" placeholder="super-mario-bros" pattern="[a-z0-9_\-]+">
      </div>
      <div class="form-row">
        <label class="form-label">タイトル（日本語）*</label>
        <input type="text" name="title" placeholder="スーパーマリオブラザーズ">
      </div>
      <div class="form-row">
        <label class="form-label">タイトル（英語）</label>
        <input type="text" name="title_en" placeholder="Super Mario Bros.">
      </div>
      <div class="form-row">
        <label class="form-label">よみがな</label>
        <input type="text" name="title_kana" placeholder="すーぱーまりおぶらざーず">
      </div>
      <div class="form-row">
        <label class="form-label">ジャンル</label>
        <input type="text" name="genre" placeholder="アクション">
      </div>
      <div class="form-row">
        <label class="form-label">開発</label>
        <input type="text" name="developer" placeholder="任天堂">
      </div>
      <div class="form-row">
        <label class="form-label">発売</label>
        <input type="text" name="publisher" placeholder="任天堂">
      </div>
      <div class="form-row">
        <label class="form-label">発売日</label>
        <input type="date" name="release_date" placeholder="1985-09-13">
      </div>
      <div class="form-row">
        <label class="form-label">発売年</label>
        <input type="number" name="release_year" placeholder="1985" min="1975" max="2010">
      </div>
      <div class="form-row">
        <label class="form-label">定価（円）</label>
        <input type="number" name="price" placeholder="4900">
      </div>
      <div class="form-row">
        <label class="form-label">プレイ人数</label>
        <input type="text" name="players" placeholder="1-2">
      </div>
      <div class="form-row">
        <label class="form-label">ROM容量</label>
        <input type="text" name="rom_size" placeholder="2M+256KRAM">
      </div>
      <div class="form-row">
        <label class="form-label">型番</label>
        <input type="text" name="catalog_no" placeholder="HVC-SM">
      </div>
      <div class="form-row">
        <label class="form-label">表示順 (小さい順)</label>
        <input type="number" name="sort_order" value="0">
      </div>
    </div>
    <div class="form-row">
      <label class="form-label">ゲーム紹介文</label>
      <textarea name="description" rows="4" placeholder="ゲームの概要・特徴..."></textarea>
    </div>
    <button type="submit" class="btn btn-primary">追加する</button>
  </form>
</details>

<!-- 検索・フィルター -->
<form method="get" style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
  <select name="platform" style="padding:7px 12px;border-radius:6px;border:1px solid var(--border);background:var(--bg2);color:var(--text);font-size:0.85rem;">
    <option value="">全機種</option>
    <?php foreach ($platformLabels as $k => $v): ?>
    <option value="<?= $k ?>" <?= $filterPlatform===$k ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="タイトル検索..." style="flex:1;min-width:180px;padding:7px 12px;border-radius:6px;border:1px solid var(--border);background:var(--bg2);color:var(--text);font-size:0.85rem;">
  <button type="submit" class="btn btn-primary">検索</button>
</form>

<!-- 一覧 -->
<div class="card" style="padding:0;overflow:hidden;">
  <table>
    <thead>
      <tr>
        <th>機種</th><th>スラッグ</th><th>タイトル</th><th>発売日</th><th>定価</th><th>ジャンル</th><th>操作</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($games)): ?>
    <tr><td colspan="7" style="text-align:center;color:var(--text2);padding:20px;">ゲームが見つかりません</td></tr>
    <?php else: ?>
    <?php foreach ($games as $g): ?>
    <tr>
      <td><span style="font-size:0.78rem;"><?= htmlspecialchars($platformLabels[$g['platform']] ?? $g['platform']) ?></span></td>
      <td><code style="font-size:0.78rem;"><?= htmlspecialchars($g['slug']) ?></code></td>
      <td>
        <a href="/<?= $g['platform'] ?>/<?= htmlspecialchars($g['slug']) ?>.html" target="_blank" style="color:var(--text);text-decoration:none;"><?= htmlspecialchars($g['title']) ?></a>
        <?php if ($g['title_en']): ?>
        <div style="font-size:0.75rem;color:var(--text2);"><?= htmlspecialchars($g['title_en']) ?></div>
        <?php endif; ?>
      </td>
      <td><?= htmlspecialchars($g['release_date'] ?? '') ?: ($g['release_year'] ?: '') ?></td>
      <td><?= $g['price'] ? '&yen;' . number_format($g['price']) : '' ?></td>
      <td><?= htmlspecialchars($g['genre'] ?? '') ?></td>
      <td>
        <form method="post" data-title="<?= htmlspecialchars($g['title'], ENT_QUOTES) ?>">
          <input type="hidden" name="_act" value="delete">
          <input type="hidden" name="id" value="<?= $g['id'] ?>">
          <button type="button" class="btn btn-danger btn-sm" onclick="confirmDelete(this)">削除</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<p style="font-size:0.78rem;color:var(--text2);margin-top:8px;"><?= count($games) ?> 件表示</p>

<!-- 削除確認オーバーレイ -->
<div id="delete-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:1000;align-items:center;justify-content:center;">
  <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:28px;max-width:360px;width:90%;text-align:center;">
    <div style="font-size:1rem;font-weight:700;margin-bottom:8px;">ゲームを削除しますか？</div>
    <div id="delete-title" style="font-size:0.88rem;color:var(--text2);margin-bottom:20px;"></div>
    <div style="display:flex;gap:10px;justify-content:center;">
      <button onclick="document.getElementById('delete-overlay').style.display='none'" class="btn" style="background:var(--bg3);color:var(--text2);">キャンセル</button>
      <button id="delete-confirm-btn" class="btn btn-danger">削除する</button>
    </div>
  </div>
</div>
<script>
let pendingDeleteForm = null;
function confirmDelete(btn) {
  const form = btn.closest('form');
  const title = form.dataset.title;
  pendingDeleteForm = form;
  document.getElementById('delete-title').textContent = '「' + title + '」は完全に削除されます。';
  document.getElementById('delete-overlay').style.display = 'flex';
}
document.getElementById('delete-confirm-btn').addEventListener('click', function() {
  if (pendingDeleteForm) pendingDeleteForm.submit();
});
</script>

    </main>
  </div>
</body>
</html>
