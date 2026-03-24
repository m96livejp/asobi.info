<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
asobiLogout();
header('Location: https://asobi.info/');
exit;
