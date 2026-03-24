<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
asobiRequireAdmin();

$db = asobiUsersDb();
$currentUser = asobiGetCurrentUser();

$actionMsg   = '';
$actionError = '';

// アクション処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $email    = trim($_POST['email'] ?? '');
        $dispName = trim($_POST['display_name'] ?? '');
        $role     = in_array($_POST['role'] ?? '', ['user','admin']) ? $_POST['role'] : 'user';

        $result = asobiRegisterUser($username, $password, $email, $dispName);
        if ($result === true) {
            if ($role === 'admin') {
                $id = $db->lastInsertId();
                $db->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$id]);
            }
            $actionMsg = "ユーザー「{$username}」を作成しました";
        } else {
            $actionError = $result;
        }
    }

    if ($action === 'set_role') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $newRole  = in_array($_POST['role'] ?? '', ['user','admin']) ? $_POST['role'] : 'user';
        if ($targetId === $currentUser['id'] && $newRole !== 'admin') {
            $actionError = '自分自身の管理者権限は外せません';
        } else {
            $db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $targetId]);
            $actionMsg = 'ロールを変更しました';
        }
    }

    if ($action === 'set_status') {
        $targetId  = (int)($_POST['user_id'] ?? 0);
        $newStatus = in_array($_POST['status'] ?? '', ['active','suspended']) ? $_POST['status'] : 'active';
        if ($targetId === $currentUser['id']) {
            $actionError = '自分自身のアカウントは変更できません';
        } else {
            $db->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newStatus, $targetId]);
            $actionMsg = $newStatus === 'suspended' ? 'アカウントを停止しました' : 'アカウントを復元しました';
        }
    }

    if ($action === 'delete') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        if ($targetId === $currentUser['id']) {
            $actionError = '自分自身は削除できません';
        } else {
            $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$targetId]);
            $target = $stmt->fetch();
            if ($target) {
                $db->prepare("DELETE FROM users WHERE id = ?")->execute([$targetId]);
                $actionMsg = "ユーザー「{$target['username']}」を削除しました";
            }
        }
    }
}

// ユーザー一覧取得
$sUsername = trim($_GET['s_username'] ?? '');
$sDisplay  = trim($_GET['s_display']  ?? '');
$sEmail    = trim($_GET['s_email']    ?? '');

$where = [];
$params = [];
if ($sUsername !== '') { $where[] = 'username LIKE ?';     $params[] = '%' . $sUsername . '%'; }
if ($sDisplay  !== '') { $where[] = 'display_name LIKE ?'; $params[] = '%' . $sDisplay  . '%'; }
if ($sEmail    !== '') { $where[] = 'email LIKE ?';        $params[] = '%' . $sEmail    . '%'; }

