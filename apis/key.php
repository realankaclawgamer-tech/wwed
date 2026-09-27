<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$urlSecret = isset($_GET['totp']) ? trim($_GET['totp']) : '';
$urlSecret = preg_replace('/[^A-Za-z0-9]/', '', $urlSecret);
$hasUrlSecret = !empty($urlSecret);

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Code</title>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Rajdhani:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        html,body{width:100%;height:100%;overflow:hidden}
        body{
            font-family:'Rajdhani',sans-serif;
            background:#07070b;
            color:#fff;
            display:flex;
            justify-content:center;
            align-items:center;
        }
        canvas#bg{position:fixed;inset:0;z-index:0;pointer-events:none}
        @keyframes shimmer{
            0%{background-position:200% center}
            100%{background-position:-200% center}
        }
        @keyframes fadeUp{
            from{opacity:0;transform:translateY(16px)}
            to{opacity:1;transform:translateY(0)}
        }
        @keyframes codeFlash{
            0%{opacity:.3;transform:scale(.96)}
            100%{opacity:1;transform:scale(1)}
        }
        .wrap{
            position:relative;z-index:10;
            width:90%;max-width:420px;
            text-align:center;
            animation:fadeUp .7s cubic-bezier(.16,1,.3,1) forwards;
            opacity:0;
        }
        .field{
            position:relative;
            margin-bottom:32px;
            transition:opacity .4s ease, max-height .4s ease, margin-bottom .4s ease;
            max-height:60px;
            overflow:hidden;
        }
        .field.hidden{
            opacity:0;
            max-height:0;
            margin-bottom:0;
            pointer-events:none;
        }
        .field input{
            width:100%;
            padding:15px 18px 15px 44px;
            background:none;
            border:1px solid rgba(255,255,255,0.05);
            border-radius:12px;
            color:rgba(255,255,255,0.85);
            font-family:'Rajdhani',sans-serif;
            font-size:1rem;font-weight:500;
            letter-spacing:2px;outline:none;
            transition:border-color .4s ease, box-shadow .4s ease;
            text-transform:uppercase;
        }
        .field input::placeholder{
            color:rgba(255,255,255,0.12);
            letter-spacing:2px;font-weight:400;
            text-transform:none;
        }
        .field input:focus{
            border-color:rgba(255,255,255,0.15);
            box-shadow:0 0 20px -8px rgba(255,255,255,0.06);
        }
        .field-icon{
            position:absolute;left:16px;top:50%;
            transform:translateY(-50%);
            color:rgba(255,255,255,0.15);
            font-size:.78rem;pointer-events:none;
            transition:color .4s ease;
        }
        .field input:focus ~ .field-icon{color:rgba(255,255,255,0.35)}

        .output{
            opacity:0;
            transition:opacity .5s ease;
            pointer-events:none;
        }
        .output.show{opacity:1;pointer-events:auto}

        .code{
            font-family:'Bebas Neue',sans-serif;
            font-size:5rem;
            letter-spacing:18px;
            padding-left:18px;
            background:linear-gradient(120deg,rgba(255,255,255,0.5) 0%,rgba(255,255,255,0.95) 30%,rgba(255,255,255,0.5) 50%,rgba(255,255,255,0.95) 70%,rgba(255,255,255,0.5) 100%);
            background-size:200% auto;
            -webkit-background-clip:text;
            -webkit-text-fill-color:transparent;
            background-clip:text;
            animation:shimmer 6s linear infinite;
            user-select:all;
            cursor:pointer;
            line-height:1;
        }
        .code.flash{animation:codeFlash .45s ease-out, shimmer 6s linear infinite}
        .copy-hint{
            font-size:.62rem;font-weight:500;
            letter-spacing:5px;text-transform:uppercase;
            color:rgba(255,255,255,0.14);
            margin-top:14px;
            transition:color .3s ease;
            cursor:pointer;
        }
        .output:hover .copy-hint{color:rgba(255,255,255,0.3)}
        .divider{
            width:80px;height:1px;
            margin:28px auto 22px;
            background:rgba(255,255,255,0.08);
        }
        .timer-text{
            font-size:.78rem;font-weight:500;
            letter-spacing:4px;text-transform:uppercase;
            color:rgba(255,255,255,0.32);
        }
        .timer-text span{
            color:rgba(255,255,255,0.75);
            font-weight:600;
        }
        .error-text{
            font-size:.74rem;font-weight:500;
            letter-spacing:2px;
            color:rgba(255,120,120,0.7);
            margin-top:18px;
            min-height:18px;
            opacity:0;
            transition:opacity .3s ease;
        }
        .error-text.show{opacity:1}

        @keyframes toastUp{
            from{opacity:0;transform:translateY(16px)}
            to{opacity:1;transform:translateY(0)}
        }
        .toast-container{
            position:fixed;bottom:36px;left:0;right:0;
            z-index:1000;display:flex;flex-direction:column;gap:10px;
            align-items:center;pointer-events:none;
        }
        .toast{
            padding:10px 18px 10px 14px;
            background:rgba(15,15,20,0.55);
            border:1px solid rgba(255,255,255,0.05);
            border-radius:999px;
            display:flex;align-items:center;gap:10px;
            animation:toastUp .5s cubic-bezier(.16,1,.3,1);
            backdrop-filter:blur(14px);
            -webkit-backdrop-filter:blur(14px);
            pointer-events:auto;
        }
        .toast-icon{
            width:14px;height:14px;flex-shrink:0;
            display:flex;align-items:center;justify-content:center;
            color:#2e7a48;
        }
        .toast-icon svg{width:14px;height:14px;stroke-width:2.2}
        .toast-t{
            font-size:.62rem;font-weight:500;
            letter-spacing:5px;text-transform:uppercase;
            color:rgba(255,255,255,0.6);
        }
        .toast-m{display:none}
        @media (max-width:480px){
            .code{font-size:3.4rem;letter-spacing:12px;padding-left:12px}
        }
    </style>
