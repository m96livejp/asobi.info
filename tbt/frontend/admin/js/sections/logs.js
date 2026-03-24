const LogsSection = {
    tab: 'ad-rewards',
    page: 1,

    async render(container) {
        container.innerHTML = `
            <div class="section-header"><h2>ログ閲覧</h2></div>
            <div class="toolbar">
                <button class="btn btn-sm ${this.tab === 'ad-rewards' ? 'btn-primary' : 'btn-ghost'}" onclick="LogsSection.switchTab('ad-rewards')">PT獲得</button>
                <button class="btn btn-sm ${this.tab === 'purchases' ? 'btn-primary' : 'btn-ghost'}" onclick="LogsSection.switchTab('purchases')">GEM購入</button>
                <button class="btn btn-sm ${this.tab === 'audit' ? 'btn-primary' : 'btn-ghost'}" onclick="LogsSection.switchTab('audit')">管理操作</button>
            </div>
            <div id="logs-table" class="card"><div class="loading">読み込み中...</div></div>
        `;
        await this.loadLogs();
    },

    switchTab(tab) {
        this.tab = tab;
        this.page = 1;
        // Re-render tabs
        const container = document.getElementById('content');
        this.render(container);
    },

    async loadLogs() {
        const el = document.getElementById('logs-table');
        try {
            if (this.tab === 'ad-rewards') {
                const data = await AdminAPI.getAdRewards({ page: this.page });
                el.innerHTML = data.length ? `
                    <table class="data-table">
                        <thead><tr><th>プレイヤー</th><th>種類</th><th>数量</th><th>日時</th></tr></thead>
                        <tbody>${data.map(r => `
                            <tr>
                                <td>${r.user_name || r.user_id.slice(0, 8)}</td>
                                <td>${r.reward_type}</td>
                                <td>${r.reward_amount}</td>
                                <td>${AdminApp.formatDate(r.created_at)}</td>
                            </tr>
                        `).join('')}</tbody>
                    </table>
                ` : '<div class="loading">ログがありません</div>';

            } else if (this.tab === 'purchases') {
                const data = await AdminAPI.getPurchases({ page: this.page });
                el.innerHTML = data.length ? `
                    <table class="data-table">
                        <thead><tr><th>プレイヤー</th><th>商品</th><th>金額</th><th>GEM</th><th>状態</th><th>日時</th></tr></thead>
                        <tbody>${data.map(p => `
                            <tr>
                                <td>${p.user_name || p.user_id.slice(0, 8)}</td>
                                <td>${p.product_name}</td>
                                <td>${p.amount}円</td>
                                <td>${p.premium_currency_granted}</td>
                                <td>${p.status}</td>
                                <td>${AdminApp.formatDate(p.created_at)}</td>
                            </tr>
                        `).join('')}</tbody>
                    </table>
                ` : '<div class="loading">ログがありません</div>';

            } else if (this.tab === 'audit') {
                const data = await AdminAPI.getAuditLogs({ page: this.page });
                el.innerHTML = data.length ? `
                    <table class="data-table">
                        <thead><tr><th>管理者ID</th><th>操作</th><th>対象</th><th>日時</th></tr></thead>
                        <tbody>${data.map(l => `
                            <tr>
                                <td>${l.admin_user_id.slice(0, 8)}</td>
                                <td>${l.action}</td>
                                <td>${l.target_type || ''} ${l.target_id ? l.target_id.slice(0, 8) : ''}</td>
                                <td>${AdminApp.formatDate(l.created_at)}</td>
                            </tr>
                        `).join('')}</tbody>
                    </table>
                ` : '<div class="loading">ログがありません</div>';
            }
        } catch (err) {
            el.innerHTML = `<div class="loading">エラー: ${err.message}</div>`;
        }
    },
};
