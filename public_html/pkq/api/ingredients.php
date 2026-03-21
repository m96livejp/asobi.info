<?php
require_once __DIR__ . '/db.php';

try {
    $db = getDb();
    $stmt = $db->query('SELECT * FROM ingredients ORDER BY sort_order, id');
    jsonResponse($stmt->fetchAll());
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
