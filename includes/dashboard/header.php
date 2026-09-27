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

$userData = ultimaAuthenticatedRegular();
if (!is_array($userData)) {
    http_response_code(401);
    exit;
}

$link_id = (int)$userData['link_id'];
$linkId = $link_id;
$isTriplehook = (int)($userData['triplehook'] ?? 0) === 1;
if (!isset($hasTriplehook)) {
    try {
        $triplehookRows = executeSafeQuery(
            'SELECT 1 FROM triplehook_data WHERE link_id = :link_id LIMIT 1',
            [':link_id' => $link_id]
        );
        $hasTriplehook = is_array($triplehookRows) && !empty($triplehookRows);
    } catch (\Throwable $e) {
        $hasTriplehook = false;
    }
} else {
    $hasTriplehook = (bool)$hasTriplehook;
}
$__headerDisplayIdentity = $userData;
$__headerHidden = strcasecmp(trim((string)($__headerDisplayIdentity['discord_username'] ?? '')), 'Anonymous') === 0
    || stripos(trim((string)($__headerDisplayIdentity['discord_avatar'] ?? '')), 'hide.png') !== false;

$avatarRaw = $__headerHidden ? '/images/hide.png' : ($__headerDisplayIdentity['discord_avatar'] ?? '');
$discordId = $__headerDisplayIdentity['discord_id'] ?? '';
if(!empty($avatarRaw) && (strpos($avatarRaw, 'https://') === 0 || strpos($avatarRaw, '/images/') === 0)){
    $displayAvatar = $avatarRaw;
} elseif(!empty($avatarRaw) && !empty($discordId)){
    $ext = (strpos($avatarRaw, 'a_') === 0) ? 'gif' : 'png';
    $displayAvatar = 'https://cdn.discordapp.com/avatars/' . $discordId . '/' . $avatarRaw . '.' . $ext;
} else {
    $displayAvatar = '/images/favicon.png';
}
$displayUsername = $__headerHidden
    ? 'Anonymous'
    : ($__headerDisplayIdentity['discord_username'] ?? 'User');
