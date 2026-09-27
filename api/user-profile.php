<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');

try {
    require_once __DIR__ . '/../libs/connection.php';

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('token');
        session_start();
    }
    $authCode = trim((string)($_SESSION['auth_code'] ?? ''));
    if ($authCode === '') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    $authStmt = $pdo->prepare('SELECT auth_code, link_id FROM regular WHERE auth_code = :auth_code LIMIT 2');
    $authStmt->execute([':auth_code' => $authCode]);
    $authRows = $authStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($authRows) || count($authRows) !== 1) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    $stored = (string)($authRows[0]['auth_code'] ?? '');
    if ($stored === '' || !hash_equals($stored, $authCode) || (int)($authRows[0]['link_id'] ?? 0) <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $linkId = isset($_GET['link_id']) ? intval($_GET['link_id']) : 0;
    if ($linkId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid link_id']);
        exit;
    }

    // `discord_*` is the current display state. Do not use avatar_hidden here:
    // a hide/show operation writes Anonymous/hide.png into these fields.
    $stmt = $pdo->prepare("SELECT r.link_id, r.discord_username, r.discord_avatar, r.discord_id
                           FROM regular r WHERE r.link_id = :link_id LIMIT 1");
    $stmt->execute([':link_id' => $linkId]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }

    $mode = isset($_GET['mode']) ? $_GET['mode'] : 'normal';
    $isTriplehook = ($mode === 'triplehook');
    $weekStart = "DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY)";

    if ($isTriplehook) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM hits WHERE link_id IN (SELECT r.link_id FROM regular r WHERE r.referred_by = :link_id) AND created_at >= $weekStart");
        $stmt->execute([':link_id' => $linkId]);
        $weeklyHits = $stmt->fetch()['count'] ?? 0;

        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM views WHERE link_id IN (SELECT r.link_id FROM regular r WHERE r.referred_by = :link_id) AND created_at >= $weekStart");
        $stmt->execute([':link_id' => $linkId]);
        $weeklyVisits = $stmt->fetch()['count'] ?? 0;

        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM login_clicks WHERE link_id IN (SELECT r.link_id FROM regular r WHERE r.referred_by = :link_id) AND created_at >= $weekStart");
        $stmt->execute([':link_id' => $linkId]);
        $weeklyClicks = $stmt->fetch()['count'] ?? 0;

        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM regular WHERE referred_by = :link_id");
        $stmt->execute([':link_id' => $linkId]);
        $rankMetric = $stmt->fetch()['count'] ?? 0;

        $ranks = [
            ['name' => 'Unranked', 'min' => 0, 'max' => 10],
            ['name' => 'Triplehook Smaller', 'min' => 10, 'max' => 25],
            ['name' => 'Triple Man', 'min' => 25, 'max' => 50],
            ['name' => 'Tyler Durden', 'min' => 50, 'max' => 100],
            ['name' => 'DON PABLO', 'min' => 100, 'max' => 200],
            ['name' => 'THIS JUST A', 'min' => 200, 'max' => 1000],
            ['name' => 'mustang boss 429', 'min' => 1000, 'max' => 2000],
            ['name' => 'ITS DEVIL', 'min' => 2000, 'max' => 10000],
            ['name' => 'BIG MAN IN THE HISTORY', 'min' => 10000, 'max' => 20000],
            ['name' => 'BACK JUST A BACK', 'min' => 20000, 'max' => PHP_INT_MAX],
        ];
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM hits WHERE link_id = :link_id AND created_at >= $weekStart");
        $stmt->execute([':link_id' => $linkId]);
        $weeklyHits = $stmt->fetch()['count'] ?? 0;

        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM views WHERE link_id = :link_id AND created_at >= $weekStart");
        $stmt->execute([':link_id' => $linkId]);
        $weeklyVisits = $stmt->fetch()['count'] ?? 0;

        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM login_clicks WHERE link_id = :link_id AND created_at >= $weekStart");
        $stmt->execute([':link_id' => $linkId]);
        $weeklyClicks = $stmt->fetch()['count'] ?? 0;

        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM hits WHERE link_id = :link_id");
        $stmt->execute([':link_id' => $linkId]);
        $rankMetric = $stmt->fetch()['count'] ?? 0;

        $ranks = [
            ['name' => 'Unranked', 'min' => 0, 'max' => 10],
            ['name' => 'Walter White', 'min' => 10, 'max' => 25],
            ['name' => 'W. W.', 'min' => 25, 'max' => 50],
            ['name' => 'apple 2023', 'min' => 50, 'max' => 100],
            ['name' => 'apple vip+', 'min' => 100, 'max' => 200],
            ['name' => 'Heisenberg', 'min' => 200, 'max' => 1000],
            ['name' => 'Devil', 'min' => 1000, 'max' => 2000],
            ['name' => 'JOHN WICK', 'min' => 2000, 'max' => 5000],
            ['name' => 'Baba Yaga', 'min' => 5000, 'max' => 10000],
            ['name' => 'NEW APPLE', 'min' => 10000, 'max' => 20000],
            ['name' => 'BIG MAN IN THE HISTORY', 'min' => 20000, 'max' => PHP_INT_MAX],
        ];
    }

    $currentRank = 'Unranked';
    $nextRank = $isTriplehook ? 'Triplehook Smaller' : 'Walter White';
    $rankPercent = 0;
    $isMaxRank = false;

    foreach ($ranks as $i => $rank) {
        if ($rankMetric >= $rank['min'] && $rankMetric < $rank['max']) {
            $currentRank = $rank['name'];
            if (isset($ranks[$i + 1])) {
                $nextRank = $ranks[$i + 1]['name'];
                $rankProgress = $rankMetric - $rank['min'];
                $rankMax = $rank['max'] - $rank['min'];
                $rankPercent = min(100, round(($rankProgress / $rankMax) * 100, 2));
            } else {
                $nextRank = 'MAX';
                $rankPercent = 100;
                $isMaxRank = true;
            }
            break;
        }
    }

    $discordUsername = trim((string)($user['discord_username'] ?? ''));
    $discordAvatar = trim((string)($user['discord_avatar'] ?? ''));
    $discordId = trim((string)($user['discord_id'] ?? ''));
    $isHidden = strcasecmp($discordUsername, 'Anonymous') === 0
        || stripos($discordAvatar, 'hide.png') !== false;

    $avatar = '';
    if ($isHidden) {
        $avatar = 'https://app.ultima.cl/images/hide.png';
    } elseif ($discordAvatar !== '') {
        if (strpos($discordAvatar, 'https://') === 0 || strpos($discordAvatar, '/images/') === 0) {
            $avatar = $discordAvatar;
        } elseif ($discordId !== '') {
            $ext = (strpos($discordAvatar, 'a_') === 0) ? 'gif' : 'png';
            $avatar = 'https://cdn.discordapp.com/avatars/' . $discordId . '/' . $discordAvatar . '.' . $ext;
        }
    }

    echo json_encode([
        'success' => true,
        'user' => [
            'username' => $isHidden ? 'Anonymous' : ($discordUsername !== '' ? $discordUsername : 'Unknown'),
            'avatar' => $avatar,
            'weekly_hits' => (int)$weeklyHits,
            'weekly_visits' => (int)$weeklyVisits,
            'weekly_clicks' => (int)$weeklyClicks,
            'current_rank' => $currentRank,
            'next_rank' => $nextRank,
            'rank_percent' => $rankPercent,
            'is_max_rank' => $isMaxRank,
            'is_triplehook' => $isTriplehook
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
?>
