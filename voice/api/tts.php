<?php
/**
 * 共通TTS API エントリーポイント
 * POST /voice/api/tts.php
 *
 * リクエスト (JSON):
 *   text    : string (必須, 最大200文字)
 *   speaker : int    (省略時: デフォルト話者)
 *   speed   : float  (省略時: 1.0)
 *   engine  : string (省略時: "voicevox")
 *
 * レスポンス: audio/wav バイナリ or JSON {"error": "..."}
 */

// CORS
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('/^https:\/\/([a-z0-9-]+\.)?asobi\.info$/', $origin)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Credentials: true');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// POST のみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// 認証
require_once '/opt/asobi/shared/assets/php/auth.php';
if (!asobiIsLoggedIn()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Login required']);
    exit;
}

// リクエスト解析
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['text'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'text is required']);
    exit;
}

$text = mb_substr(trim($input['text']), 0, 200);
$speaker = isset($input['speaker']) ? (int)$input['speaker'] : null;
$speed = isset($input['speed']) ? (float)$input['speed'] : 1.0;
$speed = max(0.5, min(2.0, $speed));
$engine = $input['engine'] ?? 'voicevox';

// エンジン振り分け
switch ($engine) {
    case 'voicevox':
        require_once __DIR__ . '/../voicevox/synthesize.php';
        $speakerId = $speaker ?? VOICEVOX_DEFAULT_SPEAKER;
        $result = voicevox_synthesize($text, $speakerId, $speed);
        break;
    default:
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => "Unknown engine: $engine"]);
        exit;
}

// レスポンス
if (!$result['ok']) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => $result['error']]);
    exit;
}

header('Content-Type: audio/wav');
header('Content-Length: ' . strlen($result['wav']));
header('Cache-Control: no-store');
echo $result['wav'];
