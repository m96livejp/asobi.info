<?php
/**
 * ゲーム情報 API
 * GET ?action=list&platform=nes[&q=keyword&page=1&limit=50]
 * GET ?action=get&platform=nes&slug=contra
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

require_once __DIR__ . '/db.php';

$action   = $_GET['action'] ?? 'list';
$platform = $_GET['platform'] ?? '';
$slug     = $_GET['slug'] ?? '';

if ($action === 'list') {
    $db = gameDb();
    $params = [];
    $sql = "SELECT id, platform, slug, title, title_en, genre, developer, publisher, release_year, release_date, price, players, rom_size, image_path FROM games WHERE 1=1";

    if ($platform && in_array($platform, $VALID_PLATFORMS, true)) {
        $sql .= " AND platform = ?";
        $params[] = $platform;
    }
    $q = trim($_GET['q'] ?? '');
    if ($q !== '') {
        $sql .= " AND (title LIKE ? OR title_en LIKE ? OR title_kana LIKE ?)";
        $like = "%$q%";
        $params = array_merge($params, [$like, $like, $like]);
    }
    $sql .= " ORDER BY platform, sort_order, title";

    $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    $countSql = preg_replace('/^SELECT .+ FROM/', 'SELECT COUNT(*) FROM', $sql);
    $total = (int)$db->prepare($countSql)->execute($params) ? $db->prepare($countSql)->execute($params) : 0;
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $sql .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $games = $stmt->fetchAll();

    gameApiJson(['ok' => true, 'games' => $games, 'total' => $total, 'page' => $page, 'limit' => $limit]);
}

if ($action === 'get') {
    if (!$platform || !$slug) gameApiError(400, 'platform and slug required');
    $db = gameDb();
    $stmt = $db->prepare("SELECT * FROM games WHERE platform = ? AND slug = ?");
    $stmt->execute([$platform, $slug]);
    $game = $stmt->fetch();
    if (!$game) gameApiError(404, 'Game not found');
    gameApiJson(['ok' => true, 'game' => $game]);
}

gameApiError(400, 'Invalid action');
