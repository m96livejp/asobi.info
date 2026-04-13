/**
 * プラットフォーム ランディングページ JS
 * 注目ゲーム・ジャンル別・メーカー別を読み込む
 */
(function() {
    const API_BASE = '/api';

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    // ゲームの代表画像を選択（title_image > box_image > cart_image）
    function gameThumb(g) {
        return g.title_image || g.box_image || g.cart_image || '';
    }

    // 注目ゲーム
    async function loadPopular() {
        const el = document.getElementById('popular-games');
        try {
            const res = await fetch(`${API_BASE}/games.php?action=popular&platform=${PLATFORM_KEY}&limit=20`);
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'エラー');

            if (data.games.length === 0) {
                el.innerHTML = '<p class="empty-msg">まだデータがありません。</p>';
                return;
            }

            el.innerHTML = data.games.map(g => {
                const thumb = gameThumb(g);
                return `
                <a href="/${g.platform}/${g.slug}.html" class="game-card">
                    <div class="game-card-img">
                        ${thumb
                            ? `<img src="${escHtml(thumb)}" alt="${escHtml(g.title)}" loading="lazy">`
                            : '🎮'}
                    </div>
                    <div class="game-card-info">
                        <div class="game-card-title">${escHtml(g.title)}</div>
                        <div class="game-card-meta">
                            ${g.genre ? `<span class="game-card-genre">${escHtml(g.genre)}</span>` : ''}
                            ${g.publisher ? `<span>${escHtml(g.publisher)}</span>` : ''}
                        </div>
                    </div>
                </a>
            `;}).join('');
        } catch (e) {
            el.innerHTML = '<p class="empty-msg">読み込みに失敗しました。</p>';
        }
    }

    // ジャンル別
    async function loadGenres() {
        const el = document.getElementById('genre-list');
        try {
            const res = await fetch(`${API_BASE}/games.php?action=genres&platform=${PLATFORM_KEY}`);
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'エラー');

            if (data.genres.length === 0) {
                el.innerHTML = '<p class="empty-msg">ジャンルデータは準備中です。</p>';
                return;
            }

            el.innerHTML = data.genres.map(g => `
                <a href="/${PLATFORM_KEY}/list.php?genre=${encodeURIComponent(g.genre)}" class="genre-card">
                    <span class="genre-card-name">${escHtml(g.genre)}</span>
                    <span class="genre-card-count">${g.game_count}タイトル</span>
                </a>
            `).join('');
        } catch (e) {
            el.innerHTML = '<p class="empty-msg">読み込みに失敗しました。</p>';
        }
    }

    // メーカー別
    async function loadPublishers() {
        const el = document.getElementById('publisher-list');
        try {
            const res = await fetch(`${API_BASE}/games.php?action=publishers&platform=${PLATFORM_KEY}`);
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'エラー');

            if (data.publishers.length === 0) {
                el.innerHTML = '<p class="empty-msg">メーカーデータがありません。</p>';
                return;
            }

            el.innerHTML = data.publishers.map(p => `
                <a href="/${PLATFORM_KEY}/list.php?publisher=${encodeURIComponent(p.publisher)}" class="tag-card">
                    <span class="tag-name">${escHtml(p.publisher)}</span>
                    <span class="tag-count">${p.game_count}</span>
                </a>
            `).join('');
        } catch (e) {
            el.innerHTML = '<p class="empty-msg">読み込みに失敗しました。</p>';
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        loadPopular();
        loadGenres();
        loadPublishers();
    });
})();
