<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('token');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

$_SESSION = [];

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$clearCookie = function ($name) use ($secure) {
    setcookie($name, '', [
        'expires' => time() - 86400,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    unset($_COOKIE[$name]);
};

if (ini_get('session.use_cookies')) {
    $clearCookie('token');
    $clearCookie(session_name());
    $clearCookie('PHPSESSID');
}
session_destroy();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => true, 'redirect' => '/']);
    exit;
}

header('Location: /');
exit;