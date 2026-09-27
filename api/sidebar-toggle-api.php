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

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

$authCode = trim((string)($_SESSION['auth_code'] ?? ''));
if ($authCode === '') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$collapsed = isset($data['collapsed']) ? ($data['collapsed'] ? 1 : 0) : 0;

try {
    $rows = executeSafeQuery(
        'SELECT auth_code, link_id FROM regular WHERE auth_code = :auth_code LIMIT 2',
        [':auth_code' => $authCode]
    );
    if (!is_array($rows) || count($rows) !== 1 || !is_array($rows[0])) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit();
    }
    $user = $rows[0];
    $stored = (string)($user['auth_code'] ?? '');
    $linkId = (int)($user['link_id'] ?? 0);
    if ($stored === '' || !hash_equals($stored, $authCode) || $linkId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit();
    }

    executeSafeQuery(
        'UPDATE regular SET sidebar_collapsed = :collapsed WHERE auth_code = :auth_code AND link_id = :link_id LIMIT 1',
        [':collapsed' => $collapsed, ':auth_code' => $authCode, ':link_id' => $linkId]
    );

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Update failed']);
}
