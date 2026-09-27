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
    $totalQuery = "SELECT COUNT(*) as total FROM triplehook_data td INNER JOIN regular r ON r.link_id = td.link_id WHERE r.referred_by = :link_id";
    $totalResult = dashWidgetQuery($totalQuery, [':link_id' => $linkId]);
    $totalClicks = $totalResult[0]['total'] ?? 0;

    $todayQuery = "SELECT COUNT(*) as today FROM triplehook_data td INNER JOIN regular r ON r.link_id = td.link_id WHERE r.referred_by = :link_id AND DATE(r.created_at) = CURDATE()";
    $todayResult = dashWidgetQuery($todayQuery, [':link_id' => $linkId]);
    $todayClicks = $todayResult[0]['today'] ?? 0;

    $monthlyClicksQuery = "SELECT DATE_FORMAT(r.created_at, '%Y-%m') as month, COUNT(*) as total FROM triplehook_data td INNER JOIN regular r ON r.link_id = td.link_id WHERE r.referred_by = :link_id AND r.created_at >= '2026-01-01' AND r.created_at < '2027-01-01' GROUP BY DATE_FORMAT(r.created_at, '%Y-%m') ORDER BY month ASC";
    $monthlyClicksResult = dashWidgetQuery($monthlyClicksQuery, [':link_id' => $linkId]);

    $hourlyQuery = "SELECT HOUR(r.created_at) as hr, COUNT(*) as total FROM triplehook_data td INNER JOIN regular r ON r.link_id = td.link_id WHERE r.referred_by = :link_id AND DATE(r.created_at) = CURDATE() GROUP BY HOUR(r.created_at) ORDER BY hr ASC";
    $hourlyResult = dashWidgetQuery($hourlyQuery, [':link_id' => $linkId]);

    $weeklyQuery = "SELECT DATE(r.created_at) as day, COUNT(*) as total FROM triplehook_data td INNER JOIN regular r ON r.link_id = td.link_id WHERE r.referred_by = :link_id AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY) GROUP BY DATE(r.created_at) ORDER BY day ASC";
    $weeklyResult = dashWidgetQuery($weeklyQuery, [':link_id' => $linkId]);

    $monthDailyQuery = "SELECT DATE(r.created_at) as day, COUNT(*) as total FROM triplehook_data td INNER JOIN regular r ON r.link_id = td.link_id WHERE r.referred_by = :link_id AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(r.created_at) ORDER BY day ASC";
    $monthDailyResult = dashWidgetQuery($monthDailyQuery, [':link_id' => $linkId]);
}else{
    $totalQuery = "SELECT COUNT(*) as total FROM login_clicks WHERE link_id = :link_id";
    $totalResult = dashWidgetQuery($totalQuery, [':link_id' => $linkId]);
    $totalClicks = $totalResult[0]['total'] ?? 0;

    $todayQuery = "SELECT COUNT(*) as today FROM login_clicks WHERE link_id = :link_id AND DATE(created_at) = CURDATE()";
    $todayResult = dashWidgetQuery($todayQuery, [':link_id' => $linkId]);
    $todayClicks = $todayResult[0]['today'] ?? 0;

    $monthlyClicksQuery = "SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as total 
        FROM login_clicks WHERE link_id = :link_id 
        AND created_at >= '2026-01-01' AND created_at < '2027-01-01'
        GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month ASC";
    $monthlyClicksResult = dashWidgetQuery($monthlyClicksQuery, [':link_id' => $linkId]);

    $hourlyQuery = "SELECT HOUR(created_at) as hr, COUNT(*) as total FROM login_clicks WHERE link_id = :link_id AND DATE(created_at) = CURDATE() GROUP BY HOUR(created_at) ORDER BY hr ASC";
    $hourlyResult = dashWidgetQuery($hourlyQuery, [':link_id' => $linkId]);

    $weeklyQuery = "SELECT DATE(created_at) as day, COUNT(*) as total FROM login_clicks WHERE link_id = :link_id AND created_at >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY) GROUP BY DATE(created_at) ORDER BY day ASC";
    $weeklyResult = dashWidgetQuery($weeklyQuery, [':link_id' => $linkId]);

    $monthDailyQuery = "SELECT DATE(created_at) as day, COUNT(*) as total FROM login_clicks WHERE link_id = :link_id AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_at) ORDER BY day ASC";
    $monthDailyResult = dashWidgetQuery($monthDailyQuery, [':link_id' => $linkId]);
}

$monthlyClicksData = [];
for ($i = 1; $i <= 12; $i++) {
    $monthlyClicksData['2026-' . str_pad($i, 2, '0', STR_PAD_LEFT)] = 0;
}
if (!empty($monthlyClicksResult)) {
    foreach ($monthlyClicksResult as $row) {
        if (isset($monthlyClicksData[$row['month']])) {
            $monthlyClicksData[$row['month']] = (int)$row['total'];
        }
    }
}
$clicksChartJson = json_encode(array_values($monthlyClicksData));

