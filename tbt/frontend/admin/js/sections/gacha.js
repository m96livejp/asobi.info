const GachaSection = {
    RARITY_LABELS: {1: 'N', 2: 'R', 3: 'SR', 4: 'SSR', 5: 'UR'},
    RARITY_COLORS: {1: '#9e9e9e', 2: '#4caf50', 3: '#2196f3', 4: '#ff9800', 5: '#e91e63'},
    pools: [],

    async render(container) {
        container.innerHTML = `
            <div class="section-header"><h2>🎰 ガチャ管理</h2></div>
            <div id="gacha-content"><div class="loading">読み込み中...</div></div>
        `;
        await this.loadPools();
    },

    async loadPools() {
        const el = document.getElementById('gacha-content');
        try {
            this.pools = await AdminAPI.getGachaPools();
            this.renderPools(el);
        } catch (err) {
            el.innerHTML = `<div class="loading">エラー: ${err.message}</div>`;
        }
    },

    renderPools(el) {
        let html = '';
        for (const pool of this.pools) {
            const statusBadge = pool.is_active
                ? '<span style="color:#4caf50;font-weight:600;">有効</span>'
                : '<span style="color:#e74c3c;font-weight:600;">無効</span>';
            const costLabel = pool.cost_type === 'premium' ? 'GEM' : 'PT';

            // レアリティ別確率サマリー
            let ratesSummary = '';
            for (const [rarity, pct] of Object.entries(pool.rates)) {
                const rarityNum = Object.entries(this.RARITY_LABELS).find(([k,v]) => v === rarity)?.[0] || 1;
                const color = this.RARITY_COLORS[rarityNum] || '#999';
                ratesSummary += `<span style="color:${color};font-weight:600;margin-right:12px;">${rarity}: ${pct}%</span>`;
            }

            // アイテム別テーブル（レアリティ順にソート）
            const sortedItems = [...pool.items].sort((a, b) => a.rarity - b.rarity || a.template_id - b.template_id);
            const totalWeight = sortedItems.reduce((s, i) => s + i.weight, 0);

            let itemRows = '';
            for (const item of sortedItems) {
                const color = this.RARITY_COLORS[item.rarity] || '#999';
                const pct = totalWeight > 0 ? (item.weight / totalWeight * 100).toFixed(2) : '0';
                itemRows += `
                    <tr>
                        <td style="color:${color};font-weight:600;">${this.RARITY_LABELS[item.rarity] || '?'}</td>
                        <td>${item.name}</td>
                        <td><input type="number" min="0" value="${item.weight}" data-pool="${pool.id}" data-item="${item.id}" class="gacha-weight-input" style="width:70px;padding:4px 8px;border:1px solid var(--border);border-radius:6px;background:var(--bg-input);color:var(--text);text-align:right;"></td>
                        <td class="rate-display" style="color:#8899aa;">${pct}%</td>
                    </tr>`;
            }

            html += `
                <div class="card" style="margin-bottom:20px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                        <div>
                            <span style="font-size:1.1rem;font-weight:700;">${pool.name}</span>
                            <span style="margin-left:12px;font-size:.85rem;color:#8899aa;">${pool.pool_type === 'item' ? '装備' : 'キャラ'} / ${pool.cost_amount} ${costLabel}</span>
                            <span style="margin-left:12px;">${statusBadge}</span>
                            ${pool.pity_count > 0 ? `<span style="margin-left:12px;font-size:.85rem;color:#8899aa;">天井: ${pool.pity_count}回</span>` : ''}
                        </div>
                        <div style="display:flex;gap:8px;">
                            <button class="btn btn-sm btn-ghost" onclick="GachaSection.editPool(${pool.id})">設定変更</button>
                            <button class="btn btn-sm btn-primary" onclick="GachaSection.saveWeights(${pool.id})">確率を保存</button>
                        </div>
                    </div>
                    <div style="margin-bottom:12px;">${ratesSummary}</div>
                    <table class="data-table">
                        <thead>
                            <tr><th style="width:60px;">レア</th><th>名前</th><th style="width:90px;">重み</th><th style="width:80px;">確率</th></tr>
                        </thead>
                        <tbody>${itemRows}</tbody>
                    </table>
                </div>`;
        }
        el.innerHTML = html;

        // 重み変更時にリアルタイムで確率再計算
        el.querySelectorAll('.gacha-weight-input').forEach(input => {
            input.addEventListener('input', () => this.recalcRates(input.dataset.pool));
        });
    },

    recalcRates(poolId) {
        const inputs = document.querySelectorAll(`.gacha-weight-input[data-pool="${poolId}"]`);
        let total = 0;
        inputs.forEach(inp => { total += parseInt(inp.value) || 0; });
        inputs.forEach(inp => {
            const w = parseInt(inp.value) || 0;
            const pct = total > 0 ? (w / total * 100).toFixed(2) : '0';
            const rateEl = inp.closest('tr').querySelector('.rate-display');
            if (rateEl) rateEl.textContent = pct + '%';
        });
    },

    async saveWeights(poolId) {
        const inputs = document.querySelectorAll(`.gacha-weight-input[data-pool="${poolId}"]`);
        const weights = {};
        inputs.forEach(inp => {
            weights[parseInt(inp.dataset.item)] = parseInt(inp.value) || 0;
        });
        try {
            await AdminAPI.updateGachaWeights(poolId, weights);
            AdminApp.toast('確率を保存しました');
            await this.loadPools();
        } catch (err) {
            AdminApp.toast(err.message, 'error');
        }
    },

    async editPool(poolId) {
        const pool = this.pools.find(p => p.id === poolId);
        if (!pool) return;
        const costLabel = pool.cost_type === 'premium' ? 'GEM' : 'PT';

        const overlay = AdminApp.showModal(`
            <h3 style="margin:0 0 16px;">プール設定: ${pool.name}</h3>
            <div class="form-group">
                <label>名前</label>
                <input type="text" id="pool-name" value="${pool.name}">
            </div>
            <div class="form-group">
                <label>説明</label>
                <input type="text" id="pool-desc" value="${pool.description || ''}">
            </div>
            <div class="form-group">
                <label>コスト (${costLabel})</label>
                <input type="number" id="pool-cost" value="${pool.cost_amount}" min="0">
            </div>
            <div class="form-group">
                <label>天井回数 (0=なし)</label>
                <input type="number" id="pool-pity" value="${pool.pity_count}" min="0">
            </div>
            <div class="form-group">
                <label>状態</label>
                <select id="pool-active">
                    <option value="1" ${pool.is_active ? 'selected' : ''}>有効</option>
                    <option value="0" ${!pool.is_active ? 'selected' : ''}>無効</option>
                </select>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
                <button class="btn btn-ghost" onclick="AdminApp.closeModal()">キャンセル</button>
                <button class="btn btn-primary" onclick="GachaSection.savePool(${poolId})">保存</button>
            </div>
        `);
    },

    async savePool(poolId) {
        const data = {
            name: document.getElementById('pool-name').value,
            description: document.getElementById('pool-desc').value,
            cost_amount: parseInt(document.getElementById('pool-cost').value) || 0,
            pity_count: parseInt(document.getElementById('pool-pity').value) || 0,
            is_active: parseInt(document.getElementById('pool-active').value),
        };
        try {
            await AdminAPI.updateGachaPool(poolId, data);
            AdminApp.closeModal();
            AdminApp.toast('プール設定を保存しました');
            await this.loadPools();
        } catch (err) {
            AdminApp.toast(err.message, 'error');
        }
    },
};
