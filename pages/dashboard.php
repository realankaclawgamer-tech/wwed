<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('token');
    session_start();
}

require_once dirname(__DIR__) . '/libs/configuration.php';
/* libs/functions.php is no longer loaded */
require_once dirname(__DIR__) . '/libs/connection.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('token');
    session_start();
}
// Kısa TTL (5 sn) cache — sayfa yüklemesinin ağır COUNT/subquery'lerinde
// kasmayı önler. WebSocket zaten 2 sn'de bir anlık push ediyor, bu yüzden
// kullanıcı 5 sn eski görüntüyü göremez; sadece ilk render'da hızlanır.
if (!defined('ULTIMA_QUERY_CACHE_TTL')) define('ULTIMA_QUERY_CACHE_TTL', 5);

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

    $cacheKey = null;
    $isSelect = preg_match('/^\s*SELECT\b/i', $sql);
    $cacheTtl = $ttl !== null ? (int)$ttl : (defined('ULTIMA_QUERY_CACHE_TTL') ? (int)ULTIMA_QUERY_CACHE_TTL : 0);
    if ($cacheTtl > 0 && $isSelect && session_status() === PHP_SESSION_ACTIVE) {
        $heavySelect = preg_match('/\b(COUNT|SUM|GROUP BY|UNION|JOIN)\b/i', $sql) || preg_match('/\bFROM\s+(hits|views|login_clicks)\b/i', $sql);
        $skipCache = preg_match('/\bFROM\s+regular\s+WHERE\s+(link_id|auth_code|discord_id|discord_username)\b/i', $sql);
        if ($heavySelect && !$skipCache) {
            $cacheKey = hash('sha256', $sql . '|' . json_encode($params));
            $bucket = $_SESSION['ultima_page_query_cache'] ?? [];
            if (isset($bucket[$cacheKey]) && is_array($bucket[$cacheKey]) && time() - ($bucket[$cacheKey]['time'] ?? 0) <= $cacheTtl && array_key_exists('value', $bucket[$cacheKey])) {
                return $bucket[$cacheKey]['value'];
            }
        }
    }

    try {
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($cacheKey !== null && session_status() === PHP_SESSION_ACTIVE) {
            if (!isset($_SESSION['ultima_page_query_cache']) || !is_array($_SESSION['ultima_page_query_cache'])) {
                $_SESSION['ultima_page_query_cache'] = [];
            }
            if (count($_SESSION['ultima_page_query_cache']) > 80) {
                array_shift($_SESSION['ultima_page_query_cache']);
            }
            $_SESSION['ultima_page_query_cache'][$cacheKey] = ['time' => time(), 'value' => $rows];
        }
        return $rows;
    } catch (PDOException $e) {
        return [];
    }
}
}

