<?php
// 共通認証（ログインチェックのみ・必須ではない）
$isLoggedIn  = false;
$sessionUserId   = null;
$sessionUsername = null;
try {
    require_once '/home/m96/asobi.info/public_html/assets/php/auth.php';
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
function canEditReport($rec, bool $isLoggedIn, ?int $sessionUserId, string $myIp): bool {
    if (!$rec) return false;
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
    if (canEditReport($rec, $isLoggedIn, $sessionUserId, getClientIp())) {
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
    if (canEditReport($rec, $isLoggedIn, $sessionUserId, getClientIp())) {
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
    $pot_type   = trim($_POST['pot_type']  ?? '');
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

    if (!canEditReport($rec, $isLoggedIn, $sessionUserId, getClientIp())) {
        $msg = 'この投稿を編集する権限がありません。';
        $msg_type = 'error';
    } elseif ($memo !== '' && function_exists('asobiCheckBanned') && asobiCheckBanned($memo, 'content')['blocked']) {
        $msg = 'メモに使用できない言葉が含まれています';
        $msg_type = 'error';
        $editMode   = true;
        $editRecord = $rec;
    } elseif ($pokemon_id > 0
        && in_array($pot_type, ['鉄','銅','銀','金'])
        && in_array($quality,  ['ふつう','いい','すごくいい','スペシャル'])
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
        $msg = '入力内容が不正です。ポケモン・鍋・品質・HP・ATKをすべて正しく入力してください。';
        $msg_type = 'error';
        $editMode   = true;
        $editRecord = $rec;
    }
}

// ---- クイック登録（個体値チェッカーから即時DB登録→編集画面へ） ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_add') {
    $pokemon_id = (int)($_POST['pokemon_id'] ?? 0);
    $pot_type   = trim($_POST['pot_type']  ?? '');
    $quality    = trim($_POST['quality']   ?? '');
    $level      = (int)($_POST['level']    ?? 100);
    $hp         = (int)($_POST['hp']       ?? 0);
    $atk        = (int)($_POST['atk']      ?? 0);

    $valid_pots    = ['鉄','銅','銀','金'];
    $valid_quality = ['ふつう','いい','すごくいい','スペシャル'];

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
    $pot_type   = trim($_POST['pot_type']  ?? '');
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

    $valid_pots     = ['鉄','銅','銀','金'];
    $valid_quality  = ['ふつう','いい','すごくいい','スペシャル'];

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
  <title>料理結果を投稿 - ポケモンクエスト情報</title>
  <meta name="description" content="料理の結果（鍋・品質・Lv・HP・ATK）を投稿してデータ収集に協力しよう。">
  <link rel="stylesheet" href="https://asobi.info/assets/css/common.css">
  <link rel="stylesheet" href="/css/style.css">
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
    .form-group { margin-bottom: 14px; }
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
      font-size: 0.9rem;
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
    .pot-selector { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; }
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

    /* 品質ボタン */
    .quality-selector { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
    .quality-btn {
      padding: 8px 4px;
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
    .lv-btns { display: flex; gap: 3px; margin-top: 6px; flex-wrap: wrap; justify-content: center; }
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
      min-width: 38px; flex-shrink: 0;
    }
    .hp-box input, .atk-box input {
      flex: none; width: 72px; background: #fff; border: none;
      border-radius: 6px; color: #333;
      font-size: 1.1rem; font-weight: 700;
      text-align: right; padding: 3px 8px;
      outline: none; box-sizing: border-box;
      -moz-appearance: textfield;
    }
    .hp-box input::-webkit-inner-spin-button,
    .hp-box input::-webkit-outer-spin-button,
    .atk-box input::-webkit-inner-spin-button,
    .atk-box input::-webkit-outer-spin-button { -webkit-appearance: none; }
    .hp-box input::placeholder, .atk-box input::placeholder {
      color: rgba(0,0,0,0.3);
    }
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
      margin-bottom: 8px;
    }
    .type-filter-label {
      font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 5px;
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
      margin-bottom: 10px;
    }
    .move-slots-label {
      font-size: 0.78rem;
      color: var(--text-secondary);
      margin-bottom: 6px;
    }
    .move-slots-row {
      display: flex;
      align-items: center;
      gap: 0;
      height: 40px;
    }
    .mslot {
      width: 36px;
      height: 36px;
      border-radius: 6px;
      border: 2px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.4rem;
      color: var(--text-secondary);
      background: var(--bg-primary);
      transition: all 0.2s;
      flex-shrink: 0;
    }
    .mslot.filled {
      font-size: 1.6rem;
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
          <li><a href="/pokemon-list.html">ポケモン一覧</a></li>
          <li><a href="/recipes.html">料理一覧</a></li>
          <li><a href="/moves.html">わざ一覧</a></li>
          <li><a href="/simulator.html">料理シミュレーター</a></li>
          <li><a href="/iv-checker.html">個体値チェッカー</a></li>
          <li><a href="/iv-report.php" class="active">結果を投稿</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main class="container">
    <div class="page-header">
      <div class="breadcrumb"><a href="/">ポケクエ</a><span>料理結果を投稿</span></div>
      <h1>料理結果を投稿</h1>
      <p>呼び出したポケモンのレベル・HP・ATKを投稿してデータ収集に協力しよう！</p>
    </div>
    <div style="margin-bottom:16px;text-align:right;">
      <a href="/iv-report-list.php" style="color:var(--accent);font-size:0.88rem;text-decoration:none;">🕐 最近の投稿一覧を見る →</a>
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

          <form method="POST">
            <input type="hidden" name="action" value="<?= $editMode ? 'update' : 'add' ?>">
            <?php if ($editMode): ?>
            <input type="hidden" name="edit_id" value="<?= $editRecord['id'] ?>">
            <?php endif; ?>
            <input type="hidden" name="pot_type"  id="pot_type_hidden"  value="<?= htmlspecialchars(fv('pot_type')) ?>">
            <input type="hidden" name="quality"   id="quality_hidden"   value="<?= htmlspecialchars(fv('quality')) ?>">

            <div class="form-group">
              <label class="form-label">料理名（任意）</label>
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
              <select id="recipe_name_sel" class="form-control" onchange="onRecipeSelectChange()">
                <option value="">-- 未選択 --</option>
                <?php foreach ($recipes_ui as $rec): ?>
                <option value="<?= htmlspecialchars($rec['name']) ?>"
                  <?= fv('recipe_name') === $rec['name'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($rec['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <input type="hidden" name="recipe_name" id="recipe_name_hidden" value="<?= htmlspecialchars(fv('recipe_name')) ?>">
            </div>

            <?php
            $pot_colors = ['なし'=>'#546e7a','鉄'=>'#777','銅'=>'#cc8844','銀'=>'#9e9e9e','金'=>'#d4af37'];
            $currentPot = fv('pot_type');
            ?>
            <div style="display:flex; gap:16px; flex-wrap:wrap;">
              <div class="form-group" style="flex:1; min-width:180px;">
                <label class="form-label">鍋の種類 <span style="color:var(--accent);">*</span></label>
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
              </div>

              <div class="form-group" style="flex:1; min-width:200px;">
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
                    'でんき'=>'#f1c40f','エスパー'=>'#e91e8c','かくとう'=>'#7f5233','いわ'=>'#8d6e63',
                    'じめん'=>'#cddc39','こおり'=>'#55d1e8','むし'=>'#8bc34a','どく'=>'#9c27b0',
                    'ゴースト'=>'#5c6bc0','ドラゴン'=>'#3949ab','あく'=>'#546e7a','はがね'=>'#90a4ae',
                    'フェアリー'=>'#f48fb1'
                  ] as $t => $tc): ?>
                  <button type="button" class="type-filter-btn" data-type="<?= $t ?>"
                    style="background:<?= $tc ?>;"
                    onclick="clickPokemonTypeBtn(this)"><?= $t ?></button>
                  <?php endforeach; ?>
                </div>
              </div>
              <div style="display:flex; gap:6px; align-items:center;">
                <select name="pokemon_id" id="pokemon-select" class="form-control" required style="flex:1; min-width:0;">
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
                  <input type="text" id="pokemon-search" class="form-control"
                    placeholder="ポケモン名、No.000〜151でフィルター" oninput="onPokemonSearchInput()"
                    style="width:100%; padding-right:24px; color:var(--text-primary); font-size:0.82rem;">
                  <button type="button" id="pokemon-search-clear"
                    onclick="clearPokemonSearch()"
                    style="display:none; position:absolute; right:5px; top:50%; transform:translateY(-50%);
                           background:none; border:none; cursor:pointer; color:var(--text-secondary);
                           font-size:0.9rem; line-height:1; padding:2px;">×</button>
                </div>
              </div>
            </div>

            <!-- ステータスカード -->
            <div style="font-size:0.85rem;font-weight:600;color:var(--text-secondary);margin-bottom:6px;">
              レベル / HP / ATK <span style="color:var(--accent);">*</span>
            </div>
            <div style="display:flex; align-items: flex-end; gap: 12px; margin-bottom: 14px; flex-wrap:wrap;">
              <div class="stat-card" style="margin-bottom:0; width:fit-content; flex-shrink:0;">
                <div class="stat-card-icon" id="stat-pokemon-icon">□</div>
                <div class="stat-card-body">
                  <div class="stat-card-name-row">
                    <div class="stat-card-name-wrap">
                      <div class="stat-card-name" id="stat-pokemon-name">ポケモンを選択してください</div>
                    </div>
                    <div class="stat-card-types" id="stat-pokemon-types"></div>
                  </div>
                  <div class="stat-card-rows">
                    <div>
                      <div class="lv-box">
                        <span class="lv-label">Lv.</span>
                        <input type="number" name="level" id="level-input" required
                          min="1" max="100" value="<?= htmlspecialchars(fv('level', '')) ?>" placeholder="100"
                          inputmode="numeric" pattern="[0-9]*">
                      </div>
                      <div class="lv-btns">
                        <button type="button" class="lv-btn" onclick="changeLevel(10)">+10</button>
                        <button type="button" class="lv-btn" onclick="changeLevel(-10)">-10</button>
                        <button type="button" class="lv-btn" onclick="changeLevel(1)">+1</button>
                        <button type="button" class="lv-btn" onclick="changeLevel(-1)">-1</button>
                      </div>
                    </div>
                    <div class="stat-hp-atk">
                      <div class="hp-box">
                        <div class="stat-icon-label">❤ HP</div>
                        <input type="number" name="hp" id="hp-input" required
                          min="1" max="99999" value="<?= htmlspecialchars(fv('hp')) ?>"
                          placeholder="500" inputmode="numeric" pattern="[0-9]*">
                      </div>
                      <div class="atk-box">
                        <div class="stat-icon-label">⚡ ATK</div>
                        <input type="number" name="atk" id="atk-input" required
                          min="1" max="99999" value="<?= htmlspecialchars(fv('atk')) ?>"
                          placeholder="500" inputmode="numeric" pattern="[0-9]*">
                      </div>
                    </div>
                    <div style="display:flex; flex-direction:column; align-items:center; justify-content:flex-end; padding-bottom:4px; min-width:28px; gap:3px;">
                      <span id="ranged-icon" style="font-size:1rem; visibility:hidden;"></span>
                      <span class="shiny-star" id="shiny-star" style="font-size:1.4rem;">★</span>
                    </div>
                  </div>
                </div>
              </div>
              <div style="display:flex; flex-direction:column; justify-content:space-between; gap:8px; align-self:stretch; flex-shrink:0; min-width:100px;">
                <div style="background:rgba(231,76,60,0.1);border:1.5px solid #e74c3c;border-radius:8px;padding:6px 10px;color:#c0392b;font-size:0.78rem;font-weight:700;line-height:1.5;">
                  ⚠️ Pストーンをすべて外した状態で入力
                </div>
                <div class="shiny-check-row" style="margin-bottom:0;">
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
                      'でんき'=>'#f1c40f','エスパー'=>'#e91e8c','かくとう'=>'#7f5233','いわ'=>'#8d6e63',
                      'じめん'=>'#cddc39','こおり'=>'#55d1e8','むし'=>'#8bc34a','どく'=>'#9c27b0',
                      'ゴースト'=>'#5c6bc0','ドラゴン'=>'#3949ab','あく'=>'#546e7a','はがね'=>'#90a4ae',
                      'フェアリー'=>'#f48fb1'
                    ] as $t => $tc): ?>
                    <button type="button" class="type-filter-btn" data-type="<?= $t ?>"
                      style="background:<?= $tc ?>;"
                      onclick="filterMoveType(<?= $n ?>, this)"><?= $t ?></button>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div style="display:flex; gap:6px; align-items:center; margin-top:4px;">
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
                    <input type="text" id="move<?= $n ?>_search" class="form-control"
                      placeholder="わざ名でフィルター"
                      oninput="onMoveSearchInput(<?= $n ?>)"
                      style="width:100%; padding-right:24px; font-size:0.82rem;">
                    <button type="button" id="move<?= $n ?>_search_clear"
                      onclick="clearMoveSearch(<?= $n ?>)"
                      style="display:none; position:absolute; right:5px; top:50%; transform:translateY(-50%);
                             background:none; border:none; cursor:pointer; color:var(--text-secondary);
                             font-size:0.9rem; line-height:1; padding:2px;">×</button>
                  </div>
                </div>
                <input type="text" id="move<?= $n ?>_txt" class="form-control"
                       placeholder="技名を入力"
                       value="<?= $isOther ? htmlspecialchars($val) : '' ?>"
                       style="margin-top:6px;display:<?= $isOther ? 'block' : 'none' ?>;">
                <input type="hidden" name="move<?= $n ?>" id="move<?= $n ?>_hidden"
                       value="<?= htmlspecialchars($val) ?>">
              </div>
              <?php endforeach; ?>
            </div>

            <!-- スロット表示 -->
            <div class="move-slots-wrap">
              <div class="move-slots-label">スロット（スロットをタップして技の位置を選択できます）</div>
              <div class="move-slots-row">
                <div class="mslot" id="mslot-0">◇</div>
                <div class="mconn" id="mconn-01">　</div>
                <div class="mslot" id="mslot-1" onclick="clickSlot(1)" style="cursor:pointer;">◇</div>
                <div class="mconn" id="mconn-12">　</div>
                <div class="mslot" id="mslot-2" onclick="clickSlot(2)" style="cursor:pointer;">◇</div>
                <div class="mconn" id="mconn-23">　</div>
                <div class="mslot" id="mslot-3" onclick="clickSlot(3)" style="cursor:pointer;">◇</div>
              </div>
              <div id="slot-prompt" style="display:none;font-size:0.78rem;color:var(--accent);margin-top:5px;font-weight:600;">
                ▲ 2つ目の技のスロット位置を選択してください
              </div>
              <input type="hidden" name="move2_slot" id="move2_slot_hidden" value="<?= htmlspecialchars(fv('move2_slot', '0')) ?>">
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
        <a href="/iv-report-list.php" style="color:var(--accent);font-size:0.88rem;text-decoration:none;">🕐 最近の投稿一覧を見る →</a>
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

  </main>

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
  if (initPot)     selectPot(initPot);
  if (initQuality) selectQuality(initQuality, initQColors[initQuality] || '#888');

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

  // ===== ポケモン絞り込みデータ =====
  const pokemonData = <?= json_encode(array_map(function($p) {
    return [
      'no'    => (int)$p['pokedex_no'],
      'name'  => $p['name'],
      'type1' => $p['type1'] ?? '',
      'type2' => $p['type2'] ?? '',
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

  function onPokemonSearchInput() {
    const val = document.getElementById('pokemon-search').value;
    document.getElementById('pokemon-search-clear').style.display = val ? 'block' : 'none';
    filterPokemon();
  }

  function clearPokemonSearch() {
    document.getElementById('pokemon-search').value = '';
    document.getElementById('pokemon-search-clear').style.display = 'none';
    filterPokemon();
  }

  function filterPokemon() {
    const query = (document.getElementById('pokemon-search').value || '').trim().toLowerCase();
    const sel = document.getElementById('pokemon-select');
    const currentVal = sel.value;
    while (sel.options.length > 1) sel.remove(1);
    pokemonData.forEach(p => {
      if (query) {
        const noStr = String(p.no).padStart(3, '0');
        const matchName = p.name.toLowerCase().includes(query);
        const matchNo   = noStr.includes(query) || String(p.no).includes(query);
        if (!matchName && !matchNo) return;
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
    el.textContent = '◆';
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
    move2SlotPos = idx;
    document.getElementById('move2_slot_hidden').value = idx;
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
      // 技２あるがスロット未選択: 位置を促す
      setSlotExt(1); setSlotExt(2); setSlotExt(3);
      setConn('mconn-01', 'dash');
      setConn('mconn-12', 'dash');
      setConn('mconn-23', 'dash');
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

  function changeLevel(delta) {
    const el  = document.getElementById('level-input');
    const val = Math.min(100, Math.max(1, (parseInt(el.value) || 100) + delta));
    el.value  = val;
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
      return;
    }
    hidden.value = pot;
    selector.classList.add('has-selection');
    document.querySelectorAll('.pot-btn').forEach(b => {
      b.classList.toggle('active-pot', b.dataset.pot === pot);
    });
    if (pot === 'なし') { disableQuality(); } else { enableQuality(); }
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
      if (rangedEl) {
        const isRanged = pokemonRangedMap[parseInt(opt.value)];
        rangedEl.textContent = isRanged ? '🏹' : '⚔️';
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
      if (rangedEl) rangedEl.style.visibility = 'hidden';
      if (typesEl) typesEl.innerHTML = '';
    }
  }
  pokemonSel.addEventListener('change', updateCardName);
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
    if (query) names = names.filter(name => name.includes(query));

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
    applyMoveFilter(n);
  }
  function clearMoveSearch(n) {
    document.getElementById('move' + n + '_search').value = '';
    document.getElementById('move' + n + '_search_clear').style.display = 'none';
    applyMoveFilter(n);
  }

  // 送信前バリデーション
  document.querySelector('form').addEventListener('submit', (e) => {
    if (!document.getElementById('pot_type_hidden').value) {
      e.preventDefault();
      alert('鍋の種類を選択してください。');
    }
  });
  </script>
</body>
</html>
