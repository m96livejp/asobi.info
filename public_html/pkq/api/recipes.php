<?php
require_once __DIR__ . '/db.php';

try {
    $db = getDb();
    $id = $_GET['id'] ?? null;
    $simulate = $_GET['simulate'] ?? null;
    $grouped = $_GET['grouped'] ?? null;

    if ($id) {
        $stmt = $db->prepare('SELECT * FROM recipes WHERE id = ?');
        $stmt->execute([(int)$id]);
        $recipe = $stmt->fetch();
        if (!$recipe) jsonResponse(['error' => 'Not found'], 404);

        // 出現ポケモン
        $stmt2 = $db->prepare('SELECT p.*, rp.rate FROM recipe_pokemon rp JOIN pokemon p ON rp.pokemon_id = p.pokedex_no WHERE rp.recipe_id = ? ORDER BY rp.rate DESC');
        $stmt2->execute([(int)$id]);
        $recipe['pokemon'] = $stmt2->fetchAll();

        // 必要素材
        $stmt3 = $db->prepare('SELECT * FROM recipe_requirements WHERE recipe_id = ?');
        $stmt3->execute([(int)$id]);
        $recipe['requirements'] = $stmt3->fetchAll();

        jsonResponse($recipe);
    }

    // シミュレーション: 素材5つからどの料理になるか
    if ($simulate) {
        $items = json_decode($simulate, true);
        if (!is_array($items) || count($items) !== 5) {
            jsonResponse(['error' => '素材は5つ必要です'], 400);
        }

        // 素材情報を取得
        $placeholders = implode(',', array_fill(0, count($items), '?'));
        $stmt = $db->prepare("SELECT * FROM ingredients WHERE id IN ($placeholders)");
        $stmt->execute($items);
        $ingredients = $stmt->fetchAll();

        // 素材の特性を集計
        $counts = ['color' => [], 'softness' => [], 'size' => [], 'rarity' => []];
        foreach ($ingredients as $ing) {
            foreach (['color', 'softness', 'size', 'rarity'] as $attr) {
                if (!empty($ing[$attr])) {
                    $counts[$attr][$ing[$attr]] = ($counts[$attr][$ing[$attr]] ?? 0) + 1;
                }
            }
        }

        $rareCount = $counts['rarity']['rare'] ?? 0;
        $quality = 'normal';
        if ($rareCount >= 4) $quality = 'special';
        elseif ($rareCount >= 3) $quality = 'very_good';
        elseif ($rareCount >= 1) $quality = 'good';

        jsonResponse([
            'ingredients' => $ingredients,
            'counts' => $counts,
            'quality' => $quality
        ]);
    }

    // グループ化された一覧 (recipe_noでグループ、各品質の出現ポケモン付き)
    if ($grouped) {
        $recipes = $db->query('SELECT * FROM recipes ORDER BY recipe_no, id')->fetchAll();

        // recipe_noごとの素材を一括取得
        $ingMap = [];
        $ingRows = $db->query('
            SELECT ri.recipe_no, i.id, i.name, i.image_path, i.color, ri.condition_group
            FROM recipe_ingredients ri
            JOIN ingredients i ON ri.ingredient_id = i.id
            ORDER BY ri.recipe_no, ri.condition_group, ri.sort_order
        ')->fetchAll();
        foreach ($ingRows as $row) {
            $ingMap[$row['recipe_no']][] = [
                'id'              => $row['id'],
                'name'            => $row['name'],
                'image_path'      => $row['image_path'],
                'color'           => $row['color'],
                'condition_group' => (int)$row['condition_group'],
            ];
        }

        $groups = [];
        foreach ($recipes as $r) {
            $no = $r['recipe_no'];
            if (!isset($groups[$no])) {
                $groups[$no] = [
                    'recipe_no'       => $no,
                    'name'            => $r['name'],
                    'description'     => $r['description'],
                    'cooking_time'    => $r['cooking_time'],
                    'hint'            => $r['hint'],
                    'ingredient_hint' => $r['ingredient_hint'],
                    'pokemon_hint'    => $r['pokemon_hint'],
                    'image_path'      => $r['image_path'],
                    'ingredients'     => $ingMap[$no] ?? [],
                    'qualities'       => []
                ];
            }
            $stmt = $db->prepare('SELECT p.pokedex_no, p.name, p.type1, rp.rate FROM recipe_pokemon rp JOIN pokemon p ON rp.pokemon_id = p.pokedex_no WHERE rp.recipe_id = ? ORDER BY rp.rate DESC LIMIT 6');
            $stmt->execute([(int)$r['id']]);
            $groups[$no]['qualities'][$r['quality']] = [
                'id'      => $r['id'],
                'pokemon' => $stmt->fetchAll()
            ];
        }
        jsonResponse(array_values($groups));
    }

    // 通常一覧
    $stmt = $db->query('SELECT * FROM recipes ORDER BY recipe_no, id');
    jsonResponse($stmt->fetchAll());
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
