<?php
/**
 * API利用ログ記録（全サイト共通）
 *
 * Python側 (aic/app/services/__init__.py) と同じ JSON Lines 形式で書き込む。
 * api-status.php がこのファイルをインポートして SQLite に取り込む。
 *
 * 使い方:
 *   require_once '/opt/asobi/shared/assets/php/api_usage_log.php';
 *   appendApiUsageLog([
 *       'site'     => 'image',
 *       'endpoint' => '/api/queue-worker',
 *       'type'     => 'image',       // chat, image, tts, translate, review
 *       'provider' => 'forge',
 *       'model'    => 'animagine-xl',
 *       'user_id'  => 123,
 *   ]);
 */

define('API_USAGE_LOG_PATH', '/opt/asobi/aic/data/api_usage.log');

function appendApiUsageLog(array $entry): void {
    if (empty($entry['ts'])) {
        $entry['ts'] = date('Y-m-d H:i:s');
    }
    // デフォルト値
    $defaults = [
        'type' => '', 'site' => '', 'endpoint' => '',
        'user_id' => null, 'username' => '', 'char_name' => '',
        'provider' => '', 'model' => '',
        'input_chars' => 0, 'output_chars' => 0,
        'ip' => '', 'user_agent' => '',
        'cost' => 0, 'currency' => 'points',
    ];
    $entry = array_merge($defaults, $entry);

    $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents(API_USAGE_LOG_PATH, $line, FILE_APPEND | LOCK_EX);
}
