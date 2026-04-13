<?php
/**
 * アセットバージョニング（キャッシュバスティング）
 * ファイル更新日時ベースの自動バージョン付与
 *
 * 使い方:
 *   require_once '/opt/asobi/shared/assets/php/version.php';
 *   <link href="/assets/css/common.css?v=<?= assetVer('/assets/css/common.css') ?>">
 *   <link href="/css/style.css?v=<?= assetVer('/css/style.css') ?>">
 *   <script src="https://asobi.info/assets/js/common.js?v=<?= assetVer('/assets/js/common.js') ?>"></script>
 */

function assetVer(string $urlPath): string {
    // クエリ文字列を除去
    $urlPath = strtok($urlPath, '?');

    // https://asobi.info/ プレフィックスを除去
    if (strpos($urlPath, 'https://asobi.info/') === 0) {
        $urlPath = substr($urlPath, strlen('https://asobi.info'));
    }

    // /assets/ → shared assets ディレクトリ
    if (strpos($urlPath, '/assets/') === 0) {
        $filePath = '/opt/asobi/shared' . $urlPath;
    } else {
        // サイトローカルファイル（DOCUMENT_ROOT基準）
        $filePath = ($_SERVER['DOCUMENT_ROOT'] ?? '') . $urlPath;
    }

    if (file_exists($filePath)) {
        return (string) filemtime($filePath);
    }

    // ファイルが見つからない場合は日付ベースのフォールバック
    return date('Ymd');
}
