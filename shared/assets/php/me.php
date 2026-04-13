<?php
// 許可オリジン（asobi.info サブドメインのみ）
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://dbd.asobi.info', 'https://pkq.asobi.info', 'https://tbt.asobi.info', 'https://aic.asobi.info', 'https://image.asobi.info', 'https://asobi.info'];
if (in_array($origin, $allowed)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    require_once __DIR__ . '/auth.php';
} catch (Exception $e) {
    echo json_encode(['loggedIn' => false]);
    exit;
}

$loggedIn  = !empty($_SESSION['asobi_user_id']);
$name      = $_SESSION['asobi_user_name'] ?? $_SESSION['asobi_user_username'] ?? 'ユーザー';
$avatarUrl = $_SESSION['asobi_user_avatar'] ?? null;
$role      = $_SESSION['asobi_user_role'] ?? 'user';
session_write_close(); // セッションロックを早期解放

if ($loggedIn) {
    $initial = mb_substr($name, 0, 1);
    echo json_encode([
        'loggedIn'    => true,
        'userId'      => (int)$_SESSION['asobi_user_id'],
        'displayName' => $name,
        'initial'     => $initial,
        'avatarUrl'   => $avatarUrl,
        'role'        => $role,
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['loggedIn' => false]);
}
