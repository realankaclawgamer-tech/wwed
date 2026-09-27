<?php
/**
 * Games Search API Proxy
 * Searches Roblox games and returns results with icons
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (!defined('GAMES_SEARCH_CACHE_FRESH_TTL')) {
    define('GAMES_SEARCH_CACHE_FRESH_TTL', 45);
}

if (!defined('GAMES_SEARCH_CACHE_STALE_TTL')) {
    define('GAMES_SEARCH_CACHE_STALE_TTL', 900);
}

if (!defined('GAMES_SEARCH_RATE_LIMIT_COOLDOWN')) {
    define('GAMES_SEARCH_RATE_LIMIT_COOLDOWN', 20);
}

if (!defined('GAMES_SEARCH_LOG_ID')) {
    try {
        define('GAMES_SEARCH_LOG_ID', substr(bin2hex(random_bytes(6)), 0, 12));
    } catch (Throwable $e) {
        define('GAMES_SEARCH_LOG_ID', str_replace('.', '', uniqid('', true)));
    }
}

header('X-Search-Request-Id: ' . GAMES_SEARCH_LOG_ID);

if (!defined('GAMES_SEARCH_VERBOSE_LOG')) {
    // false: only problems are written to the error log (failures, rate limits, crashes) — not every search
    define('GAMES_SEARCH_VERBOSE_LOG', false);
}

function searchLog($message, array $context = []) {
    if (!GAMES_SEARCH_VERBOSE_LOG && !preg_match('/fail|crash|missing|unreadable|invalid|rate limit|429|exhausted|error/i', $message)) {
        return;
    }
    $line = '[games-search] rid=' . GAMES_SEARCH_LOG_ID . ' | ' . $message;
    if (!empty($context)) {
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            $encoded = '{"context_encode_error":true}';
        }
        if (strlen($encoded) > 1500) {
            $encoded = substr($encoded, 0, 1500) . '...';
        }
        $line .= ' | ' . $encoded;
    }
    error_log($line);
}

function lowerKeyword($value) {
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function titleKeyword($value) {
    $lower = lowerKeyword($value);
    return function_exists('mb_convert_case')
        ? mb_convert_case($lower, MB_CASE_TITLE, 'UTF-8')
        : ucwords($lower);
}

function normalizeKeyword($value) {
    return preg_replace('/\s+/', ' ', trim((string) $value));
}

function buildKeywordVariants($keyword) {
    $variants = [];

    $pushVariant = function ($value) use (&$variants) {
        $normalizedValue = normalizeKeyword($value);
        if ($normalizedValue === '') {
            return;
        }

        $dedupeKey = $normalizedValue;
        if (!isset($variants[$dedupeKey])) {
            $variants[$dedupeKey] = $normalizedValue;
        }
    };

    $pushVariant($keyword);
    $pushVariant(lowerKeyword($keyword));
    $pushVariant(titleKeyword($keyword));

    return array_values($variants);
}

function gamesSearchGetProxyFilePath() {
    $documentRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') : dirname(__DIR__);
    return $documentRoot . DIRECTORY_SEPARATOR . 'libs' . DIRECTORY_SEPARATOR . 'proxy.txt';
}

function gamesSearchMapProxySchemeToCurlType($scheme) {
    $scheme = lowerKeyword((string) $scheme);

    if ($scheme === 'https' && defined('CURLPROXY_HTTPS')) {
        return CURLPROXY_HTTPS;
    }

    if ($scheme === 'socks5h' && defined('CURLPROXY_SOCKS5_HOSTNAME')) {
        return CURLPROXY_SOCKS5_HOSTNAME;
    }

    if ($scheme === 'socks5' && defined('CURLPROXY_SOCKS5')) {
        return CURLPROXY_SOCKS5;
    }

    if ($scheme === 'socks4a' && defined('CURLPROXY_SOCKS4A')) {
        return CURLPROXY_SOCKS4A;
    }

    if ($scheme === 'socks4' && defined('CURLPROXY_SOCKS4')) {
        return CURLPROXY_SOCKS4;
    }

    return defined('CURLPROXY_HTTP') ? CURLPROXY_HTTP : 0;
}

function gamesSearchBuildProxyDefinition($scheme, $host, $port, $username = '', $password = '', $rawLine = '') {
    $scheme = lowerKeyword(trim((string) ($scheme !== '' ? $scheme : 'http')));
    $host = trim((string) $host, "[] \t\n\r\0\x0B");
    $port = (int) $port;
    $username = trim((string) $username);
    $password = (string) $password;

    if ($host === '' || $port <= 0 || $port > 65535) {
        return null;
    }

    return [
        'scheme' => $scheme,
        'host' => $host,
        'port' => $port,
        'username' => $username,
        'password' => $password,
        'curl_type' => gamesSearchMapProxySchemeToCurlType($scheme),
        'id' => substr(sha1($scheme . '|' . $host . '|' . $port . '|' . $username . '|' . $password . '|' . $rawLine), 0, 10)
    ];
}

function gamesSearchParseProxyByUrl($line, $defaultScheme = 'http') {
    $candidate = preg_match('#^[a-z][a-z0-9+.-]*://#i', $line)
        ? $line
        : $defaultScheme . '://' . $line;

    $parts = @parse_url($candidate);
    if (!is_array($parts) || empty($parts['host']) || empty($parts['port'])) {
        return null;
    }

    return gamesSearchBuildProxyDefinition(
        $parts['scheme'] ?? $defaultScheme,
        $parts['host'],
        $parts['port'],
        $parts['user'] ?? '',
        $parts['pass'] ?? '',
        $line
    );
}

function gamesSearchLooksLikeHostPort($value) {
    return preg_match('/^\[[^\]]+\]:\d+$/', $value) || preg_match('/^[^:\s]+:\d+$/', $value);
}

function gamesSearchParseProxyFromSegments(array $segments, $rawLine) {
    $segments = array_values(array_map('trim', $segments));
    if (count($segments) === 2 && gamesSearchLooksLikeHostPort($segments[0] . ':' . $segments[1])) {
        return gamesSearchBuildProxyDefinition('http', $segments[0], $segments[1], '', '', $rawLine);
    }

    if (count($segments) === 4) {
        if (ctype_digit($segments[1])) {
            return gamesSearchBuildProxyDefinition('http', $segments[0], $segments[1], $segments[2], $segments[3], $rawLine);
        }

        if (ctype_digit($segments[3])) {
            return gamesSearchBuildProxyDefinition('http', $segments[2], $segments[3], $segments[0], $segments[1], $rawLine);
        }
    }

    return null;
}

function gamesSearchParseProxyLine($line) {
    $line = trim((string) $line);
    if ($line === '' || preg_match('/^(#|;|\/\/)/', $line)) {
        return null;
    }

    $line = preg_replace('/\s+#.*$/', '', $line);
    $line = trim((string) $line);
    if ($line === '') {
        return null;
    }

    $parsed = gamesSearchParseProxyByUrl($line);
    if ($parsed !== null) {
        return $parsed;
    }

    if (strpos($line, '@') !== false) {
        $parts = explode('@', $line, 2);
        if (count($parts) === 2) {
            if (gamesSearchLooksLikeHostPort($parts[1])) {
                $parsed = gamesSearchParseProxyByUrl('http://' . $parts[0] . '@' . $parts[1]);
                if ($parsed !== null) {
                    return $parsed;
                }
            }

            if (gamesSearchLooksLikeHostPort($parts[0])) {
                $credentials = explode(':', $parts[1], 2);
                if (count($credentials) === 2) {
                    $endpoint = gamesSearchParseProxyByUrl('http://' . $parts[0]);
                    if ($endpoint !== null) {
                        return gamesSearchBuildProxyDefinition(
                            $endpoint['scheme'],
                            $endpoint['host'],
                            $endpoint['port'],
                            $credentials[0],
                            $credentials[1],
                            $line
                        );
                    }
                }
            }
        }
    }

    foreach ([':', '|', ',', ';'] as $delimiter) {
        $segments = explode($delimiter, $line);
        $parsed = gamesSearchParseProxyFromSegments($segments, $line);
        if ($parsed !== null) {
            return $parsed;
        }
    }

    return null;
}

function gamesSearchLoadProxyList() {
    static $proxyList = null;
    if ($proxyList !== null) {
        return $proxyList;
    }

    $proxyList = [];
    $path = gamesSearchGetProxyFilePath();
    if (!is_file($path)) {
        searchLog('Proxy file missing', ['path' => $path]);
        return $proxyList;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        searchLog('Proxy file unreadable', ['path' => $path]);
        return $proxyList;
    }

    $invalidCount = 0;
    foreach ($lines as $line) {
        $proxy = gamesSearchParseProxyLine($line);
        if ($proxy === null) {
            $candidate = trim((string) $line);
            if ($candidate !== '' && !preg_match('/^(#|;|\/\/)/', $candidate)) {
                $invalidCount++;
            }
            continue;
        }

        $proxyList[$proxy['id']] = $proxy;
    }

    $proxyList = array_values($proxyList);
    searchLog('Proxy list loaded', [
        'path' => $path,
        'proxy_count' => count($proxyList),
        'invalid_lines' => $invalidCount
    ]);

    return $proxyList;
}

function gamesSearchChooseRandomProxy() {
    $proxyList = gamesSearchLoadProxyList();
    if (empty($proxyList)) {
        return null;
    }

    try {
        $index = random_int(0, count($proxyList) - 1);
    } catch (Throwable $e) {
        $index = mt_rand(0, count($proxyList) - 1);
    }

    return $proxyList[$index];
}

function gamesSearchGetProxyLogLabel($proxy) {
    if (!is_array($proxy)) {
        return 'DIRECT';
    }

    return strtoupper((string) $proxy['scheme']) . '#' . (string) $proxy['id'] . '@' . (string) $proxy['host'] . ':' . (int) $proxy['port'];
}

function gamesSearchShouldUseProxyForUrl($url) {
    $host = lowerKeyword((string) parse_url($url, PHP_URL_HOST));
    return $host !== '' && (substr($host, -11) === '.roblox.com' || $host === 'roblox.com');
}

/*
 * Cache and rate-limit state are kept in memory (APCu) — nothing is written to the disk any more.
 * Before, every searched keyword became a file tmp/games-search/keyword-<sha1>.json that was never deleted.
 * Without the APCu extension the search works the same, only without a cache (install php-apcu to get it back).
 */
