/**
 * アイテム管理画面
 */
const ItemsPage = {
    RARITY_NAMES: { 1: 'N', 2: 'R', 3: 'SR', 4: 'SSR', 5: 'UR' },
    TYPE_LABELS: {
        treasure_pt: 'PT宝箱',
        treasure_equip: '装備宝箱',
        treasure_ticket: 'チケット宝箱',
        equipment: '装備品',
    },
    TYPE_ICONS: {
        treasure_pt: '📦',
        treasure_equip: '🗃️',
        treasure_ticket: '🎫',
        equipment: '🗡️',
    },

    async render(container) {
        container.innerHTML = '<div class="loading"><div class="spinner"></div>読み込み中...</div>';

        try {
            const items = await API.getItems();

            if (items.length === 0) {
                container.innerHTML = `
                    <div class="text-center" style="padding:40px;">
                        <p style="font-size:48px;margin-bottom:12px;">📦</p>
                        <p>アイテムがありません</p>
                        <p style="color:var(--text-secondary);font-size:13px;margin-top:4px;">トーナメントやガチャでアイテムを手に入れよう！</p>
                    </div>
                `;
                return;
            }

            // カテゴリ分け
            const treasures = items.filter(i => i.item_type.startsWith('treasure_'));
            const equipment = items.filter(i => i.item_type === 'equipment');

            container.innerHTML = `
                <div class="card-title">アイテム (${items.length}種)</div>
                <div class="item-tabs">
                    <button class="item-tab active" data-tab="treasure">宝箱 (${treasures.length})</button>
                    <button class="item-tab" data-tab="equipment">装備品 (${equipment.length})</button>
                </div>
                <div id="item-tab-treasure" class="item-tab-content"></div>
                <div id="item-tab-equipment" class="item-tab-content" style="display:none;"></div>
            `;

            // タブ切り替え
            container.querySelectorAll('.item-tab').forEach(tab => {
                tab.addEventListener('click', () => {
                    container.querySelectorAll('.item-tab').forEach(t => t.classList.remove('active'));
                    tab.classList.add('active');
                    container.querySelectorAll('.item-tab-content').forEach(c => c.style.display = 'none');
                    document.getElementById(`item-tab-${tab.dataset.tab}`).style.display = '';
                });
            });

            // 宝箱一覧
            const treasureEl = document.getElementById('item-tab-treasure');
            if (treasures.length === 0) {
                treasureEl.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-secondary);font-size:13px;">宝箱がありません</div>';
            } else {
                treasures.forEach(item => this._renderItemCard(treasureEl, item, true));
            }

            // 装備品一覧
            const equipEl = document.getElementById('item-tab-equipment');
            if (equipment.length === 0) {
                equipEl.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-secondary);font-size:13px;">装備品がありません</div>';
            } else {
                equipment.forEach(item => this._renderItemCard(equipEl, item, false));
            }
        } catch (e) {
            container.innerHTML = `<div class="text-center" style="padding:40px;color:var(--accent);">エラー: ${e.message}</div>`;
        }
    },

    _renderItemCard(parent, item, usable) {
        const rarityName = this.RARITY_NAMES[item.rarity] || 'N';
        const rarityClass = Animation.RARITY_CLASS[item.rarity] || 'n';
        const icon = this.TYPE_ICONS[item.item_type] || '📦';

        const card = document.createElement('div');
        card.className = 'item-card';
        card.dataset.rarity = item.rarity;
        card.innerHTML = `
            <div class="item-icon" data-rarity="${item.rarity}">${icon}</div>
            <div class="item-info">
                <div class="item-name">${item.name}</div>
                <div class="item-meta">
                    <span class="rarity-badge ${rarityClass}">${rarityName}</span>
                    <span style="font-size:11px;color:var(--text-secondary);">${item.description}</span>
                </div>
                <div style="font-size:11px;color:var(--text-secondary);margin-top:2px;">所持: ${item.quantity}個</div>
            </div>
            ${usable ? `<button class="btn btn-sm btn-primary use-item-btn" data-id="${item.id}">開ける</button>` : ''}
        `;
        parent.appendChild(card);

        if (usable) {
            card.querySelector('.use-item-btn').addEventListener('click', async (e) => {
                e.stopPropagation();
                await this._useItem(item);
            });
        }
    },

    async _useItem(item) {
        try {
            const result = await API.useItem(item.id);
            const profile = await API.getProfile();
            App.updateCurrency(profile.points, profile.premium_currency);
            App.user.normal_gacha_tickets = profile.normal_gacha_tickets;
            alert(result.message);
            this.render(document.getElementById('main-content'));
        } catch (e) {
            alert(e.message);
        }
    },
};
