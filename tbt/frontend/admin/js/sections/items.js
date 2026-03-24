const ItemsSection = {
    page: 1,
    perPage: 50,
    filterUserId: '',
    filterUserName: '',
    filterItemName: '',
    sortCol: '',
    sortDir: '',

    async render(container) {
        container.innerHTML = `
            <div class="section-header"><h2>アイテム管理</h2></div>
            <div class="toolbar">
                <input type="search" id="item-filter-userid" placeholder="プレイヤーID" value="${this.filterUserId}" style="flex:1; min-width:120px;">
                <input type="search" id="item-filter-username" placeholder="プレイヤー名" value="${this.filterUserName}" style="flex:1; min-width:120px;">
                <input type="search" id="item-filter-itemname" placeholder="アイテム名" value="${this.filterItemName}" style="flex:1; min-width:120px;">
            </div>
            <div id="items-table" class="card"><div class="loading">読み込み中...</div></div>
            <div id="items-pagination" class="pagination"></div>
        `;

        const debounced = this._debounce(() => {
            this.filterUserId = document.getElementById('item-filter-userid').value;
            this.filterUserName = document.getElementById('item-filter-username').value;
            this.filterItemName = document.getElementById('item-filter-itemname').value;
            this.page = 1;
            this.loadTable();
        }, 300);

        document.getElementById('item-filter-userid').oninput = debounced;
        document.getElementById('item-filter-username').oninput = debounced;
        document.getElementById('item-filter-itemname').oninput = debounced;

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
        return `<th class="${cls}" onclick="ItemsSection.toggleSort('${col}')">${label}<span class="sort-icon">${icon}</span></th>`;
    },

    async loadTable() {
        const tableEl = document.getElementById('items-table');
        try {
            const sortParam = this.sortCol ? `${this.sortCol}_${this.sortDir}` : 'created_at_desc';
            const params = { page: this.page, per_page: this.perPage, sort: sortParam };
            if (this.filterUserId) params.user_id = this.filterUserId;
            if (this.filterUserName) params.user_name = this.filterUserName;
            if (this.filterItemName) params.item_name = this.filterItemName;
            const data = await AdminAPI.getItems(params);

            if (!data.items.length) {
                tableEl.innerHTML = '<div class="loading">アイテムが見つかりません</div>';
                document.getElementById('items-pagination').innerHTML = '';
                return;
            }

            const rarityNames = { 1: 'N', 2: 'R', 3: 'SR', 4: 'SSR', 5: 'UR' };
            tableEl.innerHTML = `
                <table class="data-table">
                    <thead>
                        <tr>
                            ${this._th('name', 'アイテム名')}
                            <th>種類</th>
                            ${this._th('rarity', 'レア')}
                            ${this._th('quantity', '数量')}
                            <th>所有者</th>
                            <th>プレイヤーID</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${data.items.map(i => `
                            <tr>
                                <td>${i.item_name}</td>
                                <td>${i.item_type}</td>
                                <td>${rarityNames[i.rarity] || i.rarity}</td>
                                <td>${i.quantity}</td>
                                <td>${i.user_name || '-'}</td>
                                <td style="font-size:11px; font-family:monospace; color:var(--text-muted);">${i.user_id.slice(0, 8)}...</td>
                                <td><button class="btn btn-sm btn-ghost" onclick="ItemsSection.showEdit(${i.id}, ${i.quantity}, '${i.user_id}')">編集</button></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;

            const totalPages = Math.ceil(data.total / this.perPage);
            document.getElementById('items-pagination').innerHTML = `
                <button class="btn btn-sm btn-ghost" ${this.page <= 1 ? 'disabled' : ''} onclick="ItemsSection.changePage(${this.page - 1})">前</button>
                <span>${this.page} / ${totalPages} (${data.total}件)</span>
                <button class="btn btn-sm btn-ghost" ${this.page >= totalPages ? 'disabled' : ''} onclick="ItemsSection.changePage(${this.page + 1})">次</button>
            `;
        } catch (err) {
            tableEl.innerHTML = `<div class="loading">エラー: ${err.message}</div>`;
        }
    },

    changePage(p) {
        this.page = p;
        this.loadTable();
    },

    showEdit(id, quantity, userId) {
        AdminApp.showModal(`
            <h3>アイテム編集</h3>
            <div class="form-group"><label>数量</label><input type="number" id="iedit-qty" value="${quantity}"></div>
            <div class="form-group"><label>所有者ID</label><input type="text" id="iedit-user" value="${userId}"></div>
            <div class="modal-actions">
                <button class="btn btn-ghost" onclick="AdminApp.closeModal()">キャンセル</button>
                <button class="btn btn-primary" onclick="ItemsSection.saveEdit(${id})">保存</button>
            </div>
        `);
    },

    async saveEdit(id) {
        try {
            await AdminAPI.updateItem(id, {
                quantity: parseInt(document.getElementById('iedit-qty').value),
                user_id: document.getElementById('iedit-user').value,
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
