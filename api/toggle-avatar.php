<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('token');
    session_start();
}

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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const ULTIMA_TOGGLE_HIDE_AVATAR_URL = 'https://app.ultima.cl/images/hide.png';

function ultimaAvatarToggleResponse(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ultimaIsAnonymousUsername($value): bool {
    return strcasecmp(trim((string)$value), 'Anonymous') === 0;
}

function ultimaIsHideAvatar($value): bool {
    return stripos(trim((string)$value), 'hide.png') !== false;
}

function ultimaFirstRealUsername(array $values): string {
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value !== '' && !ultimaIsAnonymousUsername($value)) {
            return $value;
        }
    }
    return '';
}

function ultimaFirstRealAvatar(array $values): string {
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value !== '' && !ultimaIsHideAvatar($value)) {
            return $value;
        }
    }
    return '';
}

function ultimaAvatarUrlForResponse(string $avatar, string $discordId): string {
    if ($avatar === '') {
        return '';
    }
    if (strpos($avatar, 'https://') === 0 || strpos($avatar, '/images/') === 0) {
        return $avatar;
    }
    if ($discordId === '') {
        return $avatar;
    }
    $extension = strpos($avatar, 'a_') === 0 ? 'gif' : 'png';
    return 'https://cdn.discordapp.com/avatars/' . $discordId . '/' . $avatar . '.' . $extension;
}

if (session_name() !== 'token') {
    ultimaAvatarToggleResponse(['success' => false, 'error' => 'Unauthorized'], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ultimaAvatarToggleResponse(['success' => false, 'error' => 'Invalid method'], 405);
}

$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    ultimaAvatarToggleResponse(['success' => false, 'error' => 'Invalid content type'], 415);
}

$authCode = trim((string)($_SESSION['auth_code'] ?? ''));
if ($authCode === '' || strlen($authCode) > 512) {
    ultimaAvatarToggleResponse(['success' => false, 'error' => 'Unauthorized'], 401);
}

$body = file_get_contents('php://input');
if (!is_string($body) || strlen($body) > 4096) {
    ultimaAvatarToggleResponse(['success' => false, 'error' => 'Invalid request'], 400);
}

$data = json_decode($body, true);
if (!is_array($data) || !array_key_exists('hide', $data)) {
    ultimaAvatarToggleResponse(['success' => false, 'error' => 'Invalid request'], 400);
}

