<?php
require_once '/home/m96/asobi.info/public_html/assets/php/auth.php';
asobiRequireAdmin();

$db = asobiUsersDb();
$msg = '';

// 追加
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $rawWords = $_POST['words'] ?? '';
    $category = $_POST['category'] ?? '';
    $action   = $_POST['baction'] ?? 'block';
    $note     = trim($_POST['note'] ?? '');

    if (!in_array($category, ['username','content','both'], true) || !in_array($action, ['block','warn'], true)) {
        $msg = 'error:入力内容が不正です';
    } else {
        $lines = array_filter(array_map('trim', explode("\n", str_replace("\r", '', $rawWords))));
        if (empty($lines)) {
            $msg = 'error:ワードを1つ以上入力してください';
        } else {
            $stmt = $db->prepare("INSERT OR IGNORE INTO banned_words (word, normalized, category, action, note) VALUES (?, ?, ?, ?, ?)");
            $added = 0;
            $skipped = 0;
            foreach ($lines as $word) {
                $normalized = asobiNormalize($word);
                $stmt->execute([$word, $normalized, $category, $action, $note]);
                if ($stmt->rowCount() > 0) { $added++; } else { $skipped++; }
            }
            $msg = 'success:' . $added . '件登録しました';
            if ($skipped > 0) $msg .= '（' . $skipped . '件はスキップ・重複）';
        }
    }
}

// 削除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $db->prepare("DELETE FROM banned_words WHERE id = ?")->execute([$id]);
        $msg = 'success:削除しました';
    }
}

$words = $db->query("SELECT * FROM banned_words ORDER BY category, id")->fetchAll();

