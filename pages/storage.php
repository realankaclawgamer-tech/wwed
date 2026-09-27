<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('token');
    session_start();
}

include '../libs/configuration.php';
/* libs/functions.php is no longer loaded */
include '../libs/connection.php';
// Cache TAMAMEN kaldırıldı — her istek anlık DB'ye gider, sidebar/storage
// sayaçları WebSocket 'sidebar' kanalıyla anlık senkron.
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
    // Cache kaldırıldı — anlık scope her seferinde okunur.
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

$currentPage = 'storage';
$link_id = $userData['link_id'] ?? 0;
$isTriplehook = (int)($userData['triplehook'] ?? 0) === 1;
$_SESSION['triplehook'] = $isTriplehook ? 'True' : 'False';

// Cache YOK — anlık DB kontrolü.
$triplehookCheck = ultimaPageQuery("SELECT 1 FROM triplehook_data WHERE link_id=:link_id LIMIT 1", [':link_id' => $link_id]);
$hasTriplehook = !empty($triplehookCheck);
$_SESSION['triplehook'] = $isTriplehook ? 'True' : 'False';


$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if($isTriplehook){
    $scopeIds = getTriplehookScopeIdsCached($link_id);
    if (empty($scopeIds)) {
        $whereConditions = ["link_id IN (
            SELECT link_id FROM regular WHERE referred_by = :scope_lid1
            UNION
            SELECT r2.link_id FROM regular r2 WHERE r2.referred_by IN (
                SELECT td.link_id FROM triplehook_data td
                INNER JOIN regular r ON r.link_id = td.link_id
                WHERE r.referred_by = :scope_lid2
            )
        )"];
        $params = [':scope_lid1' => $link_id, ':scope_lid2' => $link_id];
    } elseif (count($scopeIds) > 250) {
        $whereConditions = ["link_id IN (
            SELECT link_id FROM regular WHERE referred_by = :scope_lid1
            UNION
            SELECT r2.link_id FROM regular r2 WHERE r2.referred_by IN (
                SELECT td.link_id FROM triplehook_data td
                INNER JOIN regular r ON r.link_id = td.link_id
                WHERE r.referred_by = :scope_lid2
            )
        )"];
        $params = [':scope_lid1' => $link_id, ':scope_lid2' => $link_id];
    } else {
        $ph = [];
        $params = [];
        foreach ($scopeIds as $i => $lid) {
            $k = ':sid' . $i;
            $ph[] = $k;
            $params[$k] = $lid;
        }
        $whereConditions = ["link_id IN (" . implode(',', $ph) . ")"];
    }
}else{
    $whereConditions = ["link_id = :link_id"];
    $params = [':link_id' => $link_id];
}

if (empty($_SESSION['storage_token']) || !is_string($_SESSION['storage_token'])) {
    $_SESSION['storage_token'] = bin2hex(random_bytes(32));
}
$storageToken = $_SESSION['storage_token'];

// NOT: session_write_close çağrısı aşağıya taşındı (cachedHits okunduktan sonra)

switch ($filter) {
    case 'robux':
        $whereConditions[] = "robux > 0";
        $orderBy = "robux DESC";
        break;
    case 'rap':
        $whereConditions[] = "rap > 0";
        $orderBy = "rap DESC";
        break;
    case 'summary':
        $whereConditions[] = "summary > 0";
        $orderBy = "summary DESC";
        break;
    case 'recent':
        $orderBy = "created_at DESC";
        break;
    default:
        $orderBy = "created_at DESC";
        break;
}

if (!empty($search)) {
    $whereConditions[] = "username LIKE :search";
    $params[':search'] = '%' . $search . '%';
}

$whereClause = implode(' AND ', $whereConditions);
if (!$isTriplehook) {
    $whereClause .= " AND (IFNULL(hidden,0) = 0 OR hidden_link_id = :hidden_viewer)";
    $params[':hidden_viewer'] = $link_id;
}

$selectCols = "id, username, avatar_url, robux, summary, rap, password, cookie, manual_key, created_at, UNIX_TIMESTAMP(created_at) as created_ts, hidden, hidden_link_id";

// Session'ı erken kapatıyoruz (yazma kilidi açılsın diye) — sayaçlar
// bundan sonra yalnızca DB'den okunacak, cache YOK.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Toplam sayaç: sidebar formülüyle aynı SQL. Filter 'all' ve search boşken
// bu değer sidebar badge ile birebir eşleşir. WebSocket 'sidebar' kanalı
// yeni hit geldiğinde JS tarafında anlık günceller (aşağıdaki subscribe).
$countParams = $params;
$countResult = ultimaPageQuery("SELECT COUNT(*) as total FROM hits WHERE $whereClause", $countParams);
$totalRecords = (int)($countResult[0]['total'] ?? 0);
$totalPages = max(1, (int)ceil(max($totalRecords, 1) / $perPage));

$params[':limit'] = $perPage;
$params[':offset'] = $offset;
$hitsQuery = "SELECT $selectCols FROM hits WHERE $whereClause ORDER BY $orderBy LIMIT :limit OFFSET :offset";
$hitsResult = ultimaPageQuery($hitsQuery, $params);
if (!is_array($hitsResult)) $hitsResult = [];

$nowTs = time();

function buildFilterUrl($newFilter, $currentSearch) {
    $params = ['filter' => $newFilter];
    if (!empty($currentSearch)) {
        $params['search'] = $currentSearch;
    }
    return '?' . http_build_query($params);
}

function timeAgo($seconds) {
    $diff = max(0, (int)$seconds);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) {
        $h = floor($diff / 3600);
        $m = floor(($diff % 3600) / 60);
        return $m > 0 ? $h . 'h ' . $m . 'm ago' : $h . 'h ago';
    }
    if ($diff < 2592000) return floor($diff / 86400) . 'd ago';
    return floor($diff / 2592000) . 'mo ago';
}
if (empty($storageToken)) {
    $storageToken = (string)($_SESSION['storage_token'] ?? '');
}

