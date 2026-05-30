/**
 * 前端交互层。
 *
 * 管理查询历史、收藏状态、队友解析和历史赛季补全队列。所有用户或接口返回文本在写入
 * HTML 前必须转义，异步队列按服务端 Key 池容量降频，避免无意义地堆积 PHP 请求。
 */

document.addEventListener('DOMContentLoaded', () => {

    /**
     * 统一的轻量反馈组件，避免在批量刷新和删除操作中阻塞主流程。
     */
    function showToast(message, type = 'success') {
        let container = document.querySelector('.toast-container');
        if (!container) {
            container           = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
        const toast     = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerText = message;
        container.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'toastFadeOut 0.3s forwards';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    const queryForm = document.querySelector('.query-form');
    if (queryForm) {
        queryForm.addEventListener('submit', function() {
            document.querySelector('.loading').classList.add('show');
            const errorBox = document.querySelector('.error');
            if (errorBox) errorBox.classList.remove('show');
            const resCard  = document.querySelector('.result-card');
            if (resCard) resCard.classList.remove('show');
        });
    }

    /**
     * 本地历史记录和收藏夹只保存展示所需字段；读取时容错清理损坏 JSON，防止页面初始化崩溃。
     */
    const HISTORY_KEY = 'pubg_plus_history';
    const FAV_KEY     = 'pubg_plus_favorites';
    let selectedIds   = new Set();
    let isEditMode    = false; 
    let scrollTimeout = null; 
    const historySteamVacPending = new Set();
    
    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[char]));
    }

    function getApiKeyCount() {
        return Math.max(1, Number((window.PUBG_APP_CONTEXT || {}).apiKeyCount || 1));
    }

    function getApiKeyRpm() {
        return Math.max(1, Number((window.PUBG_APP_CONTEXT || {}).apiKeyRpm || 10));
    }

    function formatCheckedTime(timestamp) {
        const value = Number(timestamp || 0) * 1000;
        if (!value) return '';
        const date = new Date(value);
        return `${String(date.getMinutes()).padStart(2, '0')}分${String(date.getSeconds()).padStart(2, '0')}秒`;
    }

    function formatDuration(seconds) {
        const safeSeconds = Math.max(0, Math.floor(Number(seconds || 0)));
        return `${String(Math.floor(safeSeconds / 60)).padStart(2, '0')}m${String(safeSeconds % 60).padStart(2, '0')}s`;
    }

    function formatSteamVacUnlockLabel(data) {
        if (!data || !data.temporary_detected_at) return '';
        const label = String(data.unlock_label || '').trim();
        return label && !/^\d+(?:\.\d+)?$/.test(label) ? label : '';
    }

    function renderSteamVacResultText(resultEl, text, showAccuracyHint = false) {
        resultEl.textContent = text;
        if (!showAccuracyHint) return;

        const hint = document.createElement('span');
        hint.className = 'steam-vac-hint';
        hint.tabIndex = 0;
        hint.title = '解禁时间有5分钟误差，可点击上方刷新按钮获取接口实时数据';
        hint.setAttribute('aria-label', hint.title);
        hint.textContent = '!';
        resultEl.appendChild(hint);
    }

    function mapSteamVacBanStatus(data) {
        const state = data && (data.vac_state || data.status);
        if (state === 'clear') return '未封禁';
        if (state === 'temporary') return '临时封禁';
        if (state === 'permanent') return '永久封禁';
        return '';
    }

    function getHistoryDisplayBanStatus(item) {
        return item.steamVacBanStatus || item.banStatus || '未封禁';
    }

    function updateStoredHistorySteamVacStatus(accountId, data) {
        const history = getHistory();
        const index = history.findIndex(item => item.accountId === accountId);
        if (index === -1) return false;

        const item = history[index];
        const previous = JSON.stringify({
            steamVacBanStatus: item.steamVacBanStatus || '',
            steamVacState: item.steamVacState || '',
            steamVacSyncedAt: item.steamVacSyncedAt || 0
        });
        const statusLabel = mapSteamVacBanStatus(data);

        item.steamVacSyncedAt = Date.now();
        if (statusLabel) {
            item.steamVacBanStatus = statusLabel;
            item.steamVacState = data.vac_state || data.status || '';
            item.steamVacCheckedAt = data.checked_at || null;
        } else if ((data && (data.vac_state || data.status)) === 'not_configured') {
            delete item.steamVacBanStatus;
            delete item.steamVacState;
            delete item.steamVacCheckedAt;
        }

        const current = JSON.stringify({
            steamVacBanStatus: item.steamVacBanStatus || '',
            steamVacState: item.steamVacState || '',
            steamVacSyncedAt: item.steamVacSyncedAt || 0
        });
        if (previous === current) return false;

        localStorage.setItem(HISTORY_KEY, JSON.stringify(history));
        return true;
    }

    function renderSteamVacStatus(data) {
        const resultEl = document.getElementById('steamVacResult');
        const metaEl = document.getElementById('steamVacMeta');
        if (!resultEl || !metaEl) return;

        const state = data.vac_state || data.status || 'unknown';
        resultEl.dataset.state = state;

        const unlockLabel = formatSteamVacUnlockLabel(data);
        if (state === 'temporary' && unlockLabel) {
            renderSteamVacResultText(resultEl, `临时封禁 · ${unlockLabel}解禁`, true);
        } else {
            renderSteamVacResultText(resultEl, data.label || '无法确认 Steam VAC 状态');
        }

        const checked = data.checked_at ? `校验时间 ${formatCheckedTime(data.checked_at)}` : '尚未校验';
        const sourceType = data.from_cache ? '缓存' : '实时';
        const latency = data.latency_ms ? ` · 接口耗时 ${data.latency_ms}ms` : '';
        metaEl.textContent = `${checked} · ${sourceType}${latency}`;

        const panel = document.getElementById('steamVacCheck');
        const accountId = panel ? panel.dataset.accountId : '';
        if (accountId) updateStoredHistorySteamVacStatus(accountId, data);
        updateSteamVacCountdown(data);
    }

    let steamVacAutoTimer = null;

    function scheduleSteamVacAutoRefresh(nextRefreshAt) {
        if (steamVacAutoTimer) {
            clearTimeout(steamVacAutoTimer);
        }
        const delayMs = Math.max(1000, (Number(nextRefreshAt) - Math.floor(Date.now() / 1000)) * 1000);
        steamVacAutoTimer = setTimeout(() => refreshSteamVacStatus(false), delayMs);
    }

    function updateSteamVacCountdown(data) {
        const panel = document.getElementById('steamVacCheck');
        const nextEl = document.getElementById('steamVacNextRefresh');
        if (!panel || !nextEl) return;

        const checkedAt = Number(data.checked_at || Math.floor(Date.now() / 1000));
        const interval = Math.max(180, Number(data.poll_seconds || 180));
        const nextRefreshAt = checkedAt + interval;
        panel.dataset.nextRefreshAt = String(nextRefreshAt);
        scheduleSteamVacAutoRefresh(nextRefreshAt);
        renderSteamVacCountdown();
    }

    function renderSteamVacCountdown() {
        const panel = document.getElementById('steamVacCheck');
        const nextEl = document.getElementById('steamVacNextRefresh');
        if (!panel || !nextEl || !panel.dataset.nextRefreshAt) return;

        const left = Number(panel.dataset.nextRefreshAt) - Math.floor(Date.now() / 1000);
        nextEl.textContent = formatDuration(left);
    }

    async function refreshSteamVacStatus(force = false) {
        const panel = document.getElementById('steamVacCheck');
        const button = document.getElementById('steamVacRefreshBtn');
        const resultEl = document.getElementById('steamVacResult');
        const metaEl = document.getElementById('steamVacMeta');
        if (!panel || !resultEl || !metaEl) return;

        const accountId = panel.dataset.accountId || (window.PUBG_APP_CONTEXT || {}).accountId || '';
        if (!accountId) {
            panel.hidden = true;
            return;
        }

        if (button) button.disabled = true;
        resultEl.dataset.state = 'loading';
        resultEl.textContent = force ? '正在手动刷新 Steam VAC接口...' : '正在校验 Steam VAC接口...';

        try {
            const response = await fetch(`core/engine.php?action=steam_vac_status&account_id=${encodeURIComponent(accountId)}&force=${force ? '1' : '0'}`, { cache: 'no-store' });
            const data = await response.json();
            renderSteamVacStatus(data);
        } catch (error) {
            renderSteamVacStatus({
                status: 'error',
                vac_state: 'error',
                label: 'Steam 客服校验失败',
                detail: '网络请求或 JSON 解析失败',
                checked_at: Math.floor(Date.now() / 1000)
            });
        } finally {
            if (button) button.disabled = false;
        }
    }

    const steamVacPanel = document.getElementById('steamVacCheck');
    if (steamVacPanel && !window.__PUBG_STEAM_VAC_STARTED__) {
        window.__PUBG_STEAM_VAC_STARTED__ = true;
        const steamVacButton = document.getElementById('steamVacRefreshBtn');
        if (steamVacButton) {
            steamVacButton.addEventListener('click', () => refreshSteamVacStatus(true));
        }
        refreshSteamVacStatus(false);
        setInterval(renderSteamVacCountdown, 1000);
    }

    function readJsonList(storageKey) {
        try {
            const parsed = JSON.parse(localStorage.getItem(storageKey) || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            localStorage.removeItem(storageKey);
            return [];
        }
    }

    function getHistory()   { return readJsonList(HISTORY_KEY); }
    function getFavorites() { return readJsonList(FAV_KEY); }
    
    function saveToHistory(playerData) {
        let history = getHistory();
        const existing = history.find(item => item.accountId === playerData.accountId);
        if (existing) {
            ['steamVacBanStatus', 'steamVacState', 'steamVacCheckedAt', 'steamVacSyncedAt'].forEach(key => {
                if (existing[key] !== undefined) playerData[key] = existing[key];
            });
        }
        history     = history.filter(item => item.accountId !== playerData.accountId);
        history.unshift(playerData);
        if (history.length > 50) history.pop();
        localStorage.setItem(HISTORY_KEY, JSON.stringify(history));
    }

    function toggleFavorite(accountId) {
        let favs = getFavorites();
        if (favs.includes(accountId)) {
            favs = favs.filter(id => id !== accountId);
        } else {
            favs.push(accountId);
        }
        localStorage.setItem(FAV_KEY, JSON.stringify(favs));
    }

    function toggleSelect(accountId) {
        if (selectedIds.has(accountId)) {
            selectedIds.delete(accountId);
        } else {
            selectedIds.add(accountId);
        }
        renderHistoryModal();
    }

    function handleListInteraction(e) {
        const itemEl = e.target.closest('.history-item');
        if (!itemEl) return;

        const accId = itemEl.dataset.accountId;
        const name  = itemEl.dataset.name;
        const shard = itemEl.dataset.shard;

        const starEl = e.target.closest('.hi-star');
        if (starEl) {
            toggleFavorite(accId);
            starEl.classList.toggle('active');
            
            const selectorId = window.CSS && CSS.escape ? CSS.escape(accId) : accId.replace(/"/g, '\\"');
            document.querySelectorAll(`.history-item[data-account-id="${selectorId}"] .hi-star`).forEach(el => {
                if (el !== starEl) {
                    if (starEl.classList.contains('active')) el.classList.add('active');
                    else el.classList.remove('active');
                }
            });
            return;
        }

        if (isEditMode) {
            toggleSelect(accId);
        } else {
            window.location = `?shard=${shard}&nickname=${encodeURIComponent(name)}`;
        }
    }

    const hList = document.getElementById('history-list');
    const fList = document.getElementById('fav-list');
    if (hList) hList.addEventListener('click', handleListInteraction);
    if (fList) fList.addEventListener('click', handleListInteraction);

    const scrollContainer = document.getElementById('historyScrollContainer');
    if (scrollContainer) {
        scrollContainer.addEventListener('scroll', () => {
            scrollContainer.classList.add('is-scrolling');
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(() => {
                scrollContainer.classList.remove('is-scrolling');
            }, 150);
        });
    }

    /**
     * 生成并刷新弹窗页的组件视图树
     * 运用 DocumentFragment 缓存 DOM 并挂载，避免大批量条目变动导致的卡顿重排
     */
    function renderHistoryModal() {
        const historyList = document.getElementById('history-list');
        const favList     = document.getElementById('fav-list');
        if (!historyList || !favList) return;

        const history         = getHistory();
        const favs            = getFavorites();
        const onlyChangedElem = document.getElementById('onlyChangedCheck');
        const showOnlyChanged = onlyChangedElem ? onlyChangedElem.checked : false;

        const platIcons = {
            'steam': 'https://p0.meituan.net/poiugc/620e3c83b0c01d086334433e0185ae772320.png',
            'kakao': 'https://p0.meituan.net/poiugc/b3bb75065fd06aac94e9157e66244a7e2120.png',
            'xbox':  'https://p0.meituan.net/poiugc/8cd37b70df73fb9d97fbde54848e73e22101.png',
            'psn':   'https://p0.meituan.net/poiugc/c1017cfed28479f0680276efeee49f903117.png' 
        };

        const tierImgs = {
            1: 'https://p0.meituan.net/poiugc/f70becfa1ea550a489019df69aa59b4616960.png',
            2: 'https://p0.meituan.net/poiugc/0fc06ee9c68a02183bd9ecde6c5a8a1e14562.png',
            3: 'https://p0.meituan.net/poiugc/698daaa5eafb158fee673121b1612ea111742.png',
            4: 'https://p0.meituan.net/poiugc/3471fc609b25c733311f1ba72fd230dd14357.png',
            5: 'https://p0.meituan.net/poiugc/4d431da878d0f778d495f01f03d053c813035.png'
        };

        const buildFragment = (itemsArray) => {
            const fragment = document.createDocumentFragment();
            if (itemsArray.length === 0) {
                const emptyDiv     = document.createElement('div');
                emptyDiv.className = 'history-empty';
                emptyDiv.innerText = '暂无记录 / 暂无匹配的玩家';
                fragment.appendChild(emptyDiv);
                return fragment;
            }

            itemsArray.forEach(item => {
                const isFav     = favs.includes(item.accountId);
                const starColor = isFav ? 'active' : '';
                
                const d       = new Date(item.time);
                const timeStr = `${d.getFullYear()}年${String(d.getMonth()+1).padStart(2, '0')}月${String(d.getDate()).padStart(2, '0')}日${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
                
                let banClass  = 'normal';
                let rawBanStr = getHistoryDisplayBanStatus(item);
                if (rawBanStr.includes('永久封禁') || rawBanStr.includes('永久')) {
                    banClass = 'permanent';
                } else if (rawBanStr.includes('临时封禁') || rawBanStr.includes('暂停')) {
                    banClass = 'temporary';
                } else if (rawBanStr.includes('未封禁') || rawBanStr.includes('正常')) {
                    banClass = 'normal';
                }

                let tLevel   = item.tier ? parseInt(item.tier) : 1;
                let imgLevel = tLevel > 5 ? 5 : (tLevel < 1 ? 1 : tLevel);
                let tierImg  = tierImgs[imgLevel];
                let platImg  = platIcons[item.shard] || platIcons['steam'];
                
                const safeName = escapeHtml(item.name || '');
                const safeRawBan = escapeHtml(rawBanStr);
                let banHtml = `<span class="ban-badge ${banClass}">${safeRawBan}</span>`;
                if (item.isChanged && item.oldBanStatus && item.oldBanStatus !== rawBanStr) {
                    let oldBanClass = 'normal';
                    if (item.oldBanStatus.includes('永久封禁') || item.oldBanStatus.includes('永久')) {
                        oldBanClass = 'permanent';
                    } else if (item.oldBanStatus.includes('临时封禁') || item.oldBanStatus.includes('暂停')) {
                        oldBanClass = 'temporary';
                    }
                    banHtml = `<span class="ban-badge ${oldBanClass}">${escapeHtml(item.oldBanStatus)}</span><span class="ban-transition-arrow">→</span><span class="ban-badge ${banClass}">${safeRawBan}</span>`;
                }

                const isChecked    = selectedIds.has(item.accountId) ? 'checked' : '';
                const checkboxHtml = isEditMode 
                    ? `<input type="checkbox" class="item-checkbox" ${isChecked} tabindex="-1">` 
                    : '<span class="item-checkbox-spacer" aria-hidden="true"></span>';

                const div         = document.createElement('div');
                div.className     = `history-item ${item.isChanged ? 'has-changed' : ''}`;
                div.dataset.accountId = item.accountId;
                div.dataset.name  = item.name;
                div.dataset.shard = item.shard;
                div.innerHTML     = `
                    ${checkboxHtml}
                    <div class="hi-content">
                        <div class="hi-row1">
                            <img src="${platImg}" class="hi-plat-icon"> 
                            <span class="hi-name">${safeName}</span>
                        </div>
                        <div class="hi-row2">
                            <div class="hi-lvl-box">
                                <img src="${tierImg}" class="hi-tier-icon">
                                <span>Lv.${escapeHtml(item.level)}</span>
                            </div>
                            <div class="hi-ban-box">
                                ${banHtml}
                            </div>
                        </div>
                        <div class="hi-row3">
                            ${timeStr}
                        </div>
                    </div>
                    <div class="hi-star ${starColor}">★</div>
                `;
                fragment.appendChild(div);
            });
            return fragment;
        };

        historyList.innerHTML = '';
        historyList.appendChild(buildFragment(history));

        let favItems = history.filter(item => favs.includes(item.accountId));
        if (showOnlyChanged) {
            favItems = favItems.filter(item => item.isChanged);
        }
        
        favList.innerHTML = '';
        favList.appendChild(buildFragment(favItems));
        syncHistorySteamVacStatuses(history);
    }

    function syncHistorySteamVacStatuses(items) {
        const now = Date.now();
        const candidates = items.filter(item => (
            item &&
            item.shard === 'steam' &&
            item.accountId &&
            !historySteamVacPending.has(item.accountId) &&
            now - Number(item.steamVacSyncedAt || 0) > 60000
        ));
        if (candidates.length === 0) return;

        let changed = false;
        Promise.all(candidates.map(item => {
            historySteamVacPending.add(item.accountId);
            return fetch(`core/engine.php?action=steam_vac_status&account_id=${encodeURIComponent(item.accountId)}`, { cache: 'no-store' })
                .then(res => res.ok ? res.json() : null)
                .then(data => {
                    if (data && updateStoredHistorySteamVacStatus(item.accountId, data)) {
                        changed = true;
                    }
                })
                .catch(() => {})
                .finally(() => historySteamVacPending.delete(item.accountId));
        })).then(() => {
            if (changed) renderHistoryModal();
        });
    }

    const historyModal = document.getElementById('historyModal');
    const historyBtn   = document.getElementById('historyBtn');
    
    if (historyBtn) {
        historyBtn.addEventListener('click', () => {
            isEditMode = false;
            selectedIds.clear();
            document.getElementById('footerDefault').style.display = 'flex';
            document.getElementById('footerEdit').style.display    = 'none';
            renderHistoryModal();
            requestAnimationFrame(() => {
                historyModal.classList.add('show');
            });
        });
    }
    
    const closeHistoryModal = document.getElementById('closeHistoryModal');
    if (closeHistoryModal) {
        closeHistoryModal.addEventListener('click', () => {
            historyModal.classList.remove('show');
        });
    }

    if (historyModal) {
        historyModal.addEventListener('click', (e) => {
            if (e.target === historyModal) {
                historyModal.classList.remove('show');
            }
        });
    }
    
    const enterEditModeBtn = document.getElementById('enterEditModeBtn');
    if (enterEditModeBtn) {
        enterEditModeBtn.addEventListener('click', () => {
            isEditMode = true;
            document.getElementById('footerDefault').style.display = 'none';
            document.getElementById('footerEdit').style.display    = 'flex';
            renderHistoryModal();
        });
    }

    const selectAllBtn = document.getElementById('selectAllBtn');
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', () => {
            const activeTab = document.querySelector('.ht-tab.active').dataset.target;
            const history   = getHistory();
            const favs      = getFavorites();
            
            let itemsToSelect = [];
            if (activeTab === 'history-list') {
                itemsToSelect = history;
            } else {
                itemsToSelect = history.filter(item => favs.includes(item.accountId));
                const showOnlyChanged = document.getElementById('onlyChangedCheck').checked;
                if (showOnlyChanged) {
                    itemsToSelect = itemsToSelect.filter(item => item.isChanged);
                }
            }
            
            itemsToSelect.forEach(i => selectedIds.add(i.accountId));
            renderHistoryModal();
        });
    }

    const cancelSelectBtn = document.getElementById('cancelSelectBtn');
    if (cancelSelectBtn) {
        cancelSelectBtn.addEventListener('click', () => {
            isEditMode = false;
            selectedIds.clear();
            document.getElementById('footerDefault').style.display = 'flex';
            document.getElementById('footerEdit').style.display    = 'none';
            renderHistoryModal();
        });
    }

    const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
    if (deleteSelectedBtn) {
        deleteSelectedBtn.addEventListener('click', () => {
            if (selectedIds.size === 0) return showToast('未选中任何记录', 'info');
            
            const activeTab = document.querySelector('.ht-tab.active').dataset.target;
            let history     = getHistory();
            let favs        = getFavorites();
            
            if (activeTab === 'history-list') {
                history = history.filter(i => !selectedIds.has(i.accountId));
                favs    = favs.filter(id => !selectedIds.has(id));
                localStorage.setItem(HISTORY_KEY, JSON.stringify(history));
                localStorage.setItem(FAV_KEY, JSON.stringify(favs));
            } else {
                favs    = favs.filter(id => !selectedIds.has(id));
                localStorage.setItem(FAV_KEY, JSON.stringify(favs));
            }
            
            isEditMode = false;
            selectedIds.clear();
            document.getElementById('footerDefault').style.display = 'flex';
            document.getElementById('footerEdit').style.display    = 'none';
            renderHistoryModal();
            showToast('已成功删除选中记录', 'success');
        });
    }
    
    const onlyChangedCheck = document.getElementById('onlyChangedCheck');
    if (onlyChangedCheck) {
        onlyChangedCheck.addEventListener('change', () => {
            selectedIds.clear(); 
            renderHistoryModal();
        });
    }
    
    /**
     * 执行批量的历史状态刷新轮询
     * 通过并发降频和时延阻塞控制，避免触碰 PUBG API 的速率阈值（Rate Limit - 429 Error）
     */
    const batchBtn = document.getElementById('batchQueryBtn');
    if (batchBtn) {
        batchBtn.addEventListener('click', async () => {
            const favs = getFavorites();
            if (favs.length === 0) return showToast('收藏夹为空，无法执行批量查询！', 'info');

            batchBtn.innerText = '查询中...';
            batchBtn.disabled  = true;

            let history      = getHistory();
            let changedCount = 0;
            const batchDelayMs = Math.max(800, Math.ceil(12000 / getApiKeyCount()));
            
            const delay = ms => new Promise(res => setTimeout(res, ms));

            for (const accId of favs) {
                const itemIndex = history.findIndex(i => i.accountId === accId);
                if (itemIndex === -1) continue;
                const item = history[itemIndex];

                try {
                    const res  = await fetch(`core/engine.php?action=batch_query_player&account_id=${encodeURIComponent(accId)}&shard=${encodeURIComponent(item.shard)}`);
                    const data = await res.json();

                    if (data.status === 'success') {
                        let isChanged = false;
                        if (data.level != item.level || data.banStatus !== item.banStatus) {
                            isChanged    = true;
                            changedCount++;
                            history[itemIndex].oldBanStatus = item.banStatus;
                        } else {
                            delete history[itemIndex].oldBanStatus;
                        }
                        
                        history[itemIndex].level       = data.level;
                        history[itemIndex].tier        = data.tier;
                        history[itemIndex].banStatus   = data.banStatus;
                        history[itemIndex].isChanged   = isChanged;
                        history[itemIndex].time        = new Date().getTime(); 
                    }
                } catch (e) {
                    console.error('批量查询风控检测发生异常', e);
                }
                
                await delay(batchDelayMs);
            }

            localStorage.setItem(HISTORY_KEY, JSON.stringify(history));
            batchBtn.innerText = '批量查询';
            batchBtn.disabled  = false;
            renderHistoryModal();
            
            if (changedCount > 0) {
                showToast(`查询完毕！共有 ${changedCount} 名玩家发生了新动态。`, 'success');
            } else {
                showToast('查询完毕！收藏夹内的玩家暂无任何变化。', 'info');
            }
        });
    }
    
    document.querySelectorAll('.ht-tab').forEach(tab => {
        tab.addEventListener('click', (e) => {
            document.querySelectorAll('.ht-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.ht-pane').forEach(p => p.classList.remove('active'));
            e.target.classList.add('active');
            
            const targetId = e.target.dataset.target;
            document.getElementById(targetId).classList.add('active');
            
            isEditMode = false;
            selectedIds.clear();
            document.getElementById('footerDefault').style.display = 'flex';
            document.getElementById('footerEdit').style.display    = 'none';
            
            const enterEditBtn = document.getElementById('enterEditModeBtn');
            
            if(targetId === 'fav-list') {
                document.getElementById('favFilterToggle').style.display = 'inline-flex';
                document.getElementById('batchQueryBtn').style.display   = 'inline-flex';
                if (enterEditBtn) enterEditBtn.innerText = '删除收藏';
            } else {
                document.getElementById('favFilterToggle').style.display = 'none';
                document.getElementById('batchQueryBtn').style.display   = 'none';
                if (enterEditBtn) enterEditBtn.innerText = '删除历史';
            }
            
            renderHistoryModal();
        });
    });

    /**
     * 服务端注入环境变量钩子
     * 检测后台如果下发了队列节点（包含待解析的队友日志或历史赛季缺失块），则在此唤起异步补全机制
     */
    const Context = window.PUBG_APP_CONTEXT;
    if (Context && Context.hasData) {
        
        saveToHistory({
            name:        Context.name,
            accountId:   Context.accountId,
            shard:       Context.shard,
            level:       Context.level,
            tier:        Context.tier,
            banStatus:   Context.rawBanStatus,
            isChanged:   false, 
            time:        new Date().getTime()
        });

        const matchIds   = Context.recentMatchIds;
        const btnAnalyze = document.getElementById('startAnalyzeBtn');
        
        if (btnAnalyze) {
            btnAnalyze.addEventListener('click', function() {
                document.getElementById('teammateInitUI').style.display     = 'none';
                document.getElementById('teammateProgressUI').style.display = 'block';
                
                if (matchIds.length === 0) {
                    document.getElementById('teammateProgressUI').style.display = 'none';
                    const resUI = document.getElementById('teammateResultUI');
                    resUI.style.display = 'block';
                    resUI.innerHTML     = '<div style="padding: 24px; text-align: center; color: var(--text-secondary); font-size: 14px;">暂无近期游戏记录。</div>';
                    return;
                }
                
                const teammateCounts = {};
                let matchesProcessed = 0;
                
                function updateTeammateUI() {
                    const resUI = document.getElementById('teammateResultUI');
                    resUI.style.display = 'block'; 
                    
                    if (Object.keys(teammateCounts).length === 0) {
                        resUI.innerHTML = '<div style="padding: 24px; text-align: center; color: var(--text-secondary); font-size: 14px;">该玩家最近没有匹配到任何固定队友。</div>';
                        return;
                    }

                    const sorted = Object.entries(teammateCounts).sort((a, b) => b[1] - a[1]);
                    const top5   = sorted.slice(0, 5); 
                    
                    let html = '<table class="teammates-table"><tr><th>游戏昵称 (点击查房)</th><th class="tm-count">同队场次</th></tr>';
                    top5.forEach(([name, count], index) => {
                        let icon = '👤';
                        if(index === 0) icon = '🥇';
                        else if(index === 1) icon = '🥈';
                        else if(index === 2) icon = '🥉';
                        const safeName = escapeHtml(name);
                        
                        html += `<tr>
                                    <td>
                                        <a href="?shard=${encodeURIComponent(Context.shard)}&nickname=${encodeURIComponent(name)}" class="teammate-link">
                                            <span class="rank-icon">${icon}</span> ${safeName}
                                        </a>
                                    </td>
                                    <td class="tm-count">${count} 场</td>
                                 </tr>`;
                    });
                    html += '</table>';
                    resUI.innerHTML = html;
                }

                function processTeammateQueue() {
                    if (matchesProcessed >= matchIds.length) {
                        document.getElementById('teammateProgressUI').style.display = 'none';
                        updateTeammateUI();
                        return;
                    }
                    
                    const batchSize    = 6;
                    const currentBatch = matchIds.slice(matchesProcessed, matchesProcessed + batchSize);
                    
                    const promises = currentBatch.map(matchId => {
                        return fetch(`core/engine.php?action=fetch_match_stats&account_id=${encodeURIComponent(Context.accountId)}&match_id=${encodeURIComponent(matchId)}&shard=${encodeURIComponent(Context.shard)}`)
                            .then(res => res.json())
                            .then(data => {
                                if (data.status === 'success' && data.teammates) {
                                    data.teammates.forEach(tm => {
                                        teammateCounts[tm.name] = (teammateCounts[tm.name] || 0) + 1;
                                    });
                                }
                            }).catch(() => {});
                    });

                    Promise.all(promises).then(() => {
                        matchesProcessed += currentBatch.length;
                        const percent = Math.round((matchesProcessed / matchIds.length) * 100);
                        document.getElementById('analyzeProgressText').innerText  = `(${matchesProcessed}/${matchIds.length})`;
                        document.getElementById('analyzeProgressBar').style.width = percent + '%';
                        setTimeout(processTeammateQueue, 1800);
                    });
                }
                processTeammateQueue();
            });
        }

        const showJoinTimeLink  = document.getElementById('showJoinTimeModal');
        const joinTimeContainer = document.getElementById('joinTimeContainer');

        if (showJoinTimeLink) {
            showJoinTimeLink.addEventListener('click', () => {
                joinTimeContainer.innerHTML = '入坑时间: <span style="color:var(--warning-color);">后台排队追溯中...</span>';
            });
        }

        let historyProcessed = 0;
        function processHistoryQueue() {
            if (historyProcessed >= Context.missingQueue.length) {
                document.querySelector('.sync-content strong').innerText = '✅ 同步完成！刷新生效。';
                document.querySelector('.sync-spinner').style.display    = 'none';
                setTimeout(() => { window.location.reload(); }, 1500);
                return;
            }
            
            const keyCount     = getApiKeyCount();
            const batchSize    = Math.max(1, Math.min(keyCount, Context.missingQueue.length - historyProcessed));
            const batchDelayMs = Math.ceil(60000 / getApiKeyRpm()) + 100;
            const batchStarted = Date.now();
            const currentBatch = Context.missingQueue.slice(historyProcessed, historyProcessed + batchSize);
            
            const promises = currentBatch.map(target => {
                const targetId   = typeof target === 'object' ? target.id : target;
                const targetType = typeof target === 'object' ? target.type : 'normal';
                return fetch(`core/engine.php?action=fetch_history&account_id=${encodeURIComponent(Context.accountId)}&season_id=${encodeURIComponent(targetId)}&shard=${encodeURIComponent(Context.shard)}&type=${encodeURIComponent(targetType)}`)
                    .then(res => res.json())
                    .catch(() => ({status: 'error'}));
            });

            Promise.all(promises).then(results => {
                let rateLimited = results.some(r => r.status === 'rate_limit');
                if (rateLimited) {
                    setTimeout(processHistoryQueue, batchDelayMs); 
                    return;
                }
                
                historyProcessed += currentBatch.length;
                const syncLeft = document.getElementById('syncLeft');
                if (syncLeft) {
                    syncLeft.innerText = Math.max(0, Context.missingQueue.length - historyProcessed);
                }
                const elapsedMs = Date.now() - batchStarted;
                setTimeout(processHistoryQueue, Math.max(250, batchDelayMs - elapsedMs)); 
            });
        }
        
        if (Context.missingQueue && Context.missingQueue.length > 0) {
            const syncPanel = document.getElementById('syncPanel');
            if (syncPanel) {
                syncPanel.style.display = 'flex';
            }
            setTimeout(processHistoryQueue, 2000);
        }
    }
});
