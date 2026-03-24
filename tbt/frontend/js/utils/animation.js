/**
 * バトル・ガチャアニメーション
 */
const Animation = {
    RARITY_NAMES: { 1: 'N', 2: 'R', 3: 'SR', 4: 'SSR', 5: 'UR' },
    RARITY_ICONS: { 1: '🗡️', 2: '⚔️', 3: '🔮', 4: '👑', 5: '🌟' },
    RARITY_CLASS: { 1: 'n', 2: 'r', 3: 'sr', 4: 'ssr', 5: 'ur' },

    /** バトル演出を再生 */
    async playBattle(battleData) {
        const overlay = document.getElementById('battle-overlay');
        const charLeft = document.getElementById('battle-char-left');
        const charRight = document.getElementById('battle-char-right');
        const logEl = document.getElementById('battle-log');
        const resultEl = document.getElementById('battle-result');
        const closeBtn = document.getElementById('battle-close');

        overlay.classList.remove('hidden');
        resultEl.classList.add('hidden');
        closeBtn.classList.add('hidden');
        logEl.innerHTML = '';

        // キャラ名を取得
        const turns = battleData.turns;
        const leftName = turns.length > 0 ? turns[0].actor : '???';
        const leftId = turns.length > 0 ? turns[0].actor_id : '';
        let rightName = '???';
        for (const t of turns) {
            if (t.actor_id !== leftId) {
                rightName = t.actor;
                break;
            }
        }

        // プレイヤー名とレアリティアイコン
        const leftPlayerName = battleData.attacker_player_name || '';
        const rightPlayerName = battleData.defender_player_name || '';
        const leftIcon = this.RARITY_ICONS[battleData.attacker_rarity] || '🗡️';
        const rightIcon = this.RARITY_ICONS[battleData.defender_rarity] || '🗡️';

        // 初期HP (最初のターンのactor_hp_remaining + 最初のダメージから推定)
        let leftMaxHp = 0, rightMaxHp = 0;
        for (const t of turns) {
            if (t.actor_id === leftId && leftMaxHp === 0) {
                leftMaxHp = t.actor_hp_remaining;
            }
            if (t.actor_id !== leftId && rightMaxHp === 0) {
                rightMaxHp = t.actor_hp_remaining;
            }
            if (leftMaxHp && rightMaxHp) break;
        }
        // ダメージを受ける前のHPが最大値
        if (turns.length > 0) {
            for (const t of turns) {
                if (t.actor_id === leftId) {
                    rightMaxHp = Math.max(rightMaxHp, t.target_hp_remaining + t.damage);
                } else {
                    leftMaxHp = Math.max(leftMaxHp, t.target_hp_remaining + t.damage);
                }
            }
        }
        if (leftMaxHp === 0) leftMaxHp = 100;
        if (rightMaxHp === 0) rightMaxHp = 100;

        let leftHp = leftMaxHp;
        let rightHp = rightMaxHp;

        charLeft.innerHTML = `
            ${leftPlayerName ? `<div class="player-name">${leftPlayerName}</div>` : ''}
            <div class="char-icon-battle">${leftIcon}</div>
            <div class="name">${leftName}</div>
            <div class="hp-bar"><div class="hp-fill" id="hp-left" style="width:100%"></div></div>
            <div class="hp-text" id="hp-text-left">${leftHp}/${leftMaxHp}</div>
        `;
        charRight.innerHTML = `
            ${rightPlayerName ? `<div class="player-name">${rightPlayerName}</div>` : ''}
            <div class="char-icon-battle">${rightIcon}</div>
            <div class="name">${rightName}</div>
            <div class="hp-bar"><div class="hp-fill" id="hp-right" style="width:100%"></div></div>
            <div class="hp-text" id="hp-text-right">${rightHp}/${rightMaxHp}</div>
        `;

        // ターンを順番に表示
        for (let i = 0; i < turns.length; i++) {
            const t = turns[i];
            await this.delay(400);

            const isSkill = t.action === 'skill';
            const actionText = isSkill
                ? `<span class="skill">${t.skill_name}</span>`
                : '通常攻撃';

            logEl.innerHTML += `<div class="turn-action">T${t.turn}: ${t.actor} の${actionText} → <span class="damage">${t.damage}ダメージ</span></div>`;
            logEl.scrollTop = logEl.scrollHeight;

            // HP更新
            if (t.actor_id === leftId) {
                rightHp = t.target_hp_remaining;
                const pct = Math.max(0, (rightHp / rightMaxHp) * 100);
                document.getElementById('hp-right').style.width = pct + '%';
                document.getElementById('hp-text-right').textContent = `${rightHp}/${rightMaxHp}`;
            } else {
                leftHp = t.target_hp_remaining;
                const pct = Math.max(0, (leftHp / leftMaxHp) * 100);
                document.getElementById('hp-left').style.width = pct + '%';
                document.getElementById('hp-text-left').textContent = `${leftHp}/${leftMaxHp}`;
            }
        }

        // 結果表示
        await this.delay(500);
        resultEl.innerHTML = `<div class="winner-text">${battleData.winner_name} WIN!</div>`;
        resultEl.classList.remove('hidden');
        closeBtn.classList.remove('hidden');

        return new Promise(resolve => {
            closeBtn.onclick = () => {
                overlay.classList.add('hidden');
                resolve();
            };
        });
    },

    /** ガチャ演出を再生 */
    async playGacha(results) {
        const overlay = document.getElementById('gacha-overlay');
        const animEl = document.getElementById('gacha-animation');
        const resultsEl = document.getElementById('gacha-results');
        const closeBtn = document.getElementById('gacha-close');

        overlay.classList.remove('hidden');
        resultsEl.classList.add('hidden');
        closeBtn.classList.add('hidden');

        // 最高レアリティに応じた演出
        const maxRarity = Math.max(...results.map(r => r.rarity));
        const effect = maxRarity >= 4 ? '✨🌟✨' : maxRarity >= 3 ? '💫' : '⭐';

        animEl.innerHTML = `<div class="opening">${effect}</div>`;
        await this.delay(1200);

        // 結果表示
        animEl.innerHTML = '';
        resultsEl.classList.remove('hidden');
        resultsEl.innerHTML = '';

        for (let i = 0; i < results.length; i++) {
            const r = results[i];
            const rarityClass = this.RARITY_CLASS[r.rarity] || 'n';
            const icon = this.RARITY_ICONS[r.rarity] || '🗡️';
            const rarityName = this.RARITY_NAMES[r.rarity] || 'N';

            await this.delay(200);

            const card = document.createElement('div');
            card.className = `gacha-result-card ${r.is_new ? 'new' : ''}`;
            card.style.background = `rgba(${this.getRarityColor(r.rarity)}, 0.3)`;
            card.style.animationDelay = `${i * 0.1}s`;
            card.innerHTML = `
                <div class="char-icon">${icon}</div>
                <span class="rarity-badge ${rarityClass}">${rarityName}</span>
                <div class="char-name">${r.name}</div>
                ${r.is_new ? '<div style="color:var(--gold);font-size:10px;">NEW!</div>' : ''}
            `;
            resultsEl.appendChild(card);
        }

        // 10連は全カード表示後さらに待機してから閉じるボタンを表示
        await this.delay(results.length >= 10 ? 1500 : 600);
        closeBtn.classList.remove('hidden');

        return new Promise(resolve => {
            closeBtn.onclick = () => {
                overlay.classList.add('hidden');
                resolve();
            };
        });
    },

    getRarityColor(rarity) {
        const colors = { 1: '153,153,153', 2: '100,181,246', 3: '206,147,216', 4: '255,215,0', 5: '255,107,157' };
        return colors[rarity] || '136,136,136';
    },

    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    },
};
