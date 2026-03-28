<?php
/**
 * 共通認証モジュール - asobi.info 全サブドメイン共有
 *
 * 使用方法:
 *   require_once '/opt/asobi/shared/assets/php/auth.php';
 */

require_once __DIR__ . '/users_db.php';

define('ASOBI_AUTH_COOKIE_DOMAIN', '.asobi.info');
define('ASOBI_AUTH_SESSION_NAME', 'ASOBI_SESSION');
define('ASOBI_LOGIN_URL', 'https://asobi.info/login.php');

if (session_status() === PHP_SESSION_NONE) {
    // セッション有効期限はDB設定値を優先（デフォルト: 2592000秒 = 30日）
    $sessionLifetime = '2592000';
    try {
        $tmpDb = new PDO('sqlite:' . ASOBI_USERS_DB_PATH);
        $tmpDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $tmpDb->prepare("SELECT value FROM site_settings WHERE key='session_cookie_lifetime'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $sessionLifetime = $row['value'];
    } catch (Exception $e) {}
    ini_set('session.cookie_domain',   ASOBI_AUTH_COOKIE_DOMAIN);
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_lifetime', $sessionLifetime);
    ini_set('session.gc_maxlifetime',  $sessionLifetime);
    session_name(ASOBI_AUTH_SESSION_NAME);
    session_start();
}

// ─────────────────── 状態確認 ───────────────────

function asobiIsLoggedIn(): bool {
    return !empty($_SESSION['asobi_user_id']);
}

function asobiIsAdmin(): bool {
    return isset($_SESSION['asobi_user_role']) && $_SESSION['asobi_user_role'] === 'admin';
}

/** セッションからユーザー情報を返す（未ログイン時 null） */
function asobiGetCurrentUser(): ?array {
    if (!asobiIsLoggedIn()) return null;
    return [
        'id'           => $_SESSION['asobi_user_id'],
        'username'     => $_SESSION['asobi_user_username'] ?? '',
        'display_name' => $_SESSION['asobi_user_name'] ?? '',
        'role'         => $_SESSION['asobi_user_role'] ?? 'user',
        'avatar_url'   => $_SESSION['asobi_user_avatar'] ?? null,
    ];
}

// ─────────────────── アクセス制御 ───────────────────

/** 未ログイン時にログインページへリダイレクト（画面用） */
function asobiRequireLogin(string $redirectTo = ''): void {
    if (!asobiIsLoggedIn()) {
        if (php_sapi_name() !== 'cli' && !headers_sent()) {
            $back = $redirectTo ?: (
                (isset($_SERVER['HTTPS']) ? 'https' : 'http')
                . '://' . ($_SERVER['HTTP_HOST'] ?? 'asobi.info')
                . ($_SERVER['REQUEST_URI'] ?? '/')
            );
            header('Location: ' . ASOBI_LOGIN_URL . '?redirect=' . urlencode($back));
            exit;
        }
    }
}

/** 非管理者を弾く（画面用） */
function asobiRequireAdmin(string $redirectTo = ''): void {
    asobiRequireLogin($redirectTo);
    if (!asobiIsAdmin()) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>403</title></head>'
           . '<body style="font-family:sans-serif;background:#0a0a0f;color:#e8e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">'
           . '<div style="text-align:center"><h1 style="color:#e74c3c">403 Forbidden</h1><p>管理者権限が必要です。</p>'
           . '<a href="/" style="color:#e74c3c">トップへ戻る</a></div></body></html>';
        exit;
    }
}

/** 未ログイン時に 401 JSON を返す（API用） */
function asobiRequireLoginApi(): void {
    if (!asobiIsLoggedIn()) {
        _asobiJsonError(401, 'Unauthorized');
    }
}

/** 非管理者に 403 JSON を返す（API用） */
function asobiRequireAdminApi(): void {
    if (!asobiIsLoggedIn()) {
        _asobiJsonError(401, 'Unauthorized');
    }
    if (!asobiIsAdmin()) {
        _asobiJsonError(403, 'Forbidden');
    }
}

// ─────────────────── 年齢認証 ───────────────────

