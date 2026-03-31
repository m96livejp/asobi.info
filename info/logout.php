<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
asobiLogout();

// リダイレクト先を引き継ぐ（asobi.infoドメインのみ許可）
$redirect = '';
if (!empty($_GET['redirect'])) {
    $r = $_GET['redirect'];
    if (preg_match('/^https?:\/\/([a-z0-9\-]+\.)?asobi\.info(\/|$)/i', $r)) {
        $redirect = $r;
    }
}
$loginUrl = 'https://asobi.info/login.php';
if ($redirect) {
    $loginUrl .= '?redirect=' . urlencode($redirect);
}
header('Location: ' . $loginUrl);
exit;
