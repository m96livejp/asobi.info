<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
require_once '/opt/asobi/shared/assets/php/version.php';
require_once '/opt/asobi/shared/assets/php/wallet.php';
asobiRequireLogin();

$user = asobiGetCurrentUser();
$userId = (int)$user['id'];
$isAdmin = ($user['role'] ?? '') === 'admin';

session_start();
$adminTestMode = $isAdmin && !empty($_SESSION['stripe_admin_test_mode']);
session_write_close();

$balance = asobiWalletBalance($userId);
$campaign = asobiWalletCampaignGet();
$presets = asobiWalletPresets();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ACチャージ - あそびウォレット</title>
  <link rel="stylesheet" href="/assets/css/common.css?v=<?= assetVer('/assets/css/common.css') ?>">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; color: #1d2d3a; min-height: 100vh; display: flex; flex-direction: column; }
    main { max-width: 720px; margin: 0 auto; padding: 32px 16px; width: 100%; flex: 1; }
    h1 { font-size: 1.3rem; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }

    .current-balance { background: #f5f7fa; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 0.88rem; display: flex; justify-content: space-between; align-items: center; }
    .current-balance-value { font-weight: 700; font-size: 1.05rem; font-variant-numeric: tabular-nums; }

    .campaign-banner {
      background: linear-gradient(135deg, #ff9f43, #ee5a6f);
      color: #fff;
      border-radius: 12px;
      padding: 14px 18px;
      margin-bottom: 20px;
      display: flex; align-items: center; gap: 12px;
      font-weight: 600; font-size: 0.9rem;
      box-shadow: 0 3px 12px rgba(238,90,111,0.2);
    }
    .campaign-banner .icon { font-size: 1.4rem; }

    .preset-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; }
    .preset-btn {
      display: block;
      width: 100%;
      padding: 14px 16px;
      background: #fff;
      border: 2px solid #e0e4e8;
      border-radius: 12px;
      text-align: center;
      cursor: pointer;
      transition: border-color 0.15s, transform 0.1s, box-shadow 0.15s;
      font-family: inherit;
      white-space: nowrap;
    }
    .preset-btn:hover {
      border-color: #5567cc;
      box-shadow: 0 4px 12px rgba(85,103,204,0.12);
    }
    .preset-btn:active { transform: scale(0.98); }
    .preset-main {
      display: flex;
      align-items: baseline;
      justify-content: center;
      gap: 4px;
      font-variant-numeric: tabular-nums;
    }
    .preset-jpy {
      font-size: 0.85rem;
      font-weight: 600;
      color: #6b7a8d;
    }
    .preset-main .arrow {
      color: #9ba8b5;
      font-size: 0.82rem;
      margin: 0 2px;
    }
    .preset-main .ac {
      font-size: 1.4rem;
      font-weight: 800;
      color: #5567cc;
    }
    .preset-main .ac-unit {
      font-size: 0.8rem;
      color: #5567cc;
      font-weight: 600;
      margin-left: 2px;
    }
    .preset-sub {
      font-size: 0.72rem;
      color: #6b7a8d;
      margin-top: 4px;
      font-variant-numeric: tabular-nums;
    }
    .preset-sub .bonus {
      color: #e67e22;
      font-weight: 600;
    }

    .notice { background: #fff9e6; border: 1px solid #ffd666; border-radius: 8px; padding: 12px 14px; font-size: 0.8rem; color: #664d03; margin-top: 20px; line-height: 1.6; }

    .back-link { display: inline-block; margin-top: 20px; font-size: 0.85rem; color: #5567cc; text-decoration: none; }
    .back-link:hover { text-decoration: underline; }

    .coming-soon {
      text-align: center;
      padding: 12px;
      background: #e3f2fd;
      border-radius: 8px;
      font-size: 0.85rem;
      color: #1565c0;
      margin-bottom: 20px;
    }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="container">
      <a href="/" class="site-logo">あそび</a>
    </div>
  </header>

  <main>
    <h1><img src="/assets/images/asobi-card.svg" alt="" style="width:28px;height:28px;vertical-align:-6px;margin-right:6px;">ACチャージ</h1>

    <?php if ($adminTestMode): ?>
      <div style="background:#fff3e0;border:2px dashed #ef6c00;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:0.82rem;color:#bf360c;">
        ⚠️ <strong>テストモード有効</strong> — テストカード 4242 4242 4242 4242 で決済できます
      </div>
    <?php endif; ?>

    <div class="current-balance">
      <span>現在の残高</span>
      <span class="current-balance-value"><?= number_format($balance) ?> AC</span>
    </div>

    <?php if (($_GET['canceled'] ?? '') === '1'): ?>
      <div class="notice" style="background:#fff3e0;border-color:#ffcc80;color:#bf360c;">
        決済がキャンセルされました。
      </div>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
      <div class="notice" style="background:#ffebee;border-color:#ef9a9a;color:#c62828;">
        エラー: <?= htmlspecialchars($_GET['error']) ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($campaign['bonus_enabled'])): ?>
      <div class="campaign-banner">
        <span class="icon">🎁</span>
        <span><?= htmlspecialchars($campaign['label']) ?></span>
      </div>
    <?php endif; ?>

    <div class="preset-list">
      <?php foreach ($presets as $p): ?>
        <form method="POST" action="/wallet/checkout.php" style="display:contents;">
          <input type="hidden" name="preset_id" value="<?= $p['id'] ?>">
          <button type="submit" class="preset-btn">
            <div class="preset-main">
              <span class="preset-jpy">¥<?= number_format($p['charge_jpy']) ?></span>
              <span class="arrow">→</span>
              <span class="ac"><?= number_format($p['ac_total']) ?></span>
              <span class="ac-unit">AC</span>
            </div>
            <?php if ($p['ac_bonus'] > 0): ?>
              <div class="preset-sub">
                (内訳 <?= number_format($p['ac_base']) ?> <span class="bonus">+ ボーナス <?= number_format($p['ac_bonus']) ?></span>)
              </div>
            <?php endif; ?>
          </button>
        </form>
      <?php endforeach; ?>
    </div>

    <div class="notice">
      <strong>📌 ご注意</strong><br>
      • ACは6ヶ月の有効期限があります<br>
      • AC は各コンテンツ通貨へ一方通行で交換できます（ACには戻せません）<br>
      • AC・各コンテンツ通貨・ポイントは現金への払い戻しはできません
    </div>

    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:20px;font-size:0.85rem;">
      <a href="/wallet/" class="back-link" style="margin-top:0;">← ウォレットに戻る</a>
      <a href="/about/currency.html" class="back-link" style="margin-top:0;">ACについて</a>
      <a href="/terms.html" class="back-link" style="margin-top:0;">利用規約</a>
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
