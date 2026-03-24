<?php
require_once __DIR__ . '/db.php';

try {
    $db   = getDb();
    $id   = $_GET['id']   ?? null;
    $q    = $_GET['q']    ?? null;
    $type = $_GET['type'] ?? null;

    // 単体取得（id指定）
    if ($id) {
        $stmt = $db->prepare('SELECT * FROM moves WHERE id = ?');
        $stmt->execute([(int)$id]);
        $move = $stmt->fetch();
        if (!$move) jsonResponse(['error' => 'Not found'], 404);

        // 覚えるポケモン一覧
        $stmt2 = $db->prepare('
            SELECT p.pokedex_no, p.name, p.type1, p.type2, p.base_hp, p.base_atk, p.ranged
            FROM pokemon_moves pm
            JOIN pokemon p ON pm.pokemon_id = p.pokedex_no
            WHERE pm.move_id = ?
            ORDER BY p.pokedex_no
        ');
        $stmt2->execute([(int)$id]);
        $move['pokemon'] = $stmt2->fetchAll();

        jsonResponse($move);
    }

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