function gamesSearchMemoryAvailable() {
    static $ok = null;
    if ($ok === null) {
        $ok = function_exists('apcu_fetch') && function_exists('apcu_store')
            && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)
            && (PHP_SAPI !== 'cli' || filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOLEAN));
    }
    return $ok;
}

function readState($key) {
    if (!gamesSearchMemoryAvailable()) {
        return null;
    }
    $ok = false;
    $data = apcu_fetch('games-search:' . $key, $ok);
    return ($ok && is_array($data)) ? $data : null;
}

function writeState($key, array $payload, $ttl) {
    if (!gamesSearchMemoryAvailable()) {
        return false;
    }
    return apcu_store('games-search:' . $key, $payload, max(1, (int) $ttl));
}

function getKeywordCacheKey($keyword) {
    return 'kw:' . sha1(lowerKeyword(normalizeKeyword($keyword)));
}

function loadCachedResult($keyword, $maxAgeSeconds) {
    $payload = readState(getKeywordCacheKey($keyword));
    if (!is_array($payload) || !isset($payload['saved_at']) || !isset($payload['response']) || !is_array($payload['response'])) {
        return null;
    }

    $age = time() - (int) $payload['saved_at'];
    if ($age < 0 || $age > (int) $maxAgeSeconds) {
        return null;
    }

    $payload['age_seconds'] = $age;
    return $payload;
}

