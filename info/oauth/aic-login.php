<?php
/**
 * aic.asobi.info クロスサイトログイン
 * asobi.info のセッションを確認し、短期JWTを発行して aic へリダイレクトする
 */
require_once '/opt/asobi/shared/assets/php/auth.php';
require_once '/opt/asobi/shared/assets/php/aic_config.php';

// 未ログインの場合はログイン画面へ（callback パラメーターを保持して戻る）
if (!asobiIsLoggedIn()) {
    $self = 'https://asobi.info/oauth/aic-login.php?' . http_build_query($_GET);
    header('Location: https://asobi.info/login.php?redirect=' . urlencode($self));
    exit;
}

// callback URL をホワイトリストで検証
$callback = $_GET['callback'] ?? '';
if ($callback !== AIC_CALLBACK_URL) {
    http_response_code(400);
    echo 'Invalid callback URL';
    exit;
}

// 短期JWT発行 → aic callback へリダイレクト（state があれば転送）
$token = asobiIssueAicToken();
$redirect = AIC_CALLBACK_URL . '?token=' . urlencode($token);
if (!empty($_GET['state'])) {
    $redirect .= '&state=' . urlencode($_GET['state']);
}
header('Location: ' . $redirect);
exit;
