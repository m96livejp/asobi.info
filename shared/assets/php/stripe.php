<?php
/**
 * Stripe連携ラッパー
 *
 * 使用方法:
 *   require_once '/opt/asobi/shared/assets/php/stripe.php';
 *
 * 主要関数:
 *   stripeInit()                                       初期化（SDK読み込み+APIキー設定）
 *   stripeCreateCheckout($userId, $presetId, $origin) Checkout Session作成
 *   stripeVerifyWebhook($payload, $sigHeader)         Webhook署名検証
 */

// 設定ファイル読込（DocumentRoot外）
require_once '/opt/asobi/data/stripe_config.php';
// Composer autoload
require_once '/opt/asobi/shared/vendor/autoload.php';
// ウォレットAPI
require_once __DIR__ . '/wallet.php';

/**
 * 現在のStripeモード取得（優先順位: 管理者セッション上書き > DB設定 > stripe_config定数）
 */
function stripeCurrentMode(): string {
    // 管理者のみセッションでテストモード上書き可能
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['stripe_admin_test_mode'])) {
        return 'test';
    }
    // DB設定優先
    static $dbMode = null;
    if ($dbMode === null) {
        try {
            $db = asobiUsersDb();
            $row = $db->query("SELECT stripe_mode FROM wallet_system WHERE id = 1")->fetch();
            $dbMode = ($row && in_array($row['stripe_mode'], ['test', 'live'], true)) ? $row['stripe_mode'] : '';
        } catch (Exception $e) {
            $dbMode = '';
        }
    }
    return $dbMode ?: STRIPE_MODE;
}

/**
 * モードに応じたキー取得（stripe_config.php の関数を上書き）
 */
function stripeCurrentSecretKey(): string {
    return stripeCurrentMode() === 'live' ? STRIPE_LIVE_SECRET_KEY : STRIPE_TEST_SECRET_KEY;
}
function stripeCurrentPublicKey(): string {
    return stripeCurrentMode() === 'live' ? STRIPE_LIVE_PUBLIC_KEY : STRIPE_TEST_PUBLIC_KEY;
}
function stripeCurrentWebhookSecret(): string {
    return stripeCurrentMode() === 'live' ? STRIPE_LIVE_WEBHOOK_SECRET : STRIPE_TEST_WEBHOOK_SECRET;
}

/**
 * Stripe初期化（APIキーセット）
 */
function stripeInit(): void {
    \Stripe\Stripe::setApiKey(stripeCurrentSecretKey());
    \Stripe\Stripe::setApiVersion('2024-12-18.acacia');
}

/**
 * Checkout Session作成
 *
 * @param int $userId     ユーザーID
 * @param int $presetId   wallet_presets.id
 * @param string $origin  リダイレクト元URL（例: https://asobi.info）
 * @return \Stripe\Checkout\Session
 * @throws Exception
 */
function stripeCreateCheckout(int $userId, int $presetId, string $origin): \Stripe\Checkout\Session {
    stripeInit();

    // プリセット取得
    $db = asobiUsersDb();
    $stmt = $db->prepare("SELECT * FROM wallet_presets WHERE id = ? AND is_active = 1");
    $stmt->execute([$presetId]);
    $preset = $stmt->fetch();
    if (!$preset) {
        throw new Exception('プリセットが見つかりません');
    }

    // キャンペーン状態を取得
    $campaign = asobiWalletCampaignGet();
    $bonusOn = !empty($campaign['bonus_enabled']);
    $acBase = (int)$preset['ac_base'];
    $acBonus = $bonusOn ? (int)$preset['ac_bonus'] : 0;
    $acTotal = $acBase + $acBonus;
    $jpy = (int)$preset['charge_jpy'];

    // Checkout Session作成
    $session = \Stripe\Checkout\Session::create([
        'mode' => 'payment',
        'payment_method_types' => ['card'],
        'line_items' => [[
            'price_data' => [
                'currency' => 'jpy',
                'unit_amount' => $jpy,
                'product_data' => [
                    'name' => 'あそびウォレット AC チャージ（' . number_format($acTotal) . 'AC）',
                    'description' => $acBonus > 0
                        ? "基本 {$acBase} AC + ボーナス {$acBonus} AC"
                        : "{$acBase} AC",
                ],
            ],
            'quantity' => 1,
        ]],
        'success_url' => $origin . '/wallet/charge-complete.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'  => $origin . '/wallet/charge.php?canceled=1',
        'metadata' => [
            'user_id'   => (string)$userId,
            'preset_id' => (string)$presetId,
            'ac_base'   => (string)$acBase,
            'ac_bonus'  => (string)$acBonus,
            'ac_total'  => (string)$acTotal,
        ],
    ]);

    return $session;
}

/**
 * Webhook署名検証
 *
 * @throws \Stripe\Exception\SignatureVerificationException
 */
function stripeVerifyWebhook(string $payload, string $sigHeader): \Stripe\Event {
    // Webhookは送信者（Stripe側）のモードによって署名が変わる
    // 両方のシークレットで検証を試みる（test→liveの順）
    \Stripe\Stripe::setApiVersion('2024-12-18.acacia');
    $secrets = [STRIPE_TEST_WEBHOOK_SECRET, STRIPE_LIVE_WEBHOOK_SECRET];
    $lastError = null;
    foreach ($secrets as $secret) {
        if (!$secret) continue;
        try {
            return \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            $lastError = $e;
            continue;
        }
    }
    throw $lastError ?: new Exception('No webhook secret configured');
}

/**
 * Checkout Session取得
 */
function stripeGetCheckoutSession(string $sessionId): \Stripe\Checkout\Session {
    stripeInit();
    return \Stripe\Checkout\Session::retrieve($sessionId);
}
