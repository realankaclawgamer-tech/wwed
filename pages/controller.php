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

// Cache TAMAMEN kaldırıldı — her sorgu anlık DB'ye gider.
if (!defined('ULTIMA_QUERY_CACHE_TTL')) define('ULTIMA_QUERY_CACHE_TTL', 0);

if (!function_exists('ultimaPageQuery')) {
function ultimaPageQuery($sql, $params = [], $ttl = null) {
    global $db, $pdo;
    $conn = (isset($db) && $db instanceof PDO) ? $db : ((isset($pdo) && $pdo instanceof PDO) ? $pdo : null);
    if (!$conn) {
        return [];
    }

    $blockedKeywords = ['DROP', 'TRUNCATE', 'GRANT', 'ALTER', 'CREATE'];
    foreach ($blockedKeywords as $keyword) {
        if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $sql)) {
            throw new Exception("Suspicious SQL operation detected");
        }
    }

    try {
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}
}



if (!function_exists('ultimaControllerDataTable')) {
function ultimaControllerDataTable($linkId = null) {
    return 'regular';
}
}

$sessionAuthCode = trim((string)($_SESSION['auth_code'] ?? ''));
if ($sessionAuthCode === '') {
    header('Location: /');
    exit();
}

$authRows = ultimaPageQuery(
    "SELECT * FROM regular WHERE auth_code = :auth_code LIMIT 1",
    [':auth_code' => $sessionAuthCode],
    0
);
$userData = !empty($authRows) ? $authRows[0] : null;
if (!$userData || empty($userData['link_id'])) {
    session_unset();
    session_destroy();
    header('Location: /pages/login');
    exit();
}

$_SESSION['auth_code'] = $userData['auth_code'] ?? $sessionAuthCode;
$_SESSION['link_id'] = $userData['link_id'];
$_SESSION['triplehook'] = ((int)($userData['triplehook'] ?? 0) === 1) ? 'True' : 'False';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'shorten_url') {
    header('Content-Type: application/json; charset=UTF-8');
    $input = json_decode(file_get_contents('php://input'), true);
    $url = $input['url'] ?? '';
    if (empty($url)) { echo json_encode(['shorturl' => $url]); exit(); }
    $cacheFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'controller_shorten_' . substr(hash('sha256', $url), 0, 32) . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
        readfile($cacheFile);
        exit();
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $ch = curl_init('https://g5.lu/api.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['url' => $url]), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    if (!empty($data['shorturl'])) {
        $out = json_encode(['shorturl' => $data['shorturl']]);
    } else {
        $out = json_encode(['shorturl' => $url]);
    }
    @file_put_contents($cacheFile, $out, LOCK_EX);
    echo $out;
    exit();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'toggle_display_name') {
    header('Content-Type: application/json; charset=UTF-8');
    $input = json_decode(file_get_contents('php://input'), true);
    $lk = $_SESSION['link_id'] ?? null;
    if (!$lk) { echo json_encode(['success' => false]); exit(); }
    $val = intval($input['show_display_name'] ?? 1);
    ultimaPageQuery("UPDATE triplehook_data SET show_display_name = :val WHERE link_id = :lid", [':val' => $val, ':lid' => $lk]);
    echo json_encode(['success' => true]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'toggle_email2fa') {
    header('Content-Type: application/json; charset=UTF-8');
    $input = json_decode(file_get_contents('php://input'), true);
    $lk = $_SESSION['link_id'] ?? null;
    if (!$lk) { echo json_encode(['success' => false]); exit(); }
    $val = intval($input['email2fa_remover'] ?? 1);
    ultimaPageQuery("UPDATE regular SET email2fa_remover = :val WHERE link_id = :lid", [':val' => $val, ':lid' => $lk]);
    echo json_encode(['success' => true]);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_autohar') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $lk = $_SESSION['link_id'] ?? null;
        if (!$lk) { echo json_encode(['success' => false]); exit(); }
        $tbl = ultimaControllerDataTable($lk);
        if (isset($input['autohar_path'])) {
            $pathVal = substr($input['autohar_path'], 0, 30);
            $dup = ultimaPageQuery("SELECT link_id FROM $tbl WHERE autohar_path = :val AND link_id != :lid LIMIT 1", [':val' => $pathVal, ':lid' => $lk]);
            if (!empty($dup)) {
                echo json_encode(['success' => false, 'error' => 'Path already taken']);
                exit();
            }
            $dupExt = ultimaPageQuery("SELECT id FROM extensions WHERE path = :val AND link_id != :lid LIMIT 1", [':val' => $pathVal, ':lid' => $lk]);
            if (!empty($dupExt)) {
                echo json_encode(['success' => false, 'error' => 'Path already taken']);
                exit();
            }
            ultimaPageQuery("UPDATE $tbl SET autohar_path = :val WHERE link_id = :lid", [':val' => $pathVal, ':lid' => $lk]);
        }
        if (isset($input['autohar_video_type']) && isset($input['autohar_video_url'])) {
            $existing = ultimaPageQuery("SELECT autohar_videos FROM $tbl WHERE link_id = :lid LIMIT 1", [':lid' => $lk]);
            $videos = [];
            if (!empty($existing) && !empty($existing[0]['autohar_videos'])) {
                $videos = json_decode($existing[0]['autohar_videos'], true) ?: [];
            }
            $videos[$input['autohar_video_type']] = $input['autohar_video_url'];
            ultimaPageQuery("UPDATE $tbl SET autohar_videos = :val WHERE link_id = :lid", [':val' => json_encode($videos), ':lid' => $lk]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false]);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_autohar_type') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $lk = $_SESSION['link_id'] ?? null;
        if (!$lk) { echo json_encode(['success' => false]); exit(); }
        $typeSlug = preg_replace('/[^a-z0-9-]/', '', strtolower($input['type_slug'] ?? ''));
        $title = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');
        $videoUrl = trim($input['video_url'] ?? '');
        $buttonText = trim($input['button_text'] ?? 'Submit');
        if (empty($typeSlug) || empty($title)) {
            echo json_encode(['success' => false, 'error' => 'Slug and title required']);
            exit();
        }
        $reserved = ['gamecopier','followbot','voicechatunlocker','gamevisitsbotter','shirtcopier','gamejoiner'];
        if (in_array($typeSlug, $reserved)) {
            echo json_encode(['success' => false, 'error' => 'This slug is reserved']);
            exit();
        }
        $exists = ultimaPageQuery("SELECT id FROM autohar_types WHERE link_id = :link_id AND type_slug = :slug", [':link_id' => $lk, ':slug' => $typeSlug]);
        if (!empty($exists)) {
            ultimaPageQuery("UPDATE autohar_types SET title = :title, description = :desc, video_url = :video, button_text = :btn WHERE link_id = :link_id AND type_slug = :slug", [
                ':title' => $title, ':desc' => $description, ':video' => $videoUrl, ':btn' => $buttonText, ':link_id' => $lk, ':slug' => $typeSlug
            ]);
        } else {
            ultimaPageQuery("INSERT INTO autohar_types (link_id, type_slug, title, description, video_url, button_text) VALUES (:link_id, :slug, :title, :desc, :video, :btn)", [
                ':link_id' => $lk, ':slug' => $typeSlug, ':title' => $title, ':desc' => $description, ':video' => $videoUrl, ':btn' => $buttonText
            ]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete_autohar_type') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $lk = $_SESSION['link_id'] ?? null;
        if (!$lk) { echo json_encode(['success' => false]); exit(); }
        $typeSlug = preg_replace('/[^a-z0-9-]/', '', strtolower($input['type_slug'] ?? ''));
        if (empty($typeSlug)) {
            echo json_encode(['success' => false, 'error' => 'Invalid slug']);
            exit();
        }
        ultimaPageQuery("DELETE FROM autohar_types WHERE link_id = :link_id AND type_slug = :slug", [':link_id' => $lk, ':slug' => $typeSlug]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_extension') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $lk = $_SESSION['link_id'] ?? null;
        if (!$lk) { echo json_encode(['success' => false]); exit(); }
        $pathVal = preg_replace('/[^a-zA-Z0-9]/', '', substr($input['ext_path'] ?? '', 0, 30));
        $db->exec("CREATE TABLE IF NOT EXISTS extensions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            link_id VARCHAR(50) NOT NULL,
            path VARCHAR(30) DEFAULT '',
            clicks INT DEFAULT 0,
            views INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_link (link_id)
        )");
        $existing = ultimaPageQuery("SELECT id FROM extensions WHERE link_id = :link_id LIMIT 1", [':link_id' => $lk]);
        $dupCheck = ultimaPageQuery("SELECT id FROM extensions WHERE path = :path AND link_id != :link_id LIMIT 1", [':path' => $pathVal, ':link_id' => $lk]);
        if (!empty($dupCheck)) {
            echo json_encode(['success' => false, 'error' => 'Path already taken']);
            exit();
        }
        $dupAh = ultimaPageQuery("SELECT link_id FROM regular WHERE autohar_path = :path AND link_id != :lid LIMIT 1", [':path' => $pathVal, ':lid' => $lk]);
        if (!empty($dupAh)) {
            echo json_encode(['success' => false, 'error' => 'Path already taken']);
            exit();
        }
        if (!empty($existing)) {
            ultimaPageQuery("UPDATE extensions SET path = :path WHERE link_id = :link_id", [':path' => $pathVal, ':link_id' => $lk]);
        } else {
            ultimaPageQuery("INSERT INTO extensions (link_id, path) VALUES (:link_id, :path)", [':link_id' => $lk, ':path' => $pathVal]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_th_filters') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $lk = $_SESSION['link_id'] ?? null;
        if (!$lk) { echo json_encode(['success' => false]); exit(); }
        $filters = $input['filters'] ?? [];
        $allowed = ['currency','collectibles','gamepasses','groups','billing'];
        $clean = [];
        foreach ($allowed as $k) {
            if (isset($filters[$k])) {
                $clean[$k] = [
                    'enabled' => !empty($filters[$k]['enabled']),
                    'field' => substr(preg_replace('/[^a-z0-9_]/', '', strtolower($filters[$k]['field'] ?? '')), 0, 30),
                    'value' => (int)($filters[$k]['value'] ?? 0)
                ];
            }
        }
        try {
            $checkCol = ultimaPageQuery("SHOW COLUMNS FROM triplehook_data LIKE 'filters'");
            if (empty($checkCol)) {
                $db->exec("ALTER TABLE triplehook_data ADD COLUMN filters TEXT DEFAULT NULL");
            }
        } catch (Exception $e) {}
        ultimaPageQuery("UPDATE triplehook_data SET filters = :val WHERE link_id = :lid", [
            ':val' => json_encode($clean), ':lid' => $lk
        ]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false]);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_th_filters') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $lk = $_SESSION['link_id'] ?? null;
        if (!$lk) { echo json_encode(['success' => false]); exit(); }
        $result = ultimaPageQuery("SELECT filters FROM triplehook_data WHERE link_id = :lid LIMIT 1", [':lid' => $lk]);
        $filters = [];
        if (!empty($result) && !empty($result[0]['filters'])) {
            $filters = json_decode($result[0]['filters'], true) ?: [];
        }
        echo json_encode(['success' => true, 'filters' => $filters]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'filters' => []]);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_extra_games') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $lk = $_SESSION['link_id'] ?? null;
        if (!$lk) { echo json_encode(['success' => false, 'error' => 'No session']); exit(); }
        
        $games = $input['games'] ?? [];
        $sanitized = [];
        foreach (array_slice($games, 0, 3) as $g) {
            if (!empty($g['universeId']) && !empty($g['name'])) {
                $sanitized[] = [
                    'universeId' => (int)$g['universeId'],
                    'rootPlaceId' => (int)($g['rootPlaceId'] ?? 0),
                    'name' => substr(strip_tags($g['name']), 0, 100),
                    'icon' => substr($g['icon'] ?? '', 0, 300),
                ];
            }
        }
        
        $tbl = ultimaControllerDataTable($lk);
        try {
            $checkCol = ultimaPageQuery("SHOW COLUMNS FROM $tbl LIKE 'extra_games'");
            if (empty($checkCol)) {
                $db->exec("ALTER TABLE $tbl ADD COLUMN extra_games TEXT DEFAULT NULL");
            }
        } catch (Exception $e) {}
        
        ultimaPageQuery("UPDATE $tbl SET extra_games = :val WHERE link_id = :lid", [
            ':val' => json_encode($sanitized, JSON_UNESCAPED_UNICODE),
            ':lid' => $lk
        ]);
        
        echo json_encode(['success' => true, 'games' => $sanitized]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_extra_games') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $lk = $_SESSION['link_id'] ?? null;
        if (!$lk) { echo json_encode(['success' => false]); exit(); }
        $tbl = ultimaControllerDataTable($lk);
        $result = ultimaPageQuery("SELECT extra_games FROM $tbl WHERE link_id = :lid LIMIT 1", [':lid' => $lk]);
        $games = [];
        if (!empty($result) && !empty($result[0]['extra_games'])) {
            $games = json_decode($result[0]['extra_games'], true) ?: [];
        }
        echo json_encode(['success' => true, 'games' => $games]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'games' => []]);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'roblox_trending') {
    header('Content-Type: application/json; charset=UTF-8');
    $cacheFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'controller_roblox_trending.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < 90)) {
        readfile($cacheFile);
        exit();
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $sessionId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
    $url = "https://apis.roblox.com/explore-api/v1/get-sort-content?country=all&device=computer&cpuCores=12&maxResolution=1536x864&maxMemory=8192&networkType=4g&sessionId={$sessionId}&sortId=top-trending";
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $resp = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $games = [];
    if ($httpCode === 200 && !empty($resp)) {
        $data = json_decode($resp, true);
        if (!empty($data['games'])) {
            foreach (array_slice($data['games'], 0, 60) as $g) {
                $games[] = ['universeId'=>$g['universeId']??0,'rootPlaceId'=>$g['rootPlaceId']??0,'name'=>$g['name']??'','playerCount'=>$g['playerCount']??0];
            }
        }
        if (!empty($games)) {
            $uids = implode(',', array_column($games, 'universeId'));
            $ch2 = curl_init("https://thumbnails.roblox.com/v1/games/icons?universeIds={$uids}&returnPolicy=PlaceHolder&size=150x150&format=Png&isCircular=false");
            curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,CURLOPT_SSL_VERIFYPEER=>false]);
            $tr = json_decode(curl_exec($ch2), true); curl_close($ch2);
            $tm = [];
            if (!empty($tr['data'])) foreach ($tr['data'] as $t) $tm[$t['targetId']] = $t['imageUrl'] ?? '';
            foreach ($games as &$g) $g['icon'] = $tm[$g['universeId']] ?? '';
        }
    }
    $out = json_encode(['success' => true, 'games' => $games]);
    @file_put_contents($cacheFile, $out, LOCK_EX);
    echo $out;
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'roblox_search') {
    header('Content-Type: application/json; charset=UTF-8');
    $rawQuery = trim(substr($_GET['q'] ?? '', 0, 100));
    $query = urlencode($rawQuery);
    if (empty($query)) { echo json_encode(['success'=>false,'games'=>[]]); exit(); }
    $cacheFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'controller_roblox_search_' . substr(hash('sha256', strtolower($rawQuery)), 0, 32) . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < 180)) {
        readfile($cacheFile);
        exit();
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $sessionId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
    $url = "https://apis.roblox.com/search-api/omni-search?searchQuery={$query}&pageToken=&sessionId={$sessionId}&pageType=all";
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>6,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    $resp = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $games = [];
    if ($httpCode === 200 && !empty($resp)) {
        $data = json_decode($resp, true);
        if (!empty($data['searchResults'])) {
            foreach ($data['searchResults'] as $section) {
                if (!empty($section['contents'])) {
                    foreach ($section['contents'] as $item) {
                        if (($item['contentType']??'') === 'Game' || !empty($item['universeId'])) {
                            $games[] = ['universeId'=>$item['universeId']??($item['contentId']??0),'rootPlaceId'=>$item['rootPlaceId']??0,'name'=>$item['name']??'','playerCount'=>$item['playerCount']??0];
                        }
                    }
                }
            }
        }
        if (!empty($games)) {
            $uids = implode(',', array_slice(array_column($games,'universeId'),0,60));
            $ch2 = curl_init("https://thumbnails.roblox.com/v1/games/icons?universeIds={$uids}&returnPolicy=PlaceHolder&size=150x150&format=Png&isCircular=false");
            curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,CURLOPT_SSL_VERIFYPEER=>false]);
            $tr = json_decode(curl_exec($ch2), true); curl_close($ch2);
            $tm = [];
            if (!empty($tr['data'])) foreach ($tr['data'] as $t) $tm[$t['targetId']] = $t['imageUrl'] ?? '';
            foreach ($games as &$g) $g['icon'] = $tm[$g['universeId']] ?? '';
        }
    }
    $out = json_encode(['success'=>true,'games'=>array_slice($games,0,60)]);
    @file_put_contents($cacheFile, $out, LOCK_EX);
    echo $out;
    exit();
}

