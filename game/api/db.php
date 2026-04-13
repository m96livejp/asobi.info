<?php
define('GAME_DB_PATH', '/opt/asobi/game/data/game.sqlite');

function gameDb(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . GAME_DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
    }
    return $pdo;
}

function gameApiJson(array $data): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function gameApiError(int $code, string $msg): never {
    http_response_code($code);
    gameApiJson(['error' => $msg]);
}

$PLATFORM_LABELS = [
    'nes'  => 'ファミコン (NES)',
    'snes' => 'スーパーファミコン (SNES)',
    'pce'  => 'PCエンジン',
    'md'   => 'メガドライブ',
    'msx'  => 'MSX',
];

$VALID_PLATFORMS = array_keys($PLATFORM_LABELS);

// --- スキーマ自動マイグレーション ---
function gameDbMigrate(): void {
    $db = gameDb();

    // games テーブルに新カラム追加（存在しなければ）
    $cols = [];
    foreach ($db->query("PRAGMA table_info(games)") as $row) {
        $cols[] = $row['name'];
    }
    if (!in_array('release_date', $cols)) $db->exec("ALTER TABLE games ADD COLUMN release_date TEXT");
    if (!in_array('price', $cols))        $db->exec("ALTER TABLE games ADD COLUMN price INTEGER");
    if (!in_array('players', $cols))      $db->exec("ALTER TABLE games ADD COLUMN players TEXT");
    if (!in_array('rom_size', $cols))     $db->exec("ALTER TABLE games ADD COLUMN rom_size TEXT");
    if (!in_array('catalog_no', $cols))   $db->exec("ALTER TABLE games ADD COLUMN catalog_no TEXT");  // 型番（例: HVC-SM）
    if (!in_array('md_number', $cols))    $db->exec("ALTER TABLE games ADD COLUMN md_number TEXT");   // ROMダンプ通番（例: MD4059）
    if (!in_array('box_image', $cols))    $db->exec("ALTER TABLE games ADD COLUMN box_image TEXT");    // パッケージ画像
    if (!in_array('title_image', $cols))  $db->exec("ALTER TABLE games ADD COLUMN title_image TEXT");  // タイトル画面
    if (!in_array('cart_image', $cols))   $db->exec("ALTER TABLE games ADD COLUMN cart_image TEXT");   // カートリッジ画像
    if (!in_array('description_en', $cols)) $db->exec("ALTER TABLE games ADD COLUMN description_en TEXT"); // 英語概要（Sega Retro等から）
    if (!in_array('source_url', $cols))   $db->exec("ALTER TABLE games ADD COLUMN source_url TEXT");   // 情報元URL（Sega Retro等）
    if (!in_array('source_attribution', $cols)) $db->exec("ALTER TABLE games ADD COLUMN source_attribution TEXT"); // 出典表記（CC BY-SA等）

    // featured_games テーブル（注目ゲーム手動ピン留め）
    $db->exec("CREATE TABLE IF NOT EXISTS featured_games (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        game_id    INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
        platform   TEXT NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        UNIQUE(game_id)
    )");

    // game_screenshots テーブル（サブ画像：プレイ画面など複数枚）
    $db->exec("CREATE TABLE IF NOT EXISTS game_screenshots (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        game_id INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
        category TEXT NOT NULL DEFAULT 'screenshot',
        image_path TEXT NOT NULL,
        caption TEXT,
        region TEXT,
        source_url TEXT,
        source_attribution TEXT,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_game_screenshots_game ON game_screenshots(game_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_game_screenshots_category ON game_screenshots(game_id, category)");

    // game_tips テーブル（裏技・コード情報）
    $db->exec("CREATE TABLE IF NOT EXISTS game_tips (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        game_id    INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
        category   TEXT NOT NULL DEFAULT 'cheat',
        title      TEXT NOT NULL,
        content    TEXT NOT NULL,
        user_id    INTEGER,
        username   TEXT,
        status     TEXT NOT NULL DEFAULT 'pending',
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
}
gameDbMigrate();
