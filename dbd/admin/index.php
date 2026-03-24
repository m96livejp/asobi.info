<?php
require_once __DIR__ . '/auth.php';
asobiRequireLogin('https://dbd.asobi.info/admin/');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>DbD 管理画面 - 画像マッピング</title>
  <link rel="stylesheet" href="/css/style.css">
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
    .admin-container { max-width: 1400px; margin: 0 auto; padding: 20px; }

    .admin-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
    .admin-header h1 { color: var(--accent); font-size: 1.5rem; }

    .toolbar { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; align-items: center; }
    .toolbar button { padding: 8px 16px; background: var(--bg-card); color: var(--text-primary); border: 1px solid var(--border); border-radius: 6px; cursor: pointer; font-size: 0.9rem; }
    .toolbar button:hover { border-color: var(--accent); }
    .toolbar button.active { background: var(--accent); color: #fff; border-color: var(--accent); }

    .scan-btn { background: var(--accent) !important; color: #fff !important; }

    .image-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
    .image-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 8px; padding: 12px; transition: border-color 0.2s; }
    .image-card.mapped { border-color: #e8c252; }
    .image-card.confirmed { border-color: #4caf50; }
    .image-card .preview { display: flex; gap: 12px; align-items: center; margin-bottom: 10px; }
    .image-card .preview img { width: 64px; height: 64px; border-radius: 6px; object-fit: cover; background: var(--bg-secondary); }
    .image-card .filename { font-size: 0.75rem; color: var(--text-secondary); word-break: break-all; }
    .image-card select { width: 100%; padding: 8px; background: var(--bg-secondary); color: var(--text-primary); border: 1px solid var(--border); border-radius: 4px; font-size: 0.85rem; }
    .image-card .status { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; margin-top: 6px; }
    .status-unmapped { background: rgba(244,67,54,0.2); color: #f44336; }
    .status-mapped { background: rgba(232,194,82,0.2); color: #e8c252; }
    .status-confirmed { background: rgba(76,175,80,0.2); color: #4caf50; }

    .stats { display: flex; gap: 16px; margin-bottom: 20px; }
    .stat-item { padding: 8px 16px; background: var(--bg-card); border-radius: 8px; font-size: 0.9rem; }
    .stat-item strong { color: var(--accent); }

    .msg { padding: 12px; border-radius: 8px; margin-bottom: 16px; }
    .msg-success { background: rgba(76,175,80,0.1); color: #4caf50; border: 1px solid rgba(76,175,80,0.3); }
    .msg-error { background: rgba(244,67,54,0.1); color: #f44336; border: 1px solid rgba(244,67,54,0.3); }
  </style>
</head>
<body>
  <div class="admin-container">
    <div class="admin-header">
      <h1>DbD 画像マッピング管理</h1>
      <div style="display:flex;gap:8px;">
        <button onclick="location.href='/'" style="padding:6px 12px;background:var(--bg-card);color:var(--text-secondary);border:1px solid var(--border);border-radius:4px;cursor:pointer;">サイトへ</button>
        <button onclick="location.href='https://asobi.info/logout.php'" style="padding:6px 12px;background:var(--bg-card);color:var(--text-secondary);border:1px solid var(--border);border-radius:4px;cursor:pointer;">ログアウト</button>
      </div>
    </div>

    <div id="message"></div>

    <div class="toolbar">
      <span style="color:var(--text-secondary);font-size:0.9rem;">カテゴリ:</span>
      <button class="type-btn active" data-type="perks/killer">キラーパーク</button>
      <button class="type-btn" data-type="perks/survivor">サバイバーパーク</button>
      <button class="type-btn" data-type="characters/killer">キラー</button>
      <button class="type-btn" data-type="characters/survivor">サバイバー</button>
      <button class="type-btn" data-type="offerings">オファリング</button>
      <button class="type-btn" data-type="addons">アドオン</button>
    </div>

    <div class="toolbar">
      <button class="scan-btn" onclick="scanImages()">画像スキャン</button>
      <button onclick="applyMappings()">マッピング確定</button>
      <span style="color:var(--text-secondary);font-size:0.85rem;">フィルター:</span>
      <button class="filter-btn active" data-filter="all">全て</button>
      <button class="filter-btn" data-filter="unmapped">未対応</button>
      <button class="filter-btn" data-filter="mapped">対応済み</button>
      <button class="filter-btn" data-filter="confirmed">確認済み</button>
    </div>

    <div class="stats" id="stats"></div>
    <div class="image-grid" id="image-grid"></div>
  </div>

  <script>
    let currentType = 'perks/killer';
    let currentFilter = 'all';
    let mappings = [];
    let candidates = [];

    // Type tabs
    document.querySelectorAll('.type-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.type-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentType = btn.dataset.type;
        loadData();
      });
    });

    // Filter tabs
    document.querySelectorAll('.filter-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentFilter = btn.dataset.filter;
        renderGrid();
      });
    });

    async function loadData() {
      try {
        const [mapRes, candRes] = await Promise.all([
          fetch(`/api/admin/images.php?action=list&type=${encodeURIComponent(currentType)}`),
          fetch(`/api/admin/images.php?action=candidates&type=${encodeURIComponent(currentType)}`)
        ]);
        mappings = await mapRes.json();
        candidates = await candRes.json();
        renderGrid();
      } catch (e) {
        showMessage('データ取得エラー: ' + e.message, 'error');
      }
    }

    function renderGrid() {
      const filtered = currentFilter === 'all' ? mappings : mappings.filter(m => m.status === currentFilter);

      const total = mappings.length;
      const unmapped = mappings.filter(m => m.status === 'unmapped').length;
      const mapped = mappings.filter(m => m.status === 'mapped').length;
      const confirmed = mappings.filter(m => m.status === 'confirmed').length;

      document.getElementById('stats').innerHTML = `
        <div class="stat-item">全体: <strong>${total}</strong></div>
        <div class="stat-item">未対応: <strong style="color:#f44336;">${unmapped}</strong></div>
        <div class="stat-item">対応済み: <strong style="color:#e8c252;">${mapped}</strong></div>
        <div class="stat-item">確認済み: <strong style="color:#4caf50;">${confirmed}</strong></div>
      `;

      const grid = document.getElementById('image-grid');
      grid.innerHTML = filtered.map(m => `
        <div class="image-card ${m.status}">
          <div class="preview">
            <img src="/images/${m.image_type}/${m.image_file}" alt="" loading="lazy">
            <div>
              <div class="filename">${esc(m.image_file)}</div>
              <span class="status status-${m.status}">${statusLabel(m.status)}</span>
            </div>
          </div>
          <select onchange="mapImage(${m.id}, this)" data-id="${m.id}">
            <option value="">-- 選択 --</option>
            ${candidates.map(c => {
              const label = c.character_name
                ? `${c.character_name} / ${c.name}`
                : c.killer_name
                  ? `${c.killer_name} / ${c.name}`
                  : c.name;
              const selected = (m.mapped_id && String(m.mapped_id) === String(c.id)) ? 'selected' : '';
              return `<option value="${c.id}" data-name="${esc(c.name)}" ${selected}>${esc(label)}</option>`;
            }).join('')}
          </select>
        </div>
      `).join('');
    }

    function statusLabel(s) {
      return {unmapped: '未対応', mapped: '対応済み', confirmed: '確認済み'}[s] || s;
    }

    function esc(str) {
      if (!str) return '';
      const d = document.createElement('div');
      d.textContent = str;
      return d.innerHTML;
    }

    async function mapImage(id, select) {
      const mappedId = select.value || null;
      const mappedName = select.selectedOptions[0]?.dataset?.name || null;

      await fetch('/api/admin/images.php?action=map', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id, mapped_id: mappedId, mapped_name: mappedName})
      });
      await loadData();
    }

    async function scanImages() {
      const res = await fetch(`/api/admin/images.php?action=scan&type=${encodeURIComponent(currentType)}`);
      const data = await res.json();
      showMessage(`${data.added} 件追加 (合計 ${data.total} 件)`, 'success');
      await loadData();
    }

    async function applyMappings() {
      const res = await fetch(`/api/admin/images.php?action=apply&type=${encodeURIComponent(currentType)}`, {method: 'POST'});
      const data = await res.json();
      showMessage(`${data.applied} 件のマッピングを確定しました`, 'success');
      await loadData();
    }

    function showMessage(text, type) {
      const el = document.getElementById('message');
      el.innerHTML = `<div class="msg msg-${type}">${esc(text)}</div>`;
      setTimeout(() => el.innerHTML = '', 5000);
    }

    loadData();
  </script>
</body>
</html>
