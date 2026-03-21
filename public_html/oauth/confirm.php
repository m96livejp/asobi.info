<?php
/**
 * OAuth 新規アカウント作成確認ページ
 */
require_once '/home/m96/asobi.info/public_html/assets/php/auth.php';

// セッションに pending データがなければエラー
if (empty($_SESSION['oauth_pending'])) {
    header('Location: https://asobi.info/login.php');
    exit;
}

$pending = $_SESSION['oauth_pending'];
$providerNames = ['google' => 'Google', 'line' => 'LINE', 'twitter' => 'X (Twitter)'];
$providerName  = $providerNames[$pending['provider']] ?? $pending['provider'];
$redirectTo    = $pending['redirect_to'] ?? 'https://asobi.info/';

// キャンセル
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    unset($_SESSION['oauth_pending']);
    header('Location: https://asobi.info/login.php');
    exit;
}

// 新規作成を確定
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $db = asobiUsersDb();

    $baseUsername = _oauthConfirmGenerateUsername($pending['provider'], $pending);
    $username = $baseUsername;
    $suffix = 2;
    while (true) {
        $chk = $db->prepare("SELECT id FROM users WHERE username = ?");
        $chk->execute([$username]);
        if (!$chk->fetch()) break;
        $username = $baseUsername . $suffix++;
    }

    $displayName = $pending['display_name'] ?: $username;
    $db->prepare("INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)")
       ->execute([
           $username,
           $pending['email'] ?: null,
           password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
           $displayName,
       ]);
    $newUserId = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO social_accounts (user_id, provider, provider_id, email, display_name, username) VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$newUserId, $pending['provider'], $pending['provider_id'], $pending['email'], $pending['display_name'], $pending['username']]);

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$newUserId]);
    $newUser = $stmt->fetch();
    asobiLoginFromUserRow($newUser);

    unset($_SESSION['oauth_pending']);
    header('Location: ' . $redirectTo);
    exit;
}

function _oauthConfirmGenerateUsername(string $provider, array $info): string {
    if (!empty($info['username'])) {
        $base = preg_replace('/[^a-zA-Z0-9_\-]/', '', $info['username']);
        if (strlen($base) >= 3) return substr($base, 0, 18);
    }
    return $provider . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>アカウント作成の確認 - asobi.info</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Noto Sans JP', sans-serif;
      background: linear-gradient(135deg, #fce4f6 0%, #e8f4fe 50%, #e4fce8 100%);
      color: #2d2d3a;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 40px 16px;
    }
    .box {
      background: #fff;
      border-radius: 24px;
      padding: 44px 40px;
      width: 100%;
      max-width: 420px;
      box-shadow: 0 8px 40px rgba(130,100,180,0.12), 0 2px 8px rgba(0,0,0,0.06);
    }
    .logo {
      font-size: 1.4rem;
      font-weight: 800;
      margin-bottom: 24px;
      background: linear-gradient(135deg, #a855f7, #3b82f6);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    h1 { font-size: 1.1rem; font-weight: 700; margin-bottom: 10px; }
    .notice {
      background: #fffbeb;
      border: 1px solid #fde68a;
      border-radius: 10px;
      padding: 12px 14px;
      font-size: 0.85rem;
      color: #92400e;
      margin-bottom: 20px;
      line-height: 1.6;
    }
    .info-card {
      background: #f9fafb;
      border: 1px solid #e5e7eb;
      border-radius: 10px;
      padding: 14px 16px;
      margin-bottom: 24px;
      font-size: 0.88rem;
    }
    .info-card .row { display: flex; gap: 8px; margin-bottom: 6px; }
    .info-card .row:last-child { margin-bottom: 0; }
    .info-card .key { color: #6b7280; min-width: 80px; }
    .info-card .val { color: #2d2d3a; font-weight: 500; }
    .actions { display: flex; flex-direction: column; gap: 10px; }
    .btn-create {
      width: 100%;
      padding: 13px;
      background: linear-gradient(135deg, #a855f7, #3b82f6);
      color: #fff;
      border: none;
      border-radius: 10px;
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
      font-family: inherit;
      transition: opacity 0.2s;
    }
    .btn-create:hover { opacity: 0.88; }
    .btn-cancel {
      width: 100%;
      padding: 11px;
      background: #fff;
      color: #6b7280;
      border: 2px solid #e5e7eb;
      border-radius: 10px;
      font-size: 0.9rem;
      font-weight: 600;
      cursor: pointer;
      font-family: inherit;
      transition: border-color 0.2s, color 0.2s;
    }
    .btn-cancel:hover { border-color: #9ca3af; color: #2d2d3a; }
    .login-link {
      margin-top: 14px;
      text-align: center;
      font-size: 0.85rem;
      color: #9ca3af;
      white-space: nowrap;
    }
    .login-link a { color: #a855f7; font-weight: 600; text-decoration: none; }
    .login-link a:hover { text-decoration: underline; }
  </style>
</head>
<body>
  <div class="box">
    <div class="logo">あそび</div>
    <h1>新規アカウントを作成しますか？</h1>

    <div class="notice">
      <?= htmlspecialchars($providerName) ?> でログインしようとしましたが、連携済みのアカウントが見つかりませんでした。<br>
      すでに別の方法でアカウントをお持ちの場合は、ログインして連携することができます。
    </div>

    <div class="info-card">
      <div class="row">
        <span class="key">プロバイダー</span>
        <span class="val"><?= htmlspecialchars($providerName) ?></span>
      </div>
      <?php if ($pending['display_name']): ?>
      <div class="row">
        <span class="key">表示名</span>
        <span class="val"><?= htmlspecialchars($pending['display_name']) ?></span>
      </div>
      <?php endif; ?>
      <?php if ($pending['email']): ?>
      <div class="row">
        <span class="key">メール</span>
        <span class="val"><?= htmlspecialchars($pending['email']) ?></span>
      </div>
      <?php endif; ?>
    </div>

    <div class="actions">
      <form method="POST" action="">
        <input type="hidden" name="action" value="create">
        <button type="submit" class="btn-create">新規アカウントを作成する</button>
      </form>
      <form method="POST" action="">
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="btn-cancel">キャンセル</button>
      </form>
    </div>

    <div class="login-link">
      既存のアカウントでログインして連携する場合は
      <a href="/login.php">こちら</a>
    </div>
  </div>
</body>
</html>
