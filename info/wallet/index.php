<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
require_once '/opt/asobi/shared/assets/php/version.php';
require_once '/opt/asobi/shared/assets/php/wallet.php';
asobiRequireLogin();

$user = asobiGetCurrentUser();
$userId = (int)$user['id'];
$isAdmin = ($user['role'] ?? '') === 'admin';

// 管理者テストモードの切り替え
if ($isAdmin && isset($_GET['admin_test'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if ($_GET['admin_test'] === 'on') {
        $_SESSION['stripe_admin_test_mode'] = 1;
    } else {
        unset($_SESSION['stripe_admin_test_mode']);
    }
    session_write_close();
    header('Location: /wallet/');
    exit;
}

// 現在のテストモード状態
session_start();
$adminTestMode = $isAdmin && !empty($_SESSION['stripe_admin_test_mode']);
session_write_close();

$balance = asobiWalletBalance($userId);
$lots = asobiWalletLots($userId);
$history = asobiWalletHistory($userId, 50);

function typeLabel(string $type): string {
    return [
        'charge'   => 'チャージ',
        'exchange' => '消費',
        'bonus'    => 'ボーナス',
        'expire'   => '有効期限切れ',
        'refund'   => '返金',
        'adjust'   => '管理者調整',
    ][$type] ?? $type;
}
function typeColor(string $type): string {
    return [
        'charge'   => '#2e7d32',
        'exchange' => '#1565c0',
        'bonus'    => '#e67e22',
        'expire'   => '#c62828',
        'refund'   => '#7b1fa2',
        'adjust'   => '#6b7a8d',
    ][$type] ?? '#6b7a8d';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>あそびウォレット - asobi.info</title>
  <link rel="stylesheet" href="/assets/css/common.css?v=<?= assetVer('/assets/css/common.css') ?>">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; color: #1d2d3a; min-height: 100vh; display: flex; flex-direction: column; }
    main { max-width: 720px; margin: 0 auto; padding: 32px 16px; width: 100%; flex: 1; }
    h1 { font-size: 1.3rem; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; }

    .balance-card {
      background: linear-gradient(135deg, #5567cc, #3d4fa8);
      color: #fff;
      border-radius: 16px;
      padding: 28px 24px;
      margin-bottom: 24px;
      box-shadow: 0 4px 16px rgba(85,103,204,0.25);
    }
    .balance-label { font-size: 0.85rem; opacity: 0.9; margin-bottom: 8px; }
    .balance-value { font-size: 2.4rem; font-weight: 800; line-height: 1.1; font-variant-numeric: tabular-nums; }
    .balance-unit { font-size: 1.1rem; margin-left: 4px; opacity: 0.9; }
    .balance-actions { margin-top: 16px; display: flex; gap: 10px; flex-wrap: wrap; }
    .balance-actions a {
      display: inline-block; padding: 10px 20px; background: rgba(255,255,255,0.2);
      border: 1px solid rgba(255,255,255,0.3); border-radius: 8px;
      color: #fff; text-decoration: none; font-size: 0.85rem; font-weight: 600;
      transition: background 0.15s;
    }
    .balance-actions a:hover { background: rgba(255,255,255,0.3); }
    .balance-actions a.primary { background: #fff; color: #5567cc; }
    .balance-actions a.primary:hover { background: #f5f7fa; }

    .section { background: #fff; border: 1px solid #e0e4e8; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
    .section-title { font-size: 1rem; font-weight: 700; margin-bottom: 14px; color: #1d2d3a; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .section-sub { font-size: 0.78rem; color: #6b7a8d; font-weight: 500; }

    .lot-list { display: flex; flex-direction: column; gap: 8px; }
    .lot-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; background: #f5f7fa; border-radius: 8px; font-size: 0.85rem; }
    .lot-info { color: #6b7a8d; font-size: 0.78rem; }
    .lot-ac { font-weight: 700; color: #1d2d3a; font-variant-numeric: tabular-nums; }

    .history-list { display: flex; flex-direction: column; }
    .history-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #f0f2f5; gap: 8px; }
    .history-item:last-child { border-bottom: none; }
    .history-left { flex: 1; min-width: 0; }
    .history-type { font-size: 0.78rem; font-weight: 600; padding: 2px 8px; border-radius: 3px; color: #fff; display: inline-block; }
    .history-note { font-size: 0.78rem; color: #6b7a8d; margin-top: 2px; word-break: break-all; }
    .history-date { font-size: 0.7rem; color: #9ba8b5; margin-top: 2px; }
    .history-amount { font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .history-amount.plus { color: #2e7d32; }
    .history-amount.minus { color: #c62828; }
    .history-balance { font-size: 0.72rem; color: #9ba8b5; text-align: right; margin-top: 2px; }

    .empty { text-align: center; padding: 24px; color: #9ba8b5; font-size: 0.85rem; }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="container">
      <a href="/" class="site-logo">あそび</a>
    </div>
  </header>

  <main>
    <h1><img src="/assets/images/asobi-card.svg" alt="" style="width:28px;height:28px;vertical-align:-6px;margin-right:6px;">あそびウォレット</h1>

    <?php if ($isAdmin): ?>
      <?php if ($adminTestMode): ?>
        <div style="background:#fff3e0;border:2px dashed #ef6c00;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:0.82rem;color:#bf360c;display:flex;justify-content:space-between;align-items:center;gap:8px;">
          <span>⚠️ <strong>テストモード有効</strong>（管理者専用）— Stripeテストカードで決済できます</span>
          <a href="?admin_test=off" style="background:#ef6c00;color:#fff;padding:4px 10px;border-radius:4px;text-decoration:none;font-size:0.78rem;font-weight:600;white-space:nowrap;">OFFにする</a>
        </div>
      <?php else: ?>
        <div style="background:#f5f7fa;border:1px solid #e0e4e8;border-radius:8px;padding:8px 12px;margin-bottom:16px;font-size:0.78rem;color:#6b7a8d;text-align:right;">
          <a href="?admin_test=on" style="color:#6b7a8d;text-decoration:none;">🔧 テストモードON</a>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- 残高 -->
    <div class="balance-card">
      <div class="balance-label">現在の残高</div>
      <div class="balance-value"><?= number_format($balance) ?><span class="balance-unit">AC</span></div>
      <div class="balance-actions">
        <a href="/wallet/charge.php" class="primary">チャージする</a>
        <a href="/about/currency.html">AC について</a>
      </div>
    </div>

    <!-- 有効期限別残高 -->
    <div class="section">
      <div class="section-title">
        <span>有効なチャージ残高</span>
        <span class="section-sub">古いものから順に使われます</span>
      </div>
      <?php if (empty($lots)): ?>
        <div class="empty">有効な残高はありません</div>
      <?php else: ?>
        <div class="lot-list">
          <?php foreach ($lots as $lot): ?>
            <div class="lot-item">
              <div>
                <div class="lot-ac"><?= number_format($lot['ac_remaining']) ?> AC</div>
                <div class="lot-info">
                  <?php if ($lot['charge_jpy'] > 0): ?>
                    ¥<?= number_format($lot['charge_jpy']) ?> チャージ (<?= htmlspecialchars(mb_substr($lot['created_at'], 0, 10)) ?>)
                  <?php else: ?>
                    ボーナス付与 (<?= htmlspecialchars(mb_substr($lot['created_at'], 0, 10)) ?>)
                  <?php endif; ?>
                </div>
              </div>
              <div class="lot-info">
                期限: <?= htmlspecialchars(mb_substr($lot['expires_at'], 0, 10)) ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- 取引履歴 -->
    <div class="section">
      <div class="section-title">
        <span>取引履歴</span>
        <span class="section-sub">最新50件</span>
      </div>
      <?php if (empty($history)): ?>
        <div class="empty">取引履歴はありません</div>
      <?php else: ?>
        <div class="history-list">
          <?php foreach ($history as $h): ?>
            <div class="history-item">
              <div class="history-left">
                <span class="history-type" style="background:<?= typeColor($h['type']) ?>;"><?= typeLabel($h['type']) ?></span>
                <?php if (!empty($h['is_test'])): ?>
                  <span style="font-size:0.68rem;background:#ef6c00;color:#fff;padding:2px 6px;border-radius:3px;font-weight:600;margin-left:4px;">TEST</span>
                <?php endif; ?>
                <?php if ($h['note']): ?>
                  <span class="history-note"><?= htmlspecialchars($h['note']) ?></span>
                <?php endif; ?>
                <?php if (!empty($h['admin_comment'])): ?>
                  <div class="history-note" style="color:#1565c0;margin-top:2px;">💬 <?= htmlspecialchars($h['admin_comment']) ?></div>
                <?php endif; ?>
                <div class="history-date"><?= htmlspecialchars($h['created_at']) ?></div>
              </div>
              <div>
                <div class="history-amount <?= $h['amount'] > 0 ? 'plus' : 'minus' ?>">
                  <?= $h['amount'] > 0 ? '+' : '' ?><?= number_format($h['amount']) ?>
                </div>
                <div class="history-balance">残 <?= number_format($h['balance_after']) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>

  <footer class="site-footer">
    <div class="container">
      <p>&copy; 2026 あそび</p>
    </div>
  </footer>
  <script src="/assets/js/common.js?v=<?= assetVer('/assets/js/common.js') ?>"></script>
</body>
</html>
