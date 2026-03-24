const TournamentsSection = {
    page: 1,
    statusFilter: '',

    async render(container) {
        container.innerHTML = `
            <div class="section-header"><h2>トーナメント管理</h2></div>
            <div class="toolbar">
                <select id="tournament-status">
                    <option value="">全ステータス</option>
                    <option value="recruiting">募集中</option>
                    <option value="in_progress">進行中</option>
                    <option value="finished">終了</option>
                </select>
            </div>
            <div id="tournaments-table" class="card"><div class="loading">読み込み中...</div></div>
        `;

        document.getElementById('tournament-status').onchange = (e) => {
            this.statusFilter = e.target.value;
            this.page = 1;
            this.loadTable();
        };

        await this.loadTable();
    },

    async loadTable() {
        const el = document.getElementById('tournaments-table');
        try {
            const params = { page: this.page, per_page: 20 };
            if (this.statusFilter) params.status = this.statusFilter;
            const data = await AdminAPI.getTournaments(params);

            if (!data.length) {
                el.innerHTML = '<div class="loading">トーナメントが見つかりません</div>';
                return;
            }

            el.innerHTML = `
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>名前</th>
                            <th>ステータス</th>
                            <th>参加者</th>
                            <th>ラウンド</th>
                            <th>報酬PT</th>
                            <th>作成日</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${data.map(t => `
                            <tr>
                                <td>${t.name}</td>
                                <td><span class="badge badge-${t.status}">${t.status}</span></td>
                                <td>${t.current_participants} / ${t.max_participants}</td>
                                <td>${t.current_round}</td>
                                <td>${t.reward_points}</td>
                                <td>${AdminApp.formatDate(t.created_at)}</td>
                                <td><button class="btn btn-sm btn-danger" onclick="TournamentsSection.deleteTournament('${t.id}')">削除</button></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        } catch (err) {
            el.innerHTML = `<div class="loading">エラー: ${err.message}</div>`;
        }
    },

    async deleteTournament(id) {
        if (!confirm('このトーナメントを削除しますか？関連するバトルログ・エントリーも全て削除されます。')) return;
        try {
            await AdminAPI.deleteTournament(id);
            AdminApp.toast('削除しました');
            this.loadTable();
        } catch (err) {
            AdminApp.toast(err.message, 'error');
        }
    },
};
