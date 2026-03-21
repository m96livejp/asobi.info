<?php
require_once '/home/m96/asobi.info/public_html/assets/php/auth.php';

// 後方互換エイリアス（既存コードで isLoggedIn() 等を使っているため）
function isLoggedIn(): bool { return asobiIsLoggedIn(); }
function requireLogin(): void { asobiRequireLoginApi(); }
function attemptLogin(string $password): bool { return asobiAttemptLogin($password); }
