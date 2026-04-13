<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
require_once '/opt/asobi/shared/assets/php/version.php';
asobiRequireAdmin();

$success = '';
$error   = '';

// セッション設定定義
$sessionDefs = [
    'session_cookie_lifetime' => [
        'label'   => 'セッション有効期限（秒）',
        'desc'    => 'ブラウザを閉じてもログイン状態を維持する期間。0 = ブラウザを閉じると期限切れ。',
        'default' => '2592000',
        'options' => [
            '0'       => 'ブラウザを閉じると期限切れ',
            '86400'   => '1日',
            '604800'  => '7日',
            '2592000' => '30日（推奨）',
            '7776000' => '90日',
        ],
    ],
];

// メール認証 設定定義
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

// SMTP設定定義
$smtpDefs = [
    'smtp_host'     => ['label' => 'SMTPホスト',    'desc' => 'メール送信サーバーのホスト名', 'type' => 'text', 'placeholder' => 'sv6112.wpx.ne.jp'],
    'smtp_port'     => ['label' => 'ポート',         'desc' => 'SMTP接続ポート（通常 587）',  'type' => 'number', 'placeholder' => '587'],
    'smtp_user'     => ['label' => 'ユーザー名',     'desc' => 'SMTP認証のユーザー名',        'type' => 'text', 'placeholder' => 'noreply@asobi.info'],
    'smtp_password' => ['label' => 'パスワード',     'desc' => 'SMTP認証のパスワード',        'type' => 'password', 'placeholder' => ''],
    'smtp_from'     => ['label' => '送信元アドレス', 'desc' => 'Fromに使用するメールアドレス', 'type' => 'text', 'placeholder' => 'noreply@asobi.info'],
];

// msmtprc を更新する関数
function updateMsmtprc(string $host, int $port, string $user, string $password, string $from): bool|string {
    $conf = "# msmtp configuration for asobi.info (auto-generated)\n"
          . "defaults\n"
          . "auth           on\n"
          . "tls            on\n"
          . "tls_starttls   on\n"
          . "tls_trust_file /etc/ssl/certs/ca-certificates.crt\n"
          . "logfile        /var/log/msmtp.log\n\n"
          . "account        default\n"
          . "host           {$host}\n"
          . "port           {$port}\n"
          . "from           {$from}\n"
          . "user           {$user}\n"
          . "password       {$password}\n";
    $path = '/etc/msmtprc';
    if (@file_put_contents($path, $conf) === false) {
        return 'msmtprc の書き込みに失敗しました';
    }
    chmod($path, 0640);
    chgrp($path, 'www-data');
    return true;
}

// ── POST 処理 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_session') {
        $key = 'session_cookie_lifetime';
        $val = $_POST[$key] ?? '';
        $allowed = array_keys($sessionDefs[$key]['options']);
        if (!in_array($val, $allowed, true)) {
            $error = '不正な値です';
        } else {
            asobiSetSetting($key, $val);
            $success = 'セッション設定を保存しました';
        }
    }

    if ($action === 'save_settings') {
        // メール認証設定の保存
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
            $success = 'メール認証設定を保存しました';
        }
    }

    if ($action === 'save_smtp') {
        $smtpHost = trim($_POST['smtp_host'] ?? '');
        $smtpPort = (int)($_POST['smtp_port'] ?? 587);
        $smtpUser = trim($_POST['smtp_user'] ?? '');
        $smtpPass = $_POST['smtp_password'] ?? '';
        $smtpFrom = trim($_POST['smtp_from'] ?? '');

        if (!$smtpHost) { $error = 'SMTPホストを入力してください'; }
        elseif ($smtpPort < 1 || $smtpPort > 65535) { $error = 'ポートは1〜65535の範囲で入力してください'; }
        elseif (!$smtpUser) { $error = 'ユーザー名を入力してください'; }
        elseif (!$smtpFrom) { $error = '送信元アドレスを入力してください'; }

        if (!$error) {
            // パスワード未入力なら既存値を維持
            if ($smtpPass === '') {
                $smtpPass = asobiGetSetting('smtp_password', '');
            }
            asobiSetSetting('smtp_host', $smtpHost);
            asobiSetSetting('smtp_port', (string)$smtpPort);
            asobiSetSetting('smtp_user', $smtpUser);
            asobiSetSetting('smtp_password', $smtpPass);
            asobiSetSetting('smtp_from', $smtpFrom);

            // msmtprc を更新
            $result = updateMsmtprc($smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpFrom);
            if ($result === true) {
                $success = 'SMTP設定を保存しました';
            } else {
                $error = $result;
            }
        }
    }

    if ($action === 'test_smtp') {
        $testTo = trim($_POST['test_email'] ?? '');
        if (!$testTo || !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            $error = '有効なメールアドレスを入力してください';
        } else {
            $subject = '=?UTF-8?B?' . base64_encode('【あそび】SMTP テスト送信') . '?=';
            $body    = "このメールは asobi.info 管理画面から送信されたテストメールです。\r\n\r\n"
                     . "送信日時: " . date('Y-m-d H:i:s') . "\r\n"
                     . "SMTPホスト: " . asobiGetSetting('smtp_host', '(未設定)') . "\r\n";
            $from    = asobiGetSetting('smtp_from', 'noreply@asobi.info');
            $headers = "From: {$from}\r\nReply-To: {$from}\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            if (mail($testTo, $subject, $body, $headers, "-f {$from}")) {
                $success = "{$testTo} にテストメールを送信しました";
            } else {
                $error = 'メールの送信に失敗しました。SMTP設定を確認してください';
            }
        }
    }
}

