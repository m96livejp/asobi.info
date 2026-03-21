<?php
/**
 * 管理画面 AJAX API
 * POST JSON: { action: '...', ...params }
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['action'])) {
    echo json_encode(['ok' => false, 'error' => 'invalid request']);
    exit;
}

$db = getDb();

try {
    switch ($input['action']) {

        // -----------------------------------------------
        // 素材更新
        // -----------------------------------------------
        case 'update_ingredient': {
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('invalid id');

            $stmt = $db->prepare('
                UPDATE ingredients
                SET name=:name, color=:color, category=:category,
                    softness=:softness, size=:size, rarity=:rarity,
                    quality_point=:qp, sort_order=:so
                WHERE id=:id
            ');
            $stmt->execute([
                ':id'       => $id,
                ':name'     => $input['name']          ?? '',
                ':color'    => $input['color']    ?: null,
                ':category' => $input['category'] ?: null,
                ':softness' => $input['softness'] ?: null,
                ':size'     => $input['size']     ?: null,
                ':rarity'   => $input['rarity']        ?? 'common',
                ':qp'       => (int)($input['quality_point'] ?? 1),
                ':so'       => (int)($input['sort_order']    ?? 99),
            ]);
            echo json_encode(['ok' => true]);
            break;
        }

        // -----------------------------------------------
        // 料理更新（recipe_noに紐づく全行を更新）
        // -----------------------------------------------
        case 'update_recipe': {
            $no = (int)($input['recipe_no'] ?? 0);
            if (!$no) throw new Exception('invalid recipe_no');

            $stmt = $db->prepare('
                UPDATE recipes
                SET name=:name, ingredient_hint=:ih, pokemon_hint=:ph, image_path=:ip
                WHERE recipe_no=:no
            ');
            $stmt->execute([
                ':no'   => $no,
                ':name' => $input['name']            ?? '',
                ':ih'   => $input['ingredient_hint'] ?: null,
                ':ph'   => $input['pokemon_hint']    ?: null,
                ':ip'   => $input['image_path']      ?: null,
            ]);
            echo json_encode(['ok' => true]);
            break;
        }

        // -----------------------------------------------
        // ポケモン更新
        // -----------------------------------------------
        case 'update_pokemon': {
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('invalid id');

            $stmt = $db->prepare('
                UPDATE pokemon
                SET name=:name, type1=:t1, type2=:t2,
                    base_hp=:hp, base_atk=:atk
                WHERE id=:id
            ');
            $stmt->execute([
                ':id'  => $id,
                ':name'=> $input['name']  ?? '',
                ':t1'  => $input['type1'] ?? '',
                ':t2'  => $input['type2'] ?: null,
                ':hp'  => $input['base_hp']  !== '' ? (int)$input['base_hp']  : null,
                ':atk' => $input['base_atk'] !== '' ? (int)$input['base_atk'] : null,
            ]);
            echo json_encode(['ok' => true]);
            break;
        }

        default:
            throw new Exception('unknown action');
    }
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
