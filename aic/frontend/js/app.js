/* === aic.asobi.info フロントエンドJS === */

const API_BASE = '/api';
let token = localStorage.getItem('aic_token') || '';
let currentUser = null;
let currentConversationId = null;
let currentCharacter = null;
let isStreaming = false;

// コミュニティ用キャッシュ
let communityChars = [];
let communitySort = 'like';

const LOGIN_URL = 'https://asobi.info/oauth/aic-login.php?callback=' +
  encodeURIComponent('https://aic.asobi.info/api/auth/asobi/callback');

// === API呼び出し ===
async function api(path, opts = {}) {
  const headers = { 'Content-Type': 'application/json' };
  if (token) headers['Authorization'] = 'Bearer ' + token;
  const res = await fetch(API_BASE + path, { ...opts, headers });
  if (res.status === 401) { token = ''; localStorage.removeItem('aic_token'); currentUser = null; }
  return res;
}

// === 認証 ===
async function initAuth() {
  if (token) {
    const meRes = await api('/auth/me');
    if (meRes.ok) {
      currentUser = await meRes.json();
      updateUserInfo();
      const balRes = await api('/balance');
      if (balRes.ok) updateBalance(await balRes.json());
      return;
    }
    // トークン無効
    token = '';
    localStorage.removeItem('aic_token');
  }

  // asobi.info のセッションを確認（ログアウト後のみスキップ）
  if (!sessionStorage.getItem('aic_skip_auto_login')) {
    try {
      const meRes = await fetch('https://asobi.info/assets/php/me.php', { credentials: 'include' });
      if (meRes.ok) {
        const me = await meRes.json();
        if (me.loggedIn) {
          // asobi.info でログイン済み → AIC へ自動ログイン
          window.location.href = LOGIN_URL;
          return;
        }
      }
    } catch (_) {
      // CORS エラー等は無視してゲスト続行
    }
    // ※未ログインでもフラグはセットしない → 次回ページ読み込みで再チェック
  }

  // ゲストログイン
  let deviceId = localStorage.getItem('aic_device_id');
  if (!deviceId) { deviceId = crypto.randomUUID(); localStorage.setItem('aic_device_id', deviceId); }
  const res = await api('/auth/guest', { method: 'POST', body: JSON.stringify({ device_id: deviceId, display_name: 'ゲスト' }) });
  if (res.ok) {
    const data = await res.json();
    token = data.token;
    currentUser = data.user;
    localStorage.setItem('aic_token', token);
    updateUserInfo();
    const balRes = await api('/balance');
    if (balRes.ok) updateBalance(await balRes.json());
  }
}

function updateUserInfo() {
  // サイドバーのユーザー名
  const sidebarEl = document.getElementById('user-info');
  if (sidebarEl && currentUser) {
    sidebarEl.textContent = currentUser.is_guest ? '👤 ゲスト' : '👤 ' + currentUser.display_name;
  }
  // ログインリンクをセット
  ['login-link', 'create-login-link'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.href = LOGIN_URL;
  });
  // ヘッダーユーザーエリア
  renderHeaderUser();
  updateAdminLink();
}

function updateAdminLink() {
  const el = document.getElementById('admin-link-section');
  if (el && currentUser && currentUser.role === 'admin') el.style.display = 'block';
}

function renderHeaderUser() {
  const area = document.getElementById('header-user-area');
  if (!area) return;

  if (!currentUser || currentUser.is_guest) {
    area.innerHTML = `<a href="${LOGIN_URL}" class="header-btn-login">
      <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
      ログイン
    </a>`;
    return;
  }

  const avatarHtml = currentUser.avatar_url
    ? `<img src="${esc(currentUser.avatar_url)}" alt="">`
    : esc((currentUser.display_name || '?')[0].toUpperCase());

  const adminLink = currentUser.role === 'admin'
    ? `<div class="hud-divider"></div>
       <a href="/admin.html" class="hud-item">🛠 AIC管理画面</a>
       <a href="https://asobi.info/admin/" class="hud-item" target="_blank">🛠 asobi.info管理画面</a>`
    : '';

  area.innerHTML = `
    <button class="hdr-user-trigger" onclick="toggleUserMenu(event)">
      <div class="hdr-user-avatar">${avatarHtml}</div>
      <span class="hdr-user-caret">▼</span>
    </button>
    <div class="hdr-user-dropdown">
      <div class="hdr-user-displayname">${esc(currentUser.display_name)}</div>
      <button class="hud-item" onclick="navTo('profile'); closeUserMenu()">プロフィール</button>
      ${adminLink}
    </div>`;
}

function toggleUserMenu(e) {
  e.stopPropagation();
  document.getElementById('header-user-area').classList.toggle('open');
}

function closeUserMenu() {
  document.getElementById('header-user-area').classList.remove('open');
}

