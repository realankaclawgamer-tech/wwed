<?php
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

header('Content-Type: application/json; charset=UTF-8');

$authCode = trim((string)($_SESSION['auth_code'] ?? ''));
if ($authCode === '') {
    http_response_code(401);
    echo json_encode(['success'=>false, 'error'=>'Not authenticated']);
    exit;
}

$result = executeSafeQuery(
    'SELECT auth_code, link_id, discord_id, discord_username FROM regular WHERE auth_code = :auth_code LIMIT 2',
    [':auth_code' => $authCode]
);
if (!is_array($result) || count($result) !== 1 || !is_array($result[0])) {
    http_response_code(401);
    echo json_encode(['success'=>false, 'error'=>'User not found']);
    exit;
}
$currentUser = $result[0];
$stored = (string)($currentUser['auth_code'] ?? '');
$userId = (int)($currentUser['link_id'] ?? 0);
if ($stored === '' || !hash_equals($stored, $authCode) || $userId <= 0) {
    http_response_code(401);
    echo json_encode(['success'=>false, 'error'=>'User not found']);
    exit;
}

define('MAX_USER_MESSAGES_PER_TICKET', 3);
define('MAX_IMAGE_SIZE', 5 * 1024 * 1024);
define('MAX_IMAGES_PER_MESSAGE', 3);
define('MAX_VIDEO_SIZE', 100 * 1024 * 1024);
define('MAX_VIDEO_DURATION', 600);

$_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_host = $_SERVER['HTTP_HOST'] ?? 'app.ultima.cl';
$_currentSiteUrl = $_scheme . '://' . $_host;
define('CURRENT_SITE_URL', $_currentSiteUrl);
define('CURRENT_DOMAIN', $_host);

$_uploadRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/uploads/tickets/';
if(!is_dir($_uploadRoot)){
    @mkdir($_uploadRoot, 0755, true);
}
define('UPLOAD_DIR', $_uploadRoot);
define('UPLOAD_URL_PREFIX', CURRENT_SITE_URL . '/uploads/tickets/');
define('ALLOWED_MIME', ['image/jpeg','image/png','image/gif','image/webp']);
define('ALLOWED_VIDEO_MIME', ['video/mp4','video/webm','video/quicktime']);

$action = $_GET['action'] ?? '';

function respond($data){
    echo json_encode($data);
    exit;
}

function getActiveTicket($userId){
    $rows = executeSafeQuery("SELECT * FROM tickets WHERE user_id = :uid AND status = 'open' ORDER BY id DESC LIMIT 1", [':uid'=>$userId]);
    return !empty($rows) ? $rows[0] : null;
}

function getMessages($ticketId){
    $rows = executeSafeQuery("SELECT id, ticket_id, sender_type, message, image_url, video_url, created_at FROM ticket_messages WHERE ticket_id = :tid AND deleted = 0 ORDER BY id ASC", [':tid'=>$ticketId]);
    if(empty($rows)) return [];
    foreach($rows as &$r){
        $r['is_own'] = ($r['sender_type'] === 'user') ? 1 : 0;
    }
    return $rows;
}

function getVideoDurationSeconds($filePath){
    if(!is_file($filePath)) return null;
    $ffprobePaths = ['/usr/bin/ffprobe','/usr/local/bin/ffprobe','ffprobe'];
    foreach($ffprobePaths as $bin){
        $cmd = escapeshellcmd($bin) . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($filePath) . ' 2>/dev/null';
        $out = @shell_exec($cmd);
        if($out !== null && $out !== ''){
            $dur = (float)trim($out);
            if($dur > 0) return $dur;
        }
    }
    $fh = @fopen($filePath, 'rb');
    if(!$fh) return null;
    $head = fread($fh, 65536);
    fclose($fh);
    if($head === false) return null;
    if(preg_match('/mvhd\x00\x00\x00\x00(.{8})/s', $head, $m)){
        $bytes = $m[1];
        $timescale = unpack('N', substr($bytes, 0, 4))[1];
        $duration  = unpack('N', substr($bytes, 4, 4))[1];
        if($timescale > 0) return $duration / $timescale;
    }
    return null;
}