$__headerTriplehookActive = $isTriplehook;
$_SESSION['triplehook'] = $__headerTriplehookActive ? 'True' : 'False';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@300;400;500;600;700;800&family=Poppins:wght@500;600;700;800&family=Rajdhani:wght@300;500;700&display=swap" rel="stylesheet">
<style>
.triplehook-header,.triplehook-header *{font-family:'Outfit',sans-serif!important;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;text-rendering:optimizeLegibility;line-height:1!important;margin:0;padding:0;vertical-align:baseline;text-indent:0;text-transform:none;font-style:normal}
@media(max-width:768px){.triplehook-header,.triplehook-header *{font-family:'Poppins',sans-serif!important;font-weight:700!important}}
.triplehook-header{position:fixed;top:0;left:0;right:0;height:50px;display:flex;align-items:center;justify-content:space-between;padding:0 24px;background:rgba(8,9,12,0.95);backdrop-filter:blur(15px);-webkit-backdrop-filter:blur(15px);border-bottom:1px solid rgba(255,255,255,0.06);z-index:10000;box-shadow:0 4px 20px rgba(0,0,0,0.7),0 2px 8px rgba(0,0,0,0.5)}
.triplehook-toggle{display:inline-flex;align-items:center;gap:8px;cursor:pointer;-webkit-user-select:none;user-select:none;transition:all 0.25s ease;position:relative}
.triplehook-toggle-label{font-size:0.6rem;font-weight:700;color:rgba(255,255,255,0.5);letter-spacing:1.6px;white-space:nowrap;transition:color 0.25s ease;line-height:1}
.triplehook-toggle:hover .triplehook-toggle-label{color:rgba(255,255,255,0.75)}
.triplehook-toggle.active .triplehook-toggle-label{color:#fff}
.triplehook-status-wrap{position:relative;display:inline-block;padding-left:8px;border-left:1px solid rgba(255,255,255,0.1);transition:border-left-color 0.35s ease;line-height:1;min-width:42px}
.triplehook-toggle.active .triplehook-status-wrap{border-left-color:rgba(255,255,255,0.25)}
.triplehook-status-text{font-size:0.55rem;font-weight:700;letter-spacing:1.2px;line-height:1;transition:opacity 0.35s ease,transform 0.35s ease,color 0.35s ease;display:inline-block}
.triplehook-status-off{color:rgba(255,255,255,0.4);opacity:1;transform:translateY(0)}
.triplehook-status-on{position:absolute;left:8px;top:0;color:#fff;opacity:0;transform:translateY(-4px)}
.triplehook-toggle:hover .triplehook-status-off{color:rgba(255,255,255,0.6)}
.triplehook-toggle.active .triplehook-status-off{opacity:0;transform:translateY(4px)}
.triplehook-toggle.active .triplehook-status-on{opacity:1;transform:translateY(0)}
.header-user{display:flex;align-items:center;gap:10px;cursor:pointer;position:relative}
.header-avatar-wrap{position:relative;display:flex;align-items:center;flex-shrink:0}
.header-avatar{width:32px;height:32px;border-radius:50%;border:1.5px solid rgba(255,255,255,0.15);transition:border-color 0.3s ease;object-fit:cover;display:block}
.header-user:hover .header-avatar{border-color:rgba(255,255,255,0.45)}
.header-username{font-size:0.82rem;font-weight:600;color:rgba(255,255,255,0.85);letter-spacing:0.4px;white-space:nowrap;max-width:140px;overflow:hidden;text-overflow:ellipsis;line-height:1!important;transition:color 0.25s ease}
.header-user:hover .header-username{color:#fff}
.header-user-caret{width:12px;height:12px;stroke:rgba(255,255,255,0.5);fill:none;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0;transition:transform 0.25s ease,stroke 0.25s ease}
.header-user:hover .header-user-caret{stroke:rgba(255,255,255,0.85)}
.header-user.open .header-user-caret{transform:rotate(180deg)}
.header-dropdown{position:absolute;top:calc(100% + 8px);right:0;background:rgba(8,9,12,0.97);backdrop-filter:blur(15px);-webkit-backdrop-filter:blur(15px);border:1px solid rgba(255,255,255,0.07);border-radius:10px;padding:6px;min-width:200px;opacity:0;visibility:hidden;transform:translateY(-4px);transition:all 0.2s ease;box-shadow:0 8px 24px rgba(0,0,0,0.6)}
.header-dropdown.show{opacity:1;visibility:visible;transform:translateY(0)}
.header-dropdown-item{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:6px;color:rgba(255,255,255,0.65);font-size:0.8rem;font-weight:500;letter-spacing:0.3px;cursor:pointer;transition:all 0.2s ease;text-decoration:none;white-space:nowrap;position:relative;font-family:'Rajdhani',sans-serif!important}
.header-dropdown-item:hover{background:rgba(255,255,255,0.04);color:#fff}
.header-dropdown-item svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0}
.header-dropdown-divider{height:1px;background:rgba(255,255,255,0.06);margin:4px 8px}
body{padding-top:50px}
.sidebar{top:50px;height:calc(100vh - 50px)}
.sidebar-mobile-toggle{top:calc(50px + 10px)}
@media(max-width:768px){
.triplehook-header{height:42px;padding:0 12px}
.triplehook-toggle{gap:6px}
.triplehook-toggle-label{font-size:0.52rem;letter-spacing:1px}
.triplehook-status-text{font-size:0.48rem;letter-spacing:0.8px}
.triplehook-status-wrap{padding-left:6px;min-width:36px}
.triplehook-status-on{left:6px}
.header-user{gap:8px}
.header-avatar{width:26px;height:26px;border-width:1.5px}
.header-username{font-size:0.72rem;max-width:90px}
.header-user-caret{width:10px;height:10px}
body{padding-top:42px}
.sidebar{top:41px;height:calc(100vh - 41px)}
.sidebar-mobile-toggle{top:calc(41px + 8px)}
}
@media(max-width:480px){
.triplehook-header{height:38px;padding:0 10px}
.triplehook-toggle{gap:5px}
.triplehook-toggle-label{font-size:0.46rem;letter-spacing:0.7px}
.triplehook-status-text{font-size:0.42rem;letter-spacing:0.6px}
.triplehook-status-wrap{padding-left:5px;min-width:32px}
.triplehook-status-on{left:5px}
.header-user{gap:7px}
.header-avatar{width:24px;height:24px;border-width:1.5px}
.header-username{font-size:0.68rem;max-width:80px;letter-spacing:0.2px}
.header-user-caret{width:9px;height:9px}
body{padding-top:38px}
.sidebar{top:37px;height:calc(100vh - 37px)}
.sidebar-mobile-toggle{top:calc(37px + 6px)}
}
@media(max-width:360px){
.triplehook-header{height:34px;padding:0 8px}
.triplehook-toggle{gap:4px}
.triplehook-toggle-label{font-size:0.42rem;letter-spacing:0.5px}
.triplehook-status-text{font-size:0.38rem;letter-spacing:0.5px}
.triplehook-status-wrap{padding-left:4px;min-width:28px}
.triplehook-status-on{left:4px}
.header-user{gap:6px}
.header-avatar{width:22px;height:22px;border-width:1px}
.header-username{font-size:0.64rem;max-width:65px}
body{padding-top:34px}
.sidebar{top:33px;height:calc(100vh - 33px)}
.sidebar-mobile-toggle{top:calc(33px + 5px)}
}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.1);border-radius:2px}
::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,0.2)}
*{scrollbar-width:thin;scrollbar-color:rgba(255,255,255,0.1) transparent}
.dock-nav{font-family:'Rajdhani',sans-serif!important}
.dock-tooltip,.dock-badge{font-family:'Rajdhani',sans-serif!important}
.docs-overlay{position:fixed;inset:0;background:rgba(5,5,7,0.85);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);z-index:20000;display:flex;align-items:center;justify-content:center;opacity:0;visibility:hidden;pointer-events:none;transition:opacity 0.3s ease,visibility 0.3s ease;font-family:'Rajdhani',sans-serif}
.docs-overlay.show{opacity:1;visibility:visible;pointer-events:auto}
.docs-modal{width:640px;max-width:92vw;max-height:82vh;background:rgba(7,7,11,0.94);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.06);border-radius:16px;display:flex;flex-direction:column;overflow:hidden;transform:scale(0.97);transition:transform 0.4s cubic-bezier(0.16,1,0.3,1)}
.docs-overlay.show .docs-modal{transform:scale(1)}
.docs-modal *{font-family:'Rajdhani',sans-serif;box-sizing:border-box}
.docs-modal,.docs-modal *{-webkit-user-select:text!important;user-select:text!important;-moz-user-select:text!important;-ms-user-select:text!important}
.docs-item-head,.docs-copy-btn,.docs-item-chevron,.docs-section-label,.docs-item-head *{-webkit-user-select:none!important;user-select:none!important}
.docs-modal ::selection{background:rgba(255,255,255,0.18);color:#fff}
.docs-modal ::-moz-selection{background:rgba(255,255,255,0.18);color:#fff}
.docs-modal-header{display:flex;align-items:baseline;justify-content:space-between;padding:24px 26px 16px;flex-shrink:0}
.docs-modal-title{display:flex;align-items:baseline;gap:12px}
.docs-modal-title h2{font-size:1.05rem;font-weight:600;color:rgba(255,255,255,0.95);letter-spacing:0.4px;line-height:1!important}
.docs-modal-title em{font-style:normal;font-size:0.62rem;font-weight:500;color:rgba(255,255,255,0.28);letter-spacing:1.8px;text-transform:uppercase;line-height:1!important}
.docs-modal-body{flex:1;overflow-y:auto;padding:4px 10px 18px;min-height:0}
.docs-empty{display:flex;align-items:center;justify-content:center;padding:60px 20px;color:rgba(255,255,255,0.3);font-size:0.85rem;font-weight:400;letter-spacing:0.3px;text-align:center;line-height:1.6!important}
.docs-list{display:flex;flex-direction:column}
.docs-item{border-top:1px solid rgba(255,255,255,0.04);transition:background 0.35s ease}
.docs-item:first-child{border-top:none}
.docs-item.open{background:rgba(255,255,255,0.014)}
.docs-item-head{display:flex;align-items:center;gap:14px;padding:18px 16px;cursor:pointer;transition:opacity 0.2s ease}
.docs-item-head:hover{opacity:0.82}
.docs-item-method{font-family:'Courier New',ui-monospace,monospace!important;font-size:0.66rem;font-weight:700;letter-spacing:1.4px;color:rgba(255,255,255,0.5);min-width:36px;flex-shrink:0;line-height:1!important}
.docs-item.open .docs-item-method{color:rgba(255,255,255,0.88)}
.docs-item-info{flex:1;min-width:0;display:flex;flex-direction:column;gap:6px}
.docs-item-name{font-family:'Courier New',ui-monospace,monospace!important;font-size:0.82rem;font-weight:500;color:rgba(255,255,255,0.88);letter-spacing:0.1px;line-height:1!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.docs-item-desc{font-size:0.78rem;font-weight:400;color:rgba(255,255,255,0.42);letter-spacing:0.1px;line-height:1.3!important;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.docs-item-chevron{width:11px;height:11px;stroke:rgba(255,255,255,0.22);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0;transition:stroke 0.25s ease,transform 0.35s cubic-bezier(0.16,1,0.3,1)}
.docs-item.open .docs-item-chevron{transform:rotate(90deg);stroke:rgba(255,255,255,0.6)}
.docs-item-body{max-height:0;overflow:hidden;transition:max-height 0.45s cubic-bezier(0.16,1,0.3,1)}
.docs-item.open .docs-item-body{max-height:900px}
.docs-item-body-inner{padding:2px 16px 24px;display:flex;flex-direction:column;gap:20px}
.docs-section{display:flex;flex-direction:column;gap:9px}
.docs-section-label{font-size:0.62rem;font-weight:500;letter-spacing:2.2px;color:rgba(255,255,255,0.3);text-transform:uppercase;display:flex;align-items:center;justify-content:space-between;line-height:1!important}
.docs-copy-btn{background:transparent;border:none;color:rgba(255,255,255,0.3);font-family:'Rajdhani',sans-serif;font-size:0.62rem;font-weight:500;letter-spacing:1.6px;padding:0;cursor:pointer;transition:color 0.25s ease;text-transform:uppercase;line-height:1!important}
.docs-copy-btn:hover{color:rgba(255,255,255,0.8)}
.docs-copy-btn.copied{color:rgba(255,255,255,0.95)}
.docs-code{background:rgba(0,0,0,0.32);border:1px solid rgba(255,255,255,0.03);border-radius:8px;padding:14px 16px;font-family:'Courier New',ui-monospace,monospace!important;font-size:0.78rem;color:rgba(255,255,255,0.78);line-height:1.65!important;letter-spacing:0.15px;white-space:pre-wrap;word-break:break-all;overflow-x:auto;margin:0;-webkit-user-select:text!important;user-select:text!important;cursor:text}
.docs-code.json{font-size:0.74rem;color:rgba(255,255,255,0.72);max-height:240px;overflow-y:auto}
.docs-code::-webkit-scrollbar,.docs-code.json::-webkit-scrollbar{width:4px;height:4px}
.docs-code::-webkit-scrollbar-thumb,.docs-code.json::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.08);border-radius:2px}
.docs-params{display:flex;flex-direction:column;gap:10px}
.docs-param{display:flex;align-items:baseline;gap:14px;padding:0}
.docs-param-name{font-family:'Courier New',ui-monospace,monospace!important;font-size:0.78rem;font-weight:500;color:rgba(255,255,255,0.85);letter-spacing:0.1px;flex-shrink:0;line-height:1.4!important}
.docs-param-req{font-size:0.58rem;font-weight:500;letter-spacing:1.4px;color:rgba(255,255,255,0.38);text-transform:uppercase;flex-shrink:0;line-height:1!important}
.docs-param-req.optional{color:rgba(255,255,255,0.22)}
.docs-param-desc{font-size:0.78rem;font-weight:400;color:rgba(255,255,255,0.5);letter-spacing:0.15px;line-height:1.4!important;flex:1}
.docs-modal-body::-webkit-scrollbar{width:4px}
.docs-modal-body::-webkit-scrollbar-track{background:transparent}
.docs-modal-body::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.06);border-radius:2px}
.docs-modal-body::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,0.16)}
@media(max-width:768px){
.docs-modal{width:94vw;max-height:88vh;border-radius:14px}
.docs-modal-header{padding:20px 18px 14px}
.docs-modal-title h2{font-size:0.95rem}
.docs-modal-title em{font-size:0.58rem;letter-spacing:1.5px}
.docs-modal-body{padding:2px 6px 14px}
.docs-item-head{padding:15px 12px;gap:12px}
.docs-item-method{font-size:0.6rem;min-width:32px}
.docs-item-name{font-size:0.76rem}
.docs-item-desc{font-size:0.72rem;white-space:normal}
.docs-item-body-inner{padding:2px 12px 18px;gap:18px}
.docs-code{font-size:0.72rem;padding:12px 13px}
.docs-code.json{font-size:0.68rem;max-height:200px}
.docs-param{flex-wrap:wrap;gap:8px}
.docs-param-name{font-size:0.72rem}
.docs-param-desc{font-size:0.72rem;width:100%}
}</style>
<div class="triplehook-header" id="triplehookHeader">
<div>
<?php if($hasTriplehook): ?>
    <div class="triplehook-toggle <?=$__headerTriplehookActive?'active':''?>" id="triplehookToggle" onclick="toggleTriplehook(this)">
        <span class="triplehook-toggle-label">TRIPLEHOOK</span>
        <span class="triplehook-status-wrap">
            <span class="triplehook-status-text triplehook-status-off">OFF</span>
            <span class="triplehook-status-text triplehook-status-on">ACTIVE</span>
        </span>
    </div>
