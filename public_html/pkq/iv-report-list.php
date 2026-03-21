<?php
// 認証（任意）
$currentUserId = null;
$currentIp     = 'unknown';
try {
    require_once '/home/m96/asobi.info/public_html/assets/php/auth.php';
    if (!empty($_SESSION['asobi_user_id'])) {
        $currentUserId = (int)$_SESSION['asobi_user_id'];
    }
} catch (Exception $e) {}

function listGetClientIp(): string {
    foreach (['HTTP_X_FORWARDED_FOR','HTTP_CLIENT_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) return explode(',', $_SERVER[$k])[0];
    }
    return 'unknown';
}
$currentIp = listGetClientIp();

require_once __DIR__ . '/api/db.php';
$db = getDb();

$quality_colors = [
    'スペシャル' => '#8e44ad',
    'すごくいい' => '#2980b9',
    'いい'       => '#27ae60',
    'ふつう'     => '#95a5a6',
];
$pot_colors = ['鉄'=>'#888','銅'=>'#b87333','銀'=>'#aaa','金'=>'#d4af37'];
$charm_labels = ['atk'=>'✊','hp'=>'❤','both'=>'✊❤',''=>'？'];

// ---- 個体値計算 ----
$pot_mid = ['鉄'=>5,'銅'=>75,'銀'=>200,'金'=>350];
$iv_tiers = [
    ['label'=>'スペシャル','color'=>'#8e44ad','min'=>4.0],
    ['label'=>'すごくいい','color'=>'#2980b9','min'=>2.5],
    ['label'=>'いい',       'color'=>'#27ae60','min'=>1.5],
    ['label'=>'ふつう',     'color'=>'#95a5a6','min'=>0],
];
function calcIvTier(int $actual, int $base, string $pot, int $level, array $pot_mid, array $tiers): array {
    if ($base <= 0 || $level <= 0) return ['label'=>'—','color'=>'#555','ratio'=>null];
    $mid   = $pot_mid[$pot] ?? 5;
    $ratio = ($actual - $mid) / $base * (100 / $level);
    foreach ($tiers as $t) {
        if ($ratio >= $t['min']) return array_merge($t, ['ratio'=>$ratio]);
    }
    return array_merge(end($tiers), ['ratio'=>$ratio]);
}

$type_colors = [
    'ノーマル'=>'#9e9e9e','ほのお'=>'#e74c3c','みず'=>'#2980b9','くさ'=>'#27ae60',
    'でんき'=>'#f1c40f','こおり'=>'#55d1e8','かくとう'=>'#7f5233','どく'=>'#9c27b0',
    'じめん'=>'#cddc39','ひこう'=>'#90caf9','エスパー'=>'#e91e8c','むし'=>'#8bc34a',
    'いわ'=>'#8d6e63','ゴースト'=>'#5c6bc0','ドラゴン'=>'#3949ab','あく'=>'#546e7a',
    'はがね'=>'#90a4ae','フェアリー'=>'#f48fb1',
];

// ---- レシピ名→画像パスマップ ----
$recipe_images = [];
foreach ($db->query("SELECT DISTINCT name, image_path FROM recipes WHERE image_path IS NOT NULL") as $row) {
    $recipe_images[$row['name']] = $row['image_path'];
}

// ---- 最近の投稿（100件）----
$recent = $db->query("
    SELECT r.*, p.name AS pokemon_name, p.base_hp, p.base_atk, p.type1, p.type2, p.ranged
    FROM iv_reports r
    JOIN pokemon p ON r.pokemon_id = p.pokedex_no
    ORDER BY r.created_at DESC
    LIMIT 100
")->fetchAll();

// ---- 集計：ポケモン別報告数 ----
$stats = $db->query("
    SELECT p.name, p.pokedex_no, COUNT(*) as cnt
    FROM iv_reports r
    JOIN pokemon p ON r.pokemon_id = p.pokedex_no
    GROUP BY r.pokemon_id ORDER BY cnt DESC LIMIT 10
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>投稿一覧 - 料理結果 - ポケモンクエスト情報</title>
  <link rel="stylesheet" href="https://asobi.info/assets/css/common.css">
  <link rel="stylesheet" href="/css/style.css">
  <style>
    .report-card {
      background: var(--bg-secondary);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 20px;
      margin-bottom: 16px;
    }
    .section-title {
      font-size: 0.95rem;
      font-weight: 700;
      color: var(--accent);
      margin: 0 0 14px;
      padding-bottom: 8px;
      border-bottom: 1px solid var(--border);
    }

    .report-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.83rem;
    }
    .report-table th {
      text-align: left;
      padding: 8px 8px;
      color: var(--text-secondary);
      font-weight: 600;
      border-bottom: 2px solid var(--border);
      font-size: 0.75rem;
      white-space: nowrap;
    }
    .report-table td {
      padding: 8px 8px;
      border-bottom: 1px solid var(--border);
      vertical-align: middle;
    }
    .report-table tr:last-child td { border-bottom: none; }
    .report-table tr:hover td { background: var(--bg-primary); }

    .quality-badge, .pot-badge {
      display: inline-block;
      padding: 2px 7px;
      border-radius: 10px;
      font-size: 0.73rem;
      font-weight: 600;
      color: #fff;
      white-space: nowrap;
    }

    /* ミニステータスカード */
    .mini-stat-card {
      display: inline-flex;
      align-items: stretch;
      gap: 5px;
      background: #f5c842;
      border: 2px solid #e67e22;
      border-radius: 10px;
      padding: 5px 6px;
      white-space: nowrap;
    }
    .mini-lv-box {
      background: #e67e22;
      border-radius: 6px;
      padding: 3px 6px;
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      min-width: 2.4em;
    }
    .mini-lv-label {
      font-size: 0.55rem;
      font-weight: 700;
      color: rgba(255,255,255,0.85);
      line-height: 1;
    }
    .mini-lv-val {
      font-size: 0.95rem;
      font-weight: 800;
      color: #fff;
      line-height: 1.2;
    }
    .mini-hp-atk {
      display: flex;
      flex-direction: column;
      gap: 3px;
    }
    .mini-hp-row, .mini-atk-row {
      display: flex;
      align-items: center;
      gap: 4px;
      border-radius: 5px;
      padding: 2px 6px;
    }
    .mini-hp-row  { background: #2980b9; }
    .mini-atk-row { background: #e74c3c; }
    .mini-stat-label {
      font-size: 0.62rem;
      font-weight: 700;
      color: #fff;
      width: 32px;
      flex-shrink: 0;
    }
    .mini-stat-val {
      font-size: 0.82rem;
      font-weight: 800;
      color: #fff;
      width: 46px;
      text-align: right;
      font-variant-numeric: tabular-nums;
      flex-shrink: 0;
    }
    .mini-stat-ratio {
      font-size: 0.7rem;
      font-weight: 700;
      min-width: 28px;
      text-align: right;
    }

    /* カード右列（属性・遠近・色違い） */
    .mini-card-right {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 3px;
      justify-content: flex-start;
      padding-left: 2px;
    }
    .type-dot {
      width: 24px;
      height: 9px;
      border-radius: 3px;
      flex-shrink: 0;
    }
    .mini-ranged { font-size: 0.75rem; line-height: 1; }
    .mini-shiny  { font-size: 0.8rem; color: #e91e8c; line-height: 1; }

    /* Pチャームミニグリッド */
    .charm-mini {
      display: grid;
      grid-template-columns: repeat(3, 14px);
      gap: 2px;
    }
    .charm-mini-cell {
      width: 14px; height: 14px;
      border-radius: 3px;
      background: #444;
    }
    .charm-mini-cell.c-atk  { background: #e74c3c; }
    .charm-mini-cell.c-hp   { background: #2980b9; }
    .charm-mini-cell.c-both { background: linear-gradient(135deg, #e74c3c 50%, #2980b9 50%); }

    .stats-list { display: flex; flex-direction: column; gap: 6px; }
    .stats-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 4px 10px;
      background: var(--bg-primary);
      border-radius: 16px;
      font-size: 0.82rem;
    }
    .stats-item .cnt { font-weight: 700; color: var(--accent); }

    .reporter-name { display: flex; align-items: center; gap: 4px; font-size: 0.8rem; }
    .back-link {
      display: inline-block;
      margin-bottom: 16px;
      color: var(--accent);
      font-size: 0.88rem;
      text-decoration: none;
    }
    .back-link:hover { text-decoration: underline; }
    .edit-btn, .copy-btn {
      display: inline-block;
      padding: 3px 10px;
      border-radius: 6px;
      font-size: 0.75rem;
      font-weight: 600;
      text-decoration: none;
      white-space: nowrap;
    }
    .edit-btn {
      border: 1.5px solid var(--accent);
      color: var(--accent);
    }
    .edit-btn:hover { background: var(--accent); color: #fff; }
    .copy-btn {
      border: 1.5px solid #27ae60;
      color: #27ae60;
    }
    .copy-btn:hover { background: #27ae60; color: #fff; }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="container">
      <a href="/" class="site-logo">ポケクエ<span>.asobi.info</span></a>
      <button class="menu-toggle">&#9776;</button>
      <nav class="site-nav">
        <ul>
          <li><a href="/pokemon-list.html">ポケモン一覧</a></li>
          <li><a href="/recipes.html">料理一覧</a></li>
          <li><a href="/moves.html">わざ一覧</a></li>
          <li><a href="/simulator.html">料理シミュレーター</a></li>
          <li><a href="/iv-checker.html">個体値チェッカー</a></li>
          <li><a href="/iv-report.php" class="active">結果を投稿</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main class="container">
    <div class="page-header">
      <div class="breadcrumb">
        <a href="/">ポケクエ</a><span><a href="/iv-report.php">料理結果を投稿</a></span><span>投稿一覧</span>
      </div>
      <h1>投稿一覧</h1>
      <p>みんなの料理結果データ（最新100件）</p>
    </div>

    <?php if (isset($_GET['registered'])): ?>
    <div style="background:rgba(39,174,96,0.12);color:#1a6b3a;border:1px solid #27ae60;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-weight:600;">
      データを登録しました！ご協力ありがとうございます。
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
    <div style="background:rgba(39,174,96,0.12);color:#1a6b3a;border:1px solid #27ae60;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-weight:600;">
      投稿を削除しました。
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['updated'])): ?>
    <div style="background:rgba(39,174,96,0.12);color:#1a6b3a;border:1px solid #27ae60;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-weight:600;">
      投稿を更新しました。
    </div>
    <?php endif; ?>

    <a href="/iv-report.php" class="back-link">← 結果を投稿する</a>

    <div class="report-card" style="overflow-x:auto;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
        <p class="section-title" style="margin-bottom:0;border-bottom:none;">🕐 最近の投稿（<span id="visible-count"><?= count($recent) ?></span>件）</p>
        <?php if ($currentUserId || $currentIp !== 'unknown'): ?>
        <button id="mine-toggle" onclick="toggleMine()"
          style="padding:5px 14px;border:1.5px solid var(--accent);border-radius:20px;background:transparent;color:var(--accent);font-size:0.82rem;font-weight:600;cursor:pointer;">
          自分の投稿だけ表示
        </button>
        <?php endif; ?>
      </div>
      <?php if (empty($recent)): ?>
      <p style="color:var(--text-secondary);font-size:0.9rem;padding:20px 0;">まだ投稿がありません。最初の投稿者になりましょう！</p>
      <?php else: ?>
      <table class="report-table">
        <thead>
          <tr>
            <th>料理</th>
            <th>鍋/品質</th>
            <th>ポケモン</th>
            <th>ステータス</th>
            <th>技</th>
            <th>チャーム</th>
            <th>投稿者</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $r):
            $qc = $quality_colors[$r['quality']] ?? '#888';
            $pc = $pot_colors[$r['pot_type']] ?? '#888';
            $cells = array_pad(explode(',', $r['pcharm'] ?? ''), 9, '');
            $isOwn = false;
            if ($currentUserId && (int)$r['user_id'] === $currentUserId) $isOwn = true;
            elseif (!$currentUserId && !$r['user_id'] && $r['ip'] === $currentIp) $isOwn = true;
            $lv = max(1, (int)($r['level'] ?? 100));
            $hpTier  = calcIvTier((int)$r['hp'],  (int)$r['base_hp'],  $r['pot_type'], $lv, $pot_mid, $iv_tiers);
            $atkTier = calcIvTier((int)$r['atk'], (int)$r['base_atk'], $r['pot_type'], $lv, $pot_mid, $iv_tiers);
            $types = array_filter([$r['type1'] ?? '', $r['type2'] ?? '']);
            $isShiny = !empty($r['is_shiny']);
            $isRanged = !empty($r['ranged']);
          ?>
          <tr class="<?= $isOwn ? 'is-mine' : '' ?>">
            <td style="text-align:center;">
              <?php $imgPath = $recipe_images[$r['recipe_name'] ?? ''] ?? ''; ?>
              <?php if ($imgPath): ?>
              <img src="/images/recipes/<?= htmlspecialchars($imgPath) ?>"
                   alt="<?= htmlspecialchars($r['recipe_name']) ?>"
                   title="<?= htmlspecialchars($r['recipe_name']) ?>"
                   style="width:40px;height:40px;object-fit:contain;">
              <?php else: ?>
              <span style="color:var(--text-secondary);font-size:0.75rem;"><?= htmlspecialchars($r['recipe_name'] ?? '—') ?></span>
              <?php endif; ?>
            </td>
            <td>
              <div style="display:flex;flex-direction:column;gap:3px;align-items:flex-start;">
                <span class="pot-badge" style="background:<?= $pc ?>;"><?= htmlspecialchars($r['pot_type']) ?>の鍋</span>
                <span class="quality-badge" style="background:<?= $qc ?>;"><?= htmlspecialchars($r['quality']) ?></span>
              </div>
            </td>
            <td style="white-space:nowrap;">
              <div style="color:var(--text-secondary);font-size:0.7rem;">No.<?= str_pad($r['pokemon_id'],3,'0',STR_PAD_LEFT) ?></div>
              <strong style="font-size:0.85rem;"><?= htmlspecialchars($r['pokemon_name']) ?></strong>
            </td>
            <td>
              <div style="display:flex;align-items:center;gap:5px;">
              <div class="mini-stat-card">
                <div class="mini-lv-box">
                  <span class="mini-lv-label">Lv.</span>
                  <span class="mini-lv-val"><?= $lv ?></span>
                </div>
                <div class="mini-hp-atk">
                  <div class="mini-hp-row">
                    <span class="mini-stat-label">HP</span>
                    <span class="mini-stat-val"><?= number_format($r['hp']) ?></span>
                  </div>
                  <div class="mini-atk-row">
                    <span class="mini-stat-label">ATK</span>
                    <span class="mini-stat-val"><?= number_format($r['atk']) ?></span>
                  </div>
                </div>
                <div class="mini-card-right">
                  <?php foreach ($types as $t):
                    $tc = $type_colors[$t] ?? '#888';
                  ?>
                  <span class="type-dot" style="background:<?= $tc ?>;" title="<?= htmlspecialchars($t) ?>"></span>
                  <?php endforeach; ?>
                  <span class="mini-ranged"><?= $isRanged ? '🏹' : '⚔️' ?></span>
                  <?php if ($isShiny): ?><span class="mini-shiny">★</span><?php endif; ?>
                </div>
              </div>
              <div style="display:flex;flex-direction:column;gap:4px;font-size:0.72rem;font-weight:700;white-space:nowrap;text-align:right;">
                <span style="color:<?= $hpTier['color'] ?>;"><?= $hpTier['ratio'] !== null ? number_format($hpTier['ratio'], 2) : '—' ?></span>
                <span style="color:<?= $atkTier['color'] ?>;"><?= $atkTier['ratio'] !== null ? number_format($atkTier['ratio'], 2) : '—' ?></span>
              </div>
              </div>
            </td>
            <td style="font-size:0.8rem;color:var(--text-secondary);white-space:nowrap;">
              <?php if (!empty($r['move1'])): ?><div><?= htmlspecialchars($r['move1']) ?></div><?php endif; ?>
              <?php if (!empty($r['move2'])): ?><div><?= htmlspecialchars($r['move2']) ?></div><?php endif; ?>
              <?php if (empty($r['move1']) && empty($r['move2'])): ?><span style="color:var(--text-secondary);">—</span><?php endif; ?>
            </td>
            <td>
              <?php if (array_filter($cells)): ?>
              <div class="charm-mini">
                <?php foreach ($cells as $c):
                  $cls = in_array($c, ['atk','hp','both']) ? 'c-'.$c : '';
                ?>
                <div class="charm-mini-cell <?= $cls ?>"></div>
                <?php endforeach; ?>
              </div>
              <?php else: ?>
              <span style="color:var(--text-secondary);font-size:0.75rem;">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r['username']): ?>
              <div class="reporter-name"><span>👤</span><?= htmlspecialchars($r['username']) ?></div>
              <?php else: ?>
              <div class="reporter-name"><span>👻</span>ゲスト</div>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap;">
              <div style="display:flex;flex-direction:column;gap:4px;">
                <?php if ($isOwn): ?>
                <a href="/iv-report.php?edit=<?= $r['id'] ?>" class="edit-btn">編集</a>
                <?php endif; ?>
                <a href="/iv-report.php?copy=<?= $r['id'] ?>" class="copy-btn">進化後登録</a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- 下段：ランキング＋凡例 -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:20px;margin-top:0;">

      <?php if (!empty($stats)): ?>
      <div class="report-card">
        <p class="section-title">📊 投稿数ランキング</p>
        <div class="stats-list">
          <?php foreach ($stats as $s): ?>
          <div class="stats-item">
            <span>No.<?= str_pad($s['pokedex_no'], 3, '0', STR_PAD_LEFT) ?> <?= htmlspecialchars($s['name']) ?></span>
            <span class="cnt"><?= $s['cnt'] ?>件</span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="report-card">
        <p class="section-title">🔑 チャーム凡例</p>
        <div style="font-size:0.82rem;line-height:2.2;color:var(--text-secondary);">
          <div style="display:flex;align-items:center;gap:8px;">
            <span style="display:inline-block;width:16px;height:16px;border-radius:3px;background:#e74c3c;"></span>ATK（赤手）
          </div>
          <div style="display:flex;align-items:center;gap:8px;">
            <span style="display:inline-block;width:16px;height:16px;border-radius:3px;background:#2980b9;"></span>HP（ハート）
          </div>
          <div style="display:flex;align-items:center;gap:8px;">
            <span style="display:inline-block;width:16px;height:16px;border-radius:3px;background:linear-gradient(135deg,#e74c3c 50%,#2980b9 50%);"></span>両方
          </div>
          <div style="display:flex;align-items:center;gap:8px;">
            <span style="display:inline-block;width:16px;height:16px;border-radius:3px;background:#444;border:1px solid var(--border);"></span>未入力
          </div>
        </div>
      </div>

      <div class="report-card">
        <p class="section-title">📈 個体値判定スコア</p>
        <div style="font-size:0.82rem;color:var(--text-secondary);margin-bottom:8px;">
          スコア = (実数値 − 鍋補正) ÷ 基礎値 × (100 ÷ Lv)
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;font-size:0.82rem;">
          <?php foreach ($iv_tiers as $t): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;padding:4px 10px;background:var(--bg-primary);border-radius:8px;">
            <span style="color:<?= $t['color'] ?>;font-weight:700;"><?= $t['label'] ?></span>
            <span style="color:var(--text-secondary);"><?= number_format($t['min'], 1) ?> 以上</span>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="font-size:0.75rem;color:var(--text-secondary);margin-top:8px;line-height:1.6;">
          鍋補正：鉄5 / 銅75 / 銀200 / 金350
        </div>
      </div>

    </div>
  </main>

  <footer class="site-footer">
    <div class="container">
      <p>&copy; 2026 あそび - ポケモンクエスト情報サイト</p>
    </div>
  </footer>

  <script src="https://asobi.info/assets/js/common.js"></script>
  <script>
  let mineOnly = false;
  function toggleMine() {
    mineOnly = !mineOnly;
    const btn = document.getElementById('mine-toggle');
    const rows = document.querySelectorAll('.report-table tbody tr');
    rows.forEach(tr => {
      if (mineOnly && !tr.classList.contains('is-mine')) {
        tr.style.display = 'none';
      } else {
        tr.style.display = '';
      }
    });
    const visible = [...rows].filter(tr => tr.style.display !== 'none').length;
    document.getElementById('visible-count').textContent = visible;
    btn.textContent = mineOnly ? 'すべて表示' : '自分の投稿だけ表示';
    btn.style.background = mineOnly ? 'var(--accent)' : 'transparent';
    btn.style.color = mineOnly ? '#fff' : 'var(--accent)';
  }
  </script>
</body>
</html>