// クリック外でドロップダウンを閉じる
document.addEventListener('click', () => closeUserMenu());

function updateBalance(bal) {
  document.getElementById('balance-info').textContent = '💎 ' + bal.points + 'pt / ' + bal.crystals + 'クリスタル';
  document.getElementById('header-balance').textContent = '💎 ' + bal.points + 'pt';
}

// === ナビゲーション ===
function showScreen(id) {
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  document.getElementById('screen-' + id).classList.add('active');

  // ボトムナビのアクティブ状態を更新
  const navScreen = id === 'chat' ? 'chat-list' : id;
  document.querySelectorAll('.bnav-item').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.screen === navScreen);
  });
}

function navTo(screen) {
  if (screen === 'create') {
    showScreen('create');
    const isGuest = !currentUser || currentUser.is_guest;
    document.getElementById('create-gate').style.display = isGuest ? 'block' : 'none';
    document.getElementById('create-form').style.display = isGuest ? 'none' : 'block';
    if (!isGuest) {
      // ステップ1に戻す
      document.getElementById('cr-step-1').style.display = 'block';
      document.getElementById('cr-step-2').style.display = 'none';
      document.getElementById('cr-step-ind-1').className = 'cr-step active';
      document.getElementById('cr-step-ind-2').className = 'cr-step';
      clearAvatarSelection();
      checkSdStatus();
      // ギャラリー読み込み（アバター選択用）
      loadCreateGallery().then(() => {
        // sessionStorageからアバターURLを引き継ぐ（画像生成画面から戻った時）
        const pendingAvatar = sessionStorage.getItem('aic_gen_avatar');
        if (pendingAvatar) {
          sessionStorage.removeItem('aic_gen_avatar');
          // グリッドから該当画像のIDを探してライトボックスを開く
          const gridItems = document.querySelectorAll('#cr-gallery-grid .gen-gallery-item');
          let opened = false;
          for (const item of gridItems) {
            const img = item.querySelector('img');
            if (img && (img.src === pendingAvatar || img.src.endsWith(pendingAvatar.split('/').pop()))) {
              const id = parseInt(item.id.replace('cgrid-', ''));
              const isFav = item.querySelector('.gen-gallery-fav.active') ? 1 : 0;
              openCrLightbox(id, pendingAvatar, isFav);
              opened = true;
              break;
            }
          }
          if (!opened) selectAvatarFromGallery(pendingAvatar);
        }
      });
    }
    document.getElementById('header-title').textContent = 'キャラクター作成';
    return;
  }
  if (screen === 'generate') {
    showScreen('generate');
    document.getElementById('header-title').textContent = '画像生成';
    openGenerateScreen();
    return;
  }
  showScreen(screen);
  const titles = { home: 'AIチャット', community: 'コミュニティ', 'chat-list': 'チャット', profile: 'プロフィール' };
  document.getElementById('header-title').textContent = titles[screen] || 'AIチャット';

  if (screen === 'home') loadHomeChars();
  else if (screen === 'community') loadCommunity();
  else if (screen === 'chat-list') loadChatList();
  else if (screen === 'profile') loadProfile();
}

// === ホーム画面 ===
async function loadHomeChars() {
  if (communityChars.length === 0) {
    const res = await api('/characters/public');
    if (res.ok) communityChars = await res.json();
  }
  const featured = [...communityChars].sort((a, b) => b.like_count - a.like_count).slice(0, 4);
  renderCharCards('featured-chars', featured);
  // 公開キャラがない場合はセクション非表示
  const sec = document.getElementById('home-featured-section');
  if (sec) sec.style.display = featured.length ? 'block' : 'none';
}

// === コミュニティ ===
async function loadCommunity() {
  if (communityChars.length === 0) {
    const res = await api('/characters/public');
    if (res.ok) communityChars = await res.json();
  }
  renderCommunity();
}

function renderCommunity() {
  let chars = [...communityChars];
  const q = (document.getElementById('community-search')?.value || '').toLowerCase();
  if (q) {
    chars = chars.filter(c =>
      c.name.toLowerCase().includes(q) ||
      (c.profile || '').toLowerCase().includes(q) ||
      (c.keywords || []).some(k => k.toLowerCase().includes(q))
    );
  }
  if (communitySort === 'new') chars.sort((a, b) => b.id - a.id);
  else chars.sort((a, b) => b.like_count - a.like_count);
  renderCharCards('community-chars', chars);
}

function filterCommunity(val) {
  renderCommunity();
}

function sortCommunity(mode) {
  communitySort = mode;
  document.getElementById('sort-like').classList.toggle('active', mode === 'like');
  document.getElementById('sort-new').classList.toggle('active', mode === 'new');
  renderCommunity();
}

