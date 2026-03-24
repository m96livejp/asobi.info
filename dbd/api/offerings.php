<?php
require_once __DIR__ . '/db.php';

try {
    $db = getDb();
    $role = $_GET['role'] ?? null;
    $category = $_GET['category'] ?? null;
    $search = $_GET['q'] ?? null;

    $sql = 'SELECT * FROM offerings WHERE 1=1';
    $params = [];

    if ($role && in_array($role, ['killer', 'survivor', 'shared'])) {
        if ($role === 'killer') {
            $sql .= " AND role IN ('killer', 'shared')";
        } elseif ($role === 'survivor') {
            $sql .= " AND role IN ('survivor', 'shared')";
        } else {
            $sql .= ' AND role = ?';
            $params[] = $role;
        }
    }

    if ($category) {
        $sql .= ' AND category = ?';
        $params[] = $category;
    }

    if ($search) {
        $sql .= ' AND (name LIKE ? OR description LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= ' ORDER BY sort_order, name';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse($stmt->fetchAll());
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
