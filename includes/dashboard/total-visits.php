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

if($isTriplehook){
    $totalQuery = "SELECT COUNT(*) as total FROM regular WHERE referred_by = :link_id";
    $totalResult = dashWidgetQuery($totalQuery, [':link_id' => $linkId]);
    $totalVisits = $totalResult[0]['total'] ?? 0;

    $todayQuery = "SELECT COUNT(*) as today FROM regular WHERE referred_by = :link_id AND DATE(created_at) = CURDATE()";
    $todayResult = dashWidgetQuery($todayQuery, [':link_id' => $linkId]);
    $todayVisits = $todayResult[0]['today'] ?? 0;

    $monthlyVisitsQuery = "SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as total FROM regular WHERE referred_by = :link_id AND created_at >= '2026-01-01' AND created_at < '2027-01-01' GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month ASC";
    $monthlyVisitsResult = dashWidgetQuery($monthlyVisitsQuery, [':link_id' => $linkId]);

    $hourlyQuery = "SELECT HOUR(created_at) as hr, COUNT(*) as total FROM regular WHERE referred_by = :link_id AND DATE(created_at) = CURDATE() GROUP BY HOUR(created_at) ORDER BY hr ASC";
    $hourlyResult = dashWidgetQuery($hourlyQuery, [':link_id' => $linkId]);

    $weeklyQuery = "SELECT DATE(created_at) as day, COUNT(*) as total FROM regular WHERE referred_by = :link_id AND created_at >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY) GROUP BY DATE(created_at) ORDER BY day ASC";
    $weeklyResult = dashWidgetQuery($weeklyQuery, [':link_id' => $linkId]);

    $monthDailyQuery = "SELECT DATE(created_at) as day, COUNT(*) as total FROM regular WHERE referred_by = :link_id AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_at) ORDER BY day ASC";
    $monthDailyResult = dashWidgetQuery($monthDailyQuery, [':link_id' => $linkId]);
}else{
    $totalQuery = "SELECT COUNT(*) as total FROM views WHERE link_id = :link_id";
    $totalResult = dashWidgetQuery($totalQuery, [':link_id' => $linkId]);
    $totalVisits = $totalResult[0]['total'] ?? 0;

    $todayQuery = "SELECT COUNT(*) as today FROM views WHERE link_id = :link_id AND DATE(created_at) = CURDATE()";
    $todayResult = dashWidgetQuery($todayQuery, [':link_id' => $linkId]);
    $todayVisits = $todayResult[0]['today'] ?? 0;

    $monthlyVisitsQuery = "SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as total 
        FROM views WHERE link_id = :link_id 
        AND created_at >= '2026-01-01' AND created_at < '2027-01-01'
        GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month ASC";
    $monthlyVisitsResult = dashWidgetQuery($monthlyVisitsQuery, [':link_id' => $linkId]);

    $hourlyQuery = "SELECT HOUR(created_at) as hr, COUNT(*) as total FROM views WHERE link_id = :link_id AND DATE(created_at) = CURDATE() GROUP BY HOUR(created_at) ORDER BY hr ASC";
    $hourlyResult = dashWidgetQuery($hourlyQuery, [':link_id' => $linkId]);

    $weeklyQuery = "SELECT DATE(created_at) as day, COUNT(*) as total FROM views WHERE link_id = :link_id AND created_at >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY) GROUP BY DATE(created_at) ORDER BY day ASC";
    $weeklyResult = dashWidgetQuery($weeklyQuery, [':link_id' => $linkId]);

    $monthDailyQuery = "SELECT DATE(created_at) as day, COUNT(*) as total FROM views WHERE link_id = :link_id AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_at) ORDER BY day ASC";
    $monthDailyResult = dashWidgetQuery($monthDailyQuery, [':link_id' => $linkId]);
}

