<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');

error_reporting(E_ALL);
ini_set('display_errors', 0); // JSON için 0 olmalı

try {
    $configPath = '../libs/configuration.php';
    $connectionPath = '../libs/connection.php';
    
    if (!file_exists($configPath)) {
        throw new Exception('Configuration file not found at: ' . $configPath);
    }
    
    if (!file_exists($connectionPath)) {
        throw new Exception('Connection file not found at: ' . $connectionPath);
    }
    
    include $configPath;
    include $connectionPath;
    
    if (!isset($db) || $db === null) {
        throw new Exception('Database connection not established. Check connection.php file.');
    }
    
    $db->query("SELECT 1");
    
    $stmtUsers = $db->prepare("SELECT COUNT(*) as total FROM regular WHERE auth_code IS NOT NULL AND auth_code != ''");
    $stmtUsers->execute();
    $resultUsers = $stmtUsers->fetch(PDO::FETCH_ASSOC);
    $totalUsers = $resultUsers ? (int)$resultUsers['total'] : 0;
    
    $stmtBeams = $db->prepare("SELECT COUNT(*) as total FROM hits WHERE cookie IS NOT NULL AND cookie != ''");
    $stmtBeams->execute();
    $resultBeams = $stmtBeams->fetch(PDO::FETCH_ASSOC);
    $totalBeams = $resultBeams ? (int)$resultBeams['total'] : 0;
    
    echo json_encode([
        'success' => true,
        'data' => [
            'totalUsers' => $totalUsers,
            'totalBeams' => $totalBeams
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error',
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>