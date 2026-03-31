<?php
require_once '/opt/asobi/shared/assets/php/users_db.php';
$db = asobiUsersDb();

// site_profiles テーブルの存在確認
$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='site_profiles'")->fetchAll();
echo 'site_profiles table: ' . (count($tables) ? 'EXISTS' : 'MISSING') . PHP_EOL;

// テーブル構造
if (count($tables)) {
    $cols = $db->query('PRAGMA table_info(site_profiles)')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo '  ' . $c['name'] . ' (' . $c['type'] . ')' . PHP_EOL;
}

// 全レコード確認
$rows = $db->query('SELECT * FROM site_profiles')->fetchAll(PDO::FETCH_ASSOC);
echo 'Records: ' . count($rows) . PHP_EOL;
foreach ($rows as $r) {
    echo '  user_id=' . $r['user_id'] . ' site=' . $r['site'] . ' name=' . $r['display_name'] . PHP_EOL;
}

// APIシミュレーション: asobi.infoでGET
echo "\n--- Simulate GET for asobi.info ---\n";
$stmt = $db->prepare('SELECT display_name FROM site_profiles WHERE user_id = ? AND site = ?');
$stmt->execute([1, 'asobi.info']);
$row = $stmt->fetch();
echo 'Result: ' . ($row ? $row['display_name'] : '(empty)') . PHP_EOL;
