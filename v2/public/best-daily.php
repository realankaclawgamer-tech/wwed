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
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

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

$query = "SELECT r.discord_username, r.discord_avatar, r.discord_id, r.avatar_hidden, COUNT(h.id) as today_hits
          FROM regular r
          LEFT JOIN hits h ON r.link_id = h.link_id
          WHERE r.discord_username IS NOT NULL
          AND DATE(h.created_at) = CURDATE()
          GROUP BY r.link_id, r.discord_username, r.discord_avatar, r.discord_id, r.avatar_hidden
          HAVING today_hits > 0
          ORDER BY today_hits DESC
          LIMIT 3";

$rows = executeSafeQuery($query, []);
if (!is_array($rows)) $rows = [];

function bd_getAvatarUrl($avatar, $discordId, $hidden) {
    if (!empty($hidden) && (int)$hidden === 1) {
        return 'https://app.beamse.pro/images/hide.png';
    }
    if (empty($avatar)) {
        return 'https://cdn.pfps.gg/pfps/9332-default-discord-pfp.png';
    }
    if (strpos($avatar, 'https://') === 0) {
        return $avatar;
    }
    if (empty($discordId)) {
        return 'https://cdn.pfps.gg/pfps/9332-default-discord-pfp.png';
    }
    $ext = (strpos($avatar, 'a_') === 0) ? 'gif' : 'png';
    return 'https://cdn.discordapp.com/avatars/' . $discordId . '/' . $avatar . '.' . $ext;
}

$data = [];
foreach ($rows as $row) {
    $data[] = [
        'avatar' => bd_getAvatarUrl($row['discord_avatar'] ?? '', $row['discord_id'] ?? '', $row['avatar_hidden'] ?? 0),
        'name' => $row['discord_username'] ?? '',
        'count' => (string)($row['today_hits'] ?? 0),
    ];
}

echo json_encode(['data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);