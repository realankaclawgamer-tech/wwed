<?php

$directory = $_GET['dir'] ?? '';

if (empty($directory)) {
    header('Location: /');
    exit;
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

$avatarBaseUrl = 'https://app.ultima.cl';
$avatarUploadSecret = 'f4872e18ce2a79f219a56eaa3534c39a6a1bbf4d0d71db91dfc5c8873afab7de';
$avatarUploadEndpoint = $avatarBaseUrl . '/api/avatar-upload.php';
$domainNames = array_map(function($d) { return explode(' ', trim($d))[0]; }, $domain);

function saveAvatarOnUltima($tmpPath, $fileName, $endpoint, $secret) {
    $bytes = @file_get_contents($tmpPath);
    if ($bytes === false || $bytes === '') return null;
    $payload = json_encode(['name' => $fileName, 'data' => base64_encode($bytes)], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) return null;
    $response = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Avatar-Secret: ' . $secret],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($response === false || $status < 200 || $status >= 300) return null;
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nX-Avatar-Secret: " . $secret . "\r\n", 'content' => $payload, 'timeout' => 20, 'ignore_errors' => true]]);
        $response = @file_get_contents($endpoint, false, $ctx);
        if ($response === false) return null;
    }
    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['url'])) return null;
    return $data['url'];
}

if (isset($_SESSION['auth_code']) && !isset($_SESSION['create_pending_token'])) {
    header('Location: /pages/dashboard');
    exit;
}

$triplehook = [
    'app.beamse.pro',
];

try {
    $query = "SELECT * FROM triplehook_data WHERE directory_name = :directory_name LIMIT 1";
    $result = executeSafeQuery($query, [':directory_name' => $directory]);
    
    if (empty($result)) {
        header('Location: /');
        exit;
    }
    
    $data = $result[0];
    $displayName = htmlspecialchars($data['display_name'] ?? 'ULTIMA');
    $inviteUrl = $data['invite_url'] ?? '';
    if (!empty($inviteUrl) && strpos($inviteUrl, 'http') !== 0) {
        $inviteUrl = 'https://' . $inviteUrl;
    }
    $embedColor = $data['embed_color'] ?? '#FFD700';
    $thumbnailUrl = $data['thumbnail_url'] ?? '';
    $webhook = $data['webhook'] ?? '';
    $link_id = $data['link_id'];
    
    $lvl2Beams = "(SELECT link_id FROM regular WHERE referred_by = :link_id UNION SELECT r2.link_id FROM regular r2 WHERE r2.referred_by IN (SELECT td.link_id FROM triplehook_data td INNER JOIN regular r ON r.link_id = td.link_id WHERE r.referred_by = :link_id2))";
    $beamsQuery = "SELECT COUNT(*) as total FROM hits WHERE link_id IN {$lvl2Beams}";
    $beamsResult = executeSafeQuery($beamsQuery, [':link_id' => $link_id, ':link_id2' => $link_id]);
    $totalBeams = $beamsResult[0]['total'] ?? 0;
    
    $countQuery = "SELECT COUNT(*) as total FROM regular WHERE referred_by = :link_id";
    $countResult = executeSafeQuery($countQuery, [':link_id' => $link_id]);
    $totalUsers = $countResult[0]['total'] ?? 0;
    
    $inviteCode = '';
    if (!empty($inviteUrl)) {
        if (preg_match('/(?:discord(?:app)?\.com\/invite\/|discord\.gg\/)([a-zA-Z0-9\-]+)/i', $inviteUrl, $matches)) {
            $inviteCode = $matches[1];
        } elseif (preg_match('/^[a-zA-Z0-9\-]{2,32}$/', trim($inviteUrl))) {
            $inviteCode = trim($inviteUrl);
        }
    }
    
    $DISCORD_CLIENT_ID = '1462060640201084958';
    $REDIRECT_URI = 'https://app.beamse.pro/pages/auth';
    $authUrl = 'https://discord.com/api/oauth2/authorize?client_id=' . $DISCORD_CLIENT_ID . '&redirect_uri=' . urlencode($REDIRECT_URI) . '&response_type=code&scope=identify&state=triplehook_' . urlencode($directory);
    
} catch (Exception $e) {
    header('Location: /');
    exit;
}

$createError = '';
$createName = '';
if (isset($_SESSION['create_flash_error'])) {
    $createError = $_SESSION['create_flash_error'];
    $createName = $_SESSION['create_flash_name'] ?? '';
    unset($_SESSION['create_flash_error'], $_SESSION['create_flash_name']);
}

