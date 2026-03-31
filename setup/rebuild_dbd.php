<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$dbPath = '/opt/asobi/dbd/data/dbd.sqlite';
$setupDir = __DIR__;

// 既存の空ファイルを削除
if (file_exists($dbPath)) unlink($dbPath);

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// スキーマ
$pdo->exec(file_get_contents($setupDir . '/dbd_schema.sql'));
echo "Schema done\n";

// データ
$data = file_get_contents($setupDir . '/dbd_data.sql');
$data = preg_replace('/^--.*$/m', '', $data);
$data = trim($data);
if ($data) $pdo->exec($data);
echo "Data done\n";

// image_path columns
foreach (['perks','killers','addons'] as $t) {
    $cols = array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('image_path', $cols)) {
        $pdo->exec("ALTER TABLE $t ADD COLUMN image_path TEXT DEFAULT NULL");
        echo "$t.image_path added\n";
    }
}

// offerings table
$pdo->exec("CREATE TABLE IF NOT EXISTS offerings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    name_en TEXT,
    role TEXT NOT NULL CHECK(role IN ('killer','survivor','shared')),
    category TEXT NOT NULL,
    rarity TEXT NOT NULL,
    description TEXT,
    image_path TEXT,
    sort_order INTEGER DEFAULT 0
)");
echo "Offerings table created\n";

// offerings data
$odata = file_get_contents($setupDir . '/dbd_offerings_data.sql');
$odata = preg_replace('/^--.*$/m', '', $odata);
$odata = trim($odata);
if ($odata) $pdo->exec($odata);
echo "Offerings data done\n";

// image_mappings table
$pdo->exec("CREATE TABLE IF NOT EXISTS image_mappings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    image_file TEXT NOT NULL,
    image_type TEXT NOT NULL,
    mapped_id INTEGER DEFAULT NULL,
    mapped_name TEXT DEFAULT NULL,
    status TEXT DEFAULT 'unmapped'
)");
echo "image_mappings table created\n";

// items table (referenced by API)
$pdo->exec("CREATE TABLE IF NOT EXISTS items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    name TEXT NOT NULL,
    name_en TEXT,
    description TEXT,
    rarity TEXT
)");
echo "items table created\n";

// survivor_addons table (referenced by API)
$pdo->exec("CREATE TABLE IF NOT EXISTS survivor_addons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_type TEXT NOT NULL,
    name TEXT NOT NULL,
    name_en TEXT,
    rarity TEXT,
    description TEXT
)");
echo "survivor_addons table created\n";

// 件数確認
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    $c = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    echo "  $t: $c rows\n";
}

chown($dbPath, 'www-data');
chgrp($dbPath, 'www-data');
chmod($dbPath, 0644);
echo "Done!\n";
