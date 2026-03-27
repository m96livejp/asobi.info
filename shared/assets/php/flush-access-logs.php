<?php
/**
 * アクセスログバッファをSQLiteに一括投入する
 * cronで5分おきに実行: * /5 * * * * php /opt/asobi/shared/assets/php/flush-access-logs.php
 */
$dbPath  = '/opt/asobi/data/users.sqlite';
$logFile = '/opt/asobi/data/access_log_buffer.jsonl';

if (!file_exists($logFile) || filesize($logFile) === 0) exit;

// バッファをアトミックにリネームして読み取り
$tmp = $logFile . '.processing';
if (!@rename($logFile, $tmp)) exit;

$lines = file($tmp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (empty($lines)) { @unlink($tmp); exit; }

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=10000');

    $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO access_logs (host, path, user_id, ip, referer, user_agent, browser, device, os, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (!$row) continue;
        $stmt->execute([
            $row['host'] ?? '',
            $row['path'] ?? '/',
            $row['user_id'] ?? null,
            $row['ip'] ?? '',
            $row['referer'] ?? '',
            $row['user_agent'] ?? '',
            $row['browser'] ?? '',
            $row['device'] ?? '',
            $row['os'] ?? '',
            $row['created_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }
    $db->commit();
    @unlink($tmp);
    echo count($lines) . " logs flushed\n";
} catch (Exception $e) {
    // 失敗時はバッファを戻す
    if (file_exists($tmp)) {
        @file_put_contents($logFile, file_get_contents($tmp), FILE_APPEND | LOCK_EX);
        @unlink($tmp);
    }
    echo "Error: " . $e->getMessage() . "\n";
}
