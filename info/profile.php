<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
asobiRequireLogin();
$user = asobiGetCurrentUser();

// ソーシャル連携解除
$socialMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlink_social') {
    $provider = $_POST['provider'] ?? '';
    if (in_array($provider, ['google', 'line', 'twitter'], true)) {
        asobiUnlinkSocial($user['id'], $provider);
        $socialMsg = 'success';
    }
} elseif (!empty($_GET['social_linked'])) {
    $socialMsg = 'linked';
} elseif (!empty($_GET['social_error'])) {
    $socialMsg = $_GET['social_error'];
}
$socialAccounts = asobiGetSocialAccounts($user['id']);

$db = asobiUsersDb();
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

$profileError = $profileSuccess = '';
$pwError = $pwSuccess = '';
$msgMap = ['resent' => '確認メールを再送しました', 'cancel_email' => '確認待ちのメールアドレス変更をキャンセルしました'];
$verifyMsg = $msgMap[$_GET['msg'] ?? ''] ?? (isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : '');

// 確認待ちの新メールアドレスがあるか確認（期限内のみ）
$pendingEmailStmt = $db->prepare("SELECT email, last_sent_at FROM email_verifications WHERE user_id = ? AND expires_at > datetime('now','localtime') ORDER BY id DESC LIMIT 1");
$pendingEmailStmt->execute([$user['id']]);
$pendingRow   = $pendingEmailStmt->fetch();
$pendingEmail = $pendingRow ? $pendingRow['email'] : false;
$lastSentAt   = $pendingRow ? $pendingRow['last_sent_at'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'profile') {
        $oldEmail = $profile['email'] ?? '';
        $newEmail = trim($_POST['email'] ?? '');
        $currentEmailVerified = !empty($profile['email_verified_at']);
        // 認証済みで変更先が空欄の場合は変更なし
        if ($currentEmailVerified && $newEmail === '') $newEmail = $oldEmail;
        $emailChanged = $newEmail && $newEmail !== $oldEmail;

        // 認証済みメールが変わる場合のみ旧メールを維持する（未認証→変更は直接上書き）
        $data = [
            'display_name' => trim($_POST['display_name'] ?? ''),
            'email'        => ($emailChanged && $currentEmailVerified) ? $oldEmail : $newEmail,
            'avatar_url'   => trim($_POST['avatar_url'] ?? ''),
        ];
        if ($data['display_name'] !== '') {
            $bnCheck = asobiCheckBanned($data['display_name'], 'username');
            if ($bnCheck['blocked']) $profileError = 'その表示名は使用できません';
        }
        if (!$profileError) {
            $result = asobiUpdateProfile($user['id'], $data);
            if ($result === true) {
                if ($emailChanged) {
                    $sendResult = asobiSendVerificationEmail($user['id'], $newEmail);
                    if ($sendResult === true) {
                        if ($currentEmailVerified) {
                            $profileSuccess = 'プロフィールを更新しました。' . htmlspecialchars($newEmail) . ' に確認メールを送信しました。確認後にメールアドレスが変更されます。';
                        } else {
                            $profileSuccess = 'プロフィールを更新しました。' . htmlspecialchars($newEmail) . ' に確認メールを送信しました。';
                        }
                    } else {
                        $profileSuccess = 'プロフィールを更新しました。';
                        $verifyMsg = $sendResult; // エラーメッセージ（制限など）
                    }
                } else {
                    $profileSuccess = 'プロフィールを更新しました';
                }
                $stmt->execute([$user['id']]);
                $profile = $stmt->fetch();
            } else {
                $profileError = $result;
            }
        }
    } elseif ($_POST['action'] === 'cancel_pending_email') {
        $db->prepare("DELETE FROM email_verifications WHERE user_id = ?")->execute([$user['id']]);
        header('Location: /profile.php?msg=cancel_email#profile');
        exit;
    } elseif ($_POST['action'] === 'resend_verification') {
        // $pendingEmail は後で再取得するため、ここでは直接DBを参照
        $resendStmt = $db->prepare("SELECT email FROM email_verifications WHERE user_id = ? AND expires_at > datetime('now','localtime') ORDER BY id DESC LIMIT 1");
        $resendStmt->execute([$user['id']]);
        $targetEmail = $resendStmt->fetchColumn() ?: ($profile['email'] ?? '');
        if (!empty($targetEmail)) {
            $sendResult = asobiSendVerificationEmail($user['id'], $targetEmail);
            $msg = ($sendResult === true) ? 'resent' : urlencode($sendResult);
            header('Location: /profile.php?msg=' . $msg . '#profile');
            exit;
        }
    } elseif ($_POST['action'] === 'password') {
        $result = asobiChangePassword(
            $user['id'],
            $_POST['current_password'] ?? '',
            $_POST['new_password'] ?? ''
        );
        if ($result === true) {
            $pwSuccess = 'パスワードを変更しました';
        } else {
            $pwError = $result;
        }
    }
}