$_emojiData = [];
$_emojiDir = $_SERVER['DOCUMENT_ROOT'] . '/api/emojis';
if (is_dir($_emojiDir)) {
    $_emojiFiles = array_values(array_filter(glob($_emojiDir . '/*') ?: [], 'is_file'));
    $_emojiSig = [];
    foreach ($_emojiFiles as $_ef) {
        $_emojiSig[$_ef] = (@filesize($_ef) ?: 0) . ':' . (@filemtime($_ef) ?: 0);
    }
    $_emojiRoot = realpath($_emojiDir) ?: $_emojiDir;
    $_emojiCacheFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ultima_emoji_cache_' . substr(hash('sha256', $_emojiRoot), 0, 16) . '.json';
    $_emojiCache = is_file($_emojiCacheFile) ? json_decode((string)@file_get_contents($_emojiCacheFile), true) : null;
    if (is_array($_emojiCache) && ($_emojiCache['sig'] ?? null) === $_emojiSig && is_array($_emojiCache['data'] ?? null)) {
        $_emojiData = $_emojiCache['data'];
    } else {
    foreach ($_emojiFiles as $_ef) {
        if (!is_file($_ef)) continue;
        $_einfo = pathinfo($_ef);
        $_eext = strtolower($_einfo['extension'] ?? '');
        if (!in_array($_eext, ['png', 'gif', 'webp', 'jpg', 'jpeg'])) continue;
        $_emime = $_eext === 'gif' ? 'image/gif' : ($_eext === 'webp' ? 'image/webp' : ($_eext === 'jpg' || $_eext === 'jpeg' ? 'image/jpeg' : 'image/png'));
        $_ename = $_einfo['filename'];
        $_emojiData[$_ename] = 'data:' . $_emime . ';base64,' . base64_encode(file_get_contents($_ef));
    }
        @file_put_contents($_emojiCacheFile, json_encode(['sig' => $_emojiSig, 'data' => $_emojiData], JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
$_emojiJson = json_encode($_emojiData, JSON_UNESCAPED_SLASHES);

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Storage</title>
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
            --primary-gold: #FFD700;
            --secondary-gold: #FDB931;
            --dark-bg: #07070b;
            --darker-bg: #050507;
            --card-bg: rgba(11, 12, 16, 0.85);
            --border-color: rgba(255, 215, 0, 0.3);
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
            overflow: hidden;
            pointer-events: none;
        }

        .animated-bg .lightning,
        .animated-bg .geo-container,
        .animated-bg .geo-line,
        .animated-bg .connection-point,
        .animated-bg .particle,
        .animated-bg .glow-orb,
        .animated-bg .grid-overlay { display: none !important; }

        .lightning {
            position: absolute;
            width: 1px;
            background: linear-gradient(to bottom, transparent, rgba(255,255,255,0.1), transparent);
            opacity: 0;
            animation: lightning 5s infinite;
        }

        .lightning:nth-child(1) { left: 20%; height: 150px; animation-delay: 0s; }
        .lightning:nth-child(2) { left: 50%; height: 180px; animation-delay: 2s; }
        .lightning:nth-child(3) { left: 80%; height: 140px; animation-delay: 4s; }

        @keyframes lightning {
            0%, 90%, 100% { opacity: 0; transform: translateY(0); }
            2%, 6% { opacity: 0.5; }
            15% { transform: translateY(100vh); opacity: 0; }
        }

        .geo-line { position: absolute; background: linear-gradient(to bottom, transparent, rgba(255,255,255,0.03), transparent); }
        .geo-line:nth-child(4) { width: 1px; height: 100%; left: 15%; }
        .geo-line:nth-child(5) { width: 1px; height: 100%; left: 35%; }
        .geo-line:nth-child(6) { width: 1px; height: 100%; left: 55%; }
        .geo-line:nth-child(7) { width: 1px; height: 100%; left: 75%; }

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

        .content-wrapper {
            width: 100%;
            max-width: 1400px;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .page-header {
            animation: fadeInUp 0.12s ease forwards;
            opacity: 0;
        }

        .table-wrapper {
            animation: fadeInUp 0.12s ease forwards;
            animation-delay: 0.01s;
            opacity: 0;
        }

        .pagination {
            animation: fadeInUp 0.12s ease forwards;
            animation-delay: 0.02s;
            opacity: 0;
        }

        .data-table tbody tr {
            animation: fadeInUp 0.12s ease forwards;
            opacity: 0;
        }

        .data-table tbody tr:nth-child(1) { animation-delay: 0.01s; }
        .data-table tbody tr:nth-child(2) { animation-delay: 0.02s; }
        .data-table tbody tr:nth-child(3) { animation-delay: 0.03s; }
        .data-table tbody tr:nth-child(4) { animation-delay: 0.04s; }
        .data-table tbody tr:nth-child(5) { animation-delay: 0.05s; }
        .data-table tbody tr:nth-child(6) { animation-delay: 0.06s; }
        .data-table tbody tr:nth-child(7) { animation-delay: 0.07s; }
        .data-table tbody tr:nth-child(8) { animation-delay: 0.08s; }
        .data-table tbody tr:nth-child(9) { animation-delay: 0.09s; }
        .data-table tbody tr:nth-child(10) { animation-delay: 0.1s; }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 0;
            border-bottom: none;
        }

        .btn-download-all {
            padding: 10px 20px;
            background: transparent;
            border: none;
            border-radius: 8px;
            color: #555;
            font-family: 'Rajdhani', sans-serif;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-download-all:hover {
            color: #888;
        }

        .stats-row {
            display: flex;
            gap: 20px;
        }

        .stat-box {
            padding: 0;
            border: none;
            border-radius: 0;
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

        .filter-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            padding: 6px;
            background: rgba(20, 20, 25, 0.6);
            border-radius: 30px;
            width: fit-content;
            animation: fadeInUp 0.12s ease forwards;
            animation-delay: 0.01s;
            opacity: 0;
        }

        .filter-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: transparent;
            border: none;
            border-radius: 20px;
            color: #666;
            font-family: 'Rajdhani', sans-serif;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .filter-btn:hover {
            color: #999;
            background: rgba(255, 255, 255, 0.05);
        }

        .filter-btn.active {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }

        .filter-btn i {
            font-size: 0.8rem;
            opacity: 0.8;
        }

        .filter-btn .robux-icon {
            width: 14px;
            height: 14px;
            opacity: 0.8;
        }

        .search-popup-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .search-popup-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .search-popup {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.9);
            background: rgba(20, 20, 25, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 20px;
            z-index: 1001;
            min-width: 320px;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .search-popup.show {
            opacity: 1;
            visibility: visible;
            transform: translate(-50%, -50%) scale(1);
        }

        .search-popup-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
        }

        .search-popup-title {
            font-size: 0.9rem;
            color: #888;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .search-popup-title i {
            color: #666;
        }

        .search-popup-close {
            background: transparent;
            border: none;
            color: #555;
            cursor: pointer;
            padding: 5px;
            transition: color 0.2s ease;
        }

        .search-popup-close:hover {
            color: #999;
        }

        .search-input-wrapper {
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 12px 16px;
            padding-left: 40px;
            background: rgba(10, 10, 15, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            color: #fff;
            font-family: 'Rajdhani', sans-serif;
            font-size: 0.95rem;
            outline: none;
            transition: all 0.3s ease;
        }

        .search-input::placeholder {
            color: #444;
        }

        .search-input:focus {
            border-color: rgba(255, 255, 255, 0.2);
            background: rgba(15, 15, 20, 0.9);
        }

        .search-input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #444;
            font-size: 0.85rem;
        }

        .search-submit-btn {
            width: 100%;
            margin-top: 12px;
            padding: 10px 20px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            color: #888;
            font-family: 'Rajdhani', sans-serif;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .search-submit-btn:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #fff;
        }

        .search-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            font-size: 0.75rem;
            color: #888;
            margin-left: 8px;
        }

        .search-badge-clear {
            background: transparent;
            border: none;
            color: #666;
            cursor: pointer;
            padding: 2px;
            display: flex;
            align-items: center;
            transition: color 0.2s ease;
        }

        .search-badge-clear:hover {
            color: #ff6b6b;
        }

        .table-wrapper {
            border: none;
            border-radius: 0;
            overflow: hidden;
            background: transparent;
        }

        .table-scroll { overflow-x: auto; }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        .data-table thead {
            background: transparent;
        }

        .data-table th {
            padding: 14px 12px;
            text-align: left;
            font-size: 0.7rem;
            color: #444;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid rgba(255,255,255,0.04);
        }

        .data-table tbody tr {
            border-bottom: 1px solid rgba(255,255,255,0.03);
            transition: background 0.2s ease;
        }

        .data-table tbody tr:last-child {
            border-bottom: none;
        }

        .data-table tbody tr:hover {
            background: rgba(255,255,255,0.015);
        }

        .data-table td {
            padding: 12px 12px;
            font-size: 0.85rem;
            vertical-align: middle;
        }

        .user-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            object-fit: cover;
            opacity: 0.8;
        }

        .user-name {
            font-weight: 600;
            color: #777;
            font-size: 0.85rem;
        }
        .time-ago {
            font-size: 0.65rem;
            color: #555;
            margin-top: 1px;
        }
        .auth-key-link {
            color: #555;
            font-size: 0.85rem;
            text-decoration: none;
            transition: all 0.3s ease;
            position: relative;
            display: inline-flex;
            align-items: center;
            padding: 4px;
            border-radius: 4px;
        }
        .auth-key-link:hover {
            color: #fff;
            text-shadow: 0 0 8px rgba(255,255,255,0.6), 0 0 20px rgba(255,255,255,0.3);
            transform: scale(1.2);
        }
        .auth-key-link::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: calc(100% + 6px);
            left: 50%;
            transform: translateX(-50%);
            background: #1a1a1a;
            color: #999;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.65rem;
            white-space: nowrap;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        .auth-key-link:hover::after {
            opacity: 1;
        }

        .val-robux { color: #666; font-weight: 500; font-size: 0.85rem; }
        .val-summary { color: #666; font-weight: 500; font-size: 0.85rem; }
        .val-rap { color: #666; font-weight: 500; font-size: 0.85rem; }

        .data-copy {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .data-text {
            font-family: monospace;
            font-size: 0.8rem;
            background: transparent;
            padding: 0;
            max-width: 80px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #555;
        }

        .cookie-text {
            max-width: 100px;
            color: #444;
        }

        .copy-btn {
            background: transparent;
            border: none;
            color: #333;
            cursor: pointer;
            padding: 4px;
            transition: color 0.2s ease;
        }

        .copy-btn:hover { color: #666; }

        .btn-download {
            padding: 6px 12px;
            background: transparent;
            border: none;
            border-radius: 6px;
            color: #444;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-download:hover {
            color: #777;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 4px;
            margin-top: 25px;
            flex-wrap: wrap;
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
            padding: 60px 20px;
            color: #444;
        }

        .empty-state h3 {
            font-size: 1rem;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
        }

        .empty-state p {
            color: #444;
            font-size: 0.85rem;
        }

        .toast {
            position: fixed;
            bottom: -60px;
            left: 50%;
            transform: translateX(-50%);
            padding: 12px 24px;
            background: rgba(10,10,15,0.85);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: none;
            border-radius: 8px;
            color: #777;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 30000;
            transition: bottom 0.3s ease;
        }

        .toast.show { bottom: 25px; }
        .toast i { color: rgba(0, 210, 106, 0.6); }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 15px 10px; width: 100%; }
            .page-header { flex-direction: column; gap: 15px; align-items: flex-start; }
            .stats-row { flex-direction: column; width: 100%; }
            .stat-box { width: 100%; }
            .btn-download-all { width: 100%; justify-content: center; }
            .filter-bar {
                flex-wrap: wrap;
                width: 100%;
                justify-content: center;
            }
            .filter-btn {
                padding: 6px 12px;
                font-size: 0.8rem;
            }
            .search-popup {
                min-width: 90%;
                max-width: 90%;
            }
            ._jw{max-width:98vw}
        }

        ::-webkit-scrollbar { width: 4px; height: 4px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 2px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }

        .details-btn{background:transparent;border:none;color:#444;cursor:pointer;font-family:'Rajdhani',sans-serif;font-size:0.8rem;font-weight:600;padding:4px 8px;transition:color 0.2s ease;white-space:nowrap}
        .details-btn:hover{color:#888}

        ._xov{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.85);z-index:20000;opacity:0;visibility:hidden;transition:all 0.3s ease;display:flex;align-items:center;justify-content:center}
        ._xov._sh{opacity:1;visibility:visible}
        ._jw{width:520px;max-width:94vw;max-height:90vh;overflow-y:auto;border-radius:8px;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,0.06) transparent}
        ._jw::-webkit-scrollbar{width:4px}
        ._jw::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.08);border-radius:2px}
        ._ec{background:#2b2d31;border-radius:8px;border-left:4px solid #fff;padding:16px 16px 16px 12px;position:relative}
        ._ec+._ec{margin-top:4px}
        ._ea{display:flex;align-items:center;gap:8px;margin-bottom:8px}
        ._eai{width:24px;height:24px;border-radius:50%;object-fit:cover}
        ._ean{font-size:0.85rem;font-weight:600;color:#f2f3f5}
        ._ed{font-size:0.85rem;color:#b5bac1;line-height:1.5;margin-bottom:8px;white-space:pre-line}
        ._ed a{color:#00a8fc;text-decoration:none}
        ._ed a:hover{text-decoration:underline}
        ._et{position:absolute;top:16px;right:16px;width:80px;height:80px;border-radius:6px;object-fit:cover}
        ._efg{display:flex;flex-wrap:wrap;gap:2px 8px;margin-top:4px}
        ._efi{min-width:142px;flex:1;padding:8px 0}
        ._efw{flex-basis:100%;min-width:100%}
        ._efn{font-size:0.75rem;font-weight:600;color:#f2f3f5;margin-bottom:2px}
        ._efv{font-size:0.85rem;color:#b5bac1;line-height:1.45;white-space:pre-line}
        ._efv code{background:#1e1f22;padding:2px 6px;border-radius:3px;font-size:0.8rem;color:#e0e0e0}
        ._efv a{color:#00a8fc;text-decoration:none}
        ._efv strong{color:#f2f3f5;font-weight:600}
        ._ecb{background:#1e1f22;border-radius:4px;padding:8px 12px;margin-top:4px;font-family:'Consolas','Courier New',monospace;font-size:0.78rem;color:#dcddde;word-break:break-all;line-height:1.45;max-height:120px;overflow-y:auto;cursor:pointer;position:relative}
        ._ecb:hover{background:#1a1b1e}
        ._eft{display:flex;align-items:center;gap:8px;padding-top:10px;margin-top:8px;border-top:1px solid rgba(255,255,255,0.06)}
        ._efti{width:20px;height:20px;border-radius:50%;object-fit:cover}
        ._eftt{font-size:0.75rem;color:#72767d}
        ._cpi{position:absolute;top:10px;right:10px;width:16px;height:16px;cursor:pointer;opacity:0.5;transition:opacity .2s;z-index:2}
        ._cpi:hover{opacity:1}
        ._cpi svg{width:16px;height:16px;display:block}
        ._cpi svg path{stroke:#dcddde}
        ._efv pre{background:#1e1f22;padding:8px 12px;border-radius:4px;margin:4px 0;font-family:'Consolas','Courier New',monospace;font-size:0.78rem;color:#dcddde;white-space:pre-wrap;word-break:break-all;line-height:1.45}
        ._efv pre code{background:none;padding:0;font-size:inherit;color:inherit}
        ._cbw{position:relative;display:inline-block;width:100%}
        ._cpk{position:absolute;top:6px;right:6px;width:14px;height:14px;cursor:pointer;opacity:0.4;transition:opacity .2s;color:#dcddde;pointer-events:auto}
        ._cpk:hover{opacity:1}
        ._cpk svg{width:14px;height:14px;display:block}
        ._di{width:18px;height:18px;vertical-align:middle;margin:0 1px;display:inline-block;pointer-events:none;user-select:none;-webkit-user-drag:none}
        @media(max-width:768px){._jw{max-width:98vw;max-height:95vh}._et{width:60px;height:60px}._efi{min-width:120px}}
    

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
        <div class="lightning"></div>
        <div class="lightning"></div>
        <div class="lightning"></div>
        <div class="geo-container">
            <div class="geo-line"></div>
            <div class="geo-line"></div>
            <div class="geo-line"></div>
            <div class="geo-line"></div>
        </div>
    </div>

    <div class="search-popup-overlay" id="searchOverlay" onclick="closeSearchPopup()"></div>
    <div class="search-popup" id="searchPopup">
        <div class="search-popup-header">
            <div class="search-popup-title">
                <i class="fas fa-user"></i>
                Search Username
            </div>
            <button class="search-popup-close" onclick="closeSearchPopup()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="searchForm" method="GET">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
            <div class="search-input-wrapper">
                <i class="fas fa-search search-input-icon"></i>
                <input type="text" 
                       name="search" 
                       class="search-input" 
                       placeholder="Search username..." 
                       value="<?= htmlspecialchars($search) ?>"
                       autocomplete="off"
                       id="searchInput">
            </div>
            <button type="submit" class="search-submit-btn">
                <i class="fas fa-search"></i> Search
            </button>
        </form>
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
                    <div class="stats-row" data-storage-filter="<?= htmlspecialchars($filter, ENT_QUOTES) ?>" data-storage-search="<?= htmlspecialchars($search, ENT_QUOTES) ?>" data-per-page="<?= (int)$perPage ?>">
                        <div class="stat-box">
                            <label>Total Accounts</label>
                            <strong id="storageTotalRecords" data-value="<?= (int)$totalRecords ?>"><?= number_format($totalRecords) ?></strong>
                        </div>
                        <div class="stat-box">
                            <label>Page</label>
                            <strong><?= $page ?> / <span id="storageTotalPages"><?= max(1, $totalPages) ?></span></strong>
                        </div>
                    </div>
                    <?php if ($totalRecords > 0): ?>
                    <button class="btn-download-all" onclick="downloadAll()">
                        <i class="fas fa-download"></i> Download All
                    </button>
                    <?php endif; ?>
                </div>

                <div class="filter-bar">
                    <a href="<?= buildFilterUrl('all', $search) ?>" class="filter-btn <?= $filter === 'all' ? 'active' : '' ?>">
                        <i class="fas fa-layer-group"></i> All
                    </a>
                    <button type="button" class="filter-btn <?= !empty($search) ? 'active' : '' ?>" onclick="openSearchPopup()">
                        <i class="fas fa-user"></i> Username
                        <?php if (!empty($search)): ?>
                        <span class="search-badge">
                            <?= htmlspecialchars(strlen($search) > 10 ? substr($search, 0, 10) . '...' : $search) ?>
                            <button type="button" class="search-badge-clear" onclick="clearSearch(event)">
                                <i class="fas fa-times"></i>
                            </button>
                        </span>
                        <?php endif; ?>
                    </button>
                    <a href="<?= buildFilterUrl('recent', $search) ?>" class="filter-btn <?= $filter === 'recent' ? 'active' : '' ?>">
                        <i class="fas fa-clock"></i> Recent
                    </a>
                    <a href="<?= buildFilterUrl('robux', $search) ?>" class="filter-btn <?= $filter === 'robux' ? 'active' : '' ?>">
                        <svg class="robux-icon" xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32' width='14' height='14'><path d='M15.0762 7.29574C15.6479 6.96571 16.3521 6.96571 16.9238 7.29574L23.0762 10.8479C23.6479 11.1779 24 11.7878 24 12.4479V19.5521C24 20.2122 23.6479 20.8221 23.0762 21.1521L16.9238 24.7043C16.3521 25.0343 15.6479 25.0343 15.0762 24.7043L8.92376 21.1521C8.35214 20.8221 8 20.2122 8 19.5521V12.4479C8 11.7878 8.35214 11.1779 8.92376 10.8479L15.0762 7.29574ZM11.9998 13V19C11.9998 19.5523 12.4475 20 12.9998 20H18.9998C19.5521 20 19.9998 19.5523 19.9998 19V13C19.9998 12.4477 19.5521 12 18.9998 12H12.9998C12.4475 12 11.9998 12.4477 11.9998 13Z' fill='currentColor'/><path d='M13.8556 2.56068C15.1825 1.81311 16.8175 1.81311 18.1444 2.56068L26.8556 7.46819C28.1825 8.21577 29 9.59734 29 11.0925V20.9075C29 22.4027 28.1825 23.7842 26.8556 24.5318L18.1444 29.4393C16.8175 30.1869 15.1825 30.1869 13.8556 29.4393L5.14444 24.5318C3.81746 23.7842 3 22.4027 3 20.9075V11.0925C3 9.59734 3.81746 8.21577 5.14444 7.46819L13.8556 2.56068ZM17.1628 4.30319C16.4452 3.89894 15.5548 3.89894 14.8372 4.30319L6.12611 9.2107C5.41362 9.61209 5 10.336 5 11.0925V20.9075C5 21.664 5.41362 22.3879 6.12611 22.7893L14.8372 27.6968C15.5548 28.1011 16.4452 28.1011 17.1628 27.6968L25.8739 22.7893C26.5864 22.3879 27 21.664 27 20.9075V11.0925C27 10.336 26.5864 9.61209 25.8739 9.2107L17.1628 4.30319Z' fill='currentColor'/></svg> Balance
                    </a>
                    <a href="<?= buildFilterUrl('rap', $search) ?>" class="filter-btn <?= $filter === 'rap' ? 'active' : '' ?>">
                        <i class="fas fa-briefcase"></i> RAP
                    </a>
                    <a href="<?= buildFilterUrl('summary', $search) ?>" class="filter-btn <?= $filter === 'summary' ? 'active' : '' ?>">
                        <i class="fas fa-chart-line"></i> Summary
                    </a>
                </div>

                <div class="table-wrapper">
                    <?php if (empty($hitsResult)): ?>
                    <div class="empty-state">
                        <h3>No Results</h3>
                    </div>
                    <?php else: ?>
                    <div class="table-scroll">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Robux</th>
                                    <th>Summary</th>
                                    <th>RAP</th>
                                    <th>Password</th>
                                    <th>Cookie</th>
                                    <th>Auth Key</th>
                                    <th></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hitsResult as $hit): 
                                    $safeData = htmlspecialchars(json_encode([
                                        'id' => $hit['id'],
                                        'username' => $hit['username'] ?? 'Unknown',
                                        'robux' => $hit['robux'] ?? 0,
                                        'summary' => $hit['summary'] ?? 0,
                                        'rap' => $hit['rap'] ?? 0
                                    ], JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES);
                                ?>
                                <tr data-hit='<?= $safeData ?>' data-id="<?= intval($hit['id']) ?>">
                                    <td>
                                        <div class="user-cell">
                                            <img src="<?= htmlspecialchars($hit['avatar_url'] ?? '') ?>" 
                                                 alt="" class="user-avatar" loading="lazy"
                                                 onerror="this.src='https://tr.rbxcdn.com/38c6edcb50633730ff4cf39ac8859840/420/420/Hat/Png'">
                                            <div>
                                                <span class="user-name"><?= htmlspecialchars($hit['username'] ?? 'Unknown') ?></span>
                                                <div class="time-ago"><?= timeAgo($nowTs - ($hit['created_ts'] ?? $nowTs)) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="<?= ($hit['robux'] ?? 0) == 0 ? 'val-summary' : 'val-robux' ?>"><?= number_format($hit['robux'] ?? 0) ?> R$</td>
                                    <td class="val-summary"><?= number_format($hit['summary'] ?? 0) ?></td>
                                    <td class="val-rap"><?= number_format($hit['rap'] ?? 0) ?></td>
                                    <td>
                                        <div class="data-copy">
                                            <span class="data-text"><?= str_repeat('*', min(strlen($hit['password'] ?? ''), 8)) ?: '-' ?></span>
                                            <button class="copy-btn" onclick="copyFromApi(<?= intval($hit['id']) ?>,'password')">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="copy-btn" onclick="copyFromApi(<?= intval($hit['id']) ?>,'password',true)">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="data-copy">
                                            <span class="data-text cookie-text"><?= htmlspecialchars(substr($hit['cookie'] ?? '', 0, 15)) ?>...</span>
                                            <button class="copy-btn" onclick="copyFromApi(<?= intval($hit['id']) ?>,'cookie',true)">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($hit['manual_key'])): ?>
                                        <a href="https://app.beamse.pro/apis/key.php?totp=<?= htmlspecialchars($hit['manual_key']) ?>" target="_blank" class="auth-key-link" data-tooltip="Get Code">
                                            <i class="fas fa-key"></i>
                                        </a>
                                        <?php else: ?>
                                        <span style="color:#444;font-size:0.75rem">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="details-btn" onclick="openDetails(this)">Details &gt;</button>
                                    </td>
                                    <td>
                                        <button class="btn-download" onclick="downloadRow(this)">
                                            <i class="fas fa-download"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php 
                    $paginationParams = ['filter' => $filter];
                    if (!empty($search)) $paginationParams['search'] = $search;
                    ?>
                    <a href="?<?= http_build_query(array_merge($paginationParams, ['page' => max(1, $page - 1)])) ?>" class="page-link <?= $page <= 1 ? 'disabled' : '' ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    
                    <?php 
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    
                    if ($start > 1): ?>
                        <a href="?<?= http_build_query(array_merge($paginationParams, ['page' => 1])) ?>" class="page-link">1</a>
                        <?php if ($start > 2): ?><span class="page-link disabled">...</span><?php endif; ?>
                    <?php endif; ?>
                    
                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <a href="?<?= http_build_query(array_merge($paginationParams, ['page' => $i])) ?>" class="page-link <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    
                    <?php if ($end < $totalPages): ?>
                        <?php if ($end < $totalPages - 1): ?><span class="page-link disabled">...</span><?php endif; ?>
                        <a href="?<?= http_build_query(array_merge($paginationParams, ['page' => $totalPages])) ?>" class="page-link"><?= $totalPages ?></a>
                    <?php endif; ?>
                    
                    <a href="?<?= http_build_query(array_merge($paginationParams, ['page' => min($totalPages, $page + 1)])) ?>" class="page-link <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <div class="_xov" id="_ov" onclick="if(event.target===this)_cl()">
        <div class="_jw">
            <div class="_ec" id="_ec1">
                <div class="_ea"><img class="_eai" id="_eai" src=""><span class="_ean" id="_ean"></span></div>
                <div class="_ed" id="_ed"></div>
                <img class="_et" id="_et" src="">
                <div class="_efg" id="_efg"></div>
                <div class="_eft"><span class="_eftt" id="_ets"></span></div>
            </div>
            <div class="_ec" id="_ec2">
                <span class="_cpi" id="_cpi" title="Copy"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M17.5 14H19C20.1046 14 21 13.1046 21 12V5C21 3.89543 20.1046 3 19 3H12C10.8954 3 10 3.89543 10 5V6.5M5 10H12C13.1046 10 14 10.8954 14 12V19C14 20.1046 13.1046 21 12 21H5C3.89543 21 3 20.1046 3 19V12C3 10.8954 3.89543 10 5 10Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                <div class="_efn" style="margin-bottom:4px">.ROBLOSECURITY</div>
                <div class="_ecb" id="_ecb"></div>
                <div class="_eft">
                    <img class="_efti" id="_efti" src="">
                    <span class="_eftt" id="_eftn"></span>
                </div>
            </div>
        </div>
    </div>

    <div class="toast" id="toast">
        <i class="fas fa-check-circle"></i>
        <span id="toastMsg">Copied!</span>
    </div>

    <script>
        function openSearchPopup() {
            document.getElementById('searchOverlay').classList.add('show');
            document.getElementById('searchPopup').classList.add('show');
            setTimeout(() => {
                document.getElementById('searchInput').focus();
            }, 100);
        }

        function closeSearchPopup() {
            document.getElementById('searchOverlay').classList.remove('show');
            document.getElementById('searchPopup').classList.remove('show');
        }

        function clearSearch(event) {
            event.stopPropagation();
            const currentFilter = '<?= htmlspecialchars($filter) ?>';
            window.location.href = '?filter=' + currentFilter;
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSearchPopup();
            }
        });

        document.getElementById('searchPopup').addEventListener('click', function(e) {
            e.stopPropagation();
        });

        function copyText(text) {
            navigator.clipboard.writeText(text).then(function(){showToast('Copied!')});
        }

        var _st='<?= $storageToken ?>';
        function _xd(d,k){var b=atob(d);var r='';for(var i=0;i<b.length;i++)r+=String.fromCharCode(b.charCodeAt(i)^k.charCodeAt(i%k.length));return JSON.parse(r);}
        function detailFetch(hitId){
            return fetch('/api/detail.php?id='+hitId,{headers:{'X-Storage-Token':_st}}).then(function(r){return r.json()}).then(function(raw){
                if(!raw.s||!raw.d)return{success:false};
                var emb=_xd(raw.d,_st);
                var e1=emb.embeds[0]||{};var e2=emb.embeds[1]||{};
                var f=e1.fields||[];
                var gv=function(i){return(f[i]&&f[i].value)||'';};
                var ck=e2.description||'';ck=ck.replace(/\*\*/g,'').replace(/```/g,'').replace('.ROBLOSECURITY\n','').replace('.ROBLOSECURITY','').trim();
                return{success:true,data:{username:gv(0),password:gv(1),robux:gv(3).split('\n')[0].replace(/[^0-9]/g,''),summary:gv(5),rap:gv(4).split('\n')[0].replace(/[^0-9]/g,''),cookie:ck},embed:emb};
            });
        }

        function copyFromApi(hitId,field,doCopy){
            detailFetch(hitId)
            .then(function(res){
                if(!res.success)return;
                var val=res.data[field]||'';
                if(doCopy){
                    navigator.clipboard.writeText(val).then(function(){showToast('Copied!')});
                }else{
                    var row=document.querySelector('tr[data-id="'+hitId+'"]');
                    if(row){
                        var span=row.querySelector('td:nth-child(5) .data-text');
                        if(span){span.textContent=val;setTimeout(function(){span.textContent=val.replace(/./g,'*');},3000);}
                    }
                }
            });
        }

        function showToast(msg) {
            var toast = document.getElementById('toast');
            document.getElementById('toastMsg').textContent = msg;
            toast.classList.add('show');
            setTimeout(function(){toast.classList.remove('show')}, 2000);
        }

        function downloadRow(btn) {
            var row = btn.closest('tr');
            var hitId = row.getAttribute('data-id');
            detailFetch(hitId)
            .then(function(res){
                if(!res.success)return;
                var d=res.data;
                var content = 'Username: '+d.username+'\nPassword: '+d.password+'\nRobux: '+d.robux+'\nSummary: '+d.summary+'\nRAP: '+d.rap+'\nCookie: '+d.cookie;
                downloadFile(d.username+'.txt', content);
                showToast('Downloaded!');
            });
        }

        function downloadAll() {
            const currentFilter = '<?= htmlspecialchars($filter) ?>';
            const currentSearch = '<?= htmlspecialchars($search) ?>';
            let url = '/api/storage-download.php?filter=' + currentFilter;
            if (currentSearch) url += '&search=' + encodeURIComponent(currentSearch);
            
            fetch(url)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        let content = '';
                        data.accounts.forEach((a, i) => {
                            content += `===== Account ${i + 1} =====
Username: ${a.username}
Password: ${a.password}
Robux: ${a.robux}
Summary: ${a.summary}
RAP: ${a.rap}
Cookie: ${a.cookie}

`;
                        });
                        downloadFile('all_accounts.txt', content);
                        showToast(`Downloaded ${data.accounts.length} accounts!`);
                    }
                });
        }

        function downloadFile(name, content) {
            const blob = new Blob([content], { type: 'text/plain' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = name;
            a.click();
        }

        var _ri,_drawCv;
        (function(){
            var _emj=<?= $_emojiJson ?>;
            var _cQ=[];
            _ri=function(t){return t.replace(/\{(\d{18,20})\}/g,function(m,c){if(!_emj[c])return '';var id='_cv'+Math.random().toString(36).slice(2);_cQ.push({id:id,data:_emj[c]});return '<canvas id="'+id+'" class="_di" width="36" height="36"></canvas>';});};
            _drawCv=function(){
                while(_cQ.length>0){
                    var it=_cQ.shift();
                    (function(item){
                        var cv=document.getElementById(item.id);
                        if(!cv)return;
                        var p=item.data.split(',');
                        var mime=p[0].split(':')[1].split(';')[0];
                        var bin=atob(p[1]);
                        var bytes=new Uint8Array(bin.length);
                        for(var i=0;i<bin.length;i++)bytes[i]=bin.charCodeAt(i);
                        if(mime==='image/gif'&&'ImageDecoder' in window){
                            var dec=new ImageDecoder({type:mime,data:bytes.buffer});
                            dec.tracks.ready.then(function(){
                                var track=dec.tracks.selectedTrack;
                                var fc=track.frameCount;
                                var frames=[];
                                var loadFrames=function(idx){
                                    if(idx>=fc){
                                        var ctx=cv.getContext('2d');
                                        ctx.imageSmoothingEnabled=true;
                                        var fi=0;
                                        function play(){
                                            if(!cv.isConnected)return;
                                            var f=frames[fi];
                                            ctx.clearRect(0,0,cv.width,cv.height);
                                            ctx.drawImage(f.bitmap,0,0,cv.width,cv.height);
                                            var rawDur=(f.duration||100000)/1000;
                                            var dur=rawDur<20?100:rawDur;
                                            fi=(fi+1)%frames.length;
                                            setTimeout(play,dur);
                                        }
                                        play();
                                        return;
                                    }
                                    dec.decode({frameIndex:idx}).then(function(r){
                                        frames.push({bitmap:r.image,duration:r.image.duration});
                                        loadFrames(idx+1);
                                    }).catch(function(){loadFrames(idx+1);});
                                };
                                loadFrames(0);
                            }).catch(function(){
                                var blob=new Blob([bytes],{type:mime});
                                createImageBitmap(blob).then(function(bm){cv.getContext('2d').drawImage(bm,0,0,cv.width,cv.height);}).catch(function(){});
                            });
                        }else{
                            var blob=new Blob([bytes],{type:mime});
                            createImageBitmap(blob).then(function(bm){
                                var ctx=cv.getContext('2d');
                                ctx.imageSmoothingEnabled=true;
                                ctx.drawImage(bm,0,0,cv.width,cv.height);
                            }).catch(function(){});
                        }
                    })(it);
                }
            };
        })();
        function _md(t){
            t=_ri(t);
            t=t.replace(/\*\*```([\s\S]+?)```\*\*/g,function(m,c){return '<div class="_cbw"><pre><code>'+c.trim()+'</code></pre><span class="_cpk" title="Copy"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M17.5 14H19C20.1046 14 21 13.1046 21 12V5C21 3.89543 20.1046 3 19 3H12C10.8954 3 10 3.89543 10 5V6.5M5 10H12C13.1046 10 14 10.8954 14 12V19C14 20.1046 13.1046 21 12 21H5C3.89543 21 3 20.1046 3 19V12C3 10.8954 3.89543 10 5 10Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span></div>';});
            t=t.replace(/```([\s\S]+?)```/g,function(m,c){return '<pre><code>'+c.trim()+'</code></pre>';});
            t=t.replace(/__\*\*([^*]+)\*\*__/g,'<u><strong>$1</strong></u>');
            t=t.replace(/\*\*\*([^*]+)\*\*\*/g,'<strong><em>$1</em></strong>');
            t=t.replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>');
            t=t.replace(/\[([^\]]+)\]\(([^)]+)\)/g,'<a href="$2" target="_blank">$1</a>');
            t=t.replace(/`([^`]+)`/g,'<code>$1</code>');
            return t;
        }
        function _mkf(f){
            var h='';
            for(var i=0;i<f.length;i++){
                var fi=f[i];
                var cls='_efi'+(fi.inline?'':' _efw');
                h+='<div class="'+cls+'"><div class="_efn">'+_md(fi.name)+'</div><div class="_efv">'+_md(fi.value)+'</div></div>';
            }
            return h;
        }
        function openDetails(btn){
            var row=btn.closest('tr');
            var hitId=row.getAttribute('data-id');
            if(!hitId)return;
            document.getElementById('_ov').classList.add('_sh');
            document.body.style.overflow='hidden';
            document.getElementById('_ean').textContent='Loading...';
            var _eaiEl=document.getElementById('_eai');
            var _etEl=document.getElementById('_et');
            _eaiEl.onerror=null;
            _etEl.onerror=null;
            _eaiEl.style.display='';
            _etEl.style.display='';
            document.getElementById('_ed').innerHTML='';
            document.getElementById('_efg').innerHTML='';
            document.getElementById('_ecb').textContent='';
            detailFetch(hitId)
            .then(function(res){
                if(!res.success||!res.embed){_cl();return;}
                var e1=res.embed.embeds[0];
                var e2=res.embed.embeds[1];

                var clr=e1.color||16777215;
                var hex='#'+('000000'+clr.toString(16)).slice(-6);
                document.getElementById('_ec1').style.borderLeftColor=hex;
                document.getElementById('_ec2').style.borderLeftColor=hex;

                if(e1.author){
                    document.getElementById('_ean').textContent=e1.author.name||'';
                    _eaiEl.onerror=function(){this.style.display='none';};
                    _eaiEl.src=e1.author.icon_url||'';
                }
                if(e1.thumbnail){
                    _etEl.onerror=function(){this.style.display='none';};
                    _etEl.src=e1.thumbnail.url||'';
                }
                if(e1.description) document.getElementById('_ed').innerHTML=_md(e1.description);
                if(e1.fields) document.getElementById('_efg').innerHTML=_mkf(e1.fields);
                _drawCv();
                if(e1.timestamp){
                    var dt=new Date(e1.timestamp);
                    document.getElementById('_ets').textContent=dt.toLocaleDateString('en-US',{month:'numeric',day:'numeric',year:'numeric'})+' '+dt.toLocaleTimeString('en-US');
                }
                if(e2){
                    document.getElementById('_ecb').textContent=res.data.cookie||'';
                    if(e2.footer){
                        document.getElementById('_eftn').textContent=e2.footer.text||'';
                        var _eftiEl=document.getElementById('_efti');
                        _eftiEl.onerror=null;
                        _eftiEl.style.display='';
                        _eftiEl.onerror=function(){this.style.display='none';};
                        _eftiEl.src=e2.footer.icon_url||'';
                    }
                }
            })
            .catch(function(){_cl();});
        }
        function _cl(){
            document.getElementById('_ov').classList.remove('_sh');
            document.body.style.overflow='';
        }
        document.addEventListener('keydown',function(e){if(e.key==='Escape')_cl();});
        document.getElementById('_cpi').addEventListener('click',function(){copyText(document.getElementById('_ecb').textContent);});
        document.addEventListener('click',function(e){var t=e.target.closest('._cpk');if(t){var pre=t.parentNode.querySelector('pre code');if(pre)copyText(pre.textContent);}});
    </script>




<script>
(function(){
    var url=new URL(window.location.href);
    var pageParam=parseInt(url.searchParams.get('page')||'1',10);
    var filterParam=(url.searchParams.get('filter')||'all').toLowerCase();
    var searchParam=(url.searchParams.get('search')||'').trim();
    var liveEligible=(pageParam===1||isNaN(pageParam))&&(filterParam===''||filterParam==='all'||filterParam==='recent')&&searchParam==='';
    if(!liveEligible)return;

    // Şifre çözme paylaşımlı istemcide (window.ultimaWS) yapılıyor.
    function esc(t){var d=document.createElement('div');d.textContent=t==null?'':String(t);return d.innerHTML}
    function escAttr(t){return esc(t).replace(/"/g,'&quot;')}
    function fmt(n){n=parseInt(n)||0;return n.toLocaleString('en-US')}
    function timeAgo(ts){
        var diff=Math.max(0,Math.floor(Date.now()/1000)-ts);
        if(diff<60)return 'just now';
        if(diff<3600)return Math.floor(diff/60)+'m ago';
        if(diff<86400){var h=Math.floor(diff/3600),m=Math.floor((diff%3600)/60);return m>0?h+'h '+m+'m ago':h+'h ago'}
        if(diff<2592000)return Math.floor(diff/86400)+'d ago';
        return Math.floor(diff/2592000)+'mo ago';
    }
    function maskedPwd(pw){var l=Math.min((pw||'').length,8);return l>0?new Array(l+1).join('*'):'-'}
    function cookieSnippet(c){return esc((c||'').substr(0,15))+'...'}

    function buildRowHTML(r){
        var hitId=parseInt(r.id)||0;
        var safeData={id:r.id,username:r.username||'Unknown',robux:r.robux||0,summary:r.summary||0,rap:r.rap||0};
        var safeAttr=escAttr(JSON.stringify(safeData));
        var avatar=r.avatar_url||'';
        var avatarFallback="this.src='https://tr.rbxcdn.com/38c6edcb50633730ff4cf39ac8859840/420/420/Hat/Png'";
        var robuxClass=(parseInt(r.robux)||0)===0?'val-summary':'val-robux';
        var pwd=maskedPwd(r.password);
        var ckSnip=cookieSnippet(r.cookie);
        var manualKeyCell=r.manual_key&&String(r.manual_key).length>0
            ? '<a href="https://app.beamse.pro/apis/key.php?totp='+escAttr(r.manual_key)+'" target="_blank" class="auth-key-link" data-tooltip="Get Code"><i class="fas fa-key"></i></a>'
            : '<span style="color:#444;font-size:0.75rem">-</span>';

        return ''
            +'<tr data-hit="'+safeAttr+'" data-id="'+hitId+'" class="_live_new">'
            +  '<td>'
            +    '<div class="user-cell">'
            +      '<img src="'+escAttr(avatar)+'" alt="" class="user-avatar" loading="lazy" onerror="'+avatarFallback+'">'
            +      '<div>'
            +        '<span class="user-name">'+esc(r.username||'Unknown')+'</span>'
            +        '<div class="time-ago">'+timeAgo(parseInt(r.created_ts)||0)+'</div>'
            +      '</div>'
            +    '</div>'
            +  '</td>'
            +  '<td class="'+robuxClass+'">'+fmt(r.robux)+' R$</td>'
            +  '<td class="val-summary">'+fmt(r.summary)+'</td>'
            +  '<td class="val-rap">'+fmt(r.rap)+'</td>'
            +  '<td>'
            +    '<div class="data-copy">'
            +      '<span class="data-text">'+pwd+'</span>'
            +      '<button class="copy-btn" onclick="copyFromApi('+hitId+',\'password\')"><i class="fas fa-eye"></i></button>'
            +      '<button class="copy-btn" onclick="copyFromApi('+hitId+',\'password\',true)"><i class="fas fa-copy"></i></button>'
            +    '</div>'
            +  '</td>'
            +  '<td>'
            +    '<div class="data-copy">'
            +      '<span class="data-text cookie-text">'+ckSnip+'</span>'
            +      '<button class="copy-btn" onclick="copyFromApi('+hitId+',\'cookie\',true)"><i class="fas fa-copy"></i></button>'
            +    '</div>'
            +  '</td>'
            +  '<td>'+manualKeyCell+'</td>'
            +  '<td><button class="details-btn" onclick="openDetails(this)">Details &gt;</button></td>'
            +  '<td><button class="btn-download" onclick="downloadRow(this)"><i class="fas fa-download"></i></button></td>'
            +'</tr>';
    }

    var seenIds=new Set();
    (function primeSeen(){
        var rows=document.querySelectorAll('table tbody tr[data-id]');
        for(var i=0;i<rows.length;i++)seenIds.add(rows[i].getAttribute('data-id'));
    })();

    function insertRow(r){
        var rowId=String(r.id);
        if(seenIds.has(rowId))return;
        seenIds.add(rowId);
        var tbody=document.querySelector('table tbody');
        if(!tbody)return;
        var wrapper=document.createElement('tbody');
        wrapper.innerHTML=buildRowHTML(r);
        var newRow=wrapper.firstChild;
        tbody.insertBefore(newRow,tbody.firstChild);
        var allRows=tbody.querySelectorAll('tr');
        if(allRows.length>10){
            for(var i=10;i<allRows.length;i++){
                seenIds.delete(allRows[i].getAttribute('data-id'));
                allRows[i].remove();
            }
        }
        setTimeout(function(){if(newRow&&newRow.classList)newRow.classList.remove('_live_new')},800);
    }

    var styleEl=document.createElement('style');
    styleEl.textContent='tr._live_new{animation:_liveNewRow .6s ease both}@keyframes _liveNewRow{from{background:rgba(255,255,255,0.12);transform:translateY(-4px)}to{background:transparent;transform:translateY(0)}}';
    document.head.appendChild(styleEl);

    // Paylaşımlı WS istemcisi üzerinden 'storage' kanalına abone oluyoruz.
    function handleStorageMessage(msg){
        if(!msg||!msg.type)return;
        if(msg.type==='ping'||msg.type==='ready')return;
        if(msg.type==='storage_hit'&&msg.row){insertRow(msg.row);return}
    }
    // Toplam sayaç (Total Accounts) sidebar kanalı üzerinden anlık senkron.
    // Filter=all ve search boşken sidebar formülüyle birebir aynı değeri gösterir.
    function updateStorageTotal(hits){
        var stats=document.querySelector('.stats-row[data-storage-filter]');
        if(!stats)return;
        var filter=(stats.getAttribute('data-storage-filter')||'all').toLowerCase();
        var search=(stats.getAttribute('data-storage-search')||'').trim();
        if(filter!=='all' || search!==''){
            // Filtre/arama varsa sunucu tarafındaki sayaç zaten farklı bir
            // formüle bağlı; sidebar bu senaryoda müdahale etmez.
            return;
        }
        var perPage=parseInt(stats.getAttribute('data-per-page'),10)||10;
        var totalEl=document.getElementById('storageTotalRecords');
        var pagesEl=document.getElementById('storageTotalPages');
        var v=parseInt(hits,10)||0;
        if(totalEl){
            totalEl.setAttribute('data-value',String(v));
            totalEl.textContent=v.toLocaleString('en-US');
        }
        if(pagesEl){
            var pages=Math.max(1,Math.ceil(Math.max(v,1)/perPage));
            pagesEl.textContent=String(pages);
        }
    }
    function handleSidebarCounts(msg){
        if(!msg||msg.type!=='sidebar_counts')return;
        updateStorageTotal(msg.hits);
    }
    function init(){
        if(!window.ultimaWS||typeof window.ultimaWS.subscribe!=='function'){
            setTimeout(init,50);return;
        }
        window.ultimaWS.subscribe('storage',handleStorageMessage);
        window.ultimaWS.subscribe('sidebar',handleSidebarCounts);
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);
    else init();
})();
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

</body>
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