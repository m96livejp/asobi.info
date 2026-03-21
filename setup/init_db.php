<?php
/**
 * データベース初期化スクリプト
 * サーバー上で1回実行: php init_db.php
 * またはブラウザから: https://asobi.info/setup/init_db.php?key=SETUP_KEY_2026
 */

// セキュリティ: URLから実行する場合はキーが必要
if (php_sapi_name() !== 'cli') {
    if (($_GET['key'] ?? '') !== 'SETUP_KEY_2026') {
        http_response_code(403);
        die('Forbidden');
    }
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

$baseDir = dirname(__DIR__);

function initDatabase($dbPath, $schemaFile, $dataFile, $label) {
    echo "=== {$label} データベース構築 ===\n";

    // 既存DBを削除
    if (file_exists($dbPath)) {
        unlink($dbPath);
        echo "既存DB削除\n";
    }

    // ディレクトリ確認
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // スキーマ実行
        $schema = file_get_contents($schemaFile);
        $pdo->exec($schema);
        echo "スキーマ適用完了\n";

        // データ投入 - ファイル全体をexecで実行
        $data = file_get_contents($dataFile);
        // コメント行を削除
        $data = preg_replace('/^--.*$/m', '', $data);
        // 空行を削除
        $data = preg_replace('/^\s*$/m', '', $data);
        $data = trim($data);

        if (!empty($data)) {
            $pdo->exec($data);
        }
        echo "データ投入完了\n";

        // 件数確認
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $c = $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            echo "  {$table}: {$c} 件\n";
        }

        chmod($dbPath, 0644);
        echo "{$label} DB完了: {$dbPath}\n\n";
        return true;
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        return false;
    }
}

// DbD データベース
$ok1 = initDatabase(
    $baseDir . '/public_html/dbd/data/dbd.sqlite',
    __DIR__ . '/dbd_schema.sql',
    __DIR__ . '/dbd_data.sql',
    'Dead by Daylight'
);

// ポケモンクエスト データベース
$ok2 = initDatabase(
    $baseDir . '/public_html/pokemon-quest/data/pokemon_quest.sqlite',
    __DIR__ . '/pq_schema.sql',
    __DIR__ . '/pq_data.sql',
    'ポケモンクエスト'
);

echo "\n=== 完了 ===\n";
echo "DbD: " . ($ok1 ? 'OK' : 'FAILED') . "\n";
echo "ポケクエ: " . ($ok2 ? 'OK' : 'FAILED') . "\n";

if (php_sapi_name() !== 'cli') {
    echo "\n<br><a href='javascript:history.back()'>戻る</a>";
    echo "<br><b>セットアップ完了後、このファイルを削除してください。</b>";
}
