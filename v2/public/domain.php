<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require __DIR__ . '/../../libs/configuration.php';

if (!isset($domain) || !is_array($domain)) {
    http_response_code(500);
    echo json_encode(['error' => 'Domain source not available']);
    exit;
}

$lists = [];
foreach ($domain as $entry) {
    if (!is_string($entry) || trim($entry) === '') continue;
    $parts = preg_split('/\s+/', trim($entry));
    if (empty($parts[0])) continue;
    $name = $parts[0];
    $types = [];
    for ($i = 1; $i < count($parts); $i++) {
        $kv = explode(':', $parts[$i], 2);
        if (count($kv) === 2 && strtolower($kv[1]) === 'true') {
            $types[] = strtolower($kv[0]);
        }
    }
    $lists[] = [
        'domain' => $name,
        'type' => implode(',', $types),
    ];
}

echo json_encode(['lists' => $lists], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);