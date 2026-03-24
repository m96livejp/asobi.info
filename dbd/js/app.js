/* === DbD フロントエンド JavaScript === */

const DbDApp = {
  // パーク一覧を取得して表示
  async loadPerks(role, containerId) {
    const container = document.getElementById(containerId);
    container.innerHTML = '<div class="loading">読み込み中</div>';

    try {
      const [perks, chars] = await Promise.all([
        API.get('/api/perks.php', { role }),
        API.get('/api/characters.php', { role })
      ]);
      this.allPerks = perks;
      // キャラクター名 → image_path のマップを構築
      this.charImages = {};
      chars.forEach(c => { if (c.character_name) this.charImages[c.character_name] = c.image_path; });
      this.renderPerks(perks, container);
      this.updateCount(perks.length);
    } catch (e) {
      container.innerHTML = '<p>データの読み込みに失敗しました。</p>';
    }
  },

  renderPerks(perks, container) {
    if (perks.length === 0) {
      container.innerHTML = '<p style="color:var(--text-secondary);padding:20px;">該当するパークが見つかりません。</p>';
      return;
    }

    // キャラクター毎にグループ化
    const groups = {};
    perks.forEach(p => {
      const char = p.character_name || '共通';
      if (!groups[char]) groups[char] = [];
      groups[char].push(p);
    });

    const charImages = this.charImages || {};

    container.innerHTML = Object.entries(groups).map(([charName, charPerks]) => {
      const imgPath = charImages[charName];
      const charIcon = imgPath
        ? `<img src="${escapeHtml(imgPath)}" alt="${escapeHtml(charName)}" style="width:110px;height:134px;border-radius:8px;object-fit:cover;object-position:center top;flex-shrink:0;" loading="lazy">`
        : `<div style="width:110px;height:134px;border-radius:8px;border:2px solid var(--border);background:var(--bg-secondary);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:2rem;">${this.getPerkIcon(charPerks[0].role)}</div>`;
      return `
      <div class="character-perk-group" style="display:flex;gap:0;align-items:stretch;">
        <div style="display:flex;align-items:center;padding:12px;background:var(--bg-secondary);border-right:1px solid var(--border);">
          ${charIcon}
        </div>
        <div style="flex:1;min-width:0;">
          <div class="character-perk-header" style="padding:10px 16px;">
            <h3>${escapeHtml(charName)}</h3>
          </div>
          <div class="character-perk-list">
            ${charPerks.map(p => `
              <div class="perk-item">
                <div style="display:flex;gap:10px;align-items:flex-start;">
                  ${p.image_path ? `<img src="${escapeHtml(p.image_path)}" alt="${escapeHtml(p.name)}" style="width:48px;height:48px;border-radius:6px;object-fit:contain;flex-shrink:0;" loading="lazy">` : ''}
                  <div style="flex:1;min-width:0;">
                    <div class="perk-item-name">${escapeHtml(p.name)}</div>
                    ${p.name_en ? `<div class="perk-item-en">${escapeHtml(p.name_en)}</div>` : ''}
                    <div class="perk-item-desc">${escapeHtml(p.description || '')}</div>
                  </div>
                </div>
              </div>
            `).join('')}
            ${charPerks.length < 3 ? '<div class="perk-item perk-item-empty"></div>'.repeat(3 - charPerks.length) : ''}
          </div>
        </div>
      </div>`;
    }).join('');
  },

  getPerkIcon(role) {
    return role === 'killer' ? '&#x1F5E1;' : '&#x1F3C3;';
  },

  // アイテム一覧を取得して表示
  async loadItems(containerId) {
    const container = document.getElementById(containerId);
    container.innerHTML = '<div class="loading">読み込み中</div>';
    try {
      const items = await API.get('/api/items.php');
      this.allItems = items;
      this.renderItems(items, container);
      this.updateCount(items.length);
    } catch (e) {
      container.innerHTML = '<p>データの読み込みに失敗しました。</p>';
    }
  },

  renderItems(items, container) {
    if (items.length === 0) {
      container.innerHTML = '<p style="color:var(--text-secondary);padding:20px;">アイテムが登録されていません。</p>';
      return;
    }

    const typeLabels = {
      first_aid_kit: '救急キット', toolbox: '工具箱', flashlight: '懐中電灯',
      map: '地図', key: '鍵', other: 'その他'
    };

    // タイプ毎にグループ化
    const groups = {};
    items.forEach(item => {
      const t = item.type || 'other';
      if (!groups[t]) groups[t] = [];
      groups[t].push(item);
    });

    container.innerHTML = Object.entries(groups).map(([type, typeItems]) => `
      <div class="offering-group">
        <div class="offering-group-header">
          <h3>${typeLabels[type] || type}</h3>
          <span class="offering-group-count">${typeItems.length}件</span>
        </div>
        <div class="offering-items">
          ${typeItems.map(item => `
            <div class="offering-item">
              <div class="offering-item-header">
                ${item.image_path ? `<img src="${escapeHtml(item.image_path)}" alt="${escapeHtml(item.name)}" style="width:40px;height:40px;border-radius:4px;object-fit:contain;flex-shrink:0;" loading="lazy">` : ''}
                <span class="offering-item-name">${escapeHtml(item.name)}</span>
                <span class="rarity-badge rarity-${item.rarity}">${this.rarityLabels[item.rarity] || item.rarity}</span>
              </div>
              <div class="offering-item-desc">${escapeHtml(item.description || '')}</div>
            </div>
          `).join('')}
        </div>
      </div>
    `).join('');
  },

  searchItems(query, container) {
    if (!this.allItems) return;
    const q = query.toLowerCase();
    const filtered = this.allItems.filter(item =>
      item.name.toLowerCase().includes(q) ||
      (item.name_en && item.name_en.toLowerCase().includes(q)) ||
      (item.description && item.description.toLowerCase().includes(q))
    );
    this.renderItems(filtered, container);
    this.updateCount(filtered.length);
  },

  // パーク検索
  searchPerks(query, container) {
    if (!this.allPerks) return;
    const q = query.toLowerCase();
    const filtered = this.allPerks.filter(p =>
      p.name.toLowerCase().includes(q) ||
      (p.name_en && p.name_en.toLowerCase().includes(q)) ||
      (p.description && p.description.toLowerCase().includes(q)) ||
      (p.character_name || '共通').toLowerCase().includes(q)
    );
    this.renderPerks(filtered, container);
    this.updateCount(filtered.length);
  },

  // キャラクターフィルター
  filterByCharacter(character, container) {
    if (!this.allPerks) return;
    if (!character) {
      this.renderPerks(this.allPerks, container);
      this.updateCount(this.allPerks.length);
      return;
    }
    const filtered = this.allPerks.filter(p => p.character_name === character);
    this.renderPerks(filtered, container);
    this.updateCount(filtered.length);
  },

  updateCount(count) {
    const el = document.getElementById('result-count');
    if (el) el.innerHTML = `<strong>${count}</strong> 件`;
  },

  // キラー一覧
  async loadKillers(containerId) {
    const container = document.getElementById(containerId);
    container.innerHTML = '<div class="loading">読み込み中</div>';

    try {
      const killers = await API.get('/api/killers.php');
      this.allKillers = killers;
      return killers;
    } catch (e) {
      container.innerHTML = '<p>データの読み込みに失敗しました。</p>';
      return [];
    }
  },

  renderKillerAbilities(killers, container) {
    container.innerHTML = `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;">` +
    killers.map(k => `
      <div class="card">
        <div style="display:flex;gap:12px;align-items:flex-start;">
          ${k.image_path ? `<img src="${escapeHtml(k.image_path)}" alt="${escapeHtml(k.name)}" style="width:110px;height:134px;border-radius:8px;object-fit:cover;object-position:center top;flex-shrink:0;" loading="lazy">` : ''}
          <div style="flex:1;min-width:0;">
            <h3>${escapeHtml(k.name)} <span class="subtitle">${escapeHtml(k.name_en)}</span></h3>
            <div style="margin-top:8px;">
              <strong style="color:var(--accent);">${escapeHtml(k.power_name || '不明')}</strong>
            </div>
            <div class="description" style="margin-top:8px;">
              ${escapeHtml(k.power_description || '情報なし')}
            </div>
            <div style="margin-top:12px; display:flex; gap:16px; flex-wrap:wrap;">
              <span style="font-size:0.85rem; color:var(--text-secondary);">移動速度: <strong style="color:var(--text-primary);">${k.speed} m/s</strong></span>
              <span style="font-size:0.85rem; color:var(--text-secondary);">心音範囲: <strong style="color:var(--text-primary);">${k.terror_radius}m</strong></span>
              <span style="font-size:0.85rem; color:var(--text-secondary);">身長: <strong style="color:var(--text-primary);">${escapeHtml(k.height || '普通')}</strong></span>
            </div>
          </div>
        </div>
      </div>
    `).join('') + `</div>`;
  },

  renderKillerSpeed(killers, container, activeGroups) {
    const BASE_SPEED = 4.0;
    const SPEED_MAX = 8.0;
    const basePos = BASE_SPEED / SPEED_MAX * 100;
    const filter = activeGroups || new Set(['survivor', 'ultra_slow', 'slow', 'normal']);

    const makeBar = (speed) => {
      const pct = (speed / SPEED_MAX * 100).toFixed(2);
      const gradient = 'linear-gradient(to right, #1a4a7a, #3498db, #7ecef4)';
      return `
        <div style="position:relative;height:10px;background:var(--bg-secondary);border-radius:5px;">
          <div style="position:absolute;top:0;bottom:0;left:0;width:${pct}%;background:${gradient};border-radius:5px;"></div>
          <div style="position:absolute;top:-3px;bottom:-3px;left:${basePos}%;width:1px;background:rgba(255,255,255,0.4);border-radius:1px;z-index:2;"></div>
        </div>
        <div style="font-size:0.65rem;color:var(--text-secondary);margin-top:3px;display:flex;justify-content:space-between;">
          <span>0</span><span>4.0</span><span>8</span>
        </div>`;
    };

    // キラーをカテゴリに分類
    const cats = { ultra_slow: [], slow: [], normal: [] };
    killers.forEach(k => {
      if (k.speed >= 4.6) cats.normal.push(k.name);
      else if (k.speed >= 4.4) cats.slow.push(k.name);
      else cats.ultra_slow.push(k.name);
    });

    const categoryRows = [
      {
        group: 'survivor',
        label: 'サバイバー', label_en: 'Survivor (基準)',
        speed: 4.0, color: '#2ecc71', bgColor: 'rgba(46,204,113,0.05)',
        names: ['サバイバー'],
      },
      {
        group: 'ultra_slow',
        label: '超低速キラー', label_en: 'Ultra Slow',
        speed: 3.85, color: '#3498db', bgColor: 'rgba(52,152,219,0.05)',
        names: cats.ultra_slow,
      },
      {
        group: 'slow',
        label: '低速キラー', label_en: 'Slow',
        speed: 4.4, color: '#f1c40f', bgColor: 'rgba(241,196,15,0.05)',
        names: cats.slow,
        note: '※アニマトロニック(斧所持)',
      },
      {
        group: 'normal',
        label: '標準キラー', label_en: 'Normal',
        speed: 4.6, color: '#f39c12', bgColor: 'rgba(243,156,18,0.05)',
        names: ['その他 (' + cats.normal.length + '体)'],
        note: '※アニマトロニック(斧無し状態)',
      },
    ];

    const thStyle = 'background:var(--bg-secondary);color:var(--accent);border-bottom:2px solid var(--accent);padding:12px 16px;font-weight:600;text-align:left;';
    const tdStyle = 'padding:12px 16px;border-bottom:1px solid var(--border);vertical-align:middle;';

    const rows = categoryRows
      .filter(c => filter.has(c.group))
      .map(c => `
        <tr data-speed-group="${c.group}" style="background:${c.bgColor};">
          <td style="${tdStyle}vertical-align:top;">
            <strong style="color:${c.color};">${escapeHtml(c.label)}</strong><br>
            <span style="font-size:0.8rem;color:var(--text-secondary);">${escapeHtml(c.label_en)}</span>
            ${c.names.length > 0 ? `<div style="font-size:0.75rem;color:var(--text-secondary);margin-top:4px;">${c.names.map(escapeHtml).join('、')}</div>` : ''}
            ${c.note ? `<div style="font-size:0.7rem;color:var(--text-secondary);margin-top:2px;font-style:italic;">${escapeHtml(c.note)}</div>` : ''}
          </td>
          <td style="${tdStyle}text-align:center;white-space:nowrap;">
            <span style="font-size:1.1rem;font-weight:700;color:${c.color};">${c.speed} m/s</span>
          </td>
          <td style="${tdStyle}min-width:200px;">${makeBar(c.speed)}</td>
        </tr>`).join('');

    container.innerHTML = `
      <div style="border:1px solid var(--border);border-radius:8px;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;">
          <thead>
            <tr>
              <th style="${thStyle}">区分</th>
              <th style="${thStyle}text-align:center;">移動速度</th>
              <th style="${thStyle}min-width:200px;">速度バー <small style="font-weight:normal;opacity:0.6;">4.0基準</small></th>
            </tr>
          </thead>
          <tbody>
            ${rows}
          </tbody>
        </table>
      </div>
    `;
  },

  // アドオン一覧
  async loadAddons(containerId, killerSelectId) {
    const container = document.getElementById(containerId);
    const select = document.getElementById(killerSelectId);
    container.innerHTML = '<div class="loading">読み込み中</div>';

    try {
      const killers = await API.get('/api/killers.php');

      // セレクトボックスにキラー追加
      if (select) {
        killers.forEach(k => {
          const opt = document.createElement('option');
          opt.value = k.id;
          opt.textContent = k.name;
          select.appendChild(opt);
        });
      }

      // 全アドオン取得
      const addons = await API.get('/api/addons.php');
      this.allAddons = addons;
      this.renderAddons(addons, container);
      this.updateCount(addons.length);
    } catch (e) {
      container.innerHTML = '<p>データの読み込みに失敗しました。</p>';
    }
  },

  renderAddons(addons, container) {
    if (addons.length === 0) {
      container.innerHTML = '<p style="color:var(--text-secondary);padding:20px;">該当するアドオンが見つかりません。</p>';
      return;
    }

    const rarityLabel = {
      common: 'コモン', uncommon: 'アンコモン', rare: 'レア',
      very_rare: 'ベリーレア', ultra_rare: 'ウルトラレア', event: 'イベント'
    };

    // キラー別にグループ化
    const groups = {};
    addons.forEach(a => {
      const key = a.killer_id;
      if (!groups[key]) groups[key] = { name: a.killer_name, image_path: a.killer_image_path, addons: [] };
      groups[key].addons.push(a);
    });

    container.innerHTML = Object.values(groups).map(g => {
      const killerIcon = g.image_path
        ? `<img src="${escapeHtml(g.image_path)}" alt="${escapeHtml(g.name)}" style="width:110px;height:134px;border-radius:8px;object-fit:cover;object-position:center top;flex-shrink:0;" loading="lazy">`
        : `<div style="width:110px;height:134px;border-radius:8px;border:2px solid var(--border);background:var(--bg-secondary);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:2rem;">&#x1F5E1;</div>`;
      return `
      <div class="character-perk-group" style="display:flex;gap:0;align-items:stretch;margin-bottom:16px;">
        <div style="display:flex;align-items:center;padding:12px;background:var(--bg-secondary);border-right:1px solid var(--border);">
          ${killerIcon}
        </div>
        <div style="flex:1;min-width:0;">
          <div class="character-perk-header" style="padding:10px 16px;">
            <h3>${escapeHtml(g.name)}</h3>
          </div>
          <div class="character-perk-list">
            ${g.addons.map(a => `
              <div class="perk-item">
                <div style="display:flex;gap:10px;align-items:flex-start;">
                  ${a.image_path ? `<img src="${escapeHtml(a.image_path)}" alt="${escapeHtml(a.name)}" style="width:48px;height:48px;border-radius:6px;object-fit:contain;flex-shrink:0;" loading="lazy">` : ''}
                  <div style="flex:1;min-width:0;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                      <div class="perk-item-name">${escapeHtml(a.name)}</div>
                      <span class="rarity-badge rarity-${a.rarity}">${rarityLabel[a.rarity] || a.rarity}</span>
                    </div>
                    <div class="perk-item-desc">${escapeHtml(a.description || '')}</div>
                  </div>
                </div>
              </div>
            `).join('')}
          </div>
        </div>
      </div>`;
    }).join('');
  },

  filterAddonsByKiller(killerId, container) {
    if (!this.allAddons) return;
    if (!killerId) {
      this.renderAddons(this.allAddons, container);
      this.updateCount(this.allAddons.length);
      return;
    }
    const filtered = this.allAddons.filter(a => String(a.killer_id) === String(killerId));
    this.renderAddons(filtered, container);
    this.updateCount(filtered.length);
  },

  searchAddons(query, container) {
    if (!this.allAddons) return;
    const q = query.toLowerCase();
    const filtered = this.allAddons.filter(a =>
      a.name.toLowerCase().includes(q) ||
      (a.description && a.description.toLowerCase().includes(q)) ||
      (a.killer_name && a.killer_name.toLowerCase().includes(q))
    );
    this.renderAddons(filtered, container);
    this.updateCount(filtered.length);
  },

  // オファリング一覧
  async loadOfferings(role, containerId, filtersId) {
    const container = document.getElementById(containerId);
    container.innerHTML = '<div class="loading">読み込み中</div>';

    try {
      const offerings = await API.get('/api/offerings.php', { role });
      this.allOfferings = offerings;
      this.currentOfferingCategory = null;
      this.renderOfferings(offerings, container);
      this.updateCount(offerings.length);
      if (filtersId) this.renderOfferingFilters(offerings, filtersId, container);
    } catch (e) {
      container.innerHTML = '<p>データの読み込みに失敗しました。</p>';
    }
  },

  categoryLabels: {
    bp_increase: 'BP増加', fog: '霧', luck: '運', map_selection: 'マップ指定',
    memento_mori: 'メメント・モリ', charm: '魔除け', hook: 'フック',
    chest: '宝箱', hatch: 'ハッチ', basement: '地下室', spawn: '出現位置', event: 'イベント'
  },

  rarityLabels: {
    common: 'コモン', uncommon: 'アンコモン', rare: 'レア',
    very_rare: 'ベリーレア', ultra_rare: 'ウルトラレア', event: 'イベント'
  },

  renderOfferingFilters(offerings, filtersId, container) {
    const filtersEl = document.getElementById(filtersId);
    const categories = [...new Set(offerings.map(o => o.category))];

    filtersEl.innerHTML = `
      <button class="filter-tag active" data-cat="">全て</button>
      ${categories.map(c => `<button class="filter-tag" data-cat="${c}">${this.categoryLabels[c] || c}</button>`).join('')}
    `;

    filtersEl.querySelectorAll('.filter-tag').forEach(btn => {
      btn.addEventListener('click', () => {
        filtersEl.querySelectorAll('.filter-tag').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        this.currentOfferingCategory = btn.dataset.cat || null;
        this.filterOfferings(container);
      });
    });
  },

  filterOfferings(container) {
    if (!this.allOfferings) return;
    let filtered = this.allOfferings;
    if (this.currentOfferingCategory) {
      filtered = filtered.filter(o => o.category === this.currentOfferingCategory);
    }
    this.renderOfferings(filtered, container);
    this.updateCount(filtered.length);
  },

  renderOfferings(offerings, container) {
    if (offerings.length === 0) {
      container.innerHTML = '<p style="color:var(--text-secondary);padding:20px;">該当するオファリングが見つかりません。</p>';
      return;
    }

    const groups = {};
    offerings.forEach(o => {
      const cat = o.category || 'other';
      if (!groups[cat]) groups[cat] = [];
      groups[cat].push(o);
    });

    container.innerHTML = Object.entries(groups).map(([cat, items]) => `
      <div class="offering-group">
        <div class="offering-group-header">
          <h3>${this.categoryLabels[cat] || cat}</h3>
          <span class="offering-group-count">${items.length}件</span>
        </div>
        <div class="offering-items">
          ${items.map(o => `
            <div class="offering-item">
              <div class="offering-item-header">
                <div class="offering-icon-wrap${o.image_path ? '' : ' no-image'}">
                  ${o.image_path ? `<img src="${escapeHtml(o.image_path)}" alt="${escapeHtml(o.name)}" loading="lazy">` : ''}
                </div>
                <span class="offering-item-name">${escapeHtml(o.name)}</span>
                <span class="rarity-badge rarity-${o.rarity}">${this.rarityLabels[o.rarity] || o.rarity}</span>
              </div>
              <div class="offering-item-desc">${escapeHtml(o.description || '')}</div>
              ${o.role !== 'shared' ? `<span class="offering-role-tag">${o.role === 'killer' ? 'キラー専用' : 'サバイバー専用'}</span>` : ''}
            </div>
          `).join('')}
        </div>
      </div>
    `).join('');
  },

  // サバイバー一覧
  async loadSurvivors(containerId) {
    const container = document.getElementById(containerId);
    container.innerHTML = '<div class="loading">読み込み中</div>';
    try {
      const survivors = await API.get('/api/survivors.php');
      this.allSurvivors = survivors;
      this.renderSurvivors(survivors, container);
      this.updateCount(survivors.length);
      return survivors;
    } catch (e) {
      container.innerHTML = '<p>データの読み込みに失敗しました。</p>';
      return [];
    }
  },

  renderSurvivors(survivors, container) {
    if (survivors.length === 0) {
      container.innerHTML = '<p style="color:var(--text-secondary);padding:20px;">該当するサバイバーが見つかりません。</p>';
      return;
    }

    container.innerHTML = survivors.map(s => `
      <div class="character-perk-group">
        <div class="character-perk-header">
          ${s.image_path
            ? `<img src="${escapeHtml(s.image_path)}" alt="${escapeHtml(s.name)}" class="char-icon-img" loading="lazy">`
            : `<span style="width:40px;height:40px;border-radius:50%;border:2px solid var(--border);background:var(--bg-secondary);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.3rem;">&#x1F3C3;</span>`}
          <div>
            <h3 style="margin:0;">${escapeHtml(s.name)}</h3>
            <div style="font-size:0.85rem;color:var(--text-secondary);">${escapeHtml(s.name_en)}</div>
          </div>
        </div>
        <div class="character-perk-list">
          ${s.perks.map(p => `
            <div class="perk-item">
              <div style="display:flex;gap:10px;align-items:flex-start;">
                ${p.image_path ? `<img src="${escapeHtml(p.image_path)}" alt="${escapeHtml(p.name)}" style="width:48px;height:48px;border-radius:6px;object-fit:contain;flex-shrink:0;" loading="lazy">` : ''}
                <div style="flex:1;min-width:0;">
                  <div class="perk-item-name">${escapeHtml(p.name)}</div>
                  ${p.name_en ? `<div class="perk-item-en">${escapeHtml(p.name_en)}</div>` : ''}
                </div>
              </div>
            </div>
          `).join('')}
          ${s.perks.length < 3 ? '<div class="perk-item perk-item-empty"></div>'.repeat(3 - s.perks.length) : ''}
        </div>
      </div>
    `).join('');
  },

  // サバイバーアドオン
  async loadSurvivorAddons(containerId) {
    const container = document.getElementById(containerId);
    container.innerHTML = '<div class="loading">読み込み中</div>';
    try {
      const addons = await API.get('/api/survivor_addons.php');
      this.allSurvivorAddons = addons;
      this.renderSurvivorAddons(addons, container);
      this.updateCount(addons.length);
    } catch (e) {
      container.innerHTML = '<p>データの読み込みに失敗しました。</p>';
    }
  },

  itemTypeLabels: {
    first_aid_kit: '救急キット', toolbox: '工具箱', flashlight: '懐中電灯',
    map: '地図', key: '鍵'
  },

  renderSurvivorAddons(addons, container) {
    if (addons.length === 0) {
      container.innerHTML = '<p style="color:var(--text-secondary);padding:20px;">アドオンが登録されていません。</p>';
      return;
    }
    const groups = {};
    addons.forEach(a => {
      const t = a.item_type;
      if (!groups[t]) groups[t] = [];
      groups[t].push(a);
    });
    container.innerHTML = Object.entries(groups).map(([type, items]) => `
      <div class="offering-group">
        <div class="offering-group-header">
          <h3>${this.itemTypeLabels[type] || type}</h3>
          <span class="offering-group-count">${items.length}件</span>
        </div>
        <div class="offering-items">
          ${items.map(a => `
            <div class="offering-item">
              <div class="offering-item-header">
                ${a.image_path ? `<img src="${escapeHtml(a.image_path)}" alt="${escapeHtml(a.name)}" style="width:40px;height:40px;object-fit:contain;flex-shrink:0;" loading="lazy">` : '<div class="offering-icon-wrap no-image"></div>'}
                <span class="offering-item-name">${escapeHtml(a.name)}</span>
                <span class="rarity-badge rarity-${a.rarity}">${this.rarityLabels[a.rarity] || a.rarity}</span>
              </div>
              ${a.name_en ? `<div style="font-size:0.8rem;color:var(--text-secondary);margin-top:4px;">${escapeHtml(a.name_en)}</div>` : ''}
              <div class="offering-item-desc">${escapeHtml(a.description || '')}</div>
            </div>
          `).join('')}
        </div>
      </div>
    `).join('');
  },

  filterSurvivorAddonsByItem(itemType, container) {
    if (!this.allSurvivorAddons) return;
    const filtered = itemType
      ? this.allSurvivorAddons.filter(a => a.item_type === itemType)
      : this.allSurvivorAddons;
    this.renderSurvivorAddons(filtered, container);
    this.updateCount(filtered.length);
  },

  searchSurvivorAddons(query, container) {
    if (!this.allSurvivorAddons) return;
    const q = query.toLowerCase();
    const filtered = this.allSurvivorAddons.filter(a =>
      a.name.toLowerCase().includes(q) ||
      (a.name_en && a.name_en.toLowerCase().includes(q)) ||
      (a.description && a.description.toLowerCase().includes(q))
    );
    this.renderSurvivorAddons(filtered, container);
    this.updateCount(filtered.length);
  },

  searchOfferings(query, container) {
    if (!this.allOfferings) return;
    const q = query.toLowerCase();
    let filtered = this.allOfferings.filter(o =>
      o.name.toLowerCase().includes(q) ||
      (o.description && o.description.toLowerCase().includes(q))
    );
    if (this.currentOfferingCategory) {
      filtered = filtered.filter(o => o.category === this.currentOfferingCategory);
    }
    this.renderOfferings(filtered, container);
    this.updateCount(filtered.length);
  }
};
