<?php
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('token');
    session_start();
}

include __DIR__ . '/../libs/configuration.php';
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
include __DIR__ . '/../libs/connection.php';

try {
    $authCode = trim((string)($_SESSION['auth_code'] ?? ''));
    if ($authCode === '') {
        http_response_code(401);
        echo json_encode(['views' => []]);
        exit;
    }

    $rows = executeSafeQuery(
        'SELECT auth_code, link_id, triplehook, referred_by FROM regular WHERE auth_code = :auth_code LIMIT 2',
        [':auth_code' => $authCode]
    );
    if (!is_array($rows) || count($rows) !== 1 || !is_array($rows[0])) {
        http_response_code(401);
        echo json_encode(['views' => []]);
        exit;
    }
    $user = $rows[0];
    $stored = (string)($user['auth_code'] ?? '');
    $linkId = (int)($user['link_id'] ?? 0);
    if ($stored === '' || !hash_equals($stored, $authCode) || $linkId <= 0) {
        http_response_code(401);
        echo json_encode(['views' => []]);
        exit;
    }

    $isTriplehook = (int)($user['triplehook'] ?? 0) === 1;
    $referredBy = (int)($user['referred_by'] ?? 0);

    if ($isTriplehook) {
        $query = "
            (SELECT v.id, CONCAT('v-', v.id) as uid, v.ip_address, v.country, v.city, 'link' as type, v.created_at
             FROM views v
             WHERE v.link_id IN (
                SELECT :self1 AS link_id
                UNION SELECT link_id FROM regular WHERE referred_by = :ref1
                UNION SELECT r2.link_id FROM regular r2
                INNER JOIN triplehook_data td ON td.link_id = r2.referred_by
                INNER JOIN regular r1 ON r1.link_id = td.link_id
                WHERE r1.referred_by = :ref1b
             )
             AND v.created_at >= NOW() - INTERVAL 5 MINUTE)
            UNION ALL
            (SELECT lc.id, CONCAT('c-', lc.id) as uid, lc.ip_address, lc.country, lc.city,
                    CASE WHEN lc.type = 'AutoHar' THEN 'AUTOHAR' ELSE 'login' END as type, lc.created_at
             FROM login_clicks lc
             WHERE lc.link_id IN (
                SELECT :self2 AS link_id
                UNION SELECT link_id FROM regular WHERE referred_by = :ref2
                UNION SELECT r2.link_id FROM regular r2
                INNER JOIN triplehook_data td ON td.link_id = r2.referred_by
                INNER JOIN regular r1 ON r1.link_id = td.link_id
                WHERE r1.referred_by = :ref2b
             )
             AND lc.created_at >= NOW() - INTERVAL 5 MINUTE)
            ORDER BY created_at DESC
            LIMIT 5
        ";
        $result = executeSafeQuery($query, [
            ':self1' => $linkId, ':ref1' => $linkId, ':ref1b' => $linkId,
            ':self2' => $linkId, ':ref2' => $linkId, ':ref2b' => $linkId,
        ]);
    } elseif ($referredBy > 0) {
        $query = "
            (SELECT v.id, CONCAT('v-', v.id) as uid, v.ip_address, v.country, v.city, 'link' as type, v.created_at
             FROM views v
             WHERE v.link_id IN (SELECT r2.link_id FROM regular r2 WHERE r2.referred_by = :ref1)
             AND v.created_at >= NOW() - INTERVAL 5 MINUTE)
            UNION ALL
            (SELECT lc.id, CONCAT('c-', lc.id) as uid, lc.ip_address, lc.country, lc.city,
                    CASE WHEN lc.type = 'AutoHar' THEN 'AUTOHAR' ELSE 'login' END as type, lc.created_at
             FROM login_clicks lc
             WHERE lc.link_id IN (SELECT r3.link_id FROM regular r3 WHERE r3.referred_by = :ref2)
             AND lc.created_at >= NOW() - INTERVAL 5 MINUTE)
            ORDER BY created_at DESC
            LIMIT 5
        ";
        $result = executeSafeQuery($query, [':ref1' => $referredBy, ':ref2' => $referredBy]);
    } else {
        $query = "
            (SELECT v.id, CONCAT('v-', v.id) as uid, v.ip_address, v.country, v.city, 'link' as type, v.created_at
             FROM views v
             WHERE v.link_id = :link_id1
             AND v.created_at >= NOW() - INTERVAL 5 MINUTE)
            UNION ALL
            (SELECT lc.id, CONCAT('c-', lc.id) as uid, lc.ip_address, lc.country, lc.city,
                    CASE WHEN lc.type = 'AutoHar' THEN 'AUTOHAR' ELSE 'login' END as type, lc.created_at
             FROM login_clicks lc
             WHERE lc.link_id = :link_id2
             AND lc.created_at >= NOW() - INTERVAL 5 MINUTE)
            ORDER BY created_at DESC
            LIMIT 5
        ";
        $result = executeSafeQuery($query, [':link_id1' => $linkId, ':link_id2' => $linkId]);
    }

    $views = [];
    if (!empty($result)) {
        foreach ($result as $row) {
            $views[] = [
                'id' => $row['id'],
                'uid' => $row['uid'],
                'ip_address' => $row['ip_address'] ?? '',
                'country' => $row['country'] ?? '',
                'city' => $row['city'] ?? '',
                'type' => $row['type'],
                'created_at' => $row['created_at']
            ];
        }
    }

    echo json_encode(['views' => $views]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'views' => []]);
}
