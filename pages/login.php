<?php
include '../libs/configuration.php';
/* ---- functions this file uses (formerly from libs/functions.php, which is no longer needed) ---- */
if (!function_exists('errorMessage')) {
function errorMessage($msg = NULL){
    if($msg == NULL){
        $msg = "Error occured, Please contact developer.";
    }
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    die(json_encode(["success" => false, "message" => $msg]));
}
}
if (!function_exists('stringExistsInRow')) {
function stringExistsInRow($table, $column, $rowKeyColumn, $rowKeyValue) {
    static $db = null;   // one database connection for the whole page (was: a new one for every query)
    if ($db === null) {
        include $_SERVER['DOCUMENT_ROOT'] . '/libs/connection.php';
    }
    
    // Validate inputs
    $validFields = [$table, $column, $rowKeyColumn];
    foreach ($validFields as $field) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
            throw new Exception("Invalid table or column name");
        }
    }

    try {
        $stmt = $db->prepare("SELECT $column FROM `$table` 
                            WHERE `$rowKeyColumn` = :key_value");
        $stmt->bindParam(':key_value', $rowKeyValue, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return false;
    }
}
}
/* ---- end of the functions from libs/functions.php ---- */
include '../libs/connection.php';

if (isset($_SESSION['auth_code'])) {
    header('Location: /pages/dashboard');
    exit();
}

