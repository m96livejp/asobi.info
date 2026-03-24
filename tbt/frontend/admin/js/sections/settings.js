const SettingsSection = {
    CATEGORIES: [
        {
            label: 'アカウント作成設定',
            keys: [
                'initial_points',
                'initial_premium_currency',
                'initial_normal_tickets',
                'initial_premium_tickets',
                'initial_item_gacha_tickets',
                'initial_premium_item_gacha_tickets',
            ],
        },
        {
            label: 'トーナメント設定',
            keys: [
                'auto_tournament_interval_seconds',
                'auto_tournament_distribution',
                'tournament_auto_create_4',
                'tournament_auto_create_8',
                'tournament_max_concurrent',
                'tournament_npc_join_min_humans',
                'tournament_round_points',
                'tournament_champion_points',
                'tournament_second_points',
                'tournament_champion_chests',
                'tournament_second_chests',
            ],
        },
    ],

    LABELS: {
        initial_points: '初期ポイント (PT)',
        initial_premium_currency: '初期有料通貨 (GEM)',
        initial_normal_tickets: '初期通常ガチャチケット',
        initial_premium_tickets: '初期プレミアムガチャチケット',
        initial_item_gacha_tickets: '初期装備ガチャチケット',
        initial_premium_item_gacha_tickets: '初期プレミアム装備ガチャチケット',
        auto_tournament_interval_seconds: '自動トーナメント間隔 (秒)',
        auto_tournament_distribution: '自動トーナメント配分',
        tournament_auto_create_4: '自動作成数 4人トーナメント (0=無効)',
        tournament_auto_create_8: '自動作成数 8人トーナメント (0=無効)',
        tournament_max_concurrent: '同時開催上限',
        tournament_npc_join_min_humans: 'NPC自動参加に必要な人間参加者数 (0〜7)',
        tournament_round_points: 'ラウンド別ポイント [第1戦, 第2戦, 決勝]',
        tournament_champion_points: '優勝ボーナスポイント',
        tournament_second_points: '準優勝ボーナスポイント',
        tournament_champion_chests: '優勝宝箱数',
        tournament_second_chests: '準優勝宝箱数',
    },

    async render(container) {
        container.innerHTML = `
            <div class="section-header"><h2>設定管理</h2></div>
            <div id="settings-content" class="card"><div class="loading">読み込み中...</div></div>
        `;
        await this.loadSettings();
    },

    async loadSettings() {
        const el = document.getElementById('settings-content');
        try {
            const data = await AdminAPI.getSettings();
            const settings = data.settings;

            const renderField = (key, value) => {
                const isArray = Array.isArray(value);
                const displayValue = isArray ? JSON.stringify(value) : value;
                const inputType = typeof value === 'number' ? 'number' : 'text';
                const label = this.LABELS[key] || key;
                return `
                    <div class="form-group">
                        <label class="setting-label">${label}</label>
                        <div class="setting-key">${key}</div>
                        <input type="${inputType}" id="setting-${key}" value="${displayValue}" data-key="${key}" data-type="${isArray ? 'json' : typeof value}">
                    </div>
                `;
            };

            let html = '';
            for (const cat of this.CATEGORIES) {
                html += `<div class="settings-category"><div class="settings-category-label">${cat.label}</div><div class="settings-grid">`;
                for (const key of cat.keys) {
                    if (key in settings) {
                        html += renderField(key, settings[key]);
                    }
                }
                html += `</div></div>`;
            }

            el.innerHTML = `
                ${html}
                <div style="margin-top: 16px; text-align: right;">
                    <button class="btn btn-primary" onclick="SettingsSection.save()">設定を保存</button>
                </div>
            `;
        } catch (err) {
            el.innerHTML = `<div class="loading">エラー: ${err.message}</div>`;
        }
    },

    async save() {
        try {
            const updates = {};
            document.querySelectorAll('[data-key]').forEach(input => {
                const key = input.dataset.key;
                const type = input.dataset.type;
                let value = input.value;

                if (type === 'number') {
                    value = parseFloat(value);
                } else if (type === 'json') {
                    value = JSON.parse(value);
                }
                updates[key] = value;
            });

            await AdminAPI.updateSettings(updates);
            AdminApp.toast('設定を保存しました');
        } catch (err) {
            AdminApp.toast(err.message, 'error');
        }
    },
};
