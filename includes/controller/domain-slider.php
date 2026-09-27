<?php
$parsedDomains = [];
foreach ($domain as $entry) {
    $parts = preg_split('/\s+/', trim($entry));
    $name = $parts[0];
    $icons = [];
    for ($i = 1; $i < count($parts); $i++) {
        $kv = explode(':', $parts[$i], 2);
        if (count($kv) === 2 && strtolower($kv[1]) === 'true') {
            $icons[] = $kv[0];
        }
    }
    $parsedDomains[] = ['name' => $name, 'icons' => $icons];
}
$iconMap = [
    'google' => 'fab fa-google',
    'discord' => 'fab fa-discord',
    'global' => 'fas fa-globe',
    'tiktok' => 'fab fa-tiktok',
];
$domainNames = array_column($parsedDomains, 'name');
?>
<style>
    .domain-slider-container {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        margin-bottom: 25px;
    }

    .domain-slider-container.domain-slider-pending {
        visibility: hidden;
    }

    .domain-arrow {
        width: 36px;
        height: 36px;
        background: transparent;
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 10px;
        color: rgba(255, 255, 255, 0.25);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
        font-size: 0.9rem;
    }

    .domain-arrow:hover {
        border-color: rgba(255, 255, 255, 0.2);
        color: rgba(255, 255, 255, 0.6);
    }

    .domain-card {
        position: relative;
        min-width: 280px;
        padding: 16px 35px;
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-radius: 14px;
        text-align: center;
    }

    .domain-list-btn {
        position: absolute;
        top: -6px;
        right: -6px;
        width: 22px;
        height: 22px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: rgba(255, 255, 255, 0.3);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.55rem;
        transition: all 0.3s ease;
        z-index: 5;
    }

    .domain-list-btn:hover {
        background: rgba(255, 255, 255, 0.1);
        color: rgba(255, 255, 255, 0.6);
        transform: scale(1.1);
    }

    .domain-list-btn.active {
        background: rgba(255, 255, 255, 0.12);
        color: rgba(255, 255, 255, 0.7);
    }

    .domain-list-dropdown {
        position: absolute;
        top: calc(100% + 8px);
        left: 50%;
        transform: translateX(-50%) translateY(-6px);
        background: rgba(10, 11, 15, 0.97);
        backdrop-filter: blur(15px);
        -webkit-backdrop-filter: blur(15px);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 10px;
        padding: 4px;
        min-width: 200px;
        max-height: 220px;
        overflow-y: auto;
        opacity: 0;
        visibility: hidden;
        transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        z-index: 50;
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.6);
    }

    .domain-list-dropdown.show {
        opacity: 1;
        visibility: visible;
        transform: translateX(-50%) translateY(0);
    }

    .domain-list-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 9px 12px;
        border-radius: 7px;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .domain-list-item:hover {
        background: rgba(255, 255, 255, 0.05);
    }

    .domain-list-item.active {
        background: rgba(255, 255, 255, 0.06);
    }

    .domain-list-item-left {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .domain-list-item-name {
        font-family: 'Rajdhani', sans-serif;
        font-size: 0.82rem;
        font-weight: 600;
        color: rgba(255, 255, 255, 0.7);
    }

    .domain-list-item.active .domain-list-item-name {
        color: rgba(255, 255, 255, 0.9);
    }

    .domain-list-item-icons i {
        font-size: 0.7rem;
        color: rgba(255, 255, 255, 0.15);
        margin-left: 3px;
    }

    .domain-list-select-btn {
        font-family: 'Rajdhani', sans-serif;
        font-size: 0.6rem;
        font-weight: 700;
        letter-spacing: 1px;
        text-transform: uppercase;
        color: rgba(255, 255, 255, 0.4);
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 5px;
        padding: 4px 10px;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .domain-list-select-btn:hover {
        background: rgba(255, 255, 255, 0.1);
        color: rgba(255, 255, 255, 0.7);
    }

    .domain-list-item.active .domain-list-select-btn {
        color: rgba(255, 255, 255, 0.25);
        pointer-events: none;
    }

    .domain-name-line {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0;
    }

    .domain-name {
        font-family: 'Rajdhani', sans-serif;
        font-size: 1.3rem;
        font-weight: 600;
        color: rgba(255, 255, 255, 0.88);
        display: inline;
        opacity: 1;
        transition: opacity 0.2s ease;
    }

    .domain-name.changing {
        opacity: 0;
    }

    .domain-name.fade-in {
        animation: domainFadeIn 0.6s ease-out forwards;
    }

    @keyframes domainFadeIn {
        0% { color: rgba(255, 255, 255, 0.2); }
        100% { color: rgba(255, 255, 255, 0.88); }
    }

    .domain-selected {
        display: block;
        margin-top: 6px;
        font-family: 'Rajdhani', sans-serif;
        font-size: 10px;
        font-weight: 600;
        letter-spacing: 3px;
        text-transform: uppercase;
        color: rgba(255, 255, 255, 0.15);
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .domain-selected.show {
        opacity: 1;
    }

    .domain-icons {
        display: inline;
        margin-left: 3px;
        vertical-align: middle;
    }

    .domain-icons i {
        font-size: 1.1rem;
        color: rgba(255, 255, 255, 0.16);
        margin-left: 3px;
        transition: opacity 0.2s ease;
        position: relative;
        cursor: default;
    }

    .domain-icons i:first-child {
        margin-left: 0;
    }

    .domain-icons i::after {
        content: attr(data-name);
        position: absolute;
        bottom: calc(100% + 6px);
        left: 50%;
        transform: translateX(-50%) scale(0.9);
        font-family: 'Rajdhani', sans-serif;
        font-size: 0.65rem;
        font-weight: 500;
        letter-spacing: 0.03em;
        color: rgba(255, 255, 255, 0.35);
        white-space: nowrap;
        opacity: 0;
        pointer-events: none;
        transition: all 0.2s ease;
    }

    .domain-icons i:hover::after {
        opacity: 1;
        transform: translateX(-50%) scale(1);
    }

    .domain-icons.changing i {
        opacity: 0;
    }

    .domain-icons.fade-in i {
        animation: iconFadeIn 0.6s ease-out forwards;
    }

    @keyframes iconFadeIn {
        0% { color: rgba(255, 255, 255, 0.05); }
        100% { color: rgba(255, 255, 255, 0.16); }
    }

    .domain-dots {
        display: flex;
        justify-content: center;
        gap: 6px;
        margin-top: 10px;
    }

    .domain-dot {
        width: 6px;
        height: 6px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .domain-dot:hover {
        background: rgba(255, 255, 255, 0.3);
    }

    .domain-dot.active {
        background: rgba(255, 255, 255, 0.5);
        transform: scale(1.2);
    }

    .tab-loading-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(10, 11, 15, 0.9);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 100;
        border-radius: 12px;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.2s ease, visibility 0.2s ease;
    }

    .tab-loading-overlay.show {
        opacity: 1;
        visibility: visible;
    }

    .loading-dots {
        display: flex;
        gap: 6px;
    }

    .loading-dots span {
        width: 8px;
        height: 8px;
        background: rgba(255, 255, 255, 0.4);
        border-radius: 50%;
        animation: loadingDot 1s ease-in-out infinite;
    }

    .loading-dots span:nth-child(1) { animation-delay: 0s; }
    .loading-dots span:nth-child(2) { animation-delay: 0.2s; }
    .loading-dots span:nth-child(3) { animation-delay: 0.4s; }

    @keyframes loadingDot {
        0%, 100% { opacity: 0.3; transform: scale(0.8); }
        50% { opacity: 1; transform: scale(1.2); }
    }

    @media (max-width: 768px) {
        .domain-slider-container { gap: 8px; margin-bottom: 18px; }
        .domain-arrow { width: 30px; height: 30px; border-radius: 8px; font-size: 0.75rem; }
        .domain-card { min-width: 180px; padding: 12px 20px; border-radius: 10px; }
        .domain-name { font-size: 1rem; }
        .domain-icons i { font-size: 0.85rem; }
        .domain-selected { font-size: 8px; letter-spacing: 2px; margin-top: 4px; }
        .domain-dots { gap: 5px; margin-top: 8px; }
        .domain-dot { width: 5px; height: 5px; }
        .domain-list-btn { width: 20px; height: 20px; font-size: 0.5rem; top: -5px; right: -5px; }
        .domain-list-dropdown { min-width: 180px; }
        .domain-list-item-name { font-size: 0.75rem; }
    }
    @media (max-width: 480px) {
        .domain-slider-container { gap: 6px; margin-bottom: 14px; }
        .domain-arrow { width: 26px; height: 26px; border-radius: 6px; font-size: 0.65rem; }
        .domain-card { min-width: 150px; padding: 10px 16px; border-radius: 8px; }
        .domain-name { font-size: 0.85rem; }
        .domain-icons i { font-size: 0.75rem; }
        .domain-selected { font-size: 7px; letter-spacing: 1.5px; }
        .domain-dots { gap: 4px; margin-top: 6px; }
        .domain-dot { width: 4px; height: 4px; }
        .domain-list-btn { width: 18px; height: 18px; font-size: 0.45rem; top: -4px; right: -4px; }
        .domain-list-dropdown { min-width: 160px; }
        .domain-list-item { padding: 8px 10px; }
        .domain-list-item-name { font-size: 0.7rem; }
        .domain-list-select-btn { font-size: 0.5rem; padding: 3px 8px; }
    }
    @media (max-width: 360px) {
        .domain-slider-container { gap: 4px; margin-bottom: 12px; }
        .domain-arrow { width: 24px; height: 24px; font-size: 0.6rem; }
        .domain-card { min-width: 130px; padding: 8px 12px; }
        .domain-name { font-size: 0.78rem; }
        .domain-icons i { font-size: 0.68rem; }
        .domain-list-btn { width: 16px; height: 16px; font-size: 0.4rem; }
        .domain-list-dropdown { min-width: 140px; }
    }
</style>

<div class="domain-slider-container domain-slider-pending" id="domainSliderContainer">
    <button class="domain-arrow domain-prev" onclick="changeDomain(-1)">
        <i class="fas fa-chevron-left"></i>
    </button>
    <div class="domain-card">
        <button class="domain-list-btn" onclick="toggleDomainList(event)" title="All domains"><i class="fas fa-th-list"></i></button>
        <div class="domain-list-dropdown" id="domainListDropdown">
            <?php foreach ($parsedDomains as $index => $d): ?>
                <div class="domain-list-item <?= $index === 0 ? 'active' : '' ?>" data-index="<?= $index ?>">
                    <div class="domain-list-item-left">
                        <span class="domain-list-item-name"><?= htmlspecialchars($d['name']) ?></span>
                        <span class="domain-list-item-icons"><?php foreach ($d['icons'] as $ic): ?><i class="<?= $iconMap[$ic] ?? '' ?>"></i><?php endforeach; ?></span>
                    </div>
                    <button class="domain-list-select-btn" onclick="selectDomainFromList(<?= $index ?>, event)"><?= $index === 0 ? 'SELECTED' : 'SELECT' ?></button>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="domain-name-line"><span class="domain-name" id="currentDomain"><?= htmlspecialchars($parsedDomains[0]['name'] ?? '') ?></span><span class="domain-icons" id="domainIcons"><?php foreach (($parsedDomains[0]['icons'] ?? []) as $ic): ?><i class="<?= $iconMap[$ic] ?? '' ?>" data-name="<?= ucfirst($ic) ?>"></i><?php endforeach; ?></span></div>
        <span class="domain-selected show" id="domainSelected">SELECTED</span>
        <div class="domain-dots" id="domainDots">
            <?php foreach ($parsedDomains as $index => $d): ?>
                <span class="domain-dot <?= $index === 0 ? 'active' : '' ?>" onclick="goToDomain(<?= $index ?>)"></span>
            <?php endforeach; ?>
        </div>
    </div>
    <button class="domain-arrow domain-next" onclick="changeDomain(1)">
        <i class="fas fa-chevron-right"></i>
    </button>
</div>

<script>
    const domains = <?= json_encode(array_column($parsedDomains, 'name')) ?>;
    const parsedDomains = <?= json_encode($parsedDomains) ?>;
    const iconMap = <?= json_encode($iconMap) ?>;
    function getSavedDomainIndex() {
        try {
            const savedIndex = localStorage.getItem('selectedDomainIndex');
            if (savedIndex !== null) {
                const index = parseInt(savedIndex, 10);
                if (index >= 0 && index < domains.length) return index;
            }
        } catch(e) {}
        return 0;
    }

    let currentDomainIndex = getSavedDomainIndex();
    let isAnimating = false;

    function renderIcons(icons) {
        return icons.map(function(ic) {
            return '<i class="' + (iconMap[ic] || '') + '" data-name="' + ic.charAt(0).toUpperCase() + ic.slice(1) + '"></i>';
        }).join('');
    }

    function getCurrentDomain() {
        return domains[currentDomainIndex] || '';
    }

    function applyInitialDomainSelection() {
        const domainName = document.getElementById('currentDomain');
        const domainIcons = document.getElementById('domainIcons');
        const slider = document.getElementById('domainSliderContainer');
        if (domainName) domainName.textContent = domains[currentDomainIndex] || '';
        if (domainIcons && parsedDomains[currentDomainIndex]) {
            domainIcons.innerHTML = renderIcons(parsedDomains[currentDomainIndex].icons);
        }
        updateDots();
        updateDomainListActive();
        if (slider) slider.classList.remove('domain-slider-pending');
    }

    applyInitialDomainSelection();

    document.addEventListener('DOMContentLoaded', function() {
        applyInitialDomainSelection();
        var selected = document.getElementById('domainSelected');
        if (selected) selected.classList.add('show');
        addLoadingOverlays();
    });

    function addLoadingOverlays() {
        document.querySelectorAll('.tab-content .card').forEach(card => {
            if (!card.querySelector('.tab-loading-overlay')) {
                card.style.position = 'relative';
                const overlay = document.createElement('div');
                overlay.className = 'tab-loading-overlay';
                overlay.innerHTML = '<div class="loading-dots"><span></span><span></span><span></span></div>';
                card.appendChild(overlay);
            }
        });
    }

    function showTabLoading() {
        const activeTab = document.querySelector('.tab-content:not(.hidden)');
        if (activeTab) {
            const overlay = activeTab.querySelector('.tab-loading-overlay');
            if (overlay) {
                overlay.classList.add('show');
                setTimeout(() => { overlay.classList.remove('show'); }, 1000);
            }
        }
    }

    function changeDomain(direction) {
        if (isAnimating) return;
        isAnimating = true;

        const domainName = document.getElementById('currentDomain');
        const domainIcons = document.getElementById('domainIcons');
        const selectedText = document.getElementById('domainSelected');

        showTabLoading();
        domainName.classList.remove('fade-in');
        domainIcons.classList.remove('fade-in');
        selectedText.classList.remove('show');
        domainName.classList.add('changing');
        domainIcons.classList.add('changing');

        setTimeout(function() {
            currentDomainIndex += direction;
            if (currentDomainIndex < 0) currentDomainIndex = domains.length - 1;
            else if (currentDomainIndex >= domains.length) currentDomainIndex = 0;

            localStorage.setItem('selectedDomainIndex', currentDomainIndex);
            domainName.textContent = domains[currentDomainIndex];
            domainIcons.innerHTML = renderIcons(parsedDomains[currentDomainIndex].icons);
            domainName.classList.remove('changing');
            domainIcons.classList.remove('changing');
            void domainName.offsetWidth;
            domainName.classList.add('fade-in');
            domainIcons.classList.add('fade-in');
            updateDots();

            setTimeout(function() {
                selectedText.classList.add('show');
                isAnimating = false;
            }, 600);
        }, 200);
    }

    function goToDomain(index) {
        if (isAnimating || index === currentDomainIndex) return;
        isAnimating = true;

        const domainName = document.getElementById('currentDomain');
        const domainIcons = document.getElementById('domainIcons');
        const selectedText = document.getElementById('domainSelected');

        showTabLoading();
        domainName.classList.remove('fade-in');
        domainIcons.classList.remove('fade-in');
        selectedText.classList.remove('show');
        domainName.classList.add('changing');
        domainIcons.classList.add('changing');

        setTimeout(function() {
            currentDomainIndex = index;
            localStorage.setItem('selectedDomainIndex', currentDomainIndex);
            domainName.textContent = domains[currentDomainIndex];
            domainIcons.innerHTML = renderIcons(parsedDomains[currentDomainIndex].icons);
            domainName.classList.remove('changing');
            domainIcons.classList.remove('changing');
            void domainName.offsetWidth;
            domainName.classList.add('fade-in');
            domainIcons.classList.add('fade-in');
            updateDots();

            setTimeout(function() {
                selectedText.classList.add('show');
                isAnimating = false;
            }, 600);
        }, 200);
    }

    function updateDots() {
        document.querySelectorAll('.domain-dot').forEach((dot, index) => {
            dot.classList.toggle('active', index === currentDomainIndex);
        });
    }

    function toggleDomainList(e) {
        e.stopPropagation();
        var dropdown = document.getElementById('domainListDropdown');
        var btn = e.currentTarget;
        dropdown.classList.toggle('show');
        btn.classList.toggle('active');
    }

    function updateDomainListActive() {
        document.querySelectorAll('.domain-list-item').forEach(function(item, index) {
            var btn = item.querySelector('.domain-list-select-btn');
            if (index === currentDomainIndex) {
                item.classList.add('active');
                btn.textContent = 'SELECTED';
            } else {
                item.classList.remove('active');
                btn.textContent = 'SELECT';
            }
        });
    }

    function selectDomainFromList(index, e) {
        e.stopPropagation();
        if (index === currentDomainIndex) return;
        goToDomain(index);
        updateDomainListActive();
        setTimeout(function() {
            document.getElementById('domainListDropdown').classList.remove('show');
            document.querySelector('.domain-list-btn').classList.remove('active');
        }, 300);
    }

    document.addEventListener('click', function(e) {
        var dropdown = document.getElementById('domainListDropdown');
        var btn = document.querySelector('.domain-list-btn');
        if (dropdown && !dropdown.contains(e.target) && !btn.contains(e.target)) {
            dropdown.classList.remove('show');
            btn.classList.remove('active');
        }
    });

    var origGoToDomain = goToDomain;
    var origChangeDomain = changeDomain;

    var _origUpdateDots = updateDots;
    var __origUpdateDots = updateDots;
    document.addEventListener('DOMContentLoaded', function() {
        updateDomainListActive();
    });

    var domainObserver = setInterval(function() {
        updateDomainListActive();
    }, 700);
</script>
