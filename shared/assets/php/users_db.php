<?php
/**
 * ユーザーDB接続 - asobi.info 共通
 * DB は Webルート外に配置: /opt/asobi/data/users.sqlite
 */

define('ASOBI_USERS_DB_PATH', '/opt/asobi/data/users.sqlite');
define('ASOBI_TODOS_DB_PATH', '/opt/asobi/data/todos.sqlite');

function asobiUsersDb(): PDO {
    static $db = null;
    if ($db !== null) return $db;

    $dir = dirname(ASOBI_USERS_DB_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }

    $db = new PDO('sqlite:' . ASOBI_USERS_DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('PRAGMA foreign_keys=ON');

    // スキーマ初期化は初回のみ（マーカーファイルで判定）
    $markerFile = dirname(ASOBI_USERS_DB_PATH) . '/.schema_v12';
    if (!file_exists($markerFile)) {
        _asobiInitSchema($db);
        @file_put_contents($markerFile, date('Y-m-d H:i:s'));
    }

    return $db;
}

/**
 * TODO専用DB接続
 * /opt/asobi/data/todos.sqlite を使用
 */
function asobiTodosDb(): PDO {
    static $db = null;
    if ($db !== null) return $db;

    $dir = dirname(ASOBI_TODOS_DB_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }

    $db = new PDO('sqlite:' . ASOBI_TODOS_DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=5000');

    // content_todos テーブルがなければ作成
    $db->exec("
        CREATE TABLE IF NOT EXISTS content_todos (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            site         TEXT NOT NULL,
            area         TEXT NOT NULL DEFAULT 'general' CHECK(area IN ('general','admin')),
            title        TEXT NOT NULL,
            priority     TEXT NOT NULL DEFAULT 'medium' CHECK(priority IN ('low','medium','high')),
            status       TEXT NOT NULL DEFAULT '未着手',
            status_note  TEXT NOT NULL DEFAULT '',
            hold_answer  TEXT NOT NULL DEFAULT '',
            result       TEXT NOT NULL DEFAULT '',
            due_date     TEXT,
            started_at   TEXT,
            completed_at TEXT,
            created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        )
    ");

    return $db;
}

/** テーブル作成・マイグレーション（初回のみ） */
function _asobiInitSchema(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT    NOT NULL UNIQUE COLLATE NOCASE,
            email         TEXT    UNIQUE COLLATE NOCASE,
            password_hash TEXT    NOT NULL,
            display_name  TEXT,
            avatar_url    TEXT,
            role          TEXT    NOT NULL DEFAULT 'user'
                              CHECK(role IN ('user','admin')),
            status        TEXT    NOT NULL DEFAULT 'active'
                              CHECK(status IN ('active','suspended')),
            created_at    TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
            last_login_at TEXT
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS access_logs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            host       TEXT    NOT NULL DEFAULT '',
            path       TEXT    NOT NULL,
            user_id    INTEGER,
            ip         TEXT,
            referer    TEXT,
            user_agent TEXT,
            browser    TEXT    NOT NULL DEFAULT '',
            device     TEXT    NOT NULL DEFAULT '',
            os         TEXT    NOT NULL DEFAULT '',
            created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS login_logs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            username   TEXT    NOT NULL,
            ip         TEXT,
            user_agent TEXT,
            browser    TEXT    NOT NULL DEFAULT '',
            device     TEXT    NOT NULL DEFAULT '',
            os         TEXT    NOT NULL DEFAULT '',
            created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS social_accounts (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            provider     TEXT    NOT NULL,
            provider_id  TEXT    NOT NULL,
            email        TEXT,
            display_name TEXT,
            username     TEXT,
            created_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(provider, provider_id)
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS email_verifications (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            token      TEXT    NOT NULL UNIQUE,
            email      TEXT    NOT NULL,
            expires_at TEXT    NOT NULL,
            created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS site_settings (
            key        TEXT PRIMARY KEY,
            value      TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        )
    ");

    $defaults = [
        'email_verify_cooldown_minutes' => '10',
        'email_verify_daily_limit'      => '5',
        'email_verify_reset_hours'      => '24',
    ];
    $ins = $db->prepare("INSERT OR IGNORE INTO site_settings (key, value) VALUES (?, ?)");
    foreach ($defaults as $k => $v) {
        $ins->execute([$k, $v]);
    }
    // APIキー（未登録の場合のみ自動生成）
    $apiKeyExists = $db->query("SELECT 1 FROM site_settings WHERE key = 'api_key'")->fetch();
    if (!$apiKeyExists) {
        $db->prepare("INSERT INTO site_settings (key, value) VALUES ('api_key', ?)")
           ->execute([bin2hex(random_bytes(16))]);
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS banned_words (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            word       TEXT NOT NULL,
            normalized TEXT NOT NULL,
            category   TEXT NOT NULL CHECK(category IN ('username','content','both')),
            action     TEXT NOT NULL DEFAULT 'block' CHECK(action IN ('block','warn')),
            note       TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        )
    ");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_banned_norm_cat ON banned_words(normalized, category)");

    $db->exec("
        CREATE TABLE IF NOT EXISTS site_profiles (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id      INTEGER NOT NULL,
            site         TEXT    NOT NULL,
            display_name TEXT    NOT NULL DEFAULT '',
            updated_at   TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(user_id, site)
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS content_todos (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            site        TEXT NOT NULL,
            area        TEXT NOT NULL DEFAULT 'general'
                            CHECK(area IN ('general','admin')),
            title       TEXT NOT NULL,
            priority    TEXT NOT NULL DEFAULT 'medium'
                            CHECK(priority IN ('low','medium','high')),
            status      TEXT NOT NULL DEFAULT '未着手',
            status_note TEXT NOT NULL DEFAULT '',
            hold_answer TEXT NOT NULL DEFAULT '',
            result      TEXT NOT NULL DEFAULT '',
            due_date    TEXT,
            started_at  TEXT,
            completed_at TEXT,
            created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        )
    ");

    // ウォレット（AC）関連テーブル
    $db->exec("
        CREATE TABLE IF NOT EXISTS wallets (
            user_id           INTEGER PRIMARY KEY,
            balance           INTEGER NOT NULL DEFAULT 0,
            total_charged_jpy INTEGER NOT NULL DEFAULT 0,
            updated_at        TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS wallet_charge_lots (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id           INTEGER NOT NULL,
            charge_jpy        INTEGER NOT NULL,
            ac_base           INTEGER NOT NULL,
            ac_bonus          INTEGER NOT NULL DEFAULT 0,
            ac_total          INTEGER NOT NULL,
            ac_remaining      INTEGER NOT NULL,
            expires_at        TEXT NOT NULL,
            stripe_payment_id TEXT,
            created_at        TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_wallet_lots_user ON wallet_charge_lots(user_id, ac_remaining, expires_at)");
    $db->exec("
        CREATE TABLE IF NOT EXISTS wallet_transactions (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id       INTEGER NOT NULL,
            type          TEXT NOT NULL,
            amount        INTEGER NOT NULL,
            balance_after INTEGER NOT NULL,
            site          TEXT,
            lot_id        INTEGER,
            reference_id  TEXT,
            note          TEXT,
            created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_wallet_tx_user ON wallet_transactions(user_id, created_at DESC)");
    $db->exec("
        CREATE TABLE IF NOT EXISTS wallet_presets (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            charge_jpy INTEGER NOT NULL UNIQUE,
            ac_base    INTEGER NOT NULL,
            ac_bonus   INTEGER NOT NULL DEFAULT 0,
            is_active  INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS wallet_campaign (
            id             INTEGER PRIMARY KEY CHECK(id = 1),
            bonus_enabled  INTEGER NOT NULL DEFAULT 0,
            label          TEXT DEFAULT '期間限定ボーナス実施中'
        )
    ");
    $db->exec("INSERT OR IGNORE INTO wallet_campaign (id) VALUES (1)");
    $db->exec("
        CREATE TABLE IF NOT EXISTS wallet_system (
            id          INTEGER PRIMARY KEY CHECK(id = 1),
            stripe_mode TEXT NOT NULL DEFAULT 'test',
            updated_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_by  INTEGER
        )
    ");
    $db->exec("INSERT OR IGNORE INTO wallet_system (id, stripe_mode) VALUES (1, 'test')");

    // IP国別判定用
    $db->exec("
        CREATE TABLE IF NOT EXISTS ip_ranges (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_start   INTEGER NOT NULL,
            ip_end     INTEGER NOT NULL,
            country    TEXT NOT NULL DEFAULT 'JP',
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ip_ranges_lookup ON ip_ranges(ip_start, ip_end)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ip_ranges_country ON ip_ranges(country)");

    $migrate = [
        "ALTER TABLE access_logs ADD COLUMN referer TEXT",
        "ALTER TABLE access_logs ADD COLUMN user_agent TEXT",
        "ALTER TABLE access_logs ADD COLUMN browser TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE access_logs ADD COLUMN device TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE access_logs ADD COLUMN os TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE login_logs ADD COLUMN user_agent TEXT",
        "ALTER TABLE login_logs ADD COLUMN browser TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE login_logs ADD COLUMN device TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE login_logs ADD COLUMN os TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE users ADD COLUMN email_verified_at TEXT",
        "ALTER TABLE email_verifications ADD COLUMN send_count INTEGER NOT NULL DEFAULT 1",
        "ALTER TABLE email_verifications ADD COLUMN last_sent_at TEXT",
        "ALTER TABLE content_todos ADD COLUMN area TEXT NOT NULL DEFAULT 'general'",
        "ALTER TABLE content_todos ADD COLUMN due_date TEXT",
        "ALTER TABLE content_todos ADD COLUMN status_note TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE content_todos ADD COLUMN hold_answer TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE content_todos ADD COLUMN started_at TEXT",
        "ALTER TABLE content_todos ADD COLUMN completed_at TEXT",
        "ALTER TABLE users ADD COLUMN birth_year INTEGER",
        "ALTER TABLE users ADD COLUMN age_verified_at TEXT",
        "ALTER TABLE wallet_charge_lots ADD COLUMN is_test INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE wallet_transactions ADD COLUMN is_test INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE wallet_transactions ADD COLUMN admin_comment TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE wallet_transactions ADD COLUMN admin_comment_at TEXT",
        "ALTER TABLE wallet_transactions ADD COLUMN admin_comment_by INTEGER",
        "ALTER TABLE access_logs ADD COLUMN country TEXT NOT NULL DEFAULT ''",
    ];
    foreach ($migrate as $sql) {
        try { $db->exec($sql); } catch (Exception $e) { /* already exists */ }
    }
}
