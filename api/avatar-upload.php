<?php
$avatarUploadSecret = 'f4872e18ce2a79f219a56eaa3534c39a6a1bbf4d0d71db91dfc5c8873afab7de';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$fail = function ($status, $error, array $details = []) {
    $context = [
        'status' => $status,
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 180)
    ];
    if (!empty($details)) $context['details'] = $details;
    error_log('avatar-upload.php error ' . json_encode(['error' => $error, 'context' => $context], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    http_response_code($status);
    echo json_encode(['error' => $error], JSON_UNESCAPED_SLASHES);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $fail(405, 'method_not_allowed');
}

$headerSecret = $_SERVER['HTTP_X_AVATAR_SECRET'] ?? '';
if (!is_string($headerSecret) || !hash_equals($avatarUploadSecret, $headerSecret)) {
    $fail(403, 'invalid_secret');
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $fail(400, 'bad_json', ['json_error' => json_last_error_msg(), 'input_length' => strlen((string) $raw)]);
}

$name = basename((string) ($input['name'] ?? ''));
$encoded = (string) ($input['data'] ?? '');
if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|gif)$/', $name)) {
    $fail(400, 'bad_name');
}

$bytes = base64_decode($encoded, true);
if (!is_string($bytes) || $bytes === '') {
    $fail(400, 'bad_data');
}
if (strlen($bytes) > 4 * 1024 * 1024) {
    $fail(413, 'too_large');
}

$info = @getimagesizefromstring($bytes);
$allow = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
if ($info === false || !isset($allow[$info[2]])) {
    $fail(400, 'invalid_image');
}

$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if ($allow[$info[2]] !== $ext) {
    $fail(400, 'extension_mismatch');
}

$dir = dirname(__DIR__) . '/images/avatars';
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
    $fail(500, 'mkdir_failed', ['dir' => $dir]);
}

$path = $dir . '/' . $name;
if (@file_put_contents($path, $bytes, LOCK_EX) === false) {
    $fail(500, 'save_failed', ['path' => $path]);
}
@chmod($path, 0644);

echo json_encode(['ok' => true, 'url' => 'https://app.ultima.cl/images/avatars/' . $name], JSON_UNESCAPED_SLASHES);
