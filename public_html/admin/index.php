<?php
require_once '/home/m96/asobi.info/public_html/assets/php/auth.php';
asobiRequireAdmin();

$db = asobiUsersDb();
$totalUsers  = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$adminCount  = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$todayCount  = $db->query("SELECT COUNT(*) FROM users WHERE date(created_at) = date('now','localtime')")->fetchColumn();
$activeCount = $db->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
$currentUser = asobiGetCurrentUser();

// アクセス集計 (PV + ユニーク)
$pvToday   = $db->query("SELECT COUNT(*) FROM access_logs WHERE date(created_at) = date('now','localtime')")->fetchColumn();
$uvToday   = $db->query("SELECT COUNT(DISTINCT ip) FROM access_logs WHERE date(created_at) = date('now','localtime')")->fetchColumn();
$pvWeek    = $db->query("SELECT COUNT(*) FROM access_logs WHERE created_at >= datetime('now','localtime','-7 days')")->fetchColumn();
$uvWeek    = $db->query("SELECT COUNT(DISTINCT ip) FROM access_logs WHERE created_at >= datetime('now','localtime','-7 days')")->fetchColumn();
$pvMonth   = $db->query("SELECT COUNT(*) FROM access_logs WHERE strftime('%Y-%m', created_at) = strftime('%Y-%m', datetime('now','localtime'))")->fetchColumn();
$uvMonth   = $db->query("SELECT COUNT(DISTINCT ip) FROM access_logs WHERE strftime('%Y-%m', created_at) = strftime('%Y-%m', datetime('now','localtime'))")->fetchColumn();
$pvTotal   = $db->query("SELECT COUNT(*) FROM access_logs")->fetchColumn();
$uvTotal   = $db->query("SELECT COUNT(DISTINCT ip) FROM access_logs")->fetchColumn();
$popularPages  = $db->query("SELECT path, COUNT(*) as pv, COUNT(DISTINCT ip) as uv FROM access_logs WHERE created_at >= datetime('now','localtime','-7 days') GROUP BY path ORDER BY pv DESC LIMIT 8")->fetchAll();
$refererStats  = $db->query("SELECT referer, COUNT(*) as cnt FROM access_logs WHERE referer != '' AND created_at >= datetime('now','localtime','-7 days') GROUP BY referer ORDER BY cnt DESC LIMIT 8")->fetchAll();
$browserStats  = $db->query("SELECT browser, COUNT(*) as cnt FROM access_logs WHERE browser != '' AND created_at >= datetime('now','localtime','-7 days') GROUP BY browser ORDER BY cnt DESC")->fetchAll();
$deviceStats   = $db->query("SELECT device, COUNT(*) as cnt FROM access_logs WHERE device != '' AND created_at >= datetime('now','localtime','-7 days') GROUP BY device ORDER BY cnt DESC")->fetchAll();
$osStats       = $db->query("SELECT os, COUNT(*) as cnt FROM access_logs WHERE os != '' AND created_at >= datetime('now','localtime','-7 days') GROUP BY os ORDER BY cnt DESC")->fetchAll();
$recentLogins  = $db->query("SELECT l.username, l.ip, l.created_at, l.browser, l.device, l.os, u.display_name FROM login_logs l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.id DESC LIMIT 15")->fetchAll();

// 日別PV・UV（直近14日）
$dailyPv = $db->query("SELECT date(created_at) as day, COUNT(*) as pv, COUNT(DISTINCT ip) as uv FROM access_logs WHERE created_at >= datetime('now','localtime','-14 days') GROUP BY day ORDER BY day")->fetchAll();

