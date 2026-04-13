<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
require_once '/opt/asobi/shared/assets/php/version.php';
asobiLogAccess();

$redirect = '';
if (!empty($_GET['redirect'])) {
    $r = $_GET['redirect'];
    if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?asobi\.info(\/|$)/i', $r)) {
        $redirect = $r;
    }
}
if (empty($redirect)) {
    $redirect = 'https://asobi.info/';
}

if (asobiIsLoggedIn()) {
    header('Location: ' . $redirect);
    exit;
}

$error = '';
$notice = '';
$values = ['username' => '', 'display_name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username    = trim($_POST['username'] ?? '');
    $displayName = trim($_POST['display_name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $password    = $_POST['password'] ?? '';
    $password2   = $_POST['password2'] ?? '';

    $values = ['username' => $username, 'display_name' => $displayName, 'email' => $email];

    if (empty($_POST['agree_terms'])) {
        $error = '利用規約への同意が必要です';
    } elseif ($password !== $password2) {
        $error = 'パスワードが一致しません';
    } else {
        $check = asobiCheckBanned($username, 'username');
        if ($check['blocked']) {
            $error = 'そのユーザー名は使用できません';
        } elseif ($displayName !== '') {
            $check2 = asobiCheckBanned($displayName, 'username');
            if ($check2['blocked']) $error = 'その表示名は使用できません';
        }
        if (!$error) {
            $result = asobiRegisterUser($username, $password, $email, $displayName);
            if ($result === true) {
                asobiAttemptLogin($username, $password);
                if (!empty($email)) {
                    $userId = asobiGetCurrentUser()['id'] ?? null;
                    if ($userId) asobiSendVerificationEmail($userId, $email);
                    $notice = '確認メールを ' . htmlspecialchars($email) . ' に送信しました。メール内のリンクをクリックして確認してください。';
                } else {
                    header('Location: ' . $redirect);
                    exit;
                }
            } else {
                $error = $result;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>新規登録 - asobi.info</title>
  <link rel="canonical" href="https://asobi.info/register.php">
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
      padding: 60px 16px 40px;
      position: relative;
      overflow-x: hidden;
    }
    body::before, body::after {
      content: '';
      position: fixed;
      border-radius: 50%;
      pointer-events: none;
    }
    body::before {
      width: 450px; height: 450px;
      background: radial-gradient(circle, rgba(167,139,250,0.16) 0%, transparent 70%);
      top: -120px; right: -80px;
    }
    body::after {
      width: 380px; height: 380px;
      background: radial-gradient(circle, rgba(52,211,153,0.16) 0%, transparent 70%);
      bottom: -100px; left: -60px;
    }
    .register-box {
      background: #fff;
      border-radius: 24px;
      padding: 48px 44px;
      width: 100%;
      max-width: 420px;
      box-shadow: 0 8px 40px rgba(130,100,180,0.12), 0 2px 8px rgba(0,0,0,0.06);
      position: relative;
      z-index: 1;
    }
    .logo {
      font-size: 1.8rem;
      font-weight: 800;
      margin-bottom: 4px;
      background: linear-gradient(135deg, #a855f7, #3b82f6);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    .subtitle { font-size: 0.88rem; color: #9ca3af; margin-bottom: 32px; }
    label { display: block; font-size: 0.8rem; color: #374151; margin-bottom: 6px; font-weight: 600; }
    .optional { font-size: 0.72rem; color: #9ca3af; font-weight: 400; margin-left: 4px; }
    .hint { font-size: 0.75rem; color: #6b7280; margin-top: 4px; margin-bottom: 4px; }
    input[type=text], input[type=email], input[type=password] {
      width: 100%;
      padding: 12px 16px;
      background: #f9fafb;
      color: #2d2d3a;
      border: 2px solid #e5e7eb;
      border-radius: 10px;
      font-size: 1rem;
      margin-bottom: 4px;
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
      font-family: inherit;
    }
    input:focus {
      border-color: #a855f7;
      box-shadow: 0 0 0 3px rgba(168,85,247,0.12);
      background: #fff;
    }
    .field-group { margin-bottom: 14px; }
    button[type=submit] {
      width: 100%;
      padding: 13px;
      background: linear-gradient(135deg, #a855f7, #3b82f6);
      color: #fff;
      border: none;
      border-radius: 10px;
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
      transition: opacity 0.2s, transform 0.1s;
      margin-top: 10px;
      font-family: inherit;
      letter-spacing: 0.3px;
    }
    button[type=submit]:hover { opacity: 0.88; }
    button[type=submit]:active { transform: scale(0.98); }
    .message { margin-bottom: 18px; padding: 10px 14px; border-radius: 10px; font-size: 0.875rem; }
    .message.error  { background: #fff1f2; border: 1px solid #fecdd3; color: #e11d48; }
    .message.notice { background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; line-height: 1.5; }
    .required-mark { color: #f472b6; }
    .links { margin-top: 22px; text-align: center; font-size: 0.85rem; color: #9ca3af; }
    .links a { color: #a855f7; text-decoration: none; font-weight: 600; }
    .links a:hover { text-decoration: underline; }
    .back-link {
      position: fixed;
      top: 20px; left: 24px;
      font-size: 0.85rem;
      color: #6b7280;
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 4px;
      background: rgba(255,255,255,0.7);
      padding: 6px 14px;
      border-radius: 20px;
      backdrop-filter: blur(6px);
      transition: background 0.2s, color 0.2s;
      font-weight: 500;
      z-index: 10;
    }
    .back-link:hover { background: #fff; color: #2d2d3a; }
    @media (max-width: 480px) {
      .register-box { padding: 36px 24px; }
    }
  </style>
</head>
<body>
  <a href="/" class="back-link">&#8592; トップへ</a>

  <div class="register-box">
    <div class="logo">あそび</div>
    <p class="subtitle">新規アカウント登録</p>

    <?php if ($error): ?>
    <div class="message error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($notice): ?>
    <div class="message notice"><?= $notice ?></div>
    <div style="margin-top:20px;text-align:center;">
      <a href="<?= htmlspecialchars($redirect) ?>" style="color:#a855f7;font-weight:600;text-decoration:none;">続けてあそびを楽しむ →</a>
    </div>
    <?php else: ?>
    <form method="POST" action="">

      <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

      <div class="field-group">
        <label for="username">ユーザー名 <span class="required-mark">*</span></label>
        <input type="text" id="username" name="username"
               value="<?= htmlspecialchars($values['username']) ?>"
               autocomplete="username" autofocus required>
        <p class="hint">3〜20文字、英数字・_・- のみ使用可</p>
      </div>

      <div class="field-group">
        <label for="password">パスワード <span class="required-mark">*</span></label>
        <input type="password" id="password" name="password"
               autocomplete="new-password" required>
        <p class="hint">8文字以上</p>
      </div>

      <div class="field-group">
        <label for="password2">パスワード（確認） <span class="required-mark">*</span></label>
        <input type="password" id="password2" name="password2"
               autocomplete="new-password" required>
      </div>

      <div class="field-group">
        <label for="display_name">表示名 <span class="optional">（省略可）</span></label>
        <input type="text" id="display_name" name="display_name"
               value="<?= htmlspecialchars($values['display_name']) ?>"
               autocomplete="nickname" maxlength="50">
        <p class="hint">省略するとユーザー名が表示名になります</p>
      </div>

      <div class="field-group">
        <label for="email">メールアドレス <span class="optional">（省略可）</span></label>
        <input type="email" id="email" name="email"
               value="<?= htmlspecialchars($values['email']) ?>"
               autocomplete="email">
      </div>

      <div class="field-group" style="margin-top:18px;">
        <label style="display:flex; align-items:center; gap:8px; font-weight:400; cursor:pointer;">
          <input type="checkbox" name="agree_terms" required style="width:18px; height:18px; accent-color:#a855f7; cursor:pointer;">
          <span><a href="/terms.html" target="_blank" style="color:#a855f7; text-decoration:none; font-weight:600;">利用規約</a>に同意する</span>
        </label>
      </div>

      <button type="submit">登録してはじめる ✨</button>
    </form>
    <?php endif; ?>

    <div class="links">
      すでにアカウントをお持ちの方は
      <a href="/login.php<?= $redirect ? '?redirect=' . urlencode($redirect) : '' ?>">ログイン</a>
    </div>
  </div>
<script src="/assets/js/common.js?v=<?= assetVer('/assets/js/common.js') ?>"></script>
</body>
</html>
