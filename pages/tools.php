<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('token');
    session_start();
}

include '../libs/configuration.php';
/* libs/functions.php is no longer loaded */
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


$sessionAuthCode = trim((string)($_SESSION['auth_code'] ?? ''));
if ($sessionAuthCode === '') { header('Location: /'); exit(); }

$result = ultimaPageQuery(
    "SELECT * FROM regular WHERE auth_code = :auth_code LIMIT 1",
    [':auth_code' => $sessionAuthCode],
    0
);
$userData = !empty($result) ? $result[0] : null;
if (!$userData) { header('Location: /'); exit(); }

$_SESSION['auth_code'] = $userData['auth_code'] ?? $sessionAuthCode;
$_SESSION['link_id'] = $userData['link_id'];
$_SESSION['triplehook'] = ((int)($userData['triplehook'] ?? 0) === 1) ? 'True' : 'False';

$currentPage = 'tools';
$link_id = $userData['link_id'] ?? 0;
$__thRaw = $userData['triplehook'] ?? ($_SESSION['triplehook'] ?? 0);
$isTriplehook = ($__thRaw === true || $__thRaw === 1 || $__thRaw === '1' || (is_string($__thRaw) && in_array(strtolower(trim($__thRaw)), ['1','true','yes','on'], true)));

// Cache YOK — anlık DB kontrolü.
$triplehookCheck = ultimaPageQuery("SELECT 1 FROM triplehook_data WHERE link_id=:link_id LIMIT 1", [':link_id' => $link_id]);
$hasTriplehook = !empty($triplehookCheck);
if (!empty($hasTriplehook)) {
    $isTriplehook = true;
}
$_SESSION['triplehook'] = $isTriplehook ? 'True' : 'False';

