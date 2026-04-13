<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
require_once '/opt/asobi/shared/assets/php/version.php';
require_once '/opt/asobi/shared/assets/php/wallet.php';
asobiRequireAdmin();

$db = asobiUsersDb();

// フラッシュメッセージ
session_start();
$msg = $_SESSION['wallet_msg'] ?? '';
unset($_SESSION['wallet_msg']);
session_write_close();

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_stripe_mode') {
        session_start();
        $mode = $_POST['stripe_mode'] ?? '';
        if (in_array($mode, ['test', 'live'], true)) {
            $adminUser = asobiGetCurrentUser();
            $db->prepare("UPDATE wallet_system SET stripe_mode = ?, updated_at = datetime('now','localtime'), updated_by = ? WHERE id = 1")
               ->execute([$mode, (int)($adminUser['id'] ?? 0)]);
            $_SESSION['wallet_msg'] = 'success:Stripeモードを「' . ($mode === 'live' ? '本番' : 'テスト') . '」に切り替えました';
        } else {
            $_SESSION['wallet_msg'] = 'error:不正なモード値';
        }
        header('Location: /admin/wallet.php'); exit;
    }
    if ($action === 'update_campaign') {
        session_start();
        $enabled = !empty($_POST['bonus_enabled']) ? 1 : 0;
        $label = trim($_POST['label'] ?? '期間限定ボーナス実施中');
        $db->prepare("UPDATE wallet_campaign SET bonus_enabled = ?, label = ? WHERE id = 1")
           ->execute([$enabled, $label]);
        $_SESSION['wallet_msg'] = 'success:キャンペーン設定を更新しました';
        header('Location: /admin/wallet.php'); exit;
    }
    if ($action === 'update_preset') {
        session_start();
        $id = (int)($_POST['id'] ?? 0);
        $jpy = (int)($_POST['charge_jpy'] ?? 0);
        $base = (int)($_POST['ac_base'] ?? 0);
        $bonus = (int)($_POST['ac_bonus'] ?? 0);
        $active = !empty($_POST['is_active']) ? 1 : 0;
        $sort = (int)($_POST['sort_order'] ?? 0);
        if ($id > 0 && $jpy > 0 && $base >= 0 && $bonus >= 0) {
            $db->prepare("UPDATE wallet_presets SET charge_jpy=?, ac_base=?, ac_bonus=?, is_active=?, sort_order=? WHERE id=?")
               ->execute([$jpy, $base, $bonus, $active, $sort, $id]);
            $_SESSION['wallet_msg'] = 'success:プリセットを更新しました';
        } else {
            $_SESSION['wallet_msg'] = 'error:入力値が不正です';
        }
        header('Location: /admin/wallet.php'); exit;
    }
    if ($action === 'add_preset') {
        session_start();
        $jpy = (int)($_POST['charge_jpy'] ?? 0);
        $base = (int)($_POST['ac_base'] ?? 0);
        $bonus = (int)($_POST['ac_bonus'] ?? 0);
        $sort = (int)($_POST['sort_order'] ?? 99);
        if ($jpy > 0 && $base >= 0 && $bonus >= 0) {
            try {
                $db->prepare("INSERT INTO wallet_presets (charge_jpy, ac_base, ac_bonus, sort_order) VALUES (?, ?, ?, ?)")
                   ->execute([$jpy, $base, $bonus, $sort]);
                $_SESSION['wallet_msg'] = 'success:プリセットを追加しました';
            } catch (Exception $e) {
                $_SESSION['wallet_msg'] = 'error:同じ金額のプリセットが既に存在します';
            }
        } else {
            $_SESSION['wallet_msg'] = 'error:入力値が不正です';
        }
        header('Location: /admin/wallet.php'); exit;
    }
    if ($action === 'delete_preset') {
        session_start();
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare("DELETE FROM wallet_presets WHERE id = ?")->execute([$id]);
            $_SESSION['wallet_msg'] = 'success:プリセットを削除しました';
        }
        header('Location: /admin/wallet.php'); exit;
    }
}