function asobiIsAgeVerified(): bool {
    if (!asobiIsLoggedIn()) return false;
    if (!empty($_SESSION['asobi_age_verified'])) return true;
    // DBを確認してセッションにキャッシュ
    try {
        $db = asobiUsersDb();
        $stmt = $db->prepare("SELECT age_verified_at FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['asobi_user_id']]);
        $row = $stmt->fetch();
        if ($row && !empty($row['age_verified_at'])) {
            $_SESSION['asobi_age_verified'] = true;
            return true;
        }
    } catch (Exception $e) {}
    return false;
}

/** 年齢未認証時にプロフィールページへリダイレクト（画面用） */
function asobiRequireAgeVerified(): void {
    asobiRequireLogin();
    if (!asobiIsAgeVerified()) {
        if (php_sapi_name() !== 'cli' && !headers_sent()) {
            $back = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
                . '://' . ($_SERVER['HTTP_HOST'] ?? 'asobi.info')
                . ($_SERVER['REQUEST_URI'] ?? '/');
            header('Location: https://asobi.info/profile.php?age_verify=1&redirect=' . urlencode($back));
            exit;
        }
    }
}

// ─────────────────── 禁止ワード ───────────────────

/**
 * テキストを正規化する（ひらがな統一・半角英数・小文字）
 * カタカナ（全角・半角）→ひらがな、全角英数→半角、大文字→小文字
 */
function asobiNormalize(string $text): string {
    $text = mb_convert_kana($text, 'Hcas', 'UTF-8');
    return mb_strtolower($text, 'UTF-8');
}

/**
 * 禁止ワードチェック
 * @param string $text     チェック対象テキスト
 * @param string $category 'username' | 'content'
 * @return array ['blocked'=>bool, 'action'=>'block'|'warn'|null]
 */
function asobiCheckBanned(string $text, string $category): array {
    static $cache = null;
    if ($cache === null) {
        try {
            $db = asobiUsersDb();
            $cache = $db->query(
                "SELECT normalized, category, action FROM banned_words ORDER BY LENGTH(normalized) DESC"
            )->fetchAll();
        } catch (Exception $e) {
            $cache = [];
        }
    }
    if (empty($cache)) return ['blocked' => false, 'action' => null];
    $normalized = asobiNormalize($text);
    foreach ($cache as $row) {
        if ($row['category'] !== $category && $row['category'] !== 'both') continue;
        if ($row['normalized'] !== '' && mb_strpos($normalized, $row['normalized']) !== false) {
            return ['blocked' => true, 'action' => $row['action']];
        }
    }
    return ['blocked' => false, 'action' => null];
}

// ─────────────────── アクセスログ ───────────────────

/** User-Agentを解析してブラウザ・デバイス・OSを返す */
function asobiParseUA(string $ua): array {
    if (empty($ua)) return ['browser' => '', 'device' => '', 'os' => ''];

    // Device
    if (preg_match('/iPad/i', $ua)) {
        $device = 'Tablet';
    } elseif (preg_match('/Mobile|Android.*Mobile|iPhone/i', $ua)) {
        $device = 'Mobile';
    } else {
        $device = 'Desktop';
    }

    // OS
    if (preg_match('/Windows NT/i', $ua))          $os = 'Windows';
    elseif (preg_match('/Mac OS X/i', $ua))        $os = 'macOS';
    elseif (preg_match('/Android/i', $ua))         $os = 'Android';
    elseif (preg_match('/iPhone|iPad/i', $ua))     $os = 'iOS';
    elseif (preg_match('/Linux/i', $ua))           $os = 'Linux';
    else                                           $os = 'Other';

    // Browser (順序重要)
    if (preg_match('/Edg\//i', $ua))               $browser = 'Edge';
    elseif (preg_match('/OPR\//i', $ua))           $browser = 'Opera';
    elseif (preg_match('/SamsungBrowser/i', $ua))  $browser = 'Samsung';
    elseif (preg_match('/YaBrowser/i', $ua))       $browser = 'Yandex';
    elseif (preg_match('/Chrome\/[\d]/i', $ua))    $browser = 'Chrome';
    elseif (preg_match('/Firefox\/[\d]/i', $ua))   $browser = 'Firefox';
    elseif (preg_match('/Safari\/[\d]/i', $ua))    $browser = 'Safari';
    else                                           $browser = 'Other';

    return compact('browser', 'device', 'os');
}

/** リファラーからドメインのみ抽出 */
function asobiRefererDomain(string $referer): string {
    if (empty($referer)) return '';
    $host = parse_url($referer, PHP_URL_HOST) ?? '';
    return $host;
}

/** GETリクエストのページビューを記録（ファイル追記→定期バッチでDB投入） */
function asobiLogAccess(): void {
    if (php_sapi_name() === 'cli') return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    try {
        $ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ref    = asobiRefererDomain($_SERVER['HTTP_REFERER'] ?? '');
        $parsed = asobiParseUA($ua);
        $line   = json_encode([
            'host'       => $_SERVER['HTTP_HOST'] ?? '',
            'path'       => strtok($_SERVER['REQUEST_URI'] ?? '/', '?'),
            'user_id'    => asobiIsLoggedIn() ? $_SESSION['asobi_user_id'] : null,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
            'referer'    => $ref,
            'user_agent' => $ua,
            'browser'    => $parsed['browser'],
            'device'     => $parsed['device'],
            'os'         => $parsed['os'],
            'created_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE) . "\n";
        $logFile = dirname(ASOBI_USERS_DB_PATH) . '/access_log_buffer.jsonl';
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    } catch (Exception $e) { /* ログ失敗は無視 */ }
}

function _asobiJsonError(int $code, string $message): void {
    if (php_sapi_name() !== 'cli' && !headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
        echo json_encode(['error' => $message]);
        exit;
    }
}

// ─────────────────── ログイン・ログアウト ───────────────────

/**
 * ユーザー名 + パスワードでログイン試行
 * @return bool 成功時 true
 */
function asobiAttemptLogin(string $username, string $password): bool {
    $db = asobiUsersDb();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        // メールアドレスでも検索
        $stmt2 = $db->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
        $stmt2->execute([$username]);
        $user = $stmt2->fetch();
    }
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    // ログイン記録（DB locked でもログイン自体は成功させる）
    try {
        $db->prepare("UPDATE users SET last_login_at = datetime('now','localtime') WHERE id = ?")
           ->execute([$user['id']]);
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $uaParsed = asobiParseUA($ua);
        $db->prepare("INSERT INTO login_logs (user_id, username, ip, user_agent, browser, device, os) VALUES (?, ?, ?, ?, ?, ?, ?)")
           ->execute([$user['id'], $user['username'], $_SERVER['REMOTE_ADDR'] ?? '', $ua, $uaParsed['browser'], $uaParsed['device'], $uaParsed['os']]);
    } catch (Exception $e) { /* DB locked時もログインは続行 */ }

    session_regenerate_id(true);
    $_SESSION['asobi_user_id']       = $user['id'];
    $_SESSION['asobi_user_username'] = $user['username'];
    $_SESSION['asobi_user_name']     = $user['display_name'] ?: $user['username'];
    $_SESSION['asobi_user_role']     = $user['role'];
    $_SESSION['asobi_user_avatar']   = $user['avatar_url'];

    return true;
}

function asobiLogout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']
        );
    }
    session_destroy();
}