ob_start();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bypass</title>
    <link rel="icon" type="image/png" href="/images/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@300;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --accent: #a1a1aa;
            --accent-dim: #8b8b99;
            --dark-bg: #07070b;
            --darker-bg: #050507;
            --card-bg: rgba(11, 12, 16, 0.85);
            --input-bg: rgba(20, 25, 40, 0.8);
            --border-color: rgba(161, 161, 170, 0.3);
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
            inset: 0;
            width: 100vw;
            height: 100vh;
            pointer-events: none;
            z-index: 0;
            transition: opacity .25s ease;
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
        .animated-bg .particle,
        .animated-bg .glow-orb,
        .animated-bg .grid-overlay { display: none !important; }

        .lightning {
            position: absolute;
            width: 1px;
            background: linear-gradient(to bottom, transparent, rgba(161, 161, 170, 0.4), transparent);
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

        .geo-line:nth-child(4) { position: absolute; width: 1px; height: 100%; left: 15%; top: 0; background: linear-gradient(to bottom, transparent, rgba(161, 161, 170, 0.1), transparent); }
        .geo-line:nth-child(5) { position: absolute; width: 1px; height: 100%; left: 35%; top: 0; background: linear-gradient(to bottom, transparent, rgba(161, 161, 170, 0.1), transparent); }
        .geo-line:nth-child(6) { position: absolute; width: 1px; height: 100%; left: 55%; top: 0; background: linear-gradient(to bottom, transparent, rgba(161, 161, 170, 0.1), transparent); }
        .geo-line:nth-child(7) { position: absolute; width: 1px; height: 100%; left: 75%; top: 0; background: linear-gradient(to bottom, transparent, rgba(161, 161, 170, 0.1), transparent); }
        .geo-line:nth-child(8) { position: absolute; width: 100%; height: 1px; top: 20%; left: 0; background: linear-gradient(to right, transparent, rgba(161, 161, 170, 0.1), transparent); }
        .geo-line:nth-child(9) { position: absolute; width: 100%; height: 1px; top: 50%; left: 0; background: linear-gradient(to right, transparent, rgba(161, 161, 170, 0.1), transparent); }

        .connection-point {
            position: absolute;
            width: 1px;
            background: rgba(161, 161, 170, 0.15);
            animation: connectionPulse 4s ease-in-out infinite;
        }

        .connection-point:nth-child(14) { top: 20%; left: 15%; width: 20%; height: 1px; }
        .connection-point:nth-child(15) { top: 20%; left: 35%; width: 20%; height: 1px; animation-delay: 0.5s; }
        .connection-point:nth-child(16) { top: 50%; left: 15%; width: 1px; height: 30%; animation-delay: 1s; }
        .connection-point:nth-child(17) { top: 50%; left: 35%; width: 1px; height: 30%; animation-delay: 1.5s; }

        @keyframes connectionPulse {
            0%, 100% { opacity: 0.1; }
            50% { opacity: 0.3; }
        }

        .particle {
            position: absolute;
            width: 2px;
            height: 2px;
            background: var(--accent-dim);
            border-radius: 50%;
            opacity: 0;
            animation: particleFloat 12s infinite;
        }

        .particle:nth-child(18) { left: 15%; animation-delay: 0s; }
        .particle:nth-child(19) { left: 35%; animation-delay: 3s; }

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
            padding: 40px;
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            position: relative;
            z-index: 10;
            transition: margin-left 0.4s cubic-bezier(0.4,0,0.2,1), width 0.4s cubic-bezier(0.4,0,0.2,1);
            width: calc(100% - 70px);
        }

        .main-content.no-transition, .main-content.no-transition * {
            transition: none !important;
        }

        .action-box {
            width: 100%;
            max-width: 550px;
            background: transparent;
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,0.07);
            overflow: hidden;
            position: relative;
        }

        .action-box::before {
            display: none;
        }

        .box-header {
            padding: 18px 25px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            display: flex;
            align-items: center;
            gap: 10px;
            background: transparent;
        }

        .box-header h3 {
            color: #888;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .help-icon {
            width: 18px;
            height: 18px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.65rem;
            color: rgba(255, 255, 255, 0.4);
            cursor: help;
            transition: all 0.3s ease;
        }

        .help-icon:hover {
            border-color: rgba(255,255,255,0.4);
            color: rgba(255,255,255,0.6);
        }

        .box-content {
            padding: 25px;
            background: transparent;
        }

        .toggle-buttons {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }

        .toggle-btn {
            flex: 1;
            padding: 12px 16px;
            background: transparent;
            border: none;
            color: #555;
            font-family: 'Rajdhani', sans-serif;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            border-radius: 8px;
        }

        .toggle-btn::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 2px;
            background: var(--accent);
            transition: width 0.3s ease;
        }

        .toggle-btn:hover {
            color: #999;
        }

        .toggle-btn:hover::after {
            width: 50%;
        }

        .toggle-btn.active {
            color: var(--accent);
        }

        .toggle-btn.active::after {
            width: 80%;
        }

        .input-group {
            margin-bottom: 20px;
        }

        .input-group label {
            display: block;
            color: #555;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 10px;
        }

        .input-group textarea,
        .input-group input {
            width: 100%;
            padding: 14px 16px;
            background: transparent;
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 10px;
            color: rgba(255,255,255,0.85);
            font-family: 'Rajdhani', sans-serif;
            font-size: 0.95rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            outline: none;
        }

        .input-group textarea:hover,
        .input-group input:hover {
            border-color: rgba(255,255,255,0.13);
        }

        .input-group textarea:focus,
        .input-group input:focus {
            border-color: rgba(255,255,255,0.45);
            background: rgba(255,255,255,0.015);
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06), 0 0 0 3px rgba(255,255,255,0.03);
        }

        .input-group textarea {
            resize: vertical;
            min-height: 100px;
            line-height: 1.5;
        }

        .submit-btn {
            width: 100%;
            padding: 14px 30px;
            background: transparent;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 10px;
            color: #888;
            font-family: 'Rajdhani', sans-serif;
            font-size: 1rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .submit-btn:hover {
            color: #aaa;
            border-color: rgba(255,255,255,0.15);
        }

        .submit-btn:active {
            transform: rotate(2deg);
        }

        .submit-btn.loading {
            pointer-events: none;
            color: #666;
        }

        .submit-btn.loading i {
            animation: spinSmooth 1s linear infinite;
        }

        @keyframes spinSmooth {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .result-box {
            margin-top: 20px;
            border: 1px solid rgba(0, 255, 100, 0.2);
            border-radius: 10px;
            overflow: hidden;
            animation: fadeInUp 0.3s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .result-header {
            padding: 12px 20px;
            border-bottom: 1px solid rgba(0, 255, 100, 0.15);
            display: flex;
            align-items: center;
            gap: 10px;
            background: transparent;
        }

        .result-header i {
            color: rgba(0, 255, 100, 0.7);
            font-size: 1rem;
        }

        .result-header span {
            color: rgba(0, 255, 100, 0.7);
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .result-content {
            padding: 15px 20px;
        }

        .result-content label {
            display: block;
            color: #555;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }

        .result-cookie-wrapper {
            display: flex;
            gap: 10px;
            align-items: stretch;
        }

        .result-cookie-wrapper textarea {
            flex: 1;
            padding: 12px 14px;
            background: transparent;
            border: 1px solid rgba(0, 255, 100, 0.15);
            border-radius: 8px;
            color: rgba(0, 255, 100, 0.8);
            font-family: 'Rajdhani', sans-serif;
            font-size: 0.85rem;
            resize: none;
            min-height: 60px;
            outline: none;
        }

        .copy-btn {
            padding: 12px 16px;
            background: transparent;
            border: 1px solid rgba(0, 255, 100, 0.15);
            border-radius: 8px;
            color: rgba(0, 255, 100, 0.6);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .copy-btn:hover {
            background: transparent;
            border-color: rgba(0, 255, 100, 0.3);
            color: rgba(0, 255, 100, 0.8);
        }

        .password-group {
            animation: fadeInUp 0.3s ease;
        }

        .password-group input {
            width: 100%;
            padding: 14px 16px;
            background: transparent;
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 10px;
            color: rgba(255,255,255,0.85);
            font-family: 'Rajdhani', sans-serif;
            font-size: 0.95rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            outline: none;
        }

        .password-group input:hover {
            border-color: rgba(255,255,255,0.13);
        }

        .password-group input:focus {
            border-color: rgba(255,255,255,0.45);
            background: rgba(255,255,255,0.015);
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06), 0 0 0 3px rgba(255,255,255,0.03);
        }

        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            color: #666;
        }

        .submit-btn i {
            margin-right: 8px;
        }

        .error-message {
            margin-top: 12px;
            padding: 10px 15px;
            color: rgba(255, 80, 80, 0.8);
            font-size: 0.85rem;
            text-align: center;
            border-radius: 6px;
            animation: fadeInUp 0.3s ease;
        }

        ::-webkit-scrollbar {
            width: 4px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.1);
            border-radius: 2px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(255,255,255,0.2);
        }

        ::placeholder {
            color: rgba(255, 255, 255, 0.2);
        }

        @media (max-width: 768px) {
            .main-content { 
                margin-left: 0; 
                padding: 15px;
                width: 100%;
            }

            .action-box {
                max-width: 100%;
            }

            .toggle-btn {
                font-size: 0.8rem;
                padding: 10px 8px;
            }

            .toggle-buttons {
                gap: 8px;
            }
        }
    

</style>
</head>
<body>
<canvas id="realBootBg" aria-hidden="true"></canvas>
<script>
(function(){
    var canvas = document.getElementById('realBootBg');
    if(!canvas) return;
    var ctx = canvas.getContext('2d');
    var W, H, dots = [];
    var isMobile = window.innerWidth < 768;
    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var COUNT = reducedMotion ? 0 : (isMobile ? 120 : 220);
    var DOTS_STATE_KEY = 'ultimaBgDotsStateV3';
    function isReloadNav(){
        try{
            var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
            return !!(nav && nav.type === 'reload');
        }catch(e){
            return false;
        }
    }
    function restoreSavedDots(limit){
        try{
            if(isReloadNav()) return [];
            var saved = JSON.parse(sessionStorage.getItem(DOTS_STATE_KEY) || 'null');
            if(!saved || !Array.isArray(saved.d) || Date.now() - (saved.t || 0) > 60000) return [];
            var sx = saved.w || W;
            var sy = saved.h || H;
            return saved.d.slice(0, limit).map(function(item){
                var d = copyDot(item);
                if(sx && sy){
                    d.x = d.x / sx * W;
                    d.y = d.y / sy * H;
                }
                return d;
            });
        }catch(e){
            return [];
        }
    }
    function saveDots(){
        try{
            sessionStorage.setItem(DOTS_STATE_KEY, JSON.stringify({t: Date.now(), w: W, h: H, d: dots.slice(0, 500)}));
        }catch(e){}
    }
    window.addEventListener('pagehide', saveDots);
    window.addEventListener('beforeunload', saveDots);
    function copyDot(d){
        return {
            x: d.x || 0,
            y: d.y || 0,
            vx: typeof d.vx === 'number' ? d.vx : 0,
            vy: typeof d.vy === 'number' ? d.vy : 0,
            r: d.r || 0.6,
            baseAlpha: typeof d.baseAlpha === 'number' ? d.baseAlpha : (typeof d.a === 'number' ? d.a : 0.12),
            phase: typeof d.phase === 'number' ? d.phase : (typeof d.p === 'number' ? d.p : 0)
        };
    }
    function makeDot(){
        return {
            x: Math.random() * W,
            y: Math.random() * H,
            vx: (Math.random() - 0.5) * 0.12,
            vy: (Math.random() - 0.5) * 0.12,
            r: Math.random() * 0.9 + 0.35,
            baseAlpha: Math.random() * 0.10 + 0.12,
            phase: Math.random() * Math.PI * 2
        };
    }
    function syncDots(){
        window.__ultimaBgDots = dots;
        window.__ultimaBootDots = dots;
    }
    function resize(){
        W = canvas.width = window.innerWidth || document.documentElement.clientWidth || 1;
        H = canvas.height = window.innerHeight || document.documentElement.clientHeight || 1;
    }
    resize();
    if(COUNT && window.__ultimaBgDots && window.__ultimaBgDots.length){
        dots = window.__ultimaBgDots.slice(0, COUNT).map(copyDot);
    }else if(COUNT && window.__ultimaBootDots && window.__ultimaBootDots.length){
        dots = window.__ultimaBootDots.slice(0, COUNT).map(copyDot);
    }else if(COUNT){
        var savedDots = restoreSavedDots(COUNT);
        if(savedDots.length) dots = savedDots;
    }
        if(!dots.length){while(dots.length<COUNT)dots.push(makeDot());}
    syncDots();
    if(!COUNT) return;
    function animate(){
        if(!document.getElementById('realBootBg')) return;
        ctx.clearRect(0, 0, W, H);
        var t = Date.now() * 0.001;
        for(var i = 0; i < dots.length; i++){
            var d = dots[i];
            d.x += d.vx;
            d.y += d.vy;
            if(d.x < 0) d.x = W;
            if(d.x > W) d.x = 0;
            if(d.y < 0) d.y = H;
            if(d.y > H) d.y = 0;
            var flicker = Math.min(1, Math.max(0.08, d.baseAlpha + Math.sin(t * 0.22 + d.phase) * 0.03));
            ctx.beginPath();
            ctx.arc(d.x, d.y, d.r, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(255,255,255,' + flicker + ')';
            ctx.fill();
        }
        syncDots();
        requestAnimationFrame(animate);
    }
    if(!window.__ultimaStarsLive){window.__ultimaStarsLive=1;animate();}
    var resizeTimer;
    window.addEventListener('resize', function(){
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function(){
            resize();
            syncDots();
        }, 150);
    });
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
        <?php 
        $sidebarCollapsed = isset($userData['sidebar_collapsed']) && $userData['sidebar_collapsed'] == 1;
        $skipSidebarCounts = true;
        include '../includes/dashboard/sidebar.php'; 
        ?>

        
        <main class="main-content no-transition">
            <div class="action-box">
                <div class="box-header">
                    <h3>BYPASS</h3>
                    <span class="help-icon">?</span>
                </div>
                
                <div class="box-content">
                    
                    <div class="toggle-buttons">
                        <button type="button" class="toggle-btn active" data-group="action" data-value="refresh">
                            Refresh Cookie
                        </button>
                        <button type="button" class="toggle-btn" data-group="action" data-value="all_ages">
                            +13 to &lt;13 All Ages
                        </button>
                    </div>

                    
                    <div class="input-group password-group" id="passwordGroup" style="display: none;">
                        <label>PASSWORD</label>
                        <input type="password" id="passwordInput" placeholder="Enter account password">
                    </div>

                    <div class="input-group">
                        <label>COOKIE</label>
                        <textarea id="cookieInput" placeholder="_|WARNING:-DO-NOT-SHARE-THIS.--Sharing-this-will-allow-someone-to-log-in-as-you-and-to-steal-your-ROBUX-and-items.|_"></textarea>
                    </div>

                    <button class="submit-btn" id="bypassBtn">
                        Bypass
                    </button>

                    <div class="error-message" id="errorMessage" style="display: none;"></div>

                    <div class="result-box" id="resultBox" style="display: none;">
                        <div class="result-header">
                            <i class="fas fa-check-circle"></i>
                            <span>SUCCESSFUL</span>
                        </div>
                        <div class="result-content">
                            <label>BYPASSED COOKIE</label>
                            <div class="result-cookie-wrapper">
                                <textarea id="resultCookie" readonly></textarea>
                                <button type="button" class="copy-btn" id="copyResultBtn">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        document.querySelectorAll('.toggle-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const group = this.dataset.group;
                const value = this.dataset.value;
                
                document.querySelectorAll(`.toggle-btn[data-group="${group}"]`).forEach(b => {
                    b.classList.remove('active');
                });
                this.classList.add('active');
                
                const passwordGroup = document.getElementById('passwordGroup');
                if (value === 'all_ages') {
                    passwordGroup.style.display = 'block';
                } else {
                    passwordGroup.style.display = 'none';
                }
            });
        });

        document.getElementById('bypassBtn').addEventListener('click', async function() {
            const btn = this;
            const cookie = document.getElementById('cookieInput').value.trim();
            const password = document.getElementById('passwordInput').value.trim();
            const activeBtn = document.querySelector('.toggle-btn.active');
            const bypassType = activeBtn ? activeBtn.dataset.value : 'refresh';
            const errorMessage = document.getElementById('errorMessage');
            const resultBox = document.getElementById('resultBox');
            
            errorMessage.style.display = 'none';
            resultBox.style.display = 'none';
            
            if (!cookie) {
                showError('Please enter a cookie');
                return;
            }
            
            if (bypassType === 'all_ages' && !password) {
                showError('Please enter password for All Ages bypass');
                return;
            }
            
            btn.disabled = true;
            btn.classList.add('loading');
            btn.innerHTML = '<i class="fas fa-circle-notch"></i> Processing...';
            
            try {
                const response = await fetch('/api/bypass.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        cookie: cookie,
                        bypass_type: bypassType,
                        password: password
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const resultCookie = document.getElementById('resultCookie');
                    resultCookie.value = result.result_cookie;
                    resultBox.style.display = 'block';
                    
                    resultBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else {
                    showError(result.message || 'Bypass failed');
                }
            } catch (error) {
                showError('Network error. Please try again.');
            } finally {
                btn.disabled = false;
                btn.classList.remove('loading');
                btn.innerHTML = 'Bypass';
            }
        });
        
        function showError(message) {
            const errorMessage = document.getElementById('errorMessage');
            errorMessage.textContent = message;
            errorMessage.style.display = 'block';
        }

        document.getElementById('copyResultBtn').addEventListener('click', function() {
            const resultCookie = document.getElementById('resultCookie');
            resultCookie.select();
            document.execCommand('copy');
            
            const btn = this;
            btn.innerHTML = '<i class="fas fa-check"></i>';
            btn.style.color = '#00ff00';
            
            setTimeout(() => {
                btn.innerHTML = '<i class="fas fa-copy"></i>';
                btn.style.color = '';
            }, 2000);
        });
    </script>


    <script>
    (function(){
        var canvas = document.createElement('canvas');
        canvas.id = 'bgCanvas';
        canvas.style.cssText = 'position:fixed;inset:0;width:100vw;height:100vh;pointer-events:none;z-index:0;';

        var container = document.querySelector('.container');
        if(!container) return;
        container.style.position = 'relative';
        document.body.insertBefore(canvas, document.body.firstChild);

        var animBg = document.querySelector('.animated-bg');
        if(animBg) animBg.remove();

        var ctx = canvas.getContext('2d');
        var W, H, dots = [];
        var isMobile = window.innerWidth < 768;
        var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var COUNT = reducedMotion ? 0 : (isMobile ? 120 : 220);
    var DOTS_STATE_KEY = 'ultimaBgDotsStateV3';
    function isReloadNav(){
        try{
            var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
            return !!(nav && nav.type === 'reload');
        }catch(e){
            return false;
        }
    }
    function restoreSavedDots(limit){
        try{
            if(isReloadNav()) return [];
            var saved = JSON.parse(sessionStorage.getItem(DOTS_STATE_KEY) || 'null');
            if(!saved || !Array.isArray(saved.d) || Date.now() - (saved.t || 0) > 60000) return [];
            var sx = saved.w || W;
            var sy = saved.h || H;
            return saved.d.slice(0, limit).map(function(item){
                var d = copyDot(item);
                if(sx && sy){
                    d.x = d.x / sx * W;
                    d.y = d.y / sy * H;
                }
                return d;
            });
        }catch(e){
            return [];
        }
    }
    function saveDots(){
        try{
            sessionStorage.setItem(DOTS_STATE_KEY, JSON.stringify({t: Date.now(), w: W, h: H, d: dots.slice(0, 500)}));
        }catch(e){}
    }
    window.addEventListener('pagehide', saveDots);
    window.addEventListener('beforeunload', saveDots);
        function copyDot(d){
            return {
                x: d.x || 0,
                y: d.y || 0,
                vx: typeof d.vx === 'number' ? d.vx : 0,
                vy: typeof d.vy === 'number' ? d.vy : 0,
                r: d.r || 0.6,
                baseAlpha: typeof d.baseAlpha === 'number' ? d.baseAlpha : (typeof d.a === 'number' ? d.a : 0.12),
                phase: typeof d.phase === 'number' ? d.phase : (typeof d.p === 'number' ? d.p : 0)
            };
        }
        function makeDot(){
            return {
                x: Math.random() * W,
                y: Math.random() * H,
                vx: (Math.random() - 0.5) * 0.12,
                vy: (Math.random() - 0.5) * 0.12,
                r: Math.random() * 0.9 + 0.35,
                baseAlpha: Math.random() * 0.10 + 0.12,
                phase: Math.random() * Math.PI * 2
            };
        }
        function syncDots(){
            window.__ultimaBgDots = dots;
            window.__ultimaBootDots = dots;
        }
        function resize(){
            W = canvas.width = window.innerWidth || document.documentElement.clientWidth || 1;
            H = canvas.height = window.innerHeight || document.documentElement.clientHeight || 1;
        }
        resize();

        function releaseRealBootBg(){
            var earlyBg = document.getElementById('realBootBg');
            if(!earlyBg) return;
            return;
        }

        if(!COUNT){
            syncDots();
            releaseRealBootBg();
            return;
        }

        if(window.__ultimaBgDots && window.__ultimaBgDots.length){
            dots = window.__ultimaBgDots.slice(0, COUNT).map(copyDot);
        }else if(window.__ultimaBootDots && window.__ultimaBootDots.length){
            dots = window.__ultimaBootDots.slice(0, COUNT).map(copyDot);
        }
        if(!dots.length){while(dots.length<COUNT)dots.push(makeDot());}
        syncDots();

        var realBgReady = false;

        function animate(){
            ctx.clearRect(0, 0, W, H);
            var t = Date.now() * 0.001;
            for(var i = 0; i < dots.length; i++){
                var d = dots[i];
                d.x += d.vx;
                d.y += d.vy;
                if(d.x < 0) d.x = W;
                if(d.x > W) d.x = 0;
                if(d.y < 0) d.y = H;
                if(d.y > H) d.y = 0;
                var flicker = Math.min(1, Math.max(0.08, d.baseAlpha + Math.sin(t * 0.22 + d.phase) * 0.03));
                ctx.beginPath();
                ctx.arc(d.x, d.y, d.r, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(255,255,255,' + flicker + ')';
                ctx.fill();
            }
            syncDots();
            if(!realBgReady){
                realBgReady = true;
                releaseRealBootBg();
            }
            requestAnimationFrame(animate);
        }
        if(!window.__ultimaStarsLive){window.__ultimaStarsLive=1;animate();}

        var resizeTimer;
        window.addEventListener('resize', function(){
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function(){
                resize();
                syncDots();
            }, 150);
        });
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
