<?php
/**
 * IP範囲データ更新スクリプト
 *
 * APNIC から日本のIPv4範囲を取得してDBに保存する。
 * cronで月1回実行: 0 4 1 * * php /opt/asobi/shared/assets/php/update-ip-ranges.php
 *
 * 参考: https://ftp.apnic.net/apnic/stats/apnic/delegated-apnic-latest
 *   形式: apnic|JP|ipv4|203.104.128.0|16384|20060629|allocated
 *         レジストリ|国コード|タイプ|開始IP|個数|日付|ステータス
 */
require_once __DIR__ . '/users_db.php';

const APNIC_URL = 'https://ftp.apnic.net/apnic/stats/apnic/delegated-apnic-latest';

function log_msg(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

// 取得
log_msg('Downloading APNIC data...');
$ch = curl_init(APNIC_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CONNECTTIMEOUT => 10,
]);
$body = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($err || $httpCode !== 200 || !$body) {
    log_msg("ERROR: Download failed: " . ($err ?: "HTTP $httpCode"));
    exit(1);
}
log_msg('Downloaded ' . number_format(strlen($body)) . ' bytes');

// パース: 日本のIPv4のみ
$jpRanges = [];
$lines = explode("\n", $body);
foreach ($lines as $line) {
    if ($line === '' || $line[0] === '#' || strpos($line, '|') === false) continue;
    $cols = explode('|', $line);
    // apnic|JP|ipv4|start|count|date|status
    if (count($cols) < 7) continue;
    if ($cols[1] !== 'JP' || $cols[2] !== 'ipv4') continue;

    $startIp = $cols[3];
    $count = (int)$cols[4];
    $startNum = ip2long($startIp);
    if ($startNum === false || $count <= 0) continue;
    $endNum = $startNum + $count - 1;

    $jpRanges[] = [$startNum, $endNum];
}

if (empty($jpRanges)) {
    log_msg('ERROR: No JP IPv4 ranges found');
    exit(1);
}
log_msg('Parsed ' . number_format(count($jpRanges)) . ' JP IPv4 ranges');

// DB更新（既存の日本データを削除→新規投入）
$db = asobiUsersDb();
$db->beginTransaction();
try {
    $db->exec("DELETE FROM ip_ranges WHERE country = 'JP'");
    $stmt = $db->prepare("INSERT INTO ip_ranges (ip_start, ip_end, country) VALUES (?, ?, 'JP')");
    foreach ($jpRanges as [$s, $e]) {
        $stmt->execute([$s, $e]);
    }
    $db->commit();
    log_msg('DB updated: ' . count($jpRanges) . ' JP ranges');
} catch (Exception $e) {
    $db->rollBack();
    log_msg('ERROR: DB update failed: ' . $e->getMessage());
    exit(1);
}

// 統計
$total = (int)$db->query("SELECT COUNT(*) FROM ip_ranges WHERE country = 'JP'")->fetchColumn();
log_msg("Total JP ranges in DB: $total");
log_msg('Done.');
