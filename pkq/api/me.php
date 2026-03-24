<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
$user = asobiGetCurrentUser();
session_write_close(); // セッションロックを早期解放

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($user) {
    echo json_encode([
        'logged_in'    => true,
        'id'           => (int)$user['id'],
        'username'     => $user['username'],
        'display_name' => $user['display_name'] ?: $user['username'],
        'avatar_url'   => $user['avatar_url'] ?? null,
        'role'         => $user['role'] ?? 'user',
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['logged_in' => false]);
}
