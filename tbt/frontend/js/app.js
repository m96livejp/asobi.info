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

        // バナー広告初期化
        AdBanner.init();

        // ゲームタイトルクリックでトップへ
        document.querySelector('.game-title')?.addEventListener('click', () => this.navigate('home'));

        // ハッシュ変更時にページ切り替え（ユーザーメニューからのリンク対応）
        window.addEventListener('hashchange', () => {
            const page = location.hash.replace('#', '');
            if (this.pages[page] && page !== this.currentPage) this.navigate(page);
        });

        // 初期画面（URLハッシュがあればそのページを表示）
        const initialPage = location.hash.replace('#', '');
        this.navigate(this.pages[initialPage] ? initialPage : 'home');
    },

    navigate(page) {
        if (!this.pages[page]) return;

        this.currentPage = page;

        // URLハッシュを更新（リロード時に同じページに戻れるよう）
        history.replaceState(null, '', page === 'home' ? location.pathname : '#' + page);

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
        }
        if (premium !== null && premium !== undefined) {
            this.user.premium_currency = premium;
        }
    },
};

// アプリ起動
document.addEventListener('DOMContentLoaded', () => App.init());
