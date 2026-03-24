/**
 * バトル画面 (練習バトル)
 */
const BattlePage = {
    selectedAttacker: null,
    selectedDefender: null,

    async render(container) {
        container.innerHTML = '<div class="loading"><div class="spinner"></div>読み込み中...</div>';

        try {
            const characters = await API.getCharacters();

            if (characters.length < 2) {
                container.innerHTML = `
                    <div class="text-center" style="padding:40px;">
                        <p>練習バトルには2体以上のキャラが必要です</p>
                        <button class="btn btn-primary mt-16" onclick="App.navigate('gacha')">ガチャを引く</button>
                    </div>
                `;
                return;
            }

            this.selectedAttacker = null;
            this.selectedDefender = null;

            container.innerHTML = `
                <div class="card-title mb-8">練習バトル</div>
                <p style="font-size:12px;color:var(--text-secondary);margin-bottom:12px;">
                    2体のキャラクターを選んでバトル！
                </p>

                <div class="card">
                    <div class="card-title" style="color:var(--accent);">アタッカー</div>
                    <div id="attacker-select" style="font-size:13px;color:var(--text-secondary);">タップして選択...</div>
                </div>

                <div class="card">
                    <div class="card-title" style="color:var(--rarity-r);">ディフェンダー</div>
                    <div id="defender-select" style="font-size:13px;color:var(--text-secondary);">タップして選択...</div>
                </div>

                <button class="btn btn-primary mt-12" id="battle-start-btn" disabled>バトル開始！</button>

                <div class="card-title mt-16 mb-8">キャラクター選択</div>
                <div id="char-select-list"></div>
            `;

            const list = document.getElementById('char-select-list');
            characters.forEach(char => {
                const rarityClass = Animation.RARITY_CLASS[char.template_rarity] || 'n';
                const card = document.createElement('div');
                card.className = 'char-card';
                card.dataset.rarity = char.template_rarity;
                card.dataset.id = char.id;
                card.innerHTML = `
                    <div class="char-avatar" data-rarity="${char.template_rarity}">
                        ${Animation.RARITY_ICONS[char.template_rarity] || '🗡️'}
                    </div>
                    <div class="char-info">
                        <div class="char-name">${char.template_name}</div>
                        <div class="char-rarity">
                            <span class="rarity-badge ${rarityClass}">${Animation.RARITY_NAMES[char.template_rarity]}</span>
                            <span class="char-level">Lv.${char.level}</span>
                        </div>
                        <div class="char-stats">
                            <span>HP:${char.hp}</span>
                            <span>ATK:${char.atk}</span>
                            <span>DEF:${char.def_}</span>
                            <span>SPD:${char.spd}</span>
                        </div>
                    </div>
                `;

                card.addEventListener('click', () => {
                    if (!this.selectedAttacker) {
                        this.selectedAttacker = char;
                        document.getElementById('attacker-select').innerHTML =
                            `<strong>${char.template_name}</strong> Lv.${char.level}`;
                        card.style.opacity = '0.5';
                    } else if (!this.selectedDefender && char.id !== this.selectedAttacker.id) {
                        this.selectedDefender = char;
                        document.getElementById('defender-select').innerHTML =
                            `<strong>${char.template_name}</strong> Lv.${char.level}`;
                        card.style.opacity = '0.5';
                        document.getElementById('battle-start-btn').disabled = false;
                    }
                });

                list.appendChild(card);
            });

            document.getElementById('battle-start-btn').addEventListener('click', () => this.startBattle());
        } catch (e) {
            container.innerHTML = `<div class="text-center" style="padding:40px;color:var(--accent);">エラー: ${e.message}</div>`;
        }
    },

    async startBattle() {
        if (!this.selectedAttacker || !this.selectedDefender) return;

        try {
            const result = await API.practiceBattle(this.selectedAttacker.id, this.selectedDefender.id);
            await Animation.playBattle(result);
        } catch (e) {
            alert(e.message);
        }
    },
};
