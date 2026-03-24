<?php
/**
 * DB接続 - PDO抽象化レイヤー
 * SQLite / MySQL 切り替え対応
 */

function getDbConfig() {
    $configFile = __DIR__ . '/config.php';
    if (file_exists($configFile)) {
        return require $configFile;
    }
    // デフォルト: SQLite
    return [
        'driver' => 'sqlite',
        'path'   => __DIR__ . '/../data/dbd.sqlite',
    ];
}

function getDb() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $config = getDbConfig();

    if ($config['driver'] === 'sqlite') {
        $pdo = new PDO('sqlite:' . $config['path']);
    } elseif ($config['driver'] === 'mysql') {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
            $config['host'], $config['dbname']);
        $pdo = new PDO($dsn, $config['user'] ?? '', $config['pass'] ?? '');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $pdo;
}

function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
