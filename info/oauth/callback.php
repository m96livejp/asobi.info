<?php
/**
 * OAuth コールバックエンドポイント
 * GET /oauth/callback.php?provider=google&code=...&state=...
 */
require_once '/opt/asobi/shared/assets/php/auth.php';
require_once '/opt/asobi/shared/assets/php/oauth_config.php';

$provider = $_GET['provider'] ?? '';
$code     = $_GET['code']     ?? '';
$state    = $_GET['state']    ?? '';
$error    = $_GET['error']    ?? '';

$valid_providers = ['google', 'line', 'twitter'];

// エラー・バリデーション
if ($error) {
    _oauthRedirectError('認証がキャンセルされました');
}
if (!in_array($provider, $valid_providers, true) || !$code || !$state) {
    _oauthRedirectError('無効なリクエストです');
}

// state 検証
if (empty($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) {
    _oauthRedirectError('セッションが無効です。もう一度お試しください');
}

$mode         = $_SESSION['oauth_mode']     ?? 'login';
$redirectTo   = $_SESSION['oauth_redirect'] ?? 'https://asobi.info/';
$codeVerifier = $_SESSION['oauth_code_verifier'] ?? null;
$purpose      = $_SESSION['oauth_purpose']  ?? '';

// セッション用変数をクリア
unset($_SESSION['oauth_state'], $_SESSION['oauth_mode'], $_SESSION['oauth_redirect'], $_SESSION['oauth_code_verifier'], $_SESSION['oauth_purpose']);

// ── プロバイダーからユーザー情報取得 ─────────────────────────────
try {
    $info = _oauthFetchUserInfo($provider, $code, $codeVerifier, $purpose);
} catch (Exception $e) {
    _oauthRedirectError('認証に失敗しました: ' . $e->getMessage());
}

$db = asobiUsersDb();

// ── 年齢認証モード ─────────────────────────────────────────────────
if ($purpose === 'age_verify') {
    if (!asobiIsLoggedIn()) {
        _oauthRedirectError('ログインが必要です');
    }
    $birthYear = $info['birth_year'] ?? null;
    if ($birthYear === null) {
        $dest = $redirectTo !== 'https://asobi.info/' ? $redirectTo : 'https://asobi.info/profile.php';
        header('Location: ' . $dest . (strpos($dest, '?') !== false ? '&' : '?') . 'age_verify_error=no_birthday');
        exit;
    }
    $age = (int)date('Y') - (int)$birthYear;
    if ($age < 18) {
        header('Location: https://asobi.info/profile.php?age_verify_error=underage');
        exit;
    }
    $userId = $_SESSION['asobi_user_id'];
    $db->prepare("UPDATE users SET birth_year = ?, age_verified_at = datetime('now','localtime') WHERE id = ?")
       ->execute([$birthYear, $userId]);
    $_SESSION['asobi_age_verified'] = true;
    $dest = $redirectTo !== 'https://asobi.info/' ? $redirectTo : 'https://asobi.info/profile.php';
    header('Location: ' . $dest . (strpos($dest, '?') !== false ? '&' : '?') . 'age_verified=1');
    exit;
}

// ── リンクモード ─────────────────────────────────────────────────
if ($mode === 'link') {
    if (!asobiIsLoggedIn()) {
        _oauthRedirectError('ログインが必要です');
    }
    $userId = $_SESSION['asobi_user_id'];

    // 既存チェック
    $existing = $db->prepare("SELECT user_id FROM social_accounts WHERE provider = ? AND provider_id = ?");
    $existing->execute([$provider, $info['provider_id']]);
    $row = $existing->fetch();

    if ($row) {
        $msg = ($row['user_id'] == $userId) ? 'already_linked' : 'used_by_other';
        header('Location: https://asobi.info/profile.php?social_error=' . $msg);
        exit;
    }

    $db->prepare("INSERT INTO social_accounts (user_id, provider, provider_id, email, display_name, username) VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$userId, $provider, $info['provider_id'], $info['email'], $info['display_name'], $info['username']]);

    header('Location: https://asobi.info/profile.php?social_linked=1');
    exit;
}

// ── ログインモード ───────────────────────────────────────────────
// social_accounts を直接検索（JOINなし：孤立エントリも検出）
$stmtSa = $db->prepare("SELECT user_id FROM social_accounts WHERE provider = ? AND provider_id = ?");
$stmtSa->execute([$provider, $info['provider_id']]);
$saRow = $stmtSa->fetch();

if ($saRow) {
    $stmtU = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmtU->execute([$saRow['user_id']]);
    $user = $stmtU->fetch();
    if ($user) {
        // 既存ソーシャルアカウント → ログイン
        if ($user['status'] !== 'active') {
            _oauthRedirectError('このアカウントは停止されています');
        }
        asobiLoginFromUserRow($user);
        header('Location: ' . $redirectTo);
        exit;
    }
    // ユーザーが存在しない孤立エントリ → 削除して続行
    $db->prepare("DELETE FROM social_accounts WHERE provider = ? AND provider_id = ?")->execute([$provider, $info['provider_id']]);
}

// メールが一致する既存ユーザーがいれば自動リンク
if (!empty($info['email'])) {
    $stmt2 = $db->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
    $stmt2->execute([$info['email']]);
    $user = $stmt2->fetch();
    if ($user) {
        try {
            $db->prepare("INSERT INTO social_accounts (user_id, provider, provider_id, email, display_name, username) VALUES (?, ?, ?, ?, ?, ?)")
               ->execute([$user['id'], $provider, $info['provider_id'], $info['email'], $info['display_name'], $info['username']]);
        } catch (PDOException $e) {
            // 二重送信等で既に登録済みの場合は無視
        }
        asobiLoginFromUserRow($user);
        header('Location: ' . $redirectTo);
        exit;
    }
}

// 新規アカウント作成確認ページへ
$_SESSION['oauth_pending'] = [
    'provider'     => $provider,
    'provider_id'  => $info['provider_id'],
    'email'        => $info['email'],
    'display_name' => $info['display_name'],
    'username'     => $info['username'],
    'redirect_to'  => $redirectTo,
];
header('Location: https://asobi.info/oauth/confirm.php');
exit;


// ── ヘルパー関数 ─────────────────────────────────────────────────

function _oauthRedirectError(string $msg): never {
    header('Location: https://asobi.info/login.php?oauth_error=' . urlencode($msg));
    exit;
}

function _oauthGenerateUsername(string $provider, array $info): string {
    if (!empty($info['username'])) {
        $base = preg_replace('/[^a-zA-Z0-9_\-]/', '', $info['username']);
        if (strlen($base) >= 3) return substr($base, 0, 18);
    }
    return $provider . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
}

function _oauthFetchUserInfo(string $provider, string $code, ?string $codeVerifier, string $purpose = ''): array {
    if ($provider === 'google')  return _oauthGoogle($code, $purpose);
    if ($provider === 'line')    return _oauthLine($code);
    if ($provider === 'twitter') return _oauthTwitter($code, $codeVerifier ?? '');
    throw new Exception('Unknown provider');
}

function _oauthCurl(string $url, array $post = [], array $headers = [], ?string $basicUser = null, ?string $basicPass = null): array {
    $ch = curl_init($url);
    if ($post) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $hdrs = !empty($post) ? ['Content-Type: application/x-www-form-urlencoded'] : [];
    foreach ($headers as $h) $hdrs[] = $h;
    if ($hdrs) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
    if ($basicUser !== null) curl_setopt($ch, CURLOPT_USERPWD, $basicUser . ':' . $basicPass);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) throw new Exception('curl failed');
    $json = json_decode($body, true) ?? [];
    if ($code >= 400) throw new Exception($json['error_description'] ?? $json['error'] ?? "HTTP $code");
    return $json;
}

function _oauthGoogle(string $code, string $purpose = ''): array {
    $tokens = _oauthCurl('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => ASOBI_GOOGLE_CLIENT_ID,
        'client_secret' => ASOBI_GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => ASOBI_GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]);
    $userinfo = _oauthCurl('https://www.googleapis.com/oauth2/v2/userinfo', [], [
        'Authorization: Bearer ' . $tokens['access_token'],
    ]);

    // 年齢認証用：Google People API から生年月日を取得
    $birthYear = null;
    if ($purpose === 'age_verify') {
        try {
            $people = _oauthCurl(
                'https://people.googleapis.com/v1/people/me?personFields=birthdays',
                [],
                ['Authorization: Bearer ' . $tokens['access_token']]
            );
            foreach ($people['birthdays'] ?? [] as $bd) {
                if (!empty($bd['date']['year'])) {
                    $birthYear = (int)$bd['date']['year'];
                    break;
                }
            }
        } catch (Exception $e) {}
    }

    return [
        'provider_id'  => (string)$userinfo['id'],
        'email'        => $userinfo['email'] ?? null,
        'display_name' => $userinfo['name']  ?? null,
        'username'     => null,
        'birth_year'   => $birthYear,
    ];
}

