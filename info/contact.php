<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
require_once '/opt/asobi/shared/assets/php/users_db.php';

$currentUser = asobiGetCurrentUser();
session_write_close();

$errors  = [];
$success = false;

$categories = ['一般', '不具合報告', 'ご要望', 'その他'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRFチェック
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = '不正なリクエストです。';
    }

    // ハニーポット
    if (!empty($_POST['website'])) {
        $errors[] = '不正なリクエストです。';
    }

    $type       = trim($_POST['type']       ?? '個人');
    $name       = trim($_POST['name']       ?? '');
    $company    = trim($_POST['company']    ?? '');
    $department = trim($_POST['department'] ?? '');
    $email      = trim($_POST['email']      ?? '');
    $phone      = trim($_POST['phone']      ?? '');
    $category   = trim($_POST['category']   ?? '');
    $message    = trim($_POST['message']    ?? '');
    $ip         = $_SERVER['REMOTE_ADDR']   ?? '';

    if (!in_array($type, ['個人', '法人'])) $type = '個人';

    if ($type === '法人') {
        if (empty($company)) $errors[] = '社名を入力してください。';
        if (empty($name))    $errors[] = '担当者名を入力してください。';
    } else {
        if (empty($name))    $errors[] = 'お名前を入力してください。';
    }

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = '有効なメールアドレスを入力してください。';
    if (!in_array($category, $categories)) $errors[] = 'お問い合わせ種別を選択してください。';
    if (empty($message)) $errors[] = 'お問い合わせ内容を入力してください。';
    if (mb_strlen($message) > 3000) $errors[] = '内容は3000文字以内で入力してください。';

    // レート制限（1時間に3回まで）
    if (empty($errors)) {
        $db    = asobiUsersDb();
        $count = $db->prepare("SELECT COUNT(*) FROM contact_submissions WHERE ip=? AND created_at >= datetime('now','localtime','-1 hour')");
        $count->execute([$ip]);
        if ((int)$count->fetchColumn() >= 3) {
            $errors[] = '送信回数が上限に達しました。しばらく時間をおいてから再度お試しください。';
        }
    }

    if (empty($errors)) {
        $db = asobiUsersDb();
        $db->prepare("INSERT INTO contact_submissions (ip, type, name, company, department, email, phone, category, message) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$ip, $type, $name, $company, $department, $email, $phone, $category, $message]);

        $nameLabel = $type === '法人' ? "【社名】{$company}\n【部署名】{$department}\n【担当者名】{$name}" : "【お名前】{$name}";
        $phoneLine = $phone ? "【電話番号】{$phone}\n" : '';

        $subject = '[あそび お問い合わせ] ' . $category . ' - ' . mb_substr($name, 0, 20);
        $body    = "お問い合わせを受け付けました。\n\n"
                 . "【区分】{$type}\n"
                 . "{$nameLabel}\n"
                 . "【メールアドレス】{$email}\n"
                 . $phoneLine
                 . "【種別】{$category}\n"
                 . "【内容】\n{$message}\n\n"
                 . "【送信日時】" . date('Y-m-d H:i:s') . "\n"
                 . "【IPアドレス】{$ip}\n";

        $headers  = "From: noreply@asobi.info\r\n";
        $headers .= "Reply-To: {$email}\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

        mail('form@asobi.info', mb_encode_mimeheader($subject, 'UTF-8'), $body, $headers);

        $success = true;
    }
}

// CSRFトークン生成
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
session_write_close();

