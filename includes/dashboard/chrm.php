<?php
function encryptStart() {
    ob_start();
}

function encryptEnd($blockId) {
    $html = ob_get_clean();
    if (empty($html)) return;

    if (!isset($_SESSION['enc_master_key'])) {
        $_SESSION['enc_master_key'] = random_bytes(32);
    }

    $key = $_SESSION['enc_master_key'];
    $iv = random_bytes(12);
    $encrypted = openssl_encrypt($html, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

    if ($encrypted === false) {
        echo $html;
        return;
    }

    $payload = base64_encode($iv . $tag . $encrypted);

    echo '<div class="enc-block" id="enc_' . htmlspecialchars($blockId) . '" data-enc="' . $payload . '"></div>';
}

function getEncToken() {
    if (!isset($_SESSION['enc_token'])) {
        $_SESSION['enc_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['enc_token'];
}