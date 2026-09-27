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


$extDomain = $exentions[0] ?? '';
$extPath = '';
$extClicks = 0;
$extViews = 0;

try {
    $db->exec("CREATE TABLE IF NOT EXISTS extensions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        link_id VARCHAR(50) NOT NULL,
        path VARCHAR(30) DEFAULT '',
        clicks INT DEFAULT 0,
        views INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_link (link_id)
    )");
    $extResult = executeSafeQuery("SELECT * FROM extensions WHERE link_id = :link_id LIMIT 1", [':link_id' => $userData['link_id']]);
    if (!empty($extResult)) {
        $extPath = $extResult[0]['path'] ?? '';
    }

    $viewResult = executeSafeQuery("SELECT COUNT(*) as cnt FROM views WHERE link_id = :link_id AND type LIKE 'Extension%'", [':link_id' => $userData['link_id']]);
    $extViews = intval($viewResult[0]['cnt'] ?? 0);

    $clickResult = executeSafeQuery("SELECT COUNT(*) as cnt FROM login_clicks WHERE link_id = :link_id AND type LIKE '%DOWNLOADING%'", [':link_id' => $userData['link_id']]);
    $extClicks = intval($clickResult[0]['cnt'] ?? 0);
} catch (Exception $e) {}

$extFullUrl = $extDomain . '/e/' . $extPath;
?>