$hide = filter_var($data['hide'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($hide === null) {
    ultimaAvatarToggleResponse(['success' => false, 'error' => 'Invalid hide state'], 400);
}

$userQuery = 'SELECT auth_code, link_id, discord_avatar, original_avatar, discord_id, avatar_hidden, discord_username, original_username
              FROM regular WHERE auth_code = :auth_code LIMIT 2';

try {
    $userResult = executeSafeQuery($userQuery, [':auth_code' => $authCode]);
} catch (\Throwable $e) {
    ultimaAvatarToggleResponse(['success' => false, 'error' => 'Service unavailable'], 503);
}

if (!is_array($userResult) || count($userResult) !== 1 || !is_array($userResult[0])) {
    ultimaAvatarToggleResponse(['success' => false, 'error' => 'Unauthorized'], 401);
}

$user = $userResult[0];
$storedAuthCode = (string)($user['auth_code'] ?? '');
$linkId = (int)($user['link_id'] ?? 0);
if ($storedAuthCode === '' || !hash_equals($storedAuthCode, $authCode) || $linkId <= 0) {
    ultimaAvatarToggleResponse(['success' => false, 'error' => 'Unauthorized'], 401);
}

$currentAvatar = trim((string)($user['discord_avatar'] ?? ''));
$currentUsername = trim((string)($user['discord_username'] ?? ''));
$originalAvatar = trim((string)($user['original_avatar'] ?? ''));
$originalUsername = trim((string)($user['original_username'] ?? ''));
$discordId = trim((string)($user['discord_id'] ?? ''));
$sessionDiscordId = trim((string)($_SESSION['discord_user_id'] ?? ''));
$sessionIdentityMatches = $discordId !== '' && $sessionDiscordId !== '' && hash_equals($discordId, $sessionDiscordId);
$sessionAvatar = $sessionIdentityMatches ? trim((string)($_SESSION['discord_avatar'] ?? '')) : '';
$sessionUsername = $sessionIdentityMatches ? trim((string)($_SESSION['discord_username'] ?? '')) : '';
$currentlyHidden = ultimaIsAnonymousUsername($currentUsername) || ultimaIsHideAvatar($currentAvatar);
$usernameSources = $currentlyHidden
    ? [$originalUsername, $currentUsername, $sessionUsername]
    : [$currentUsername, $originalUsername, $sessionUsername];
$avatarSources = $currentlyHidden
    ? [$originalAvatar, $currentAvatar, $sessionAvatar]
    : [$currentAvatar, $originalAvatar, $sessionAvatar];
$realUsername = ultimaFirstRealUsername($usernameSources);
$realAvatar = ultimaFirstRealAvatar($avatarSources);

if ($realUsername === '') {
    ultimaAvatarToggleResponse([
        'success' => false,
        'error' => 'Original Discord username is missing. Reconnect Discord once, then toggle again.',
    ], 409);
}

try {
    if ($hide) {
        executeSafeQuery(
            'UPDATE regular
             SET original_avatar = :original_avatar,
                 original_username = :original_username,
                 discord_avatar = :hidden_avatar,
                 discord_username = :hidden_username,
                 avatar_hidden = 1
             WHERE auth_code = :auth_code AND link_id = :link_id
             LIMIT 1',
            [
                ':original_avatar' => $realAvatar,
                ':original_username' => $realUsername,
                ':hidden_avatar' => ULTIMA_TOGGLE_HIDE_AVATAR_URL,
                ':hidden_username' => 'Anonymous',
                ':auth_code' => $authCode,
                ':link_id' => $linkId,
            ]
        );
    } else {
        executeSafeQuery(
            'UPDATE regular
             SET discord_avatar = :discord_avatar,
                 discord_username = :discord_username,
                 avatar_hidden = 0
             WHERE auth_code = :auth_code AND link_id = :link_id
             LIMIT 1',
            [
                ':discord_avatar' => $realAvatar,
                ':discord_username' => $realUsername,
                ':auth_code' => $authCode,
                ':link_id' => $linkId,
            ]
        );
    }

    $finalResult = executeSafeQuery($userQuery, [':auth_code' => $authCode]);
} catch (\Throwable $e) {
    ultimaAvatarToggleResponse(['success' => false, 'error' => 'Profile update failed'], 500);
}

if (!is_array($finalResult) || count($finalResult) !== 1 || !is_array($finalResult[0])) {
    ultimaAvatarToggleResponse(['success' => false, 'error' => 'Profile update could not be verified'], 500);
}

$final = $finalResult[0];
$finalAuthCode = (string)($final['auth_code'] ?? '');
$finalLinkId = (int)($final['link_id'] ?? 0);
$finalUsername = trim((string)($final['discord_username'] ?? ''));
$finalAvatar = trim((string)($final['discord_avatar'] ?? ''));
$finalOriginalUsername = trim((string)($final['original_username'] ?? ''));
$finalOriginalAvatar = trim((string)($final['original_avatar'] ?? ''));
$finalDiscordId = trim((string)($final['discord_id'] ?? $discordId));
$finalHidden = (int)($final['avatar_hidden'] ?? 0) === 1;

if ($finalAuthCode === '' || !hash_equals($finalAuthCode, $authCode) || $finalLinkId !== $linkId) {
    ultimaAvatarToggleResponse(['success' => false, 'error' => 'Profile update could not be verified'], 500);
}

$stateMatches = $hide
    ? $finalHidden && ultimaIsAnonymousUsername($finalUsername) && ultimaIsHideAvatar($finalAvatar) && hash_equals($realUsername, $finalOriginalUsername) && hash_equals($realAvatar, $finalOriginalAvatar)
    : !$finalHidden && hash_equals($realUsername, $finalUsername) && hash_equals($realAvatar, $finalAvatar);

if (!$stateMatches) {
    ultimaAvatarToggleResponse(['success' => false, 'error' => 'Profile update could not be verified'], 500);
}

$_SESSION['discord_username'] = $realUsername;
$_SESSION['discord_avatar'] = $realAvatar;

ultimaAvatarToggleResponse([
    'success' => true,
    'hidden' => $finalHidden,
    'username' => $finalUsername,
    'discord_username' => $finalUsername,
    'avatar_url' => ultimaAvatarUrlForResponse($finalAvatar, $finalDiscordId),
    'discord_avatar' => $finalAvatar,
    'original_username' => $finalOriginalUsername,
    'original_avatar_url' => ultimaAvatarUrlForResponse($finalOriginalAvatar, $finalDiscordId),
]);