$monthlyVisitsData = [];
for ($i = 1; $i <= 12; $i++) {
    $monthlyVisitsData['2026-' . str_pad($i, 2, '0', STR_PAD_LEFT)] = 0;
}
if (!empty($monthlyVisitsResult)) {
    foreach ($monthlyVisitsResult as $row) {
        if (isset($monthlyVisitsData[$row['month']])) {
            $monthlyVisitsData[$row['month']] = (int)$row['total'];
        }
    }
}
$visitsChartJson = json_encode(array_values($monthlyVisitsData));

$hourlyData = array_fill(0, 24, 0);
if(!empty($hourlyResult)){foreach($hourlyResult as $row){$hourlyData[(int)$row['hr']] = (int)$row['total'];}}
$visitsHourlyJson = json_encode($hourlyData);

$weekStart = date('Y-m-d', strtotime('monday this week'));
$weeklyData = [];
for($i=0;$i<7;$i++){$d=date('Y-m-d',strtotime($weekStart." +{$i} days"));$weeklyData[$d]=0;}
if(!empty($weeklyResult)){foreach($weeklyResult as $row){if(isset($weeklyData[$row['day']])){$weeklyData[$row['day']]=(int)$row['total'];}}}
$visitsWeeklyJson = json_encode(array_values($weeklyData));
$visitsWeeklyLabels = json_encode(array_map(function($d){return date('D',strtotime($d));}, array_keys($weeklyData)));

$monthDailyData = [];
for($i=29;$i>=0;$i--){$d=date('Y-m-d',strtotime("-{$i} days"));$monthDailyData[$d]=0;}
if(!empty($monthDailyResult)){foreach($monthDailyResult as $row){if(isset($monthDailyData[$row['day']])){$monthDailyData[$row['day']]=(int)$row['total'];}}}
$visitsMonthJson = json_encode(array_values($monthDailyData));
$visitsMonthLabels = json_encode(array_map(function($d){return date('M d',strtotime($d));}, array_keys($monthDailyData)));

$todayText = $todayVisits > 0 ? "+{$todayVisits}" : "+0";
$cardTitle = $isTriplehook ? "Triplehook Members" : "Total Visits";
$visitsHourlyLabelsArr = [];
for($h=0;$h<24;$h++){$visitsHourlyLabelsArr[]=str_pad($h,2,'0',STR_PAD_LEFT).':00';}
$visitsHourlyLabels = json_encode($visitsHourlyLabelsArr);

$dcUsername = $userData['discord_username'] ?? '';
$dcAvatar = $userData['discord_avatar'] ?? '';
$dcId = $userData['discord_id'] ?? '';
$dcAvatarUrl = '';
if(!empty($dcAvatar)){
    if(strpos($dcAvatar,'https://')===0){$dcAvatarUrl=$dcAvatar;}
    elseif(!empty($dcId)){$ext=(strpos($dcAvatar,'a_')===0)?'gif':'png';$dcAvatarUrl='https://cdn.discordapp.com/avatars/'.$dcId.'/'.$dcAvatar.'.'.$ext;}
}

