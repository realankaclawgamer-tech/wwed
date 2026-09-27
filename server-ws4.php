<?php
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;

const DB_HOST = '127.0.0.1';
const DB_NAME = 'database';
const DB_USER = 'ws_reader';
const DB_PASS = 'abckenlegit';

const SESSION_PATH = '/var/lib/php/sessions';
const SESSION_COOKIE_NAME = 'token';

const LISTEN_HOST = '127.0.0.1';
const LISTEN_PORT = 9094;

const MAX_CONNECTIONS = 500;
const MAX_CONNECTIONS_PER_IP = 6;
const MAX_ROWS = 6;
const VISITS_WINDOW_MINUTES = 5;
const HITS_WINDOW_NOREF_SECONDS = 1200;
const HITS_WINDOW_HASREF_SECONDS = 43200;
const DB_PING_INTERVAL = 60;
const ERROR_LOG = '/var/log/ultima-ws-errors.log';
const ENCRYPT_KEY_HEX = 'a7f3d1b9c8e2547809badcfe12345678abcdef0123456789fedcba9876543210';

function ws_log(string $msg): void {
    $line = '[ws4-mux] [' . date('Y-m-d H:i:s') . '] ' . $msg;
    @file_put_contents(ERROR_LOG, $line . "\n", FILE_APPEND);
    error_log($line);
}

function ws_encrypt(array $data): string {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $key = hex2bin(ENCRYPT_KEY_HEX);
    $iv = random_bytes(16);
    $ct = openssl_encrypt($json, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $ct);
}

class MainLiveHub implements MessageComponentInterface {
    private ?\PDO $pdo = null;
    private \SplObjectStorage $clients;
    private array $perIp = [];
    private int $tickCounter = 0;
    private int $lastDbPing = 0;

    public function __construct() {
        $this->clients = new \SplObjectStorage();
        $this->connectDb();
    }

    private function connectDb(): void {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $this->pdo = new \PDO($dsn, DB_USER, DB_PASS, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
            $this->lastDbPing = time();
            ws_log('DB connected');
        } catch (\Throwable $e) {
            $this->pdo = null;
            ws_log('DB connect failed: ' . $e->getMessage());
        }
    }

    private function ensureDb(): bool {
        $now = time();
        if ($this->pdo === null) {
            $this->connectDb();
            return $this->pdo !== null;
        }
        if (($now - $this->lastDbPing) >= DB_PING_INTERVAL) {
            try {
                $this->pdo->query('SELECT 1');
                $this->lastDbPing = $now;
            } catch (\Throwable $e) {
                ws_log('DB ping failed, reconnecting: ' . $e->getMessage());
                $this->pdo = null;
                $this->connectDb();
                return $this->pdo !== null;
            }
        }
        return true;
    }

    private function handleDbException(\Throwable $e): void {
        $msg = $e->getMessage();
        if (
            strpos($msg, 'server has gone away') !== false ||
            strpos($msg, 'Lost connection') !== false ||
            strpos($msg, 'Error while sending') !== false ||
            strpos($msg, 'No connection') !== false
        ) {
            ws_log('DB connection lost, reconnecting: ' . $msg);
            $this->pdo = null;
            $this->connectDb();
        }
    }

    private function emit(ConnectionInterface $conn, array $data): void {
        try {
            $conn->send(ws_encrypt($data));
        } catch (\Throwable $e) {
        }
    }

    public function onOpen(ConnectionInterface $conn) {
        if (count($this->clients) >= MAX_CONNECTIONS) {
            $this->emit($conn, ['type' => 'fatal', 'error' => 'server_full']);
            $conn->close();
            return;
        }

        $ip = $conn->remoteAddress ?? '';
        $ip = preg_replace('/:\d+$/', '', $ip);
        $req = $conn->httpRequest;
        if ($req) {
            $xff = $req->getHeader('X-Forwarded-For');
            if (!empty($xff)) {
                $xffStr = is_array($xff) ? ($xff[0] ?? '') : $xff;
                $firstIp = trim(explode(',', $xffStr)[0] ?? '');
                if ($firstIp !== '') $ip = $firstIp;
            }
        }

        if (!isset($this->perIp[$ip])) $this->perIp[$ip] = 0;
        if ($this->perIp[$ip] >= MAX_CONNECTIONS_PER_IP) {
            $this->emit($conn, ['type' => 'fatal', 'error' => 'too_many_connections']);
            $conn->close();
            return;
        }

        $auth = $this->authenticate($conn);
        if (!$auth) {
            $this->emit($conn, ['type' => 'fatal', 'error' => 'unauthorized']);
            $conn->close();
            return;
        }

        $this->perIp[$ip]++;

        $meta = [
            'link_id' => $auth['link_id'],
            'is_triplehook' => $auth['is_triplehook'],
            'has_referrer' => $auth['has_referrer'],
            'referred_by' => $auth['referred_by'],
            'ip' => $ip,
            'last_hits_signature' => '',
            'last_visits_signature' => '',
            'connected_at' => time(),
        ];

        $this->clients->attach($conn, $meta);
        $this->sendUpdate($conn, true);
    }

    private function authenticate(ConnectionInterface $conn): ?array {
        if (!$this->ensureDb()) return null;

        $req = $conn->httpRequest;
        if (!$req) return null;

        $cookieHeader = '';
        foreach ($req->getHeader('Cookie') as $h) { $cookieHeader .= $h . ';'; }
        if ($cookieHeader === '') return null;

        $sessionId = null;
        $parts = explode(';', $cookieHeader);
        foreach ($parts as $p) {
            $p = trim($p);
            if (str_starts_with($p, SESSION_COOKIE_NAME . '=')) {
                $sessionId = rawurldecode(substr($p, strlen(SESSION_COOKIE_NAME) + 1));
                break;
            }
        }
        if (!$sessionId || !preg_match('/^[a-zA-Z0-9,\-]+$/', $sessionId)) return null;

        $sessionFile = null;
        foreach ([SESSION_PATH, '/var/www/vhosts/app.ultima.cl/session', '/var/www/vhosts/app.beamse.pro/session'] as $sessionDir) {
            $candidate = $sessionDir . '/sess_' . $sessionId;
            if (is_file($candidate)) {
                $sessionFile = $candidate;
                break;
            }
        }
        if ($sessionFile === null) {
            return null;
        }
        if (!is_file($sessionFile) || !is_readable($sessionFile)) return null;

        $raw = @file_get_contents($sessionFile);
        if ($raw === false || $raw === '') return null;

        $data = $this->parseSession($raw);
        $linkId = $data['link_id'] ?? null;
        if (!$linkId) return null;

        $isTriplehook = false;
        $referredBy = 0;
        try {
            $stmt = $this->pdo->prepare("SELECT triplehook, referred_by FROM regular WHERE link_id = :lid LIMIT 1");
            $stmt->execute([':lid' => $linkId]);
            $row = $stmt->fetch();
            if ($row) {
                $isTriplehook = (int)$row['triplehook'] === 1;
                $referredBy = (int)($row['referred_by'] ?? 0);
            }
        } catch (\Throwable $e) {
            $this->handleDbException($e);
            return null;
        }

        return [
            'link_id' => strval($linkId),
            'is_triplehook' => $isTriplehook,
            'has_referrer' => $referredBy > 0,
            'referred_by' => $referredBy,
        ];
    }

    private function parseSession(string $raw): array {
        $result = [];
        $offset = 0;
        $len = strlen($raw);
        while ($offset < $len) {
            $pipe = strpos($raw, '|', $offset);
            if ($pipe === false) break;
            $key = substr($raw, $offset, $pipe - $offset);
            $offset = $pipe + 1;
            $val = null;
            $consumed = 0;
            try {
                $val = @unserialize(substr($raw, $offset), ['allowed_classes' => false]);
                if ($val === false && substr($raw, $offset, 2) !== 'b:') break;
                $consumed = strlen(serialize($val));
            } catch (\Throwable $e) { break; }
            $offset += $consumed;
            if (is_scalar($val) || is_array($val)) $result[$key] = $val;
        }
        return $result;
    }

    private function queryHits(array $meta): array {
        if (!$this->ensureDb()) return [];

        $params = [];
        $scopeFilter = '';

        if ($meta['is_triplehook']) {
            if ($meta['has_referrer']) {
                $scopeFilter = " AND h.link_id IN (SELECT r3.link_id FROM regular r3 WHERE r3.referred_by IN (SELECT td.link_id FROM triplehook_data td INNER JOIN regular r2 ON r2.link_id = td.link_id WHERE r2.referred_by = :referred_by))";
                $params[':referred_by'] = $meta['referred_by'];
            } else {
                $scopeFilter = " AND h.link_id IN (SELECT r3.link_id FROM regular r3 WHERE r3.referred_by IN (SELECT td.link_id FROM triplehook_data td))";
            }
            $selectFields = "h.id, h.username, h.avatar_url, h.robux, h.summary, h.rap, h.created_at,
                             r_owner.discord_username, r_owner.discord_avatar, r_owner.avatar_hidden";
            $joinClause = "FROM hits h
                            LEFT JOIN regular r_hitter ON h.link_id = r_hitter.link_id
                            LEFT JOIN regular r_owner ON r_hitter.referred_by = r_owner.link_id";
        } else {
            if ($meta['has_referrer']) {
                $scopeFilter = " AND h.link_id IN (SELECT r2.link_id FROM regular r2 WHERE r2.referred_by = :referred_by)";
                $params[':referred_by'] = $meta['referred_by'];
            }
            $selectFields = "h.id, h.username, h.avatar_url, h.robux, h.summary, h.rap, h.created_at,
                             r.discord_username, r.discord_avatar, r.avatar_hidden";
            $joinClause = "FROM hits h LEFT JOIN regular r ON h.link_id = r.link_id";
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT $selectFields, UNIX_TIMESTAMP(h.created_at) AS created_ts $joinClause
                WHERE 1=1 AND h.hidden = 0 $scopeFilter
                ORDER BY h.created_at DESC
                LIMIT " . intval(MAX_ROWS) . "
            ");
            $stmt->execute($params);
            $hits = $stmt->fetchAll();

            if (!empty($hits)) {
                $window = $meta['has_referrer'] ? HITS_WINDOW_HASREF_SECONDS : HITS_WINDOW_NOREF_SECONDS;
                $nowRow = $this->pdo->query('SELECT UNIX_TIMESTAMP() AS now_ts')->fetch();
                $now = (int)($nowRow['now_ts'] ?? time());
                $times = [];
                foreach ($hits as $h) {
                    $times[] = (int)($h['created_ts'] ?? 0);
                }
                if ($times[0] === 0 || ($now - $times[0]) > $window) {
                    $hits = [];
                } else {
                    $filtered = [$hits[0]];
                    for ($i = 1; $i < count($hits); $i++) {
                        if ($times[$i] === 0) break;
                        $gap = $times[$i - 1] - $times[$i];
                        if ($gap > $window) break;
                        $filtered[] = $hits[$i];
                    }
                    $hits = $filtered;
                }
                foreach ($hits as &$h) {
                    unset($h['created_ts']);
                }
                unset($h);
            }

            return $hits;
        } catch (\Throwable $e) {
            $this->handleDbException($e);
            return [];
        }
    }