$sql = 'SELECT * FROM users' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ユーザー管理 - asobi.info 管理画面</title>
  <link rel="stylesheet" href="/assets/css/common.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Noto Sans JP', sans-serif;
      background: #f0f2f5;
      color: #1d2d3a;
      min-height: 100vh;
    }

    /* ヘッダー */
    .site-header { background: rgba(255,255,255,0.85); border-bottom: 1px solid #e0e0e0; }
    .site-logo a { color: #1d1d1f; text-decoration: none; font-size: 1.5rem; font-weight: 700; }
    .header-right { display: flex; align-items: center; gap: 24px; }
    .site-nav ul { display: flex; list-style: none; gap: 24px; }
    .site-nav a { color: #1d1d1f; font-weight: 500; font-size: 0.9rem; }
    .admin-badge { display: inline-block; font-size: 0.7rem; font-weight: 700; padding: 2px 8px; background: linear-gradient(135deg, #e74c3c, #c0392b); color: #fff; border-radius: 10px; margin-left: 6px; vertical-align: middle; }
    .user-area { display: flex; align-items: center; gap: 10px; position: relative; }
    .user-area::before { content: ''; display: block; width: 1px; height: 18px; background: #d0d0d5; }
    .user-menu { position: relative; }
    .user-trigger { display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 4px 8px; border-radius: 8px; transition: background 0.2s; color: #1d1d1f; }
    .user-trigger:hover { background: rgba(0,0,0,0.05); }
    .user-avatar { width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(135deg, #667eea, #764ba2); display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 700; color: #fff; flex-shrink: 0; overflow: hidden; }
    .user-avatar img { width: 100%; height: 100%; object-fit: cover; }
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

    .admin-body { max-width: 1100px; margin: 0 auto; padding: 40px 24px 80px; }

    .page-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 28px;
      flex-wrap: wrap;
      gap: 16px;
    }
    h1 { font-size: 1.4rem; }

    .create-form {
      background: #fff;
      border: 1px solid #e0e4e8;
      border-radius: 12px;
      padding: 24px;
      margin-bottom: 32px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .create-form h2 { font-size: 0.95rem; color: #5567cc; margin-bottom: 16px; }
    .form-row {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 12px;
      align-items: end;
    }
    .form-field label { display: block; font-size: 0.75rem; color: #637080; margin-bottom: 5px; font-weight: 600; }
    .form-field input, .form-field select {
      width: 100%;
      padding: 9px 12px;
      background: #fff;
      color: #1d2d3a;
      border: 2px solid #e0e4e8;
      border-radius: 8px;
      font-size: 0.9rem;
      outline: none;
      transition: border-color 0.2s;
      font-family: inherit;
    }
    .form-field input:focus, .form-field select:focus { border-color: #667eea; }
    .btn {
      padding: 9px 20px;
      border: none;
      border-radius: 8px;
      font-size: 0.9rem;
      font-weight: 600;
      cursor: pointer;
      font-family: inherit;
      transition: opacity 0.2s;
    }
    .btn:hover { opacity: 0.85; }
    .btn-primary { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; }
    .btn-sm { padding: 5px 12px; font-size: 0.8rem; }
    .btn-danger  { background: rgba(231,76,60,0.1);  color: #c0392b; border: 1px solid rgba(231,76,60,0.25); }
    .btn-warning { background: rgba(230,126,0,0.1);  color: #c07000; border: 1px solid rgba(230,126,0,0.25); }
    .btn-success { background: rgba(39,174,96,0.1);  color: #1a8a50; border: 1px solid rgba(39,174,96,0.25); }
    .btn-muted   { background: #f0f2f5; color: #637080; border: 1px solid #e0e4e8; }

    .filters { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; align-items: flex-end; }
    .filter-field { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 160px; }
    .filter-field label { font-size: 0.72rem; color: #637080; font-weight: 600; }
    .filters input {
      padding: 8px 14px;
      background: #fff;
      color: #1d2d3a;
      border: 2px solid #e0e4e8;
      border-radius: 8px;
      font-size: 0.9rem;
      outline: none;
      font-family: inherit;
      width: 100%;
    }
    .filters input:focus { border-color: #667eea; }
    .filter-btn { padding: 8px 18px; align-self: flex-end; }
    .btn-clear { background: #f0f2f5; color: #637080; border: 1px solid #e0e4e8; }

    .table-wrap { overflow-x: auto; background: #fff; border: 1px solid #e0e4e8; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
    thead tr { background: #f5f7fa; }
    th {
      padding: 12px 14px;
      text-align: left;
      font-size: 0.75rem;
      font-weight: 600;
      color: #637080;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      white-space: nowrap;
      border-bottom: 2px solid #e0e4e8;
    }
    td { padding: 12px 14px; border-top: 1px solid #f0f2f5; vertical-align: middle; color: #1d2d3a; }
    tr:hover td { background: #f8fafc; }
    .user-meta { display: flex; align-items: center; gap: 10px; }
    .avatar-sm {
      width: 34px; height: 34px; border-radius: 50%;
      background: linear-gradient(135deg, #667eea, #764ba2);
      display: flex; align-items: center; justify-content: center;
      font-size: 0.85rem; font-weight: 600; flex-shrink: 0; overflow: hidden; color: #fff;
    }
    .avatar-sm img { width: 100%; height: 100%; object-fit: cover; }
    .user-name { font-weight: 600; color: #1d2d3a; }
    .user-id { font-size: 0.75rem; color: #9ba8b5; }

    .badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
    .badge-admin     { background: rgba(192,57,43,0.1);   color: #c0392b; border: 1px solid rgba(192,57,43,0.25); }
    .badge-user      { background: rgba(85,103,204,0.1);  color: #5567cc; border: 1px solid rgba(85,103,204,0.2); }
    .badge-active    { background: rgba(26,138,80,0.1);   color: #1a8a50; border: 1px solid rgba(26,138,80,0.2); }
    .badge-suspended { background: #f0f2f5; color: #637080; border: 1px solid #e0e4e8; }

    .actions { display: flex; gap: 6px; flex-wrap: wrap; }

    .message { padding: 10px 16px; border-radius: 8px; font-size: 0.875rem; margin-bottom: 20px; }
    .message.error   { background: rgba(192,57,43,0.08); border: 1px solid rgba(192,57,43,0.2); color: #c0392b; }
    .message.success { background: rgba(26,138,80,0.08); border: 1px solid rgba(26,138,80,0.2); color: #1a8a50; }

    .empty { text-align: center; padding: 40px; color: #9ba8b5; }
    .count-label { font-size: 0.85rem; color: #637080; margin-bottom: 12px; }
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
        <div class="user-area">
          <div class="user-menu">
            <div class="user-trigger" tabindex="0">
              <div class="user-avatar">
                <?php if ($currentUser['avatar_url']): ?>
                  <img src="<?= htmlspecialchars($currentUser['avatar_url']) ?>" alt="">
                <?php else: ?>
                  <?= htmlspecialchars(mb_substr($currentUser['display_name'], 0, 1)) ?>
                <?php endif; ?>
              </div>
              <span class="user-display-name"><?= htmlspecialchars($currentUser['display_name']) ?></span>
              <span class="user-caret">▼</span>
            </div>
            <div class="user-dropdown">
              <a href="/">あそびトップ</a>
              <div class="dropdown-divider"></div>
              <a href="/profile.php">プロフィール</a>
              <div class="dropdown-divider"></div>
              <a href="/logout.php" class="dropdown-logout">ログアウト</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </header>

  <div class="admin-body">
    <div class="page-header">
      <h1>ユーザー管理</h1>
    </div>

    <?php if ($actionMsg): ?><div class="message success"><?= htmlspecialchars($actionMsg) ?></div><?php endif; ?>
    <?php if ($actionError): ?><div class="message error"><?= htmlspecialchars($actionError) ?></div><?php endif; ?>

    <div class="create-form">
      <h2>新規ユーザー作成</h2>
      <form method="POST" action="">
        <input type="hidden" name="action" value="create">
        <div class="form-row">
          <div class="form-field">
            <label>ユーザー名 <span style="color:#e74c3c">*</span></label>
            <input type="text" name="username" placeholder="username" required>
          </div>
          <div class="form-field">
            <label>表示名</label>
            <input type="text" name="display_name" placeholder="省略可">
          </div>
          <div class="form-field">
            <label>メール</label>
            <input type="email" name="email" placeholder="省略可">
          </div>
          <div class="form-field">
            <label>パスワード <span style="color:#e74c3c">*</span></label>
            <input type="text" name="password" placeholder="8文字以上" required>
          </div>
          <div class="form-field">
            <label>ロール</label>
            <select name="role">
              <option value="user">ユーザー</option>
              <option value="admin">管理者</option>
            </select>
          </div>
          <div class="form-field" style="display:flex;align-items:flex-end">
            <button type="submit" class="btn btn-primary" style="width:100%">作成</button>
          </div>
        </div>
      </form>
    </div>

    <form method="GET" action="" class="filters">
      <div class="filter-field">
        <label>ユーザー名</label>
        <input type="text" name="s_username" value="<?= htmlspecialchars($sUsername) ?>" placeholder="username">
      </div>
      <div class="filter-field">
        <label>表示名</label>
        <input type="text" name="s_display" value="<?= htmlspecialchars($sDisplay) ?>" placeholder="表示名">
      </div>
      <div class="filter-field">
        <label>メール</label>
        <input type="text" name="s_email" value="<?= htmlspecialchars($sEmail) ?>" placeholder="email@example.com">
      </div>
      <button type="submit" class="btn btn-primary filter-btn">検索</button>
      <?php if ($sUsername || $sDisplay || $sEmail): ?>
        <a href="/admin/users.php" class="btn btn-clear filter-btn" style="text-decoration:none;display:inline-flex;align-items:center">クリア</a>
      <?php endif; ?>
    </form>

    <p class="count-label"><?= count($users) ?> 件</p>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>ユーザー</th>
            <th>メール</th>
            <th>ロール</th>
            <th>状態</th>
            <th>登録日</th>
            <th>最終ログイン</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($users)): ?>
          <tr><td colspan="7" class="empty">ユーザーがいません</td></tr>
          <?php else: ?>
          <?php foreach ($users as $u): ?>
          <tr>
            <td>
              <div class="user-meta">
                <div class="avatar-sm">
                  <?php if ($u['avatar_url']): ?>
                    <img src="<?= htmlspecialchars($u['avatar_url']) ?>" alt="" loading="lazy">
                  <?php else: ?>
                    <?= htmlspecialchars(mb_substr($u['display_name'] ?: $u['username'], 0, 1)) ?>
                  <?php endif; ?>
                </div>
                <div>
                  <div class="user-name"><?= htmlspecialchars($u['display_name'] ?: $u['username']) ?></div>
                  <div class="user-id">@<?= htmlspecialchars($u['username']) ?> &nbsp;#<?= $u['id'] ?></div>
                </div>
              </div>
            </td>
            <td style="color:#8888a0;font-size:0.83rem"><?= htmlspecialchars($u['email'] ?? '—') ?></td>
            <td><span class="badge badge-<?= $u['role'] ?>"><?= $u['role'] === 'admin' ? '管理者' : 'ユーザー' ?></span></td>
            <td><span class="badge badge-<?= $u['status'] ?>"><?= $u['status'] === 'active' ? '有効' : '停止' ?></span></td>
            <td style="font-size:0.8rem;color:#8888a0;white-space:nowrap"><?= htmlspecialchars(substr($u['created_at'], 0, 10)) ?></td>
            <td style="font-size:0.8rem;color:#8888a0;white-space:nowrap"><?= $u['last_login_at'] ? htmlspecialchars(substr($u['last_login_at'], 0, 16)) : '—' ?></td>
            <td>
              <div class="actions">
                <?php if ($u['id'] !== $currentUser['id']): ?>
                  <form method="POST" action="" style="display:contents">
                    <input type="hidden" name="action" value="set_role">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <?php if ($u['role'] === 'user'): ?>
                      <input type="hidden" name="role" value="admin">
                      <button type="submit" class="btn btn-sm btn-warning"
                              onclick="return confirm('管理者に昇格しますか？')">管理者へ</button>
                    <?php else: ?>
                      <input type="hidden" name="role" value="user">
                      <button type="submit" class="btn btn-sm btn-muted"
                              onclick="return confirm('管理者権限を外しますか？')">一般へ</button>
                    <?php endif; ?>
                  </form>
                  <form method="POST" action="" style="display:contents">
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <?php if ($u['status'] === 'active'): ?>
                      <input type="hidden" name="status" value="suspended">
                      <button type="submit" class="btn btn-sm btn-danger"
                              onclick="return confirm('アカウントを停止しますか？')">停止</button>
                    <?php else: ?>
                      <input type="hidden" name="status" value="active">
                      <button type="submit" class="btn btn-sm btn-success">復元</button>
                    <?php endif; ?>
                  </form>
                  <form method="POST" action="" style="display:contents">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger"
                            onclick="return confirm('「<?= htmlspecialchars(addslashes($u['username'])) ?>」を削除します。この操作は取り消せません。')">削除</button>
                  </form>
                <?php else: ?>
                  <span style="font-size:0.8rem;color:#8888a0">（自分）</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
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
  </script>
</body>
</html>
