<?php
// DB schema update for image support + offerings
require_once __DIR__ . '/../public_html/dbd/api/db.php';
$db = getDb();

// Add image_path columns
$tables = ['perks', 'killers', 'addons'];
foreach ($tables as $t) {
    $cols = array_column($db->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('image_path', $cols)) {
        $db->exec("ALTER TABLE $t ADD COLUMN image_path TEXT DEFAULT NULL");
        echo "$t.image_path added\n";
    } else {
        echo "$t.image_path already exists\n";
    }
}

// image_mappings table
$db->exec("CREATE TABLE IF NOT EXISTS image_mappings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    image_file TEXT NOT NULL,
    image_type TEXT NOT NULL,
    mapped_id INTEGER DEFAULT NULL,
    mapped_name TEXT DEFAULT NULL,
    status TEXT DEFAULT 'unmapped'
)");
echo "image_mappings table ready\n";

// offerings table
$db->exec("CREATE TABLE IF NOT EXISTS offerings (
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
echo "offerings table ready\n";
echo "All DB changes done\n";
