<?php
/**
 * OAuth 開始エンドポイント
 * GET /oauth/start.php?provider=google&mode=login&redirect=...
 * GET /oauth/start.php?provider=google&mode=link
 */
require_once '/home/m96/asobi.info/public_html/assets/php/auth.php';

$provider = $_GET['provider'] ?? '';
$mode     = $_GET['mode'] ?? 'login';
$redirect = $_GET['redirect'] ?? '';

$valid_providers = ['google', 'line', 'twitter'];
if (!in_array($provider, $valid_providers, true)) {
    http_response_code(400);
    exit('Invalid provider');
}

if (!in_array($mode, ['login', 'link'], true)) {
    $mode = 'login';
}

// link モードはログイン必須
if ($mode === 'link') {
    asobiRequireLogin();
    $redirect = 'https://asobi.info/profile.php';
} else {
    // redirect の検証
    if (!empty($redirect) && !preg_match('/^https?:\/\/([a-z0-9\-]+\.)?asobi\.info(\/|$)/i', $redirect)) {
        $redirect = '';
    }
}

$url = asobiOAuthGetUrl($provider, $mode, $redirect);
header('Location: ' . $url);
exit;
