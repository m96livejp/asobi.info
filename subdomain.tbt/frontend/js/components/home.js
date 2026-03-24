/**
 * ホーム画面
 */
const HomePage = {
    async render(container) {
        const user = App.user;
        container.innerHTML = `
            <div class="home-welcome">
                <h2>ようこそ、${user.display_name}さん</h2>
                <p style="color:var(--text-secondary);font-size:13px;">トーナメントで最強を目指そう！</p>
            </div>

            <div class="card">
                <div class="card-title">ステータス</div>
                <div style="display:flex;gap:12px;font-size:13px;">
                    <div>スタミナ: <strong>${user.stamina}</strong></div>
                    <div>キャラ数: <strong>${user.characters_count || 0}</strong></div>
                </div>
            </div>

            <div class="ad-reward-section">
                <div class="card-title">広告を見てポイントGET!</div>
                <p style="font-size:12px;color:var(--text-secondary);margin-bottom:8px;">
                    動画広告を見て50ポイントもらえます
                </p>
                <button class="btn btn-primary" id="ad-reward-btn">広告を見る (🪙50PT)</button>
                <div class="remaining" id="ad-remaining"></div>
            </div>

            <div class="home-actions mt-12">
                <button class="home-action-btn" data-page="character">
                    <span class="icon">⚔️</span>
                    <span class="label">キャラ</span>
                </button>
                <button class="home-action-btn" data-page="gacha">
                    <span class="icon">🎰</span>
                    <span class="label">ガチャ</span>
                </button>
                <button class="home-action-btn" data-page="items">
                    <span class="icon">📦</span>
                    <span class="label">アイテム</span>
                </button>
                <button class="home-action-btn" data-page="tournament">
                    <span class="icon">🏆</span>
                    <span class="label">大会</span>
                </button>
                <button class="home-action-btn" data-page="ranking">
                    <span class="icon">📊</span>
                    <span class="label">ランキング</span>
                </button>
                <button class="home-action-btn" data-page="shop">
                    <span class="icon">🛒</span>
                    <span class="label">ショップ</span>
                </button>
            </div>

            <div style="text-align:center;margin-top:24px;padding-bottom:8px;">
                <a href="#" id="home-terms-link" style="font-size:12px;color:var(--text-secondary);text-decoration:underline;">利用規約</a>
            </div>
        `;

        // ショートカットボタン
        container.querySelectorAll('.home-action-btn').forEach(btn => {
            btn.addEventListener('click', () => App.navigate(btn.dataset.page));
        });

        // 利用規約リンク
        document.getElementById('home-terms-link')?.addEventListener('click', (e) => {
            e.preventDefault();
            App.navigate('terms');
        });

        // 広告報酬ボタン: 事前に状態チェックしてグレーアウト
        const adBtn = document.getElementById('ad-reward-btn');
        const adRemaining = document.getElementById('ad-remaining');
        try {
            const status = await API.getAdStatus();
            adRemaining.textContent = `本日残り${status.daily_remaining}回 / 1時間残り${status.hourly_remaining}回`;
            if (!status.can_watch) {
                adBtn.disabled = true;
                adBtn.textContent = status.hourly_remaining === 0 && status.daily_remaining > 0
                    ? '1時間の上限に達しました' : '本日の上限に達しました';
            }
        } catch (_) {}

        adBtn.addEventListener('click', () => {
            adBtn.disabled = true;
            AdBanner.showRewardCountdown(async () => {
                try {
                    const result = await API.claimAdReward('points');
                    App.updateCurrency(result.points, null);
                    adRemaining.textContent =
                        `本日残り${result.remaining_daily_views}回 / 1時間残り${result.remaining_hourly_views}回`;
                    alert(`${result.reward_amount}ポイント獲得！`);
                    if (result.remaining_daily_views <= 0 || result.remaining_hourly_views <= 0) {
                        adBtn.disabled = true;
                        adBtn.textContent = result.remaining_hourly_views <= 0 && result.remaining_daily_views > 0
                            ? '1時間の上限に達しました' : '本日の上限に達しました';
                        return;
                    }
                } catch (e) {
                    alert(e.message);
                }
                adBtn.disabled = false;
                adBtn.textContent = '広告を見る (🪙50PT)';
            });
        });
    },
};
