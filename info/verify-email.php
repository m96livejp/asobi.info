<?php
require_once '/opt/asobi/shared/assets/php/auth.php';

$token = trim($_GET['token'] ?? '');

// 未ログインの場合はログイン後にこのページへ戻る
if (!asobiIsLoggedIn()) {
    $redirectBack = 'https://asobi.info/verify-email.php?token=' . urlencode($token);
    header('Location: https://asobi.info/login.php?redirect=' . urlencode($redirectBack));
    exit;
}

$user = asobiGetCurrentUser();
$result = $token ? asobiVerifyEmail($token, (int)$user['id']) : 'トークンが指定されていません';
$success = ($result === true);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>メールアドレスの確認 - asobi.info</title>
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
    }
    .box {
      background: #fff;
      border-radius: 24px;
      padding: 48px 44px;
      width: 100%;
      max-width: 420px;
      box-shadow: 0 8px 40px rgba(130,100,180,0.12), 0 2px 8px rgba(0,0,0,0.06);
      text-align: center;
    }
    .icon { font-size: 3rem; margin-bottom: 16px; }
    h1 { font-size: 1.3rem; font-weight: 700; margin-bottom: 12px; }
    p { font-size: 0.9rem; color: #6b7280; line-height: 1.6; margin-bottom: 28px; }
    .btn {
      display: inline-block;
      padding: 12px 28px;
      background: linear-gradient(135deg, #a855f7, #3b82f6);
      color: #fff;
      border-radius: 10px;
      text-decoration: none;
      font-weight: 700;
      font-size: 0.95rem;
    }
    .error-msg { color: #e11d48; }
  </style>
</head>
<body>
  <div class="box">
    <?php if ($success): ?>
      <div class="icon">✅</div>
      <h1>メールアドレスを確認しました</h1>
      <p>確認が完了しました。<br>引き続きあそびをお楽しみください。</p>
      <a href="/profile.php" class="btn">プロフィールへ</a>
    <?php else: ?>
      <div class="icon">❌</div>
      <h1>確認できませんでした</h1>
      <p class="error-msg"><?= htmlspecialchars($result) ?></p>
      <a href="/profile.php" class="btn">プロフィールへ</a>
    <?php endif; ?>
  </div>
<script src="/assets/js/common.js?v=20260327e"></script>
</body>
</html>