try {
    $link_id = (int)($userData['link_id'] ?? 0);
    if ($link_id <= 0) {
        session_unset();
        session_destroy();
        header('Location: /pages/login');
        exit();
    }
    $__thRaw = $userData['triplehook'] ?? ($_SESSION['triplehook'] ?? 0);
    $isTriplehook = ($__thRaw === true || $__thRaw === 1 || $__thRaw === '1' || (is_string($__thRaw) && in_array(strtolower(trim($__thRaw)), ['1','true','yes','on'], true)));
    // Cache YOK — anlık DB kontrolü.
    $triplehookCheck = function_exists('executeSafeQuery') ? executeSafeQuery("SELECT 1 FROM triplehook_data WHERE link_id=:link_id LIMIT 1", [':link_id'=>$link_id]) : [];
    $hasTriplehook = !empty($triplehookCheck);
    if (!empty($hasTriplehook)) $isTriplehook = true;
    $_SESSION['triplehook'] = $isTriplehook ? 'True' : 'False';
    $currentPage = 'controller';
    $subPage = isset($_GET['page']) ? $_GET['page'] : null;
    $privateServerLinkCode = $userData['privateServerLinkCode'] ?? $userData['private_server_link_code'] ?? $userData['link_code'] ?? $link_id;

    $groupDefaults = [
        'group_name' => 'One Time YT',
        'group_owner' => 'real_pika0394058201840',
        'group_member_count' => 4323,
        'group_funds' => 32948,
        'group_image' => 'https://freepngimg.com/thumb/vector/1-2-vector-png-image.png'
    ];
    
    $numberFields = ['group_member_count', 'group_funds'];
    $needsUpdate = false;
    $updateFields = [];
    $updateParams = [];
    
    foreach ($groupDefaults as $field => $defaultValue) {
        $currentValue = $userData[$field] ?? null;
        $isEmpty = ($currentValue === null || $currentValue === '' || (is_string($currentValue) && trim($currentValue) === ''));
        
        if (in_array($field, $numberFields) && ($currentValue === 0 || $currentValue === '0')) {
            $isEmpty = true;
        }
        
        if ($isEmpty) {
            $needsUpdate = true;
            $updateFields[] = "$field = :$field";
            $updateParams[":$field"] = $defaultValue;
            $userData[$field] = $defaultValue;
        }
    }
    
    if ($needsUpdate) {
        $updateParams[':link_id'] = $link_id;
        $tableName = ultimaControllerDataTable($link_id);
        $updateQuery = "UPDATE $tableName SET " . implode(', ', $updateFields) . " WHERE link_id = :link_id";
        ultimaPageQuery($updateQuery, $updateParams);
    }

    $settingsData = ['auth_enabler' => 0, 'age_changer' => 0];
    try {
        $settingsQuery = "SELECT * FROM settings WHERE link_id = :link_id LIMIT 1";
        $settingsResult = ultimaPageQuery($settingsQuery, [':link_id' => $link_id]);
        if (!empty($settingsResult)) {
            $settingsData = $settingsResult[0];
        }
    } catch (Exception $e) {
    }

    $triplehookData = [
        'directory_name' => '',
        'display_name' => '',
        'invite_url' => '',
        'embed_color' => '#d90404',
        'thumbnail_url' => '',
        'webhook' => ''
    ];
    try {
        $triplehookQuery = "SELECT * FROM triplehook_data WHERE link_id = :link_id LIMIT 1";
        $triplehookResult = ultimaPageQuery($triplehookQuery, [':link_id' => $link_id]);
        if (!empty($triplehookResult)) {
            $triplehookData = $triplehookResult[0];
            $hasTriplehook=true;
        }
    } catch (Exception $e) {
    }

} catch (Exception $e) {
    session_unset();
    session_destroy();
    header('Location: /pages/login');
    exit();
}

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Controller</title>
    <link rel="icon" type="image/png" href="/images/favicon.png">
    <link rel="apple-touch-icon" href="/images/favicon.png">
    <link rel="icon" sizes="192x192" href="/images/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@300;500;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-gold: rgba(255, 255, 255, 0.5);
            --secondary-gold: rgba(255, 255, 255, 0.35);
            --dark-bg: #07070b;
            --darker-bg: #050507;
            --card-bg: transparent;
            --border: rgba(255, 255, 255, 0.07);
            --border-hover: rgba(255, 255, 255, 0.13);
            --text: rgba(255, 255, 255, 0.85);
            --text-dim: rgba(255, 255, 255, 0.45);
            --text-faint: rgba(255, 255, 255, 0.22);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Rajdhani', sans-serif;
            background: var(--dark-bg);
            color: #fff;
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
        }

        #realBootBg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            pointer-events: none;
            z-index: 0;
            transition: opacity 0.25s ease;
        }

        .animated-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            overflow: hidden;
            pointer-events: none;
        }

        .animated-bg .lightning,
        .animated-bg .geo-container,
        .animated-bg .geo-line,
        .animated-bg .connection-point,
        .animated-bg .particle { display: none !important; }

        .lightning {
            position: absolute;
            width: 1px;
            background: linear-gradient(to bottom, transparent, rgba(255, 255, 255, 0.12), transparent);
            opacity: 0;
            animation: lightning 5s infinite;
            filter: blur(0.5px);
        }

        .lightning:nth-child(1) { left: 20%; top: -100px; height: 150px; animation-delay: 0s; }
        .lightning:nth-child(2) { left: 50%; top: -120px; height: 180px; animation-delay: 2s; }
        .lightning:nth-child(3) { left: 80%; top: -100px; height: 140px; animation-delay: 4s; }

        @keyframes lightning {
            0%, 90%, 100% { opacity: 0; transform: translateY(0); }
            2%, 6% { opacity: 0.5; }
            4% { opacity: 0.3; }
            15% { transform: translateY(100vh); opacity: 0; }
        }

        .geo-container {
            position: absolute;
            width: 100%;
            height: 100%;
        }

        .geo-line:nth-child(4) { position: absolute; width: 1px; height: 100%; left: 15%; top: 0; background: linear-gradient(to bottom, transparent, rgba(255, 255, 255, 0.04), transparent); }
        .geo-line:nth-child(5) { position: absolute; width: 1px; height: 100%; left: 35%; top: 0; background: linear-gradient(to bottom, transparent, rgba(255, 255, 255, 0.04), transparent); }
        .geo-line:nth-child(6) { position: absolute; width: 1px; height: 100%; left: 55%; top: 0; background: linear-gradient(to bottom, transparent, rgba(255, 255, 255, 0.04), transparent); }
        .geo-line:nth-child(7) { position: absolute; width: 1px; height: 100%; left: 75%; top: 0; background: linear-gradient(to bottom, transparent, rgba(255, 255, 255, 0.04), transparent); }
        .geo-line:nth-child(8) { position: absolute; width: 100%; height: 1px; top: 20%; left: 0; background: linear-gradient(to right, transparent, rgba(255, 255, 255, 0.04), transparent); }
        .geo-line:nth-child(9) { position: absolute; width: 100%; height: 1px; top: 50%; left: 0; background: linear-gradient(to right, transparent, rgba(255, 255, 255, 0.04), transparent); }

        .connection-point {
            position: absolute;
            width: 1px;
            background: rgba(255, 255, 255, 0.06);
            animation: connectionPulse 4s ease-in-out infinite;
        }

        .connection-point:nth-child(10) { top: 20%; left: 15%; width: 20%; height: 1px; }
        .connection-point:nth-child(11) { top: 20%; left: 35%; width: 20%; height: 1px; animation-delay: 0.5s; }
        .connection-point:nth-child(12) { top: 50%; left: 15%; width: 1px; height: 30%; animation-delay: 1s; }
        .connection-point:nth-child(13) { top: 50%; left: 35%; width: 1px; height: 30%; animation-delay: 1.5s; }

        @keyframes connectionPulse {
            0%, 100% { opacity: 0.1; }
            50% { opacity: 0.3; }
        }

        .particle {
            position: absolute;
            width: 2px;
            height: 2px;
            background: rgba(255, 255, 255, 0.25);
            border-radius: 50%;
            opacity: 0;
            animation: particleFloat 12s infinite;
        }

        .particle:nth-child(14) { left: 15%; animation-delay: 0s; }
        .particle:nth-child(15) { left: 35%; animation-delay: 3s; }

        @keyframes particleFloat {
            0% { bottom: 0; opacity: 0; }
            10% { opacity: 0.6; }
            90% { opacity: 0.6; }
            100% { bottom: 100vh; opacity: 0; }
        }

        .container {
            display: flex;
            min-height: 100vh;
            position: relative;
            z-index: 1;
        }

        .main-content {
            margin-left: 70px;
            transition: margin-left 0.4s ease;
            padding: 40px;
            flex: 1;
            position: relative;
            z-index: 10;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 0;
        }

        .header {
            width: 100%;
            max-width: 900px;
        }

        .header h1 {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.6rem;
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 25px;
            text-shadow: 0 0 20px rgba(255, 255, 255, 0.4);
        }

        .domain-slider-container{position:relative;z-index:10}

        .entrance-clone{position:fixed;z-index:9999;pointer-events:none}
        .entrance-clone .domain-nav-btn,.entrance-clone .slider-arrow,.entrance-clone .nav-btn,.entrance-clone button[class*="prev"],.entrance-clone button[class*="next"],.entrance-clone .slider-dots,.entrance-clone .domain-dots{opacity:0!important;visibility:hidden!important}
        .entrance-clone .domain-card{box-shadow:0 0 40px rgba(255, 255, 255,0.15)}

        .star-particle{position:fixed;z-index:10000;pointer-events:none}
        .star-particle svg{width:100%;height:100%;filter:drop-shadow(0 0 6px rgba(255, 255, 255,0.8))}
        .star-particle.shoot{animation:starShoot 0.9s cubic-bezier(0.16,1,0.3,1) forwards}
        @keyframes starShoot{
            0%{opacity:0;transform:scale(0) rotate(0deg)}
            20%{opacity:1;transform:scale(1.4) rotate(60deg)}
            50%{opacity:1;transform:scale(1) rotate(180deg)}
            100%{opacity:0;transform:scale(0.2) rotate(360deg) translate(var(--tx),var(--ty))}
        }

        .gold-ring{position:fixed;border:2px solid rgba(255, 255, 255,0.5);border-radius:50%;z-index:9998;pointer-events:none;opacity:0}
        .gold-ring.expand{animation:ringExpand 0.8s cubic-bezier(0.16,1,0.3,1) forwards}
        @keyframes ringExpand{0%{opacity:0.8;transform:translate(-50%,-50%) scale(0)}60%{opacity:0.4}100%{opacity:0;transform:translate(-50%,-50%) scale(1)}}

        .tab-navigation.anim-hide{opacity:0;transform:translateY(18px)}
        .tab-navigation.anim-show{opacity:1!important;transform:translateY(0)!important;transition:opacity 0.24s cubic-bezier(0.16,1,0.3,1),transform 0.24s cubic-bezier(0.16,1,0.3,1)}
        .tab-navigation.anim-hide .tab-btn{opacity:0;transform:translateY(6px) scale(0.94)}
        .tab-navigation.anim-show .tab-btn{opacity:1!important;transform:translateY(0) scale(1)!important}
        .tab-navigation.anim-show .tab-btn:nth-child(1){transition:opacity 0.18s ease 0.02s,transform 0.18s cubic-bezier(0.16,1,0.3,1) 0.02s,background 0.3s ease,color 0.3s ease,border 0.3s ease,box-shadow 0.3s ease}
        .tab-navigation.anim-show .tab-btn:nth-child(2){transition:opacity 0.18s ease 0.04s,transform 0.18s cubic-bezier(0.16,1,0.3,1) 0.04s,background 0.3s ease,color 0.3s ease,border 0.3s ease,box-shadow 0.3s ease}
        .tab-navigation.anim-show .tab-btn:nth-child(3){transition:opacity 0.18s ease 0.06s,transform 0.18s cubic-bezier(0.16,1,0.3,1) 0.06s,background 0.3s ease,color 0.3s ease,border 0.3s ease,box-shadow 0.3s ease}
        .tab-navigation.anim-show .tab-btn:nth-child(4){transition:opacity 0.18s ease 0.08s,transform 0.18s cubic-bezier(0.16,1,0.3,1) 0.08s,background 0.3s ease,color 0.3s ease,border 0.3s ease,box-shadow 0.3s ease}
        .tab-navigation.anim-show .tab-btn:nth-child(5){transition:opacity 0.18s ease 0.10s,transform 0.18s cubic-bezier(0.16,1,0.3,1) 0.10s,background 0.3s ease,color 0.3s ease,border 0.3s ease,box-shadow 0.3s ease}

        .tab-content.anim-hide{opacity:0;transform:translateY(22px);pointer-events:none}
        .tab-content.anim-show{opacity:1!important;transform:translateY(0)!important;pointer-events:auto!important;transition:opacity 0.24s cubic-bezier(0.16,1,0.3,1),transform 0.24s cubic-bezier(0.16,1,0.3,1)}
        .entrance-active .tab-content{opacity:0!important;transform:translateY(22px)!important}
        .entrance-active .tab-navigation{opacity:0!important;transform:translateY(18px)!important}

        .tab-navigation {
            display: flex;
            background: rgba(11, 12, 16, 0.85);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            padding: 6px;
            margin-bottom: 25px;
            gap: 4px;
            max-width: 700px;
        }

        .tab-btn {
            flex: 1;
            padding: 10px 15px;
            background: transparent;
            border: none;
            border-bottom: 1px solid transparent;
            color: rgba(255, 255, 255, 0.45);
            font-family: 'Rajdhani', sans-serif;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            border-radius: 0;
            transition: color 0.3s ease, border-color 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            white-space: nowrap;
        }

        .tab-btn i { font-size: 0.85rem; }
        .tab-btn:hover { color: rgba(255,255,255,0.85); background: transparent; border-bottom-color: rgba(255,255,255,0.13); }

        .tab-btn.active {
            background: transparent;
            color: #fff;
            border: none;
            border-bottom: 1px solid rgba(255,255,255,0.55);
            box-shadow: 0 1px 0 0 rgba(255,255,255,0.08);
            text-shadow: 0 0 12px rgba(255,255,255,0.15);
            animation: none;
        }
        @keyframes tabPulse{0%,100%{box-shadow:none}50%{box-shadow:none}}

        .card{background:transparent;backdrop-filter:none;border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:20px;width:100%;overflow:hidden}
        .input-group input:focus,.input-group textarea:focus,.input-group select:focus{border-color:rgba(255,255,255,0.45)!important;outline:none;box-shadow:inset 0 0 0 1px rgba(255,255,255,0.06), 0 0 0 3px rgba(255,255,255,0.03)!important;background:rgba(255,255,255,0.015)!important}
        .domain-card{transition:transform 0.3s cubic-bezier(0.16,1,0.3,1),box-shadow 0.3s ease,border-color 0.3s ease}
        .domain-card:hover{transform:translateY(-2px) scale(1.02);box-shadow:0 6px 24px rgba(255,255,255,0.08)}
        .tab-btn.active i { color: rgba(255,255,255,0.9); }

        .form-row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-bottom: 15px; max-width: 100%; }
        .form-row.three-col { grid-template-columns: repeat(3, 1fr); }
        .form-row.full-width { grid-template-columns: 1fr; }
        .input-group.full { grid-column: 1 / -1; }

        .input-group textarea { padding: 12px 15px; background: transparent; border: 1px solid var(--border); color: var(--text); border-radius: 8px; font-family: 'Rajdhani', sans-serif; font-size: 1rem; resize: vertical; min-height: 80px; transition: border-color 0.3s ease; width: 100%; }
        .input-group textarea:hover { border-color: var(--border-hover); background: transparent; }
        .input-group textarea:focus { border-color: rgba(255, 255, 255, 0.45); outline: none; box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06), 0 0 0 3px rgba(255,255,255,0.03); background: rgba(255,255,255,0.015); }

        .card h2, .card h3 { color: rgba(255,255,255,0.85); font-family: 'Rajdhani', sans-serif; font-size: 1.1rem; margin-bottom: 15px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1.2rem; margin: 1.5rem 0; width: 100%; }
        .input-group { display: flex; flex-direction: column; gap: 0.4rem; min-width: 0; overflow: visible; position: relative; }
        .input-group label { color: rgba(255, 255, 255, 0.6); font-family: 'Rajdhani', sans-serif; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; }

        .input-group input, .input-group select { padding: 12px 15px; background: transparent; border: 1px solid var(--border); color: var(--text); border-radius: 8px; font-family: 'Rajdhani', sans-serif; font-size: 1rem; transition: border-color 0.3s ease; width: 100%; min-width: 0; box-sizing: border-box; }
        .input-group input[type="number"]::-webkit-outer-spin-button, .input-group input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .input-group input[type="number"] { -moz-appearance: textfield; }
        .input-group input[type="date"] { position: relative; cursor: pointer; -webkit-appearance: none; appearance: none; min-width: 0; max-width: 100%; }
        .input-group input[type="date"]::-webkit-calendar-picker-indicator { background: transparent; color: transparent; cursor: pointer; position: absolute; right: 0; top: 0; width: 100%; height: 100%; opacity: 0; }

        .date-input-wrapper { position: relative; width: 100%; overflow: hidden; }


