/**
 * メインアプリケーション
 */
const App = {
    user: null,
    currentPage: 'home',

    pages: {
        home: HomePage,
        character: CharacterPage,
        gacha: GachaPage,
        items: ItemsPage,
        battle: BattlePage,
        tournament: TournamentPage,
        shop: ShopPage,
        ranking: RankingPage,
        settings: SettingsPage,
        terms: TermsPage,
        login: LoginPage,
    },

    async init() {
        // PWA Service Worker登録
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js').catch(() => {});
        }

        // 認証
        try {
            this.user = await Auth.init();
        } catch (e) {
            document.getElementById('main-content').innerHTML = `
                <div class="text-center" style="padding:40px;">
                    <p style="color:var(--accent);">サーバーに接続できません</p>
                    <p style="color:var(--text-secondary);font-size:12px;margin-top:8px;">${e.message}</p>
                    <button class="btn btn-primary mt-16" onclick="location.reload()">再試行</button>
                </div>
            `;
            return;
        }

        // 未ログイン → ログイン画面
        if (!this.user) {
            document.getElementById('header').style.display = 'none';
            document.getElementById('bottom-nav').style.display = 'none';
            document.getElementById('ad-banner').style.display = 'none';
            document.getElementById('main-content').style.paddingTop = '0';
            document.getElementById('main-content').style.paddingBottom = '0';
            LoginPage.render(document.getElementById('main-content'));
            return;
        }

        this.updateCurrency(this.user.points, this.user.premium_currency);

        // ナビゲーション
        document.querySelectorAll('.nav-btn').forEach(btn => {
            btn.addEventListener('click', () => this.navigate(btn.dataset.page));
        });

        // 設定ボタン
        document.getElementById('settings-btn')?.addEventListener('click', () => this.navigate('settings'));

        // バナー広告初期化
        AdBanner.init();

        // ゲームタイトルクリックで大会ページへ
        document.querySelector('.game-title')?.addEventListener('click', () => this.navigate('tournament'));

        // 初期画面
        this.navigate('home');
    },

    navigate(page) {
        if (!this.pages[page]) return;

        this.currentPage = page;

        // ナビのアクティブ状態更新
        document.querySelectorAll('.nav-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.page === page);
        });

        // 画面描画
        const container = document.getElementById('main-content');
        container.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
        this.pages[page].render(container);
    },

    updateCurrency(points, premium) {
        if (points !== null && points !== undefined) {
            this.user.points = points;
            document.getElementById('points-display').textContent = `🪙 ${points.toLocaleString()} PT`;
        }
        if (premium !== null && premium !== undefined) {
            this.user.premium_currency = premium;
            document.getElementById('premium-display').textContent = `💎 ${premium.toLocaleString()} GEM`;
        }
    },
};

// アプリ起動
document.addEventListener('DOMContentLoaded', () => App.init());
