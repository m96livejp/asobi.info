<?php
require_once '/home/m96/asobi.info/public_html/assets/php/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (asobiIsLoggedIn()) {
    $user = asobiGetCurrentUser();
    echo json_encode([
        'logged_in'    => true,
        'display_name' => $user['display_name'] ?: $user['username'],
        'avatar_url'   => $user['avatar_url'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['logged_in' => false]);
}
