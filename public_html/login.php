<?php
require_once '/home/m96/asobi.info/public_html/assets/php/auth.php';
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
if (!empty($_GET['oauth_error'])) {
    $error = htmlspecialchars($_GET['oauth_error']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (asobiAttemptLogin($username, $password)) {
        header('Location: ' . $redirect);
        exit;
    }
    $error = 'ユーザー名・メールアドレスまたはパスワードが正しくありません';
}
$oauthBase = '/oauth/start.php?mode=login&redirect=' . urlencode($redirect);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ログイン - asobi.info</title>
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
      position: relative;
      overflow: hidden;
    }

    /* 背景デコレーション */
    body::before, body::after {
      content: '';
      position: fixed;
      border-radius: 50%;
      pointer-events: none;
    }
    body::before {
      width: 400px; height: 400px;
      background: radial-gradient(circle, rgba(167,139,250,0.18) 0%, transparent 70%);
      top: -100px; left: -100px;
    }
    body::after {
      width: 350px; height: 350px;
      background: radial-gradient(circle, rgba(99,202,183,0.18) 0%, transparent 70%);
      bottom: -80px; right: -80px;
    }

    .login-box {
      background: #fff;
      border-radius: 24px;
      padding: 48px 44px;
      width: 100%;
      max-width: 390px;
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
    .subtitle {
      font-size: 0.88rem;
      color: #9ca3af;
      margin-bottom: 32px;
    }

    label {
      display: block;
      font-size: 0.8rem;
      color: #374151;
      margin-bottom: 6px;
      font-weight: 600;
    }
    input[type=text], input[type=password] {
      width: 100%;
      padding: 12px 16px;
      background: #f9fafb;
      color: #2d2d3a;
      border: 2px solid #e5e7eb;
      border-radius: 10px;
      font-size: 1rem;
      margin-bottom: 18px;
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
      font-family: inherit;
    }
    input:focus {
      border-color: #a855f7;
      box-shadow: 0 0 0 3px rgba(168,85,247,0.12);
      background: #fff;
    }

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
      font-family: inherit;
      letter-spacing: 0.3px;
    }
    button[type=submit]:hover { opacity: 0.88; }
    button[type=submit]:active { transform: scale(0.98); }

    .error {
      margin-bottom: 18px;
      padding: 10px 14px;
      background: #fff1f2;
      border: 1px solid #fecdd3;
      border-radius: 10px;
      color: #e11d48;
      font-size: 0.875rem;
    }

    .links {
      margin-top: 22px;
      text-align: center;
      font-size: 0.85rem;
      color: #9ca3af;
    }
    .links a {
      color: #a855f7;
      text-decoration: none;
      font-weight: 600;
    }
    .links a:hover { text-decoration: underline; }

    .divider {
      display: flex;
      align-items: center;
      gap: 10px;
      margin: 22px 0;
      color: #d1d5db;
      font-size: 0.82rem;
    }
    .divider::before, .divider::after {
      content: '';
      flex: 1;
      height: 1px;
      background: #e5e7eb;
    }

    .social-btns { display: flex; flex-direction: column; gap: 10px; }
    .social-btn {
      display: flex;
      align-items: center;
      gap: 12px;
      width: 100%;
      padding: 11px 16px;
      border: 2px solid #e5e7eb;
      border-radius: 10px;
      background: #fff;
      font-size: 0.9rem;
      font-weight: 600;
      color: #2d2d3a;
      text-decoration: none;
      cursor: pointer;
      font-family: inherit;
      transition: border-color 0.2s, box-shadow 0.2s;
    }
    .social-btn:hover { border-color: #a855f7; box-shadow: 0 0 0 3px rgba(168,85,247,0.08); }
    .social-btn svg { width: 20px; height: 20px; flex-shrink: 0; }
    .social-btn span { flex: 1; text-align: center; }

    .back-link {
      position: fixed;
      top: 20px;
      left: 24px;
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
  </style>
</head>
<body>
<a href="<?= htmlspecialchars($redirect) ?>" class="back-link">&#8592; 戻る</a>
  <div class="login-box">
    <div class="logo">あそび</div>
    <p class="subtitle">ログインしてゲームを楽しもう</p>

    <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
      <label for="username">ユーザー名またはメールアドレス</label>
      <input type="text" id="username" name="username"
             value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
             autocomplete="username" autofocus required>
      <label for="password">パスワード</label>
      <input type="password" id="password" name="password"
             autocomplete="current-password" required>
      <button type="submit">ログイン</button>
    </form>

    <div class="links">
      アカウントをお持ちでない方は
      <a href="/register.php<?= $redirect ? '?redirect=' . urlencode($redirect) : '' ?>">新規登録</a>
    </div>

    <div class="divider">または</div>

    <div class="social-btns">
      <a href="<?= $oauthBase ?>&provider=google" class="social-btn">
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
        <span>Google でログイン</span>
      </a>
      <a href="<?= $oauthBase ?>&provider=line" class="social-btn">
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.281.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314" fill="#06C755"/></svg>
        <span>LINE でログイン</span>
      </a>
      <a href="<?= $oauthBase ?>&provider=twitter" class="social-btn">
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.261 5.636zm-1.161 17.52h1.833L7.084 4.126H5.117z" fill="#000"/></svg>
        <span>X (Twitter) でログイン</span>
      </a>
    </div>
  </div>
</body>
</html>
