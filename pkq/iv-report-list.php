<?php
// 認証（任意）
$currentUserId = null;
$currentIp     = 'unknown';
try {
    require_once '/opt/asobi/shared/assets/php/auth.php';
    if (!empty($_SESSION['asobi_user_id'])) {
        $currentUserId = (int)$_SESSION['asobi_user_id'];
    }
} catch (Exception $e) {}
$isAdmin = (($_SESSION['asobi_user_role'] ?? '') === 'admin');

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
$pot_base = ['鉄'=>0,'銅'=>50,'銀'=>150,'金'=>300,'なし'=>0];
$pot_ivmax = ['鉄'=>10,'銅'=>50,'銀'=>100,'金'=>100,'なし'=>10];
define('GRAPH_MAX', 1250);

function calcStatBar(int $actual, int $base, string $pot, int $level, array $pot_base): array {
    $pb = $pot_base[$pot] ?? 0;
    $iv = max(0, $actual - $base - $level - $pb);
    $lvRemain = max(0, 100 - $level);
    return ['base' => $base, 'pot' => $pb, 'iv' => $iv, 'lv' => $level, 'lv_remain' => $lvRemain, 'total' => $actual];
}

$type_colors = [
    'ノーマル'=>'#9e9e9e','ほのお'=>'#e74c3c','みず'=>'#2980b9','くさ'=>'#27ae60',
    'でんき'=>'#f1c40f','こおり'=>'#55d1e8','かくとう'=>'#c03028','どく'=>'#9c27b0',
    'じめん'=>'#e0c068','ひこう'=>'#90caf9','エスパー'=>'#e91e8c','むし'=>'#8bc34a',
    'いわ'=>'#8d6e63','ゴースト'=>'#705898','ドラゴン'=>'#3949ab','あく'=>'#546e7a',
    'はがね'=>'#90a4ae','フェアリー'=>'#f48fb1',
];

// ---- わざ名→タイプマップ ----
$move_type_map = [];
foreach ($db->query("SELECT name, type FROM moves") as $row) {
    $move_type_map[$row['name']] = $row['type'];
}

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