if (!function_exists('getTriplehookScopeIdsCached')) {
function getTriplehookScopeIdsCached($linkId) {
    // Kısa TTL (15 sn) cache — triplehook scope nadir değişir, her sayfa
    // yüklemesinde bu UNION sorgusu yavaş olduğundan cache tutmaya değer.
    $cacheKey = (string)$linkId . ':v8';
    $now = time();
    if (isset($_SESSION['triplehook_scope_ids_cache'][$cacheKey]) && is_array($_SESSION['triplehook_scope_ids_cache'][$cacheKey])) {
        $cached = $_SESSION['triplehook_scope_ids_cache'][$cacheKey];
        if ($now - ($cached['time'] ?? 0) <= 15 && is_array($cached['ids'] ?? null)) {
            return $cached['ids'];
        }
    }
    $rows = ultimaPageQuery(
        "SELECT link_id FROM regular WHERE referred_by = :link_id
         UNION
         SELECT r2.link_id FROM regular r2
         INNER JOIN (
            SELECT td.link_id FROM triplehook_data td
            INNER JOIN regular r ON r.link_id = td.link_id
            WHERE r.referred_by = :link_id2
            LIMIT 2
         ) th ON r2.referred_by = th.link_id",
        [':link_id' => $linkId, ':link_id2' => $linkId],
        0
    );
    $ids = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (isset($row['link_id'])) {
                $ids[] = (int)$row['link_id'];
            }
        }
    }
    $ids = array_values(array_unique(array_filter($ids)));
    $_SESSION['triplehook_scope_ids_cache'][$cacheKey] = ['time' => $now, 'ids' => $ids];
    return $ids;
}
}
function encryptStart(){}
function encryptEnd($blockId){}
function getEncToken(){return '';}

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
        $GLOBALS['ultimaEarlyChrome'] = true;
        echo '<div id="bootChrome">';
        if ($headerFile && is_file($headerFile)) {
            include $headerFile;
        }
        echo '<div class="container boot-container">';
        if ($sidebarFile && is_file($sidebarFile)) {
            include $sidebarFile;
        }
        echo '<main class="main-content no-transition"></main></div></div>';
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        echo '<script>(function(){var k=', $keyJson, ',p=', $payJson, ';window.__ultimaXorKey=k;function dec(){var b=atob(p),o=new Array(b.length),kl=k.length;for(var i=0;i<b.length;i++)o[i]=String.fromCharCode(b.charCodeAt(i)^k.charCodeAt(i%kl));return o.join("")}function chrome(n){if(!n||n.nodeType!==1)return false;var id=n.id||"",tag=(n.tagName||"").toLowerCase(),cls=typeof n.className==="string"?n.className:"";if(id==="bootChrome"||id==="bootBg"||id==="realBootBg"||id==="bgCanvas"||id==="sidebar"||id==="sidebar-overlay"||id==="mobile-menu-btn"||id==="triplehookHeader")return true;if(tag==="header"||tag==="nav")return true;if(tag==="canvas"&&(id==="bootBg"||id==="realBootBg"||id==="bgCanvas"||id==="ultimaStars"))return true;if(/\b(dock-nav|mobile-dock-btn|dock-overlay|animated-bg)\b/.test(cls))return true;return false}function skipStar(tx){return /realBootBg|__ultimaStarsLive|ultimaBgDotsStateV3|bgCanvas/.test(tx||"")}function apply(h){try{var doc=new DOMParser().parseFromString(h,"text/html");document.title=doc.title||document.title;var newMain=doc.querySelector("main.main-content")||doc.querySelector("main");var curMain=document.querySelector("#bootChrome main.main-content")||document.querySelector("main");if(!newMain||!curMain){return}curMain.innerHTML=newMain.innerHTML;function dropDupes(){["sidebar","sidebar-overlay","mobile-menu-btn","triplehookHeader"].forEach(function(id){var nodes=document.querySelectorAll("#"+id);if(nodes.length<2)return;var keep=document.querySelector("#bootChrome #"+id)||nodes[0];[].slice.call(nodes).forEach(function(n){if(n!==keep&&n.parentNode)n.parentNode.removeChild(n)})});var docks=document.querySelectorAll("nav.dock-nav,.dock-nav");if(docks.length>1){var keep=document.querySelector("#bootChrome .dock-nav")||docks[0];[].slice.call(docks).forEach(function(n){if(n!==keep&&n.parentNode)n.parentNode.removeChild(n)})}}dropDupes();var b=document.body;[].slice.call(doc.body.childNodes).forEach(function(n){if(!n||n.nodeType!==1||chrome(n))return;var tag=(n.tagName||"").toLowerCase(),cls=typeof n.className==="string"?n.className:"";if(tag==="main")return;if(/\bcontainer\b/.test(cls)&&n.querySelector&&n.querySelector("main,.dock-nav,#sidebar"))return;b.appendChild(document.importNode(n,true))});var ss=[];function grab(root){if(!root)return;if(root.tagName&&root.tagName.toLowerCase()==="script"){ss.push(root);return}if(root.querySelectorAll){[].slice.call(root.querySelectorAll("script")).forEach(function(s){ss.push(s)})}}grab(curMain);[].slice.call(b.childNodes).forEach(function(n){if(n.id==="bootChrome"||n.id==="bootBg"||chrome(n))return;grab(n)});ss=ss.filter(function(s){var src=s.src||"";if(src.indexOf("chart.js")!==-1&&window.Chart)return false;return !skipStar(s.textContent||"")});ss.sort(function(a,b){return ((a.src||"")?0:1)-((b.src||"")?0:1)});var oldAEL=document.addEventListener,baseAEL=oldAEL.bind(document);document.addEventListener=function(type,fn,opts){if(type==="DOMContentLoaded"&&document.readyState!=="loading"){setTimeout(function(){try{fn.call(document,new Event("DOMContentLoaded"))}catch(e){}},0);return}return baseAEL(type,fn,opts)};(function run(i){if(i>=ss.length){document.addEventListener=oldAEL;setTimeout(function(){function bootChart(){try{var cards=document.querySelectorAll(".overview-card .chart-container,.chart-container");for(var i=0;i<cards.length;i++){var box=cards[i],cv=box.querySelector("canvas");if(!cv)continue;var w=box.clientWidth||box.offsetWidth||0,h=box.clientHeight||box.offsetHeight||235;if(w<10)w=box.parentElement?box.parentElement.clientWidth:0;if(w>10){cv.style.width=w+"px";cv.style.height=Math.max(h,220)+"px";cv.width=w;cv.height=Math.max(h,220);}if(window.Chart&&typeof Chart.getChart==="function"){var old=Chart.getChart(cv);if(old&&old.destroy)old.destroy()}}window.dispatchEvent(new Event("resize"));if(typeof OverviewChart!=="undefined"&&OverviewChart.init)OverviewChart.init();else if(typeof Dashboard!=="undefined"&&Dashboard.init)Dashboard.init()}catch(e){}}requestAnimationFrame(function(){requestAnimationFrame(bootChart)})},80);return}var old=ss[i],s=document.createElement("script");[].slice.call(old.attributes).forEach(function(a){s.setAttribute(a.name,a.value)});if(old.src){s.async=false;s.onload=s.onerror=function(){run(i+1)};}else{s.text=old.textContent}try{old.parentNode?old.parentNode.replaceChild(s,old):document.body.appendChild(s)}catch(e){}if(!old.src)run(i+1)})(0)}catch(e){}}apply(dec())})();</script></body></html>';
    }
}