// === チャットリスト ===
async function loadChatList() {
  const isGuest = !currentUser || currentUser.is_guest;

  // 会話履歴
  const convsEl = document.getElementById('chatlist-convs');
  if (token) {
    const convRes = await api('/conversations');
    if (convRes.ok) {
      const convs = await convRes.json();
      if (convs.length === 0) {
        convsEl.innerHTML = '<p class="chatlist-empty">まだ会話がありません</p>';
      } else {
        convsEl.innerHTML = convs.map(c => {
          const avatar = c.character_name ? c.character_name[0] : '?';
          return `<div class="chatlist-conv-item" onclick="openConversation(${c.id})">
            <div class="chatlist-conv-avatar">${esc(avatar)}</div>
            <div class="chatlist-conv-info">
              <div class="chatlist-conv-name">${esc(c.character_name)}</div>
              <div class="chatlist-conv-preview">${esc(c.last_message || '会話を始める')}</div>
            </div>
          </div>`;
        }).join('');
      }
    }
  }

  // マイキャラ
  const mineEl = document.getElementById('chatlist-mine');
  if (!isGuest) {
    const mineRes = await api('/characters/mine');
    if (mineRes.ok) {
      const mine = await mineRes.json();
      if (mine.length === 0) {
        mineEl.innerHTML = '<p class="chatlist-empty"><a href="#" onclick="navTo(\'create\')" style="color:var(--accent)">キャラクターを作成</a>してチャットしましょう</p>';
      } else {
        renderCharCards('chatlist-mine', mine);
      }
    }
    // お気に入り
    const likedRes = await api('/characters/liked');
    if (likedRes.ok) {
      const liked = await likedRes.json();
      if (liked.length > 0) {
        document.getElementById('chatlist-liked-section').style.display = 'block';
        renderCharCards('chatlist-liked', liked);
      }
    }
  } else {
    // サンプルキャラを表示
    const sampleRes = await api('/characters/sample');
    if (sampleRes.ok) renderCharCards('chatlist-mine', await sampleRes.json());
  }
}

// === プロフィール ===
function loadProfile() {
  const el = document.getElementById('profile-content');
  const isGuest = !currentUser || currentUser.is_guest;

  if (isGuest) {
    el.innerHTML = `
      <div class="profile-guest-box">
        <div class="profile-guest-icon">👤</div>
        <p style="font-size:1rem;color:var(--text)">ゲストとして利用中</p>
        <p>asobi.info アカウントでログインすると<br>キャラクター作成やデータの保存ができます</p>
        <a href="${LOGIN_URL}" class="btn btn-primary">asobi.info でログイン</a>
      </div>`;
  } else {
    el.innerHTML = `
      <div class="profile-header">
        <div class="profile-avatar">${esc(currentUser.display_name[0] || '?')}</div>
        <div>
          <div class="profile-name">${esc(currentUser.display_name)}</div>
          <div class="profile-balance" id="profile-balance-text"></div>
        </div>
      </div>
      <div class="profile-section">
        <h3>マイキャラクター</h3>
        <div id="profile-mine-chars" class="char-grid"></div>
        <button class="btn btn-outline" style="margin-top:12px" onclick="navTo('create')">＋ キャラクター作成</button>
      </div>
      <div class="profile-actions">
        <button class="btn btn-secondary" onclick="logout()">ログアウト</button>
      </div>`;

    // 残高表示
    api('/balance').then(r => r.ok && r.json()).then(bal => {
      if (bal) {
        const el = document.getElementById('profile-balance-text');
        if (el) el.textContent = '💎 ' + bal.points + 'pt / ' + bal.crystals + 'クリスタル';
      }
    });

    // マイキャラ
    api('/characters/mine').then(r => r.ok && r.json()).then(chars => {
      if (chars) {
        const el = document.getElementById('profile-mine-chars');
        if (el) renderCharCards('profile-mine-chars', chars);
      }
    });
  }
}

async function logout() {
  token = '';
  currentUser = null;
  localStorage.removeItem('aic_token');
  // ログアウト後は同一セッション内で自動ログインしない
  sessionStorage.setItem('aic_skip_auto_login', '1');
  closeUserMenu();
  await initAuth();
  navTo('home');
}

// === キャラクターカード描画 ===
function renderCharCards(containerId, chars) {
  const el = document.getElementById(containerId);
  if (!el) return;
  if (!chars.length) { el.innerHTML = '<p style="color:var(--text-secondary);font-size:0.85rem;grid-column:1/-1">まだありません</p>'; return; }
  el.innerHTML = chars.map(c => {
    const avatar = c.char_name ? c.char_name[0] : c.name[0];
    const tags = (c.genre_personality || []).slice(0, 2).map(t => '<span class="char-card-tag">' + t + '</span>').join('');
    return '<div class="char-card" onclick="startChat(' + c.id + ')">' +
      '<div class="char-card-avatar">' + esc(avatar) + '</div>' +
      '<div class="char-card-name">' + esc(c.name) + '</div>' +
      '<div class="char-card-desc">' + esc(c.profile || '') + '</div>' +
      '<div class="char-card-meta">' + tags + ' ❤ ' + c.like_count + ' 💬 ' + c.use_count + '</div>' +
      '</div>';
  }).join('');
}

