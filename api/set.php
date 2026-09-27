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

function setDebugLog($event, $context = []) {
    $safeContext = [];

    foreach ($context as $key => $value) {
        if (is_bool($value)) {
            $safeContext[$key] = $value ? 1 : 0;
        } elseif (is_scalar($value) || $value === null) {
            $safeContext[$key] = $value;
        } else {
            $safeContext[$key] = json_encode($value);
        }
    }

    error_log('[set.php] ' . $event . ' ' . json_encode($safeContext, JSON_UNESCAPED_SLASHES));
}

function setCookieDebugInfo() {
    $sessionName = session_name();
    $rawCookie = $_SERVER['HTTP_COOKIE'] ?? '';
    $sessionCookieHashes = [];

    if ($rawCookie !== '') {
        preg_match_all(
            '/(?:^|;\s*)' . preg_quote($sessionName, '/') . '=([^;]*)/',
            $rawCookie,
            $matches
        );

        foreach ($matches[1] as $cookieValue) {
            $sessionCookieHashes[] = substr(hash('sha256', urldecode($cookieValue)), 0, 12);
        }
    }

    return [
        'session_name' => $sessionName,
        'cookie_names' => array_keys($_COOKIE),
        'session_cookie_present' => isset($_COOKIE[$sessionName]),
        'session_cookie_hash' => isset($_COOKIE[$sessionName]) ? substr(hash('sha256', $_COOKIE[$sessionName]), 0, 12) : '',
        'session_cookie_count_raw' => count($sessionCookieHashes),
        'session_cookie_hashes_raw' => $sessionCookieHashes
    ];
}

function getRawSessionCookieValues() {
    $sessionName = session_name();
    $rawCookie = $_SERVER['HTTP_COOKIE'] ?? '';
    $values = [];

    if ($rawCookie === '') {
        return $values;
    }

    preg_match_all(
        '/(?:^|;\s*)' . preg_quote($sessionName, '/') . '=([^;]*)/',
        $rawCookie,
        $matches
    );

    foreach ($matches[1] as $cookieValue) {
        $decoded = urldecode($cookieValue);
        if ($decoded !== '' && !in_array($decoded, $values, true)) {
            $values[] = $decoded;
        }
    }

    return $values;
}