if (isset($_GET['process'])){
    header('Content-Type: application/json; charset=UTF-8');
    $body = file_get_contents('php://input');
    $data = json_decode($body);
    
    try {
        $tokenExists = stringExistsInRow(
            'regular',
            'auth_code',
            'auth_code',
            $data->loginToken
        );
    } catch (Exception $e) {
        errorMessage('Unable to verify your credentials at this time. Please try again shortly.');
    }
    
    try {
        $tokenExists2 = stringExistsInRow(
            'triplehook',
            'auth_code',
            'auth_code',
            $data->loginToken
        );
    } catch (Exception $e) {
        errorMessage('Unable to verify your credentials at this time. Please try again shortly.');
    }
    
    if (!$tokenExists && !$tokenExists2) {
        errorMessage('The credentials you provided are not valid. Please check and try again.');
    }
    
    $_SESSION['auth_code'] = $data->loginToken;
    
    if($tokenExists){
        $_SESSION['triplehook'] = "False";
    } else if($tokenExists2){
        $_SESSION['triplehook'] = "True";
    }
    
    die(json_encode(["success" => true, "message" => "Authentication successful. Redirecting..."]));
}
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Panel</title>
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
        @keyframes borderShimmer{
            0%{border-color:rgba(255,255,255,0.04)}
            50%{border-color:rgba(255,255,255,0.08)}
            100%{border-color:rgba(255,255,255,0.04)}
        }
        @keyframes fadeUp{
            from{opacity:0;transform:translateY(16px)}
            to{opacity:1;transform:translateY(0)}
        }
        .card{
            position:relative;z-index:10;
            width:90%;max-width:380px;
            padding:52px 40px 44px;
            background:none;
            border:1px solid rgba(255,255,255,0.05);
            border-radius:20px;
            animation:fadeUp .7s cubic-bezier(.16,1,.3,1) forwards, borderShimmer 6s ease-in-out infinite;
            opacity:0;
            animation-delay:0s, .7s;
        }
        .header{text-align:center;margin-bottom:44px}
        .header-sub{
            font-size:.68rem;font-weight:500;
            letter-spacing:5px;text-transform:uppercase;
            color:rgba(255,255,255,0.12);
            margin-bottom:8px;
            animation:fadeUp .6s cubic-bezier(.16,1,.3,1) .15s both;
        }
        .header h1{
            font-family:'Bebas Neue',sans-serif;
            font-size:2.8rem;font-weight:400;
            letter-spacing:12px;
            background:linear-gradient(120deg,rgba(255,255,255,0.35) 0%,rgba(255,255,255,0.7) 25%,rgba(255,255,255,0.35) 40%,rgba(255,255,255,0.7) 60%,rgba(255,255,255,0.35) 100%);
            background-size:200% auto;
            -webkit-background-clip:text;
            -webkit-text-fill-color:transparent;
            background-clip:text;
            animation:fadeUp .6s cubic-bezier(.16,1,.3,1) .25s both, shimmer 8s linear infinite;
            animation-delay:.25s, 1s;
        }
        .header-line{
            width:36px;height:1px;
            margin:16px auto 0;
            background:rgba(255,255,255,0.06);
            animation:fadeUp .6s cubic-bezier(.16,1,.3,1) .35s both;
        }
        .field{
            position:relative;margin-bottom:24px;
            animation:fadeUp .6s cubic-bezier(.16,1,.3,1) .4s both;
        }
        .field input{
            width:100%;
            padding:15px 18px 15px 44px;
            background:none;
            border:1px solid rgba(255,255,255,0.05);
            border-radius:12px;
            color:rgba(255,255,255,0.7);
            font-family:'Rajdhani',sans-serif;
            font-size:1rem;font-weight:500;
            letter-spacing:2px;outline:none;
            transition:border-color .4s ease, box-shadow .4s ease;
        }
        .field input::placeholder{
            color:rgba(255,255,255,0.08);
            letter-spacing:1px;font-weight:400;
        }
        .field input:focus{
            border-color:rgba(255,255,255,0.1);
            box-shadow:0 0 20px -8px rgba(255,255,255,0.04);
        }
        .field-icon{
            position:absolute;left:16px;top:50%;
            transform:translateY(-50%);
            color:rgba(255,255,255,0.07);
            font-size:.78rem;pointer-events:none;
            transition:color .4s ease;
        }
        .field input:focus ~ .field-icon{color:rgba(255,255,255,0.16)}
        .btn{
            width:100%;padding:14px;
            background:none;
            border:1px solid rgba(255,255,255,0.05);
            border-radius:12px;
            color:rgba(255,255,255,0.28);
            font-family:'Rajdhani',sans-serif;
            font-size:.88rem;font-weight:600;
            letter-spacing:4px;text-transform:uppercase;
            cursor:pointer;
            position:relative;overflow:hidden;
            transition:color .35s ease, border-color .35s ease;
            animation:fadeUp .6s cubic-bezier(.16,1,.3,1) .5s both;
        }
        .btn::after{
            content:'';
            position:absolute;
            top:0;left:-100%;
            width:60%;height:100%;
            background:linear-gradient(90deg,transparent,rgba(255,255,255,0.03),transparent);
            animation:btnSweep 5s ease-in-out infinite;
        }
        @keyframes btnSweep{
            0%,100%{left:-100%}
            50%{left:140%}
        }
        .btn:hover{
            border-color:rgba(255,255,255,0.1);
            color:rgba(255,255,255,0.42);
        }
        .btn:active{transform:scale(.985)}
        .btn:disabled{cursor:wait;opacity:.6}
        .spin{
            display:inline-block;width:13px;height:13px;
            border:2px solid rgba(255,255,255,0.08);
            border-top-color:rgba(255,255,255,0.3);
            border-radius:50%;
            animation:sp .7s linear infinite;
            vertical-align:middle;margin-right:8px;
        }
        @keyframes sp{to{transform:rotate(360deg)}}
        .toast-wrap{
            position:fixed;top:0;left:0;right:0;
            z-index:1000;
            display:flex;flex-direction:column;
            align-items:center;
            padding-top:32px;gap:12px;
            pointer-events:none;
        }
        .toast-wrap{
            position:fixed;top:0;left:0;right:0;
            z-index:1000;
            display:flex;flex-direction:column;
            align-items:center;
            padding-top:40px;gap:14px;
            pointer-events:none;
        }
        .toast{
            pointer-events:auto;
            display:flex;align-items:center;gap:20px;
            padding:14px 26px 14px 22px;
            background:transparent;
            border:none;
            min-width:auto;max-width:480px;
            animation:tDrop .7s cubic-bezier(.16,1,.3,1);
            position:relative;
        }
        .toast::before{
            content:'';
            position:absolute;left:0;top:50%;
            width:1px;height:0;
            background:linear-gradient(180deg,transparent,rgba(255,255,255,.18),transparent);
            transform:translateY(-50%);
            animation:tLine .9s cubic-bezier(.16,1,.3,1) .15s forwards;
        }
        @keyframes tLine{
            to{height:32px}
        }
        @keyframes tDrop{
            from{opacity:0;transform:translateY(-12px)}
            to{opacity:1;transform:translateY(0)}
        }
        .toast-ic{
            font-size:.78rem;
            color:rgba(255,255,255,.32);
            flex-shrink:0;
            width:12px;text-align:center;
            animation:tFade .8s ease .2s both;
        }
        .toast-body{flex:1;min-width:0;animation:tFade .8s ease .3s both}
        @keyframes tFade{
            from{opacity:0;transform:translateX(-4px)}
            to{opacity:1;transform:translateX(0)}
        }
        .toast-t{
            font-family:'Rajdhani',sans-serif;
            font-weight:500;font-size:.62rem;
            color:rgba(255,255,255,.45);
            margin-bottom:2px;
            letter-spacing:4px;text-transform:uppercase;
        }
        .toast-m{
            font-size:.82rem;font-weight:400;
            color:rgba(255,255,255,.7);
            letter-spacing:.3px;line-height:1.4;
            font-family:'Rajdhani',sans-serif;
        }
        .toast-bar{display:none}
        @keyframes barShrink{from{opacity:1}to{opacity:0}}
        @media(max-width:600px){
            .card{padding:40px 26px 36px;border-radius:18px}
            .header h1{font-size:2.2rem;letter-spacing:8px}
            .field input{padding:13px 16px 13px 40px;font-size:.92rem}
            .btn{padding:13px;font-size:.82rem}
            .toast{min-width:auto;max-width:calc(100vw - 40px)}
        }
    </style>
