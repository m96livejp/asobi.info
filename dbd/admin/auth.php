<?php
require_once '/opt/asobi/shared/assets/php/auth.php';

// 後方互換エイリアス（既存コードで isLoggedIn() 等を使っているため）
function isLoggedIn(): bool { return asobiIsLoggedIn(); }
function requireLogin(): void { asobiRequireLoginApi(); }
function attemptLogin(string $password): bool { return asobiAttemptLogin($password); }
