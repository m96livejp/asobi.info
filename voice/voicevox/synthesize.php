<?php
/**
 * VOICEVOX合成処理
 * VOICEVOXエンジン(localhost:50021)にリクエストしてWAV音声を返す
 */

define('VOICEVOX_HOST', 'http://127.0.0.1:50021');
define('VOICEVOX_TIMEOUT', 30);
define('VOICEVOX_DEFAULT_SPEAKER', 3);

/**
 * テキストからWAV音声を合成
 *
 * @param string $text 読み上げテキスト
 * @param int $speaker 話者ID
 * @param float $speed 速度倍率
 * @return array ['ok' => bool, 'wav' => string|null, 'error' => string|null]
 */
function voicevox_synthesize(string $text, int $speaker = VOICEVOX_DEFAULT_SPEAKER, float $speed = 1.0): array {
    // 1. audio_query: テキスト → クエリJSON
    $queryUrl = VOICEVOX_HOST . '/audio_query?speaker=' . $speaker . '&text=' . rawurlencode($text);
    $ch = curl_init($queryUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => VOICEVOX_TIMEOUT,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => '',
    ]);
    $queryResult = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($queryResult === false || $httpCode !== 200) {
        return ['ok' => false, 'wav' => null, 'error' => 'VOICEVOX audio_query failed: ' . ($curlError ?: "HTTP $httpCode")];
    }

    // 2. speedScale を調整
    $queryJson = json_decode($queryResult, true);
    if (!$queryJson) {
        return ['ok' => false, 'wav' => null, 'error' => 'Invalid audio_query response'];
    }
    $queryJson['speedScale'] = $speed;

    // 3. synthesis: クエリJSON → WAV音声
    $synthUrl = VOICEVOX_HOST . '/synthesis?speaker=' . $speaker;
    $ch = curl_init($synthUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => VOICEVOX_TIMEOUT,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($queryJson, JSON_UNESCAPED_UNICODE),
    ]);
    $wavData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($wavData === false || $httpCode !== 200) {
        return ['ok' => false, 'wav' => null, 'error' => 'VOICEVOX synthesis failed: ' . ($curlError ?: "HTTP $httpCode")];
    }

    return ['ok' => true, 'wav' => $wavData, 'error' => null];
}