// === 会話開始 ===
async function startChat(charId) {
  const res = await api('/conversations', { method: 'POST', body: JSON.stringify({ character_id: charId }) });
  if (res.ok) {
    const conv = await res.json();
    currentConversationId = conv.id;
    await openConversation(conv.id);
    loadConversations();
  }
}

// === サイドバー会話一覧 ===
async function loadConversations() {
  const res = await api('/conversations');
  if (!res.ok) return;
  const convs = await res.json();
  const el = document.getElementById('conversation-list');
  el.innerHTML = convs.map(c =>
    '<div class="conv-item' + (c.id === currentConversationId ? ' active' : '') + '" onclick="openConversation(' + c.id + ')">' +
    '<div class="conv-item-name">' + esc(c.character_name) + '</div>' +
    '<div class="conv-item-preview">' + esc(c.last_message || '会話を始める') + '</div>' +
    '</div>'
  ).join('');
}

// === 会話表示 ===
async function openConversation(convId) {
  currentConversationId = convId;
  const res = await api('/conversations/' + convId);
  if (!res.ok) return;
  const data = await res.json();
  currentCharacter = data.character;
  document.getElementById('header-title').textContent = currentCharacter ? currentCharacter.name : 'チャット';
  showScreen('chat');

  const el = document.getElementById('chat-messages');
  el.innerHTML = data.messages.map(m =>
    m.role === 'user'
      ? '<div class="msg msg-user">' + esc(m.content) + '</div>'
      : '<div class="msg msg-ai"><div class="msg-ai-name">' + esc(currentCharacter?.name || 'AI') + '</div>' + esc(m.content) + '</div>'
  ).join('');
  el.scrollTop = el.scrollHeight;

  document.getElementById('chat-input').focus();
  loadConversations();
}

// === メッセージ送信 ===
async function sendMessage() {
  const input = document.getElementById('chat-input');
  const msg = input.value.trim();
  if (!msg || isStreaming || !currentConversationId) return;

  input.value = '';
  input.style.height = 'auto';
  isStreaming = true;
  document.getElementById('send-btn').disabled = true;

  const chatEl = document.getElementById('chat-messages');
  chatEl.innerHTML += '<div class="msg msg-user">' + esc(msg) + '</div>';

  const aiMsg = document.createElement('div');
  aiMsg.className = 'msg msg-ai';
  aiMsg.innerHTML = '<div class="msg-ai-name">' + esc(currentCharacter?.name || 'AI') + '</div><span class="msg-typing">考え中...</span>';
  chatEl.appendChild(aiMsg);
  chatEl.scrollTop = chatEl.scrollHeight;

  try {
    const res = await fetch(API_BASE + '/chat/' + currentConversationId, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
      body: JSON.stringify({ message: msg }),
    });

    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      aiMsg.innerHTML = '<div class="msg-ai-name">エラー</div>' + esc(err.detail || '送信に失敗しました');
      return;
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let aiText = '';
    aiMsg.innerHTML = '<div class="msg-ai-name">' + esc(currentCharacter?.name || 'AI') + '</div>';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      const chunk = decoder.decode(value);
      for (const line of chunk.split('\n')) {
        if (!line.startsWith('data: ')) continue;
        try {
          const data = JSON.parse(line.slice(6));
          if (data.text) {
            aiText += data.text;
            aiMsg.innerHTML = '<div class="msg-ai-name">' + esc(currentCharacter?.name || 'AI') + '</div>' + esc(aiText);
            chatEl.scrollTop = chatEl.scrollHeight;
          }
          if (data.done) {
            const balRes = await api('/balance');
            if (balRes.ok) updateBalance(await balRes.json());
          }
          if (data.error) {
            aiMsg.innerHTML += '<br><span style="color:var(--accent);">' + esc(data.error) + '</span>';
          }
        } catch (e) {}
      }
    }
  } catch (e) {
    aiMsg.innerHTML = '<div class="msg-ai-name">エラー</div>通信エラーが発生しました';
  } finally {
    isStreaming = false;
    document.getElementById('send-btn').disabled = false;
    loadConversations();
  }
}

// === キャラ作成 ===
function initTagSelects() {
  document.querySelectorAll('#cr-step-2 .tag-select').forEach(container => {
    if (container.children.length) return; // 初期化済みはスキップ
    const options = container.dataset.options.split(',');
    container.innerHTML = options.map(o =>
      '<button type="button" class="tag-btn" onclick="toggleTag(this)">' + o + '</button>'
    ).join('');
  });
}

