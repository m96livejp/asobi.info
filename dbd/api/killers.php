<?php
require_once __DIR__ . '/db.php';

try {
    $db = getDb();
    $id = $_GET['id'] ?? null;

    if ($id) {
        $stmt = $db->prepare('SELECT * FROM killers WHERE id = ?');
        $stmt->execute([(int)$id]);
        $killer = $stmt->fetch();
        if (!$killer) jsonResponse(['error' => 'Not found'], 404);

        // アドオンも取得
        $stmt2 = $db->prepare('SELECT * FROM addons WHERE killer_id = ? ORDER BY
            CASE rarity WHEN "common" THEN 1 WHEN "uncommon" THEN 2 WHEN "rare" THEN 3 WHEN "very_rare" THEN 4 WHEN "ultra_rare" THEN 5 WHEN "event" THEN 6 END');
        $stmt2->execute([(int)$id]);
        $killer['addons'] = $stmt2->fetchAll();

        // 固有パーク
        $stmt3 = $db->prepare('SELECT * FROM perks WHERE character_name = ? AND role = "killer"');
        $stmt3->execute([$killer['name']]);
        $killer['perks'] = $stmt3->fetchAll();

        jsonResponse($killer);
    }

    // 一覧
    $stmt = $db->query('SELECT * FROM killers ORDER BY id');
    jsonResponse($stmt->fetchAll());
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