$p = $_POST;
$selType = $p['type'] ?? '個人';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>お問い合わせ - あそび</title>
  <link rel="stylesheet" href="/assets/css/common.css?v=20260327e">
  <style>
    main { max-width: 600px; margin: 0 auto; padding: 40px 16px 60px; }
    h1 { font-size: 1.4rem; margin-bottom: 8px; }
    .lead { font-size: 0.88rem; color: #666; margin-bottom: 28px; }
    .section-label { font-size: 0.8rem; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: .05em; margin: 24px 0 12px; padding-bottom: 6px; border-bottom: 1px solid #eee; }
    .form-group { margin-bottom: 16px; }
    label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; }
    label .req { color: #e74c3c; margin-left: 4px; }
    label .opt { color: #999; font-size: 0.75rem; font-weight: 400; margin-left: 4px; }
    input[type=text], input[type=email], input[type=tel], select, textarea {
      width: 100%; box-sizing: border-box;
      padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px;
      font-size: 0.95rem; font-family: inherit;
      background: #fff; color: #222;
      transition: border-color .15s;
    }
    input:focus, select:focus, textarea:focus { outline: none; border-color: #3498db; }
    textarea { resize: vertical; min-height: 140px; }
    .type-toggle { display: flex; gap: 10px; margin-bottom: 20px; }
    .type-btn { flex: 1; padding: 10px; border: 2px solid #ddd; border-radius: 8px; background: #fff; font-size: 0.9rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: all .15s; color: #555; }
    .type-btn.active { border-color: #3498db; background: #f0f8ff; color: #2980b9; }
    .corp-fields { display: none; }
    .corp-fields.show { display: block; }
    .honeypot { display: none; }
    .errors { background: #fdecea; border: 1px solid #e74c3c; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; }
    .errors li { font-size: 0.85rem; color: #c0392b; }
    .success-box { background: #eafaf1; border: 1px solid #27ae60; border-radius: 8px; padding: 20px; text-align: center; }
    .success-box p { color: #1e8449; margin: 0 0 6px; font-weight: 600; }
    .success-box small { color: #555; font-size: 0.82rem; }
    .btn-submit { width: 100%; padding: 12px; background: #3498db; color: #fff; border: none; border-radius: 8px; font-size: 1rem; font-weight: 700; cursor: pointer; font-family: inherit; margin-top: 8px; }
    .btn-submit:hover { background: #2980b9; }
    .char-count { font-size: 0.75rem; color: #999; text-align: right; margin-top: 4px; }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="container">
      <a href="/" class="site-logo">あそび</a>
    </div>
  </header>

  <main>
    <h1>お問い合わせ</h1>
    <p class="lead">ご質問・ご要望・不具合報告などお気軽にどうぞ。<br>お返事にお時間をいただく場合があります。</p>

    <?php if ($success): ?>
      <div class="success-box">
        <p>送信が完了しました</p>
        <small>お問い合わせいただきありがとうございます。内容を確認いたします。なお、内容によってはご返答できない場合があります。</small>
      </div>
    <?php else: ?>

      <?php if ($errors): ?>
        <ul class="errors">
          <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <form method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="type" id="type-input" value="<?= htmlspecialchars($selType) ?>">
        <div class="honeypot">
          <input type="text" name="website" tabindex="-1" autocomplete="off">
        </div>

        <!-- 個人/法人 -->
        <div class="section-label">区分</div>
        <div class="type-toggle">
          <button type="button" class="type-btn <?= $selType === '個人' ? 'active' : '' ?>" onclick="setType('個人')">個人</button>
          <button type="button" class="type-btn <?= $selType === '法人' ? 'active' : '' ?>" onclick="setType('法人')">法人・団体</button>
        </div>

        <!-- 法人フィールド -->
        <div class="corp-fields <?= $selType === '法人' ? 'show' : '' ?>" id="corp-fields">
          <div class="form-group">
            <label>社名<span class="req">*</span></label>
            <input type="text" name="company" value="<?= htmlspecialchars($p['company'] ?? '') ?>" placeholder="株式会社〇〇" maxlength="100">
          </div>
          <div class="form-group">
            <label>部署名<span class="opt">任意</span></label>
            <input type="text" name="department" value="<?= htmlspecialchars($p['department'] ?? '') ?>" placeholder="営業部" maxlength="100">
          </div>
        </div>

        <!-- 氏名 -->
        <div class="form-group">
          <label id="name-label"><?= $selType === '法人' ? '担当者名' : 'お名前' ?><span class="req">*</span></label>
          <input type="text" name="name" value="<?= htmlspecialchars($p['name'] ?? ($currentUser['display_name'] ?? '')) ?>" placeholder="山田 太郎" maxlength="50" required>
        </div>

        <!-- 連絡先 -->
        <div class="section-label">連絡先</div>
        <div class="form-group">
          <label>メールアドレス<span class="req">*</span></label>
          <input type="email" name="email" value="<?= htmlspecialchars($p['email'] ?? '') ?>" placeholder="example@example.com" maxlength="200" required>
        </div>
        <div class="form-group">
          <label>電話番号<span class="opt">任意</span></label>
          <input type="tel" name="phone" value="<?= htmlspecialchars($p['phone'] ?? '') ?>" placeholder="03-0000-0000" maxlength="20">
        </div>

        <!-- お問い合わせ内容 -->
        <div class="section-label">お問い合わせ</div>
        <div class="form-group">
          <label>種別<span class="req">*</span></label>
          <select name="category" required>
            <option value="">選択してください</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= htmlspecialchars($cat) ?>" <?= (($p['category'] ?? '') === $cat) ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>内容<span class="req">*</span></label>
          <textarea name="message" placeholder="お問い合わせ内容を入力してください。" maxlength="3000" id="message-input" required><?= htmlspecialchars($p['message'] ?? '') ?></textarea>
          <div class="char-count"><span id="char-count">0</span> / 3000</div>
        </div>

        <button type="submit" class="btn-submit">送信する</button>
      </form>

    <?php endif; ?>
  </main>

  <footer class="site-footer">
    <div class="container">
      <p style="font-size:0.72rem; margin-bottom:6px; opacity:0.6;">
        <a href="/" style="color:inherit;">← トップへ戻る</a>
      </p>
      <p>&copy; 2026 あそび - ゲーム情報ポータル</p>
    </div>
  </footer>
  <script src="/assets/js/common.js?v=20260327h"></script>
  <script>
    function setType(type) {
      document.getElementById('type-input').value = type;
      document.querySelectorAll('.type-btn').forEach(b => b.classList.toggle('active', b.textContent.trim().startsWith(type === '個人' ? '個人' : '法人')));
      document.getElementById('corp-fields').classList.toggle('show', type === '法人');
      document.getElementById('name-label').firstChild.textContent = type === '法人' ? '担当者名' : 'お名前';
    }
    const msg = document.getElementById('message-input');
    const cnt = document.getElementById('char-count');
    if (msg && cnt) {
      msg.addEventListener('input', () => cnt.textContent = msg.value.length);
      cnt.textContent = msg.value.length;
    }
  </script>
</body>
</html>
