<?php
require_once __DIR__ . '/db.php';

try {
    $db = getDb();
    $type   = $_GET['type']   ?? null;
    $search = $_GET['q']      ?? null;

    $sql = 'SELECT * FROM items WHERE 1=1';
    $params = [];

    if ($type) {
        $sql .= ' AND type = ?';
        $params[] = $type;
    }

    if ($search) {
        $sql .= ' AND (name LIKE ? OR name_en LIKE ? OR description LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $rarityOrder = "CASE rarity
        WHEN 'common'     THEN 1
        WHEN 'uncommon'   THEN 2
        WHEN 'rare'       THEN 3
        WHEN 'very_rare'  THEN 4
        WHEN 'ultra_rare' THEN 5
        WHEN 'event'      THEN 6
        ELSE 99 END";

    $sql .= " ORDER BY type, $rarityOrder, id";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse($stmt->fetchAll());
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
