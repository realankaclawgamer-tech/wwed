<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$fail = function ($status, $error, array $logContext = [], array $responseExtra = []) {
    $context = [
        'status' => $status,
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 180),
    ];
    if (!empty($logContext)) {
        $context['details'] = $logContext;
    }
    error_log('chrome.php error ' . json_encode(['error' => $error, 'context' => $context], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    http_response_code($status);
    echo json_encode(array_merge(['error' => $error], $responseExtra));
    exit();
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $fail(405, 'method_not_allowed');
}

function chromeTokenFile()
{
    $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__));
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ultima_chrome_tokens_' . substr(hash('sha256', $root), 0, 16) . '.json';
}

$token = $_SERVER['HTTP_X_ENC_TOKEN'] ?? '';
if (empty($token)) {
    $fail(403, 'missing_token');
}

$isOneTimeToken = strncmp($token, 'ot_', 3) === 0;

if (!$isOneTimeToken) {
    $fail(403, 'invalid_token_type', ['has_header_token' => true]);
}

if ($isOneTimeToken) {
    $tokenFile = chromeTokenFile();
    $tokenHandle = @fopen($tokenFile, 'c+');
    if (!$tokenHandle) {
        $fail(500, 'token_store_unavailable', ['token_file' => $tokenFile, 'tmp_dir' => sys_get_temp_dir()]);
    }
    $storedKey = null;
    if (@flock($tokenHandle, LOCK_EX)) {
        $tokenRaw = stream_get_contents($tokenHandle);
        $tokenData = json_decode($tokenRaw, true);
        if (!is_array($tokenData)) $tokenData = [];
        $tokenHash = hash('sha256', $token);
        if (isset($tokenData[$tokenHash]['key'])) {
            $decodedKey = base64_decode((string) $tokenData[$tokenHash]['key'], true);
            if (is_string($decodedKey) && strlen($decodedKey) === 32) {
                $storedKey = $decodedKey;
            }
            unset($tokenData[$tokenHash]);
        }
        rewind($tokenHandle);
        ftruncate($tokenHandle, 0);
        fwrite($tokenHandle, json_encode($tokenData, JSON_UNESCAPED_SLASHES));
        fflush($tokenHandle);
        flock($tokenHandle, LOCK_UN);
    }
    fclose($tokenHandle);
    if ($storedKey === null) {
        $fail(403, 'token_not_found_or_used', ['token_store_exists' => is_file($tokenFile)]);
    }
    $encMasterKey = $storedKey;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    $fail(400, 'bad_input', ['json_error' => json_last_error_msg(), 'input_length' => strlen($rawInput)]);
}

$clientPubKey = isset($input['pub']) ? trim($input['pub']) : '';

$aesKey = $encMasterKey;

if (empty($aesKey)) {
    $fail(403, 'no_key_for_page');
}

if (empty($clientPubKey)) {
    $fail(400, 'missing_pub');
}

$derBytes = base64_decode($clientPubKey, true);
if ($derBytes === false || strlen($derBytes) < 32) {
    $fail(400, 'bad_b64', ['pub_length' => strlen($clientPubKey)]);
}

$pemKey = "-----BEGIN PUBLIC KEY-----\n"
        . wordwrap(base64_encode($derBytes), 64, "\n", true)
        . "\n-----END PUBLIC KEY-----";

$pubKeyRes = openssl_pkey_get_public($pemKey);
if (!$pubKeyRes) {
    $opensslErrors = [];
    while ($opensslError = openssl_error_string()) {
        $opensslErrors[] = $opensslError;
    }
    $fail(400, 'bad_key', ['openssl_errors' => $opensslErrors]);
}

$encryptedKey = '';
$success = openssl_public_encrypt($aesKey, $encryptedKey, $pubKeyRes, OPENSSL_PKCS1_OAEP_PADDING);

if (!$success) {
    $opensslErrors = [];
    while ($opensslError = openssl_error_string()) {
        $opensslErrors[] = $opensslError;
    }
    $fail(500, 'enc_fail', ['openssl_errors' => $opensslErrors]);
}

echo json_encode(['k' => base64_encode($encryptedKey)]);