function persistCurrentSessionCookieRootPath() {
    if (headers_sent() || session_id() === '') {
        return;
    }

    $sessionLifetime = 365 * 24 * 60 * 60;
    $secure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    );

    if (PHP_VERSION_ID >= 70300) {
        setcookie(session_name(), session_id(), [
            'expires' => time() + $sessionLifetime,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } else {
        setcookie(session_name(), session_id(), time() + $sessionLifetime, '/', '', $secure, true);
    }
}

function resolveLinkIdFromAuthCodeValue($auth_code, $source) {
    $auth_code = trim((string)$auth_code);
    $authCodeHash = substr(hash('sha256', $auth_code), 0, 12);

    if (!preg_match('/^[A-Za-z0-9_-]{20,256}$/', $auth_code)) {
        setDebugLog('invalid_auth_code_format', [
            'source' => $source,
            'auth_code_len' => strlen($auth_code),
            'auth_code_hash' => $authCodeHash
        ]);

        return null;
    }

    try {
        $result = executeSafeQuery(
            "SELECT link_id FROM regular WHERE auth_code = :auth_code LIMIT 1",
            [':auth_code' => $auth_code]
        );
    } catch (Exception $e) {
        setDebugLog('auth_code_lookup_exception', [
            'source' => $source,
            'auth_code_len' => strlen($auth_code),
            'auth_code_hash' => $authCodeHash,
            'error' => $e->getMessage()
        ]);

        return null;
    }

    setDebugLog('auth_code_lookup_result', [
        'source' => $source,
        'auth_code_len' => strlen($auth_code),
        'auth_code_hash' => $authCodeHash,
        'rows' => is_array($result) ? count($result) : 0,
        'has_link_id' => !empty($result[0]['link_id'])
    ]);

    if (empty($result) || empty($result[0]['link_id'])) {
        return null;
    }

    return $result[0]['link_id'];
}

function resolveLinkIdFromCookieSessionCandidates() {
    $originalSessionId = session_id();
    $sessionCandidates = getRawSessionCookieValues();
    $candidateHashes = [];

    foreach ($sessionCandidates as $candidate) {
        $candidateHashes[] = substr(hash('sha256', $candidate), 0, 12);
    }

    setDebugLog('session_cookie_candidates', [
        'candidate_count' => count($sessionCandidates),
        'candidate_hashes' => $candidateHashes,
        'original_session_hash' => substr(hash('sha256', $originalSessionId), 0, 12)
    ]);

    foreach ($sessionCandidates as $candidate) {
        if ($candidate === $originalSessionId) {
            continue;
        }

        if (!preg_match('/^[A-Za-z0-9,-]{16,128}$/', $candidate)) {
            setDebugLog('candidate_session_skipped_invalid_id', [
                'candidate_hash' => substr(hash('sha256', $candidate), 0, 12),
                'candidate_len' => strlen($candidate)
            ]);
            continue;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_id($candidate);
        session_start();

        setDebugLog('candidate_session_loaded', [
            'candidate_hash' => substr(hash('sha256', session_id()), 0, 12),
            'session_keys' => array_keys($_SESSION),
            'has_link_id' => !empty($_SESSION['link_id']),
            'has_auth_code' => !empty($_SESSION['auth_code'])
        ]);

        if (!empty($_SESSION['auth_code'])) {
            $linkId = resolveLinkIdFromAuthCodeValue($_SESSION['auth_code'], 'candidate_session');

            if ($linkId) {
                $_SESSION['link_id'] = $linkId;
                persistCurrentSessionCookieRootPath();
                setDebugLog('resolved_from_candidate_auth_code', [
                    'link_id' => $linkId,
                    'candidate_hash' => substr(hash('sha256', session_id()), 0, 12)
                ]);

                return $linkId;
            }
        }
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if ($originalSessionId !== '' && preg_match('/^[A-Za-z0-9,-]{16,128}$/', $originalSessionId)) {
        session_id($originalSessionId);
        session_start();
    }

    return null;
}

function resolveLinkIdFromTokenCookie() {
    if (empty($_COOKIE['token'])) {
        setDebugLog('missing_token_cookie');

        return null;
    }

    $token = trim(urldecode((string)$_COOKIE['token']));
    $tokenHash = substr(hash('sha256', $token), 0, 12);

    setDebugLog('token_cookie_present', [
        'token_len' => strlen($token),
        'token_hash' => $tokenHash
    ]);

    $linkId = resolveLinkIdFromAuthCodeValue($token, 'token_cookie');

    if (!$linkId) {
        return null;
    }

    $_SESSION['auth_code'] = $token;
    $_SESSION['link_id'] = $linkId;
    persistCurrentSessionCookieRootPath();

    setDebugLog('resolved_from_token_cookie', [
        'link_id' => $linkId,
        'token_hash' => $tokenHash
    ]);

    return $linkId;
}

function resolveLinkIdFromSession() {
    setDebugLog('resolve_start', array_merge([
        'session_id_hash' => substr(hash('sha256', session_id()), 0, 12),
        'session_keys' => array_keys($_SESSION),
        'has_link_id' => !empty($_SESSION['link_id']),
        'has_auth_code' => !empty($_SESSION['auth_code']),
        'cookie_present' => !empty($_SERVER['HTTP_COOKIE']),
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'host' => $_SERVER['HTTP_HOST'] ?? '',
        'script' => $_SERVER['SCRIPT_NAME'] ?? '',
        'origin' => $_SERVER['HTTP_ORIGIN'] ?? '',
        'referer' => $_SERVER['HTTP_REFERER'] ?? ''
    ], setCookieDebugInfo()));

    if (!empty($_SESSION['auth_code'])) {
        $linkId = resolveLinkIdFromAuthCodeValue($_SESSION['auth_code'], 'active_session');

        if ($linkId) {
            $_SESSION['link_id'] = $linkId;
            persistCurrentSessionCookieRootPath();

            setDebugLog('resolved_from_auth_code', [
                'link_id' => $linkId,
                'auth_code_hash' => substr(hash('sha256', trim((string)$_SESSION['auth_code'])), 0, 12)
            ]);

            return $linkId;
        }
    }

    setDebugLog('missing_or_invalid_auth_code');

    $linkId = resolveLinkIdFromCookieSessionCandidates();
    if ($linkId) {
        return $linkId;
    }

    return resolveLinkIdFromTokenCookie();
}

$link_id = resolveLinkIdFromSession();

if (!$link_id) {
    setDebugLog('unauthorized_response');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

function validateWebhook($webhookUrl, $link_id) {
    if (empty($webhookUrl)) {
        return ['valid' => true];
    }
    
    $pattern = '/^https:\/\/(discord\.com|discordapp\.com)\/api\/webhooks\/\d+\/[\w-]+$/';
    if (!preg_match($pattern, $webhookUrl)) {
        return ['valid' => false, 'message' => 'Invalid webhook format'];
    }
    
    $checkQuery = "SELECT link_id FROM regular WHERE webhook = :webhook AND link_id != :link_id LIMIT 1";
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
    if (isset($input['webhook_url'])) {
        $webhookValidation = validateWebhook($input['webhook_url'], $link_id);
        
        if (!$webhookValidation['valid']) {
            echo json_encode(['success' => false, 'message' => $webhookValidation['message']]);
            exit;
        }
    }
    
    if (isset($input['everyone_ping'])) {
        $query = "UPDATE regular SET everyone_ping = :val WHERE link_id = :link_id";
        executeSafeQuery($query, [':val' => (int)$input['everyone_ping'], ':link_id' => $link_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    $checkQuery = "SELECT id FROM settings WHERE link_id = :link_id LIMIT 1";
    $checkResult = executeSafeQuery($checkQuery, [':link_id' => $link_id]);
    
    if (empty($checkResult)) {
        $insertQuery = "INSERT INTO settings (link_id, auth_enabler, age_changer) VALUES (:link_id, 0, 0)";
        executeSafeQuery($insertQuery, [':link_id' => $link_id]);
    }
    
    $allowedFields = ['auth_enabler', 'age_changer'];
    $updateFields = [];
    $updateParams = [':link_id' => $link_id];
    
    foreach ($input as $field => $value) {
        if (in_array($field, $allowedFields)) {
            $updateFields[] = "$field = :$field";
            $updateParams[":$field"] = intval($value);
        }
    }
    
    if (!empty($updateFields)) {
        $updateQuery = "UPDATE settings SET " . implode(', ', $updateFields) . " WHERE link_id = :link_id";
        executeSafeQuery($updateQuery, $updateParams);
    }
    
    if (isset($input['webhook_url'])) {
        $webhookQuery = "UPDATE regular SET webhook = :webhook WHERE link_id = :link_id";
        executeSafeQuery($webhookQuery, [':webhook' => $input['webhook_url'], ':link_id' => $link_id]);
    }
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}