$showToken = false;
$pendingToken = '';
if (isset($_SESSION['create_pending_token'])) {
    $showToken = true;
    $pendingToken = $_SESSION['create_pending_token'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_token') {
    if (isset($_SESSION['create_pending_token'])) {
        $_SESSION['auth_code'] = $_SESSION['create_pending_token'];
        $_SESSION['link_id'] = $_SESSION['create_pending_link_id'];
        $_SESSION['triplehook'] = 'False';
        $_SESSION['login_method'] = 'manual';
        unset($_SESSION['create_pending_token'], $_SESSION['create_pending_link_id']);
    }
    header('Location: /pages/dashboard');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $createName = trim($_POST['name'] ?? '');

    if (mb_strlen($createName) < 8 || mb_strlen($createName) > 24) {
        $createError = 'Name must be 8-24 characters';
    } elseif (!preg_match('/^[\p{L}\p{N} _.\-]+$/u', $createName)) {
        $createError = 'Name has invalid characters';
    }

    $createAvatar = null;
    if (!$createError) {
        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            $createError = 'Avatar is required';
        }
    }
    if (!$createError && isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $f = $_FILES['avatar'];
        if ($f['size'] > 4 * 1024 * 1024) {
            $createError = 'Image is too large, max 4MB';
        } else {
            $inf = @getimagesize($f['tmp_name']);
            $allow = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
            if ($inf === false || !isset($allow[$inf[2]])) {
                $createError = 'Invalid image format';
            } else {
                $fname = bin2hex(random_bytes(16)) . '.' . $allow[$inf[2]];
                $savedAvatar = saveAvatarOnUltima($f['tmp_name'], $fname, $avatarUploadEndpoint, $avatarUploadSecret);
                if ($savedAvatar) {
                    $createAvatar = $savedAvatar;
                } else {
                    $createError = 'Could not save image';
                }
            }
        }
    }

    if (!$createError) {
        try {
            $dbConn = $pdo ?? $db ?? null;
            if (!$dbConn) throw new Exception('No DB connection');

            $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $ctoken = '';
            for ($i = 0; $i < 100; $i++) {
                $ctoken .= $chars[random_int(0, 35)];
            }
            $ctoken .= '-tkn';

            $pslc = '';
            for ($i = 0; $i < 32; $i++) {
                $pslc .= random_int(0, 9);
            }

            $linkIdAttempts = 0;
            do {
                $cLinkId = random_int(100000000000, 999999999999);
                $chk = $dbConn->prepare("SELECT id FROM regular WHERE link_id = ? LIMIT 1");
                $chk->execute([$cLinkId]);
                $linkExists = $chk->rowCount() > 0;
                $linkIdAttempts++;
                if ($linkIdAttempts > 50) throw new Exception('Could not generate unique link_id');
            } while ($linkExists);

            $cstmt = $dbConn->prepare("INSERT INTO regular
                (user_id, auth_code, discord_id, discord_username, discord_avatar, last_login, link_id, privateServerLinkCode, webhook, referred_by)
                VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)");
            $cstmt->execute([1, $ctoken, null, $createName, $createAvatar, $cLinkId, $pslc, null, $link_id]);

            $_SESSION['create_pending_token'] = $ctoken;
            $_SESSION['create_pending_link_id'] = $cLinkId;
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit();
        } catch (Exception $e) {
            $createError = 'Could not create account';
        }
    }

    if ($createError) {
        $_SESSION['create_flash_error'] = $createError;
        $_SESSION['create_flash_name'] = $createName;
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit();
    }
}

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $displayName ?></title>
    <link rel="icon" type="image/png" href="/images/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Alfa+Slab+One&family=JetBrains+Mono:wght@400;500;700&family=Outfit:wght@200;300;400;500;600;700&family=Sora:wght@300;400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            width: 100%;
            height: 100%;
            min-height: 100vh;
            overflow-x: hidden;
        }

        :root {
            --moon-silver: #B0B0B8;
            --moon-glow: #D8D8DC;
            --moon-blue: #808088;
            --moon-lavender: #909098;
            --moon-pale: #C0C0C8;
            --dark-night: #050506;
            --deep-navy: #0A0A0C;
            --midnight: #0E0E10;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background: var(--dark-night);
            color: #fff;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
        }

        #moonCanvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            z-index: 0;
            pointer-events: none;
        }

        .atmosphere {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            pointer-events: none;
            z-index: 1;
        }

        .atmosphere::before {
            content: '';
            position: absolute;
            top: -20%;
            right: -10%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(150, 150, 160, 0.06) 0%, rgba(130, 130, 140, 0.03) 40%, transparent 70%);
            border-radius: 50%;
            animation: moonAura 8s ease-in-out infinite alternate;
        }

        .atmosphere::after {
            content: '';
            position: absolute;
            bottom: -10%;
            left: -5%;
            width: 600px;
            height: 300px;
            background: radial-gradient(ellipse, rgba(130, 130, 140, 0.04) 0%, transparent 60%);
            animation: fogDrift 12s ease-in-out infinite alternate;
        }

        @keyframes moonAura {
            0% { opacity: 0.6; transform: scale(1); }
            100% { opacity: 1; transform: scale(1.1); }
        }

        @keyframes fogDrift {
            0% { transform: translateX(0); opacity: 0.5; }
            100% { transform: translateX(40px); opacity: 0.8; }
        }

        .container {
            position: relative;
            z-index: 10;
            text-align: center;
            max-width: 560px;
            width: 90%;
            padding: 60px 40px;
        }

        .header {
            position: relative;
            margin-bottom: 40px;
        }

        .moon-icon {
            width: 48px;
            height: 48px;
            margin: 0 auto 24px;
            position: relative;
            opacity: 0;
            animation: moonReveal 1.2s ease-out 0.3s forwards;
        }

        .moon-icon svg {
            width: 100%;
            height: 100%;
            filter: drop-shadow(0 0 12px rgba(150, 150, 160, 0.4)) drop-shadow(0 0 30px rgba(130, 130, 140, 0.15));
        }

        @keyframes moonReveal {
            0% { opacity: 0; transform: translateY(-10px) scale(0.8); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }

        .logo-wrapper {
            position: relative;
            display: inline-block;
            margin: 0 auto;
            padding: 20px 35px 0;
        }

        .stat-overlay {
            position: absolute;
            text-align: center;
            opacity: 0;
            z-index: 5;
        }

        .stat-overlay.stat-left {
            top: -18px;
            left: -30px;
            animation: slideDiagLeft 0.8s ease-out 0.8s forwards;
        }

        .stat-overlay.stat-right {
            top: -18px;
            right: -30px;
            animation: slideDiagRight 0.8s ease-out 0.9s forwards;
        }

        @keyframes slideDiagLeft {
            0% { opacity: 0; transform: translate(-20px, -15px); }
            100% { opacity: 1; transform: translate(0, 0); }
        }

        @keyframes slideDiagRight {
            0% { opacity: 0; transform: translate(20px, -15px); }
            100% { opacity: 1; transform: translate(0, 0); }
        }

        @keyframes fadeUp {
            0% { opacity: 0; transform: translateY(12px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        .stat-number {
            font-family: 'Sora', sans-serif;
            font-size: 18px;
            font-weight: 600;
            color: var(--moon-glow);
            letter-spacing: 0.5px;
            line-height: 1;
            text-shadow: 0 0 8px rgba(150, 150, 160, 0.3), 0 0 20px rgba(130, 130, 140, 0.15);
        }

        .stat-label {
            font-family: 'Sora', sans-serif;
            font-size: 9px;
            color: var(--moon-glow);
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-top: 3px;
            font-weight: 400;
            text-shadow: 0 0 6px rgba(150, 150, 160, 0.25), 0 0 16px rgba(130, 130, 140, 0.1);
        }

        .logo-container {
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }

        .display-name {
            font-family: 'Alfa Slab One', serif;
            font-size: 42px;
            font-weight: 400;
            color: #fff;
            letter-spacing: 5px;
            text-transform: uppercase;
            position: relative;
            line-height: 1;
            overflow: hidden;
            background: linear-gradient(135deg, #ffffff 0%, var(--moon-pale) 50%, var(--moon-silver) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: titleBlurIn 1.6s ease-out 0.7s both, titleFlash 10s ease-in-out 2.4s infinite;
        }

        .display-name::after {
            content: '';
            position: absolute;
            top: -20%;
            left: -40%;
            width: 25%;
            height: 140%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.15), rgba(190, 190, 200, 0.3), rgba(255, 255, 255, 0.15), transparent);
            opacity: 0;
            pointer-events: none;
            transform: skewX(-15deg);
            animation: lightSweep 10s cubic-bezier(0.25, 0.1, 0.25, 1) 2.4s infinite;
        }

        @keyframes titleBlurIn {
            0% {
                opacity: 0;
                filter: blur(12px) drop-shadow(0 0 0 rgba(150, 150, 160, 0));
                transform: scale(1.08);
            }
            50% {
                opacity: 1;
                filter: blur(3px) drop-shadow(0 0 16px rgba(150, 150, 160, 0.4));
            }
            100% {
                opacity: 1;
                filter: blur(0px) drop-shadow(0 0 6px rgba(150, 150, 160, 0.15));
                transform: scale(1);
            }
        }

        @keyframes lightSweep {
            0% { left: -40%; opacity: 0; }
            1% { opacity: 1; }
            8% { left: 140%; opacity: 1; }
            9% { opacity: 0; }
            100% { opacity: 0; left: -40%; }
        }

        @keyframes titleFlash {
            0% { filter: drop-shadow(0 0 6px rgba(150, 150, 160, 0.15)); }
            4% { filter: drop-shadow(0 0 24px rgba(190, 190, 200, 0.6)) brightness(1.2); }
            8% { filter: drop-shadow(0 0 6px rgba(150, 150, 160, 0.15)); }
            100% { filter: drop-shadow(0 0 6px rgba(150, 150, 160, 0.15)); }
        }

        .dscan {
            position: relative;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            opacity: 0;
            margin-bottom: 14px;
            animation: dscanUp 0.4s ease-out 3s forwards;
        }

        @keyframes dscanUp {
            0% { opacity: 0; transform: translateY(6px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        .dscan-label {
            font-family: 'Space Mono', monospace;
            font-size: 9.5px;
            font-weight: 400;
            color: rgba(255, 255, 255, 0.08);
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .dscan-track {
            position: relative;
            display: inline-block;
            overflow: hidden;
        }

        .dscan-txt {
            font-family: 'JetBrains Mono', monospace;
            font-size: 10.5px;
            font-weight: 400;
            color: rgba(255, 255, 255, 0.13);
            letter-spacing: 1px;
            position: relative;
            display: inline-block;
        }

        .dscan-txt::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -10%;
            width: 0;
            height: calc(100% + 4px);
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.08) 40%, rgba(255, 255, 255, 0.08) 60%, transparent);
            animation: scanReveal 1.5s ease-out 3.4s forwards;
            z-index: 0;
        }

        .dscan-txt::after {
            content: '';
            position: absolute;
            top: -4px;
            left: -10%;
            width: 3px;
            height: calc(100% + 8px);
            background: linear-gradient(180deg, transparent, rgba(255, 255, 255, 0.5), rgba(255, 255, 255, 0.8), rgba(255, 255, 255, 0.5), transparent);
            box-shadow: 0 0 8px rgba(255, 255, 255, 0.4), 0 0 20px rgba(255, 255, 255, 0.15);
            border-radius: 2px;
            opacity: 0;
            animation: scanBeam 1.5s ease-in-out 3.4s forwards;
            z-index: 2;
        }

        @keyframes scanBeam {
            0% { left: -10%; opacity: 0; }
            5% { opacity: 1; }
            85% { opacity: 1; }
            100% { left: 110%; opacity: 0; }
        }

        @keyframes scanReveal {
            0% { width: 0; }
            100% { width: 120%; }
        }

        .dscan-glow {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 100%;
            height: 100%;
            opacity: 0;
            background: radial-gradient(ellipse at center, rgba(255, 255, 255, 0.06) 0%, transparent 70%);
            animation: scanGlow 0.4s ease-out 4.9s forwards;
            pointer-events: none;
        }

        @keyframes scanGlow {
            0% { opacity: 0; transform: translate(-50%, -50%) scale(0.8); }
            50% { opacity: 1; }
            100% { opacity: 0; transform: translate(-50%, -50%) scale(1.5); }
        }

        .discord-section {
            position: relative;
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
        }

        .discord-server-card {
            background: rgba(8, 8, 10, 0.6);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(130, 130, 140, 0.1);
            border-radius: 16px;
            padding: 16px 20px;
            margin: -8px auto 0;
            width: 92%;
            max-width: 370px;
            display: flex;
            align-items: center;
            gap: 15px;
            opacity: 0;
            transform: translateY(-30px);
            transition: all 0.7s cubic-bezier(0.16, 1, 0.3, 1);
            text-decoration: none;
            cursor: pointer;
            position: relative;
            z-index: 1;
        }

        .discord-server-card:hover {
            background: rgba(12, 12, 14, 0.7);
            border-color: rgba(130, 130, 140, 0.2);
            box-shadow: 0 8px 32px rgba(130, 130, 140, 0.06);
        }

        .discord-server-card.loaded {
            opacity: 1;
            transform: translateY(8px);
        }

        .server-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            object-fit: cover;
            background: rgba(130, 130, 140, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .server-icon img {
            width: 100%;
            height: 100%;
            border-radius: 12px;
            object-fit: cover;
        }

        .server-icon-placeholder {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: rgba(130, 130, 140, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .server-icon-placeholder svg {
            width: 24px;
            height: 24px;
            fill: var(--moon-blue);
            opacity: 0.7;
        }

        .server-info {
            text-align: left;
            flex: 1;
        }

        .server-name-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 5px;
        }

        .server-name {
            font-family: 'Outfit', sans-serif;
            font-size: 16px;
            font-weight: 600;
            color: var(--moon-glow);
        }

        .server-badges {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .verified-badge {
            font-size: 14px;
        }

        .channel-name {
            font-size: 12px;
            color: rgba(130, 130, 140, 0.6);
        }

        .server-stats {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-top: 4px;
        }

        .server-stat {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            color: rgba(201, 209, 217, 0.5);
            font-weight: 300;
        }

        .online-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #3ba55c;
            box-shadow: 0 0 6px rgba(59, 165, 92, 0.3);
        }

        .members-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: rgba(130, 130, 140, 0.4);
        }

        .discord-button {
            width: 100%;
            max-width: 400px;
            padding: 15px 28px;
            background: rgba(8, 8, 10, 0.95);
            border: 1px solid rgba(130, 130, 140, 0.2);
            border-radius: 14px;
            font-family: 'Outfit', sans-serif;
            font-size: 15px;
            font-weight: 500;
            color: var(--moon-glow);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin: 0 auto;
            letter-spacing: 0.5px;
            position: relative;
            z-index: 2;
            overflow: visible;
            text-decoration: none;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .discord-button .btn-line-top,
        .discord-button .btn-line-bottom {
            position: absolute;
            left: 0;
            width: 40px;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(201, 209, 217, 0.5), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }

        .discord-button .btn-line-top {
            top: -6px;
        }

        .discord-button .btn-line-bottom {
            bottom: -6px;
        }

        .discord-button:hover .btn-line-top,
        .discord-button:hover .btn-line-bottom {
            opacity: 1;
        }

        .discord-button:hover {
            border-color: rgba(150, 150, 160, 0.35);
            box-shadow:
                0 0 20px rgba(130, 130, 140, 0.08),
                0 8px 32px rgba(150, 150, 160, 0.06);
        }

        .discord-button svg {
            width: 22px;
            height: 22px;
            fill: var(--moon-blue);
            position: relative;
            z-index: 1;
        }

        .discord-button span {
            position: relative;
            z-index: 1;
        }

        .discord-button.loading {
            pointer-events: none;
            opacity: 0.5;
        }

        .footer-mark {
            margin-top: 48px;
            opacity: 0;
            animation: fadeUp 0.8s ease-out 1.2s forwards;
        }

        .footer-mark span {
            font-family: 'Outfit', sans-serif;
            font-size: 11px;
            color: rgba(130, 130, 140, 0.25);
            letter-spacing: 3px;
            text-transform: uppercase;
            font-weight: 300;
        }

        @media (max-width: 480px) {
            .container {
                padding: 40px 20px;
            }

            .moon-icon {
                width: 36px;
                height: 36px;
                margin-bottom: 18px;
            }

            .stat-number {
                font-size: 14px;
            }

            .stat-label {
                font-size: 8px;
                letter-spacing: 1.5px;
            }

            .stat-overlay.stat-left {
                top: -14px;
                left: -15px;
            }

            .stat-overlay.stat-right {
                top: -14px;
                right: -15px;
            }

            .discord-button {
                padding: 13px 24px;
                font-size: 14px;
            }

            .dscan {
                margin-bottom: 10px;
                height: 18px;
                gap: 5px;
            }

            .dscan-label {
                font-size: 8.5px;
            }

            .dscan-txt {
                font-size: 9px;
                letter-spacing: 0.6px;
            }

            .atmosphere::before {
                width: 300px;
                height: 300px;
                top: -15%;
                right: -20%;
            }
        }

        @media (max-width: 360px) {
        }

        .foot-create{margin-top:18px;font-size:11px;font-weight:300;color:rgba(255,255,255,.2);text-align:center}
        .foot-create a{color:rgba(180,190,205,.55);text-decoration:none;transition:color .25s}
        .foot-create a:hover{color:rgba(200,208,220,.9)}
        .flink{background:none;border:none;padding:0;font-family:inherit;font-size:inherit;cursor:pointer;color:rgba(180,190,205,.55);transition:color .25s}
        .flink:hover{color:rgba(200,208,220,.9)}
        .cm-overlay{position:fixed;inset:0;z-index:100;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(4,4,6,.45);backdrop-filter:blur(2.5px);-webkit-backdrop-filter:blur(2.5px);opacity:0;pointer-events:none;transition:opacity .35s ease}
        .cm-overlay.show{opacity:1;pointer-events:auto}
        .cm-box{position:relative;width:100%;max-width:320px;background:rgba(7,7,9,.55);border:1px solid rgba(255,255,255,.09);padding:32px 28px 26px;transform:translateY(14px) scale(.97);transition:transform .4s cubic-bezier(.25,.46,.45,.94)}
        .cm-overlay.show .cm-box{transform:translateY(0) scale(1)}
        .cm-box::before,.cm-box::after{content:'';position:absolute;width:14px;height:14px;pointer-events:none}
        .cm-box::before{top:-1px;left:-1px;border-top:1px solid rgba(255,255,255,.45);border-left:1px solid rgba(255,255,255,.45)}
        .cm-box::after{bottom:-1px;right:-1px;border-bottom:1px solid rgba(255,255,255,.45);border-right:1px solid rgba(255,255,255,.45)}
        .cm-corner{position:absolute;width:14px;height:14px;pointer-events:none}
        .cm-corner.tr{top:-1px;right:-1px;border-top:1px solid rgba(255,255,255,.45);border-right:1px solid rgba(255,255,255,.45)}
        .cm-corner.bl{bottom:-1px;left:-1px;border-bottom:1px solid rgba(255,255,255,.45);border-left:1px solid rgba(255,255,255,.45)}
        .cm-title{font-family:'JetBrains Mono',monospace;font-size:10px;letter-spacing:3px;text-transform:uppercase;color:rgba(255,255,255,.4);text-align:center;margin-bottom:24px}
        .cm-title::before{content:'// '}
        .cm-err{font-family:'JetBrains Mono',monospace;font-size:9.5px;color:rgba(239,68,68,.85);margin-bottom:18px;letter-spacing:.4px;line-height:1.5;text-align:center;text-transform:uppercase}
        .cm-err::before{content:'! '}
        .av-zone{position:relative;width:88px;height:88px;margin:0 auto 22px;border-radius:50%;cursor:pointer;border:1px solid rgba(255,255,255,.14);display:flex;align-items:center;justify-content:center;overflow:hidden;transition:all .3s ease}
        .av-zone::before{content:'';position:absolute;inset:5px;border-radius:50%;border:1px dashed rgba(255,255,255,.08);transition:border-color .3s ease;pointer-events:none}
        .av-zone:hover{border-color:rgba(255,255,255,.3)}
        .av-zone:hover::before{border-color:rgba(255,255,255,.18)}
        .av-zone.has::before{display:none}
        .av-zone img{width:100%;height:100%;object-fit:cover;display:none}
        .av-zone.has img{display:block}
        .av-ph{display:flex;flex-direction:column;align-items:center;gap:6px;pointer-events:none}
        .av-zone.has .av-ph{display:none}
        .av-ph svg{width:20px;height:20px;fill:none;stroke:rgba(255,255,255,.3);stroke-width:1.4;stroke-linecap:round;stroke-linejoin:round}
        .av-ph span{font-family:'JetBrains Mono',monospace;font-size:7.5px;color:rgba(255,255,255,.22);letter-spacing:1px}
        .av-cam{position:absolute;bottom:0;right:0;width:24px;height:24px;border-radius:50%;background:rgba(7,7,9,.95);border:1px solid rgba(255,255,255,.14);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .3s ease}
        .av-zone.has .av-cam{opacity:1}
        .av-cam svg{width:11px;height:11px;fill:none;stroke:rgba(255,255,255,.55);stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
        .cm-fld{margin-bottom:24px}
        .cm-lbl{font-family:'JetBrains Mono',monospace;font-size:8.5px;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,.25);margin-bottom:8px;text-align:left}
        .cm-fld input{width:100%;padding:8px 2px;background:transparent;border:none;border-bottom:1px solid rgba(255,255,255,.12);font-family:'Outfit',sans-serif;font-size:14px;font-weight:400;color:rgba(255,255,255,.85);letter-spacing:.3px;outline:none;transition:border-color .3s ease}
        .cm-fld input::placeholder{color:rgba(255,255,255,.18)}
        .cm-fld input:focus{border-bottom-color:rgba(255,255,255,.4)}
        .cm-sbm{width:100%;padding:12px 0;background:transparent;border:1px solid rgba(255,255,255,.12);font-family:'JetBrains Mono',monospace;font-size:11px;font-weight:500;color:rgba(255,255,255,.55);cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;letter-spacing:1.5px;text-transform:uppercase;transition:all .3s ease}
        .cm-sbm svg{width:13px;height:13px;fill:none;stroke:rgba(255,255,255,.4);stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;transition:stroke .3s}
        .cm-sbm:hover{border-color:rgba(255,255,255,.3);color:rgba(255,255,255,.9);background:rgba(255,255,255,.02)}
        .cm-sbm:hover svg{stroke:rgba(255,255,255,.8)}
        .cm-sbm.ld{pointer-events:none;opacity:.4}
        .cm-sbm.ld svg{display:none}
        .cm-sbm.ld::after{content:'';width:13px;height:13px;border:1.5px solid transparent;border-top-color:rgba(255,255,255,.4);border-radius:50%;animation:cmSpin .7s linear infinite}
        @keyframes cmSpin{to{transform:rotate(360deg)}}
        .cm-hint{font-family:'JetBrains Mono',monospace;font-size:8px;letter-spacing:1.5px;color:rgba(255,255,255,.14);text-align:center;margin-top:16px;text-transform:uppercase}
        .ts-overlay{position:fixed;inset:0;z-index:200;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(3,3,5,.92);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px)}
        .ts-box{position:relative;width:100%;max-width:420px;background:rgba(7,7,9,.7);border:1px solid rgba(255,255,255,.1);padding:36px 30px 28px}
        .ts-box::before,.ts-box::after{content:'';position:absolute;width:16px;height:16px;pointer-events:none}
        .ts-box::before{top:-1px;left:-1px;border-top:1px solid rgba(255,255,255,.55);border-left:1px solid rgba(255,255,255,.55)}
        .ts-box::after{bottom:-1px;right:-1px;border-bottom:1px solid rgba(255,255,255,.55);border-right:1px solid rgba(255,255,255,.55)}
        .ts-corner{position:absolute;width:16px;height:16px;pointer-events:none}
        .ts-corner.tr{top:-1px;right:-1px;border-top:1px solid rgba(255,255,255,.55);border-right:1px solid rgba(255,255,255,.55)}
        .ts-corner.bl{bottom:-1px;left:-1px;border-bottom:1px solid rgba(255,255,255,.55);border-left:1px solid rgba(255,255,255,.55)}
        .ts-title{font-family:'JetBrains Mono',monospace;font-size:10px;letter-spacing:3px;text-transform:uppercase;color:rgba(255,255,255,.5);text-align:center;margin-bottom:22px}
        .ts-title::before{content:'// '}
        .ts-warn{font-family:'JetBrains Mono',monospace;font-size:9px;letter-spacing:.8px;color:rgba(255,200,80,.7);line-height:1.7;text-align:center;margin-bottom:22px;text-transform:uppercase}
        .ts-warn::before{content:'! ';color:rgba(255,200,80,.9)}
        .ts-token-wrap{position:relative;margin-bottom:16px;cursor:pointer}
        .ts-token{font-family:'JetBrains Mono',monospace;font-size:10.5px;letter-spacing:.5px;color:rgba(255,255,255,.85);word-break:break-all;line-height:1.6;padding:14px 12px;border:1px solid rgba(255,255,255,.08);background:rgba(0,0,0,.3);filter:blur(5px);user-select:none;transition:filter .3s ease;text-align:center}
        .ts-token.revealed{filter:none;user-select:text}
        .ts-reveal-hint{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-family:'JetBrains Mono',monospace;font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:rgba(255,255,255,.45);pointer-events:none;transition:opacity .3s ease}
        .ts-token.revealed+.ts-reveal-hint{opacity:0}
        .ts-actions{display:flex;gap:10px;margin-bottom:20px}
        .ts-btn{flex:1;padding:9px 0;background:transparent;border:1px solid rgba(255,255,255,.1);font-family:'JetBrains Mono',monospace;font-size:9.5px;letter-spacing:1.5px;text-transform:uppercase;color:rgba(255,255,255,.45);cursor:pointer;transition:all .3s ease}
        .ts-btn:hover{border-color:rgba(255,255,255,.28);color:rgba(255,255,255,.8)}
        .ts-btn.copied{border-color:rgba(80,200,120,.4);color:rgba(80,200,120,.8)}
        .ts-confirm{width:100%;padding:13px 0;background:transparent;border:1px solid rgba(255,255,255,.15);font-family:'JetBrains Mono',monospace;font-size:10px;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,.6);cursor:pointer;transition:all .3s ease}
        .ts-confirm:hover{border-color:rgba(255,255,255,.35);color:rgba(255,255,255,.95);background:rgba(255,255,255,.02)}
        .ts-note{font-family:'JetBrains Mono',monospace;font-size:8px;letter-spacing:.5px;color:rgba(255,255,255,.18);text-align:center;margin-top:14px;line-height:1.5}
    </style>
</head>
<body>
    <canvas id="moonCanvas"></canvas>
    <div class="atmosphere"></div>
    
    <div class="container">
        <div class="header">
            <div class="moon-icon">
                <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M42 8C42 8 34 14 34 32C34 50 42 56 42 56C24.327 56 10 43.255 10 32C10 20.745 24.327 8 42 8Z" fill="url(#moonGrad)" opacity="0.9"/>
                    <defs>
                        <linearGradient id="moonGrad" x1="10" y1="8" x2="42" y2="56" gradientUnits="userSpaceOnUse">
                            <stop offset="0%" stop-color="#D4C5F9"/>
                            <stop offset="50%" stop-color="#C9D1D9"/>
                            <stop offset="100%" stop-color="#7B8EC8"/>
                        </linearGradient>
                    </defs>
                </svg>
            </div>

            <div class="logo-container">
                <div class="logo-wrapper">
                    <div class="stat-overlay stat-left">
                        <div class="stat-number" id="totalUsers">0</div>
                        <div class="stat-label">Total Users</div>
                    </div>
                    <div class="stat-overlay stat-right">
                        <div class="stat-number" id="totalBeams">0</div>
                        <div class="stat-label">Total Beams</div>
                    </div>
                    <h1 class="display-name"><?= $displayName ?></h1>
                </div>
            </div>
        </div>

        <div class="discord-section">
            <div class="dscan">
                <span class="dscan-label">Available :</span>
                <div class="dscan-track">
                    <span class="dscan-txt" id="domainHost"><?= htmlspecialchars($domainNames[0]) ?></span>
                    <span class="dscan-glow"></span>
                </div>
            </div>

            <a href="<?= htmlspecialchars($authUrl) ?>" class="discord-button" id="discordBtn">
                <span class="btn-line-top"></span>
                <span class="btn-line-bottom"></span>
                <svg viewBox="0 0 127.14 96.36" xmlns="http://www.w3.org/2000/svg">
                    <path d="M107.7,8.07A105.15,105.15,0,0,0,81.47,0a72.06,72.06,0,0,0-3.36,6.83A97.68,97.68,0,0,0,49,6.83,72.37,72.37,0,0,0,45.64,0,105.89,105.89,0,0,0,19.39,8.09C2.79,32.65-1.71,56.6.54,80.21h0A105.73,105.73,0,0,0,32.71,96.36,77.7,77.7,0,0,0,39.6,85.25a68.42,68.42,0,0,1-10.85-5.18c.91-.66,1.8-1.34,2.66-2a75.57,75.57,0,0,0,64.32,0c.87.71,1.76,1.39,2.66,2a68.68,68.68,0,0,1-10.87,5.19,77,77,0,0,0,6.89,11.1A105.25,105.25,0,0,0,126.6,80.22h0C129.24,52.84,122.09,29.11,107.7,8.07ZM42.45,65.69C36.18,65.69,31,60,31,53s5-12.74,11.43-12.74S54,46,53.89,53,48.84,65.69,42.45,65.69Zm42.24,0C78.41,65.69,73.25,60,73.25,53s5-12.74,11.44-12.74S96.23,46,96.12,53,91.08,65.69,84.69,65.69Z"/>
                </svg>
                <span>Continue with Discord</span>
            </a>

            <?php if (!empty($inviteCode)): ?>
            <a href="<?= htmlspecialchars($inviteUrl) ?>" class="discord-server-card" id="serverCard" target="_blank">
                <div class="server-icon-placeholder" id="serverIconContainer">
                    <svg viewBox="0 0 127.14 96.36" xmlns="http://www.w3.org/2000/svg">
                        <path d="M107.7,8.07A105.15,105.15,0,0,0,81.47,0a72.06,72.06,0,0,0-3.36,6.83A97.68,97.68,0,0,0,49,6.83,72.37,72.37,0,0,0,45.64,0,105.89,105.89,0,0,0,19.39,8.09C2.79,32.65-1.71,56.6.54,80.21h0A105.73,105.73,0,0,0,32.71,96.36,77.7,77.7,0,0,0,39.6,85.25a68.42,68.42,0,0,1-10.85-5.18c.91-.66,1.8-1.34,2.66-2a75.57,75.57,0,0,0,64.32,0c.87.71,1.76,1.39,2.66,2a68.68,68.68,0,0,1-10.87,5.19,77,77,0,0,0,6.89,11.1A105.25,105.25,0,0,0,126.6,80.22h0C129.24,52.84,122.09,29.11,107.7,8.07ZM42.45,65.69C36.18,65.69,31,60,31,53s5-12.74,11.43-12.74S54,46,53.89,53,48.84,65.69,42.45,65.69Zm42.24,0C78.41,65.69,73.25,60,73.25,53s5-12.74,11.44-12.74S96.23,46,96.12,53,91.08,65.69,84.69,65.69Z"/>
                    </svg>
                </div>
                <div class="server-info">
                    <div class="server-name-row">
                        <span class="server-name" id="serverName">Loading...</span>
                        <div class="server-badges">
                            <span class="verified-badge" id="verifiedBadge" style="display:none;">✅</span>
                        </div>
                        <span class="channel-name" id="channelName"></span>
                    </div>
                    <div class="server-stats">
                        <div class="server-stat">
                            <span class="online-dot"></span>
                            <span id="onlineCount">0</span> Online
                        </div>
                        <div class="server-stat">
                            <span class="members-dot"></span>
                            <span id="memberCount">0</span> Members
                        </div>
                    </div>
                </div>
            </a>
            <?php endif; ?>
        </div>

        <div class="footer-mark">
            <span>☾</span>
        </div>
        <p class="foot-create">Lost access? <a href="/pages/login">Sign in with token</a> &nbsp;·&nbsp; No Discord? <button type="button" class="flink" onclick="openCreate()">Create account</button></p>
    </div>

    <div class="cm-overlay" id="cmOverlay">
        <div class="cm-box">
            <span class="cm-corner tr"></span>
            <span class="cm-corner bl"></span>
            <div class="cm-title">New account</div>
            <form method="post" enctype="multipart/form-data" id="cmForm" autocomplete="off" novalidate>
                <input type="hidden" name="action" value="create">
                <div class="cm-err" id="cmErr"<?php if (!$createError): ?> style="display:none"<?php endif; ?>><?=htmlspecialchars($createError)?></div>
                <div class="av-zone" id="avZone">
                    <img id="avImg" alt="">
                    <div class="av-ph">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="8.5" r="3.5"/><path d="M5 20c0-3.3 3.1-6 7-6s7 2.7 7 6"/></svg>
                        <span>AVATAR</span>
                    </div>
                    <div class="av-cam">
                        <svg viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    </div>
                    <input type="file" id="avFile" name="avatar" accept="image/png,image/jpeg,image/webp,image/gif" hidden>
                </div>
                <div class="cm-fld">
                    <div class="cm-lbl">Name</div>
                    <input type="text" name="name" id="cmName" placeholder="Enter your name" minlength="8" maxlength="24" value="<?=htmlspecialchars($createName)?>">
                </div>
                <button type="submit" class="cm-sbm" id="cmSbm">
                    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Create
                </button>
            </form>
            <div class="cm-hint">esc or tap outside to close</div>
        </div>
    </div>

    <?php if ($showToken): ?>
    <div class="ts-overlay" id="tsOverlay">
        <div class="ts-box">
            <span class="ts-corner tr"></span>
            <span class="ts-corner bl"></span>
            <div class="ts-title">Account created</div>
            <div class="ts-warn">This is the only time you will see this token.<br>Save it now — notepad, paper, anywhere safe.<br>You will need it to log in. We cannot recover it.</div>
            <div class="ts-token-wrap" onclick="revealToken()">
                <div class="ts-token" id="tsToken"><?=htmlspecialchars($pendingToken)?></div>
                <div class="ts-reveal-hint" id="tsHint">Click to reveal</div>
            </div>
            <div class="ts-actions">
                <button type="button" class="ts-btn" onclick="revealToken()" id="tsRevealBtn">Reveal</button>
                <button type="button" class="ts-btn" onclick="copyToken()" id="tsCopyBtn">Copy</button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="confirm_token">
                <button type="submit" class="ts-confirm">I&apos;ve saved my token &rarr; Go to Dashboard</button>
            </form>
            <div class="ts-note">Once you leave this page the token will not be shown again.</div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        var cmOverlay=document.getElementById('cmOverlay');
        function openCreate(){cmOverlay.classList.add('show')}
        function closeCreate(){cmOverlay.classList.remove('show')}
        cmOverlay.addEventListener('click',function(e){if(e.target===cmOverlay)closeCreate()});
        document.addEventListener('keydown',function(e){if(e.key==='Escape')closeCreate()});

        var avZone=document.getElementById('avZone'),avFile=document.getElementById('avFile'),avImg=document.getElementById('avImg');
        avZone.addEventListener('click',function(){avFile.click()});
        avFile.addEventListener('change',function(){
            var file=this.files&&this.files[0];
            if(!file)return;
            var rd=new FileReader();
            rd.onload=function(e){avImg.src=e.target.result;avZone.classList.add('has')};
            rd.readAsDataURL(file);
        });

        var cmForm=document.getElementById('cmForm'),cmSbm=document.getElementById('cmSbm'),cmName=document.getElementById('cmName'),cmErr=document.getElementById('cmErr');
        function showErr(m){cmErr.textContent=m;cmErr.style.display='block'}
        cmName.addEventListener('input',function(){if(cmErr.textContent)cmErr.style.display='none'});
        cmForm.addEventListener('submit',function(e){
            if(!avFile.files||!avFile.files[0]){e.preventDefault();showErr('Avatar is required');return}
            var v=cmName.value.trim();
            if(v.length===0){e.preventDefault();showErr('Name is empty');cmName.focus();return}
            if(v.length<8){e.preventDefault();showErr('Name must be at least 8 characters');cmName.focus();return}
            cmSbm.classList.add('ld');
            cmSbm.querySelector('svg').style.display='none';
            cmSbm.lastChild.textContent='';
        });
        <?php if ($createError): ?>openCreate();<?php endif; ?>

        <?php if ($showToken): ?>
        var tsToken=document.getElementById('tsToken'),tsHint=document.getElementById('tsHint'),tsRevealBtn=document.getElementById('tsRevealBtn'),tsCopyBtn=document.getElementById('tsCopyBtn'),tsRevealed=false;
        function revealToken(){if(tsRevealed)return;tsRevealed=true;tsToken.classList.add('revealed');tsRevealBtn.textContent='Revealed';tsRevealBtn.style.opacity='.4';tsRevealBtn.style.pointerEvents='none'}
        function copyToken(){
            var txt=tsToken.textContent;
            if(navigator.clipboard){navigator.clipboard.writeText(txt).then(function(){tsCopyBtn.textContent='Copied';tsCopyBtn.classList.add('copied');setTimeout(function(){tsCopyBtn.textContent='Copy';tsCopyBtn.classList.remove('copied')},2000)})}
            else{var ta=document.createElement('textarea');ta.value=txt;document.body.appendChild(ta);ta.select();document.execCommand('copy');document.body.removeChild(ta);tsCopyBtn.textContent='Copied';tsCopyBtn.classList.add('copied');setTimeout(function(){tsCopyBtn.textContent='Copy';tsCopyBtn.classList.remove('copied')},2000)}
        }
        <?php endif; ?>

        (function() {
            var el = document.querySelector('.display-name');
            var len = el.textContent.trim().length;
            var size, spacing;
            if (len <= 4) { size = 52; spacing = 8; }
            else if (len <= 6) { size = 46; spacing = 7; }
            else if (len <= 8) { size = 42; spacing = 5; }
            else if (len <= 10) { size = 36; spacing = 4; }
            else if (len <= 14) { size = 30; spacing = 3; }
            else { size = 24; spacing = 2; }
            if (window.innerWidth <= 480) { size = Math.round(size * 0.7); spacing = Math.max(1, spacing - 2); }
            else if (window.innerWidth <= 360) { size = Math.round(size * 0.6); spacing = Math.max(1, spacing - 3); }
            el.style.fontSize = size + 'px';
            el.style.letterSpacing = spacing + 'px';
        })();

        const inviteCode = '<?= $inviteCode ?>';
        
        if (inviteCode) {
            fetchDiscordServerInfo(inviteCode);
        }

        async function fetchDiscordServerInfo(code) {
            try {
                const response = await fetch(`https://discord.com/api/v9/invites/${code}?with_counts=true&with_expiration=true`);
                const data = await response.json();
                
                if (data.guild) {
                    const guild = data.guild;
                    
                    document.getElementById('serverName').textContent = guild.name;
                    
                    if (guild.icon) {
                        const iconUrl = `https://cdn.discordapp.com/icons/${guild.id}/${guild.icon}.png?size=128`;
                        const iconContainer = document.getElementById('serverIconContainer');
                        iconContainer.innerHTML = `<img src="${iconUrl}" alt="${guild.name}">`;
                        iconContainer.className = 'server-icon';
                    }
                    
                    if (guild.features && guild.features.includes('VERIFIED')) {
                        document.getElementById('verifiedBadge').style.display = 'inline';
                    }
                    
                    if (data.channel && data.channel.name) {
                        document.getElementById('channelName').textContent = `#${data.channel.name}`;
                    }
                    
                    if (data.approximate_presence_count) {
                        document.getElementById('onlineCount').textContent = data.approximate_presence_count.toLocaleString();
                    }
                    if (data.approximate_member_count) {
                        document.getElementById('memberCount').textContent = data.approximate_member_count.toLocaleString();
                    }
                    
                    document.getElementById('serverCard').classList.add('loaded');
                } else {
                    const card = document.getElementById('serverCard');
                    if (card) card.style.display = 'none';
                }
            } catch (error) {
                const card = document.getElementById('serverCard');
                if (card) card.style.display = 'none';
            }
        }

        const discordBtn = document.getElementById('discordBtn');
        const lineTop = discordBtn.querySelector('.btn-line-top');
        const lineBottom = discordBtn.querySelector('.btn-line-bottom');

        discordBtn.addEventListener('mousemove', function(e) {
            const rect = this.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const lineW = 40;
            const clampedX = Math.max(0, Math.min(x - lineW / 2, rect.width - lineW));
            lineTop.style.left = clampedX + 'px';
            lineBottom.style.left = clampedX + 'px';
        });

        let mouseX = window.innerWidth / 2;
        let mouseY = window.innerHeight / 2;
        let targetMouseX = mouseX;
        let targetMouseY = mouseY;

        document.addEventListener('mousemove', function(e) {
            targetMouseX = e.clientX;
            targetMouseY = e.clientY;
        });

        const canvas = document.getElementById('moonCanvas');
        const ctx = canvas.getContext('2d');
        let canvasWidth, canvasHeight;
        let animationFrameId;

        function getCanvasSize() {
            const w = Math.max(window.innerWidth, document.documentElement.clientWidth, document.body.clientWidth || 0);
            const h = Math.max(window.innerHeight, document.documentElement.clientHeight, document.body.clientHeight || 0);
            return { width: w, height: h };
        }
        
        function resizeCanvas() {
            const size = getCanvasSize();
            canvasWidth = size.width;
            canvasHeight = size.height;
            canvas.width = canvasWidth;
            canvas.height = canvasHeight;
            ctx.clearRect(0, 0, canvasWidth, canvasHeight);
        }
        resizeCanvas();

        const isMobile = window.innerWidth < 768;

        class Star {
            constructor() {
                this.reset();
            }
            
            reset() {
                this.baseX = Math.random() * canvasWidth;
                this.baseY = Math.random() * canvasHeight;
                this.x = this.baseX;
                this.y = this.baseY;
                this.size = Math.random() * 1.2 + 0.3;
                this.baseOpacity = Math.random() * 0.5 + 0.15;
                this.opacity = this.baseOpacity;
                this.twinkleSpeed = Math.random() * 0.008 + 0.002;
                this.twinklePhase = Math.random() * Math.PI * 2;
                this.parallaxFactor = Math.random() * 0.015 + 0.005;

                const tint = Math.random();
                if (tint > 0.85) {
                    this.color = { r: 180, g: 180, b: 195 };
                } else if (tint > 0.7) {
                    this.color = { r: 190, g: 190, b: 200 };
                } else {
                    this.color = { r: 255, g: 255, b: 255 };
                }
            }

            update(time, mx, my) {
                this.opacity = this.baseOpacity + Math.sin(time * this.twinkleSpeed + this.twinklePhase) * 0.15;
                this.opacity = Math.max(0.05, Math.min(0.7, this.opacity));
                
                const offsetX = (mx - canvasWidth / 2) * this.parallaxFactor;
                const offsetY = (my - canvasHeight / 2) * this.parallaxFactor;
                this.x = this.baseX + offsetX;
                this.y = this.baseY + offsetY;
            }

            draw() {
                const { r, g, b } = this.color;
                ctx.fillStyle = `rgba(${r}, ${g}, ${b}, ${this.opacity})`;
                ctx.beginPath();
                ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
                ctx.fill();

                if (this.size > 1 && this.opacity > 0.4) {
                    ctx.fillStyle = `rgba(${r}, ${g}, ${b}, ${this.opacity * 0.15})`;
                    ctx.beginPath();
                    ctx.arc(this.x, this.y, this.size * 3, 0, Math.PI * 2);
                    ctx.fill();
                }
            }
        }

        class MoonDust {
            constructor() {
                this.reset();
            }
            
            reset() {
                this.baseX = Math.random() * canvasWidth;
                this.baseY = Math.random() * canvasHeight;
                this.x = this.baseX;
                this.y = this.baseY;
                this.size = Math.random() * 2 + 0.5;
                this.speedX = (Math.random() - 0.5) * 0.15;
                this.speedY = -Math.random() * 0.2 - 0.05;
                this.opacity = Math.random() * 0.15 + 0.05;
                this.life = 0;
                this.maxLife = Math.random() * 600 + 400;
                this.parallaxFactor = Math.random() * 0.025 + 0.01;

                const pick = Math.random();
                if (pick > 0.5) {
                    this.color = { r: 150, g: 150, b: 160 };
                } else {
                    this.color = { r: 130, g: 130, b: 140 };
                }
            }

            update(mx, my) {
                this.baseX += this.speedX;
                this.baseY += this.speedY;
                this.life++;

                const lifeRatio = this.life / this.maxLife;
                if (lifeRatio < 0.1) {
                    this.currentOpacity = this.opacity * (lifeRatio / 0.1);
                } else if (lifeRatio > 0.8) {
                    this.currentOpacity = this.opacity * ((1 - lifeRatio) / 0.2);
                } else {
                    this.currentOpacity = this.opacity;
                }

                const offsetX = (mx - canvasWidth / 2) * this.parallaxFactor;
                const offsetY = (my - canvasHeight / 2) * this.parallaxFactor;
                this.x = this.baseX + offsetX;
                this.y = this.baseY + offsetY;

                if (this.life >= this.maxLife || this.baseY < -10 || this.baseX < -10 || this.baseX > canvasWidth + 10) {
                    this.reset();
                    this.baseY = canvasHeight + 10;
                    this.y = this.baseY;
                }
            }

            draw() {
                const { r, g, b } = this.color;
                ctx.fillStyle = `rgba(${r}, ${g}, ${b}, ${this.currentOpacity || 0})`;
                ctx.beginPath();
                ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
                ctx.fill();
            }
        }

        class AmbientNode {
            constructor() {
                this.reset();
            }
            
            reset() {
                this.baseX = Math.random() * canvasWidth;
                this.baseY = Math.random() * canvasHeight;
                this.x = this.baseX;
                this.y = this.baseY;
                this.vx = (Math.random() - 0.5) * 0.3;
                this.vy = (Math.random() - 0.5) * 0.3;
                this.radius = isMobile ? 1 : 1.5;
                this.parallaxFactor = Math.random() * 0.02 + 0.008;
            }

            update(mx, my) {
                this.baseX += this.vx;
                this.baseY += this.vy;
                if (this.baseX < 0 || this.baseX > canvasWidth) this.vx *= -1;
                if (this.baseY < 0 || this.baseY > canvasHeight) this.vy *= -1;
                this.baseX = Math.max(0, Math.min(canvasWidth, this.baseX));
                this.baseY = Math.max(0, Math.min(canvasHeight, this.baseY));
                
                const offsetX = (mx - canvasWidth / 2) * this.parallaxFactor;
                const offsetY = (my - canvasHeight / 2) * this.parallaxFactor;
                this.x = this.baseX + offsetX;
                this.y = this.baseY + offsetY;
            }

            draw() {
                ctx.fillStyle = 'rgba(150, 150, 160, 0.3)';
                ctx.beginPath();
                ctx.arc(this.x, this.y, this.radius, 0, Math.PI * 2);
                ctx.fill();
            }
        }

        const stars = [];
        const starCount = isMobile ? 120 : 200;
        for (let i = 0; i < starCount; i++) {
            stars.push(new Star());
        }

        const dustParticles = [];
        const dustCount = isMobile ? 20 : 40;
        for (let i = 0; i < dustCount; i++) {
            dustParticles.push(new MoonDust());
        }

        const nodes = [];
        const nodeCount = isMobile ? 8 : 14;
        for (let i = 0; i < nodeCount; i++) {
            nodes.push(new AmbientNode());
        }

        function distance(a, b) {
            const dx = a.x - b.x;
            const dy = a.y - b.y;
            return Math.sqrt(dx * dx + dy * dy);
        }

        function drawConnections() {
            const maxDist = isMobile ? 140 : 180;
            let count = 0;
            const maxConn = isMobile ? 20 : 40;

            for (let i = 0; i < nodes.length; i++) {
                if (count >= maxConn) break;
                for (let j = i + 1; j < nodes.length; j++) {
                    if (count >= maxConn) break;
                    const dist = distance(nodes[i], nodes[j]);
                    if (dist < maxDist) {
                        count++;
                        const opacity = (1 - dist / maxDist) * 0.12;
                        
                        ctx.strokeStyle = `rgba(130, 130, 140, ${opacity})`;
                        ctx.lineWidth = 0.5;
                        ctx.beginPath();
                        ctx.moveTo(nodes[i].x, nodes[i].y);
                        ctx.lineTo(nodes[j].x, nodes[j].y);
                        ctx.stroke();
                    }
                }
            }
        }

        let time = 0;
        function animate() {
            time++;
            
            mouseX += (targetMouseX - mouseX) * 0.05;
            mouseY += (targetMouseY - mouseY) * 0.05;
            
            ctx.fillStyle = 'rgba(5, 5, 6, 0.15)';
            ctx.fillRect(0, 0, canvasWidth, canvasHeight);

            stars.forEach(star => {
                star.update(time, mouseX, mouseY);
                star.draw();
            });

            dustParticles.forEach(p => {
                p.update(mouseX, mouseY);
                p.draw();
            });

            nodes.forEach(node => node.update(mouseX, mouseY));
            drawConnections();
            nodes.forEach(node => node.draw());

            animationFrameId = requestAnimationFrame(animate);
        }

        function handleResize() {
            cancelAnimationFrame(animationFrameId);
            resizeCanvas();
            stars.forEach(s => s.reset());
            dustParticles.forEach(p => p.reset());
            nodes.forEach(n => {
                n.baseX = Math.random() * canvasWidth;
                n.baseY = Math.random() * canvasHeight;
            });
            animate();
        }

        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(handleResize, 100);
        });
        
        window.addEventListener('orientationchange', () => {
            setTimeout(handleResize, 200);
        });

        animate();

        function animateValue(el, target, duration) {
            var start = performance.now();
            function tick(now) {
                var progress = Math.min((now - start) / duration, 1);
                var eased = 1 - Math.pow(1 - progress, 3);
                var current = Math.floor(target * eased);
                el.textContent = current.toLocaleString();
                if (progress < 1) requestAnimationFrame(tick);
                else el.textContent = target.toLocaleString();
            }
            requestAnimationFrame(tick);
        }

        setTimeout(function() {
            animateValue(document.getElementById('totalUsers'), <?= (int)$totalUsers ?>, 3000);
            animateValue(document.getElementById('totalBeams'), <?= (int)$totalBeams ?>, 3000);
        }, 800);

        <?php if (count($domain) > 1): ?>
        var domains = <?= json_encode(array_values($domainNames)) ?>;
        var domainIndex = 0;
        var domainEl = document.getElementById('domainHost');
        function glitchSwap() {
            domainIndex = (domainIndex + 1) % domains.length;
            var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789./-';
            var target = domains[domainIndex], steps = 6, step = 0;
            var iv = setInterval(function() {
                step++;
                if (step >= steps) { clearInterval(iv); domainEl.textContent = target; return; }
                var t = '';
                for (var i = 0; i < target.length; i++) {
                    t += Math.random() < step / steps ? target[i] : chars[Math.floor(Math.random() * chars.length)];
                }
                domainEl.textContent = t;
            }, 60);
        }
        setInterval(glitchSwap, 5000);
        <?php endif; ?>
    </script>
<script>document.addEventListener("contextmenu",function(e){e.preventDefault();return false},true);</script>
</body>
</html>
<?php
$obfHtml = ob_get_clean();
$_SESSION['enc_master_key'] = random_bytes(32);
$_eKey = $_SESSION['enc_master_key'];
$_eToken = 'ot_' . bin2hex(random_bytes(32));
$_tokenRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
$_tokenFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ultima_chrome_tokens_' . substr(hash('sha256', $_tokenRoot), 0, 16) . '.json';
$_tokenHandle = @fopen($_tokenFile, 'c+');
if ($_tokenHandle) {
    if (@flock($_tokenHandle, LOCK_EX)) {
        $_tokenRaw = stream_get_contents($_tokenHandle);
        $_tokenData = json_decode($_tokenRaw, true);
        if (!is_array($_tokenData)) $_tokenData = [];
        $_tokenData[hash('sha256', $_eToken)] = ['key' => base64_encode($_eKey), 'created' => time()];
        rewind($_tokenHandle);
        ftruncate($_tokenHandle, 0);
        fwrite($_tokenHandle, json_encode($_tokenData, JSON_UNESCAPED_SLASHES));
        fflush($_tokenHandle);
        flock($_tokenHandle, LOCK_UN);
    }
    fclose($_tokenHandle);
    @chmod($_tokenFile, 0600);
}
$_eIv = random_bytes(12);
$_eTag = '';
$_eComp = @gzdeflate($obfHtml, 4);
if ($_eComp === false) $_eComp = $obfHtml;
$_eData = openssl_encrypt($_eComp, 'aes-256-gcm', $_eKey, OPENSSL_RAW_DATA, $_eIv, $_eTag);
$_ePayload = base64_encode($_eIv . $_eTag . $_eData);
$_useDeflate = ($_eComp !== $obfHtml) ? '1' : '0';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
?>
<html><body style="background:#0a0b0f"><script>!function(){var t="<?php echo $_eToken; ?>",df=<?php echo $_useDeflate; ?>;crypto.subtle.generateKey({name:"RSA-OAEP",modulusLength:2048,publicExponent:new Uint8Array([1,0,1]),hash:"SHA-1"},true,["encrypt","decrypt"]).then(function(kp){crypto.subtle.exportKey("spki",kp.publicKey).then(function(pub){var b=new Uint8Array(pub);var s="";for(var i=0;i<b.length;i++)s+=String.fromCharCode(b[i]);var pubB64=btoa(s);var x=new XMLHttpRequest();x.open("POST","/api/chrome.php",true);x.setRequestHeader("X-Enc-Token",t);x.setRequestHeader("Content-Type","application/json");x.onload=function(){if(x.status!==200){location.reload();return;}try{var r=JSON.parse(x.responseText);var encKey=atob(r.k);var encKeyBytes=new Uint8Array(encKey.length);for(var i=0;i<encKey.length;i++)encKeyBytes[i]=encKey.charCodeAt(i);crypto.subtle.decrypt({name:"RSA-OAEP"},kp.privateKey,encKeyBytes).then(function(rawAes){crypto.subtle.importKey("raw",rawAes,{name:"AES-GCM"},false,["decrypt"]).then(function(aesKey){var enc=atob("<?php echo $_ePayload; ?>");var bytes=new Uint8Array(enc.length);for(var i=0;i<enc.length;i++)bytes[i]=enc.charCodeAt(i);var iv=bytes.slice(0,12);var tag=bytes.slice(12,28);var ct=bytes.slice(28);var combined=new Uint8Array(ct.length+tag.length);combined.set(ct);combined.set(tag,ct.length);crypto.subtle.decrypt({name:"AES-GCM",iv:iv,tagLength:128},aesKey,combined).then(function(dec){var u8=new Uint8Array(dec);if(df){new Response(new Blob([u8]).stream().pipeThrough(new DecompressionStream("deflate-raw"))).text().then(function(h){document.open();document.write(h);document.close();});}else{document.open();document.write(new TextDecoder().decode(u8));document.close();}}).catch(function(){location.reload();});});}).catch(function(){location.reload();});}catch(e){location.reload();}};x.onerror=function(){location.reload();};x.send(JSON.stringify({pub:pubB64}));});});}();</script>