function dashRemember($key, $ttl, $loader){
    // Kısa TTL cache — sayfa yükleme hızını korur; WS yayını 2 sn'de bir
    // yaptığı için 5 sn'lik ilk render cache'i kullanıcı fark edemez.
    $ttl = min((int)$ttl, 5);
    $bucket = 'dashboard_runtime_cache';
    $now = time();
    if (isset($_SESSION[$bucket][$key]) && is_array($_SESSION[$bucket][$key]) && $now - ($_SESSION[$bucket][$key]['time'] ?? 0) <= $ttl) {
        return $_SESSION[$bucket][$key]['value'];
    }
    $value = $loader();
    $_SESSION[$bucket][$key] = ['time' => $now, 'value' => $value];
    return $value;
}
function dashIncludeCached($key, $file, $ttl, $vars = []){
    // Dashboard bileşen HTML'i kısa süreli (5 sn) cache'lenir. WS akışı
    // component içine yazan JS'i tetikler, cache stale kalmadan güncellenir.
    $ttl = min((int)$ttl, 5);
    $bucket = 'dashboard_block_cache';
    $mode = !empty($GLOBALS['isTriplehook']) ? 'th' : 'regular';
    $link = (string)($GLOBALS['link_id'] ?? '');
    $cacheKey = 'v3:' . $link . ':' . $mode . ':' . $key;
    $now = time();
    if (isset($_SESSION[$bucket][$cacheKey]) && is_array($_SESSION[$bucket][$cacheKey]) && $now - ($_SESSION[$bucket][$cacheKey]['time'] ?? 0) <= $ttl && trim((string)($_SESSION[$bucket][$cacheKey]['html'] ?? '')) !== '') {
        echo $_SESSION[$bucket][$cacheKey]['html'];
        return;
    }
    if (!is_file($file)) return;
    ob_start();
    if (is_array($vars)) extract($vars, EXTR_SKIP);
    require $file;
    $html = ob_get_clean();
    if (trim($html) !== '') {
        $_SESSION[$bucket][$cacheKey] = ['time' => $now, 'html' => $html];
    }
    echo $html;
}

