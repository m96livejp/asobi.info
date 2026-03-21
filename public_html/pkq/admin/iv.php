<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$db = getDb();

// ---- テーブルが無ければ作成 ----
$db->exec("
    CREATE TABLE IF NOT EXISTS iv_observations (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        pokemon_id  INTEGER NOT NULL,
        pot_type    TEXT    NOT NULL,
        hp          INTEGER NOT NULL,
        atk         INTEGER NOT NULL,
        memo        TEXT    NOT NULL DEFAULT '',
        created_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (pokemon_id) REFERENCES pokemon(pokedex_no)
    )
");
$db->exec("
    CREATE TABLE IF NOT EXISTS iv_reports (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        pokemon_id INTEGER NOT NULL,
        pot_type   TEXT    NOT NULL,
        quality    TEXT    NOT NULL,
        level      INTEGER NOT NULL DEFAULT 100,
        hp         INTEGER NOT NULL,
        atk        INTEGER NOT NULL,
        user_id    INTEGER,
        username   TEXT,
        ip         TEXT,
        memo       TEXT    NOT NULL DEFAULT '',
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (pokemon_id) REFERENCES pokemon(pokedex_no)
    )
");

// ---- 鍋の中間値 ----
$pot_mid = ['鉄' => 5, '銅' => 75, '銀' => 200, '金' => 350];
$pot_range = ['鉄' => '0〜10', '銅' => '50〜100', '銀' => '150〜250', '金' => '300〜400'];

// ---- 品質ティア ----
$tiers = [
    ['key' => 'special',   'label' => 'スペシャル', 'stars' => '★★★★', 'color' => '#8e44ad', 'min' => 4.0, 'max' => PHP_INT_MAX],
    ['key' => 'very_good', 'label' => 'すごくいい', 'stars' => '★★★',  'color' => '#2980b9', 'min' => 2.5, 'max' => 4.0],
    ['key' => 'good',      'label' => 'いい',       'stars' => '★★',   'color' => '#27ae60', 'min' => 1.5, 'max' => 2.5],
    ['key' => 'normal',    'label' => 'ふつう',     'stars' => '★',    'color' => '#95a5a6', 'min' => 0.0, 'max' => 1.5],
];

function get_quality(float $ratio, array $tiers): array {
    foreach ($tiers as $t) {
        if ($ratio >= $t['min']) return $t;
    }
    return $tiers[3]; // ふつう
}

// ---- 例外判定 ----
// ratio < 0: 実測値が鍋の中間値を下回る（理論上あり得ない）
// ratio > 8: 異常に高い（データ入力ミスの疑い）
function is_exception(float $ratio): bool {
    return $ratio < 0 || $ratio > 8;
}

$msg = '';
$msg_type = '';

// ---- 登録処理 ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $pokemon_id = (int)($_POST['pokemon_id'] ?? 0);
        $pot_type   = $_POST['pot_type'] ?? '';
        $hp         = (int)($_POST['hp']  ?? 0);
        $atk        = (int)($_POST['atk'] ?? 0);
        $memo       = trim($_POST['memo'] ?? '');

        if ($pokemon_id && in_array($pot_type, array_keys($pot_mid)) && $hp > 0 && $atk > 0) {
            $stmt = $db->prepare('INSERT INTO iv_observations (pokemon_id, pot_type, hp, atk, memo) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$pokemon_id, $pot_type, $hp, $atk, $memo]);
            $msg = 'データを登録しました。';
            $msg_type = 'success';
        } else {
            $msg = '入力値が不正です。ポケモン・鍋種別・HP・ATKを正しく入力してください。';
            $msg_type = 'error';
        }
    } elseif ($_POST['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare('DELETE FROM iv_observations WHERE id=?')->execute([$id]);
            $msg = 'データを削除しました。';
            $msg_type = 'success';
        }
    }
}

// ---- ポケモン一覧取得 ----
$pokemon_list = $db->query('SELECT pokedex_no, name, base_hp, base_atk FROM pokemon ORDER BY pokedex_no')->fetchAll();
$pokemon_map  = [];
foreach ($pokemon_list as $p) {
    $pokemon_map[(int)$p['pokedex_no']] = $p;
}

// ---- 観測データ一覧取得 ----
$observations = $db->query('
    SELECT o.*, p.name AS pokemon_name, p.base_hp, p.base_atk
    FROM iv_observations o
    JOIN pokemon p ON o.pokemon_id = p.pokedex_no
    ORDER BY o.created_at DESC
')->fetchAll();

// ---- ユーザー投稿データ取得 ----
$reports = $db->query("
    SELECT r.*, p.name AS pokemon_name, p.base_hp, p.base_atk
    FROM iv_reports r
    JOIN pokemon p ON r.pokemon_id = p.pokedex_no
    ORDER BY r.created_at DESC
    LIMIT 200
")->fetchAll();
$report_total = $db->query('SELECT COUNT(*) FROM iv_reports')->fetchColumn();

// ---- 例外件数カウント ----
$exception_count = 0;
foreach ($observations as $obs) {
    $mid        = $pot_mid[$obs['pot_type']] ?? 0;
    $base_hp    = (int)$obs['base_hp'];
    $base_atk   = (int)$obs['base_atk'];
    $ratio_hp   = $base_hp  > 0 ? ((int)$obs['hp']  - $mid) / $base_hp  : -1;
    $ratio_atk  = $base_atk > 0 ? ((int)$obs['atk'] - $mid) / $base_atk : -1;
    if (is_exception($ratio_hp) || is_exception($ratio_atk)) {
        $exception_count++;
    }
}

layout_head('個体値データ管理', 'iv');
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msg_type === 'success' ? 'success' : 'error' ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- 例外アラート -->
<?php if ($exception_count > 0): ?>
<div class="alert" style="background:#fff3cd;color:#856404;border:1px solid #ffc107;display:flex;align-items:center;gap:10px;">
    <span style="font-size:1.3rem;">⚠️</span>
    <div>
        <strong>例外データが <?= $exception_count ?> 件あります。</strong>
        <span style="font-size:0.85rem;margin-left:8px;">暫定閾値の範囲外（ratio &lt; 0 または ratio &gt; 8）のデータを確認してください。</span>
    </div>
</div>
<?php endif; ?>

<!-- サマリーカード -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;">
  <div class="card" style="padding:20px;text-align:center;">
    <div style="font-size:1.8rem;margin-bottom:6px;">📊</div>
    <div style="font-size:2rem;font-weight:700;color:#e17055;"><?= count($observations) ?></div>
    <div style="color:#636e72;font-size:0.82rem;margin-top:4px;">総観測データ数</div>
  </div>
  <div class="card" style="padding:20px;text-align:center;">
    <div style="font-size:1.8rem;margin-bottom:6px;">⚠️</div>
    <div style="font-size:2rem;font-weight:700;color:<?= $exception_count > 0 ? '#e17055' : '#00b894' ?>;"><?= $exception_count ?></div>
    <div style="color:#636e72;font-size:0.82rem;margin-top:4px;">例外データ数</div>
  </div>
  <div class="card" style="padding:20px;text-align:center;">
    <div style="font-size:1.8rem;margin-bottom:6px;">🔬</div>
    <div style="font-size:2rem;font-weight:700;color:#636e72;"><?= count($pokemon_list) ?></div>
    <div style="color:#636e72;font-size:0.82rem;margin-top:4px;">登録ポケモン数</div>
  </div>
  <div class="card" style="padding:20px;text-align:center;">
    <div style="font-size:1.8rem;margin-bottom:6px;">📝</div>
    <div style="font-size:2rem;font-weight:700;color:#00b894;"><?= $report_total ?></div>
    <div style="color:#636e72;font-size:0.82rem;margin-top:4px;">ユーザー投稿数</div>
  </div>
</div>

<!-- 品質ティア参考（暫定値） -->
<div class="card" style="margin-bottom:24px;">
  <div class="card-header">
    品質ティア（暫定値）
    <span style="font-size:0.75rem;color:#e17055;font-weight:400;">※ ratio = (実測値 − 鍋中間値) ÷ 種族値ベース</span>
  </div>
  <div class="card-body" style="padding:16px;">
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <?php foreach ($tiers as $t): ?>
      <div style="flex:1;min-width:120px;padding:12px;border-radius:8px;border:2px solid <?= $t['color'] ?>;text-align:center;">
        <div style="color:<?= $t['color'] ?>;font-weight:700;"><?= $t['label'] ?></div>
        <div style="font-size:0.85rem;color:#888;margin-top:4px;"><?= $t['stars'] ?></div>
        <div style="font-size:0.8rem;color:#636e72;margin-top:4px;">
          ratio: <?php
            if ($t['max'] === PHP_INT_MAX) echo '≥ ' . number_format($t['min'],1);
            elseif ($t['min'] == 0) echo '0 〜 ' . number_format($t['max'],1);
            else echo number_format($t['min'],1) . ' 〜 ' . number_format($t['max'],1);
          ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- 登録フォーム -->
<div class="card" style="margin-bottom:24px;">
  <div class="card-header">新規データ登録</div>
  <div class="card-body">
    <form method="POST" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
      <input type="hidden" name="action" value="add">

      <div class="form-group" style="grid-column:1/-1;">
        <label class="form-label">ポケモン</label>
        <select name="pokemon_id" class="form-control" required>
          <option value="">-- 選択してください --</option>
          <?php foreach ($pokemon_list as $p): ?>
          <option value="<?= $p['pokedex_no'] ?>">
            No.<?= str_pad($p['pokedex_no'], 3, '0', STR_PAD_LEFT) ?> <?= htmlspecialchars($p['name']) ?>
            (HP種族値:<?= $p['base_hp'] ?? '?' ?> / ATK種族値:<?= $p['base_atk'] ?? '?' ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">鍋の種別</label>
        <select name="pot_type" class="form-control" required>
          <option value="">-- 選択 --</option>
          <?php foreach ($pot_mid as $pot => $mid): ?>
          <option value="<?= $pot ?>"><?= $pot ?>鍋（ボーナス<?= $pot_range[$pot] ?>）</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Lv.100 HP（実測値）</label>
        <input type="number" name="hp" class="form-control" required min="1" max="99999" placeholder="例: 3500">
      </div>

      <div class="form-group">
        <label class="form-label">Lv.100 ATK（実測値）</label>
        <input type="number" name="atk" class="form-control" required min="1" max="99999" placeholder="例: 3200">
      </div>

      <div class="form-group" style="grid-column:1/-1;">
        <label class="form-label">メモ（任意）</label>
        <input type="text" name="memo" class="form-control" placeholder="例: 石版3枚使用, 特定ワザで確認" maxlength="200">
      </div>

      <div style="grid-column:1/-1;">
        <button type="submit" class="btn btn-primary">登録する</button>
      </div>
    </form>
  </div>
</div>

<!-- 観測データ一覧 -->
<div class="card">
  <div class="card-header">
    観測データ一覧
    <span style="font-size:0.8rem;color:#636e72;font-weight:400;">
      ⚠️ 例外行はオレンジ色でハイライトされます
    </span>
  </div>
  <?php if (empty($observations)): ?>
  <div class="card-body" style="color:#636e72;text-align:center;padding:40px;">
    まだデータが登録されていません
  </div>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table class="tbl">
      <thead>
        <tr>
          <th>日時</th>
          <th>ポケモン</th>
          <th>鍋</th>
          <th>HP実測</th>
          <th>HP品質</th>
          <th>ATK実測</th>
          <th>ATK品質</th>
          <th>メモ</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($observations as $obs):
            $mid      = $pot_mid[$obs['pot_type']] ?? 0;
            $base_hp  = (int)($obs['base_hp']  ?? 0);
            $base_atk = (int)($obs['base_atk'] ?? 0);

            $ratio_hp  = $base_hp  > 0 ? ((int)$obs['hp']  - $mid) / $base_hp  : -1;
            $ratio_atk = $base_atk > 0 ? ((int)$obs['atk'] - $mid) / $base_atk : -1;

            $q_hp  = get_quality($ratio_hp,  $tiers);
            $q_atk = get_quality($ratio_atk, $tiers);

            $exc_hp  = is_exception($ratio_hp);
            $exc_atk = is_exception($ratio_atk);
            $is_exc  = $exc_hp || $exc_atk;

            $row_bg = $is_exc ? 'background:#fff8e1;' : '';
        ?>
        <tr style="<?= $row_bg ?>">
          <td style="color:#636e72;font-size:0.8rem;white-space:nowrap;">
            <?= htmlspecialchars(substr($obs['created_at'], 0, 16)) ?>
          </td>
          <td>
            <span style="color:#636e72;font-size:0.78rem;">No.<?= str_pad($obs['pokemon_id'], 3, '0', STR_PAD_LEFT) ?></span><br>
            <strong><?= htmlspecialchars($obs['pokemon_name']) ?></strong>
          </td>
          <td>
            <?php
            $pot_colors = ['鉄'=>'#888','銅'=>'#b87333','銀'=>'#aaa','金'=>'#d4af37'];
            $c = $pot_colors[$obs['pot_type']] ?? '#888';
            ?>
            <span style="display:inline-block;padding:2px 8px;border-radius:10px;background:<?= $c ?>;color:#fff;font-size:0.8rem;font-weight:600;"><?= htmlspecialchars($obs['pot_type']) ?></span>
          </td>
          <td>
            <strong><?= number_format($obs['hp']) ?></strong>
            <?php if ($exc_hp): ?>
            <span style="color:#e17055;font-size:0.75rem;margin-left:4px;">⚠️例外</span>
            <?php endif; ?>
            <div style="font-size:0.75rem;color:#636e72;">ratio: <?= number_format($ratio_hp, 2) ?></div>
          </td>
          <td>
            <span style="display:inline-block;padding:2px 8px;border-radius:10px;background:<?= $q_hp['color'] ?>;color:#fff;font-size:0.78rem;font-weight:600;">
              <?= $q_hp['label'] ?>
            </span>
            <div style="font-size:0.75rem;color:#636e72;"><?= $q_hp['stars'] ?></div>
          </td>
          <td>
            <strong><?= number_format($obs['atk']) ?></strong>
            <?php if ($exc_atk): ?>
            <span style="color:#e17055;font-size:0.75rem;margin-left:4px;">⚠️例外</span>
            <?php endif; ?>
            <div style="font-size:0.75rem;color:#636e72;">ratio: <?= number_format($ratio_atk, 2) ?></div>
          </td>
          <td>
            <span style="display:inline-block;padding:2px 8px;border-radius:10px;background:<?= $q_atk['color'] ?>;color:#fff;font-size:0.78rem;font-weight:600;">
              <?= $q_atk['label'] ?>
            </span>
            <div style="font-size:0.75rem;color:#636e72;"><?= $q_atk['stars'] ?></div>
          </td>
          <td style="font-size:0.82rem;color:#636e72;max-width:200px;">
            <?= htmlspecialchars($obs['memo']) ?>
          </td>
          <td>
            <form method="POST" onsubmit="return confirm('このデータを削除しますか？')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $obs['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">削除</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ユーザー投稿一覧 -->
<div class="card" style="margin-top:8px;">
  <div class="card-header">
    ユーザー投稿データ（最新200件 / 計 <?= $report_total ?> 件）
    <a href="https://pkq.asobi.info/iv-report.php" target="_blank" class="btn btn-sm btn-primary">投稿ページを見る</a>
  </div>
  <?php if (empty($reports)): ?>
  <div class="card-body" style="color:#636e72;text-align:center;padding:40px;">
    まだユーザー投稿がありません
  </div>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table class="tbl">
      <thead>
        <tr>
          <th>日時</th>
          <th>ポケモン</th>
          <th>鍋</th>
          <th>品質</th>
          <th>Lv</th>
          <th>HP</th>
          <th>ATK</th>
          <th>HP ratio</th>
          <th>ATK ratio</th>
          <th>投稿者</th>
          <th>メモ</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $q_colors = ['スペシャル'=>'#8e44ad','すごくいい'=>'#2980b9','いい'=>'#27ae60','ふつう'=>'#95a5a6'];
        $p_colors = ['鉄'=>'#888','銅'=>'#b87333','銀'=>'#aaa','金'=>'#d4af37'];
        foreach ($reports as $r):
            $mid      = $pot_mid[$r['pot_type']] ?? 0;
            $base_hp  = (int)($r['base_hp']  ?? 0);
            $base_atk = (int)($r['base_atk'] ?? 0);
            $r_hp  = $base_hp  > 0 ? ((int)$r['hp']  - $mid) / $base_hp  : -1;
            $r_atk = $base_atk > 0 ? ((int)$r['atk'] - $mid) / $base_atk : -1;
            $exc   = ($r_hp < 0 || $r_hp > 8 || $r_atk < 0 || $r_atk > 8);
            $qc = $q_colors[$r['quality']] ?? '#888';
            $pc = $p_colors[$r['pot_type']] ?? '#888';
        ?>
        <tr style="<?= $exc ? 'background:#fff8e1;' : '' ?>">
          <td style="color:#636e72;font-size:0.78rem;white-space:nowrap;"><?= htmlspecialchars(substr($r['created_at'],0,16)) ?></td>
          <td>
            <span style="color:#636e72;font-size:0.75rem;">No.<?= str_pad($r['pokemon_id'],3,'0',STR_PAD_LEFT) ?></span><br>
            <strong style="font-size:0.85rem;"><?= htmlspecialchars($r['pokemon_name']) ?></strong>
          </td>
          <td><span style="display:inline-block;padding:2px 7px;border-radius:10px;background:<?= $pc ?>;color:#fff;font-size:0.78rem;font-weight:600;"><?= htmlspecialchars($r['pot_type']) ?></span></td>
          <td><span style="display:inline-block;padding:2px 7px;border-radius:10px;background:<?= $qc ?>;color:#fff;font-size:0.78rem;font-weight:600;"><?= htmlspecialchars($r['quality']) ?></span></td>
          <td style="font-size:0.82rem;color:#636e72;">Lv.<?= $r['level'] ?></td>
          <td><strong><?= number_format($r['hp']) ?></strong></td>
          <td><strong><?= number_format($r['atk']) ?></strong></td>
          <td style="font-size:0.8rem;color:<?= $r_hp < 0 || $r_hp > 8 ? '#e17055' : '#636e72' ?>;">
            <?= number_format($r_hp, 2) ?><?= ($r_hp < 0 || $r_hp > 8) ? ' ⚠️' : '' ?>
          </td>
          <td style="font-size:0.8rem;color:<?= $r_atk < 0 || $r_atk > 8 ? '#e17055' : '#636e72' ?>;">
            <?= number_format($r_atk, 2) ?><?= ($r_atk < 0 || $r_atk > 8) ? ' ⚠️' : '' ?>
          </td>
          <td style="font-size:0.82rem;">
            <?php if ($r['username']): ?>
            <span style="color:#2d3436;">👤 <?= htmlspecialchars($r['username']) ?></span>
            <?php else: ?>
            <span style="color:#b2bec3;">👻 ゲスト</span>
            <?php endif; ?>
          </td>
          <td style="font-size:0.78rem;color:#636e72;max-width:160px;"><?= htmlspecialchars($r['memo']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- 計算式の説明 -->
<div class="card" style="margin-top:8px;">
  <div class="card-header">計算式・判定ロジックについて（暫定）</div>
  <div class="card-body" style="font-size:0.88rem;color:#636e72;line-height:1.8;">
    <p><strong>ratio</strong> = (実測値 − 鍋中間値) ÷ 種族値ベース</p>
    <p style="margin-top:8px;"><strong>例外判定</strong>：ratio &lt; 0（実測値が鍋ボーナス中間値を下回る）または ratio &gt; 8（異常に高い値）</p>
    <p style="margin-top:8px;color:#e17055;"><strong>注意：</strong>これらの閾値は暫定値です。実データ収集が進んだ段階でしきい値を見直してください。</p>
    <p style="margin-top:8px;">鍋中間値：鉄=5 / 銅=75 / 銀=200 / 金=350</p>
  </div>
</div>

<?php layout_foot(); ?>