<?php endif; ?>
</div>
<div class="header-user" id="headerUser" onclick="toggleHeaderDropdown(event)">
    <div class="header-avatar-wrap">
        <img src="<?=htmlspecialchars($displayAvatar)?>" class="header-avatar" id="headerAvatar" alt="">
    </div>
    <span class="header-username" id="headerUsername"><?=htmlspecialchars($displayUsername)?></span>
    <svg class="header-user-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
    <div class="header-dropdown" id="headerDropdown">
        <div class="header-dropdown-item" id="dropdownDocs" onclick="openDocsModal();event.stopPropagation();closeDropdownMenu();">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/></svg>
            Documentation API
        </div>
        <div class="header-dropdown-divider"></div>
        <a href="/pages/logout" class="header-dropdown-item">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Sign out
        </a>
    </div>
</div>
</div>
<div class="docs-overlay" id="docsOverlay" onclick="if(event.target===this)closeDocsModal()">
    <div class="docs-modal">
        <div class="docs-modal-header">
            <div class="docs-modal-title">
                <h2>Documentation</h2>
                <em>Public API</em>
            </div>
        </div>
        <div class="docs-modal-body" id="docsModalBody">
            <div class="docs-empty">No API endpoints available yet.<br>Check back soon.</div>
        </div>
    </div>
</div>
<script>
function toggleHeaderDropdown(e){
    e.stopPropagation();
    var u=document.getElementById('headerUser');
    var d=document.getElementById('headerDropdown');
    d.classList.toggle('show');
    u.classList.toggle('open');
}
function closeDropdownMenu(){document.getElementById('headerDropdown').classList.remove('show');document.getElementById('headerUser').classList.remove('open')}
document.addEventListener('click',function(){closeDropdownMenu()});

