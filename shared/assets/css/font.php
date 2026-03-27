<?php
/**
 * フォント設定CSS配信エンドポイント
 * サブドメインからのクロスオリジンリクエストに対応
 */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://asobi.info', 'https://www.asobi.info', 'https://pkq.asobi.info', 'https://dbd.asobi.info', 'https://tbt.asobi.info', 'https://aic.asobi.info'];
if (in_array($origin, $allowed)) {
    header("Access-Control-Allow-Origin: $origin");
}

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$base = '/opt/asobi/data/users.sqlite';
$family = 'migu-1c';
$format = 'woff2';

try {
    $db = new PDO('sqlite:' . $base, '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $rows = $db->query("SELECT key, value FROM site_settings WHERE key IN ('font_family','font_format')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $family = $rows['font_family'] ?? $family;
    $format = $rows['font_format'] ?? $format;
} catch (Exception $e) {}

if ($family === 'none') {
    echo '/* フォント: システムデフォルト */';
    exit;
}

$ext  = $format === 'woff2' ? 'woff2' : 'ttf';
$fmt  = $format === 'woff2' ? 'woff2' : 'truetype';
$base_url = 'https://asobi.info/assets/fonts';

echo "@font-face {
    font-family: 'MiguFont';
    src: url('{$base_url}/{$family}-regular.{$ext}') format('{$fmt}');
    font-weight: normal;
    font-style: normal;
    font-display: swap;
}
@font-face {
    font-family: 'MiguFont';
    src: url('{$base_url}/{$family}-bold.{$ext}') format('{$fmt}');
    font-weight: bold;
    font-style: normal;
    font-display: swap;
}
main, main input, main button, main select, main textarea {
    font-family: 'MiguFont', system-ui, -apple-system, sans-serif;
}
/* 0.9rem未満の小さい文字をBoldで読みやすく */
[style*='font-size:0.6'],[style*='font-size:0.7'],[style*='font-size:0.8'],
[style*='font-size: 0.6'],[style*='font-size: 0.7'],[style*='font-size: 0.8'] {
    font-weight: 700 !important;
}";
