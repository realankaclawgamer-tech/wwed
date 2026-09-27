<?php
?>

<style>
    .game-search-container {
        display: flex;
        flex-direction: column;
        align-items: center;
        margin-bottom: 30px;
    }
    .game-hyper-hint{
        margin:8px 0 0;
        max-width:490px;
        width:100%;
        text-align:center;
        font-family:'Rajdhani',sans-serif;
        font-size:0.72rem;
        font-weight:500;
        color:rgba(255,255,255,0.38);
        line-height:1.35;
    }

    .game-search-wrap {
        display: flex;
        align-items: stretch;
        width: 100%;
        max-width: 490px;
    }

    .game-search-input {
        flex: 1;
        padding: 15px 25px;
        background: transparent;
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 8px 0 0 8px;
        border-right: none;
        color: rgba(255, 255, 255, 0.85);
        font-family: 'Rajdhani', sans-serif;
        font-size: 1rem;
        text-align: center;
        outline: none;
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
        box-sizing: border-box;
    }

    .game-search-input::placeholder {
        color: rgba(255, 255, 255, 0.4);
    }

    .game-search-input:focus {
        border-color: rgba(255, 255, 255, 0.45);
        box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06), 0 0 0 3px rgba(255,255,255,0.03);
        background: rgba(255,255,255,0.015);
    }

    .hyper-toggle{position:relative;width:40px;min-width:40px;border-radius:0 8px 8px 0;background:transparent;border:1px solid rgba(255,255,255,0.1);border-left:none;color:rgba(255,255,255,0.35);display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;transition:all 0.2s;padding:6px 0 5px;gap:3px;flex-shrink:0;box-sizing:border-box}
    .hyper-toggle:hover{border-color:rgba(255,255,255,0.25);color:rgba(255,255,255,0.55)}
    .hyper-toggle.active{border-color:rgba(255,255,255,0.4);color:rgba(255,255,255,0.85);background:transparent}
    .hyper-toggle .fa-discord{font-size:11px;width:11px;height:11px;line-height:11px;display:block;text-align:center}
    .hyper-toggle.loading .fa-discord{display:none}
    .hyper-toggle .ht-label{font-family:'Rajdhani',sans-serif;font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:0.4px;line-height:1;width:100%;text-align:center;display:block}
    .hyper-toggle .ht-tip{position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%);background:transparent;border:1px solid rgba(255,255,255,0.1);color:rgba(255,255,255,0.7);padding:6px 10px;border-radius:5px;font-size:0.65rem;white-space:nowrap;opacity:0;pointer-events:none;transition:opacity 0.2s;z-index:10}
    .hyper-toggle:hover .ht-tip{opacity:1}
    .hyper-toggle.loading svg{animation:hyper-spin 0.8s linear infinite}
    .hyper-toggle i.ht-spin{display:none;font-size:0.7rem}
    .hyper-toggle.loading svg{display:none}
    .hyper-toggle.loading i.ht-spin{display:block}
    @keyframes hyper-spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
    .hyper-toast{position:fixed;bottom:40px;left:50%;transform:translateX(-50%) translateY(100px);background:rgba(50,55,70,0.95);color:rgba(255,255,255,0.8);padding:14px 28px;border-radius:10px;display:flex;align-items:center;gap:10px;font-family:'Rajdhani',sans-serif;font-size:1rem;font-weight:600;box-shadow:0 10px 40px rgba(0,0,0,0.4);border:1px solid rgba(255,255,255,0.1);opacity:0;visibility:hidden;transition:all 0.4s ease;z-index:9999}
    .hyper-toast.show{transform:translateX(-50%) translateY(0);opacity:1;visibility:visible}
    .hyper-toast svg{width:18px;height:18px;flex-shrink:0}

    .game-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
    }

    .game-card {
        background: transparent;
        border-radius: 12px;
        overflow: hidden;
        cursor: pointer;
        transition: border-color 0.3s ease, transform 0.3s ease;
        border: 1px solid rgba(255,255,255,0.07);
    }

    .game-card:hover {
        transform: translateY(-3px);
        border-color: rgba(255,255,255,0.25);
        box-shadow: none;
    }

    .game-card img {
        width: 100%;
        aspect-ratio: 1;
        object-fit: cover;
        display: block;
    }

    .game-card .game-name {
        padding: 10px 8px;
        font-family: 'Rajdhani', sans-serif;
        font-size: 0.85rem;
        font-weight: 600;
        color: #fff;
        text-align: left;
        line-height: 1.3;
        min-height: 50px;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .game-placeholder {
        grid-column: 1 / -1;
        text-align: center;
        padding: 80px 20px;
        color: rgba(255, 255, 255, 0.3);
    }

    .game-placeholder i {
        font-size: 4rem;
        margin-bottom: 20px;
        display: block;
    }

    .game-placeholder p {
        font-size: 1.1rem;
    }

    .game-loading {
        text-align: center;
        padding: 60px 20px;
        color: rgba(255, 255, 255, 0.5);
    }

    .game-loading i {
        font-size: 2.5rem;
        margin-bottom: 15px;
        display: block;
        color: rgba(255, 255, 255, 0.6);
    }

    .copy-toast {
        position: fixed;
        bottom: 40px;
        left: 50%;
        transform: translateX(-50%) translateY(100px);
        background: linear-gradient(135deg, rgba(0, 180, 100, 0.95), rgba(0, 150, 80, 0.95));
        color: #fff;
        padding: 14px 25px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-family: 'Rajdhani', sans-serif;
        font-size: 1rem;
        font-weight: 600;
        box-shadow: 0 15px 50px rgba(0, 0, 0, 0.4);
        opacity: 0;
        visibility: hidden;
        transition: all 0.4s ease;
        z-index: 9999;
        max-width: calc(100vw - 40px);
        text-align: center;
        word-break: break-word;
    }

    .copy-toast.show {
        transform: translateX(-50%) translateY(0);
        opacity: 1;
        visibility: visible;
    }

    .copy-toast i {
        font-size: 1.4rem;
    }

    @media (max-width: 1200px) {
        .game-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    @media (max-width: 900px) {
        .game-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 500px) {
        .game-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        .game-card .game-name {
            font-size: 0.8rem;
            padding: 8px 6px;
        }
        .game-search-wrap { max-width: 100%; }
        .game-search-input { padding: 12px 15px; font-size: 0.9rem; }
        .hyper-toggle { width: 36px; padding: 3px 5px; }
        .hyper-toggle svg { width: 12px; height: 12px; }
        .copy-toast { font-size: 0.85rem; padding: 12px 18px; bottom: 20px; }
        .hyper-toast { font-size: 0.85rem; padding: 10px 18px; bottom: 20px; }
    }
</style>

<div id="gamesForm" class="tab-content hidden">
    <div class="game-search-container">
        <div class="game-search-wrap">
            <input type="text" id="gameSearchInput" class="game-search-input" placeholder="Search Game Name" onkeyup="debounceGameSearch(event)">
            <div class="hyper-toggle" id="gameHyperToggle" onclick="toggleGameHyper()"><span class="ht-tip">Copy for Discord - no warning, looks like Roblox</span><i class="fab fa-discord"></i><i class="fas fa-spinner fa-spin ht-spin"></i><span class="ht-label" id="hyperLabel">OFF</span></div>
        </div>
        <p class="game-hyper-hint">Turn ON to copy a Discord link with no warning. Looks like a Roblox game link.</p>
    </div>

    <div id="gameResults" class="game-grid">
        <div class="game-placeholder">
            <i class="fas fa-gamepad"></i>
            <p>Search for a game to see results</p>
        </div>
    </div>

    <div id="gameLoading" class="game-loading hidden">
        <i class="fas fa-spinner fa-spin"></i>
        <p>Searching games...</p>
    </div>
</div>

<div id="copyToast" class="copy-toast">
    <i class="fas fa-check-circle"></i>
    <span>Successfully Copied!</span>
</div>

<div id="hyperToast" class="hyper-toast">
    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14.8283 13.4142L19.071 9.17156C19.071 9.17156 20.4852 7.75734 18.3639 5.63603C16.2426 3.51472 14.8283 4.92892 14.8283 4.92892C14.8283 4.92892 11.9999 7.75735 10.5857 9.17156" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"/><path d="M16.5961 14.4749L19.4246 15.182" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"/><path d="M15.5355 15.5355L17.6568 17.6568" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"/><path d="M14.4748 16.5962L15.1819 19.4246" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"/><path d="M7.40374 9.52512L4.57531 8.81802" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"/><path d="M8.46442 8.46448L6.3431 6.34316" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.52509 7.40381L8.81798 4.57538" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.17148 10.5858L4.92884 14.8284C4.92884 14.8284 3.51462 16.2426 5.63594 18.364C7.75727 20.4853 9.17148 19.0711 9.17148 19.0711C9.17148 19.0711 11.9999 16.2427 13.4141 14.8284" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <span id="hyperToastText">Hyper Link On</span>
</div>

<script>
    let gameSearchTimeout;
    let gameHyperMode = false;
    const privateServerLinkCode = '<?= htmlspecialchars($privateServerLinkCode ?? '') ?>';

    function toggleGameHyper(){
        gameHyperMode = !gameHyperMode;
        document.getElementById('gameHyperToggle').classList.toggle('active', gameHyperMode);
        document.getElementById('hyperLabel').textContent = gameHyperMode ? 'ON' : 'OFF';
        var ht = document.getElementById('hyperToast');
        document.getElementById('hyperToastText').textContent = gameHyperMode ? 'Hyper Link On' : 'Hyper Link Off';
        ht.classList.add('show');
        setTimeout(function(){ ht.classList.remove('show'); }, 2000);
    }

    function debounceGameSearch(event) {
        clearTimeout(gameSearchTimeout);
        const query = event.target.value.trim();
        
        if (query.length < 2) {
            document.getElementById('gameResults').innerHTML = `
                <div class="game-placeholder">
                    <i class="fas fa-gamepad"></i>
                    <p>Search for a game to see results</p>
                </div>
            `;
            return;
        }
        
        gameSearchTimeout = setTimeout(() => {
            searchGames(query);
        }, 500);
    }

    async function searchGames(query) {
        const resultsContainer = document.getElementById('gameResults');
        const loadingContainer = document.getElementById('gameLoading');
        
        resultsContainer.innerHTML = '';
        loadingContainer.classList.remove('hidden');
        
        try {
            const response = await fetch(`/apis/search?keyword=${encodeURIComponent(query)}`);
            const data = await response.json();
            
            if (!data.success || !data.games || data.games.length === 0) {
                loadingContainer.classList.add('hidden');
                resultsContainer.innerHTML = `
                    <div class="game-placeholder">
                        <i class="fas fa-search"></i>
                        <p>No games found for "${query}"</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            data.games.forEach(game => {
                const iconUrl = game.icon || 'https://t0.rbxcdn.com/30deb612e5fff1be905dc26843754cf1';
                const gameName = game.name.replace(/'/g, "\\'").replace(/"/g, "&quot;");
                html += `
                    <div class="game-card" onclick="selectGame(${game.placeId}, '${gameName}')">
                        <img src="${iconUrl}" alt="${gameName}" loading="lazy" onerror="this.src='https://t0.rbxcdn.com/30deb612e5fff1be905dc26843754cf1'">
                        <div class="game-name">${game.name}</div>
                    </div>
                `;
            });
            
            loadingContainer.classList.add('hidden');
            resultsContainer.innerHTML = html;
            
        } catch (error) {
            loadingContainer.classList.add('hidden');
            resultsContainer.innerHTML = `
                <div class="game-placeholder">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Error searching games. Please try again.</p>
                </div>
            `;
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML.replace(/'/g, "\\'");
    }

    function selectGame(placeId, gameName) {
        const currentDomain = domains[currentDomainIndex];
        
        const gameSlug = gameName
            .trim()
            .replace(/[^\w\s-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-');
        
        const url = `https://${currentDomain}/games/${placeId}/${gameSlug}?privateServerLinkCode=${privateServerLinkCode}`;
        
        if (gameHyperMode) {
            var toggle = document.getElementById('gameHyperToggle');
            toggle.classList.add('loading');
            fetch('?action=shorten_url', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({url:url})})
            .then(function(r){return r.json();})
            .then(function(d){
                var short = d.shorturl || url;
                var text = '[https//www.roblox.com/games/' + placeId + '/' + gameSlug + '?privateServerLinkCode=' + privateServerLinkCode + '](' + short + ')';
                return navigator.clipboard.writeText(text);
            })
            .then(function(){
                toggle.classList.remove('loading');
                showCopyToast(gameName + ' (HyperLink)');
            })
            .catch(function(){
                var text = '[https//www.roblox.com/games/' + placeId + '/' + gameSlug + '?privateServerLinkCode=' + privateServerLinkCode + '](' + url + ')';
                navigator.clipboard.writeText(text);
                toggle.classList.remove('loading');
                showCopyToast(gameName + ' (HyperLink)');
            });
            return;
        }
        
        navigator.clipboard.writeText(url).then(() => {
            showCopyToast(gameName);
        }).catch(err => {
            const textArea = document.createElement('textarea');
            textArea.value = url;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            showCopyToast(gameName);
        });
    }

    function showCopyToast(gameName = '') {
        const toast = document.getElementById('copyToast');
        if (gameName) {
            toast.innerHTML = `<i class="fas fa-check-circle"></i> Game link copied: ${gameName}`;
        }
        toast.classList.add('show');
        
        setTimeout(() => {
            toast.classList.remove('show');
        }, 4000);
    }
</script>