$whoList = '[]';
if($isTriplehook){
    $whoQuery = "SELECT r.discord_username, r.discord_avatar, r.discord_id, r.avatar_hidden, r.created_at FROM regular r WHERE r.referred_by = :link_id AND r.discord_username IS NOT NULL ORDER BY r.created_at DESC";
    $whoResult = dashWidgetQuery($whoQuery, [':link_id' => $linkId]);
    if(!empty($whoResult)){
        $whoArr = [];
        foreach($whoResult as $w){
            $wHid = ($w['avatar_hidden'] ?? 0) == 1;
            $wAv = '';
            if($wHid){$wAv = 'https://app.ultima.cl/images/hide.png';}
            elseif(!empty($w['discord_avatar'])){
                if(strpos($w['discord_avatar'],'https://')===0){$wAv=$w['discord_avatar'];}
                elseif(!empty($w['discord_id'])){$wExt=(strpos($w['discord_avatar'],'a_')===0)?'gif':'png';$wAv='https://cdn.discordapp.com/avatars/'.$w['discord_id'].'/'.$w['discord_avatar'].'.'.$wExt;}
            }
            $whoArr[] = ['n'=>$wHid?'Anonymous':($w['discord_username']??'Unknown'),'a'=>$wAv,'d'=>$w['created_at']??''];
        }
        $whoList = json_encode($whoArr);
    }
}
?>
<div class="modern-stat-card neon-hover-card" id="visits-stat-card" data-color="gold" data-chart-title="<?= $cardTitle ?>" data-hourly='<?= $visitsHourlyJson ?>' data-hourly-labels='<?= $visitsHourlyLabels ?>' data-weekly='<?= $visitsWeeklyJson ?>' data-weekly-labels='<?= $visitsWeeklyLabels ?>' data-monthly='<?= $visitsMonthJson ?>' data-monthly-labels='<?= $visitsMonthLabels ?>' data-who-list='<?= htmlspecialchars($whoList) ?>'>
    <div class="neon-border-el"></div>
    <div class="stat-detail-btn">View Details</div>
    <div class="stat-icon-wrapper">
        <?php if($isTriplehook): ?>
        <div class="stat-icon triplehook">T</div>
        <?php else: ?>
        <svg class="stat-icon stat-icon-svg" viewBox="0 -2.96 15.929 15.929" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M-3.768,6.232l-.416-.416A9.609,9.609,0,0,0-11,2.993a9.609,9.609,0,0,0-6.816,2.823l-.416.416a2.5,2.5,0,0,0,0,3.536l.416.416A9.609,9.609,0,0,0-11,13.007a9.609,9.609,0,0,0,6.816-2.823l.416-.416A2.5,2.5,0,0,0-3.768,6.232ZM-11,4a.5.5,0,0,1,.5.5A.5.5,0,0,1-11,5a2,2,0,0,0-2,2,.5.5,0,0,1-.5.5A.5.5,0,0,1-14,7,3,3,0,0,1-11,4Zm6.525,5.061-.416.416A8.581,8.581,0,0,1-11,12.007a8.581,8.581,0,0,1-6.109-2.53l-.416-.416A1.493,1.493,0,0,1-17.964,8a1.493,1.493,0,0,1,.439-1.061l.416-.416A8.624,8.624,0,0,1-14.183,4.6,3.964,3.964,0,0,0-15,7a4,4,0,0,0,4,4A4,4,0,0,0-7,7a3.964,3.964,0,0,0-.817-2.4A8.624,8.624,0,0,1-4.891,6.523l.416.416A1.493,1.493,0,0,1-4.036,8,1.493,1.493,0,0,1-4.475,9.061Z" transform="translate(18.965 -2.993)"/></svg>
        <?php endif; ?>
    </div>
    <div class="stat-content">
        <div class="stat-label"><?= $cardTitle ?></div>
        <div class="stat-value counter-animate" data-target="<?= $totalVisits ?>">0</div>
        <div class="stat-bottom-row">
            <div class="card-user-info">
                <?php if(!empty($dcAvatarUrl)): ?><img src="<?=htmlspecialchars($dcAvatarUrl)?>" class="card-user-avatar" alt=""><?php else: ?><div class="card-user-avatar-placeholder"><i class="fas fa-user"></i></div><?php endif; ?>
                <span class="card-user-name"><?=htmlspecialchars($dcUsername ?: 'Unknown')?></span>
            </div>
            <span class="stat-dot">&bull;</span>
            <div class="stat-today green"><?= $todayText ?> <span>today</span></div>
        </div>
        <div class="stat-mini-chart" id="visitsMiniChart"></div>
        <div class="stat-mini-info"><div class="stat-mini-labels" id="visitsMiniLabels"></div><div class="stat-mini-pct" id="visitsMiniPct"></div></div>
    </div>
</div>
<script>
buildMiniChart('visitsMiniChart','visitsMiniLabels','visitsMiniPct',<?=$visitsWeeklyJson?>,<?=$visitsWeeklyLabels?>,'<?= $isTriplehook ? "Members" : "Visits" ?>');
</script>
