<?php
/**
 * IPアドレス国別判定
 *
 * 使用方法:
 *   require_once '/opt/asobi/shared/assets/php/ip_country.php';
 *   $country = ipCountry('203.104.209.71');  // 'JP' または 'OTHER'
 */
require_once __DIR__ . '/users_db.php';

/**
 * IPアドレスから国コードを判定（IPv4のみ対応）
 *
 * @param string $ip IPv4アドレス
 * @return string 'JP' / 'OTHER' / '' (無効IP)
 */
function ipCountry(string $ip): string {
    // IPv4でなければ判定不可（IPv6は'OTHER'扱い）
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        // IPv6の場合は 'OTHER' として記録（今後対応可）
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 'OTHER';
        }
        return '';
    }

    // プライベートIPは社内/開発環境扱いで 'JP' として判定
    // （10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 127.0.0.0/8）
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return 'JP';
    }

    $num = ip2long($ip);
    if ($num === false) return '';

    // キャッシュ（同一リクエスト内の重複判定防止）
    static $cache = [];
    if (isset($cache[$ip])) return $cache[$ip];

    $db = asobiUsersDb();
    $stmt = $db->prepare("SELECT country FROM ip_ranges WHERE ? BETWEEN ip_start AND ip_end LIMIT 1");
    // SQLiteは32bit符号付きINTEGERで問題なくINTを扱えるが、念のため文字列で渡す
    $stmt->execute([$num]);
    $country = $stmt->fetchColumn();
    $result = $country ?: 'OTHER';
    $cache[$ip] = $result;
    return $result;
}
