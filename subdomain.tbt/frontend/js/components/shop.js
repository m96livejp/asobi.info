/**
 * ショップ画面
 */
const ShopPage = {
    async render(container) {
        container.innerHTML = '<div class="loading"><div class="spinner"></div>読み込み中...</div>';

        try {
            const products = await API.getProducts();

            container.innerHTML = `
                <div class="card-title mb-8">ショップ</div>
                <div class="ad-reward-section mb-12">
                    <div class="card-title">無料ポイント</div>
                    <p style="font-size:12px;color:var(--text-secondary);margin-bottom:8px;">
                        広告を見てポイントを獲得！
                    </p>
                    <button class="btn btn-primary" id="shop-ad-btn">広告を見る (+🪙50PT)</button>
                </div>
                <div class="card-title mb-8">💎 有料通貨 (GEM)</div>
                <div id="product-list"></div>
            `;

            const list = document.getElementById('product-list');

            if (products.length === 0) {
                // デフォルト商品を表示
                const defaultProducts = [
                    { id: 0, name: '60 GEM', price_yen: 120, premium_currency_amount: 60, bonus_amount: 0, description: 'お試しパック' },
                    { id: 0, name: '300 GEM + 30ボーナス', price_yen: 490, premium_currency_amount: 300, bonus_amount: 30, description: 'お得パック' },
                    { id: 0, name: '980 GEM + 120ボーナス', price_yen: 1480, premium_currency_amount: 980, bonus_amount: 120, description: '大容量パック' },
                    { id: 0, name: '2000 GEM + 400ボーナス', price_yen: 2980, premium_currency_amount: 2000, bonus_amount: 400, description: '超お得パック' },
                ];

                defaultProducts.forEach(p => {
                    list.innerHTML += this.productCard(p, true);
                });
            } else {
                products.forEach(p => {
                    list.innerHTML += this.productCard(p, false);
                });
            }

            // 広告ボタン: 事前に状態チェックしてグレーアウト
            const shopAdBtn = document.getElementById('shop-ad-btn');
            try {
                const status = await API.getAdStatus();
                if (!status.can_watch) {
                    shopAdBtn.disabled = true;
                    shopAdBtn.textContent = status.hourly_remaining === 0 && status.daily_remaining > 0
                        ? '1時間の上限に達しました' : '本日の上限に達しました';
                }
            } catch (_) {}

            shopAdBtn.addEventListener('click', () => {
                shopAdBtn.disabled = true;
                AdBanner.showRewardCountdown(async () => {
                    try {
                        const result = await API.claimAdReward('points');
                        App.updateCurrency(result.points, null);
                        alert(`${result.reward_amount}ポイント獲得！\n本日残り${result.remaining_daily_views}回 / 1時間残り${result.remaining_hourly_views}回`);
                        if (result.remaining_daily_views <= 0 || result.remaining_hourly_views <= 0) {
                            shopAdBtn.disabled = true;
                            shopAdBtn.textContent = result.remaining_hourly_views <= 0 && result.remaining_daily_views > 0
                                ? '1時間の上限に達しました' : '本日の上限に達しました';
                            return;
                        }
                    } catch (e) {
                        alert(e.message);
                    }
                    shopAdBtn.disabled = false;
                    shopAdBtn.textContent = '広告を見る (+🪙50PT)';
                });
            });

            // 購入ボタン
            container.querySelectorAll('.purchase-btn').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const productId = parseInt(btn.dataset.id);
                    if (productId === 0) {
                        alert('この商品はStripe決済連携後に購入できるようになります。');
                        return;
                    }
                    if (!await UI.confirm('購入しますか？')) return;
                    try {
                        const result = await API.purchase(productId);
                        const profile = await API.getProfile();
                        App.updateCurrency(profile.points, profile.premium_currency);
                        alert(result.message);
                    } catch (e) {
                        alert(e.message);
                    }
                });
            });
        } catch (e) {
            container.innerHTML = `<div class="text-center" style="padding:40px;color:var(--accent);">エラー: ${e.message}</div>`;
        }
    },

    productCard(product, isDemo) {
        const bonus = product.bonus_amount > 0 ? ` + ${product.bonus_amount}ボーナス` : '';
        return `
            <div class="shop-item">
                <div class="shop-item-info">
                    <div style="font-weight:600;">${product.name}</div>
                    <div style="font-size:12px;color:var(--text-secondary);">${product.description}</div>
                    <div style="font-size:12px;color:var(--gold);margin-top:2px;">
                        💎 ${product.premium_currency_amount} GEM${bonus}
                    </div>
                </div>
                <button class="btn btn-gold btn-sm purchase-btn" data-id="${product.id}">
                    ¥${product.price_yen.toLocaleString()}
                </button>
            </div>
        `;
    },
};
