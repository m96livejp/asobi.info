const NpcNamesSection = {
    async render(container) {
        container.innerHTML = `
            <div class="section-header">
                <h2>NPC名前管理</h2>
                <button class="btn btn-primary" onclick="NpcNamesSection.showAdd()">+ 追加</button>
            </div>
            <div id="npc-names-content" class="card"><div class="loading">読み込み中...</div></div>
        `;
        await this.loadNames();
    },

    async loadNames() {
        const el = document.getElementById('npc-names-content');
        try {
            const names = await AdminAPI.getNpcNames();

            if (!names.length) {
                el.innerHTML = '<div class="loading">NPC名前が登録されていません</div>';
                return;
            }

            el.innerHTML = `
                <table class="data-table">
                    <thead>
                        <tr><th>ID</th><th>名前</th><th>状態</th><th>操作</th></tr>
                    </thead>
                    <tbody>
                        ${names.map(n => `
                            <tr>
                                <td>${n.id}</td>
                                <td>${n.name}</td>
                                <td>${n.is_active ? '<span style="color:#4caf50;">有効</span>' : '<span style="color:#e74c3c;">無効</span>'}</td>
                                <td>
                                    ${n.is_active
                                        ? `<button class="btn btn-sm btn-warning" onclick="NpcNamesSection.deactivate(${n.id})">無効化</button>`
                                        : `<button class="btn btn-sm btn-success" onclick="NpcNamesSection.activate(${n.id})">有効化</button>`
                                    }
                                    <button class="btn btn-sm btn-danger" onclick="NpcNamesSection.deleteName(${n.id}, '${n.name}')">削除</button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        } catch (err) {
            el.innerHTML = `<div class="loading">エラー: ${err.message}</div>`;
        }
    },

    showAdd() {
        AdminApp.showModal(`
            <h3>NPC名前追加</h3>
            <div class="form-group">
                <label>名前</label>
                <input type="text" id="npc-new-name" placeholder="ニックネーム">
            </div>
            <div class="modal-actions">
                <button class="btn btn-ghost" onclick="AdminApp.closeModal()">キャンセル</button>
                <button class="btn btn-primary" onclick="NpcNamesSection.addName()">追加</button>
            </div>
        `);
    },

    async addName() {
        const name = document.getElementById('npc-new-name').value.trim();
        if (!name) return;
        try {
            await AdminAPI.createNpcName(name);
            AdminApp.closeModal();
            AdminApp.toast('追加しました');
            this.loadNames();
        } catch (err) {
            AdminApp.toast(err.message, 'error');
        }
    },

    async deactivate(id) {
        try {
            await AdminAPI.deactivateNpcName(id);
            AdminApp.toast('無効化しました');
            this.loadNames();
        } catch (err) {
            AdminApp.toast(err.message, 'error');
        }
    },

    async activate(id) {
        try {
            await AdminAPI.activateNpcName(id);
            AdminApp.toast('有効化しました');
            this.loadNames();
        } catch (err) {
            AdminApp.toast(err.message, 'error');
        }
    },

    async deleteName(id, name) {
        if (!await AdminApp.confirm(`「${name}」を完全に削除しますか？`)) return;
        try {
            await AdminAPI.deleteNpcName(id);
            AdminApp.toast('削除しました');
            this.loadNames();
        } catch (err) {
            AdminApp.toast(err.message, 'error');
        }
    },
};
