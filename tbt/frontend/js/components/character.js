/**
 * キャラクター管理画面
 */
const CharacterPage = {
    RARITY_NAMES: { 1: 'N', 2: 'R', 3: 'SR', 4: 'SSR', 5: 'UR' },
    RARITY_ICONS: { 1: '🗡️', 2: '⚔️', 3: '🔮', 4: '👑', 5: '🌟' },
    RACE_NAMES: { warrior: '戦士', mage: '魔法使い', beastman: '獣人' },
    RACE_ICONS: { warrior: '⚔️', mage: '🔮', beastman: '🐾' },
    SLOT_NAMES: {
        weapon1: '武器1', weapon2: '武器2/盾',
        head: '頭', body: '体', hands: '手', feet: '足',
        accessory1: '装飾1', accessory2: '装飾2', accessory3: '装飾3',
    },
    // 装備タイプ → 装備可能スロット説明
    EQUIP_SLOT_LABELS: {
        weapon_1h: '武器1 または 武器2/盾スロット',
        weapon_2h: '武器1+2スロット（両手武器）',
        shield:    '武器2/盾スロット',
        head:      '頭スロット',
        body:      '体スロット',
        hands:     '手スロット',
        feet:      '足スロット',
        accessory: '装飾スロット（1〜3）',
    },
    RACE_LABELS: {
        all: '', warrior: '戦士専用', mage: '魔法使い専用', beastman: '獣人専用',
    },

    // レベルアップに必要なEXP (3^level)
    requiredExp(level) { return Math.pow(3, level); },

    // 特訓で得られるEXP計算 (兵士EXP(最低1) + レアリティ固定ボーナス)
    RARITY_BONUS: { 1: 1, 2: 5, 3: 10, 4: 25, 5: 50 },
    calcTrainExp(soldier) {
        const bonus = this.RARITY_BONUS[soldier.template_rarity] || 1;
        return Math.max(1, soldier.exp) + bonus;
    },

    async render(container) {
        container.innerHTML = '<div class="loading"><div class="spinner"></div>読み込み中...</div>';

        try {
            const characters = await API.getCharacters();
            if (characters.length === 0) {
                container.innerHTML = `
                    <div class="text-center" style="padding:40px;">
                        <p style="font-size:48px;margin-bottom:12px;">⚔️</p>
                        <p>まだキャラクターがいません</p>
                        <p style="color:var(--text-secondary);font-size:13px;margin-top:4px;">ガチャを引いてキャラを手に入れよう！</p>
                        <button class="btn btn-primary mt-16" onclick="App.navigate('gacha')">ガチャを引く</button>
                    </div>
                `;
                return;
            }

            container.innerHTML = `
                <div class="card-title">所持キャラクター (${characters.length}体)</div>
                <div id="char-list"></div>
            `;

            const list = document.getElementById('char-list');
            characters.forEach(char => {
                const rarityName = this.RARITY_NAMES[char.template_rarity] || 'N';
                const rarityClass = Animation.RARITY_CLASS[char.template_rarity] || 'n';
                const icon = this.RARITY_ICONS[char.template_rarity] || '🗡️';
                const raceIcon = this.RACE_ICONS[char.race] || '';
                const raceName = this.RACE_NAMES[char.race] || '';
                const req = this.requiredExp(char.level);
                const expPct = Math.min(100, Math.round(char.exp / req * 100));

                const card = document.createElement('div');
                card.className = 'char-card';
                card.dataset.rarity = char.template_rarity;
                card.innerHTML = `
                    <div class="char-avatar" data-rarity="${char.template_rarity}">${icon}</div>
                    <div class="char-info">
                        <div style="display:flex;align-items:center;gap:6px;">
                            <div class="char-name" style="flex:1;">${char.template_name}</div>
                            <button class="btn-fav${char.is_favorite ? ' is-fav' : ''}" data-id="${char.id}" title="お気に入り">★</button>
                        </div>
                        <div class="char-rarity">
                            <span class="rarity-badge ${rarityClass}">${rarityName}</span>
                            <span class="race-badge">${raceIcon}${raceName}</span>
                            <span class="char-level">Lv.${char.level}</span>
                        </div>
                        <div class="char-stats">
                            <span>HP:${char.hp}</span>
                            <span>ATK:${char.atk}</span>
                            <span>DEF:${char.def_}</span>
                            <span>SPD:${char.spd}</span>
                        </div>
                        <div class="exp-bar-wrap" title="EXP: ${char.exp}/${req}">
                            <div class="exp-bar-fill" style="width:${expPct}%"></div>
                        </div>
                    </div>
                `;
                card.querySelector('.btn-fav').addEventListener('click', async (e) => {
                    e.stopPropagation();
                    const btn = e.currentTarget;
                    try {
                        const res = await API.toggleFavorite(char.id);
                        char.is_favorite = res.is_favorite;
                        btn.classList.toggle('is-fav', res.is_favorite);
                    } catch (err) { alert(err.message); }
                });
                card.addEventListener('click', () => this.showDetail(char));
                list.appendChild(card);
            });
        } catch (e) {
            container.innerHTML = `<div class="text-center" style="padding:40px;color:var(--accent);">エラー: ${e.message}</div>`;
        }
    },

    async showDetail(char) {
        const rarityName = this.RARITY_NAMES[char.template_rarity] || 'N';
        const rarityClass = Animation.RARITY_CLASS[char.template_rarity] || 'n';
        const icon = this.RARITY_ICONS[char.template_rarity] || '🗡️';
        const raceName = this.RACE_NAMES[char.race] || '不明';
        const raceIcon = this.RACE_ICONS[char.race] || '';
        const req = this.requiredExp(char.level);
        const expPct = Math.min(100, Math.round(char.exp / req * 100));

        const overlay = document.createElement('div');
        overlay.className = 'char-detail-overlay';
        overlay.innerHTML = `
            <div class="char-detail" style="max-height:90vh;overflow-y:auto;">
                <div class="text-center">
                    <div class="char-avatar" data-rarity="${char.template_rarity}" style="margin:0 auto 8px;">${icon}</div>
                    <span class="rarity-badge ${rarityClass}" style="font-size:14px;">${rarityName}</span>
                    <span class="race-badge" style="margin-left:6px;">${raceIcon}${raceName}</span>
                    <h3 style="margin-top:8px;">${char.template_name}</h3>
                    <div style="display:flex;align-items:center;justify-content:center;gap:10px;margin-top:4px;">
                        <div style="color:var(--gold);">Lv.${char.level}</div>
                        <button class="btn-fav btn-fav-detail${char.is_favorite ? ' is-fav' : ''}" id="fav-btn-detail" title="お気に入り">★ ${char.is_favorite ? 'お気に入り登録中' : 'お気に入り'}</button>
                    </div>
                    <div class="exp-bar-detail-wrap" style="margin:6px auto 0;max-width:200px;">
                        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-secondary);margin-bottom:3px;">
                            <span>EXP</span><span>${char.exp} / ${req}</span>
                        </div>
                        <div class="exp-bar-wrap"><div class="exp-bar-fill" style="width:${expPct}%"></div></div>
                        <div style="font-size:10px;color:var(--text-secondary);margin-top:2px;text-align:center;">次のレベルまで ${req - char.exp} EXP</div>
                    </div>
                </div>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-label">HP</div>
                        <div class="stat-value">${char.hp}</div>
                        <div class="stat-bonus" id="bonus-hp"></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">ATK</div>
                        <div class="stat-value">${char.atk}</div>
                        <div class="stat-bonus" id="bonus-atk"></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">DEF</div>
                        <div class="stat-value">${char.def_}</div>
                        <div class="stat-bonus" id="bonus-def"></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">SPD</div>
                        <div class="stat-value">${char.spd}</div>
                        <div class="stat-bonus" id="bonus-spd"></div>
                    </div>
                </div>
                <div class="card" style="background:#ffffff;">
                    <div style="font-size:12px;color:#6b8a6e;font-weight:600;">スキル</div>
                    <div style="font-weight:600;color:#2e3d2f;">${char.skill_name || '---'}</div>
                    <div style="font-size:12px;color:#6b8a6e;margin-top:2px;">威力: ${char.skill_power}x</div>
                </div>
                <div class="card" style="background:#ffffff;">
                    <div style="font-size:12px;color:#6b8a6e;font-weight:600;margin-bottom:8px;">装備</div>
                    <div id="equip-slots" style="font-size:12px;color:var(--text-secondary);">読み込み中...</div>
                </div>
                <button class="btn btn-gold mb-8" id="train-btn">⚔️ 特訓する</button>
                <button class="btn btn-secondary" id="close-detail">閉じる</button>
            </div>
        `;

        document.body.appendChild(overlay);

        overlay.querySelector('#close-detail').addEventListener('click', () => overlay.remove());
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) overlay.remove();
        });

        overlay.querySelector('#fav-btn-detail').addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            try {
                const res = await API.toggleFavorite(char.id);
                char.is_favorite = res.is_favorite;
                btn.classList.toggle('is-fav', res.is_favorite);
                btn.textContent = `★ ${res.is_favorite ? 'お気に入り登録中' : 'お気に入り'}`;
            } catch (err) { alert(err.message); }
        });

        overlay.querySelector('#train-btn').addEventListener('click', () => {
            this._showTrainPicker(char, overlay);
        });

        // 装備情報を読み込み
        this._loadEquipment(char, overlay);
    },

    async _showTrainPicker(char, parentOverlay) {
        try {
            const [allChars, activeData] = await Promise.all([
                API.getCharacters(),
                API.getActiveCharacterIds(),
            ]);
            const activeIds = new Set(activeData.character_ids || []);
            // 自分以外のキャラクター
            const soldiers = allChars.filter(c => c.id !== char.id);

            if (soldiers.length === 0) {
                alert('特訓に使えるキャラクターがいません。\n他のキャラクターが必要です。');
                return;
            }

            const req = this.requiredExp(char.level);
            const RARITY_BONUS = { 1: 1, 2: 5, 3: 10, 4: 25, 5: 50 };
            const picker = document.createElement('div');
            picker.className = 'char-detail-overlay';
            picker.style.zIndex = '160';
            picker.innerHTML = `
                <div class="char-detail" style="max-height:85vh;overflow-y:auto;">
                    <div class="card-title">⚔️ 特訓 — 兵士を選択</div>
                    <div class="card" style="background:rgba(255,200,0,0.08);border:1px solid var(--gold);margin-bottom:12px;font-size:12px;">
                        <div style="font-weight:600;margin-bottom:4px;">📋 特訓のルール</div>
                        <ul style="margin:0;padding-left:16px;line-height:1.7;">
                            <li>選んだキャラクターは<b>去ってしまいます</b>（装備は返却）</li>
                            <li>獲得EXP = 兵士の現在EXP（最低1）+ レアリティボーナス</li>
                            <li style="color:var(--text-secondary);">N:+1 / R:+5 / SR:+10 / SSR:+25 / UR:+50</li>
                            <li>${char.template_name} 現在 ${char.exp} / ${req} EXP</li>
                        </ul>
                    </div>
                    <div id="train-picker-list"></div>
                    <button class="btn btn-secondary mt-8" id="train-picker-close">キャンセル</button>
                </div>
            `;
            document.body.appendChild(picker);
            picker.querySelector('#train-picker-close').addEventListener('click', () => picker.remove());
            picker.addEventListener('click', (e) => { if (e.target === picker) picker.remove(); });

            const list = picker.querySelector('#train-picker-list');
            soldiers.forEach(soldier => {
                const sIcon = this.RARITY_ICONS[soldier.template_rarity] || '🗡️';
                const sRarityClass = Animation.RARITY_CLASS[soldier.template_rarity] || 'n';
                const sRarityName = this.RARITY_NAMES[soldier.template_rarity] || 'N';
                const sRaceName = this.RACE_NAMES[soldier.race] || '';
                const inTournament = activeIds.has(soldier.id);
                const isFav = soldier.is_favorite;
                const isLocked = inTournament || isFav;

                // 獲得EXP計算
                const bonus = RARITY_BONUS[soldier.template_rarity] || 1;
                const gainExp = Math.max(1, soldier.exp) + bonus;
                const newExp = char.exp + gainExp;
                const willLevelUp = newExp >= req;

                const el = document.createElement('div');
                el.className = 'char-card';
                if (isLocked) {
                    el.style.cssText = 'opacity:0.45;cursor:not-allowed;';
                } else {
                    el.style.cursor = 'pointer';
                }

                let statusLabel = '去る';
                let statusColor = 'color:var(--accent);';
                if (isFav) { statusLabel = '★お気に入り'; statusColor = 'color:var(--gold);'; }
                else if (inTournament) { statusLabel = '🏆大会中'; statusColor = 'color:var(--text-secondary);'; }

                el.innerHTML = `
                    <div class="char-avatar" data-rarity="${soldier.template_rarity}">${sIcon}</div>
                    <div class="char-info">
                        <div class="char-name">${soldier.template_name}</div>
                        <div class="char-rarity">
                            <span class="rarity-badge ${sRarityClass}">${sRarityName}</span>
                            <span style="font-size:11px;color:var(--text-secondary);margin-left:4px;">${sRaceName}</span>
                            <span class="char-level">Lv.${soldier.level}</span>
                        </div>
                        <div class="char-stats" style="font-size:11px;">
                            <span>HP:${soldier.hp}</span>
                            <span>ATK:${soldier.atk}</span>
                            <span>DEF:${soldier.def_}</span>
                            <span>SPD:${soldier.spd}</span>
                        </div>
                        ${!isLocked ? `
                        <div style="font-size:11px;margin-top:3px;">
                            <span style="color:var(--gold);font-weight:600;">EXP +${gainExp}</span>
                            <span style="color:var(--text-secondary);margin-left:4px;">(EXP ${Math.max(1, soldier.exp)} + ボーナス ${bonus})</span>
                            ${willLevelUp ? `<span style="color:var(--gold);margin-left:4px;">⬆️Lv UP!</span>` : ''}
                        </div>` : ''}
                    </div>
                    <div style="font-size:11px;text-align:right;padding-right:4px;${statusColor}">
                        ${statusLabel}
                    </div>
                `;
                if (!isLocked) {
                    el.addEventListener('click', async () => {
                        if (!await UI.confirm(`${soldier.template_name} を特訓に使いますか？\nこのキャラクターは去ってしまいます。`)) return;
                        try {
                            el.style.opacity = '0.5';
                            const result = await API.trainCharacter(char.id, soldier.id);
                            picker.remove();
                            parentOverlay.remove();

                            // 完了メッセージ
                            let msg = `特訓完了！\nEXP +${result.exp_gained}`;
                            if (result.returned_items && result.returned_items.length > 0) {
                                msg += `\n装備を回収: ${result.returned_items.join(', ')}`;
                            }
                            if (result.leveled_up) {
                                msg += `\n\n⬆️ レベルアップ！\nLv.${result.old_level} → Lv.${result.new_level}`;
                            }

                            // 最新データでキャラ詳細を再表示（詳細画面を閉じない）
                            try {
                                const updatedChar = await API.getCharacter(char.id);
                                CharacterPage.showDetail(updatedChar);
                            } catch (_) { /* キャラ取得失敗時は詳細は開かない */ }

                            alert(msg);

                            // バックグラウンドでリスト更新
                            CharacterPage.render(document.getElementById('main-content'));
                        } catch (e) {
                            el.style.opacity = '1';
                            alert(e.message);
                        }
                    });
                }
                list.appendChild(el);
            });
        } catch (e) {
            alert(e.message);
        }
    },

    // 装備タイプ → 割り当て候補スロット（優先順）
    EQUIP_SLOT_CANDIDATES: {
        weapon_1h: ['weapon1', 'weapon2'],
        weapon_2h: ['weapon1'],
        shield:    ['weapon2'],
        head:      ['head'],
        body:      ['body'],
        hands:     ['hands'],
        feet:      ['feet'],
        accessory: ['accessory1', 'accessory2', 'accessory3'],
    },

    _findAutoSlot(equipSlot, slotMap) {
        const candidates = this.EQUIP_SLOT_CANDIDATES[equipSlot] || [];
        // 両手武器は weapon1 固定（APIが weapon1+weapon2 を処理する）
        if (equipSlot === 'weapon_2h') return 'weapon1';
        // 空きスロットを優先
        for (const slot of candidates) {
            if (!slotMap[slot]) return slot;
        }
        // 空きがなければ最初の候補（上書き）
        return candidates[0] || null;
    },

    async _loadEquipment(char, overlay) {
        const slotsEl = overlay.querySelector('#equip-slots');
        try {
            const equip = await API.getEquipment(char.id);
            const slotMap = {};
            equip.slots.forEach(s => slotMap[s.slot] = s);

            // ボーナス表示
            if (equip.total_bonus_hp > 0) overlay.querySelector('#bonus-hp').textContent = `+${equip.total_bonus_hp}`;
            if (equip.total_bonus_atk > 0) overlay.querySelector('#bonus-atk').textContent = `+${equip.total_bonus_atk}`;
            if (equip.total_bonus_def > 0) overlay.querySelector('#bonus-def').textContent = `+${equip.total_bonus_def}`;
            if (equip.total_bonus_spd > 0) overlay.querySelector('#bonus-spd').textContent = `+${equip.total_bonus_spd}`;

            let html = '';
            const allSlots = ['weapon1', 'weapon2', 'head', 'body', 'hands', 'feet', 'accessory1', 'accessory2', 'accessory3'];
            allSlots.forEach(slot => {
                const eq = slotMap[slot];
                const slotName = this.SLOT_NAMES[slot];
                if (eq) {
                    const rarityClass = Animation.RARITY_CLASS[eq.rarity] || 'n';
                    html += `
                        <div class="equip-slot filled" data-slot="${slot}">
                            <span class="equip-slot-name">${slotName}</span>
                            <span class="equip-item-name rarity-${rarityClass}">${eq.name}</span>
                            <button class="btn-unequip" data-slot="${slot}">外す</button>
                        </div>
                    `;
                } else {
                    html += `
                        <div class="equip-slot empty" data-slot="${slot}">
                            <span class="equip-slot-name">${slotName}</span>
                            <span class="equip-empty">--- 空き ---</span>
                        </div>
                    `;
                }
            });
            html += `<button class="btn btn-primary btn-sm mt-8" id="equip-open-btn" style="width:100%;">装備する</button>`;
            slotsEl.innerHTML = html;

            // 装備するボタン
            slotsEl.querySelector('#equip-open-btn')?.addEventListener('click', () => {
                this._showEquipPicker(char, slotMap, overlay);
            });
            // 外すボタン
            slotsEl.querySelectorAll('.btn-unequip').forEach(btn => {
                btn.addEventListener('click', async () => {
                    try {
                        await API.unequip(char.id, btn.dataset.slot);
                        this._loadEquipment(char, overlay);
                    } catch (e) { alert(e.message); }
                });
            });
        } catch (e) {
            slotsEl.textContent = '装備情報の読み込みに失敗';
        }
    },

    async _showEquipPicker(char, slotMap, parentOverlay) {
        try {
            const items = await API.getItems();
            const equipItems = items.filter(i => i.item_type === 'equipment');

            if (equipItems.length === 0) {
                alert('装備できるアイテムがありません');
                return;
            }

            const picker = document.createElement('div');
            picker.className = 'char-detail-overlay';
            picker.style.zIndex = '160';
            picker.innerHTML = `
                <div class="char-detail" style="max-height:85vh;overflow-y:auto;">
                    <div class="card-title">装備アイテム一覧</div>
                    <p style="font-size:11px;color:var(--text-secondary);margin-bottom:8px;">
                        ${this.RACE_ICONS[char.race] || ''}${this.RACE_NAMES[char.race] || ''}のキャラクターに装備できるアイテムを表示中
                    </p>
                    <div id="equip-picker-list"></div>
                    <button class="btn btn-secondary mt-8" id="picker-close">戻る</button>
                </div>
            `;
            document.body.appendChild(picker);

            picker.querySelector('#picker-close').addEventListener('click', () => picker.remove());
            picker.addEventListener('click', (e) => { if (e.target === picker) picker.remove(); });

            const list = picker.querySelector('#equip-picker-list');
            equipItems.forEach(item => {
                const rarityName = this.RARITY_NAMES[item.rarity] || 'N';
                const rarityClass = Animation.RARITY_CLASS[item.rarity] || 'n';
                const slotLabel = this.EQUIP_SLOT_LABELS[item.equip_slot] || item.equip_slot;
                const raceLabel = this.RACE_LABELS[item.equip_race] || '';
                const raceOk = item.equip_race === 'all' || item.equip_race === char.race;
                const targetSlot = raceOk ? this._findAutoSlot(item.equip_slot, slotMap) : null;
                const canEquip = raceOk && targetSlot;

                const bonuses = [];
                if (item.bonus_hp > 0) bonuses.push(`HP+${item.bonus_hp}`);
                if (item.bonus_atk > 0) bonuses.push(`ATK+${item.bonus_atk}`);
                if (item.bonus_def > 0) bonuses.push(`DEF+${item.bonus_def}`);
                if (item.bonus_spd > 0) bonuses.push(`SPD+${item.bonus_spd}`);
                if (item.effect_name) bonuses.push(`${item.effect_name}: ${item.effect_value}`);

                const el = document.createElement('div');
                el.className = 'item-card';
                el.style.cursor = canEquip ? 'pointer' : 'not-allowed';
                el.style.opacity = canEquip ? '1' : '0.45';
                el.innerHTML = `
                    <div class="item-icon" data-rarity="${item.rarity}">🗡️</div>
                    <div class="item-info" style="flex:1;">
                        <div class="item-name">${item.name}</div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:2px;">
                            <span class="rarity-badge ${rarityClass}">${rarityName}</span>
                            <span style="font-size:10px;color:var(--text-secondary);">x${item.quantity}</span>
                            ${raceLabel ? `<span style="font-size:10px;padding:1px 5px;border-radius:4px;background:${raceOk ? 'rgba(102,187,106,0.15)' : 'rgba(239,83,80,0.15)'};color:${raceOk ? 'var(--success)' : '#ef5350'};">${raceOk ? '' : '⛔'}${raceLabel}</span>` : `<span style="font-size:10px;padding:1px 5px;border-radius:4px;background:rgba(102,187,106,0.15);color:var(--success);">全種族OK</span>`}
                        </div>
                        <div style="font-size:10px;color:var(--text-secondary);margin-top:2px;">📍 ${slotLabel}${canEquip ? ` → ${this.SLOT_NAMES[targetSlot]}` : ''}</div>
                        ${bonuses.length ? `<div style="font-size:11px;color:var(--gold);margin-top:2px;">${bonuses.join(' / ')}</div>` : ''}
                    </div>
                `;
                if (canEquip) {
                    el.addEventListener('click', async () => {
                        try {
                            await API.equip(char.id, targetSlot, item.item_template_id);
                            picker.remove();
                            this._loadEquipment(char, parentOverlay);
                        } catch (e) {
                            alert(e.message);
                        }
                    });
                }
                list.appendChild(el);
            });
        } catch (e) {
            alert(e.message);
        }
    },
};
