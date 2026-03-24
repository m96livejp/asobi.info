/**
 * バナー広告コンポーネント (Google AdSense)
 */
const AdBanner = {
    initialized: false,

    init() {
        if (this.initialized) return;
        this.initialized = true;

        const banner = document.getElementById('ad-banner');
        if (!banner) return;

        // AdSenseが読み込まれていない場合はプレースホルダー表示
        try {
            (adsbygoogle = window.adsbygoogle || []).push({});
        } catch (e) {
            // AdSense未設定時はバナーを非表示
            banner.style.display = 'none';
            document.documentElement.style.setProperty('--ad-banner-height', '0px');
        }
    },

    /**
     * リワード広告のカウントダウン演出を表示
     * @param {Function} onComplete - 完了時コールバック
     */
    showRewardCountdown(onComplete) {
        const overlay = document.createElement('div');
        overlay.className = 'reward-ad-overlay';
        overlay.innerHTML = `
            <div class="reward-ad-content">
                <div class="reward-ad-icon">🎬</div>
                <div class="reward-ad-title">広告を視聴中...</div>
                <div class="reward-ad-countdown" id="reward-countdown">3</div>
                <div class="reward-ad-progress">
                    <div class="reward-ad-progress-bar" id="reward-progress-bar"></div>
                </div>
                <div style="font-size:11px;color:var(--text-secondary);margin-top:8px;">視聴完了で報酬を獲得できます</div>
            </div>
        `;
        document.body.appendChild(overlay);

        const countdownEl = document.getElementById('reward-countdown');
        const progressBar = document.getElementById('reward-progress-bar');
        let remaining = 3;

        // プログレスバーアニメーション
        progressBar.style.transition = 'width 3s linear';
        requestAnimationFrame(() => {
            progressBar.style.width = '100%';
        });

        const timer = setInterval(() => {
            remaining--;
            if (remaining > 0) {
                countdownEl.textContent = remaining;
            } else {
                clearInterval(timer);
                countdownEl.textContent = '0';
                setTimeout(() => {
                    overlay.remove();
                    if (onComplete) onComplete();
                }, 300);
            }
        }, 1000);
    },
};