$sessionAuthCode = trim((string)($_SESSION['auth_code'] ?? ''));
if ($sessionAuthCode === '') { session_unset(); session_destroy(); header('Location: /'); exit(); }

$result = ultimaPageQuery(
    "SELECT * FROM regular WHERE auth_code = :auth_code LIMIT 2",
    [':auth_code' => $sessionAuthCode],
    0
);
$userData = is_array($result) && count($result) === 1 && is_array($result[0]) ? $result[0] : null;
if (!$userData || !hash_equals((string)($userData['auth_code'] ?? ''), $sessionAuthCode) || (int)($userData['link_id'] ?? 0) <= 0) { session_unset(); session_destroy(); header('Location: /'); exit(); }

$_SESSION['auth_code'] = $userData['auth_code'] ?? $sessionAuthCode;
$_SESSION['triplehook'] = ((int)($userData['triplehook'] ?? 0) === 1) ? 'True' : 'False';


$displayAvatar = $_SESSION['discord_avatar'] ?? $userData['discord_avatar'] ?? '';
$displayUsername = $_SESSION['discord_username'] ?? $userData['discord_username'] ?? '';

if(isset($userData['avatar_hidden']) && $userData['avatar_hidden'] == 1){
    $displayAvatar = 'https://app.ultima.cl/images/hide.png';
    $displayUsername = 'Anonymous';
}

$currentPage='dashboard';
$link_id=$userData['link_id']??0;
$dashboardIncludeDir=dirname(__DIR__).'/includes/dashboard';
$privateServerLinkCode=$userData['privateServerLinkCode']??'';
$directory=$userData['directory']??'';
$__thRaw = $userData['triplehook'] ?? ($_SESSION['triplehook'] ?? 0);
$isTriplehook = ($__thRaw === true || $__thRaw === 1 || $__thRaw === '1' || (is_string($__thRaw) && in_array(strtolower(trim($__thRaw)), ['1','true','yes','on'], true)));

// Cache YOK — anlık DB kontrolü.
$triplehookCheck = ultimaPageQuery("SELECT 1 FROM triplehook_data WHERE link_id=:link_id LIMIT 1", [':link_id' => $link_id]);
$hasTriplehook = !empty($triplehookCheck);
if (!empty($hasTriplehook)) {
    $isTriplehook = true;
}
$_SESSION['triplehook'] = $isTriplehook ? 'True' : 'False';


