/**
 * トーナメント画面
 */
const TournamentPage = {
    async render(container) {
        container.innerHTML = '<div class="loading"><div class="spinner"></div>読み込み中...</div>';

        try {
            const tournaments = await API.getTournaments();

            container.innerHTML = `
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <div class="card-title" style="margin:0;">🏆 大会 / トーナメント</div>
                    <button class="btn btn-primary btn-sm" id="create-tournament-btn">新規作成</button>
                </div>
                <div id="tournament-list"></div>
            `;

            const list = document.getElementById('tournament-list');

            if (tournaments.length === 0) {
                list.innerHTML = '<div class="text-center" style="padding:20px;color:var(--text-secondary);">トーナメントがありません</div>';
            } else {
                tournaments.forEach(t => {
                    const card = document.createElement('div');
                    card.className = 'tournament-card';
                    card.innerHTML = `
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <div>
                                <div style="font-weight:600;">${t.name}</div>
                                <div style="font-size:12px;color:var(--text-secondary);margin-top:2px;">
                                    参加: ${t.current_participants}/${t.max_participants} | 報酬: ${t.reward_points}PT
                                </div>
                                ${t.my_character_name ? `<div style="font-size:12px;color:var(--accent);margin-top:3px;">⚔️ ${t.my_character_name} 出場中</div>` : ''}
                            </div>
                            <span class="tournament-status ${t.status}">${this.statusLabel(t.status)}</span>
                        </div>
                        <div style="margin-top:8px;display:flex;gap:8px;">
                            ${t.status === 'recruiting' ? `<button class="btn btn-primary btn-sm enter-btn" data-id="${t.id}" ${(t.current_participants >= t.max_participants || t.my_character_name) ? 'disabled' : ''}>参加する</button>` : ''}
                            <button class="btn btn-secondary btn-sm bracket-btn" data-id="${t.id}">トーナメント表</button>
                        </div>
                    `;
                    list.appendChild(card);
                });
            }

            // イベント
            document.getElementById('create-tournament-btn').addEventListener('click', () => this.createTournament());

            container.querySelectorAll('.enter-btn').forEach(btn => {
                btn.addEventListener('click', () => this.enterTournament(btn.dataset.id));
            });
            container.querySelectorAll('.bracket-btn').forEach(btn => {
                btn.addEventListener('click', () => this.showBracket(btn.dataset.id));
            });
        } catch (e) {
            container.innerHTML = `<div class="text-center" style="padding:40px;color:var(--accent);">エラー: ${e.message}</div>`;
        }
    },

    statusLabel(status) {
        const labels = { recruiting: '募集中', in_progress: '進行中', finished: '終了' };
        return labels[status] || status;
    },

    async createTournament() {
        // 参加人数を選択
        const maxP = await UI.select('参加人数を選択:', [
            { label: '4人トーナメント', value: 4 },
            { label: '8人トーナメント', value: 8 },
        ]);
        if (!maxP) return;

        const name = await UI.prompt('トーナメント名を入力:', `新トーナメント(${maxP}人)`);
        if (!name) return;

        try {
            await API.createTournament(name, maxP, 200);
            this.render(document.getElementById('main-content'));
        } catch (e) {
            alert(e.message);
        }
    },

    async enterTournament(tournamentId) {
        try {
            const [characters, activeData] = await Promise.all([
                API.getCharacters(),
                API.getActiveCharacterIds(),
            ]);
            if (characters.length === 0) {
                alert('参加するキャラクターがいません。ガチャを引いてください。');
                return;
            }

            const activeIds = new Set(activeData.character_ids);
            const selected = await UI.selectCharacter(characters, '参加キャラクターを選択', activeIds);
            if (!selected) return;

            await API.enterTournament(tournamentId, selected.id);
            alert('トーナメントに参加しました！');
            this.render(document.getElementById('main-content'));
        } catch (e) {
            alert(e.message);
            // エラー後もリストを最新状態に更新する（満員・開始済みの場合に表示を正す）
            this.render(document.getElementById('main-content'));
        }
    },

    RARITY_ICONS: { 1: '🗡️', 2: '⚔️', 3: '🔮', 4: '👑', 5: '🌟' },

    fighterHtml(name, playerName, rarity, isWinner) {
        const icon = this.RARITY_ICONS[rarity] || '🗡️';
        const cls = isWinner === true ? 'bk-w' : (isWinner === false ? 'bk-l' : '');
        const mark = isWinner === true ? '○' : (isWinner === false ? '✕' : '');
        return `<div class="bk-p ${cls}">
            <span class="bk-mark">${mark}</span>
            <span class="bk-icon">${icon}</span>
            <div class="bk-pinfo">
                <div class="bk-cname">${name}</div>
                <div class="bk-uname">${playerName}</div>
            </div>
        </div>`;
    },

    buildBracketTree(battles, maxParticipants, entries) {
        if (battles.length === 0) {
            if (entries && entries.length > 0) {
                let html = '<div class="bk-entry-list">';
                entries.forEach(e => {
                    const icon = this.RARITY_ICONS[e.rarity] || '🗡️';
                    html += `<div class="bk-entry-item"><span class="bk-icon">${icon}</span> ${e.character_name} <span class="bk-uname">${e.user_name}</span></div>`;
                });
                html += '</div>';
                return html;
            }
            return '<div style="color:var(--text-secondary);text-align:center;padding:12px;font-size:13px;">まだバトルは行われていません</div>';
        }

        const SLOT_H = 44;   // 選手1人分の高さ
        const HEADER_H = 18; // ラベル行の高さ
        const PLAYER_W = 100; // 選手名欄幅
        const CONN_W = 70;   // 各ラウンド列の幅
        const BTN_OFF = 30;  // ボタン中心のx（列左端からのオフセット）
        const BTN_R = 14;    // ボタン半径
        const CHAMP_W = 68;  // 優勝者欄幅

        const numRounds = Math.log2(maxParticipants);
        const totalH = HEADER_H + maxParticipants * SLOT_H;
        const totalW = PLAYER_W + numRounds * CONN_W + CHAMP_W;
        const ACCENT = 'var(--accent)';

        const roundMap = {};
        battles.forEach(b => {
            if (!roundMap[b.round]) roundMap[b.round] = [];
            roundMap[b.round].push(b);
        });

        // ラウンドr(1始まり)、試合m(0始まり)のボタンx中心
        const bx = (r) => PLAYER_W + (r - 1) * CONN_W + BTN_OFF;
        // ラウンドr、試合mの中心y（HEADER_H分オフセット）
        const cy = (r, m) => HEADER_H + (m + 0.5) * Math.pow(2, r) * SLOT_H;
        // ラウンドr、試合mの上/下エントリーy
        const topEY = (r, m) => HEADER_H + (2 * m + 0.5) * Math.pow(2, r - 1) * SLOT_H;
        const botEY = (r, m) => HEADER_H + (2 * m + 1.5) * Math.pow(2, r - 1) * SLOT_H;

        // R1バトルから選手名を取得。なければentriesから補完
        const playerNames = [];
        for (let i = 0; i < maxParticipants; i++) {
            playerNames[i] = { name: '—', uname: '', rarity: 1, isWinner: null };
        }
        if (entries) {
            entries.forEach((e, i) => {
                if (i < maxParticipants) {
                    playerNames[i] = { name: e.character_name, uname: e.user_name, rarity: e.rarity || 1, isWinner: null };
                }
            });
        }
        (roundMap[1] || []).forEach((b, m) => {
            const aWin = b.winner_name === b.attacker_name;
            playerNames[m * 2]     = { name: b.attacker_name, uname: b.attacker_player_name || '', rarity: b.attacker_rarity || 1, isWinner: aWin };
            playerNames[m * 2 + 1] = { name: b.defender_name,  uname: b.defender_player_name  || '', rarity: b.defender_rarity  || 1, isWinner: !aWin };
        });

        let svgLines = '';
        let elems = '';

        // ラベル行（SVGテキスト）
        svgLines += `<text x="${PLAYER_W / 2}" y="${HEADER_H - 4}" text-anchor="middle" font-size="10" font-weight="700" fill="${ACCENT}">参加者</text>`;
        for (let r = 1; r <= numRounds; r++) {
            const lbl = r === numRounds ? '決勝' : `第${r}戦`;
            svgLines += `<text x="${bx(r)}" y="${HEADER_H - 4}" text-anchor="middle" font-size="10" font-weight="700" fill="${ACCENT}">${lbl}</text>`;
        }
        svgLines += `<text x="${totalW - CHAMP_W / 2}" y="${HEADER_H - 4}" text-anchor="middle" font-size="10" font-weight="700" fill="${ACCENT}">優勝</text>`;

        // 選手スロット + 水平線（→R1ボタンへ）
        for (let i = 0; i < maxParticipants; i++) {
            const slotTop = HEADER_H + i * SLOT_H;
            const pcy = HEADER_H + (i + 0.5) * SLOT_H;
            const p = playerNames[i];
            const icon = this.RARITY_ICONS[p.rarity] || '🗡️';
            const winCls = p.isWinner === true ? ' bk2-pw' : (p.isWinner === false ? ' bk2-pl' : '');

            svgLines += `<line x1="${PLAYER_W}" y1="${pcy}" x2="${bx(1)}" y2="${pcy}" stroke="${ACCENT}" stroke-width="1.5" opacity="0.6"/>`;

            elems += `<div class="bk2-pslot${winCls}" style="top:${slotTop}px;height:${SLOT_H}px;width:${PLAYER_W - 2}px;">
                <span class="bk2-picon">${icon}</span>
                <div class="bk2-pinfo2">
                    <div class="bk2-cname2">${p.name}</div>
                    <div class="bk-uname">${p.uname}</div>
                </div>
            </div>`;
        }

        // 各ラウンドのブラケット線とボタン
        for (let r = 1; r <= numRounds; r++) {
            const numMatches = maxParticipants / Math.pow(2, r);
            const roundBattles = roundMap[r] || [];
            const btnX = bx(r);

            for (let m = 0; m < numMatches; m++) {
                const cY = cy(r, m);
                const tY = topEY(r, m);
                const bY = botEY(r, m);

                // 縦ブラケット線（上下エントリーをつなぐ）
                svgLines += `<line x1="${btnX}" y1="${tY}" x2="${btnX}" y2="${bY}" stroke="${ACCENT}" stroke-width="1.5" opacity="0.8"/>`;

                // 水平線（ボタン右→次ラウンドへ）
                if (r < numRounds) {
                    svgLines += `<line x1="${btnX}" y1="${cY}" x2="${bx(r + 1)}" y2="${cY}" stroke="${ACCENT}" stroke-width="1.5" opacity="0.8"/>`;
                } else {
                    svgLines += `<line x1="${btnX}" y1="${cY}" x2="${totalW - CHAMP_W + 4}" y2="${cY}" stroke="${ACCENT}" stroke-width="1.5" opacity="0.8"/>`;
                }

                // 試合ボタン
                const battle = roundBattles[m];
                if (battle) {
                    elems += `<button class="bk2-btn bk2-btn-done replay-btn"
                        style="left:${btnX - BTN_R}px;top:${cY - BTN_R}px;width:${BTN_R * 2}px;height:${BTN_R * 2}px;"
                        data-battle-id="${battle.battle_id || ''}"
                        title="${battle.attacker_name} vs ${battle.defender_name}">▶</button>`;
                } else {
                    const lbl = r === numRounds ? '決' : `R${r}`;
                    elems += `<div class="bk2-btn bk2-btn-tbd"
                        style="left:${btnX - BTN_R}px;top:${cY - BTN_R}px;width:${BTN_R * 2}px;height:${BTN_R * 2}px;"
                        ><span style="font-size:7px;">${lbl}</span></div>`;
                }
            }
        }

        // 優勝者表示
        const finalBattle = (roundMap[numRounds] || [])[0];
        if (finalBattle) {
            const wRarity = finalBattle.winner_name === finalBattle.attacker_name ? finalBattle.attacker_rarity : finalBattle.defender_rarity;
            const wPlayer = finalBattle.winner_name === finalBattle.attacker_name ? finalBattle.attacker_player_name : finalBattle.defender_player_name;
            const wIcon = this.RARITY_ICONS[wRarity] || '🗡️';
            const champTop = HEADER_H + maxParticipants * SLOT_H / 2 - 28;
            elems += `<div class="bk2-champ" style="left:${totalW - CHAMP_W + 4}px;top:${champTop}px;">
                🏆<br>${wIcon} ${finalBattle.winner_name}
                <div class="bk-champ-player">${wPlayer || ''}</div>
            </div>`;
        }

        return `<div class="bk2-outer"><div class="bk2-wrap" style="position:relative;width:${totalW}px;height:${totalH}px;">
            <svg style="position:absolute;top:0;left:0;width:${totalW}px;height:${totalH}px;overflow:visible;" xmlns="http://www.w3.org/2000/svg">
                ${svgLines}
            </svg>
            ${elems}
        </div></div>`;
    },

    async showBracket(tournamentId) {
        try {
            const bracket = await API.getBracket(tournamentId);

            const overlay = document.createElement('div');
            overlay.className = 'char-detail-overlay';

            const treeHtml = this.buildBracketTree(bracket.battles, bracket.max_participants || 8, bracket.entries || []);

            overlay.innerHTML = `
                <div class="char-detail" style="max-height:85vh;overflow:auto;max-width:95vw;width:auto;">
                    <h3 style="text-align:center;">${bracket.tournament_name}</h3>
                    <div style="text-align:center;margin-top:4px;">
                        <span class="tournament-status ${bracket.status}">${this.statusLabel(bracket.status)}</span>
                    </div>
                    <div class="card-title mt-12">トーナメント表</div>
                    ${treeHtml}
                    <button class="btn btn-secondary mt-16" id="close-bracket">閉じる</button>
                </div>
            `;

            document.body.appendChild(overlay);
            overlay.querySelector('#close-bracket').addEventListener('click', () => overlay.remove());
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) overlay.remove();
            });

            // バトル再生ボタン
            overlay.querySelectorAll('.replay-btn').forEach(btn => {
                btn.addEventListener('click', () => this.replayBattle(btn.dataset.battleId));
            });
        } catch (e) {
            alert(e.message);
        }
    },

    async replayBattle(battleId) {
        try {
            const battleData = await API.getBattle(battleId);
            await Animation.playBattle(battleData);
        } catch (e) {
            alert(e.message);
        }
    },
};
