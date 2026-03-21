/* === ポケモンクエスト フロントエンド JavaScript === */

const PQApp = {
  // ポケモン一覧
  async loadPokemon(containerId) {
    const container = document.getElementById(containerId);
    container.innerHTML = '<div class="loading">読み込み中</div>';

    try {
      const pokemon = await API.get('/api/pokemon.php');
      this.allPokemon = pokemon;
      this.renderPokemon(pokemon, container);
      this.updateCount(pokemon.length);
    } catch (e) {
      container.innerHTML = '<p>データの読み込みに失敗しました。</p>';
    }
  },

  renderPokemon(list, container) {
    if (list.length === 0) {
      container.innerHTML = '<p style="color:var(--text-secondary);padding:20px;">該当するポケモンが見つかりません。</p>';
      return;
    }

    container.innerHTML = list.map(p => {
      // レシピアイコン
      let recipeIconsHtml = '';
      if (p.recipes && p.recipes.length > 0) {
        const icons = p.recipes.map(r => {
          const img = r.image_path
            ? `<img src="/images/recipes/${escapeHtml(r.image_path)}" alt="${escapeHtml(r.name)}" title="${escapeHtml(r.name)}" style="width:28px;height:28px;object-fit:contain;border-radius:4px;">`
            : `<span style="font-size:1.1rem;" title="${escapeHtml(r.name)}">🍲</span>`;
          return `<a href="/recipe-detail.html?no=${r.recipe_no}" onclick="event.stopPropagation()" style="display:inline-flex;">${img}</a>`;
        }).join('');
        const evoNote = p.recipe_from_evolution
          ? `<div style="margin-bottom:4px;"><span style="display:inline-flex;align-items:center;gap:3px;font-size:0.72rem;font-weight:600;background:rgba(142,68,173,0.1);color:#8e44ad;border:1px solid rgba(142,68,173,0.3);border-radius:10px;padding:1px 8px;">🔄 ${escapeHtml(p.evolution_from_name)}から進化</span></div>`
          : '';
        recipeIconsHtml = `<div class="pokemon-recipes">${evoNote}<div style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;">${icons}</div></div>`;
      } else if (p.recipe_from_evolution) {
        recipeIconsHtml = `<div class="pokemon-recipes"><span style="display:inline-flex;align-items:center;gap:3px;font-size:0.72rem;font-weight:600;background:rgba(142,68,173,0.1);color:#8e44ad;border:1px solid rgba(142,68,173,0.3);border-radius:10px;padding:1px 8px;">🔄 ${escapeHtml(p.evolution_from_name)}から進化</span></div>`;
      }

      return `
        <div class="card pokemon-card pokemon-card-link" onclick="location.href='/pokemon-detail.html?no=${p.pokedex_no}'">
          <div class="pokemon-no">No.${String(p.pokedex_no).padStart(3, '0')}</div>
          <div class="pokemon-name">${escapeHtml(p.name)}</div>
          <div class="pokemon-types">
            <span class="type-badge type-${escapeHtml(p.type1)}">${escapeHtml(p.type1)}</span>
            ${p.type2 ? `<span class="type-badge type-${escapeHtml(p.type2)}">${escapeHtml(p.type2)}</span>` : ''}
          </div>
          <div class="pokemon-stats">
            HP: ${p.base_hp || '?'} / ATK: ${p.base_atk || '?'}
            ${p.ranged !== null && p.ranged !== undefined
              ? ` <span style="font-size:0.78rem;color:var(--text-secondary);">${p.ranged ? '🏹 遠距離' : '⚔️ 近距離'}</span>`
              : ''}</div>
          ${recipeIconsHtml}
        </div>
      `;
    }).join('');
  },

  searchPokemon(query, container) {
    if (!this.allPokemon) return;
    const q = query.toLowerCase();
    const filtered = this.allPokemon.filter(p =>
      p.name.toLowerCase().includes(q) ||
      p.type1.toLowerCase().includes(q) ||
      (p.type2 && p.type2.toLowerCase().includes(q)) ||
      String(p.pokedex_no).includes(q)
    );
    this.renderPokemon(filtered, container);
    this.updateCount(filtered.length);
  },

  filterByType(types, container) {
    if (!this.allPokemon) return;
    const typeArr = Array.isArray(types) ? types : (types ? [types] : []);
    if (typeArr.length === 0) {
      this.renderPokemon(this.allPokemon, container);
      this.updateCount(this.allPokemon.length);
      return;
    }
    const filtered = this.allPokemon.filter(p =>
      typeArr.every(t => p.type1 === t || p.type2 === t)
    );
    this.renderPokemon(filtered, container);
    this.updateCount(filtered.length);
  },

  // 料理一覧 (グループ化表示)
  async loadRecipes(containerId) {
    const container = document.getElementById(containerId);
    container.innerHTML = '<div class="loading">読み込み中</div>';

    try {
      const groups = await API.get('/api/recipes.php', { grouped: '1' });
      // にじのマテリアル(id=7)を含むレシピを末尾に
      groups.sort((a, b) => {
        const aHasNiji = a.ingredients.some(i => i.id === 7) ? 1 : 0;
        const bHasNiji = b.ingredients.some(i => i.id === 7) ? 1 : 0;
        return aHasNiji - bHasNiji || a.recipe_no - b.recipe_no;
      });
      this.allRecipeGroups = groups;
      this.renderRecipeGroups(groups, container);
      this.updateCount(groups.length);
    } catch (e) {
      container.innerHTML = '<p>データの読み込みに失敗しました。</p>';
    }
  },

  renderRecipeGroups(groups, container) {
    if (groups.length === 0) {
      container.innerHTML = '<p style="color:var(--text-secondary);padding:20px;">該当する料理が見つかりません。</p>';
      return;
    }

    container.innerHTML = groups.map(g => {
      const imgHtml = g.image_path
        ? `<img src="/images/recipes/${escapeHtml(g.image_path)}" alt="${escapeHtml(g.name)}" style="width:64px;height:64px;object-fit:contain;border-radius:8px;flex-shrink:0;">`
        : `<div style="width:64px;height:64px;background:var(--bg-primary);border-radius:8px;flex-shrink:0;"></div>`;

      return `
        <a href="/recipe-detail.html?no=${g.recipe_no}" style="display:block;text-decoration:none;color:inherit;">
        <div class="recipe-group-card">
          <div style="display:flex;gap:14px;align-items:flex-start;">
            ${imgHtml}
            <div style="flex:1;min-width:0;">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
                <span class="recipe-no">No.${g.recipe_no}</span>
                <h3 style="margin:0;font-size:1.05rem;color:var(--accent);">${escapeHtml(g.name)}</h3>
              </div>
              ${(() => {
                if (!g.ingredients || g.ingredients.length === 0) return '';
                const colorEmoji = { red: '🔴', blue: '🔵', yellow: '🟡', gray: '⚪', rainbow: '🌈' };
                const iconHtml = ing => {
                  const p = ing.image_path ? `/images/ingredients/${escapeHtml(ing.image_path)}` : null;
                  return p
                    ? `<img src="${p}" alt="${escapeHtml(ing.name)}" title="${escapeHtml(ing.name)}" style="width:36px;height:36px;object-fit:contain;border-radius:6px;background:var(--bg-primary);">`
                    : `<span title="${escapeHtml(ing.name)}" style="width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;font-size:1.3rem;border-radius:6px;background:var(--bg-primary);">${colorEmoji[ing.color] || '⚪'}</span>`;
                };
                const hintParts = (g.ingredient_hint || '').split('／');
                const g1 = g.ingredients.filter(i => i.condition_group !== 2);
                const g2 = g.ingredients.filter(i => i.condition_group === 2);
                if (g2.length > 0) {
                  return `<div style="display:flex;flex-direction:column;gap:4px;margin-bottom:8px;">
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                      <span style="font-size:0.72rem;color:var(--text-secondary);white-space:nowrap;min-width:0;">${escapeHtml(hintParts[0] ? hintParts[0].trim() : '')}</span>
                      ${g1.map(iconHtml).join('')}
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                      <span style="font-size:0.72rem;color:var(--text-secondary);white-space:nowrap;min-width:0;">${escapeHtml(hintParts[1] ? hintParts[1].trim() : '')}</span>
                      ${g2.map(iconHtml).join('')}
                    </div>
                  </div>`;
                }
                return `<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">${g.ingredients.map(iconHtml).join('')}</div>`;
              })()}
              <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:0.88rem;">
                <div>
                  <span style="color:var(--accent);font-weight:600;">素材</span>
                  <span style="color:var(--text-secondary);margin-left:6px;">${escapeHtml(g.ingredient_hint || '—')}</span>
                </div>
                <div>
                  <span style="color:var(--accent);font-weight:600;">ポケモン</span>
                  <span style="color:var(--text-secondary);margin-left:6px;">${escapeHtml(g.pokemon_hint || '—')}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
        </a>
      `;
    }).join('');
  },

  searchRecipes(query, container) {
    if (!this.allRecipeGroups) return;
    const q = query.toLowerCase();
    const filtered = this.allRecipeGroups.filter(g =>
      g.name.toLowerCase().includes(q) ||
      (g.ingredient_hint && g.ingredient_hint.toLowerCase().includes(q)) ||
      (g.pokemon_hint    && g.pokemon_hint.toLowerCase().includes(q))
    );
    this.renderRecipeGroups(filtered, container);
    this.updateCount(filtered.length);
  },

  // 料理シミュレーター
  selectedIngredients: [null, null, null, null, null],
  allIngredients: [],
  selectedPot: '鉄',

  selectPot(pot, btn) {
    this.selectedPot = pot;
    document.querySelectorAll('.pot-type-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    // 鍋の色クラスを更新
    const potWrapper = document.querySelector('.pot-wrapper');
    if (potWrapper) {
      potWrapper.classList.remove('pot-iron', 'pot-copper', 'pot-silver', 'pot-gold');
      const potClassMap = { '鉄': 'pot-iron', '銅': 'pot-copper', '銀': 'pot-silver', '金': 'pot-gold' };
      potWrapper.classList.add(potClassMap[pot] || 'pot-gold');
    }
    // 必要素材個数を更新
    const numEl = document.getElementById('pot-lid-num');
    if (numEl) {
      const countMap = { '鉄': 3, '銅': 10, '銀': 15, '金': 20 };
      numEl.textContent = countMap[pot] ?? '';
    }
    // 素材が揃っていたら再計算
    if (this.selectedIngredients.every(s => s !== null)) this.cook();
  },

  async initSimulator() {
    try {
      const data = await API.get('/api/ingredients.php');
      this.allIngredients = data;
    } catch (e) {
      this.allIngredients = this.getDefaultIngredients();
    }
    this.renderIngredientPicker();
  },

  getDefaultIngredients() {
    return [
      { id: 1, name: 'ちいさなキノコ', color: 'red', softness: 'soft', size: 'small', rarity: 'common' },
      { id: 2, name: 'ブリーのみ', color: 'blue', softness: 'soft', size: 'small', rarity: 'common' },
      { id: 3, name: 'ぼんぐり', color: 'yellow', softness: 'hard', size: 'small', rarity: 'common' },
      { id: 4, name: 'カセキ', color: 'gray', softness: 'hard', size: 'big', rarity: 'common' },
      { id: 5, name: 'にじのマテリアル', color: 'rainbow', softness: 'soft', size: 'small', rarity: 'rare' },
      { id: 6, name: 'おおきなねっこ', color: 'red', softness: 'soft', size: 'big', rarity: 'common' },
      { id: 7, name: 'つめたいいわ', color: 'blue', softness: 'hard', size: 'big', rarity: 'common' },
      { id: 8, name: 'あまいミツ', color: 'yellow', softness: 'soft', size: 'big', rarity: 'common' },
      { id: 9, name: 'かおるキノコ', color: 'yellow', softness: 'soft', size: 'small', rarity: 'common' },
      { id: 10, name: 'しんぴの貝殻', color: 'blue', softness: 'hard', size: 'small', rarity: 'rare' }
    ];
  },

  renderIngredientPicker() {
    const picker = document.getElementById('ingredient-picker');
    if (!picker) return;

    const colorEmoji = { red: '&#x1F534;', blue: '&#x1F535;', yellow: '&#x1F7E1;', gray: '&#x26AA;', rainbow: '&#x1F308;' };

    picker.innerHTML = this.allIngredients.map(ing => {
      const imgPath = ing.image_path ? `/images/ingredients/${ing.image_path}` : null;
      return `
        <button class="ingredient-btn" onclick="PQApp.addIngredient(${ing.id})" title="${escapeHtml(ing.name)}">
          ${imgPath
            ? `<img src="${imgPath}" alt="${escapeHtml(ing.name)}" loading="lazy">`
            : `<span style="font-size:2rem;display:block;padding:12px;text-align:center;">${colorEmoji[ing.color] || '&#x26AA;'}</span>`
          }
        </button>
      `;
    }).join('');
  },

  addIngredient(id) {
    const emptyIndex = this.selectedIngredients.indexOf(null);
    if (emptyIndex === -1) return;
    const ing = this.allIngredients.find(i => i.id === id);
    if (!ing) return;

    this.selectedIngredients[emptyIndex] = ing;
    this.renderSlots();
    if (this.selectedIngredients.every(s => s !== null)) this.cook();
  },

  removeIngredient(index) {
    this.selectedIngredients[index] = null;
    this.renderSlots();
    const resultBox = document.getElementById('result-box');
    if (resultBox) resultBox.style.display = 'none';
  },

  renderSlots() {
    const colorEmoji = { red: '&#x1F534;', blue: '&#x1F535;', yellow: '&#x1F7E1;', gray: '&#x26AA;', rainbow: '&#x1F308;' };

    for (let i = 0; i < 5; i++) {
      const slot = document.getElementById(`pot-slot-${i}`);
      if (!slot) continue;
      const ing = this.selectedIngredients[i];
      if (ing) {
        const imgPath = ing.image_path ? `/images/ingredients/${ing.image_path}` : null;
        const idx = i;
        slot.innerHTML = imgPath
          ? `<img src="${imgPath}" alt="${escapeHtml(ing.name)}" title="クリックで外す">`
          : `<span style="font-size:2.5rem;" title="クリックで外す">${colorEmoji[ing.color] || '&#x26AA;'}</span>`;
        slot.onclick = () => PQApp.removeIngredient(idx);
      } else {
        slot.innerHTML = '';
        slot.onclick = null;
      }
    }
  },

  cook() {
    if (!this.selectedIngredients.every(s => s !== null)) return;

    // 素材の特性を集計
    const clr = {}, cat = {}, sft = {};
    let qualityTotal = 0, hasKaigara = false;
    for (const ing of this.selectedIngredients) {
      if (ing.color)    clr[ing.color]     = (clr[ing.color]     || 0) + 1;
      if (ing.category) cat[ing.category]  = (cat[ing.category]  || 0) + 1;
      if (ing.softness) sft[ing.softness]  = (sft[ing.softness]  || 0) + 1;
      qualityTotal += ing.quality_point || 1;
      if (ing.id === 8) hasKaigara = true;
    }
    const red  = clr.red    || 0, blue     = clr.blue    || 0;
    const yel  = clr.yellow || 0, gray     = clr.gray    || 0;
    const mush = cat.mushroom|| 0, plant   = cat.plant   || 0;
    const swt  = cat.sweet  || 0, mineral = cat.mineral  || 0;
    const soft = sft.soft   || 0, hard    = sft.hard     || 0;

    // 品質判定（品質度合計）
    let quality, qualityLabel;
    if      (qualityTotal >= 10) { quality = 'special';   qualityLabel = 'スペシャル'; }
    else if (qualityTotal >= 8)  { quality = 'very_good'; qualityLabel = 'すごくいい'; }
    else if (qualityTotal >= 6)  { quality = 'good';      qualityLabel = 'いい'; }
    else                         { quality = 'normal';    qualityLabel = 'ふつう'; }

    // レシピ判定（優先度順）
    // P1: しんぴのかいがら最優先
    let recipeName;
    if      (hasKaigara)                    recipeName = 'カクコロレジェンドスープ';
    // P2: 単色4枚以上
    else if (red  >= 4)                     recipeName = 'レッドカクコロシチュー';
    else if (blue >= 4)                     recipeName = 'ブルーカクコロジュース';
    else if (yel  >= 4)                     recipeName = 'イエローカクコロカレー';
    else if (gray >= 4)                     recipeName = 'ホワイトカクコログラタン';
    // P3強: 複数特徴＋たっぷり系（条件が厳しい順）
    else if (hard  >= 4 && mineral >= 2)    recipeName = 'カクコロガンセキ煮';
    else if (soft  >= 4 && yel     >= 3)    recipeName = 'カクコロビリビリゾット';
    else if (mush  >= 3 && red     >= 2)    recipeName = 'カクコロファイヤベース';
    else if (plant >= 4 && soft    >= 2)    recipeName = 'カクコロ健康スムージー';
    else if (swt   >= 4 && yel     >= 3)    recipeName = 'カクコロハニーフォンデュ';
    // P3中: 多め＋少々系
    else if (soft  >= 4 && blue    >= 3)    recipeName = 'カクコロウォータカウダ';
    else if (mush  >= 4 && soft    >= 3)    recipeName = 'カクコロヘドロしるこ';
    else if (soft  >= 3 && mineral >= 2)    recipeName = 'カクコロクレイチャウダー';
    else if (mineral >= 3 && plant >= 2)    recipeName = 'カクコロウィンドリア';
    else if (swt   >= 3 && mush    >= 2)    recipeName = 'カクコロマッスルオレ';
    else if (swt   >= 3 && hard    >= 2)    recipeName = 'カクコロねんジャオロース';
    else if (swt   >= 3 && gray    >= 2)    recipeName = 'カクコロシルクレープ';
    // P4: デフォルト
    else                                    recipeName = 'カクコロスープ';

    // 調理時間: 鉄ベース＋鍋オフセット＋品質ボーナス（確認済: スープ=2, レジェンド=4）
    const baseTimes = {
      'カクコロスープ': 2,
      'レッドカクコロシチュー': 3, 'ブルーカクコロジュース': 3,
      'イエローカクコロカレー': 3, 'ホワイトカクコログラタン': 3,
      'カクコロウォータカウダ': 3, 'カクコロシルクレープ': 3,
      'カクコロヘドロしるこ': 3,   'カクコロクレイチャウダー': 3,
      'カクコロ健康スムージー': 3, 'カクコロハニーフォンデュ': 3,
      'カクコロねんジャオロース': 3, 'カクコロガンセキ煮': 3,
      'カクコロウィンドリア': 3,   'カクコロファイヤベース': 3,
      'カクコロビリビリゾット': 3, 'カクコロマッスルオレ': 4,
      'カクコロレジェンドスープ': 4
    };
    const potOffset  = { '鉄': 0, '銅': 0, '銀': 1, '金': 2 };
    const qualBonus  = { normal: 0, good: 2, very_good: 3, special: 4 };
    const potLvRange = { '鉄': 'Lv 1〜19', '銅': 'Lv 21〜40', '銀': 'Lv 41〜70', '金': 'Lv 71〜100' };
    const cookTime   = (baseTimes[recipeName] || 3)
                     + (potOffset[this.selectedPot] ?? 0)
                     + qualBonus[quality];

    // レシピ画像マッピング
    const recipeImgMap = {
      'カクコロスープ': 'recipe_1.png',
      'レッドカクコロシチュー': 'recipe_2.png',
      'ブルーカクコロジュース': 'recipe_3.png',
      'イエローカクコロカレー': 'recipe_4.png',
      'ホワイトカクコログラタン': 'recipe_5.png',
      'カクコロウォータカウダ': 'recipe_6.png',
      'カクコロシルクレープ': 'recipe_7.png',
      'カクコロヘドロしるこ': 'recipe_8.png',
      'カクコロクレイチャウダー': 'recipe_9.png',
      'カクコロ健康スムージー': 'recipe_10.png',
      'カクコロハニーフォンデュ': 'recipe_11.png',
      'カクコロねんジャオロース': 'recipe_12.png',
      'カクコロガンセキ煮': 'recipe_13.png',
      'カクコロウィンドリア': 'recipe_14.png',
      'カクコロファイヤベース': 'recipe_15.png',
      'カクコロビリビリゾット': 'recipe_16.png',
      'カクコロマッスルオレ': 'recipe_17.png',
      'カクコロレジェンドスープ': 'recipe_18.png'
    };
    const imgFile = recipeImgMap[recipeName];
    const recipeImgHtml = imgFile
      ? `<img src="/images/recipes/${imgFile}" alt="${escapeHtml(recipeName)}" style="width:64px;height:64px;object-fit:contain;border-radius:8px;flex-shrink:0;">`
      : '';

    // 結果表示
    const resultBox = document.getElementById('result-box');
    resultBox.innerHTML = `
      <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;justify-content:center;">
        ${recipeImgHtml}
        <div style="text-align:left;">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:4px;">
            <h3 style="margin:0;">${escapeHtml(recipeName)}</h3>
            <span class="quality-badge quality-${quality}">${qualityLabel}</span>
          </div>
          <div style="font-size:0.9rem;color:var(--text-secondary);">
            ${escapeHtml(this.selectedPot)}の鍋（${potLvRange[this.selectedPot]}） ／ &#x23F1; 冒険 ${cookTime} 回分
          </div>
        </div>
      </div>
    `;
    resultBox.style.display = 'block';
  },

  resetSimulator() {
    this.selectedIngredients = [null, null, null, null, null];
    this.renderSlots();
    const resultBox = document.getElementById('result-box');
    if (resultBox) resultBox.style.display = 'none';
  },

  updateCount(count) {
    const el = document.getElementById('result-count');
    if (el) el.innerHTML = `<strong>${count}</strong> 件`;
  }
};