var __docsEndpoints = [
    {
        method:'GET',
        name:'/v2/public/domain.php',
        desc:'List available domains and their types',
        curl:'curl -X GET "https://app.beamse.pro/v2/public/domain.php"',
        response:'{\n  "lists": [\n    {\n      "domain": "roblox.com.bn",\n      "type": "tiktok"\n    },\n    {\n      "domain": "apples.com",\n      "type": "tiktok,discord"\n    }\n  ]\n}'
    },
    {
        method:'GET',
        name:'/v2/public/best-daily.php',
        desc:'Today\'s top 3 hitters (global)',
        curl:'curl -X GET "https://app.beamse.pro/v2/public/best-daily.php"',
        response:'{\n  "data": [\n    {\n      "avatar": "https://cdn.discordapp.com/avatars/.../....png",\n      "name": "burhq.jam644",\n      "count": "95"\n    },\n    {\n      "avatar": "https://cdn.pfps.gg/pfps/9332-default-discord-pfp.png",\n      "name": "Negronymous",\n      "count": "48"\n    }\n  ]\n}'
    },
    {
        method:'GET',
        name:'/v2/public/user.php',
        desc:'Get user stats by discord_id',
        params:[
            { name:'user', required:true, desc:'The discord_id of the user' }
        ],
        curl:'curl -X GET "https://app.beamse.pro/v2/public/user.php?user=YOUR_DISCORD_ID"',
        response:'{\n  "success": true,\n  "nickname": "BroListen!",\n  "stats": {\n    "hits": 3,\n    "visits": 2195,\n    "clicks": 47471\n  }\n}'
    }
];

function openDocsModal(){
    var overlay = document.getElementById('docsOverlay');
    var body = document.getElementById('docsModalBody');
    if(!overlay || !body) return;
    renderDocsList();
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeDocsModal(){
    var overlay = document.getElementById('docsOverlay');
    if(!overlay) return;
    overlay.classList.remove('show');
    document.body.style.overflow = '';
}

function renderDocsList(){
    var body = document.getElementById('docsModalBody');
    if(!body) return;
    if(!__docsEndpoints || __docsEndpoints.length === 0){
        body.innerHTML = '<div class="docs-empty">No API endpoints available yet.<br>Check back soon.</div>';
        return;
    }
    var html = '<div class="docs-list">';
    for(var i = 0; i < __docsEndpoints.length; i++){
        var ep = __docsEndpoints[i];
        var method = (ep.method || 'GET').toUpperCase();
        var name = ep.name || '';
        var desc = ep.desc || '';
        html += '<div class="docs-item" data-idx="' + i + '">';
        html += '<div class="docs-item-head" onclick="toggleDocsItem(' + i + ')">';
        html += '<span class="docs-item-method">' + escapeAttr(method) + '</span>';
        html += '<div class="docs-item-info">';
        html += '<span class="docs-item-name">' + escapeAttr(name) + '</span>';
        if(desc) html += '<span class="docs-item-desc">' + escapeAttr(desc) + '</span>';
        html += '</div>';
        html += '<svg class="docs-item-chevron" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>';
        html += '</div>';
        html += '<div class="docs-item-body"><div class="docs-item-body-inner">';
        if(ep.params && ep.params.length){
            html += '<div class="docs-section"><div class="docs-section-label">Parameters</div><div class="docs-params">';
            for(var p = 0; p < ep.params.length; p++){
                var par = ep.params[p];
                html += '<div class="docs-param">';
                html += '<span class="docs-param-name">' + escapeAttr(par.name) + '</span>';
                html += '<span class="docs-param-req' + (par.required ? '' : ' optional') + '">' + (par.required ? 'Required' : 'Optional') + '</span>';
                html += '<span class="docs-param-desc">' + escapeAttr(par.desc || '') + '</span>';
                html += '</div>';
            }
            html += '</div></div>';
        }
        if(ep.curl){
            html += '<div class="docs-section"><div class="docs-section-label"><span>Request</span><button class="docs-copy-btn" onclick="copyDocsCode(event,this,' + i + ',\'curl\')">Copy</button></div>';
            html += '<pre class="docs-code">' + escapeAttr(ep.curl) + '</pre></div>';
        }
        if(ep.response){
            html += '<div class="docs-section"><div class="docs-section-label"><span>Response</span><button class="docs-copy-btn" onclick="copyDocsCode(event,this,' + i + ',\'response\')">Copy</button></div>';
            html += '<pre class="docs-code json">' + escapeAttr(ep.response) + '</pre></div>';
        }
        html += '</div></div>';
        html += '</div>';
    }
    html += '</div>';
    body.innerHTML = html;
}

function toggleDocsItem(idx){
    var items = document.querySelectorAll('.docs-item');
    for(var i = 0; i < items.length; i++){
        if(parseInt(items[i].getAttribute('data-idx'), 10) === idx){
            items[i].classList.toggle('open');
        } else {
            items[i].classList.remove('open');
        }
    }
}

function copyDocsCode(e, btn, idx, field){
    if(e) e.stopPropagation();
    if(!__docsEndpoints[idx]) return;
    var text = __docsEndpoints[idx][field] || '';
    if(!text) return;
    var done = function(){
        var original = btn.textContent;
        btn.textContent = 'Copied';
        btn.classList.add('copied');
        setTimeout(function(){
            btn.textContent = original;
            btn.classList.remove('copied');
        }, 1400);
    };
    if(navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(text).then(done).catch(function(){
            fallbackCopy(text); done();
        });
    } else {
        fallbackCopy(text); done();
    }
}

function fallbackCopy(text){
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch(_){}
    document.body.removeChild(ta);
}

function escapeAttr(s){
    if(s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function(c){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}

document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
        var ov = document.getElementById('docsOverlay');
        if(ov && ov.classList.contains('show')) closeDocsModal();
    }
});

