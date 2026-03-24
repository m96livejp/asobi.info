<?php
require_once __DIR__ . '/db.php';

try {
    $db = getDb();
    $role = $_GET['role'] ?? null;
    $search = $_GET['q'] ?? null;
    $character = $_GET['character'] ?? null;

    $sql = 'SELECT * FROM perks WHERE 1=1';
    $params = [];

    if ($role && in_array($role, ['killer', 'survivor'])) {
        $sql .= ' AND role = ?';
        $params[] = $role;
    }

    if ($character) {
        $sql .= ' AND character_name = ?';
        $params[] = $character;
    }

    if ($search) {
        $sql .= ' AND (name LIKE ? OR name_en LIKE ? OR description LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= ' ORDER BY character_name, id';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse($stmt->fetchAll());
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
