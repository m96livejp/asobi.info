<?php
// 共通認証（ログインチェックのみ・必須ではない）
$isLoggedIn  = false;
$sessionUserId   = null;
$sessionUsername = null;
try {
    require_once '/opt/asobi/shared/assets/php/auth.php';
    if (!empty($_SESSION['asobi_user_id'])) {
        $isLoggedIn      = true;
        $sessionUserId   = (int)$_SESSION['asobi_user_id'];
        $sessionUsername = $_SESSION['asobi_user_name'] ?? $_SESSION['asobi_user_username'] ?? 'ユーザー';
    }
} catch (Exception $e) {}

// DB接続
require_once __DIR__ . '/api/db.php';
$db = getDb();

// テーブル作成（初回のみ）
$db->exec("
    CREATE TABLE IF NOT EXISTS iv_reports (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        pokemon_id INTEGER NOT NULL,
        pot_type   TEXT    NOT NULL,
        quality    TEXT    NOT NULL,
        level      INTEGER NOT NULL DEFAULT 100,
        hp         INTEGER NOT NULL,
        atk        INTEGER NOT NULL,
        user_id    INTEGER,
        username   TEXT,
        ip         TEXT,
        memo       TEXT    NOT NULL DEFAULT '',
        move1      TEXT    NOT NULL DEFAULT '',
        move2      TEXT    NOT NULL DEFAULT '',
        pcharm     TEXT    NOT NULL DEFAULT '',
        created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (pokemon_id) REFERENCES pokemon(pokedex_no)
    )
");
// 既存テーブルへの新カラム追加（初回のみ）
foreach (['move1', 'move2', 'pcharm', 'recipe_name'] as $col) {
    try { $db->exec("ALTER TABLE iv_reports ADD COLUMN {$col} TEXT NOT NULL DEFAULT ''"); } catch(Exception $e) {}
}
try { $db->exec("ALTER TABLE iv_reports ADD COLUMN move2_slot INTEGER NOT NULL DEFAULT 2"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE iv_reports ADD COLUMN is_shiny  INTEGER NOT NULL DEFAULT 0"); } catch(Exception $e) {}

// クライアントIP取得
function getClientIp(): string {
    foreach (['HTTP_X_FORWARDED_FOR','HTTP_CLIENT_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            return explode(',', $_SERVER[$k])[0];
        }
    }
    return 'unknown';
}

$msg = '';
$msg_type = '';
$inserted_id = null;

// ---- 編集対象レコードの所有確認 ----
$isAdmin = (($_SESSION['asobi_user_role'] ?? '') === 'admin');
function canEditReport($rec, bool $isLoggedIn, ?int $sessionUserId, string $myIp, bool $isAdmin = false): bool {
    if (!$rec) return false;
    if ($isAdmin) return true;
    if ($isLoggedIn && (int)$rec['user_id'] === $sessionUserId) return true;
    if (!$isLoggedIn && !$rec['user_id'] && $rec['ip'] === $myIp) return true;
    return false;
}

$editMode   = false;
$editRecord = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $db->prepare('SELECT * FROM iv_reports WHERE id = ?');
    $stmt->execute([$editId]);
    $rec = $stmt->fetch();
    if (canEditReport($rec, $isLoggedIn, $sessionUserId, getClientIp(), $isAdmin)) {
        $editMode   = true;
        $editRecord = $rec;
    } else {
        $msg = 'この投稿を編集する権限がありません。';
        $msg_type = 'error';
    }
}
// 進化コピー（level/hp/atk を空にして新規登録）
if (!$editRecord && isset($_GET['copy'])) {
    $copyId = (int)$_GET['copy'];
    $stmt = $db->prepare('SELECT * FROM iv_reports WHERE id = ?');
    $stmt->execute([$copyId]);
    $rec = $stmt->fetch();
    if ($rec) {
        $editRecord = $rec;
        $editRecord['id']    = null;  // 新規扱い
        $editRecord['level'] = '';
        $editRecord['hp']    = '';
        $editRecord['atk']   = '';
        // 進化先があれば pokemon_id を上書き
        $evoStmt = $db->prepare('SELECT pokedex_no FROM pokemon WHERE evolution_from = ? LIMIT 1');
        $evoStmt->execute([$rec['pokemon_id']]);
        $evo = $evoStmt->fetch();
        if ($evo) {
            $editRecord['pokemon_id'] = $evo['pokedex_no'];
        }
    }
}

// ---- 削除処理 ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $delId  = (int)($_POST['edit_id'] ?? 0);
    $stmt   = $db->prepare('SELECT * FROM iv_reports WHERE id = ?');
    $stmt->execute([$delId]);
    $rec    = $stmt->fetch();
    if (canEditReport($rec, $isLoggedIn, $sessionUserId, getClientIp(), $isAdmin)) {
        $db->prepare('DELETE FROM iv_reports WHERE id = ?')->execute([$delId]);
        header('Location: /iv-report-list.php?deleted=1');
        exit;
    } else {
        $msg = 'この投稿を削除する権限がありません。';
        $msg_type = 'error';
    }
}

// ---- 更新処理 ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $editId     = (int)($_POST['edit_id'] ?? 0);
    $stmt       = $db->prepare('SELECT * FROM iv_reports WHERE id = ?');
    $stmt->execute([$editId]);
    $rec        = $stmt->fetch();

    $pokemon_id = (int)($_POST['pokemon_id'] ?? 0);
    $pot_type   = trim($_POST['pot_type']  ?? '') ?: 'なし';
    $quality    = trim($_POST['quality']   ?? '');
    $level      = (int)($_POST['level']    ?? 100);
    $hp         = (int)($_POST['hp']       ?? 0);
    $atk        = (int)($_POST['atk']      ?? 0);
    $memo        = mb_substr(trim($_POST['memo']        ?? ''), 0, 200);
    $move1       = mb_substr(trim($_POST['move1']       ?? ''), 0, 50);
    $move2       = mb_substr(trim($_POST['move2']       ?? ''), 0, 50);
    $pcharm      = mb_substr(trim($_POST['pcharm']      ?? ''), 0, 100);
    $recipe_name = mb_substr(trim($_POST['recipe_name'] ?? ''), 0, 60);
    $move2_slot  = max(1, min(3, (int)($_POST['move2_slot'] ?? 2)));
    $is_shiny    = isset($_POST['is_shiny']) ? 1 : 0;

    if (!canEditReport($rec, $isLoggedIn, $sessionUserId, getClientIp(), $isAdmin)) {
        $msg = 'この投稿を編集する権限がありません。';
        $msg_type = 'error';
    } elseif ($memo !== '' && function_exists('asobiCheckBanned') && asobiCheckBanned($memo, 'content')['blocked']) {
        $msg = 'メモに使用できない言葉が含まれています';
        $msg_type = 'error';
        $editMode   = true;
        $editRecord = $rec;
    } elseif ($pokemon_id > 0
        && in_array($pot_type, ['鉄','銅','銀','金','なし'])
        && ($quality === '' || in_array($quality, ['ふつう','いい','すごくいい','スペシャル']))
        && $level >= 1 && $level <= 100
        && $hp > 0 && $atk > 0
    ) {
        $stmt2 = $db->prepare('UPDATE iv_reports
            SET pokemon_id=?, pot_type=?, quality=?, level=?, hp=?, atk=?, memo=?, move1=?, move2=?, pcharm=?, recipe_name=?, move2_slot=?, is_shiny=?
            WHERE id=?');
        $stmt2->execute([$pokemon_id, $pot_type, $quality, $level, $hp, $atk, $memo, $move1, $move2, $pcharm, $recipe_name, $move2_slot, $is_shiny, $editId]);
        header('Location: /iv-report-list.php?updated=1');
        exit;
    } else {
        $errors = [];
        if ($pokemon_id <= 0) $errors[] = 'ポケモン';
        if (!in_array($pot_type, ['鉄','銅','銀','金','なし'])) $errors[] = "鍋({$pot_type})";
        if ($quality !== '' && !in_array($quality, ['ふつう','いい','すごくいい','スペシャル'])) $errors[] = "品質({$quality})";
        if ($level < 1 || $level > 100) $errors[] = "レベル({$level})";
        if ($hp <= 0) $errors[] = "HP({$hp})";
        if ($atk <= 0) $errors[] = "ATK({$atk})";
        $msg = '入力内容が不正です: ' . implode(', ', $errors);
        $msg_type = 'error';
        $editMode   = true;
        $editRecord = $rec;
    }
}

// ---- クイック登録（個体値チェッカーから即時DB登録→編集画面へ） ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_add') {
    $pokemon_id = (int)($_POST['pokemon_id'] ?? 0);
    $pot_type   = trim($_POST['pot_type']  ?? '') ?: 'なし';
    $quality    = trim($_POST['quality']   ?? '');
    $level      = (int)($_POST['level']    ?? 100);
    $hp         = (int)($_POST['hp']       ?? 0);
    $atk        = (int)($_POST['atk']      ?? 0);

    $valid_pots    = ['鉄','銅','銀','金','なし'];
    $valid_quality = ['ふつう','いい','すごくいい','スペシャル',''];

    if ($pokemon_id > 0
        && in_array($pot_type, $valid_pots)
        && in_array($quality, $valid_quality)
        && $level >= 1 && $level <= 100
        && $hp > 0 && $atk > 0
    ) {
        $stmt = $db->prepare('INSERT INTO iv_reports
            (pokemon_id, pot_type, quality, level, hp, atk, user_id, username, ip, memo, move1, move2, pcharm, recipe_name, move2_slot, is_shiny)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $pokemon_id, $pot_type, $quality, $level, $hp, $atk,
            $isLoggedIn ? $sessionUserId   : null,
            $isLoggedIn ? $sessionUsername : null,
            getClientIp(),
            '', '', '', '', '', 2, 0,
        ]);
        $newId = $db->lastInsertId();
        header('Location: /iv-report.php?edit=' . $newId);
        exit;
    }
    header('Location: /iv-checker.html');
    exit;
}

// ---- 登録処理 ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $pokemon_id = (int)($_POST['pokemon_id'] ?? 0);
    $pot_type   = trim($_POST['pot_type']  ?? '') ?: 'なし';
    $quality    = trim($_POST['quality']   ?? '');
    $level      = (int)($_POST['level']    ?? 100);
    $hp         = (int)($_POST['hp']       ?? 0);
    $atk        = (int)($_POST['atk']      ?? 0);
    $memo        = mb_substr(trim($_POST['memo']        ?? ''), 0, 200);
    $move1       = mb_substr(trim($_POST['move1']       ?? ''), 0, 50);
    $move2       = mb_substr(trim($_POST['move2']       ?? ''), 0, 50);
    $pcharm      = mb_substr(trim($_POST['pcharm']      ?? ''), 0, 100);
    $recipe_name = mb_substr(trim($_POST['recipe_name'] ?? ''), 0, 60);
    $move2_slot  = max(1, min(3, (int)($_POST['move2_slot'] ?? 2)));
    $is_shiny    = isset($_POST['is_shiny']) ? 1 : 0;

    $valid_pots     = ['鉄','銅','銀','金','なし'];
    $valid_quality  = ['ふつう','いい','すごくいい','スペシャル',''];

    if ($memo !== '' && function_exists('asobiCheckBanned') && asobiCheckBanned($memo, 'content')['blocked']) {
        $msg = 'メモに使用できない言葉が含まれています';
        $msg_type = 'error';
    } elseif ($pokemon_id > 0
        && in_array($pot_type, $valid_pots)
        && in_array($quality,  $valid_quality)
        && $level >= 1 && $level <= 100
        && $hp > 0 && $atk > 0
    ) {
        $stmt = $db->prepare('INSERT INTO iv_reports
            (pokemon_id, pot_type, quality, level, hp, atk, user_id, username, ip, memo, move1, move2, pcharm, recipe_name, move2_slot, is_shiny)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $pokemon_id,
            $pot_type,
            $quality,
            $level,
            $hp,
            $atk,
            $isLoggedIn ? $sessionUserId   : null,
            $isLoggedIn ? $sessionUsername : null,
            getClientIp(),
            $memo,
            $move1,
            $move2,
            $pcharm,
            $recipe_name,
            $move2_slot,
            $is_shiny,
        ]);
        header('Location: /iv-report-list.php?registered=1');
        exit;
    } else {
        $msg = '入力内容が不正です。ポケモン・鍋・品質・HP・ATKをすべて正しく入力してください。';
        $msg_type = 'error';
    }
}

// ---- フォーム値ヘルパー（POST > editRecord > GET > デフォルト）----
function fv(string $key, $default = '') {
    if (isset($_POST[$key])) return $_POST[$key];
    // editRecord はグローバル
    global $editRecord;
    if ($editRecord && isset($editRecord[$key])) return $editRecord[$key];
    if (isset($_GET[$key])) return $_GET[$key];
    return $default;
}