$triplehookScopeIds = $isTriplehook ? getTriplehookScopeIdsCached($link_id) : [$link_id];
$GLOBALS['triplehookScopeIds'] = $triplehookScopeIds;
$GLOBALS['isTriplehook'] = $isTriplehook;
$GLOBALS['link_id'] = $link_id;
$leaderboardResult = [];
$chartResult = [];
$liveHitsResult = [];
$summaryData = [0, 0, 0, 0, 0, 0, 0];
$rapData = [0, 0, 0, 0, 0, 0, 0];
$balanceData = [0, 0, 0, 0, 0, 0, 0];
date_default_timezone_set('UTC');
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <style>html,body{background:#07070b}#realBootBg,#bootBg{position:fixed;inset:0;width:100vw;height:100vh;pointer-events:none;z-index:0;opacity:1}</style>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
    <title>Dashboard</title>
    <link rel="icon" type="image/png" href="/images/favicon.png">
    <link rel="apple-touch-icon" href="/images/favicon.png">
    <link rel="icon" sizes="192x192" href="/images/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Rajdhani:wght@300;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script>
    if(sessionStorage.getItem('dashboardScroll')!==null){
        document.documentElement.style.scrollBehavior='auto';
        history.scrollRestoration='manual';
    }
    </script>
    <style>
:root{
    --gold:#d4a843;
    --gold-soft:rgba(212,168,67,.5);
    --gold-faint:rgba(212,168,67,.18);
    --gold-glow:rgba(212,168,67,.08);
    --dark-bg:#07070b;
    --darker-bg:#050507;
    --card-bg:transparent;
    --primary-gold:#d4a843;
    --secondary-gold:rgba(212,168,67,.7);
    --accent-orange:#d4a843;
    --neon-yellow:#d4a843;
    --border:rgba(255,255,255,.07);
    --border-hover:rgba(255,255,255,.13);
    --text:rgba(255,255,255,.85);
    --text-dim:rgba(255,255,255,.45);
    --text-faint:rgba(255,255,255,.22);
}
*{margin:0;padding:0;box-sizing:border-box}
html,body{height:auto;overflow-x:hidden;max-width:100vw}
html{overflow-x:hidden}
body{font-family:'Rajdhani',sans-serif;background:var(--dark-bg);color:var(--text);min-height:100vh;position:relative;overflow-x:hidden;font-weight:500}
@keyframes shimmer{0%{background-position:200% center}100%{background-position:-200% center}}
@keyframes borderShimmer{0%,100%{border-color:rgba(255,255,255,.04)}50%{border-color:rgba(255,255,255,.08)}}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.container{display:flex;min-height:100vh;position:relative;overflow-x:hidden;max-width:100%}
.main-content{margin-left:70px;padding:40px;padding-bottom:20px;flex:1;position:relative;z-index:2;transition:margin-left 0.4s cubic-bezier(0.4,0,0.2,1),width 0.4s cubic-bezier(0.4,0,0.2,1);width:calc(100% - 70px);min-width:0}
.enc-block{display:none}
#realBootBg{position:fixed;inset:0;width:100vw;height:100vh;pointer-events:none;z-index:0;opacity:1}
.profile-modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;opacity:0;visibility:hidden}
.profile-modal-loading{display:none}
.main-content.no-transition,.main-content.no-transition *{transition:none!important}
.top-stats-grid{transition:all 0.4s cubic-bezier(0.4,0,0.2,1)}
.bottom-section-grid{display:grid;grid-template-columns:1.2fr 1fr;gap:24px;margin-bottom:24px;transition:all 0.4s cubic-bezier(0.4,0,0.2,1)}
.live-section-grid{display:grid;grid-template-columns:1.8fr 1.2fr;gap:24px;margin-bottom:24px;align-items:start;transition:all 0.4s cubic-bezier(0.4,0,0.2,1)}
.card,.top-stat-card,.overview-card,.leaderboards-card{transition:border-color 0.4s ease, transform 0.4s ease}
.bottom-section-grid .overview-card{max-height:440px}
.bottom-section-grid .overview-card .chart-container{min-height:235px}
.bottom-section-grid .leaderboards-card{max-height:440px;overflow:hidden}
.stat-divider{display:none}


