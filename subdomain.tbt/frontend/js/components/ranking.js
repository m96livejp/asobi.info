/**
 * ランキング画面
 */
const RankingPage = {
    async render(container) {
        container.innerHTML = '<div class="loading"><div class="spinner"></div>読み込み中...</div>';

        try {
            const data = await API.getRanking();
            const { ranking, my_rank, my_points, my_display_name } = data;

            let html = `
                <div class="card-title">ポイントランキング</div>
                <div class="rank-list">
            `;

            ranking.forEach(r => {
                const medal = r.rank === 1 ? '🥇' : r.rank === 2 ? '🥈' : r.rank === 3 ? '🥉' : null;
                const rankDisp = medal || `${r.rank}`;
                const meCls = r.is_me ? ' rank-me' : '';

                html += `
                    <div class="rank-row${meCls}">
                        <div class="rank-num">${rankDisp}</div>
                        <div class="rank-name">${r.display_name}</div>
                        <div class="rank-pts">🪙 ${r.points.toLocaleString()} PT</div>
                    </div>
                `;
            });

            html += '</div>';

            // 自分がトップN外の場合
            if (my_rank !== null && my_rank !== undefined) {
                html += `
                    <div class="rank-myrow">
                        <div class="rank-row rank-me">
                            <div class="rank-num">${my_rank}</div>
                            <div class="rank-name">${my_display_name} <span style="font-size:10px;opacity:0.7;">（あなた）</span></div>
                            <div class="rank-pts">🪙 ${my_points.toLocaleString()} PT</div>
                        </div>
                    </div>
                `;
            }

            container.innerHTML = html;
        } catch (e) {
            container.innerHTML = `<div class="text-center" style="padding:40px;color:var(--accent);">エラー: ${e.message}</div>`;
        }
    },
};
