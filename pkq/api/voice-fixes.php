<?php
/**
 * 音声認識補正辞書API
 * GET /api/voice-fixes.php
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://asobi.info', 'https://pkq.asobi.info', 'https://dbd.asobi.info'];
if (in_array($origin, $allowed)) {
    header("Access-Control-Allow-Origin: $origin");
}

try {
    $db = new PDO('sqlite:' . __DIR__ . '/../data/pokemon_quest.sqlite');
    $rows = $db->query('SELECT input_text, output_text, match_type, field_type FROM voice_fixes ORDER BY LENGTH(input_text) DESC')->fetchAll(PDO::FETCH_ASSOC);

    $exact = [];
    $replaceName = [];
    $replaceNum = [];
    foreach ($rows as $r) {
        if ($r['match_type'] === 'replace' && $r['field_type'] === 'number') {
            $replaceNum[] = [$r['input_text'], $r['output_text']];
        } elseif ($r['match_type'] === 'replace') {
            $replaceName[] = [$r['input_text'], $r['output_text']];
        } else {
            $exact[$r['input_text']] = $r['output_text'];
        }
    }

    echo json_encode([
        'exact' => $exact,
        'replaceName' => $replaceName,
        'replaceNum' => $replaceNum,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
