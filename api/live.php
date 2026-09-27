<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

try {
    require_once __DIR__ . '/../libs/connection.php';

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('token');
        session_start();
    }

    $authCode = trim((string)($_SESSION['auth_code'] ?? ''));
    if ($authCode === '') {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized', 'hits' => []]);
        exit;
    }

    $authStmt = $pdo->prepare('SELECT auth_code, link_id, triplehook, referred_by FROM regular WHERE auth_code = :auth_code LIMIT 2');
    $authStmt->execute([':auth_code' => $authCode]);
    $authRows = $authStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($authRows) || count($authRows) !== 1) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized', 'hits' => []]);
        exit;
    }
    $authUser = $authRows[0];
    $storedAuth = (string)($authUser['auth_code'] ?? '');
    $linkId = (string)((int)($authUser['link_id'] ?? 0));
    if ($storedAuth === '' || !hash_equals($storedAuth, $authCode) || (int)$linkId <= 0) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized', 'hits' => []]);
        exit;
    }

    $isTriplehook = ((int)($authUser['triplehook'] ?? 0) === 1) || (isset($_GET['triplehook']) && $_GET['triplehook'] == '1' && (int)($authUser['triplehook'] ?? 0) === 1);
    $since = isset($_GET['since']) ? intval($_GET['since']) : 0;
    
    $scopeFilter = "";
    $params = [];
    $hasReferrer = false;
    
    $timeFilter = "";
    
    if ($isTriplehook && $linkId > 0) {
        $refStmt = $pdo->prepare("SELECT referred_by FROM regular WHERE link_id = :link_id LIMIT 1");
        $refStmt->execute([':link_id' => $linkId]);
        $refRow = $refStmt->fetch();
        $myReferredBy = $refRow['referred_by'] ?? 0;
        $hasReferrer = $myReferredBy > 0;
        
        if ($myReferredBy > 0) {
            $scopeFilter = " AND h.link_id IN (SELECT r3.link_id FROM regular r3 WHERE r3.referred_by IN (SELECT td.link_id FROM triplehook_data td INNER JOIN regular r2 ON r2.link_id = td.link_id WHERE r2.referred_by = :referred_by))";
            $params[':referred_by'] = $myReferredBy;
        } else {
            $scopeFilter = " AND h.link_id IN (SELECT r3.link_id FROM regular r3 WHERE r3.referred_by IN (SELECT td.link_id FROM triplehook_data td))";
        }
        if ($since > 0) {
            $timeFilter = "";
        } else {
            $timeFilter = "";
        }
        
        $selectFields = "h.id, h.username, h.avatar_url, h.robux, h.summary, h.rap, h.created_at,
                         r_owner.discord_username, r_owner.discord_avatar, r_owner.avatar_hidden";
        $joinClause = "FROM hits h 
                        LEFT JOIN regular r_hitter ON h.link_id = r_hitter.link_id 
                        LEFT JOIN regular r_owner ON r_hitter.referred_by = r_owner.link_id";
    } elseif ($linkId > 0) {
        $refStmt = $pdo->prepare("SELECT referred_by FROM regular WHERE link_id = :link_id LIMIT 1");
        $refStmt->execute([':link_id' => $linkId]);
        $refRow = $refStmt->fetch();
        $referredBy = $refRow['referred_by'] ?? 0;
        $hasReferrer = $referredBy > 0;
        
        if ($referredBy > 0) {
            $scopeFilter = " AND h.link_id IN (SELECT r2.link_id FROM regular r2 WHERE r2.referred_by = :referred_by)";
            $params[':referred_by'] = $referredBy;
        } else {
            if ($since > 0) {
                $timeFilter = "";
            } else {
                $timeFilter = "";
            }
        }
    }
    
    if (!$isTriplehook) {
        $selectFields = "h.id, h.username, h.avatar_url, h.robux, h.summary, h.rap, h.created_at,
                         r.discord_username, r.discord_avatar, r.avatar_hidden";
        $joinClause = "FROM hits h LEFT JOIN regular r ON h.link_id = r.link_id";
    }
    
    $stmt = $pdo->prepare("
        SELECT $selectFields $joinClause
        WHERE 1=1 AND h.hidden = 0 $timeFilter $scopeFilter
        ORDER BY h.created_at DESC 
        LIMIT 6
    ");
    $stmt->execute($params);
    $hits = $stmt->fetchAll();
    
    if (!$isTriplehook && empty($timeFilter) && count($hits) < 6) {
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) as total FROM hits h WHERE 1=1 AND h.hidden = 0 $scopeFilter
        ");
        $countStmt->execute($params);
        $totalHits = $countStmt->fetch()['total'] ?? 0;
        
        if ($totalHits >= 6) {
            $needed = 6 - count($hits);
            $excludeClause = '';
            $padParams = $params;
            
            if (!empty($hits)) {
                $existingIds = array_column($hits, 'id');
                $placeholders = [];
                foreach ($existingIds as $idx => $eid) {
                    $key = ':exclude_' . $idx;
                    $placeholders[] = $key;
                    $padParams[$key] = $eid;
                }
                $excludeClause = " AND h.id NOT IN (" . implode(',', $placeholders) . ")";
            }
            
            $padStmt = $pdo->prepare("
                SELECT $selectFields $joinClause
                WHERE 1=1 AND h.hidden = 0 $scopeFilter $excludeClause
                ORDER BY h.created_at DESC 
                LIMIT $needed
            ");
            $padStmt->execute($padParams);
            $olderHits = $padStmt->fetchAll();
            
            $hits = array_merge($hits, $olderHits);
        }
    }
    
    if (!empty($hits)) {
        $checkStmt = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, :latest, NOW()) as diff");
        $checkStmt->execute([':latest' => $hits[0]['created_at']]);
        $diff = (int)($checkStmt->fetch()['diff'] ?? 99999);
        $window = $hasReferrer ? 43200 : 1200;
        if ($diff > $window) {
            $hits = [];
        } else {
            $filtered = [$hits[0]];
            for ($i = 1; $i < count($hits); $i++) {
                $gapStmt = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, :older, :newer) as gap");
                $gapStmt->execute([':older' => $hits[$i]['created_at'], ':newer' => $hits[$i-1]['created_at']]);
                $gap = (int)($gapStmt->fetch()['gap'] ?? 99999);
                if ($gap > $window) break;
                $filtered[] = $hits[$i];
            }
            $hits = $filtered;
        }
    }
    
    echo json_encode([
        'success' => true,
        'hits' => $hits
    ]);
    
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error'
    ]);
}
?>