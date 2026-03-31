<?php
/**
 * 管理画面 共通レイアウト部品
 * layout_head($title) / layout_foot() で囲む
 */
function layout_head(string $title, string $active = ''): void {
    $nav = [
        'dashboard'   => ['href' => '/admin/dashboard.php',   'label' => 'ダッシュボード', 'icon' => '🏠'],
        'ingredients' => ['href' => '/admin/ingredients.php', 'label' => '素材管理',       'icon' => '🥕'],
        'recipes'     => ['href' => '/admin/recipes.php',     'label' => '料理管理',       'icon' => '🍲'],
        'pokemon'     => ['href' => '/admin/pokemon.php',     'label' => 'ポケモン管理',   'icon' => '⚡'],
        'iv'          => ['href' => '/admin/iv.php',          'label' => '個体値データ',   'icon' => '📊'],
        'comments'    => ['href' => '/admin/comments.php',    'label' => 'コメント管理',   'icon' => '💬'],
        'voice-fixes' => ['href' => '/admin/voice-fixes.php', 'label' => '音声補正辞書',   'icon' => '🎤'],
        'settings'    => ['href' => '/admin/settings.php',    'label' => '設定',           'icon' => '⚙️'],
    ];
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($title) ?> - 管理画面</title>
  <link rel="stylesheet" href="https://asobi.info/assets/css/font.php">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'MiguFont', -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Noto Sans JP', sans-serif;
      background: #f0f2f5;
      color: #2d3436;
      min-height: 100vh;
      display: flex;
    }
    /* サイドバー */
    .sidebar {
      width: 220px;
      flex-shrink: 0;
      background: #2d3436;
      color: #dfe6e9;
      display: flex;
      flex-direction: column;
      min-height: 100vh;
      position: fixed;
      top: 0; left: 0;
    }
    .sidebar-logo {
      padding: 20px 20px 16px;
      font-size: 1rem;
      font-weight: 700;
      color: #e17055;
      border-bottom: 1px solid #3d4345;
      line-height: 1.3;
    }
    .sidebar-logo span { color: #b2bec3; font-size: 0.75rem; font-weight: 400; display: block; }
    .sidebar-nav { flex: 1; padding: 12px 0; }
    .nav-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 11px 20px;
      color: #b2bec3;
      text-decoration: none;
      font-size: 0.9rem;
      transition: background 0.15s, color 0.15s;
      border-left: 3px solid transparent;
    }
    .nav-item:hover { background: #3d4345; color: #fff; }
    .nav-item.active { background: #3d4345; color: #e17055; border-left-color: #e17055; font-weight: 600; }
    .nav-item .icon { font-size: 1rem; width: 20px; text-align: center; }
    .sidebar-footer { padding: 16px 20px; border-top: 1px solid #3d4345; display: flex; flex-direction: column; gap: 8px; }
    .logout-btn {
      display: block;
      padding: 8px 14px;
      background: transparent;
      border: 1px solid #636e72;
      border-radius: 6px;
      color: #b2bec3;
      text-decoration: none;
      font-size: 0.85rem;
      text-align: center;
      transition: all 0.15s;
    }
    .logout-btn:hover { border-color: #e17055; color: #e17055; }
    /* メインコンテンツ */
    .main {
      margin-left: 220px;
      flex: 1;
      display: flex;
      flex-direction: column;
      min-height: 100vh;
    }
    .topbar {
      background: #fff;
      border-bottom: 1px solid #e0e0e0;
      padding: 14px 28px;
      font-size: 1.1rem;
      font-weight: 600;
      color: #2d3436;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .content { padding: 28px; flex: 1; }
    /* カード */
    .card {
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.06);
      margin-bottom: 24px;
    }
    .card-header {
      padding: 16px 20px;
      border-bottom: 1px solid #f0f0f0;
      font-weight: 600;
      font-size: 0.95rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .card-body { padding: 20px; }
    /* テーブル */
    .tbl { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
    .tbl th {
      background: #f8f9fa;
      padding: 10px 12px;
      text-align: left;
      font-weight: 600;
      color: #636e72;
      border-bottom: 2px solid #e0e0e0;
      white-space: nowrap;
    }
    .tbl td {
      padding: 10px 12px;
      border-bottom: 1px solid #f0f0f0;
      vertical-align: middle;
    }
    .tbl tr:hover td { background: #fafafa; }
    /* ボタン */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 7px 14px;
      border-radius: 6px;
      border: none;
      font-size: 0.85rem;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      transition: opacity 0.15s, transform 0.1s;
    }
    .btn:hover { opacity: 0.85; }
    .btn:active { transform: scale(0.97); }
    .btn-primary { background: #e17055; color: #fff; }
    .btn-secondary { background: #636e72; color: #fff; }
    .btn-sm { padding: 4px 10px; font-size: 0.8rem; }
    .btn-success { background: #00b894; color: #fff; }
    .btn-danger { background: #d63031; color: #fff; }
    /* バッジ */
    .badge {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 12px;
      font-size: 0.75rem;
      font-weight: 600;
    }
    .badge-red    { background: #ff7675; color: #fff; }
    .badge-blue   { background: #74b9ff; color: #fff; }
    .badge-yellow { background: #fdcb6e; color: #333; }
    .badge-gray   { background: #b2bec3; color: #fff; }
    .badge-rainbow{ background: linear-gradient(90deg,#ff7675,#fdcb6e,#55efc4,#74b9ff); color: #fff; }
    .badge-common { background: #dfe6e9; color: #636e72; }
    .badge-rare   { background: #a29bfe; color: #fff; }
    /* フォーム */
    .form-group { margin-bottom: 16px; }
    .form-label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; color: #2d3436; }
    .form-control {
      width: 100%;
      padding: 9px 12px;
      border: 2px solid #e0e0e0;
      border-radius: 8px;
      font-size: 0.9rem;
      outline: none;
      transition: border-color 0.2s;
      background: #fff;
      color: #2d3436;
    }
    .form-control:focus { border-color: #e17055; }
    select.form-control { cursor: pointer; }
    /* アラート */
    .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem; }
    .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .alert-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    /* モーダル */
    .modal-overlay {
      display: none;
      position: fixed; inset: 0;
      background: rgba(0,0,0,0.5);
      z-index: 1000;
      align-items: center;
      justify-content: center;
    }
    .modal-overlay.open { display: flex; }
    .modal {
      background: #fff;
      border-radius: 12px;
      padding: 28px;
      width: 100%;
      max-width: 540px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 16px 48px rgba(0,0,0,0.2);
    }
    .modal-title { font-size: 1.1rem; font-weight: 700; margin-bottom: 20px; }
    .modal-footer { display: flex; gap: 10px; justify-content: flex-end; margin-top: 24px; }
    /* ステータス通知 */
    #toast {
      position: fixed;
      bottom: 24px; right: 24px;
      padding: 12px 20px;
      border-radius: 8px;
      font-size: 0.9rem;
      font-weight: 600;
      opacity: 0;
      transition: opacity 0.3s;
      z-index: 9999;
      pointer-events: none;
    }
    #toast.show { opacity: 1; }
    #toast.success { background: #00b894; color: #fff; }
    #toast.error   { background: #d63031; color: #fff; }
  </style>
</head>
<body>
  <aside class="sidebar">
    <div class="sidebar-logo">
      🍳 ポケクエ管理
      <span>pkq.asobi.info</span>
    </div>
    <nav class="sidebar-nav">
      <?php foreach ($nav as $key => $item): ?>
        <a href="<?= $item['href'] ?>" class="nav-item <?= $active === $key ? 'active' : '' ?>">
          <span class="icon"><?= $item['icon'] ?></span>
          <?= $item['label'] ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
      <a href="https://pkq.asobi.info/" class="logout-btn">← サイトトップ</a>
      <a href="https://asobi.info/" class="logout-btn">asobi.info</a>
      <a href="https://asobi.info/logout.php?redirect=https://pkq.asobi.info/" class="logout-btn">ログアウト</a>
    </div>
  </aside>
  <div class="main">
    <div class="topbar">
      <span><?= htmlspecialchars($title) ?></span>
      <div id="asobi-user-area"></div>
    </div>
    <div class="content">
    <div id="toast"></div>
<?php
}

function layout_foot(): void {
    ?>
    </div><!-- .content -->
  </div><!-- .main -->
  <script>
  function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'show ' + type;
    setTimeout(() => { t.className = ''; }, 2800);
  }
  async function adminApi(action, data = {}) {
    const res = await fetch('/admin/api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, ...data })
    });
    return res.json();
  }
  </script>
  <script src="https://asobi.info/assets/js/common.js?v=20260327h"></script>
</body>
</html>
<?php
}