// POST処理後に再取得（stale防止）
$pendingEmailStmt->execute([$user['id']]);
$pendingRow   = $pendingEmailStmt->fetch();
$pendingEmail = $pendingRow ? $pendingRow['email'] : false;
$lastSentAt   = $pendingRow ? $pendingRow['last_sent_at'] : null;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>プロフィール - asobi.info</title>
  <link rel="stylesheet" href="/assets/css/common.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body {
      background: linear-gradient(135deg, #fce4f6 0%, #e8f4fe 50%, #e4fce8 100%);
      color: #2d2d3a;
      min-height: 100vh;
    }
    .site-header { background: rgba(255,255,255,0.85); border-bottom: 1px solid #e0e0e0; backdrop-filter: blur(8px); }
    .site-logo a { color: #1d1d1f; text-decoration: none; font-size: 1.5rem; font-weight: 700; }
    .header-right { display: flex; align-items: center; gap: 24px; }
    .site-nav ul { display: flex; list-style: none; gap: 24px; }
    .site-nav a { color: #1d1d1f; font-weight: 500; font-size: 0.9rem; }
    .user-area { display: flex; align-items: center; gap: 10px; position: relative; }
    .user-area::before { content: ''; display: block; width: 1px; height: 18px; background: #d0d0d5; }
    .user-menu { position: relative; }
    .user-trigger { display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 4px 8px; border-radius: 8px; transition: background 0.2s; color: #1d1d1f; }
    .user-trigger:hover { background: rgba(0,0,0,0.05); }
    .user-avatar-sm { width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(135deg, #a855f7, #3b82f6); display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 700; color: #fff; flex-shrink: 0; overflow: hidden; }
    .user-avatar-sm img { width: 100%; height: 100%; object-fit: cover; }
    .user-display-name { font-size: 0.875rem; font-weight: 500; max-width: 100px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .user-caret { font-size: 0.6rem; opacity: 0.5; }
    .user-dropdown { display: none; position: absolute; top: calc(100% + 8px); right: 0; background: #fff; border: 1px solid #e0e0e0; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,0.12); min-width: 160px; overflow: hidden; z-index: 200; }
    .user-menu.open .user-dropdown { display: block; }
    .user-dropdown a { display: block; padding: 11px 16px; font-size: 0.875rem; color: #1d1d1f; text-decoration: none; transition: background 0.15s; }
    .user-dropdown a:hover { background: #f5f5f7; }
    .user-dropdown .dropdown-divider { height: 1px; background: #e0e0e0; margin: 4px 0; }
    .user-dropdown .dropdown-logout { color: #e74c3c; }
    @media (max-width: 768px) {
      .site-header .container { flex-direction: row; align-items: center; }
      .site-nav { display: none; }
      .user-display-name { display: none; }
      .user-area::before { display: none; }
    }

    .profile-layout {
      max-width: 800px;
      margin: 0 auto;
      padding: 40px 20px 80px;
      display: grid;
      grid-template-columns: 220px 1fr;
      gap: 32px;
      align-items: start;
    }
    @media (max-width: 640px) {
      .profile-layout { grid-template-columns: 1fr; }
    }

    /* サイドバー */
    .profile-sidebar {
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      padding: 28px 20px;
      text-align: center;
      box-shadow: 0 4px 20px rgba(130,100,180,0.08);
    }
    .avatar {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      background: linear-gradient(135deg, #a855f7, #3b82f6);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 2rem;
      margin: 0 auto 16px;
      overflow: hidden;
    }
    .avatar img { width: 100%; height: 100%; object-fit: cover; }
    .profile-name {
      font-size: 1.1rem;
      font-weight: 700;
      margin-bottom: 4px;
      color: #2d2d3a;
    }
    .profile-username {
      font-size: 0.8rem;
      color: #9ca3af;
      margin-bottom: 12px;
    }
    .role-badge {
      display: inline-block;
      padding: 3px 12px;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 600;
    }
    .role-admin { background: #fff1f2; color: #e11d48; border: 1px solid #fecdd3; }
    .role-user  { background: #f5f3ff; color: #7c3aed; border: 1px solid #ddd6fe; }
    .sidebar-links {
      margin-top: 24px;
      display: flex;
      flex-direction: column;
      gap: 2px;
    }
    .sidebar-links a {
      padding: 8px 12px;
      border-radius: 8px;
      font-size: 0.85rem;
      color: #6b7280;
      text-decoration: none;
      transition: background 0.2s, color 0.2s;
    }
    .sidebar-links a:hover { background: #f3f4f6; color: #2d2d3a; }
    .sidebar-links a.active { background: #f5f3ff; color: #7c3aed; font-weight: 600; }
    .sidebar-divider { height: 1px; background: #e5e7eb; margin: 8px 0; }
    .sidebar-links a.logout { color: #e11d48; }
    .sidebar-links a.logout:hover { background: #fff1f2; }

    /* トースト通知 */
    .toast {
      position: fixed;
      top: 24px;
      left: 50%;
      transform: translateX(-50%) translateY(-80px);
      padding: 12px 24px;
      border-radius: 10px;
      font-size: 0.9rem;
      font-weight: 600;
      z-index: 1000;
      box-shadow: 0 4px 20px rgba(0,0,0,0.15);
      transition: transform 0.35s cubic-bezier(0.34,1.56,0.64,1);
      white-space: nowrap;
    }
    .toast.show { transform: translateX(-50%) translateY(0); }
    .toast.success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #16a34a; }
    .toast.error   { background: #fff1f2; border: 1px solid #fecdd3; color: #e11d48; }

    /* メインコンテンツ */
    .profile-main { display: flex; flex-direction: column; gap: 24px; }

    .section-card {
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      padding: 28px;
      box-shadow: 0 4px 20px rgba(130,100,180,0.08);
    }
    .section-card h2 {
      font-size: 1rem;
      font-weight: 600;
      margin-bottom: 20px;
      color: #7c3aed;
      padding-bottom: 12px;
      border-bottom: 1px solid #e5e7eb;
    }
    label {
      display: block;
      font-size: 0.8rem;
      color: #6b7280;
      margin-bottom: 6px;
      font-weight: 600;
    }
    .field-group { margin-bottom: 16px; }
    input[type=text], input[type=email], input[type=password], input[type=url] {
      width: 100%;
      padding: 11px 14px;
      background: #f9fafb;
      color: #2d2d3a;
      border: 2px solid #e5e7eb;
      border-radius: 8px;
      font-size: 0.95rem;
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
      font-family: inherit;
    }
    input:focus { border-color: #a855f7; box-shadow: 0 0 0 3px rgba(168,85,247,0.1); background: #fff; }
    input[readonly] { opacity: 0.6; cursor: default; }
    .hint { font-size: 0.75rem; color: #9ca3af; margin-top: 4px; }
    button[type=submit] {
      padding: 10px 24px;
      background: linear-gradient(135deg, #a855f7, #3b82f6);
      color: #fff;
      border: none;
      border-radius: 8px;
      font-size: 0.9rem;
      font-weight: 600;
      cursor: pointer;
      transition: opacity 0.2s;
      font-family: inherit;
    }
    button[type=submit]:hover { opacity: 0.85; }

    .message {
      padding: 10px 14px;
      border-radius: 8px;
      font-size: 0.875rem;
      margin-bottom: 16px;
    }
    .message.error   { background: #fff1f2; border: 1px solid #fecdd3; color: #e11d48; }
    .message.success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #16a34a; }

    /* ソーシャル連携 */
    .social-list { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 16px; }
    .social-row {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      padding: 16px 12px;
      border: 2px solid #e5e7eb;
      border-radius: 10px;
      background: #f9fafb;
      text-align: center;
    }
    .social-row.linked { border-color: #bbf7d0; background: #f0fdf4; }
    .social-icon { width: 28px; height: 28px; flex-shrink: 0; }
    .social-name { font-weight: 600; font-size: 0.88rem; }
    .social-info { font-size: 0.75rem; color: #6b7280; word-break: break-all; }
    @media (max-width: 500px) {
      .social-list { grid-template-columns: 1fr; }
      .social-row { flex-direction: row; text-align: left; padding: 12px 14px; }
      .social-name { font-size: 0.9rem; flex: 1; }
    }
    .btn-unlink {
      padding: 5px 12px;
      border: 1px solid #fecdd3;
      border-radius: 6px;
      background: #fff;
      color: #e11d48;
      font-size: 0.8rem;
      font-weight: 600;
      cursor: pointer;
      font-family: inherit;
      transition: background 0.15s;
    }
    .btn-unlink:hover { background: #fff1f2; }
    .btn-link-social {
      display: inline-block;
      padding: 6px 14px;
      border: 1.5px solid #e5e7eb;
      border-radius: 8px;
      background: #fff;
      color: #2d2d3a;
      font-size: 0.8rem;
      font-weight: 600;
      text-decoration: none;
      transition: border-color 0.2s;
      margin-top: auto;
    }
    .btn-link-social:hover { border-color: #a855f7; }

    /* メール確認バッジ・再送ボタン */
    .email-badge {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 12px;
      font-size: 0.7rem;
      font-weight: 600;
      margin-left: 6px;
      vertical-align: middle;
    }
    .email-badge.verified   { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
    .email-badge.unverified { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
    .btn-resend {
      padding: 7px 16px;
      border: 1.5px solid #fde68a;
      border-radius: 8px;
      background: #fffbeb;
      color: #92400e;
      font-size: 0.82rem;
      font-weight: 600;
      cursor: pointer;
      font-family: inherit;
      transition: opacity 0.2s;
    }
    .btn-resend:hover { opacity: 0.75; }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="container">
      <div class="site-logo"><a href="/">あそび</a></div>
      <div class="header-right">
        <nav class="site-nav">
          <ul>
            <li><a href="https://tbt.asobi.info">Tournament Battle</a></li>
            <li><a href="https://dbd.asobi.info">DbD</a></li>
            <li><a href="https://pkq.asobi.info">ポケモンクエスト</a></li>
          </ul>
        </nav>
        <div class="user-area">
          <div class="user-menu">
            <div class="user-trigger" tabindex="0">
              <div class="user-avatar-sm">
                <?php if ($profile['avatar_url']): ?>
                  <img src="<?= htmlspecialchars($profile['avatar_url']) ?>" alt="">
                <?php else: ?>
                  <?= htmlspecialchars(mb_substr($profile['display_name'] ?: $profile['username'], 0, 1)) ?>
                <?php endif; ?>
              </div>
              <span class="user-display-name"><?= htmlspecialchars($profile['display_name'] ?: $profile['username']) ?></span>
              <span class="user-caret">▼</span>
            </div>
            <div class="user-dropdown">
              <a href="/">あそびトップ</a>
              <div class="dropdown-divider"></div>
              <a href="/profile.php">プロフィール</a>
              <?php if ($profile['role'] === 'admin'): ?>
                <a href="/admin/">管理画面</a>
              <?php endif; ?>
              <div class="dropdown-divider"></div>
              <a href="/logout.php" class="dropdown-logout">ログアウト</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </header>

  <div class="profile-layout">
    <!-- サイドバー -->
    <aside class="profile-sidebar">
      <div class="avatar">
        <?php if ($profile['avatar_url']): ?>
          <img src="<?= htmlspecialchars($profile['avatar_url']) ?>" alt="avatar" loading="lazy">
        <?php else: ?>
          <?= mb_substr($profile['display_name'] ?: $profile['username'], 0, 1) ?>
        <?php endif; ?>
      </div>
      <div class="profile-name"><?= htmlspecialchars($profile['display_name'] ?: $profile['username']) ?></div>
      <div class="profile-username">@<?= htmlspecialchars($profile['username']) ?></div>
      <span class="role-badge role-<?= $profile['role'] ?>">
        <?= $profile['role'] === 'admin' ? '管理者' : 'ユーザー' ?>
      </span>
      <nav class="sidebar-links">
        <a href="#profile"  class="nav-link">プロフィール編集</a>
        <a href="#password" class="nav-link">パスワード変更</a>
        <a href="#social"   class="nav-link">ソーシャル連携</a>
        <a href="#account"  class="nav-link">アカウント情報</a>
        <div class="sidebar-divider"></div>
        <?php if ($profile['role'] === 'admin'): ?>
          <a href="/admin/">管理画面</a>
        <?php endif; ?>
        <a href="/logout.php" class="logout">ログアウト</a>
      </nav>
    </aside>

    <!-- メインコンテンツ -->
    <main class="profile-main">
      <!-- プロフィール編集 -->
      <div class="section-card" id="profile">
        <h2>プロフィール</h2>
        <?php if ($profileError): ?><div class="message error"><?= htmlspecialchars($profileError) ?></div><?php endif; ?>
        <?php if ($profileSuccess): ?><div class="message success"><?= htmlspecialchars($profileSuccess) ?></div><?php endif; ?>
        <form method="POST" action="">
          <input type="hidden" name="action" value="profile">
          <div class="field-group">
            <label>ユーザー名</label>
            <input type="text" value="<?= htmlspecialchars($profile['username']) ?>" readonly>
          </div>
          <div class="field-group">
            <label for="display_name">表示名</label>
            <input type="text" id="display_name" name="display_name"
                   value="<?= htmlspecialchars($profile['display_name'] ?? '') ?>"
                   maxlength="50">
          </div>
          <?php if (!empty($profile['email_verified_at'])): ?>
          <div class="field-group">
            <label>現在のメールアドレス <span class="email-badge verified">確認済み</span></label>
            <input type="email" value="<?= htmlspecialchars($profile['email']) ?>" readonly
                   style="background:#f3f4f6;color:#6b7280;cursor:default;">
          </div>
          <div class="field-group">
            <label for="email">変更先メールアドレス</label>
            <input type="email" id="email" name="email"
                   value="<?= htmlspecialchars($pendingEmail && $pendingEmail !== $profile['email'] ? $pendingEmail : '') ?>"
                   placeholder="新しいメールアドレスを入力">
            <p class="hint">変更する場合のみ入力してください。確認メールを送信します。</p>
            <?php if ($pendingEmail && $pendingEmail !== $profile['email']): ?>
              <p class="hint" style="color:#b45309;margin-top:4px;">
                確認待ち（確認後に変更されます）
              </p>
              <?php if ($verifyMsg): ?><p class="hint" style="color:#16a34a;margin-top:4px;"><?= htmlspecialchars($verifyMsg) ?></p><?php endif; ?>
              <div style="margin-top:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <button type="submit" form="resend-form" class="btn-resend">確認メールを再送する</button>
                <button type="button" class="btn-resend" style="background:#fff;color:#e11d48;border:2px solid #fecdd3;" onclick="document.getElementById('cancel-email-modal').style.display='flex'">取り消す</button>
                <?php if ($lastSentAt): ?><span style="font-size:0.78rem;color:#9ca3af;">最終送信: <?= htmlspecialchars($lastSentAt) ?></span><?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <div class="field-group">
            <label for="email">メールアドレス
              <?php if (!empty($profile['email'])): ?>
                <span class="email-badge unverified">未確認</span>
              <?php endif; ?>
            </label>
            <input type="email" id="email" name="email"
                   value="<?= htmlspecialchars($profile['email'] ?? '') ?>">
            <?php if (!empty($profile['email'])): ?>
              <?php if ($verifyMsg): ?><p class="hint" style="color:#16a34a;margin-top:6px;"><?= htmlspecialchars($verifyMsg) ?></p><?php endif; ?>
              <div style="margin-top:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <button type="submit" form="resend-form" class="btn-resend">確認メールを再送する</button>
                <?php if ($lastSentAt): ?><span style="font-size:0.78rem;color:#9ca3af;">最終送信: <?= htmlspecialchars($lastSentAt) ?></span><?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <div class="field-group">
            <label for="avatar_url">アバター画像URL</label>
            <input type="url" id="avatar_url" name="avatar_url"
                   value="<?= htmlspecialchars($profile['avatar_url'] ?? '') ?>"
                   placeholder="https://...">
            <p class="hint">https:// から始まる画像URLを入力してください</p>
          </div>
          <button type="submit">保存する</button>
        </form>
        <?php if (($pendingEmail && $pendingEmail !== $profile['email']) || (!empty($profile['email']) && empty($profile['email_verified_at']))): ?>
        <form id="resend-form" method="POST" action="">
          <input type="hidden" name="action" value="resend_verification">
        </form>
        <?php endif; ?>
        <?php if ($pendingEmail && $pendingEmail !== $profile['email']): ?>
        <form id="cancel-pending-form" method="POST" action="">
          <input type="hidden" name="action" value="cancel_pending_email">
        </form>
        <?php endif; ?>
      </div>

      <!-- パスワード変更 -->
      <div class="section-card" id="password">
        <h2>パスワード変更</h2>
        <?php if ($pwError): ?><div class="message error"><?= htmlspecialchars($pwError) ?></div><?php endif; ?>
        <?php if ($pwSuccess): ?><div class="message success"><?= htmlspecialchars($pwSuccess) ?></div><?php endif; ?>
        <form method="POST" action="">
          <input type="hidden" name="action" value="password">
          <div class="field-group">
            <label for="current_password">現在のパスワード</label>
            <input type="password" id="current_password" name="current_password"
                   autocomplete="current-password" required>
          </div>
          <div class="field-group">
            <label for="new_password">新しいパスワード</label>
            <input type="password" id="new_password" name="new_password"
                   autocomplete="new-password" required>
            <p class="hint">8文字以上</p>
          </div>
          <button type="submit">変更する</button>
        </form>
      </div>

      <!-- ソーシャル連携 -->
      <div class="section-card" id="social">
        <h2>ソーシャル連携</h2>
        <?php
        $providerNames = ['google' => 'Google', 'line' => 'LINE', 'twitter' => 'X (Twitter)'];
        $providerIcons = [
            'google'  => '<svg class="social-icon" viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>',
            'line'    => '<svg class="social-icon" viewBox="0 0 24 24"><path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.281.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314" fill="#06C755"/></svg>',
            'twitter' => '<svg class="social-icon" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.261 5.636zm-1.161 17.52h1.833L7.084 4.126H5.117z" fill="#000"/></svg>',
        ];
        $linkedProviders = array_column($socialAccounts, 'provider');

        if ($socialMsg === 'linked'): ?>
          <div class="message success">ソーシャルアカウントを連携しました</div>
        <?php elseif ($socialMsg === 'success'): ?>
          <div class="message success">連携を解除しました</div>
        <?php elseif ($socialMsg === 'already_linked'): ?>
          <div class="message error">このアカウントはすでに連携済みです</div>
        <?php elseif ($socialMsg === 'used_by_other'): ?>
          <div class="message error">このアカウントは別のユーザーに連携されています</div>
        <?php endif; ?>

        <div class="social-list">
          <?php foreach ($providerNames as $pKey => $pName):
            $linkedRow = null;
            foreach ($socialAccounts as $sa) {
                if ($sa['provider'] === $pKey) { $linkedRow = $sa; break; }
            }
          ?>
          <div class="social-row <?= $linkedRow ? 'linked' : '' ?>">
            <?= $providerIcons[$pKey] ?>
            <div class="social-name"><?= $pName ?></div>
            <?php if ($linkedRow): ?>
              <div class="social-info">
                <?= htmlspecialchars($linkedRow['display_name'] ?? '') ?>
                <?php if ($linkedRow['username']): ?><br>@<?= htmlspecialchars($linkedRow['username']) ?><?php endif; ?>
              </div>
              <form method="POST" action="">
                <input type="hidden" name="action" value="unlink_social">
                <input type="hidden" name="provider" value="<?= $pKey ?>">
                <button type="button" class="btn-unlink"
                        data-confirm="<?= htmlspecialchars($pName) ?> の連携を解除しますか？"
                        data-confirm-ok="解除する">解除</button>
              </form>
            <?php else: ?>
              <div class="social-info">未連携</div>
              <a href="/oauth/start.php?provider=<?= $pKey ?>&mode=link" class="btn-link-social">連携する</a>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- アカウント情報 -->
      <div class="section-card" id="account">
        <h2>アカウント情報</h2>
        <div class="field-group">
          <label>登録日</label>
          <input type="text" value="<?= htmlspecialchars($profile['created_at']) ?>" readonly>
        </div>
        <div class="field-group">
          <label>最終ログイン</label>
          <input type="text" value="<?= htmlspecialchars($profile['last_login_at'] ?? '—') ?>" readonly>
        </div>
      </div>
    </main>
  </div>

  <footer class="site-footer">
    <div class="container">
      <p>&copy; 2026 あそび</p>
    </div>
  </footer>
  <script src="/assets/js/common.js"></script>
  <?php if ($socialMsg): ?>
  <div id="toast" class="toast <?= in_array($socialMsg, ['linked', 'success']) ? 'success' : 'error' ?>">
    <?php
      $toastText = [
        'linked'       => 'ソーシャルアカウントを連携しました',
        'success'      => '連携を解除しました',
        'already_linked' => 'このアカウントはすでに連携済みです',
        'used_by_other'  => 'このアカウントは別のユーザーに連携されています',
      ];
      echo htmlspecialchars($toastText[$socialMsg] ?? $socialMsg);
    ?>
  </div>
  <?php endif; ?>
  <script>
    const userMenu = document.querySelector('.user-menu');
    if (userMenu) {
      userMenu.querySelector('.user-trigger').addEventListener('click', function(e) {
        e.stopPropagation();
        userMenu.classList.toggle('open');
      });
      document.addEventListener('click', function() {
        userMenu.classList.remove('open');
      });
    }

    // トースト表示
    const toast = document.getElementById('toast');
    if (toast) {
      requestAnimationFrame(() => { requestAnimationFrame(() => { toast.classList.add('show'); }); });
      setTimeout(() => { toast.classList.remove('show'); }, 4000);
      <?php if ($socialMsg): ?>
      // ソーシャル連携セクションへスクロール
      setTimeout(() => {
        document.getElementById('social')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 100);
      <?php endif; ?>
    }

    // サイドバー アクティブリンク
    const sections = ['profile', 'password', 'social', 'account'];
    const navLinks = document.querySelectorAll('.nav-link');
    function updateActive() {
      let current = sections[0];
      sections.forEach(id => {
        const el = document.getElementById(id);
        if (el && el.getBoundingClientRect().top <= 120) current = id;
      });
      navLinks.forEach(a => {
        a.classList.toggle('active', a.getAttribute('href') === '#' + current);
      });
    }
    updateActive();
    window.addEventListener('scroll', updateActive, { passive: true });
  </script>

  <!-- メールキャンセル確認モーダル -->
  <div id="cancel-email-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:32px 28px;max-width:360px;width:90%;box-shadow:0 8px 40px rgba(0,0,0,0.18);text-align:center;">
      <p style="font-weight:700;font-size:1rem;margin-bottom:10px;">メールアドレス変更を取り消しますか？</p>
      <p style="font-size:0.85rem;color:#6b7280;margin-bottom:24px;line-height:1.6;">確認待ちのメールアドレスへの変更がキャンセルされます。</p>
      <div style="display:flex;gap:10px;justify-content:center;">
        <button type="button" onclick="document.getElementById('cancel-email-modal').style.display='none'"
          style="padding:10px 24px;border:2px solid #e5e7eb;border-radius:8px;background:#fff;color:#6b7280;font-size:0.9rem;font-weight:600;cursor:pointer;font-family:inherit;">
          キャンセル
        </button>
        <button type="submit" form="cancel-pending-form"
          style="padding:10px 24px;border:none;border-radius:8px;background:#e11d48;color:#fff;font-size:0.9rem;font-weight:700;cursor:pointer;font-family:inherit;">
          取り消す
        </button>
      </div>
    </div>
  </div>
</body>
</html>
