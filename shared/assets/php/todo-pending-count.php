<?php
/**
 * 未処理TODO件数を返す（TODOモニター用）
 * 標準出力に数字のみ出力
 */
require_once __DIR__ . '/users_db.php';
$db = asobiTodosDb();
$count = $db->query("SELECT COUNT(*) FROM content_todos WHERE status IN ('未着手','対応中')")->fetchColumn();
echo $count;