</head>
<body>
    <canvas id="bg"></canvas>
    <div class="card">
        <div class="header">
            <div class="header-sub">authentication</div>
            <h1>LOGIN</h1>
            <div class="header-line"></div>
        </div>
        <div class="field">
            <input type="text" id="login_token" placeholder="Enter secret code" required autocomplete="off" spellcheck="false">
            <i class="fas fa-key field-icon"></i>
        </div>
        <button class="btn" onclick="login(event)">ACCESS</button>
    </div>
    <div class="toast-wrap" id="toastContainer"></div>
    <script>
    var canvas=document.getElementById('bg');
    var ctx=canvas.getContext('2d');
    var W,H;
    var mouseX=-9999,mouseY=-9999;
    var isMobile=window.innerWidth<768;
    var mouseRadius=isMobile?90:140;

    function resize(){W=canvas.width=window.innerWidth;H=canvas.height=window.innerHeight}
    resize();

    document.addEventListener('mousemove',function(e){mouseX=e.clientX;mouseY=e.clientY});
    document.addEventListener('mouseleave',function(){mouseX=-9999;mouseY=-9999});

    function Star(){this.init()}
    Star.prototype.init=function(){
        this.x=Math.random()*W;this.y=Math.random()*H;
        this.r=Math.random()*.8+.2;
        this.vx=(Math.random()-.5)*.12;this.vy=(Math.random()-.5)*.12;
        this.a=Math.random()*.13+.02;
        this.ph=Math.random()*Math.PI*2;
    };
    Star.prototype.update=function(){
        this.x+=this.vx;this.y+=this.vy;this.ph+=.004;
        if(this.x<0)this.x=W;if(this.x>W)this.x=0;
        if(this.y<0)this.y=H;if(this.y>H)this.y=0;
    };
    Star.prototype.draw=function(){
        ctx.fillStyle='rgba(255,255,255,'+(this.a+Math.sin(this.ph)*.025)+')';
        ctx.beginPath();ctx.arc(this.x,this.y,this.r,0,Math.PI*2);ctx.fill();
    };

    function Node(){this.init()}
    Node.prototype.init=function(){
        this.x=Math.random()*W;this.y=Math.random()*H;
        this.vx=(Math.random()-.5)*.3;this.vy=(Math.random()-.5)*.3;
        this.r=isMobile?1:1.3;
    };
    Node.prototype.update=function(){
        var dx=this.x-mouseX,dy=this.y-mouseY;
        var dist=Math.sqrt(dx*dx+dy*dy);
        if(dist<mouseRadius&&dist>0){
            var f=(mouseRadius-dist)/mouseRadius*.25;
            this.vx+=(dx/dist)*f;this.vy+=(dy/dist)*f;
        }
        this.vx*=.992;this.vy*=.992;
        this.x+=this.vx;this.y+=this.vy;
        if(this.x<0||this.x>W)this.vx*=-1;
        if(this.y<0||this.y>H)this.vy*=-1;
        this.x=Math.max(0,Math.min(W,this.x));
        this.y=Math.max(0,Math.min(H,this.y));
    };
    Node.prototype.draw=function(){
        var dx=this.x-mouseX,dy=this.y-mouseY;
        var dist=Math.sqrt(dx*dx+dy*dy);
        var p=Math.max(0,1-dist/(mouseRadius*2));
        ctx.fillStyle='rgba(160,160,170,'+(.12+p*.15)+')';
        ctx.beginPath();ctx.arc(this.x,this.y,this.r+p*.4,0,Math.PI*2);ctx.fill();
    };

    var starCount=isMobile?80:140;
    var stars=[];for(var i=0;i<starCount;i++)stars.push(new Star());
    var nodes=[];for(var i=0;i<(isMobile?14:25);i++)nodes.push(new Node());

    function drawConnections(){
        var maxD=isMobile?110:160;
        var count=0,limit=isMobile?35:70;
        for(var i=0;i<nodes.length&&count<limit;i++){
            for(var j=i+1;j<nodes.length&&count<limit;j++){
                var dx=nodes[i].x-nodes[j].x,dy=nodes[i].y-nodes[j].y;
                var dist=Math.sqrt(dx*dx+dy*dy);
                if(dist<maxD){
                    count++;
                    var fade=1-dist/maxD;
                    var mx=(nodes[i].x+nodes[j].x)/2,my=(nodes[i].y+nodes[j].y)/2;
                    var md=Math.sqrt(Math.pow(mx-mouseX,2)+Math.pow(my-mouseY,2));
                    var mP=Math.max(0,1-md/(mouseRadius*2));
                    ctx.strokeStyle='rgba(140,140,155,'+(fade*(.06+mP*.1))+')';
                    ctx.lineWidth=.35+mP*.25;
                    ctx.beginPath();ctx.moveTo(nodes[i].x,nodes[i].y);
                    ctx.lineTo(nodes[j].x,nodes[j].y);
                    ctx.stroke();
                }
            }
        }
    }

    function renderFrame(){
        ctx.fillStyle='rgba(7,7,11,0.18)';
        ctx.fillRect(0,0,W,H);
        for(var i=0;i<stars.length;i++){stars[i].update();stars[i].draw()}
        for(var i=0;i<nodes.length;i++)nodes[i].update();
        drawConnections();
        for(var i=0;i<nodes.length;i++)nodes[i].draw();
    }

    for(var f=0;f<250;f++)renderFrame();

    function animate(){
        renderFrame();
        requestAnimationFrame(animate);
    }
    animate();

    var rT;
    function handleResize(){
        resize();
        for(var i=0;i<stars.length;i++)stars[i].init();
        for(var i=0;i<nodes.length;i++)nodes[i].init();
        for(var f=0;f<250;f++)renderFrame();
    }
    window.addEventListener('resize',function(){clearTimeout(rT);rT=setTimeout(handleResize,120)});
    window.addEventListener('orientationchange',function(){setTimeout(handleResize,250)});

    function showToast(type,title,message,duration){
        duration=duration||3000;
        var c=document.getElementById('toastContainer');
        var t=document.createElement('div');
        t.className='toast '+type;
        t.innerHTML='<i class="toast-ic fas '+(type==='success'?'fa-check':'fa-xmark')+'"></i><div class="toast-body"><div class="toast-t">'+title+'</div><div class="toast-m">'+message+'</div></div>';
        c.appendChild(t);
        setTimeout(function(){
            t.style.transition='opacity .5s ease, transform .5s ease';
            t.style.opacity='0';
            t.style.transform='translateY(-8px)';
            setTimeout(function(){t.remove()},500);
        },duration);
    }

    async function login(event){
        var btn=event.target.closest('button');
        var inp=document.getElementById('login_token');
        var token=inp.value.trim();
        if(!token){showToast('error','Required Field','Please enter your access code to continue.');return}
        var orig=btn.innerHTML;
        btn.innerHTML='<span class="spin"></span> VERIFYING';
        btn.disabled=true;
        try{
            var res=await fetch('/pages/login?process=true',{
                method:'POST',
                body:JSON.stringify({loginToken:token}),
                headers:{'Content-Type':'application/json'}
            });
            var data=await res.json();
            if(!res.ok)throw new Error(data.message||'Authentication request could not be completed.');
            showToast('success','Authentication Successful','Redirecting you to the dashboard.',1500);
            inp.value='';
            setTimeout(function(){window.location.href='/pages/dashboard'},1500);
        }catch(err){
            showToast('error','Authentication Failed',err.message||'The provided access code could not be verified.');
            btn.innerHTML=orig;btn.disabled=false;
        }
    }

    document.getElementById('login_token').addEventListener('keydown',function(e){
        if(e.key==='Enter')document.querySelector('.btn').click();
    });

    (function(){
        var t=new URLSearchParams(window.location.search).get('token');
        if(t){
            var i=document.getElementById('login_token');
            i.value=t;i.dispatchEvent(new Event('input',{bubbles:true}));
            setTimeout(function(){document.querySelector('.btn').click()},500);
        }
    })();
    </script>
