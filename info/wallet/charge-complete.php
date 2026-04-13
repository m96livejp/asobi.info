<?php
/**
 * チャージ完了ページ
 * Stripe決済後にリダイレクトされる
 */
require_once '/opt/asobi/shared/assets/php/auth.php';
require_once '/opt/asobi/shared/assets/php/version.php';
require_once '/opt/asobi/shared/assets/php/stripe.php';
asobiRequireLogin();

$user = asobiGetCurrentUser();
$userId = (int)$user['id'];

$sessionId = $_GET['session_id'] ?? '';
$chargeInfo = null;
$error = '';

if ($sessionId) {
    try {
        $session = stripeGetCheckoutSession($sessionId);
        $chargeInfo = [
            'amount' => $session->amount_total,
            'status' => $session->payment_status,
            'ac_total' => (int)($session->metadata['ac_total'] ?? 0),
            'ac_base' => (int)($session->metadata['ac_base'] ?? 0),
            'ac_bonus' => (int)($session->metadata['ac_bonus'] ?? 0),
        ];
    } catch (Exception $e) {
        $error = '決済情報の取得に失敗しました';
    }
}

$balance = asobiWalletBalance($userId);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>チャージ完了 - あそびウォレット</title>
  <link rel="stylesheet" href="/assets/css/common.css?v=<?= assetVer('/assets/css/common.css') ?>">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; color: #1d2d3a; min-height: 100vh; display: flex; flex-direction: column; }
    main { max-width: 520px; margin: 0 auto; padding: 40px 16px; width: 100%; flex: 1; }

    .complete-card {
      background: #fff;
      border: 1px solid #e0e4e8;
      border-radius: 16px;
      padding: 32px 24px;
      text-align: center;
      box-shadow: 0 4px 16px rgba(0,0,0,0.06);
    }
    .success-icon {
      width: 72px; height: 72px;
      background: linear-gradient(135deg, #52c41a, #389e0d);
      border-radius: 50%;
      display: inline-flex; align-items: center; justify-content: center;
      color: #fff; font-size: 2.2rem; margin-bottom: 16px;
    }
    .title { font-size: 1.3rem; font-weight: 700; margin-bottom: 8px; }
    .message { font-size: 0.9rem; color: #6b7a8d; margin-bottom: 24px; line-height: 1.6; }

    .amount-box {
      background: #f5f7fa;
      border-radius: 12px;
      padding: 20px;
      margin-bottom: 16px;
    }
    .amount-row { display: flex; justify-content: space-between; align-items: center; font-size: 0.88rem; margin-bottom: 8px; }
    .amount-row:last-child { margin-bottom: 0; }
    .amount-row.total { border-top: 1px solid #e0e4e8; padding-top: 10px; margin-top: 10px; font-weight: 700; font-size: 1.05rem; color: #5567cc; }
    .amount-val { font-variant-numeric: tabular-nums; font-weight: 600; }

    .balance-box {
      background: linear-gradient(135deg, #5567cc, #3d4fa8);
      color: #fff;
      border-radius: 12px;
      padding: 16px 20px;
      margin-bottom: 20px;
    }
    .balance-label { font-size: 0.8rem; opacity: 0.9; }
    .balance-value { font-size: 1.6rem; font-weight: 800; font-variant-numeric: tabular-nums; }

    .btn { display: inline-block; padding: 12px 28px; border-radius: 8px; text-decoration: none; font-size: 0.9rem; font-weight: 600; }
    .btn-primary { background: #5567cc; color: #fff; }
    .btn-ghost { background: transparent; color: #5567cc; border: 1px solid #5567cc; }
    .btn + .btn { margin-left: 8px; }

    .error-box { background: #fff1f0; border: 1px solid #ffccc7; color: #cf1322; padding: 16px; border-radius: 8px; margin-bottom: 20px; font-size: 0.88rem; }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="container">
      <a href="/" class="site-logo">あそび</a>
    </div>
  </header>

  <main>
    <div class="complete-card">
      <?php if ($error): ?>
        <div class="error-box"><?= htmlspecialchars($error) ?></div>
      <?php elseif ($chargeInfo): ?>
        <div class="success-icon">✓</div>
        <div class="title">チャージが完了しました</div>
        <div class="message">あそびウォレットにACが追加されました。<br>反映には少し時間がかかる場合があります。</div>

        <div class="amount-box">
          <div class="amount-row">
            <span>お支払い金額</span>
            <span class="amount-val">¥<?= number_format($chargeInfo['amount']) ?></span>
          </div>
          <div class="amount-row">
            <span>基本AC</span>
            <span class="amount-val"><?= number_format($chargeInfo['ac_base']) ?> AC</span>
          </div>
          <?php if ($chargeInfo['ac_bonus'] > 0): ?>
          <div class="amount-row">
            <span style="color:#e67e22;">ボーナスAC</span>
            <span class="amount-val" style="color:#e67e22;">+ <?= number_format($chargeInfo['ac_bonus']) ?> AC</span>
          </div>
          <?php endif; ?>
          <div class="amount-row total">
            <span>付与AC合計</span>
            <span class="amount-val"><?= number_format($chargeInfo['ac_total']) ?> AC</span>
          </div>
        </div>
      <?php else: ?>
        <div class="title">決済情報が取得できません</div>
      <?php endif; ?>

      <div class="balance-box">
        <div class="balance-label">現在の残高</div>
        <div class="balance-value"><?= number_format($balance) ?> AC</div>
      </div>

      <a href="/wallet/" class="btn btn-primary">ウォレットへ</a>
      <a href="/wallet/charge.php" class="btn btn-ghost">続けてチャージ</a>
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
