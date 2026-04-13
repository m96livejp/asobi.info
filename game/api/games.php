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
    $sql = "SELECT id, platform, slug, title, title_en, genre, developer, publisher, release_year, release_date, price, players, rom_size, box_image, title_image, cart_image FROM games WHERE 1=1";

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
    $publisher = trim($_GET['publisher'] ?? '');
    if ($publisher !== '') {
        $sql .= " AND publisher = ?";
        $params[] = $publisher;
    }
    $genre = trim($_GET['genre'] ?? '');
    if ($genre !== '') {
        $sql .= " AND genre = ?";
        $params[] = $genre;
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

// 注目ゲーム（ピン留め優先 + アクセスログベースの人気ゲーム）
if ($action === 'popular') {
    if (!$platform || !in_array($platform, $VALID_PLATFORMS, true)) gameApiError(400, 'platform required');
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    $db = gameDb();

    // 1) ピン留めゲームを優先取得
    $stmt = $db->prepare("
        SELECT g.id, g.platform, g.slug, g.title, g.title_en, g.genre, g.publisher, g.release_year, g.box_image, g.title_image, 1 as featured
        FROM featured_games f
        JOIN games g ON g.id = f.game_id
        WHERE f.platform = ?
        ORDER BY f.sort_order, f.created_at
    ");
    $stmt->execute([$platform]);
    $featured = $stmt->fetchAll();
    $featuredSlugs = array_column($featured, 'slug');

    $remaining = $limit - count($featured);
    if ($remaining <= 0) {
        gameApiJson(['ok' => true, 'games' => array_slice($featured, 0, $limit), 'source' => 'featured']);
    }

    // 2) アクセスログから人気ゲームを取得（ピン留め分を除く）
    $usersDb = new PDO('sqlite:/opt/asobi/data/users.sqlite');
    $usersDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $usersDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pathPattern = '/' . $platform . '/%.html';
    $stmt = $usersDb->prepare("
        SELECT path, COUNT(*) as access_count, MAX(created_at) as last_access
        FROM access_logs
        WHERE host = 'game.asobi.info' AND path LIKE ?
        GROUP BY path
        ORDER BY access_count DESC, last_access DESC
        LIMIT ?
    ");
    $stmt->execute([$pathPattern, $remaining * 2]);
    $accessData = $stmt->fetchAll();

    $slugs = [];
    $accessMap = [];
    foreach ($accessData as $row) {
        if (preg_match('#^/' . preg_quote($platform, '#') . '/([a-z0-9_\-]+)\.html$#', $row['path'], $m)) {
            if (!in_array($m[1], $featuredSlugs, true)) {
                $slugs[] = $m[1];
                $accessMap[$m[1]] = ['access_count' => (int)$row['access_count'], 'last_access' => $row['last_access']];
            }
        }
    }

    $accessGames = [];
    if (!empty($slugs)) {
        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $stmt = $db->prepare("SELECT id, platform, slug, title, title_en, genre, publisher, release_year, box_image, title_image, cart_image, 0 as featured FROM games WHERE platform = ? AND slug IN ($placeholders)");
        $stmt->execute(array_merge([$platform], $slugs));
        $accessGames = $stmt->fetchAll();

        usort($accessGames, function($a, $b) use ($accessMap) {
            $ca = $accessMap[$a['slug']]['access_count'] ?? 0;
            $cb = $accessMap[$b['slug']]['access_count'] ?? 0;
            return $cb - $ca;
        });
        $accessGames = array_slice($accessGames, 0, $remaining);
        foreach ($accessGames as &$g) {
            $g['access_count'] = $accessMap[$g['slug']]['access_count'] ?? 0;
            $g['last_access'] = $accessMap[$g['slug']]['last_access'] ?? null;
        }
        unset($g);
    }

    // アクセスデータもない場合は最新ゲームで補完
    if (empty($accessGames)) {
        $excludeIds = array_column($featured, 'id');
        $where = "platform = ?";
        $params = [$platform];
        if (!empty($excludeIds)) {
            $where .= " AND id NOT IN (" . implode(',', array_fill(0, count($excludeIds), '?')) . ")";
            $params = array_merge($params, $excludeIds);
        }
        $stmt = $db->prepare("SELECT id, platform, slug, title, title_en, genre, publisher, release_year, box_image, title_image, cart_image, 0 as featured FROM games WHERE $where ORDER BY id DESC LIMIT ?");
        $params[] = $remaining;
        $stmt->execute($params);
        $accessGames = $stmt->fetchAll();
    }

    $games = array_merge($featured, $accessGames);
    gameApiJson(['ok' => true, 'games' => $games, 'source' => count($featured) > 0 ? 'featured+access_log' : 'access_log']);
}

// メーカー一覧（ゲーム数付き）
if ($action === 'publishers') {
    if (!$platform || !in_array($platform, $VALID_PLATFORMS, true)) gameApiError(400, 'platform required');
    $db = gameDb();
    $stmt = $db->prepare("
        SELECT publisher, COUNT(*) as game_count
        FROM games
        WHERE platform = ? AND publisher IS NOT NULL AND publisher != ''
        GROUP BY publisher
        ORDER BY game_count DESC, publisher
    ");
    $stmt->execute([$platform]);
    gameApiJson(['ok' => true, 'publishers' => $stmt->fetchAll()]);
}

// ジャンル一覧（ゲーム数付き）
if ($action === 'genres') {
    if (!$platform || !in_array($platform, $VALID_PLATFORMS, true)) gameApiError(400, 'platform required');
    $db = gameDb();
    $stmt = $db->prepare("
        SELECT genre, COUNT(*) as game_count
        FROM games
        WHERE platform = ? AND genre IS NOT NULL AND genre != ''
        GROUP BY genre
        ORDER BY game_count DESC, genre
    ");
    $stmt->execute([$platform]);
    gameApiJson(['ok' => true, 'genres' => $stmt->fetchAll()]);
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