function toggleTag(btn) { btn.classList.toggle('active'); }

function getSelectedTags(containerId) {
  return Array.from(document.querySelectorAll('#' + containerId + ' .tag-btn.active')).map(b => b.textContent);
}

async function saveCharacter() {
  const name = document.getElementById('cr-name').value.trim();
  if (!name) {
    showInlineError('cr-name-error', 'キャラクター名を入力してください', 'cr-name');
    return;
  }
  clearInlineError('cr-name-error');
  clearInlineError('cr-save-error');

  const body = {
    name: name,
    char_name: document.getElementById('cr-char-name').value.trim() || null,
    char_age: document.getElementById('cr-age').value.trim() || null,
    profile: document.getElementById('cr-profile').value.trim() || null,
    private_profile: document.getElementById('cr-private').value.trim() || null,
    first_message: document.getElementById('cr-first-msg').value.trim() || null,
    genre_story: getSelectedTags('cr-story'),
    genre_char_type: getSelectedTags('cr-chartype'),
    genre_personality: getSelectedTags('cr-personality'),
    genre_era: getSelectedTags('cr-era'),
    genre_base: getSelectedTags('cr-base'),
    keywords: document.getElementById('cr-keywords').value.split(',').map(s => s.trim()).filter(s => s).slice(0, 5),
    is_public: document.getElementById('cr-public').checked ? 1 : 0,
    avatar_url: document.getElementById('cr-avatar-url')?.value || null,
  };

  const res = await api('/characters', { method: 'POST', body: JSON.stringify(body) });
  if (res.ok) {
    communityChars = []; // キャッシュクリア
    showToast('キャラクターを作成しました', 'success');
    navTo('profile');
  } else {
    const err = await res.json().catch(() => ({}));
    showInlineError('cr-save-error', err.detail || '作成に失敗しました');
  }
}

// === 画像生成 ===
let sdEnabled = false;
let selectedGenIds = new Set();

async function checkSdStatus() {
  try {
    const r = await api('/generate/sd-status');
    if (r.ok) {
      const d = await r.json();
      sdEnabled = d.enabled;
      const sec = document.getElementById('avatar-gen-section');
      if (sec) sec.style.display = sdEnabled ? 'block' : 'none';
    }
  } catch (_) {}
}

// 画像生成画面を開く
async function openGenerateScreen() {
  selectedGenIds = new Set();
  await loadGenTemplates();
  await loadPendingImages();
  await loadGallery();
}