function saveCachedResult($keyword, array $response) {
    // kept as long as the stale window, then APCu removes it by itself
    return writeState(getKeywordCacheKey($keyword), [
        'keyword' => lowerKeyword(normalizeKeyword($keyword)),
        'saved_at' => time(),
        'response' => $response
    ], GAMES_SEARCH_CACHE_STALE_TTL);
}

function getActiveRateLimitState() {
    $payload = readState('rate-limit');
    if (!is_array($payload)) {
        return null;
    }

    $cooldownUntil = (int) ($payload['cooldown_until'] ?? 0);
    if ($cooldownUntil <= time()) {
        return null;
    }

    $payload['remaining_seconds'] = $cooldownUntil - time();
    return $payload;
}

function setRateLimitCooldown($seconds, array $context = []) {
    $cooldownSeconds = max(1, min(120, (int) $seconds));
    $payload = [
        'created_at' => time(),
        'cooldown_until' => time() + $cooldownSeconds,
        'context' => $context
    ];

    writeState('rate-limit', $payload, $cooldownSeconds);
    return $payload;
}

/*
 * The files the old version left behind are removed (a few hundred per request, at most once every 10 minutes),
 * together with the folders when they are empty.
 */
function cleanupOldDiskCache() {
    if (gamesSearchMemoryAvailable()) {
        if (apcu_fetch('games-search:cleanup-done')) {
            return;
        }
        apcu_store('games-search:cleanup-done', 1, 600);
    }
    $dirs = [];
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $dirs[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'games-search';
    }
    $dirs[] = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'games-search';
    $dirs[] = __DIR__ . DIRECTORY_SEPARATOR . '.games-search-cache';
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $files = array_merge(glob($dir . DIRECTORY_SEPARATOR . 'keyword-*.json') ?: [], glob($dir . DIRECTORY_SEPARATOR . 'rate-limit.json') ?: []);
        foreach (array_slice($files, 0, 500) as $file) {
            @unlink($file);
        }
        @rmdir($dir);                                  // only succeeds when the folder is empty
    }
}

