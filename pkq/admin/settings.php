<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
    $new = trim($_POST['new_password']);
    $confirm = trim($_POST['confirm_password']);

    if (strlen($new) < 8) {
        $error = 'パスワードは8文字以上にしてください。';
    } elseif ($new !== $confirm) {
        $error = '確認用パスワードが一致しません。';
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $configFile = __DIR__ . '/config.php';
        $content = "<?php\nreturn [\n    'password_hash' => " . var_export($hash, true) . ",\n];\n";
        if (file_put_contents($configFile, $content) !== false) {
            $success = 'パスワードを変更しました。';
        } else {
            $error = 'ファイルへの書き込みに失敗しました。サーバーのパーミッションを確認してください。';
        }
    }
}

layout_head('設定', 'settings');
?>

<div class="card" style="max-width:480px;">
  <div class="card-header">パスワード変更</div>
  <div class="card-body">
    <?php if ($success): ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
      <div class="form-group">
        <label class="form-label">新しいパスワード（8文字以上）</label>
        <input type="password" name="new_password" class="form-control" required minlength="8">
      </div>
      <div class="form-group">
        <label class="form-label">確認用パスワード</label>
        <input type="password" name="confirm_password" class="form-control" required>
      </div>
      <button type="submit" class="btn btn-success">パスワードを変更する</button>
    </form>
  </div>
</div>

<?php layout_foot(); ?>
