<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('token');
    session_start();
}

require_once dirname(__DIR__) . '/libs/configuration.php';
/* libs/functions.php is no longer loaded */
require_once dirname(__DIR__) . '/libs/connection.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('token');
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function ultimaTriplehookResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (session_name() !== 'token') {
    ultimaTriplehookResponse(['success' => false, 'error' => 'Unauthorized'], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ultimaTriplehookResponse(['success' => false, 'error' => 'Invalid method'], 405);
}

$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    ultimaTriplehookResponse(['success' => false, 'error' => 'Invalid content type'], 415);
}

$authCode = trim((string)($_SESSION['auth_code'] ?? ''));
if ($authCode === '' || strlen($authCode) > 512) {
    ultimaTriplehookResponse(['success' => false, 'error' => 'Unauthorized'], 401);
}

$body = file_get_contents('php://input');
if (!is_string($body) || strlen($body) > 2048) {
    ultimaTriplehookResponse(['success' => false, 'error' => 'Invalid request'], 400);
}

$data = json_decode($body, true);
if (!is_array($data) || !array_key_exists('triplehook', $data)) {
    ultimaTriplehookResponse(['success' => false, 'error' => 'Invalid request'], 400);
}

$requestedState = $data['triplehook'];
if ($requestedState === true || $requestedState === 1 || $requestedState === '1') {
    $newState = 1;
} elseif ($requestedState === false || $requestedState === 0 || $requestedState === '0') {
    $newState = 0;
} else {
    ultimaTriplehookResponse(['success' => false, 'error' => 'Invalid state'], 400);
}

$connection = isset($db) && $db instanceof \PDO
    ? $db
    : (isset($pdo) && $pdo instanceof \PDO ? $pdo : null);

if (!$connection) {
    ultimaTriplehookResponse(['success' => false, 'error' => 'Service unavailable'], 503);
}

try {
    $connection->beginTransaction();

    $userStatement = $connection->prepare(
        'SELECT auth_code, link_id, triplehook
         FROM regular
         WHERE auth_code = :auth_code
         LIMIT 2
         FOR UPDATE'
    );
    if (!$userStatement || !$userStatement->execute([':auth_code' => $authCode])) {
        throw new \RuntimeException();
    }
    $userRows = $userStatement->fetchAll(\PDO::FETCH_ASSOC);

    if (!is_array($userRows) || count($userRows) !== 1 || !is_array($userRows[0])) {
        $connection->rollBack();
        ultimaTriplehookResponse(['success' => false, 'error' => 'Unauthorized'], 401);
    }

    $user = $userRows[0];
    $storedAuthCode = (string)($user['auth_code'] ?? '');
    $linkId = (int)($user['link_id'] ?? 0);
    if ($storedAuthCode === '' || !hash_equals($storedAuthCode, $authCode) || $linkId <= 0) {
        $connection->rollBack();
        ultimaTriplehookResponse(['success' => false, 'error' => 'Unauthorized'], 401);
    }

    $entitlementStatement = $connection->prepare(
        'SELECT link_id
         FROM triplehook_data
         WHERE link_id = :link_id
         LIMIT 1
         FOR UPDATE'
    );
    if (!$entitlementStatement || !$entitlementStatement->execute([':link_id' => $linkId])) {
        throw new \RuntimeException();
    }
    if (!$entitlementStatement->fetch(\PDO::FETCH_ASSOC)) {
        $connection->rollBack();
        ultimaTriplehookResponse(['success' => false, 'error' => 'Triplehook unavailable'], 403);
    }

    $updateStatement = $connection->prepare(
        'UPDATE regular
         SET triplehook = :triplehook
         WHERE auth_code = :auth_code AND link_id = :link_id
         LIMIT 1'
    );
    if (!$updateStatement) {
        throw new \RuntimeException();
    }
    $updateStatement->bindValue(':triplehook', $newState, \PDO::PARAM_INT);
    $updateStatement->bindValue(':auth_code', $authCode, \PDO::PARAM_STR);
    $updateStatement->bindValue(':link_id', $linkId, \PDO::PARAM_INT);
    if (!$updateStatement->execute()) {
        throw new \RuntimeException();
    }

    $verifyStatement = $connection->prepare(
        'SELECT auth_code, link_id, triplehook
         FROM regular
         WHERE auth_code = :auth_code AND link_id = :link_id
         LIMIT 1'
    );
    if (!$verifyStatement || !$verifyStatement->execute([':auth_code' => $authCode, ':link_id' => $linkId])) {
        throw new \RuntimeException();
    }
    $verified = $verifyStatement->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($verified)) {
        $connection->rollBack();
        ultimaTriplehookResponse(['success' => false, 'error' => 'Update could not be verified'], 500);
    }
    $verifiedAuthCode = (string)($verified['auth_code'] ?? '');
    $verifiedLinkId = (int)($verified['link_id'] ?? 0);
    $verifiedState = (int)($verified['triplehook'] ?? 0);

    if (
        $verifiedAuthCode === '' ||
        !hash_equals($verifiedAuthCode, $authCode) ||
        $verifiedLinkId !== $linkId ||
        $verifiedState !== $newState
    ) {
        $connection->rollBack();
        ultimaTriplehookResponse(['success' => false, 'error' => 'Update could not be verified'], 500);
    }

    $connection->commit();
} catch (\Throwable $e) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    ultimaTriplehookResponse(['success' => false, 'error' => 'Update failed'], 500);
}

$_SESSION['triplehook'] = $verifiedState === 1 ? 'True' : 'False';

ultimaTriplehookResponse([
    'success' => true,
    'triplehook' => $verifiedState === 1,
]);
