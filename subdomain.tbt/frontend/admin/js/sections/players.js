const PlayersSection = {
    page: 1,
    perPage: 20,
    name: '',
    idQ: '',
    emailQ: '',
    sortCol: '',
    sortDir: '',

    async render(container) {
        container.innerHTML = `
            <div class="section-header">
                <h2>プレイヤー管理</h2>
            </div>
            <div class="toolbar">
                <input type="search" class="search-input" id="player-search-name" placeholder="名前" value="${this.name}">
                <input type="search" class="search-input" id="player-search-id" placeholder="ID" value="${this.idQ}">
                <input type="search" class="search-input" id="player-search-email" placeholder="メール" value="${this.emailQ}">
            </div>
            <div id="players-table" class="card"><div class="loading">読み込み中...</div></div>
            <div id="players-pagination" class="pagination"></div>
        `;

        const debounced = this._debounce(() => { this.page = 1; this.loadTable(); }, 300);
        document.getElementById('player-search-name').oninput = (e) => { this.name = e.target.value; debounced(); };
        document.getElementById('player-search-id').oninput = (e) => { this.idQ = e.target.value; debounced(); };
        document.getElementById('player-search-email').oninput = (e) => { this.emailQ = e.target.value; debounced(); };

        await this.loadTable();
    },

    toggleSort(col) {
        if (this.sortCol !== col) { this.sortCol = col; this.sortDir = 'desc'; }
        else if (this.sortDir === 'desc') { this.sortDir = 'asc'; }
        else { this.sortCol = ''; this.sortDir = ''; }
        this.page = 1;
        this.loadTable();
    },

    _th(col, label) {
        const active = this.sortCol === col;
        const cls = active ? `sortable sort-${this.sortDir}` : 'sortable';
        const icon = active ? (this.sortDir === 'desc' ? '▼' : '▲') : '▽';
        return `<th class="${cls}" onclick="PlayersSection.toggleSort('${col}')">${label}<span class="sort-icon">${icon}</span></th>`;
    },

    async loadTable() {
        const tableEl = document.getElementById('players-table');
        try {
            const sortParam = this.sortCol ? `${this.sortCol}_${this.sortDir}` : 'created_at_desc';
            const data = await AdminAPI.getUsers({
                name: this.name, id_q: this.idQ, email_q: this.emailQ,
                page: this.page, per_page: this.perPage, sort: sortParam
            });

            if (!data.users.length) {
                tableEl.innerHTML = '<div class="loading">プレイヤーが見つかりません</div>';
                return;
            }

            tableEl.innerHTML = `
                <table class="data-table">
                    <thead>
                        <tr>
                            ${this._th('name', '名前')}
                            ${this._th('points', 'PT')}
                            ${this._th('premium', 'GEM')}
                            <th>キャラ数</th>
                            <th>状態</th>
                            ${this._th('last_login', '最終ログイン')}
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${data.users.map(u => `
                            <tr>
                                <td>
                                    ${u.display_name}
                                    ${u.is_admin ? '<span class="badge badge-admin">Admin</span>' : ''}
                                    ${u.is_npc ? '<span class="badge badge-npc">NPC</span>' : ''}
                                </td>
                                <td>${u.points.toLocaleString()}</td>
                                <td>${u.premium_currency.toLocaleString()}</td>
                                <td>${u.character_count}</td>
                                <td>${u.has_email ? '📧' : ''}${u.has_social ? '🔗' : ''}</td>
                                <td>${AdminApp.formatDate(u.last_login_at)}</td>
                                <td>
                                    <button class="btn btn-sm btn-ghost" onclick="PlayersSection.showDetail('${u.id}')">詳細</button>
                                    ${u.is_npc ? `<button class="btn btn-sm btn-danger" onclick="PlayersSection.deleteUser('${u.id}', '${u.display_name}')">削除</button>` : ''}
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;

            // ページネーション
            const totalPages = Math.ceil(data.total / this.perPage);
            document.getElementById('players-pagination').innerHTML = `
                <button class="btn btn-sm btn-ghost" ${this.page <= 1 ? 'disabled' : ''} onclick="PlayersSection.changePage(${this.page - 1})">前</button>
                <span>${this.page} / ${totalPages} (${data.total}件)</span>
                <button class="btn btn-sm btn-ghost" ${this.page >= totalPages ? 'disabled' : ''} onclick="PlayersSection.changePage(${this.page + 1})">次</button>
            `;
        } catch (err) {
            tableEl.innerHTML = `<div class="loading">エラー: ${err.message}</div>`;
        }
    },

    changePage(p) {
        this.page = p;
        this.loadTable();
    },

    async showDetail(userId) {
        try {
            const user = await AdminAPI.getUser(userId);
            const modal = AdminApp.showModal(`
                <h3>${user.display_name} の詳細</h3>
                <div class="form-group">
                    <label>ID</label>
                    <input type="text" value="${user.id}" readonly>
                </div>
                <div class="form-group">
                    <label>表示名</label>
                    <input type="text" id="edit-name" value="${user.display_name}">
                </div>
                <div class="settings-grid">
                    <div class="form-group">
                        <label>PT</label>
                        <input type="number" id="edit-points" value="${user.points}">
                    </div>
                    <div class="form-group">
                        <label>GEM</label>
                        <input type="number" id="edit-gem" value="${user.premium_currency}">
                    </div>
                    <div class="form-group">
                        <label>スタミナ</label>
                        <input type="number" id="edit-stamina" value="${user.stamina}">
                    </div>
                    <div class="form-group">
                        <label>通常チケット</label>
                        <input type="number" id="edit-tickets" value="${user.normal_gacha_tickets}">
                    </div>
                    <div class="form-group">
                        <label>プレミアムチケット</label>
                        <input type="number" id="edit-ptickets" value="${user.premium_gacha_tickets}">
                    </div>
                    <div class="form-group">
                        <label>管理者</label>
                        <select id="edit-admin">
                            <option value="0" ${user.is_admin ? '' : 'selected'}>一般</option>
                            <option value="1" ${user.is_admin ? 'selected' : ''}>管理者</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>管理メモ</label>
                    <textarea id="edit-memo">${user.admin_memo || ''}</textarea>
                </div>
                <table class="detail-info-table" style="margin-top:12px; width:100%; font-size:13px;">
                    <tr><td style="color:#8888bb; padding:4px 12px 4px 0; white-space:nowrap;">Email</td><td style="color:#e0e0f0; padding:4px 0;">${user.email || 'なし'} ${user.email_verified ? '<span style="color:#4caf50;">(認証済)</span>' : ''}</td></tr>
                    <tr><td style="color:#8888bb; padding:4px 12px 4px 0;">Device</td><td style="color:#e0e0f0; padding:4px 0; font-family:monospace; font-size:11px;">${user.device_id || 'なし'}</td></tr>
                    <tr><td style="color:#8888bb; padding:4px 12px 4px 0;">SNS</td><td style="color:#e0e0f0; padding:4px 0;">${user.social_accounts.map(s => s.provider).join(', ') || 'なし'}</td></tr>
                    <tr><td style="color:#8888bb; padding:4px 12px 4px 0;">キャラ数</td><td style="color:#e0e0f0; padding:4px 0;">${user.character_count}</td></tr>
                    <tr><td style="color:#8888bb; padding:4px 12px 4px 0;">登録日</td><td style="color:#e0e0f0; padding:4px 0;">${AdminApp.formatDate(user.created_at)}</td></tr>
                </table>
                <div class="modal-actions">
                    <button class="btn btn-danger btn-sm" onclick="PlayersSection.convertNpc('${user.id}')">NPC化</button>
                    <button class="btn btn-ghost" onclick="AdminApp.closeModal()">キャンセル</button>
                    <button class="btn btn-primary" onclick="PlayersSection.saveUser('${user.id}')">保存</button>
                </div>
            `);
        } catch (err) {
            AdminApp.toast(err.message, 'error');
        }
    },

    async saveUser(userId) {
        try {
            const data = {
                display_name: document.getElementById('edit-name').value,
                points: parseInt(document.getElementById('edit-points').value),
                premium_currency: parseInt(document.getElementById('edit-gem').value),
                stamina: parseInt(document.getElementById('edit-stamina').value),
                normal_gacha_tickets: parseInt(document.getElementById('edit-tickets').value),
                premium_gacha_tickets: parseInt(document.getElementById('edit-ptickets').value),
                is_admin: parseInt(document.getElementById('edit-admin').value),
                admin_memo: document.getElementById('edit-memo').value,
            };
            await AdminAPI.updateUser(userId, data);
            AdminApp.closeModal();
            AdminApp.toast('更新しました');
            this.loadTable();
        } catch (err) {
            AdminApp.toast(err.message, 'error');
        }
    },

    async deleteUser(userId, name) {
        if (!confirm(`NPC「${name}」を削除しますか？関連するキャラクター・アイテム等も全て削除されます。`)) return;
        try {
            await AdminAPI.deleteUser(userId);
            AdminApp.toast('削除しました');
            this.loadTable();
        } catch (err) {
            AdminApp.toast(err.message, 'error');
        }
    },

    async convertNpc(userId) {
        if (!confirm('このユーザーをNPC化しますか？ログインできなくなります。')) return;
        try {
            await AdminAPI.convertNpc(userId);
            AdminApp.closeModal();
            AdminApp.toast('NPC化しました');
            this.loadTable();
        } catch (err) {
            AdminApp.toast(err.message, 'error');
        }
    },

    _debounce(fn, ms) {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), ms);
        };
    },
};
