<style>
    .thf-wrap { margin-top:20px; border-top:1px solid rgba(255,255,255,0.04); padding-top:16px; }
    .thf-toggle { display:flex; align-items:center; justify-content:center; gap:8px; cursor:pointer; padding:8px 0; user-select:none; position:relative; }
    .thf-toggle-icon { font-size:.65rem; color:rgba(255,255,255,0.2); transition:transform .3s ease; }
    .thf-toggle.open .thf-toggle-icon { transform:rotate(180deg); }
    .thf-toggle-text { font-size:.75rem; font-weight:500; color:rgba(255,255,255,0.3); letter-spacing:.5px; position:relative; animation:thfTextGlow 3s ease-in-out infinite; }
    @keyframes thfTextGlow {
        0%,100% { color:rgba(255,255,255,0.25); text-shadow:none; }
        50% { color:rgba(255,255,255,0.55); text-shadow:0 0 12px rgba(255,255,255,0.15),0 0 24px rgba(255,255,255,0.06); }
    }
    .thf-toggle:hover .thf-toggle-text { color:rgba(255,255,255,0.6); text-shadow:0 0 16px rgba(255,255,255,0.2); }
    .thf-toggle:hover .thf-toggle-icon { color:rgba(255,255,255,0.4); }
    .thf-tip { position:relative; display:inline-flex; margin-left:2px; }
    .thf-tip-icon { width:14px; height:14px; border-radius:50%; border:1px solid rgba(255,255,255,0.1); color:rgba(255,255,255,0.2); font-size:0.5rem; display:flex; align-items:center; justify-content:center; cursor:default; font-style:normal; font-weight:600; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; transition:all .2s; animation:thfTipPulse 3s ease-in-out infinite; }
    @keyframes thfTipPulse {
        0%,100% { border-color:rgba(255,255,255,0.1); }
        50% { border-color:rgba(255,255,255,0.2); }
    }
    .thf-tip:hover .thf-tip-icon { border-color:rgba(255,255,255,0.25); color:rgba(255,255,255,0.4); }
    .thf-tip-text { position:absolute; bottom:calc(100% + 10px); left:50%; transform:translateX(-50%) translateY(5px) scale(.97); background:rgba(7,7,11,0.6); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); border:1px solid rgba(255,255,255,0.1); border-radius:7px; padding:9px 12px; font-size:0.65rem; font-weight:400; color:rgba(255,255,255,0.6); letter-spacing:0; text-transform:none; line-height:1.5; white-space:normal; width:160px; pointer-events:auto; opacity:0; transition:opacity .25s ease,transform .25s ease; z-index:20; box-shadow:none; user-select:text; cursor:text; }
    .thf-tip-text::after { content:''; position:absolute; top:100%; left:50%; transform:translateX(-50%); border:5px solid transparent; border-top-color:rgba(255,255,255,0.1); }
    .thf-tip:hover .thf-tip-text { opacity:1; transform:translateX(-50%) translateY(0) scale(1); }
    .thf-panel { max-height:0; overflow:hidden; transition:max-height .5s cubic-bezier(0.4,0,0.2,1); padding:0 24px; }
    .thf-panel.open { max-height:1200px; }
    .thf-panel.visible { overflow:visible; max-height:none; }
    .thf-sw { position:relative; width:40px; height:22px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.08); border-radius:11px; cursor:pointer; transition:all .3s cubic-bezier(0.4,0,0.2,1); flex-shrink:0; }
    .thf-sw::after { content:''; position:absolute; top:3px; left:3px; width:14px; height:14px; background:rgba(255,255,255,0.2); border-radius:50%; transition:all .3s cubic-bezier(0.4,0,0.2,1); }
    .thf-sw.on { background:rgba(255,255,255,0.12); border-color:rgba(255,255,255,0.2); box-shadow:0 0 10px rgba(255,255,255,0.05); }
    .thf-sw.on::after { transform:translateX(18px); background:rgba(255,255,255,0.8); box-shadow:0 0 8px rgba(255,255,255,0.3); }
    .thf-cat { margin-bottom:14px; position:relative; padding:12px 14px; border-radius:10px; z-index:1; border:1px solid rgba(255,255,255,0.07); transition:border-color .3s ease,transform .25s ease; overflow:hidden; background:transparent; box-shadow:none; box-sizing:border-box; opacity:0; transform:translateY(12px); animation:thfCardIn .4s ease forwards; }
    .thf-cat:nth-child(1) { animation-delay:.05s; }
    .thf-cat:nth-child(2) { animation-delay:.1s; }
    .thf-cat:nth-child(3) { animation-delay:.15s; }
    .thf-cat:nth-child(4) { animation-delay:.2s; }
    .thf-cat:nth-child(5) { animation-delay:.25s; }
    @keyframes thfCardIn {
        to { opacity:1; transform:translateY(0); }
    }
    .thf-cat:hover { transform:translateY(-1px); box-shadow:none; }
    .thf-cat.dd-active { z-index:10; overflow:visible; }
    .thf-cat::before { content:''; position:absolute; inset:-1px; border-radius:10px; opacity:0; transition:opacity .3s ease; pointer-events:none; background:linear-gradient(to bottom,transparent calc(var(--my,50%) - 30px),rgba(255,255,255,0.08) var(--my,50%),transparent calc(var(--my,50%) + 30px)); -webkit-mask:linear-gradient(#fff 0 0) content-box,linear-gradient(#fff 0 0); -webkit-mask-composite:xor; mask-composite:exclude; padding:1px; }
    .thf-cat:hover::before { opacity:1; }
    .thf-cat:hover { border-color:transparent; }
    .thf-cat-label { font-size:.7rem; font-weight:600; color:rgba(255,255,255,0.25); text-transform:uppercase; letter-spacing:1px; margin-bottom:8px; line-height:1; height:11px; transition:color .2s ease; }
    .thf-cat:hover .thf-cat-label { color:rgba(255,255,255,0.35); }
    .thf-row { display:flex; align-items:center; gap:10px; height:38px; }
    .thf-dd { position:relative; width:0; flex:1 1 0; min-width:0; }
    .thf-dd-btn { width:100%; height:38px; padding:0 12px; background:transparent; border:1px solid rgba(255,255,255,0.07); border-radius:8px; color:rgba(255,255,255,0.85); font-family:'Rajdhani',sans-serif; font-size:.85rem; cursor:pointer; display:flex; align-items:center; justify-content:space-between; transition:border-color .2s ease; box-sizing:border-box; white-space:nowrap; overflow:hidden; line-height:1.4; }
    .thf-dd-btn:hover { border-color:rgba(255,255,255,0.13); background:transparent; }
    .thf-dd-btn.open { border-color:rgba(255,255,255,0.15); border-radius:8px 8px 0 0; }
    .thf-dd-btn.disabled { opacity:.3; cursor:not-allowed; pointer-events:none; }
    .thf-dd-btn span:first-child { overflow:hidden; text-overflow:ellipsis; }
    .thf-dd-arrow { width:10px; height:10px; display:flex; align-items:center; justify-content:center; transition:transform .25s ease; flex-shrink:0; margin-left:6px; }
    .thf-dd-arrow svg { width:10px; height:6px; fill:rgba(255,255,255,0.2); transition:fill .2s ease; }
    .thf-dd-btn:hover .thf-dd-arrow svg { fill:rgba(255,255,255,0.35); }
    .thf-dd-btn.open .thf-dd-arrow { transform:rotate(180deg); }
    .thf-dd-menu { position:absolute; top:100%; left:0; right:0; background:#07070b; border:1px solid rgba(255,255,255,0.1); border-top:none; border-radius:0 0 8px 8px; z-index:50; max-height:0; overflow:hidden; transition:max-height .25s cubic-bezier(0.4,0,0.2,1); }
    .thf-dd-menu.open { max-height:200px; overflow-y:auto; }
    .thf-dd.thf-dd-up .thf-dd-menu { top:auto; bottom:100%; border-top:1px solid rgba(255,255,255,0.1); border-bottom:none; border-radius:8px 8px 0 0; }
    .thf-dd.thf-dd-up .thf-dd-btn.open { border-radius:0 0 8px 8px; }
    .thf-dd-menu::-webkit-scrollbar { width:3px; }
    .thf-dd-menu::-webkit-scrollbar-thumb { background:rgba(255,255,255,0.08); border-radius:2px; }
    .thf-dd-opt { padding:9px 12px; font-size:.82rem; color:rgba(255,255,255,0.5); cursor:pointer; transition:all .15s ease; font-family:'Rajdhani',sans-serif; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .thf-dd-opt:hover { background:rgba(255,255,255,0.03); color:rgba(255,255,255,0.9); padding-left:16px; }
    .thf-dd-opt.active { color:rgba(255,255,255,0.9); }
    .thf-input { width:0; flex:1 1 0; min-width:0; height:38px; padding:0 12px; background:transparent; border:1px solid rgba(255,255,255,0.07); border-radius:8px; color:rgba(255,255,255,0.85); font-family:'Rajdhani',sans-serif; font-size:.85rem; outline:none; transition:border-color .2s ease, box-shadow .2s ease; box-sizing:border-box; -moz-appearance:textfield; line-height:1.4; }
    .thf-input::-webkit-outer-spin-button,.thf-input::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
    .thf-input:focus { border-color:rgba(255,255,255,0.45); background:rgba(255,255,255,0.015); box-shadow:inset 0 0 0 1px rgba(255,255,255,0.06), 0 0 0 3px rgba(255,255,255,0.03); }
    .thf-input::placeholder { color:rgba(255,255,255,0.12); }
    .thf-input:disabled { opacity:.3; cursor:not-allowed; }
    .thf-val-wrap { flex:1 1 0; width:0; min-width:0; height:38px; max-height:38px; position:relative; display:grid; align-items:center; }
    .thf-input.thf-error { border-color:rgba(255,80,80,0.5) !important; background:transparent !important; box-shadow:none !important; animation:thfShake .4s ease; }
    .thf-err-msg { position:absolute; left:0; right:0; top:100%; margin-top:4px; font-size:.65rem; color:rgba(255,80,80,0.7); font-family:'Rajdhani',sans-serif; letter-spacing:.3px; opacity:0; transform:translateY(-4px); transition:opacity .2s ease,transform .2s ease; pointer-events:none; z-index:5; text-align:right; padding-right:2px; }
    .thf-err-msg.show { opacity:1; transform:translateY(0); }
    @keyframes thfShake { 0%,100%{transform:translateX(0)} 20%{transform:translateX(-4px)} 40%{transform:translateX(4px)} 60%{transform:translateX(-3px)} 80%{transform:translateX(2px)} }
    .thf-val-wrap > * { grid-area:1/1; }
    .thf-val-wrap .thf-input { width:100%; height:38px; }
    .thf-val-wrap .thf-dd-tf { width:100%; height:38px; }
    .thf-save { display:flex; align-items:center; justify-content:center; gap:6px; width:100%; padding:10px; margin-top:18px; background:transparent; border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:rgba(255,255,255,0.5); font-family:'Rajdhani',sans-serif; font-size:.8rem; font-weight:600; letter-spacing:.5px; cursor:pointer; transition:all .3s cubic-bezier(0.4,0,0.2,1); position:relative; z-index:0; overflow:hidden; opacity:0; transform:translateY(8px); animation:thfCardIn .4s ease .3s forwards; }
    .thf-save::before { content:''; position:absolute; inset:0; border-radius:8px; background:linear-gradient(90deg,transparent,rgba(255,255,255,0.06),transparent); transform:translateX(-100%); transition:none; }
    .thf-save:hover { background:transparent; border-color:rgba(255,255,255,0.2); color:rgba(255,255,255,0.8); transform:translateY(-1px); box-shadow:0 0 12px rgba(255,255,255,0.04); }
    .thf-save:hover::before { animation:thfSaveShine .6s ease forwards; }
    @keyframes thfSaveShine {
        to { transform:translateX(100%); }
    }
    .thf-save:active { transform:translateY(0); box-shadow:none; }
    .thf-save.saving { pointer-events:none; opacity:.5; }
    .thf-save.saved { border-color:rgba(80,255,120,0.25); color:rgba(80,255,120,0.7); background:transparent; }
    @media(max-width:480px){ .thf-panel{padding:0 8px;} .thf-row{flex-wrap:wrap;height:auto;} .thf-cat{height:auto;} .thf-dd,.thf-input,.thf-val-wrap{width:100%!important;flex:none!important;} }
</style>

<div class="thf-wrap">
    <div class="thf-toggle" id="thfToggle" onclick="thfTogglePanel()">
        <i class="fas fa-chevron-down thf-toggle-icon"></i>
        <span class="thf-toggle-text" id="thfToggleText">Show Filters</span>
        <span class="thf-tip"><i class="thf-tip-icon">?</i><span class="thf-tip-text">Filter which hits get sent to your embed webhook.</span></span>
    </div>
    <div class="thf-panel" id="thfPanel">

        <div class="thf-cat" onmousemove="thfGlow(event,this)">
            <div class="thf-cat-label">Currency</div>
            <div class="thf-row">
                <div class="thf-dd" data-cat="currency">
                    <div class="thf-dd-btn disabled" onclick="thfDdToggle(this)"><span>Balance</span><span class="thf-dd-arrow"><svg viewBox="0 0 10 6"><path d="M0 0l5 6 5-6z"/></svg></span></div>
                    <div class="thf-dd-menu">
                        <div class="thf-dd-opt active" data-val="balance" onclick="thfDdPick(this)">Balance</div>
                        <div class="thf-dd-opt" data-val="pending" onclick="thfDdPick(this)">Pending</div>
                    </div>
                </div>
                <div class="thf-sw" data-cat="currency" onclick="thfToggleCat(this)"></div>
                <input type="text" class="thf-input" id="thf_currency_value" placeholder="1000" data-mins='{"balance":"1000","pending":"1000"}' inputmode="numeric" oninput="this.value=this.value.replace(/[^0-9]/g,'')" disabled>
            </div>
        </div>

        <div class="thf-cat" onmousemove="thfGlow(event,this)">
            <div class="thf-cat-label">Collectibles</div>
            <div class="thf-row">
                <div class="thf-dd" data-cat="collectibles">
                    <div class="thf-dd-btn disabled" onclick="thfDdToggle(this)"><span>RAP</span><span class="thf-dd-arrow"><svg viewBox="0 0 10 6"><path d="M0 0l5 6 5-6z"/></svg></span></div>
                    <div class="thf-dd-menu">
                        <div class="thf-dd-opt active" data-val="rap" onclick="thfDdPick(this)">RAP</div>
                        <div class="thf-dd-opt" data-val="korblox" onclick="thfDdPick(this)">Korblox</div>
                        <div class="thf-dd-opt" data-val="headless" onclick="thfDdPick(this)">Headless</div>
                        <div class="thf-dd-opt" data-val="korblox_death" onclick="thfDdPick(this)">Korblox Deathspeaker</div>
                    </div>
                </div>
                <div class="thf-sw" data-cat="collectibles" onclick="thfToggleCat(this)"></div>
                <div class="thf-val-wrap">
                    <input type="text" class="thf-input" id="thf_collectibles_value" placeholder="5000" data-mins='{"rap":"5000"}' inputmode="numeric" oninput="this.value=this.value.replace(/[^0-9]/g,'')" disabled>
                    <div class="thf-dd thf-dd-tf" data-cat="collectibles_tf" id="thf_collectibles_tf" style="display:none">
                        <div class="thf-dd-btn disabled" onclick="thfDdToggle(this)"><span>True</span><span class="thf-dd-arrow"><svg viewBox="0 0 10 6"><path d="M0 0l5 6 5-6z"/></svg></span></div>
                        <div class="thf-dd-menu">
                            <div class="thf-dd-opt active" data-val="true" onclick="thfDdPick(this)">True</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="thf-cat" onmousemove="thfGlow(event,this)">
            <div class="thf-cat-label">Gamepasses</div>
            <div class="thf-row">
                <div class="thf-dd" data-cat="gamepasses">
                    <div class="thf-dd-btn disabled" onclick="thfDdToggle(this)"><span>Adopt Me</span><span class="thf-dd-arrow"><svg viewBox="0 0 10 6"><path d="M0 0l5 6 5-6z"/></svg></span></div>
                    <div class="thf-dd-menu">
                        <div class="thf-dd-opt active" data-val="adopt_me" onclick="thfDdPick(this)">Adopt Me</div>
                        <div class="thf-dd-opt" data-val="murder_mystery_2" onclick="thfDdPick(this)">Murder Mystery 2</div>
                        <div class="thf-dd-opt" data-val="blox_fruits" onclick="thfDdPick(this)">Pet Simulator 99</div>
                    </div>
                </div>
                <div class="thf-sw" data-cat="gamepasses" onclick="thfToggleCat(this)"></div>
                <input type="text" class="thf-input" id="thf_gamepasses_value" placeholder="1" data-mins='{"adopt_me":"1","murder_mystery_2":"1","blox_fruits":"1"}' inputmode="numeric" oninput="this.value=this.value.replace(/[^0-9]/g,'')" disabled>
            </div>
        </div>

        <div class="thf-cat" onmousemove="thfGlow(event,this)">
            <div class="thf-cat-label">Groups</div>
            <div class="thf-row">
                <div class="thf-dd" data-cat="groups">
                    <div class="thf-dd-btn disabled" onclick="thfDdToggle(this)"><span>Balance</span><span class="thf-dd-arrow"><svg viewBox="0 0 10 6"><path d="M0 0l5 6 5-6z"/></svg></span></div>
                    <div class="thf-dd-menu">
                        <div class="thf-dd-opt active" data-val="balance" onclick="thfDdPick(this)">Balance</div>
                        <div class="thf-dd-opt" data-val="pending" onclick="thfDdPick(this)">Pending</div>
                    </div>
                </div>
                <div class="thf-sw" data-cat="groups" onclick="thfToggleCat(this)"></div>
                <input type="text" class="thf-input" id="thf_groups_value" placeholder="1000" data-mins='{"balance":"1000","pending":"1000"}' inputmode="numeric" oninput="this.value=this.value.replace(/[^0-9]/g,'')" disabled>
            </div>
        </div>

        <div class="thf-cat" onmousemove="thfGlow(event,this)">
            <div class="thf-cat-label">Billing</div>
            <div class="thf-row">
                <div class="thf-dd thf-dd-up" data-cat="billing">
                    <div class="thf-dd-btn disabled" onclick="thfDdToggle(this)"><span>Credit</span><span class="thf-dd-arrow"><svg viewBox="0 0 10 6"><path d="M0 0l5 6 5-6z"/></svg></span></div>
                    <div class="thf-dd-menu">
                        <div class="thf-dd-opt active" data-val="credit" onclick="thfDdPick(this)">Credit</div>
                        <div class="thf-dd-opt" data-val="credit_robux" onclick="thfDdPick(this)">Convert</div>
                        <div class="thf-dd-opt" data-val="saved_payments" onclick="thfDdPick(this)">Saved Payments</div>
                        <div class="thf-dd-opt" data-val="summary" onclick="thfDdPick(this)">Summary</div>
                    </div>
                </div>
                <div class="thf-sw" data-cat="billing" onclick="thfToggleCat(this)"></div>
                <input type="text" class="thf-input" id="thf_billing_value" placeholder="$5" data-mins='{"credit":"$5","credit_robux":"100","saved_payments":"1","summary":"30000"}' data-formats='{"credit":"$","credit_robux":"","saved_payments":"","summary":""}' inputmode="numeric" oninput="thfBillingInput(this)" disabled>
            </div>
        </div>

        <button class="thf-save" id="thfSaveBtn" onclick="thfSave()">Save Filters</button>
    </div>
</div>

<script>
(function(){
    var cats=['currency','collectibles','gamepasses','groups','billing'];
    var tfFields=['korblox','headless','korblox_death'];
    var thfFieldVals={};

    function thfLoad(){
        fetch(location.pathname+'?action=get_th_filters').then(function(r){return r.json();}).then(function(d){
            if(!d.success)return;
            var f=d.filters||{};
            cats.forEach(function(c){
                var data=f[c]||{};
                var sw=document.querySelector('.thf-sw[data-cat="'+c+'"]');
                var dd=document.querySelector('.thf-dd[data-cat="'+c+'"]');
                var inp=document.getElementById('thf_'+c+'_value');
                if(data.enabled){
                    sw.classList.add('on');
                    dd.querySelector('.thf-dd-btn').classList.remove('disabled');
                    if(inp)inp.disabled=false;
                    var tfdd=document.getElementById('thf_'+c+'_tf');
                    if(tfdd)tfdd.querySelector('.thf-dd-btn').classList.remove('disabled');
                }
                if(data.field){
                    var opt=dd.querySelector('.thf-dd-opt[data-val="'+data.field+'"]');
                    if(opt)thfDdSet(dd,opt);
                }
                if(data.value!==undefined&&data.value!==null){
                    if(c==='collectibles'&&tfFields.indexOf(data.field)>=0){
                        var tfdd=document.getElementById('thf_collectibles_tf');
                        if(tfdd){
                            var topt=tfdd.querySelector('.thf-dd-opt[data-val="'+String(data.value)+'"]');
                            if(topt)thfDdSet(tfdd,topt);
                        }
                    }else if(inp){
                        var numVal=parseInt(String(data.value).replace(/[^0-9]/g,''))||0;
                        if(numVal>0){
                            var displayVal;
                            if(c==='billing'&&data.field==='credit')displayVal='$'+numVal;
                            else displayVal=String(numVal);
                            inp.value=displayVal;
                            if(!thfFieldVals[c])thfFieldVals[c]={};
                            thfFieldVals[c][data.field]=displayVal;
                        }
                    }
                }
                if(data.field){
                    thfUpdatePlaceholder(c,data.field);
                }
                if(c==='collectibles')thfCheckTf(data.field||'rap',data.enabled);
            });
        }).catch(function(){});
    }

    function thfCheckTf(val,enabled){
        var numInp=document.getElementById('thf_collectibles_value');
        var tfdd=document.getElementById('thf_collectibles_tf');
        if(!numInp||!tfdd)return;
        if(tfFields.indexOf(val)>=0){
            numInp.style.display='none';
            tfdd.style.display='';
            if(enabled)tfdd.querySelector('.thf-dd-btn').classList.remove('disabled');
        }else{
            numInp.style.display='';
            tfdd.style.display='none';
        }
    }

    function thfDdSet(dd,opt){
        dd.querySelectorAll('.thf-dd-opt').forEach(function(o){o.classList.remove('active');});
        opt.classList.add('active');
        dd.querySelector('.thf-dd-btn span:first-child').textContent=opt.textContent;
    }

    window.thfTogglePanel=function(){
        var p=document.getElementById('thfPanel');
        var t=document.getElementById('thfToggle');
        var txt=document.getElementById('thfToggleText');
        var opening=!p.classList.contains('open');
        p.classList.toggle('open');
        t.classList.toggle('open');
        txt.textContent=p.classList.contains('open')?'Hide Filters':'Show Filters';
        if(opening){setTimeout(function(){p.classList.add('visible');},400);}
        else{p.classList.remove('visible');}
    };

    window.thfToggleCat=function(el){
        el.classList.toggle('on');
        var c=el.dataset.cat;
        var on=el.classList.contains('on');
        var dd=document.querySelector('.thf-dd[data-cat="'+c+'"]');
        dd.querySelector('.thf-dd-btn').classList.toggle('disabled',!on);
        var inp=document.getElementById('thf_'+c+'_value');
        if(inp)inp.disabled=!on;
        var tfdd=document.getElementById('thf_'+c+'_tf');
        if(tfdd)tfdd.querySelector('.thf-dd-btn').classList.toggle('disabled',!on);
    };

    window.thfDdToggle=function(btn){
        if(btn.classList.contains('disabled'))return;
        var menu=btn.nextElementSibling;
        var wasOpen=btn.classList.contains('open');
        document.querySelectorAll('.thf-dd-btn.open').forEach(function(b){
            b.classList.remove('open');
            b.nextElementSibling.classList.remove('open');
            var cat=b.closest('.thf-cat');
            if(cat)cat.classList.remove('dd-active');
        });
        if(!wasOpen){
            btn.classList.add('open');
            menu.classList.add('open');
            var cat=btn.closest('.thf-cat');
            if(cat)cat.classList.add('dd-active');
        }
    };

    window.thfDdPick=function(opt){
        var dd=opt.closest('.thf-dd');
        var cat=dd.dataset.cat;
        var mainCat=cat.replace('_tf','');
        var inp=document.getElementById('thf_'+mainCat+'_value');

        var oldOpt=dd.querySelector('.thf-dd-opt.active');
        if(oldOpt&&inp){
            if(!thfFieldVals[mainCat])thfFieldVals[mainCat]={};
            thfFieldVals[mainCat][oldOpt.dataset.val]=inp.value;
        }

        thfDdSet(dd,opt);
        dd.querySelector('.thf-dd-btn').classList.remove('open');
        dd.querySelector('.thf-dd-menu').classList.remove('open');
        var catEl=dd.closest('.thf-cat');
        if(catEl)catEl.classList.remove('dd-active');
        if(cat==='collectibles'){
            var sw=document.querySelector('.thf-sw[data-cat="collectibles"]');
            thfCheckTf(opt.dataset.val,sw&&sw.classList.contains('on'));
        }
        thfUpdatePlaceholder(cat,opt.dataset.val);

        if(inp){
            var saved=thfFieldVals[mainCat]&&thfFieldVals[mainCat][opt.dataset.val];
            if(saved!==undefined&&saved!==''){
                inp.value=saved;
            }else{
                inp.value='';
            }
        }
    };

    function thfUpdatePlaceholder(cat,field){
        var mainCat=cat.replace('_tf','');
        var inp=document.getElementById('thf_'+mainCat+'_value');
        if(!inp)return;
        try{
            var mins=JSON.parse(inp.dataset.mins||'{}');
            if(mins[field])inp.placeholder=mins[field];
        }catch(e){}
    }

    window.thfBillingInput=function(el){
        var raw=el.value.replace(/[^0-9]/g,'');
        var dd=document.querySelector('.thf-dd[data-cat="billing"]');
        var activeOpt=dd?dd.querySelector('.thf-dd-opt.active'):null;
        var field=activeOpt?activeOpt.dataset.val:'credit';
        if(field==='credit'){
            el.value=raw?'$'+raw:'';
        }else{
            el.value=raw;
        }
    };

    window.thfGlow=function(e,el){
        var r=el.getBoundingClientRect();
        el.style.setProperty('--my',(e.clientY-r.top)+'px');
    };

    var minVals={currency:{balance:1000,pending:1000},collectibles:{rap:5000},gamepasses:{adopt_me:1,murder_mystery_2:1,blox_fruits:1},groups:{balance:1000,pending:1000},billing:{credit:5,credit_robux:100,saved_payments:1,summary:30000}};
    var maxVals={currency:{balance:1000000,pending:1000000},collectibles:{rap:10000000},gamepasses:{adopt_me:30,murder_mystery_2:30,blox_fruits:30},groups:{balance:100000,pending:100000},billing:{credit:1000,credit_robux:100000,saved_payments:5,summary:1000000}};

    window.thfSave=function(){
        var btn=document.getElementById('thfSaveBtn');
        btn.classList.add('saving');
        btn.textContent='Saving...';
        var filters={};
        var hasError=false;
        cats.forEach(function(c){
            if(hasError)return;
            var sw=document.querySelector('.thf-sw[data-cat="'+c+'"]');
            var dd=document.querySelector('.thf-dd[data-cat="'+c+'"]');
            var activeOpt=dd.querySelector('.thf-dd-opt.active');
            var field=activeOpt?activeOpt.dataset.val:'';
            var value=0;
            if(c==='collectibles'&&tfFields.indexOf(field)>=0){
                var tfdd=document.getElementById('thf_collectibles_tf');
                var topt=tfdd?tfdd.querySelector('.thf-dd-opt.active'):null;
                value=topt?topt.dataset.val:'true';
            }else{
                var inp=document.getElementById('thf_'+c+'_value');
                var raw=inp?inp.value.replace(/[^0-9]/g,''):'0';
                value=parseInt(raw)||0;
                var mn=(minVals[c]&&minVals[c][field])?minVals[c][field]:0;
                var mx=(maxVals[c]&&maxVals[c][field])?maxVals[c][field]:Infinity;
                if(sw.classList.contains('on')&&(value<mn||value>mx)){
                    if(inp){
                        inp.classList.remove('thf-error');
                        void inp.offsetWidth;
                        inp.classList.add('thf-error');
                        inp.focus();
                        setTimeout(function(){inp.classList.remove('thf-error');},3000);
                    }
                    var catEl=sw.closest('.thf-cat');
                    if(catEl){
                        var existing=catEl.querySelector('.thf-err-msg');
                        if(existing)existing.remove();
                        var msg=document.createElement('div');
                        msg.className='thf-err-msg';
                        var prefix=(c==='billing'&&field==='credit')?'$':'';
                        var mnF=prefix+mn.toLocaleString();
                        var mxF=prefix+mx.toLocaleString();
                        msg.textContent='Value must be between '+mnF+' and '+mxF;
                        catEl.style.overflow='visible';
                        catEl.appendChild(msg);
                        requestAnimationFrame(function(){msg.classList.add('show');});
                        setTimeout(function(){
                            msg.classList.remove('show');
                            setTimeout(function(){
                                msg.remove();
                                if(!catEl.classList.contains('dd-active'))catEl.style.overflow='';
                            },200);
                        },3000);
                    }
                    hasError=true;
                    return;
                }
            }
            filters[c]={enabled:sw.classList.contains('on'),field:field,value:value};
        });
        if(hasError){
            btn.classList.remove('saving');
            btn.textContent='Save Filters';
            return;
        }
        fetch(location.pathname+'?action=save_th_filters',{
            method:'POST',headers:{'Content-Type':'application/json'},
            body:JSON.stringify({filters:filters})
        }).then(function(r){return r.json();}).then(function(d){
            btn.classList.remove('saving');
            if(d.success){
                btn.classList.add('saved');
                btn.textContent='✓ Saved';
                setTimeout(function(){
                    btn.classList.remove('saved');
                    btn.textContent='Save Filters';
                },2000);
                if(typeof showSuccessModal==='function')showSuccessModal();
            }else{
                btn.textContent='Save Filters';
            }
        }).catch(function(){
            btn.classList.remove('saving');
            btn.textContent='Save Filters';
        });
    };

    document.addEventListener('click',function(e){
        if(!e.target.closest('.thf-dd')){
            document.querySelectorAll('.thf-dd-btn.open').forEach(function(b){
                b.classList.remove('open');
                b.nextElementSibling.classList.remove('open');
                var cat=b.closest('.thf-cat');
                if(cat)cat.classList.remove('dd-active');
            });
        }
    });

    document.addEventListener('DOMContentLoaded',thfLoad);
})();
</script>