$recipe_stats = $db->query("
    SELECT recipe_name, COUNT(*) as cnt
    FROM iv_reports
    WHERE recipe_name IS NOT NULL AND recipe_name != ''
    GROUP BY recipe_name ORDER BY cnt DESC LIMIT 10
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>料理結果一覧 - ポケモンクエスト情報</title>
  <link rel="stylesheet" href="https://asobi.info/assets/css/common.css?v=20260327e">
  <link rel="stylesheet" href="/css/style.css?v=20260327c">
  <link rel="stylesheet" href="https://asobi.info/assets/css/font.php">
  <link rel="stylesheet" href="https://asobi.info/assets/css/font.php">
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
      min-width: 800px;
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
      width: 2.8em;
      flex-shrink: 0;
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
      width: 100%;
      text-align: center;
      font-variant-numeric: tabular-nums;
    }
    .mini-hp-atk {
      display: flex;
      flex-direction: column;
      gap: 3px;
    }
    .mini-hp-row, .mini-atk-row {
      display: flex;
      align-items: center;
      gap: 2px;
      border-radius: 5px;
      padding: 1px 4px;
    }
    .mini-hp-row  { background: #2980b9; }
    .mini-atk-row { background: #e74c3c; }
    .mini-stat-label {
      font-size: 0.62rem;
      font-weight: 700;
      color: #fff;
      width: 20px;
      flex-shrink: 0;
    }
    .mini-stat-val {
      font-size: 0.75rem;
      font-weight: 800;
      color: #fff;
      width: 36px;
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
    .mini-shiny  { font-size: 0.8rem; color: #fff; line-height: 1; }

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
          <li><a href="/pokemon-list.html">全ポケモン一覧</a></li>
          <li><a href="/moves.html">全わざ一覧</a></li>
          <li><a href="/recipes.html">全料理一覧</a></li>
          <li><a href="/simulator.html">料理シミュレーター</a></li>
          <li><a href="/iv-checker.html">個体値チェッカー</a></li>
          <li><a href="/iv-report-list.php" class="active">料理結果一覧</a></li>
          <li><a href="/iv-report.php">料理結果投稿</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main class="container">
    <div class="page-header">
      <div class="breadcrumb">
        <a href="/">ポケクエ</a><span><a href="/iv-report.php">料理結果投稿</a></span><span>料理結果一覧</span>
      </div>
      <h1>料理結果一覧</h1>
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

    <a href="/iv-report.php" class="back-link">← 料理結果投稿</a>

    <div class="report-card" style="overflow:visible;padding-left:0;padding-right:0;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;padding:0 20px;">
        <p class="section-title" style="margin-bottom:0;border-bottom:none;">🕐 最近の投稿（<span id="visible-count"><?= count($recent) ?></span>件）</p>
        <div style="display:flex;gap:8px;align-items:center;">
          <?php if ($currentUserId || $currentIp !== 'unknown'): ?>
          <button id="mine-toggle" onclick="toggleMine()"
            style="padding:5px 14px;border:1.5px solid var(--accent);border-radius:20px;background:transparent;color:var(--accent);font-size:0.82rem;font-weight:600;cursor:pointer;">
            自分の投稿だけ表示
          </button>
          <?php endif; ?>
          <a href="/iv-report.php"
            style="padding:5px 14px;border:1.5px solid #27ae60;border-radius:20px;background:#27ae60;color:#fff;font-size:0.82rem;font-weight:600;cursor:pointer;text-decoration:none;">
            ＋ 新規登録
          </a>
        </div>
      </div>
      <?php if (empty($recent)): ?>
      <p style="color:var(--text-secondary);font-size:0.9rem;padding:20px 0;">まだ投稿がありません。最初の投稿者になりましょう！</p>
      <?php else: ?>
      <div style="overflow-x:auto;">
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
            if ($isAdmin) $isOwn = true;
            elseif ($currentUserId && (int)$r['user_id'] === $currentUserId) $isOwn = true;
            elseif (!$currentUserId && !$r['user_id'] && $r['ip'] === $currentIp) $isOwn = true;
            $lv = max(1, (int)($r['level'] ?? 100));
            $hpBar  = calcStatBar((int)$r['hp'],  (int)$r['base_hp'],  $r['pot_type'], $lv, $pot_base);
            $atkBar = calcStatBar((int)$r['atk'], (int)$r['base_atk'], $r['pot_type'], $lv, $pot_base);
            $ivMax  = $pot_ivmax[$r['pot_type']] ?? 10;
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
                   style="width:40px;height:40px;min-width:40px;min-height:40px;object-fit:contain;">
              <?php else: ?>
              <span style="color:var(--text-secondary);font-size:0.75rem;"><?= htmlspecialchars($r['recipe_name'] ?? '—') ?></span>
              <?php endif; ?>
            </td>
            <td>
              <div style="display:flex;flex-direction:column;gap:3px;align-items:flex-start;">
                <span class="pot-badge" style="background:<?= $pc ?>;"><?= $r['pot_type'] === 'なし' ? 'なし' : htmlspecialchars($r['pot_type']) . 'の鍋' ?></span>
<?php if (!empty($r['quality'])): ?>
                <span class="quality-badge" style="background:<?= $qc ?>;"><?= htmlspecialchars($r['quality']) ?></span>
                <?php endif; ?>
              </div>
            </td>
            <td>
              <div style="display:flex;align-items:center;gap:6px;">
                <img src="/images/pokemon/<?= str_pad($r['pokemon_id'],3,'0',STR_PAD_LEFT) ?>.png" alt="" style="width:36px;height:36px;min-width:36px;object-fit:contain;">
                <div style="min-width:0;">
                  <div style="color:var(--text-secondary);font-size:0.7rem;white-space:nowrap;">No.<?= str_pad($r['pokemon_id'],3,'0',STR_PAD_LEFT) ?></div>
                  <a href="/pokemon-detail.html?no=<?= $r['pokemon_id'] ?>" style="font-size:0.85rem;font-weight:700;color:var(--text-primary);text-decoration:none;white-space:nowrap;"><?= htmlspecialchars($r['pokemon_name']) ?></a>
                </div>
              </div>
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
                  <span class="mini-ranged"><img src="/images/<?= $isRanged ? 'ranged' : 'melee' ?>.png" alt="<?= $isRanged ? '遠距離' : '近距離' ?>" style="width:16px;height:16px;"></span>
                  <?php if ($isShiny): ?><span class="mini-shiny">★</span><?php endif; ?>
                </div>
              </div>
              <div style="display:flex;flex-direction:column;gap:4px;min-width:100px;flex:1;">
                <?php foreach ([['HP',$hpBar],['ATK',$atkBar]] as [$sl,$sb]):
                  $bPct  = min(100, round($sb['base'] / GRAPH_MAX * 100));
                  $pPct  = min(100 - $bPct, round($sb['pot'] / GRAPH_MAX * 100));
                  $ivPct = min(100 - $bPct - $pPct, round($sb['iv'] / GRAPH_MAX * 100));
                  $lvPct = min(100 - $bPct - $pPct - $ivPct, round($sb['lv'] / GRAPH_MAX * 100));
                  $lrPct = min(100 - $bPct - $pPct - $ivPct - $lvPct, round($sb['lv_remain'] / GRAPH_MAX * 100));
                ?>
                <?php
                  $totalMinPos = round(($sb['base'] + 1) / GRAPH_MAX * 100, 1);
                  $totalMaxPos = round(($sb['base'] + 500) / GRAPH_MAX * 100, 1);
                  $rangeWidth = $totalMaxPos - $totalMinPos;
                ?>
                <div style="display:flex;align-items:flex-end;gap:3px;margin-bottom:-3px;">
                  <span style="min-width:20px;"></span>
                  <div style="position:relative;flex:1;height:10px;">
                    <div style="position:absolute;left:<?= $totalMinPos ?>%;top:0;height:calc(100% + 1px);width:1px;background:#666;"></div>
                    <div style="position:absolute;left:calc(<?= $totalMinPos ?>% + 2px);right:calc(<?= 100 - $totalMaxPos ?>% + 2px);top:50%;border-top:1px solid #999;"></div>
                    <div style="position:absolute;left:calc(<?= $totalMinPos ?>% + 1px);top:50%;transform:translateY(-50%);width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-right:5px solid #999;"></div>
                    <div style="position:absolute;right:calc(<?= 100 - $totalMaxPos ?>% + 1px);top:50%;transform:translateY(-50%);width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-left:5px solid #999;"></div>
                    <div style="position:absolute;left:<?= $totalMaxPos ?>%;top:0;height:calc(100% + 1px);width:1px;background:#666;"></div>
                  </div>
                </div>
                <div style="display:flex;align-items:center;gap:3px;">
                  <span style="font-size:0.6rem;font-weight:700;color:var(--text-secondary);min-width:20px;"><?= $sl ?></span>
                  <div style="flex:1;height:12px;background:var(--border);border-radius:4px;overflow:hidden;display:flex;">
                    <div style="width:<?= $bPct ?>%;background:#7f8c8d;"></div>
                    <div style="width:<?= $pPct ?>%;background:#f39c12;"></div>
                    <div style="width:<?= $ivPct ?>%;background:#27ae60;"></div>
                    <div style="width:<?= $lvPct ?>%;background:#3498db;"></div>
                    <div style="width:<?= $lrPct ?>%;background:#c8e6f8;"></div>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
              </div>
            </td>
            <td style="font-size:0.75rem;white-space:nowrap;line-height:1.2;padding-top:2px;padding-bottom:2px;">
              <?php
              $has1  = !empty($r['move1']);
              $has2  = !empty($r['move2']);
              $slot2 = (int)($r['move2_slot'] ?? 0);
              $slotActive = (int)($r['slot_active'] ?? 0);
              $hasSlot = $has1 || $has2 || $slot2 > 0 || $slotActive;
              if (!$hasSlot):
              ?>
                <span style="color:var(--text-secondary);">—</span>
              <?php else:
                $c1    = $move_type_map[$r['move1']] ?? null;
                $col1  = $c1 ? ($type_colors[$c1] ?? '#888') : '#888';
                $c2    = $move_type_map[$r['move2']] ?? null;
                $col2  = $c2 ? ($type_colors[$c2] ?? '#888') : '#888';

                // スロット配列を構築
                $slots = ['empty','empty','empty','empty'];
                // スロットが有効なら0番を■にする
                if ($slotActive || $has1 || $slot2 > 0 || $has2) $slots[0] = 'col:'.$col1;
                if ($slot2 > 0) $slots[$slot2] = 'col:'.$col2;
              ?>
                <?php if ($has1): ?>
                <div style="color:var(--text-secondary);margin-bottom:0;"><?= htmlspecialchars($r['move1']) ?></div>
                <?php endif; ?>
                <?php if ($has2): ?>
                <div style="color:var(--text-secondary);margin-bottom:0;"><?= htmlspecialchars($r['move2']) ?></div>
                <?php endif; ?>
                <div style="display:flex;align-items:center;gap:0;">
                  <?php foreach ($slots as $si => $sv):
                    $isFilled = str_starts_with($sv, 'col:');
                    $clr      = $isFilled ? substr($sv, 4) : null;
                    if ($si > 0):
                      $showConn = !($slot2 > 0 && $si === $slot2);
                      echo '<span style="color:#aaa;padding:0 1px;'.($showConn ? '' : 'visibility:hidden;').'">-</span>';
                    endif;
                    if ($isFilled):
                      echo '<span style="font-size:0.85rem;color:'.$clr.';">■</span>';
                    else:
                      echo '<span style="font-size:0.85rem;color:#888;">◇</span>';
                    endif;
                  endforeach; ?>
                </div>
              <?php endif; ?>
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
                <?php if ($isOwn): ?>
                <a href="/iv-report.php?copy=<?= $r['id'] ?>" class="copy-btn">進化後登録</a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <p id="no-mine-msg" style="padding-left:20px;" style="display:none;color:var(--text-secondary);font-size:0.9rem;padding:20px 0;">投稿したデータはありません。</p>
      <?php endif; ?>
    </div>

    <!-- 下段：ランキング＋凡例 -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:20px;margin-top:0;">

      <?php if (!empty($stats)): ?>
      <div class="report-card">
        <p class="section-title">📊 ポケモンランキング</p>
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

      <?php if (!empty($recipe_stats)): ?>
      <div class="report-card">
        <p class="section-title">🍳 料理種類ランキング</p>
        <div class="stats-list">
          <?php foreach ($recipe_stats as $rs): ?>
          <div class="stats-item">
            <span><?= htmlspecialchars($rs['recipe_name']) ?></span>
            <span class="cnt"><?= $rs['cnt'] ?>件</span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="report-card" style="margin-top:16px;">
        <p class="section-title">💬 コメント</p>
        <div id="comments-list" style="margin-bottom:12px;">読み込み中...</div>
        <div id="comment-form" style="display:none;">
          <textarea id="comment-input" class="comment-input" placeholder="コメントを入力..." maxlength="1000"></textarea>
          <button id="comment-submit-btn" class="comment-submit" onclick="Comments.post()">投稿する</button>
        </div>
        <div id="comment-login-note" style="display:none;font-size:0.85rem;color:var(--text-secondary);">
          <a href="https://asobi.info/login.php" style="color:var(--accent);">ログイン</a>するとコメントできます
        </div>
      </div>

    </div>
  <!-- Amazon アフィリエイト スライダー -->
  <div class="container">
    <div class="amazon-slider" id="amz-slider">
      <div class="amazon-slider-track" id="amz-track">
        <a class="amazon-slide" href="https://www.amazon.co.jp/s?k=switch2+%E3%83%9D%E3%82%B1%E3%83%A2%E3%83%B3&tag=asobi-jp-22" target="_blank" rel="noopener sponsored">
          <span class="amazon-pr">PR</span>
          <span class="amazon-slide-icon">🎮</span>
          <span class="amazon-slide-body"><div class="amazon-slide-title">Nintendo Switch 2 × ポケモン</div><div class="amazon-slide-sub">Amazonで関連商品を見る</div></span>
          <span class="amazon-slide-arrow">›</span>
        </a>
        <a class="amazon-slide" href="https://www.amazon.co.jp/s?k=%E3%83%9D%E3%82%B1%E3%83%A2%E3%83%B3%E3%82%AB%E3%83%BC%E3%83%89%E3%82%B2%E3%83%BC%E3%83%A0&tag=asobi-jp-22" target="_blank" rel="noopener sponsored">
          <span class="amazon-pr">PR</span>
          <span class="amazon-slide-icon">🃏</span>
          <span class="amazon-slide-body"><div class="amazon-slide-title">ポケモンカードゲーム</div><div class="amazon-slide-sub">Amazonで関連商品を見る</div></span>
          <span class="amazon-slide-arrow">›</span>
        </a>
        <a class="amazon-slide" href="https://www.amazon.co.jp/s?k=Nintendo+Switch+2&tag=asobi-jp-22" target="_blank" rel="noopener sponsored">
          <span class="amazon-pr">PR</span>
          <span class="amazon-slide-icon">🕹️</span>
          <span class="amazon-slide-body"><div class="amazon-slide-title">Nintendo Switch 2 本体</div><div class="amazon-slide-sub">Amazonで関連商品を見る</div></span>
          <span class="amazon-slide-arrow">›</span>
        </a>
        <a class="amazon-slide" href="https://www.amazon.co.jp/s?k=%E3%83%9D%E3%82%B1%E3%83%A2%E3%83%B3+%E3%82%B0%E3%83%83%E3%82%BA&tag=asobi-jp-22" target="_blank" rel="noopener sponsored">
          <span class="amazon-pr">PR</span>
          <span class="amazon-slide-icon">🧸</span>
          <span class="amazon-slide-body"><div class="amazon-slide-title">ポケモングッズ</div><div class="amazon-slide-sub">Amazonで関連商品を見る</div></span>
          <span class="amazon-slide-arrow">›</span>
        </a>
      </div>
      <div class="amazon-slider-dots" id="amz-dots">
        <span class="active" onclick="amzGoTo(0)"></span>
        <span onclick="amzGoTo(1)"></span>
        <span onclick="amzGoTo(2)"></span>
        <span onclick="amzGoTo(3)"></span>
      </div>
    </div>
  </div>
  <script>
  (function(){
    var idx=0, total=4, timer;
    function amzGoTo(n){
      idx=n;
      document.getElementById("amz-track").style.transform="translateX(-"+idx*100+"%)";
      document.querySelectorAll("#amz-dots span").forEach(function(d,i){d.classList.toggle("active",i===idx);});
    }
    window.amzGoTo=amzGoTo;
    function amzNext(){ amzGoTo((idx+1)%total); }
    timer=setInterval(amzNext,10000);
    document.getElementById("amz-slider").addEventListener("mouseenter",function(){clearInterval(timer);});
    document.getElementById("amz-slider").addEventListener("mouseleave",function(){timer=setInterval(amzNext,10000);});
  })();
  </script>  </main>

  <footer class="site-footer">
    <div class="container">
      <p>&copy; 2026 あそび - ポケモンクエスト情報サイト</p>
    </div>
  </footer>

  <script src="https://asobi.info/assets/js/common.js?v=20260327h"></script>
  <script src="/js/comments.js"></script>
  <script>
  // コメント初期化
  document.addEventListener('DOMContentLoaded', function() { Comments.init('report_list', 0); });

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
    const noMsg = document.getElementById('no-mine-msg');
    if (noMsg) noMsg.style.display = (mineOnly && visible === 0) ? '' : 'none';
  }
  </script>
</body>
</html>
