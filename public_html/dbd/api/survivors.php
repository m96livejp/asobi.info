<?php
require_once __DIR__ . '/db.php';

try {
    $db = getDb();

    // サバイバー一覧とそれぞれのパーク3つを取得
    $survivors = $db->query("
        SELECT id, name, name_en, image_path
        FROM survivors
        ORDER BY id
    ")->fetchAll();

    // パークをキャラクター名でグループ化
    $perksRaw = $db->query("
        SELECT name, name_en, character_name, image_path
        FROM perks
        WHERE role = 'survivor' AND character_name IS NOT NULL
        ORDER BY character_name, id
    ")->fetchAll();

    $perksByChar = [];
    foreach ($perksRaw as $p) {
        $perksByChar[$p['character_name']][] = $p;
    }

    // サバイバーにパーク情報を付与
    foreach ($survivors as &$s) {
        $s['perks'] = $perksByChar[$s['name']] ?? [];
    }

    jsonResponse($survivors);
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