</script>
<script>
(function(){
if(window.__ultimaSoftNavInstalled)return;window.__ultimaSoftNavInstalled=true;
var AK='ultimaSharedAesGcmV1',busy=false;
function U(s){var b=atob(s),a=new Uint8Array(b.length);for(var i=0;i<b.length;i++)a[i]=b.charCodeAt(i);return a}
function cached(){try{var c=JSON.parse(sessionStorage.getItem(AK)||'null');if(c&&Date.now()-(c.t||0)<1800000&&c.k){return crypto.subtle.importKey('raw',U(c.k),{name:'AES-GCM'},false,['decrypt'])}}catch(e){}return null}
function decode(key,payloadB64,df){return new Promise(function(resolve,reject){try{var enc=atob(payloadB64),bytes=new Uint8Array(enc.length);for(var i=0;i<enc.length;i++)bytes[i]=enc.charCodeAt(i);var iv=bytes.slice(0,12),tag=bytes.slice(12,28),ct=bytes.slice(28),combined=new Uint8Array(ct.length+tag.length);combined.set(ct);combined.set(tag,ct.length);crypto.subtle.decrypt({name:'AES-GCM',iv:iv,tagLength:128},key,combined).then(function(dec){var u8=new Uint8Array(dec);if(df){if(!window.DecompressionStream){reject(new Error('no deflate'));return}new Response(new Blob([u8]).stream().pipeThrough(new DecompressionStream('deflate-raw'))).text().then(resolve,reject)}else{resolve(new TextDecoder().decode(u8))}}).catch(reject)}catch(e){reject(e)}})}
function parsePayload(txt){var p=txt.match(/var\s+enc\s*=\s*atob\((['"])([A-Za-z0-9+\/=]+)\1\)/);if(!p)p=txt.match(/payload\s*\(\s*key\s*,\s*(['"])([A-Za-z0-9+\/=]+)\1\s*,\s*df\s*\)/);var d=txt.match(/var\s+t\s*=\s*(['"]).*?\1\s*,\s*df\s*=\s*([01])/);return p?{payload:p[2],df:parseInt((d&&d[2])||'0',10)}:null}
function swap(h){try{var p=new DOMParser().parseFromString(h,'text/html'),b=document.body;[].slice.call(document.documentElement.attributes).forEach(function(a){document.documentElement.removeAttribute(a.name)});[].slice.call(p.documentElement.attributes).forEach(function(a){document.documentElement.setAttribute(a.name,a.value)});document.head.innerHTML=p.head.innerHTML;[].slice.call(b.childNodes).forEach(function(n){if(n.parentNode)n.parentNode.removeChild(n)});[].slice.call(b.attributes).forEach(function(a){b.removeAttribute(a.name)});[].slice.call(p.body.attributes).forEach(function(a){b.setAttribute(a.name,a.value)});var m=document.createComment('soft-nav');b.appendChild(m);[].slice.call(p.body.childNodes).forEach(function(n){b.appendChild(document.importNode(n,true))});var roots=[],nx=m.nextSibling;while(nx){roots.push(nx);nx=nx.nextSibling}if(m.parentNode)m.parentNode.removeChild(m);var ss=[];roots.forEach(function(r){if(r.nodeType===1){if(r.tagName&&r.tagName.toLowerCase()==='script')ss.push(r);ss=ss.concat([].slice.call(r.querySelectorAll('script')))}});var oldAEL=document.addEventListener,baseAEL=oldAEL.bind(document);document.addEventListener=function(type,fn,opts){if(type==='DOMContentLoaded'&&document.readyState!=='loading'){setTimeout(function(){try{var ev=new Event('DOMContentLoaded');if(typeof fn==='function')fn.call(document,ev);else if(fn&&typeof fn.handleEvent==='function')fn.handleEvent(ev)}catch(e){}},0);return}return baseAEL(type,fn,opts)};function done(){document.addEventListener=oldAEL}function run(i){if(i>=ss.length){done();return}var old=ss[i],s=document.createElement('script');[].slice.call(old.attributes).forEach(function(a){s.setAttribute(a.name,a.value)});if(old.src){s.async=false;s.onload=s.onerror=function(){run(i+1)}}else{s.text=old.textContent}try{old.parentNode.replaceChild(s,old)}catch(e){}if(!old.src)run(i+1)}run(0)}catch(e){location.href=location.href}}
function ok(a){if(!a||a.target&&a.target!=='_self'||a.hasAttribute('download')||a.getAttribute('data-no-pjax')==='1')return false;var href=a.getAttribute('href');if(!href||href.charAt(0)==='#'||/^javascript:|^mailto:|^tel:/i.test(href))return false;var u;try{u=new URL(href,location.href)}catch(e){return false}if(u.origin!==location.origin)return false;if(u.pathname.indexOf('/pages/')!==0)return false;if(/\/logout/i.test(u.pathname)||/\/api\//i.test(u.pathname))return false;if(u.href===location.href)return false;return true}
// XOR decrypt: window.__ultimaXorKey full-page render'da set edilir.
// Soft-nav response'u {"p": "<base64_xor>"} JSON formatında gelir.
function xorDecrypt(payloadB64, key){
    var b = atob(payloadB64);
    var kl = key.length;
    var out = new Array(b.length);
    for(var i=0;i<b.length;i++){
        out[i] = String.fromCharCode(b.charCodeAt(i) ^ key.charCodeAt(i % kl));
    }
    return out.join('');
}
// Chrome (kalıcı kabuk) düğümü mü? Partial swap'te bunlar korunur.
function chromeNode(n){if(!n||n.nodeType!==1)return false;var id=n.id||'',tag=(n.tagName||'').toLowerCase(),cls=typeof n.className==='string'?n.className:'';if(id==='bootChrome'||id==='bootBg'||id==='realBootBg'||id==='bgCanvas'||id==='sidebar'||id==='sidebar-overlay'||id==='mobile-menu-btn'||id==='triplehookHeader')return true;if(tag==='header'||tag==='nav')return true;if(tag==='canvas'&&(id==='bootBg'||id==='realBootBg'||id==='bgCanvas'||id==='ultimaStars'))return true;if(/\b(dock-nav|mobile-dock-btn|dock-overlay|animated-bg)\b/.test(cls))return true;return false}
function skipStarScript(tx){return /realBootBg|__ultimaStarsLive|ultimaBgDotsStateV3|bgCanvas/.test(tx||'')}
// Partial swap: yalnızca main.main-content'i değiştirir; header (WS istemcisi),
// sidebar ve arka plan canvas korunur. Tam-döküman swap yerine bunu kullanmak
// sayfa geçişini hızlandırır ve tam reload'ı ortadan kaldırır. Kabuk (bootChrome)
// yoksa false döner; çağıran tam swap'e düşer.
function applyMain(h){
    var doc=new DOMParser().parseFromString(h,'text/html');
    var newMain=doc.querySelector('main.main-content')||doc.querySelector('main');
    var curMain=document.querySelector('#bootChrome main.main-content')||document.querySelector('main.main-content')||document.querySelector('main');
    if(!newMain||!curMain)return false;
    document.title=doc.title||document.title;
    // Önceki geçişte body'ye enjekte edilen düğümleri (modal/script) temizle — birikmeyi önler.
    [].slice.call(document.querySelectorAll('[data-sn-injected="1"]')).forEach(function(n){if(n.parentNode)n.parentNode.removeChild(n)});
    curMain.innerHTML=newMain.innerHTML;
    var b=document.body;
    [].slice.call(doc.body.childNodes).forEach(function(n){
        if(!n||n.nodeType!==1||chromeNode(n))return;
        var tag=(n.tagName||'').toLowerCase(),cls=typeof n.className==='string'?n.className:'';
        if(tag==='main')return;
        if(/\bcontainer\b/.test(cls)&&n.querySelector&&n.querySelector('main,.dock-nav,#sidebar'))return;
        var imp=document.importNode(n,true);
        if(imp.nodeType===1)imp.setAttribute('data-sn-injected','1');
        b.appendChild(imp);
    });
    var ss=[];
    function grab(root){if(!root)return;if(root.tagName&&root.tagName.toLowerCase()==='script'){ss.push(root);return}if(root.querySelectorAll){[].slice.call(root.querySelectorAll('script')).forEach(function(s){ss.push(s)})}}
    grab(curMain);
    [].slice.call(b.childNodes).forEach(function(n){if(n.id==='bootChrome'||n.id==='bootBg'||chromeNode(n))return;grab(n)});
    ss=ss.filter(function(s){var src=s.src||'';if(src.indexOf('chart.js')!==-1&&window.Chart)return false;return !skipStarScript(s.textContent||'')});
    ss.sort(function(a,b){return ((a.src||'')?0:1)-((b.src||'')?0:1)});
    var oldAEL=document.addEventListener,baseAEL=oldAEL.bind(document);
    document.addEventListener=function(type,fn,opts){if(type==='DOMContentLoaded'&&document.readyState!=='loading'){setTimeout(function(){try{if(typeof fn==='function')fn.call(document,new Event('DOMContentLoaded'));else if(fn&&typeof fn.handleEvent==='function')fn.handleEvent(new Event('DOMContentLoaded'))}catch(e){}},0);return}return baseAEL(type,fn,opts)};
    (function run(i){
        if(i>=ss.length){
            document.addEventListener=oldAEL;
            setTimeout(function(){try{
                var cards=document.querySelectorAll('.overview-card .chart-container,.chart-container');
                for(var j=0;j<cards.length;j++){var box=cards[j],cv=box.querySelector('canvas');if(!cv)continue;var w=box.clientWidth||box.offsetWidth||0,hh=box.clientHeight||box.offsetHeight||235;if(w<10)w=box.parentElement?box.parentElement.clientWidth:0;if(w>10){cv.style.width=w+'px';cv.style.height=Math.max(hh,220)+'px';cv.width=w;cv.height=Math.max(hh,220)}if(window.Chart&&typeof Chart.getChart==='function'){var old=Chart.getChart(cv);if(old&&old.destroy)old.destroy()}}
                window.dispatchEvent(new Event('resize'));
                if(typeof OverviewChart!=='undefined'&&OverviewChart.init)OverviewChart.init();else if(typeof Dashboard!=='undefined'&&Dashboard.init)Dashboard.init();
            }catch(e){}},80);
            return;
        }
        var old=ss[i],s=document.createElement('script');
        [].slice.call(old.attributes).forEach(function(a){s.setAttribute(a.name,a.value)});
        if(old.src){s.async=false;s.onload=s.onerror=function(){run(i+1)}}else{s.text=old.textContent}
        try{old.parentNode?old.parentNode.replaceChild(s,old):document.body.appendChild(s)}catch(e){}
        if(!old.src)run(i+1);
    })(0);
    return true;
}
function go(url,push,preserveScroll){
    var ck=cached();
    var scrollY=preserveScroll?(window.scrollY||window.pageYOffset||0):0;
    busy=true;
    document.documentElement.classList.add('soft-nav-loading');
    fetch(url,{credentials:'same-origin',cache:'no-store',headers:{'X-Soft-Nav':'1'}})
        .then(function(r){if(!r.ok)throw new Error('http');return r.text()})
        .then(function(txt){
            // Yeni davranış: JSON envelope {"p": xor_encrypted_base64}
            // Sayfa tasarımı asla düz olarak dışarı çıkmaz.
            try {
                var jsonData = JSON.parse(txt);
                if(jsonData && typeof jsonData.p === 'string'){
                    var xorKey = window.__ultimaXorKey;
                    if(!xorKey) throw new Error('no-xor-key');
                    var html = xorDecrypt(jsonData.p, xorKey);
                    if(push) history.pushState({softNav:1}, '', url);
                    // Önce hafif partial swap (chrome/WS/arka plan korunur, tam reload yok);
                    // kabuk yoksa veya hata olursa tam-döküman swap'e düş.
                    var okp=false; try{okp=applyMain(html)}catch(e){okp=false}
                    if(!okp) swap(html);
                    window.scrollTo(0, scrollY);
                    return;
                }
            } catch(e) {
                // JSON değilse aşağıdaki eski path'lere düş
            }
            // Geriye uyum: full HTML (login gibi encryption'ı olmayan sayfalar)
            if(/<html[\s>]/i.test(txt)){
                if(push)history.pushState({softNav:1},'',url);
                swap(txt);
                window.scrollTo(0,scrollY);
                return;
            }
            // AES-GCM payload (login akışı)
            var data=parsePayload(txt);
            if(data && ck){
                return ck.then(function(k){return decode(k,data.payload,data.df)}).then(function(html){
                    if(push)history.pushState({softNav:1},'',url);
                    swap(html);
                    window.scrollTo(0,scrollY);
                });
            }
            throw new Error('no payload');
        })
        .catch(function(){location.href=url})
        .then(function(){busy=false;document.documentElement.classList.remove('soft-nav-loading');try{window.dispatchEvent(new CustomEvent('ultima:softnav-complete',{detail:{url:url}}))}catch(e){}});
    return true;
}
window.ultimaSoftNavigate=function(url,options){if(busy)return false;return go(url||location.href,false,!!(options&&options.preserveScroll))};
// Sidebar ve diğer navigasyonlar için: sayfa geçişini soft-nav (fetch + DOM swap)
// ile yap; push=true ile URL/history güncellensin. Başarısızlıkta false döner —
// çağıran taraf o zaman location.assign()'a düşer.
window.ultimaSoftGo=function(url){if(!url||busy)return false;return go(url,true,false)};
window.ultimaStopLiveSockets=function(){var stops=window.__ultimaLiveSocketStops||{};Object.keys(stops).forEach(function(name){try{if(typeof stops[name]==='function')stops[name]()}catch(e){}})};
window.ultimaRefreshLiveSockets=function(){var restarts=window.__ultimaLiveSocketRestarts||{},count=0;Object.keys(restarts).forEach(function(name){try{if(typeof restarts[name]==='function'&&restarts[name]())count++}catch(e){}});return count};
document.addEventListener('click',function(e){if(e.defaultPrevented||busy||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;var a=e.target.closest&&e.target.closest('a[href]');if(!ok(a))return;e.preventDefault();go(new URL(a.getAttribute('href'),location.href).href,true)},true);
window.addEventListener('popstate',function(){go(location.href,false)});
})();
</script>
<?php if($hasTriplehook): ?>
<script>
(function(){
    var el=document.getElementById('triplehookToggle');
    if(!el) return;
    try{
        var ts=parseInt(sessionStorage.getItem('ultimaTriplehookChangedAt')||'0',10);
        var mode=sessionStorage.getItem('ultimaTriplehookMode');
        if(ts && Date.now()-ts<15000 && (mode==='1'||mode==='0')){
            el.classList.toggle('active', mode==='1');
        }
    }catch(_){}
})();
function toggleTriplehook(el){
    if(!el || el.dataset.saving==='1') return;
    var isActive=el.classList.contains('active');
    var newState=isActive?0:1;
    var oldState=isActive?1:0;
    el.dataset.saving='1';
    el.style.pointerEvents='none';
    el.classList.toggle('active', !!newState);
    fetch('/api/toggle-triplehook.php',{
        method:'POST',
        cache:'no-store',
        credentials:'same-origin',
        headers:{'Content-Type':'application/json','Cache-Control':'no-store'},
        body:JSON.stringify({triplehook:newState})
    })
    .then(function(r){return r.json()})
    .then(function(d){
        if(d && d.success){
            try{
                sessionStorage.setItem('ultimaTriplehookMode', newState?'1':'0');
                sessionStorage.setItem('ultimaTriplehookChangedAt', String(Date.now()));
                sessionStorage.setItem('dashboardScroll', String(window.scrollY||0));
            }catch(_){}
            // Yeni-mod veri akışı tümüyle WS üzerinden gelsin:
            // 1) Eski-mod snapshot'ı at ki soft-nav sonrası kurulan kartlara yanlış
            //    veri replay edilmesin.
            // 2) Soft-nav ile yeni mod DOM'unu (yapı + PHP ilk değerler) render et.
            // 3) DOM yerleşince (softnav-complete) WS'i yeniden kur — sunucu bağlantı
            //    anında is_triplehook'u tekrar okur ve yeni-mod snapshot'ı push eder;
            //    kartlar canlı ve anında dolar.
            // Soft-nav yoksa tam reload zaten WS'i baştan kurduğu için ek işe gerek yok.
            try{if(window.ultimaWS&&window.ultimaWS.dropSnapshots)window.ultimaWS.dropSnapshots();}catch(_){}
            window.addEventListener('ultima:softnav-complete',function _thRefresh(){
                window.removeEventListener('ultima:softnav-complete',_thRefresh);
                try{
                    if(window.ultimaWS&&typeof window.ultimaWS.restart==='function')window.ultimaWS.restart();
                    else if(typeof window.ultimaRefreshLiveSockets==='function')window.ultimaRefreshLiveSockets();
                }catch(_){}
            });
            if(!(window.ultimaSoftNavigate && window.ultimaSoftNavigate(location.href, {preserveScroll:true}))){
                window.location.reload();
            }
        }else{
            el.classList.toggle('active', !!oldState);
            el.dataset.saving='0';
            el.style.pointerEvents='';
        }
    })
    .catch(function(){
        el.classList.toggle('active', !!oldState);
        el.dataset.saving='0';
        el.style.pointerEvents='';
    });
}
</script>
<?php endif; ?>

<script>
(function(){
    function setAvatarVisibility(detail){
        detail=detail||{};
        var avatar=document.getElementById('headerAvatar');
        var username=document.getElementById('headerUsername');
        if(!avatar)return;
        var source=detail.avatarUrl||detail.avatar_url||avatar.src;
        var name=detail.username||detail.discord_username||'';
        if(source)avatar.src=source;
        if(username&&name)username.textContent=name;
    }
    window.ultimaSetAvatarVisibility=setAvatarVisibility;
    window.addEventListener('ultima:avatar-visibility',function(event){setAvatarVisibility(event&&event.detail);});
})();
</script>

<script>
/*
 * Ultima Unified WS Client
 * ------------------------
 * Tüm website için tek bir WebSocket bağlantısı üzerinden çalışır.
 * server-ws4.php'nin /wsx rotasına bağlanır ve kanallara göre dispatch yapar.
 *
 * Kullanım (herhangi bir sayfada):
 *   window.ultimaWS.subscribe('dashboard', function(msg){ ... });
 *   window.ultimaWS.subscribe('live',      function(msg){ ... });
 *   window.ultimaWS.subscribe('storage',   function(msg){ ... });
 *   window.ultimaWS.subscribe('leaderboard', function(msg){ ... });
 *   window.ultimaWS.subscribe('dashboard_visits', function(msg){ page: N });
 *
 * Bileşen abone olduğunda istemci gerekli kanalları toparlayıp bir sonraki
 * mikrotick'te tek bir WebSocket açar. Yeni bir kanal geldiyse bağlantıyı
 * yeni kanal listesiyle kibarca yeniden kurar.
 */
(function(){
    if(window.ultimaWS)return;
    var ENCK_HEX='a7f3d1b9c8e2547809badcfe12345678abcdef0123456789fedcba9876543210';
    function hexToBytes(hex){var b=new Uint8Array(hex.length/2);for(var i=0;i<b.length;i++)b[i]=parseInt(hex.substr(i*2,2),16);return b}
    function b64ToBytes(v){var raw=atob(v);var b=new Uint8Array(raw.length);for(var i=0;i<raw.length;i++)b[i]=raw.charCodeAt(i);return b}
    var keyPromise=null;
    function getKey(){if(!keyPromise)keyPromise=window.crypto.subtle.importKey('raw',hexToBytes(ENCK_HEX),{name:'AES-CBC'},false,['decrypt']);return keyPromise}
    function decrypt(payloadB64){
        var bytes=b64ToBytes(payloadB64);
        return getKey().then(function(k){return window.crypto.subtle.decrypt({name:'AES-CBC',iv:bytes.slice(0,16)},k,bytes.slice(16))})
            .then(function(buf){return new TextDecoder('utf-8').decode(buf)});
    }

    var subscribers={};       // channel -> [handler,...]
    var wanted={};            // channel -> true (istenen kanallar)
    var lastByChannel={};     // channel -> son tam-durum snapshot'ı (geç abonelere replay)
    var socket=null, reconnectTimer=null, reconnectDelay=2000, lastMessageAt=0;
    var stopped=false, authFailed=false, bootScheduled=false;
    var currentChannels='';   // en son bağlanılan kanal listesi (imza)
    var visitsPage=1;         // dashboard_visits için sayfa parametresi
    var healthTimer=null;

    function channelsQuery(){
        var keys=Object.keys(wanted).sort();
        return keys.join(',');
    }

    function wsUrl(){
        var proto=(location.protocol==='https:'?'wss:':'ws:');
        var ch=channelsQuery();
        var qs=ch?('?channels='+encodeURIComponent(ch)):'';
        // dashboard_visits için sayfa parametresi ekle
        if(wanted.dashboard_visits){
            qs+=(qs?'&':'?')+'page='+encodeURIComponent(visitsPage);
        }
        return proto+'//'+location.host+'/wsx'+qs;
    }

    function scheduleReconnect(){
        if(stopped||authFailed||reconnectTimer||document.hidden)return;
        reconnectTimer=setTimeout(function(){
            reconnectTimer=null;
            reconnectDelay=Math.min(reconnectDelay*1.5,30000);
            connect();
        },reconnectDelay);
    }

    function dispatch(channel,message){
        var handlers=subscribers[channel];
        if(!handlers)return;
        for(var i=0;i<handlers.length;i++){
            try{handlers[i](message)}catch(e){}
        }
    }

    function handleFrame(text){
        var envelope;
        try{envelope=JSON.parse(text)}catch(e){return}
        if(!envelope||typeof envelope!=='object')return;
        var channel=envelope.c;
        var inner=envelope.d;
        if(channel==='_sys'){
            try{var sys=JSON.parse(inner);dispatch('_sys',sys)}catch(e){}
            return;
        }
        if(typeof inner!=='string'||!inner)return;
        decrypt(inner).then(function(plain){
            var msg;try{msg=JSON.parse(plain)}catch(e){return}
            if(!msg)return;
            if(msg.type==='fatal'){
                authFailed=true;
                if(reconnectTimer){clearTimeout(reconnectTimer);reconnectTimer=null}
                dispatch(channel,msg);
                return;
            }
            // dashboard kanalı için CustomEvent yayınla (mevcut componentler için)
            if(channel==='dashboard'&&msg.type==='dashboard_stats'){
                try{window.dispatchEvent(new CustomEvent('dashboard:stats',{detail:msg.payload}))}catch(e){}
                // Son tam-durum snapshot'ını sakla: soft-nav sonrası yeniden kurulan
                // kartlar bir sonraki tick'i beklemeden anında güncel veriyi alır.
                // Yalnızca dashboard_stats (tam durum) saklanır; artımlı mesajlar değil,
                // aksi halde yeni aboneye sahte "yeni olay" gibi replay edilirdi.
                lastByChannel[channel]=msg;
            }
            dispatch(channel,msg);
        }).catch(function(){});
    }

    function connect(){
        if(stopped||authFailed||document.hidden)return;
        if(socket&&(socket.readyState===WebSocket.OPEN||socket.readyState===WebSocket.CONNECTING))return;
        var url=wsUrl();
        currentChannels=channelsQuery();
        try{socket=new WebSocket(url)}catch(e){scheduleReconnect();return}
        socket.onopen=function(){reconnectDelay=2000;lastMessageAt=Date.now()};
        socket.onmessage=function(ev){
            lastMessageAt=Date.now();
            if(typeof ev.data==='string')handleFrame(ev.data);
        };
        socket.onerror=function(){};
        socket.onclose=function(){socket=null;scheduleReconnect()};
    }

    function boot(){
        if(bootScheduled)return;
        bootScheduled=true;
        setTimeout(function(){
            bootScheduled=false;
            if(stopped)return;
            var desired=channelsQuery();
            if(socket&&currentChannels!==desired){
                // İstenen kanal listesi değişti — bağlantıyı yeniden kur.
                try{socket.close(1000,'channel-change')}catch(e){}
                socket=null;
                if(reconnectTimer){clearTimeout(reconnectTimer);reconnectTimer=null}
                reconnectDelay=200;
            }
            connect();
        },40);
    }

    document.addEventListener('visibilitychange',function(){
        if(stopped)return;
        if(document.hidden){
            if(socket){try{socket.close(1000,'tab-hidden')}catch(e){}socket=null}
            if(reconnectTimer){clearTimeout(reconnectTimer);reconnectTimer=null}
        }else if(!authFailed){reconnectDelay=2000;connect()}
    });

    healthTimer=setInterval(function(){
        if(stopped||authFailed||document.hidden)return;
        if(!socket||socket.readyState===WebSocket.CLOSED||socket.readyState===WebSocket.CLOSING){connect();return}
        if(socket.readyState===WebSocket.OPEN&&lastMessageAt&&Date.now()-lastMessageAt>45000){
            try{socket.close(4000,'stale')}catch(e){}
        }
    },10000);

    function subscribe(channel,handler){
        if(!channel||typeof handler!=='function')return function(){};
        if(!subscribers[channel])subscribers[channel]=[];
        subscribers[channel].push(handler);
        if(channel!=='_sys')wanted[channel]=true;
        // Kanalda saklı bir snapshot varsa yeni aboneye hemen ver (anlık dolum):
        // soft-nav ile yeniden oluşturulan kartlar açık soketin bir sonraki
        // push'unu beklemeden mevcut veriyle dolar.
        if(lastByChannel[channel]!==undefined){
            try{handler(lastByChannel[channel])}catch(e){}
        }
        boot();
        return function unsubscribe(){
            var list=subscribers[channel];if(!list)return;
            var i=list.indexOf(handler);if(i>=0)list.splice(i,1);
        };
    }

    function setVisitsPage(page){
        page=parseInt(page,10)||1;
        if(page===visitsPage)return;
        visitsPage=page;
        if(wanted.dashboard_visits)boot();
    }

    function stop(){
        stopped=true;authFailed=true;
        if(reconnectTimer){clearTimeout(reconnectTimer);reconnectTimer=null}
        if(healthTimer){clearInterval(healthTimer);healthTimer=null}
        if(socket){try{socket.close(1000,'stop')}catch(e){}socket=null}
    }

    function restart(){
        if(stopped)return false;
        authFailed=false;lastMessageAt=0;reconnectDelay=200;
        lastByChannel={};   // mod değişmiş olabilir; eski snapshot atılır, taze snapshot beklenir
        if(reconnectTimer){clearTimeout(reconnectTimer);reconnectTimer=null}
        if(socket){var prev=socket;socket=null;prev.onclose=function(){};try{prev.close(1000,'restart')}catch(e){}setTimeout(connect,50)}
        else connect();
        return true;
    }

    window.ultimaWS={
        subscribe:subscribe,
        setVisitsPage:setVisitsPage,
        stop:stop,
        restart:restart,
        dropSnapshots:function(){lastByChannel={}},
        // Bilgi/uyum için:
        isReady:function(){return socket&&socket.readyState===WebSocket.OPEN},
        channels:function(){return Object.keys(wanted)}
    };
    // triplehook toggling gibi yerlerin bağlantıyı yeniden kurabilmesi için:
    window.__ultimaLiveSocketStops=window.__ultimaLiveSocketStops||{};
    window.__ultimaLiveSocketRestarts=window.__ultimaLiveSocketRestarts||{};
    window.__ultimaLiveSocketStops.unified=stop;
    window.__ultimaLiveSocketRestarts.unified=restart;
})();
</script>