function getResponseHeaderValue(array $headers, $name) {
    foreach ($headers as $headerName => $values) {
        if (strcasecmp((string) $headerName, (string) $name) !== 0) {
            continue;
        }

        if (is_array($values)) {
            return isset($values[0]) ? (string) $values[0] : null;
        }

        return (string) $values;
    }

    return null;
}

function parseRetryAfterSeconds($value) {
    if ($value === null || $value === '') {
        return 0;
    }

    if (ctype_digit((string) $value)) {
        return max(0, (int) $value);
    }

    $timestamp = strtotime((string) $value);
    if ($timestamp === false) {
        return 0;
    }

    return max(0, $timestamp - time());
}

function respondJson(array $payload, $statusCode = 200, $cacheStatus = null) {
    http_response_code((int) $statusCode);
    if ($cacheStatus !== null) {
        header('X-Search-Cache: ' . $cacheStatus);
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function createSearchSessionId() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

function robloxJsonRequest($url, $postBody = null, $timeout = 12) {
    $ch = curl_init($url);
    $headers = [];
    $selectedProxy = null;
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$headers) {
        $length = strlen($headerLine);
        $trimmed = trim($headerLine);
        if ($trimmed === '' || strpos($trimmed, ':') === false) {
            return $length;
        }

        list($name, $value) = explode(':', $trimmed, 2);
        $name = trim($name);
        $value = trim($value);
        if (!isset($headers[$name])) {
            $headers[$name] = [];
        }

        $headers[$name][] = $value;
        return $length;
    });

    if (gamesSearchShouldUseProxyForUrl($url)) {
        $selectedProxy = gamesSearchChooseRandomProxy();
        if ($selectedProxy !== null) {
            curl_setopt($ch, CURLOPT_PROXY, (string) $selectedProxy['host']);
            curl_setopt($ch, CURLOPT_PROXYPORT, (int) $selectedProxy['port']);
            curl_setopt($ch, CURLOPT_PROXYTYPE, (int) $selectedProxy['curl_type']);

            if ($selectedProxy['username'] !== '') {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $selectedProxy['username'] . ':' . $selectedProxy['password']);
            }

            if ($selectedProxy['scheme'] === 'https') {
                if (defined('CURLOPT_PROXY_SSL_VERIFYPEER')) {
                    curl_setopt($ch, CURLOPT_PROXY_SSL_VERIFYPEER, false);
                }

                if (defined('CURLOPT_PROXY_SSL_VERIFYHOST')) {
                    curl_setopt($ch, CURLOPT_PROXY_SSL_VERIFYHOST, false);
                }
            }
        } else {
            searchLog('Proxy list empty, using direct Roblox request', [
                'host' => parse_url($url, PHP_URL_HOST)
            ]);
        }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
    ]);

    if ($postBody !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postBody));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
        ]);
    }

    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'ok' => $body !== false && $error === '' && $code >= 200 && $code < 300,
        'code' => $code,
        'body' => $body !== false ? $body : '',
        'error' => $error,
        'headers' => $headers,
        'proxy_label' => gamesSearchGetProxyLogLabel($selectedProxy)
    ];
}

