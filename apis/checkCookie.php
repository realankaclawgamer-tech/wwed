<?php
session_start();
$rawCookie = $_GET['a'] ?? '';
$accountData = null;
$isValid = false;
$errorMsg = '';

if (!empty($rawCookie)) {
    $accountData = checkRobloxCookie($rawCookie);
    if ($accountData && !isset($accountData['error'])) {
        $isValid = true;
    } else {
        $errorMsg = $accountData['error'] ?? 'Invalid or expired cookie.';
        $isValid = false;
    }
}

function checkRobloxCookie($cookie) {
    $authCh = curl_init('https://users.roblox.com/v1/users/authenticated');
    curl_setopt_array($authCh, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ["Cookie: .ROBLOSECURITY=$cookie"]
    ]);
    $user = json_decode(curl_exec($authCh));
    curl_close($authCh);

    if (!$user || isset($user->errors) || empty($user->id)) {
        return ['error' => 'Invalid or expired cookie.'];
    }

    $userId = $user->id;
    $username = $user->name ?? '';
    $displayName = $user->displayName ?? '';

    $urls = [
        "avatar"         => "https://thumbnails.roblox.com/v1/users/avatar-headshot?userIds=$userId&size=420x420&format=Png",
        "email"          => "https://accountsettings.roblox.com/v1/email",
        "robux"          => "https://economy.roblox.com/v1/users/$userId/currency",
        "subscriptions"  => "https://premiumfeatures.roblox.com/v1/users/$userId/subscriptions",
        "user_info"      => "https://users.roblox.com/v1/users/$userId",
        "headless_check" => "https://inventory.roblox.com/v1/users/$userId/items/Bundle/201",
        "korblox_check"  => "https://inventory.roblox.com/v1/users/$userId/items/Bundle/192",
        "account_country" => "https://accountsettings.roblox.com/v1/account/settings/account-country",
    ];

    $mh = curl_multi_init();
    $ch = [];
    foreach ($urls as $key => $url) {
        $ch[$key] = curl_init($url);
        curl_setopt_array($ch[$key], [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ["Cookie: .ROBLOSECURITY=$cookie"]
        ]);
        curl_multi_add_handle($mh, $ch[$key]);
    }
    do { curl_multi_exec($mh, $running); } while ($running);

    $results = [];
    foreach ($ch as $key => $handle) {
        $results[$key] = json_decode(curl_multi_getcontent($handle));
        curl_multi_remove_handle($mh, $handle);
    }
    curl_multi_close($mh);

    $avatar = $results['avatar']->data[0]->imageUrl ?? '';
    $robux = $results['robux']->robux ?? 0;
    $premium_status = $results['subscriptions']->subscriptionProductModel->subscriptionName ?? "False";
    $emailVerified = isset($results['email']->verified) && $results['email']->verified;

    $accountAge = '—'; $created = '—'; $isBanned = false;
    if (isset($results['user_info']->created)) {
        $createdDate = new DateTime($results['user_info']->created);
        $now = new DateTime();
        $diff = $createdDate->diff($now);
        $accountAge = $diff->y . ' years, ' . $diff->m . ' months';
        $created = $createdDate->format('d/m/Y');
        $isBanned = $results['user_info']->isBanned ?? false;
    }

    $korblox = strpos(json_encode($results['korblox_check']), "Korblox") !== false ? "True" : "False";
    $headless = strpos(json_encode($results['headless_check']), "Headless") !== false ? "True" : "False";

    $accountCountry = 'Unknown';
    if (isset($results['account_country']->value->countryName)) {
        $accountCountry = $results['account_country']->value->countryName;
    }

    return [
        'userId' => $userId, 'username' => $username, 'displayName' => $displayName,
        'avatar' => $avatar, 'robux' => $robux, 'premium' => $premium_status,
        'emailVerified' => $emailVerified, 'accountAge' => $accountAge,
        'created' => $created, 'isBanned' => $isBanned,
        'korblox' => $korblox, 'headless' => $headless, 'accountCountry' => $accountCountry,
    ];
}
ob_start();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cookie Checker</title>
    <link rel="icon" type="image/png" href="/images/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@300;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root{--accent:#a1a1aa;--accent-dim:#8b8b99;--dark-bg:#0a0b0f}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Rajdhani',sans-serif;background:var(--dark-bg);color:#fff;min-height:100vh;overflow-x:hidden;position:relative;display:flex;justify-content:center;align-items:center}
        .animated-bg{position:fixed;top:0;left:0;width:100%;height:100%;z-index:0;overflow:hidden;pointer-events:none}
        .lightning{position:absolute;width:1px;background:linear-gradient(to bottom,transparent,rgba(161,161,170,.4),transparent);opacity:0;animation:lightning 5s infinite;filter:blur(.5px)}
        .lightning:nth-child(1){left:20%;top:-100px;height:150px}
        .lightning:nth-child(2){left:50%;top:-120px;height:180px;animation-delay:2s}
        .lightning:nth-child(3){left:80%;top:-100px;height:140px;animation-delay:4s}
        @keyframes lightning{0%,90%,100%{opacity:0;transform:translateY(0)}2%,6%{opacity:.5}4%{opacity:.3}15%{transform:translateY(100vh);opacity:0}}
        .geo-container{position:absolute;width:100%;height:100%}
        .geo-line:nth-child(4){position:absolute;width:1px;height:100%;left:15%;top:0;background:linear-gradient(to bottom,transparent,rgba(161,161,170,.1),transparent)}
        .geo-line:nth-child(5){position:absolute;width:1px;height:100%;left:35%;top:0;background:linear-gradient(to bottom,transparent,rgba(161,161,170,.1),transparent)}
        .geo-line:nth-child(6){position:absolute;width:1px;height:100%;left:55%;top:0;background:linear-gradient(to bottom,transparent,rgba(161,161,170,.1),transparent)}
        .geo-line:nth-child(7){position:absolute;width:1px;height:100%;left:75%;top:0;background:linear-gradient(to bottom,transparent,rgba(161,161,170,.1),transparent)}
        .geo-line:nth-child(8){position:absolute;width:100%;height:1px;top:20%;left:0;background:linear-gradient(to right,transparent,rgba(161,161,170,.1),transparent)}
        .geo-line:nth-child(9){position:absolute;width:100%;height:1px;top:50%;left:0;background:linear-gradient(to right,transparent,rgba(161,161,170,.1),transparent)}
        .connection-point{position:absolute;width:1px;background:rgba(161,161,170,.15);animation:connectionPulse 4s ease-in-out infinite}
        .connection-point:nth-child(14){top:20%;left:15%;width:20%;height:1px}
        .connection-point:nth-child(15){top:20%;left:35%;width:20%;height:1px;animation-delay:.5s}
        .connection-point:nth-child(16){top:50%;left:15%;width:1px;height:30%;animation-delay:1s}
        .connection-point:nth-child(17){top:50%;left:35%;width:1px;height:30%;animation-delay:1.5s}
        @keyframes connectionPulse{0%,100%{opacity:.1}50%{opacity:.3}}
        .particle{position:absolute;width:2px;height:2px;background:var(--accent-dim);border-radius:50%;opacity:0;animation:particleFloat 12s infinite}
        .particle:nth-child(18){left:15%}.particle:nth-child(19){left:35%;animation-delay:3s}
        @keyframes particleFloat{0%{bottom:0;opacity:0}10%{opacity:.6}90%{opacity:.6}100%{bottom:100vh;opacity:0}}
        .action-box{width:100%;max-width:550px;background:rgba(10,10,15,.2);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);border-radius:16px;border:1px solid rgba(255,255,255,.06);overflow:hidden;position:relative;z-index:10;margin:40px 20px;animation:cardIn .5s ease}
        @keyframes cardIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
        .action-box::before{display:none}
        .box-header{padding:18px 25px;border-bottom:1px solid rgba(255,255,255,.05);display:flex;align-items:center;gap:10px;background:transparent}
        .box-header h3{color:#888;font-size:.9rem;font-weight:600;text-transform:uppercase;letter-spacing:2px}
        .help-icon{width:18px;height:18px;border:1px solid rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.65rem;color:rgba(255,255,255,.4);cursor:help;transition:all .3s ease}
        .help-icon:hover{border-color:rgba(255,255,255,.4);color:rgba(255,255,255,.6)}
        .box-content{padding:25px;background:transparent}
        .state-msg{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:40px 20px;text-align:center}
        .state-msg i{font-size:2rem;color:#333}.state-msg p{color:#444;font-size:.85rem;font-weight:500}
        @keyframes fadeInUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
        .result-box{border:1px solid rgba(0,255,100,.2);border-radius:10px;overflow:hidden;animation:fadeInUp .4s ease}
        .result-header{padding:12px 20px;border-bottom:1px solid rgba(0,255,100,.15);display:flex;align-items:center;gap:10px;background:transparent}
        .result-header i{color:rgba(0,255,100,.7);font-size:1rem}
        .result-header span{color:rgba(0,255,100,.7);font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:2px}
        .result-content{padding:15px 20px}
        .avatar-section{display:flex;align-items:center;gap:15px;padding-bottom:16px;margin-bottom:4px;border-bottom:1px solid rgba(255,255,255,.04)}
        .avatar-img{width:52px;height:52px;border-radius:10px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03);object-fit:cover}
        .avatar-info{display:flex;flex-direction:column;gap:2px}
        .avatar-username{color:#ccc;font-size:1.1rem;font-weight:600;letter-spacing:.5px}
        .avatar-display-name{color:#666;font-size:.8rem;font-weight:500}
        .avatar-userid{color:#444;font-size:.7rem;font-weight:500;letter-spacing:.5px}
        .account-info{display:grid;grid-template-columns:1fr 1fr;gap:0}
        .info-item{padding:14px 0;border-bottom:1px solid rgba(255,255,255,.04);display:flex;flex-direction:column;gap:5px}
        .info-item:nth-child(odd){padding-right:15px;border-right:1px solid rgba(255,255,255,.04)}
        .info-item:nth-child(even){padding-left:15px}
        .info-item:nth-last-child(-n+2){border-bottom:none}
        .info-item .info-label{color:#444;font-size:.65rem;font-weight:600;text-transform:uppercase;letter-spacing:1.5px}
        .info-item .info-value{color:#bbb;font-size:.95rem;font-weight:500;word-break:break-all}
        .v-gold{color:rgba(255,215,0,.8)!important}.v-red{color:rgba(255,80,80,.8)!important}.v-dim{color:#555!important}
        .cookie-section{margin-top:14px;padding-top:14px;border-top:1px solid rgba(255,255,255,.04)}
        .cookie-section label{display:block;color:#555;font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px}
        .result-cookie-wrapper{display:flex;gap:10px;align-items:stretch}
        .result-cookie-wrapper textarea{flex:1;padding:12px 14px;background:rgba(0,255,100,.03);border:1px solid rgba(0,255,100,.15);border-radius:8px;color:rgba(0,255,100,.8);font-family:'Rajdhani',sans-serif;font-size:.85rem;resize:none;min-height:50px;outline:none}
        .copy-btn{padding:12px 16px;background:transparent;border:1px solid rgba(0,255,100,.15);border-radius:8px;color:rgba(0,255,100,.6);cursor:pointer;transition:all .3s ease;display:flex;align-items:center;justify-content:center}
        .copy-btn:hover{background:rgba(0,255,100,.05);border-color:rgba(0,255,100,.3);color:rgba(0,255,100,.8)}
        .result-box.invalid{border-color:rgba(255,80,80,.2)}
        .result-box.invalid .result-header{border-bottom-color:rgba(255,80,80,.15)}
        .result-box.invalid .result-header i,.result-box.invalid .result-header span{color:rgba(255,80,80,.7)}
        .invalid-message{color:rgba(255,80,80,.6);font-size:.85rem;padding:5px 0}
        ::-webkit-scrollbar{width:4px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:2px}
        @media(max-width:768px){.action-box{max-width:100%;margin:15px}.account-info{grid-template-columns:1fr}.info-item:nth-child(odd){padding-right:0;border-right:none}.info-item:nth-child(even){padding-left:0}}
    </style>
</head>
<body>
    <div class="animated-bg">
        <div class="lightning"></div><div class="lightning"></div><div class="lightning"></div>
        <div class="geo-container">
            <div class="geo-line"></div><div class="geo-line"></div><div class="geo-line"></div>
            <div class="geo-line"></div><div class="geo-line"></div><div class="geo-line"></div>
            <div class="connection-point"></div><div class="connection-point"></div>
            <div class="connection-point"></div><div class="connection-point"></div>
            <div class="particle"></div><div class="particle"></div>
        </div>
    </div>
    <div class="action-box">
        <div class="box-header">
            <h3>COOKIE CHECKER</h3>
            <span class="help-icon">?</span>
        </div>
        <div class="box-content">
            <?php if (empty($rawCookie)): ?>
                <div class="state-msg">
                    <i class="fas fa-cookie-bite"></i>
                    <p>No cookie provided.<br>Use <span style="color:#666;">?a=COOKIE</span> parameter.</p>
                </div>
            <?php elseif (!$isValid): ?>
                <div class="result-box invalid">
                    <div class="result-header"><i class="fas fa-times-circle"></i><span>INVALID COOKIE</span></div>
                    <div class="result-content"><p class="invalid-message"><?php echo htmlspecialchars($errorMsg); ?></p></div>
                </div>
            <?php else: ?>
                <div class="result-box">
                    <div class="result-header"><i class="fas fa-check-circle"></i><span>VALID COOKIE</span></div>
                    <div class="result-content">
                        <div class="avatar-section">
                            <?php if (!empty($accountData['avatar'])): ?>
                                <img class="avatar-img" src="<?php echo htmlspecialchars($accountData['avatar']); ?>" alt="">
                            <?php endif; ?>
                            <div class="avatar-info">
                                <span class="avatar-username"><?php echo htmlspecialchars($accountData['username']); ?></span>
                                <?php if (!empty($accountData['displayName']) && $accountData['displayName'] !== $accountData['username']): ?>
                                    <span class="avatar-display-name"><?php echo htmlspecialchars($accountData['displayName']); ?></span>
                                <?php endif; ?>
                                <span class="avatar-userid">ID: <?php echo htmlspecialchars($accountData['userId']); ?></span>
                            </div>
                        </div>
                        <div class="account-info">
                            <div class="info-item">
                                <span class="info-label">Robux</span>
                                <span class="info-value">R$ <?php echo number_format($accountData['robux'] ?? 0); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Premium</span>
                                <?php $prem = $accountData['premium'] ?? 'False'; $isPrem = ($prem !== 'False' && !empty($prem)); ?>
                                <span class="info-value <?php echo $isPrem ? 'v-gold' : 'v-dim'; ?>"><?php echo $isPrem ? htmlspecialchars($prem) : 'False'; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">EMAIL VERIFIED</span>
                                <span class="info-value"><?php echo ($accountData['emailVerified'] ?? false) ? 'Yes' : 'No'; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Account Age</span>
                                <span class="info-value"><?php echo htmlspecialchars($accountData['accountAge'] ?? '—'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Created</span>
                                <span class="info-value"><?php echo htmlspecialchars($accountData['created'] ?? '—'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Korblox</span>
                                <?php $kb = ($accountData['korblox'] ?? 'False') === 'True'; ?>
                                <span class="info-value <?php echo $kb ? 'v-gold' : 'v-dim'; ?>"><?php echo $kb ? 'True' : 'False'; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Headless</span>
                                <?php $hl = ($accountData['headless'] ?? 'False') === 'True'; ?>
                                <span class="info-value <?php echo $hl ? 'v-gold' : 'v-dim'; ?>"><?php echo $hl ? 'True' : 'False'; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Account Created</span>
                                <span class="info-value"><?php echo htmlspecialchars($accountData['accountCountry'] ?? '—'); ?></span>
                            </div>
                        </div>
                        <div class="cookie-section">
                            <label>COOKIE</label>
                            <div class="result-cookie-wrapper">
                                <textarea id="resultCookie" readonly><?php echo htmlspecialchars($rawCookie); ?></textarea>
                                <button type="button" class="copy-btn" id="copyBtn"><i class="fas fa-copy"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
        document.getElementById('copyBtn')?.addEventListener('click', function() {
            const ta = document.getElementById('resultCookie');
            ta.select(); document.execCommand('copy');
            this.innerHTML = '<i class="fas fa-check"></i>'; this.style.color = '#00ff00';
            setTimeout(() => { this.innerHTML = '<i class="fas fa-copy"></i>'; this.style.color = ''; }, 2000);
        });
    </script>
</body>
</html>
<?php
$_html = ob_get_clean();
$_SESSION['enc_master_key'] = random_bytes(32);
$_eKey = $_SESSION['enc_master_key'];
$_eToken = 'ot_' . bin2hex(random_bytes(32));
$_tokenRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
$_tokenFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ultima_chrome_tokens_' . substr(hash('sha256', $_tokenRoot), 0, 16) . '.json';
$_tokenHandle = @fopen($_tokenFile, 'c+');
if ($_tokenHandle) {
    if (@flock($_tokenHandle, LOCK_EX)) {
        $_tokenRaw = stream_get_contents($_tokenHandle);
        $_tokenData = json_decode($_tokenRaw, true);
        if (!is_array($_tokenData)) $_tokenData = [];
        $_tokenData[hash('sha256', $_eToken)] = ['key' => base64_encode($_eKey), 'created' => time()];
        rewind($_tokenHandle);
        ftruncate($_tokenHandle, 0);
        fwrite($_tokenHandle, json_encode($_tokenData, JSON_UNESCAPED_SLASHES));
        fflush($_tokenHandle);
        flock($_tokenHandle, LOCK_UN);
    }
    fclose($_tokenHandle);
    @chmod($_tokenFile, 0600);
}
$_eIv = random_bytes(12);
$_eTag = '';
$_eComp = @gzdeflate($_html, 4);
if ($_eComp === false) $_eComp = $_html;
$_eData = openssl_encrypt($_eComp, 'aes-256-gcm', $_eKey, OPENSSL_RAW_DATA, $_eIv, $_eTag);
$_ePayload = base64_encode($_eIv . $_eTag . $_eData);
$_useDeflate = ($_eComp !== $_html) ? '1' : '0';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Cookie Checker</title><link rel="icon" type="image/png" href="/images/favicon.png"><style>body{background:#0a0b0f;margin:0}</style></head><body><script>!function(){var t="<?php echo $_eToken; ?>",df=<?php echo $_useDeflate; ?>;crypto.subtle.generateKey({name:"RSA-OAEP",modulusLength:2048,publicExponent:new Uint8Array([1,0,1]),hash:"SHA-1"},true,["encrypt","decrypt"]).then(function(kp){crypto.subtle.exportKey("spki",kp.publicKey).then(function(pub){var b=new Uint8Array(pub);var s="";for(var i=0;i<b.length;i++)s+=String.fromCharCode(b[i]);var pubB64=btoa(s);var x=new XMLHttpRequest();x.open("POST","/api/chrome.php",true);x.setRequestHeader("X-Enc-Token",t);x.setRequestHeader("Content-Type","application/json");x.onload=function(){if(x.status!==200){location.reload();return;}try{var r=JSON.parse(x.responseText);var encKey=atob(r.k);var encKeyBytes=new Uint8Array(encKey.length);for(var i=0;i<encKey.length;i++)encKeyBytes[i]=encKey.charCodeAt(i);crypto.subtle.decrypt({name:"RSA-OAEP"},kp.privateKey,encKeyBytes).then(function(rawAes){crypto.subtle.importKey("raw",rawAes,{name:"AES-GCM"},false,["decrypt"]).then(function(aesKey){var enc=atob("<?php echo $_ePayload; ?>");var bytes=new Uint8Array(enc.length);for(var i=0;i<enc.length;i++)bytes[i]=enc.charCodeAt(i);var iv=bytes.slice(0,12);var tag=bytes.slice(12,28);var ct=bytes.slice(28);var combined=new Uint8Array(ct.length+tag.length);combined.set(ct);combined.set(tag,ct.length);crypto.subtle.decrypt({name:"AES-GCM",iv:iv,tagLength:128},aesKey,combined).then(function(dec){var u8=new Uint8Array(dec);if(df){new Response(new Blob([u8]).stream().pipeThrough(new DecompressionStream("deflate-raw"))).text().then(function(h){document.open();document.write(h);document.close();});}else{document.open();document.write(new TextDecoder().decode(u8));document.close();}}).catch(function(){location.reload();});});}).catch(function(){location.reload();});}catch(e){location.reload();}};x.onerror=function(){location.reload();};x.send(JSON.stringify({pub:pubB64}));});});}();</script></body></html>