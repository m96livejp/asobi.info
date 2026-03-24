/* === ポケモンクエスト フロントエンド JavaScript === */

const PQApp = {
  // 図鑑登録
  currentUser: null,
  registeredSet: new Set(),
  pokedexTotal: 151,

  async checkAuth() {
    try {
      const res = await fetch('https://asobi.info/assets/php/me.php', { credentials: 'include' });
      const data = await res.json();
      this.currentUser = data.loggedIn ? data : null;
    } catch { this.currentUser = null; }
  },

  async loadPokedex() {
    if (!this.currentUser) return;
    try {
      const data = await API.get('/api/pokedex.php');
      this.registeredSet = new Set(data.registered);
      this.pokedexTotal = data.total;
      this.updatePokedexCount();
    } catch { /* ignore */ }
  },

  async togglePokedex(no, event) {
    event.stopPropagation();
    if (!this.currentUser) return;
    try {
      const res = await fetch('/api/pokedex.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ pokedex_no: no })
      });
      const json = await res.json();
      if (json.registered) { this.registeredSet.add(no); }
      else { this.registeredSet.delete(no); }
      this.updatePokedexCount();
      // フィルターが active なら即時リスト更新
      if (this.pokedexFilter) {
        this.applyAllFilters();
      } else {
        const cb = document.querySelector(`.pokedex-cb[data-no="${no}"]`);
        if (cb) cb.checked = json.registered;
      }
    } catch { /* ignore */ }
  },

  pokedexFilter: null, // null=両方, 'registered', 'unregistered'

  updatePokedexCount() {
    const allEl = document.getElementById('pokedex-all-count');
    const regEl = document.getElementById('pokedex-reg-count');
    const unregEl = document.getElementById('pokedex-unreg-count');
    if (allEl) allEl.textContent = this.pokedexTotal;
    if (regEl) regEl.textContent = this.registeredSet.size;
    if (unregEl) unregEl.textContent = this.pokedexTotal - this.registeredSet.size;
    const bar = document.getElementById('pokedex-count-bar');
    if (bar) bar.style.display = '';
  },

  setPokedexFilter(mode) {
    this.pokedexFilter = mode;
    const allBtn = document.getElementById('pokedex-btn-all');
    const regBtn = document.getElementById('pokedex-btn-registered');
    const unregBtn = document.getElementById('pokedex-btn-unregistered');
    if (allBtn) allBtn.classList.toggle('active', mode === null);
    if (regBtn) regBtn.classList.toggle('active', mode === 'registered');
    if (unregBtn) unregBtn.classList.toggle('active', mode === 'unregistered');
    this.applyAllFilters();
  },

  applyAllFilters() {
    if (!this.allPokemon) return;
    const container = document.getElementById('pokemon-list');
    let list = this.allPokemon;

    // 検索フィルター
    const searchEl = document.getElementById('search-input');
    if (searchEl && searchEl.value) {
      const q = searchEl.value.toLowerCase();
      list = list.filter(p =>
        p.name.toLowerCase().includes(q) ||
        p.type1.toLowerCase().includes(q) ||
        (p.type2 && p.type2.toLowerCase().includes(q)) ||
        String(p.pokedex_no).includes(q)
      );
    }

    // 図鑑フィルター
    if (this.pokedexFilter === 'registered') {
      list = list.filter(p => this.registeredSet.has(p.pokedex_no));
    } else if (this.pokedexFilter === 'unregistered') {
      list = list.filter(p => !this.registeredSet.has(p.pokedex_no));
    }

    this.renderPokemon(list, container);
    this.updateCount(list.length);
  },

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

      const pokedexCb = this.currentUser
        ? `<label class="pokedex-check" onclick="event.stopPropagation()" title="図鑑登録">
             <input type="checkbox" class="pokedex-cb" data-no="${p.pokedex_no}"
                    ${this.registeredSet.has(p.pokedex_no) ? 'checked' : ''}
                    onchange="PQApp.togglePokedex(${p.pokedex_no}, event)">
             <span style="font-size:0.65rem;color:var(--text-secondary);">図鑑登録済み</span>
           </label>`
        : '';

      const pokeUrl = '/pokemon-detail.html?no=' + p.pokedex_no;
      return `
        <div class="card pokemon-card">
          ${pokedexCb}
          <a href="${pokeUrl}" style="text-decoration:none;"><img src="/images/pokemon/${String(p.pokedex_no).padStart(3, '0')}.png" alt="${escapeHtml(p.name)}" class="pokemon-icon"></a>
          <div class="pokemon-card-body">
            <div class="pokemon-no">No.${String(p.pokedex_no).padStart(3, '0')}</div>
            <a href="${pokeUrl}" class="pokemon-name" style="text-decoration:none;color:inherit;display:block;">${escapeHtml(p.name)}</a>
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
                const addCount = (h) => {
                  const t = h.trim();
                  if (t.includes('しんぴてき') && t.includes('たっぷり')) return t + '（1個以上）';
                  if (t.includes('たっぷり')) return t + '（4個以上）';
                  if (t.includes('多め')) return t + '（3個以上）';
                  if (t.includes('少々')) return t + '（1〜2個）';
                  return t;
                };
                // 条件テキストから属性を判定して該当素材をフィルター
                const matchHint = (hint, ing) => {
                  if (!hint) return false;
                  const h = hint.trim();
                  if (h.includes('赤') && ing.color === 'red') return true;
                  if (h.includes('青') && ing.color === 'blue') return true;
                  if (h.includes('黄') && ing.color === 'yellow') return true;
                  if (h.includes('灰') && ing.color === 'gray') return true;
                  if (h.includes('やわらかい') && ing.softness === 'soft') return true;
                  if (h.includes('かたい') && ing.softness === 'hard') return true;
                  if (h.includes('ちいさい') && ing.size === 'small') return true;
                  if (h.includes('おおきい') && ing.size === 'big') return true;
                  if (h.includes('あまい') && ing.category === 'sweet') return true;
                  if (h.includes('きのこ') && ing.category === 'mushroom') return true;
                  if (h.includes('植物') && ing.category === 'plant') return true;
                  if (h.includes('鉱物') && ing.category === 'mineral') return true;
                  if (h.includes('しんぴてき') && ing.category === 'special') return true;
                  return false;
                };
                const uniqueIngs = g.ingredients.filter((v, i, a) => a.findIndex(x => x.id === v.id) === i);
                if (hintParts.length >= 2) {
                  const g1 = uniqueIngs.filter(i => matchHint(hintParts[0], i));
                  const g2 = uniqueIngs.filter(i => matchHint(hintParts[1], i));
                  return `<div style="display:flex;flex-direction:column;gap:4px;margin-bottom:8px;">
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                      <span style="font-size:0.72rem;color:var(--text-secondary);white-space:nowrap;min-width:0;">${escapeHtml(addCount(hintParts[0]))}</span>
                      ${g1.map(iconHtml).join('')}
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                      <span style="font-size:0.72rem;color:var(--text-secondary);white-space:nowrap;min-width:0;">${escapeHtml(addCount(hintParts[1]))}</span>
                      ${g2.map(iconHtml).join('')}
                    </div>
                  </div>`;
                }
                return `<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:8px;">
                  <span style="font-size:0.72rem;color:var(--text-secondary);white-space:nowrap;min-width:0;">${escapeHtml(addCount(g.ingredient_hint || ''))}</span>
                  ${uniqueIngs.map(iconHtml).join('')}
                </div>`;
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
  kaigaraOverride: false,

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
    }).join('') + `
      <button class="reset-btn" onclick="PQApp.resetSimulator()" title="すべてリセット">
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
          <path d="M3 3v5h5"/>
        </svg>
      </button>
    `;
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
    if (this.kaigaraOverride) hasKaigara = true;
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

  setKaigara(checked) {
    this.kaigaraOverride = checked;
    if (this.selectedIngredients.every(s => s !== null)) this.cook();
  },

  resetSimulator() {
    this.selectedIngredients = [null, null, null, null, null];
    this.kaigaraOverride = false;
    const cb = document.getElementById('kaigara-cb');
    if (cb) cb.checked = false;
    this.renderSlots();
    const resultBox = document.getElementById('result-box');
    if (resultBox) resultBox.style.display = 'none';
  },

  updateCount(count) {
    const el = document.getElementById('result-count');
    if (el) el.innerHTML = `<strong>${count}</strong> 件`;
  }
};