// テンプレート一覧を読み込む
async function loadGenTemplates() {
  try {
    const r = await api('/generate/templates');
    if (!r.ok) return;
    const templates = await r.json();
    const el = document.getElementById('gen-templates');
    const emptyMsg = document.getElementById('gen-tmpl-empty');
    if (!el) return;
    if (!templates.length) {
      el.innerHTML = '';
      if (emptyMsg) emptyMsg.style.display = 'block';
      return;
    }
    if (emptyMsg) emptyMsg.style.display = 'none';
    // テンプレートボタンを生成（onclick はイベントリスナーで登録）
    el.innerHTML = templates.map((t, i) =>
      `<button class="gen-tmpl-btn" data-idx="${i}">${esc(t.name)}</button>`
    ).join('');
    // データを配列で保持してクリック時に参照
    el._templates = templates;
    el.querySelectorAll('.gen-tmpl-btn').forEach(btn => {
      btn.addEventListener('click', function() {
        el.querySelectorAll('.gen-tmpl-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const idx = parseInt(this.dataset.idx);
        const t = el._templates[idx];
        const promptEl = document.getElementById('gen-main-prompt');
        if (promptEl && t) promptEl.value = t.prompt;
        // template_id を記録
        el.dataset.selectedId = t ? t.id : '';
      });
    });
  } catch(_) {}
}

// pending画像を確認し、あれば表示（画面再訪でのレジューム）
async function loadPendingImages() {
  try {
    const r = await api('/generate/pending');
    if (!r.ok) return;
    const d = await r.json();
    const imgs = d.images || [];
    const notice = document.getElementById('gen-pending-notice');
    const formSection = document.getElementById('gen-form-section');
    const resultsWrap = document.getElementById('gen-results-wrap');
    if (imgs.length > 0) {
      if (notice) notice.style.display = 'block';
      if (formSection) formSection.style.display = 'none';
      renderGenResults(imgs);
    } else {
      if (notice) notice.style.display = 'none';
      if (formSection) formSection.style.display = 'block';
      if (resultsWrap) resultsWrap.style.display = 'none';
    }
  } catch(_) {}
}

// 生成結果グリッドを描画
function renderGenResults(imgs) {
  const wrap = document.getElementById('gen-results-wrap');
  const grid = document.getElementById('gen-results-grid');
  if (!wrap || !grid) return;
  selectedGenIds = new Set();
  grid.innerHTML = imgs.map(img =>
    `<div class="gen-img-card" id="gcard-${img.id}" onclick="selectGenImage(${img.id})">
      <img src="${esc(img.url)}" loading="lazy" alt="">
      <div class="gen-img-check">✓</div>
    </div>`
  ).join('');
  wrap.style.display = 'block';
}

// 画像を選択/解除
function selectGenImage(id) {
  if (selectedGenIds.has(id)) {
    selectedGenIds.delete(id);
    document.getElementById('gcard-' + id)?.classList.remove('selected');
  } else {
    selectedGenIds.add(id);
    document.getElementById('gcard-' + id)?.classList.add('selected');
  }
}

// 6枚生成
async function generateImages() {
  const prompt = document.getElementById('gen-main-prompt')?.value.trim();
  if (!prompt) {
    showInlineError('gen-prompt-error', 'プロンプトを入力してください', 'gen-main-prompt');
    return;
  }
  clearInlineError('gen-prompt-error');

  const btn = document.getElementById('btn-gen-6');
  const status = document.getElementById('gen-main-status');
  btn.disabled = true; btn.textContent = '⏳ 生成中...';
  if (status) { status.style.display = 'block'; status.textContent = '⏳ 生成しています（1〜2分かかることがあります）'; }

  const tmplContainer = document.getElementById('gen-templates');
  const templateId = tmplContainer?.dataset.selectedId ? parseInt(tmplContainer.dataset.selectedId) : null;

  try {
    const r = await api('/generate/image', {
      method: 'POST',
      body: JSON.stringify({ prompt, template_id: templateId }),
    });
    if (!r.ok) {
      const err = await r.json().catch(() => ({}));
      if (status) status.textContent = '❌ ' + (err.detail || '生成に失敗しました');
      return;
    }
    if (status) status.style.display = 'none';
    await loadPendingImages();
    await loadGallery();
  } catch(e) {
    if (status) status.textContent = '❌ 通信エラー: ' + e.message;
  } finally {
    btn.disabled = false; btn.textContent = '✨ 6枚生成する';
  }
}

// 選択した画像を保存
async function saveSelectedImages() {
  if (selectedGenIds.size === 0) {
    showToast('保存する画像をクリックして選択してください', 'info');
    return;
  }
  let saved = 0;
  for (const id of selectedGenIds) {
    const r = await api('/generate/save/' + id, { method: 'POST' });
    if (r.ok) saved++;
  }
  // 残りのpendingを破棄
  await api('/generate/discard-pending', { method: 'POST' });
  await loadPendingImages();
  await loadGallery();
  showToast(saved + '枚の画像をギャラリーに保存しました', 'success');
}

// 全て破棄
async function discardAllPending() {
  showConfirm('生成した画像をすべて破棄しますか？', async () => {
    await api('/generate/discard-pending', { method: 'POST' });
    await loadPendingImages();
  }, '破棄する');
}

// ギャラリー読み込み
async function loadGallery() {
  try {
    const r = await api('/generate/my-images');
    if (!r.ok) return;
    const d = await r.json();
    const imgs = d.images || [];
    const count = d.count || 0;
    const badge = document.getElementById('gen-count-badge');
    if (badge) badge.textContent = `📁 ${count} / 100枚`;
    const limitMsg = document.getElementById('gen-limit-msg');
    if (limitMsg) limitMsg.style.display = count >= 100 ? 'block' : 'none';
    const el = document.getElementById('gen-gallery');
    if (!el) return;
    if (!imgs.length) {
      el.innerHTML = '<p style="color:var(--text-secondary);font-size:0.82rem;grid-column:1/-1">まだ保存した画像はありません</p>';
      return;
    }
    el.innerHTML = imgs.map(img =>
      `<div class="gen-gallery-item">
        <img src="${esc(img.url)}" loading="lazy" alt="">
        <button class="gen-gallery-del" onclick="deleteFromGallery(${img.id})" title="削除">✕</button>
        <button class="gen-gallery-use" onclick="useGalleryImage('${esc(img.url)}')">アバターに使う</button>
      </div>`
    ).join('');
  } catch(_) {}
}

// ギャラリーから削除（ソフトデリート）
async function deleteFromGallery(id) {
  showConfirm('ギャラリーから削除しますか？', async () => {
    const r = await api('/generate/my-images/' + id, { method: 'DELETE' });
    if (r.ok) {
      await loadGallery();
      showToast('ギャラリーから削除しました', 'info');
    }
  }, '削除する');
}

// ギャラリーの画像をアバターとして使う（generate画面から）
function useGalleryImage(url) {
  sessionStorage.setItem('aic_gen_avatar', url);
  navTo('create');
}

// キャラ作成画面の画像生成ツールへ遷移
function goToGenerate() {
  navTo('generate');
}

// キャラクター作成画面のギャラリー読み込み
async function loadCreateGallery() {
  const grid = document.getElementById('cr-gallery-grid');
  const section = document.getElementById('cr-gallery-section');
  const emptyEl = document.getElementById('cr-gallery-empty');
  if (!grid) return;
  try {
    const r = await api('/generate/my-images');
    if (!r.ok) return;
    const d = await r.json();
    const imgs = d.images || [];
    if (imgs.length === 0) {
      if (section) section.style.display = 'none';
      if (emptyEl) emptyEl.style.display = 'block';
      return;
    }
    if (section) section.style.display = '';
    if (emptyEl) emptyEl.style.display = 'none';
    grid.innerHTML = imgs.map(img => {
      const favClass = img.is_favorite ? 'active' : '';
      return `<div class="gen-gallery-item" id="cgrid-${img.id}" style="cursor:pointer"
                   onclick="openCrLightbox(${img.id}, '${esc(img.url)}', ${img.is_favorite ? 1 : 0})">
        <img src="${esc(img.url)}" loading="lazy" alt="">
        <button class="gen-gallery-fav ${favClass}" onclick="event.stopPropagation();crToggleFavInGrid(${img.id}, this)" title="お気に入り">♡</button>
      </div>`;
    }).join('');
  } catch(_) {}
}

// ─── ギャラリーライトボックス ───
let _lbCurrentId = null;
let _lbCurrentUrl = null;
let _lbCurrentFav = 0;

function openCrLightbox(id, url, isFav) {
  _lbCurrentId  = id;
  _lbCurrentUrl = url;
  _lbCurrentFav = isFav;
  document.getElementById('lb-img').src = url;
  const favBtn = document.getElementById('lb-fav-btn');
  if (favBtn) {
    favBtn.textContent = isFav ? '♥' : '♡';
    favBtn.classList.toggle('active', !!isFav);
  }
  document.getElementById('cr-lightbox').classList.add('open');
}

function closeCrLightbox() {
  document.getElementById('cr-lightbox').classList.remove('open');
}

function crLightboxBgClick(e) {
  if (e.target === document.getElementById('cr-lightbox')) closeCrLightbox();
}

function selectFromCrLightbox() {
  if (!_lbCurrentUrl) return;
  selectAvatarFromGallery(_lbCurrentUrl);
  closeCrLightbox();
  goToCreateStep2();
}

async function deleteFromCrLightbox() {
  if (!_lbCurrentId) return;
  showConfirm('この画像をギャラリーから削除しますか？', async () => {
    const r = await api('/generate/my-images/' + _lbCurrentId, { method: 'DELETE' });
    if (r.ok) {
      closeCrLightbox();
      await loadCreateGallery();
      showToast('削除しました', 'info');
    } else {
      showToast('削除に失敗しました', 'error');
    }
  }, '削除する');
}

async function toggleCrFavorite() {
  if (!_lbCurrentId) return;
  const r = await api('/generate/my-images/' + _lbCurrentId + '/favorite', { method: 'POST' });
  if (!r.ok) { showToast('更新に失敗しました', 'error'); return; }
  const d = await r.json();
  _lbCurrentFav = d.is_favorite;
  const favBtn = document.getElementById('lb-fav-btn');
  if (favBtn) {
    favBtn.textContent = d.is_favorite ? '♥' : '♡';
    favBtn.classList.toggle('active', !!d.is_favorite);
  }
  // グリッドのバッジも更新
  const gridFav = document.querySelector(`#cgrid-${_lbCurrentId} .gen-gallery-fav`);
  if (gridFav) {
    gridFav.classList.toggle('active', !!d.is_favorite);
  }
  // お気に入り変更でギャラリー並び順が変わるため再読み込み
  await loadCreateGallery();
}

async function crToggleFavInGrid(id, btnEl) {
  const r = await api('/generate/my-images/' + id + '/favorite', { method: 'POST' });
  if (!r.ok) { showToast('更新に失敗しました', 'error'); return; }
  const d = await r.json();
  btnEl.classList.toggle('active', !!d.is_favorite);
  await loadCreateGallery();
}

// ギャラリーからアバターを選択
function selectAvatarFromGallery(url) {
  document.getElementById('cr-avatar-url').value = url;
  const img = document.getElementById('avatar-preview-img');
  const placeholder = document.getElementById('avatar-preview-placeholder');
  const nameEl = document.getElementById('avatar-selected-name');
  const clearBtn = document.getElementById('btn-clear-avatar');
  if (img) { img.src = url; img.style.display = 'block'; }
  if (placeholder) placeholder.style.display = 'none';
  if (nameEl) nameEl.textContent = '✅ 画像を選択しました';
  if (clearBtn) clearBtn.style.display = 'inline-flex';
  // 選択中の枠を更新
  document.querySelectorAll('#cr-gallery-grid .gen-gallery-item').forEach(el => {
    const isSelected = el.querySelector('img')?.src === url || el.querySelector('img')?.src.endsWith(url.split('/').pop());
    el.style.outline = isSelected ? '2px solid var(--accent)' : '';
  });
}

// アバター選択解除
function clearAvatarSelection() {
  document.getElementById('cr-avatar-url').value = '';
  const img = document.getElementById('avatar-preview-img');
  const placeholder = document.getElementById('avatar-preview-placeholder');
  const nameEl = document.getElementById('avatar-selected-name');
  const clearBtn = document.getElementById('btn-clear-avatar');
  if (img) { img.src = ''; img.style.display = 'none'; }
  if (placeholder) placeholder.style.display = 'block';
  if (nameEl) nameEl.textContent = '未選択';
  if (clearBtn) clearBtn.style.display = 'none';
  document.querySelectorAll('#cr-gallery-grid .gen-gallery-item').forEach(el => el.style.outline = '');
}

// アバターURLプレビュー（hidden input 用、sessionStorageからの引き継ぎで使用）
function onAvatarUrlInput() {
  const url = document.getElementById('cr-avatar-url')?.value.trim();
  if (url) selectAvatarFromGallery(url);
  else clearAvatarSelection();
}

// STEP 1 → STEP 2
function goToCreateStep2() {
  document.getElementById('cr-step-1').style.display = 'none';
  document.getElementById('cr-step-2').style.display = 'block';
  document.getElementById('cr-step-ind-1').classList.remove('active');
  document.getElementById('cr-step-ind-1').classList.add('done');
  document.getElementById('cr-step-ind-2').classList.add('active');
  // タグ初期化（まだなら）
  initTagSelects();
  // 先頭にスクロール
  document.getElementById('create-form').scrollTop = 0;
  document.getElementById('cr-name').focus();
}

// STEP 2 → STEP 1
function backToCreateStep1() {
  document.getElementById('cr-step-2').style.display = 'none';
  document.getElementById('cr-step-1').style.display = 'block';
  document.getElementById('cr-step-ind-2').classList.remove('active');
  document.getElementById('cr-step-ind-1').classList.remove('done');
  document.getElementById('cr-step-ind-1').classList.add('active');
}

// === サイドバー ===
function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebar-overlay');
  const isOpen = sidebar.classList.toggle('open');
  overlay.style.display = isOpen ? 'block' : 'none';
}

