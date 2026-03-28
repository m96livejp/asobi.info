<?php
/**
 * OAuth 開始エンドポイント
 * GET /oauth/start.php?provider=google&mode=login&redirect=...
 * GET /oauth/start.php?provider=google&mode=link
 */
require_once '/opt/asobi/shared/assets/php/auth.php';

$provider = $_GET['provider'] ?? '';
$mode     = $_GET['mode'] ?? 'login';
$redirect = $_GET['redirect'] ?? '';

$valid_providers = ['google', 'line', 'twitter'];
if (!in_array($provider, $valid_providers, true)) {
    http_response_code(400);
    exit('Invalid provider');
}

if (!in_array($mode, ['login', 'link'], true)) {
    $mode = 'login';
}

// link モードはログイン必須
if ($mode === 'link') {
    asobiRequireLogin();
    $redirect = 'https://asobi.info/profile.php';
} else {
    // redirect の検証
    if (!empty($redirect) && !preg_match('/^https?:\/\/([a-z0-9\-]+\.)?asobi\.info(\/|$)/i', $redirect)) {
        $redirect = '';
    }
}

// Google: WebView からのアクセスは Error 403: disallowed_useragent になるため案内ページを表示
if ($provider === 'google') {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $isWebView = false;

    // Android WebView: Chrome UA 内に "wv" が含まれる
    if (preg_match('/Android.*Chrome\/[\d\.]+ .*wv/i', $ua)) {
        $isWebView = true;
    }
    // iOS WebView: iPhone/iPad なのに "Safari/" が含まれない
    if (!$isWebView && preg_match('/iPhone|iPad/i', $ua) && !preg_match('/Safari\//i', $ua)) {
        $isWebView = true;
    }
    // 既知のアプリ内ブラウザ
    if (!$isWebView && preg_match('/FBAN|FBAV|Line\/|Instagram|Twitter/i', $ua)) {
        $isWebView = true;
    }

    if ($isWebView) {
        // このページ自体のURLを案内する（Safari で開けば正常にリダイレクト）
        $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $selfUrl  = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ブラウザで開いてください - asobi.info</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      background: #f0f2f5;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }
    .card {
      background: #fff;
      border-radius: 16px;
      padding: 36px 24px;
      max-width: 420px;
      width: 100%;
      box-shadow: 0 4px 24px rgba(0,0,0,0.10);
      text-align: center;
    }
    .icon { font-size: 3rem; margin-bottom: 16px; }
    h1 { font-size: 1.15rem; color: #1d2d3a; margin-bottom: 12px; line-height: 1.5; }
    p { font-size: 0.88rem; color: #637080; line-height: 1.75; margin-bottom: 20px; }
    .url-box {
      background: #f0f2f5;
      border-radius: 8px;
      padding: 12px 14px;
      font-size: 0.78rem;
      color: #1d2d3a;
      word-break: break-all;
      margin-bottom: 20px;
      user-select: all;
      -webkit-user-select: all;
      cursor: text;
    }
    .copy-btn {
      background: #5567cc;
      color: #fff;
      border: none;
      border-radius: 10px;
      padding: 14px 24px;
      font-size: 0.95rem;
      font-weight: 600;
      cursor: pointer;
      width: 100%;
      transition: background 0.2s;
    }
    .copy-btn.copied { background: #3eaa63; }
    .note {
      font-size: 0.78rem;
      color: #9ba8b5;
      margin-top: 16px;
      line-height: 1.6;
    }
  </style>
</head>
<body>
  <div class="card">
    <div class="icon">🌐</div>
    <h1>Safari（またはChrome）で<br>開いてください</h1>
    <p>アプリ内ブラウザからのGoogleログインは<br>Googleのポリシーにより制限されています。<br>以下のURLをコピーして外部ブラウザで<br>開いてください。</p>
    <div class="url-box" id="urlText"><?= htmlspecialchars($selfUrl) ?></div>
    <button class="copy-btn" id="copyBtn" onclick="copyUrl()">URLをコピー</button>
    <p class="note">コピー後、Safari のアドレスバーに貼り付けてアクセスしてください。</p>
  </div>
  <script>
    function copyUrl() {
      const text = document.getElementById('urlText').textContent;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(onCopied).catch(fallback);
      } else {
        fallback();
      }
    }
    function onCopied() {
      const btn = document.getElementById('copyBtn');
      btn.textContent = 'コピーしました！';
      btn.classList.add('copied');
      setTimeout(() => {
        btn.textContent = 'URLをコピー';
        btn.classList.remove('copied');
      }, 2500);
    }
    function fallback() {
      const el = document.getElementById('urlText');
      const range = document.createRange();
      range.selectNodeContents(el);
      const sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
    }
  </script>
</body>
</html>
<?php
        exit;
    }
}

$url = asobiOAuthGetUrl($provider, $mode, $redirect);
header('Location: ' . $url);
exit;