// 現在値を取得
$current = [];
foreach ($settingDefs as $key => $def) {
    $current[$key] = asobiGetSetting($key);
}
$smtpCurrent = [];
foreach ($smtpDefs as $key => $def) {
    $smtpCurrent[$key] = asobiGetSetting($key, '');
}

// 最新のメール送信ログ
$msmtpLog = '';
if (is_readable('/var/log/msmtp.log')) {
    $lines = file('/var/log/msmtp.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $msmtpLog = implode("\n", array_slice($lines, -10));
}

$currentUser = asobiGetCurrentUser();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>サイト設定 - asobi.info 管理画面</title>
  <link rel="stylesheet" href="/assets/css/common.css?v=<?= assetVer('/assets/css/common.css') ?>">
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
      width: 90px; padding: 8px 12px; border: 2px solid #e0e4e8; border-radius: 8px;
      font-size: 0.95rem; font-family: inherit; color: #1d2d3a; text-align: right;
      outline: none; transition: border-color 0.2s;
    }
    .input-with-unit input[type=number]:focus { border-color: #667eea; }
    .input-with-unit .unit { font-size: 0.85rem; color: #637080; white-space: nowrap; }

    .smtp-input {
      width: 280px; padding: 8px 12px; border: 2px solid #e0e4e8; border-radius: 8px;
      font-size: 0.9rem; font-family: inherit; color: #1d2d3a; outline: none;
      transition: border-color 0.2s;
    }
    .smtp-input:focus { border-color: #667eea; }
    .smtp-input[type=number] { width: 100px; text-align: right; }

    .message { padding: 12px 16px; border-radius: 10px; font-size: 0.875rem; margin-bottom: 20px; }
    .message.success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
    .message.error   { background: #fff1f2; border: 1px solid #fecdd3; color: #e11d48; }

    .btn-save {
      padding: 11px 28px;
      background: linear-gradient(135deg, #667eea, #764ba2);
      color: #fff; border: none; border-radius: 10px;
      font-size: 0.95rem; font-weight: 700; cursor: pointer;
      font-family: inherit; transition: opacity 0.2s;
    }
    .btn-save:hover { opacity: 0.88; }

    .btn-test {
      padding: 8px 20px;
      background: #fff; color: #667eea;
      border: 2px solid #667eea; border-radius: 10px;
      font-size: 0.85rem; font-weight: 600; cursor: pointer;
      font-family: inherit; transition: all 0.2s;
    }
    .btn-test:hover { background: #667eea; color: #fff; }

    .test-row {
      display: flex; align-items: center; gap: 12px;
      padding: 16px 0 0; border-top: 1px solid #f0f2f5; margin-top: 8px;
    }
    .test-row input { flex: 1; max-width: 280px; }

    .log-box {
      background: #f8f9fb; border: 1px solid #e0e4e8; border-radius: 8px;
      padding: 14px 16px; font-size: 0.78rem; font-family: 'SF Mono', Consolas, monospace;
      color: #4a5568; white-space: pre-wrap; word-break: break-all;
      max-height: 200px; overflow-y: auto; margin-top: 12px;
    }

    .password-hint {
      font-size: 0.75rem; color: #8a9bb0; margin-top: 2px;
    }
  </style>
</head>
<body>
  <?php $adminActivePage = 'settings'; require __DIR__ . '/_sidebar.php'; ?>

    <div style="max-width:720px;">
    <h1 style="font-size:1.4rem;font-weight:700;margin-bottom:32px;">サイト設定</h1>

    <?php if ($success): ?>
      <div class="message success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="message error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- セッション設定 -->
    <form method="POST" action="">
      <input type="hidden" name="action" value="save_session">
      <div class="card">
        <div class="card-title">🔐 セッション設定</div>
        <?php
        $key = 'session_cookie_lifetime';
        $def = $sessionDefs[$key];
        $curVal = asobiGetSetting($key, $def['default']);
        ?>
        <div class="setting-row">
          <div>
            <div class="setting-label"><?= htmlspecialchars($def['label']) ?></div>
            <div class="setting-desc"><?= htmlspecialchars($def['desc']) ?></div>
          </div>
          <select name="<?= $key ?>" style="padding:8px 12px;border:2px solid #e0e4e8;border-radius:8px;font-size:0.9rem;font-family:inherit;color:#1d2d3a;outline:none;cursor:pointer;">
            <?php foreach ($def['options'] as $v => $label): ?>
            <option value="<?= $v ?>" <?= $curVal === (string)$v ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="margin-top:16px;">
          <button type="submit" class="btn-save">セッション設定を保存</button>
        </div>
      </div>
    </form>

    <!-- SMTP設定 -->
    <form method="POST" action="">
      <input type="hidden" name="action" value="save_smtp">
      <div class="card">
        <div class="card-title">📧 SMTP設定（メール送信）</div>

        <?php foreach ($smtpDefs as $key => $def): ?>
        <div class="setting-row">
          <div>
            <div class="setting-label"><?= htmlspecialchars($def['label']) ?></div>
            <div class="setting-desc"><?= htmlspecialchars($def['desc']) ?></div>
            <?php if ($def['type'] === 'password' && $smtpCurrent[$key]): ?>
              <div class="password-hint">※ 変更しない場合は空欄のまま</div>
            <?php endif; ?>
          </div>
          <input class="smtp-input" type="<?= $def['type'] ?>"
                 name="<?= $key ?>"
                 value="<?= $def['type'] === 'password' ? '' : htmlspecialchars($smtpCurrent[$key]) ?>"
                 placeholder="<?= htmlspecialchars($def['placeholder']) ?>"
                 <?= $key === 'smtp_host' || $key === 'smtp_user' || $key === 'smtp_from' ? 'required' : '' ?>
                 autocomplete="off">
        </div>
        <?php endforeach; ?>

        <div style="margin-top:16px;">
          <button type="submit" class="btn-save">SMTP設定を保存</button>
        </div>
      </div>
    </form>

    <!-- テスト送信 -->
    <form method="POST" action="">
      <input type="hidden" name="action" value="test_smtp">
      <div class="card">
        <div class="card-title">📨 テスト送信</div>
        <div class="test-row">
          <input class="smtp-input" type="email" name="test_email"
                 placeholder="送信先メールアドレス" required>
          <button type="submit" class="btn-test">テスト送信</button>
        </div>
        <?php if ($msmtpLog): ?>
        <div style="margin-top:16px;">
          <div class="setting-label">送信ログ（直近）</div>
          <div class="log-box"><?= htmlspecialchars($msmtpLog) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </form>

    <!-- メール認証 送信制限 -->
    <form method="POST" action="">
      <input type="hidden" name="action" value="save_settings">
      <div class="card">
        <div class="card-title">⏱ メール認証 送信制限</div>

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

        <div style="margin-top:16px;">
          <button type="submit" class="btn-save">送信制限を保存</button>
        </div>
      </div>
    </form>
    </div>
  </main>
  </div>

  <script src="/assets/js/common.js?v=<?= assetVer('/assets/js/common.js') ?>"></script>
</body>
</html>