// データ取得
$systemRow = $db->query("SELECT * FROM wallet_system WHERE id = 1")->fetch();
$stripeMode = $systemRow['stripe_mode'] ?? 'test';
$campaign = asobiWalletCampaignGet();
$presets = $db->query("SELECT * FROM wallet_presets ORDER BY charge_jpy ASC")->fetchAll();

// 前プリセットのボーナス率と比較して警告判定
$prevBonusRate = null;
foreach ($presets as &$p) {
    $bonusRate = $p['charge_jpy'] > 0 ? ($p['ac_bonus'] / $p['charge_jpy'] * 100) : 0;
    $totalRate = $p['charge_jpy'] > 0 ? (($p['ac_base'] + $p['ac_bonus']) / $p['charge_jpy'] * 100) : 0;
    $baseRate = $p['charge_jpy'] > 0 ? ($p['ac_base'] / $p['charge_jpy'] * 100) : 0;
    $p['bonus_rate'] = $bonusRate;
    $p['total_rate'] = $totalRate;
    $p['base_rate'] = $baseRate;
    $p['warn_over'] = $bonusRate > 10;
    $p['warn_reverse'] = ($prevBonusRate !== null && $bonusRate < $prevBonusRate);
    $prevBonusRate = $bonusRate;
}
unset($p);

// 集計
$totalUsers = (int)$db->query("SELECT COUNT(*) FROM wallets WHERE balance > 0")->fetchColumn();
$totalBalance = (int)$db->query("SELECT COALESCE(SUM(balance), 0) FROM wallets")->fetchColumn();
$totalChargedJpy = (int)$db->query("SELECT COALESCE(SUM(total_charged_jpy), 0) FROM wallets")->fetchColumn();

