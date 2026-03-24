<?php
require_once __DIR__ . '/db.php';

try {
    $db = getDb();
    $killerId = $_GET['killer_id'] ?? null;
    $search = $_GET['q'] ?? null;

    $sql = 'SELECT a.*, k.name as killer_name, k.image_path as killer_image_path FROM addons a JOIN killers k ON a.killer_id = k.id WHERE 1=1';
    $params = [];

    if ($killerId) {
        $sql .= ' AND a.killer_id = ?';
        $params[] = (int)$killerId;
    }

    if ($search) {
        $sql .= ' AND (a.name LIKE ? OR a.description LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= ' ORDER BY a.killer_id, CASE a.rarity WHEN "common" THEN 1 WHEN "uncommon" THEN 2 WHEN "rare" THEN 3 WHEN "very_rare" THEN 4 WHEN "ultra_rare" THEN 5 WHEN "event" THEN 6 END';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse($stmt->fetchAll());
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