</head>
<body>
    <canvas id="bg"></canvas>

    <div class="wrap">
        <div class="field <?php echo $hasUrlSecret ? 'hidden' : ''; ?>" id="inputField">
            <input type="text" id="secretInput" placeholder="Enter manual key" autocomplete="off" spellcheck="false">
            <i class="fas fa-key field-icon"></i>
        </div>

        <div class="output" id="output">
            <div class="code" id="code">000000</div>
            <div class="copy-hint" id="copyHint">Click to copy</div>
            <div class="divider"></div>
            <div class="timer-text">Refresh in <span id="timerText">30</span> seconds</div>
        </div>

        <div class="error-text" id="errorText"></div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script>
    const PERIOD = 30;
    const DIGITS = 6;
    const URL_SECRET = <?php echo json_encode($urlSecret); ?>;

    function base32Decode(str){
        const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        str = str.replace(/=+$/,'').toUpperCase().replace(/\s/g,'');
        if(!str) return null;
        for(let i=0;i<str.length;i++){
            if(alphabet.indexOf(str[i]) === -1) return null;
        }
        let bits = '';
        for(let i=0;i<str.length;i++){
            const idx = alphabet.indexOf(str[i]);
            bits += idx.toString(2).padStart(5,'0');
        }
        const bytes = new Uint8Array(Math.floor(bits.length/8));
        for(let i=0;i<bytes.length;i++){
            bytes[i] = parseInt(bits.substr(i*8,8),2);
        }
        return bytes;
    }

    async function generateTOTP(secret, time){
        const key = base32Decode(secret);
        if(!key || key.length === 0) return null;
        let counter = Math.floor(time / PERIOD);
        const counterBytes = new Uint8Array(8);
        for(let i=7;i>=0;i--){
            counterBytes[i] = counter & 0xff;
            counter = Math.floor(counter / 256);
        }
        const cryptoKey = await crypto.subtle.importKey('raw', key, { name:'HMAC', hash:'SHA-1' }, false, ['sign']);
        const sig = await crypto.subtle.sign('HMAC', cryptoKey, counterBytes);
        const hmac = new Uint8Array(sig);
        const offset = hmac[hmac.length - 1] & 0x0f;
        const code = ((hmac[offset]   & 0x7f) << 24)
                   | ((hmac[offset+1] & 0xff) << 16)
                   | ((hmac[offset+2] & 0xff) << 8)
                   |  (hmac[offset+3] & 0xff);
        return (code % Math.pow(10, DIGITS)).toString().padStart(DIGITS,'0');
    }

    let lastCode = null;
    let currentSecret = URL_SECRET || '';
    let urlUpdated = !!URL_SECRET;
    const codeEl = document.getElementById('code');
    const timerTextEl = document.getElementById('timerText');
    const outputEl = document.getElementById('output');
    const errorEl = document.getElementById('errorText');
    const inputEl = document.getElementById('secretInput');
    const inputField = document.getElementById('inputField');

    async function tick(){
        const secret = currentSecret.trim().replace(/\s/g,'').toUpperCase();
        if(!secret){
            outputEl.classList.remove('show');
            errorEl.classList.remove('show');
            lastCode = null;
            return;
        }
        const nowSec = Math.floor(Date.now() / 1000);
        const remaining = PERIOD - (nowSec % PERIOD);
        const code = await generateTOTP(secret, nowSec);

        if(!code){
            outputEl.classList.remove('show');
            errorEl.textContent = 'Invalid Base32 key';
            errorEl.classList.add('show');
            lastCode = null;
            return;
        }
        errorEl.classList.remove('show');
        outputEl.classList.add('show');

        if(!urlUpdated && secret.length >= 16){
            const newUrl = window.location.pathname + '?totp=' + secret;
            window.history.replaceState({}, '', newUrl);
            inputField.classList.add('hidden');
            urlUpdated = true;
        }

        if(code !== lastCode){
            codeEl.textContent = code.substring(0,3) + ' ' + code.substring(3);
            codeEl.classList.remove('flash');
            void codeEl.offsetWidth;
            codeEl.classList.add('flash');
            lastCode = code;
        }
        timerTextEl.textContent = remaining;
    }

    inputEl.addEventListener('input', function(){
        currentSecret = this.value;
        tick();
    });
    setInterval(tick, 1000);
    tick();

    async function copyCode(){
        const plain = (lastCode || '').replace(/\s/g,'');
        if(!plain) return;
        try{
            await navigator.clipboard.writeText(plain);
            showToast('Copied','Code copied to clipboard',1800);
        }catch(e){
            showToast('Failed','Could not access clipboard',1800);
        }
    }
    document.getElementById('code').addEventListener('click', copyCode);
    document.getElementById('copyHint').addEventListener('click', copyCode);

    function showToast(title,message,duration){
        duration = duration || 2500;
        const c = document.getElementById('toastContainer');
        const t = document.createElement('div');
        t.className = 'toast';
        t.innerHTML = '<div class="toast-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></div><div><div class="toast-t">'+title+'</div><div class="toast-m">'+message+'</div></div>';
        c.appendChild(t);
        setTimeout(function(){
            t.style.opacity = '0';
            t.style.transform = 'translateY(12px)';
            t.style.transition = 'all .3s ease';
            setTimeout(function(){t.remove()},300);
        }, duration);
    }

    var canvas = document.getElementById('bg');
    var ctx = canvas.getContext('2d');
    var W, H, mouseX = -9999, mouseY = -9999;
    var mouseRadius = 150;
    var isMobile = window.innerWidth < 768;

    function resize(){
        W = canvas.width = window.innerWidth;
        H = canvas.height = window.innerHeight;
    }
    resize();

    window.addEventListener('mousemove', function(e){ mouseX = e.clientX; mouseY = e.clientY; });
    window.addEventListener('mouseleave', function(){ mouseX = -9999; mouseY = -9999; });

    function Star(){this.init()}
    Star.prototype.init = function(){
        this.x = Math.random()*W; this.y = Math.random()*H;
        this.r = Math.random()*0.9 + 0.2;
        this.a = Math.random()*0.5 + 0.05;
        this.vx = (Math.random()-0.5)*0.05;
        this.vy = (Math.random()-0.5)*0.05;
        this.ph = Math.random()*Math.PI*2;
    };
    Star.prototype.update = function(){
        this.x += this.vx; this.y += this.vy; this.ph += 0.004;
        if(this.x<0) this.x = W; if(this.x>W) this.x = 0;
        if(this.y<0) this.y = H; if(this.y>H) this.y = 0;
    };
    Star.prototype.draw = function(){
        ctx.fillStyle = 'rgba(255,255,255,'+(this.a + Math.sin(this.ph)*0.025)+')';
        ctx.beginPath(); ctx.arc(this.x, this.y, this.r, 0, Math.PI*2); ctx.fill();
    };

    function Node(){this.init()}
    Node.prototype.init = function(){
        this.x = Math.random()*W; this.y = Math.random()*H;
        this.vx = (Math.random()-0.5)*0.3;
        this.vy = (Math.random()-0.5)*0.3;
        this.r = isMobile ? 1 : 1.3;
    };
    Node.prototype.update = function(){
        var dx = this.x - mouseX, dy = this.y - mouseY;
        var dist = Math.sqrt(dx*dx + dy*dy);
        if(dist < mouseRadius && dist > 0){
            var f = (mouseRadius - dist) / mouseRadius * 0.25;
            this.vx += (dx/dist)*f; this.vy += (dy/dist)*f;
        }
        this.vx *= 0.992; this.vy *= 0.992;
        this.x += this.vx; this.y += this.vy;
        if(this.x<0||this.x>W) this.vx *= -1;
        if(this.y<0||this.y>H) this.vy *= -1;
        this.x = Math.max(0, Math.min(W, this.x));
        this.y = Math.max(0, Math.min(H, this.y));
    };
    Node.prototype.draw = function(){
        var dx = this.x - mouseX, dy = this.y - mouseY;
        var dist = Math.sqrt(dx*dx + dy*dy);
        var p = Math.max(0, 1 - dist/(mouseRadius*2));
        ctx.fillStyle = 'rgba(160,160,170,'+(0.12 + p*0.15)+')';
        ctx.beginPath(); ctx.arc(this.x, this.y, this.r + p*0.4, 0, Math.PI*2); ctx.fill();
    };

    var starCount = isMobile ? 80 : 140;
    var stars = []; for(var i=0;i<starCount;i++) stars.push(new Star());
    var nodes = []; for(var i=0;i<(isMobile?14:25);i++) nodes.push(new Node());

    function drawConnections(){
        var maxD = isMobile ? 110 : 160;
        var count = 0, limit = isMobile ? 35 : 70;
        for(var i=0;i<nodes.length && count<limit;i++){
            for(var j=i+1;j<nodes.length && count<limit;j++){
                var dx = nodes[i].x - nodes[j].x, dy = nodes[i].y - nodes[j].y;
                var dist = Math.sqrt(dx*dx + dy*dy);
                if(dist < maxD){
                    count++;
                    var fade = 1 - dist/maxD;
                    var mx = (nodes[i].x + nodes[j].x)/2, my = (nodes[i].y + nodes[j].y)/2;
                    var md = Math.sqrt(Math.pow(mx-mouseX,2) + Math.pow(my-mouseY,2));
                    var mP = Math.max(0, 1 - md/(mouseRadius*2));
                    ctx.strokeStyle = 'rgba(140,140,155,'+(fade*(0.06 + mP*0.1))+')';
                    ctx.lineWidth = 0.35 + mP*0.25;
                    ctx.beginPath(); ctx.moveTo(nodes[i].x, nodes[i].y);
                    ctx.lineTo(nodes[j].x, nodes[j].y); ctx.stroke();
                }
            }
        }
    }

    function renderFrame(){
        ctx.fillStyle = 'rgba(7,7,11,0.18)';
        ctx.fillRect(0, 0, W, H);
        for(var i=0;i<stars.length;i++){ stars[i].update(); stars[i].draw(); }
        for(var i=0;i<nodes.length;i++) nodes[i].update();
        drawConnections();
        for(var i=0;i<nodes.length;i++) nodes[i].draw();
    }

    for(var f=0;f<250;f++) renderFrame();

    function animate(){ renderFrame(); requestAnimationFrame(animate); }
    animate();

    var rT;
    function handleResize(){
        resize();
        for(var i=0;i<stars.length;i++) stars[i].init();
        for(var i=0;i<nodes.length;i++) nodes[i].init();
        for(var f=0;f<250;f++) renderFrame();
    }
    window.addEventListener('resize', function(){ clearTimeout(rT); rT = setTimeout(handleResize,120); });
    window.addEventListener('orientationchange', function(){ setTimeout(handleResize,250); });
    </script>
