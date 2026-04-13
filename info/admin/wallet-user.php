<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
require_once '/opt/asobi/shared/assets/php/version.php';
require_once '/opt/asobi/shared/assets/php/wallet.php';
asobiRequireAdmin();

$adminUser = asobiGetCurrentUser();
$adminUserId = (int)$adminUser['id'];
$db = asobiUsersDb();

$targetUserId = (int)($_GET['user_id'] ?? 0);
if ($targetUserId <= 0) {
    header('Location: /admin/wallet.php');
    exit;
}

// フラッシュメッセージ
session_start();
$msg = $_SESSION['wuser_msg'] ?? '';
unset($_SESSION['wuser_msg']);
session_write_close();

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    session_start();

    if ($action === 'adjust') {
        $amount = (int)($_POST['amount'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        if ($amount !== 0 && $note !== '') {
            try {
                $ok = asobiWalletAdjust($targetUserId, $amount, '[管理者] ' . $note);
                $_SESSION['wuser_msg'] = $ok
                    ? ('success:' . ($amount > 0 ? '+' : '') . number_format($amount) . ' AC を調整しました')
                    : 'error:残高不足または失敗';
            } catch (Exception $e) {
                $_SESSION['wuser_msg'] = 'error:' . $e->getMessage();
            }
        } else {
            $_SESSION['wuser_msg'] = 'error:金額とメモを入力してください';
        }
        header('Location: /admin/wallet-user.php?user_id=' . $targetUserId);
        exit;
    }

    if ($action === 'comment') {
        $txId = (int)($_POST['tx_id'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');
        if ($txId > 0) {
            asobiWalletAdminComment($txId, $comment, $adminUserId);
            $_SESSION['wuser_msg'] = 'success:コメントを更新しました';
        }
        header('Location: /admin/wallet-user.php?user_id=' . $targetUserId);
        exit;
    }
}

// ユーザー情報
$userStmt = $db->prepare("SELECT id, username, display_name, email, role FROM users WHERE id = ?");
$userStmt->execute([$targetUserId]);
$userRow = $userStmt->fetch();
if (!$userRow) {
    header('Location: /admin/wallet.php');
    exit;
}

$balance = asobiWalletBalance($targetUserId);
$totalChargedStmt = $db->prepare("SELECT total_charged_jpy FROM wallets WHERE user_id = ?");
$totalChargedStmt->execute([$targetUserId]);
$totalCharged = (int)($totalChargedStmt->fetchColumn() ?: 0);
$lots = asobiWalletLots($targetUserId);
$history = asobiWalletHistory($targetUserId, 200);

function typeLabel(string $type): string {
    return [
        'charge' => 'チャージ', 'exchange' => '消費', 'bonus' => 'ボーナス',
        'expire' => '失効', 'refund' => '返金', 'adjust' => '調整',
    ][$type] ?? $type;
}
function typeColor(string $type): string {
    return [
        'charge' => '#2e7d32', 'exchange' => '#1565c0', 'bonus' => '#e67e22',
        'expire' => '#c62828', 'refund' => '#7b1fa2', 'adjust' => '#6b7a8d',
    ][$type] ?? '#6b7a8d';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ウォレット履歴 - <?= htmlspecialchars($userRow['display_name'] ?? $userRow['username']) ?></title>
  <link rel="stylesheet" href="/assets/css/common.css?v=<?= assetVer('/assets/css/common.css') ?>">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; color: #1d2d3a; min-height: 100vh; display: flex; flex-direction: column; }
    .card { background: #fff; border: 1px solid #e0e4e8; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
    .card-title { font-size: 1rem; font-weight: 700; margin-bottom: 16px; color: #1d2d3a; display: flex; justify-content: space-between; align-items: center; }
    .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 0.85rem; }
    .flash.success { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
    .flash.error { background: #ffebee; color: #c62828; border: 1px solid #ef9a9a; }
    .user-info { display: flex; gap: 16px; flex-wrap: wrap; font-size: 0.85rem; }
    .user-info-item { padding: 8px 14px; background: #f5f7fa; border-radius: 6px; }
    .user-info-label { color: #6b7a8d; font-size: 0.75rem; }
    .user-info-value { font-weight: 600; font-variant-numeric: tabular-nums; }
    table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
    th { background: #f5f7fa; padding: 8px; text-align: left; font-weight: 600; color: #637080; font-size: 0.72rem; border-bottom: 2px solid #e0e4e8; }
    td { padding: 8px; border-bottom: 1px solid #f0f2f5; vertical-align: top; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .plus { color: #2e7d32; font-weight: 600; }
    .minus { color: #c62828; font-weight: 600; }
    .type-badge { font-size: 0.68rem; font-weight: 600; padding: 2px 8px; border-radius: 3px; color: #fff; white-space: nowrap; display: inline-block; }
    .test-badge { font-size: 0.65rem; background: #ef6c00; color: #fff; padding: 1px 5px; border-radius: 3px; font-weight: 600; margin-left: 4px; }
    input[type=text], input[type=number], textarea { padding: 6px 10px; border: 1px solid #e0e4e8; border-radius: 6px; font-size: 0.85rem; width: 100%; font-family: inherit; }
    .btn { display: inline-block; padding: 6px 14px; border: none; border-radius: 6px; font-size: 0.8rem; font-weight: 600; cursor: pointer; text-decoration: none; }
    .btn-primary { background: #5567cc; color: #fff; }
    .btn-secondary { background: #6b7a8d; color: #fff; }
    .btn-small { padding: 4px 10px; font-size: 0.72rem; }
    .btn:hover { opacity: 0.85; }
    .comment-edit { width: 100%; min-width: 160px; }
    .admin-comment { font-size: 0.72rem; color: #1565c0; margin-top: 2px; }
  </style>
</head>
<body>
  <?php $adminActivePage = 'wallet'; require __DIR__ . '/_sidebar.php'; ?>

  <div style="margin-bottom:16px;">
    <a href="/admin/wallet.php" style="font-size:0.85rem;color:#5567cc;text-decoration:none;">← ウォレット管理へ</a>
  </div>

  <h1 style="font-size:1.3rem;margin-bottom:16px;">
    👤 <?= htmlspecialchars($userRow['display_name'] ?? $userRow['username']) ?> のウォレット
  </h1>

  <?php if ($msg): list($type, $text) = explode(':', $msg, 2); ?>
    <div class="flash <?= htmlspecialchars($type) ?>"><?= htmlspecialchars($text) ?></div>
  <?php endif; ?>

  <!-- ユーザー情報 -->
  <div class="card">
    <div class="user-info">
      <div class="user-info-item"><div class="user-info-label">ID</div><div class="user-info-value"><?= (int)$userRow['id'] ?></div></div>
      <div class="user-info-item"><div class="user-info-label">ユーザー名</div><div class="user-info-value"><?= htmlspecialchars($userRow['username']) ?></div></div>
      <div class="user-info-item"><div class="user-info-label">表示名</div><div class="user-info-value"><?= htmlspecialchars($userRow['display_name'] ?? '') ?></div></div>
      <div class="user-info-item"><div class="user-info-label">ロール</div><div class="user-info-value"><?= htmlspecialchars($userRow['role']) ?></div></div>
      <div class="user-info-item"><div class="user-info-label">残高</div><div class="user-info-value" style="color:#5567cc;font-size:1.05rem;"><?= number_format($balance) ?> AC</div></div>
      <div class="user-info-item"><div class="user-info-label">累計チャージ</div><div class="user-info-value">¥<?= number_format($totalCharged) ?></div></div>
    </div>
  </div>

  <!-- 手動調整 -->
  <div class="card">
    <div class="card-title">手動AC調整</div>
    <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <input type="hidden" name="action" value="adjust">
      <div style="flex:0 0 140px;">
        <label style="font-size:0.78rem;color:#6b7a8d;">金額(AC, +/-)</label>
        <input type="number" name="amount" placeholder="例: +100 / -50" required>
      </div>
      <div style="flex:1;min-width:200px;">
        <label style="font-size:0.78rem;color:#6b7a8d;">理由（必須）</label>
        <input type="text" name="note" placeholder="例: お詫び補填、返金調整..." required>
      </div>
      <button type="submit" class="btn btn-primary">調整実行</button>
    </form>
    <div style="font-size:0.72rem;color:#6b7a8d;margin-top:8px;line-height:1.5;">
      ※ プラスで付与、マイナスで減算。減算は残高不足時に失敗します。<br>
      ※ 6ヶ月有効期限のロットが新規作成されます（付与時）。
    </div>
  </div>

  <!-- 有効ロット -->
  <div class="card">
    <div class="card-title">有効なロット</div>
    <?php if (empty($lots)): ?>
      <div style="padding:12px;text-align:center;color:#9ba8b5;font-size:0.85rem;">なし</div>
    <?php else: ?>
      <table>
        <thead><tr><th>ID</th><th class="num">金額(円)</th><th class="num">基本</th><th class="num">ボーナス</th><th class="num">残</th><th>有効期限</th><th>作成</th></tr></thead>
        <tbody>
          <?php foreach ($lots as $l): ?>
            <tr>
              <td><?= (int)$l['id'] ?></td>
              <td class="num"><?= $l['charge_jpy'] > 0 ? '¥' . number_format($l['charge_jpy']) : '-' ?></td>
              <td class="num"><?= number_format((int)$l['ac_base']) ?></td>
              <td class="num" style="color:#e67e22;"><?= number_format((int)$l['ac_bonus']) ?></td>
              <td class="num"><strong><?= number_format((int)$l['ac_remaining']) ?></strong></td>
              <td style="font-size:0.72rem;"><?= htmlspecialchars(mb_substr($l['expires_at'], 0, 16)) ?></td>
              <td style="font-size:0.72rem;color:#9ba8b5;"><?= htmlspecialchars(mb_substr($l['created_at'], 0, 16)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- 取引履歴 -->
  <div class="card">
    <div class="card-title">取引履歴（最新200件）</div>
    <?php if (empty($history)): ?>
      <div style="padding:12px;text-align:center;color:#9ba8b5;font-size:0.85rem;">履歴なし</div>
    <?php else: ?>
      <table>
        <thead><tr><th>ID</th><th>種別</th><th class="num">増減</th><th class="num">残高</th><th>メモ/参照</th><th style="width:260px;">管理者コメント</th><th>日時</th></tr></thead>
        <tbody>
          <?php foreach ($history as $h): ?>
            <tr>
              <td><?= (int)$h['id'] ?></td>
              <td>
                <span class="type-badge" style="background:<?= typeColor($h['type']) ?>;"><?= typeLabel($h['type']) ?></span>
                <?php if (!empty($h['is_test'])): ?><span class="test-badge">TEST</span><?php endif; ?>
              </td>
              <td class="num <?= $h['amount'] > 0 ? 'plus' : 'minus' ?>">
                <?= $h['amount'] > 0 ? '+' : '' ?><?= number_format((int)$h['amount']) ?>
              </td>
              <td class="num"><?= number_format((int)$h['balance_after']) ?></td>
              <td style="font-size:0.75rem;word-break:break-all;">
                <?= htmlspecialchars($h['note'] ?? '') ?>
                <?php if ($h['reference_id']): ?>
                  <div style="font-size:0.68rem;color:#9ba8b5;margin-top:2px;"><?= htmlspecialchars($h['reference_id']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <form method="POST" style="display:flex;gap:4px;">
                  <input type="hidden" name="action" value="comment">
                  <input type="hidden" name="tx_id" value="<?= (int)$h['id'] ?>">
                  <input type="text" name="comment" value="<?= htmlspecialchars($h['admin_comment'] ?? '') ?>" class="comment-edit" placeholder="コメント..." style="font-size:0.75rem;">
                  <button type="submit" class="btn btn-secondary btn-small">保存</button>
                </form>
              </td>
              <td style="font-size:0.72rem;color:#9ba8b5;white-space:nowrap;"><?= htmlspecialchars($h['created_at']) ?></td>
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