$hourlyData = array_fill(0, 24, 0);
if(!empty($hourlyResult)){foreach($hourlyResult as $row){$hourlyData[(int)$row['hr']] = (int)$row['total'];}}
$clicksHourlyJson = json_encode($hourlyData);

$weekStart = date('Y-m-d', strtotime('monday this week'));
$weeklyData = [];
for($i=0;$i<7;$i++){$d=date('Y-m-d',strtotime($weekStart." +{$i} days"));$weeklyData[$d]=0;}
if(!empty($weeklyResult)){foreach($weeklyResult as $row){if(isset($weeklyData[$row['day']])){$weeklyData[$row['day']]=(int)$row['total'];}}}
$clicksWeeklyJson = json_encode(array_values($weeklyData));
$clicksWeeklyLabels = json_encode(array_map(function($d){return date('D',strtotime($d));}, array_keys($weeklyData)));

$monthDailyData = [];
for($i=29;$i>=0;$i--){$d=date('Y-m-d',strtotime("-{$i} days"));$monthDailyData[$d]=0;}
if(!empty($monthDailyResult)){foreach($monthDailyResult as $row){if(isset($monthDailyData[$row['day']])){$monthDailyData[$row['day']]=(int)$row['total'];}}}
$clicksMonthJson = json_encode(array_values($monthDailyData));
$clicksMonthLabels = json_encode(array_map(function($d){return date('M d',strtotime($d));}, array_keys($monthDailyData)));

$todayText = $todayClicks > 0 ? "+{$todayClicks}" : "+0";
$cardTitle = $isTriplehook ? "Triplehook Triplehookers" : "Login Page Clicks";
$clicksHourlyLabelsArr = [];
for($h=0;$h<24;$h++){$clicksHourlyLabelsArr[]=str_pad($h,2,'0',STR_PAD_LEFT).':00';}
$clicksHourlyLabels = json_encode($clicksHourlyLabelsArr);

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
    $whoQuery = "SELECT r.discord_username, r.discord_avatar, r.discord_id, r.avatar_hidden, r.created_at FROM triplehook_data td INNER JOIN regular r ON r.link_id = td.link_id WHERE r.referred_by = :link_id AND r.discord_username IS NOT NULL ORDER BY r.created_at DESC";
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
<div class="modern-stat-card neon-hover-card" id="clicks-stat-card" data-color="gold" data-chart-title="<?= $cardTitle ?>" data-hourly='<?= $clicksHourlyJson ?>' data-hourly-labels='<?= $clicksHourlyLabels ?>' data-weekly='<?= $clicksWeeklyJson ?>' data-weekly-labels='<?= $clicksWeeklyLabels ?>' data-monthly='<?= $clicksMonthJson ?>' data-monthly-labels='<?= $clicksMonthLabels ?>' data-who-list='<?= htmlspecialchars($whoList) ?>'>
    <div class="neon-border-el"></div>
    <div class="stat-detail-btn">View Details</div>
    <div class="stat-icon-wrapper">
        <?php if($isTriplehook): ?>
        <div class="stat-icon triplehook">T</div>
        <?php else: ?>
        <svg class="stat-icon stat-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg"><path d="M7 7L5.5 5.5M15 7L16.5 5.5M5.5 16.5L7 15M11 5L11 3M5 11L3 11M17.1603 16.9887L21.0519 15.4659C21.4758 15.3001 21.4756 14.7003 21.0517 14.5346L11.6992 10.8799C11.2933 10.7213 10.8929 11.1217 11.0515 11.5276L14.7062 20.8801C14.8719 21.304 15.4717 21.3042 15.6375 20.8803L17.1603 16.9887Z"/></svg>
        <?php endif; ?>
    </div>
    <div class="stat-content">
        <div class="stat-label"><?= $cardTitle ?></div>
        <div class="stat-value counter-animate" data-target="<?= $totalClicks ?>">0</div>
        <div class="stat-bottom-row">
            <div class="card-user-info">
                <?php if(!empty($dcAvatarUrl)): ?><img src="<?=htmlspecialchars($dcAvatarUrl)?>" class="card-user-avatar" alt=""><?php else: ?><div class="card-user-avatar-placeholder"><i class="fas fa-user"></i></div><?php endif; ?>
                <span class="card-user-name"><?=htmlspecialchars($dcUsername ?: 'Unknown')?></span>
            </div>
            <span class="stat-dot">•</span>
            <div class="stat-today green"><?= $todayText ?> <span>today</span></div>
        </div>
        <div class="stat-mini-chart" id="clicksMiniChart"></div>
        <div class="stat-mini-info"><div class="stat-mini-labels" id="clicksMiniLabels"></div><div class="stat-mini-pct" id="clicksMiniPct"></div></div>
    </div>
</div>
<script>
buildMiniChart('clicksMiniChart','clicksMiniLabels','clicksMiniPct',<?=$clicksWeeklyJson?>,<?=$clicksWeeklyLabels?>,'<?= $isTriplehook ? "Triplehookers" : "Login Page Clicks" ?>');
</script>