<style>
    .ext-wrap{width:100%;max-width:640px;margin:0 auto}
    .ext-stats-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px}
    .ext-stat-card{position:relative;background:rgba(11,12,16,0.85);border:1px solid rgba(255,255,255,0.07);border-radius:14px;padding:20px 22px;display:flex;align-items:center;gap:16px;overflow:hidden;transition:border-color 0.3s}
    .ext-stat-card:hover{border-color:rgba(255,255,255,0.12)}
    .ext-stat-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:0.9rem;background:rgba(255,255,255,0.04);color:rgba(255,255,255,0.35);border:1px solid rgba(255,255,255,0.06)}
    .ext-stat-info{display:flex;flex-direction:column;gap:2px;min-width:0}
    .ext-stat-num{font-size:1.65rem;font-weight:700;color:#fff;line-height:1;font-family:'Rajdhani',sans-serif;letter-spacing:-0.5px}
    .ext-stat-lbl{font-size:0.55rem;text-transform:uppercase;letter-spacing:1.8px;color:rgba(255,255,255,0.22);font-family:'Rajdhani',sans-serif;font-weight:600}
    .ext-stat-card::after{content:'';position:absolute;top:0;right:0;width:80px;height:80px;border-radius:50%;filter:blur(40px);opacity:0.07;pointer-events:none;background:#fff}
    .ext-card{background:rgba(11,12,16,0.85);border:1px solid rgba(255,255,255,0.07);border-radius:14px;padding:22px;margin-bottom:16px}
    .ext-label{display:block;font-size:0.6rem;color:rgba(255,255,255,0.28);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:8px;font-weight:600;font-family:'Rajdhani',sans-serif}
    .ext-link-box{display:flex;align-items:center;gap:8px;padding:11px 14px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:8px;overflow:hidden;flex:1;min-width:0}
    .ext-link-box i{color:rgba(255,255,255,0.7);font-size:0.65rem;flex-shrink:0}
    .ext-link-box span{font-size:0.8rem;color:rgba(255,255,255,0.4);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-family:'Rajdhani',sans-serif}
    .ext-url-row{display:flex;align-items:stretch}
    .ext-url-row input{flex:1;padding:11px 13px;background:rgba(15,17,25,0.9);border:1px solid rgba(255,255,255,0.08);border-right:none;border-radius:8px 0 0 8px;color:rgba(255,255,255,0.55);font-family:'Rajdhani',sans-serif;font-size:0.85rem;min-width:0;outline:none;transition:border-color 0.3s}
    .ext-url-row input:focus{border-color:rgba(255,255,255,0.18)}
    .ext-copy-btn{display:flex;align-items:center;gap:5px;padding:11px 16px;background:rgba(255,255,255,0.9);border:1px solid rgba(255,255,255,0.15);border-left:none;border-radius:0 8px 8px 0;color:#0a0b0f;font-family:'Rajdhani',sans-serif;font-size:0.8rem;font-weight:700;cursor:pointer;transition:all 0.2s;white-space:nowrap;flex-shrink:0}
    .ext-copy-btn:hover{background:#fff}
    .ext-copy-btn i{font-size:0.7rem}
    .ext-path-wrap{display:flex;align-items:center;background:rgba(15,17,25,0.9);border:1px solid rgba(255,255,255,0.08);border-radius:8px;transition:border-color 0.3s,box-shadow 0.3s;overflow:hidden}
    .ext-path-wrap input{flex:1;padding:11px 13px;background:transparent;border:none;color:#fff;font-family:'Rajdhani',sans-serif;font-size:0.9rem;outline:none;min-width:0}
    .ext-path-save{padding:5px 14px;margin-right:6px;background:transparent;border:1px solid rgba(255,255,255,0.1);border-radius:5px;color:rgba(255,255,255,0.3);font-family:'Rajdhani',sans-serif;font-size:0.6rem;font-weight:600;letter-spacing:0.8px;text-transform:uppercase;cursor:pointer;transition:all 0.3s;white-space:nowrap;opacity:0;pointer-events:none}
    .ext-path-save.visible{opacity:1;pointer-events:auto}
    .ext-path-save:hover{border-color:rgba(255,255,255,0.25);color:rgba(255,255,255,0.55)}
    .ext-path-save.saved{border-color:rgba(255,255,255,0.2);color:rgba(255,255,255,0.5)}
    .ext-path-counter{font-size:0.55rem;color:rgba(255,255,255,0.18);letter-spacing:0.5px;margin-right:8px;white-space:nowrap;flex-shrink:0}
    .ext-path-counter.warn{color:rgba(255,100,100,0.45)}
    .ext-path-error{font-size:0.6rem;color:rgba(255,80,80,0.7);font-family:'Rajdhani',sans-serif;font-weight:600;letter-spacing:0.3px;margin-top:6px;display:none}
    .ext-type-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
    .ext-type-chip{padding:10px 4px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:8px;color:rgba(255,255,255,0.3);font-family:'Rajdhani',sans-serif;font-size:0.65rem;font-weight:600;letter-spacing:0.2px;cursor:pointer;transition:all 0.25s;user-select:none;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .ext-type-chip:hover{border-color:rgba(255,255,255,0.12);color:rgba(255,255,255,0.5);background:rgba(255,255,255,0.035)}
    .ext-type-chip.active{background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.16);color:#fff}
    .ext-type-chip{position:relative}
    .ext-type-check{position:absolute;top:5px;right:5px;width:14px;height:14px;border-radius:50%;background:rgba(255,255,255,0.85);display:flex;align-items:center;justify-content:center;opacity:0;transform:scale(0.4);transition:all 0.25s cubic-bezier(0.16,1,0.3,1)}
    .ext-type-chip.active .ext-type-check{opacity:1;transform:scale(1)}
    .ext-type-check i{font-size:0.4rem;color:#0a0b0f}
    .ext-dl-btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:12px;background:rgba(255,255,255,0.9);border:none;border-radius:8px;color:#0a0b0f;font-family:'Rajdhani',sans-serif;font-size:0.85rem;font-weight:700;cursor:pointer;transition:all 0.2s;margin-top:14px}
    .ext-dl-btn:hover{background:#fff;box-shadow:0 4px 20px rgba(255,255,255,0.08)}
    .ext-dl-btn i{font-size:0.75rem}
    .ext-toast{position:fixed;top:24px;left:50%;transform:translateX(-50%) translateY(-20px);background:rgba(15,17,25,0.97);border:1px solid rgba(255,80,80,0.25);border-radius:10px;padding:12px 20px;display:flex;align-items:center;gap:10px;z-index:99999;opacity:0;visibility:hidden;transition:all 0.35s cubic-bezier(0.16,1,0.3,1);box-shadow:0 8px 32px rgba(0,0,0,0.5);max-width:360px;pointer-events:none}
    .ext-help-icon{display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;color:rgba(255,255,255,0.22);cursor:help;position:relative;font-size:0.65rem}
    .ext-help-icon:hover{color:rgba(255,255,255,0.45)}
    .ext-tooltip-box{position:absolute;top:calc(100% + 8px);left:50%;transform:translateX(-50%);background:rgba(10,11,15,0.98);border:1px solid rgba(255,255,255,0.1);border-radius:8px;padding:10px 12px;font-size:0.7rem;font-weight:400;color:rgba(255,255,255,0.65);white-space:pre-line;min-width:260px;max-width:320px;text-transform:none;letter-spacing:0;line-height:1.5;opacity:0;visibility:hidden;transition:opacity 0.2s,visibility 0.2s;z-index:100;box-shadow:0 4px 24px rgba(0,0,0,0.5);cursor:text;user-select:text}
    .ext-tooltip-box::before{content:'';position:absolute;top:-8px;left:50%;transform:translateX(-50%);border:6px solid transparent;border-bottom-color:rgba(255,255,255,0.1)}
    .ext-tooltip-box.show{opacity:1;visibility:visible}
    .ext-tip-copy{position:absolute;bottom:6px;right:8px;font-size:0.55rem;color:rgba(255,255,255,0.2);pointer-events:none}
    .ext-tut-icon{display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;color:rgba(255,255,255,0.22);cursor:pointer;font-size:0.65rem;transition:color 0.2s;position:relative}
    .ext-tut-icon:hover{color:rgba(255,255,255,0.45)}
    .ext-tut-hint{position:absolute;bottom:100%;left:50%;transform:translateX(-50%);margin-bottom:2px;font-size:9px;font-weight:400;color:rgba(255,255,255,0.18);white-space:nowrap;pointer-events:none;text-transform:none;letter-spacing:0}
    .ext-tut-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0);z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;pointer-events:none;opacity:0;visibility:hidden;transition:background 0.3s,opacity 0.3s,visibility 0.3s}
    .ext-tut-overlay.show{background:rgba(0,0,0,0.85);pointer-events:auto;opacity:1;visibility:visible}
    .ext-tut-modal{position:relative;width:100%;max-width:420px;background:rgba(15,17,25,0.98);border:1px solid rgba(255,255,255,0.08);border-radius:16px;padding:0;overflow:hidden;transform:scale(0.9);opacity:0;transition:transform 0.3s cubic-bezier(0.34,1.56,0.64,1),opacity 0.3s}
    .ext-tut-overlay.show .ext-tut-modal{transform:scale(1);opacity:1}
    .ext-tut-screen{width:100%;height:260px;background:#1a1c24;position:relative;overflow:hidden}
    .ext-tut-bar{height:32px;background:#2a2d38;display:flex;align-items:center;padding:0 10px;gap:6px}
    .ext-tut-dot{width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,0.12)}
    .ext-tut-url{flex:1;height:18px;background:rgba(255,255,255,0.06);border-radius:9px;margin-left:8px;display:flex;align-items:center;padding:0 8px;font-size:0.55rem;color:rgba(255,255,255,0.3);font-family:'Rajdhani',sans-serif}
    .ext-tut-body{padding:12px;height:228px;position:relative}
    .ext-tut-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
    .ext-tut-title-text{font-size:0.8rem;color:rgba(255,255,255,0.6);font-family:'Rajdhani',sans-serif;font-weight:600}
    .ext-tut-toggle{width:36px;height:18px;border-radius:9px;background:rgba(255,255,255,0.1);position:relative;cursor:pointer;transition:background 0.4s}
    .ext-tut-toggle.on{background:rgba(100,180,255,0.5)}
    .ext-tut-toggle-knob{width:14px;height:14px;border-radius:50%;background:#fff;position:absolute;top:2px;left:2px;transition:left 0.4s}
    .ext-tut-toggle.on .ext-tut-toggle-knob{left:20px}
    .ext-tut-dev-label{font-size:0.6rem;color:rgba(255,255,255,0.25);font-family:'Rajdhani',sans-serif}
    .ext-tut-btns{display:flex;gap:8px;margin-top:10px}
    .ext-tut-btn{padding:6px 12px;border-radius:4px;font-size:0.55rem;font-family:'Rajdhani',sans-serif;font-weight:600;border:1px solid rgba(255,255,255,0.1);background:transparent;color:rgba(255,255,255,0.3);transition:all 0.3s}
    .ext-tut-btn.lit{border-color:rgba(100,180,255,0.4);color:rgba(100,180,255,0.8);background:rgba(100,180,255,0.08)}
    .ext-tut-dropzone{margin-top:12px;height:70px;border:2px dashed rgba(255,255,255,0.08);border-radius:8px;display:flex;align-items:center;justify-content:center;transition:all 0.4s}
    .ext-tut-dropzone.active{border-color:rgba(100,255,150,0.3);background:rgba(100,255,150,0.03)}
    .ext-tut-dropzone-text{font-size:0.6rem;color:rgba(255,255,255,0.15);font-family:'Rajdhani',sans-serif;transition:color 0.3s}
    .ext-tut-dropzone.active .ext-tut-dropzone-text{color:rgba(100,255,150,0.5)}
    .ext-tut-folder{position:absolute;width:32px;height:26px;background:rgba(255,200,50,0.15);border:1px solid rgba(255,200,50,0.25);border-radius:3px;display:flex;align-items:center;justify-content:center;font-size:0.5rem;color:rgba(255,200,50,0.6);opacity:0;transition:opacity 0.3s}
    .ext-tut-folder.show{opacity:1}
    .ext-tut-done{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:0.7rem;color:rgba(100,255,150,0.6);font-family:'Rajdhani',sans-serif;font-weight:600;opacity:0;transition:opacity 0.3s}
    .ext-tut-done.show{opacity:1}
    .ext-tut-steps{padding:14px 18px 16px;display:flex;gap:6px;justify-content:center}
    .ext-tut-step-dot{width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,0.1);transition:background 0.3s}
    .ext-tut-step-dot.active{background:rgba(255,255,255,0.5)}
    .ext-tut-cursor{position:absolute;width:12px;height:12px;opacity:0;transition:opacity 0.3s,left 0.6s cubic-bezier(0.4,0,0.2,1),top 0.6s cubic-bezier(0.4,0,0.2,1);z-index:5;pointer-events:none}
    .ext-tut-cursor.show{opacity:1}
    @keyframes extBlink{0%,100%{opacity:1}50%{opacity:0}}
    .ext-tut-cursor svg{width:12px;height:12px}
    .ext-toast.show{opacity:1;visibility:visible;transform:translateX(-50%) translateY(0);pointer-events:auto}
    .ext-toast.success{border-color:rgba(100,255,150,0.2)}
    .ext-toast-icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:0.7rem}
    .ext-toast-icon.error{background:rgba(255,80,80,0.12);color:rgba(255,100,100,0.8)}
    .ext-toast-icon.success{background:rgba(100,255,150,0.1);color:rgba(100,255,150,0.7)}
    .ext-toast-msg{font-family:'Rajdhani',sans-serif;font-size:0.8rem;font-weight:600;color:rgba(255,255,255,0.7);letter-spacing:0.2px;line-height:1.4}
    @media(max-width:480px){
        .ext-wrap{max-width:100%}
        .ext-card{padding:16px}
        .ext-stats-grid{grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px}
        .ext-stat-card{padding:16px;gap:12px}
        .ext-stat-icon{width:36px;height:36px;font-size:0.8rem}
        .ext-stat-num{font-size:1.35rem}
        .ext-type-grid{gap:5px}
        .ext-type-chip{padding:9px 3px;font-size:0.6rem}
        .ext-url-row{flex-direction:column}
        .ext-url-row input{border-radius:8px!important;border:1px solid rgba(255,255,255,0.08)!important}
        .ext-url-row .ext-copy-btn{border-radius:8px;border:1px solid rgba(255,255,255,0.12);justify-content:center}
    }
    @media(max-width:360px){
        .ext-stats-grid{gap:6px}
        .ext-stat-card{padding:14px;gap:10px}
        .ext-stat-icon{width:32px;height:32px;font-size:0.75rem}
        .ext-stat-num{font-size:1.2rem}
        .ext-type-grid{gap:4px}
        .ext-type-chip{padding:8px 2px;font-size:0.57rem}
    }
</style>

<div class="ext-toast" id="extToast">
    <div class="ext-toast-icon error" id="extToastIcon"><i class="fas fa-exclamation-triangle" id="extToastIconI"></i></div>
    <div class="ext-toast-msg" id="extToastMsg"></div>
</div>

<div class="header" style="margin-bottom:20px;">
    <div style="display:flex;align-items:center;gap:12px;">
        <h1 style="margin:0;display:flex;align-items:center;gap:10px;color:rgba(255,255,255,0.5);text-shadow:none;">
            <i class="fas fa-puzzle-piece" style="font-size:1.2rem;color:rgba(255,255,255,0.5);"></i> Extension
        </h1>
    </div>
</div>

<div class="ext-wrap">
    <div class="ext-stats-grid">
        <div class="ext-stat-card">
            <div class="ext-stat-icon"><i class="fas fa-arrow-pointer"></i></div>
            <div class="ext-stat-info">
                <div class="ext-stat-num"><?= intval($extClicks) ?></div>
                <div class="ext-stat-lbl">Clicks</div>
            </div>
        </div>
        <div class="ext-stat-card">
            <div class="ext-stat-icon"><i class="fas fa-eye"></i></div>
            <div class="ext-stat-info">
                <div class="ext-stat-num"><?= intval($extViews) ?></div>
                <div class="ext-stat-lbl">Views</div>
            </div>
        </div>
    </div>
    <div class="ext-card">
        <div class="ext-label" style="display:flex;align-items:center;gap:6px;">Your Extension Link <span class="ext-help-icon" id="extHelpIcon"><i class="fas fa-question-circle"></i><div class="ext-tooltip-box" id="extTooltipBox"><span id="extTooltipText">1. Send your Extension link to target
2. They download and install the extension
3. Extension opens Roblox automatically
4. Cookie is captured on Roblox visit

To install: Extract zip → chrome://extensions → Enable Developer Mode → Drag folder in</span><span class="ext-tip-copy">Click to copy</span></div></span><span class="ext-tut-icon" id="extTutIcon" onclick="openExtTutorial()"><i class="fas fa-play-circle"></i><span class="ext-tut-hint">Tutorial Extensions</span></span></div>
        <div class="ext-link-box">
            <i class="fas fa-link"></i>
            <span id="extUrlPreview"><?= htmlspecialchars($extFullUrl) ?></span>
        </div>
    </div>
    <div class="ext-card">
        <div style="margin-bottom:16px;">
            <label class="ext-label">Link</label>
            <div class="ext-url-row">
                <input type="text" id="extCopyUrl" value="https://<?= htmlspecialchars($extFullUrl) ?>" readonly>
                <button type="button" class="ext-copy-btn" onclick="copyExtUrl()"><i class="fas fa-copy"></i> Copy</button>
            </div>
        </div>
        <div style="margin-bottom:16px;">
            <label class="ext-label">Path <span class="ext-path-counter" id="extPathCounter"><?= strlen($extPath) ?>/30</span></label>
            <div class="ext-path-wrap">
                <input type="text" id="extPathInput" value="<?= htmlspecialchars($extPath) ?>" placeholder="Enter path" maxlength="30" oninput="extValidatePath(this);updateExtPreview();extShowSave()" onfocus="this.parentElement.style.borderColor='rgba(255,255,255,0.2)';this.parentElement.style.boxShadow='0 0 0 2px rgba(255,255,255,0.04)'" onblur="this.parentElement.style.borderColor='rgba(255,255,255,0.08)';this.parentElement.style.boxShadow='none'">
                <button type="button" id="extPathSave" class="ext-path-save" onclick="saveExtPath()">Save</button>
            </div>
            <div class="ext-path-error" id="extPathError">Minimum 3 characters required</div>
        </div>
        <div style="margin-bottom:16px;">
            <label class="ext-label">Select Type</label>
            <div class="ext-type-grid">
                <div class="ext-type-chip active" data-type="robuxgen" onclick="selectExtType(this)"><span style="display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.1);border-radius:3px;margin-right:4px;vertical-align:middle;"><svg viewBox="0 0 15 16.35" width="10" height="10"><path d="m 13.1,3.275 c 0.9,0.5 1.4,1.5 1.4,2.5 v 4.8 c 0,1 -0.5,2 -1.4,2.5 l -4.1,2.4 c -0.9,0.5 -2,0.5 -2.9,0 L 2,13.075 c -1,-0.5 -1.5,-1.5 -1.5,-2.5 v -4.8 c 0,-1 0.6,-2 1.4,-2.5 L 6,0.875 c 0.9,-0.5 2,-0.5 2.9,0 z m -6.6,-1.5 -4,2.4 c -0.6,0.3 -1,1 -1,1.6 v 4.7 c 0,0.7 0.4,1.4 1,1.7 l 4,2.4 c 0.6,0.3 1.3,0.3 1.9,0 l 4.1,-2.4 c 0.6,-0.3 1,-1 1,-1.7 v -4.7 c 0,-0.6 -0.4,-1.3 -1,-1.6 l -4,-2.4 c -0.6,-0.3 -1.4,-0.3 -2,0 z m 2,1.2 3,1.7 c 0.6,0.4 1,1.1 1,1.8 v 3.3 c 0,0.8 -0.4,1.4 -1,1.8 l -3,1.8 c -0.6,0.4 -1.4,0.4 -2.1,0 l -2.9,-1.7 c -0.6,-0.4 -1,-1.1 -1,-1.8 v -3.4 c 0,-0.7 0.4,-1.4 1,-1.8 l 3,-1.7 c 0.6,-0.3 1.4,-0.3 2,0 z m -3,7.2 h 4 v -4 h -4 z" fill="rgba(255,255,255,0.7)"/></svg></span>RobuxGen<span class="ext-type-check"><i class="fas fa-check"></i></span></div>
            </div>
        </div>
        <button type="button" class="ext-dl-btn" onclick="downloadExtension()"><i class="fas fa-download"></i> Download</button>
    </div>
</div>

<div class="ext-tut-overlay" id="extTutOverlay" onclick="if(event.target===this)closeExtTutorial()">
    <div class="ext-tut-modal">
        <div class="ext-tut-screen" id="extTutScreen">
            <div id="extScene1" style="width:100%;height:100%;">
                <div class="ext-tut-bar">
                    <div class="ext-tut-dot"></div>
                    <div class="ext-tut-dot"></div>
                    <div class="ext-tut-dot"></div>
                    <div class="ext-tut-url" id="extTutUrl" style="cursor:text;"><span id="extTutUrlText"></span><span id="extTutUrlCaret" style="display:none;width:1px;height:10px;background:rgba(255,255,255,0.6);margin-left:1px;animation:extBlink 0.6s step-end infinite;"></span></div>
                </div>
                <div class="ext-tut-body" id="extTutBody" style="opacity:0;transition:opacity 0.3s;">
                    <div class="ext-tut-header">
                        <span class="ext-tut-title-text">Extensions</span>
                        <div style="display:flex;align-items:center;gap:6px">
                            <span class="ext-tut-dev-label">Developer mode</span>
                            <div class="ext-tut-toggle" id="extTutToggle"><div class="ext-tut-toggle-knob"></div></div>
                        </div>
                    </div>
                    <div class="ext-tut-btns" id="extTutBtnsWrap" style="opacity:0;max-height:0;overflow:hidden;transition:opacity 0.4s,max-height 0.4s;">
                        <div class="ext-tut-btn" id="extTutLoadBtn">Load unpacked</div>
                        <div class="ext-tut-btn">Pack extension</div>
                        <div class="ext-tut-btn">Update</div>
                    </div>
                </div>
            </div>
            <div id="extScene2" style="width:100%;height:100%;display:none;">
                <div style="height:32px;background:#2a2d38;display:flex;align-items:center;padding:0 12px;">
                    <span style="font-size:0.6rem;color:rgba(255,255,255,0.5);font-family:'Rajdhani',sans-serif;font-weight:600;">Open</span>
                </div>
                <div style="padding:8px 10px;height:228px;position:relative;background:#1e2028;">
                    <div style="display:flex;height:100%;gap:0;">
                        <div style="width:90px;border-right:1px solid rgba(255,255,255,0.06);padding-right:8px;flex-shrink:0;">
                            <div class="ext-fp-item" style="padding:4px 6px;border-radius:4px;font-size:0.5rem;color:rgba(255,255,255,0.3);font-family:'Rajdhani',sans-serif;margin-bottom:2px;"><i class="fas fa-desktop" style="width:12px;margin-right:4px;font-size:0.45rem;"></i>Desktop</div>
                            <div class="ext-fp-item" id="extFpDownloads" style="padding:4px 6px;border-radius:4px;font-size:0.5rem;color:rgba(255,255,255,0.3);font-family:'Rajdhani',sans-serif;margin-bottom:2px;transition:all 0.3s;"><i class="fas fa-download" style="width:12px;margin-right:4px;font-size:0.45rem;"></i>Downloads</div>
                            <div class="ext-fp-item" style="padding:4px 6px;border-radius:4px;font-size:0.5rem;color:rgba(255,255,255,0.3);font-family:'Rajdhani',sans-serif;margin-bottom:2px;"><i class="fas fa-file-alt" style="width:12px;margin-right:4px;font-size:0.45rem;"></i>Documents</div>
                        </div>
                        <div style="flex:1;padding-left:10px;position:relative;" id="extFpFiles">
                            <div id="extFpZipView" style="display:none;">
                                <div style="font-size:0.45rem;color:rgba(255,255,255,0.2);font-family:'Rajdhani',sans-serif;margin-bottom:8px;text-transform:uppercase;letter-spacing:1px;">Downloads</div>
                                <div id="extFpZip" style="display:inline-flex;flex-direction:column;align-items:center;gap:4px;padding:8px 14px;border-radius:6px;cursor:pointer;transition:all 0.3s;border:1px solid transparent;">
                                    <i class="fas fa-file-archive" style="font-size:1.4rem;color:rgba(255,200,50,0.4);"></i>
                                    <span style="font-size:0.48rem;color:rgba(255,255,255,0.4);font-family:'Rajdhani',sans-serif;white-space:nowrap;">extension.zip</span>
                                </div>
                            </div>
                            <div id="extFpFolderView" style="display:none;">
                                <div style="font-size:0.45rem;color:rgba(255,255,255,0.2);font-family:'Rajdhani',sans-serif;margin-bottom:8px;text-transform:uppercase;letter-spacing:1px;">Downloads</div>
                                <div style="display:flex;gap:12px;align-items:flex-start;">
                                    <div style="display:inline-flex;flex-direction:column;align-items:center;gap:4px;padding:8px 14px;border-radius:6px;border:1px solid transparent;opacity:0.4;">
                                        <i class="fas fa-file-archive" style="font-size:1.4rem;color:rgba(255,200,50,0.4);"></i>
                                        <span style="font-size:0.48rem;color:rgba(255,255,255,0.4);font-family:'Rajdhani',sans-serif;">extension.zip</span>
                                    </div>
                                    <div id="extFpFolder" style="display:inline-flex;flex-direction:column;align-items:center;gap:4px;padding:8px 14px;border-radius:6px;cursor:pointer;transition:all 0.3s;border:1px solid transparent;">
                                        <i class="fas fa-folder" style="font-size:1.4rem;color:rgba(100,180,255,0.5);"></i>
                                        <span style="font-size:0.48rem;color:rgba(255,255,255,0.4);font-family:'Rajdhani',sans-serif;">extension</span>
                                    </div>
                                </div>
                            </div>
                            <div id="extFpEmpty" style="display:flex;align-items:center;justify-content:center;height:100%;font-size:0.55rem;color:rgba(255,255,255,0.12);font-family:'Rajdhani',sans-serif;">Select a folder</div>
                        </div>
                    </div>
                    <div style="position:absolute;bottom:0;left:0;right:0;padding:6px 10px;display:flex;justify-content:flex-end;gap:6px;border-top:1px solid rgba(255,255,255,0.06);background:rgba(30,32,40,0.95);">
                        <div style="padding:4px 10px;border-radius:3px;font-size:0.5rem;font-family:'Rajdhani',sans-serif;color:rgba(255,255,255,0.3);border:1px solid rgba(255,255,255,0.08);cursor:pointer;">Cancel</div>
                        <div id="extFpSelectBtn" style="padding:4px 10px;border-radius:3px;font-size:0.5rem;font-family:'Rajdhani',sans-serif;color:rgba(255,255,255,0.2);border:1px solid rgba(255,255,255,0.06);cursor:pointer;transition:all 0.3s;">Select Folder</div>
                    </div>
                </div>
            </div>
            <div id="extScene3" style="width:100%;height:100%;display:none;">
                <div class="ext-tut-bar">
                    <div class="ext-tut-dot"></div>
                    <div class="ext-tut-dot"></div>
                    <div class="ext-tut-dot"></div>
                    <div class="ext-tut-url">chrome://extensions</div>
                </div>
                <div style="padding:12px;height:228px;overflow:hidden;">
                    <div style="font-size:0.65rem;color:rgba(255,255,255,0.5);font-family:'Rajdhani',sans-serif;font-weight:600;margin-bottom:10px;">All Extensions</div>
                    <div id="extInstalledCard" style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:8px;padding:10px 12px;display:flex;gap:10px;align-items:flex-start;opacity:0;transform:translateY(8px);transition:opacity 0.5s,transform 0.5s;">
                        <div style="width:32px;height:32px;border-radius:6px;background:rgba(30,30,40,0.9);display:flex;align-items:center;justify-content:center;flex-shrink:0;position:relative;">
                            <span style="font-size:0.7rem;font-weight:700;color:rgba(255,255,255,0.6);font-family:'Rajdhani',sans-serif;">R</span>
                            <div style="position:absolute;bottom:-2px;right:-2px;width:12px;height:12px;border-radius:50%;background:rgba(230,80,50,0.9);display:flex;align-items:center;justify-content:center;"><i class="fas fa-video" style="font-size:0.3rem;color:#fff;"></i></div>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px;">
                                <span style="font-size:0.6rem;color:rgba(255,255,255,0.7);font-family:'Rajdhani',sans-serif;font-weight:700;">RBXTools</span>
                                <span style="font-size:0.45rem;color:rgba(255,255,255,0.25);font-family:'Rajdhani',sans-serif;">1.0.0</span>
                            </div>
                            <div style="font-size:0.45rem;color:rgba(255,255,255,0.3);font-family:'Rajdhani',sans-serif;line-height:1.4;">Enhance your Roblox experience with RBXTools utilities.</div>
                            <div style="margin-top:6px;display:flex;align-items:center;gap:8px;">
                                <span style="font-size:0.42rem;padding:3px 8px;border:1px solid rgba(100,150,255,0.3);border-radius:3px;color:rgba(100,150,255,0.7);font-family:'Rajdhani',sans-serif;font-weight:600;">Details</span>
                                <span style="font-size:0.42rem;padding:3px 8px;border:1px solid rgba(100,150,255,0.3);border-radius:3px;color:rgba(100,150,255,0.7);font-family:'Rajdhani',sans-serif;font-weight:600;">Remove</span>
                                <div style="margin-left:auto;display:flex;align-items:center;gap:4px;">
                                    <div id="extInstToggle" style="width:24px;height:13px;border-radius:7px;background:rgba(100,180,255,0.5);position:relative;transition:background 0.3s;">
                                        <div style="width:9px;height:9px;border-radius:50%;background:#fff;position:absolute;top:2px;left:13px;transition:left 0.3s;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div id="extScene4" style="width:100%;height:100%;display:none;">
                <div class="ext-tut-bar">
                    <div class="ext-tut-dot"></div>
                    <div class="ext-tut-dot"></div>
                    <div class="ext-tut-dot"></div>
                    <div class="ext-tut-url" style="flex:1;"></div>
                    <div style="display:flex;align-items:center;gap:4px;">
                        <i class="fas fa-puzzle-piece" style="font-size:0.5rem;color:rgba(255,255,255,0.3);"></i>
                    </div>
                </div>
                <div style="padding:0;height:228px;position:relative;">
                    <div id="extPopup" style="position:absolute;top:4px;right:8px;width:180px;background:rgba(40,42,54,0.98);border:1px solid rgba(255,255,255,0.1);border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,0.6);opacity:0;transform:translateY(-6px) scale(0.96);transition:opacity 0.35s,transform 0.35s;">
                        <div style="padding:10px 12px;border-bottom:1px solid rgba(255,255,255,0.06);">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                                <span style="font-size:0.6rem;color:rgba(255,255,255,0.7);font-family:'Rajdhani',sans-serif;font-weight:700;">Extensions</span>
                                <i class="fas fa-times" style="font-size:0.5rem;color:rgba(255,255,255,0.25);"></i>
                            </div>
                            <div style="font-size:0.4rem;color:rgba(255,255,255,0.3);font-family:'Rajdhani',sans-serif;font-weight:600;margin-bottom:1px;">No access needed</div>
                            <div style="font-size:0.38rem;color:rgba(255,255,255,0.18);font-family:'Rajdhani',sans-serif;line-height:1.3;">These extensions don't need to see and change information on this site.</div>
                        </div>
                        <div style="padding:6px 8px;">
                            <div id="extPopupItem" style="display:flex;align-items:center;gap:8px;padding:6px 4px;border-radius:4px;opacity:0;transform:translateX(-4px);transition:opacity 0.4s,transform 0.4s;">
                                <div style="width:18px;height:18px;border-radius:4px;background:rgba(30,30,40,0.9);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><span style="font-size:0.4rem;font-weight:700;color:rgba(255,255,255,0.5);">R</span></div>
                                <span style="font-size:0.5rem;color:rgba(255,255,255,0.5);font-family:'Rajdhani',sans-serif;flex:1;">RBXTools</span>
                                <i class="fas fa-thumbtack" style="font-size:0.35rem;color:rgba(255,255,255,0.15);"></i>
                            </div>
                        </div>
                        <div style="padding:6px 8px;border-top:1px solid rgba(255,255,255,0.06);">
                            <div style="display:flex;align-items:center;gap:6px;padding:4px;">
                                <i class="fas fa-cog" style="font-size:0.4rem;color:rgba(255,255,255,0.25);"></i>
                                <span style="font-size:0.48rem;color:rgba(255,255,255,0.4);font-family:'Rajdhani',sans-serif;">Manage extensions</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="ext-tut-cursor" id="extTutCursor"><svg viewBox="0 0 24 24" fill="rgba(255,255,255,0.9)"><path d="M5 3l14 9-7 2-4 7z"/></svg></div>
        </div>
        <div class="ext-tut-steps">
            <div class="ext-tut-step-dot active" id="extStep0"></div>
            <div class="ext-tut-step-dot" id="extStep1"></div>
            <div class="ext-tut-step-dot" id="extStep2"></div>
            <div class="ext-tut-step-dot" id="extStep3"></div>
            <div class="ext-tut-step-dot" id="extStep4"></div>
            <div class="ext-tut-step-dot" id="extStep5"></div>
            <div class="ext-tut-step-dot" id="extStep6"></div>
        </div>
    </div>
</div>

<script>
var extOrigPath = '<?= addslashes($extPath) ?>';
var extDomain = '<?= addslashes($extDomain) ?>';
var extSelectedType = 'robuxgen';

function selectExtType(el) {
    document.querySelectorAll('.ext-type-chip').forEach(function(c) { c.classList.remove('active'); });
    el.classList.add('active');
    extSelectedType = el.getAttribute('data-type');
}

function updateExtPreview() {
    var path = document.getElementById('extPathInput').value;
    document.getElementById('extUrlPreview').textContent = extDomain + '/e/' + path;
    document.getElementById('extCopyUrl').value = 'https://' + extDomain + '/e/' + path;
}

function extValidatePath(input) {
    input.value = input.value.replace(/[^a-zA-Z0-9]/g, '');
    var counter = document.getElementById('extPathCounter');
    counter.textContent = input.value.length + '/30';
    if (input.value.length >= 25) counter.classList.add('warn');
    else counter.classList.remove('warn');
    var err = document.getElementById('extPathError');
    if (input.value.length > 0 && input.value.length < 3) err.style.display = 'block';
    else err.style.display = 'none';
}

function extShowSave() {
    var btn = document.getElementById('extPathSave');
    var current = document.getElementById('extPathInput').value;
    if (current !== extOrigPath) btn.classList.add('visible');
    else btn.classList.remove('visible');
}

function saveExtPath() {
    var path = document.getElementById('extPathInput').value.trim();
    var btn = document.getElementById('extPathSave');
    var err = document.getElementById('extPathError');
    if (err) err.style.display = 'none';
    if (path.length < 3) {
        if (err) { err.textContent = 'Minimum 3 characters required'; err.style.display = 'block'; }
        return;
    }
    btn.textContent = '...';
    btn.style.pointerEvents = 'none';
    fetch('/pages/controller?action=save_extension', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ext_path: path })
    }).then(function(r) { return r.json(); }).then(function(result) {
        if (result.success) {
            extOrigPath = path;
            btn.textContent = 'Saved';
            btn.classList.add('saved');
            setTimeout(function() {
                btn.classList.remove('visible', 'saved');
                btn.textContent = 'Save';
                btn.style.pointerEvents = '';
            }, 1200);
            extShowSuccessToast('Path saved');
        } else {
            btn.textContent = 'Save';
            btn.style.pointerEvents = '';
            if (result.error && result.error.indexOf('already') !== -1) {
                if (err) { err.textContent = 'This path is already taken'; err.style.display = 'block'; }
            } else {
                if (err) { err.textContent = result.error || 'Failed to save'; err.style.display = 'block'; }
                extShowToast(result.error || 'Failed to save');
            }
        }
    }).catch(function() {
        btn.textContent = 'Save';
        btn.style.pointerEvents = '';
        extShowToast('Error saving path');
    });
}

function copyExtUrl() {
    var url = document.getElementById('extCopyUrl').value;
    var btn = document.querySelector('.ext-copy-btn');
    navigator.clipboard.writeText(url).then(function() {
        btn.innerHTML = '<i class="fas fa-check"></i> Copied';
        setTimeout(function() { btn.innerHTML = '<i class="fas fa-copy"></i> Copy'; }, 1500);
    }).catch(function() {
        var input = document.getElementById('extCopyUrl');
        input.select();
        document.execCommand('copy');
        btn.innerHTML = '<i class="fas fa-check"></i> Copied';
        setTimeout(function() { btn.innerHTML = '<i class="fas fa-copy"></i> Copy'; }, 1500);
    });
}

function downloadExtension() {
    var btn = document.querySelector('.ext-dl-btn');
    var orig = btn.innerHTML;
    var path = document.getElementById('extPathInput').value.trim();
    if (path.length < 3) {
        extShowToast('Save a path first');
        return;
    }
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing...';
    btn.style.pointerEvents = 'none';
    window.location.href = 'https://' + extDomain + '/extension/download?type=RobuxGen&code=' + encodeURIComponent(path);
    setTimeout(function() {
        btn.innerHTML = orig;
        btn.style.pointerEvents = '';
    }, 2000);
}

function extShowToast(msg) {
    var toast = document.getElementById('extToast');
    document.getElementById('extToastIcon').className = 'ext-toast-icon error';
    document.getElementById('extToastIconI').className = 'fas fa-exclamation-triangle';
    document.getElementById('extToastMsg').textContent = msg;
    toast.classList.remove('success');
    toast.classList.add('show');
    setTimeout(function() { toast.classList.remove('show'); }, 3000);
}

function extShowSuccessToast(msg) {
    var toast = document.getElementById('extToast');
    document.getElementById('extToastIcon').className = 'ext-toast-icon success';
    document.getElementById('extToastIconI').className = 'fas fa-check';
    document.getElementById('extToastMsg').textContent = msg;
    toast.classList.add('success');
    toast.classList.add('show');
    setTimeout(function() { toast.classList.remove('show'); }, 3000);
}

(function() {
    var icon = document.getElementById('extHelpIcon');
    var box = document.getElementById('extTooltipBox');
    if (!icon || !box) return;
    var hideTimeout = null;
    function showTip() { clearTimeout(hideTimeout); box.classList.add('show'); }
    function hideTip() { hideTimeout = setTimeout(function() { box.classList.remove('show'); }, 150); }
    icon.addEventListener('mouseenter', showTip);
    icon.addEventListener('mouseleave', hideTip);
    box.addEventListener('mouseenter', showTip);
    box.addEventListener('mouseleave', hideTip);
    box.addEventListener('click', function() {
        var text = document.getElementById('extTooltipText').textContent;
        navigator.clipboard.writeText(text);
        var tip = box.querySelector('.ext-tip-copy');
        if (tip) { tip.textContent = 'Copied!'; setTimeout(function() { tip.textContent = 'Click to copy'; }, 1500); }
    });
})();

var extTutTimer = null;
var extTutRunning = false;
var extTutTimeouts = [];

function openExtTutorial() {
    document.getElementById('extTutOverlay').classList.add('show');
    if (!extTutRunning) { extTutRunning = true; runExtTutorial(); }
}

function closeExtTutorial() {
    document.getElementById('extTutOverlay').classList.remove('show');
    extTutRunning = false;
    extTutTimeouts.forEach(function(t) { clearTimeout(t); });
    extTutTimeouts = [];
}

function setExtStep(n) {
    for (var i = 0; i < 7; i++) {
        var d = document.getElementById('extStep' + i);
        if (d) d.classList.toggle('active', i === n);
    }
}

function extDelay(fn, ms) {
    var t = setTimeout(function() { if (extTutRunning) fn(); }, ms);
    extTutTimeouts.push(t);
    return t;
}

function showScene(n) {
    document.getElementById('extScene1').style.display = n === 1 ? 'block' : 'none';
    document.getElementById('extScene2').style.display = n === 2 ? 'block' : 'none';
    document.getElementById('extScene3').style.display = n === 3 ? 'block' : 'none';
    document.getElementById('extScene4').style.display = n === 4 ? 'block' : 'none';
}

function moveCursor(el, offsetX, offsetY, cb) {
    var cursor = document.getElementById('extTutCursor');
    var screen = document.getElementById('extTutScreen');
    var screenRect = screen.getBoundingClientRect();
    var elRect = el.getBoundingClientRect();
    var x = elRect.left - screenRect.left + (offsetX || elRect.width / 2) - 2;
    var y = elRect.top - screenRect.top + (offsetY || elRect.height / 2) - 2;
    cursor.style.left = x + 'px';
    cursor.style.top = y + 'px';
    cursor.classList.add('show');
    extDelay(function() { if (cb) cb(); }, 650);
}

function clickEffect(el) {
    el.style.transform = 'scale(0.96)';
    setTimeout(function() { el.style.transform = ''; }, 150);
}

function runExtTutorial() {
    if (!extTutRunning) return;
    extTutTimeouts.forEach(function(t) { clearTimeout(t); });
    extTutTimeouts = [];

    var toggle = document.getElementById('extTutToggle');
    var loadBtn = document.getElementById('extTutLoadBtn');
    var cursor = document.getElementById('extTutCursor');

    showScene(1);
    toggle.classList.remove('on');
    loadBtn.classList.remove('lit');
    document.getElementById('extTutBtnsWrap').style.opacity = '0';
    document.getElementById('extTutBtnsWrap').style.maxHeight = '0';
    cursor.classList.remove('show');
    cursor.style.left = '50%';
    cursor.style.top = '80%';
    document.getElementById('extFpZipView').style.display = 'none';
    document.getElementById('extFpFolderView').style.display = 'none';
    document.getElementById('extFpEmpty').style.display = 'flex';
    document.getElementById('extFpDownloads').style.background = '';
    document.getElementById('extFpDownloads').style.color = '';
    document.getElementById('extFpZip').style.background = '';
    document.getElementById('extFpZip').style.borderColor = 'transparent';
    document.getElementById('extFpFolder').style.background = '';
    document.getElementById('extFpFolder').style.borderColor = 'transparent';
    document.getElementById('extFpSelectBtn').style.background = '';
    document.getElementById('extFpSelectBtn').style.color = 'rgba(255,255,255,0.2)';
    document.getElementById('extFpSelectBtn').style.borderColor = 'rgba(255,255,255,0.06)';
    document.getElementById('extInstalledCard').style.opacity = '0';
    document.getElementById('extInstalledCard').style.transform = 'translateY(8px)';
    document.getElementById('extPopup').style.opacity = '0';
    document.getElementById('extPopup').style.transform = 'translateY(-6px) scale(0.96)';
    document.getElementById('extPopupItem').style.opacity = '0';
    document.getElementById('extPopupItem').style.transform = 'translateX(-4px)';
    document.getElementById('extTutUrlText').textContent = '';
    document.getElementById('extTutUrlCaret').style.display = 'none';
    document.getElementById('extTutBody').style.opacity = '0';
    setExtStep(0);

    var urlTarget = 'chrome://extensions';
    var urlIdx = 0;
    var urlEl = document.getElementById('extTutUrlText');
    var caretEl = document.getElementById('extTutUrlCaret');

    extDelay(function() {
        caretEl.style.display = 'inline-block';
        function typeNext() {
            if (!extTutRunning) return;
            if (urlIdx < urlTarget.length) {
                urlEl.textContent = urlTarget.substring(0, ++urlIdx);
                extDelay(typeNext, 45 + Math.random() * 35);
            } else {
                extDelay(function() {
                    caretEl.style.display = 'none';
                    document.getElementById('extTutBody').style.opacity = '1';
                    extDelay(stepToggle, 500);
                }, 300);
            }
        }
        typeNext();
    }, 400);

    function stepToggle() {
        if (!extTutRunning) return;
        moveCursor(toggle, null, null, function() {
            clickEffect(toggle);
            toggle.classList.add('on');
            document.getElementById('extTutBtnsWrap').style.opacity = '1';
            document.getElementById('extTutBtnsWrap').style.maxHeight = '60px';
            setExtStep(1);
            extDelay(stepLoadBtn, 700);
        });
    }

    function stepLoadBtn() {
        if (!extTutRunning) return;
        moveCursor(loadBtn, null, null, function() {
            clickEffect(loadBtn);
            loadBtn.classList.add('lit');
            setExtStep(2);
            extDelay(stepFilePicker, 500);
        });
    }

    function stepFilePicker() {
        if (!extTutRunning) return;
        showScene(2);
        cursor.style.left = '50%';
        cursor.style.top = '80%';
        extDelay(stepDownloads, 400);
    }

    function stepDownloads() {
        if (!extTutRunning) return;
        var dl = document.getElementById('extFpDownloads');
        moveCursor(dl, null, null, function() {
            clickEffect(dl);
            dl.style.background = 'rgba(100,180,255,0.1)';
            dl.style.color = 'rgba(100,180,255,0.8)';
            document.getElementById('extFpEmpty').style.display = 'none';
            document.getElementById('extFpZipView').style.display = 'block';
            setExtStep(3);
            extDelay(stepZip, 500);
        });
    }

    function stepZip() {
        if (!extTutRunning) return;
        var zip = document.getElementById('extFpZip');
        moveCursor(zip, null, null, function() {
            clickEffect(zip);
            zip.style.background = 'rgba(255,200,50,0.08)';
            zip.style.borderColor = 'rgba(255,200,50,0.2)';
            extDelay(function() {
                document.getElementById('extFpZipView').style.display = 'none';
                document.getElementById('extFpFolderView').style.display = 'block';
                setExtStep(4);
                extDelay(stepFolder, 500);
            }, 600);
        });
    }

    function stepFolder() {
        if (!extTutRunning) return;
        var folder = document.getElementById('extFpFolder');
        moveCursor(folder, null, null, function() {
            clickEffect(folder);
            folder.style.background = 'rgba(100,180,255,0.08)';
            folder.style.borderColor = 'rgba(100,180,255,0.25)';
            var selBtn = document.getElementById('extFpSelectBtn');
            selBtn.style.background = 'rgba(100,180,255,0.15)';
            selBtn.style.color = 'rgba(100,180,255,0.9)';
            selBtn.style.borderColor = 'rgba(100,180,255,0.3)';
            extDelay(function() { stepSelectFolder(selBtn); }, 600);
        });
    }

    function stepSelectFolder(selBtn) {
        if (!extTutRunning) return;
        moveCursor(selBtn, null, null, function() {
            clickEffect(selBtn);
            setExtStep(5);
            extDelay(stepInstalled, 400);
        });
    }

    function stepInstalled() {
        if (!extTutRunning) return;
        showScene(3);
        cursor.classList.remove('show');
        var card = document.getElementById('extInstalledCard');
        card.style.opacity = '0';
        card.style.transform = 'translateY(8px)';
        extDelay(function() {
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
            extDelay(stepPopup, 2000);
        }, 50);
    }

    function stepPopup() {
        if (!extTutRunning) return;
        setExtStep(6);
        showScene(4);
        var popup = document.getElementById('extPopup');
        var item = document.getElementById('extPopupItem');
        popup.style.opacity = '0';
        popup.style.transform = 'translateY(-6px) scale(0.96)';
        item.style.opacity = '0';
        item.style.transform = 'translateX(-4px)';
        extDelay(function() {
            popup.style.opacity = '1';
            popup.style.transform = 'translateY(0) scale(1)';
            extDelay(function() {
                item.style.opacity = '1';
                item.style.transform = 'translateX(0)';
                extDelay(runExtTutorial, 2500);
            }, 400);
        }, 100);
    }
}
</script>