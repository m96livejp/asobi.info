/**
 * ガチャ画面
 */
const GachaPage = {
    RARITY_NAMES: { 1: 'N', 2: 'R', 3: 'SR', 4: 'SSR', 5: 'UR' },

    async render(container) {
        container.innerHTML = '<div class="loading"><div class="spinner"></div>読み込み中...</div>';

        try {
            const pools = await API.getGachaPools();

            if (pools.length === 0) {
                container.innerHTML = '<div class="text-center" style="padding:40px;">現在開催中のガチャはありません</div>';
                return;
            }

            const user = App.user;
            container.innerHTML = `
                <div class="card-title mb-8">ガチャ</div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;font-size:12px;color:var(--text-secondary);">
                    <span>通常チケット: <b style="color:var(--text-primary);">${user.normal_gacha_tickets}枚</b></span>
                    <span>プレミアムチケット: <b style="color:var(--gold);">${user.premium_gacha_tickets}枚</b></span>
                    <span>装備チケット: <b style="color:#4ecdc4;">${user.item_gacha_tickets ?? 0}枚</b></span>
                </div>
            `;

            pools.forEach(pool => {
                const banner = document.createElement('div');
                banner.className = 'gacha-banner';
                const isItem = pool.pool_type === 'item';

                if (isItem) {
                    banner.style.background = 'linear-gradient(135deg, var(--bg-card), rgba(78,205,196,0.2))';
                }

                // レアリティ名のみ表示（確率なし）
                const rateHtml = Object.keys(pool.rates)
                    .map(name => `<span class="gacha-rate rarity-${name.toLowerCase()}">${name}</span>`)
                    .join('');

                const isNormal = pool.cost_type === 'points';
                const costIcon = isNormal ? '🪙' : '💎';
                const costUnit = isNormal ? 'PT' : 'GEM';
                const itemTicketCount = user.item_gacha_tickets ?? 0;

                // チケット設定（装備ガチャは専用チケット、キャラガチャは通常/プレミアム）
                const ticketCount = isItem ? itemTicketCount : (isNormal ? user.normal_gacha_tickets : user.premium_gacha_tickets);
                const ticketShort = isItem ? '装備チケット' : (isNormal ? '通常チケット' : 'Pチケット');
                const ticketColor = isItem ? '#4ecdc4' : 'var(--text-primary)';

                const typeLabel = isItem ? (isNormal ? '🗡️ 装備ガチャ' : '💎 プレミアム装備ガチャ') : '👤 キャラガチャ';

                const ticketHtml = `
                    <div class="gacha-buttons" style="margin-top:6px;">
                        <button class="btn btn-ticket pull-btn" data-pool="${pool.id}" data-count="1" data-ticket="true"
                            ${ticketCount < 1 ? 'disabled' : ''}>
                            ${ticketShort}1回
                        </button>
                        <button class="btn btn-ticket pull-btn" data-pool="${pool.id}" data-count="10" data-ticket="true"
                            ${ticketCount < 10 ? 'disabled' : ''}>
                            ${ticketShort}10連
                        </button>
                    </div>
                    <div style="font-size:11px;color:var(--text-secondary);margin-top:4px;text-align:right;">
                        ${ticketShort}: <b style="color:${ticketColor};">${ticketCount}枚</b>
                    </div>
                `;

                banner.innerHTML = `
                    <div style="font-size:10px;color:var(--text-secondary);margin-bottom:4px;">${typeLabel}</div>
                    <div class="gacha-banner-title">${pool.name}</div>
                    <div style="font-size:12px;color:var(--text-secondary);">${pool.description}</div>
                    <div class="gacha-rates">${rateHtml}</div>
                    <div class="gacha-buttons">
                        <button class="btn btn-primary pull-btn" data-pool="${pool.id}" data-count="1" data-ticket="false">
                            1回 (${costIcon}${pool.cost_amount}${costUnit})
                        </button>
                        <button class="btn btn-gold pull-btn" data-pool="${pool.id}" data-count="10" data-ticket="false">
                            10連 (${costIcon}${pool.cost_amount * 10}${costUnit})
                        </button>
                    </div>
                    ${ticketHtml}
                `;
                container.appendChild(banner);
            });

            // ガチャボタンのイベント
            container.querySelectorAll('.pull-btn').forEach(btn => {
                btn.addEventListener('click', () => this.pull(
                    parseInt(btn.dataset.pool),
                    parseInt(btn.dataset.count),
                    btn.dataset.ticket === 'true'
                ));
            });
        } catch (e) {
            container.innerHTML = `<div class="text-center" style="padding:40px;color:var(--accent);">エラー: ${e.message}</div>`;
        }
    },

    async pull(poolId, count, useTicket) {
        try {
            const result = await API.pullGacha(poolId, count, useTicket);
            App.updateCurrency(result.remaining_points, result.remaining_premium);
            // チケット残数も更新
            App.user.normal_gacha_tickets = result.remaining_normal_tickets;
            App.user.premium_gacha_tickets = result.remaining_premium_tickets;
            App.user.item_gacha_tickets = result.remaining_item_gacha_tickets;

            // ガチャ演出
            await Animation.playGacha(result.results);

            // 画面更新
            this.render(document.getElementById('main-content'));
        } catch (e) {
            alert(e.message);
        }
    },
};
