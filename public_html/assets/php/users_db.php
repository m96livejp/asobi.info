<?php
/**
 * ユーザーDB接続 - asobi.info 共通
 * DB は Webルート外に配置: /home/m96/asobi.info/data/users.sqlite
 */

define('ASOBI_USERS_DB_PATH', '/home/m96/asobi.info/data/users.sqlite');

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

    // デフォルト設定の初期投入（存在しない場合のみ）
    $defaults = [
        'email_verify_cooldown_minutes' => '10',
        'email_verify_daily_limit'      => '5',
        'email_verify_reset_hours'      => '24',
    ];
    $ins = $db->prepare("INSERT OR IGNORE INTO site_settings (key, value) VALUES (?, ?)");
    foreach ($defaults as $k => $v) {
        $ins->execute([$k, $v]);
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

    // 既存テーブルへのカラム追加（既存DBのマイグレーション）
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
    ];
    foreach ($migrate as $sql) {
        try { $db->exec($sql); } catch (Exception $e) { /* already exists */ }
    }

    return $db;
}