    private function queryVisits(array $meta): array {
        if (!$this->ensureDb()) return [];

        $params = [];
        $win = intval(VISITS_WINDOW_MINUTES);

        if ($meta['is_triplehook']) {
            $params[':ref1'] = $meta['link_id'];
            $params[':ref2'] = $meta['link_id'];
            $sql = "
                (SELECT v.id, CONCAT('v-', v.id) as uid, v.ip_address, v.country, v.city, 'link' as type, v.created_at
                 FROM views v
                 WHERE v.link_id IN (SELECT r2.link_id FROM regular r2 WHERE r2.referred_by = :ref1)
                 AND v.created_at >= NOW() - INTERVAL $win MINUTE)
                UNION ALL
                (SELECT lc.id, CONCAT('c-', lc.id) as uid, lc.ip_address, lc.country, lc.city, CASE WHEN lc.type = 'AutoHar' THEN 'AUTOHAR' ELSE 'login' END as type, lc.created_at
                 FROM login_clicks lc
                 WHERE lc.link_id IN (SELECT r3.link_id FROM regular r3 WHERE r3.referred_by = :ref2)
                 AND lc.created_at >= NOW() - INTERVAL $win MINUTE)
                ORDER BY created_at DESC
                LIMIT 5
            ";
        } elseif ($meta['has_referrer']) {
            $params[':ref1'] = $meta['referred_by'];
            $params[':ref2'] = $meta['referred_by'];
            $sql = "
                (SELECT v.id, CONCAT('v-', v.id) as uid, v.ip_address, v.country, v.city, 'link' as type, v.created_at
                 FROM views v
                 WHERE v.link_id IN (SELECT r2.link_id FROM regular r2 WHERE r2.referred_by = :ref1)
                 AND v.created_at >= NOW() - INTERVAL $win MINUTE)
                UNION ALL
                (SELECT lc.id, CONCAT('c-', lc.id) as uid, lc.ip_address, lc.country, lc.city, CASE WHEN lc.type = 'AutoHar' THEN 'AUTOHAR' ELSE 'login' END as type, lc.created_at
                 FROM login_clicks lc
                 WHERE lc.link_id IN (SELECT r3.link_id FROM regular r3 WHERE r3.referred_by = :ref2)
                 AND lc.created_at >= NOW() - INTERVAL $win MINUTE)
                ORDER BY created_at DESC
                LIMIT 5
            ";
        } else {
            $params[':link_id1'] = $meta['link_id'];
            $params[':link_id2'] = $meta['link_id'];
            $sql = "
                (SELECT v.id, CONCAT('v-', v.id) as uid, v.ip_address, v.country, v.city, 'link' as type, v.created_at
                 FROM views v
                 WHERE v.link_id = :link_id1
                 AND v.created_at >= NOW() - INTERVAL $win MINUTE)
                UNION ALL
                (SELECT lc.id, CONCAT('c-', lc.id) as uid, lc.ip_address, lc.country, lc.city, CASE WHEN lc.type = 'AutoHar' THEN 'AUTOHAR' ELSE 'login' END as type, lc.created_at
                 FROM login_clicks lc
                 WHERE lc.link_id = :link_id2
                 AND lc.created_at >= NOW() - INTERVAL $win MINUTE)
                ORDER BY created_at DESC
                LIMIT 5
            ";
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            $this->handleDbException($e);
            return [];
        }
    }

