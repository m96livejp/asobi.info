<?php
/**
 * 管理画面サイドバー共通パーツ
 *
 * 使い方:
 *   $adminActivePage = 'users';   // 現在のページID
 *   require __DIR__ . '/_sidebar.php';
 *   // ... ページ本体 ...
 *   // 末尾に </main></div> を書く
 *
 * ページID: dashboard, users, banned-words, settings, font, todos, content-design, api-status, backup, access-stats, access-logs
 */
$adminActivePage = $adminActivePage ?? '';
function _adminSbActive(string $page): string {
    global $adminActivePage;
    return $adminActivePage === $page ? ' active' : '';
}
?>
<style>
  /* ── ヘッダー（共通） ── */
  .site-header { background: rgba(255,255,255,0.85); border-bottom: 1px solid #e0e0e0; }
  .site-logo a { color: #1d1d1f; text-decoration: none; font-size: 1.5rem; font-weight: 700; }
  .admin-badge {
    display: inline-block; font-size: 0.7rem; font-weight: 700;
    padding: 2px 8px; background: linear-gradient(135deg, #e74c3c, #c0392b);
    color: #fff; border-radius: 10px; margin-left: 6px; vertical-align: middle;
  }

  /* ── サイドバーレイアウト（共通） ── */
  .admin-layout { display: flex; flex: 1; min-height: 0; }
  .admin-sidebar {
    width: 220px; background: #fff; border-right: 1px solid #e0e4e8;
    padding: 20px 0; flex-shrink: 0; overflow-y: auto;
  }
  .sidebar-section { margin-bottom: 20px; }
  .sidebar-label {
    font-size: 0.7rem; font-weight: 700; color: #9ba8b5;
    text-transform: uppercase; letter-spacing: 0.06em;
    padding: 0 20px; margin-bottom: 6px;
  }
  .sidebar-link {
    display: flex; align-items: center; gap: 8px;
    padding: 9px 20px; font-size: 0.85rem; color: #1d2d3a;
    text-decoration: none; transition: background 0.15s; white-space: nowrap;
  }
  .sidebar-link:hover { background: #f5f7fa; }
  .sidebar-link.active { background: #eef1f8; color: #5567cc; font-weight: 600; }
  .sidebar-link .sb-icon { font-size: 1rem; width: 22px; text-align: center; flex-shrink: 0; }
  .sidebar-divider { height: 1px; background: #e0e4e8; margin: 12px 16px; }

  .sidebar-mobile-toggle {
    display: none; padding: 10px 16px; background: #fff;
    border-bottom: 1px solid #e0e4e8; font-size: 0.85rem;
    font-weight: 600; color: #1d2d3a; cursor: pointer;
  }
  .sidebar-mobile-toggle::after { content: ' \25BC'; font-size: 0.7rem; opacity: 0.5; }

  @media (max-width: 768px) {
    .admin-layout { flex-direction: column; }
    .admin-sidebar {
      width: 100%; border-right: none;
      border-bottom: 1px solid #e0e4e8; padding: 0; display: none;
    }
    .admin-sidebar.open { display: block; padding: 12px 0; }
    .sidebar-mobile-toggle { display: block; }
  }

  .admin-main {
    flex: 1; padding: 28px 32px; min-width: 0; overflow-y: auto;
  }
  @media (max-width: 768px) {
    .admin-main { padding: 20px 16px; }
  }
</style>

<header class="site-header">
  <div class="container">
    <div class="site-logo"><a href="/admin/">あそび<span class="admin-badge">ADMIN</span></a></div>
  </div>
</header>
<!-- common.js が .site-header .container にユーザーメニューを自動注入 -->

<div class="sidebar-mobile-toggle" onclick="document.querySelector('.admin-sidebar').classList.toggle('open')">メニュー</div>

<div class="admin-layout">
  <!-- サイドバー -->
  <nav class="admin-sidebar">
    <div class="sidebar-section">
      <div class="sidebar-label">管理</div>
      <a href="/admin/" class="sidebar-link<?= _adminSbActive('dashboard') ?>">
        <span class="sb-icon">📊</span>ダッシュボード
      </a>
      <a href="/admin/users.php" class="sidebar-link<?= _adminSbActive('users') ?>">
        <span class="sb-icon">👥</span>ユーザー管理
      </a>
      <a href="/admin/banned-words.php" class="sidebar-link<?= _adminSbActive('banned-words') ?>">
        <span class="sb-icon">🚫</span>禁止ワード管理
      </a>
      <a href="/admin/settings.php" class="sidebar-link<?= _adminSbActive('settings') ?>">
        <span class="sb-icon">⚙️</span>サイト設定
      </a>
      <a href="/admin/font.php" class="sidebar-link<?= _adminSbActive('font') ?>">
        <span class="sb-icon">🔤</span>フォント設定
      </a>
      <a href="/admin/todos.php" class="sidebar-link<?= _adminSbActive('todos') ?>">
        <span class="sb-icon">📝</span>TODO管理
      </a>
      <a href="/admin/content-design.php" class="sidebar-link<?= _adminSbActive('content-design') ?>">
        <span class="sb-icon">🗂️</span>コンテンツ構成
      </a>
      <a href="/admin/api-status.php" class="sidebar-link<?= _adminSbActive('api-status') ?>">
        <span class="sb-icon">🔌</span>API接続確認
      </a>
      <a href="/admin/backup.php" class="sidebar-link<?= _adminSbActive('backup') ?>">
        <span class="sb-icon">💾</span>バックアップ管理
      </a>
      <a href="/admin/access-stats.php" class="sidebar-link<?= _adminSbActive('access-stats') ?>">
        <span class="sb-icon">📊</span>アクセス統計
      </a>
      <a href="/admin/access-logs.php" class="sidebar-link<?= _adminSbActive('access-logs') ?>">
        <span class="sb-icon">📋</span>アクセス生ログ
      </a>
    </div>
    <div class="sidebar-divider"></div>
    <div class="sidebar-section">
      <div class="sidebar-label">コンテンツ</div>
      <a href="https://aic.asobi.info/admin.html" class="sidebar-link" target="_blank">
        <span class="sb-icon">🤖</span>AI チャット
      </a>
      <a href="https://tbt.asobi.info/admin/" class="sidebar-link" target="_blank">
        <span class="sb-icon">⚔️</span>Tournament Battle
      </a>
      <a href="https://dbd.asobi.info/admin/" class="sidebar-link" target="_blank">
        <span class="sb-icon">🔪</span>Dead by Daylight
      </a>
      <a href="https://pkq.asobi.info/admin/" class="sidebar-link" target="_blank">
        <span class="sb-icon">🎮</span>ポケモンクエスト
      </a>
    </div>
    <div class="sidebar-divider"></div>
    <div class="sidebar-section">
      <a href="/" class="sidebar-link">
        <span class="sb-icon">🏠</span>サイトトップ
      </a>
    </div>
  </nav>

  <!-- メインコンテンツ -->
  <main class="admin-main">
