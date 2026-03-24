<?php
require_once __DIR__ . '/db.php';

try {
    $db = getDb();
    $item_type = $_GET['item_type'] ?? null;

    if ($item_type) {
        $stmt = $db->prepare("SELECT * FROM survivor_addons WHERE item_type = ? ORDER BY rarity DESC, name");
        $stmt->execute([$item_type]);
    } else {
        $stmt = $db->query("SELECT * FROM survivor_addons ORDER BY item_type, rarity DESC, name");
    }

    jsonResponse($stmt->fetchAll());
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