input.en-date-src{letter-spacing:.02em}

.en-date-native{position:absolute!important;opacity:0!important;pointer-events:none!important;width:1px!important;height:1px!important;left:0;top:0}
.date-input-wrapper .en-date-view{width:100%;padding-right:40px;box-sizing:border-box;max-width:100%}
.en-cal{position:fixed;z-index:100000;width:280px;background:#0c0c12;border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:12px;box-shadow:0 18px 50px rgba(0,0,0,.45);font-family:Arial,sans-serif;color:#fff}
.en-cal-head{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.en-cal-head select,.en-cal-head input{background:#14141c;border:1px solid rgba(255,255,255,.08);color:#fff;border-radius:8px;height:32px;padding:0 8px;font-size:13px}
.en-cal-head select{flex:1;cursor:pointer}
.en-cal-head input{width:72px;text-align:center}
.en-cal-head button{background:transparent;border:0;color:rgba(255,255,255,.7);cursor:pointer;font-size:16px;width:28px;height:28px}
.en-cal-days,.en-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center}
.en-cal-days span{font-size:10px;color:rgba(255,255,255,.35);padding:4px 0}
.en-cal-grid button,.en-cal-grid span{height:30px;border:0;background:transparent;color:rgba(255,255,255,.85);border-radius:6px;font-size:12px;cursor:pointer}
.en-cal-day:hover{background:rgba(255,255,255,.08)}
.en-cal-day.on{background:#2563eb;color:#fff}
.en-cal-day.today{box-shadow:inset 0 0 0 1px rgba(37,99,235,.8)}
.en-cal-foot{display:flex;justify-content:space-between;margin-top:10px}
.en-cal-foot button{background:transparent;border:0;color:#60a5fa;cursor:pointer;font-size:12px;padding:4px 0}

.date-input-wrapper input { width: 100%; padding-right: 40px; box-sizing: border-box; max-width: 100%; }
        .date-input-wrapper::after { content: ''; position: absolute; right: 12px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='rgba(255,255,255,0.5)' viewBox='0 0 24 24'%3E%3Cpath d='M19 4h-1V2h-2v2H8V2H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2z'/%3E%3C/svg%3E"); background-size: contain; background-repeat: no-repeat; pointer-events: none; }

        .input-group input:hover, .input-group select:hover { border-color: var(--border-hover); background: transparent; }
        .input-group input:focus, .input-group select:focus { border-color: rgba(255, 255, 255, 0.45); outline: none; box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06), 0 0 0 3px rgba(255,255,255,0.03); background: rgba(255,255,255,0.015); }
        .input-group select option { background: #07070b; color: #fff; }

        .save-btn { background: rgba(255, 255, 255, 0.9); border: none; color: #0a0b0f; padding: 13px 44px; border-radius: 10px; cursor: pointer; font-family: 'Rajdhani', sans-serif; font-size: 1rem; font-weight: 700; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; gap: 8px; margin: 20px auto 0; box-shadow: 0 2px 12px rgba(0,0,0,0.2); }
        .save-btn:hover { background: #fff; box-shadow: 0 4px 24px rgba(255,255,255,0.1), 0 2px 12px rgba(0,0,0,0.25); transform: translateY(-1px); }
        .save-btn:active { transform: translateY(0); box-shadow: 0 1px 8px rgba(0,0,0,0.2); }
        .hidden { display: none !important; }
        .tab-content { width: 100%; max-width: 900px; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

        .copy-modal { position: fixed; bottom: -100px; left: 50%; transform: translateX(-50%); background: var(--card-bg); backdrop-filter: blur(15px); padding: 20px 35px; border-radius: 15px; border: 2px solid var(--primary-gold); box-shadow: 0 0 40px rgba(255, 255, 255, 0.15); opacity: 0; display: flex; align-items: center; gap: 15px; transition: all 0.4s ease; z-index: 1000; white-space: nowrap; }
        .copy-modal.show { bottom: 40px; opacity: 1; }
        .copy-modal i { color: rgba(255, 255, 255, 0.7); font-size: 1.5rem; }
        .copy-modal p { color: #fff; font-size: 1.1rem; font-weight: 600; }

        .error-modal { position: fixed; bottom: -100px; left: 50%; transform: translateX(-50%); background: var(--card-bg); backdrop-filter: blur(15px); padding: 20px 35px; border-radius: 15px; border: 2px solid #ff4444; box-shadow: 0 0 40px rgba(255, 68, 68, 0.4); opacity: 0; display: flex; align-items: center; gap: 15px; transition: all 0.4s ease; z-index: 1000; max-width: 90vw; }
        .error-modal.show { bottom: 40px; opacity: 1; }
        .error-modal i { color: #ff4444; font-size: 1.5rem; flex-shrink: 0; }
        .error-modal p { color: #fff; font-size: 1.1rem; font-weight: 600; word-break: break-word; }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 15px; padding-top: 20px; align-items: stretch; }
            .tab-navigation { flex-wrap: nowrap; max-width: 100%; gap: 3px; padding: 4px; margin-bottom: 18px; border-radius: 10px; overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
            .tab-navigation::-webkit-scrollbar { display: none; }
            .tab-btn { flex: 0 0 auto; padding: 10px 10px; font-size: 0.62rem; gap: 4px; border-radius: 6px; white-space: nowrap; align-items: center; justify-content: center; }
            .tab-btn i { font-size: 0.6rem; flex-shrink: 0; }
            .form-grid { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; gap: 12px; }
            .form-row.three-col { grid-template-columns: 1fr; }
            .domain-slider-container { max-width: 100%; }
            .domain-card { min-width: 180px; padding: 12px 20px; }
            .domain-name { font-size: 1rem; }
            .tab-content { max-width: 100%; }
            .card { padding: 16px; border-radius: 10px; }
            .save-btn { width: 100%; padding: 12px 20px; }
            .input-group input, .input-group select { padding: 10px 12px; font-size: 0.95rem; }
            .input-group textarea { padding: 10px 12px; font-size: 0.95rem; }
            .input-group label { font-size: 0.7rem; }
            .toggle-box-container { flex-direction: column; gap: 10px; }
            .toggle-box { min-width: unset; width: 100%; padding: 14px 16px; }
            .settings-webhook-group input { padding: 12px 14px; font-size: 0.95rem; }
            .url-input-wrapper { flex-direction: row; }
            .url-input-wrapper input { font-size: 0.85rem; padding: 10px 12px; min-width: 0; }
            .url-copy-btn { padding: 10px 12px; }
            .color-input-wrapper { flex-wrap: wrap; }
            .color-input-wrapper input[type="color"] { width: 42px; height: 38px; }
            .color-input-wrapper input[type="text"] { flex: 1; min-width: 0; }
            .copy-modal { padding: 14px 20px; max-width: 90vw; }
            .copy-modal p { font-size: 0.95rem; }
            .error-modal { padding: 14px 20px; }
            .error-modal p { font-size: 0.95rem; }
            .date-input-wrapper input { padding-right: 35px; }
        }

        @media (max-width: 480px) {
            .main-content { padding: 10px; padding-top: 15px; }
            .tab-navigation { gap: 3px; padding: 4px; margin-bottom: 14px; }
            .tab-btn { padding: 8px 8px; font-size: 0.55rem; gap: 3px; }
            .tab-btn i { font-size: 0.55rem; }
            .card { padding: 12px; border-radius: 8px; }
            .input-group input, .input-group select { padding: 9px 10px; font-size: 0.9rem; border-radius: 6px; }
            .input-group textarea { padding: 9px 10px; font-size: 0.9rem; border-radius: 6px; }
            .save-btn { padding: 11px 16px; font-size: 0.9rem; border-radius: 6px; }
            .toggle-box { padding: 12px 14px; border-radius: 8px; }
            .toggle-box .toggle-label { font-size: 0.85rem; }
            .settings-webhook-group input { padding: 10px 12px; font-size: 0.9rem; border-radius: 6px; }
            .url-copy-btn { padding: 9px 10px; border-radius: 6px 0 0 6px; }
            .url-input-wrapper input { padding: 9px 10px; font-size: 0.8rem; border-radius: 0 6px 6px 0 !important; }
            .color-input-wrapper input[type="color"] { width: 38px; height: 36px; border-radius: 6px; }
            .copy-modal { padding: 12px 16px; border-radius: 10px; }
            .copy-modal i { font-size: 1.2rem; }
            .copy-modal p { font-size: 0.85rem; }
            .error-modal { padding: 12px 16px; border-radius: 10px; }
            .error-modal i { font-size: 1.2rem; }
            .error-modal p { font-size: 0.85rem; }
            .date-input-wrapper input { padding-right: 30px; font-size: 0.85rem; }
            .date-input-wrapper::after { right: 8px; width: 14px; height: 14px; }
        }

        @media (max-width: 360px) {
            .main-content { padding: 8px; }
            .tab-navigation { gap: 2px; padding: 3px; }
            .tab-btn { padding: 6px 4px; font-size: 0.65rem; }
            .tab-btn i { font-size: 0.65rem; }
            .card { padding: 10px; }
            .input-group input, .input-group select, .input-group textarea { font-size: 0.85rem; padding: 8px; }
            .save-btn { font-size: 0.85rem; }
        }

        input::placeholder, textarea::placeholder { color: #888 !important; opacity: 1; }
        input::-webkit-input-placeholder, textarea::-webkit-input-placeholder { color: #888 !important; }
        input::-moz-placeholder, textarea::-moz-placeholder { color: #888 !important; }
        input:-ms-input-placeholder, textarea:-ms-input-placeholder { color: #888 !important; }

        .toggle-switch { position: relative; display: inline-block; width: 50px; height: 26px; margin-top: 5px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.2); transition: 0.3s; border-radius: 26px; }
        .toggle-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: #888; transition: 0.3s; border-radius: 50%; }
        .toggle-switch input:checked + .toggle-slider { background-color: rgba(0, 150, 255, 0.3); border-color: rgba(0, 150, 255, 0.5); }
        .toggle-switch input:checked + .toggle-slider:before { transform: translateX(24px); background-color: #00a2ff; }

        .toggle-box-container { display: flex; gap: 20px; flex-wrap: wrap; }
        .toggle-box { display: flex; align-items: center; justify-content: space-between; gap: 15px; padding: 16px 20px; background: transparent; border: 1px solid var(--border); border-radius: 12px; min-width: 200px; position: relative; overflow: hidden; transition: border-color 0.3s; }
        .toggle-box:hover { border-color: var(--border-hover); }
        .toggle-box.disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }
        .toggle-box-info { display: flex; flex-direction: column; gap: 2px; }
        .toggle-box .toggle-label { font-size: 0.9rem; font-weight: 600; color: rgba(255, 255, 255, 0.35); letter-spacing: 0.5px; transition: color 0.3s; }
        .toggle-box .toggle-subtitle { font-size: 0.7rem; color: rgba(255, 255, 255, 0.2); text-transform: uppercase; letter-spacing: 1px; transition: color 0.3s; }

        .modern-switch { position: relative; width: 44px; height: 24px; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 12px; transition: all 0.3s ease; flex-shrink: 0; }
        .modern-switch::after { content: ''; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; background: rgba(255, 255, 255, 0.2); border-radius: 50%; transition: all 0.3s ease; }
        .toggle-box.active .modern-switch { background: rgba(255, 255, 255, 0.1); border-color: rgba(255, 255, 255, 0.25); }
        .toggle-box.active .modern-switch::after { transform: translateX(20px); background: rgba(255, 255, 255, 0.85); }
        .toggle-box.active .toggle-label { color: rgba(255, 255, 255, 0.85); }
        .toggle-box.active .toggle-subtitle { color: rgba(255, 255, 255, 0.4); }

        .settings-webhook-group { margin-top: 25px; }
        .settings-webhook-group label { display: block; font-size: 0.75rem; color: rgba(255, 255, 255, 0.5); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
        .settings-webhook-group input { width: 100%; padding: 14px 16px; background: transparent; border: 1px solid var(--border); border-radius: 8px; color: var(--text); font-family: 'Rajdhani', sans-serif; font-size: 1rem; transition: border-color 0.3s ease; box-sizing: border-box; }
        .settings-webhook-group input:focus { outline: none; border-color: rgba(255, 255, 255, 0.45); box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06), 0 0 0 3px rgba(255,255,255,0.03); background: rgba(255,255,255,0.015); }
        .settings-webhook-group input.saving { border-color: rgba(255, 255, 255, 0.3); animation: webhookChecking 1s ease-in-out infinite; }
        @keyframes webhookChecking { 0%, 100% { border-color: rgba(255, 255, 255, 0.15); box-shadow: 0 0 5px rgba(255, 255, 255, 0.05); } 50% { border-color: rgba(255, 255, 255, 0.4); box-shadow: 0 0 15px rgba(255, 255, 255, 0.1); } }
        .settings-webhook-group input.saved { border-color: rgba(255, 255, 255, 0.35); box-shadow: 0 0 15px rgba(255, 255, 255, 0.08); }
        .settings-webhook-group input.error { border-color: #ff4444; box-shadow: 0 0 15px rgba(255, 68, 68, 0.2); }

        .url-input-wrapper { display: flex; gap: 0; align-items: stretch; width: 100%; }
        .url-copy-btn { background: transparent; border: 1px solid var(--border); border-right: none; color: rgba(255,255,255,0.55); padding: 12px 15px; border-radius: 8px 0 0 8px; cursor: pointer; font-size: 1rem; transition: color 0.3s ease, border-color 0.3s ease; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .url-copy-btn:hover { background: transparent; color: rgba(255,255,255,0.9); border-color: var(--border-hover); }
        .url-input-wrapper input { flex: 1; border-radius: 0 8px 8px 0 !important; background: transparent; border: 1px solid var(--border); color: var(--text); padding: 12px 15px; font-family: 'Rajdhani', sans-serif; font-size: 1rem; min-width: 0; box-sizing: border-box; }

        .color-input-wrapper { display: flex; gap: 10px; align-items: center; }
        .color-input-wrapper input[type="color"] { width: 50px; height: 42px; padding: 2px; border: 1px solid var(--border); border-radius: 8px; background: transparent; cursor: pointer; flex-shrink: 0; }
        .color-input-wrapper input[type="color"]::-webkit-color-swatch-wrapper { padding: 2px; }
        .color-input-wrapper input[type="color"]::-webkit-color-swatch { border-radius: 4px; border: none; }
        .color-input-wrapper input[type="text"] { flex: 1; min-width: 0; }

        .inline-checkbox { width: 16px !important; height: 16px !important; margin-left: 8px; vertical-align: middle; accent-color: #00a2ff; }

        .ql-trigger {
            position: relative;
            width: 34px;
            height: 34px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 9px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            z-index: 100;
            flex-shrink: 0;
        }
        .ql-trigger:hover {
            border-color: rgba(255, 255, 255, 0.3);
            background: rgba(255, 255, 255, 0.08);
        }
        .ql-trigger.active {
            border-color: rgba(255, 255, 255, 0.35);
            background: rgba(255, 255, 255, 0.06);
        }
        .ql-trigger svg {
            width: 15px;
            height: 15px;
            opacity: 0.7;
            transition: all 0.4s ease;
        }
        .ql-trigger:hover svg {
            opacity: 0.9;
        }
        .ql-trigger.active svg {
            opacity: 1;
        }
        .ql-trigger svg line,
        .ql-trigger svg circle {
            stroke: rgba(255, 255, 255, 0.5);
            fill: rgba(255, 255, 255, 0.5);
            stroke-width: 1.5;
            stroke-linecap: round;
            transition: all 0.3s ease;
        }
        .ql-trigger:hover svg circle {
            stroke: rgba(255, 255, 255, 0.7);
            fill: rgba(255, 255, 255, 0.7);
        }
        .ql-trigger.active svg circle {
            stroke: rgba(255, 255, 255, 0.8);
            fill: rgba(255, 255, 255, 0.8);
        }

        .ql-wrapper {
            position: absolute;
            right: 0;
            top: 0;
            display: flex;
            align-items: flex-start;
            z-index: 99;
        }

        .ql-panel {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            width: 180px;
            background: rgba(8, 9, 13, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            padding: 6px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-6px) scale(0.97);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            z-index: 101;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.03) inset;
        }
        .ql-panel.open {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }

        .ql-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: 7px;
            font-family: 'Rajdhani', sans-serif;
            font-size: 0.82rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            color: rgba(255, 255, 255, 0.7);
            cursor: pointer;
            transition: all 0.25s ease;
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }
        .ql-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 2px;
            height: 0;
            background: var(--primary-gold);
            border-radius: 2px;
            transition: height 0.25s ease;
        }
        .ql-item:hover::before {
            height: 60%;
        }
        .ql-item:hover {
            background: rgba(255, 255, 255, 0.06);
            color: rgba(255, 255, 255, 0.9);
        }
        .ql-item i {
            font-size: 0.72rem;
            width: 16px;
            text-align: center;
            opacity: 0.6;
            transition: opacity 0.25s ease;
        }
        .ql-item:hover i {
            opacity: 1;
        }

        .ql-item.disabled {
            opacity: 0.22;
            cursor: default;
            pointer-events: none;
        }
        .ql-item.disabled::after {
            content: 'soon';
            font-size: 0.55rem;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255, 255, 255, 0.2);
            margin-left: auto;
            font-weight: 500;
        }

        .ql-item.ql-active { color: rgba(255, 255, 255, 0.85); }

        .section-wrap{width:100%;display:flex;flex-direction:column;align-items:center}

        @media (max-width: 768px) {
            .ql-trigger { width: 30px; height: 30px; }
            .ql-trigger svg { width: 13px; height: 13px; }
            .ql-panel { width: 160px; }
            .ql-item { padding: 8px 10px; font-size: 0.78rem; }
        }

        .switch-overlay{position:fixed;top:0;left:0;width:100%;height:100%;z-index:99999;pointer-events:none}
        .switch-square{position:absolute;background:rgba(255, 255, 255,0.15);border:1px solid rgba(255, 255, 255,0.2);border-radius:3px;opacity:0;will-change:transform;backface-visibility:hidden;-webkit-backface-visibility:hidden}
        .section-fade-out{opacity:0;transition:opacity 0.35s ease}
        .section-fade-in{opacity:0;transition:opacity 0.35s ease}

        .ah-promo{position:absolute;top:calc(100% + 12px);right:-8px;background:rgba(7,7,11,0.6);border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:12px 36px 12px 14px;display:flex;align-items:center;gap:10px;z-index:102;cursor:pointer;opacity:0;visibility:hidden;transform:translateY(-8px) scale(0.96);transition:all 0.4s cubic-bezier(0.16,1,0.3,1);box-shadow:none;white-space:nowrap;pointer-events:none;backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px)}
        .ah-promo.show{opacity:1;visibility:visible;transform:translateY(0) scale(1);pointer-events:auto}
        .ah-promo:hover{border-color:rgba(255,255,255,0.2);background:rgba(7,7,11,0.7)}
        .ah-promo-arrow{position:absolute;top:-6px;right:18px;width:10px;height:10px;background:rgba(7,7,11,0.6);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border-left:1px solid rgba(255,255,255,0.1);border-top:1px solid rgba(255,255,255,0.1);transform:rotate(45deg)}
        .ah-promo-content{display:flex;align-items:center;gap:10px}
        .ah-promo-char{flex-shrink:0;display:flex;align-items:center;justify-content:center}
        .ah-promo-text{display:flex;flex-direction:column;gap:1px}
        .ah-promo-msg{font-family:'Rajdhani',sans-serif;font-size:0.82rem;font-weight:600;color:rgba(255,255,255,0.8);letter-spacing:0.2px}
        .ah-promo-msg strong{color:#fff}
        .ah-promo-sub{font-family:'Rajdhani',sans-serif;font-size:0.58rem;color:rgba(255,255,255,0.25);text-transform:uppercase;letter-spacing:1px}
        .ah-promo-close{position:absolute;top:6px;right:6px;width:18px;height:18px;background:transparent;border:none;color:rgba(255,255,255,0.2);font-size:0.85rem;cursor:pointer;display:flex;align-items:center;justify-content:center;border-radius:50%;transition:all 0.2s;line-height:1}
        .ah-promo-close:hover{color:rgba(255,255,255,0.5);background:rgba(255,255,255,0.06)}
        @keyframes ahExtPulse{
            0%,100%{transform:rotate(0deg) scale(1)}
            25%{transform:rotate(6deg) scale(1.05)}
            50%{transform:rotate(0deg) scale(1)}
            75%{transform:rotate(-6deg) scale(1.05)}
        }
        @keyframes ahExtRing{
            0%,100%{r:12;opacity:0.15}
            50%{r:14;opacity:0.06}
        }
        .ah-ext-piece{transform-origin:24px 22px;animation:ahExtPulse 2.5s ease-in-out infinite}
        .ah-ext-ring{animation:ahExtRing 2.5s ease-in-out infinite}
        .ah-promo.show .ah-ext-piece{animation:ahExtPulse 2.5s ease-in-out infinite}
        @keyframes ahPromoFloat{
            0%,100%{transform:translateY(0)}
            50%{transform:translateY(-2px)}
        }
        .ah-promo.show .ah-promo-char{animation:ahPromoFloat 2.5s ease-in-out infinite}
        @media(max-width:480px){
            .ah-promo{right:-4px;padding:10px 30px 10px 10px;border-radius:10px}
            .ah-promo-char svg{width:30px;height:30px}
            .ah-promo-msg{font-size:0.75rem}
        }

        .dn-input-row { display: flex; gap: 8px; align-items: stretch; }
        .dn-input-row input { flex: 1; min-width: 0; }
        .dn-toggle-box { width: 42px; min-width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; background: transparent; border: 1px solid var(--border); border-radius: 8px; cursor: pointer; transition: border-color 0.3s ease, color 0.3s ease; position: relative; user-select: none; overflow: hidden; }
        .dn-toggle-box:hover { border-color: var(--border-hover); }
        .dn-toggle-box.active { background: transparent; border-color: rgba(255,255,255,0.32); }
        .dn-toggle-box i { font-size: 0.8rem; color: rgba(255,255,255,0.25); transition: color 0.3s ease; }
        .dn-toggle-box.active i { color: rgba(255,255,255,0.85); }
        .dn-tooltip { position: fixed; width: 280px; background: rgba(7,7,11,0.6); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1); border-radius: 10px; padding: 14px; opacity: 0; visibility: hidden; transition: opacity 0.25s, visibility 0.25s; pointer-events: auto; z-index: 99999; box-shadow: none; }
        
        .dn-toggle-box::after { content: ''; position: absolute; left: 0; width: 100%; height: 100%; border-radius: 7px; background: linear-gradient(180deg, transparent 0%, currentColor 50%, transparent 100%); opacity: 0; pointer-events: none; }
        .dn-toggle-box.dn-wave::after { animation: dnSweepDown 0.6s ease-in-out; }
        .dn-toggle-box.dn-wave-up::after { animation: dnSweepUp 0.6s ease-in-out; }
        @keyframes dnSweepDown { 0% { opacity: 0; top: -100%; } 20% { opacity: 0.15; } 80% { opacity: 0.15; } 100% { opacity: 0; top: 100%; } }
        @keyframes dnSweepUp { 0% { opacity: 0; top: 100%; } 20% { opacity: 0.15; } 80% { opacity: 0.15; } 100% { opacity: 0; top: -100%; } }

        .dn-tooltip::after { content: ''; position: absolute; top: 100%; right: 20px; border: 6px solid transparent; border-top-color: rgba(255,255,255,0.1); pointer-events: none; }
        .dn-tooltip-title { font-size: 0.7rem; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
        .dn-tooltip-preview { background: transparent; border: 1px solid rgba(255,255,255,0.07); border-radius: 6px; padding: 10px 12px; text-align: center; margin-bottom: 8px; }
        .dn-tooltip-preview .fires-text { color: #ffffff; font-size: 0.85rem; font-weight: 700; text-decoration: underline; }
        .dn-tooltip-preview .fires-emoji { font-size: 0.75rem; }
        .dn-tooltip-desc { font-size: 0.7rem; color: rgba(255,255,255,0.35); line-height: 1.4; }
        .dn-tooltip-status { display: inline-block; font-size: 0.6rem; font-weight: 700; letter-spacing: 1px; padding: 2px 6px; border-radius: 4px; margin-top: 6px; transition: all 0.3s; }
        .dn-tooltip-status.pop { animation: dnStatusPop 0.35s ease; }
        @keyframes dnStatusPop { 0% { opacity: 0.3; } 100% { opacity: 1; } }
        .dn-tooltip-status { background: transparent; border: 1px solid rgba(255,255,255,0.13); color: rgba(255,255,255,0.4); }
        .dn-tooltip-status.active { background: transparent; border: 1px solid rgba(255,255,255,0.32); color: rgba(255,255,255,0.85); }
    
html body,html body *{font-family:'Outfit',sans-serif!important;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;text-rendering:optimizeLegibility;-webkit-text-size-adjust:100%;text-size-adjust:100%}
@media(max-width:768px){html body,html body *{font-family:'Poppins',sans-serif!important;font-weight:700!important}}
html body .fa,html body .fas,html body .far,html body .fab,html body .fal,html body .fad,html body [data-prefix]{font-family:'Font Awesome 6 Free'!important}
html body .fab{font-family:'Font Awesome 6 Brands'!important}


html body .tab-content input[type="text"],
html body .tab-content input[type="url"],
html body .tab-content input[type="email"],
html body .tab-content input[type="number"],
html body .tab-content input[type="password"],
html body .tab-content input[type="search"],
html body .tab-content input[type="tel"],
html body .tab-content input[type="date"],
html body .tab-content input:not([type]),
html body .tab-content textarea,
html body .tab-content select {
    background: transparent !important;
    background-color: transparent !important;
    border: 1px solid var(--border) !important;
    color: var(--text) !important;
    box-shadow: none !important;
    backdrop-filter: none !important;
}
html body .tab-content input:hover:not(:focus),
html body .tab-content textarea:hover:not(:focus),
html body .tab-content select:hover:not(:focus) {
    background: transparent !important;
    background-color: transparent !important;
    border-color: var(--border-hover) !important;
    box-shadow: none !important;
}
html body .tab-content input:focus,
html body .tab-content textarea:focus,
html body .tab-content select:focus {
    background: rgba(255,255,255,0.015) !important;
    background-color: rgba(255,255,255,0.015) !important;
    border-color: rgba(255,255,255,0.45) !important;
    outline: none !important;
    box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06), 0 0 0 3px rgba(255,255,255,0.03) !important;
}
html body .tab-content input[type="color"] {
    background: transparent !important;
    border: 1px solid var(--border) !important;
}
html body .tab-content select option {
    background: #07070b !important;
    color: #fff !important;
}
html body .tab-content .card,
html body .tab-content .form-section,
html body .tab-content .section {
    background: transparent !important;
    backdrop-filter: none !important;
}

</style>
</head>
<body>
<canvas id="realBootBg" aria-hidden="true"></canvas>
<script>
(function(){
    var c=document.getElementById('realBootBg');
    if(!c)return;
    var x=c.getContext('2d'),W,H,dots=[];
    var mobile=innerWidth<768;
    var reduced=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var COUNT=reduced?0:(mobile?120:220);
    var KEY='ultimaBgDotsStateV3';
    function reloadNav(){try{var n=performance.getEntriesByType&&performance.getEntriesByType('navigation')[0];return !!(n&&n.type==='reload')}catch(e){return false}}
    function copy(o){return{x:o.x||0,y:o.y||0,vx:typeof o.vx==='number'?o.vx:0,vy:typeof o.vy==='number'?o.vy:0,r:o.r||.6,baseAlpha:typeof o.baseAlpha==='number'?o.baseAlpha:.12,phase:typeof o.phase==='number'?o.phase:0}}
    function restore(limit){try{if(0)return[];var s=JSON.parse(sessionStorage.getItem(KEY)||'null');if(!s||!Array.isArray(s.d)||Date.now()-(s.t||0)>60000)return[];var sw=s.w||W,sh=s.h||H;return s.d.slice(0,limit).map(function(o){var p=copy(o);if(sw&&sh){p.x=p.x/sw*W;p.y=p.y/sh*H}return p})}catch(e){return[]}}
    function save(){try{sessionStorage.setItem(KEY,JSON.stringify({t:Date.now(),w:W,h:H,d:dots.slice(0,500)}))}catch(e){}}
    function sync(){window.__ultimaBgDots=dots;window.__ultimaBootDots=dots}
    function resize(){W=c.width=innerWidth||document.documentElement.clientWidth||1;H=c.height=innerHeight||document.documentElement.clientHeight||1}
    function make(){return{x:Math.random()*W,y:Math.random()*H,vx:(Math.random()-.5)*.12,vy:(Math.random()-.5)*.12,r:Math.random()*.9+.35,baseAlpha:Math.random()*.10+.12,phase:Math.random()*Math.PI*2}}
    resize();
    if(COUNT&&window.__ultimaBgDots&&window.__ultimaBgDots.length)dots=window.__ultimaBgDots.slice(0,COUNT).map(copy);
    else if(COUNT&&window.__ultimaBootDots&&window.__ultimaBootDots.length)dots=window.__ultimaBootDots.slice(0,COUNT).map(copy);
    else if(COUNT)dots=restore(COUNT);
    if(!dots.length){while(dots.length<COUNT)dots.push(make());}
    sync();
    addEventListener('pagehide',save);
    addEventListener('beforeunload',save);
    if(!COUNT)return;
    function anim(){
        if(!document.getElementById('realBootBg'))return;
        x.clearRect(0,0,W,H);
        var t=Date.now()*.001;
        for(var i=0;i<dots.length;i++){
            var p=dots[i];p.x+=p.vx;p.y+=p.vy;
            if(p.x<0)p.x=W;if(p.x>W)p.x=0;if(p.y<0)p.y=H;if(p.y>H)p.y=0;
            x.beginPath();x.arc(p.x,p.y,p.r,0,Math.PI*2);
            x.fillStyle='rgba(255,255,255,'+(Math.min(1,Math.max(0.08,p.baseAlpha+Math.sin(t*0.22+p.phase)*0.03)))+')';x.fill();
        }
        sync();
        requestAnimationFrame(anim);
    }
    if(!window.__ultimaStarsLive){window.__ultimaStarsLive=1;anim();}
    var rt;addEventListener('resize',function(){clearTimeout(rt);rt=setTimeout(function(){resize();sync()},150)});
})();
</script>
<?php include '../includes/dashboard/header.php'; ?>


    <div class="animated-bg">
        <div class="lightning"></div>
        <div class="lightning"></div>
        <div class="lightning"></div>
        <div class="geo-container">
            <div class="geo-line"></div>
            <div class="geo-line"></div>
            <div class="geo-line"></div>
            <div class="geo-line"></div>
            <div class="geo-line"></div>
            <div class="geo-line"></div>
            <div class="connection-point"></div>
            <div class="connection-point"></div>
            <div class="connection-point"></div>
            <div class="connection-point"></div>
            <div class="particle"></div>
            <div class="particle"></div>
        </div>
    </div>

    <div class="container">
        <?php $skipSidebarCounts = true; include '../includes/dashboard/sidebar.php'; ?>

        <main class="main-content no-transition">
            <div style="position:relative;width:100%;max-width:900px;">
                <div class="ql-wrapper">
                    <button class="ql-trigger" id="qlTrigger" onclick="toggleQuickLinks()" aria-label="Quick Links">
                        <svg viewBox="0 0 16 16">
                            <circle cx="3" cy="8" r="1.2"/>
                            <circle cx="8" cy="8" r="1.2"/>
                            <circle cx="13" cy="8" r="1.2"/>
                        </svg>
                    </button>
                    <div class="ql-panel" id="qlPanel">
                        <a class="ql-item ql-link-btn <?php if($subPage !== 'autohar' && $subPage !== 'extension'): ?>ql-active<?php endif; ?>" href="javascript:void(0)" onclick="switchPage('links')">
                            <i class="fas fa-link"></i> Links
                        </a>
                        <a class="ql-item ql-autohar-btn <?php if($subPage === 'autohar'): ?>ql-active<?php endif; ?>" href="javascript:void(0)" onclick="switchPage('autohar')">
                            <i class="fas fa-file-lines"></i> AutoHar
                        </a>
                        <a class="ql-item ql-ext-btn <?php if($subPage === 'extension'): ?>ql-active<?php endif; ?>" href="javascript:void(0)" onclick="switchPage('extension')">
                            <i class="fas fa-puzzle-piece"></i> Extension
                        </a>
                        <div class="ql-item disabled">
                            <i class="fas fa-bookmark"></i> BookMarks
                        </div>
                    </div>
                    <div class="ah-promo" id="ahPromo" onclick="switchPage('extension');closeAhPromo();">
                        <div class="ah-promo-arrow"></div>
                        <div class="ah-promo-content">
                            <div class="ah-promo-char" id="ahPromoChar">
                                <svg viewBox="0 0 48 48" width="36" height="36">
                                    <defs>
                                        <linearGradient id="extPromoGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                            <stop offset="0%" style="stop-color:rgba(130,170,255,0.9)"/>
                                            <stop offset="100%" style="stop-color:rgba(100,140,255,0.7)"/>
                                        </linearGradient>
                                    </defs>
                                    <rect x="4" y="4" width="40" height="40" rx="10" fill="rgba(255,255,255,0.06)" stroke="rgba(130,170,255,0.2)" stroke-width="1"/>
                                    <g class="ah-ext-piece">
                                        <path d="M20 12h8a2 2 0 012 2v3.5a1 1 0 001 1h1a2.5 2.5 0 010 5h-1a1 1 0 00-1 1V28a2 2 0 01-2 2h-3.5a1 1 0 01-1-1v-1a2.5 2.5 0 00-5 0v1a1 1 0 01-1 1H14a2 2 0 01-2-2v-4.5a1 1 0 011-1h1a2.5 2.5 0 000-5h-1a1 1 0 01-1-1V14a2 2 0 012-2h3.5a1 1 0 001-1v-1a2.5 2.5 0 015 0v1a1 1 0 001 1z" fill="url(#extPromoGrad)" opacity="0.85"/>
                                    </g>
                                    <circle cx="24" cy="22" r="12" fill="none" stroke="rgba(130,170,255,0.15)" stroke-width="0.5" class="ah-ext-ring"/>
                                </svg>
                            </div>
                            <div class="ah-promo-text">
                                <span class="ah-promo-msg">Try <strong>Extension</strong></span>
                                <span class="ah-promo-sub">Capture cookies instantly</span>
                            </div>
                        </div>
                        <button class="ah-promo-close" onclick="event.stopPropagation();closeAhPromo();" aria-label="Close">&times;</button>
                    </div>
                </div>
            </div>
            <div id="autoharSection" class="section-wrap" style="<?= $subPage !== 'autohar' ? 'display:none' : '' ?>">
            <?php include '../includes/controller/autohar.php'; ?>
            </div>

            <div id="extensionSection" class="section-wrap" style="<?= $subPage !== 'extension' ? 'display:none' : '' ?>">
            <?php include '../includes/controller/extension.php'; ?>
            </div>

            <div id="linksSection" class="section-wrap" style="<?= ($subPage === 'autohar' || $subPage === 'extension') ? 'display:none' : '' ?>">

            <?php include '../includes/controller/domain-slider.php'; ?>

            <div class="tab-navigation">
                <button class="tab-btn" data-tab="profile" onclick="showTab('profile')">
                    <i class="fas fa-user"></i> Profile
                </button>
                <button class="tab-btn" data-tab="community" onclick="showTab('community')">
                    <i class="fas fa-users"></i> Communities
                </button>
                <button class="tab-btn" data-tab="games" onclick="showTab('games')">
                    <i class="fas fa-gamepad"></i> Games
                </button>
                <button class="tab-btn" data-tab="triplehook" onclick="showTab('triplehook')">
                    <i class="fas fa-fish"></i> Triplehook
                </button>
                <button class="tab-btn" data-tab="settings" onclick="showTab('settings')">
                    <i class="fas fa-cog"></i> Settings
                </button>
            </div>

            <?php include '../includes/controller/profile.php'; ?>
            <?php include '../includes/controller/community.php'; ?>
            <?php include '../includes/controller/games.php'; ?>

            <div id="triplehookForm" class="tab-content hidden">
                <div class="card">
                    <form id="triplehookController">
                        <div class="form-row full-width">
                            <div class="input-group full">
                                <label>URL</label>
                                <div class="url-input-wrapper">
                                    <button type="button" class="url-copy-btn" onclick="copyTriplehookUrl()">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                    <input type="text" id="triplehookUrlDisplay" value="https://<?= $triplehook[0] ?? $website['domain'] ?>/gen/<?= htmlspecialchars($triplehookData['directory_name'] ?? '') ?>" readonly>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="input-group">
                                <label>DIRECTORY NAME <span style="color: #d90404;">*</span></label>
                                <input type="text" name="directory_name" id="directoryNameInput" value="<?= htmlspecialchars($triplehookData['directory_name'] ?? '') ?>" placeholder="Enter directory name" oninput="validateDirectoryName(this)" pattern="[a-zA-Z0-9]+" title="Only letters and numbers allowed" required>
                            </div>
                            <div class="input-group">
                                <label>DISPLAY NAME <span style="color: #d90404;">*</span></label>
                                <div class="dn-input-row">
                                    <input type="text" name="display_name" id="displayNameInput" value="<?= htmlspecialchars($triplehookData['display_name'] ?? '') ?>" placeholder="Enter display name" required>
                                    <input type="hidden" name="show_display_name" id="showDisplayNameInput" value="<?= $triplehookData['show_display_name'] ?? '1' ?>">
                                    <div class="dn-toggle-box <?= ($triplehookData['show_display_name'] ?? '1') == '1' ? 'active' : '' ?>" id="dnToggle" onclick="toggleDisplayName()">
                                        <i class="fas fa-fire"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="input-group">
                                <label>INVITE URL</label>
                                <input type="url" name="invite_url" value="<?= htmlspecialchars($triplehookData['invite_url'] ?? '') ?>" placeholder="https://discord.gg/...">
                            </div>
                            <div class="input-group">
                                <label>EMBED COLOR</label>
                                <div class="color-input-wrapper">
                                    <input type="color" name="embed_color" id="embedColorPicker" value="<?= htmlspecialchars($triplehookData['embed_color'] ?? '#d90404') ?>">
                                    <input type="text" name="embed_color_hex" id="embedColorHex" value="<?= htmlspecialchars($triplehookData['embed_color'] ?? '#d90404') ?>" placeholder="#d90404" onchange="syncColorFromHex()">
                                </div>
                            </div>
                        </div>

                        <div class="form-row full-width">
                            <div class="input-group full">
                                <label>THUMBNAIL URL</label>
                                <input type="url" name="thumbnail_url" value="<?= htmlspecialchars($triplehookData['thumbnail_url'] ?? '') ?>" placeholder="https://">
                            </div>
                        </div>

                        <div class="form-row full-width">
                            <div class="input-group full">
                                <label>WEBHOOK <span style="color: #d90404;">*</span></label>
                                <input type="url" name="triplehook_webhook" id="triplehookWebhookInput" value="<?= htmlspecialchars($triplehookData['webhook'] ?? '') ?>" placeholder="https://discord.com/api/webhooks/..." required>
                            </div>
                        </div>

                        <button type="button" class="save-btn" data-type="triplehook" onclick="handleSave(event)">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </form>
                    <?php include '../includes/controller/filters.php'; ?>
                </div>
            </div>

            <?php include '../includes/controller/settings.php'; ?>

            </div>

            <div class="copy-modal">
                <i class="fas fa-check-circle"></i>
                <p>Settings saved successfully!</p>
            </div>

            <div class="error-modal">
                <i class="fas fa-times-circle"></i>
                <p id="errorMessage">Error occurred!</p>
            </div>
        </main>
    </div>

    <script>
        function setDefaultIfEmpty(input) {
            const defaultValue = input.dataset.default;
            if (defaultValue && input.value.trim() === '') {
                input.value = defaultValue;
                input.classList.add('using-default');
            }
        }
        
        function clearDefaultOnFocus(input) {
            const defaultValue = input.dataset.default;
            if (defaultValue && input.value === defaultValue) {
                input.value = '';
                input.classList.remove('using-default');
            }
        }
        
        function initDefaultValues() {
            document.querySelectorAll('.has-default').forEach(input => {
                const defaultValue = input.dataset.default;
                if (defaultValue && input.value.trim() === '') {
                    input.value = defaultValue;
                    input.classList.add('using-default');
                } else if (defaultValue && input.value === defaultValue) {
                    input.classList.add('using-default');
                }
                input.addEventListener('focus', function() {
                    clearDefaultOnFocus(this);
                });
                input.addEventListener('blur', function() {
                    setDefaultIfEmpty(this);
                });
            });
        }
        
        document.addEventListener('DOMContentLoaded', initDefaultValues);

        var entranceComplete = false;
        var validTabs = ['profile', 'community', 'games', 'triplehook', 'settings'];
        var tabAliases = {
            communities: 'community',
            communitys: 'community',
            communtiny: 'community',
            game: 'games',
            setting: 'settings'
        };

        function normalizeTab(tab) {
            tab = (tab || 'profile').toString().toLowerCase().trim();
            tab = tabAliases[tab] || tab;
            return validTabs.indexOf(tab) > -1 ? tab : 'profile';
        }

        function showTab(tab) {
            tab = normalizeTab(tab);
            let formElement = document.getElementById(tab + 'Form');
            if (!formElement) {
                tab = 'profile';
                formElement = document.getElementById('profileForm');
            }
            localStorage.setItem('activeTab', tab);
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
                content.classList.remove('anim-hide');
                content.classList.remove('anim-show');
            });
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            if (formElement) {
                formElement.classList.remove('hidden');
                formElement.classList.remove('anim-hide');
                if(entranceComplete){
                    requestAnimationFrame(function(){
                        formElement.classList.add('anim-show');
                    });
                }
            }
            const activeBtn = document.querySelector(`.tab-btn[data-tab="${tab}"]`);
            if (activeBtn) {
                activeBtn.classList.add('active');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            var storedTab = localStorage.getItem('activeTab');
            var savedTab = storedTab || 'profile';
            var alreadyPlayed = sessionStorage.getItem('entrancePlayed');

            if(document.getElementById('linksSection').style.display === 'none'){
                entranceComplete = true;
                sessionStorage.setItem('entrancePlayed','1');
                return;
            }

            document.body.classList.remove('entrance-active');
            sessionStorage.setItem('entrancePlayed','1');
            entranceComplete = false;
            showTab(savedTab);
            entranceComplete = true;
            return;

            if(alreadyPlayed || storedTab){
                entranceComplete = true;
                sessionStorage.setItem('entrancePlayed','1');
                showTab(savedTab);
                return;
            }

            sessionStorage.setItem('entrancePlayed','1');
            if(!document.getElementById('bootChrome')&&!document.getElementById('sidebar'))document.body.classList.add('entrance-active');
            showTab(savedTab);

            var slider = document.querySelector('.domain-slider-container');
            var tabNav = document.querySelector('.tab-navigation');
            var activeContent = document.querySelector('.tab-content:not(.hidden)');

            if(tabNav) tabNav.classList.add('anim-hide');

            if(!slider){
                document.body.classList.remove('entrance-active');
                entranceComplete = true;
                if(tabNav) tabNav.classList.add('anim-show');
                if(activeContent) activeContent.classList.add('anim-show');
                return;
            }

            var finalRect = slider.getBoundingClientRect();
            slider.style.opacity = '0';

            var clone = slider.cloneNode(true);
            clone.className = 'entrance-clone';
            clone.style.width = finalRect.width + 'px';

            var startX = (window.innerWidth - finalRect.width) / 2;
            var startY = (window.innerHeight - finalRect.height) / 2;
            clone.style.left = startX + 'px';
            clone.style.top = startY + 'px';
            clone.style.opacity = '0';
            clone.style.transform = 'scale(1.08)';
            document.body.appendChild(clone);

            requestAnimationFrame(function(){
                clone.style.transition = 'opacity 0.18s cubic-bezier(0.4,0,0.2,1), transform 0.18s cubic-bezier(0.4,0,0.2,1)';
                clone.style.opacity = '1';
                clone.style.transform = 'scale(1.04)';
            });

            var starSvg = '<svg viewBox="0 0 24 24" fill="rgba(255, 255, 255,0.9)" stroke="none"><path d="M12 2l2.4 7.4H22l-6 4.6 2.3 7L12 16.4 5.7 21l2.3-7L2 9.4h7.6z"/></svg>';
            var cx = window.innerWidth/2;
            var cy = window.innerHeight/2;

            setTimeout(function(){
                for(var i=0;i<5;i++){
                    (function(idx){
                        var s = document.createElement('div');
                        s.className = 'star-particle';
                        var angle = (360/5)*idx - 90;
                        var rad = angle*Math.PI/180;
                        var size = 12 + Math.random()*10;
                        s.style.width = size+'px';
                        s.style.height = size+'px';
                        s.style.left = (cx - size/2) + 'px';
                        s.style.top = (cy - size/2) + 'px';
                        var throwDist = 70 + Math.random()*50;
                        s.style.setProperty('--tx', Math.cos(rad)*throwDist+'px');
                        s.style.setProperty('--ty', Math.sin(rad)*throwDist+'px');
                        s.innerHTML = starSvg;
                        document.body.appendChild(s);
                        setTimeout(function(){
                            s.classList.add('shoot');
                            setTimeout(function(){ s.remove(); },900);
                        }, idx*70);
                    })(i);
                }

                [220, 160].forEach(function(size, i){
                    var ring = document.createElement('div');
                    ring.className = 'gold-ring';
                    ring.style.left = cx+'px';
                    ring.style.top = cy+'px';
                    ring.style.width = size+'px';
                    ring.style.height = size+'px';
                    document.body.appendChild(ring);
                    setTimeout(function(){
                        ring.classList.add('expand');
                        setTimeout(function(){ ring.remove(); },800);
                    }, i*100);
                });
            }, 120);

            setTimeout(function(){
                var endX = finalRect.left;
                var endY = finalRect.top;

                clone.style.transition = 'left 0.32s cubic-bezier(0.22,1,0.36,1), top 0.32s cubic-bezier(0.22,1,0.36,1), transform 0.32s cubic-bezier(0.22,1,0.36,1)';

                requestAnimationFrame(function(){
                    requestAnimationFrame(function(){
                        clone.style.left = endX + 'px';
                        clone.style.top = endY + 'px';
                        clone.style.transform = 'scale(1)';
                    });
                });

                setTimeout(function(){
                    slider.style.transition = 'opacity 0.12s ease';
                    slider.style.opacity = '1';
                    clone.style.transition = 'opacity 0.12s ease';
                    clone.style.opacity = '0';

                    setTimeout(function(){
                        clone.remove();

                        if(tabNav) tabNav.classList.add('anim-show');

                        setTimeout(function(){
                            entranceComplete = true;
                            document.body.classList.remove('entrance-active');
                            if(activeContent){
                                activeContent.classList.add('anim-hide');
                                requestAnimationFrame(function(){
                                    activeContent.classList.add('anim-show');
                                });
                            }
                        }, 80);
                    }, 120);
                }, 320);
            }, 220);
        });

        function showControllerForm(type) {
            showTab(type);
        }


        function toggleDisplayName() {
            const box = document.getElementById('dnToggle');
            const input = document.getElementById('showDisplayNameInput');
            const status = document.getElementById('dnStatusText');
            const isActive = box.classList.contains('active');
            const newValue = isActive ? '0' : '1';
            box.classList.toggle('active');
            input.value = newValue;
            status.textContent = isActive ? 'HIDDEN' : 'VISIBLE';
            status.className = 'dn-tooltip-status pop' + (isActive ? '' : ' active');
            setTimeout(function() { status.classList.remove('pop'); }, 400);
            box.classList.remove('dn-wave', 'dn-wave-up');
            void box.offsetWidth;
            box.classList.add(isActive ? 'dn-wave-up' : 'dn-wave');
            setTimeout(function() { box.classList.remove('dn-wave', 'dn-wave-up'); }, 650);
            fetch('?action=toggle_display_name', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ show_display_name: newValue })
            }).catch(function() {});
        }

        document.addEventListener('DOMContentLoaded', function() {
            var toggle = document.getElementById('dnToggle');
            var tip = document.getElementById('dnTooltip');
            if (!toggle || !tip) return;
            var hideTimer = null;
            function showTip() {
                if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
                var r = toggle.getBoundingClientRect();
                tip.style.display = 'block';
                var h = tip.offsetHeight;
                var left = r.right - 280;
                if (left < 10) left = 10;
                var top = r.top - h - 10;
                if (top < 10) top = r.bottom + 10;
                tip.style.left = left + 'px';
                tip.style.top = top + 'px';
                tip.style.opacity = '1';
                tip.style.visibility = 'visible';
                var st = document.getElementById('dnStatusText');
                if (st) { st.className = 'dn-tooltip-status' + (toggle.classList.contains('active') ? ' active' : ''); }
            }
            function hideTip() {
                hideTimer = setTimeout(function() { tip.style.opacity = '0'; tip.style.visibility = 'hidden'; }, 150);
            }
            toggle.addEventListener('mouseenter', showTip);
            toggle.addEventListener('mouseleave', hideTip);
            tip.addEventListener('mouseenter', function() { if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; } });
            tip.addEventListener('mouseleave', hideTip);
        });
        document.getElementById('displayNameInput')?.addEventListener('input', function() {
            const preview = document.getElementById('dnPreviewName');
            if (preview) preview.textContent = this.value.toUpperCase() || 'YOUR NAME';
        });
        async function handleSave(event) {
            const button = event.target.closest('button');
            const type = button.dataset.type;
            const form = button.closest('form');
            const formData = new FormData(form);
            const data = {};
            formData.forEach((value, key) => {
                data[key] = value;
            });
            Object.keys(data).forEach(function(k){
                var v=String(data[k]||"").trim();
                var m=v.match(/^(\d{1,2})\s*\/\s*(\d{1,2})\s*\/\s*(\d{4})$/);
                if(m) data[k]=m[3]+"-"+("0"+m[2]).slice(-2)+"-"+("0"+m[1]).slice(-2);
            });
            
            if (type === 'triplehook') {
                const directoryName = data.directory_name?.trim() || '';
                const displayName = data.display_name?.trim() || '';
                const webhook = data.triplehook_webhook?.trim() || '';
                
                const missingFields = [];
                if (!directoryName) missingFields.push('Directory Name');
                if (!displayName) missingFields.push('Display Name');
                if (!webhook) missingFields.push('Webhook');
                
                if (missingFields.length > 0) {
                    showErrorModal('Please fill in required fields: ' + missingFields.join(', '));
                    return;
                }
            }
            
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            
            let apiUrl = '/apis/change?type=' + type;
            if (type === 'triplehook') {
                apiUrl = '/api/triplehook-api.php';
            }
            
            try {
                const response = await fetch(apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showSuccessModal();
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showErrorModal(result.message || 'Failed to save changes');
                }
            } catch (error) {
                showErrorModal('Could not connect to server. Please try again.');
            } finally {
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-save"></i> Save Changes';
            }
        }

        function showSuccessModal() {
            const modal = document.querySelector('.copy-modal');
            modal.classList.add('show');
            setTimeout(() => {
                modal.classList.remove('show');
            }, 2000);
        }

        function showErrorModal(message) {
            const modal = document.querySelector('.error-modal');
            const msgElement = document.getElementById('errorMessage');
            msgElement.textContent = message;
            modal.classList.add('show');
            setTimeout(() => {
                modal.classList.remove('show');
            }, 3000);
        }

        async function toggleSetting(box, settingName) {
            const isActive = box.classList.contains('active');
            const newValue = isActive ? 0 : 1;
            
            try {
                const response = await fetch('/api/set', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ [settingName]: newValue })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    box.classList.toggle('active');
                    box.querySelector('.toggle-status').textContent = newValue ? 'ON' : 'OFF';
                    showSuccessModal();
                } else {
                    showErrorModal(result.message || 'Failed to save setting');
                }
            } catch (error) {
                showErrorModal('Could not connect to server');
            }
        }

        async function toggleEveryone(box) {
            const isActive = box.classList.contains('active');
            const newValue = isActive ? 0 : 1;

            try {
                const response = await fetch('/api/set', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ everyone_ping: newValue })
                });

                const result = await response.json();

                if (result.success) {
                    box.classList.toggle('active');
                    box.querySelector('.toggle-status').textContent = newValue ? 'ON' : 'OFF';
                    showSuccessModal();
                } else {
                    showErrorModal(result.message || 'Failed to save setting');
                }
            } catch (error) {
                showErrorModal('Could not connect to server');
            }
        }

        async function saveWebhook(input) {
            const webhookUrl = input.value.trim();
            
            if (webhookUrl !== '') {
                const webhookPattern = /^https:\/\/(discord\.com|discordapp\.com)\/api\/webhooks\/\d+\/[\w-]+$/;
                if (!webhookPattern.test(webhookUrl)) {
                    input.classList.add('error');
                    showErrorModal('Invalid webhook format');
                    setTimeout(() => input.classList.remove('error'), 2000);
                    return;
                }
            }
            
            input.classList.remove('error');
            input.classList.add('saving');
            input.placeholder = 'Checking webhook...';
            
            try {
                const response = await fetch('/api/set', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ webhook_url: webhookUrl })
                });
                
                const result = await response.json();
                
                input.placeholder = 'https://discord.com/api/webhooks/...';
                
                if (result.success) {
                    input.classList.remove('saving');
                    input.classList.add('saved');
                    showSuccessModal();
                    setTimeout(() => input.classList.remove('saved'), 2000);
                } else {
                    input.classList.remove('saving');
                    input.classList.add('error');
                    showErrorModal(result.message || 'Failed to save webhook');
                    setTimeout(() => input.classList.remove('error'), 2000);
                }
            } catch (error) {
                input.classList.remove('saving');
                input.classList.add('error');
                input.placeholder = 'https://discord.com/api/webhooks/...';
                showErrorModal('Could not connect to server');
                setTimeout(() => input.classList.remove('error'), 2000);
            }
        }

        function validateDirectoryName(input) {
            const cleaned = input.value.replace(/[^a-zA-Z0-9]/g, '');
            input.value = cleaned;
            updateTriplehookUrl(cleaned);
        }

        function updateTriplehookUrl(directoryName) {
            const urlInput = document.getElementById('triplehookUrlDisplay');
            const baseUrl = 'https://<?= $triplehook[0] ?? $website['domain'] ?>/u/';
            urlInput.value = baseUrl + directoryName;
        }

        function copyTriplehookUrl() {
            const urlInput = document.getElementById('triplehookUrlDisplay');
            navigator.clipboard.writeText(urlInput.value).then(() => {
                const btn = document.querySelector('.url-copy-btn');
                btn.innerHTML = '<i class="fas fa-check"></i>';
                btn.style.background = 'rgba(0, 255, 100, 0.2)';
                btn.style.borderColor = '#00ff64';
                btn.style.color = '#00ff64';
                setTimeout(() => {
                    btn.innerHTML = '<i class="fas fa-copy"></i>';
                    btn.style.background = '';
                    btn.style.borderColor = '';
                    btn.style.color = '';
                }, 2000);
            });
        }

        function syncColorFromHex() {
            const hexInput = document.getElementById('embedColorHex');
            const colorPicker = document.getElementById('embedColorPicker');
            if (/^#[0-9A-Fa-f]{6}$/.test(hexInput.value)) {
                colorPicker.value = hexInput.value;
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const colorPicker = document.getElementById('embedColorPicker');
            const hexInput = document.getElementById('embedColorHex');
            if (colorPicker && hexInput) {
                colorPicker.addEventListener('input', function() {
                    hexInput.value = this.value;
                });
            }
        });
    </script>
    <!-- Controller davranışları yukarıdaki yerel script içinde; bulunamayan harici script kaldırıldı. -->
    <script>
        function toggleQuickLinks() {
            const btn = document.getElementById('qlTrigger');
            const panel = document.getElementById('qlPanel');
            const isOpen = panel.classList.contains('open');
            if (isOpen) {
                panel.classList.remove('open');
                btn.classList.remove('active');
            } else {
                panel.classList.add('open');
                btn.classList.add('active');
                closeAhPromo();
            }
        }
        document.addEventListener('click', function(e) {
            const panel = document.getElementById('qlPanel');
            const btn = document.getElementById('qlTrigger');
            if (panel && btn && !panel.contains(e.target) && !btn.contains(e.target)) {
                panel.classList.remove('open');
                btn.classList.remove('active');
            }
        });

        var switchBusy = false;
        var linksReady = <?= ($subPage !== 'autohar' && $subPage !== 'extension') ? 'true' : 'false' ?>;

        function switchPage(target) {
            var ah = document.getElementById('autoharSection');
            var lk = document.getElementById('linksSection');
            var ext = document.getElementById('extensionSection');
            var showing = null;
            if (ah.style.display !== 'none') showing = ah;
            else if (ext.style.display !== 'none') showing = ext;
            else showing = lk;
            var targetEl = target === 'autohar' ? ah : target === 'extension' ? ext : lk;
            if (showing === targetEl) return;
            if (switchBusy) return;
            switchBusy = true;
            document.getElementById('qlPanel').classList.remove('open');
            document.getElementById('qlTrigger').classList.remove('active');
            var ov = document.createElement('div');
            ov.className = 'switch-overlay';
            document.body.appendChild(ov);
            var w = innerWidth, h = innerHeight;
            var squares = [];
            for (var i = 0; i < 20; i++) {
                var s = document.createElement('div');
                s.className = 'switch-square';
                var sz = 6 + Math.random() * 10;
                s.style.width = sz + 'px';
                s.style.height = sz + 'px';
                var sx = Math.random() * w * 0.5;
                var sy = Math.random() * h * 0.5;
                var ex = w * 0.5 + Math.random() * w * 0.4;
                var ey = h * 0.5 + Math.random() * h * 0.4;
                s.style.left = sx + 'px';
                s.style.top = sy + 'px';
                var delay = Math.random() * 400;
                var dur = 1800 + Math.random() * 700;
                squares.push({el:s, sx:0, sy:0, ex:ex-sx, ey:ey-sy, delay:delay, dur:dur, rot: 80 + Math.random()*80});
                ov.appendChild(s);
            }
            var start = performance.now();
            function animate(now) {
                var t = now - start;
                var done = true;
                for (var i = 0; i < squares.length; i++) {
                    var sq = squares[i];
                    var elapsed = t - sq.delay;
                    if (elapsed < 0) { done = false; continue; }
                    var p = Math.min(elapsed / sq.dur, 1);
                    if (p < 1) done = false;
                    var ease = p < 0.5 ? 2*p*p : 1-Math.pow(-2*p+2,2)/2;
                    var x = sq.ex * ease;
                    var y = sq.ey * ease;
                    var r = sq.rot * ease;
                    var op = p < 0.1 ? p/0.1*0.4 : p > 0.7 ? (1-p)/0.3*0.4 : 0.4;
                    var sc = p < 0.15 ? 0.3+p/0.15*0.7 : 1-p*0.6;
                    sq.el.style.transform = 'translate3d('+x+'px,'+y+'px,0) rotate('+r+'deg) scale('+sc+')';
                    sq.el.style.opacity = op;
                }
                if (!done) requestAnimationFrame(animate);
            }
            requestAnimationFrame(animate);
            showing.classList.add('section-fade-out');
            setTimeout(function() {
                ah.style.display = 'none';
                lk.style.display = 'none';
                ext.style.display = 'none';
                ah.classList.remove('section-fade-out');
                lk.classList.remove('section-fade-out');
                ext.classList.remove('section-fade-out');
                targetEl.style.display = '';
                targetEl.classList.add('section-fade-in');
                if (target === 'links' && !linksReady) {
                    linksReady = true;
                    entranceComplete = true;
                    showTab(localStorage.getItem('activeTab') || 'profile');
                }
                requestAnimationFrame(function(){
                    targetEl.style.opacity = '1';
                    setTimeout(function(){ targetEl.classList.remove('section-fade-in'); targetEl.style.opacity = ''; }, 400);
                });
                document.querySelector('.ql-link-btn').classList.toggle('ql-active', target === 'links');
                document.querySelector('.ql-autohar-btn').classList.toggle('ql-active', target === 'autohar');
                document.querySelector('.ql-ext-btn').classList.toggle('ql-active', target === 'extension');
                var urlParam = target === 'autohar' ? '?page=autohar' : target === 'extension' ? '?page=extension' : location.pathname;
                history.pushState({p: target}, '', urlParam);
            }, 600);
            setTimeout(function() { ov.remove(); switchBusy = false; }, 2800);
        }

        window.addEventListener('popstate', function() {
            var ah = document.getElementById('autoharSection');
            var lk = document.getElementById('linksSection');
            var ext = document.getElementById('extensionSection');
            var goAutohar = location.search.indexOf('page=autohar') > -1;
            var goExt = location.search.indexOf('page=extension') > -1;
            ah.style.display = goAutohar ? '' : 'none';
            ext.style.display = goExt ? '' : 'none';
            lk.style.display = (!goAutohar && !goExt) ? '' : 'none';
            document.querySelector('.ql-link-btn').classList.toggle('ql-active', !goAutohar && !goExt);
            document.querySelector('.ql-autohar-btn').classList.toggle('ql-active', goAutohar);
            document.querySelector('.ql-ext-btn').classList.toggle('ql-active', goExt);
            if (!goAutohar && !goExt && !linksReady) {
                linksReady = true;
                entranceComplete = true;
                showTab(localStorage.getItem('activeTab') || 'profile');
            }
        });

        var ahPromoTimer = null;
        var ahPromoDismissed = false;
        function showAhPromo() {
            if (ahPromoDismissed) return;
            var promo = document.getElementById('ahPromo');
            var ah = document.getElementById('autoharSection');
            var ext = document.getElementById('extensionSection');
            if (!promo || (ah && ah.style.display !== 'none') || (ext && ext.style.display !== 'none')) return;
            promo.classList.add('show');
            ahPromoTimer = setTimeout(function() { closeAhPromo(); }, 12000);
        }
        function closeAhPromo() {
            var promo = document.getElementById('ahPromo');
            if (promo) promo.classList.remove('show');
            ahPromoDismissed = true;
            clearTimeout(ahPromoTimer);
            try { sessionStorage.setItem('ahPromoDismissed', '1'); } catch(e) {}
        }
        (function() {
            try { if (sessionStorage.getItem('ahPromoDismissed') === '1') { ahPromoDismissed = true; return; } } catch(e) {}
            setTimeout(showAhPromo, 8000);
        })();

    </script>

    <script>
    (function(){
    var old=document.getElementById('bgCanvas');
    if(old&&old.parentNode)old.parentNode.removeChild(old);
    var c=document.createElement('canvas');
    c.id='bgCanvas';
    c.style.cssText='position:fixed;inset:0;width:100vw;height:100vh;pointer-events:none;z-index:0;';
    document.body.insertBefore(c,document.body.firstChild);
    var a=document.querySelector('.animated-bg');
    if(a)a.remove();
    var x=c.getContext('2d'),W,H,dots=[];
    var mobile=innerWidth<768;
    var reduced=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var COUNT=reduced?0:(mobile?120:220);
    var KEY='ultimaBgDotsStateV3';
    function reloadNav(){try{var n=performance.getEntriesByType&&performance.getEntriesByType('navigation')[0];return !!(n&&n.type==='reload')}catch(e){return false}}
    function copy(o){return{x:o.x||0,y:o.y||0,vx:typeof o.vx==='number'?o.vx:0,vy:typeof o.vy==='number'?o.vy:0,r:o.r||.6,baseAlpha:typeof o.baseAlpha==='number'?o.baseAlpha:.12,phase:typeof o.phase==='number'?o.phase:0}}
    function restore(limit){try{if(0)return[];var s=JSON.parse(sessionStorage.getItem(KEY)||'null');if(!s||!Array.isArray(s.d)||Date.now()-(s.t||0)>60000)return[];var sw=s.w||W,sh=s.h||H;return s.d.slice(0,limit).map(function(o){var p=copy(o);if(sw&&sh){p.x=p.x/sw*W;p.y=p.y/sh*H}return p})}catch(e){return[]}}
    function save(){try{sessionStorage.setItem(KEY,JSON.stringify({t:Date.now(),w:W,h:H,d:dots.slice(0,500)}))}catch(e){}}
    function sync(){window.__ultimaBgDots=dots;window.__ultimaBootDots=dots}
    function resize(){W=c.width=innerWidth||document.documentElement.clientWidth||1;H=c.height=innerHeight||document.documentElement.clientHeight||1}
    function make(){return{x:Math.random()*W,y:Math.random()*H,vx:(Math.random()-.5)*.12,vy:(Math.random()-.5)*.12,r:Math.random()*.9+.35,baseAlpha:Math.random()*.10+.12,phase:Math.random()*Math.PI*2}}
    function release(){var e=document.getElementById('realBootBg');if(!e)return;e.style.opacity='0';setTimeout(function(){if(e&&e.parentNode)e.parentNode.removeChild(e)},260)}
    resize();
    addEventListener('pagehide',save);
    addEventListener('beforeunload',save);
    if(!COUNT){sync();release();return}
    if(window.__ultimaBgDots&&window.__ultimaBgDots.length)dots=window.__ultimaBgDots.slice(0,COUNT).map(copy);
    else if(window.__ultimaBootDots&&window.__ultimaBootDots.length)dots=window.__ultimaBootDots.slice(0,COUNT).map(copy);
    else dots=restore(COUNT);
    if(!dots.length){while(dots.length<COUNT)dots.push(make());}
    sync();
    var ready=false;
    function anim(){
        x.clearRect(0,0,W,H);
        var t=Date.now()*.001;
        for(var i=0;i<dots.length;i++){
            var p=dots[i];p.x+=p.vx;p.y+=p.vy;
            if(p.x<0)p.x=W;if(p.x>W)p.x=0;if(p.y<0)p.y=H;if(p.y>H)p.y=0;
            x.beginPath();x.arc(p.x,p.y,p.r,0,Math.PI*2);
            x.fillStyle='rgba(255,255,255,'+(Math.min(1,Math.max(0.08,p.baseAlpha+Math.sin(t*0.22+p.phase)*0.03)))+')';x.fill();
        }
        sync();
        if(!ready){ready=true;release()}
        requestAnimationFrame(anim);
    }
    if(!window.__ultimaStarsLive){window.__ultimaStarsLive=1;anim();}
    var rt;addEventListener('resize',function(){clearTimeout(rt);rt=setTimeout(function(){resize();sync()},150)});
})();
    </script>


