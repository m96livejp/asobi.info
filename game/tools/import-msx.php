<?php
/**
 * MSX全タイトル一括インポート
 *
 * データソース:
 *   GitHub boaglio/msx-games-db (msxgames.js - MongoDB形式)
 *
 * 使い方:
 *   php import-msx.php                  # インポート実行
 *   php import-msx.php --dry-run        # 実行せず内容を確認
 *   php import-msx.php --reset          # 既存MSXデータを削除して再投入
 */

$localDb = __DIR__ . '/../data/game.sqlite';
$serverDb = '/opt/asobi/game/data/game.sqlite';
$dbPath = file_exists($serverDb) ? $serverDb : $localDb;

echo "=== MSX タイトル一括インポート ===\n";
echo "DB: {$dbPath}\n\n";

$pdo = new PDO("sqlite:{$dbPath}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA journal_mode=WAL');
$pdo->exec('PRAGMA foreign_keys=ON');

// --- スキーマ確認 ---
$cols = [];
foreach ($pdo->query("PRAGMA table_info(games)") as $row) {
    $cols[] = $row['name'];
}
if (!in_array('release_date', $cols)) $pdo->exec("ALTER TABLE games ADD COLUMN release_date TEXT");
if (!in_array('price', $cols))        $pdo->exec("ALTER TABLE games ADD COLUMN price INTEGER");
if (!in_array('players', $cols))      $pdo->exec("ALTER TABLE games ADD COLUMN players TEXT");
if (!in_array('rom_size', $cols))     $pdo->exec("ALTER TABLE games ADD COLUMN rom_size TEXT");
if (!in_array('catalog_no', $cols))   $pdo->exec("ALTER TABLE games ADD COLUMN catalog_no TEXT");
echo "スキーマ確認OK\n";

// --- オプション解析 ---
$dryRun = in_array('--dry-run', $argv ?? []);
$reset  = in_array('--reset', $argv ?? []);

if ($reset && !$dryRun) {
    $deleted = $pdo->exec("DELETE FROM games WHERE platform = 'msx'");
    echo "既存MSXデータを削除しました: {$deleted}件\n";
}

// --- GitHubデータ取得 ---
$jsUrl = 'https://raw.githubusercontent.com/boaglio/msx-games-db/master/msxgames.js';
echo "データ取得中: {$jsUrl}\n";

$jsData = file_get_contents($jsUrl);
if ($jsData === false) {
    echo "Error: データの取得に失敗しました\n";
    exit(1);
}

// --- MongoDB JS形式をパース ---
$lines = explode("\n", trim($jsData));
$games = [];
$sortOrder = 0;

foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line)) continue;

    // db.msxgames.insertOne({_id:1,name:"...",genre:"...",publisher:"...",lang:"...",released:"...",machine:"..."});
    if (preg_match('/\{_id:(\d+),name:"([^"]*)",genre:"([^"]*)",publisher:"([^"]*)",lang:"([^"]*)",released:"([^"]*)",machine:"([^"]*)"\s*\}/', $line, $m)) {
        $sortOrder++;
        $year = $m[6];
        // 不正な年（9992等）はnullに
        $releaseYear = ($year && is_numeric($year) && (int)$year >= 1980 && (int)$year <= 2020) ? (int)$year : null;

        $games[] = [
            'id'           => (int)$m[1],
            'title'        => $m[2],
            'genre'        => $m[3] !== '[uncategorized]' ? $m[3] : null,
            'publisher'    => $m[4],
            'lang'         => $m[5],
            'release_year' => $releaseYear,
            'machine'      => $m[7],
            'sort_order'   => $sortOrder,
        ];
    }
}

echo "パース件数: " . count($games) . "件\n\n";

if ($dryRun) {
    echo "--- Dry Run モード（データは投入しません）---\n";
    foreach ($games as $i => $g) {
        printf("  %4d. [%s] %s (%s, %s, %s)\n",
            $i + 1,
            $g['release_year'] ?? '????',
            $g['title'],
            $g['publisher'],
            $g['machine'],
            $g['lang']
        );
    }
    echo "\n合計: " . count($games) . "件\n";
    exit(0);
}

// --- スラッグ生成 ---
function makeSlug(int $id): string {
    return sprintf('msx-%04d', $id);
}

// --- 一括インポート ---
$insertSql = "INSERT OR IGNORE INTO games
    (platform, slug, title, genre, publisher, release_year, sort_order, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = $pdo->prepare($insertSql);

$now = date('Y-m-d H:i:s');
$success = 0;
$skipped = 0;

$pdo->beginTransaction();

foreach ($games as $g) {
    $slug = makeSlug($g['id']);

    $stmt->execute([
        'msx',
        $slug,
        $g['title'],
        $g['genre'],
        $g['publisher'],
        $g['release_year'],
        $g['sort_order'],
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

$total = $pdo->query("SELECT COUNT(*) FROM games WHERE platform = 'msx'")->fetchColumn();
echo "  MSX合計: {$total}件\n";