try {

    if($action === 'get_active'){
        $ticket = getActiveTicket($userId);
        if($ticket){
            $messages = getMessages($ticket['id']);
            respond(['success'=>true, 'ticket'=>$ticket, 'messages'=>$messages]);
        }
        respond(['success'=>true, 'ticket'=>null, 'messages'=>[]]);
    }

    if($action === 'create'){
        $existing = getActiveTicket($userId);
        if($existing){
            respond(['success'=>false, 'error'=>'You already have an active ticket']);
        }
        executeSafeQuery(
            "INSERT INTO tickets (user_id, status, source_domain, created_at) VALUES (:uid, 'open', :dom, NOW())",
            [':uid'=>$userId, ':dom'=>CURRENT_DOMAIN]
        );
        respond(['success'=>true]);
    }

    if($action === 'get_messages'){
        $ticketId = intval($_GET['ticket_id'] ?? 0);
        if(!$ticketId) respond(['success'=>false, 'error'=>'Invalid ticket']);

        $rows = executeSafeQuery("SELECT * FROM tickets WHERE id = :tid AND user_id = :uid LIMIT 1", [':tid'=>$ticketId, ':uid'=>$userId]);
        if(empty($rows)) respond(['success'=>false, 'error'=>'Ticket not found']);

        $messages = getMessages($ticketId);
        respond(['success'=>true, 'ticket'=>$rows[0], 'messages'=>$messages]);
    }

    if($action === 'send_message'){
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        $messageText = trim($_POST['message'] ?? '');

        if(!$ticketId) respond(['success'=>false, 'error'=>'Invalid ticket']);

        $rows = executeSafeQuery("SELECT * FROM tickets WHERE id = :tid AND user_id = :uid AND status = 'open' LIMIT 1", [':tid'=>$ticketId, ':uid'=>$userId]);
        if(empty($rows)) respond(['success'=>false, 'error'=>'Ticket not found or closed']);

        $countRows = executeSafeQuery(
            "SELECT COUNT(*) as c FROM ticket_messages
             WHERE ticket_id = :tid AND sender_type = 'user' AND deleted = 0
             AND id > COALESCE((SELECT MAX(id) FROM ticket_messages WHERE ticket_id = :tid2 AND sender_type = 'developer' AND deleted = 0), 0)",
            [':tid'=>$ticketId, ':tid2'=>$ticketId]
        );
        $userMsgCount = !empty($countRows) ? (int)$countRows[0]['c'] : 0;

        if($userMsgCount >= MAX_USER_MESSAGES_PER_TICKET){
            respond(['success'=>false, 'error'=>'Message limit reached']);
        }

        $savedUrls = [];
        if(!empty($_FILES['images']['name'][0])){
            $files = $_FILES['images'];
            $fileCount = count($files['name']);
            if($fileCount > MAX_IMAGES_PER_MESSAGE){
                respond(['success'=>false, 'error'=>'Max '.MAX_IMAGES_PER_MESSAGE.' images per message']);
            }

            for($i = 0; $i < $fileCount; $i++){
                if($files['error'][$i] !== UPLOAD_ERR_OK) continue;
                if($files['size'][$i] > MAX_IMAGE_SIZE){
                    respond(['success'=>false, 'error'=>'Image too large (max 5MB)']);
                }

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $files['tmp_name'][$i]);
                finfo_close($finfo);
                if(!in_array($mime, ALLOWED_MIME)){
                    respond(['success'=>false, 'error'=>'Invalid image type']);
                }

                $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
                $ext = $extMap[$mime];
                $fileName = $ticketId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $destPath = UPLOAD_DIR . $fileName;

                if(!move_uploaded_file($files['tmp_name'][$i], $destPath)){
                    respond(['success'=>false, 'error'=>'Failed to save image']);
                }
                $savedUrls[] = UPLOAD_URL_PREFIX . $fileName;
            }
        }

        $savedVideoUrl = null;
        if(!empty($_FILES['video']['name']) && $_FILES['video']['error'] !== UPLOAD_ERR_NO_FILE){
            $vf = $_FILES['video'];
            if($vf['error'] !== UPLOAD_ERR_OK){
                respond(['success'=>false, 'error'=>'Video upload failed']);
            }
            if($vf['size'] > MAX_VIDEO_SIZE){
                respond(['success'=>false, 'error'=>'Video too large (max 100MB)']);
            }
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $vmime = finfo_file($finfo, $vf['tmp_name']);
            finfo_close($finfo);
            if(!in_array($vmime, ALLOWED_VIDEO_MIME)){
                respond(['success'=>false, 'error'=>'Invalid video type']);
            }
            $duration = getVideoDurationSeconds($vf['tmp_name']);
            if($duration === null){
                respond(['success'=>false, 'error'=>'Could not read video duration']);
            }
            if($duration > MAX_VIDEO_DURATION){
                respond(['success'=>false, 'error'=>'Video too long (max 10 minutes)']);
            }
            $vExtMap = ['video/mp4'=>'mp4','video/webm'=>'webm','video/quicktime'=>'mov'];
            $vExt = $vExtMap[$vmime];
            $vFileName = $ticketId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $vExt;
            $vDest = UPLOAD_DIR . $vFileName;
            if(!move_uploaded_file($vf['tmp_name'], $vDest)){
                respond(['success'=>false, 'error'=>'Failed to save video']);
            }
            $savedVideoUrl = UPLOAD_URL_PREFIX . $vFileName;
        }

        if(empty($savedUrls) && !$savedVideoUrl && $messageText === ''){
            respond(['success'=>false, 'error'=>'Message empty']);
        }

        if(!empty($savedUrls) || $savedVideoUrl){
            $first = true;
            foreach($savedUrls as $url){
                executeSafeQuery(
                    "INSERT INTO ticket_messages (ticket_id, sender_type, message, image_url, video_url, created_at) VALUES (:tid, 'user', :msg, :img, NULL, NOW())",
                    [':tid'=>$ticketId, ':msg'=>$first ? $messageText : '', ':img'=>$url]
                );
                $first = false;
            }
            if($savedVideoUrl){
                executeSafeQuery(
                    "INSERT INTO ticket_messages (ticket_id, sender_type, message, image_url, video_url, created_at) VALUES (:tid, 'user', :msg, NULL, :vid, NOW())",
                    [':tid'=>$ticketId, ':msg'=>$first ? $messageText : '', ':vid'=>$savedVideoUrl]
                );
            }
        } else {
            executeSafeQuery(
                "INSERT INTO ticket_messages (ticket_id, sender_type, message, image_url, video_url, created_at) VALUES (:tid, 'user', :msg, NULL, NULL, NOW())",
                [':tid'=>$ticketId, ':msg'=>$messageText]
            );
        }

        executeSafeQuery("UPDATE tickets SET updated_at = NOW() WHERE id = :tid", [':tid'=>$ticketId]);
        respond(['success'=>true]);
    }

    if($action === 'close'){
        $input = json_decode(file_get_contents('php://input'), true);
        $ticketId = intval($input['ticket_id'] ?? 0);
        if(!$ticketId) respond(['success'=>false, 'error'=>'Invalid ticket']);

        executeSafeQuery("UPDATE tickets SET status = 'closed', closed_at = NOW() WHERE id = :tid AND user_id = :uid", [':tid'=>$ticketId, ':uid'=>$userId]);
        respond(['success'=>true]);
    }

    if($action === 'unread_count'){
        $rows = executeSafeQuery(
            "SELECT COUNT(*) as c FROM ticket_messages tm
             INNER JOIN tickets t ON t.id = tm.ticket_id
             WHERE t.user_id = :uid AND tm.sender_type = 'developer' AND tm.read_by_user = 0 AND tm.deleted = 0",
            [':uid'=>$userId]
        );
        $count = (!empty($rows) && isset($rows[0]['c'])) ? (int)$rows[0]['c'] : 0;
        respond(['success'=>true, 'unread'=>$count]);
    }

    if($action === 'mark_read'){
        executeSafeQuery(
            "UPDATE ticket_messages tm
             INNER JOIN tickets t ON t.id = tm.ticket_id
             SET tm.read_by_user = 1, tm.read_at = NOW()
             WHERE t.user_id = :uid AND tm.sender_type = 'developer' AND tm.read_by_user = 0",
            [':uid'=>$userId]
        );
        respond(['success'=>true]);
    }

    respond(['success'=>false, 'error'=>'Unknown action']);

} catch(Exception $e){
    http_response_code(500);
    respond(['success'=>false, 'error'=>'Server error']);
}