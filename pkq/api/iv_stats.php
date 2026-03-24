<?php
/**
 * iv_stats.php - 個体値観測データAPI
 * 指定ポケモン・鍋・レベル付近の観測HP/ATK範囲を品質ごとに返す
 *
 * GET /api/iv_stats.php?pokemon_id=150&pot_type=金&level=100
 */
require_once __DIR__ . '/db.php';

$pokemonId = isset($_GET['pokemon_id']) ? (int)$_GET['pokemon_id'] : 0;
$potType   = isset($_GET['pot_type'])   ? trim($_GET['pot_type'])   : '';
$level     = isset($_GET['level'])      ? (int)$_GET['level']       : 100;

if ($pokemonId <= 0 || $potType === '') {
    jsonResponse(['error' => 'pokemon_id and pot_type are required'], 400);
}

$level = max(1, min(100, $level));
$lvMin = max(1,   $level - 10);
$lvMax = min(100, $level + 10);

$qualities = ['スペシャル', 'すごくいい', 'いい', 'ふつう'];

$pdo = getDb();
$stmt = $pdo->prepare(
    'SELECT quality,
            MIN(hp)    AS hp_min,
            MAX(hp)    AS hp_max,
            MIN(atk)   AS atk_min,
            MAX(atk)   AS atk_max,
            COUNT(*)   AS cnt
     FROM iv_reports
     WHERE pokemon_id = ?
       AND pot_type   = ?
       AND level BETWEEN ? AND ?
     GROUP BY quality'
);
$stmt->execute([$pokemonId, $potType, $lvMin, $lvMax]);
$rows = $stmt->fetchAll();

// quality をキーにしたマップ
$map = [];
foreach ($rows as $r) {
    $map[$r['quality']] = $r;
}

$result = [];
foreach ($qualities as $q) {
    if (isset($map[$q])) {
        $r = $map[$q];
        $result[] = [
            'quality' => $q,
            'hp_min'  => (int)$r['hp_min'],
            'hp_max'  => (int)$r['hp_max'],
            'atk_min' => (int)$r['atk_min'],
            'atk_max' => (int)$r['atk_max'],
            'count'   => (int)$r['cnt'],
        ];
    } else {
        $result[] = [
            'quality' => $q,
            'hp_min'  => null,
            'hp_max'  => null,
            'atk_min' => null,
            'atk_max' => null,
            'count'   => 0,
        ];
    }
}

jsonResponse([
    'pokemon_id' => $pokemonId,
    'pot_type'   => $potType,
    'level_range'=> ['min' => $lvMin, 'max' => $lvMax],
    'data'       => $result,
]);
