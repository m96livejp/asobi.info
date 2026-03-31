<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
asobiRequireAdmin();

$db = asobiUsersDb();
$totalUsers  = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$adminCount  = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$todayCount  = $db->query("SELECT COUNT(*) FROM users WHERE date(created_at) = date('now','localtime')")->fetchColumn();
$activeCount = $db->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
$currentUser = asobiGetCurrentUser();

// PV集計のみ
$pvToday = $db->query("SELECT COUNT(*) FROM access_logs WHERE date(created_at) = date('now','localtime')")->fetchColumn();
$pvWeek  = $db->query("SELECT COUNT(*) FROM access_logs WHERE created_at >= datetime('now','localtime','-7 days')")->fetchColumn();
$pvMonth = $db->query("SELECT COUNT(*) FROM access_logs WHERE strftime('%Y-%m', created_at) = strftime('%Y-%m', datetime('now','localtime'))")->fetchColumn();
$pvTotal = $db->query("SELECT COUNT(*) FROM access_logs")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>管理画面 - asobi.info</title>
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

    /* PVコンパクト表示 */
    .pv-compact {
      display: flex;
      flex-wrap: wrap;
      gap: 4px 16px;
      font-size: 0.75rem;
      color: #9ba8b5;
      margin-bottom: 24px;
    }
    .pv-compact span {
      white-space: nowrap;
    }
    .pv-compact .pv-num {
      font-weight: 600;
      color: #637080;
    }

    /* ユーザー統計カード */
    h1 {
      font-size: 1.3rem;
      margin-bottom: 20px;
      color: #1d2d3a;
    }
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 16px;
      margin-bottom: 32px;
    }
    .stat-card {
      background: #fff;
      border: 1px solid #e0e4e8;
      border-radius: 12px;
      padding: 20px 18px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .stat-label {
      font-size: 0.78rem;
      color: #637080;
      margin-bottom: 6px;
    }
    .stat-value {
      font-size: 1.8rem;
      font-weight: 700;
      color: #1d2d3a;
    }
    .stat-value.accent { color: #5567cc; }

    .section-title {
      font-size: 0.95rem;
      font-weight: 600;
      color: #637080;
      margin-bottom: 14px;
      padding-bottom: 8px;
      border-bottom: 1px solid #e0e4e8;
    }

    /* メニューカード */
    .menu-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 14px;
    }
    .menu-card {
      background: #fff;
      border: 1px solid #e0e4e8;
      border-radius: 12px;
      padding: 20px 18px;
      text-decoration: none;
      color: #1d2d3a;
      transition: border-color 0.2s, transform 0.2s;
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .menu-card:hover {
      border-color: #667eea;
      transform: translateY(-2px);
    }
    .menu-card-icon { font-size: 1.6rem; margin-bottom: 10px; }
    .menu-card-title { font-weight: 600; font-size: 0.9rem; margin-bottom: 3px; }
    .menu-card-desc { font-size: 0.78rem; color: #637080; }

    .menu-card.tbt:hover { border-color: #f7d94e; }
    .menu-card.dbd:hover { border-color: #e74c3c; }
    .menu-card.pq:hover  { border-color: #f093fb; }
    .menu-card.aic:hover { border-color: #6edd8a; }

    .content-section { margin-top: 32px; }
  </style>
</head>
<body>
  <?php $adminActivePage = 'dashboard'; require __DIR__ . '/_sidebar.php'; ?>

      <!-- PV コンパクト表示 -->
      <div class="pv-compact">
        <span>PV: 本日 <span class="pv-num"><?= number_format($pvToday) ?></span></span>
        <span>週間 <span class="pv-num"><?= number_format($pvWeek) ?></span></span>
        <span>月間 <span class="pv-num"><?= number_format($pvMonth) ?></span></span>
        <span>累計 <span class="pv-num"><?= number_format($pvTotal) ?></span></span>
      </div>

      <h1>ダッシュボード</h1>

      <!-- ユーザー統計 -->
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

      <!-- 管理メニュー -->
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
        <a href="/admin/access-stats.php" class="menu-card">
          <div class="menu-card-icon">📊</div>
          <div class="menu-card-title">アクセス統計</div>
          <div class="menu-card-desc">PV/UVグラフ・人気ページ・ブラウザ統計</div>
        </a>
        <a href="/admin/todos.php" class="menu-card">
          <div class="menu-card-icon">📝</div>
          <div class="menu-card-title">TODO管理</div>
          <div class="menu-card-desc">タスク・対応状況・優先度管理</div>
        </a>
        <a href="/admin/font.php" class="menu-card">
          <div class="menu-card-icon">🔤</div>
          <div class="menu-card-title">フォント設定</div>
          <div class="menu-card-desc">サイト共通Webフォントの切り替え</div>
        </a>
        <a href="/admin/content-design.php" class="menu-card">
          <div class="menu-card-icon">🗂️</div>
          <div class="menu-card-title">コンテンツ構成</div>
          <div class="menu-card-desc">サイト構成・ページ設計管理</div>
        </a>
        <a href="/admin/api-status.php" class="menu-card">
          <div class="menu-card-icon">🔌</div>
          <div class="menu-card-title">API接続確認</div>
          <div class="menu-card-desc">外部API・サービスの疎通チェック</div>
        </a>
        <a href="/admin/backup.php" class="menu-card">
          <div class="menu-card-icon">💾</div>
          <div class="menu-card-title">バックアップ管理</div>
          <div class="menu-card-desc">DBバックアップ・ダウンロード</div>
        </a>
      </div>

      <!-- コンテンツ管理 -->
      <div class="content-section">
        <div class="section-title">コンテンツ管理</div>
        <div class="menu-grid">
          <a href="https://aic.asobi.info/admin.html" class="menu-card aic" target="_blank">
            <div class="menu-card-icon">🤖</div>
            <div class="menu-card-title">AI チャット</div>
            <div class="menu-card-desc">aic.asobi.info 管理画面</div>
          </a>
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
    </main>
  </div>

  <script src="/assets/js/common.js?v=20260327h"></script>
</body>
</html>