// サイト別集計（過去7日）
$siteStats = $db->query("SELECT host, COUNT(*) as pv, COUNT(DISTINCT ip) as uv FROM access_logs WHERE created_at >= datetime('now','localtime','-7 days') AND host != '' GROUP BY host ORDER BY pv DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>管理画面 - asobi.info</title>
  <link rel="stylesheet" href="/assets/css/common.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Noto Sans JP', sans-serif;
      background: #f0f2f5;
      color: #1d2d3a;
      min-height: 100vh;
    }

    /* ヘッダー（トップページと同じ構造） */
    .site-header {
      background: rgba(255,255,255,0.85);
      border-bottom: 1px solid #e0e0e0;
    }
    .site-logo a { color: #1d1d1f; text-decoration: none; font-size: 1.5rem; font-weight: 700; }
    .header-right { display: flex; align-items: center; gap: 24px; }
    .site-nav ul { display: flex; list-style: none; gap: 24px; }
    .site-nav a { color: #1d1d1f; font-weight: 500; font-size: 0.9rem; }
    .admin-badge {
      display: inline-block;
      font-size: 0.7rem;
      font-weight: 700;
      padding: 2px 8px;
      background: linear-gradient(135deg, #e74c3c, #c0392b);
      color: #fff;
      border-radius: 10px;
      margin-left: 6px;
      vertical-align: middle;
    }
    .user-area { display: flex; align-items: center; gap: 10px; position: relative; }
    .user-area::before { content: ''; display: block; width: 1px; height: 18px; background: #d0d0d5; }
    .user-menu { position: relative; }
    .user-trigger {
      display: flex; align-items: center; gap: 8px;
      cursor: pointer; padding: 4px 8px; border-radius: 8px;
      transition: background 0.2s; color: #1d1d1f;
    }
    .user-trigger:hover { background: rgba(0,0,0,0.05); }
    .user-avatar {
      width: 30px; height: 30px; border-radius: 50%;
      background: linear-gradient(135deg, #667eea, #764ba2);
      display: flex; align-items: center; justify-content: center;
      font-size: 0.8rem; font-weight: 700; color: #fff; flex-shrink: 0; overflow: hidden;
    }
    .user-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .user-display-name { font-size: 0.875rem; font-weight: 500; max-width: 100px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .user-caret { font-size: 0.6rem; opacity: 0.5; }
    .user-dropdown {
      display: none; position: absolute; top: calc(100% + 8px); right: 0;
      background: #fff; border: 1px solid #e0e0e0; border-radius: 12px;
      box-shadow: 0 8px 32px rgba(0,0,0,0.12); min-width: 160px; overflow: hidden; z-index: 200;
    }
    .user-menu.open .user-dropdown { display: block; }
    .user-dropdown a { display: block; padding: 11px 16px; font-size: 0.875rem; color: #1d1d1f; text-decoration: none; transition: background 0.15s; }
    .user-dropdown a:hover { background: #f5f5f7; }
    .user-dropdown .dropdown-divider { height: 1px; background: #e0e0e0; margin: 4px 0; }
    .user-dropdown .dropdown-logout { color: #e74c3c; }
    @media (max-width: 768px) {
      .site-header .container { flex-direction: row; align-items: center; }
      .site-nav { display: none; }
      .user-display-name { display: none; }
      .user-area::before { display: none; }
    }

    .admin-body {
      max-width: 1000px;
      margin: 0 auto;
      padding: 40px 24px;
    }
    h1 {
      font-size: 1.4rem;
      margin-bottom: 32px;
      color: #1d2d3a;
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 20px;
      margin-bottom: 40px;
    }
    .stat-card {
      background: #fff;
      border: 1px solid #e0e4e8;
      border-radius: 12px;
      padding: 24px 20px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .stat-label {
      font-size: 0.8rem;
      color: #637080;
      margin-bottom: 8px;
    }
    .stat-value {
      font-size: 2rem;
      font-weight: 700;
      color: #1d2d3a;
    }
    .stat-value.accent { color: #5567cc; }

    .section-title {
      font-size: 1rem;
      font-weight: 600;
      color: #637080;
      margin-bottom: 16px;
      padding-bottom: 10px;
      border-bottom: 1px solid #e0e4e8;
    }

    .menu-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px;
    }
    .menu-card {
      background: #fff;
      border: 1px solid #e0e4e8;
      border-radius: 12px;
      padding: 24px 20px;
      text-decoration: none;
      color: #1d2d3a;
      transition: border-color 0.2s, transform 0.2s;
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .menu-card:hover {
      border-color: #667eea;
      transform: translateY(-2px);
    }
    .menu-card-icon { font-size: 1.8rem; margin-bottom: 12px; }
    .menu-card-title { font-weight: 600; margin-bottom: 4px; }
    .menu-card-desc { font-size: 0.8rem; color: #637080; }

    .menu-card.tbt:hover  { border-color: #f7d94e; }
    .menu-card.dbd:hover  { border-color: #e74c3c; }
    .menu-card.pq:hover   { border-color: #f093fb; }

    .content-section { margin-top: 40px; }

    .section-sublabel { font-size: 0.8rem; color: #637080; margin-bottom: 10px; }
    .uv-num { font-size: 1.1rem; color: #5567cc; }

    /* PV/UV カードグリッド */
    .pv-uv-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 20px;
      margin-bottom: 24px;
    }

    /* バーチャート */
    .pv-chart-wrap { margin: 0 0 24px; }
    .pv-chart {
      display: flex;
      align-items: flex-end;
      gap: 4px;
      height: 120px;
      background: #f8fafc;
      border: 1px solid #e0e4e8;
      border-radius: 8px;
      padding: 10px 10px 0;
    }
    .pv-bar-col {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      height: 100%;
      justify-content: flex-end;
      gap: 2px;
    }
    .pv-bar-val { font-size: 0.58rem; color: #9ba8b5; }
    .pv-bar-dual { display: flex; gap: 1px; align-items: flex-end; width: 100%; height: 80%; }
    .pv-bar {
      flex: 1;
      border-radius: 2px 2px 0 0;
      min-height: 2px;
    }
    .pv-bar.pv { background: #667eea; }
    .pv-bar.uv { background: #a090d0; }
    .pv-bar-label { font-size: 0.58rem; color: #9ba8b5; padding: 3px 0 4px; white-space: nowrap; }

    /* アクセス詳細グリッド */
    .access-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 24px;
      margin-top: 20px;
    }
    @media (max-width: 700px) { .access-grid { grid-template-columns: 1fr; } }
    .access-table { width: 100%; border-collapse: collapse; font-size: 0.83rem; }
    .access-table th { color: #637080; font-weight: 600; padding: 6px 8px; border-bottom: 2px solid #e0e4e8; text-align: left; background: #f8fafc; }
    .access-table td { padding: 7px 8px; border-bottom: 1px solid #f0f2f5; word-break: break-all; color: #1d2d3a; }
    .access-table tr:hover td { background: #f8fafc; }
    .no-data { color: #9ba8b5; text-align: center; padding: 12px !important; }

    /* 横棒グラフ */
    .stat-bar-row { display: flex; align-items: center; gap: 8px; margin-bottom: 5px; }
    .stat-bar-label { font-size: 0.8rem; width: 70px; flex-shrink: 0; color: #1d2d3a; }
    .stat-bar-track { flex: 1; height: 8px; background: #e8eef4; border-radius: 4px; overflow: hidden; }
    .stat-bar-fill { height: 100%; background: linear-gradient(90deg, #667eea, #a090d0); border-radius: 4px; }
    .stat-bar-pct { font-size: 0.75rem; color: #637080; width: 32px; text-align: right; flex-shrink: 0; }

    /* タグ */
    .tag-device, .tag-browser {
      display: inline-block;
      font-size: 0.7rem;
      padding: 1px 6px;
      border-radius: 4px;
      margin-right: 3px;
    }
    .tag-device  { background: rgba(102,126,234,0.12); color: #5567cc; }
    .tag-browser { background: rgba(160,144,208,0.15); color: #7a66b5; }
    .ip-link { color: #9ba8b5; font-size: 0.78rem; text-decoration: none; cursor: pointer; background: none; border: none; padding: 0; font-family: inherit; }
    .ip-link:hover { color: #5567cc; text-decoration: underline; }
    /* IP履歴モーダル */
    .ip-modal-overlay {
      display: none; position: fixed; inset: 0;
      background: rgba(0,0,0,0.45); z-index: 500;
      align-items: center; justify-content: center;
    }
    .ip-modal-overlay.open { display: flex; }
    .ip-modal {
      background: #fff; border-radius: 12px; padding: 28px;
      width: 100%; max-width: 680px; max-height: 80vh;
      overflow-y: auto; box-shadow: 0 16px 48px rgba(0,0,0,0.18);
    }
    .ip-modal-title { font-size: 1rem; font-weight: 700; margin-bottom: 20px; color: #1d2d3a; }
    .ip-modal-subtitle { font-size: 0.8rem; font-weight: 600; color: #637080; margin: 16px 0 8px; }
    .ip-modal-close {
      float: right; background: none; border: none; font-size: 1.2rem;
      cursor: pointer; color: #637080; line-height: 1;
    }
    .ip-modal-close:hover { color: #e74c3c; }
    #ip-modal-loading { text-align: center; color: #637080; padding: 20px; }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="container">
      <div class="site-logo"><a href="/admin/">あそび<span class="admin-badge">ADMIN</span></a></div>
      <div class="header-right">
        <nav class="site-nav">
          <ul>
            <li><a href="/">サイトトップ</a></li>
            <li><a href="/admin/">ダッシュボード</a></li>
            <li><a href="/admin/users.php">ユーザー管理</a></li>
            <li><a href="/admin/settings.php">サイト設定</a></li>
          </ul>
        </nav>
        <div class="user-area">
          <div class="user-menu">
            <div class="user-trigger" tabindex="0">
              <div class="user-avatar">
                <?php if ($currentUser['avatar_url']): ?>
                  <img src="<?= htmlspecialchars($currentUser['avatar_url']) ?>" alt="">
                <?php else: ?>
                  <?= htmlspecialchars(mb_substr($currentUser['display_name'], 0, 1)) ?>
                <?php endif; ?>
              </div>
              <span class="user-display-name"><?= htmlspecialchars($currentUser['display_name']) ?></span>
              <span class="user-caret">▼</span>
            </div>
            <div class="user-dropdown">
              <a href="/">あそびトップ</a>
              <div class="dropdown-divider"></div>
              <a href="/profile.php">プロフィール</a>
              <div class="dropdown-divider"></div>
              <a href="/logout.php" class="dropdown-logout">ログアウト</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </header>

  <!-- IP履歴モーダル -->
  <div class="ip-modal-overlay" id="ip-modal-overlay" onclick="closeIpModal(event)">
    <div class="ip-modal">
      <button class="ip-modal-close" onclick="document.getElementById('ip-modal-overlay').classList.remove('open')">✕</button>
      <div class="ip-modal-title" id="ip-modal-title">IPアクセス履歴</div>
      <div id="ip-modal-body"><div id="ip-modal-loading">読み込み中...</div></div>
    </div>
  </div>

  <div class="admin-body">
    <h1>ダッシュボード</h1>

    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-label">総ユーザー数</div>
        <div class="stat-value accent"><?= $totalUsers ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">アクティブ</div>
        <div class="stat-value"><?= $activeCount ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">管理者</div>
        <div class="stat-value"><?= $adminCount ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">本日の新規登録</div>
        <div class="stat-value"><?= $todayCount ?></div>
      </div>
    </div>

    <div class="section-title">管理メニュー</div>
    <div class="menu-grid">
      <a href="/admin/users.php" class="menu-card">
        <div class="menu-card-icon">👥</div>
        <div class="menu-card-title">ユーザー管理</div>
        <div class="menu-card-desc">ユーザー一覧・ロール変更・停止</div>
      </a>
      <a href="/admin/settings.php" class="menu-card">
        <div class="menu-card-icon">⚙️</div>
        <div class="menu-card-title">サイト設定</div>
        <div class="menu-card-desc">メール制限・各種パラメータ設定</div>
      </a>
      <a href="/admin/banned-words.php" class="menu-card">
        <div class="menu-card-icon">🚫</div>
        <div class="menu-card-title">禁止ワード管理</div>
        <div class="menu-card-desc">ニックネーム・コメントのフィルター</div>
      </a>
    </div>

    <div class="content-section">
      <div class="section-title">コンテンツ管理</div>
      <div class="menu-grid">
        <a href="https://tbt.asobi.info/admin/" class="menu-card tbt" target="_blank">
          <div class="menu-card-icon">⚔️</div>
          <div class="menu-card-title">Tournament Battle</div>
          <div class="menu-card-desc">tbt.asobi.info 管理画面</div>
        </a>
        <a href="https://dbd.asobi.info/admin/" class="menu-card dbd" target="_blank">
          <div class="menu-card-icon">🔪</div>
          <div class="menu-card-title">Dead by Daylight</div>
          <div class="menu-card-desc">dbd.asobi.info 管理画面</div>
        </a>
        <a href="https://pkq.asobi.info/admin/" class="menu-card pq" target="_blank">
          <div class="menu-card-icon">🎮</div>
          <div class="menu-card-title">ポケモンクエスト</div>
          <div class="menu-card-desc">pkq.asobi.info 管理画面</div>
        </a>
      </div>
    </div>

    <div class="content-section">
      <div class="section-title">アクセス集計</div>

      <!-- PV / UV カード -->
      <div class="pv-uv-grid">
        <div class="stat-card">
          <div class="stat-label">本日 PV / UV</div>
          <div class="stat-value accent"><?= number_format($pvToday) ?> <span class="uv-num">/ <?= number_format($uvToday) ?></span></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">週間 PV / UV</div>
          <div class="stat-value"><?= number_format($pvWeek) ?> <span class="uv-num">/ <?= number_format($uvWeek) ?></span></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">月間 PV / UV</div>
          <div class="stat-value"><?= number_format($pvMonth) ?> <span class="uv-num">/ <?= number_format($uvMonth) ?></span></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">累計 PV / UV</div>
          <div class="stat-value"><?= number_format($pvTotal) ?> <span class="uv-num">/ <?= number_format($uvTotal) ?></span></div>
        </div>
      </div>

      <!-- サイト別集計 -->
      <?php if (!empty($siteStats)): ?>
      <div style="margin-bottom:24px;">
        <div class="section-sublabel">サイト別 PV / UV（過去7日）</div>
        <?php
        $maxSitePv = max(array_column($siteStats, 'pv')) ?: 1;
        $siteLabels = [
            'asobi.info'                  => ['label' => 'asobi.info',          'color' => '#667eea'],
            'dbd.asobi.info'              => ['label' => 'DbD',                 'color' => '#e74c3c'],
            'pkq.asobi.info'    => ['label' => 'ポケモンクエスト',   'color' => '#f093fb'],
            'tbt.asobi.info'              => ['label' => 'Tournament Battle',   'color' => '#f7d94e'],
        ];
        ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
        <?php foreach ($siteStats as $s): ?>
        <?php
            $info  = $siteLabels[$s['host']] ?? ['label' => htmlspecialchars($s['host']), 'color' => '#9ba8b5'];
            $pct   = round($s['pv'] / $maxSitePv * 100);
        ?>
        <div style="display:flex;align-items:center;gap:10px;">
          <span style="width:150px;font-size:0.82rem;color:#1d2d3a;flex-shrink:0;"><?= $info['label'] ?></span>
          <div style="flex:1;height:12px;background:#e8eef4;border-radius:6px;overflow:hidden;">
            <div style="height:100%;width:<?= $pct ?>%;background:<?= $info['color'] ?>;border-radius:6px;"></div>
          </div>
          <span style="font-size:0.82rem;color:#1d2d3a;width:40px;text-align:right;"><?= number_format($s['pv']) ?></span>
          <span style="font-size:0.78rem;color:#5567cc;width:32px;text-align:right;">/ <?= number_format($s['uv']) ?></span>
        </div>
        <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- 日別バーチャート -->
      <?php if (!empty($dailyPv)): ?>
      <div class="pv-chart-wrap">
        <div class="section-sublabel">直近14日のPV（青）/ UV（紫）推移</div>
        <div class="pv-chart">
          <?php
          $maxPv = max(array_column($dailyPv, 'pv')) ?: 1;
          foreach ($dailyPv as $row):
            $pctPv = round($row['pv'] / $maxPv * 100);
            $pctUv = round($row['uv'] / $maxPv * 100);
            $label = date('m/d', strtotime($row['day']));
          ?>
          <div class="pv-bar-col">
            <div class="pv-bar-val"><?= $row['pv'] ?></div>
            <div class="pv-bar-dual">
              <div class="pv-bar pv" style="height:<?= $pctPv ?>%"></div>
              <div class="pv-bar uv" style="height:<?= $pctUv ?>%"></div>
            </div>
            <div class="pv-bar-label"><?= $label ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- 詳細グリッド -->
      <div class="access-grid">

        <!-- 人気ページ -->
        <div>
          <div class="section-sublabel">人気ページ（過去7日）</div>
          <table class="access-table">
            <thead><tr><th>パス</th><th style="text-align:right">PV</th><th style="text-align:right">UV</th></tr></thead>
            <tbody>
              <?php foreach ($popularPages as $p): ?>
              <tr>
                <td><?= htmlspecialchars($p['path']) ?></td>
                <td style="text-align:right;color:#a090d0;"><?= number_format($p['pv']) ?></td>
                <td style="text-align:right;color:#667eea;"><?= number_format($p['uv']) ?></td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($popularPages)): ?><tr><td colspan="3" class="no-data">データなし</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- 参照元 -->
        <div>
          <div class="section-sublabel">参照元サイト（過去7日）</div>
          <table class="access-table">
            <thead><tr><th>ドメイン</th><th style="text-align:right">件数</th></tr></thead>
            <tbody>
              <?php foreach ($refererStats as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['referer'] ?: '直接') ?></td>
                <td style="text-align:right;color:#a090d0;"><?= number_format($r['cnt']) ?></td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($refererStats)): ?><tr><td colspan="2" class="no-data">データなし</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- デバイス・ブラウザ・OS -->
        <div>
          <div class="section-sublabel">デバイス / ブラウザ / OS（過去7日）</div>
          <?php
          $allStat = [];
          $totalDevSum = array_sum(array_column($deviceStats, 'cnt')) ?: 1;
          foreach ($deviceStats as $s) $allStat['device'][] = $s;
          $totalBrSum = array_sum(array_column($browserStats, 'cnt')) ?: 1;
          foreach ($browserStats as $s) $allStat['browser'][] = $s;
          $totalOsSum = array_sum(array_column($osStats, 'cnt')) ?: 1;
          foreach ($osStats as $s) $allStat['os'][] = $s;

          $sections = [
              ['key' => 'device',  'label' => 'デバイス', 'total' => $totalDevSum, 'rows' => $deviceStats],
              ['key' => 'browser', 'label' => 'ブラウザ', 'total' => $totalBrSum,  'rows' => $browserStats],
              ['key' => 'os',      'label' => 'OS',       'total' => $totalOsSum,  'rows' => $osStats],
          ];
          ?>
          <?php foreach ($sections as $sec): ?>
          <div style="margin-bottom:14px;">
            <div style="font-size:0.75rem;color:#8888a0;margin-bottom:6px;"><?= $sec['label'] ?></div>
            <?php foreach ($sec['rows'] as $row): ?>
            <?php $pct = round($row['cnt'] / $sec['total'] * 100); ?>
            <div class="stat-bar-row">
              <span class="stat-bar-label"><?= htmlspecialchars($row[$sec['key']]) ?></span>
              <div class="stat-bar-track"><div class="stat-bar-fill" style="width:<?= $pct ?>%"></div></div>
              <span class="stat-bar-pct"><?= $pct ?>%</span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($sec['rows'])): ?><div style="font-size:0.8rem;color:#8888a0;">データなし</div><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- ログイン履歴 -->
        <div>
          <div class="section-sublabel">最近のログイン</div>
          <table class="access-table">
            <thead><tr><th>ユーザー</th><th>端末/ブラウザ</th><th>IP</th><th>日時</th></tr></thead>
            <tbody>
              <?php foreach ($recentLogins as $l): ?>
              <tr>
                <td><?= htmlspecialchars($l['display_name'] ?: $l['username']) ?></td>
                <td>
                  <span class="tag-device"><?= htmlspecialchars($l['device'] ?: '?') ?></span>
                  <span class="tag-browser"><?= htmlspecialchars($l['browser'] ?: '?') ?></span>
                  <?php if ($l['os']): ?><span style="font-size:0.72rem;color:#8888a0;"><?= htmlspecialchars($l['os']) ?></span><?php endif; ?>
                </td>
                <td><button class="ip-link" onclick="showIpLogs(<?= htmlspecialchars(json_encode($l['ip'] ?: '')) ?>)"><?= htmlspecialchars($l['ip'] ?: '-') ?></button></td>
                <td style="color:#8888a0;font-size:0.78rem;white-space:nowrap;"><?= htmlspecialchars(substr($l['created_at'], 0, 16)) ?></td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($recentLogins)): ?><tr><td colspan="4" class="no-data">データなし</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>

      </div><!-- .access-grid -->
    </div>
  </div>
  <script>
    async function showIpLogs(ip) {
      if (!ip) return;
      const overlay = document.getElementById('ip-modal-overlay');
      document.getElementById('ip-modal-title').textContent = 'IPアクセス履歴: ' + ip;
      document.getElementById('ip-modal-body').innerHTML = '<div id="ip-modal-loading">読み込み中...</div>';
      overlay.classList.add('open');
      try {
        const res = await fetch('/admin/ip-logs.php?ip=' + encodeURIComponent(ip));
        const data = await res.json();
        let html = '';

        html += '<div class="ip-modal-subtitle">ログイン履歴（最大30件）</div>';
        if (data.logins && data.logins.length) {
          html += '<table class="access-table"><thead><tr><th>日時</th><th>ユーザー</th><th>端末</th><th>ブラウザ</th><th>OS</th></tr></thead><tbody>';
          for (const l of data.logins) {
            html += `<tr><td style="white-space:nowrap;font-size:0.78rem;color:#8888a0;">${l.created_at.slice(0,16)}</td><td>${esc(l.username)}</td><td><span class="tag-device">${esc(l.device||'?')}</span></td><td><span class="tag-browser">${esc(l.browser||'?')}</span></td><td style="font-size:0.75rem;color:#8888a0;">${esc(l.os||'')}</td></tr>`;
          }
          html += '</tbody></table>';
        } else {
          html += '<div style="font-size:0.82rem;color:#9ba8b5;padding:6px 0;">ログイン記録なし</div>';
        }

        html += '<div class="ip-modal-subtitle" style="margin-top:20px;">アクセス履歴（最大50件）</div>';
        if (data.accesses && data.accesses.length) {
          html += '<table class="access-table"><thead><tr><th>日時</th><th>ホスト</th><th>パス</th><th>端末</th></tr></thead><tbody>';
          for (const a of data.accesses) {
            html += `<tr><td style="white-space:nowrap;font-size:0.78rem;color:#8888a0;">${a.created_at.slice(0,16)}</td><td style="font-size:0.78rem;color:#637080;">${esc(a.host)}</td><td style="font-size:0.82rem;">${esc(a.path)}</td><td><span class="tag-device">${esc(a.device||'?')}</span></td></tr>`;
          }
          html += '</tbody></table>';
        } else {
          html += '<div style="font-size:0.82rem;color:#9ba8b5;padding:6px 0;">アクセス記録なし</div>';
        }

        document.getElementById('ip-modal-body').innerHTML = html;
      } catch (e) {
        document.getElementById('ip-modal-body').innerHTML = '<div style="color:#e74c3c;padding:12px;">読み込みに失敗しました</div>';
      }
    }
    function closeIpModal(e) {
      if (e.target === document.getElementById('ip-modal-overlay')) {
        document.getElementById('ip-modal-overlay').classList.remove('open');
      }
    }
    function esc(str) {
      return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    const userMenu = document.querySelector('.user-menu');
    if (userMenu) {
      userMenu.querySelector('.user-trigger').addEventListener('click', function(e) {
        e.stopPropagation();
        userMenu.classList.toggle('open');
      });
      document.addEventListener('click', function() {
        userMenu.classList.remove('open');
      });
    }
  </script>
</body>
</html>
