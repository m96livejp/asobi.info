<?php
/**
 * チャージ開始 - Stripe Checkout Sessionを作成してリダイレクト
 *
 * POST: preset_id → Stripe決済画面へリダイレクト
 */
require_once '/opt/asobi/shared/assets/php/auth.php';
require_once '/opt/asobi/shared/assets/php/stripe.php';
asobiRequireLogin();

$user = asobiGetCurrentUser();
$userId = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /wallet/charge.php');
    exit;
}

$presetId = (int)($_POST['preset_id'] ?? 0);
if ($presetId <= 0) {
    header('Location: /wallet/charge.php?error=invalid');
    exit;
}

try {
    $origin = 'https://' . $_SERVER['HTTP_HOST'];
    // asobi.info以外から来た場合も返り先はasobi.info
    if (strpos($origin, 'asobi.info') === false) {
        $origin = 'https://asobi.info';
    }
    $session = stripeCreateCheckout($userId, $presetId, $origin);
    header('Location: ' . $session->url);
    exit;
} catch (Exception $e) {
    error_log('[stripe-checkout] ' . $e->getMessage());
    header('Location: /wallet/charge.php?error=' . urlencode($e->getMessage()));
    exit;
}
