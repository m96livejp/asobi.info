<?php
require_once __DIR__ . '/db.php';

try {
    $db = getDb();
    $id     = $_GET['id']   ?? null;
    $search = $_GET['q']    ?? null;
    $type   = $_GET['type'] ?? null;

    // ---- 単体詳細 ----
    if ($id) {
        $stmt = $db->prepare('SELECT p.*, ef.name AS evolution_from_name FROM pokemon p LEFT JOIN pokemon ef ON p.evolution_from = ef.pokedex_no WHERE p.pokedex_no = ?');
        $stmt->execute([(int)$id]);
        $pokemon = $stmt->fetch();
        if (!$pokemon) jsonResponse(['error' => 'Not found'], 404);

        // このポケモンが出る料理（直接）
        $stmt2 = $db->prepare(
            'SELECT DISTINCT r.recipe_no, r.name, r.image_path
             FROM recipe_pokemon rp
             JOIN recipes r ON rp.recipe_id = r.id
             WHERE rp.pokemon_id = ?
             ORDER BY r.recipe_no'
        );
        $stmt2->execute([(int)$id]);
        $directRecipes = $stmt2->fetchAll();

        // 料理がない場合は進化前の料理を取得
        if (empty($directRecipes) && $pokemon['evolution_from']) {
            $stmt3 = $db->prepare(
                'SELECT DISTINCT r.recipe_no, r.name, r.image_path
                 FROM recipe_pokemon rp
                 JOIN recipes r ON rp.recipe_id = r.id
                 WHERE rp.pokemon_id = ?
                 ORDER BY r.recipe_no'
            );
            $stmt3->execute([(int)$pokemon['evolution_from']]);
            $pokemon['recipes']              = $stmt3->fetchAll();
            $pokemon['recipe_from_evolution'] = true;
        } else {
            $pokemon['recipes']              = $directRecipes;
            $pokemon['recipe_from_evolution'] = false;
        }

        // ワザ一覧
        $stmt4 = $db->prepare(
            'SELECT m.* FROM pokemon_moves pm
             JOIN moves m ON pm.move_id = m.id
             WHERE pm.pokemon_id = ?
             ORDER BY pm.id'
        );
        $stmt4->execute([(int)$id]);
        $pokemon['moves'] = $stmt4->fetchAll();

        // 進化先
        $stmt5 = $db->prepare('SELECT pokedex_no, name, evolve_level FROM pokemon WHERE evolution_from = ? ORDER BY pokedex_no');
        $stmt5->execute([(int)$id]);
        $pokemon['evolves_to'] = $stmt5->fetchAll();

        jsonResponse($pokemon);
    }

    // ---- 一覧 ----
    $sql    = 'SELECT p.*, ef.name AS evolution_from_name FROM pokemon p LEFT JOIN pokemon ef ON p.evolution_from = ef.pokedex_no WHERE 1=1';
    $params = [];

    if ($search) {
        $sql .= ' AND p.name LIKE ?';
        $params[] = '%' . $search . '%';
    }
    if ($type) {
        $sql .= ' AND (p.type1 = ? OR p.type2 = ?)';
        $params[] = $type;
        $params[] = $type;
    }

    $sql .= ' ORDER BY p.pokedex_no';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $pokemons = $stmt->fetchAll();

    // 全ポケモンのレシピマッピングを一括取得
    $recipeRows = $db->query(
        'SELECT DISTINCT rp.pokemon_id, r.recipe_no, r.name, r.image_path
         FROM recipe_pokemon rp
         JOIN recipes r ON rp.recipe_id = r.id
         ORDER BY r.recipe_no'
    )->fetchAll();

    $recipeMap = [];
    foreach ($recipeRows as $row) {
        $pid = (int)$row['pokemon_id'];
        $rno = (int)$row['recipe_no'];
        if (!isset($recipeMap[$pid][$rno])) {
            $recipeMap[$pid][$rno] = [
                'recipe_no'  => $rno,
                'name'       => $row['name'],
                'image_path' => $row['image_path'],
            ];
        }
    }

    // 各ポケモンにレシピ情報を付与
    foreach ($pokemons as &$p) {
        $no      = (int)$p['pokedex_no'];
        $recipes = array_values($recipeMap[$no] ?? []);
        if (empty($recipes) && $p['evolution_from']) {
            $parentNo = (int)$p['evolution_from'];
            $p['recipes']               = array_values($recipeMap[$parentNo] ?? []);
            $p['recipe_from_evolution'] = true;
        } else {
            $p['recipes']               = $recipes;
            $p['recipe_from_evolution'] = false;
        }
    }
    unset($p);

    jsonResponse($pokemons);

} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