// ─────────────────── ユーザー登録 ───────────────────

/**
 * 新規ユーザー登録
 * @return true|string 成功時 true、失敗時エラーメッセージ
 */
function asobiRegisterUser(
    string $username,
    string $password,
    string $email = '',
    string $displayName = ''
): bool|string {
    if (!preg_match('/^[a-zA-Z0-9_\-]{3,20}$/', $username)) {
        return 'ユーザー名は3〜20文字の英数字・アンダースコア・ハイフンで入力してください';
    }
    if (strlen($password) < 8) {
        return 'パスワードは8文字以上で入力してください';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'メールアドレスの形式が正しくありません';
    }

    $db = asobiUsersDb();

    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) return 'そのユーザー名は既に使われています';

    if ($email !== '') {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) return 'そのメールアドレスは既に登録されています';
    }

    $db->prepare("INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)")
       ->execute([
           $username,
           $email !== '' ? $email : null,
           password_hash($password, PASSWORD_BCRYPT),
           $displayName !== '' ? $displayName : $username,
       ]);

    return true;
}

// ─────────────────── プロフィール更新 ───────────────────

/**
 * プロフィール情報を更新
 * @return true|string 成功時 true、失敗時エラーメッセージ
 */
function asobiUpdateProfile(int $userId, array $data): bool|string {
    $db = asobiUsersDb();

    if (!empty($data['email'])) {
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return 'メールアドレスの形式が正しくありません';
        }
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$data['email'], $userId]);
        if ($stmt->fetch()) return 'そのメールアドレスは既に使われています';
    }

    $allowed = ['display_name', 'email', 'avatar_url'];
    $sets = [];
    $params = [];
    foreach ($allowed as $col) {
        if (array_key_exists($col, $data)) {
            $sets[] = "$col = ?";
            $params[] = ($data[$col] !== '') ? $data[$col] : null;
        }
    }
    if (empty($sets)) return true;

    $params[] = $userId;
    $db->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?")
       ->execute($params);

    // セッションも同期
    if (isset($data['display_name'])) {
        $_SESSION['asobi_user_name'] = $data['display_name'] ?: ($_SESSION['asobi_user_username'] ?? '');
    }
    if (isset($data['avatar_url'])) {
        $_SESSION['asobi_user_avatar'] = $data['avatar_url'] ?: null;
    }

    return true;
}

