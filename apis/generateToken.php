<?php
/* ---- functions this file uses (formerly from libs/functions.php, which is no longer needed) ---- */
if (!function_exists('checkRequest')) {
function checkRequest(){
    if(isset($_SERVER['HTTP_USER_AGENT']) && isset($_SERVER['HTTP_REFERER'])){
        return true;
    }else{
        errorResponse("Access Forbidden");
    }
}
}
if (!function_exists('errorResponse')) {
function errorResponse($message) {
    http_response_code(403);
    exit(json_encode(["success" => false, "message" => $message]));
}
}
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
if (!function_exists('sendEmbed')) {
function sendEmbed($webhook, $embed){
    if (empty($webhook)) {
        error_log("sendEmbed ERROR: Webhook URL is empty!");
        return false;
    }
    
    error_log("sendEmbed: Sending to webhook: " . substr($webhook, 0, 50) . "...");
    
    $embedJson = json_encode($embed);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("sendEmbed ERROR: JSON encode failed: " . json_last_error_msg());
        return false;
    }
    
    $ch = curl_init($webhook);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $embedJson);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Content-Length: " . strlen($embedJson)
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    error_log("sendEmbed: HTTP Code: " . $httpCode);
    
    if ($error) {
        error_log("sendEmbed CURL ERROR: " . $error);
        return false;
    }
    
    if ($httpCode === 204 || $httpCode === 200) {
        error_log("sendEmbed: SUCCESS");
        return 'success';
    }
    
    if ($httpCode !== 200 && $httpCode !== 204) {
        error_log("sendEmbed HTTP ERROR: Code " . $httpCode . " Response: " . $response);
        return false;
    }
    
    return $response ?: 'success';
}
}
if (!function_exists('isValidUrl')) {
function isValidUrl($url) {
    // Validate URL structure using PHP's filter function
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    
    // Check if URL contains forbidden characters (# or ?)
    if (strpos($url, '#') !== false || strpos($url, '?') !== false) {
        return false;
    }
    
    return true;
}
}
if (!function_exists('errorMessage')) {
function errorMessage($msg = NULL){
    if($msg == NULL){
        $msg = "Error occured, Please contact developer.";
    }
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    die(json_encode(["success" => false, "message" => $msg]));
}
}
if (!function_exists('getRandomNumber')) {
function getRandomNumber($length) {
    if ($length < 1) {
        return ''; // Return an empty string for invalid lengths
    }

    // Generate a random number as a string
    $randomNumber = '';
    for ($i = 0; $i < $length; $i++) {
        $randomNumber .= mt_rand(0, 9); // Append a random digit (0-9)
    }

    return $randomNumber; // Return the random number as a string
}
}
if (!function_exists('stringExistsInRow')) {
function stringExistsInRow($table, $column, $rowKeyColumn, $rowKeyValue) {
    static $db = null;   // one database connection for the whole page (was: a new one for every query)
    if ($db === null) {
        include $_SERVER['DOCUMENT_ROOT'] . '/libs/connection.php';
    }
    
    // Validate inputs
    $validFields = [$table, $column, $rowKeyColumn];
    foreach ($validFields as $field) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
            throw new Exception("Invalid table or column name");
        }
    }

    try {
        $stmt = $db->prepare("SELECT $column FROM `$table` 
                            WHERE `$rowKeyColumn` = :key_value");
        $stmt->bindParam(':key_value', $rowKeyValue, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
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
include '../libs/connection.php';
include '../libs/configuration.php';
session_start();

$body = file_get_contents('php://input');

$data = json_decode($body);

if ($data) {
}

$isDiscordLogin = isset($data->discord_id) && !empty($data->discord_id);

if (!$isDiscordLogin) {
    session_unset();
    checkRequest();
} else {
}
header('Content-Type: application/json; charset=UTF-8');

if (!isset($data->webhook) || empty($data->webhook)) {
    errorMessage("Invalid request format: webhook field missing");
}

if (!isset($data->tokenType) || empty($data->tokenType)) {
    errorMessage("Invalid request format: Token type field missing");
}

$webhook = filter_var($data->webhook, FILTER_SANITIZE_URL);
if (!isValidUrl($webhook)) {
    errorMessage("Invalid webhook value. Webhook must be a valid URL");
}

$parsedWebhook = parse_url($webhook);
if (!isset($parsedWebhook['scheme']) || !in_array($parsedWebhook['scheme'], ['http', 'https'])) {
    errorMessage("Invalid webhook protocol. Only HTTP/HTTPS allowed");
}

$skipWebhookCheck = isset($data->skip_webhook_check) && $data->skip_webhook_check === true;

if (!$skipWebhookCheck) {
    $checkWebhook = getRequest($webhook);
    if(!isset(json_decode($checkWebhook['body'])->channel_id)){
        errorMessage("Webhook is invalid. Must be active webhooks");
    }
}

$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
$token = '';
for ($i = 0; $i < 100; $i++) {
    $token .= $chars[random_int(0, 35)];
}
$token .= '-tkn';

try {
    $webhookExists = stringExistsInRow(
        $data->tokenType,
        'auth_code',
        'webhook',
        $webhook,
    );
} catch (Exception $e) {
    errorMessage("Database validation failed: " . $e->getMessage());
}

if ($webhookExists) {
    errorMessage('Webhook is already in database');
}

if($data->tokenType == "regular"){
    do {
        $linkId = random_int(100000000000, 999999999999);
        $exists = stringExistsInRow('regular', 'link_id', 'link_id', $linkId);
    } while ($exists);
    
    $referred_by = isset($data->referred_by) && !empty($data->referred_by) ? $data->referred_by : null;
    
    
    if ($isDiscordLogin) {
        
        $insertQuery = "INSERT INTO " . $data->tokenType . " 
            (user_id, auth_code, discord_id, discord_username, discord_avatar, last_login, link_id, privateServerLinkCode, webhook, referred_by) 
            VALUES 
            (:user_id, :auth_code, :discord_id, :discord_username, :discord_avatar, NOW(), :link_id, :privateServerLinkCode, :webhook, :referred_by)";
        
        $params = [
            ':user_id' => 1,
            ':auth_code' => $token,
            ':discord_id' => $data->discord_id ?? null,
            ':discord_username' => $data->discord_username ?? null,
            ':discord_avatar' => $data->discord_avatar ?? null,
            ':link_id' => $linkId,
            ':privateServerLinkCode' => getRandomNumber(32),
            ':webhook' => $webhook,
            ':referred_by' => $referred_by
        ];
        
    } else {
        
        $insertQuery = "INSERT INTO " . $data->tokenType . " 
            (auth_code, link_id, webhook, privateServerLinkCode, referred_by) 
            VALUES 
            (:auth_code, :link_id, :webhook, :privateServerLinkCode, :referred_by)";
        
        $params = [
            ':auth_code' => $token,
            ':link_id' => $linkId,
            ':webhook' => $webhook,
            ':privateServerLinkCode' => getRandomNumber(32),
            ':referred_by' => $referred_by
        ];
    }
    
    $_SESSION['triplehook'] = "False";
    $_SESSION['link_id'] = $linkId;
    
}else if($data->tokenType == "triplehook"){
    $directory = $data->directory;
    $name = $data->name;
    $thumbnail = $data->thumbnail;
    $color = $data->color;
    if (empty($directory) || empty($name) || empty($thumbnail) || empty($color)) {
        errorMessage('Input value cannot be empty');
    }

    $thumbnail = filter_var($thumbnail, FILTER_SANITIZE_URL);
    if (!isValidUrl($thumbnail)) {
        errorMessage("Invalid webhook value. Webhook must be a valid URL");
    }

    $parsedthumbnail = parse_url($thumbnail);
    if (!isset($parsedthumbnail['scheme']) || !in_array($parsedthumbnail['scheme'], ['http', 'https'])) {
        errorMessage("Invalid webhook protocol. Only HTTP/HTTPS allowed");
    }
    
    try {
        $directoryExists = stringExistsInRow(
            $data->tokenType,
            'auth_code',
            'directory',
            $directory,
        );
    } catch (Exception $e) {
        errorMessage("Database validation failed: " . $e->getMessage());
    }

    if ($directoryExists) {
        errorMessage('Directory already taken');
    }
    
    $insertQuery = "INSERT INTO " . $data->tokenType . " 
        (auth_code, directory, webhook, name, thumbnail, color) 
        VALUES 
        (:auth_code, :directory, :webhook, :name, :thumbnail, :color)";
    
    $params = [
        ':auth_code' => $token,
        ':directory' => $directory,
        ':webhook' => $webhook,
        ':name' => $name,
        ':thumbnail' => $thumbnail,
        ':color' => $color,
    ];
    
    $_SESSION['triplehook'] = "True";
    $_SESSION['directory'] = $directory;
}else{
    errorMessage("Invalid request format: Token type is invalid");
}

try {
    $result = executeSafeQuery($insertQuery, $params);
    
    if ($result === false) {
        errorMessage("Failed to create new webhook entry");
    }
    
    $embedName = $website['name'];
    $embedThumbnail = 'https://cdn.discordapp.com/attachments/1464597073549852765/1465827671865954324/ssb4-master-shadow-pikachu-png-clipart-removebg-preview.png?ex=697a85e6&is=69793466&hm=f51b8911ab88fbb654550238715ff579756946c85acc3996fd480403ac3aa44f&';
    $embedDomain = 'app.ultima.cl';
    
    if (!empty($referred_by)) {
        try {
            $tripStmt = $conn->prepare("SELECT display_name, thumbnail_url FROM triplehook_data WHERE link_id = :link_id LIMIT 1");
            $tripStmt->execute([':link_id' => $referred_by]);
            $tripData = $tripStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($tripData) {
                if (!empty($tripData['display_name'])) {
                    $embedName = $tripData['display_name'];
                }
                if (!empty($tripData['thumbnail_url'])) {
                    $embedThumbnail = $tripData['thumbnail_url'];
                }
                $embedDomain = $triplehook[0];
            }
        } catch (Exception $e) {
            error_log('TripleHook embed data error: ' . $e->getMessage());
        }
    }
    
    $embedData = [
        'content' => '',
        'tts' => false,
        'embeds' => [
            [
                'author' => [
                    'name' => 'TOKEN INFORMATION'
                ],
                'description' => "Secret Code / Auth\n```$token```\nYou can easily access your account with this token.",

                'color' => 13111312,
                'thumbnail' => [
                    'url' => 'https://cdn.discordapp.com/attachments/1465312216008888476/1465820612374171688/images__6_-removebg-preview.png?ex=697a7f53&is=69792dd3&hm=fad30875af9b7937c3a54bc25c7dd016fd5e46b849d3737b74bacf31e0fc8a8c&'
                ]
            ],
            [
                'title' => 'Welcome To ' . $embedName,
                'description' => "**We are ready to serve you with the latest updates. has been released as of \nnow. Thank you for choosing us for more money and more power. I believe \nwe will grow even bigger very soon.**\n\n[**[ LOGIN GENERATOR ]**](https://" . $embedDomain . "/pages/login?token=$token)",
                'thumbnail' => [
                    'url' => $embedThumbnail
                ]
            ]
        ],
        'username' => $embedName,
        'avatar_url' => $website['thumbnail'],
        'attachments' => []
    ];

    sendEmbed($webhook, $embedData);

    $_SESSION['auth_code'] = $token;

    die(json_encode([
        'success' => true,
        'auth_code' => $token
    ]));

} catch (Exception $e) {
    errorMessage("Database operation failed: " . $e->getMessage());
}
?>