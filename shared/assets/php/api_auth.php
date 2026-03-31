<?php
/**
 * APIキー認証モジュール - asobi.info
 *
 * site_settings テーブルの api_key で Bearer トークン認証を行う。
 * 使用方法:
 *   require_once '/opt/asobi/shared/assets/php/api_auth.php';
 *   asobiRequireApiKey();
 */

require_once __DIR__ . '/users_db.php';

function asobiRequireApiKey(): void {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    // Apache が Authorization を渡さない場合の fallback
    if (!$header && function_exists('getallheaders')) {
        $all = getallheaders();
        $header = $all['Authorization'] ?? $all['authorization'] ?? '';
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        _asobiApiError(401, 'API key required');
    }

    $provided = trim($m[1]);
    $db = asobiUsersDb();
    $stmt = $db->prepare("SELECT value FROM site_settings WHERE key = 'api_key'");
    $stmt->execute();
    $row = $stmt->fetch();

    if (!$row || !hash_equals($row['value'], $provided)) {
        _asobiApiError(401, 'Invalid API key');
    }
}

function _asobiApiError(int $code, string $message): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
    }
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function _asobiApiJson(array $data, int $code = 200): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
