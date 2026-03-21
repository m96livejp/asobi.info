<?php
// auth/login.php → トップレベルの login.php へ転送
header('Location: /login.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
exit;
