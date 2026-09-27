<?php
    include 'configuration.php';
    if(basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) die('{"errors":[{"code":401,"message":"Unauthorized"}]}');

    try{
        // ❌ SORUN 1: charset=gbk YANLIŞ! (Çince karakter seti)
        // ✅ ÇÖZÜM: charset=utf8mb4 kullan
        
        // ❌ SORUN 2: PDO error mode ayarlanmamış
        // ✅ ÇÖZÜM: ERRMODE_EXCEPTION ekle
        
        // ❌ SORUN 3: Değişken ismi $db, ama kodda $pdo kullanılıyor
        // ✅ ÇÖZÜM: $pdo olarak oluştur
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // MySQL bazı sürümlerde DSN'deki charset'i ignore eder; SET NAMES
            // bağlantı seviyesinde utf8mb4'ü zorlar — 4-byte karakterler
            // (emoji, Japonca, Korece) bozulmadan saklanır.
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        $pdo = new PDO(
            'mysql:host=127.0.0.1;dbname=' . $database['name'] . ';charset=utf8mb4',
            $database['username'],
            $database['password'],
            $options
        );
        
        // Eski kod uyumluluğu için (eğer başka yerlerde $db kullanılıyorsa)
        $db = $pdo;
        
    } catch(PDOException $Exception) {
        error_log('ERROR: '.$Exception->getMessage().' - '.$_SERVER['REQUEST_URI'].' at '.date('l jS \of F, Y, h:i:s A')."\n", 3, 'error.log');
        die('Database failed to connect.');
    }
?>