// ユーザー一覧（残高またはチャージ履歴があるユーザー）
$userStmt = $db->query("
    SELECT w.user_id, w.balance, w.total_charged_jpy, w.updated_at,
           u.username, u.display_name, u.role
    FROM wallets w
    LEFT JOIN users u ON u.id = w.user_id
    WHERE w.balance > 0 OR w.total_charged_jpy > 0
    ORDER BY w.balance DESC, w.updated_at DESC
    LIMIT 100
");
$userRows = $userStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ウォレット管理 - asobi.info</title>
  <link rel="stylesheet" href="/assets/css/common.css?v=<?= assetVer('/assets/css/common.css') ?>">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; color: #1d2d3a; min-height: 100vh; display: flex; flex-direction: column; }

    .card { background: #fff; border: 1px solid #e0e4e8; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
    .card-title { font-size: 1rem; font-weight: 700; margin-bottom: 16px; color: #1d2d3a; }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 16px; }
    .stat-box { background: #f5f7fa; border-radius: 8px; padding: 12px 16px; }
    .stat-label { font-size: 0.75rem; color: #637080; margin-bottom: 4px; }
    .stat-value { font-size: 1.3rem; font-weight: 700; color: #1d2d3a; }

    .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 0.85rem; }
    .flash.success { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
    .flash.error { background: #ffebee; color: #c62828; border: 1px solid #ef9a9a; }

    table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    th { background: #f5f7fa; padding: 10px 8px; text-align: left; font-weight: 600; color: #637080; font-size: 0.75rem; border-bottom: 2px solid #e0e4e8; }
    td { padding: 8px; border-bottom: 1px solid #f0f2f5; vertical-align: middle; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .warn-over { background: #fff9c4; }
    .warn-reverse { background: #ffcdd2; }
    .warn-both { background: #ce93d8; }
    .warn-label { font-size: 0.7rem; font-weight: 600; padding: 2px 6px; border-radius: 3px; white-space: nowrap; display: inline-block; margin-right: 4px; }
    .warn-over-label { background: #f9a825; color: #fff; }
    .warn-reverse-label { background: #c62828; color: #fff; }

    input[type=text], input[type=number] { padding: 6px 10px; border: 1px solid #e0e4e8; border-radius: 6px; font-size: 0.85rem; width: 100%; }
    input.inline-num { width: 80px; text-align: right; }
    .btn { display: inline-block; padding: 6px 14px; border: none; border-radius: 6px; font-size: 0.8rem; font-weight: 600; cursor: pointer; text-decoration: none; }
    .btn-primary { background: #5567cc; color: #fff; }
    .btn-danger { background: #e74c3c; color: #fff; }
    .btn-small { padding: 4px 10px; font-size: 0.75rem; }
    .btn:hover { opacity: 0.85; }

    .toggle { position: relative; display: inline-block; width: 44px; height: 24px; }
    .toggle input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; inset: 0; background: #ccc; border-radius: 24px; transition: background 0.2s; cursor: pointer; }
    .slider::before { content:''; position: absolute; width: 18px; height: 18px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: transform 0.2s; }
    .toggle input:checked + .slider { background: #5567cc; }
    .toggle input:checked + .slider::before { transform: translateX(20px); }

    .inline-form { display: inline; }
  </style>
</head>
<body>
  <?php $adminActivePage = 'wallet'; require __DIR__ . '/_sidebar.php'; ?>

  <h1 style="font-size:1.3rem;margin-bottom:16px;">💰 ウォレット管理</h1>

  <?php if ($msg): list($type, $text) = explode(':', $msg, 2); ?>
    <div class="flash <?= htmlspecialchars($type) ?>"><?= htmlspecialchars($text) ?></div>
  <?php endif; ?>

  <!-- 統計 -->
  <div class="card">
    <div class="card-title">ウォレット統計</div>
    <div class="stats-grid">
      <div class="stat-box">
        <div class="stat-label">残高保有者</div>
        <div class="stat-value"><?= number_format($totalUsers) ?> 人</div>
      </div>
      <div class="stat-box">
        <div class="stat-label">総残高</div>
        <div class="stat-value"><?= number_format($totalBalance) ?> AC</div>
      </div>
      <div class="stat-box">
        <div class="stat-label">累計チャージ額</div>
        <div class="stat-value">¥<?= number_format($totalChargedJpy) ?></div>
      </div>
    </div>
  </div>

  <!-- Stripeモード -->
  <div class="card">
    <div class="card-title">
      <span>Stripeモード</span>
      <span style="font-size:0.75rem;font-weight:500;padding:3px 10px;border-radius:4px;<?= $stripeMode === 'live' ? 'background:#e8f5e9;color:#2e7d32;' : 'background:#fff3e0;color:#ef6c00;' ?>"><?= $stripeMode === 'live' ? '🟢 本番環境' : '🟠 テスト環境' ?></span>
    </div>
    <form method="POST" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
      <input type="hidden" name="action" value="update_stripe_mode">
      <label style="display:flex;align-items:center;gap:6px;font-size:0.9rem;cursor:pointer;">
        <input type="radio" name="stripe_mode" value="live" <?= $stripeMode === 'live' ? 'checked' : '' ?>>
        <strong style="color:#2e7d32;">本番環境</strong>
        <span style="color:#6b7a8d;font-size:0.8rem;">（実際の決済が発生）</span>
      </label>
      <label style="display:flex;align-items:center;gap:6px;font-size:0.9rem;cursor:pointer;">
        <input type="radio" name="stripe_mode" value="test" <?= $stripeMode === 'test' ? 'checked' : '' ?>>
        <strong style="color:#ef6c00;">テスト環境</strong>
        <span style="color:#6b7a8d;font-size:0.8rem;">（4242-4242-4242-4242で擬似決済）</span>
      </label>
      <button type="submit" class="btn btn-primary">モード切り替え</button>
    </form>
    <div style="font-size:0.72rem;color:#6b7a8d;margin-top:10px;line-height:1.5;">
      ⚠️ <strong>本番モード</strong>に切り替えると、全ユーザーのチャージで実際の課金が発生します。<br>
      <?php if ($systemRow['updated_at'] ?? null): ?>
        最終変更: <?= htmlspecialchars($systemRow['updated_at']) ?>
      <?php endif; ?>
    </div>

    <!-- テストカード情報 -->
    <details style="margin-top:12px;background:#fff3e0;border-radius:8px;padding:10px 14px;">
      <summary style="cursor:pointer;font-size:0.82rem;font-weight:600;color:#bf360c;">🔧 テスト環境で使えるカード情報</summary>
      <div style="margin-top:10px;font-size:0.78rem;color:#3a4a5a;line-height:1.7;">
        <p style="margin-bottom:8px;"><strong>Stripe公式テストカード番号:</strong></p>
        <table style="width:100%;font-size:0.75rem;margin-bottom:10px;">
          <thead>
            <tr><th style="text-align:left;padding:4px 8px;background:#fff;">カード番号</th><th style="text-align:left;padding:4px 8px;background:#fff;">動作</th></tr>
          </thead>
          <tbody>
            <tr><td style="padding:4px 8px;font-family:monospace;">4242 4242 4242 4242</td><td style="padding:4px 8px;">成功（VISA）</td></tr>
            <tr><td style="padding:4px 8px;font-family:monospace;">5555 5555 5555 4444</td><td style="padding:4px 8px;">成功（Mastercard）</td></tr>
            <tr><td style="padding:4px 8px;font-family:monospace;">3782 822463 10005</td><td style="padding:4px 8px;">成功（AMEX）</td></tr>
            <tr><td style="padding:4px 8px;font-family:monospace;">4000 0025 0000 3155</td><td style="padding:4px 8px;">3Dセキュア認証必須</td></tr>
            <tr><td style="padding:4px 8px;font-family:monospace;">4000 0000 0000 9995</td><td style="padding:4px 8px;">残高不足で失敗</td></tr>
            <tr><td style="padding:4px 8px;font-family:monospace;">4000 0000 0000 0002</td><td style="padding:4px 8px;">カード拒否で失敗</td></tr>
            <tr><td style="padding:4px 8px;font-family:monospace;">4000 0000 0000 0069</td><td style="padding:4px 8px;">有効期限切れで失敗</td></tr>
          </tbody>
        </table>
        <p style="margin-bottom:4px;"><strong>その他の入力値（任意）:</strong></p>
        <ul style="margin-left:20px;margin-bottom:8px;">
          <li><strong>有効期限</strong>: 未来の任意の日付（例: <code>12 / 34</code>）</li>
          <li><strong>CVC / セキュリティコード</strong>: 任意の3桁（例: <code>123</code>）※AMEXは4桁</li>
          <li><strong>カード名義</strong>: 任意の英字（例: <code>TEST USER</code>）</li>
          <li><strong>郵便番号</strong>: 任意の7桁（例: <code>1010001</code>）</li>
          <li><strong>メール</strong>: 任意のメールアドレス</li>
        </ul>
        <p style="font-size:0.72rem;color:#9ba8b5;">詳細: <a href="https://docs.stripe.com/testing" target="_blank" style="color:#5567cc;">https://docs.stripe.com/testing</a></p>
      </div>
    </details>
  </div>

  <!-- キャンペーン設定 -->
  <div class="card">
    <div class="card-title">ボーナスキャンペーン</div>
    <form method="POST" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
      <input type="hidden" name="action" value="update_campaign">
      <label style="display:flex;align-items:center;gap:8px;">
        <span style="font-size:0.85rem;font-weight:600;">ボーナス有効</span>
        <label class="toggle">
          <input type="checkbox" name="bonus_enabled" value="1" <?= $campaign['bonus_enabled'] ? 'checked' : '' ?>>
          <span class="slider"></span>
        </label>
      </label>
      <label style="display:flex;align-items:center;gap:8px;flex:1;min-width:200px;">
        <span style="font-size:0.85rem;font-weight:600;white-space:nowrap;">表示ラベル</span>
        <input type="text" name="label" value="<?= htmlspecialchars($campaign['label']) ?>" style="flex:1;">
      </label>
      <button type="submit" class="btn btn-primary">保存</button>
    </form>
  </div>

  <!-- プリセット一覧 -->
  <div class="card">
    <div class="card-title">チャージプリセット</div>
    <table>
      <thead>
        <tr>
          <th>並び順</th>
          <th class="num">金額</th>
          <th class="num">基本AC</th>
          <th class="num">ボーナスAC</th>
          <th class="num">合計AC</th>
          <th class="num">還元率</th>
          <th class="num">ボーナス率</th>
          <th>警告</th>
          <th>有効</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($presets as $p):
          $rowClass = '';
          if ($p['warn_over'] && $p['warn_reverse']) $rowClass = 'warn-both';
          elseif ($p['warn_over']) $rowClass = 'warn-over';
          elseif ($p['warn_reverse']) $rowClass = 'warn-reverse';
        ?>
          <tr class="<?= $rowClass ?>">
            <form method="POST" class="inline-form">
              <input type="hidden" name="action" value="update_preset">
              <input type="hidden" name="id" value="<?= $p['id'] ?>">
              <td><input type="number" name="sort_order" value="<?= $p['sort_order'] ?>" class="inline-num" style="width:60px;"></td>
              <td class="num">¥<input type="number" name="charge_jpy" value="<?= $p['charge_jpy'] ?>" class="inline-num"></td>
              <td class="num"><input type="number" name="ac_base" value="<?= $p['ac_base'] ?>" class="inline-num"></td>
              <td class="num"><input type="number" name="ac_bonus" value="<?= $p['ac_bonus'] ?>" class="inline-num"></td>
              <td class="num"><strong><?= number_format($p['ac_base'] + $p['ac_bonus']) ?></strong></td>
              <td class="num"><?= number_format($p['total_rate'], 1) ?>%</td>
              <td class="num"><?= number_format($p['bonus_rate'], 1) ?>%</td>
              <td>
                <?php if ($p['warn_over']): ?><span class="warn-label warn-over-label">10%超</span><?php endif; ?>
                <?php if ($p['warn_reverse']): ?><span class="warn-label warn-reverse-label">逆転</span><?php endif; ?>
              </td>
              <td>
                <label class="toggle">
                  <input type="checkbox" name="is_active" value="1" <?= $p['is_active'] ? 'checked' : '' ?> onchange="this.form.submit()">
                  <span class="slider"></span>
                </label>
              </td>
              <td style="white-space:nowrap;">
                <button type="submit" class="btn btn-primary btn-small">保存</button>
            </form>
            <form method="POST" class="inline-form" onsubmit="return openDeleteConfirm(this);">
              <input type="hidden" name="action" value="delete_preset">
              <input type="hidden" name="id" value="<?= $p['id'] ?>">
              <button type="submit" class="btn btn-danger btn-small">削除</button>
            </form>
              </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- 新規追加 -->
    <form method="POST" style="display:flex;gap:8px;align-items:center;margin-top:16px;flex-wrap:wrap;">
      <input type="hidden" name="action" value="add_preset">
      <label style="font-size:0.85rem;font-weight:600;">新規追加:</label>
      <label>金額 <input type="number" name="charge_jpy" placeholder="金額" class="inline-num" required></label>
      <label>基本AC <input type="number" name="ac_base" placeholder="基本" class="inline-num" required></label>
      <label>ボーナスAC <input type="number" name="ac_bonus" placeholder="ボーナス" value="0" class="inline-num"></label>
      <label>並び <input type="number" name="sort_order" value="99" class="inline-num" style="width:60px;"></label>
      <button type="submit" class="btn btn-primary">追加</button>
    </form>

    <div style="margin-top:12px;padding:12px;background:#f5f7fa;border-radius:8px;font-size:0.78rem;color:#637080;line-height:1.6;">
      <strong>警告の意味:</strong><br>
      🟡 <strong>10%超</strong>: ボーナス率（ボーナスAC / 金額）が10%を超えています<br>
      🔴 <strong>逆転</strong>: 前の金額よりボーナス率が低くなっています（高額ほどお得にならない）<br>
      🟣 <strong>両方</strong>: 上記2つが同時に発生
    </div>
  </div>

  <!-- ユーザー別残高一覧 -->
  <div class="card">
    <div class="card-title">ユーザー別残高（上位100件）</div>
    <form method="GET" action="/admin/wallet-user-search.php" style="display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap;">
      <label style="font-size:0.85rem;font-weight:600;">ユーザー検索:</label>
      <input type="text" name="q" placeholder="ユーザー名・表示名・メール・ID" style="flex:1;min-width:240px;" required>
      <button type="submit" class="btn btn-primary">検索</button>
      <span style="font-size:0.75rem;color:#9ba8b5;">（課金履歴なしのユーザーに付与したい場合）</span>
    </form>
    <?php if (empty($userRows)): ?>
      <div style="padding:20px;text-align:center;color:#9ba8b5;font-size:0.85rem;">残高保有ユーザーなし</div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>ユーザー</th>
            <th class="num">残高(AC)</th>
            <th class="num">累計チャージ(円)</th>
            <th>最終更新</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($userRows as $u): ?>
            <tr>
              <td><?= (int)$u['user_id'] ?></td>
              <td>
                <?= htmlspecialchars($u['display_name'] ?? $u['username'] ?? '') ?>
                <?php if ($u['role'] === 'admin'): ?><span style="font-size:0.68rem;background:#5567cc;color:#fff;padding:1px 6px;border-radius:3px;margin-left:4px;">admin</span><?php endif; ?>
              </td>
              <td class="num"><strong><?= number_format((int)$u['balance']) ?></strong></td>
              <td class="num">¥<?= number_format((int)$u['total_charged_jpy']) ?></td>
              <td style="font-size:0.72rem;color:#9ba8b5;"><?= htmlspecialchars($u['updated_at']) ?></td>
              <td><a href="/admin/wallet-user.php?user_id=<?= (int)$u['user_id'] ?>" class="btn btn-primary btn-small">履歴/調整</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  </main>
  </div><!-- /.admin-layout -->

  <!-- 削除確認ダイアログ -->
  <div id="del-confirm" style="display:none;position:fixed;inset:0;z-index:200;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;max-width:380px;width:90%;padding:20px;">
      <h3 style="margin:0 0 10px;font-size:1rem;color:#e74c3c;">プリセット削除</h3>
      <p style="font-size:0.88rem;color:#1d2d3a;margin-bottom:16px;">このプリセットを削除しますか？</p>
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" class="btn" style="background:#e0e4e8;color:#1d2d3a;" onclick="closeDelConfirm()">キャンセル</button>
        <button type="button" class="btn btn-danger" onclick="confirmDelSubmit()">削除する</button>
      </div>
    </div>
  </div>

  <script>
    let _delForm = null;
    function openDeleteConfirm(form) {
      _delForm = form;
      document.getElementById('del-confirm').style.display = 'flex';
      return false;
    }
    function closeDelConfirm() {
      document.getElementById('del-confirm').style.display = 'none';
      _delForm = null;
    }
    function confirmDelSubmit() {
      if (_delForm) _delForm.submit();
    }
  </script>
  <script src="/assets/js/common.js?v=<?= assetVer('/assets/js/common.js') ?>"></script>
</body>
</html>