$categoryLabels = ['username' => '名前', 'content' => 'コメント', 'both' => '両方'];
$actionLabels   = ['block' => 'ブロック', 'warn' => '警告'];
$currentUser    = asobiGetCurrentUser();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>禁止ワード管理 - asobi.info</title>
  <link rel="stylesheet" href="/assets/css/common.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Noto Sans JP', sans-serif;
      background: #f0f2f5;
      color: #1d2d3a;
      min-height: 100vh;
    }
    .site-header {
      background: rgba(255,255,255,0.85);
      border-bottom: 1px solid #e0e0e0;
    }
    .site-logo a { color: #1d1d1f; text-decoration: none; font-size: 1.5rem; font-weight: 700; }
    .header-right { display: flex; align-items: center; gap: 24px; }
    .site-nav ul { display: flex; list-style: none; gap: 24px; }
    .site-nav a { color: #1d1d1f; font-weight: 500; font-size: 0.9rem; }
    .admin-badge {
      display: inline-block; font-size: 0.7rem; font-weight: 700;
      padding: 2px 8px; background: linear-gradient(135deg, #e74c3c, #c0392b);
      color: #fff; border-radius: 10px; margin-left: 6px; vertical-align: middle;
    }
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

    .admin-body { max-width: 900px; margin: 0 auto; padding: 40px 24px; }
    h1 { font-size: 1.4rem; margin-bottom: 32px; color: #1d2d3a; }
    .section-title { font-size: 1rem; font-weight: 600; color: #637080; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 1px solid #e0e4e8; }

    .add-form {
      background: #fff; border: 1px solid #e0e4e8; border-radius: 12px;
      padding: 24px; margin-bottom: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .form-row { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
    .form-group { display: flex; flex-direction: column; gap: 6px; }
    .form-group label { font-size: 0.78rem; font-weight: 600; color: #637080; }
    .form-group input[type=text] {
      padding: 9px 12px; border: 2px solid #e0e4e8; border-radius: 8px;
      font-size: 0.9rem; font-family: inherit; outline: none; transition: border-color 0.2s;
    }
    .form-group input[type=text]:focus { border-color: #667eea; }
    .form-group select {
      padding: 9px 12px; border: 2px solid #e0e4e8; border-radius: 8px;
      font-size: 0.9rem; font-family: inherit; outline: none; transition: border-color 0.2s; background: #fff;
    }
    .form-group select:focus { border-color: #667eea; }
    .btn-add {
      padding: 9px 20px; background: linear-gradient(135deg, #667eea, #764ba2);
      color: #fff; border: none; border-radius: 8px; font-size: 0.9rem;
      font-weight: 600; cursor: pointer; font-family: inherit; white-space: nowrap;
    }
    .btn-add:hover { opacity: 0.88; }

    .msg { padding: 10px 14px; border-radius: 8px; font-size: 0.875rem; margin-bottom: 20px; }
    .msg.success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
    .msg.error   { background: #fff1f2; border: 1px solid #fecdd3; color: #e11d48; }

    .words-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .words-table th { background: #f8fafc; font-size: 0.8rem; font-weight: 600; color: #637080; padding: 10px 14px; text-align: left; border-bottom: 2px solid #e0e4e8; }
    .words-table td { padding: 10px 14px; border-bottom: 1px solid #f0f2f5; font-size: 0.875rem; color: #1d2d3a; }
    .words-table tr:last-child td { border-bottom: none; }
    .words-table tr:hover td { background: #f8fafc; }

    .badge { display: inline-block; font-size: 0.72rem; font-weight: 600; padding: 2px 8px; border-radius: 10px; }
    .badge-username { background: rgba(102,126,234,0.15); color: #5567cc; }
    .badge-content  { background: rgba(240,147,251,0.15); color: #a820c9; }
    .badge-both     { background: rgba(231,76,60,0.15);   color: #c0392b; }
    .badge-block    { background: rgba(231,76,60,0.12);   color: #e74c3c; }
    .badge-warn     { background: rgba(247,201,78,0.2);   color: #b78a00; }

    .btn-delete {
      padding: 4px 12px; background: #fff; border: 1px solid #e0e4e8; border-radius: 6px;
      font-size: 0.78rem; color: #e74c3c; cursor: pointer; font-family: inherit; transition: background 0.15s;
    }
    .btn-delete:hover { background: #fff1f2; }

    .mono { font-family: 'Consolas', 'Monaco', monospace; font-size: 0.85rem; color: #637080; }
    .no-data { text-align: center; color: #9ba8b5; padding: 24px; }
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
            <li><a href="/admin/banned-words.php">禁止ワード</a></li>
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
    <h1>禁止ワード管理</h1>

    <?php if ($msg): ?>
    <?php [$type, $text] = explode(':', $msg, 2); ?>
    <div class="msg <?= $type ?>"><?= $text ?></div>
    <?php endif; ?>

    <div class="add-form">
      <div class="section-title">ワードを追加</div>
      <form method="POST" action="">
        <input type="hidden" name="action" value="add">
        <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;">
          <div class="form-group" style="flex:1;min-width:180px;">
            <label>ワード <span style="color:#e74c3c;">*</span> <span style="color:#9ca3af;font-weight:400;">1行1ワード・複数可</span></label>
            <textarea name="words" rows="10" placeholder="" required
              style="width:100%;padding:9px 12px;border:2px solid #e0e4e8;border-radius:8px;font-size:0.9rem;font-family:inherit;outline:none;resize:vertical;transition:border-color 0.2s;line-height:1.6;"
              onfocus="this.style.borderColor='#667eea'" onblur="this.style.borderColor='#e0e4e8'"></textarea>
          </div>
          <div style="display:flex;flex-direction:column;gap:12px;min-width:200px;">
            <div class="form-group">
              <label>カテゴリ</label>
              <select name="category">
                <option value="username">名前（ユーザー名・表示名）</option>
                <option value="content">コメント・メモ</option>
                <option value="both">両方</option>
              </select>
            </div>
            <div class="form-group">
              <label>アクション</label>
              <select name="baction">
                <option value="block">ブロック（登録不可）</option>
                <option value="warn">警告のみ</option>
              </select>
            </div>
            <div class="form-group">
              <label>メモ <span style="color:#9ca3af;font-weight:400;">任意</span></label>
              <input type="text" name="note" placeholder="管理用メモ" style="width:100%;">
            </div>
            <button type="submit" class="btn-add">追加</button>
          </div>
        </div>
        <p style="font-size:0.75rem;color:#9ba8b5;margin-top:10px;">※ ひらがな・カタカナ・半角カタカナは自動的に同一視されます。重複ワードはスキップされます。</p>
      </form>
    </div>

    <div class="section-title">登録済みワード（<?= count($words) ?>件）</div>
    <?php if (empty($words)): ?>
    <div class="no-data">登録されているワードはありません</div>
    <?php else: ?>
    <table class="words-table">
      <thead>
        <tr>
          <th>ワード</th>
          <th>正規化形</th>
          <th>カテゴリ</th>
          <th>アクション</th>
          <th>メモ</th>
          <th>登録日</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($words as $w): ?>
        <tr>
          <td><strong><?= htmlspecialchars($w['word']) ?></strong></td>
          <td class="mono"><?= htmlspecialchars($w['normalized']) ?></td>
          <td><span class="badge badge-<?= $w['category'] ?>"><?= $categoryLabels[$w['category']] ?? $w['category'] ?></span></td>
          <td><span class="badge badge-<?= $w['action'] ?>"><?= $actionLabels[$w['action']] ?? $w['action'] ?></span></td>
          <td style="color:#637080;"><?= htmlspecialchars($w['note']) ?></td>
          <td style="color:#9ba8b5;font-size:0.78rem;white-space:nowrap;"><?= substr($w['created_at'], 0, 10) ?></td>
          <td>
            <form method="POST" action="" onsubmit="return confirm('削除しますか？')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $w['id'] ?>">
              <button type="submit" class="btn-delete">削除</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
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
