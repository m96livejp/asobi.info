<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
require_once '/opt/asobi/shared/assets/php/version.php';
asobiRequireAdmin();

$db = asobiUsersDb();
$query = trim($_GET['q'] ?? '');
$results = [];

if ($query !== '') {
    if (ctype_digit($query)) {
        // ID検索
        $stmt = $db->prepare("
            SELECT u.id, u.username, u.display_name, u.email, u.role,
                   COALESCE(w.balance, 0) AS balance,
                   COALESCE(w.total_charged_jpy, 0) AS total_charged_jpy
            FROM users u
            LEFT JOIN wallets w ON w.user_id = u.id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$query]);
    } else {
        // 名前/メール検索
        $like = '%' . $query . '%';
        $stmt = $db->prepare("
            SELECT u.id, u.username, u.display_name, u.email, u.role,
                   COALESCE(w.balance, 0) AS balance,
                   COALESCE(w.total_charged_jpy, 0) AS total_charged_jpy
            FROM users u
            LEFT JOIN wallets w ON w.user_id = u.id
            WHERE u.username LIKE ? OR u.display_name LIKE ? OR u.email LIKE ?
            ORDER BY u.id ASC
            LIMIT 50
        ");
        $stmt->execute([$like, $like, $like]);
    }
    $results = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ユーザー検索 - ウォレット管理</title>
  <link rel="stylesheet" href="/assets/css/common.css?v=<?= assetVer('/assets/css/common.css') ?>">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; color: #1d2d3a; min-height: 100vh; display: flex; flex-direction: column; }
    .card { background: #fff; border: 1px solid #e0e4e8; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
    .card-title { font-size: 1rem; font-weight: 700; margin-bottom: 16px; }
    input[type=text] { padding: 8px 12px; border: 1px solid #e0e4e8; border-radius: 6px; font-size: 0.9rem; width: 100%; }
    .btn { display: inline-block; padding: 8px 16px; border: none; border-radius: 6px; font-size: 0.85rem; font-weight: 600; cursor: pointer; text-decoration: none; }
    .btn-primary { background: #5567cc; color: #fff; }
    .btn-small { padding: 4px 10px; font-size: 0.75rem; }
    .btn:hover { opacity: 0.85; }
    table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    th { background: #f5f7fa; padding: 10px 8px; text-align: left; font-weight: 600; color: #637080; font-size: 0.75rem; border-bottom: 2px solid #e0e4e8; }
    td { padding: 8px; border-bottom: 1px solid #f0f2f5; vertical-align: middle; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
  </style>
</head>
<body>
  <?php $adminActivePage = 'wallet'; require __DIR__ . '/_sidebar.php'; ?>

  <div style="margin-bottom:16px;">
    <a href="/admin/wallet.php" style="font-size:0.85rem;color:#5567cc;text-decoration:none;">← ウォレット管理へ</a>
  </div>

  <h1 style="font-size:1.3rem;margin-bottom:16px;">🔍 ユーザー検索</h1>

  <div class="card">
    <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="ユーザー名・表示名・メール・ID" style="flex:1;min-width:240px;" required autofocus>
      <button type="submit" class="btn btn-primary">検索</button>
    </form>
  </div>

  <div class="card">
    <div class="card-title">検索結果 <?= $query !== '' ? '（' . count($results) . '件）' : '' ?></div>
    <?php if ($query === ''): ?>
      <div style="padding:20px;text-align:center;color:#9ba8b5;font-size:0.85rem;">検索キーワードを入力してください</div>
    <?php elseif (empty($results)): ?>
      <div style="padding:20px;text-align:center;color:#9ba8b5;font-size:0.85rem;">該当ユーザーが見つかりません</div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>ユーザー名</th>
            <th>表示名</th>
            <th>メール</th>
            <th class="num">残高(AC)</th>
            <th class="num">累計チャージ(円)</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($results as $u): ?>
            <tr>
              <td><?= (int)$u['id'] ?></td>
              <td><?= htmlspecialchars($u['username']) ?></td>
              <td>
                <?= htmlspecialchars($u['display_name'] ?? '') ?>
                <?php if ($u['role'] === 'admin'): ?><span style="font-size:0.68rem;background:#5567cc;color:#fff;padding:1px 6px;border-radius:3px;margin-left:4px;">admin</span><?php endif; ?>
              </td>
              <td style="font-size:0.75rem;color:#6b7a8d;"><?= htmlspecialchars($u['email'] ?? '') ?></td>
              <td class="num"><strong><?= number_format((int)$u['balance']) ?></strong></td>
              <td class="num">¥<?= number_format((int)$u['total_charged_jpy']) ?></td>
              <td><a href="/admin/wallet-user.php?user_id=<?= (int)$u['id'] ?>" class="btn btn-primary btn-small">履歴/調整</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  </main>
  </div>
  <script src="/assets/js/common.js?v=<?= assetVer('/assets/js/common.js') ?>"></script>
</body>
</html>