// ---- ポケモン一覧 ----
$pokemon_list = $db->query('SELECT pokedex_no, name, base_hp, base_atk, ranged, type1, type2 FROM pokemon ORDER BY pokedex_no')->fetchAll();

// ---- レシピ一覧（18種）----
$recipes_ui = $db->query(
    'SELECT name, image_path FROM recipes GROUP BY name ORDER BY MIN(id)'
)->fetchAll();

// ---- 技一覧（タイプ付き）----
$moves_raw = $db->query('SELECT name, type FROM moves')->fetchAll();
$moves_list   = [];
$move_type_map = [];
foreach ($moves_raw as $m) {
    $moves_list[]              = $m['name'];
    $move_type_map[$m['name']] = $m['type'] ?? '';
}
// ひらがな・カタカナを区別せず50音順に並べ替え
usort($moves_list, function($a, $b) {
    // カタカナ→ひらがなに統一して比較
    $ha = mb_convert_kana($a, 'c', 'UTF-8');
    $hb = mb_convert_kana($b, 'c', 'UTF-8');
    return strcmp($ha, $hb);
});

// ---- 集計：ポケモン別報告数 ----
$stats = $db->query("
    SELECT p.name, p.pokedex_no, COUNT(*) as cnt
    FROM iv_reports r
    JOIN pokemon p ON r.pokemon_id = p.pokedex_no
    GROUP BY r.pokemon_id ORDER BY cnt DESC LIMIT 10
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>料理結果投稿 - ポケモンクエスト情報</title>
  <meta name="description" content="料理の結果（鍋・品質・Lv・HP・ATK）を投稿してデータ収集に協力しよう。">
  <link rel="stylesheet" href="https://asobi.info/assets/css/common.css">
  <link rel="stylesheet" href="/css/style.css">
  <link rel="stylesheet" href="https://asobi.info/assets/css/font.php">
  <link rel="stylesheet" href="https://asobi.info/assets/css/font.php">
  <style>
    .report-card {
      background: var(--bg-secondary);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 20px;
      margin-bottom: 16px;
    }
    .section-title {
      font-size: 0.95rem;
      font-weight: 700;
      color: var(--accent);
      margin: 0 0 14px;
      padding-bottom: 8px;
      border-bottom: 1px solid var(--border);
    }
    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }
    .form-group { margin-bottom: 10px; }
    .form-label {
      display: block;
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--text-primary);
      margin-bottom: 6px;
    }
    .form-control {
      width: 100%;
      padding: 9px 12px;
      border: 2px solid var(--border);
      border-radius: 8px;
      font-size: 16px;
      background: var(--bg-primary);
      color: var(--text-primary);
      outline: none;
      box-sizing: border-box;
      transition: border-color 0.2s;
      font-family: inherit;
    }
    .form-control:focus { border-color: var(--accent); }
    #pokemon-search::placeholder { color: var(--text-secondary); opacity: 0.55; }
    select.form-control { cursor: pointer; }

    /* 鍋ボタン */
    .pot-selector { display: grid; grid-template-columns: repeat(5, minmax(min-content, 1fr)); gap: 8px; }
    .pot-btn {
      white-space: nowrap;
      padding: 8px 4px;
      border: 2px solid var(--pot-color, var(--border));
      border-radius: 8px;
      background: var(--pot-color, var(--bg-primary));
      cursor: pointer;
      text-align: center;
      font-size: 0.82rem;
      font-weight: 700;
      color: #fff;
      transition: opacity 0.15s, box-shadow 0.15s;
      opacity: 0.7;
    }
    .pot-btn:hover { opacity: 1; }
    .pot-selector.has-selection .pot-btn { opacity: 0.3; filter: grayscale(0.4); }
    .pot-selector.has-selection .pot-btn.active-pot {
      opacity: 1; filter: none;
      box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--pot-color);
    }

    /* スライダー */
    #slider-section { user-select: none; -webkit-user-select: none; }
    .slider-tooltip {
      display: none;
      position: absolute;
      top: -48px;
      background: #333;
      color: #fff;
      font-size: 1.1rem;
      font-weight: 700;
      padding: 3px 8px;
      border-radius: 4px;
      white-space: nowrap;
      pointer-events: none;
      z-index: 10;
      transform: translateX(-50%);
    }
    .slider-tooltip::after {
      content: '';
      position: absolute;
      bottom: -4px;
      left: 50%;
      transform: translateX(-50%);
      border-left: 4px solid transparent;
      border-right: 4px solid transparent;
      border-top: 4px solid #333;
    }
    #slider-section input[type="range"] {
      -webkit-appearance: none;
      appearance: none;
      height: 6px;
      border-radius: 3px;
      background: var(--border);
      outline: none;
      cursor: pointer;
      margin: 8px 0;
      touch-action: none;
    }
    #slider-section input[type="range"]::-webkit-slider-runnable-track {
      height: 6px;
      border-radius: 3px;
    }
    #slider-section .slider-labels {
      position: relative;
      height: 18px;
      margin-top: -9px;
      pointer-events: none;
    }
    #slider-section .slider-labels .tick-label,
    #slider-section .slider-labels .tick-only {
      position: absolute;
      transform: translateX(-50%);
      font-size: 0.6rem;
      color: var(--text-secondary);
      top: 0;
      pointer-events: none;
    }
    #slider-section .slider-labels .tick-line {
      position: absolute;
      top: -5px;
      left: 50%;
      transform: translateX(-50%);
      background: var(--text-secondary);
      pointer-events: none;
    }
    #slider-section .slider-labels .tick-only .tick-line {
      top: -4px;
      opacity: 0.4;
    }
    #slider-section .slider-labels .tick-only {
      font-size: 0;
      height: 0;
    }
    #slider-section input[type="range"]::-webkit-slider-thumb {
      -webkit-appearance: none;
      appearance: none;
      width: 20px;
      height: 18px;
      border-radius: 0;
      background: var(--accent);
      cursor: pointer;
      border: none;
      box-shadow: none;
      clip-path: polygon(0 0, 100% 0, 50% 100%);
      margin-top: -22px;
    }
    #slider-section input[type="range"]:disabled {
      opacity: 0.3;
      cursor: not-allowed;
    }
    #slider-section input[type="range"]:disabled::-webkit-slider-thumb {
      background: #999;
    }
    #slider-section input[type="range"]#hp-slider { background: rgba(231,76,60,0.2); }
    #slider-section input[type="range"]#hp-slider::-webkit-slider-thumb { background: #e74c3c; }
    #slider-section input[type="range"]#atk-slider { background: rgba(52,152,219,0.2); }
    #slider-section input[type="range"]#atk-slider::-webkit-slider-thumb { background: #3498db; }
    #slider-section input[type="range"]#level-slider { background: rgba(225,112,85,0.2); accent-color: #e17055; }
    #slider-section input[type="range"]#level-slider::-webkit-slider-thumb { background: #e17055; }

    /* 品質ボタン */
    .quality-selector { display: flex; gap: 8px; }
    .quality-btn {
      flex: 1 1 0;
      min-width: 80px;
      white-space: nowrap;
      padding: 8px 6px;
      font-size: 0.82rem;
      border: 2px solid var(--border);
      border-radius: 8px;
      background: var(--bg-primary);
      cursor: pointer;
      text-align: center;
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--text-secondary);
      transition: all 0.15s;
    }
    .quality-btn:hover { border-color: var(--accent); }
    .quality-btn.active {
      color: #fff;
      border-color: var(--q-color, var(--accent));
      background: var(--q-color, var(--accent));
    }
    .quality-selector.disabled .quality-btn {
      opacity: 0.3; cursor: not-allowed; pointer-events: none;
    }
    @media (max-width: 480px) {
      .pot-selector     { grid-template-columns: repeat(3, 1fr); }
      .quality-selector { grid-template-columns: repeat(2, 1fr); }
    }

    .submit-btn {
      width: 100%;
      padding: 12px;
      background: var(--accent);
      color: #fff;
      border: none;
      border-radius: 8px;
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
      transition: opacity 0.15s;
      font-family: inherit;
    }
    .submit-btn:hover { opacity: 0.85; }

    /* ログイン案内 */
    .login-note {
      font-size: 0.82rem;
      color: var(--text-secondary);
      padding: 8px 12px;
      background: var(--bg-primary);
      border-radius: 6px;
      border-left: 3px solid var(--border);
      margin-bottom: 14px;
    }
    .login-note a { color: var(--accent); }

    /* アラート */
    .alert-success {
      background: rgba(39,174,96,0.12);
      color: #1a6b3a;
      border: 1px solid #27ae60;
      border-radius: 8px;
      padding: 12px 16px;
      margin-bottom: 16px;
      font-weight: 600;
    }
    .alert-error {
      background: rgba(231,76,60,0.1);
      color: #c0392b;
      border: 1px solid #e74c3c;
      border-radius: 8px;
      padding: 12px 16px;
      margin-bottom: 16px;
    }

    /* 投稿一覧テーブル */
    .report-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.85rem;
    }
    .report-table th {
      text-align: left;
      padding: 8px 10px;
      color: var(--text-secondary);
      font-weight: 600;
      border-bottom: 2px solid var(--border);
      font-size: 0.78rem;
      white-space: nowrap;
    }
    .report-table td {
      padding: 8px 10px;
      border-bottom: 1px solid var(--border);
      vertical-align: middle;
    }
    .report-table tr:last-child td { border-bottom: none; }
    .report-table tr:hover td { background: var(--bg-primary); }

    .quality-badge {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 10px;
      font-size: 0.75rem;
      font-weight: 600;
      color: #fff;
    }
    .pot-badge {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 10px;
      font-size: 0.75rem;
      font-weight: 600;
      color: #fff;
    }
    .reporter-name {
      font-size: 0.78rem;
      color: var(--text-secondary);
      display: flex;
      align-items: center;
      gap: 4px;
    }
    .reporter-name .icon { font-size: 0.9rem; }

    /* 統計 */
    .stats-list {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }
    .stats-item {
      display: flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      background: var(--bg-primary);
      border-radius: 16px;
      font-size: 0.82rem;
    }
    .stats-item .cnt {
      font-weight: 700;
      color: var(--accent);
    }

    /* ステータスカード */
    .stat-card {
      background: #f5c842;
      border-radius: 16px;
      padding: 12px;
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 12px;
      position: relative;
    }
    .stat-card-icon {
      width: 72px; height: 72px;
      background: #ddd;
      border-radius: 12px;
      border: 2px solid rgba(0,0,0,0.12);
      flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-size: 2.2rem; overflow: hidden;
    }
    .stat-card-body { flex: 1; min-width: 0; }
    .stat-card-name-row {
      display: flex; align-items: flex-start; gap: 6px; margin-bottom: 8px;
      min-height: 42px;
    }
    .stat-card-name-wrap {
      flex: 1; min-width: 0;
      text-align: center;
      padding-bottom: 4px;
      border-bottom: 1.5px solid #c0570a;
    }
    .stat-card-name {
      font-size: 1.25rem; font-weight: 800;
      color: #333;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .stat-card-types {
      flex-shrink: 0;
      display: flex; flex-direction: column; gap: 3px;
      align-items: flex-end;
      justify-content: flex-start;
      height: 42px;
      overflow: hidden;
    }
    .stat-type-badge {
      display: inline-block;
      border-radius: 5px;
      padding: 1px 6px;
      font-size: 0.65rem; font-weight: 700;
      color: #fff; white-space: nowrap;
      min-width: 40px; text-align: center;
    }
    .stat-card-rows { display: flex; align-items: stretch; gap: 8px; }
    .lv-box {
      background: #e67e22;
      border-radius: 8px;
      padding: 4px 7px;
      text-align: center;
      flex-shrink: 0;
      display: flex; flex-direction: column; align-items: center; justify-content: center;
    }
    .lv-label {
      font-size: 0.6rem; font-weight: 700;
      color: rgba(255,255,255,0.85); display: block;
    }
    .lv-box input {
      width: 56px; background: #fff; border: none;
      border-radius: 5px; color: #333; font-size: 1.15rem; font-weight: 800;
      text-align: center; outline: none; padding: 2px 3px;
      -moz-appearance: textfield;
    }
    .lv-box input::-webkit-inner-spin-button,
    .lv-box input::-webkit-outer-spin-button { -webkit-appearance: none; }
    .lv-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 3px; }
    .lv-btn {
      padding: 3px 7px;
      border: 1.5px solid rgba(0,0,0,0.2);
      border-radius: 5px;
      background: rgba(255,255,255,0.2);
      color: rgba(0,0,0,0.6); font-size: 0.72rem; font-weight: 700;
      cursor: pointer;
    }
    .lv-btn:hover { background: rgba(255,255,255,0.35); }
    .stat-hp-atk { display: flex; flex-direction: column; gap: 5px; flex: 1; }
    .hp-box, .atk-box {
      display: flex; align-items: center; gap: 6px;
      border-radius: 8px; padding: 5px 10px; flex: 1;
    }
    .hp-box  { background: #2980b9; }
    .atk-box { background: #e74c3c; }
    .stat-icon-label {
      display: flex; align-items: center; gap: 3px;
      color: #fff; font-size: 0.78rem; font-weight: 700;
      width: 46px; flex-shrink: 0;
    }
    .hp-box input, .atk-box input {
      flex: none; width: 72px; background: #fff; border: none;
      border-radius: 6px; color: #333;
      font-size: 1.1rem; font-weight: 700;
      text-align: right; padding: 3px 8px;
      outline: none; box-sizing: border-box;
      -moz-appearance: textfield;
      font-family: 'Courier New', monospace;
      font-variant-numeric: tabular-nums;
    }
    .hp-box input::-webkit-inner-spin-button,
    .hp-box input::-webkit-outer-spin-button,
    .atk-box input::-webkit-inner-spin-button,
    .atk-box input::-webkit-outer-spin-button { -webkit-appearance: none; }
    .hp-box input::placeholder, .atk-box input::placeholder {
      color: rgba(0,0,0,0.15);
    }
    .lv-box input::placeholder { color: rgba(0,0,0,0.15); }
    .shiny-corner {
      position: absolute; bottom: 10px; right: 10px;
      width: 30px; height: 30px; border-radius: 50%;
      background: rgba(255,255,255,0.35);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.2rem; line-height: 1;
    }
    .shiny-star { visibility: hidden; color: #fff; }
    .shiny-star.show { visibility: visible; }
    .shiny-check-row {
      display: flex; align-items: center; gap: 8px;
      margin-bottom: 14px;
    }
    .shiny-check-row input[type=checkbox] {
      width: 18px; height: 18px; cursor: pointer; accent-color: #e91e8c;
    }
    .shiny-check-row label { cursor: pointer; font-size: 0.9rem; }

    /* タイプフィルター */
    .type-filter-wrap {
      margin-bottom: 4px;
    }
    .type-filter-label {
      font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 2px;
    }
    .type-filter-btns {
      display: flex; flex-wrap: wrap; gap: 4px;
    }
    .type-filter-btn {
      padding: 3px 8px;
      border-radius: 12px;
      border: none;
      font-size: 0.75rem; font-weight: 600;
      cursor: pointer; color: #fff;
      transition: box-shadow 0.15s;
    }
    .type-filter-btn:hover { opacity: 0.85; }
    .type-filter-btn.active { box-shadow: 0 0 0 2px #fff, 0 0 0 4px rgba(0,0,0,0.6); }

    /* 料理アイコン選択 */
    .recipe-icons {
      display: grid;
      grid-template-columns: repeat(6, 1fr);
      gap: 6px;
      margin-bottom: 10px;
    }
    .recipe-icon-btn {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 3px;
      padding: 6px 4px;
      border: 2px solid var(--border);
      border-radius: 8px;
      background: var(--bg-primary);
      cursor: pointer;
      transition: border-color 0.15s, background 0.15s;
    }
    .recipe-icon-btn:hover { border-color: var(--accent); }
    .recipe-icon-btn.selected {
      border-color: var(--accent);
      background: rgba(231,76,60,0.12);
    }
    .recipe-icon-btn img {
      width: 40px;
      height: 40px;
      object-fit: contain;
    }
    .recipe-icon-btn span {
      font-size: 0.6rem;
      color: var(--text-secondary);
      text-align: center;
      line-height: 1.2;
      word-break: break-all;
    }
    @media (max-width: 480px) {
      .recipe-icons { grid-template-columns: repeat(4, 1fr); }
      .recipe-icon-btn img { width: 32px; height: 32px; }
    }

    /* 技スロット */
    .move-slots-wrap {
      margin: 0;
    }
    .move-slots-label {
      font-size: 0.78rem;
      color: var(--text-secondary);
      margin-bottom: 2px;
    }
    .move-slots-row {
      display: flex;
      align-items: center;
      gap: 0;
      height: 32px;
    }
    .mslot {
      width: 28px;
      height: 28px;
      border-radius: 6px;
      border: 2px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.1rem;
      color: var(--text-secondary);
      background: var(--bg-primary);
      transition: all 0.2s;
      flex-shrink: 0;
    }
    .mslot.filled {
      font-size: 1.2rem;
    }
    .mslot.ext {
      background: rgba(255,255,255,0.07);
      color: var(--text-secondary);
    }
    .mconn {
      width: 16px;
      text-align: center;
      font-size: 1rem;
      color: var(--text-secondary);
      flex-shrink: 0;
      transition: opacity 0.2s;
    }
    .mconn.hidden-conn { opacity: 0; }
    .mconn.gap-conn { opacity: 0; }

    /* Pチャームグリッド */
    .pcharm-grid {
      display: grid;
      grid-template-columns: repeat(3, 56px);
      gap: 6px;
    }
    .pcharm-cell {
      width: 56px;
      height: 56px;
      border-radius: 10px;
      border: 2px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.3rem;
      cursor: pointer;
      user-select: none;
      transition: transform 0.1s, box-shadow 0.1s;
      background: var(--bg-primary);
      color: var(--text-secondary);
      line-height: 1;
    }
    .pcharm-cell:active { transform: scale(0.92); }
    .pcharm-cell.state-atk  { background: #e74c3c; border-color: #c0392b; color: #fff; }
    .pcharm-cell.state-hp   { background: #2980b9; border-color: #1f6fa3; color: #fff; }
    .pcharm-cell.state-both {
      background: linear-gradient(135deg, #e74c3c 50%, #2980b9 50%);
      border-color: #c0392b;
      color: #fff;
      font-size: 1rem;
    }

    @media (max-width: 600px) {
      .form-row { grid-template-columns: 1fr; }
    }

    /* 削除確認モーダル */
    .modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.55);
      z-index: 1000;
      align-items: center;
      justify-content: center;
    }
    .modal-overlay.active { display: flex; }
    .modal-box {
      background: var(--bg-secondary);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 28px 24px;
      width: min(340px, 90vw);
      text-align: center;
      box-shadow: 0 8px 32px rgba(0,0,0,0.4);
    }
    .modal-box h3 {
      margin: 0 0 10px;
      font-size: 1.05rem;
    }
    .modal-box p {
      font-size: 0.88rem;
      color: var(--text-secondary);
      margin: 0 0 20px;
    }
    .modal-btns {
      display: flex;
      gap: 10px;
    }
    .modal-btns button {
      flex: 1;
      padding: 10px;
      border-radius: 8px;
      font-size: 0.9rem;
      font-weight: 700;
      cursor: pointer;
      border: none;
      font-family: inherit;
    }
    .modal-cancel { background: var(--bg-primary); color: var(--text-primary); border: 2px solid var(--border) !important; }
    .modal-delete { background: #e74c3c; color: #fff; }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="container">
      <a href="/" class="site-logo">ポケクエ<span>.asobi.info</span></a>
      <button class="menu-toggle">&#9776;</button>
            <nav class="site-nav">
        <ul>
          <li><a href="/pokemon-list.html">全ポケモン一覧</a></li>
          <li><a href="/moves.html">全わざ一覧</a></li>
          <li><a href="/recipes.html">全料理一覧</a></li>
          <li><a href="/simulator.html">料理シミュレーター</a></li>
          <li><a href="/iv-checker.html">個体値チェッカー</a></li>
          <li><a href="/iv-report.php" class="active">料理結果投稿</a></li>
          <li><a href="/iv-report-list.php">料理結果一覧</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main class="container">
    <div class="page-header">
      <div class="breadcrumb"><a href="/">ポケクエ</a><span>料理結果投稿</span></div>
      <h1>料理結果投稿</h1>
      <p>呼び出したポケモンのレベル・HP・ATKを投稿してデータ収集に協力しよう！</p>
    </div>
    <div style="margin-bottom:16px;text-align:right;">
      <a href="/iv-report-list.php" style="color:var(--accent);font-size:0.88rem;text-decoration:none;">🕐 料理結果一覧を見る →</a>
    </div>

    <?php if ($msg): ?>
    <div class="alert-<?= $msg_type === 'success' ? 'success' : 'error' ?>">
      <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <div>

      <!-- 投稿フォーム -->
      <div>
        <div class="report-card">
          <p class="section-title"><?= $editMode ? '✏️ 投稿を編集する' : '📝 データを投稿する' ?></p>
          <?php if ($editMode): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
            <span style="font-size:0.83rem;color:var(--text-secondary);">投稿ID: #<?= $editRecord['id'] ?></span>
            <a href="/iv-report-list.php" style="font-size:0.83rem;color:var(--text-secondary);text-decoration:none;">← 一覧に戻る</a>
          </div>
          <?php endif; ?>

          <?php if ($isLoggedIn): ?>
          <div class="login-note">
            <span>👤</span> <strong><?= htmlspecialchars($sessionUsername) ?></strong> としてログイン中。投稿にユーザー名が紐づきます。
          </div>
          <?php else: ?>
          <div class="login-note">
            現在ゲストとして投稿されます。
            <a href="https://asobi.info/login.php?back=<?= urlencode('https://pkq.asobi.info/iv-report.php') ?>">ログイン</a>するとユーザー名で投稿できます。
          </div>
          <?php endif; ?>

          <form method="POST" id="report-main-form">
            <input type="hidden" name="action" value="<?= $editMode ? 'update' : 'add' ?>">
            <?php if ($editMode): ?>
            <input type="hidden" name="edit_id" value="<?= $editRecord['id'] ?>">
            <?php endif; ?>
            <input type="hidden" name="pot_type"  id="pot_type_hidden"  value="<?= htmlspecialchars(fv('pot_type')) ?>">
            <input type="hidden" name="quality"   id="quality_hidden"   value="<?= htmlspecialchars(fv('quality')) ?>">

            <div class="form-group">
              <label class="form-label">料理名（任意）</label>
              <select id="recipe_name_sel" class="form-control" onchange="onRecipeSelectChange()" style="margin-bottom:8px;">
                <option value="">-- 未選択 --</option>
                <?php foreach ($recipes_ui as $rec): ?>
                <option value="<?= htmlspecialchars($rec['name']) ?>"
                  <?= fv('recipe_name') === $rec['name'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($rec['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <div class="recipe-icons" id="recipe-icon-grid">
                <?php foreach ($recipes_ui as $rec): ?>
                <button type="button" class="recipe-icon-btn"
                  data-name="<?= htmlspecialchars($rec['name']) ?>"
                  onclick="selectRecipe(this)">
                  <img src="/images/recipes/<?= htmlspecialchars($rec['image_path']) ?>"
                       alt="<?= htmlspecialchars($rec['name']) ?>"
                       onerror="this.style.display='none'">
                  <span><?= htmlspecialchars($rec['name']) ?></span>
                </button>
                <?php endforeach; ?>
              </div>
              <input type="hidden" name="recipe_name" id="recipe_name_hidden" value="<?= htmlspecialchars(fv('recipe_name')) ?>">
            </div>

            <?php
            $pot_colors = ['なし'=>'#546e7a','鉄'=>'#777','銅'=>'#cc8844','銀'=>'#9e9e9e','金'=>'#d4af37'];
            $currentPot = fv('pot_type');
            ?>
            <div style="display:flex; gap:16px; flex-wrap:wrap;">
              <div class="form-group" style="flex:2; min-width:280px;">
                <label class="form-label">鍋の種類</label>
                <div class="pot-selector <?= $currentPot ? 'has-selection' : '' ?>" id="pot-selector">
                  <?php foreach ($pot_colors as $pot => $pc): ?>
                  <button type="button"
                    class="pot-btn <?= $currentPot === $pot ? 'active-pot' : '' ?>"
                    data-pot="<?= $pot ?>"
                    style="--pot-color:<?= $pc ?>;"
                    onclick="selectPot('<?= $pot ?>')">
                    <?= $pot === 'なし' ? 'なし' : $pot . 'の鍋' ?>
                  </button>
                  <?php endforeach; ?>
                </div>
                <div id="pot-error" style="display:none; font-size:0.78rem; color:#e74c3c; margin-top:4px;">鍋の種類を選択してください。</div>
              </div>

              <div class="form-group" style="flex:1; min-width:360px;">
                <label class="form-label">品質</label>
                <div class="quality-selector" id="quality-selector">
                  <?php
                  $q_colors = ['ふつう'=>'#95a5a6','いい'=>'#27ae60','すごくいい'=>'#2980b9','スペシャル'=>'#8e44ad'];
                  foreach ($q_colors as $q => $qc):
                    $isActive = isset($_POST['quality']) && $_POST['quality'] === $q;
                  ?>
                  <button type="button"
                    class="quality-btn <?= $isActive ? 'active' : '' ?>"
                    style="--q-color: <?= $qc ?>;"
                    onclick="selectQuality('<?= $q ?>', '<?= $qc ?>')"><?= $q ?></button>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">ポケモン <span style="color:var(--accent);">*</span></label>
              <div class="type-filter-wrap" style="margin-bottom:6px;">
                <div class="type-filter-label">タイプで絞り込み（2つまで選択可）</div>
                <div class="type-filter-btns" id="pokemon-type-filter">
                  <button type="button" class="type-filter-btn active" data-type="" style="background:#555;" onclick="clickPokemonTypeBtn(this)">すべて</button>
                  <?php foreach ([
                    'ノーマル'=>'#9e9e9e','ほのお'=>'#e74c3c','みず'=>'#2980b9','くさ'=>'#27ae60',
                    'でんき'=>'#f1c40f','エスパー'=>'#e91e8c','かくとう'=>'#c03028','いわ'=>'#8d6e63',
                    'じめん'=>'#e0c068','ひこう'=>'#90caf9','こおり'=>'#55d1e8','むし'=>'#8bc34a','どく'=>'#9c27b0',
                    'ゴースト'=>'#705898','ドラゴン'=>'#3949ab','あく'=>'#546e7a','はがね'=>'#90a4ae',
                    'フェアリー'=>'#f48fb1'
                  ] as $t => $tc): ?>
                  <button type="button" class="type-filter-btn" data-type="<?= $t ?>"
                    style="background:<?= $tc ?>;"
                    onclick="clickPokemonTypeBtn(this)"><?= $t ?></button>
                  <?php endforeach; ?>
                </div>
              </div>
              <div style="display:flex; gap:6px; align-items:flex-start;">
                <select name="pokemon_id" id="pokemon-select" class="form-control" required style="flex:1; min-width:0;" size="3">
                  <option value="">-- 選択してください --</option>
                  <?php foreach ($pokemon_list as $p): ?>
                  <option value="<?= $p['pokedex_no'] ?>"
                    <?php
                      $preId = (int)fv('pokemon_id', isset($_GET['pokemon_id']) ? (int)$_GET['pokemon_id'] : 0);
                      echo ($preId === (int)$p['pokedex_no']) ? 'selected' : '';
                    ?>>
                    No.<?= str_pad($p['pokedex_no'], 3, '0', STR_PAD_LEFT) ?> <?= htmlspecialchars($p['name']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
                <div style="position:relative; flex:1; min-width:0;">
                  <input type="text" id="pokemon-search" class="form-control" lang="ja"
                    placeholder="名前検索" oninput="onPokemonSearchInput();toggleVoiceClear('pokemon-search')"
                    style="width:100%; padding-right:28px; color:var(--text-primary); ime-mode:active;">
                  <button type="button" id="pokemon-search-mic" onclick="startVoice('pokemon-search', event)"
                    style="position:absolute; right:5px; top:50%; transform:translateY(-50%);
                           background:none; border:none; cursor:pointer; color:var(--text-secondary);
                           font-size:0.9rem; line-height:1; padding:2px;" title="音声入力">🎤</button>
                  <button type="button" id="pokemon-search-clear"
                    onclick="clearPokemonSearch();toggleVoiceClear('pokemon-search')"
                    style="display:none; position:absolute; right:5px; top:50%; transform:translateY(-50%);
                           background:none; border:none; cursor:pointer; color:var(--text-secondary);
                           font-size:0.9rem; line-height:1; padding:2px;">×</button>
                </div>
                <div style="position:relative; width:130px; flex-shrink:0;">
                  <input type="text" id="pokemon-no-search" class="form-control"
                    placeholder="No.検索" oninput="onPokemonSearchInput();toggleVoiceClear('pokemon-no-search')"
                    style="width:100%; padding-right:28px; color:var(--text-primary);">
                  <button type="button" id="pokemon-no-search-mic" onclick="startVoice('pokemon-no-search', event)"
                    style="position:absolute; right:5px; top:50%; transform:translateY(-50%);
                           background:none; border:none; cursor:pointer; color:var(--text-secondary);
                           font-size:0.9rem; line-height:1; padding:2px;" title="音声入力">🎤</button>
                  <button type="button" id="pokemon-no-search-clear"
                    onclick="document.getElementById('pokemon-no-search').value='';onPokemonSearchInput();toggleVoiceClear('pokemon-no-search')"
                    style="display:none; position:absolute; right:5px; top:50%; transform:translateY(-50%);
                           background:none; border:none; cursor:pointer; color:var(--text-secondary);
                           font-size:0.9rem; line-height:1; padding:2px;">×</button>
                </div>
              </div>
            </div>

            <!-- ステータスカード -->
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
              <span style="font-size:0.85rem;font-weight:600;color:var(--text-secondary);">レベル / HP / ATK <span style="color:var(--accent);">*</span></span>
              <span style="color:#c0392b;font-size:0.72rem;font-weight:700;">⚠️ Pストーンをすべて外した状態で入力</span>
            </div>
            <div style="display:flex; align-items: flex-start; gap: 12px; margin-bottom: 14px; flex-wrap:wrap;">
              <div class="stat-card" style="margin-bottom:0; width:fit-content; flex-shrink:0;">
                <div class="stat-card-icon" id="stat-pokemon-icon"><img id="stat-pokemon-img" src="/images/pokemon/000.png" alt="" style="width:64px;height:64px;object-fit:contain;"></div>
                <div class="stat-card-body">
                  <div class="stat-card-name-row">
                    <div class="stat-card-name-wrap">
                      <div class="stat-card-name" id="stat-pokemon-name">ポケモンを選択してください</div>
                    </div>
                    <div class="stat-card-types" id="stat-pokemon-types"></div>
                  </div>
                  <div class="stat-card-rows">
                    <div style="display:flex; align-items:center; gap:4px;">
                      <div class="lv-box">
                        <span class="lv-label">Lv.</span>
                        <input type="text" name="level" id="level-input" required
                          value="<?= htmlspecialchars(fv('level', '')) ?>" placeholder="100"
                          pattern="[0-9]*" maxlength="3"
                          oninput="this.value=this.value.replace(/[^0-9]/g,'');updateSliders()"
                          onblur="if(this.value){let v=Math.min(100,Math.max(1,parseInt(this.value)||1));this.value=v;updateSliders()}">
                      </div>
                    </div>
                    <div class="stat-hp-atk">
                      <div class="hp-box">
                        <div class="stat-icon-label">❤ HP</div>
                        <input type="text" name="hp" id="hp-input" required
                          value="<?= htmlspecialchars(fv('hp')) ?>"
                          placeholder="500" pattern="[0-9]*" maxlength="4"
                          oninput="this.value=this.value.replace(/[^0-9]/g,'');syncSliderFromInput('hp')">
                      </div>
                      <div class="atk-box">
                        <div class="stat-icon-label">⚡ ATK</div>
                        <input type="text" name="atk" id="atk-input" required
                          value="<?= htmlspecialchars(fv('atk')) ?>"
                          placeholder="500" pattern="[0-9]*" maxlength="4"
                          oninput="this.value=this.value.replace(/[^0-9]/g,'');syncSliderFromInput('atk')">
                      </div>
                    </div>
                    <div style="display:flex; flex-direction:column; align-items:center; justify-content:space-between; min-width:24px; padding:4px 0;">
                      <span id="ranged-icon" style="font-size:1rem; visibility:hidden;"></span>
                      <span class="shiny-star" id="shiny-star" style="font-size:1.4rem;">★</span>
                    </div>
                  </div>
                </div>
              </div>
              <div style="display:flex; flex-direction:column; justify-content:flex-start; gap:8px; align-self:stretch; flex:1; min-width:200px; max-width:550px;">
                <div id="slider-section" style="display:flex;flex-direction:column;gap:2px;padding-top:10px;overflow:visible;">
                  <div style="display:flex;align-items:center;gap:4px;">
                    <span style="font-size:0.75rem;color:var(--text-secondary);width:22px;text-align:right;">Lv</span>
                    <button type="button" class="lv-btn" onclick="changeLevel(-10)" style="font-size:0.65rem;padding:2px 4px;">-10</button>
                    <button type="button" class="lv-btn" onclick="changeLevel(-1)" style="font-size:0.65rem;padding:2px 4px;">-1</button>
                    <div style="flex:1;position:relative;">
                      <div class="slider-tooltip" id="level-slider-tip"></div>
                      <input type="range" id="level-slider" min="1" max="100" value="<?= htmlspecialchars(fv('level', '50')) ?>"
                        list="lv-ticks" style="width:100%;"
                        oninput="document.getElementById('level-input').value=this.value;updateSliders();showSliderTip(this)"
                        onfocus="showSliderTip(this)" onblur="hideSliderTip(this)"
                        >
                      <datalist id="lv-ticks"></datalist>
                      <div id="lv-tick-labels" class="slider-labels">
                      </div>
                    </div>
                    <button type="button" class="lv-btn" onclick="changeLevel(1)" style="font-size:0.65rem;padding:2px 4px;">+1</button>
                    <button type="button" class="lv-btn" onclick="changeLevel(10)" style="font-size:0.65rem;padding:2px 4px;">+10</button>
                  </div>
                  <div style="display:flex;align-items:center;gap:4px;">
                    <span style="font-size:0.75rem;color:#e74c3c;width:22px;text-align:right;">HP</span>
                    <button type="button" class="lv-btn" onclick="changeStat('hp',-10)" style="font-size:0.65rem;padding:2px 4px;">-10</button>
                    <button type="button" class="lv-btn" onclick="changeStat('hp',-1)" style="font-size:0.65rem;padding:2px 4px;">-1</button>
                    <div style="flex:1;position:relative;">
                      <div class="slider-tooltip" id="hp-slider-tip"></div>
                      <input type="range" id="hp-slider" min="0" max="1000" value="500" step="1"
                        list="hp-ticks" style="width:100%;" disabled
                        oninput="document.getElementById('hp-input').value=this.value;showSliderTip(this)"
                        onfocus="showSliderTip(this)" onblur="hideSliderTip(this)"
                        >
                      <datalist id="hp-ticks"></datalist>
                      <div id="hp-tick-labels" class="slider-labels">
                        <span>—</span><span>—</span><span>—</span>
                      </div>
                    </div>
                    <button type="button" class="lv-btn" onclick="changeStat('hp',1)" style="font-size:0.65rem;padding:2px 4px;">+1</button>
                    <button type="button" class="lv-btn" onclick="changeStat('hp',10)" style="font-size:0.65rem;padding:2px 4px;">+10</button>
                  </div>
                  <div style="display:flex;align-items:center;gap:4px;">
                    <span style="font-size:0.75rem;color:#3498db;width:22px;text-align:right;">ATK</span>
                    <button type="button" class="lv-btn" onclick="changeStat('atk',-10)" style="font-size:0.65rem;padding:2px 4px;">-10</button>
                    <button type="button" class="lv-btn" onclick="changeStat('atk',-1)" style="font-size:0.65rem;padding:2px 4px;">-1</button>
                    <div style="flex:1;position:relative;">
                      <div class="slider-tooltip" id="atk-slider-tip"></div>
                      <input type="range" id="atk-slider" min="0" max="1000" value="500" step="1"
                        list="atk-ticks" style="width:100%;" disabled
                        oninput="document.getElementById('atk-input').value=this.value;showSliderTip(this)"
                        onfocus="showSliderTip(this)" onblur="hideSliderTip(this)"
                        >
                      <datalist id="atk-ticks"></datalist>
                      <div id="atk-tick-labels" class="slider-labels">
                        <span>—</span><span>—</span><span>—</span>
                      </div>
                    </div>
                    <button type="button" class="lv-btn" onclick="changeStat('atk',1)" style="font-size:0.65rem;padding:2px 4px;">+1</button>
                    <button type="button" class="lv-btn" onclick="changeStat('atk',10)" style="font-size:0.65rem;padding:2px 4px;">+10</button>
                  </div>
                </div>
                <div style="flex:1;"></div>
                <div class="shiny-check-row" style="margin-top:6px;">
                  <input type="checkbox" id="is-shiny-cb" name="is_shiny"
                    <?= fv('is_shiny', '0') == '1' ? 'checked' : '' ?>
                    onchange="toggleShiny(this.checked)">
                  <label for="is-shiny-cb">色違い</label>
                </div>
              </div>
            </div>

            <?php
              // 技の復元：リスト外の値は「その他」扱い
              foreach ([1,2] as $n) {
                $key = 'move'.$n;
                $v = fv($key);
                ${"move{$n}_val"}     = $v;
                ${"move{$n}_inList"}  = in_array($v, $moves_list);
                ${"move{$n}_isOther"} = ($v !== '' && !${"move{$n}_inList"});
              }
            ?>
            <div class="form-row">
              <?php foreach ([1,2] as $n):
                $val     = ${"move{$n}_val"};
                $inList  = ${"move{$n}_inList"};
                $isOther = ${"move{$n}_isOther"};
              ?>
              <div class="form-group">
                <label class="form-label">初期わざ<?= $n ?>（任意）</label>
                <div class="type-filter-wrap">
                  <div class="type-filter-label">タイプで絞り込み</div>
                  <div class="type-filter-btns" id="move<?= $n ?>-type-filter">
                    <button type="button" class="type-filter-btn active" data-type="" style="background:#555;" onclick="filterMoveType(<?= $n ?>, this)">すべて</button>
                    <?php foreach ([
                      'ノーマル'=>'#9e9e9e','ほのお'=>'#e74c3c','みず'=>'#2980b9','くさ'=>'#27ae60',
                      'でんき'=>'#f1c40f','エスパー'=>'#e91e8c','かくとう'=>'#c03028','いわ'=>'#8d6e63',
                      'じめん'=>'#e0c068','ひこう'=>'#90caf9','こおり'=>'#55d1e8','むし'=>'#8bc34a','どく'=>'#9c27b0',
                      'ゴースト'=>'#705898','ドラゴン'=>'#3949ab','あく'=>'#546e7a','はがね'=>'#90a4ae',
                      'フェアリー'=>'#f48fb1'
                    ] as $t => $tc): ?>
                    <button type="button" class="type-filter-btn" data-type="<?= $t ?>"
                      style="background:<?= $tc ?>;"
                      onclick="filterMoveType(<?= $n ?>, this)"><?= $t ?></button>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div style="display:flex; gap:6px; align-items:center; margin-top:2px;">
                  <select id="move<?= $n ?>_sel" class="form-control" style="flex:1; min-width:0;"
                          onchange="onMoveChange(<?= $n ?>)">
                    <option value="">-- 未選択 --</option>
                    <?php foreach ($moves_list as $m): ?>
                    <option value="<?= htmlspecialchars($m) ?>"
                      <?= (!$isOther && $val === $m) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($m) ?>
                    </option>
                    <?php endforeach; ?>
                    <option value="__other" <?= $isOther ? 'selected' : '' ?>>その他（手入力）</option>
                  </select>
                  <div style="position:relative; flex:1; min-width:0;">
                    <input type="text" id="move<?= $n ?>_search" class="form-control" lang="ja"
                      placeholder="わざ名検索"
                      oninput="onMoveSearchInput(<?= $n ?>)"
                      style="width:100%; padding-right:48px; ime-mode:active;">
                    <button type="button" onclick="startVoice('move<?= $n ?>_search', event)"
                      style="position:absolute; right:24px; top:50%; transform:translateY(-50%);
                             background:none; border:none; cursor:pointer; color:var(--text-secondary);
                             font-size:0.9rem; line-height:1; padding:2px;" title="音声入力">🎤</button>
                    <button type="button" id="move<?= $n ?>_search_clear"
                      onclick="clearMoveSearch(<?= $n ?>)"
                      style="display:none; position:absolute; right:5px; top:50%; transform:translateY(-50%);
                             background:none; border:none; cursor:pointer; color:var(--text-secondary);
                             font-size:0.9rem; line-height:1; padding:2px;">×</button>
                  </div>
                </div>
                <input type="text" id="move<?= $n ?>_txt" class="form-control"
                       placeholder="わざ名を入力"
                       value="<?= $isOther ? htmlspecialchars($val) : '' ?>"
                       style="margin-top:6px;display:<?= $isOther ? 'block' : 'none' ?>;">
                <input type="hidden" name="move<?= $n ?>" id="move<?= $n ?>_hidden"
                       value="<?= htmlspecialchars($val) ?>">
              </div>
              <?php endforeach; ?>
            </div>

            <!-- スロット表示 -->
            <div class="move-slots-wrap">
              <div class="move-slots-label">スロット（スロットをタップしてわざの位置を選択できます）</div>
              <div class="move-slots-row">
                <div class="mslot" id="mslot-0">◇</div>
                <div class="mconn" id="mconn-01">　</div>
                <div class="mslot" id="mslot-1" onclick="clickSlot(1)" style="cursor:pointer;">◇</div>
                <div class="mconn" id="mconn-12">　</div>
                <div class="mslot" id="mslot-2" onclick="clickSlot(2)" style="cursor:pointer;">◇</div>
                <div class="mconn" id="mconn-23">　</div>
                <div class="mslot" id="mslot-3" onclick="clickSlot(3)" style="cursor:pointer;">◇</div>
              </div>
              <div id="slot-prompt" style="display:none;font-size:0.78rem;color:var(--accent);margin-top:2px;font-weight:600;">
                ▲ 2つ目のわざのスロット位置を選択してください
              </div>
              <input type="hidden" name="move2_slot" id="move2_slot_hidden" value="<?= empty(fv('move2')) ? '0' : htmlspecialchars(fv('move2_slot', '0')) ?>">
            </div>

            <div class="form-group">
              <label class="form-label">Pチャーム（任意）</label>
              <p style="font-size:0.8rem;color:var(--text-secondary);margin:0 0 10px;">
                各マスをタップして切り替え：
                <span style="display:inline-flex;align-items:center;gap:4px;margin:0 6px;">
                  <span style="display:inline-block;width:18px;height:18px;border-radius:4px;background:#e74c3c;"></span>✊ ATK
                </span>
                <span style="display:inline-flex;align-items:center;gap:4px;margin:0 6px;">
                  <span style="display:inline-block;width:18px;height:18px;border-radius:4px;background:#2980b9;"></span>❤ HP
                </span>
                <span style="display:inline-flex;align-items:center;gap:4px;margin:0 6px;">
                  <span style="display:inline-block;width:18px;height:18px;border-radius:4px;background:linear-gradient(135deg,#e74c3c 50%,#2980b9 50%);"></span>両方
                </span>
              </p>
              <div class="pcharm-grid" id="pcharm-grid">
                <?php for ($i = 0; $i < 9; $i++): ?>
                <div class="pcharm-cell" id="charm-<?= $i ?>" onclick="cycleCharm(<?= $i ?>)">？</div>
                <?php endfor; ?>
              </div>
              <input type="hidden" name="pcharm" id="pcharm_hidden" value="<?= htmlspecialchars(fv('pcharm')) ?>">
            </div>

            <div class="form-group">
              <label class="form-label">メモ（任意）</label>
              <input type="text" name="memo" class="form-control" maxlength="200"
                value="<?= htmlspecialchars(fv('memo')) ?>"
                placeholder="例: 登録時のメモ、このポケモンを手に入れた感想など">
            </div>

            <button type="submit" class="submit-btn" id="submit-btn"><?= $editMode ? '更新する' : '投稿する' ?></button>
            <script>
            (function(){
              // 二重送信防止
              var submitted = false;
              document.getElementById('report-main-form').addEventListener('submit', function(e) {
                if (submitted) { e.preventDefault(); return; }
                // rangeチェックでpreventDefaultされた場合はsubmittedにしない
                setTimeout(function() {
                  if (!e.defaultPrevented) {
                    submitted = true;
                    document.getElementById('submit-btn').disabled = true;
                    document.getElementById('submit-btn').textContent = '送信中...';
                  }
                }, 0);
              });
              const POT_RANGES = [
                { min: 0, max: 10 }, { min: 50, max: 100 },
                { min: 150, max: 250 }, { min: 300, max: 400 },
              ];
              function inRange(v) { return POT_RANGES.some(r => v >= r.min && v <= r.max); }
              let rangeWarned = false;
              const form = document.getElementById('report-main-form');
              const btn  = document.getElementById('submit-btn');
              form.addEventListener('submit', function(e) {
                if (rangeWarned) { rangeWarned = false; return; }
                const pokeSel = document.getElementById('pokemon-select');
                const hp  = parseInt(document.getElementById('hp-input').value) || 0;
                const atk = parseInt(document.getElementById('atk-input').value) || 0;
                const lv  = parseInt(document.getElementById('level-input').value) || 0;
                const poke = pokemonData.find(p => String(p.no) === pokeSel.value);
                if (!poke || !hp || !atk || !lv) return;
                const hpBonus  = hp - poke.base_hp - lv;
                const atkBonus = atk - poke.base_atk - lv;
                const hpOk  = inRange(hpBonus);
                const atkOk = inRange(atkBonus);
                if (!hpOk || !atkOk) {
                  e.preventDefault();
                  const msgs = [];
                  if (!hpOk)  msgs.push('HP(' + hpBonus + ')');
                  if (!atkOk) msgs.push('ATK(' + atkBonus + ')');
                  btn.setCustomValidity(msgs.join('・') + 'が通常の範囲外です。確認のうえもう一度押すと投稿できます。');
                  btn.reportValidity();
                  rangeWarned = true;
                  btn.addEventListener('click', function clearValidity() { btn.setCustomValidity(''); btn.removeEventListener('click', clearValidity); }, { once: true });
                }
              });
              ['hp-input','atk-input','level-input','pokemon-select'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.addEventListener('input', function(){ rangeWarned = false; });
                if (el) el.addEventListener('change', function(){ rangeWarned = false; });
              });
            })();
            </script>
            <?php if ($editMode): ?>
            <button type="button" onclick="showDeleteModal()"
              style="width:100%;margin-top:10px;padding:10px;background:transparent;color:#e74c3c;border:2px solid #e74c3c;border-radius:8px;font-size:0.9rem;font-weight:700;cursor:pointer;font-family:inherit;">
              この投稿を削除する
            </button>
            <?php endif; ?>
          </form>

          <?php if ($editMode): ?>
          <form id="delete-form" method="POST">
            <input type="hidden" name="action"  value="delete">
            <input type="hidden" name="edit_id" value="<?= $editRecord['id'] ?>">
          </form>
          <?php endif; ?>
        </div>

        <!-- 投稿が多いポケモン TOP10 -->
        <?php if (!empty($stats)): ?>
        <div class="report-card">
          <p class="section-title">📊 投稿数ランキング</p>
          <div class="stats-list">
            <?php foreach ($stats as $s): ?>
            <div class="stats-item">
              <span>No.<?= str_pad($s['pokedex_no'], 3, '0', STR_PAD_LEFT) ?> <?= htmlspecialchars($s['name']) ?></span>
              <span class="cnt"><?= $s['cnt'] ?>件</span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <div style="text-align:right;margin-top:8px;">
        <a href="/iv-report-list.php" style="color:var(--accent);font-size:0.88rem;text-decoration:none;">🕐 料理結果一覧を見る →</a>
      </div>

    </div>

    <!-- 説明 -->
    <div class="report-card" style="margin-top:8px;">
      <p class="section-title">ℹ️ このページについて</p>
      <div style="font-size:0.88rem;color:var(--text-secondary);line-height:1.9;">
        <p>料理で呼び出したポケモンのステータスデータを収集しています。</p>
        <p>集まったデータは <a href="/iv-checker.html" style="color:var(--accent);">個体値チェッカー</a> の精度向上に活用されます。</p>
        <p style="margin-top:8px;">投稿者情報を記録しています。同一ポケモン・同一条件での複数投稿は可能です。</p>
      </div>
    </div>
  <!-- Amazon アフィリエイト スライダー -->
  <div class="container">
    <div class="amazon-slider" id="amz-slider">
      <div class="amazon-slider-track" id="amz-track">
        <a class="amazon-slide" href="https://www.amazon.co.jp/s?k=switch2+%E3%83%9D%E3%82%B1%E3%83%A2%E3%83%B3&tag=asobi-jp-22" target="_blank" rel="noopener sponsored">
          <span class="amazon-pr">PR</span>
          <span class="amazon-slide-icon">🎮</span>
          <span class="amazon-slide-body"><div class="amazon-slide-title">Nintendo Switch 2 × ポケモン</div><div class="amazon-slide-sub">Amazonで関連商品を見る</div></span>
          <span class="amazon-slide-arrow">›</span>
        </a>
        <a class="amazon-slide" href="https://www.amazon.co.jp/s?k=%E3%83%9D%E3%82%B1%E3%83%A2%E3%83%B3%E3%82%AB%E3%83%BC%E3%83%89%E3%82%B2%E3%83%BC%E3%83%A0&tag=asobi-jp-22" target="_blank" rel="noopener sponsored">
          <span class="amazon-pr">PR</span>
          <span class="amazon-slide-icon">🃏</span>
          <span class="amazon-slide-body"><div class="amazon-slide-title">ポケモンカードゲーム</div><div class="amazon-slide-sub">Amazonで関連商品を見る</div></span>
          <span class="amazon-slide-arrow">›</span>
        </a>
        <a class="amazon-slide" href="https://www.amazon.co.jp/s?k=Nintendo+Switch+2&tag=asobi-jp-22" target="_blank" rel="noopener sponsored">
          <span class="amazon-pr">PR</span>
          <span class="amazon-slide-icon">🕹️</span>
          <span class="amazon-slide-body"><div class="amazon-slide-title">Nintendo Switch 2 本体</div><div class="amazon-slide-sub">Amazonで関連商品を見る</div></span>
          <span class="amazon-slide-arrow">›</span>
        </a>
        <a class="amazon-slide" href="https://www.amazon.co.jp/s?k=%E3%83%9D%E3%82%B1%E3%83%A2%E3%83%B3+%E3%82%B0%E3%83%83%E3%82%BA&tag=asobi-jp-22" target="_blank" rel="noopener sponsored">
          <span class="amazon-pr">PR</span>
          <span class="amazon-slide-icon">🧸</span>
          <span class="amazon-slide-body"><div class="amazon-slide-title">ポケモングッズ</div><div class="amazon-slide-sub">Amazonで関連商品を見る</div></span>
          <span class="amazon-slide-arrow">›</span>
        </a>
      </div>
      <div class="amazon-slider-dots" id="amz-dots">
        <span class="active" onclick="amzGoTo(0)"></span>
        <span onclick="amzGoTo(1)"></span>
        <span onclick="amzGoTo(2)"></span>
        <span onclick="amzGoTo(3)"></span>
      </div>
    </div>
  </div>
  <script>
  (function(){
    var idx=0, total=4, timer;
    function amzGoTo(n){
      idx=n;
      document.getElementById("amz-track").style.transform="translateX(-"+idx*100+"%)";
      document.querySelectorAll("#amz-dots span").forEach(function(d,i){d.classList.toggle("active",i===idx);});
    }
    window.amzGoTo=amzGoTo;
    function amzNext(){ amzGoTo((idx+1)%total); }
    timer=setInterval(amzNext,10000);
    document.getElementById("amz-slider").addEventListener("mouseenter",function(){clearInterval(timer);});
    document.getElementById("amz-slider").addEventListener("mouseleave",function(){timer=setInterval(amzNext,10000);});
  })();
  </script>  </main>

  <!-- 削除確認モーダル -->
  <div class="modal-overlay" id="delete-modal" onclick="hideDeleteModal(event)">
    <div class="modal-box">
      <h3>投稿を削除しますか？</h3>
      <p>この操作は元に戻せません。</p>
      <div class="modal-btns">
        <button class="modal-cancel" onclick="hideDeleteModal()">キャンセル</button>
        <button class="modal-delete" onclick="document.getElementById('delete-form').submit()">削除する</button>
      </div>
    </div>
  </div>

  <footer class="site-footer">
    <div class="container">
      <p>&copy; 2026 あそび - ポケモンクエスト情報サイト</p>
    </div>
  </footer>

  <script src="https://asobi.info/assets/js/common.js"></script>
  <script>
  // 選択状態の初期化
  const initPot     = <?= json_encode(fv('pot_type')) ?>;
  const initQuality = <?= json_encode(fv('quality')) ?>;
  const initQColors = {
    'ふつう': '#95a5a6', 'いい': '#27ae60',
    'すごくいい': '#2980b9', 'スペシャル': '#8e44ad'
  };
  // 編集時の初期化（toggleを発動させずにUIだけ復元）
  if (initPot) {
    document.getElementById('pot-selector').classList.add('has-selection');
    document.querySelectorAll('.pot-btn').forEach(b => {
      b.classList.toggle('active-pot', b.dataset.pot === initPot);
    });
    // 鍋「なし」でも品質選択は有効のまま
  }
  if (initQuality) {
    const color = initQColors[initQuality] || '#888';
    document.querySelectorAll('.quality-btn').forEach(b => {
      const isActive = b.textContent.trim() === initQuality;
      b.classList.toggle('active', isActive);
      if (isActive) b.style.setProperty('--q-color', color);
    });
  }

  // ===== Pチャーム =====
  const CHARM_STATES = ['', 'atk', 'hp', 'both'];
  const CHARM_ICON   = { '': '？', 'atk': '✊', 'hp': '❤', 'both': '✊❤' };
  let charmState = Array(9).fill('');

  function cycleCharm(idx) {
    const cur = CHARM_STATES.indexOf(charmState[idx]);
    charmState[idx] = CHARM_STATES[(cur + 1) % CHARM_STATES.length];
    renderCharmCell(idx);
    updateCharmInput();
  }

  function renderCharmCell(idx) {
    const cell  = document.getElementById('charm-' + idx);
    const state = charmState[idx];
    cell.className = 'pcharm-cell' + (state ? ' state-' + state : '');
    cell.textContent = CHARM_ICON[state];
  }

  function updateCharmInput() {
    document.getElementById('pcharm_hidden').value = charmState.join(',');
  }

  // POST後の復元
  (function initCharm() {
    const saved = <?= json_encode(fv('pcharm')) ?>;
    if (saved) {
      const parts = saved.split(',');
      parts.forEach((v, i) => {
        if (i < 9 && CHARM_STATES.includes(v)) charmState[i] = v;
      });
    }
    for (let i = 0; i < 9; i++) renderCharmCell(i);
    updateCharmInput();
  })();

  // ===== 技スロット =====
  const MOVE_TYPE_COLORS = {
    'ノーマル': '#9e9e9e', 'ほのお': '#e74c3c', 'みず': '#2980b9',
    'くさ': '#27ae60', 'でんき': '#f1c40f', 'エスパー': '#e91e8c',
    'かくとう': '#7f5233', 'いわ': '#8d6e63', 'じめん': '#cddc39',
    'こおり': '#55d1e8', 'むし': '#8bc34a', 'どく': '#9c27b0',
    'ゴースト': '#5c6bc0', 'ドラゴン': '#3949ab', 'あく': '#546e7a',
    'はがね': '#90a4ae', 'フェアリー': '#f48fb1',
  };
  const moveTypeMap = <?= json_encode($move_type_map, JSON_UNESCAPED_UNICODE) ?>;
  const MOVE_NAMES  = <?= json_encode(array_values($moves_list), JSON_UNESCAPED_UNICODE) ?>;
  const pokemonRangedMap = <?= json_encode(array_column($pokemon_list, 'ranged', 'pokedex_no')) ?>;
  const pokemonTypeMap = <?= json_encode(array_reduce($pokemon_list, function($carry, $p) {
    $carry[(int)$p['pokedex_no']] = array_values(array_filter([$p['type1'], $p['type2']]));
    return $carry;
  }, []), JSON_UNESCAPED_UNICODE) ?>;

  // ===== マイク/×ボタン排他表示 =====
  function toggleVoiceClear(inputId) {
    const el = document.getElementById(inputId);
    const mic = document.getElementById(inputId + '-mic');
    const clear = document.getElementById(inputId + '-clear');
    if (!mic || !clear) return;
    if (el && el.value) {
      mic.style.display = 'none';
      clear.style.display = 'block';
    } else {
      mic.style.display = 'block';
      clear.style.display = 'none';
      // placeholderを元に戻す
      if (el && el.dataset.origPlaceholder) {
        el.placeholder = el.dataset.origPlaceholder;
      }
    }
  }

  // ===== ポケモン絞り込みデータ =====
  const pokemonData = <?= json_encode(array_map(function($p) {
    return [
      'no'       => (int)$p['pokedex_no'],
      'name'     => $p['name'],
      'type1'    => $p['type1'] ?? '',
      'type2'    => $p['type2'] ?? '',
      'base_hp'  => (int)($p['base_hp'] ?? 0),
      'base_atk' => (int)($p['base_atk'] ?? 0),
    ];
  }, $pokemon_list), JSON_UNESCAPED_UNICODE) ?>;

  let pokemonSelectedTypes = [];

  function clickPokemonTypeBtn(btn) {
    const type = btn.dataset.type;
    const allBtn = document.querySelector('#pokemon-type-filter .type-filter-btn[data-type=""]');
    if (type === '') {
      pokemonSelectedTypes = [];
      document.querySelectorAll('#pokemon-type-filter .type-filter-btn').forEach(b => b.classList.remove('active'));
      allBtn.classList.add('active');
    } else {
      allBtn.classList.remove('active');
      if (btn.classList.contains('active')) {
        btn.classList.remove('active');
        pokemonSelectedTypes = pokemonSelectedTypes.filter(t => t !== type);
        if (pokemonSelectedTypes.length === 0) allBtn.classList.add('active');
      } else {
        if (pokemonSelectedTypes.length >= 2) {
          const old = pokemonSelectedTypes.shift();
          const oldBtn = document.querySelector(`#pokemon-type-filter .type-filter-btn[data-type="${old}"]`);
          if (oldBtn) oldBtn.classList.remove('active');
        }
        btn.classList.add('active');
        pokemonSelectedTypes.push(type);
      }
    }
    filterPokemon();
  }

  let _pokeSearchTimer = null;
  let _moveSearchTimer = [null, null, null];

  function onPokemonSearchInput() {
    const val = document.getElementById('pokemon-search').value;
    document.getElementById('pokemon-search-clear').style.display = val ? 'block' : 'none';
    clearTimeout(_pokeSearchTimer);
    _pokeSearchTimer = setTimeout(() => filterPokemon(), 300);
  }

  function clearPokemonSearch() {
    document.getElementById('pokemon-search').value = '';
    document.getElementById('pokemon-search-clear').style.display = 'none';
    filterPokemon();
  }

  function toKatakana(s) { return s.replace(/[\u3041-\u3096]/g, c => String.fromCharCode(c.charCodeAt(0) + 0x60)); }
  function toHiragana(s) { return s.replace(/[\u30A1-\u30F6]/g, c => String.fromCharCode(c.charCodeAt(0) - 0x60)); }
  function filterPokemon() {
    const rawName = (document.getElementById('pokemon-search').value || '').trim().toLowerCase();
    const rawNo   = (document.getElementById('pokemon-no-search')?.value || '').trim().replace(/[０-９]/g, c => String.fromCharCode(c.charCodeAt(0) - 0xFEE0)).replace(/[^0-9]/g, '');
    const nameQuery = rawName;
    const nameKata = toKatakana(rawName);
    const nameHira = toHiragana(rawName);
    const sel = document.getElementById('pokemon-select');
    const currentVal = sel.value;
    while (sel.options.length > 1) sel.remove(1);
    pokemonData.forEach(p => {
      if (nameQuery) {
        const nameLow = p.name.toLowerCase();
        if (!(nameLow.includes(nameQuery) || nameLow.includes(nameKata) || nameLow.includes(nameHira))) return;
      }
      if (rawNo) {
        const noStr = String(p.no).padStart(3, '0');
        if (!(noStr.includes(rawNo) || String(p.no).includes(rawNo))) return;
      }
      if (pokemonSelectedTypes.length > 0) {
        const types = [p.type1, p.type2].filter(t => t);
        if (!pokemonSelectedTypes.every(t => types.includes(t))) return;
      }
      const opt = document.createElement('option');
      opt.value = p.no;
      opt.textContent = 'No.' + String(p.no).padStart(3, '0') + ' ' + p.name;
      if (String(p.no) === currentVal) opt.selected = true;
      sel.appendChild(opt);
    });
    if (sel.options.length === 2 && !sel.value) {
      sel.selectedIndex = 1;
    }
    // 候補数が少ない場合は複数行表示
    const count = sel.options.length - 1; // 「選択してください」を除く
    sel.size = Math.max(3, (count > 0 && count <= 10) ? Math.min(count + 1, 6) : 3);
    updateCardName();
  }

  function getMoveColor(name) {
    if (!name) return null;
    const type = moveTypeMap[name];
    return (type && MOVE_TYPE_COLORS[type]) ? MOVE_TYPE_COLORS[type] : '#888';
  }

  // move2のスロット位置（0=未選択, 1/2/3）
  let move2SlotPos = parseInt(document.getElementById('move2_slot_hidden').value) || 0;

  function setSlotFilled(idx, color) {
    const el = document.getElementById('mslot-' + idx);
    el.className = 'mslot filled';
    el.style.background = '';
    el.style.borderColor = '';
    el.style.color = color || '#888';
    el.textContent = '■';
  }
  function setSlotExt(idx) {
    const el = document.getElementById('mslot-' + idx);
    el.className = 'mslot ext';
    el.style.background = '';
    el.style.borderColor = '';
    el.style.color = '';
    el.textContent = '◇';
  }
  function setSlotEmpty(idx) {
    const el = document.getElementById('mslot-' + idx);
    el.className = 'mslot';
    el.style.background = '';
    el.style.borderColor = '';
    el.style.color = '';
    el.textContent = '◇';
  }
  function setConn(id, mode) {
    // mode: 'dash' | 'gap' | 'hidden'
    const el = document.getElementById(id);
    el.textContent = mode === 'dash' ? '—' : '　';
    el.style.opacity = (mode === 'gap') ? '0' : '1';
  }

  function clickSlot(idx) {
    // 選択済みのスロットをクリック → 解除
    if (move2SlotPos === idx) {
      move2SlotPos = 0;
      document.getElementById('move2_slot_hidden').value = 0;
    } else {
      move2SlotPos = idx;
      document.getElementById('move2_slot_hidden').value = idx;
    }
    updateSlots();
  }

  function updateSlots() {
    const m1   = document.getElementById('move1_hidden').value;
    const m2   = document.getElementById('move2_hidden').value;
    const has1 = m1 !== '';
    const has2 = m2 !== '';
    const col1 = has1 ? getMoveColor(m1) : null;
    const col2 = has2 ? getMoveColor(m2) : null;
    const p    = move2SlotPos; // 技２の位置 (1/2/3)

    // スロット全リセット
    for (let i = 0; i < 4; i++) setSlotEmpty(i);
    setConn('mconn-01', 'hidden');
    setConn('mconn-12', 'hidden');
    setConn('mconn-23', 'hidden');

    // スロット1〜3は常にクリック可能
    [1,2,3].forEach(i => {
      document.getElementById('mslot-' + i).style.cursor = 'pointer';
    });

    const promptEl = document.getElementById('slot-prompt');
    if (!has1 && !has2) { if (promptEl) promptEl.style.display = 'none'; return; }

    // 技１は常にスロット0
    if (has1) setSlotFilled(0, col1); else setSlotEmpty(0);

    if (!has2) {
      // 1技のみ: ◆-◇-◇-◇
      setSlotExt(1); setSlotExt(2); setSlotExt(3);
      setConn('mconn-01', 'dash');
      setConn('mconn-12', 'dash');
      setConn('mconn-23', 'dash');
      if (promptEl) promptEl.style.display = 'none';
    } else if (p === 0) {
      // 技２あるがスロット未選択: 接続線は表示しない
      setSlotExt(1); setSlotExt(2); setSlotExt(3);
      setConn('mconn-01', 'hidden');
      setConn('mconn-12', 'hidden');
      setConn('mconn-23', 'hidden');
      if (promptEl) promptEl.style.display = 'block';
    } else {
      if (promptEl) promptEl.style.display = 'none';
      // 2技: 技２はスロットp
      // スロット1〜(p-1)は技１の拡張
      for (let i = 1; i < p; i++) setSlotExt(i);
      // スロットpは技２
      setSlotFilled(p, col2);
      // スロット(p+1)〜3は技２の拡張
      for (let i = p + 1; i < 4; i++) setSlotExt(i);

      // コネクター: ギャップはスロット(p-1)とスロットpの間
      // conn01: p>1ならdash、p=1ならgap
      setConn('mconn-01', p === 1 ? 'gap' : 'dash');
      // conn12: p>2ならdash、p=2ならgap、p=1なら技２の拡張内なのでdash
      setConn('mconn-12', p === 2 ? 'gap' : 'dash');
      // conn23: p=3ならgap、それ以外dash
      setConn('mconn-23', p === 3 ? 'gap' : 'dash');
    }
  }

  // ===== 技入力（選択 or 手入力）=====
  function onMoveChange(n) {
    const sel = document.getElementById('move' + n + '_sel');
    const txt = document.getElementById('move' + n + '_txt');
    const hid = document.getElementById('move' + n + '_hidden');
    if (sel.value === '__other') {
      txt.style.display = 'block';
      txt.focus();
      hid.value = txt.value;
    } else {
      txt.style.display = 'none';
      hid.value = sel.value;
    }
    // 技２がクリアされたらスロット位置をリセット
    if (n === 2 && hid.value === '') {
      move2SlotPos = 0;
      document.getElementById('move2_slot_hidden').value = 0;
    }
    updateSlots();
  }
  // テキスト入力時も hidden を更新＋スロット更新
  [1, 2].forEach(n => {
    document.getElementById('move' + n + '_txt').addEventListener('input', function() {
      document.getElementById('move' + n + '_hidden').value = this.value;
      updateSlots();
    });
  });

  // 初期スロット描画（POST復元時）
  updateSlots();

  // ===== 削除確認モーダル =====
  function showDeleteModal() {
    document.getElementById('delete-modal').classList.add('active');
  }
  function hideDeleteModal(e) {
    // オーバーレイ自体クリック時のみ閉じる（モーダル内クリックは無視）
    if (e && e.target !== document.getElementById('delete-modal')) return;
    document.getElementById('delete-modal').classList.remove('active');
  }

  // ===== 料理選択 =====
  function selectRecipe(btn) {
    const name = btn.dataset.name;
    const alreadySelected = btn.classList.contains('selected');
    document.querySelectorAll('.recipe-icon-btn').forEach(b => b.classList.remove('selected'));
    if (alreadySelected) {
      // 再タップで解除
      document.getElementById('recipe_name_sel').value = '';
      document.getElementById('recipe_name_hidden').value = '';
    } else {
      btn.classList.add('selected');
      document.getElementById('recipe_name_sel').value = name;
      document.getElementById('recipe_name_hidden').value = name;
    }
  }
  function onRecipeSelectChange() {
    const name = document.getElementById('recipe_name_sel').value;
    document.getElementById('recipe_name_hidden').value = name;
    document.querySelectorAll('.recipe-icon-btn').forEach(b => {
      b.classList.toggle('selected', b.dataset.name === name);
    });
  }
  // POST/編集復元時にアイコン選択を反映
  (function initRecipe() {
    const saved = document.getElementById('recipe_name_hidden').value;
    if (saved) {
      document.querySelectorAll('.recipe-icon-btn').forEach(b => {
        if (b.dataset.name === saved) b.classList.add('selected');
      });
    }
  })();

  const POT_BASE = {'鉄':0,'銅':50,'銀':150,'金':300,'なし':0};
  const POT_IVMAX = {'鉄':10,'銅':50,'銀':100,'金':100,'なし':10};
  const POT_MINLV = {'鉄':1,'銅':21,'銀':41,'金':71,'なし':1};

  function changeLevel(delta) {
    const el  = document.getElementById('level-input');
    const val = Math.min(100, Math.max(1, (parseInt(el.value) || 100) + delta));
    el.value  = val;
    updateSliders();
    showSliderTip(document.getElementById('level-slider'));
  }
  function changeStat(stat, delta) {
    const el = document.getElementById(stat + '-input');
    const slider = document.getElementById(stat + '-slider');
    if (!el || slider.disabled) return;
    const min = parseInt(slider.min) || 0;
    const max = parseInt(slider.max) || 9999;
    const val = Math.min(max, Math.max(min, (parseInt(el.value) || 0) + delta));
    el.value = val;
    slider.value = val;
    showSliderTip(slider);
  }

  function updateSliders() {
    const lvSlider = document.getElementById('level-slider');
    const hpSlider = document.getElementById('hp-slider');
    const atkSlider = document.getElementById('atk-slider');
    const lvInput = document.getElementById('level-input');
    const hpInput = document.getElementById('hp-input');
    const atkInput = document.getElementById('atk-input');
    const potType = document.getElementById('pot_type_hidden').value || '';
    const lv = parseInt(lvInput.value) || 1;

    // レベルスライダー同期（鍋に応じて下限変更）
    const minLv = (potType && POT_MINLV[potType]) ? POT_MINLV[potType] : 1;
    lvSlider.min = minLv;
    lvSlider.value = lv;
    // Lvメモリ更新
    buildTicksAndLabels('lv-ticks', 'lv-tick-labels', minLv, 100, 10);

    // 鍋・ポケモン・レベルが揃っているか
    const hasPot = potType && POT_BASE[potType] !== undefined;
    const sel = document.getElementById('pokemon-select');
    const poke = pokemonData.find(p => String(p.no) === sel.value);
    const hasLevel = lv >= 1 && lv <= 100 && lvInput.value !== '';
    const canSlide = hasPot && poke && hasLevel;
    hpSlider.disabled = !canSlide;
    atkSlider.disabled = !canSlide;

    if (canSlide) {
      const baseHp = poke ? (poke.base_hp || 0) : 0;
      const baseAtk = poke ? (poke.base_atk || 0) : 0;
      const potBase = POT_BASE[potType];
      const ivMax = POT_IVMAX[potType];

      const hpMin = baseHp + lv + potBase;
      const hpMax = hpMin + ivMax;
      const atkMin = baseAtk + lv + potBase;
      const atkMax = atkMin + ivMax;

      hpSlider.min = hpMin;
      hpSlider.max = hpMax;
      atkSlider.min = atkMin;
      atkSlider.max = atkMax;

      // 現在値をスライダー範囲内に収める
      const hpVal = parseInt(hpInput.value) || hpMin;
      hpSlider.value = Math.min(hpMax, Math.max(hpMin, hpVal));

      const atkVal = parseInt(atkInput.value) || atkMin;
      atkSlider.value = Math.min(atkMax, Math.max(atkMin, atkVal));

      buildTicksAndLabels('hp-ticks', 'hp-tick-labels', hpMin, hpMax, null, 50);
      buildTicksAndLabels('atk-ticks', 'atk-tick-labels', atkMin, atkMax, null, 50);
    }
  }

  // メモリラベル・datalist更新（位置をパーセンテージで配置）
  function buildTicksAndLabels(sliderId, labelsId, min, max, forceStep, boldStep) {
    const dl = document.getElementById(sliderId);
    const lb = document.getElementById(labelsId);
    if (!dl || !lb) return;
    dl.innerHTML = '';
    const range = max - min || 1;
    const sliderEl = dl.closest('div')?.querySelector('input[type="range"]');
    const sliderW = sliderEl ? sliderEl.offsetWidth : 200;
    let labelStep = forceStep || 10;
    if (!forceStep && sliderW < 250) labelStep = 20;
    const tickStep = 5;
    const bold = boldStep || 10;
    const thumbR = 8;
    const trackW = sliderW - thumbR * 2;
    let html = '';
    const firstTick = Math.ceil(min / tickStep) * tickStep;
    for (let v = firstTick; v <= max; v += tickStep) {
      const o = document.createElement('option');
      o.value = v;
      dl.appendChild(o);
      const posPx = thumbR + ((v - min) / range) * trackW;
      const pct = (posPx / sliderW) * 100;
      const isBold = (v % bold === 0);
      const showLabel = (v % labelStep === 0);
      const tickW = isBold ? 2 : 1;
      const tickH = isBold ? 7 : 5;
      if (showLabel) {
        const isBold100 = (v % 100 === 0);
        html += `<span style="left:${pct}%;${isBold100 ? 'font-weight:700;' : ''}" class="tick-label"><span class="tick-line" style="width:${tickW}px;height:${tickH}px"></span>${v}</span>`;
      } else {
        html += `<span style="left:${pct}%" class="tick-only"><span class="tick-line" style="width:${tickW}px;height:${tickH}px"></span></span>`;
      }
    }
    lb.innerHTML = html;
  }

  function showSliderTip(slider) {
    const tip = document.getElementById(slider.id + '-tip');
    if (!tip) return;
    const min = parseFloat(slider.min);
    const max = parseFloat(slider.max);
    const val = parseFloat(slider.value);
    const range = max - min || 1;
    const thumbR = 8;
    const sliderW = slider.offsetWidth || 200;
    const trackW = sliderW - thumbR * 2;
    const posPx = thumbR + ((val - min) / range) * trackW;
    const pct = (posPx / sliderW) * 100;
    tip.textContent = val;
    tip.style.display = 'block';
    tip.style.left = pct + '%';
  }
  function hideSliderTip(slider) {
    const tip = document.getElementById(slider.id + '-tip');
    if (tip) tip.style.display = 'none';
  }
  function hideAllSliderTips() {
    ['level-slider-tip','hp-slider-tip','atk-slider-tip'].forEach(id => {
      const t = document.getElementById(id);
      if (t) t.style.display = 'none';
    });
  }
  document.addEventListener('mousedown', function(e) {
    if (!e.target.closest('input[type="range"]') && !e.target.closest('.lv-btn')) {
      hideAllSliderTips();
    }
  });

  function syncSliderFromInput(stat) {
    const slider = document.getElementById(stat + '-slider');
    const input = document.getElementById(stat + '-input');
    if (!slider.disabled) {
      slider.value = Math.min(parseInt(slider.max), Math.max(parseInt(slider.min), parseInt(input.value) || 0));
    }
  }

  // ===== 品質 有効/無効 =====
  function disableQuality() {
    document.getElementById('quality_hidden').value = '';
    document.querySelectorAll('.quality-btn').forEach(b => {
      b.classList.remove('active'); b.disabled = true;
    });
    document.getElementById('quality-selector').classList.add('disabled');
  }
  function enableQuality() {
    document.querySelectorAll('.quality-btn').forEach(b => { b.disabled = false; });
    document.getElementById('quality-selector').classList.remove('disabled');
  }

  function selectPot(pot) {
    const hidden   = document.getElementById('pot_type_hidden');
    const selector = document.getElementById('pot-selector');
    if (hidden.value === pot) {
      // 同じ鍋クリック → 解除
      hidden.value = '';
      selector.classList.remove('has-selection');
      document.querySelectorAll('.pot-btn').forEach(b => b.classList.remove('active-pot'));
      enableQuality();
      updateSliders();
      return;
    }
    hidden.value = pot;
    selector.classList.add('has-selection');
    document.querySelectorAll('.pot-btn').forEach(b => {
      b.classList.toggle('active-pot', b.dataset.pot === pot);
    });
    // レベルが未入力または鍋の最低値未満なら最低値をセット
    const potMinLv = {'なし':1,'鉄':1,'銅':21,'銀':41,'金':71};
    const lvInput = document.getElementById('level-input');
    const minLv = potMinLv[pot] || 1;
    if (!lvInput.value || parseInt(lvInput.value) < minLv) {
      lvInput.value = minLv;
    }
    enableQuality();
    updateSliders();
  }

  function selectQuality(q, color) {
    const hidden = document.getElementById('quality_hidden');
    if (hidden.value === q) {
      // 同じ品質クリック → 解除
      hidden.value = '';
      document.querySelectorAll('.quality-btn').forEach(b => b.classList.remove('active'));
      return;
    }
    hidden.value = q;
    document.querySelectorAll('.quality-btn').forEach(b => {
      const isActive = b.textContent.trim() === q;
      b.classList.toggle('active', isActive);
      if (isActive) b.style.setProperty('--q-color', color);
    });
  }

  // ===== 色違い星 =====
  function toggleShiny(checked) {
    document.getElementById('shiny-star').classList.toggle('show', checked);
  }
  (function() {
    const cb = document.getElementById('is-shiny-cb');
    if (cb && cb.checked) document.getElementById('shiny-star').classList.add('show');
  })();

  // ===== 音声入力 =====
  // 補正辞書（DBから読み込み、タイプ別）
  <?php
    $vfDb = new PDO('sqlite:' . __DIR__ . '/data/pokemon_quest.sqlite');
    $vfRows = $vfDb->query('SELECT input_text, output_text, match_type, field_type FROM voice_fixes')->fetchAll(PDO::FETCH_ASSOC);
    $vfExact = []; $vfReplaceName = []; $vfReplaceNum = [];
    foreach ($vfRows as $r) {
      if ($r['match_type'] === 'replace' && $r['field_type'] === 'number') {
        $vfReplaceNum[] = [$r['input_text'], $r['output_text']];
      } elseif ($r['match_type'] === 'replace' && $r['field_type'] === 'name') {
        $vfReplaceName[] = [$r['input_text'], $r['output_text']];
      } else {
        $vfExact[$r['input_text']] = $r['output_text'];
      }
    }
    // 長い文字列を先に処理するようソート
    usort($vfReplaceName, fn($a, $b) => mb_strlen($b[0]) - mb_strlen($a[0]));
    usort($vfReplaceNum, fn($a, $b) => mb_strlen($b[0]) - mb_strlen($a[0]));
  ?>
  const voiceFixes = <?= json_encode($vfExact, JSON_UNESCAPED_UNICODE) ?>;
  const voiceReplaceName = <?= json_encode($vfReplaceName, JSON_UNESCAPED_UNICODE) ?>;
  const voiceReplaceNum = <?= json_encode($vfReplaceNum, JSON_UNESCAPED_UNICODE) ?>;
  let _voiceRec = null;
  function startVoice(targetId, event) {
    if (event) event.preventDefault();
    const el = document.getElementById(targetId);
    if (!el) return;
    if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
      el.setCustomValidity('このブラウザは音声入力に対応していません');
      el.reportValidity();
      el.addEventListener('input', () => el.setCustomValidity(''), { once: true });
      return;
    }
    // 既に認識中なら停止
    if (_voiceRec) { try { _voiceRec.abort(); } catch(e){} _voiceRec = null; }
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    const rec = new SR();
    rec.lang = 'ja-JP';
    rec.continuous = false;
    rec.interimResults = true;
    _voiceRec = rec;
    if (!el.placeholder.includes('🎤')) {
      el.dataset.origPlaceholder = el.placeholder;
    }
    el.value = '';
    el.placeholder = '🎤 ...';
    // 両方の入力欄をクリア＆placeholderをリセット
    const nameEl = document.getElementById('pokemon-search');
    const noEl = document.getElementById('pokemon-no-search');
    if (el !== nameEl && nameEl) { nameEl.value = ''; nameEl.placeholder = nameEl.dataset.origPlaceholder || 'ポケモン名'; document.getElementById('pokemon-search-clear').style.display = 'none'; }
    if (el !== noEl && noEl) { noEl.value = ''; noEl.placeholder = noEl.dataset.origPlaceholder || 'No.'; }
    el.dispatchEvent(new Event('input'));
    el.focus();
    rec.onresult = function(e) {
      const result = e.results[e.results.length - 1];
      let text = result[0].transcript.replace(/[、。，．,.！？!?・\s]/g, '');
      const isNumField = targetId === 'pokemon-no-search';
      if (isNumField) {
        // 数字用：DB部分置換を適用（長い順に処理済み）
        voiceReplaceNum.forEach(r => { text = text.split(r[0]).join(r[1]); });
        // 漢数字の位取り変換（百十を正しく処理）
        text = text.replace(/百/g, '00').replace(/十/g, '0');
        // 漢数字一文字を数字に
        text = text.replace(/[〇一二三四五六七八九]/g, c => '〇一二三四五六七八九'.indexOf(c));
        // 数字以外を除去（先頭ゼロは維持）
        text = text.replace(/[^0-9]/g, '');
      } else {
        // 名前用：DB部分置換を適用
        voiceReplaceName.forEach(r => { text = text.split(r[0]).join(r[1]); });
      }
      // 英語→日本語の補正辞書を先にチェック
      const fixKey = text.toLowerCase().replace(/[\s.-]/g, '');
      if (voiceFixes[fixKey]) { text = voiceFixes[fixKey]; }
      // ポケモン名・わざ名の候補リストと照合して補正（途中結果・確定結果とも）
      const candidates = targetId.startsWith('move') ? (window.MOVE_NAMES || []) : (window.pokemonData || []).map(p => p.name);
      const textKata = toKatakana(text);
      const textHira = toHiragana(text);
      const textLow = text.toLowerCase();
      // 完全一致
      let match = candidates.find(c => c === text || c === textKata || c === textHira);
      // 部分一致（候補が入力を含む or 入力が候補を含む）
      if (!match) match = candidates.find(c => {
        const cLow = c.toLowerCase();
        return cLow.includes(textLow) || cLow.includes(toKatakana(textLow)) || cLow.includes(toHiragana(textLow))
            || textLow.includes(cLow) || toKatakana(textLow).includes(cLow) || toHiragana(textLow).includes(cLow);
      });
      if (match) text = match;
      el.value = text;
      el.dispatchEvent(new Event('input'));
    };
    rec.onend = function() {
      el.placeholder = el.dataset.origPlaceholder || '';
      _voiceRec = null;
    };
    rec.onerror = function(e) {
      el.placeholder = el.dataset.origPlaceholder || '';
      _voiceRec = null;
      if (e.error !== 'no-speech' && e.error !== 'aborted') {
        el.setCustomValidity('音声認識エラー: ' + e.error);
        el.reportValidity();
        el.addEventListener('input', () => el.setCustomValidity(''), { once: true });
      }
    };
    el.dataset.origPlaceholder = el.placeholder;
    rec.start();
  }

  // ===== ステータスカード：ポケモン名更新 =====
  const TYPE_COLORS = {
    'ノーマル':'#9e9e9e','ほのお':'#e74c3c','みず':'#2980b9','くさ':'#27ae60',
    'でんき':'#f1c40f','こおり':'#55d1e8','かくとう':'#7f5233','どく':'#9c27b0',
    'じめん':'#cddc39','ひこう':'#90caf9','エスパー':'#e91e8c','むし':'#8bc34a',
    'いわ':'#8d6e63','ゴースト':'#5c6bc0','ドラゴン':'#3949ab','あく':'#546e7a',
    'はがね':'#90a4ae','フェアリー':'#f48fb1'
  };
  const pokemonSel = document.querySelector('select[name="pokemon_id"]');
  function updateCardName() {
    const opt = pokemonSel.options[pokemonSel.selectedIndex];
    const rangedEl = document.getElementById('ranged-icon');
    const typesEl  = document.getElementById('stat-pokemon-types');
    if (opt && opt.value) {
      document.getElementById('stat-pokemon-name').textContent = opt.text.replace(/^No\.\d{3} /, '');
      var imgEl = document.getElementById('stat-pokemon-img');
      if (imgEl) imgEl.src = '/images/pokemon/' + String(opt.value).padStart(3,'0') + '.png';
      if (rangedEl) {
        const isRanged = pokemonRangedMap[parseInt(opt.value)];
        rangedEl.innerHTML = isRanged ? '<img src="/images/ranged.png" alt="遠距離" style="width:30px;height:31px;">' : '<img src="/images/melee.png" alt="近距離" style="width:30px;height:31px;">';
        rangedEl.style.visibility = 'visible';
      }
      if (typesEl) {
        const types = pokemonTypeMap[parseInt(opt.value)] || [];
        typesEl.innerHTML = types.map(t => {
          const bg = TYPE_COLORS[t] || '#888';
          return `<span class="stat-type-badge" style="background:${bg};">${t}</span>`;
        }).join('');
      }
    } else {
      document.getElementById('stat-pokemon-name').textContent = 'ポケモンを選択してください';
      var imgEl2 = document.getElementById('stat-pokemon-img');
      if (imgEl2) imgEl2.src = '/images/pokemon/000.png';
      if (rangedEl) rangedEl.style.visibility = 'hidden';
      if (typesEl) typesEl.innerHTML = '';
    }
  }
  pokemonSel.addEventListener('change', function() { updateCardName(); updateSliders(); });
  updateCardName();

  // ===== 技フィルター共通処理 =====
  function applyMoveFilter(n) {
    const typeBtn = document.querySelector('#move' + n + '-type-filter .type-filter-btn.active');
    const type    = typeBtn ? typeBtn.dataset.type : '';
    const query   = (document.getElementById('move' + n + '_search')?.value || '').trim();
    const sel     = document.getElementById('move' + n + '_sel');
    const curVal  = document.getElementById('move' + n + '_hidden').value;
    const isTxt   = document.getElementById('move' + n + '_txt').style.display !== 'none';

    let names = type ? MOVE_NAMES.filter(name => moveTypeMap[name] === type) : [...MOVE_NAMES];
    if (query) {
      const qKata = toKatakana(query);
      const qHira = toHiragana(query);
      names = names.filter(name => name.includes(query) || name.includes(qKata) || name.includes(qHira));
    }

    sel.innerHTML = '<option value="">-- 未選択 --</option>';
    names.forEach(name => {
      const o = document.createElement('option');
      o.value = name; o.textContent = name;
      if (name === curVal) o.selected = true;
      sel.appendChild(o);
    });
    const other = document.createElement('option');
    other.value = '__other'; other.textContent = 'その他（手入力）';
    if (isTxt) other.selected = true;
    sel.appendChild(other);

    // 検索結果が1件なら自動選択
    if (names.length === 1 && !isTxt) {
      sel.value = names[0];
      document.getElementById('move' + n + '_hidden').value = names[0];
    } else if (!isTxt && curVal && !names.includes(curVal)) {
      sel.value = '';
    }
    updateSlots();
  }

  // ===== 技タイプフィルター =====
  function filterMoveType(n, btn) {
    const isActive = btn.classList.contains('active');
    const type = (isActive && btn.dataset.type !== '') ? '' : btn.dataset.type;
    const allBtn = btn.closest('.type-filter-btns').querySelector('.type-filter-btn[data-type=""]');
    btn.closest('.type-filter-btns').querySelectorAll('.type-filter-btn').forEach(b => b.classList.remove('active'));
    if (type === '') { allBtn.classList.add('active'); } else { btn.classList.add('active'); }
    applyMoveFilter(n);
  }

  // ===== 技名テキスト検索 =====
  function onMoveSearchInput(n) {
    const val = document.getElementById('move' + n + '_search').value;
    document.getElementById('move' + n + '_search_clear').style.display = val ? 'block' : 'none';
    clearTimeout(_moveSearchTimer[n]);
    _moveSearchTimer[n] = setTimeout(() => applyMoveFilter(n), 300);
  }
  function clearMoveSearch(n) {
    document.getElementById('move' + n + '_search').value = '';
    document.getElementById('move' + n + '_search_clear').style.display = 'none';
    applyMoveFilter(n);
  }

  </script>
</body>
</html>