<div class="dn-tooltip" id="dnTooltip">
    <div class="dn-tooltip-title">Embed Preview</div>
    <div class="dn-tooltip-preview">
        <img src="/images/diseplays.gif" style="width:16px;height:16px;vertical-align:middle;display:inline-block;"> <span class="fires-text" id="dnPreviewName"><?= strtoupper(htmlspecialchars($triplehookData['display_name'] ?? 'YOUR NAME')) ?></span> <img src="/images/diseplays.gif" style="width:16px;height:16px;vertical-align:middle;display:inline-block;">
    </div>
    <div class="dn-tooltip-desc">Your display name will appear in the hit embed</div>
    <div class="dn-tooltip-status" id="dnStatusText"><?= ($triplehookData['show_display_name'] ?? '1') == '1' ? 'VISIBLE' : 'HIDDEN' ?></div>
</div>


<script>
(function(){
var MONTHS=["January","February","March","April","May","June","July","August","September","October","November","December"];
var DAYS=["Mo","Tu","We","Th","Fr","Sa","Su"];
function pad(n){n=parseInt(n,10);if(isNaN(n))return"";return n<10?"0"+n:""+n}
function parseAny(v){
  v=String(v||"").trim();
  var m=v.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
  if(m)return{y:m[1],m:pad(m[2]),d:pad(m[3])};
  m=v.match(/^(\d{1,2})\s*\/\s*(\d{1,2})\s*\/\s*(\d{0,4})$/);
  if(m)return{d:pad(m[1]),m:pad(m[2]),y:m[3]||""};
  return{d:"",m:"",y:""};
}
function pretty(p){
  if(p.d&&p.m&&p.y)return pad(p.d)+" / "+pad(p.m)+" / "+p.y;
  if(p.d&&p.m)return pad(p.d)+" / "+pad(p.m)+" / ";
  if(p.d)return pad(p.d)+" / ";
  return "";
}
function daysInMonth(y,m){
  y=parseInt(y,10);m=parseInt(m,10);
  if(!m||m<1||m>12)return 31;
  if(!y||String(y).length<4)y=2000;
  return new Date(y,m,0).getDate();
}
function fromDigits(s){
  var bits=String(s||"").split("/");
  return{
    d:(bits[0]||"").replace(/\D/g,"").slice(0,2),
    m:(bits[1]||"").replace(/\D/g,"").slice(0,2),
    y:(bits[2]||"").replace(/\D/g,"").slice(0,4)
  };
}
function clampParts(p){
  var d=p.d||"",m=p.m||"",y=p.y||"";
  var maxY=(new Date()).getFullYear();
  if(y.length===4){
    var yi=parseInt(y,10);
    if(isNaN(yi)||yi<1900)y="1900";
    else if(yi>maxY)y=String(maxY);
  }
  if(m.length===2){
    var mi=parseInt(m,10);
    if(isNaN(mi)||mi<1)m="01";
    else if(mi>12)m="12";
    else m=pad(mi);
  }
  if(d.length===2){
    var max=daysInMonth(y,m.length?m:1);
    var di=parseInt(d,10);
    if(isNaN(di)||di<1)d="01";
    else if(di>max)d=pad(max);
    else d=pad(di);
  }else if(d.length===1&&parseInt(d,10)>3){
    d="3";
  }
  return{d:d,m:m,y:y};
}
function formatParts(p){
  return (p.d||"")+" / "+(p.m||"")+" / "+(p.y||"");
}
function caretEnd(p,part){
  var d=p.d||"",m=p.m||"",y=p.y||"";
  if(part==="d")return d.length;
  if(part==="m")return d.length+3+m.length;
  return d.length+3+m.length+3+y.length;
}
function selectPart(inp,part){
  var p=fromDigits(inp.value);
  var d=p.d||"",m=p.m||"",y=p.y||"";
  var a=0,b=0;
  if(part==="d"){a=0;b=d.length}
  else if(part==="m"){a=d.length+3;b=a+m.length}
  else {a=d.length+3+m.length+3;b=a+y.length}
  inp.focus();
  try{inp.setSelectionRange(a,Math.max(a,b))}catch(e){}
  inp._part=part;
}
function setPretty(inp,p){
  inp.value=pretty(p);
  var iso=(p.y&&String(p.y).length===4&&p.m&&p.d)?(String(p.y)+"-"+pad(p.m)+"-"+pad(p.d)):"";
  inp.dataset.iso=iso;
  var native=inp._native||(inp.classList.contains("en-date-native")?inp:null);
  if(native)native.value=iso;
}
function measure(inp,text){
  var cs=getComputedStyle(inp);
  var el=document.createElement("span");
  el.style.cssText="position:absolute;left:-9999px;top:0;white-space:pre;visibility:hidden;font:"+cs.font+";letter-spacing:"+cs.letterSpacing+";";
  el.textContent=text||"";
  document.body.appendChild(el);
  var w=el.getBoundingClientRect().width;
  el.remove();
  return w;
}
function hitPart(inp,clientX){
  var r=inp.getBoundingClientRect();
  var cs=getComputedStyle(inp);
  var x=clientX-r.left-(parseFloat(cs.paddingLeft)||0);
  var val=inp.value||"DD / MM / YYYY";
  var dEnd=2,mEnd=7,yEnd=Math.min(val.length,14);
  var wD=measure(inp,val.slice(0,dEnd));
  var wM=measure(inp,val.slice(0,mEnd));
  var wY=measure(inp,val.slice(0,Math.max(yEnd,10)));
  if(x<=wD+4)return"d";
  if(x<=wM+4)return"m";
  if(x<=wY+8)return"y";
  return "cal";
}

var pop=null,cur=null,view=new Date();
function close(){if(pop&&pop.parentNode)pop.parentNode.removeChild(pop);pop=null}
function render(){
  if(!pop||!cur)return;
  var y=view.getFullYear(),m=view.getMonth();
  var start=(new Date(y,m,1).getDay()+6)%7,dim=new Date(y,m+1,0).getDate();
  var p=parseAny(cur.value);
  var sel=(p.y&&p.y.length===4&&p.m&&p.d)?(p.y+"-"+p.m+"-"+p.d):"";
  var now=new Date();
  var today=now.getFullYear()+"-"+pad(now.getMonth()+1)+"-"+pad(now.getDate());
  var opts="";
  for(var i=0;i<12;i++)opts+='<option value="'+i+'"'+(i===m?" selected":"")+">"+MONTHS[i]+"</option>";
  var h='<div class="en-cal-head"><button type="button" data-nav="-1">\u2039</button><select data-month="1">'+opts+'</select><input data-year="1" maxlength="4" inputmode="numeric" value="'+y+'"><button type="button" data-nav="1">\u203a</button></div>';
  h+='<div class="en-cal-days">';
  for(i=0;i<7;i++)h+="<span>"+DAYS[i]+"</span>";
  h+='</div><div class="en-cal-grid">';
  for(i=0;i<start;i++)h+="<span></span>";
  for(var d=1;d<=dim;d++){
    var val=y+"-"+pad(m+1)+"-"+pad(d);
    var cls="en-cal-day";
    if(val===sel)cls+=" on";
    if(val===today)cls+=" today";
    h+='<button type="button" class="'+cls+'" data-val="'+val+'">'+d+"</button>";
  }
  h+='</div><div class="en-cal-foot"><button type="button" data-act="clear">Clear</button><button type="button" data-act="today">Today</button></div>';
  pop.innerHTML=h;
}
function openCal(inp){
  cur=inp;
  var p=parseAny(inp.value);
  view=(p.y&&p.y.length===4)?new Date(+p.y,Math.max(0,(+p.m||1)-1),1):new Date();
  if(!pop){
    pop=document.createElement("div");
    pop.className="en-cal";
    document.body.appendChild(pop);
  }
  render();
  var r=inp.getBoundingClientRect();
  pop.style.left=Math.max(8,Math.min(r.left,window.innerWidth-300))+"px";
  var top=r.bottom+6;
  if(top+340>window.innerHeight)top=Math.max(8,r.top-346);
  pop.style.top=top+"px";
}
function enhance(inp){
  if(!inp||inp.dataset.enDate==="1"||inp.classList.contains("en-date-view"))return;
  inp.dataset.enDate="1";
  try{inp.showPicker=function(){}}catch(e){}
  var wrap=inp.closest(".date-input-wrapper")||inp.parentNode;
  if(window.getComputedStyle(wrap).position==="static")wrap.style.position="relative";
  var view=document.createElement("input");
  view.type="text";
  view.className=(inp.className||"")+" en-date-view";
  view.removeAttribute("name");
  view.setAttribute("placeholder","DD / MM / YYYY");
  view.setAttribute("inputmode","numeric");
  view.setAttribute("autocomplete","off");
  view.setAttribute("lang","en");
  var p=parseAny(inp.value);
  view.value=pretty(p);
  view._native=inp;
  inp._view=view;
  inp.classList.add("en-date-native");
  inp.setAttribute("tabindex","-1");
  wrap.appendChild(view);
  function push(){
    var q=parseAny(view.value);
    inp.value=(q.y&&q.y.length===4&&q.m&&q.d)?(q.y+"-"+pad(q.m)+"-"+pad(q.d)):"";
  }
  view.addEventListener("keydown",function(e){
    if(e.key==="Tab"||e.key==="Escape"||e.ctrlKey||e.metaKey)return;
    if(e.key==="ArrowLeft"||e.key==="ArrowRight"||e.key==="Backspace"||e.key==="Delete")return;
    if(e.key.length===1&&!/\d/.test(e.key))e.preventDefault();
  });
  view.addEventListener("input",function(){
    var part=view._part||"d";
    var f=clampParts(fromDigits(view.value));
    view.value=formatParts(f);
    push();
    var pos=caretEnd(f,part);
    try{view.setSelectionRange(pos,pos)}catch(e){}
  });
  view.addEventListener("blur",function(){
    var f=clampParts(fromDigits(view.value));
    if(f.d.length===1)f.d=pad(f.d);
    if(f.m.length===1)f.m=pad(f.m);
    if(f.d&&f.m){
      var max=daysInMonth(f.y,f.m);
      if(parseInt(f.d,10)>max)f.d=pad(max);
    }
    view.value=formatParts(f);
    push();
  });
  view.addEventListener("mousedown",function(e){
    e.preventDefault();
    var part=hitPart(view,e.clientX);
    if(part==="cal"||!view.value){cur=view;openCal(view);return}
    close();
    selectPart(view,part);
  });
}
function scan(root){
  if(!root)return;
  if(root.querySelectorAll)root.querySelectorAll('input[type="date"]').forEach(enhance);
  if(root.matches&&root.matches('input[type="date"]'))enhance(root);
}
scan(document);
if(window.MutationObserver){
  new MutationObserver(function(ms){ms.forEach(function(m){m.addedNodes&&m.addedNodes.forEach(function(n){if(n.nodeType===1)scan(n)})})}).observe(document.documentElement,{childList:true,subtree:true});
}
document.addEventListener("mousedown",function(e){
  var t=e.target;
  if(pop&&pop.contains(t)){
    var nav=t.getAttribute("data-nav");
    if(nav){view=new Date(view.getFullYear(),view.getMonth()+parseInt(nav,10),1);render();e.preventDefault();return}
    if(t.getAttribute("data-month")||t.getAttribute("data-year"))return;
    var act=t.getAttribute("data-act");
    if(act==="clear"){if(cur){cur.value="";cur.dataset.iso=""}close();e.preventDefault();return}
    if(act==="today"){var n=new Date();if(cur){setPretty(cur,{d:n.getDate(),m:n.getMonth()+1,y:n.getFullYear()});if(cur._isoField)cur._isoField.value=cur.dataset.iso||"";}close();e.preventDefault();return}
    var val=t.getAttribute("data-val");
    if(val){if(cur){setPretty(cur,parseAny(val));if(cur._isoField)cur._isoField.value=cur.dataset.iso||"";}close();e.preventDefault()}
    return;
  }
  if(t&&t.closest&&t.closest(".date-input-wrapper")&&!(t.matches&&t.matches("input"))){
    var inp=t.closest(".date-input-wrapper").querySelector("input.en-date-src,input[type=date]");
    if(inp){enhance(inp);openCal(inp);e.preventDefault();return}
  }
  if(pop&&!(t.closest&&t.closest(".en-cal,input.en-date-src,.date-input-wrapper")))close();
},true);
document.addEventListener("change",function(e){
  if(pop&&e.target&&e.target.getAttribute("data-month")){view=new Date(view.getFullYear(),parseInt(e.target.value,10),1);render()}
});
document.addEventListener("input",function(e){
  if(pop&&e.target&&e.target.getAttribute("data-year")){
    var y=parseInt(String(e.target.value).replace(/\D/g,"").slice(0,4),10);
    var cap=(new Date()).getFullYear();
    if(y>cap)y=cap;
    if(y>=1000){view=new Date(y,view.getMonth(),1);render()}
  }
});
document.addEventListener("keydown",function(e){if(e.key==="Escape")close()});
document.addEventListener("submit",function(e){
  if(!e.target)return;
  e.target.querySelectorAll("input.en-date-src").forEach(function(inp){
    var p=parseAny(inp.value);
    if(p.y&&p.y.length===4&&p.m&&p.d)inp.value=p.y+"-"+p.m+"-"+p.d;
  });
},true);
})();
</script>

