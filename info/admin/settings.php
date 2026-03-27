<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
asobiRequireAdmin();

$success = '';
$error   = '';

// 設定定義
$settingDefs = [
    'email_verify_cooldown_minutes' => [
        'label' => '送信間隔（分）',
        'desc'  => '同じユーザーが連続して確認メールを送れない間隔',
        'unit'  => '分',
        'min'   => 1,
        'max'   => 1440,
    ],
    'email_verify_daily_limit' => [
        'label' => '送信上限（回）',
        'desc'  => 'リセット時間内に送信できる最大回数',
        'unit'  => '回',
        'min'   => 1,
        'max'   => 100,
    ],
    'email_verify_reset_hours' => [
        'label' => 'リセット時間（時間）',
        'desc'  => '送信カウントがリセットされるまでの時間',
        'unit'  => '時間',
        'min'   => 1,
        'max'   => 168,
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    foreach ($settingDefs as $key => $def) {
        $val = (int)($_POST[$key] ?? 0);
        if ($val < $def['min'] || $val > $def['max']) {
            $error = htmlspecialchars($def['label']) . ' は ' . $def['min'] . '〜' . $def['max'] . ' の範囲で入力してください';
            break;
        }
    }
    if (!$error) {
        foreach ($settingDefs as $key => $def) {
            asobiSetSetting($key, (string)(int)$_POST[$key]);
        }
        $success = '設定を保存しました';
    }
}

// 現在値を取得
$current = [];
foreach ($settingDefs as $key => $def) {
    $current[$key] = asobiGetSetting($key);
}

$currentUser = asobiGetCurrentUser();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>サイト設定 - asobi.info 管理画面</title>
  <link rel="stylesheet" href="/assets/css/common.css?v=20260327e">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Noto Sans JP', sans-serif;
      background: #f0f2f5;
      color: #1d2d3a;
      min-height: 100vh;
    }
    .site-header { background: rgba(255,255,255,0.85); border-bottom: 1px solid #e0e0e0; }
    .site-logo a { color: #1d1d1f; text-decoration: none; font-size: 1.5rem; font-weight: 700; }
    .header-right { display: flex; align-items: center; gap: 24px; }
    .site-nav ul { display: flex; list-style: none; gap: 24px; }
    .site-nav a { color: #1d1d1f; font-weight: 500; font-size: 0.9rem; }
    .admin-badge {
      display: inline-block; font-size: 0.7rem; font-weight: 700;
      padding: 2px 8px; background: linear-gradient(135deg, #e74c3c, #c0392b);
      color: #fff; border-radius: 10px; margin-left: 6px; vertical-align: middle;
    }
    @media (max-width: 768px) {
      .site-header .container { flex-direction: row; align-items: center; }
      .site-nav { display: none; }
    }

    .admin-body { max-width: 720px; margin: 0 auto; padding: 40px 24px; }
    .page-header { margin-bottom: 32px; }
    .page-header h1 { font-size: 1.4rem; font-weight: 700; }
    .page-header .breadcrumb { font-size: 0.82rem; color: #8a9bb0; margin-bottom: 8px; }
    .page-header .breadcrumb a { color: #667eea; text-decoration: none; }

    .card {
      background: #fff;
      border: 1px solid #e0e4e8;
      border-radius: 16px;
      padding: 28px 32px;
      margin-bottom: 24px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .card-title {
      font-size: 1rem;
      font-weight: 700;
      margin-bottom: 20px;
      padding-bottom: 12px;
      border-bottom: 1px solid #f0f2f5;
      color: #1d2d3a;
    }
    .setting-row {
      display: grid;
      grid-template-columns: 1fr auto;
      align-items: center;
      gap: 16px;
      padding: 16px 0;
      border-bottom: 1px solid #f5f6f8;
    }
    .setting-row:last-child { border-bottom: none; padding-bottom: 0; }
    .setting-row:first-child { padding-top: 0; }
    .setting-label { font-size: 0.9rem; font-weight: 600; color: #1d2d3a; margin-bottom: 3px; }
    .setting-desc { font-size: 0.78rem; color: #8a9bb0; }
    .input-with-unit { display: flex; align-items: center; gap: 8px; }
    .input-with-unit input[type=number] {
      width: 90px;
      padding: 8px 12px;
      border: 2px solid #e0e4e8;
      border-radius: 8px;
      font-size: 0.95rem;
      font-family: inherit;
      color: #1d2d3a;
      text-align: right;
      outline: none;
      transition: border-color 0.2s;
    }
    .input-with-unit input[type=number]:focus { border-color: #667eea; }
    .input-with-unit .unit { font-size: 0.85rem; color: #637080; white-space: nowrap; }

    .message { padding: 12px 16px; border-radius: 10px; font-size: 0.875rem; margin-bottom: 20px; }
    .message.success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
    .message.error   { background: #fff1f2; border: 1px solid #fecdd3; color: #e11d48; }

    .btn-save {
      padding: 11px 28px;
      background: linear-gradient(135deg, #667eea, #764ba2);
      color: #fff;
      border: none;
      border-radius: 10px;
      font-size: 0.95rem;
      font-weight: 700;
      cursor: pointer;
      font-family: inherit;
      transition: opacity 0.2s;
    }
    .btn-save:hover { opacity: 0.88; }
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
          </ul>
        </nav>
      </div>
    </div>
  </header>

  <div class="admin-body">
    <div class="page-header">
      <div class="breadcrumb"><a href="/admin/">管理画面</a> &rsaquo; サイト設定</div>
      <h1>サイト設定</h1>
    </div>

    <?php if ($success): ?>
      <div class="message success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="message error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <input type="hidden" name="action" value="save_settings">

      <div class="card">
        <div class="card-title">メール認証 送信制限</div>

        <?php foreach ($settingDefs as $key => $def): ?>
        <div class="setting-row">
          <div>
            <div class="setting-label"><?= htmlspecialchars($def['label']) ?></div>
            <div class="setting-desc"><?= htmlspecialchars($def['desc']) ?></div>
          </div>
          <div class="input-with-unit">
            <input type="number" name="<?= $key ?>"
                   value="<?= htmlspecialchars($current[$key]) ?>"
                   min="<?= $def['min'] ?>" max="<?= $def['max'] ?>" required>
            <span class="unit"><?= htmlspecialchars($def['unit']) ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <button type="submit" class="btn-save">保存する</button>
    </form>
  </div>

  <script src="/assets/js/common.js?v=20260327e"></script>
</body>
</html>
