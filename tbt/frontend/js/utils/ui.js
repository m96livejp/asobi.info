/**
 * カスタムモーダル (alert/confirm置き換え)
 * サイト名を表示し、ネイティブダイアログを使わない
 */
const UI = {
    APP_NAME: 'Tournament Battle',

    alert(message) {
        return new Promise(resolve => {
            const overlay = this._createOverlay();
            const modal = this._createModal();
            modal.innerHTML = `
                <div class="ui-modal-title">${this.APP_NAME}</div>
                <div class="ui-modal-body">${this._escape(message)}</div>
                <div class="ui-modal-actions">
                    <button class="ui-modal-btn ui-modal-btn-primary" id="ui-ok-btn">OK</button>
                </div>
            `;
            overlay.appendChild(modal);
            document.body.appendChild(overlay);
            const okBtn = document.getElementById('ui-ok-btn');
            okBtn.focus();
            okBtn.addEventListener('click', () => {
                overlay.remove();
                resolve();
            });
        });
    },

    confirm(message) {
        return new Promise(resolve => {
            const overlay = this._createOverlay();
            const modal = this._createModal();
            modal.innerHTML = `
                <div class="ui-modal-title">${this.APP_NAME}</div>
                <div class="ui-modal-body">${this._escape(message)}</div>
                <div class="ui-modal-actions">
                    <button class="ui-modal-btn ui-modal-btn-secondary" id="ui-cancel-btn">キャンセル</button>
                    <button class="ui-modal-btn ui-modal-btn-primary" id="ui-ok-btn">OK</button>
                </div>
            `;
            overlay.appendChild(modal);
            document.body.appendChild(overlay);
            document.getElementById('ui-ok-btn').focus();
            document.getElementById('ui-ok-btn').addEventListener('click', () => {
                overlay.remove();
                resolve(true);
            });
            document.getElementById('ui-cancel-btn').addEventListener('click', () => {
                overlay.remove();
                resolve(false);
            });
        });
    },

    // 警告用確認ダイアログ（重要メッセージを赤で強調）
    confirmDanger(message, dangerLines = [], okLabel = 'ログアウトする') {
        return new Promise(resolve => {
            const overlay = this._createOverlay();
            const modal = this._createModal();
            const bodyHtml = this._escape(message).replace(/\n/g, '<br>');
            const dangerHtml = dangerLines.map(l =>
                `<div style="color:#e53935;font-weight:700;font-size:13px;margin-top:8px;">${this._escape(l)}</div>`
            ).join('');
            modal.innerHTML = `
                <div class="ui-modal-title" style="color:#e53935;">⚠️ 警告</div>
                <div class="ui-modal-body">${bodyHtml}${dangerHtml}</div>
                <div class="ui-modal-actions">
                    <button class="ui-modal-btn ui-modal-btn-secondary" id="ui-cancel-btn">キャンセル</button>
                    <button class="ui-modal-btn" id="ui-ok-btn"
                        style="background:#e53935;color:#fff;border:none;">${this._escape(okLabel)}</button>
                </div>
            `;
            overlay.appendChild(modal);
            document.body.appendChild(overlay);
            document.getElementById('ui-cancel-btn').focus();
            document.getElementById('ui-ok-btn').addEventListener('click', () => {
                overlay.remove();
                resolve(true);
            });
            document.getElementById('ui-cancel-btn').addEventListener('click', () => {
                overlay.remove();
                resolve(false);
            });
        });
    },

    prompt(message, defaultValue = '') {
        return new Promise(resolve => {
            const overlay = this._createOverlay();
            const modal = this._createModal();
            modal.innerHTML = `
                <div class="ui-modal-title">${this.APP_NAME}</div>
                <div class="ui-modal-body">${this._escape(message)}</div>
                <div style="padding:0 16px;">
                    <input type="text" class="ui-modal-input" id="ui-prompt-input" value="${this._escape(defaultValue)}">
                </div>
                <div class="ui-modal-actions">
                    <button class="ui-modal-btn ui-modal-btn-secondary" id="ui-cancel-btn">キャンセル</button>
                    <button class="ui-modal-btn ui-modal-btn-primary" id="ui-ok-btn">OK</button>
                </div>
            `;
            overlay.appendChild(modal);
            document.body.appendChild(overlay);
            const input = document.getElementById('ui-prompt-input');
            input.focus();
            input.select();
            document.getElementById('ui-ok-btn').addEventListener('click', () => {
                overlay.remove();
                resolve(input.value);
            });
            document.getElementById('ui-cancel-btn').addEventListener('click', () => {
                overlay.remove();
                resolve(null);
            });
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { overlay.remove(); resolve(input.value); }
            });
        });
    },

    /**
     * 選択肢モーダル
     * @param {string} message - メッセージ
     * @param {Array<{label: string, value: any}>} options - 選択肢
     * @returns {Promise<any|null>} 選択された値 or null
     */
    select(message, options) {
        return new Promise(resolve => {
            const overlay = this._createOverlay();
            const modal = this._createModal();
            const btnsHtml = options.map((o, i) =>
                `<button class="ui-modal-btn ui-modal-btn-primary" data-idx="${i}" style="width:100%;margin-bottom:6px;">${this._escape(o.label)}</button>`
            ).join('');
            modal.innerHTML = `
                <div class="ui-modal-title">${this.APP_NAME}</div>
                <div class="ui-modal-body">${this._escape(message)}</div>
                <div class="ui-modal-actions" style="flex-direction:column;">
                    ${btnsHtml}
                    <button class="ui-modal-btn ui-modal-btn-secondary" id="ui-cancel-btn" style="width:100%;">キャンセル</button>
                </div>
            `;
            overlay.appendChild(modal);
            document.body.appendChild(overlay);
            modal.querySelectorAll('[data-idx]').forEach(btn => {
                btn.addEventListener('click', () => {
                    overlay.remove();
                    resolve(options[parseInt(btn.dataset.idx)].value);
                });
            });
            document.getElementById('ui-cancel-btn').addEventListener('click', () => {
                overlay.remove();
                resolve(null);
            });
        });
    },

    /**
     * キャラクター選択モーダル
     * @param {Array} characters - キャラクター配列
     * @param {string} title - モーダルタイトル
     * @returns {Promise<object|null>} 選択されたキャラクター or null
     */
    selectCharacter(characters, title = 'キャラクターを選択', disabledIds = new Set()) {
        const RARITY_NAMES = { 1: 'N', 2: 'R', 3: 'SR', 4: 'SSR', 5: 'UR' };
        const RARITY_ICONS = { 1: '🗡️', 2: '⚔️', 3: '🔮', 4: '👑', 5: '🌟' };
        const RACE_ICONS = { warrior: '⚔️', mage: '🔮', beastman: '🐾' };
        const RARITY_CLASS = { 1: 'n', 2: 'r', 3: 'sr', 4: 'ssr', 5: 'ur' };

        return new Promise(resolve => {
            const overlay = this._createOverlay();
            const modal = this._createModal();
            modal.classList.add('char-select-modal');

            let listHtml = '';
            characters.forEach((c, i) => {
                const icon = RARITY_ICONS[c.template_rarity] || '🗡️';
                const rarityName = RARITY_NAMES[c.template_rarity] || 'N';
                const rarityClass = RARITY_CLASS[c.template_rarity] || 'n';
                const raceIcon = RACE_ICONS[c.race] || '';
                const isDisabled = disabledIds.has(c.id);
                listHtml += `
                    <div class="char-select-item${isDisabled ? ' disabled' : ''}" data-index="${i}" data-disabled="${isDisabled}">
                        <div class="char-avatar-sm" data-rarity="${c.template_rarity}">${icon}</div>
                        <div class="char-select-info">
                            <div style="font-weight:600;font-size:14px;">${c.template_name}</div>
                            <div style="display:flex;gap:4px;align-items:center;margin-top:2px;">
                                <span class="rarity-badge ${rarityClass}" style="font-size:10px;padding:1px 6px;">${rarityName}</span>
                                <span style="font-size:11px;">${raceIcon}</span>
                                <span style="font-size:11px;color:var(--text-secondary);">Lv.${c.level}</span>
                            </div>
                            <div style="font-size:11px;color:var(--text-secondary);margin-top:2px;">
                                ${isDisabled ? '⚔️ 出場中' : `HP:${c.hp} ATK:${c.atk} DEF:${c.def_} SPD:${c.spd}`}
                            </div>
                        </div>
                        <div class="char-select-check">✓</div>
                    </div>
                `;
            });

            modal.innerHTML = `
                <div class="ui-modal-title">${this._escape(title)}</div>
                <div class="char-select-list" id="char-select-list">${listHtml}</div>
                <div class="ui-modal-actions">
                    <button class="ui-modal-btn ui-modal-btn-secondary" id="ui-cancel-btn">キャンセル</button>
                    <button class="ui-modal-btn ui-modal-btn-primary" id="ui-confirm-btn" disabled>決定</button>
                </div>
            `;
            overlay.appendChild(modal);
            document.body.appendChild(overlay);

            let selectedIdx = -1;
            const confirmBtn = document.getElementById('ui-confirm-btn');

            // 選択状態の切り替え
            modal.querySelectorAll('.char-select-item').forEach(item => {
                item.addEventListener('click', () => {
                    if (isDragging) return; // ドラッグ中はクリック無効
                    if (item.dataset.disabled === 'true') return; // 出場中は選択不可
                    modal.querySelectorAll('.char-select-item').forEach(el => el.classList.remove('selected'));
                    item.classList.add('selected');
                    selectedIdx = parseInt(item.dataset.index);
                    confirmBtn.disabled = false;
                });
            });

            confirmBtn.addEventListener('click', () => {
                if (selectedIdx < 0) return;
                overlay.remove();
                resolve(characters[selectedIdx]);
            });

            document.getElementById('ui-cancel-btn').addEventListener('click', () => {
                overlay.remove();
                resolve(null);
            });

            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) { overlay.remove(); resolve(null); }
            });

            // マウスドラッグスクロール
            const list = document.getElementById('char-select-list');
            let isDragging = false;
            let startY = 0;
            let startScrollTop = 0;
            let dragDistance = 0;

            list.addEventListener('mousedown', (e) => {
                isDragging = false;
                dragDistance = 0;
                startY = e.clientY;
                startScrollTop = list.scrollTop;
                list.style.cursor = 'grabbing';
                list.style.userSelect = 'none';

                const onMouseMove = (e) => {
                    const dy = startY - e.clientY;
                    dragDistance = Math.abs(dy);
                    if (dragDistance > 4) isDragging = true;
                    list.scrollTop = startScrollTop + dy;
                };
                const onMouseUp = () => {
                    list.style.cursor = '';
                    list.style.userSelect = '';
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('mouseup', onMouseUp);
                    // ドラッグ終了後に isDragging をリセット (少し遅らせる)
                    setTimeout(() => { isDragging = false; }, 50);
                };
                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp);
            });
        });
    },

    _createOverlay() {
        const el = document.createElement('div');
        el.className = 'ui-modal-overlay';
        return el;
    },

    _createModal() {
        const el = document.createElement('div');
        el.className = 'ui-modal';
        return el;
    },

    _escape(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
    },
};

// window.alert をオーバーライド
window.alert = function(msg) { UI.alert(msg); };