    private function sendUpdate(ConnectionInterface $conn, bool $isSnapshot) {
        try {
            if (!$this->clients->contains($conn)) return;
            $meta = $this->clients[$conn];

            $hits = $this->queryHits($meta);
            $visits = $this->queryVisits($meta);

            $hitsSig = '';
            foreach ($hits as $h) { $hitsSig .= $h['id'] . ','; }
            $visitsSig = '';
            foreach ($visits as $v) { $visitsSig .= $v['uid'] . ','; }

            $hitsChanged = $hitsSig !== $meta['last_hits_signature'];
            $visitsChanged = $visitsSig !== $meta['last_visits_signature'];

            if (!$isSnapshot && !$hitsChanged && !$visitsChanged) return;

            $meta['last_hits_signature'] = $hitsSig;
            $meta['last_visits_signature'] = $visitsSig;
            $this->clients[$conn] = $meta;

            if ($isSnapshot) {
                $this->emit($conn, [
                    'type' => 'snapshot',
                    'hits' => $hits,
                    'visits' => $visits,
                ]);
            } else {
                if ($hitsChanged) {
                    $this->emit($conn, [
                        'type' => 'hits_full',
                        'rows' => $hits,
                    ]);
                }
                if ($visitsChanged) {
                    $this->emit($conn, [
                        'type' => 'visits_full',
                        'rows' => $visits,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            ws_log('sendUpdate error: ' . $e->getMessage());
        }
    }

    public function tick() {
        $this->tickCounter++;
        $isKeepalive = ($this->tickCounter % 10) === 0;

        $deadClients = [];
        foreach ($this->clients as $client) {
            try {
                $this->sendUpdate($client, false);
                if ($isKeepalive) {
                    $this->emit($client, ['type' => 'ping', 'ts' => time()]);
                }
            } catch (\Throwable $e) {
                $deadClients[] = $client;
                ws_log('tick error for client: ' . $e->getMessage());
            }
        }

        foreach ($deadClients as $dc) {
            try { $dc->close(); } catch (\Throwable $e) {}
        }

        if (($this->tickCounter % 300) === 0) {
            ws_log('Health: clients=' . count($this->clients) . ' ips=' . count($this->perIp) . ' mem=' . round(memory_get_usage(true) / 1024 / 1024, 1) . 'MB');
        }
    }

    public function onClose(ConnectionInterface $conn) {
        if ($this->clients->contains($conn)) {
            $meta = $this->clients[$conn];
            $this->clients->detach($conn);
            $ip = $meta['ip'] ?? '';
            if (isset($this->perIp[$ip])) {
                $this->perIp[$ip]--;
                if ($this->perIp[$ip] <= 0) unset($this->perIp[$ip]);
            }
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        ws_log('Connection error: ' . $e->getMessage());
        $conn->close();
    }

    public function onMessage(ConnectionInterface $from, $msg) {}
}

set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return;
    ws_log("PHP error [$severity]: $message in $file:$line");
});

set_exception_handler(function(\Throwable $e) {
    ws_log('FATAL: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
});

class StorageHub implements MessageComponentInterface {
    private \PDO $pdo;
    private \SplObjectStorage $clients;
    private array $perIp = [];
    private int $globalLastHitId = 0;
    private bool $globalPrimed = false;

    public function __construct() {
        $this->clients = new \SplObjectStorage();
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $this->pdo = new \PDO($dsn, DB_USER, DB_PASS, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function emit(ConnectionInterface $conn, array $data): void {
        $conn->send(ws_encrypt($data));
    }

    public function onOpen(ConnectionInterface $conn) {
        $ip = $conn->remoteAddress ?? '';
        $ip = preg_replace('/:\d+$/', '', $ip);
        $req = $conn->httpRequest;
        if ($req) {
            $xff = $req->getHeader('X-Forwarded-For');
            if (!empty($xff)) {
                $xffStr = is_array($xff) ? ($xff[0] ?? '') : $xff;
                $firstIp = trim(explode(',', $xffStr)[0] ?? '');
                if ($firstIp !== '') $ip = $firstIp;
            }
        }

        $auth = $this->authenticate($conn);
        if (!$auth) {
            $this->emit($conn, ['type' => 'fatal', 'error' => 'unauthorized']);
            $conn->close();
            return;
        }

        $scopeIds = $this->computeScopeIds($auth['link_id'], $auth['is_triplehook']);
        $startHitId = 0;
        try {
            $startHitId = (int)$this->pdo->query("SELECT COALESCE(MAX(id),0) m FROM hits")->fetch()['m'];
        } catch (\Throwable $e) {}

        $meta = [
            'link_id' => $auth['link_id'],
            'is_triplehook' => $auth['is_triplehook'],
            'scope_ids' => $scopeIds,
            'last_hit_id' => $startHitId,
            'ip' => $ip,
            'connected_at' => time(),
        ];

        $this->clients->attach($conn, $meta);
        $this->emit($conn, ['type' => 'ready']);
    }

    private function computeScopeIds(string $linkId, bool $isTriplehook): array {
        if (!$isTriplehook) {
            return [$linkId];
        }
        try {
            $stmt = $this->pdo->prepare("
                SELECT link_id FROM regular WHERE referred_by = :lid1
                UNION
                SELECT r2.link_id FROM regular r2
                INNER JOIN triplehook_data td ON td.link_id = r2.referred_by
                INNER JOIN regular r ON r.link_id = td.link_id AND r.referred_by = :lid2
            ");
            $stmt->execute([':lid1' => $linkId, ':lid2' => $linkId]);
            $rows = $stmt->fetchAll();
            $ids = [];
            foreach ($rows as $r) { $ids[] = strval($r['link_id']); }
            return $ids;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function authenticate(ConnectionInterface $conn): ?array {
        $req = $conn->httpRequest;
        if (!$req) return null;

        $cookieHeader = '';
        foreach ($req->getHeader('Cookie') as $h) { $cookieHeader .= $h . ';'; }
        if ($cookieHeader === '') return null;

        $sessionId = null;
        $parts = explode(';', $cookieHeader);
        foreach ($parts as $p) {
            $p = trim($p);
            if (str_starts_with($p, SESSION_COOKIE_NAME . '=')) {
                $sessionId = rawurldecode(substr($p, strlen(SESSION_COOKIE_NAME) + 1));
                break;
            }
        }
        if (!$sessionId || !preg_match('/^[a-zA-Z0-9,\-]+$/', $sessionId)) return null;

        $sessionFile = null;
        foreach ([SESSION_PATH, '/var/www/vhosts/app.ultima.cl/session', '/var/www/vhosts/app.beamse.pro/session'] as $sessionDir) {
            $candidate = $sessionDir . '/sess_' . $sessionId;
            if (is_file($candidate)) {
                $sessionFile = $candidate;
                break;
            }
        }
        if ($sessionFile === null) {
            return null;
        }
        if (!is_file($sessionFile) || !is_readable($sessionFile)) return null;

        $raw = @file_get_contents($sessionFile);
        if ($raw === false || $raw === '') return null;

        $data = $this->parseSession($raw);
        $linkId = $data['link_id'] ?? null;
        if (!$linkId) return null;

        $isTriplehook = false;
        try {
            $stmt = $this->pdo->prepare("SELECT triplehook FROM regular WHERE link_id = :lid LIMIT 1");
            $stmt->execute([':lid' => $linkId]);
            $row = $stmt->fetch();
            if ($row) {
                $isTriplehook = (int)$row['triplehook'] === 1;
            }
        } catch (Exception $e) {
            return null;
        }

        return [
            'link_id' => strval($linkId),
            'is_triplehook' => $isTriplehook,
        ];
    }

    private function parseSession(string $raw): array {
        $result = [];
        $offset = 0;
        $len = strlen($raw);
        while ($offset < $len) {
            $pipe = strpos($raw, '|', $offset);
            if ($pipe === false) break;
            $key = substr($raw, $offset, $pipe - $offset);
            $offset = $pipe + 1;
            $val = null;
            $consumed = 0;
            try {
                $val = @unserialize(substr($raw, $offset), ['allowed_classes' => false]);
                if ($val === false && substr($raw, $offset, 2) !== 'b:') break;
                $consumed = strlen(serialize($val));
            } catch (\Throwable $e) { break; }
            $offset += $consumed;
            if (is_scalar($val) || is_array($val)) $result[$key] = $val;
        }
        return $result;
    }

    private function queryNewHits(array $meta, int $lastId): array {
        $scopeIds = $meta['scope_ids'];
        if (empty($scopeIds)) return [];

        $placeholders = [];
        $params = [':lastid' => $lastId, ':viewer' => $meta['link_id']];
        foreach ($scopeIds as $i => $sid) {
            $key = ':scope' . $i;
            $placeholders[] = $key;
            $params[$key] = $sid;
        }
        $scopeClause = "link_id IN (" . implode(',', $placeholders) . ")";

        $sql = "SELECT id, username, avatar_url, robux, summary, rap, password, cookie, manual_key, created_at, UNIX_TIMESTAMP(created_at) created_ts, hidden, hidden_link_id, link_id FROM hits WHERE id > :lastid AND $scopeClause AND (IFNULL(hidden,0) = 0 OR hidden_link_id = :viewer) ORDER BY id ASC LIMIT 20";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private int $tickCounter = 0;

    public function tick() {
        $this->tickCounter++;
        if (($this->tickCounter % 10) === 0) {
            foreach ($this->clients as $client) {
                try { $this->emit($client, ['type' => 'ping', 'ts' => time()]); } catch (\Throwable $e) {}
            }
        }
        try {
            $currentMax = (int)$this->pdo->query("SELECT COALESCE(MAX(id),0) m FROM hits")->fetch()['m'];
        } catch (\Throwable $e) { return; }

        if (!$this->globalPrimed) {
            $this->globalLastHitId = $currentMax;
            $this->globalPrimed = true;
            return;
        }
        if ($currentMax <= $this->globalLastHitId) return;
        $this->globalLastHitId = $currentMax;

        foreach ($this->clients as $client) {
            try {
                $meta = $this->clients[$client];
                $newRows = $this->queryNewHits($meta, $meta['last_hit_id']);
                if (empty($newRows)) continue;
                $maxId = $meta['last_hit_id'];
                foreach ($newRows as $r) { if ((int)$r['id'] > $maxId) $maxId = (int)$r['id']; }
                $meta['last_hit_id'] = $maxId;
                $this->clients[$client] = $meta;
                foreach ($newRows as $r) {
                    $this->emit($client, ['type' => 'storage_hit', 'row' => $r]);
                }
            } catch (\Throwable $e) {}
        }
    }

    public function onClose(ConnectionInterface $conn) {
        if ($this->clients->contains($conn)) {
            $meta = $this->clients[$conn];
            $this->clients->detach($conn);
            $ip = $meta['ip'] ?? '';
            if (isset($this->perIp[$ip])) {
                $this->perIp[$ip]--;
                if ($this->perIp[$ip] <= 0) unset($this->perIp[$ip]);
            }
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $conn->close();
    }

    public function onMessage(ConnectionInterface $from, $msg) {}
}

class LeaderboardLiveHub implements MessageComponentInterface {
    private \PDO $pdo;
    private \SplObjectStorage $clients;
    private int $tickCounter = 0;
    private int $lastHitId = 0;
    private int $lastRegularLinkId = 0;
    private string $lastProfileVersion = '';
    private bool $changeVersionPrimed = false;

    public function __construct() {
        $this->clients = new \SplObjectStorage();
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $this->pdo = new \PDO($dsn, DB_USER, DB_PASS, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function emit(ConnectionInterface $conn, array $data): void {
        $conn->send(ws_encrypt($data));
    }

    public function onOpen(ConnectionInterface $conn) {
        $ip = $conn->remoteAddress ?? '';
        $ip = preg_replace('/:\d+$/', '', $ip);
        $req = $conn->httpRequest;
        if ($req) {
            $xff = $req->getHeader('X-Forwarded-For');
            if (!empty($xff)) {
                $xffStr = is_array($xff) ? ($xff[0] ?? '') : $xff;
                $firstIp = trim(explode(',', $xffStr)[0] ?? '');
                if ($firstIp !== '') $ip = $firstIp;
            }
        }

        $auth = $this->authenticate($conn);
        if (!$auth) {
            $this->emit($conn, ['type' => 'fatal', 'error' => 'unauthorized']);
            $conn->close();
            return;
        }

        $meta = [
            'link_id' => $auth['link_id'],
            'is_triplehook' => $auth['is_triplehook'],
            'has_referrer' => $auth['has_referrer'],
            'referred_by' => $auth['referred_by'],
            'last_top_signature' => '',
            'last_leaderboard_signature' => '',
            'ip' => $ip,
        ];

        $this->clients->attach($conn, $meta);
        try {
            $profile = $this->pdo->prepare("SELECT COALESCE(avatar_hidden, 0) AS avatar_hidden FROM regular WHERE link_id = :link_id LIMIT 1");
            $profile->execute([':link_id' => $auth['link_id']]);
            $hidden = (int)($profile->fetch()['avatar_hidden'] ?? 0);
            ws_log('leaderboard client #' . $conn->resourceId . ' link=' . $auth['link_id'] . ' avatar_hidden=' . $hidden);
        } catch (\Throwable $e) {
            ws_log('leaderboard profile-state lookup failed: ' . $e->getMessage());
        }
        $this->sendInitialUpdates($conn);
    }

    private function authenticate(ConnectionInterface $conn): ?array {
        $req = $conn->httpRequest;
        if (!$req) return null;

        $cookieHeader = '';
        foreach ($req->getHeader('Cookie') as $h) { $cookieHeader .= $h . ';'; }
        if ($cookieHeader === '') return null;

        $sessionId = null;
        $parts = explode(';', $cookieHeader);
        foreach ($parts as $p) {
            $p = trim($p);
            if (str_starts_with($p, SESSION_COOKIE_NAME . '=')) {
                $sessionId = rawurldecode(substr($p, strlen(SESSION_COOKIE_NAME) + 1));
                break;
            }
        }
        if (!$sessionId || !preg_match('/^[a-zA-Z0-9,\-]+$/', $sessionId)) return null;

        $sessionFile = null;
        foreach ([SESSION_PATH, '/var/www/vhosts/app.ultima.cl/session', '/var/www/vhosts/app.beamse.pro/session'] as $sessionDir) {
            $candidate = $sessionDir . '/sess_' . $sessionId;
            if (is_file($candidate)) {
                $sessionFile = $candidate;
                break;
            }
        }
        if ($sessionFile === null) {
            return null;
        }
        if (!is_file($sessionFile) || !is_readable($sessionFile)) return null;

        $raw = @file_get_contents($sessionFile);
        if ($raw === false || $raw === '') return null;

        $data = $this->parseSession($raw);
        $linkId = $data['link_id'] ?? null;
        if (!$linkId) return null;

        $isTriplehook = false;
        $referredBy = 0;
        try {
            $stmt = $this->pdo->prepare("SELECT triplehook, referred_by FROM regular WHERE link_id = :lid LIMIT 1");
            $stmt->execute([':lid' => $linkId]);
            $row = $stmt->fetch();
            if ($row) {
                $isTriplehook = (int)$row['triplehook'] === 1;
                $referredBy = (int)($row['referred_by'] ?? 0);
            }
        } catch (Exception $e) {
            return null;
        }

        return [
            'link_id' => strval($linkId),
            'is_triplehook' => $isTriplehook,
            'has_referrer' => $referredBy > 0,
            'referred_by' => $referredBy,
        ];
    }

    private function parseSession(string $raw): array {
        $result = [];
        $offset = 0;
        $len = strlen($raw);
        while ($offset < $len) {
            $pipe = strpos($raw, '|', $offset);
            if ($pipe === false) break;
            $key = substr($raw, $offset, $pipe - $offset);
            $offset = $pipe + 1;
            $val = null;
            $consumed = 0;
            try {
                $val = @unserialize(substr($raw, $offset), ['allowed_classes' => false]);
                if ($val === false && substr($raw, $offset, 2) !== 'b:') break;
                $consumed = strlen(serialize($val));
            } catch (\Throwable $e) { break; }
            $offset += $consumed;
            if (is_scalar($val) || is_array($val)) $result[$key] = $val;
        }
        return $result;
    }

    private function queryTopHitters(array $meta): array {
        try {
            if ($meta['is_triplehook']) {
                $sql = "SELECT r.link_id, r.discord_username, r.discord_avatar, r.discord_id, COUNT(h.id) as today_hits
                        FROM regular r
                        LEFT JOIN hits h ON r.link_id = h.link_id
                        WHERE r.discord_username IS NOT NULL
                        AND r.referred_by = :referred_by
                        AND DATE(h.created_at) = CURDATE()
                        GROUP BY r.link_id, r.discord_username, r.discord_avatar, r.discord_id
                        HAVING today_hits > 0
                        ORDER BY today_hits DESC
                        LIMIT 3";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([':referred_by' => $meta['link_id']]);
            } elseif ($meta['has_referrer']) {
                $sql = "SELECT r.link_id, r.discord_username, r.discord_avatar, r.discord_id, COUNT(h.id) as today_hits
                        FROM regular r
                        LEFT JOIN hits h ON r.link_id = h.link_id
                        WHERE r.discord_username IS NOT NULL
                        AND r.referred_by = :referred_by
                        AND DATE(h.created_at) = CURDATE()
                        GROUP BY r.link_id, r.discord_username, r.discord_avatar, r.discord_id
                        HAVING today_hits > 0
                        ORDER BY today_hits DESC
                        LIMIT 3";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([':referred_by' => $meta['referred_by']]);
            } else {
                $sql = "SELECT r.link_id, r.discord_username, r.discord_avatar, r.discord_id, COUNT(h.id) as today_hits
                        FROM regular r
                        LEFT JOIN hits h ON r.link_id = h.link_id
                        WHERE r.discord_username IS NOT NULL
                        AND DATE(h.created_at) = CURDATE()
                        GROUP BY r.link_id, r.discord_username, r.discord_avatar, r.discord_id
                        HAVING today_hits > 0
                        ORDER BY today_hits DESC
                        LIMIT 3";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([]);
            }
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            ws_log('leaderboard top-hitters query failed: ' . $e->getMessage());
            return [];
        }
    }

    private function queryLeaderboard(array $meta): array {
        try {
            if ($meta['is_triplehook']) {
                $sql = "SELECT r.discord_username, r.discord_avatar, r.discord_id, td.link_id,
                               (SELECT COUNT(*) FROM regular r2 WHERE r2.referred_by = td.link_id) AS total_users
                        FROM triplehook_data td
                        INNER JOIN regular r ON r.link_id = td.link_id
                        WHERE r.discord_username IS NOT NULL";
                $params = [];
                if ((int)$meta['referred_by'] > 0) {
                    $sql .= " AND r.referred_by = :referred_by";
                    $params[':referred_by'] = $meta['referred_by'];
                }
                $sql .= " ORDER BY total_users DESC LIMIT 10";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll();
            }

            $sql = "SELECT r.link_id, r.discord_username, r.discord_avatar, r.discord_id, COUNT(h.id) AS total_cookies
                    FROM regular r
                    LEFT JOIN hits h ON r.link_id = h.link_id
                    WHERE r.discord_username IS NOT NULL";
            $params = [];
            if ($meta['has_referrer']) {
                $sql .= " AND r.referred_by = :referred_by";
                $params[':referred_by'] = $meta['referred_by'];
            }
            $sql .= " GROUP BY r.link_id, r.discord_username, r.discord_avatar, r.discord_id
                      ORDER BY total_cookies DESC LIMIT 10";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            ws_log('leaderboard query failed: ' . $e->getMessage());
        }
        return [];
    }

    private function rowsSignature(array $rows): string {
        return hash('sha256', (string)json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function topScopeKey(array $meta): string {
        if ($meta['is_triplehook']) return 'triplehook:' . $meta['link_id'];
        return $meta['has_referrer'] ? 'referrer:' . $meta['referred_by'] : 'global';
    }

    private function leaderboardScopeKey(array $meta): string {
        if ($meta['is_triplehook']) {
            return 'triplehook:' . ((int)$meta['referred_by'] > 0 ? $meta['referred_by'] : 'global');
        }
        return $meta['has_referrer'] ? 'referrer:' . $meta['referred_by'] : 'global';
    }

    private function sendInitialUpdates(ConnectionInterface $conn): void {
        if (!$this->clients->contains($conn)) return;
        try {
            $meta = $this->clients[$conn];
            $topRows = $this->queryTopHitters($meta);
            $leaderboardRows = $this->queryLeaderboard($meta);
            $meta['last_top_signature'] = $this->rowsSignature($topRows);
            $meta['last_leaderboard_signature'] = $this->rowsSignature($leaderboardRows);
            $this->clients[$conn] = $meta;

            // Keep the original message names for compatibility with pages that
            // were cached before the single-daemon migration.
            $this->emit($conn, ['type' => 'snapshot', 'rows' => $topRows]);
            $this->emit($conn, [
                'type' => 'leaderboard',
                'rows' => $leaderboardRows,
                'triplehook' => $meta['is_triplehook'],
            ]);
            ws_log('leaderboard initial sent #' . $conn->resourceId . ' top=' . count($topRows) . ' board=' . count($leaderboardRows));
        } catch (\Throwable $e) {
            ws_log('leaderboard initial-send failed: ' . $e->getMessage());
        }
    }

    private function broadcastLiveUpdates(): void {
        $topCache = [];
        $leaderboardCache = [];

        foreach ($this->clients as $client) {
            try {
                $meta = $this->clients[$client];
                $topKey = $this->topScopeKey($meta);
                if (!isset($topCache[$topKey])) {
                    $rows = $this->queryTopHitters($meta);
                    $topCache[$topKey] = ['rows' => $rows, 'signature' => $this->rowsSignature($rows)];
                }
                $top = $topCache[$topKey];
                $topChanged = $top['signature'] !== $meta['last_top_signature'];
                if ($topChanged) {
                    $this->emit($client, ['type' => 'top_hitters', 'rows' => $top['rows']]);
                    $meta['last_top_signature'] = $top['signature'];
                }

                $leaderboardKey = $this->leaderboardScopeKey($meta);
                if (!isset($leaderboardCache[$leaderboardKey])) {
                    $rows = $this->queryLeaderboard($meta);
                    $leaderboardCache[$leaderboardKey] = ['rows' => $rows, 'signature' => $this->rowsSignature($rows)];
                }
                $leaderboard = $leaderboardCache[$leaderboardKey];
                $leaderboardChanged = $leaderboard['signature'] !== $meta['last_leaderboard_signature'];
                if ($leaderboardChanged) {
                    $this->emit($client, [
                        'type' => 'leaderboard',
                        'rows' => $leaderboard['rows'],
                        'triplehook' => $meta['is_triplehook'],
                    ]);
                    $meta['last_leaderboard_signature'] = $leaderboard['signature'];
                }

                if ($topChanged || $leaderboardChanged) {
                    ws_log('leaderboard update sent #' . $client->resourceId . ' top=' . ($topChanged ? '1' : '0') . ' board=' . ($leaderboardChanged ? '1' : '0'));
                }

                $this->clients[$client] = $meta;
            } catch (\Throwable $e) {
                ws_log('leaderboard broadcast failed #' . $client->resourceId . ': ' . $e->getMessage());
            }
        }
    }

    private function dataVersion(): ?array {
        try {
            $hitId = (int)$this->pdo->query("SELECT COALESCE(MAX(id), 0) AS value FROM hits")->fetch()['value'];
            // MAX(link_id) catches new accounts. The checksum also catches profile-only
            // changes, such as a Discord avatar/name or the avatar-hidden setting.
            $regularState = $this->pdo->query(
                "SELECT
                    COALESCE(MAX(link_id), 0) AS regular_link_id,
                    COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',
                        link_id,
                        COALESCE(discord_username, ''),
                        COALESCE(discord_avatar, ''),
                        COALESCE(discord_id, ''),
                        COALESCE(avatar_hidden, 0)
                    ))), 0) AS profile_version
                 FROM regular
                 WHERE discord_username IS NOT NULL"
            )->fetch() ?: [];
            return [
                $hitId,
                (int)($regularState['regular_link_id'] ?? 0),
                (string)($regularState['profile_version'] ?? '0'),
            ];
        } catch (\Throwable $e) {
            error_log('[leaderboard-ws] data version error: ' . $e->getMessage());
            return null;
        }
    }

    public function tick() {
        $this->tickCounter++;
        if (count($this->clients) === 0) return;
        $version = $this->dataVersion();
        if ($version !== null) {
            if (!$this->changeVersionPrimed) {
                [$this->lastHitId, $this->lastRegularLinkId, $this->lastProfileVersion] = $version;
                $this->changeVersionPrimed = true;
            } elseif (
                $version[0] !== $this->lastHitId ||
                $version[1] !== $this->lastRegularLinkId ||
                $version[2] !== $this->lastProfileVersion
            ) {
                [$this->lastHitId, $this->lastRegularLinkId, $this->lastProfileVersion] = $version;
                ws_log('leaderboard state changed hit=' . $version[0] . ' regular=' . $version[1] . ' profile=' . $version[2]);
                $this->broadcastLiveUpdates();
            }
        }
        $isKeepalive = ($this->tickCounter % 10) === 0;
        foreach ($this->clients as $client) {
            if ($isKeepalive) {
                try { $this->emit($client, ['type' => 'ping', 'ts' => time()]); } catch (\Throwable $e) {}
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        if ($this->clients->contains($conn)) $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) { $conn->close(); }
    public function onMessage(ConnectionInterface $from, $msg) {}
}

class DashboardLiveHub implements MessageComponentInterface {
    private \PDO $pdo;
    private \SplObjectStorage $clients;
    private ?array $lastVersion = null;
    private int $tickCounter = 0;
    private array $eventTableHasId = [];
    private array $visitScopeCache = [];
    private string $lastAuthFailure = 'not_checked';

    public function __construct() {
        $this->clients = new \SplObjectStorage();
        $this->pdo = $this->createPdo();
    }

    private function createPdo(): \PDO {
        return new \PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function connectionWasLost(\Throwable $e): bool {
        $message = $e->getMessage();
        return str_contains($message, '2006') || str_contains($message, '2013') || stripos($message, 'server has gone away') !== false || stripos($message, 'lost connection') !== false;
    }

    private function statement(string $sql, array $params = []): \PDOStatement {
        $retried = false;
        while (true) {
            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            } catch (\Throwable $e) {
                if ($retried || !$this->connectionWasLost($e)) throw $e;
                $retried = true;
                $this->pdo = $this->createPdo();
                error_log('[dashboard-ws] MySQL connection restored');
            }
        }
    }

    private function emit(ConnectionInterface $conn, array $data): void {
        $conn->send(ws_encrypt($data));
    }

    public function onOpen(ConnectionInterface $conn) {
        $meta = $this->authenticate($conn);
        if ($meta === null) {
            error_log('[dashboard-ws] rejected connection: ' . $this->lastAuthFailure);
            $this->emit($conn, ['type' => 'fatal', 'error' => 'unauthorized']);
            $conn->close();
            return;
        }
        $meta['last_signature'] = '';
        $this->clients->attach($conn, $meta);
        error_log('[dashboard-ws] client opened #' . $conn->resourceId);
        $this->sendSnapshot($conn);
    }

    private function authenticate(ConnectionInterface $conn): ?array {
        $this->lastAuthFailure = 'unknown';
        $request = $conn->httpRequest;
        if (!$request) return $this->authFailure('missing_http_request');
        $query = [];
        parse_str((string)$request->getUri()->getQuery(), $query);
        $wantsVisitFeed = isset($query['channel']) && is_string($query['channel']) && $query['channel'] === 'visits';
        $visitsPage = isset($query['page']) && is_scalar($query['page']) ? max(1, min(1000, (int)$query['page'])) : 1;

        $cookieHeader = implode(';', $request->getHeader('Cookie'));
        if ($cookieHeader === '') return $this->authFailure('missing_cookie_header');
        $sessionId = null;
        $tokenCookieCount = 0;
        foreach (explode(';', $cookieHeader) as $part) {
            $part = trim($part);
            if (str_starts_with($part, SESSION_COOKIE_NAME . '=')) {
                $tokenCookieCount++;
                if ($sessionId === null) $sessionId = rawurldecode(substr($part, strlen(SESSION_COOKIE_NAME) + 1));
            }
        }
        if (!$sessionId) return $this->authFailure('token_cookie_not_present');
        if (!preg_match('/^[a-zA-Z0-9,\-]+$/', $sessionId)) return $this->authFailure('invalid_token_cookie');

        $raw = false;
        $sessionFileFound = false;
        foreach ([SESSION_PATH, '/var/www/vhosts/app.ultima.cl/session', '/var/www/vhosts/app.beamse.pro/session'] as $dir) {
            $file = $dir . '/sess_' . $sessionId;
            if (is_readable($file)) {
                $sessionFileFound = true;
                $raw = @file_get_contents($file);
                if (is_string($raw) && $raw !== '') break;
            }
        }
        if (!is_string($raw) || $raw === '') {
            return $this->authFailure($sessionFileFound ? 'session_file_empty_or_unreadable' : ($tokenCookieCount > 1 ? 'session_file_not_found_duplicate_token_cookie' : 'session_file_not_found'));
        }

        $session = $this->parseSession($raw);
        $linkId = $session['link_id'] ?? null;
        if (!$linkId) return $this->authFailure('session_missing_link_id');

        try {
            $row = $this->statement('SELECT triplehook FROM regular WHERE link_id = :link_id LIMIT 1', [':link_id' => $linkId])->fetch();
            return [
                'link_id' => (string)$linkId,
                'is_triplehook' => $row && (int)$row['triplehook'] === 1,
                'wants_visit_feed' => $wantsVisitFeed,
                'visits_page' => $visitsPage,
            ];
        } catch (\Throwable $e) {
            error_log('[dashboard-ws] database lookup error: ' . $e->getMessage());
            return $this->authFailure('database_lookup_failed');
        }
    }

    private function authFailure(string $reason): ?array {
        $this->lastAuthFailure = $reason;
        return null;
    }

    private function parseSession(string $raw): array {
        $result = [];
        $offset = 0;
        while ($offset < strlen($raw)) {
            $pipe = strpos($raw, '|', $offset);
            if ($pipe === false) break;
            $key = substr($raw, $offset, $pipe - $offset);
            $offset = $pipe + 1;
            try {
                $value = @unserialize(substr($raw, $offset), ['allowed_classes' => false]);
                if ($value === false && substr($raw, $offset, 2) !== 'b:') break;
                $offset += strlen(serialize($value));
                if (is_scalar($value) || is_array($value)) $result[$key] = $value;
            } catch (\Throwable $e) {
                break;
            }
        }
        return $result;
    }

    private function row(string $sql, array $params = []): array {
        return $this->statement($sql, $params)->fetch() ?: [];
    }

    private function rows(string $sql, array $params = []): array {
        return $this->statement($sql, $params)->fetchAll();
    }

    private function dateSeries(string $from, string $dateColumn, string $where, array $params): array {
        $total = $this->row("SELECT COUNT(*) AS total, COALESCE(SUM(DATE($dateColumn) = CURDATE()), 0) AS today FROM $from WHERE $where", $params);
        $hourly = array_fill(0, 24, 0);
        foreach ($this->rows("SELECT HOUR($dateColumn) AS item_key, COUNT(*) AS item_count FROM $from WHERE $where AND DATE($dateColumn) = CURDATE() GROUP BY HOUR($dateColumn)", $params) as $item) {
            $hour = (int)$item['item_key'];
            if ($hour >= 0 && $hour < 24) $hourly[$hour] = (int)$item['item_count'];
        }

        $weekDates = $this->dates(6, 0);
        $weekValues = array_fill(0, 7, 0);
        $weekIndex = array_flip($weekDates);
        foreach ($this->rows("SELECT DATE($dateColumn) AS item_key, COUNT(*) AS item_count FROM $from WHERE $where AND $dateColumn >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) GROUP BY DATE($dateColumn)", $params) as $item) {
            $key = (string)$item['item_key'];
            if (isset($weekIndex[$key])) $weekValues[$weekIndex[$key]] = (int)$item['item_count'];
        }

        $monthDates = $this->dates(29, 29);
        $monthValues = array_fill(0, 30, 0);
        $monthIndex = array_flip($monthDates);
        foreach ($this->rows("SELECT DATE($dateColumn) AS item_key, COUNT(*) AS item_count FROM $from WHERE $where AND $dateColumn >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE($dateColumn)", $params) as $item) {
            $key = (string)$item['item_key'];
            if (isset($monthIndex[$key])) $monthValues[$monthIndex[$key]] = (int)$item['item_count'];
        }

        return [
            'total' => (int)($total['total'] ?? 0),
            'today' => (int)($total['today'] ?? 0),
            'hourly' => $hourly,
            'hourly_labels' => array_map(fn($hour) => str_pad((string)$hour, 2, '0', STR_PAD_LEFT) . ':00', range(0, 23)),
            'weekly' => $weekValues,
            'weekly_labels' => array_map(fn($date) => date('D', strtotime($date)), $weekDates),
            'monthly' => $monthValues,
            'monthly_labels' => array_map(fn($date) => date('M d', strtotime($date)), $monthDates),
        ];
    }

    private function dates(int $daysBack, int $count): array {
        $start = $count === 0
            ? (new \DateTimeImmutable('monday this week'))
            : (new \DateTimeImmutable('today'))->modify("-$daysBack days");
        $total = $count === 0 ? 7 : $count + 1;
        $dates = [];
        for ($i = 0; $i < $total; $i++) $dates[] = $start->modify("+$i days")->format('Y-m-d');
        return $dates;
    }

    private function scope(string $alias, array $meta, bool $overview, string $prefix): array {
        $link = $meta['link_id'];
        if (!$meta['is_triplehook']) {
            $sql = "$alias.link_id = :{$prefix}_link";
            if ($overview) {
                $sql .= " AND EXISTS (SELECT 1 FROM regular {$prefix}_r WHERE {$prefix}_r.link_id = $alias.link_id AND {$prefix}_r.discord_username IS NOT NULL)";
            }
            return ['sql' => $sql, 'params' => [":{$prefix}_link" => $link]];
        }

        if (!$overview) {
            return [
                'sql' => "$alias.link_id IN (SELECT {$prefix}_r.link_id FROM regular {$prefix}_r WHERE {$prefix}_r.referred_by = :{$prefix}_link)",
                'params' => [":{$prefix}_link" => $link],
            ];
        }

        return [
            'sql' => "$alias.link_id IN (SELECT {$prefix}_r1.link_id FROM regular {$prefix}_r1 WHERE {$prefix}_r1.referred_by = :{$prefix}_link UNION SELECT {$prefix}_r2.link_id FROM regular {$prefix}_r2 WHERE {$prefix}_r2.referred_by IN (SELECT {$prefix}_td.link_id FROM triplehook_data {$prefix}_td INNER JOIN regular {$prefix}_r3 ON {$prefix}_r3.link_id = {$prefix}_td.link_id WHERE {$prefix}_r3.referred_by = :{$prefix}_link2))",
            'params' => [":{$prefix}_link" => $link, ":{$prefix}_link2" => $link],
        ];
    }

    private function financial(array $meta, bool $overview = false): array {
        $outer = $this->scope('h', $meta, $overview, 'outer');
        $inner = $this->scope('h2', $meta, $overview, 'inner');
        $params = array_merge($outer['params'], $inner['params']);
        $dedupe = "h.id IN (SELECT MIN(h2.id) FROM hits h2 WHERE {$inner['sql']} GROUP BY h2.username, DATE(h2.created_at))";
        $totals = $this->row("SELECT COALESCE(SUM(h.summary),0) AS summary, COALESCE(SUM(h.rap),0) AS rap, COALESCE(SUM(h.robux),0) AS balance FROM hits h WHERE {$outer['sql']} AND $dedupe", $params);

        $todayDedupe = "h.id IN (SELECT MIN(h2.id) FROM hits h2 WHERE {$inner['sql']} AND DATE(h2.created_at) = CURDATE() GROUP BY h2.username, DATE(h2.created_at))";
        $today = $this->row("SELECT COALESCE(SUM(h.summary),0) AS summary, COALESCE(SUM(h.rap),0) AS rap, COALESCE(SUM(h.robux),0) AS balance FROM hits h WHERE {$outer['sql']} AND DATE(h.created_at) = CURDATE() AND $todayDedupe", $params);

        $weekly = ['summary' => array_fill(0, 7, 0), 'rap' => array_fill(0, 7, 0), 'balance' => array_fill(0, 7, 0)];
        $weekDates = $this->dates(6, 0);
        $weekIndex = array_flip($weekDates);
        $weeklyDedupe = "h.id IN (SELECT MIN(h2.id) FROM hits h2 WHERE {$inner['sql']} AND h2.created_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) GROUP BY h2.username, DATE(h2.created_at))";
        $weeklyRows = $this->rows("SELECT DATE(h.created_at) AS item_key, COALESCE(SUM(h.summary),0) AS summary, COALESCE(SUM(h.rap),0) AS rap, COALESCE(SUM(h.robux),0) AS balance FROM hits h WHERE {$outer['sql']} AND h.created_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AND $weeklyDedupe GROUP BY DATE(h.created_at)", $params);
        foreach ($weeklyRows as $item) {
            $key = (string)$item['item_key'];
            if (!isset($weekIndex[$key])) continue;
            $index = $weekIndex[$key];
            $weekly['summary'][$index] = (int)$item['summary'];
            $weekly['rap'][$index] = (int)$item['rap'];
            $weekly['balance'][$index] = (int)$item['balance'];
        }
        return [
            'totals' => ['summary' => (int)($totals['summary'] ?? 0), 'rap' => (int)($totals['rap'] ?? 0), 'balance' => (int)($totals['balance'] ?? 0)],
            'today' => ['summary' => (int)($today['summary'] ?? 0), 'rap' => (int)($today['rap'] ?? 0), 'balance' => (int)($today['balance'] ?? 0)],
            'weekly' => $weekly,
            'labels' => array_map(fn($date) => date('D', strtotime($date)), $weekDates),
        ];
    }

    private function clicksPayload(array $meta): array {
        $link = [':link' => $meta['link_id']];
        if ($meta['is_triplehook']) {
            return $this->dateSeries('triplehook_data td INNER JOIN regular r ON r.link_id = td.link_id', 'r.created_at', 'r.referred_by = :link', $link);
        }
        return $this->dateSeries('login_clicks c', 'c.created_at', 'c.link_id = :link', $link);
    }

    private function visitsPayload(array $meta): array {
        $link = [':link' => $meta['link_id']];
        if ($meta['is_triplehook']) return $this->dateSeries('regular r', 'r.created_at', 'r.referred_by = :link', $link);
        return $this->dateSeries('views v', 'v.created_at', 'v.link_id = :link', $link);
    }

    private function accountsPayload(array $meta): array {
        if (!$meta['is_triplehook']) return $this->dateSeries('hits h', 'h.created_at', 'h.link_id = :link', [':link' => $meta['link_id']]);
        $accountWhere = "h.link_id IN (SELECT r1.link_id FROM regular r1 WHERE r1.referred_by = :link UNION SELECT r2.link_id FROM regular r2 WHERE r2.referred_by IN (SELECT td2.link_id FROM triplehook_data td2 INNER JOIN regular r3 ON r3.link_id = td2.link_id WHERE r3.referred_by = :link2))";
        return $this->dateSeries('hits h', 'h.created_at', $accountWhere, [':link' => $meta['link_id'], ':link2' => $meta['link_id']]);
    }

    private function visitScopeIds(array $meta): array {
        if (!$meta['is_triplehook']) return [(string)$meta['link_id']];
        $cacheKey = (string)$meta['link_id'];
        if (isset($this->visitScopeCache[$cacheKey])) return $this->visitScopeCache[$cacheKey];
        $rows = $this->rows("
            SELECT link_id FROM regular WHERE referred_by = :visit_scope_link
            UNION
            SELECT r2.link_id FROM regular r2
            INNER JOIN triplehook_data td ON td.link_id = r2.referred_by
            INNER JOIN regular r ON r.link_id = td.link_id AND r.referred_by = :visit_scope_link2
        ", [':visit_scope_link' => $meta['link_id'], ':visit_scope_link2' => $meta['link_id']]);
        $ids = [];
        foreach ($rows as $row) {
            if (isset($row['link_id']) && $row['link_id'] !== '') $ids[] = (string)$row['link_id'];
        }
        return $this->visitScopeCache[$cacheKey] = array_values(array_unique($ids));
    }

    private function visitFeedPayload(array $meta): array {
        $ids = $this->visitScopeIds($meta);
        $page = max(1, (int)($meta['visits_page'] ?? 1));
        $perPage = 15;
        if ($ids === []) return ['page' => $page, 'per_page' => $perPage, 'views_total' => 0, 'clicks_total' => 0, 'total_pages' => 0, 'rows' => []];

        $viewMarks = [];
        $clickMarks = [];
        $viewParams = [];
        $clickParams = [];
        foreach ($ids as $index => $linkId) {
            $viewKey = ':visit_view_' . $index;
            $clickKey = ':visit_click_' . $index;
            $viewMarks[] = $viewKey;
            $clickMarks[] = $clickKey;
            $viewParams[$viewKey] = $linkId;
            $clickParams[$clickKey] = $linkId;
        }
        $counts = $this->row(
            'SELECT (SELECT COUNT(*) FROM views WHERE link_id IN (' . implode(',', $viewMarks) . ')) AS views_total, (SELECT COUNT(*) FROM login_clicks WHERE link_id IN (' . implode(',', $clickMarks) . ')) AS clicks_total',
            array_merge($viewParams, $clickParams)
        );
        $viewsTotal = (int)($counts['views_total'] ?? 0);
        $clicksTotal = (int)($counts['clicks_total'] ?? 0);
        $totalRecords = $viewsTotal + $clicksTotal;
        $offset = ($page - 1) * $perPage;
        $innerLimit = max($perPage, $offset + $perPage);

        $rowViewMarks = [];
        $rowClickMarks = [];
        $rowViewParams = [];
        $rowClickParams = [];
        foreach ($ids as $index => $linkId) {
            $viewKey = ':visit_row_view_' . $index;
            $clickKey = ':visit_row_click_' . $index;
            $rowViewMarks[] = $viewKey;
            $rowClickMarks[] = $clickKey;
            $rowViewParams[$viewKey] = $linkId;
            $rowClickParams[$clickKey] = $linkId;
        }
        $sql = '
            (SELECT ip_address, country, city, COALESCE(type, \'link\') AS type, current_url, created_at FROM views WHERE link_id IN (' . implode(',', $rowViewMarks) . ') ORDER BY created_at DESC LIMIT ' . $innerLimit . ')
            UNION ALL
            (SELECT ip_address, country, city, CASE WHEN type LIKE \'%DOWNLOADING%\' THEN type WHEN type LIKE \'Extension%\' THEN type WHEN type = \'AutoHar\' THEN \'AUTOHAR\' ELSE \'login\' END AS type, current_url, created_at FROM login_clicks WHERE link_id IN (' . implode(',', $rowClickMarks) . ') ORDER BY created_at DESC LIMIT ' . $innerLimit . ')
            ORDER BY created_at DESC
            LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $rows = $this->rows($sql, array_merge($rowViewParams, $rowClickParams));
        return [
            'page' => $page,
            'per_page' => $perPage,
            'views_total' => $viewsTotal,
            'clicks_total' => $clicksTotal,
            'total_pages' => (int)ceil($totalRecords / $perPage),
            'rows' => $rows,
        ];
    }
    private function userStatsRank(int $metric, bool $isTriplehook): array {
        $ranks = $isTriplehook ? [
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
        ] : [
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
        foreach ($ranks as $index => $rank) {
            if ($metric < $rank['min'] || $metric >= $rank['max']) continue;
            $isMax = !isset($ranks[$index + 1]);
            $percent = $isMax ? 100 : min(100, (int)round((($metric - $rank['min']) / ($rank['max'] - $rank['min'])) * 100));
            return ['current' => $rank['name'], 'next' => $isMax ? 'MAX' : $ranks[$index + 1]['name'], 'percent' => $percent, 'is_max' => $isMax];
        }
        return ['current' => 'Unranked', 'next' => $ranks[1]['name'], 'percent' => 0, 'is_max' => false];
    }

    private function userStatsPayload(array $meta, ?array $parts = null): array {
        $wanted = array_fill_keys($parts ?? ['hits', 'views', 'clicks', 'rank'], true);
        $isTriplehook = (bool)$meta['is_triplehook'];
        $link = $meta['link_id'];
        $where = $isTriplehook
            ? 'link_id IN (SELECT r.link_id FROM regular r WHERE r.referred_by = :user_stats_link)'
            : 'link_id = :user_stats_link';
        $params = [':user_stats_link' => $link];
        $week = 'created_at >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY)';
        $payload = ['is_triplehook' => $isTriplehook];

        if (isset($wanted['hits'])) $payload['weekly_hits'] = (int)($this->row("SELECT COUNT(*) AS total FROM hits WHERE $where AND $week", $params)['total'] ?? 0);
        if (isset($wanted['views'])) $payload['weekly_visits'] = (int)($this->row("SELECT COUNT(*) AS total FROM views WHERE $where AND $week", $params)['total'] ?? 0);
        if (isset($wanted['clicks'])) $payload['weekly_clicks'] = (int)($this->row("SELECT COUNT(*) AS total FROM login_clicks WHERE $where AND $week", $params)['total'] ?? 0);
        if (isset($wanted['rank'])) {
            if ($isTriplehook) {
                $metric = (int)($this->row('SELECT COUNT(*) AS total FROM regular WHERE referred_by = :user_stats_link', $params)['total'] ?? 0);
                $payload['referred_count'] = $metric;
            } else {
                $metric = (int)($this->row("SELECT COUNT(*) AS total FROM hits WHERE $where", $params)['total'] ?? 0);
            }
            $payload['rank'] = $this->userStatsRank($metric, $isTriplehook);
        }
        return $payload;
    }
    private function payload(array $meta): array {
        if (!empty($meta['wants_visit_feed'])) return ['visit_feed' => $this->visitFeedPayload($meta)];
        return [
            'clicks' => $this->clicksPayload($meta),
            'visits' => $this->visitsPayload($meta),
            'accounts' => $this->accountsPayload($meta),
            'summary' => $this->financial($meta, false),
            'overview' => $this->financial($meta, true),
            'user_stats' => $this->userStatsPayload($meta),
        ];
    }

    private function livePayload(array $meta, array $changes): array {
        if (!empty($meta['wants_visit_feed'])) {
            if (isset($changes['regular']) || isset($changes['triplehook']) || isset($changes['views']) || isset($changes['clicks'])) {
                return ['visit_feed' => $this->visitFeedPayload($meta)];
            }
            return [];
        }
        // Relationship changes can affect multiple Triplehook scopes, so preserve a full refresh there.
        if (isset($changes['regular']) || isset($changes['triplehook'])) return $this->payload($meta);

        $payload = [];
        if (isset($changes['clicks']) && !$meta['is_triplehook']) $payload['clicks'] = $this->clicksPayload($meta);
        if (isset($changes['views']) && !$meta['is_triplehook']) $payload['visits'] = $this->visitsPayload($meta);
        if (isset($changes['hits'])) {
            $payload['accounts'] = $this->accountsPayload($meta);
            $payload['summary'] = $this->financial($meta, false);
            $payload['overview'] = $this->financial($meta, true);
        }
        $userStatsParts = [];
        if (isset($changes['hits'])) {
            $userStatsParts[] = 'hits';
            if (!$meta['is_triplehook']) $userStatsParts[] = 'rank';
        }
        if (isset($changes['views'])) $userStatsParts[] = 'views';
        if (isset($changes['clicks'])) $userStatsParts[] = 'clicks';
        if ($userStatsParts !== []) $payload['user_stats'] = $this->userStatsPayload($meta, array_values(array_unique($userStatsParts)));
        return $payload;
    }

    private function componentSignatures(array $payload): array {
        $signatures = [];
        foreach ($payload as $component => $value) {
            $signatures[$component] = hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return $signatures;
    }

    private function sendSnapshot(ConnectionInterface $conn): void {
        if (!$this->clients->contains($conn)) return;
        try {
            $meta = $this->clients[$conn];
            $payload = $this->payload($meta);
            $meta['component_signatures'] = $this->componentSignatures($payload);
            $this->clients[$conn] = $meta;
            $this->emit($conn, ['type' => 'dashboard_stats', 'payload' => $payload]);
        } catch (\Throwable $e) {
            error_log('[dashboard-ws] snapshot failed #' . $conn->resourceId . ': ' . $e->getMessage());
            $this->emit($conn, ['type' => 'fatal', 'error' => 'data_unavailable']);
        }
    }

    private function eventVersion(string $table): string {
        // Some legacy event tables may not have an `id` column. Detect once,
        // then use their timestamp as a safe fallback instead of stopping all live updates.
        if (!array_key_exists($table, $this->eventTableHasId)) {
            try {
                $this->row("SELECT id FROM $table LIMIT 1");
                $this->eventTableHasId[$table] = true;
            } catch (\Throwable $e) {
                $this->eventTableHasId[$table] = false;
            }
        }
        if ($this->eventTableHasId[$table]) {
            return (string)($this->row("SELECT COALESCE(MAX(id), 0) AS value FROM $table")['value'] ?? 0);
        }
        return (string)($this->row("SELECT COALESCE(MAX(UNIX_TIMESTAMP(created_at)), 0) AS value FROM $table")['value'] ?? 0);
    }

    private function version(): ?array {
        try {
            // `id` is append-only and indexed, unlike COUNT(*) on the large event tables.
            // These are change tokens only; dashboard data is still filtered by link_id.
            $smallTables = $this->row('SELECT (SELECT COUNT(*) FROM regular) AS regular_count, (SELECT COUNT(*) FROM triplehook_data) AS triplehook_count');
            return [
                'hits' => $this->eventVersion('hits'),
                'views' => $this->eventVersion('views'),
                'clicks' => $this->eventVersion('login_clicks'),
                'regular' => (string)($smallTables['regular_count'] ?? 0),
                'triplehook' => (string)($smallTables['triplehook_count'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function broadcastChanges(array $changes): void {
        $cache = [];
        $changeKey = implode(',', array_keys($changes));
        foreach ($this->clients as $client) {
            try {
                $meta = $this->clients[$client];
                $variant = !empty($meta['wants_visit_feed']) ? 'visits:' . (int)($meta['visits_page'] ?? 1) : 'dashboard';
                $key = $meta['link_id'] . ':' . (int)$meta['is_triplehook'] . ':' . $variant . ':' . $changeKey;
                if (!isset($cache[$key])) {
                    $payload = $this->livePayload($meta, $changes);
                    $cache[$key] = ['payload' => $payload, 'signatures' => $this->componentSignatures($payload)];
                }
                $out = [];
                foreach ($cache[$key]['payload'] as $component => $value) {
                    $signature = $cache[$key]['signatures'][$component];
                    if (($meta['component_signatures'][$component] ?? '') !== $signature) {
                        $out[$component] = $value;
                        $meta['component_signatures'][$component] = $signature;
                    }
                }
                if ($out !== []) {
                    $this->emit($client, ['type' => 'dashboard_stats', 'payload' => $out]);
                    $this->clients[$client] = $meta;
                }
            } catch (\Throwable $e) {
                // Keep the socket alive; the following data change will retry this client.
            }
        }
    }

    public function tick() {
        if (count($this->clients) === 0) return;
        $version = $this->version();
        if ($version !== null) {
            if ($this->lastVersion === null) $this->lastVersion = $version;
            else {
                $changes = [];
                foreach ($version as $component => $value) {
                    if (($this->lastVersion[$component] ?? null) !== $value) $changes[$component] = true;
                }
                $this->lastVersion = $version;
                if (isset($changes['regular']) || isset($changes['triplehook'])) $this->visitScopeCache = [];
                if ($changes !== []) $this->broadcastChanges($changes);
            }
        }
        $this->tickCounter++;
        if (($this->tickCounter % 10) === 0) {
            foreach ($this->clients as $client) {
                try { $this->emit($client, ['type' => 'ping', 'ts' => time()]); } catch (\Throwable $e) {}
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        error_log('[dashboard-ws] client closed #' . $conn->resourceId);
        if ($this->clients->contains($conn)) $this->clients->detach($conn);
    }
    public function onError(ConnectionInterface $conn, \Exception $e) {
        error_log('[dashboard-ws] connection error #' . $conn->resourceId . ': ' . $e->getMessage());
        $conn->close();
    }
    public function onMessage(ConnectionInterface $from, $msg) {}
}

/**
 * SidebarCountsHub — sidebar formülüyle (hidden filtresi dahil) hits ve visits
 * toplamlarını anlık akıtır. Her sayfada aynı formül kullanılsın diye tek nokta.
 *
 *   HITS   (normal): SELECT COUNT(*) FROM hits WHERE link_id = :lid
 *                    AND (IFNULL(hidden,0)=0 OR hidden_link_id = :viewer)
 *   HITS   (triple): scope = referred_by + triplehook_data ikinci seviyeden birleşim
 *   VISITS (her ikisi): views + login_clicks (aynı scope)
 *
 * Sunucu tick her N saniyede bir hesaplar; sayı değişmediyse push yok.
 */
class SidebarCountsHub implements MessageComponentInterface {
    private \PDO $pdo;
    private \SplObjectStorage $clients;
    private int $tickCounter = 0;
    private int $lastHitsMax = -1;
    private int $lastViewsMax = -1;
    private int $lastClicksMax = -1;
    private int $lastRegularMax = -1;
    private string $lastAuthFailure = 'not_checked';

    public function __construct() {
        $this->clients = new \SplObjectStorage();
        $this->pdo = new \PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function emit(ConnectionInterface $conn, array $data): void {
        try { $conn->send(ws_encrypt($data)); } catch (\Throwable $e) {}
    }

    public function onOpen(ConnectionInterface $conn) {
        $meta = $this->authenticate($conn);
        if ($meta === null) {
            $this->emit($conn, ['type' => 'fatal', 'error' => 'unauthorized']);
            $conn->close();
            return;
        }
        $meta['last_hits'] = -1;
        $meta['last_visits'] = -1;
        $this->clients->attach($conn, $meta);
        $this->sendSnapshot($conn);
    }

    private function authenticate(ConnectionInterface $conn): ?array {
        $req = $conn->httpRequest;
        if (!$req) return null;
        $cookieHeader = '';
        foreach ($req->getHeader('Cookie') as $h) { $cookieHeader .= $h . ';'; }
        if ($cookieHeader === '') return null;
        $sessionId = null;
        foreach (explode(';', $cookieHeader) as $p) {
            $p = trim($p);
            if (str_starts_with($p, SESSION_COOKIE_NAME . '=')) {
                $sessionId = rawurldecode(substr($p, strlen(SESSION_COOKIE_NAME) + 1));
                break;
            }
        }
        if (!$sessionId || !preg_match('/^[a-zA-Z0-9,\-]+$/', $sessionId)) return null;
        $sessionFile = null;
        foreach ([SESSION_PATH, '/var/www/vhosts/app.ultima.cl/session', '/var/www/vhosts/app.beamse.pro/session'] as $dir) {
            $file = $dir . '/sess_' . $sessionId;
            if (is_file($file) && is_readable($file)) { $sessionFile = $file; break; }
        }
        if (!$sessionFile) return null;
        $raw = @file_get_contents($sessionFile);
        if (!is_string($raw) || $raw === '') return null;
        $data = $this->parseSession($raw);
        $linkId = $data['link_id'] ?? null;
        if (!$linkId) return null;
        try {
            $row = $this->pdo->prepare('SELECT triplehook FROM regular WHERE link_id = :lid LIMIT 1');
            $row->execute([':lid' => $linkId]);
            $r = $row->fetch();
            return [
                'link_id' => (string)$linkId,
                'is_triplehook' => $r && (int)$r['triplehook'] === 1,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function parseSession(string $raw): array {
        $result = [];
        $offset = 0;
        $len = strlen($raw);
        while ($offset < $len) {
            $pipe = strpos($raw, '|', $offset);
            if ($pipe === false) break;
            $key = substr($raw, $offset, $pipe - $offset);
            $offset = $pipe + 1;
            try {
                $val = @unserialize(substr($raw, $offset), ['allowed_classes' => false]);
                if ($val === false && substr($raw, $offset, 2) !== 'b:') break;
                $offset += strlen(serialize($val));
                if (is_scalar($val) || is_array($val)) $result[$key] = $val;
            } catch (\Throwable $e) { break; }
        }
        return $result;
    }

    private function computeCounts(array $meta): array {
        try {
            if ($meta['is_triplehook']) {
                $scopeSql = "link_id IN (
                    SELECT link_id FROM regular WHERE referred_by = :lid
                    UNION
                    SELECT r2.link_id FROM regular r2 WHERE r2.referred_by IN (
                        SELECT td.link_id FROM triplehook_data td
                        INNER JOIN regular r ON r.link_id = td.link_id
                        WHERE r.referred_by = :lid2
                    )
                )";
                $params = [':lid' => $meta['link_id'], ':lid2' => $meta['link_id']];
                $hits = (int)$this->one("SELECT COUNT(*) AS c FROM hits WHERE $scopeSql", $params);
                $views = (int)$this->one("SELECT COUNT(*) AS c FROM views WHERE $scopeSql", $params);
                $clicks = (int)$this->one("SELECT COUNT(*) AS c FROM login_clicks WHERE $scopeSql", $params);
            } else {
                $hits = (int)$this->one(
                    "SELECT COUNT(*) AS c FROM hits WHERE link_id = :lid AND (IFNULL(hidden,0)=0 OR hidden_link_id = :viewer)",
                    [':lid' => $meta['link_id'], ':viewer' => $meta['link_id']]
                );
                $views = (int)$this->one("SELECT COUNT(*) AS c FROM views WHERE link_id = :lid", [':lid' => $meta['link_id']]);
                $clicks = (int)$this->one("SELECT COUNT(*) AS c FROM login_clicks WHERE link_id = :lid", [':lid' => $meta['link_id']]);
            }
            return ['hits' => $hits, 'visits' => $views + $clicks, 'views' => $views, 'clicks' => $clicks];
        } catch (\Throwable $e) {
            return ['hits' => 0, 'visits' => 0, 'views' => 0, 'clicks' => 0];
        }
    }

    private function one(string $sql, array $params) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row['c'] ?? 0;
    }

    private function sendSnapshot(ConnectionInterface $conn): void {
        if (!$this->clients->contains($conn)) return;
        $meta = $this->clients[$conn];
        $counts = $this->computeCounts($meta);
        $meta['last_hits'] = $counts['hits'];
        $meta['last_visits'] = $counts['visits'];
        $this->clients[$conn] = $meta;
        $this->emit($conn, ['type' => 'sidebar_counts', 'hits' => $counts['hits'], 'visits' => $counts['visits'], 'views' => $counts['views'], 'clicks' => $counts['clicks']]);
    }

    public function tick(): void {
        if (count($this->clients) === 0) return;
        $this->tickCounter++;
        try {
            $marker = $this->pdo->query("SELECT
                COALESCE((SELECT MAX(id) FROM hits),0) AS h,
                COALESCE((SELECT MAX(id) FROM views),0) AS v,
                COALESCE((SELECT MAX(id) FROM login_clicks),0) AS c,
                COALESCE((SELECT MAX(link_id) FROM regular),0) AS r
            ")->fetch();
        } catch (\Throwable $e) { return; }
        $h = (int)($marker['h'] ?? 0);
        $v = (int)($marker['v'] ?? 0);
        $c = (int)($marker['c'] ?? 0);
        $r = (int)($marker['r'] ?? 0);
        if ($this->lastHitsMax === -1) {
            $this->lastHitsMax = $h; $this->lastViewsMax = $v; $this->lastClicksMax = $c; $this->lastRegularMax = $r;
            return;
        }
        $changed = ($h !== $this->lastHitsMax) || ($v !== $this->lastViewsMax) || ($c !== $this->lastClicksMax) || ($r !== $this->lastRegularMax);
        $this->lastHitsMax = $h; $this->lastViewsMax = $v; $this->lastClicksMax = $c; $this->lastRegularMax = $r;
        if (!$changed) {
            if (($this->tickCounter % 10) === 0) {
                foreach ($this->clients as $client) {
                    try { $this->emit($client, ['type' => 'ping', 'ts' => time()]); } catch (\Throwable $e) {}
                }
            }
            return;
        }
        foreach ($this->clients as $client) {
            try {
                $meta = $this->clients[$client];
                $counts = $this->computeCounts($meta);
                if ($counts['hits'] !== $meta['last_hits'] || $counts['visits'] !== $meta['last_visits']) {
                    $meta['last_hits'] = $counts['hits'];
                    $meta['last_visits'] = $counts['visits'];
                    $this->clients[$client] = $meta;
                    $this->emit($client, ['type' => 'sidebar_counts', 'hits' => $counts['hits'], 'visits' => $counts['visits'], 'views' => $counts['views'], 'clicks' => $counts['clicks']]);
                }
            } catch (\Throwable $e) {}
        }
    }

    public function onClose(ConnectionInterface $conn) {
        if ($this->clients->contains($conn)) $this->clients->detach($conn);
    }
    public function onError(ConnectionInterface $conn, \Exception $e) {
        $conn->close();
    }
    public function onMessage(ConnectionInterface $from, $msg) {}
}

/**
 * Kanal etiketleyici bağlantı proxy'si.
 *
 * UnifiedHub bir bağlantıyı birden fazla arka hub'a kaydettiğinde her hub
 * kendi payload'unu ws_encrypt() ile şifreleyip $conn->send() çağırıyor.
 * Bu proxy, o çağrıyı yakalar ve dış tarafa {"c":"<channel>","d":"<enc>"}
 * biçiminde etiketli metin olarak yollar. Kanal adı düz metin — payload
 * gene aynı AES anahtarıyla kapalı; sadece istemci hangi hub'a ait olduğunu
 * bilerek doğru handler'a dispatch edebiliyor.
 */
class ChanneledConnection implements ConnectionInterface {
    public ConnectionInterface $inner;
    public string $channel;
    public $httpRequest;
    public $remoteAddress;
    public $resourceId;
    public $WebSocket;

    public function __construct(ConnectionInterface $inner, string $channel) {
        $this->inner = $inner;
        $this->channel = $channel;
        $this->httpRequest = $inner->httpRequest ?? null;
        $this->remoteAddress = $inner->remoteAddress ?? null;
        $this->resourceId = $inner->resourceId ?? null;
        $this->WebSocket = $inner->WebSocket ?? null;
    }

    public function send($data) {
        try {
            $frame = json_encode(['c' => $this->channel, 'd' => (string)$data], JSON_UNESCAPED_UNICODE);
            $this->inner->send($frame);
        } catch (\Throwable $e) {}
        return $this;
    }

    public function close() {
        try { $this->inner->close(); } catch (\Throwable $e) {}
    }
}

/**
 * UnifiedHub: tek WebSocket bağlantısı ile /ws, /ws2, /ws3, /ws4 hub'larının
 * hepsine abone olma. İstemci ?channels=live,storage,leaderboard,dashboard
 * gibi parametreyle hangi kanalları istediğini belirtir. Aynı bağlantı
 * her hub için ChanneledConnection proxy'si ile kaydedilir; hub'lar
 * mevcut mantıklarını değiştirmeden yayın yapmaya devam eder.
 */
class UnifiedHub implements MessageComponentInterface {
    private \SplObjectStorage $sessions;
    private MainLiveHub $mainHub;
    private StorageHub $storageHub;
    private LeaderboardLiveHub $leaderboardHub;
    private DashboardLiveHub $dashboardHub;
    private SidebarCountsHub $sidebarHub;

    public function __construct(
        MainLiveHub $mainHub,
        StorageHub $storageHub,
        LeaderboardLiveHub $leaderboardHub,
        DashboardLiveHub $dashboardHub,
        SidebarCountsHub $sidebarHub
    ) {
        $this->sessions = new \SplObjectStorage();
        $this->mainHub = $mainHub;
        $this->storageHub = $storageHub;
        $this->leaderboardHub = $leaderboardHub;
        $this->dashboardHub = $dashboardHub;
        $this->sidebarHub = $sidebarHub;
    }

    private function parseChannels(ConnectionInterface $conn): array {
        try {
            $query = [];
            parse_str((string)$conn->httpRequest->getUri()->getQuery(), $query);
            $raw = $query['channels'] ?? $query['ch'] ?? '';
        } catch (\Throwable $e) {
            $raw = '';
        }
        // Boş bırakılırsa hepsini aç (sidebar dahil)
        if ($raw === '' || $raw === null) {
            return ['live', 'storage', 'leaderboard', 'dashboard', 'sidebar'];
        }
        $wanted = [];
        foreach (explode(',', (string)$raw) as $part) {
            $part = strtolower(trim($part));
            if ($part === '') continue;
            // 'visits' alias'ını dashboard_visits'e maple
            if ($part === 'dashboard_visits' || $part === 'visits') $part = 'dashboard_visits';
            if (in_array($part, ['live','storage','leaderboard','dashboard','dashboard_visits','sidebar'], true)) {
                $wanted[$part] = true;
            }
        }
        return array_keys($wanted) ?: ['sidebar'];
    }

    public function onOpen(ConnectionInterface $conn) {
        $wanted = $this->parseChannels($conn);
        $proxies = [];
        try {
            if (in_array('live', $wanted, true)) {
                $p = new ChanneledConnection($conn, 'live');
                $this->mainHub->onOpen($p);
                $proxies['live'] = $p;
            }
            if (in_array('storage', $wanted, true)) {
                $p = new ChanneledConnection($conn, 'storage');
                $this->storageHub->onOpen($p);
                $proxies['storage'] = $p;
            }
            if (in_array('leaderboard', $wanted, true)) {
                $p = new ChanneledConnection($conn, 'leaderboard');
                $this->leaderboardHub->onOpen($p);
                $proxies['leaderboard'] = $p;
            }
            if (in_array('dashboard', $wanted, true) || in_array('dashboard_visits', $wanted, true)) {
                $p = new ChanneledConnection($conn, in_array('dashboard_visits', $wanted, true) ? 'dashboard_visits' : 'dashboard');
                $this->dashboardHub->onOpen($p);
                $proxies[$p->channel] = $p;
            }
            if (in_array('sidebar', $wanted, true)) {
                $p = new ChanneledConnection($conn, 'sidebar');
                $this->sidebarHub->onOpen($p);
                $proxies['sidebar'] = $p;
            }
        } catch (\Throwable $e) {
            ws_log('unified onOpen error #' . $conn->resourceId . ': ' . $e->getMessage());
        }
        $this->sessions->attach($conn, ['proxies' => $proxies, 'channels' => $wanted]);
        try {
            $conn->send(json_encode(['c' => '_sys', 'd' => json_encode(['type' => 'ready', 'channels' => $wanted])], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {}
        ws_log('unified open #' . $conn->resourceId . ' channels=' . implode(',', $wanted));
    }

    public function onClose(ConnectionInterface $conn) {
        if (!$this->sessions->contains($conn)) return;
        $meta = $this->sessions[$conn];
        foreach ($meta['proxies'] as $ch => $proxy) {
            try {
                if ($ch === 'live') $this->mainHub->onClose($proxy);
                elseif ($ch === 'storage') $this->storageHub->onClose($proxy);
                elseif ($ch === 'leaderboard') $this->leaderboardHub->onClose($proxy);
                elseif ($ch === 'dashboard' || $ch === 'dashboard_visits') $this->dashboardHub->onClose($proxy);
                elseif ($ch === 'sidebar') $this->sidebarHub->onClose($proxy);
            } catch (\Throwable $e) {
                ws_log('unified onClose error ch=' . $ch . ': ' . $e->getMessage());
            }
        }
        $this->sessions->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        ws_log('unified error #' . $conn->resourceId . ': ' . $e->getMessage());
        $conn->close();
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        // İstemciler şu an mesaj göndermiyor; ileride komut için burası kullanılabilir.
    }
}

class Ws4Router implements MessageComponentInterface {
    private \SplObjectStorage $routes;
    private array $hubs;

    public function __construct(array $hubs) {
        $this->routes = new \SplObjectStorage();
        $this->hubs = $hubs;
    }

    private function routeFor(ConnectionInterface $conn): string {
        try {
            $path = (string)$conn->httpRequest->getUri()->getPath();
            $path = rtrim($path, '/');
            return $path === '' ? '/' : $path;
        } catch (\Throwable $e) {
            return '/';
        }
    }

    public function onOpen(ConnectionInterface $conn) {
        $route = $this->routeFor($conn);
        if (!isset($this->hubs[$route])) {
            ws_log('rejected #' . $conn->resourceId . ' unknown_route=' . $route);
            try { $conn->send(ws_encrypt(['type' => 'fatal', 'error' => 'unknown_route'])); } catch (\Throwable $e) {}
            $conn->close();
            return;
        }

        $hub = $this->hubs[$route];
        $this->routes->attach($conn, ['hub' => $hub, 'route' => $route]);
        ws_log('open #' . $conn->resourceId . ' route=' . $route);
        try {
            $hub->onOpen($conn);
        } catch (\Throwable $e) {
            ws_log('open error #' . $conn->resourceId . ' route=' . $route . ': ' . $e->getMessage());
            $conn->close();
        }
    }

    public function onClose(ConnectionInterface $conn) {
        if (!$this->routes->contains($conn)) {
            ws_log('close #' . $conn->resourceId . ' route=unknown');
            return;
        }
        $meta = $this->routes[$conn];
        try { $meta['hub']->onClose($conn); } catch (\Throwable $e) {
            ws_log('close handler error #' . $conn->resourceId . ' route=' . $meta['route'] . ': ' . $e->getMessage());
        }
        $this->routes->detach($conn);
        ws_log('close #' . $conn->resourceId . ' route=' . $meta['route']);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $route = 'unknown';
        if ($this->routes->contains($conn)) {
            $meta = $this->routes[$conn];
            $route = $meta['route'];
            try { $meta['hub']->onError($conn, $e); } catch (\Throwable $ignored) { $conn->close(); }
        } else {
            $conn->close();
        }
        ws_log('error #' . $conn->resourceId . ' route=' . $route . ': ' . $e->getMessage());
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        if (!$this->routes->contains($from)) return;
        $meta = $this->routes[$from];
        try { $meta['hub']->onMessage($from, $msg); } catch (\Throwable $e) {
            ws_log('message error #' . $from->resourceId . ' route=' . $meta['route'] . ': ' . $e->getMessage());
        }
    }

    public function clientCount(): int { return count($this->routes); }
}

set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    ws_log("PHP error [$severity]: $message in $file:$line");
    return false;
});
set_exception_handler(function(\Throwable $e) {
    ws_log('FATAL: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
});

$loop = Loop::get();
$mainHub = new MainLiveHub();
$storageHub = new StorageHub();
$leaderboardHub = new LeaderboardLiveHub();
$dashboardHub = new DashboardLiveHub();
$sidebarHub = new SidebarCountsHub();
$unifiedHub = new UnifiedHub($mainHub, $storageHub, $leaderboardHub, $dashboardHub, $sidebarHub);
$router = new Ws4Router([
    '/ws' => $mainHub,
    '/ws2' => $storageHub,
    '/ws3' => $leaderboardHub,
    '/ws4' => $dashboardHub,
    '/wsx' => $unifiedHub,
]);

$loop->addPeriodicTimer(2.0, function() use ($mainHub) { $mainHub->tick(); });
$loop->addPeriodicTimer(2.0, function() use ($storageHub) { $storageHub->tick(); });
$loop->addPeriodicTimer(1.0, function() use ($leaderboardHub) { $leaderboardHub->tick(); });
$loop->addPeriodicTimer(2.0, function() use ($dashboardHub) { $dashboardHub->tick(); });
$loop->addPeriodicTimer(2.0, function() use ($sidebarHub) { $sidebarHub->tick(); });
$loop->addPeriodicTimer(300.0, function() use ($router) {
    ws_log('health clients=' . $router->clientCount() . ' memory=' . round(memory_get_usage(true) / 1024 / 1024, 1) . 'MB');
});

$plainSocket = new SocketServer(LISTEN_HOST . ':' . LISTEN_PORT, [], $loop);
$server = new IoServer(new HttpServer(new WsServer($router)), $plainSocket, $loop);

ws_log('single daemon started on ws://' . LISTEN_HOST . ':' . LISTEN_PORT . ' routes=/ws,/ws2,/ws3,/ws4,/wsx (channels: live,storage,leaderboard,dashboard,dashboard_visits,sidebar)');
echo "WS4 unified daemon listening on ws://" . LISTEN_HOST . ':' . LISTEN_PORT . " routes: /ws, /ws2, /ws3, /ws4, /wsx [AES encrypted]\n";
$loop->run();
