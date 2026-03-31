<?php
/**
 * ファミコン全タイトル一括インポート
 *
 * データソース:
 *   1) GitHub famicom-soft-list (642件: 発売日,タイトル,メーカー,ROM容量,定価)
 *   2) Wikipedia ファミリーコンピュータのゲームタイトル一覧（補完用）
 *
 * 使い方:
 *   php import-famicom.php                  # GitHubデータをインポート
 *   php import-famicom.php --dry-run        # 実行せず内容を確認
 *   php import-famicom.php --reset          # 既存NESデータを削除して再投入
 */

// --- DB接続（サーバーと同じパスを使うか、ローカル用に切り替え） ---
$localDb = __DIR__ . '/../data/game.sqlite';
$serverDb = '/opt/asobi/game/data/game.sqlite';
$dbPath = file_exists($serverDb) ? $serverDb : $localDb;

echo "=== ファミコンタイトル一括インポート ===\n";
echo "DB: {$dbPath}\n\n";

$pdo = new PDO("sqlite:{$dbPath}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA journal_mode=WAL');
$pdo->exec('PRAGMA foreign_keys=ON');

// --- スキーマ確認・マイグレーション ---
$cols = [];
foreach ($pdo->query("PRAGMA table_info(games)") as $row) {
    $cols[] = $row['name'];
}
if (!in_array('release_date', $cols)) $pdo->exec("ALTER TABLE games ADD COLUMN release_date TEXT");
if (!in_array('price', $cols))        $pdo->exec("ALTER TABLE games ADD COLUMN price INTEGER");
if (!in_array('players', $cols))      $pdo->exec("ALTER TABLE games ADD COLUMN players TEXT");
if (!in_array('rom_size', $cols))     $pdo->exec("ALTER TABLE games ADD COLUMN rom_size TEXT");
if (!in_array('catalog_no', $cols))   $pdo->exec("ALTER TABLE games ADD COLUMN catalog_no TEXT");

// game_tips テーブル
$pdo->exec("CREATE TABLE IF NOT EXISTS game_tips (
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

echo "スキーマ確認OK\n";

// --- オプション解析 ---
$dryRun = in_array('--dry-run', $argv ?? []);
$reset  = in_array('--reset', $argv ?? []);

if ($reset && !$dryRun) {
    $deleted = $pdo->exec("DELETE FROM games WHERE platform = 'nes'");
    echo "既存NESデータを削除しました: {$deleted}件\n";
}

// --- GitHubデータ取得 ---
$jsonUrl = 'https://raw.githubusercontent.com/webcyou-org/famicom-soft-list/master/data/soft_list.json';
echo "データ取得中: {$jsonUrl}\n";

$json = file_get_contents($jsonUrl);
if ($json === false) {
    echo "Error: データの取得に失敗しました\n";
    exit(1);
}

$games = json_decode($json, true);
if (!$games) {
    echo "Error: JSONのパースに失敗しました\n";
    exit(1);
}

echo "取得件数: " . count($games) . "件\n\n";

if ($dryRun) {
    echo "--- Dry Run モード（データは投入しません）---\n";
    foreach ($games as $i => $g) {
        printf("  %4d. [%s] %s (%s, ¥%s, %s)\n",
            $i + 1,
            $g['release_date'] ?? '????-??-??',
            $g['title'],
            $g['publisher'] ?? '',
            number_format($g['price'] ?? 0),
            $g['bit_memory'] ?? ''
        );
    }
    echo "\n合計: " . count($games) . "件\n";
    exit(0);
}

// --- スラッグ生成 ---
function makeSlug(int $id, string $title): string {
    // fc-0001 形式の連番スラッグ
    return sprintf('fc-%04d', $id);
}

// --- 一括インポート ---
$insertSql = "INSERT OR IGNORE INTO games
    (platform, slug, title, publisher, release_date, release_year, price, rom_size, sort_order, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = $pdo->prepare($insertSql);

$now = date('Y-m-d H:i:s');
$success = 0;
$skipped = 0;

$pdo->beginTransaction();

foreach ($games as $g) {
    $id = (int)$g['id'];
    $slug = makeSlug($id, $g['title']);
    $releaseDate = $g['release_date'] ?? null;
    $releaseYear = $releaseDate ? (int)substr($releaseDate, 0, 4) : null;
    $price = isset($g['price']) ? (int)$g['price'] : null;
    $romSize = $g['bit_memory'] ?? null;

    $result = $stmt->execute([
        'nes',
        $slug,
        $g['title'],
        $g['publisher'] ?? null,
        $releaseDate,
        $releaseYear,
        $price ?: null,
        $romSize,
        $id,   // sort_order = 元のID（発売順）
        $now,
        $now,
    ]);

    if ($stmt->rowCount() > 0) {
        $success++;
        if ($success % 100 === 0) {
            echo "  {$success}件 投入済み...\n";
        }
    } else {
        $skipped++;
    }
}

$pdo->commit();

echo "\n=== 完了 ===\n";
echo "  投入: {$success}件\n";
echo "  スキップ（重複）: {$skipped}件\n";

// 確認
$total = $pdo->query("SELECT COUNT(*) FROM games WHERE platform = 'nes'")->fetchColumn();
echo "  NES合計: {$total}件\n";
