<?php
/**
 * Stripe Webhook受信エンドポイント
 * URL: https://asobi.info/wallet/webhook.php
 *
 * Stripeから決済完了通知を受信し、ウォレットにAC付与する。
 */
require_once '/opt/asobi/shared/assets/php/stripe.php';

// ログ
function webhookLog(string $level, string $msg): void {
    error_log('[stripe-webhook] ' . $level . ': ' . $msg);
}

$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Webhook署名シークレット未設定時はログのみ（セキュリティのため拒否）
if (STRIPE_TEST_WEBHOOK_SECRET === '' && STRIPE_LIVE_WEBHOOK_SECRET === '') {
    webhookLog('ERROR', 'Webhook secret not configured');
    http_response_code(500);
    echo 'Webhook secret not configured';
    exit;
}

try {
    $event = stripeVerifyWebhook($payload, $sigHeader);
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    webhookLog('ERROR', 'Signature verification failed: ' . $e->getMessage());
    http_response_code(400);
    echo 'Invalid signature';
    exit;
} catch (Exception $e) {
    webhookLog('ERROR', 'Webhook error: ' . $e->getMessage());
    http_response_code(400);
    echo 'Bad request';
    exit;
}

webhookLog('INFO', 'Event received: ' . $event->type . ' (id=' . $event->id . ')');

// checkout.session.completed のみ処理
if ($event->type === 'checkout.session.completed') {
    $session = $event->data->object;
    $sessionId = $session->id;
    $paymentStatus = $session->payment_status;
    $amountTotal = $session->amount_total;
    $metadata = $session->metadata;

    if ($paymentStatus !== 'paid') {
        webhookLog('WARN', "Session {$sessionId} not paid (status={$paymentStatus})");
        http_response_code(200);
        echo 'ok';
        exit;
    }

    $userId = (int)($metadata['user_id'] ?? 0);
    $presetId = (int)($metadata['preset_id'] ?? 0);
    $acBase = (int)($metadata['ac_base'] ?? 0);
    $acBonus = (int)($metadata['ac_bonus'] ?? 0);
    $jpy = (int)$amountTotal;

    if ($userId <= 0 || $jpy <= 0 || $acBase <= 0) {
        webhookLog('ERROR', "Invalid metadata in session {$sessionId}: user={$userId}, jpy={$jpy}, ac_base={$acBase}");
        http_response_code(400);
        echo 'invalid metadata';
        exit;
    }

    // 重複処理防止: 同じstripe_payment_idのロットが既に存在する場合はスキップ
    $db = asobiUsersDb();
    $checkStmt = $db->prepare("SELECT id FROM wallet_charge_lots WHERE stripe_payment_id = ?");
    $checkStmt->execute([$sessionId]);
    if ($checkStmt->fetch()) {
        webhookLog('INFO', "Session {$sessionId} already processed, skipping");
        http_response_code(200);
        echo 'already processed';
        exit;
    }

    // テストモード判定（Stripeのlivemodeフラグから）
    $isTest = isset($event->livemode) ? !$event->livemode : false;

    // AC付与
    try {
        $lotId = asobiWalletCharge($userId, $jpy, $acBase, $acBonus, $sessionId, $isTest);
        webhookLog('INFO', "Charged: user={$userId}, jpy={$jpy}, ac=" . ($acBase + $acBonus) . ", lot_id={$lotId}" . ($isTest ? ' [TEST]' : ''));
    } catch (Exception $e) {
        webhookLog('ERROR', 'Charge failed: ' . $e->getMessage());
        http_response_code(500);
        echo 'charge failed';
        exit;
    }
}

http_response_code(200);
echo 'ok';
