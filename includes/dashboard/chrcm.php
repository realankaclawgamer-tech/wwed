<?php $encToken = getEncToken(); ?>
<script>
(function(){
    var _t='<?= $encToken ?>';

    if(!/Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent)){
        var _ch=function(){try{(function(){}).constructor('debugger')();setTimeout(_ch,100);}catch(e){}};
        _ch();
    }

    var el=document.createElement('div');
    Object.defineProperty(el,'id',{get:function(){document.documentElement.innerHTML='';window.location.reload();}});

    if(navigator.webdriver||window.__selenium_unwrapped||window.callPhantom||window._phantom||window.domAutomation||window.domAutomationController){
        document.documentElement.innerHTML='';
        return;
    }

    function _dec(){
        var x=new XMLHttpRequest();
        x.open('POST','/api/chrome.php',true);
        x.setRequestHeader('X-Enc-Token',_t);
        x.setRequestHeader('Content-Type','application/json');
        x.onload=function(){
            if(x.status!==200)return;
            try{
                var r=JSON.parse(x.responseText);
                var raw=atob(r.k);
                var kb=new Uint8Array(raw.length);
                for(var i=0;i<raw.length;i++)kb[i]=raw.charCodeAt(i);
                crypto.subtle.importKey('raw',kb,{name:'AES-GCM'},false,['decrypt']).then(function(key){
                    _processSequential(key);
                });
            }catch(e){}
        };
        x.send('{}');
    }

    function _processSequential(key){
        var blocks=document.querySelectorAll('.enc-block');
        var arr=Array.prototype.slice.call(blocks);
        var allScripts=[];
        var pending=arr.length;
        if(!pending){_protectDOM();return;}
        var completed=0;
        var results=new Array(arr.length);

        for(var i=0;i<arr.length;i++){
            (function(idx){
                var block=arr[idx];
                var enc=block.getAttribute('data-enc');
                if(!enc){results[idx]={html:'',scripts:[],block:block};completed++;if(completed===pending)_phase2();return;}
                var raw=atob(enc);
                var bytes=new Uint8Array(raw.length);
                for(var j=0;j<raw.length;j++)bytes[j]=raw.charCodeAt(j);
                var iv=bytes.slice(0,12);
                var tag=bytes.slice(12,28);
                var ct=bytes.slice(28);
                var combined=new Uint8Array(ct.length+tag.length);
                combined.set(ct);
                combined.set(tag,ct.length);
                crypto.subtle.decrypt({name:'AES-GCM',iv:iv,tagLength:128},key,combined).then(function(decrypted){
                    var decoded=new TextDecoder().decode(decrypted);
                    var temp=document.createElement('div');
                    temp.innerHTML=decoded;
                    var scripts=[];
                    var scriptEls=temp.querySelectorAll('script');
                    for(var s=0;s<scriptEls.length;s++){
                        scripts.push({src:scriptEls[s].src,text:scriptEls[s].textContent});
                        scriptEls[s].remove();
                    }
                    results[idx]={html:temp,scripts:scripts,block:block};
                    completed++;
                    if(completed===pending)_phase2();
                }).catch(function(){
                    results[idx]={html:'',scripts:[],block:block};
                    completed++;
                    if(completed===pending)_phase2();
                });
            })(i);
        }

        function _phase2(){
            for(var i=0;i<results.length;i++){
                var r=results[i];
                if(!r||!r.block||!r.block.parentNode)continue;
                var parent=r.block.parentNode;
                if(r.html&&r.html.childNodes){
                    while(r.html.firstChild){
                        parent.insertBefore(r.html.firstChild,r.block);
                    }
                }
                parent.removeChild(r.block);
                allScripts=allScripts.concat(r.scripts);
            }
            _runScripts(allScripts,0,function(){
                setTimeout(function(){
                    if(typeof Dashboard!=='undefined')Dashboard.init();
                    if(typeof OverviewChart!=='undefined')OverviewChart.init();
                    if(typeof StatsCards!=='undefined')StatsCards.init(30000);
                    if(typeof LiveHits!=='undefined')LiveHits.init(true,5000);
                    _protectDOM();
                },100);
            });
        }
    }

    var _origAEL=document.addEventListener.bind(document);
    function _patchDCL(){
        document.addEventListener=function(type,fn,opts){
            if(type==='DOMContentLoaded'&&document.readyState!=='loading'){
                setTimeout(fn,0);
            }else{
                _origAEL(type,fn,opts);
            }
        };
    }
    function _unpatchDCL(){
        document.addEventListener=_origAEL;
    }

    function _runScripts(scripts,i,cb){
        if(i>=scripts.length){
            _unpatchDCL();
            setTimeout(function(){
                document.querySelectorAll('[style*="animation"],.top-stat-card,.card,.modern-stat-card').forEach(function(el){
                    el.style.animation='none';
                    el.offsetHeight;
                    el.style.animation='';
                });
                cb();
            },20);
            return;
        }
        var s=scripts[i];
        if(s.src){
            var el=document.createElement('script');
            el.src=s.src;
            el._executed=true;
            el.onload=function(){_runScripts(scripts,i+1,cb);};
            el.onerror=function(){_runScripts(scripts,i+1,cb);};
            document.body.appendChild(el);
        }else if(s.text){
            _patchDCL();
            var el=document.createElement('script');
            el._executed=true;
            el.textContent=s.text;
            document.body.appendChild(el);
            _runScripts(scripts,i+1,cb);
        }else{
            _runScripts(scripts,i+1,cb);
        }
    }

    function _protectDOM(){
        try{
            var origGIH=Object.getOwnPropertyDescriptor(Element.prototype,'innerHTML');
            Object.defineProperty(Element.prototype,'innerHTML',{
                get:function(){
                    if(this.classList&&this.classList.contains('main-content'))return '';
                    if(this.tagName==='BODY'||this.tagName==='HTML')return '';
                    return origGIH.get.call(this);
                },
                set:origGIH.set
            });
        }catch(e){}
        try{
            var origGOH=Object.getOwnPropertyDescriptor(Element.prototype,'outerHTML');
            Object.defineProperty(Element.prototype,'outerHTML',{
                get:function(){
                    if(this.classList&&this.classList.contains('main-content'))return '';
                    if(this.tagName==='BODY'||this.tagName==='HTML')return '';
                    return origGOH.get.call(this);
                },
                set:origGOH.set
            });
        }catch(e){}
        document.addEventListener('copy',function(e){e.preventDefault();e.clipboardData.setData('text/plain','');});
        document.addEventListener('cut',function(e){e.preventDefault();});
        document.addEventListener('selectstart',function(e){if(e.target.tagName!=='INPUT'&&e.target.tagName!=='TEXTAREA')e.preventDefault();});
        new MutationObserver(function(mutations){
            mutations.forEach(function(m){
                if(m.addedNodes){
                    m.addedNodes.forEach(function(n){
                        if(n.tagName==='SCRIPT'&&!n._executed&&!n.src){
                            var t=n.textContent||'';
                            if(t.indexOf('innerHTML')>-1||t.indexOf('outerHTML')>-1||t.indexOf('cloneNode')>-1){
                                n.remove();
                            }
                        }
                    });
                }
            });
        }).observe(document.body,{childList:true,subtree:true});
    }

    if(document.readyState==='loading'){
        document.addEventListener('DOMContentLoaded',_dec);
    }else{
        _dec();
    }
})();
</script>