.copy-modal{position:fixed;bottom:-100px;left:50%;transform:translateX(-50%);background:transparent;padding:14px 28px 14px 22px;border-radius:0;border:none;box-shadow:none;opacity:0;display:flex;align-items:center;gap:18px;transition:bottom 0.5s cubic-bezier(0.16,1,0.3,1), opacity 0.5s ease;z-index:1000;white-space:nowrap}
.copy-modal::before{content:'';position:absolute;left:0;top:50%;width:1px;height:32px;background:linear-gradient(180deg,transparent,var(--gold-soft),transparent);transform:translateY(-50%)}
.copy-modal.active{bottom:40px;opacity:1}
.copy-modal i{color:var(--gold);font-size:0.85rem;opacity:.75;filter:none;flex-shrink:0}
.copy-modal p{font-size:0.82rem;font-weight:500;color:var(--text);letter-spacing:.4px;font-family:'Rajdhani',sans-serif}
.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(350px,1fr));gap:24px;margin-bottom:0}
.card{background:transparent;padding:28px;border-radius:18px;border:1px solid var(--border);transition:border-color 0.5s ease;position:relative;overflow:hidden;animation:fadeUp 0.6s cubic-bezier(0.16,1,0.3,1) both}
.card::before{display:none}
.card:hover{border-color:var(--border-hover)}
.live-hits-card.card:hover{border-color:var(--border-hover);box-shadow:none}
.card h2,.card h3{font-family:'Rajdhani',sans-serif;color:var(--gold);margin-bottom:25px;font-size:1.8rem;font-weight:700;display:flex;align-items:center;gap:12px;position:relative;z-index:1}
.card h2 i,.card h3 i{font-size:1.6rem;color:var(--gold);opacity:.85}
.link-group{margin:22px 0;padding:20px;background:transparent;border-radius:14px;border:1px solid var(--border);position:relative;z-index:1}
.link-group h3{color:var(--gold);margin-bottom:18px;font-size:1.4rem;opacity:.85;letter-spacing:0}
.link-item{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;margin:10px 0;background:rgba(255,255,255,0.01);border:1px solid var(--border);border-radius:10px;cursor:pointer;transition:border-color 0.3s ease, background 0.3s ease, transform 0.3s ease;position:relative;overflow:hidden}
.link-item::before{content:'';position:absolute;left:0;top:0;width:1px;height:100%;background:var(--gold);opacity:0;transition:opacity 0.3s ease;transform:none}
.link-item:hover{transform:translateX(4px);border-color:var(--border-hover);box-shadow:none;background:rgba(255,255,255,0.02)}
.link-item:hover::before{opacity:.5;transform:none}
.link-item span{font-size:0.92rem;color:var(--text);font-weight:500;letter-spacing:.3px}
.copy-btn{background:transparent;border:1px solid var(--gold-faint);color:var(--gold);padding:8px 14px;border-radius:8px;cursor:pointer;transition:border-color 0.3s ease, background 0.3s ease, color 0.3s ease;font-size:0.85rem;flex-shrink:0;letter-spacing:1px}
.copy-btn:hover{background:var(--gold-glow);color:#e8c668;transform:none;box-shadow:none;border-color:var(--gold-soft)}

@media(max-width:1200px){
    .bottom-section-grid{grid-template-columns:1fr}
    .bottom-section-grid .overview-card,.bottom-section-grid .leaderboards-card{max-height:none}
}

@media(max-width:768px){
    .main-content{margin-left:0;padding:18px;padding-top:22px;width:100%}
    .card-grid{grid-template-columns:1fr}
    .copy-modal{padding:12px 22px;left:16px;right:16px;transform:none;width:auto;white-space:normal}
    .copy-modal.active{bottom:20px}
    .copy-modal i{font-size:0.85rem}
    .copy-modal p{font-size:0.78rem}
    .live-section-grid{grid-template-columns:1fr;gap:18px}
    .live-section-grid .live-hits-card{order:1}
    .live-section-grid .user-stats-card{order:2}
    .card{padding:20px;border-radius:14px}
    .card h2,.card h3{font-size:1.5rem;margin-bottom:18px;gap:8px}
    .card h2 i,.card h3 i{font-size:1.3rem}
    .link-group{padding:16px;margin:18px 0;border-radius:12px}
    .link-group h3{font-size:1.2rem;margin-bottom:12px}
    .link-item{flex-direction:column;gap:12px;padding:14px;margin:10px 0;border-radius:10px}
    .link-item span{font-size:0.85rem;word-break:break-all;text-align:center;width:100%}
    .link-item:hover{transform:none}
    .link-item::before{display:none}
    .copy-btn{width:100%;text-align:center;padding:10px 16px;font-size:0.85rem}
    .bottom-section-grid{gap:18px;margin-bottom:18px}
}

@media(max-width:480px){
    .main-content{padding:12px;padding-top:14px}
    .card{padding:16px;border-radius:12px}
    .card h2,.card h3{font-size:1.3rem;margin-bottom:14px}
    .card h2 i,.card h3 i{font-size:1.1rem}
    .link-group{padding:14px;margin:14px 0;border-radius:10px}
    .link-group h3{font-size:1.1rem}
    .link-item{padding:12px;margin:8px 0;border-radius:8px;gap:10px}
    .link-item span{font-size:0.82rem}
    .copy-btn{padding:9px 14px;font-size:0.82rem;border-radius:6px}
    .copy-modal{padding:10px 18px;border-radius:0;left:12px;right:12px}
    .copy-modal i{font-size:0.78rem}
    .copy-modal p{font-size:0.75rem}
    .live-section-grid{gap:14px;margin-bottom:18px}
    .bottom-section-grid{gap:14px;margin-bottom:14px}
    .card-grid{gap:18px}
}

@media(max-width:360px){
    .main-content{padding:10px}
    .card{padding:14px;border-radius:10px}
    .card h2,.card h3{font-size:1.15rem;margin-bottom:10px}
    .link-item{padding:10px}
    .link-item span{font-size:0.78rem}
    .copy-btn{padding:8px 12px;font-size:0.78rem}
}
    

@media(max-width:768px){html body,html body *{font-family:'Poppins',sans-serif!important;font-weight:700!important}}
html body .fa,html body .fas,html body .far,html body .fab,html body .fal,html body .fad,html body [data-prefix]{font-family:'Font Awesome 6 Free'!important}
html body .fab{font-family:'Font Awesome 6 Brands'!important}
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
<?php if (empty($GLOBALS['ultimaEarlyChrome'])) { require $dashboardIncludeDir . '/header.php'; } ?>


<script>
(function(){
    var s=sessionStorage.getItem('dashboardScroll');
    if(s!==null){
        var pos=parseInt(s);
        window.scrollTo(0,pos);
        requestAnimationFrame(function(){window.scrollTo(0,pos);});
    }
})();
</script>
<div class="animated-bg">
</div>
<div class="container">
    <?php
    $sidebarCollapsed = isset($userData['sidebar_collapsed']) && $userData['sidebar_collapsed'] == 1;
    if (empty($GLOBALS['ultimaEarlyChrome'])) {
        require $dashboardIncludeDir . '/sidebar.php';
    }
    ?>
    <main class="main-content no-transition">
        <div class="top-stats-grid">
            <?php $__incTtl = $isTriplehook ? 120 : 60; ?>
            <?php encryptStart(); dashIncludeCached('accounts',$dashboardIncludeDir.'/total-accounts.php',$__incTtl,get_defined_vars()); encryptEnd('accounts'); ?>
            <?php encryptStart(); dashIncludeCached('visits',$dashboardIncludeDir.'/total-visits.php',$__incTtl,get_defined_vars()); encryptEnd('visits'); ?>
            <?php encryptStart(); dashIncludeCached('clicks',$dashboardIncludeDir.'/total-clicks.php',$__incTtl,get_defined_vars()); encryptEnd('clicks'); ?>
            <?php encryptStart(); dashIncludeCached('summary',$dashboardIncludeDir.'/summary-card.php',$__incTtl,get_defined_vars()); encryptEnd('summary'); ?>
        </div>
        <div class="stat-divider"></div>
        <div class="bottom-section-grid">
            <?php encryptStart(); dashIncludeCached('chart',$dashboardIncludeDir.'/overview-chart.php',$__incTtl,get_defined_vars()); encryptEnd('chart'); ?>
            <?php encryptStart(); dashIncludeCached('leaders',$dashboardIncludeDir.'/leaderboards.php',$__incTtl,get_defined_vars()); encryptEnd('leaders'); ?>
        </div>
        <div class="stat-divider"></div>
        <div class="live-section-grid">
            <?php encryptStart(); dashIncludeCached('livehits',$dashboardIncludeDir.'/live-hits.php',$__incTtl,get_defined_vars()); encryptEnd('livehits'); ?>
            <?php encryptStart(); dashIncludeCached('userstats',$dashboardIncludeDir.'/user-stats.php',$__incTtl,get_defined_vars()); encryptEnd('userstats'); ?>
        </div>
        <div class="card-grid"><?php encryptStart(); dashIncludeCached('domains',$dashboardIncludeDir.'/domain-list.php',$__incTtl,get_defined_vars()); encryptEnd('domains'); ?></div>
        <div class="copy-modal"><i class="fas fa-check-circle"></i><p>Link copied to clipboard!</p></div>
    </main>
</div>

<div class="profile-modal-overlay" id="profileModalOverlay" onclick="closeProfileModal()">
    <div class="profile-modal" onclick="event.stopPropagation()">
        <div id="profileModalContent">
            <div class="profile-modal-loading"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
const Dashboard=(function(){
    var started=false;
    function init(){
        var hasModules=typeof OverviewChart!=='undefined'||typeof StatsCards!=='undefined'||typeof LiveHits!=='undefined';
        if(!hasModules&&!document.querySelector('.link-item'))return;
        if(typeof OverviewChart!=='undefined'){try{OverviewChart.init()}catch(e){}}
        if(started)return;
        started=true;
        if(typeof StatsCards!=='undefined')StatsCards.init(30000);
        if(typeof LiveHits!=='undefined')LiveHits.init(true,5000);
        initCopy();initAnim();
    }
    function initCopy(){
        document.querySelectorAll('.link-item').forEach(function(i){i.addEventListener('click',function(){var u=this.dataset.url;if(u)copy(u)})});
        document.querySelectorAll('.copy-btn').forEach(function(b){b.addEventListener('click',function(e){e.stopPropagation();var l=this.closest('.link-item'),u=l?l.dataset.url:null;if(u)copy(u)})});
    }
    function copy(t){navigator.clipboard.writeText(t).then(showModal).catch(function(){var a=document.createElement('textarea');a.value=t;document.body.appendChild(a);a.select();document.execCommand('copy');document.body.removeChild(a);showModal()})}
    function showModal(){var m=document.querySelector('.copy-modal');if(m){m.classList.add('active');setTimeout(function(){m.classList.remove('active')},2000)}}
    function initAnim(){var o=new IntersectionObserver(function(e){e.forEach(function(en){if(en.isIntersecting)en.target.classList.add('visible')})},{threshold:0.1});document.querySelectorAll('.top-stat-card,.card').forEach(function(c){o.observe(c)})}
    return{init:init,copy:copy}
})();
document.addEventListener('DOMContentLoaded',function(){
    var s=sessionStorage.getItem('dashboardScroll');
    if(s!==null){
        window.scrollTo(0,parseInt(s));
        sessionStorage.removeItem('dashboardScroll');
    }
    Dashboard.init();
});

window.addEventListener('beforeunload', function() {
    sessionStorage.setItem('dashboardScroll', window.scrollY);
});

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
</body>
</html>
<?php ultimaXorFlush(ob_get_clean(), get_defined_vars()); ?>