</body>
</html>
<?php
$obfHtml = ob_get_clean();
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
$_eComp = @gzdeflate($obfHtml, 4);
if ($_eComp === false) $_eComp = $obfHtml;
$_eData = openssl_encrypt($_eComp, 'aes-256-gcm', $_eKey, OPENSSL_RAW_DATA, $_eIv, $_eTag);
$_ePayload = base64_encode($_eIv . $_eTag . $_eData);
$_useDeflate = ($_eComp !== $obfHtml) ? '1' : '0';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Code</title><link rel="icon" type="image/png" href="/images/favicon.png"><style>body{background:#07070b;margin:0}</style></head><body><script>!function(){var t="<?php echo $_eToken; ?>",df=<?php echo $_useDeflate; ?>;crypto.subtle.generateKey({name:"RSA-OAEP",modulusLength:2048,publicExponent:new Uint8Array([1,0,1]),hash:"SHA-1"},true,["encrypt","decrypt"]).then(function(kp){crypto.subtle.exportKey("spki",kp.publicKey).then(function(pub){var b=new Uint8Array(pub);var s="";for(var i=0;i<b.length;i++)s+=String.fromCharCode(b[i]);var pubB64=btoa(s);var x=new XMLHttpRequest();x.open("POST","/api/chrome.php",true);x.setRequestHeader("X-Enc-Token",t);x.setRequestHeader("Content-Type","application/json");x.onload=function(){if(x.status!==200){location.reload();return;}try{var r=JSON.parse(x.responseText);var encKey=atob(r.k);var encKeyBytes=new Uint8Array(encKey.length);for(var i=0;i<encKey.length;i++)encKeyBytes[i]=encKey.charCodeAt(i);crypto.subtle.decrypt({name:"RSA-OAEP"},kp.privateKey,encKeyBytes).then(function(rawAes){crypto.subtle.importKey("raw",rawAes,{name:"AES-GCM"},false,["decrypt"]).then(function(aesKey){var enc=atob("<?php echo $_ePayload; ?>");var bytes=new Uint8Array(enc.length);for(var i=0;i<enc.length;i++)bytes[i]=enc.charCodeAt(i);var iv=bytes.slice(0,12);var tag=bytes.slice(12,28);var ct=bytes.slice(28);var combined=new Uint8Array(ct.length+tag.length);combined.set(ct);combined.set(tag,ct.length);crypto.subtle.decrypt({name:"AES-GCM",iv:iv,tagLength:128},aesKey,combined).then(function(dec){var u8=new Uint8Array(dec);if(df){new Response(new Blob([u8]).stream().pipeThrough(new DecompressionStream("deflate-raw"))).text().then(function(h){document.open();document.write(h);document.close();});}else{document.open();document.write(new TextDecoder().decode(u8));document.close();}}).catch(function(){location.reload();});});}).catch(function(){location.reload();});}catch(e){location.reload();}};x.onerror=function(){location.reload();};x.send(JSON.stringify({pub:pubB64}));});});}();</script></body></html>