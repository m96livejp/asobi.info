<?php
require_once __DIR__ . '/db.php';

try {
    $db = getDb();
    $role = $_GET['role'] ?? null;

    // キラー: killers.name = perks.character_name で一致
    // サバイバー: perks.character_name LIKE survivors.name || '%' で前方一致
    $results = [];

    if (!$role || $role === 'killer') {
        $killers = $db->query("SELECT name AS character_name, image_path, 'killer' AS role FROM killers ORDER BY name")->fetchAll();
        $results = array_merge($results, $killers);
    }

    if (!$role || $role === 'survivor') {
        $survivors = $db->query("
            SELECT DISTINCT p.character_name, s.image_path, 'survivor' AS role
            FROM perks p
            LEFT JOIN survivors s ON p.character_name LIKE s.name || '%'
            WHERE p.role = 'survivor' AND p.character_name IS NOT NULL
            ORDER BY p.character_name
        ")->fetchAll();
        $results = array_merge($results, $survivors);
    }

    jsonResponse($results);
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