function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sidebar-overlay').style.display = 'none';
}

// === ユーティリティ ===
function esc(s) {
  if (!s) return '';
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// 右下トースト通知（alert代替）
function showToast(msg, type = 'info', duration = 3000) {
  const container = document.getElementById('toast-container');
  if (!container) return;
  const el = document.createElement('div');
  el.className = `toast toast-${type}`;
  el.textContent = msg;
  container.appendChild(el);
  requestAnimationFrame(() => el.classList.add('show'));
  setTimeout(() => {
    el.classList.remove('show');
    setTimeout(() => el.remove(), 300);
  }, duration);
}

// 画面全体オーバーレイ確認ダイアログ（confirm代替）
function showConfirm(msg, onOk, okLabel = '実行', okClass = 'btn-danger') {
  const overlay = document.createElement('div');
  overlay.className = 'confirm-overlay';
  overlay.innerHTML = `
    <div class="confirm-box">
      <p>${esc(msg)}</p>
      <div class="confirm-actions">
        <button class="btn btn-ghost">キャンセル</button>
        <button class="btn ${okClass}">${esc(okLabel)}</button>
      </div>
    </div>`;
  document.body.appendChild(overlay);
  const [cancelBtn, okBtn] = overlay.querySelectorAll('button');
  cancelBtn.onclick = () => overlay.remove();
  okBtn.onclick = () => { overlay.remove(); onOk(); };
}

// インラインエラー表示
function showInlineError(errorId, msg, focusId) {
  const el = document.getElementById(errorId);
  if (!el) return;
  el.textContent = msg;
  el.classList.add('show');
  if (focusId) document.getElementById(focusId)?.focus();
}

// インラインエラーをクリア
function clearInlineError(errorId) {
  const el = document.getElementById(errorId.endsWith('-error') ? errorId : errorId + '-error');
  if (el) { el.textContent = ''; el.classList.remove('show'); }
}

// === テキストエリア自動リサイズ ===
document.addEventListener('DOMContentLoaded', () => {
  const ta = document.getElementById('chat-input');
  if (ta) {
    ta.addEventListener('input', () => {
      ta.style.height = 'auto';
      ta.style.height = Math.min(ta.scrollHeight, 120) + 'px';
    });
  }
});

// === 初期化 ===
(async () => {
  await initAuth();
  await loadHomeChars();
  await loadConversations();
})();
