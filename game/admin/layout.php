<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
asobiRequireAdmin();
session_write_close();

$currentPath = $_SERVER['REQUEST_URI'] ?? '';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' - ' : '' ?>管理 | あそびゲーム情報</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #0f1117; --bg2: #1a1d27; --bg3: #22263a;
      --text: #e0e0f0; --text2: #8899bb; --border: #2a2f4a;
      --accent: #7c6aee; --success: #4caf85; --warn: #f0a030; --danger: #e05060;
    }
    body { font-family: system-ui,-apple-system,sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; }

    .admin-header { background: var(--bg2); border-bottom: 1px solid var(--border); padding: 0 20px; height: 52px; display: flex; align-items: center; gap: 16px; }
    .admin-header a.logo { font-weight: 700; color: var(--accent); text-decoration: none; font-size: 1.1rem; }
    .admin-header .site-link { margin-left: auto; font-size: 0.82rem; color: var(--text2); text-decoration: none; }

    .admin-body { display: flex; flex: 1; }
    .admin-sidebar { width: 200px; background: var(--bg2); border-right: 1px solid var(--border); padding: 16px 0; flex-shrink: 0; }
    .admin-sidebar a {
      display: block; padding: 9px 20px; font-size: 0.88rem; color: var(--text2);
      text-decoration: none; transition: background 0.15s, color 0.15s;
    }
    .admin-sidebar a:hover, .admin-sidebar a.active { background: var(--bg3); color: var(--text); }
    .admin-sidebar .nav-section { font-size: 0.7rem; color: var(--text2); padding: 12px 20px 4px; text-transform: uppercase; letter-spacing: 1px; }

    .admin-content { flex: 1; padding: 24px; overflow-x: auto; }
    .page-title { font-size: 1.3rem; font-weight: 700; margin-bottom: 20px; }

    .card { background: var(--bg2); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 18px; }
    table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    th { text-align: left; padding: 8px 12px; border-bottom: 2px solid var(--border); color: var(--text2); font-weight: 600; white-space: nowrap; }
    td { padding: 8px 12px; border-bottom: 1px solid var(--border); vertical-align: top; }
    tr:last-child td { border-bottom: none; }

    .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
    .badge-pending  { background: rgba(240,160,48,0.2); color: var(--warn); }
    .badge-approved { background: rgba(76,175,133,0.2); color: var(--success); }
    .badge-rejected { background: rgba(224,80,96,0.2);  color: var(--danger); }

    .btn { display: inline-block; padding: 6px 14px; border-radius: 6px; font-size: 0.82rem; cursor: pointer; border: none; font-weight: 600; text-decoration: none; transition: opacity 0.2s; }
    .btn:hover { opacity: 0.82; }
    .btn-primary  { background: var(--accent); color: #fff; }
    .btn-success  { background: var(--success); color: #fff; }
    .btn-danger   { background: var(--danger);  color: #fff; }
    .btn-sm       { padding: 4px 10px; font-size: 0.78rem; }

    input[type=text], input[type=number], select, textarea {
      background: var(--bg); border: 1px solid var(--border); color: var(--text);
      border-radius: 6px; padding: 8px 12px; font-size: 0.88rem; width: 100%;
      outline: none; font-family: inherit;
    }
    input:focus, select:focus, textarea:focus { border-color: var(--accent); }
    .form-row { margin-bottom: 14px; }
    .form-label { font-size: 0.82rem; color: var(--text2); margin-bottom: 5px; display: block; }
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

    .msg { padding: 10px 14px; border-radius: 6px; font-size: 0.88rem; margin-bottom: 14px; }
    .msg-ok  { background: rgba(76,175,133,0.15); color: var(--success); }
    .msg-err { background: rgba(224,80,96,0.15);  color: var(--danger); }
  </style>
</head>
<body>
  <header class="admin-header">
    <a class="logo" href="/admin/">ゲーム情報 管理</a>
    <a class="site-link" href="/" target="_blank">← サイトへ</a>
  </header>
  <div class="admin-body">
    <aside class="admin-sidebar">
      <div class="nav-section">コンテンツ</div>
      <a href="/admin/" class="<?= str_starts_with($currentPath, '/admin/') && !str_contains($currentPath, 'games') && !str_contains($currentPath, 'comments') ? 'active' : '' ?>">ダッシュボード</a>
      <a href="/admin/games.php" class="<?= str_contains($currentPath, 'games') ? 'active' : '' ?>">ゲーム管理</a>
      <a href="/admin/featured.php" class="<?= str_contains($currentPath, 'featured') ? 'active' : '' ?>">注目ゲーム</a>
      <a href="/admin/comments.php" class="<?= str_contains($currentPath, 'comments') ? 'active' : '' ?>">コメント審査</a>
      <div class="nav-section">リンク</div>
      <a href="https://asobi.info/admin/" target="_blank">あそび管理</a>
    </aside>
    <main class="admin-content">
