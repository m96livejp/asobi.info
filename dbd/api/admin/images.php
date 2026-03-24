<?php
require_once __DIR__ . '/../../admin/auth.php';
require_once __DIR__ . '/../db.php';
requireLogin();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    if ($action === 'scan') {
        // 画像ディレクトリをスキャンしてDBに登録
        $type = $_GET['type'] ?? '';
        $validTypes = ['perks/killer', 'perks/survivor', 'characters/killer', 'characters/survivor', 'offerings', 'addons'];
        if (!in_array($type, $validTypes)) {
            jsonResponse(['error' => 'Invalid type'], 400);
        }

        $imgDir = __DIR__ . '/../../images/' . $type;
        if (!is_dir($imgDir)) {
            jsonResponse(['error' => 'Directory not found'], 404);
        }

        $files = glob($imgDir . '/*.png');
        $added = 0;
        foreach ($files as $file) {
            $filename = basename($file);
            $existing = $db->prepare('SELECT id FROM image_mappings WHERE image_file = ? AND image_type = ?');
            $existing->execute([$filename, $type]);
            if (!$existing->fetch()) {
                $stmt = $db->prepare('INSERT INTO image_mappings (image_file, image_type, status) VALUES (?, ?, ?)');
                $stmt->execute([$filename, $type, 'unmapped']);
                $added++;
            }
        }
        jsonResponse(['added' => $added, 'total' => count($files)]);
    }

    if ($action === 'list') {
        $type = $_GET['type'] ?? '';
        $sql = 'SELECT * FROM image_mappings';
        $params = [];
        if ($type) {
            $sql .= ' WHERE image_type = ?';
            $params[] = $type;
        }
        $sql .= ' ORDER BY image_type, image_file';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        jsonResponse($stmt->fetchAll());
    }

    if ($action === 'candidates') {
        // マッピング候補のDB名を取得
        $type = $_GET['type'] ?? '';
        if (strpos($type, 'perks') !== false) {
            $role = strpos($type, 'killer') !== false ? 'killer' : 'survivor';
            $stmt = $db->prepare('SELECT id, name, name_en, character_name FROM perks WHERE role = ? ORDER BY character_name, name');
            $stmt->execute([$role]);
        } elseif (strpos($type, 'characters/killer') !== false) {
            $stmt = $db->query('SELECT id, name, name_en FROM killers ORDER BY name');
        } elseif (strpos($type, 'characters/survivor') !== false) {
            $stmt = $db->query("SELECT DISTINCT character_name as name FROM perks WHERE role = 'survivor' ORDER BY character_name");
        } elseif ($type === 'addons') {
            $stmt = $db->query('SELECT a.id, a.name, k.name as killer_name, a.rarity FROM addons a JOIN killers k ON a.killer_id = k.id ORDER BY k.name, a.name');
        } elseif ($type === 'offerings') {
            $stmt = $db->query('SELECT id, name, role, rarity FROM offerings ORDER BY name');
        } else {
            jsonResponse([]);
        }
        jsonResponse($stmt->fetchAll());
    }

    if ($method === 'POST' && $action === 'map') {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? 0;
        $mapped_id = $data['mapped_id'] ?? null;
        $mapped_name = $data['mapped_name'] ?? null;

        $stmt = $db->prepare('UPDATE image_mappings SET mapped_id = ?, mapped_name = ?, status = ? WHERE id = ?');
        $stmt->execute([$mapped_id, $mapped_name, 'mapped', $id]);
        jsonResponse(['success' => true]);
    }

    if ($method === 'POST' && $action === 'apply') {
        // マッピングを確定: image_pathをDBに書き込み、ファイルをリネーム
        $type = $_GET['type'] ?? '';
        $stmt = $db->prepare("SELECT * FROM image_mappings WHERE image_type = ? AND status = 'mapped'");
        $stmt->execute([$type]);
        $mappings = $stmt->fetchAll();

        $applied = 0;
        foreach ($mappings as $m) {
            $imgDir = __DIR__ . '/../../images/' . $type . '/';

            if (strpos($type, 'perks') !== false && $m['mapped_id']) {
                // パーク: image_pathを更新
                $newName = $m['image_file']; // keep original for now
                $relativePath = '/images/' . $type . '/' . $newName;
                $upd = $db->prepare('UPDATE perks SET image_path = ? WHERE id = ?');
                $upd->execute([$relativePath, $m['mapped_id']]);
            } elseif (strpos($type, 'characters/killer') !== false && $m['mapped_id']) {
                $relativePath = '/images/' . $type . '/' . $m['image_file'];
                $upd = $db->prepare('UPDATE killers SET image_path = ? WHERE id = ?');
                $upd->execute([$relativePath, $m['mapped_id']]);
            } elseif ($type === 'addons' && $m['mapped_id']) {
                $relativePath = '/images/' . $type . '/' . $m['image_file'];
                $upd = $db->prepare('UPDATE addons SET image_path = ? WHERE id = ?');
                $upd->execute([$relativePath, $m['mapped_id']]);
            } elseif ($type === 'offerings' && $m['mapped_id']) {
                $relativePath = '/images/' . $type . '/' . $m['image_file'];
                $upd = $db->prepare('UPDATE offerings SET image_path = ? WHERE id = ?');
                $upd->execute([$relativePath, $m['mapped_id']]);
            }

            // ステータスを確定済みに
            $upd2 = $db->prepare("UPDATE image_mappings SET status = 'confirmed' WHERE id = ?");
            $upd2->execute([$m['id']]);
            $applied++;
        }
        jsonResponse(['applied' => $applied]);
    }

    if ($method === 'DELETE' && $action === 'delete') {
        $id = $_GET['id'] ?? 0;
        $stmt = $db->prepare('DELETE FROM image_mappings WHERE id = ?');
        $stmt->execute([$id]);
        jsonResponse(['success' => true]);
    }

    jsonResponse(['error' => 'Unknown action'], 400);
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
