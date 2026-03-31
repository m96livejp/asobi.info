/**
 * プラットフォーム一覧ページ共通JS
 * ページに PLATFORM_KEY を定義してから読み込む
 */
(function() {
    const API_BASE = '/api';
    let currentPage = 1;
    let currentQ = '';
    const LIMIT = 60;

    async function loadGames(page, q) {
        const list = document.getElementById('game-list');
        const pagination = document.getElementById('pagination');
        list.innerHTML = '<div class="loading"><div class="spinner"></div><p>読み込み中...</p></div>';
        pagination.innerHTML = '';

        const params = new URLSearchParams({
            action: 'list',
            platform: PLATFORM_KEY,
            page,
            limit: LIMIT,
        });
        if (q) params.set('q', q);

        try {
            const res = await fetch(`${API_BASE}/games.php?${params}`);
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'エラー');

            if (data.games.length === 0) {
                list.innerHTML = '<p style="color:var(--text2);padding:20px 0;">ゲームが見つかりませんでした。</p>';
                return;
            }

            list.innerHTML = data.games.map(g => `
                <a href="/${g.platform}/${g.slug}.html" class="game-card">
                    <div class="game-card-img">
                        ${g.image_path
                            ? `<img src="${escHtml(g.image_path)}" alt="${escHtml(g.title)}" loading="lazy">`
                            : '🎮'}
                    </div>
                    <div class="game-card-info">
                        <div class="game-card-title">${escHtml(g.title)}</div>
                        ${g.title_en ? `<div class="game-card-sub">${escHtml(g.title_en)}</div>` : ''}
                        ${g.release_year ? `<div class="game-card-sub">${g.release_year}年</div>` : ''}
                    </div>
                </a>
            `).join('');

            // ページング
            const totalPages = Math.ceil(data.total / LIMIT);
            if (totalPages > 1) {
                const btns = [];
                for (let p = 1; p <= totalPages; p++) {
                    btns.push(`<button class="${p === page ? 'active' : ''}" data-page="${p}">${p}</button>`);
                }
                pagination.innerHTML = btns.join('');
                pagination.querySelectorAll('button').forEach(btn => {
                    btn.addEventListener('click', () => {
                        currentPage = parseInt(btn.dataset.page);
                        loadGames(currentPage, currentQ);
                        window.scrollTo({top: 0, behavior: 'smooth'});
                    });
                });
            }
        } catch (e) {
            list.innerHTML = `<p style="color:var(--accent);padding:20px 0;">エラー: ${e.message}</p>`;
        }
    }

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    document.addEventListener('DOMContentLoaded', () => {
        loadGames(1, '');

        const searchInput = document.getElementById('search-input');
        const searchBtn   = document.getElementById('search-btn');

        function doSearch() {
            currentQ = searchInput.value.trim();
            currentPage = 1;
            loadGames(currentPage, currentQ);
        }

        searchBtn?.addEventListener('click', doSearch);
        searchInput?.addEventListener('keydown', e => { if (e.key === 'Enter') doSearch(); });
    });
})();