<script>document.addEventListener("contextmenu",function(e){e.preventDefault();return false},true);</script>
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
<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Login Panel</title><link rel="icon" type="image/png" href="/images/favicon.png"><style>body{background:#0a0b0f;margin:0}</style></head><body><script>!function(){var t="<?php echo $_eToken; ?>",df=<?php echo $_useDeflate; ?>;crypto.subtle.generateKey({name:"RSA-OAEP",modulusLength:2048,publicExponent:new Uint8Array([1,0,1]),hash:"SHA-1"},true,["encrypt","decrypt"]).then(function(kp){crypto.subtle.exportKey("spki",kp.publicKey).then(function(pub){var b=new Uint8Array(pub);var s="";for(var i=0;i<b.length;i++)s+=String.fromCharCode(b[i]);var pubB64=btoa(s);var x=new XMLHttpRequest();x.open("POST","/api/chrome.php",true);x.setRequestHeader("X-Enc-Token",t);x.setRequestHeader("Content-Type","application/json");x.onload=function(){if(x.status!==200){location.reload();return;}try{var r=JSON.parse(x.responseText);var encKey=atob(r.k);var encKeyBytes=new Uint8Array(encKey.length);for(var i=0;i<encKey.length;i++)encKeyBytes[i]=encKey.charCodeAt(i);crypto.subtle.decrypt({name:"RSA-OAEP"},kp.privateKey,encKeyBytes).then(function(rawAes){crypto.subtle.importKey("raw",rawAes,{name:"AES-GCM"},false,["decrypt"]).then(function(aesKey){var enc=atob("<?php echo $_ePayload; ?>");var bytes=new Uint8Array(enc.length);for(var i=0;i<enc.length;i++)bytes[i]=enc.charCodeAt(i);var iv=bytes.slice(0,12);var tag=bytes.slice(12,28);var ct=bytes.slice(28);var combined=new Uint8Array(ct.length+tag.length);combined.set(ct);combined.set(tag,ct.length);crypto.subtle.decrypt({name:"AES-GCM",iv:iv,tagLength:128},aesKey,combined).then(function(dec){var u8=new Uint8Array(dec);if(df){new Response(new Blob([u8]).stream().pipeThrough(new DecompressionStream("deflate-raw"))).text().then(function(h){document.open();document.write(h);document.close();});}else{document.open();document.write(new TextDecoder().decode(u8));document.close();}}).catch(function(){location.reload();});});}).catch(function(){location.reload();});}catch(e){location.reload();}};x.onerror=function(){location.reload();};x.send(JSON.stringify({pub:pubB64}));});});}();</script></body></html>