function fetchOmniSearchData($keyword, $attempts = 3) {
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        $sessionId = createSearchSessionId();
        $url = 'https://apis.roblox.com/search-api/omni-search?searchQuery='
            . urlencode($keyword)
            . '&pageToken=&sessionId='
            . urlencode($sessionId)
            . '&pageType=all';

        searchLog('Omni search attempt started', [
            'keyword' => $keyword,
            'attempt' => $attempt + 1,
            'attempts' => $attempts,
            'session_id' => $sessionId
        ]);

        $response = robloxJsonRequest($url);
        if ((int) $response['code'] === 429) {
            $retryAfter = parseRetryAfterSeconds(getResponseHeaderValue($response['headers'], 'Retry-After'));
            $cooldownSeconds = $retryAfter > 0 ? $retryAfter : GAMES_SEARCH_RATE_LIMIT_COOLDOWN;
            setRateLimitCooldown($cooldownSeconds, [
                'keyword' => $keyword,
                'attempt' => $attempt + 1,
                'http_code' => $response['code'],
                'proxy' => $response['proxy_label'] ?? 'DIRECT'
            ]);

            searchLog('Omni search rate limited', [
                'keyword' => $keyword,
                'attempt' => $attempt + 1,
                'http_code' => $response['code'],
                'proxy' => $response['proxy_label'] ?? 'DIRECT',
                'retry_after_seconds' => $retryAfter,
                'cooldown_seconds' => $cooldownSeconds,
                'body_preview' => substr($response['body'], 0, 250)
            ]);

            return [
                'ok' => false,
                'data' => null,
                'rate_limited' => true,
                'http_code' => $response['code'],
                'cooldown_seconds' => $cooldownSeconds
            ];
        }

        if (!$response['ok'] || $response['body'] === '') {
            searchLog('Omni search HTTP failure', [
                'keyword' => $keyword,
                'attempt' => $attempt + 1,
                'http_code' => $response['code'],
                'proxy' => $response['proxy_label'] ?? 'DIRECT',
                'curl_error' => $response['error'],
                'body_length' => strlen($response['body']),
                'body_preview' => substr($response['body'], 0, 250)
            ]);
            usleep(250000 * ($attempt + 1));
            continue;
        }

        $data = json_decode($response['body'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            searchLog('Omni search attempt succeeded', [
                'keyword' => $keyword,
                'attempt' => $attempt + 1,
                'http_code' => $response['code'],
                'proxy' => $response['proxy_label'] ?? 'DIRECT',
                'search_results_count' => isset($data['searchResults']) && is_array($data['searchResults'])
                    ? count($data['searchResults'])
                    : 0,
                'filtered_search_query' => $data['filteredSearchQuery'] ?? null
            ]);
            return [
                'ok' => true,
                'data' => $data,
                'rate_limited' => false,
                'http_code' => $response['code']
            ];
        }

        searchLog('Omni search invalid JSON', [
            'keyword' => $keyword,
            'attempt' => $attempt + 1,
            'http_code' => $response['code'],
            'proxy' => $response['proxy_label'] ?? 'DIRECT',
            'json_error' => json_last_error_msg(),
            'body_preview' => substr($response['body'], 0, 250)
        ]);
        usleep(250000 * ($attempt + 1));
    }

    searchLog('Omni search exhausted attempts', [
        'keyword' => $keyword,
        'attempts' => $attempts
    ]);
    return [
        'ok' => false,
        'data' => null,
        'rate_limited' => false,
        'http_code' => 0
    ];
}

