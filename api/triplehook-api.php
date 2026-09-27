<?php
if (session_status() === PHP_SESSION_NONE) {
    $sessionLifetime = 365 * 24 * 60 * 60;
    $secure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    );

    session_name('token');
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
}
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

$authCode = trim((string)($_SESSION['auth_code'] ?? ''));
if ($authCode === '') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
$authRows = executeSafeQuery(
    'SELECT auth_code, link_id FROM regular WHERE auth_code = :auth_code LIMIT 2',
    [':auth_code' => $authCode]
);
if (!is_array($authRows) || count($authRows) !== 1 || !is_array($authRows[0])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
$stored = (string)($authRows[0]['auth_code'] ?? '');
$link_id = (int)($authRows[0]['link_id'] ?? 0);
if ($stored === '' || !hash_equals($stored, $authCode) || $link_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

function validateTriplehookWebhook($webhookUrl, $link_id) {
    if (empty($webhookUrl)) {
        return ['valid' => true];
    }
    
    $pattern = '/^https:\/\/(discord\.com|discordapp\.com)\/api\/webhooks\/\d+\/[\w-]+$/';
    if (!preg_match($pattern, $webhookUrl)) {
        return ['valid' => false, 'message' => 'Invalid webhook format'];
    }
    
    $checkQuery = "SELECT link_id FROM triplehook_data WHERE webhook = :webhook AND link_id != :link_id LIMIT 1";
    $checkResult = executeSafeQuery($checkQuery, [
        ':webhook' => $webhookUrl,
        ':link_id' => $link_id
    ]);
    
    if (!empty($checkResult)) {
        return ['valid' => false, 'message' => 'This webhook is already in use'];
    }
    
    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return ['valid' => false, 'message' => 'Webhook is not active or invalid'];
    }
    
    return ['valid' => true];
}

try {
    if (isset($input['directory_name']) && !empty($input['directory_name'])) {
        $dirName = trim($input['directory_name']);
        
        if (!preg_match('/^[a-zA-Z0-9]+$/', $dirName)) {
            echo json_encode(['success' => false, 'message' => 'Directory name can only contain letters and numbers']);
            exit;
        }
        
        $checkDirQuery = "SELECT link_id FROM triplehook_data WHERE directory_name = :directory_name AND link_id != :link_id LIMIT 1";
        $checkDirResult = executeSafeQuery($checkDirQuery, [
            ':directory_name' => $dirName,
            ':link_id' => $link_id
        ]);
        
        if (!empty($checkDirResult)) {
            echo json_encode(['success' => false, 'message' => 'This directory name is already taken']);
            exit;
        }
    }
    
    if (isset($input['triplehook_webhook'])) {
        $webhookValidation = validateTriplehookWebhook($input['triplehook_webhook'], $link_id);
        
        if (!$webhookValidation['valid']) {
            echo json_encode(['success' => false, 'message' => $webhookValidation['message']]);
            exit;
        }
    }
    
    $checkQuery = "SELECT id FROM triplehook_data WHERE link_id = :link_id LIMIT 1";
    $checkResult = executeSafeQuery($checkQuery, [':link_id' => $link_id]);
    
    if (empty($checkResult)) {
        $insertQuery = "INSERT INTO triplehook_data (link_id, embed_color) VALUES (:link_id, :embed_color)";
        executeSafeQuery($insertQuery, [':link_id' => $link_id, ':embed_color' => '#d90404']);
    }
    
    $allowedFields = ['directory_name', 'display_name', 'invite_url', 'embed_color', 'thumbnail_url', 'webhook'];
    $updateFields = [];
    $updateParams = [':link_id' => $link_id];
    
    $fieldMapping = [
        'directory_name' => 'directory_name',
        'display_name' => 'display_name',
        'invite_url' => 'invite_url',
        'embed_color' => 'embed_color',
        'thumbnail_url' => 'thumbnail_url',
        'triplehook_webhook' => 'webhook'
    ];
    
    foreach ($input as $field => $value) {
        $dbField = $fieldMapping[$field] ?? $field;
        if (in_array($dbField, $allowedFields)) {
            $updateFields[] = "$dbField = :$dbField";
            $updateParams[":$dbField"] = $value;
        }
    }
    
    if (!empty($updateFields)) {
        $updateQuery = "UPDATE triplehook_data SET " . implode(', ', $updateFields) . " WHERE link_id = :link_id";
        executeSafeQuery($updateQuery, $updateParams);
    }
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}