/**
 * パスワード変更
 * @return true|string 成功時 true、失敗時エラーメッセージ
 */
function asobiChangePassword(int $userId, string $currentPassword, string $newPassword): bool|string {
    if (strlen($newPassword) < 8) {
        return 'パスワードは8文字以上で入力してください';
    }

    $db = asobiUsersDb();
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
        return '現在のパスワードが正しくありません';
    }

    $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
       ->execute([password_hash($newPassword, PASSWORD_BCRYPT), $userId]);

    return true;
}

// ─────────────────── ソーシャルアカウント連携 ───────────────────

/** ソーシャルアカウント一覧を取得 */
function asobiGetSocialAccounts(int $userId): array {
    $db = asobiUsersDb();
    $stmt = $db->prepare("SELECT provider, display_name, username, email, created_at FROM social_accounts WHERE user_id = ? ORDER BY created_at");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** ソーシャルアカウントを解除 */
function asobiUnlinkSocial(int $userId, string $provider): bool {
    $db = asobiUsersDb();
    $stmt = $db->prepare("DELETE FROM social_accounts WHERE user_id = ? AND provider = ?");
    $stmt->execute([$userId, $provider]);
    return $stmt->rowCount() > 0;
}

/** OAuth URL を生成してセッションにステートを保存 */
function asobiOAuthGetUrl(string $provider, string $mode = 'login', string $redirectTo = ''): string {
    require_once __DIR__ . '/oauth_config.php';

    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state']    = $state;
    $_SESSION['oauth_mode']     = $mode;
    $_SESSION['oauth_redirect'] = $redirectTo ?: 'https://asobi.info/';
    unset($_SESSION['oauth_code_verifier']);

    if ($provider === 'google') {
        $params = [
            'client_id'     => ASOBI_GOOGLE_CLIENT_ID,
            'redirect_uri'  => ASOBI_GOOGLE_REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'prompt'        => 'select_account',
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);

    } elseif ($provider === 'line') {
        $params = [
            'response_type' => 'code',
            'client_id'     => ASOBI_LINE_CHANNEL_ID,
            'redirect_uri'  => ASOBI_LINE_REDIRECT_URI,
            'state'         => $state,
            'scope'         => 'profile openid email',
        ];
        return 'https://access.line.me/oauth2/v2.1/authorize?' . http_build_query($params);

    } elseif ($provider === 'twitter') {
        $verifier  = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $_SESSION['oauth_code_verifier'] = $verifier;
        $params = [
            'response_type'         => 'code',
            'client_id'             => ASOBI_TWITTER_CLIENT_ID,
            'redirect_uri'          => ASOBI_TWITTER_REDIRECT_URI,
            'scope'                 => 'users.read tweet.read offline.access',
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ];
        return 'https://twitter.com/i/oauth2/authorize?' . http_build_query($params);
    }

    throw new InvalidArgumentException("Unknown OAuth provider: $provider");
}

// ─────────────────── サイト設定 ───────────────────

/** サイト設定値を取得 */
function asobiGetSetting(string $key, string $default = ''): string {
    $db = asobiUsersDb();
    $stmt = $db->prepare("SELECT value FROM site_settings WHERE key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return ($val !== false) ? $val : $default;
}

/** サイト設定値を保存 */
function asobiSetSetting(string $key, string $value): void {
    $db = asobiUsersDb();
    $db->prepare("INSERT INTO site_settings (key, value, updated_at) VALUES (?, ?, datetime('now','localtime'))
                  ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
       ->execute([$key, $value]);
}

// ─────────────────── メール認証 ───────────────────

/** メール確認済みかどうか */
function asobiIsEmailVerified(int $userId): bool {
    $db = asobiUsersDb();
    $stmt = $db->prepare("SELECT email_verified_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row && !empty($row['email_verified_at']);
}

/**
 * 確認メールを送信
 * @return true|string 成功時 true、制限・失敗時エラーメッセージ
 */
function asobiSendVerificationEmail(int $userId, string $email): bool|string {
    $db = asobiUsersDb();

    // 既存レコードの確認（制限チェック）
    $existingStmt = $db->prepare("SELECT * FROM email_verifications WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $existingStmt->execute([$userId]);
    $row = $existingStmt->fetch();

    // DB から制限設定を取得
    $cooldownMinutes = max(1, (int)asobiGetSetting('email_verify_cooldown_minutes', '10'));
    $dailyLimit      = max(1, (int)asobiGetSetting('email_verify_daily_limit', '5'));
    $resetHours      = max(1, (int)asobiGetSetting('email_verify_reset_hours', '24'));

    if ($row) {
        // クールダウンチェック
        $lastSent = strtotime($row['last_sent_at'] ?? $row['created_at']);
        if ($lastSent > strtotime("-{$cooldownMinutes} minutes")) {
            return '連続して送信することはできません。しばらくたってから再度お試しください';
        }
        // 1日の上限チェック
        if ((int)($row['send_count'] ?? 1) >= $dailyLimit && strtotime($row['created_at']) > strtotime("-{$resetHours} hours")) {
            return "送信上限（{$dailyLimit}回）に達しました。しばらくたってから再試行してください";
        }
        // リセット時間経過済みなら古いレコードを削除してリセット
        if (strtotime($row['created_at']) <= strtotime("-{$resetHours} hours")) {
            $db->prepare("DELETE FROM email_verifications WHERE user_id = ?")->execute([$userId]);
            $row = null;
        }
    }

    $token     = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $now       = date('Y-m-d H:i:s');

    if ($row) {
        // 既存レコードを更新（created_at を保持して回数をカウント）
        $db->prepare("UPDATE email_verifications SET token = ?, email = ?, expires_at = ?, send_count = send_count + 1, last_sent_at = ? WHERE user_id = ?")
           ->execute([$token, $email, $expiresAt, $now, $userId]);
    } else {
        $db->prepare("INSERT INTO email_verifications (user_id, token, email, expires_at, send_count, last_sent_at) VALUES (?, ?, ?, ?, 1, ?)")
           ->execute([$userId, $token, $email, $expiresAt, $now]);
    }

    $url  = 'https://asobi.info/verify-email.php?token=' . $token;
    $subject = '=?UTF-8?B?' . base64_encode('【あそび】メールアドレスの確認') . '?=';
    $body = "このメールはあそび（asobi.info）から送信されています。\r\n\r\n"
          . "以下のリンクをクリックしてメールアドレスを確認してください。\r\n"
          . "リンクの有効期限は24時間です。\r\n\r\n"
          . $url . "\r\n\r\n"
          . "このメールに心当たりがない場合は無視してください。\r\n";
    $headers = "From: noreply@asobi.info\r\nReply-To: noreply@asobi.info\r\nContent-Type: text/plain; charset=UTF-8\r\n";

    return mail($email, $subject, $body, $headers, '-f noreply@asobi.info') ? true : 'メールの送信に失敗しました';
}

/** トークンを検証してメールを確認済みにする（$userId: ログイン中のユーザーIDで所有者確認） */
function asobiVerifyEmail(string $token, int $userId): bool|string {
    $db = asobiUsersDb();
    $stmt = $db->prepare("SELECT * FROM email_verifications WHERE token = ?");
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) return 'リンクが無効です';
    if ((int)$row['user_id'] !== $userId) return 'このリンクはあなたのアカウント用ではありません';
    if (strtotime($row['expires_at']) < time()) {
        $db->prepare("DELETE FROM email_verifications WHERE id = ?")->execute([$row['id']]);
        return 'リンクの有効期限が切れています。再度確認メールを送信してください';
    }

    $db->prepare("UPDATE users SET email_verified_at = datetime('now','localtime'), email = ? WHERE id = ?")
       ->execute([$row['email'], $row['user_id']]);
    $db->prepare("DELETE FROM email_verifications WHERE id = ?")->execute([$row['id']]);
    return true;
}

/** セッションからユーザーを設定（ソーシャルログイン用） */
function asobiLoginFromUserRow(array $user): void {
    try {
        $db = asobiUsersDb();
        $db->prepare("UPDATE users SET last_login_at = datetime('now','localtime') WHERE id = ?")
           ->execute([$user['id']]);
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $uaParsed = asobiParseUA($ua);
        $db->prepare("INSERT INTO login_logs (user_id, username, ip, user_agent, browser, device, os) VALUES (?, ?, ?, ?, ?, ?, ?)")
           ->execute([$user['id'], $user['username'], $_SERVER['REMOTE_ADDR'] ?? '', $ua, $uaParsed['browser'], $uaParsed['device'], $uaParsed['os']]);
    } catch (Exception $e) { /* DB locked時もログインは続行 */ }

    session_regenerate_id(true);
    $_SESSION['asobi_user_id']       = $user['id'];
    $_SESSION['asobi_user_username'] = $user['username'];
    $_SESSION['asobi_user_name']     = $user['display_name'] ?: $user['username'];
    $_SESSION['asobi_user_role']     = $user['role'];
    $_SESSION['asobi_user_avatar']   = $user['avatar_url'];
}

// ─────────────────── tbt.asobi.info 連携 ───────────────────

/**
 * ログイン中ユーザーの情報を含む短期JWT（5分）を発行する
 * tbt.asobi.info のクロスサイトログインに使用
 */
function asobiIssueTbtToken(): string {
    require_once __DIR__ . '/tbt_config.php';

    $user = asobiGetCurrentUser();
    $db   = asobiUsersDb();
    $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    $b64url = function($v): string {
        $str = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
        return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
    };

    $header  = $b64url(['alg' => 'HS256', 'typ' => 'JWT']);
    $payload = $b64url([
        'sub'     => (string)$user['id'],
        'email'   => $row['email'] ?? null,
        'name'    => $user['display_name'] ?: $user['username'],
        'avatar'  => $user['avatar_url'] ?? null,
        'purpose' => 'tbt_login',
        'iat'     => time(),
        'exp'     => time() + 300,
    ]);
    $sig = $b64url(hash_hmac('sha256', "$header.$payload", TBT_SHARED_SECRET, true));
    return "$header.$payload.$sig";
}

// ─────────────────── aic.asobi.info 連携 ───────────────────

/**
 * ログイン中ユーザーの情報を含む短期JWT（5分）を発行する
 * aic.asobi.info のクロスサイトログインに使用
 */
function asobiIssueAicToken(): string {
    require_once __DIR__ . '/aic_config.php';

    $user = asobiGetCurrentUser();
    $db   = asobiUsersDb();
    $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    $b64url = function($v): string {
        $str = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
        return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
    };

    $header  = $b64url(['alg' => 'HS256', 'typ' => 'JWT']);
    $payload = $b64url([
        'sub'     => (string)$user['id'],
        'email'   => $row['email'] ?? null,
        'name'    => $user['display_name'] ?: $user['username'],
        'avatar'  => $user['avatar_url'] ?? null,
        'role'    => $user['role'] ?? 'user',
        'purpose' => 'aic_login',
        'iat'     => time(),
        'exp'     => time() + 300,
    ]);
    $sig = $b64url(hash_hmac('sha256', "$header.$payload", AIC_SHARED_SECRET, true));
    return "$header.$payload.$sig";
}