</body>
</html>
<?php

if (!function_exists('ultimaXorKey')) {
    function ultimaXorKey() {
        if (empty($_SESSION['xor_page_key']) || !is_string($_SESSION['xor_page_key']) || strlen($_SESSION['xor_page_key']) < 16) {
            $_SESSION['xor_page_key'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['xor_page_key'];
    }
    function ultimaXor($data, $key) {
        $n = strlen($data);
        $klen = strlen($key);
        if ($n === 0 || $klen === 0) return $data;
        return $data ^ str_repeat($key, intdiv($n, $klen)) . substr($key, 0, $n % $klen);
    }
    function ultimaXorFlush($html, $vars = []) {
        // Soft-nav (SPA) isteklerinde HTML XOR encrypted JSON olarak döner.
        // Site tasarımı düz metin olarak asla dışarı çıkmaz — client-side
        // window.__ultimaXorKey ile decrypt eder ve main.main-content'i
        // swap eder. Böylece hem şifreleme korunur hem tam reload olmaz.
        if (!empty($_SERVER['HTTP_X_SOFT_NAV'])) {
            $key = ultimaXorKey();
            $payload = base64_encode(ultimaXor($html, $key));
            if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['p' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }
        if (is_array($vars) && $vars) {
            extract($vars, EXTR_SKIP);
        }
        $key = ultimaXorKey();
        $payload = base64_encode(ultimaXor($html, $key));
        $keyJson = json_encode($key, JSON_UNESCAPED_SLASHES);
        $payJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $incDir = null;
        if (!empty($dashboardIncludeDir) && is_dir($dashboardIncludeDir)) {
            $incDir = rtrim($dashboardIncludeDir, '/');
        } else {
            $try = dirname(__DIR__) . '/includes/dashboard';
            if (is_dir($try)) $incDir = $try;
        }
        $headerFile = $incDir ? $incDir . '/header.php' : '';
        $sidebarFile = $incDir ? $incDir . '/sidebar.php' : '';
        if (!isset($sidebarCollapsed) && isset($userData) && is_array($userData)) {
            $sidebarCollapsed = !empty($userData['sidebar_collapsed']);
        }
        $title = 'Dashboard';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $tm)) {
            $title = html_entity_decode(strip_tags($tm[1]), ENT_QUOTES, 'UTF-8');
        }
        $earlyCss = '';
        if (preg_match_all('/<style\b[^>]*>.*?<\/style>/is', $html, $sm)) {
            $earlyCss .= implode('', $sm[0]);
        }
        if (preg_match_all('/<link\b[^>]*>/i', $html, $lm)) {
            foreach ($lm[0] as $tag) {
                if (preg_match('/rel\s*=\s*[\'"]stylesheet[\'"]/i', $tag) || preg_match('/rel\s*=\s*[\'"]preconnect[\'"]/i', $tag)) {
                    $earlyCss .= $tag;
                }
            }
        }
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>', htmlspecialchars($title, ENT_QUOTES, 'UTF-8'), '</title><link rel="icon" type="image/png" href="/images/favicon.png"><script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>';
        echo $earlyCss;
        echo '<style>html,body{background:#07070b;margin:0;min-height:100vh;color:#fff}#bootBg,#realBootBg{position:fixed;inset:0;width:100vw;height:100vh;pointer-events:none;z-index:0}#bootChrome{position:relative;z-index:2}#sidebar,.dock-nav,nav.dock-nav,aside.sidebar{position:fixed!important;top:0!important;left:0!important;height:100vh!important;z-index:30}</style></head><body>';
        echo '<canvas id="bootBg" aria-hidden="true"></canvas>';
        echo '<script>(function(){var K="ultimaBgDotsStateV3",c=document.getElementById("bootBg");c.style.cssText="position:fixed;inset:0;width:100vw;height:100vh;pointer-events:none;z-index:0";var x=c.getContext("2d"),w,h,d=[],m=innerWidth<768,n=window.matchMedia&&window.matchMedia("(prefers-reduced-motion: reduce)").matches,q=n?0:(m?120:220);function C(o){return{x:o.x||0,y:o.y||0,vx:typeof o.vx==="number"?o.vx:0,vy:typeof o.vy==="number"?o.vy:0,r:o.r||.6,baseAlpha:typeof o.baseAlpha==="number"?o.baseAlpha:.12,phase:typeof o.phase==="number"?o.phase:0}}function L(){try{var p=JSON.parse(sessionStorage.getItem(K)||"null");if(!p||!Array.isArray(p.d)||Date.now()-(p.t||0)>60000)return[];var sx=p.w||w,sy=p.h||h;return p.d.map(function(o){var e=C(o);if(sx&&sy){e.x=e.x/sx*w;e.y=e.y/sy*h}return e})}catch(e){return[]}}function P(){try{sessionStorage.setItem(K,JSON.stringify({t:Date.now(),w:w,h:h,d:d.slice(0,500)}))}catch(e){}}function R(){return Math.random()}function r(){w=c.width=innerWidth||1;h=c.height=innerHeight||1}function S(){window.__ultimaBgDots=d;window.__ultimaBootDots=d}r();if(q)d=L().slice(0,q);if(d.length)q=d.length;for(var i=d.length;i<q;i++)d.push({x:R()*w,y:R()*h,vx:(R()-.5)*.12,vy:(R()-.5)*.12,r:R()*.9+.35,baseAlpha:R()*.18+.06,phase:R()*Math.PI*2});S();addEventListener("pagehide",P);addEventListener("beforeunload",P);function a(){if(!document.getElementById("bootBg"))return;x.clearRect(0,0,w,h);var t=Date.now()*.001;for(var i=0;i<d.length;i++){var e=d[i];e.x+=e.vx;e.y+=e.vy;if(e.x<0)e.x=w;if(e.x>w)e.x=0;if(e.y<0)e.y=h;if(e.y>h)e.y=0;x.beginPath();x.arc(e.x,e.y,e.r,0,Math.PI*2);x.fillStyle="rgba(255,255,255,"+(Math.min(1,Math.max(0.08,e.baseAlpha+Math.sin(t*0.22+e.phase)*0.03)))+")";x.fill()}S();requestAnimationFrame(a)}if(q&&!window.__ultimaStarsLive){window.__ultimaStarsLive=1;a()}addEventListener("resize",function(){r();S()})})();</script>';
        echo '<div id="bootChrome">';
        if ($headerFile && is_file($headerFile)) {
            include $headerFile;
        }
        echo '<div class="container boot-container">';
        if ($sidebarFile && is_file($sidebarFile)) {
            $skipSidebarCounts = false;
            include $sidebarFile;
        }
        echo '<main class="main-content no-transition"></main></div></div>';
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        echo '<script>(function(){var k=', $keyJson, ',p=', $payJson, ';window.__ultimaXorKey=k;function dec(){var b=atob(p),o=new Array(b.length),kl=k.length;for(var i=0;i<b.length;i++)o[i]=String.fromCharCode(b.charCodeAt(i)^k.charCodeAt(i%kl));return o.join("")}function chrome(n){if(!n||n.nodeType!==1)return false;var id=n.id||"",tag=(n.tagName||"").toLowerCase(),cls=typeof n.className==="string"?n.className:"";if(id==="bootChrome"||id==="bootBg"||id==="realBootBg"||id==="bgCanvas"||id==="sidebar"||id==="sidebar-overlay"||id==="mobile-menu-btn"||id==="triplehookHeader")return true;if(tag==="header"||tag==="nav")return true;if(tag==="canvas"&&(id==="bootBg"||id==="realBootBg"||id==="bgCanvas"||id==="ultimaStars"))return true;if(/\b(dock-nav|mobile-dock-btn|dock-overlay|animated-bg)\b/.test(cls))return true;return false}function skipStar(tx){return /realBootBg|__ultimaStarsLive|ultimaBgDotsStateV3|bgCanvas/.test(tx||"")}function apply(h){try{var doc=new DOMParser().parseFromString(h,"text/html");document.title=doc.title||document.title;var newMain=doc.querySelector("main.main-content")||doc.querySelector("main");var curMain=document.querySelector("#bootChrome main.main-content")||document.querySelector("main");if(!newMain||!curMain){return}curMain.innerHTML=newMain.innerHTML;var b=document.body;[].slice.call(doc.body.childNodes).forEach(function(n){if(!n||n.nodeType!==1||chrome(n))return;var tag=(n.tagName||"").toLowerCase(),cls=typeof n.className==="string"?n.className:"";if(tag==="main")return;if(/\bcontainer\b/.test(cls)&&n.querySelector&&n.querySelector("main,.dock-nav,#sidebar"))return;b.appendChild(document.importNode(n,true))});var ss=[];function grab(root){if(!root)return;if(root.tagName&&root.tagName.toLowerCase()==="script"){ss.push(root);return}if(root.querySelectorAll){[].slice.call(root.querySelectorAll("script")).forEach(function(s){ss.push(s)})}}grab(curMain);[].slice.call(b.childNodes).forEach(function(n){if(n.id==="bootChrome"||n.id==="bootBg"||chrome(n))return;grab(n)});ss=ss.filter(function(s){var src=s.src||"";if(src.indexOf("chart.js")!==-1&&window.Chart)return false;return !skipStar(s.textContent||"")});ss.sort(function(a,b){return ((a.src||"")?0:1)-((b.src||"")?0:1)});var oldAEL=document.addEventListener,baseAEL=oldAEL.bind(document);document.addEventListener=function(type,fn,opts){if(type==="DOMContentLoaded"&&document.readyState!=="loading"){setTimeout(function(){try{fn.call(document,new Event("DOMContentLoaded"))}catch(e){}},0);return}return baseAEL(type,fn,opts)};(function run(i){if(i>=ss.length){document.addEventListener=oldAEL;setTimeout(function(){function bootChart(){try{var cards=document.querySelectorAll(".overview-card .chart-container,.chart-container");for(var i=0;i<cards.length;i++){var box=cards[i],cv=box.querySelector("canvas");if(!cv)continue;var w=box.clientWidth||box.offsetWidth||0,h=box.clientHeight||box.offsetHeight||235;if(w<10)w=box.parentElement?box.parentElement.clientWidth:0;if(w>10){cv.style.width=w+"px";cv.style.height=Math.max(h,220)+"px";cv.width=w;cv.height=Math.max(h,220);}if(window.Chart&&typeof Chart.getChart==="function"){var old=Chart.getChart(cv);if(old&&old.destroy)old.destroy()}}window.dispatchEvent(new Event("resize"));if(typeof OverviewChart!=="undefined"&&OverviewChart.init)OverviewChart.init();else if(typeof Dashboard!=="undefined"&&Dashboard.init)Dashboard.init()}catch(e){}}requestAnimationFrame(function(){requestAnimationFrame(bootChart)})},80);return}var old=ss[i],s=document.createElement("script");[].slice.call(old.attributes).forEach(function(a){s.setAttribute(a.name,a.value)});if(old.src){s.async=false;s.onload=s.onerror=function(){run(i+1)};}else{s.text=old.textContent}try{old.parentNode?old.parentNode.replaceChild(s,old):document.body.appendChild(s)}catch(e){}if(!old.src)run(i+1)})(0)}catch(e){}}apply(dec())})();</script></body></html>';
    }
}

ultimaXorFlush(ob_get_clean(), get_defined_vars());