function normalizeGameRow($universeId, $rootPlaceId, $name, $playerCount) {
    return [
        'universeId' => (int) $universeId,
        'rootPlaceId' => $rootPlaceId > 0 ? (int) $rootPlaceId : null,
        'name' => $name !== '' ? $name : 'Unknown',
        'playerCount' => (int) $playerCount
    ];
}

function extractGameCandidates($searchData) {
    $resolved = [];
    $pending = [];

    if (empty($searchData['searchResults']) || !is_array($searchData['searchResults'])) {
        return ['resolved' => $resolved, 'pending' => $pending];
    }

    foreach ($searchData['searchResults'] as $section) {
        if (empty($section['contents']) || !is_array($section['contents'])) {
            continue;
        }

        $groupType = strtolower((string) ($section['contentGroupType'] ?? ''));
        $sectionLooksLikeGameGroup = in_array($groupType, ['game', 'games', 'experience', 'experiences'], true);

        foreach ($section['contents'] as $content) {
            $contentType = strtolower((string) ($content['contentType'] ?? ''));
            $universeId = (int) ($content['universeId'] ?? 0);
            $rootPlaceId = (int) ($content['rootPlaceId'] ?? 0);
            $contentId = (int) ($content['contentId'] ?? 0);
            $name = trim((string) ($content['name'] ?? ''));
            $playerCount = (int) ($content['playerCount'] ?? 0);

            $looksLikeGame = $sectionLooksLikeGameGroup || $contentType === 'game' || $universeId > 0 || $rootPlaceId > 0;
            if (!$looksLikeGame) {
                continue;
            }

            if ($rootPlaceId <= 0 && $contentType === 'game' && $contentId > 0) {
                $rootPlaceId = $contentId;
            }

            if ($universeId > 0) {
                $resolved[$universeId] = normalizeGameRow($universeId, $rootPlaceId, $name, $playerCount);
                continue;
            }

            if ($rootPlaceId > 0 && !isset($pending[$rootPlaceId])) {
                $pending[$rootPlaceId] = normalizeGameRow(0, $rootPlaceId, $name, $playerCount);
            }
        }
    }

    return [
        'resolved' => array_values($resolved),
        'pending' => $pending
    ];
}

function resolveUniverseIdByPlaceId($placeId) {
    $url = 'https://apis.roblox.com/universes/v1/places/' . (int) $placeId . '/universe';
    $response = robloxJsonRequest($url, null, 8);

    if (!$response['ok'] || $response['body'] === '') {
        searchLog('Universe resolve failed', [
            'place_id' => (int) $placeId,
            'http_code' => $response['code'],
            'proxy' => $response['proxy_label'] ?? 'DIRECT',
            'curl_error' => $response['error'],
            'body_length' => strlen($response['body'])
        ]);
        return 0;
    }

    $data = json_decode($response['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        searchLog('Universe resolve invalid JSON', [
            'place_id' => (int) $placeId,
            'http_code' => $response['code'],
            'proxy' => $response['proxy_label'] ?? 'DIRECT',
            'json_error' => json_last_error_msg(),
            'body_preview' => substr($response['body'], 0, 250)
        ]);
        return 0;
    }

    return (int) ($data['universeId'] ?? 0);
}

