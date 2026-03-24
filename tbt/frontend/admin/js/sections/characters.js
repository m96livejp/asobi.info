const CharactersSection = {
    page: 1,
    perPage: 50,
    playerQ: '',
    charQ: '',
    userId: '',
    sortCol: '',
    sortDir: '',

    async render(container) {
        container.innerHTML = `
            <div class="section-header"><h2>キャラクター管理</h2></div>
            <div class="toolbar">
                <input type="search" class="search-input" id="char-search-player" placeholder="プレイヤー名" value="${this.playerQ}">
                <input type="search" class="search-input" id="char-search-char" placeholder="キャラ名" value="${this.charQ}">
                ${this.userId ? `<button class="btn btn-sm btn-ghost" onclick="CharactersSection.clearFilter()">フィルター解除</button>` : ''}
            </div>
            <div id="chars-table" class="card"><div class="loading">読み込み中...</div></div>
            <div id="chars-pagination" class="pagination"></div>
        `;

        const debounced = this._debounce(() => { this.page = 1; this.loadTable(); }, 300);
        document.getElementById('char-search-player').oninput = (e) => { this.playerQ = e.target.value; debounced(); };
        document.getElementById('char-search-char').oninput = (e) => { this.charQ = e.target.value; debounced(); };

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
        return `<th class="${cls}" onclick="CharactersSection.toggleSort('${col}')">${label}<span class="sort-icon">${icon}</span></th>`;
    },

    async loadTable() {
        const tableEl = document.getElementById('chars-table');
        try {
            const sortParam = this.sortCol ? `${this.sortCol}_${this.sortDir}` : 'created_at_desc';
            const params = { page: this.page, per_page: this.perPage, sort: sortParam };
            if (this.playerQ) params.player_q = this.playerQ;
            if (this.charQ) params.char_q = this.charQ;
            if (this.userId) params.user_id = this.userId;
            const data = await AdminAPI.getCharacters(params);

            if (!data.items.length) {
                tableEl.innerHTML = '<div class="loading">キャラクターが見つかりません</div>';
                document.getElementById('chars-pagination').innerHTML = '';
                return;
            }

            const rarityNames = { 1: 'N', 2: 'R', 3: 'SR', 4: 'SSR', 5: 'UR' };
            tableEl.innerHTML = `
                <table class="data-table">
                    <thead>
                        <tr>
                            ${this._th('name', 'キャラ名')}
                            ${this._th('rarity', 'レア')}
                            <th>プレイヤー</th>
                            <th>種族</th>
                            ${this._th('level', 'Lv')}
                            <th>HP</th>
                            <th>ATK</th>
                            <th>DEF</th>
                            <th>SPD</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${data.items.map(c => `
                            <tr>
                                <td>${c.template_name}</td>
                                <td>${rarityNames[c.rarity] || c.rarity}</td>
                                <td class="clickable" onclick="CharactersSection.filterByUser('${c.user_id}')">${c.user_name}</td>
                                <td>${c.race}</td>
                                <td>${c.level}</td>
                                <td>${c.hp}</td>
                                <td>${c.atk}</td>
                                <td>${c.def_}</td>
                                <td>${c.spd}</td>
                                <td><button class="btn btn-sm btn-ghost" onclick="CharactersSection.showEdit('${c.id}', ${JSON.stringify(c).replace(/"/g, '&quot;')})">編集</button></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;

            // ページネーション
            const totalPages = Math.ceil(data.total / this.perPage);
            document.getElementById('chars-pagination').innerHTML = `
                <button class="btn btn-sm btn-ghost" ${this.page <= 1 ? 'disabled' : ''} onclick="CharactersSection.changePage(${this.page - 1})">前</button>
                <span>${this.page} / ${totalPages} (${data.total}件)</span>
                <button class="btn btn-sm btn-ghost" ${this.page >= totalPages ? 'disabled' : ''} onclick="CharactersSection.changePage(${this.page + 1})">次</button>
            `;
        } catch (err) {
            tableEl.innerHTML = `<div class="loading">エラー: ${err.message}</div>`;
        }
    },

    changePage(p) {
        this.page = p;
        this.loadTable();
    },

    filterByUser(userId) {
        this.userId = userId;
        this.page = 1;
        // re-render to show filter badge
        const container = document.getElementById('content');
        this.render(container);
    },

    clearFilter() {
        this.userId = '';
        this.page = 1;
        const container = document.getElementById('content');
        this.render(container);
    },

    showEdit(id, data) {
        AdminApp.showModal(`
            <h3>${data.template_name} のステータス編集</h3>
            <div class="settings-grid">
                <div class="form-group"><label>HP</label><input type="number" id="cedit-hp" value="${data.hp}"></div>
                <div class="form-group"><label>ATK</label><input type="number" id="cedit-atk" value="${data.atk}"></div>
                <div class="form-group"><label>DEF</label><input type="number" id="cedit-def" value="${data.def_}"></div>
                <div class="form-group"><label>SPD</label><input type="number" id="cedit-spd" value="${data.spd}"></div>
                <div class="form-group"><label>Level</label><input type="number" id="cedit-level" value="${data.level}"></div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-ghost" onclick="AdminApp.closeModal()">キャンセル</button>
                <button class="btn btn-primary" onclick="CharactersSection.saveEdit('${id}')">保存</button>
            </div>
        `);
    },

    async saveEdit(id) {
        try {
            await AdminAPI.updateCharacter(id, {
                hp: parseInt(document.getElementById('cedit-hp').value),
                atk: parseInt(document.getElementById('cedit-atk').value),
                def_: parseInt(document.getElementById('cedit-def').value),
                spd: parseInt(document.getElementById('cedit-spd').value),
                level: parseInt(document.getElementById('cedit-level').value),
            });
            AdminApp.closeModal();
            AdminApp.toast('更新しました');
            this.loadTable();
        } catch (err) {
            AdminApp.toast(err.message, 'error');
        }
    },

    _debounce(fn, ms) {
        let timer;
        return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), ms); };
    },
};