function _oauthLine(string $code): array {
    $tokens = _oauthCurl('https://api.line.me/oauth2/v2.1/token', [
        'grant_type'   => 'authorization_code',
        'code'         => $code,
        'redirect_uri' => ASOBI_LINE_REDIRECT_URI,
        'client_id'    => ASOBI_LINE_CHANNEL_ID,
        'client_secret'=> ASOBI_LINE_CHANNEL_SECRET,
    ]);
    $profile = _oauthCurl('https://api.line.me/v2/profile', [], [
        'Authorization: Bearer ' . $tokens['access_token'],
    ]);
    $email = null;
    if (!empty($tokens['id_token'])) {
        try {
            $verify = _oauthCurl('https://api.line.me/oauth2/v2.1/verify', [
                'id_token'  => $tokens['id_token'],
                'client_id' => ASOBI_LINE_CHANNEL_ID,
            ]);
            $email = $verify['email'] ?? null;
        } catch (Exception $e) {}
    }
    return [
        'provider_id'  => (string)$profile['userId'],
        'email'        => $email,
        'display_name' => $profile['displayName'] ?? null,
        'username'     => null,
    ];
}

function _oauthTwitter(string $code, string $codeVerifier): array {
    $tokens = _oauthCurl('https://api.twitter.com/2/oauth2/token', [
        'code'          => $code,
        'grant_type'    => 'authorization_code',
        'redirect_uri'  => ASOBI_TWITTER_REDIRECT_URI,
        'code_verifier' => $codeVerifier,
    ], [], ASOBI_TWITTER_CLIENT_ID, ASOBI_TWITTER_CLIENT_SECRET);

    $userResp = _oauthCurl(
        'https://api.twitter.com/2/users/me?user.fields=id,name,username',
        [],
        ['Authorization: Bearer ' . $tokens['access_token']]
    );
    $data = $userResp['data'];
    return [
        'provider_id'  => (string)$data['id'],
        'email'        => null,
        'display_name' => $data['name']     ?? null,
        'username'     => $data['username'] ?? null,
    ];
}