function buildGamesList($searchData, $limit = 60) {
    $candidates = extractGameCandidates($searchData);
    $games = [];
    $seenUniverseIds = [];

    foreach ($candidates['resolved'] as $game) {
        if ($game['universeId'] <= 0 || isset($seenUniverseIds[$game['universeId']])) {
            continue;
        }

        $seenUniverseIds[$game['universeId']] = true;
        $games[] = $game;

        if (count($games) >= $limit) {
            return $games;
        }
    }

    // Some omni-search results only expose place IDs, so resolve them here.
    foreach ($candidates['pending'] as $placeId => $game) {
        if (count($games) >= $limit) {
            break;
        }

        $universeId = resolveUniverseIdByPlaceId($placeId);
        if ($universeId <= 0 || isset($seenUniverseIds[$universeId])) {
            continue;
        }

        $seenUniverseIds[$universeId] = true;
        $games[] = normalizeGameRow($universeId, $placeId, $game['name'], $game['playerCount']);
    }

    searchLog('Built games list', [
        'resolved_candidates' => count($candidates['resolved']),
        'pending_candidates' => count($candidates['pending']),
        'final_games' => count($games)
    ]);

    return $games;
}

function fetchGameIcons($games) {
    if (empty($games)) {
        return [];
    }

    $universeIds = array_slice(array_column($games, 'universeId'), 0, 60);
    $universeIds = array_values(array_filter(array_map('intval', $universeIds)));
    if (empty($universeIds)) {
        return [];
    }

    $url = 'https://thumbnails.roblox.com/v1/games/icons?universeIds='
        . implode(',', $universeIds)
        . '&returnPolicy=PlaceHolder&size=256x256&format=Png&isCircular=false';

    $response = robloxJsonRequest($url, null, 10);
    if (!$response['ok'] || $response['body'] === '') {
        searchLog('Icon fetch failed', [
            'http_code' => $response['code'],
            'proxy' => $response['proxy_label'] ?? 'DIRECT',
            'curl_error' => $response['error'],
            'body_length' => strlen($response['body']),
            'requested_universe_count' => count($universeIds)
        ]);
        return [];
    }

    $data = json_decode($response['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE || empty($data['data']) || !is_array($data['data'])) {
        searchLog('Icon fetch invalid JSON', [
            'http_code' => $response['code'],
            'proxy' => $response['proxy_label'] ?? 'DIRECT',
            'json_error' => json_last_error_msg(),
            'body_preview' => substr($response['body'], 0, 250)
        ]);
        return [];
    }

    $icons = [];
    foreach ($data['data'] as $icon) {
        $targetId = (int) ($icon['targetId'] ?? 0);
        $imageUrl = (string) ($icon['imageUrl'] ?? '');
        if ($targetId > 0 && $imageUrl !== '') {
            $icons[$targetId] = $imageUrl;
        }
    }

    return $icons;
}

cleanupOldDiskCache();

$keyword = isset($_GET['keyword']) ? normalizeKeyword($_GET['keyword']) : '';

if (empty($keyword) || strlen($keyword) < 2) {
    searchLog('Rejected short keyword', [
        'keyword' => $keyword,
        'uri' => $_SERVER['REQUEST_URI'] ?? ''
    ]);
    respondJson(['success' => false, 'message' => 'Keyword too short', 'games' => []], 400, 'BYPASS');
    exit;
}

try {
    $keywordVariants = buildKeywordVariants($keyword);
    $matchedKeyword = null;
    $games = [];
    $rateLimited = null;

    searchLog('Incoming search request', [
        'keyword' => $keyword,
        'keyword_variants' => $keywordVariants,
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? ''
    ]);

    $freshCache = loadCachedResult($keyword, GAMES_SEARCH_CACHE_FRESH_TTL);
    if (is_array($freshCache)) {
        searchLog('Served fresh cache hit', [
            'keyword' => $keyword,
            'cache_age_seconds' => $freshCache['age_seconds'],
            'games_returned' => isset($freshCache['response']['games']) && is_array($freshCache['response']['games'])
                ? count($freshCache['response']['games'])
                : 0
        ]);
        respondJson($freshCache['response'], 200, 'HIT');
        exit;
    }

    $cooldownState = getActiveRateLimitState();
    if (is_array($cooldownState)) {
        $staleCache = loadCachedResult($keyword, GAMES_SEARCH_CACHE_STALE_TTL);
        if (is_array($staleCache)) {
            searchLog('Served stale cache during global cooldown', [
                'keyword' => $keyword,
                'cache_age_seconds' => $staleCache['age_seconds'],
                'cooldown_remaining_seconds' => $cooldownState['remaining_seconds']
            ]);
            respondJson($staleCache['response'], 200, 'STALE');
            exit;
        }

        searchLog('Skipped upstream search during active cooldown', [
            'keyword' => $keyword,
            'cooldown_remaining_seconds' => $cooldownState['remaining_seconds']
        ]);
        respondJson(['success' => false, 'message' => 'Search temporarily rate limited', 'games' => []], 503, 'MISS');
        exit;
    }

    foreach ($keywordVariants as $index => $variant) {
        $searchResult = fetchOmniSearchData($variant, $index === 0 ? 2 : 1);
        if (empty($searchResult['ok'])) {
            if (!empty($searchResult['rate_limited'])) {
                $rateLimited = $searchResult;
                break;
            }
            continue;
        }

        $searchData = $searchResult['data'];
        $games = buildGamesList($searchData, 60);
        searchLog('Keyword variant processed', [
            'variant' => $variant,
            'variant_index' => $index,
            'games_found' => count($games),
            'filtered_search_query' => $searchData['filteredSearchQuery'] ?? null
        ]);

        if (!empty($games)) {
            $matchedKeyword = $variant;
            break;
        }
    }

    if ($rateLimited !== null) {
        $staleCache = loadCachedResult($keyword, GAMES_SEARCH_CACHE_STALE_TTL);
        if (is_array($staleCache)) {
            searchLog('Served stale cache after upstream 429', [
                'keyword' => $keyword,
                'cache_age_seconds' => $staleCache['age_seconds'],
                'cooldown_seconds' => $rateLimited['cooldown_seconds'] ?? 0
            ]);
            respondJson($staleCache['response'], 200, 'STALE');
            exit;
        }

        searchLog('Returned upstream rate limit response', [
            'keyword' => $keyword,
            'cooldown_seconds' => $rateLimited['cooldown_seconds'] ?? 0
        ]);
        respondJson(['success' => false, 'message' => 'Search temporarily rate limited', 'games' => []], 503, 'MISS');
        exit;
    }

    if ($matchedKeyword === null) {
        searchLog('Search completed with no results', [
            'keyword' => $keyword,
            'keyword_variants' => $keywordVariants
        ]);
    }

    if (empty($games)) {
        $emptyResponse = ['success' => true, 'games' => []];
        saveCachedResult($keyword, $emptyResponse);
        respondJson($emptyResponse, 200, 'MISS');
        exit;
    }

    $iconMap = fetchGameIcons($games);
    $result = [];

    foreach ($games as $game) {
        $result[] = [
            'universeId' => $game['universeId'],
            'placeId' => $game['rootPlaceId'],
            'name' => $game['name'],
            'playerCount' => $game['playerCount'],
            'icon' => $iconMap[$game['universeId']] ?? ''
        ];
    }

    searchLog('Search completed successfully', [
        'keyword' => $keyword,
        'matched_keyword' => $matchedKeyword,
        'games_returned' => count($result),
        'icons_resolved' => count($iconMap)
    ]);

    $responsePayload = ['success' => true, 'games' => $result];
    saveCachedResult($keyword, $responsePayload);
    respondJson($responsePayload, 200, 'MISS');
} catch (Throwable $e) {
    searchLog('Search crashed', [
        'keyword' => $keyword,
        'exception_class' => get_class($e),
        'exception_message' => $e->getMessage(),
        'line' => $e->getLine(),
        'file' => $e->getFile()
    ]);
    respondJson(['success' => false, 'message' => 'Search temporarily unavailable', 'games' => []], 500, 'MISS');
}