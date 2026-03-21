<?php
require_once __DIR__ . '/db.php';

try {
    $db   = getDb();
    $q    = $_GET['q']    ?? null;
    $type = $_GET['type'] ?? null;

    $where  = [];
    $params = [];

    if ($q) {
        $where[]  = '(name LIKE ? OR description LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    if ($type) {
        $where[]  = 'type = ?';
        $params[] = $type;
    }

    $sql = 'SELECT id, name, type, power, wait, description FROM moves';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY id ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse($stmt->fetchAll());
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
