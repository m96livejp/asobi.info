/* === aic.asobi.info フロントエンドJS === */

const API_BASE = '/api';
let currentUser = null;
let currentConversationId = null;
let currentCharacter = null;
let isStreaming = false;
let _convLoadId = 0;  // openConversation の世代管理（古いリクエスト無視用）
let _chatVisMode = 0; // 0=全表示, 1=フェード, 2=非表示
let _longPressTimer = null;

// TTS（VOICEVOX音声読み上げ）
let _ttsAvailable = false; // 現在の会話でTTSが利用可能か（tts_mode + ユーザーロール考慮）
let _ttsAudio = null;
let _ttsPlayingBtn = null;
let _ttsQueue = [];        // 再生キュー [{type:'se'|'voice', name?, style?, text?, styleId?}]
let _ttsPlaying = false;   // キュー再生中フラグ
let _ttsStopFlag = false;  // 停止リクエスト
let _ttsAutoPlay = false;  // 自動読み上げON/OFF
let _vvStyleMap = {};      // スタイル名→IDマップ（現在の会話のキャラから構築）
let _ttsCache = new Map(); // テキスト+styleId → Blob キャッシュ（会話ごとにリセット）

// キャラクター編集モード
let _editingCharId = null;
let _editReturnScreen = null; // 'char-detail' | 'chat' | null（編集後の戻り先）
let _editReturnCharId = null;

// キャラID → 会話IDマップ（カードから直接チャットへ飛ぶため）
let _charConvMap = {};

// コミュニティ用キャッシュ
let communityChars = [];
let communitySort = 'like';

const LOGIN_URL = 'https://asobi.info/oauth/aic-login.php?callback=' +
  encodeURIComponent('https://aic.asobi.info/api/auth/asobi/callback');

// === トークン永続化（localStorage + Cookie 二重保存） ===
function _getCookie(name) {
  const m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
  return m ? decodeURIComponent(m[1]) : '';
}
function _setCookie(name, value, days) {
  const d = new Date(); d.setTime(d.getTime() + days * 86400000);
  document.cookie = `${name}=${encodeURIComponent(value)};path=/;expires=${d.toUTCString()};SameSite=Lax;Secure`;
}
function _removeCookie(name) {
  document.cookie = `${name}=;path=/;expires=Thu, 01 Jan 1970 00:00:00 GMT;SameSite=Lax;Secure`;
}
function saveToken(t) {
  token = t;
  localStorage.setItem('aic_token', t);
  _setCookie('aic_token', t, 30);
}
function clearToken() {
  token = '';
  localStorage.removeItem('aic_token');
  _removeCookie('aic_token');
}
// localStorage優先、なければCookieから復元
let token = localStorage.getItem('aic_token') || _getCookie('aic_token') || '';
if (token && !localStorage.getItem('aic_token')) {
  localStorage.setItem('aic_token', token); // Cookieから復元
}

// === API呼び出し ===
async function api(path, opts = {}) {
  const headers = { 'Content-Type': 'application/json' };
  if (token) headers['Authorization'] = 'Bearer ' + token;
  const res = await fetch(API_BASE + path, { ...opts, headers });
  if (res.status === 401) { clearToken(); currentUser = null; }
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
    clearToken();
  }

  // asobi.info のセッションを確認（ログアウト後のみスキップ）
  if (!sessionStorage.getItem('aic_skip_auto_login')) {
    try {
      const meRes = await fetch('https://asobi.info/assets/php/me.php', { credentials: 'include' });
      if (meRes.ok) {
        const me = await meRes.json();
        if (me.loggedIn) {
          // asobi.info でログイン済み → AIC へ自動ログイン
          window.location.replace(LOGIN_URL);
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
    currentUser = data.user;
    saveToken(data.token);
    const balRes = await api('/balance');
    if (balRes.ok) updateBalance(await balRes.json());
  }
  // ゲスト成功・失敗どちらでもUI更新（未ログイン時はログインリンク表示）
  updateUserInfo();
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
}

function renderHeaderUser() {
  const area = document.getElementById('header-user-area');
  const chatArea = document.getElementById('chat-header-user');
  if (!area) return;

  const loginSvg = '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>';

  if (!currentUser || currentUser.is_guest) {
    area.innerHTML = `<a href="${LOGIN_URL}" class="header-btn-login">${loginSvg} ログイン</a>`;
    if (chatArea) chatArea.innerHTML = `<a href="${LOGIN_URL}">${loginSvg}</a>`;
    return;
  }

  const avatarHtml = currentUser.avatar_url
    ? `<img src="${esc(currentUser.avatar_url)}" alt="">`
    : esc((currentUser.display_name || '?')[0].toUpperCase());

  let menuItems = '';
  // サイト固有
  menuItems += '<button class="hud-item" onclick="navTo(\'home\'); closeUserMenu()">サイトに戻る</button>';
  menuItems += '<button class="hud-item" onclick="navTo(\'profile\'); closeUserMenu()">プロフィール</button>';
  if (currentUser.role === 'admin') {
    menuItems += '<a class="hud-item" href="/admin.html">🔒 コンテンツ管理</a>';
  }
  menuItems += '<div class="hud-divider"></div>';
  // asobi.info 共通
  menuItems += '<a class="hud-item" href="https://asobi.info/">asobi.info TOP</a>';
  if (currentUser.role === 'admin') {
    menuItems += '<a class="hud-item" href="https://asobi.info/admin/">🔒 asobi.info 管理</a>';
  }

  area.innerHTML = `
    <button class="hdr-user-trigger" onclick="toggleUserMenu(event)">
      <div class="hdr-user-avatar">${avatarHtml}</div>
    </button>
    <div class="hdr-user-dropdown">
      ${menuItems}
    </div>`;

  // チャットヘッダーにもドロップダウン付きユーザーアイコン表示（メインと同じ構造）
  if (chatArea) {
    chatArea.innerHTML = `
      <button class="hdr-user-trigger" onclick="toggleChatUserMenu(event)">
        <div class="hdr-user-avatar">${avatarHtml}</div>
      </button>
      <div class="hdr-user-dropdown" id="chat-user-dropdown">
        ${menuItems}
      </div>`;
  }
}

function toggleUserMenu(e) {
  e.stopPropagation();
  document.getElementById('header-user-area').classList.toggle('open');
}

function toggleChatUserMenu(e) {
  e.stopPropagation();
  document.getElementById('chat-header-user').classList.toggle('open');
}

function closeUserMenu() {
  document.getElementById('header-user-area').classList.remove('open');
  const chatMenu = document.getElementById('chat-header-user');
  if (chatMenu) chatMenu.classList.remove('open');
}

// クリック外でドロップダウンを閉じる
document.addEventListener('click', () => closeUserMenu());

function updateBalance(bal) {
  const el = document.getElementById('balance-info');
  if (el) el.textContent = '💎 ' + bal.points + 'pt / ' + bal.crystals + 'クリスタル';
}

// === ナビゲーション ===

function showScreen(id) {
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  document.getElementById('screen-' + id).classList.add('active');

  // チャット画面ではヘッダー・フッターを非表示
  document.body.classList.toggle('chat-active', id === 'chat');

  // ボトムナビのアクティブ状態を更新
  const navScreen = id === 'chat' ? 'chat-list' : id;
  document.querySelectorAll('.bnav-item').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.screen === navScreen);
  });

}

let _navReplace = false;  // replaceState モード（初回ロード・popstate用）

function navTo(screen) {
  // URL ハッシュを更新（ブラウザバック・リロード対応）
  // chat は会話IDを持たないため chat-list として記録
  const hashTarget = screen === 'chat' ? 'chat-list' : screen;
  if (location.hash !== '#' + hashTarget) {
    if (_navReplace) {
      history.replaceState(null, '', '#' + hashTarget);
    } else {
      history.pushState(null, '', '#' + hashTarget);
    }
  }

  if (screen === 'create') {
    // 編集モードリセット
    _editingCharId = null;
    const saveBtn = document.getElementById('cr-save-btn');
    if (saveBtn) saveBtn.textContent = '作成する';
    const backBtn = document.getElementById('cr-back-btn');
    if (backBtn) backBtn.style.display = '';

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

// 会話時間のフォーマット
function _formatChatTime(dateStr) {
  const d = new Date(dateStr);
  const now = new Date();
  const diff = now - d;
  const min = Math.floor(diff / 60000);
  if (min < 1) return 'たった今';
  if (min < 60) return min + '分前';
  const hr = Math.floor(min / 60);
  if (hr < 24) return hr + '時間前';
  const days = Math.floor(hr / 24);
  if (days < 7) return days + '日前';
  return (d.getMonth() + 1) + '/' + d.getDate();
}

// === チャットリスト ===
async function loadChatList() {
  const isGuest = !currentUser || currentUser.is_guest;

  // 未ログイン時はログイン案内
  const convsEl = document.getElementById('chatlist-convs');
  if (isGuest) {
    convsEl.innerHTML = `
      <div class="login-prompt" style="margin:40px 0 0;text-align:center">
        <h3 style="margin-bottom:8px;color:var(--text)">チャット</h3>
        <p>キャラクターとチャットするには<br>asobi.info アカウントでログインしてください</p>
        <a class="btn btn-primary" onclick="location.href=LOGIN_URL">asobi.info でログイン</a>
      </div>`;
    return;
  }

  // 会話履歴
  if (token) {
    const convRes = await api('/conversations');
    if (convRes.ok) {
      const convs = await convRes.json();
      convs.forEach(c => { if (c.character_id) _charConvMap[c.character_id] = c.id; });
      if (convs.length === 0) {
        convsEl.innerHTML = '<p class="chatlist-empty">まだ会話がありません</p>';
      } else {
        convsEl.innerHTML = convs.map(c => {
          const isDeleted = c.character_is_deleted || 0;
          const avatarHtml = c.character_avatar
            ? `<img src="${esc(c.character_avatar)}" alt="" style="${isDeleted ? 'opacity:0.4;filter:grayscale(1)' : ''}">`
            : esc(c.character_name ? c.character_name[0] : '?');
          const timeStr = c.updated_at ? _formatChatTime(c.updated_at) : '';
          const deletedBadge = isDeleted === 2
            ? ' <span style="font-size:0.7rem;color:var(--accent);opacity:0.8">削除済み</span>'
            : isDeleted === 1
              ? ' <span style="font-size:0.7rem;color:var(--sub);opacity:0.8">削除済み</span>'
              : '';
          return `<div class="chatlist-conv-item" data-conv-id="${c.id}" onclick="openConversation(${c.id})" style="${isDeleted ? 'opacity:0.7' : ''}">
            <div class="chatlist-conv-avatar">${avatarHtml}</div>
            <div class="chatlist-conv-info">
              <div class="chatlist-conv-top">
                <div class="chatlist-conv-name">${esc(c.character_name)}${deletedBadge}</div>
                <div class="chatlist-conv-time">${esc(timeStr)}</div>
              </div>
              <div class="chatlist-conv-preview">${esc(c.last_message || '会話を始める')}</div>
            </div>
          </div>`;
        }).join('');
        _attachConvListDeleteHandlers(convsEl);
      }
    }
  }

  // お気に入り + コミュニティキャラ
  if (!isGuest) {
    const likedRes = await api('/characters/liked');
    let liked = [];
    if (likedRes.ok) {
      liked = await likedRes.json();
      if (liked.length > 0) {
        document.getElementById('chatlist-liked-section').style.display = 'block';
        renderCharCards('chatlist-liked', liked);
      }
    }

    // コミュニティキャラクター（キャッシュ読み込み）
    if (communityChars.length === 0) {
      const pubRes = await api('/characters/public');
      if (pubRes.ok) communityChars = await pubRes.json();
    }
    const alreadyChatting = new Set(Object.values(_charConvMap).length > 0
      ? Object.keys(_charConvMap).map(Number) : []);
    const likedIds = new Set(liked.map(c => c.id));
    const filtered = communityChars.filter(c => !alreadyChatting.has(c.id) && !likedIds.has(c.id));
    for (let i = filtered.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [filtered[i], filtered[j]] = [filtered[j], filtered[i]];
    }
    const toShow = filtered.slice(0, 6);
    const commSec = document.getElementById('chatlist-community-section');
    if (commSec && toShow.length > 0) {
      commSec.style.display = 'block';
      renderCharCards('chatlist-community', toShow);
    }
  } else {
    // サンプルキャラを表示
    const sampleRes = await api('/characters/sample');
    if (sampleRes.ok) {
      const commSec = document.getElementById('chatlist-community-section');
      if (commSec) {
        commSec.style.display = 'block';
        document.querySelector('#chatlist-community-section h3').textContent = 'サンプルキャラクター';
        renderCharCards('chatlist-community', await sampleRes.json());
      }
    }
  }
}

function _attachConvListDeleteHandlers(container) {
  container.querySelectorAll('.chatlist-conv-item[data-conv-id]').forEach(el => {
    let _longTimer = null;
    const trigger = () => {
      const convId = parseInt(el.dataset.convId);
      const name = el.querySelector('.chatlist-conv-name')?.textContent || '会話';
      showConfirm(`「${name}」を削除しますか？`, async () => {
        const r = await api(`/conversations/${convId}`, { method: 'DELETE' });
        if (r.ok) { showToast('削除しました', 'success'); loadChatList(); loadConversations(); }
        else showToast('削除に失敗しました', 'error');
      }, '会話の削除');
    };
    el.addEventListener('contextmenu', e => { e.preventDefault(); trigger(); });
    el.addEventListener('touchstart', () => { _longTimer = setTimeout(trigger, 600); }, { passive: true });
    el.addEventListener('touchend', () => clearTimeout(_longTimer));
    el.addEventListener('touchmove', () => clearTimeout(_longTimer));
  });
}

// === プロフィール ===
function loadProfile() {
  const el = document.getElementById('profile-content');
  const isGuest = !currentUser || currentUser.is_guest;

  // 名前編集セクション（共通）
  const nameSection = `
    <div class="profile-section">
      <h3>表示名</h3>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="text" id="profile-name-input" class="search-input" style="margin-bottom:0;flex:1"
               value="${esc(currentUser ? currentUser.display_name : '')}" maxlength="30"
               placeholder="名前を入力...">
        <button class="btn btn-primary btn-sm" onclick="saveProfileName()">保存</button>
      </div>
      <p class="inline-error" id="profile-name-error"></p>
    </div>`;

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
      ${nameSection}
      <div class="profile-section">
        <h3>マイキャラクター</h3>
        <div id="profile-mine-chars" class="char-grid"></div>
        <button class="btn btn-outline" style="margin-top:12px" onclick="navTo('create')">＋ キャラクター作成</button>
      </div>
      <div class="profile-section" id="profile-public-section" style="display:none">
        <h3>会話中の公開キャラクター</h3>
        <div id="profile-public-chars" class="char-grid"></div>
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

    // 会話中の公開キャラクター
    api('/characters/chatting-public').then(r => r.ok && r.json()).then(chars => {
      if (chars && chars.length) {
        document.getElementById('profile-public-section').style.display = 'block';
        renderCharCards('profile-public-chars', chars);
      }
    });
  }
}

async function saveProfileName() {
  const input = document.getElementById('profile-name-input');
  const name = (input?.value || '').trim();
  if (!name) {
    showInlineError('profile-name-error', '名前を入力してください', 'profile-name-input');
    return;
  }
  clearInlineError('profile-name-error');
  try {
    const res = await api('/auth/profile', { method: 'PUT', body: JSON.stringify({ display_name: name }) });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      showInlineError('profile-name-error', err.detail || '保存に失敗しました');
      return;
    }
    const updated = await res.json();
    currentUser.display_name = updated.display_name;
    updateUserInfo();
    showToast('名前を保存しました', 'success');
    // プロフィールヘッダーも更新
    const nameEl = document.querySelector('.profile-name');
    if (nameEl) nameEl.textContent = updated.display_name;
    const avatarEl = document.querySelector('.profile-avatar');
    if (avatarEl) avatarEl.textContent = updated.display_name[0] || '?';
  } catch (e) {
    showInlineError('profile-name-error', '通信エラーが発生しました');
  }
}

function isProfileNameSet() {
  if (!currentUser) return false;
  const name = currentUser.display_name;
  return name && name !== 'ゲスト' && name.trim() !== '';
}

async function logout() {
  clearToken();
  currentUser = null;
  // ログアウト後は同一セッション内で自動ログインしない
  sessionStorage.setItem('aic_skip_auto_login', '1');
  closeUserMenu();
  await initAuth();
  navTo('home');
}

// === キャラクターカード描画 ===
// === キャラクター詳細画面 ===
let _charDetailId = null;

async function showCharDetail(charId) {
  _charDetailId = charId;
  showScreen('char-detail');
  const el = document.getElementById('char-detail-content');
  el.innerHTML = '<div class="loading" style="padding:60px 0"><div class="typing-dots"><span></span><span></span><span></span></div></div>';

  try {
    const res = await api('/characters/' + charId);
    if (!res.ok) throw new Error('取得失敗');
    const c = await res.json();

    // 削除済みキャラクターの場合
    if (c.is_deleted === 2) {
      el.innerHTML = `
        <div style="padding:60px 20px;text-align:center">
          <div style="font-size:2rem;margin-bottom:12px">⚠</div>
          <div style="font-size:1.1rem;font-weight:600;margin-bottom:8px">${esc(c.name)}</div>
          <div style="color:var(--text-secondary)">このキャラクターは規約違反のため管理者により削除されました。</div>
          <button class="btn" style="margin-top:24px" onclick="history.back()">戻る</button>
        </div>`;
      return;
    }
    if (c.is_deleted === 1 && !c.is_owner && !c.is_admin) {
      el.innerHTML = `
        <div style="padding:60px 20px;text-align:center">
          <div style="font-size:2rem;margin-bottom:12px">📦</div>
          <div style="font-size:1.1rem;font-weight:600;margin-bottom:8px">${esc(c.name)}</div>
          <div style="color:var(--text-secondary)">このキャラクターは作成者により削除されました。</div>
          <button class="btn" style="margin-top:24px" onclick="history.back()">戻る</button>
        </div>`;
      return;
    }

    const tags = [
      ...(c.genre_personality || []),
      ...(c.genre_story || []),
      ...(c.genre_char_type || []),
      ...(c.genre_era || []),
    ];
    const tagsHtml = tags.map(t => '<span class="cd-tag">' + esc(t) + '</span>').join('');

    const heroImg = c.avatar_url
      ? '<img src="' + esc(c.avatar_url) + '" alt="">'
      : '<div style="width:100%;height:100%;background:var(--bg-secondary);display:flex;align-items:center;justify-content:center;font-size:4rem;color:var(--text-secondary)">' + esc((c.char_name || c.name || '?')[0]) + '</div>';

    const charNameHtml = c.char_name
      ? '<div class="cd-char-name">' + esc(c.char_name) + (c.char_age ? '（' + esc(c.char_age) + '）' : '') + '</div>'
      : '';

    const profileHtml = c.profile
      ? '<div class="cd-profile">' + esc(c.profile) + '</div>'
      : '';

    const likedClass = c.liked ? ' active' : '';
    const likedText = c.liked ? '❤ いいね済み' : '🤍 いいね';

    const isLoggedIn = currentUser && !currentUser.is_guest;

    // 作者 or 管理者なら編集リンク
    const editHtml = (c.is_owner || c.is_admin)
      ? '<div style="text-align:center"><span class="cd-edit-link" onclick="editCharFromDetail(' + c.id + ')">✏️ キャラクター編集</span></div>'
      : '';

    el.innerHTML = `
      <div class="cd-hero">
        ${heroImg}
        <div class="cd-hero-gradient"></div>
        <button class="cd-hero-back" onclick="history.back()">‹</button>
      </div>
      <div class="cd-body">
        <div class="cd-name">${esc(c.name)}</div>
        ${charNameHtml}
        <div class="cd-stats">❤ ${c.like_count} 💬 ${c.use_count}</div>
        ${tagsHtml ? '<div class="cd-tags">' + tagsHtml + '</div>' : ''}
        ${profileHtml}
        <div class="cd-actions">
          <button class="btn cd-btn-chat" onclick="startChat(${c.id})">💬 このキャラクターとチャット</button>
          ${isLoggedIn
            ? ((!c.is_owner || c.is_admin) ? `
            <div class="cd-action-row">
              <button class="btn cd-btn-like${likedClass}" id="cd-like-btn" onclick="toggleCharLike(${c.id})">
                ${likedText}
              </button>
            </div>
            <button class="btn cd-btn-report" onclick="reportChar(${c.id})">⚠ 不正報告</button>
          ` : '')
            : `
            <a class="btn cd-btn-like" onclick="location.href=LOGIN_URL">ログインしていいね・報告</a>
          `}
        </div>
        ${editHtml}
      </div>`;
  } catch (e) {
    el.innerHTML = '<p style="color:var(--accent);text-align:center;padding:40px">' + esc(e.message) + '</p>';
  }
}

async function toggleCharLike(charId) {
  const res = await api('/characters/' + charId + '/like', { method: 'POST' });
  if (!res.ok) return;
  const d = await res.json();
  const btn = document.getElementById('cd-like-btn');
  if (btn) {
    btn.classList.toggle('active', d.liked);
    btn.innerHTML = d.liked ? '❤ いいね済み' : '🤍 いいね';
  }
}

function reportChar(charId) {
  const overlay = document.createElement('div');
  overlay.className = 'confirm-overlay';
  const inputStyle = 'width:100%;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:8px;color:var(--text);font-family:inherit;font-size:0.85rem;';
  overlay.innerHTML = `
    <div class="confirm-box">
      <p style="font-weight:600;margin-bottom:8px">不正報告</p>
      <p style="font-size:0.85rem;margin-bottom:12px">このキャラクターに問題がありますか？</p>
      <select id="report-category" style="${inputStyle}appearance:auto;margin-bottom:10px">
        <option value="">-- 報告カテゴリを選択 --</option>
        <option value="性的・公序良俗に反する内容">性的・公序良俗に反する内容</option>
        <option value="知的財産の侵害">知的財産の侵害</option>
        <option value="他人の差別に対する問題">他人の差別に対する問題</option>
        <option value="法律違反・犯罪行為の助長">法律違反・犯罪行為の助長</option>
        <option value="政治に関する内容">政治に関する内容</option>
        <option value="その他">その他</option>
      </select>
      <textarea id="report-reason" rows="3" style="${inputStyle}resize:vertical" placeholder="報告理由を具体的に記載..."></textarea>
      <div class="confirm-actions" style="margin-top:12px">
        <button class="btn btn-ghost" data-act="cancel">キャンセル</button>
        <button class="btn btn-danger" data-act="send">送信</button>
      </div>
    </div>`;
  document.body.appendChild(overlay);
  overlay.onclick = async e => {
    const act = e.target.dataset.act;
    if (act === 'cancel' || e.target === overlay) { overlay.remove(); return; }
    if (act === 'send') {
      const category = document.getElementById('report-category').value;
      const detail = document.getElementById('report-reason').value.trim();
      if (!category) { showToast('カテゴリを選択してください', 'err'); return; }
      const reason = category + (detail ? '\n' + detail : '');
      const r = await api('/characters/' + charId + '/report', { method: 'POST', body: JSON.stringify({ reason }) });
      overlay.remove();
      if (r.ok) showToast('報告を送信しました');
      else showToast('報告の送信に失敗しました', 'err');
    }
  };
}

function editCharFromDetail(charId) {
  _editReturnScreen = 'char-detail';
  _editReturnCharId = charId;
  editCharacter(charId);
}

function renderCharCards(containerId, chars) {
  const el = document.getElementById(containerId);
  if (!el) return;
  if (!chars.length) { el.innerHTML = '<p style="color:var(--text-secondary);font-size:0.85rem;grid-column:1/-1">まだありません</p>'; return; }
  el.innerHTML = chars.map(c => {
    const avatarInner = c.avatar_url
      ? '<img src="' + esc(c.avatar_url) + '" alt="">'
      : esc(c.char_name ? c.char_name[0] : c.name[0]);
    const tags = (c.genre_personality || []).slice(0, 2).map(t => '<span class="char-card-tag">' + t + '</span>').join('');
    const clickAction = _charConvMap[c.id]
      ? 'openConversation(' + _charConvMap[c.id] + ')'
      : 'showCharDetail(' + c.id + ')';
    return '<div class="char-card" onclick="' + clickAction + '">' +
      '<div class="char-card-avatar">' + avatarInner + '</div>' +
      '<div class="char-card-name">' + esc(c.name) + '</div>' +
      '<div class="char-card-desc">' + esc(c.profile || '') + '</div>' +
      '<div class="char-card-meta">' + tags + ' ❤ ' + c.like_count + ' 💬 ' + c.use_count + '</div>' +
      '</div>';
  }).join('');
}

// === 会話開始 ===
async function startChat(charId) {
  // 名前未設定の場合はプロフィール画面で設定を促す
  if (!isProfileNameSet()) {
    showToast('チャットを始める前に名前を設定してください', 'info');
    navTo('profile');
    setTimeout(() => document.getElementById('profile-name-input')?.focus(), 300);
    return;
  }
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
  const el = document.getElementById('conversation-list');
  const section = el?.closest('.nav-section-title, .sidebar-conv-section') || el?.parentElement;
  if (!currentUser || currentUser.is_guest) {
    if (el) el.innerHTML = '';
    if (section) section.style.display = 'none';
    return;
  }
  if (section) section.style.display = '';
  const res = await api('/conversations');
  if (!res.ok) return;
  const convs = (await res.json()).slice(0, 5);
  convs.forEach(c => { if (c.character_id) _charConvMap[c.character_id] = c.id; });
  el.innerHTML = convs.map(c => {
    const isDel = c.character_is_deleted || 0;
    const avInner = c.character_avatar
      ? '<img src="' + esc(c.character_avatar) + '" alt=""' + (isDel ? ' style="opacity:0.4;filter:grayscale(1)"' : '') + '>'
      : esc(c.character_name ? c.character_name[0] : '?');
    return '<div class="conv-item' + (c.id === currentConversationId ? ' active' : '') + '" onclick="openConversation(' + c.id + ')"' + (isDel ? ' style="opacity:0.7"' : '') + '>' +
      '<div class="conv-item-avatar">' + avInner + '</div>' +
      '<div class="conv-item-body">' +
        '<div class="conv-item-name">' + esc(c.character_name) + '</div>' +
        '<div class="conv-item-preview">' + esc(c.last_message || '会話を始める') + '</div>' +
      '</div>' +
    '</div>';
  }).join('');
}

// === 会話表示 ===
async function openConversation(convId) {
  currentConversationId = convId;
  const myLoadId = ++_convLoadId;  // このリクエストの世代ID

  // URLハッシュに会話IDを保存（リロード復元用）
  history.replaceState(null, '', '#chat/' + convId);

  // 先にチャット画面に切り替え（ローディング表示）
  showScreen('chat');
  const el = document.getElementById('chat-messages');
  el.innerHTML = '<div class="loading"><div class="typing-dots"><span></span><span></span><span></span></div></div>';

  const res = await api('/conversations/' + convId);
  // 画面切り替え済みで別の会話が開かれていたら無視
  if (myLoadId !== _convLoadId) return;
  if (!res.ok) return;
  const data = await res.json();
  if (myLoadId !== _convLoadId) return;

  currentCharacter = data.character;
  // TTS利用可否（サーバー判定結果 + キャラに音声設定があること）
  _ttsAvailable = !!(data.tts_available && currentCharacter?.voice_model);
  // スタイルマップ・キャッシュリセット
  _vvStyleMap = {};
  _ttsCache.clear();
  if (currentCharacter?.tts_styles) {
    const styles = Array.isArray(currentCharacter.tts_styles) ? currentCharacter.tts_styles : [];
    for (const s of styles) { if (s.name != null && s.id != null) _vvStyleMap[s.name] = s.id; }
  }
  // チャットメニューのTTSボタン表示切替
  const avBtn = document.getElementById('auto-voice-btn');
  if (avBtn) avBtn.style.display = _ttsAvailable ? '' : 'none';

  document.getElementById('header-title').textContent = currentCharacter ? currentCharacter.name : 'チャット';
  document.getElementById('chat-header-name').textContent = currentCharacter ? currentCharacter.name : 'チャット';

  // 背景にキャラクター画像（上半身が見えるよう上寄り配置）
  const chatScreen = document.getElementById('screen-chat');
  if (currentCharacter?.avatar_url) {
    chatScreen.style.backgroundImage = `url('${currentCharacter.avatar_url}')`;
    chatScreen.style.backgroundSize = 'cover';
    chatScreen.style.backgroundPosition = 'center 15%';
  } else {
    chatScreen.style.backgroundImage = '';
  }

  const inputArea = document.querySelector('.chat-input-area');
  const isDeleted = currentCharacter?.is_deleted || 0;


  if (isDeleted === 2) {
    // 管理者削除: 履歴非表示、削除メッセージのみ
    el.innerHTML = '<div class="chat-deleted-banner" style="padding:40px 20px;text-align:center;color:var(--sub)">'
      + '<div style="font-size:1.5rem;margin-bottom:12px">⚠</div>'
      + '<div style="font-weight:600;margin-bottom:6px">' + esc(currentCharacter.name) + '</div>'
      + '<div>このキャラクターは規約違反のため管理者により削除されました。</div>'
      + '</div>';
    inputArea.style.display = 'none';
  } else if (isDeleted === 1) {
    // 作成者削除: 履歴は見える、入力不可
    el.innerHTML = _renderMessages(data.messages);
    el.innerHTML += '<div class="chat-deleted-banner" style="padding:16px 20px;text-align:center;color:var(--sub);border-top:1px solid rgba(255,255,255,0.1)">'
      + 'このキャラクターは作成者により削除されました。新しいメッセージは送れません。'
      + '</div>';
    inputArea.style.display = 'none';
  } else {
    // 通常
    el.innerHTML = _renderMessages(data.messages);
    inputArea.style.display = '';
    // 自動読み上げ（最後のAIメッセージ）
    if (_ttsAutoPlay && _ttsAvailable) {
      setTimeout(ttsAutoPlayLast, 200);
    }
    // 最後がuserメッセージ（AIが未返答 or 失敗）なら再送UIを表示
    if (data.needs_retry) {
      const aiMsg = document.createElement('div');
      aiMsg.className = 'msg msg-ai';
      const active = data.messages.filter(m => !m.is_deleted);
      const lastUser = active.filter(m => m.role === 'user').pop();
      const retryBtn = document.createElement('button');
      retryBtn.className = 'retry-btn';
      retryBtn.textContent = '↻';
      retryBtn.onclick = () => {
        if (!lastUser || isStreaming) return;
        isStreaming = true;
        document.getElementById('send-btn').disabled = true;
        aiMsg.innerHTML = '<div class="msg-typing"><span class="msg-typing-dot"></span><span class="msg-typing-dot"></span><span class="msg-typing-dot"></span></div>';
        _sendRequest(lastUser.content, aiMsg, el);
      };
      aiMsg.appendChild(retryBtn);
      el.appendChild(_createAiRow(aiMsg));
    }
  }

  // 表示モードリセット & 長押し初期化
  el.classList.remove('msg-masked');
  el.style.removeProperty('-webkit-mask-size');
  el.style.removeProperty('mask-size');
  el.style.removeProperty('opacity');
  el.style.pointerEvents = '';
  _chatVisMode = 0;
  _updateChatHideBtn();
  initMsgLongPress();
  el.scrollTop = el.scrollHeight;

  if (!isDeleted) document.getElementById('chat-input').focus();
  loadConversations();
}

// === キャラクタープロフィール表示 ===
async function openCharProfile() {
  if (!currentCharacter) return;
  const overlay = document.getElementById('char-profile-overlay');
  const body = document.getElementById('char-profile-body');
  body.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
  overlay.classList.add('active');

  try {
    const res = await api('/characters/' + currentCharacter.id);
    if (!res.ok) throw new Error('取得失敗');
    const c = await res.json();

    const tags = [
      ...(c.genre_personality || []),
      ...(c.genre_story || []),
      ...(c.genre_char_type || []),
      ...(c.genre_era || []),
      ...(c.genre_base || []),
    ];
    const tagsHtml = tags.map(t => '<span class="cp-tag">' + esc(t) + '</span>').join('');

    const avatarHtml = c.avatar_url
      ? '<div class="cp-avatar"><img src="' + esc(c.avatar_url) + '" alt=""></div>'
      : '';

    let html = avatarHtml;
    html += '<div class="cp-name">' + esc(c.name) + '</div>';
    if (c.char_name) html += '<div class="cp-char-name">' + esc(c.char_name) + (c.char_age ? '（' + esc(c.char_age) + '）' : '') + '</div>';
    if (tagsHtml) html += '<div class="cp-tags">' + tagsHtml + '</div>';
    if (c.profile) html += '<div class="cp-section"><div class="cp-section-title">プロフィール</div><div class="cp-text">' + esc(c.profile) + '</div></div>';
    if (c.private_profile) html += '<div class="cp-section"><div class="cp-section-title">非公開プロフィール</div><div class="cp-text">' + esc(c.private_profile) + '</div></div>';
    if (c.first_message) html += '<div class="cp-section"><div class="cp-section-title">最初のメッセージ</div><div class="cp-text">' + esc(c.first_message) + '</div></div>';
    html += '<div class="cp-meta">❤ ' + c.like_count + ' 💬 ' + c.use_count + '</div>';
    if (c.is_owner || c.is_admin) {
      html += '<div style="margin-top:16px;text-align:center"><button class="btn btn-secondary btn-sm" onclick="editCharacter(' + c.id + ')">編集</button></div>';
    }

    body.innerHTML = html;
  } catch (e) {
    body.innerHTML = '<p style="color:var(--accent);text-align:center;padding:20px">' + esc(e.message) + '</p>';
  }
}

function closeCharProfile() {
  document.getElementById('char-profile-overlay').classList.remove('active');
}

async function editCharacter(charId) {
  closeCharProfile();
  const res = await api('/characters/' + charId);
  if (!res.ok) { showToast('キャラクター情報の取得に失敗しました', 'error'); return; }
  const c = await res.json();

  _editingCharId = charId;

  // フォームをeditモードで開く（ステップ2から）
  showScreen('create');
  document.getElementById('create-gate').style.display = 'none';
  document.getElementById('create-form').style.display = 'block';
  // ステップ2を直接表示（アバター選択スキップ）
  document.getElementById('cr-step-1').style.display = 'none';
  document.getElementById('cr-step-2').style.display = 'block';
  document.getElementById('cr-step-ind-1').className = 'cr-step done';
  document.getElementById('cr-step-ind-2').className = 'cr-step active';

  // ボタンラベル変更
  const saveBtn = document.getElementById('cr-save-btn');
  if (saveBtn) saveBtn.textContent = '保存する';
  const backBtn = document.getElementById('cr-back-btn');
  if (backBtn) backBtn.style.display = 'none';

  // アバターURL設定
  const avatarEl = document.getElementById('cr-avatar-url');
  if (avatarEl) avatarEl.value = c.avatar_url || '';

  // タグ初期化
  initTagSelects();

  // フォームに値をセット
  document.getElementById('cr-name').value = c.name || '';
  document.getElementById('cr-char-name').value = c.char_name || '';
  document.getElementById('cr-age').value = c.char_age || '';
  document.getElementById('cr-gender').value = c.gender || '';
  document.getElementById('cr-profile').value = c.profile || '';
  document.getElementById('cr-private').value = c.private_profile || '';
  document.getElementById('cr-first-msg').value = c.first_message || '';
  document.getElementById('cr-public').checked = c.is_public == 1;

  // キーワード
  const kwInputs = document.querySelectorAll('#cr-keywords-group .cr-kw-input');
  const kws = c.keywords || [];
  kwInputs.forEach((el, i) => { el.value = kws[i] || ''; });

  // タグ選択状態を復元
  const tagMap = {
    'cr-story': c.genre_story || [],
    'cr-chartype': c.genre_char_type || [],
    'cr-personality': c.genre_personality || [],
    'cr-era': c.genre_era || [],
    'cr-base': c.genre_base || [],
  };
  for (const [containerId, selected] of Object.entries(tagMap)) {
    document.querySelectorAll('#' + containerId + ' .tag-btn').forEach(btn => {
      btn.classList.toggle('active', selected.includes(btn.textContent));
    });
  }

  // 音声モデル復元（非同期: まずモデル一覧取得してから選択）
  if (c.voice_model) {
    loadVoiceModelsForForm(c.gender || '').then(() => {
      const sel = document.getElementById('cr-voice-model');
      if (sel) {
        sel.value = c.voice_model;
        // 手動でスタイルJSON設定
        const hidden = document.getElementById('cr-tts-styles-json');
        if (hidden) hidden.value = JSON.stringify(c.tts_styles || []);
        onCrVoiceModelChange();
      }
    });
  } else {
    loadVoiceModelsForForm(c.gender || '');
  }

  // エラークリア
  clearInlineError('cr-name-error');
  clearInlineError('cr-save-error');
  document.getElementById('create-form').scrollTop = 0;
}

// 入力フォーカス時: 非表示(2)なら半分フェード(1)に戻す
function onChatInputFocus() {
  if (_chatVisMode !== 2) return;
  const el = document.getElementById('chat-messages');
  if (!el) return;
  const isAdmin = currentUser && currentUser.role === 'admin';
  el.style.pointerEvents = '';
  el.style.removeProperty('opacity');
  el.classList.add('msg-masked');
  el.style.setProperty('-webkit-mask-size', '100% 100%');
  el.style.setProperty('mask-size', '100% 100%');
  _chatVisMode = 1;
  _updateChatHideBtn();
}

// === チャット表示切り替え（全表示↔半分フェード、2段階） ===
function toggleChatVisibility() {
  if (_chatVisMode === 2) { toggleChatHide(); return; } // 非表示中はクリックで全表示
  const el = document.getElementById('chat-messages');
  if (!el) return;
  if (_chatVisMode === 0) {
    // 全表示→半分フェード
    _chatVisMode = 1;
    el.classList.add('msg-masked');
    el.style.setProperty('-webkit-mask-size', '100% 250%');
    el.style.setProperty('mask-size', '100% 250%');
    el.offsetHeight;
    el.style.setProperty('-webkit-mask-size', '100% 100%');
    el.style.setProperty('mask-size', '100% 100%');
  } else {
    // 半分フェード→全表示
    _chatVisMode = 0;
    el.style.pointerEvents = '';
    el.style.setProperty('-webkit-mask-size', '100% 250%');
    el.style.setProperty('mask-size', '100% 250%');
    const onDone = () => {
      el.removeEventListener('transitionend', onDone);
      el.classList.remove('msg-masked');
      el.style.removeProperty('-webkit-mask-size');
      el.style.removeProperty('mask-size');
    };
    el.addEventListener('transitionend', onDone);
  }
}

// === チャット非表示トグル（メニューから） ===
function toggleChatHide() {
  _chatMenuOpen = false;
  document.getElementById('chat-menu-panel')?.classList.remove('open');
  const el = document.getElementById('chat-messages');
  if (!el) return;
  const isAdmin = currentUser && currentUser.role === 'admin';
  if (_chatVisMode === 2) {
    // 非表示→全表示
    _chatVisMode = 0;
    el.style.pointerEvents = '';
    el.style.removeProperty('opacity');
    el.classList.remove('msg-masked');
    el.style.removeProperty('-webkit-mask-size');
    el.style.removeProperty('mask-size');
  } else {
    // 全表示/半分→非表示
    _chatVisMode = 2;
    el.style.pointerEvents = 'none';
    if (isAdmin) {
      // 管理者: マスクでうっすら表示
      if (!el.classList.contains('msg-masked')) {
        el.classList.add('msg-masked');
        el.style.setProperty('-webkit-mask-size', '100% 100%');
        el.style.setProperty('mask-size', '100% 100%');
        el.offsetHeight;
      }
      el.style.setProperty('-webkit-mask-size', '100% 0%');
      el.style.setProperty('mask-size', '100% 0%');
    } else {
      // 一般ユーザー: 完全非表示
      el.classList.remove('msg-masked');
      el.style.removeProperty('-webkit-mask-size');
      el.style.removeProperty('mask-size');
      el.style.opacity = '0';
    }
  }
  _updateChatHideBtn();
}

function _updateChatHideBtn() {
  const btn = document.getElementById('chat-hide-btn');
  if (!btn) return;
  btn.textContent = _chatVisMode === 2 ? '💬 チャット表示' : '💬 チャット非表示';
}

// === TTS テキスト解析 ===
// [SE名]{スタイル:速度:ピッチ:抑揚:音量}テキスト 形式を解析してセグメント配列に変換
// voiceParams がない場合（旧形式 {スタイル}）は null
function parseMessageSegments(text) {
  const segments = [];
  const re = /(\[([^\]]*)\])|(\{([^}]+)\}([^[{]*))|([^[{]+)/g;
  let match;
  while ((match = re.exec(text)) !== null) {
    if (!match[0]) continue;
    if (match[1]) {
      const seName = match[2].trim();
      if (seName) segments.push({ type: 'se', name: seName });
    } else if (match[3]) {
      const styleRaw = match[4].trim();
      const content = match[5];
      // {スタイル:速度:ピッチ:抑揚:音量} 形式を解析
      const parts = styleRaw.split(':');
      const style = parts[0].trim();
      let voiceParams = null;
      if (parts.length === 5) {
        const [s, p, i, v] = parts.slice(1).map(n => parseInt(n, 10));
        if (!isNaN(s) && !isNaN(p) && !isNaN(i) && !isNaN(v)) {
          voiceParams = { speed: s, pitch: p, intonation: i, volume: v };
        }
      }
      if (content && content.trim()) segments.push({ type: 'voice', style, text: content, voiceParams });
    } else if (match[6]) {
      const content = match[6];
      if (content && content.trim()) segments.push({ type: 'voice', style: 'ノーマル', text: content, voiceParams: null });
    }
  }
  return segments;
}

// TTS/SEマーカーを除去して表示用テキストを返す
function getDisplayText(text) {
  if (!text) return '';
  return text
    .replace(/\[[^\]]*\]/g, '')   // 完結した [...]
    .replace(/\[[^\]]*$/g, '')    // ストリーミング中の未完成 [...
    .replace(/\{[^}]*\}/g, '')    // 完結した {...}
    .replace(/\{[^}]*$/g, '')     // ストリーミング中の未完成 {...
    .trim();
}

// === メッセージ長押し削除 ===
function _createAiRow(aiMsgEl) {
  return aiMsgEl;
}

function _renderMessages(messages) {
  const hasTts = _ttsAvailable;
  return messages.map(m => {
    const deleted = m.is_deleted ? ' msg-soft-deleted' : '';
    if (m.role === 'user')
      return '<div class="msg msg-user' + deleted + '" data-msg-id="' + m.id + '" data-deleted="' + (m.is_deleted||0) + '" data-raw="' + esc(m.content) + '">' + esc(m.content) + '</div>';
    const display = esc(getDisplayText(m.content));
    const ttsBtn = hasTts ? '<button class="tts-btn" onclick="ttsPlayFromBtn(this)" title="読み上げ">▶</button>' : '';
    return '<div class="msg msg-ai' + deleted + '" data-msg-id="' + m.id + '" data-deleted="' + (m.is_deleted||0) + '" data-raw="' + esc(m.content) + '">' + display + ttsBtn + '</div>';
  }).join('');
}

function initMsgLongPress() {
  const el = document.getElementById('chat-messages');
  if (!el || el._longPressInit) return;
  el._longPressInit = true;

  let timer = null;
  let startX = 0, startY = 0;

  function getMsgEl(e) {
    const t = e.target.closest('.msg[data-msg-id]');
    return t;
  }

  function cancelTimer() { clearTimeout(timer); timer = null; }

  el.addEventListener('touchstart', e => {
    const msgEl = getMsgEl(e);
    if (!msgEl) return;
    startX = e.touches[0].clientX;
    startY = e.touches[0].clientY;
    timer = setTimeout(() => {
      timer = null;
      const msgId = msgEl.dataset.msgId;
      if (!msgId) return;
      e.preventDefault();
      showMsgMenu(msgId, msgEl);
    }, 600);
  }, { passive: false });

  el.addEventListener('touchmove', e => {
    if (!timer) return;
    const dx = e.touches[0].clientX - startX;
    const dy = e.touches[0].clientY - startY;
    if (Math.abs(dx) > 10 || Math.abs(dy) > 10) cancelTimer();
  });
  el.addEventListener('touchend', cancelTimer);
  el.addEventListener('touchcancel', cancelTimer);

  // PC: 右クリックでも
  el.addEventListener('contextmenu', e => {
    const msgEl = getMsgEl(e);
    if (!msgEl) return;
    const msgId = msgEl.dataset.msgId;
    if (!msgId) return;
    e.preventDefault();
    showMsgMenu(msgId, msgEl);
  });
}

function showMsgMenu(msgId, msgEl) {
  // 既存メニューを閉じる
  document.getElementById('msg-ctx-menu')?.remove();

  const isAi = msgEl.classList.contains('msg-ai');
  // 最新のAIメッセージか判定
  const chatEl = document.getElementById('chat-messages');
  const lastAiEl = [...chatEl.querySelectorAll('.msg-ai[data-msg-id]')].pop();
  const canRegen = isAi && lastAiEl && lastAiEl === msgEl && !isStreaming;

  const isHidden = msgEl.dataset.deleted === '1';
  const menu = document.createElement('div');
  menu.id = 'msg-ctx-menu';
  menu.className = 'msg-ctx-menu';
  menu.innerHTML =
    (canRegen ? '<button class="msg-ctx-item" data-act="regen">再更新</button>' : '') +
    '<button class="msg-ctx-item" data-act="hide">' + (isHidden ? '元に戻す' : '会話の削除') + '</button>';

  // メッセージ要素の位置に合わせて表示
  const rect = msgEl.getBoundingClientRect();
  menu.style.position = 'fixed';
  menu.style.top = (rect.bottom + 6) + 'px';
  menu.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 160)) + 'px';
  document.body.appendChild(menu);

  // メニュー外クリックで閉じる
  const close = (e) => {
    if (!menu.contains(e.target)) { menu.remove(); document.removeEventListener('pointerdown', close, true); }
  };
  setTimeout(() => document.addEventListener('pointerdown', close, true), 10);

  menu.onclick = async e => {
    const act = e.target.dataset.act;
    if (!act) return;
    menu.remove();
    document.removeEventListener('pointerdown', close, true);

    if (act === 'hide') {
      const r = await api('/chat/' + currentConversationId + '/messages/' + msgId + '/hide', { method: 'PATCH' });
      if (r.ok) {
        const d = await r.json();
        msgEl.dataset.deleted = d.is_deleted;
        if (d.is_deleted) {
          msgEl.classList.add('msg-soft-deleted');
        } else {
          msgEl.classList.remove('msg-soft-deleted');
        }
      }
    } else if (act === 'regen') {
      // 最後のユーザーメッセージを取得して再送信
      const userMsgs = [...chatEl.querySelectorAll('.msg-user[data-msg-id]')];
      const lastUserEl = userMsgs[userMsgs.length - 1];
      if (!lastUserEl) return;
      const lastUserMsg = lastUserEl.textContent.trim();
      // AI吹き出しをリセット
      msgEl.innerHTML = '<div class="msg-typing"><span class="msg-typing-dot"></span><span class="msg-typing-dot"></span><span class="msg-typing-dot"></span></div>';
      // まず古いAIメッセージをDB削除
      await api('/chat/' + currentConversationId + '/messages/' + msgId, { method: 'DELETE' });
      // 再送信
      isStreaming = true;
      document.getElementById('send-btn').disabled = true;
      await _sendRequest(lastUserMsg, msgEl, chatEl);
    }
  };
}

// === メッセージ送信 ===
async function sendMessage() {
  const input = document.getElementById('chat-input');
  const msg = (input.textContent || '').trim();
  if (!msg || isStreaming || !currentConversationId) return;

  input.textContent = '';
  isStreaming = true;
  document.getElementById('send-btn').disabled = true;

  const chatEl = document.getElementById('chat-messages');

  // 1. AIリクエストを先行送信（await しない）
  const fetchPromise = fetch(API_BASE + '/chat/' + currentConversationId, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
    body: JSON.stringify({ message: msg }),
  });

  // 2. ユーザーメッセージをゆっくり表示
  const userMsgEl = document.createElement('div');
  userMsgEl.className = 'msg msg-user';
  chatEl.appendChild(userMsgEl);
  await _typeUserMsg(userMsgEl, msg, chatEl);

  // 3. AI typing indicator を追加
  const aiMsg = document.createElement('div');
  aiMsg.className = 'msg msg-ai';
  aiMsg.innerHTML = '<div class="msg-typing"><span class="msg-typing-dot"></span><span class="msg-typing-dot"></span><span class="msg-typing-dot"></span></div>';
  chatEl.appendChild(_createAiRow(aiMsg));
  chatEl.scrollTop = chatEl.scrollHeight;

  // 4. 先行fetch済みのリクエストを引き継いで処理
  await _sendRequest(msg, aiMsg, chatEl, fetchPromise);
}

// ユーザーメッセージを1文字ずつ表示するアニメーション
async function _typeUserMsg(el, text, chatEl) {
  const chars = [...text]; // Unicode（絵文字等）対応
  const totalMs = Math.min(3000, Math.max(400, chars.length * 250));
  const perChar = totalMs / chars.length;
  let displayed = '';
  for (const char of chars) {
    displayed += char;
    el.textContent = displayed;
    chatEl.scrollTop = chatEl.scrollHeight;
    await new Promise(r => setTimeout(r, perChar));
  }
  el.textContent = text; // 最終確定
}

async function _sendRequest(msg, aiMsg, chatEl, prefetch = null) {
  try {
    const res = await (prefetch || fetch(API_BASE + '/chat/' + currentConversationId, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
      body: JSON.stringify({ message: msg }),
    }));

    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      _showRetryError(aiMsg, chatEl, msg, esc(err.detail || '送信に失敗しました'));
      return;
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let aiText = '';
    aiMsg.innerHTML = '';

    // === ストリーミング早期TTS再生 ===
    let _sBuf = '';       // セグメント抽出用バッファ
    let _sQueue = [];     // {type:'audio',prom:Promise<Blob>}|{type:'se',name:string}
    let _sDone = false;   // ストリーム完了フラグ
    let _sWakeResolve = null;
    let _sLoopStarted = false;
    let _sTtsBtn = null;

    function _sSignal() {
      if (_sWakeResolve) { const r = _sWakeResolve; _sWakeResolve = null; r(); }
    }
    function _sWaitForMore() {
      return new Promise(r => { _sWakeResolve = r; });
    }
    // バッファからセグメントを抽出してキューに追加
    function _sExtract(isFinal) {
      let added = false;
      while (true) {
        const first = _sBuf.indexOf('{');
        if (first === -1) {
          // {style}なし: finalなら残り全体をデフォルトスタイルで再生
          if (isFinal && _sBuf.trim()) {
            const styleId = Object.values(_vvStyleMap)[0];
            if (styleId) {
              _sQueue.push({ type: 'audio', prom: _fetchTtsAudio(_sBuf.trim().slice(0, 300), styleId, null) });
              added = true;
            }
            _sBuf = '';
          }
          break;
        }
        const next = _sBuf.indexOf('{', first + 1);
        if (next === -1 && !isFinal) break; // 次の{が来るまで待機
        const end = isFinal ? _sBuf.length : next;
        const segs = parseMessageSegments(_sBuf.slice(0, end));
        _sBuf = _sBuf.slice(end);
        for (const s of segs) {
          if (s.type === 'voice' && s.text.trim()) {
            const sId = _vvStyleMap[s.style] ?? _vvStyleMap['ノーマル'] ?? Object.values(_vvStyleMap)[0];
            if (sId) {
              _sQueue.push({ type: 'audio', prom: _fetchTtsAudio(s.text.trim().slice(0, 300), sId, s.voiceParams) });
              added = true;
            }
          } else if (s.type === 'se' && s.name) {
            _sQueue.push({ type: 'se', name: s.name });
            added = true;
          }
        }
        if (isFinal) break;
      }
      if (added) _sSignal();
    }
    // 再生ループ（ストリームと並行動作）
    async function _sPlayLoop(btn) {
      let idx = 0;
      _ttsStopFlag = false;
      _ttsPlaying = true;
      _ttsPlayingBtn = btn;
      btn.innerHTML = '<span class="tts-dots"><b></b><b></b><b></b></span>';
      btn.classList.remove('tts-playing');
      btn.classList.add('tts-loading');
      btn.dataset.duration = '0';
      while (true) {
        if (_ttsStopFlag) break;
        if (idx < _sQueue.length) {
          const item = _sQueue[idx++];
          if (item.type === 'audio') {
            const blob = await item.prom;
            if (!blob || _ttsStopFlag) continue;
            await _playAudioBlob(blob);
          } else if (item.type === 'se') {
            await _playSe(item.name);
          }
        } else if (_sDone) {
          break;
        } else {
          await _sWaitForMore(); // 次のセグメントが来るまで待機
        }
      }
      _ttsPlaying = false;
      if (_ttsPlayingBtn === btn) {
        _ttsResetBtn(btn);
        _ttsPlayingBtn = null;
      }
    }

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
            // STATEタグ・TTSマーカー除去して表示（未完成タグも除去）
            const stripped = aiText
              .replace(/<<<STATE>>>[\s\S]*?<<\/STATE>>>/g, '')  // 完結したSTATEブロック
              .replace(/<<<STATE>>>[\s\S]*$/g, '')               // 未完成のSTATEブロック
              .replace(/<<<[A-Z]*$/g, '');                       // <<< 途中のタグ
            aiMsg.innerHTML = esc(getDisplayText(stripped));
            chatEl.scrollTop = chatEl.scrollHeight;

            // ストリーミング早期再生（自動再生ONの場合）
            if (_ttsAutoPlay && _ttsAvailable) {
              _sBuf += data.text;
              _sExtract(false);
              // 最初のセグメントがキューに入ったらループ開始
              if (!_sLoopStarted && _sQueue.length > 0) {
                _sLoopStarted = true;
                _sTtsBtn = document.createElement('button');
                _sTtsBtn.className = 'tts-btn';
                _sTtsBtn.title = '停止';
                _sTtsBtn.onclick = function() { ttsPlayFromBtn(this); };
                aiMsg.appendChild(_sTtsBtn);
                _sPlayLoop(_sTtsBtn); // awaitしない（並行実行）
              }
            }
          }
          if (data.done) {
            // raw テキストを保存（STATEタグを除去）
            const rawText = aiText
              .replace(/<<<STATE>>>[\s\S]*?<<\/STATE>>>/g, '').replace(/<<<STATE>>>[\s\S]*$/g, '')
              .trim();
            aiMsg.dataset.raw = rawText;

            if (_ttsAvailable) {
              if (_ttsAutoPlay) {
                // 残りバッファを処理してループを終了
                _sExtract(true);
                _sDone = true;
                _sSignal();
                if (!_sLoopStarted) {
                  // {スタイル}形式なし or キューが空 → 通常の自動再生
                  if (_sQueue.length > 0) {
                    // 残りバッファからのみセグメントが取れた場合、ループを起動
                    _sLoopStarted = true;
                    _sTtsBtn = document.createElement('button');
                    _sTtsBtn.className = 'tts-btn';
                    _sTtsBtn.title = '停止';
                    _sTtsBtn.onclick = function() { ttsPlayFromBtn(this); };
                    aiMsg.appendChild(_sTtsBtn);
                    _sPlayLoop(_sTtsBtn);
                  } else {
                    // 完全フォールバック: rawTextをそのまま再生
                    const ttsBtn = document.createElement('button');
                    ttsBtn.className = 'tts-btn';
                    ttsBtn.textContent = '▶';
                    ttsBtn.title = '読み上げ';
                    ttsBtn.onclick = function() { ttsPlayFromBtn(this); };
                    aiMsg.appendChild(ttsBtn);
                    ttsPlayFromBtn(ttsBtn);
                  }
                }
                // ループ起動済みの場合は_sDone+_sSignalで自動終了
              } else {
                // 自動再生OFF → ボタンのみ追加
                const ttsBtn = document.createElement('button');
                ttsBtn.className = 'tts-btn';
                ttsBtn.textContent = '▶';
                ttsBtn.title = '読み上げ';
                ttsBtn.onclick = function() { ttsPlayFromBtn(this); };
                aiMsg.appendChild(ttsBtn);
              }
            }

            const balRes = await api('/balance');
            if (balRes.ok) updateBalance(await balRes.json());
          }
          if (data.error) {
            const errSpan = document.createElement('span');
            errSpan.style.cssText = 'display:inline-flex;align-items:center;gap:6px;color:var(--accent);margin-top:6px';
            errSpan.innerHTML = '<br>' + esc(data.error) + ' <button onclick="this.parentElement.remove()" style="background:none;border:none;color:var(--sub);cursor:pointer;font-size:1rem;padding:0;line-height:1" title="閉じる">×</button>';
            aiMsg.appendChild(errSpan);
          }
        } catch (e) {}
      }
    }
  } catch (e) {
    _showRetryError(aiMsg, chatEl, msg, '通信エラーが発生しました');
  } finally {
    isStreaming = false;
    document.getElementById('send-btn').disabled = false;
    loadConversations();
  }
}

function _showRetryError(aiMsg, chatEl, msg, errText) {
  aiMsg.innerHTML = '<div class="msg-ai-name">エラー</div>' + errText;
  const retryBtn = document.createElement('button');
  retryBtn.className = 'retry-btn';
  retryBtn.textContent = '↻';
  retryBtn.onclick = () => {
    isStreaming = true;
    document.getElementById('send-btn').disabled = true;
    aiMsg.innerHTML = '<div class="msg-typing"><span class="msg-typing-dot"></span><span class="msg-typing-dot"></span><span class="msg-typing-dot"></span></div>';
    _sendRequest(msg, aiMsg, chatEl);
  };
  aiMsg.appendChild(document.createElement('br'));
  aiMsg.appendChild(retryBtn);
}

// === キャラ作成 ===
// === キャラクター作成フォーム: 音声モデル ===
async function loadVoiceModelsForForm(gender) {
  const sel = document.getElementById('cr-voice-model');
  if (!sel) return;
  const params = gender ? '?gender=' + encodeURIComponent(gender) : '';
  try {
    const res = await api('/tts/voice-models' + params);
    if (!res.ok) return;
    const models = await res.json();
    sel.innerHTML = '<option value="">音声なし</option>' +
      models.map(m => '<option value="' + esc(m.speaker_uuid) + '" data-styles="' + esc(JSON.stringify(m.styles)) + '">' + esc(m.display_name) + '</option>').join('');
  } catch (_) {}
}

function onCrVoiceModelChange() {
  const sel = document.getElementById('cr-voice-model');
  const preview = document.getElementById('cr-tts-styles-preview');
  const hidden = document.getElementById('cr-tts-styles-json');
  if (!sel || !preview || !hidden) return;
  const opt = sel.selectedOptions[0];
  if (!opt || !opt.value) {
    preview.textContent = '';
    hidden.value = '[]';
    return;
  }
  const styles = JSON.parse(opt.dataset.styles || '[]');
  hidden.value = JSON.stringify(styles);
  preview.textContent = 'スタイル: ' + (styles.map(s => s.name).join('、') || 'ノーマル');
}

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
    gender: document.getElementById('cr-gender').value || null,
    profile: document.getElementById('cr-profile').value.trim() || null,
    private_profile: document.getElementById('cr-private').value.trim() || null,
    first_message: document.getElementById('cr-first-msg').value.trim() || null,
    genre_story: getSelectedTags('cr-story'),
    genre_char_type: getSelectedTags('cr-chartype'),
    genre_personality: getSelectedTags('cr-personality'),
    genre_era: getSelectedTags('cr-era'),
    genre_base: getSelectedTags('cr-base'),
    keywords: Array.from(document.querySelectorAll('#cr-keywords-group .cr-kw-input')).map(el => el.value.trim()).filter(s => s).slice(0, 5),
    is_public: document.getElementById('cr-public').checked ? 1 : 0,
    avatar_url: document.getElementById('cr-avatar-url')?.value || null,
    voice_model: document.getElementById('cr-voice-model')?.value || null,
    tts_styles: JSON.parse(document.getElementById('cr-tts-styles-json')?.value || '[]'),
  };

  let res;
  if (_editingCharId) {
    res = await api('/characters/' + _editingCharId, { method: 'PUT', body: JSON.stringify(body) });
  } else {
    res = await api('/characters', { method: 'POST', body: JSON.stringify(body) });
  }
  if (res.ok) {
    communityChars = []; // キャッシュクリア
    const updatedId = _editingCharId;
    showToast(updatedId ? 'キャラクターを更新しました' : 'キャラクターを作成しました', 'success');
    _editingCharId = null;
    if (_editReturnScreen === 'char-detail' && updatedId) {
      _editReturnScreen = null;
      _editReturnCharId = null;
      showCharDetail(updatedId);
    } else if (_editReturnScreen === 'chat') {
      _editReturnScreen = null;
      showScreen('chat');
    } else {
      navTo('profile');
    }
  } else {
    const err = await res.json().catch(() => ({}));
    showInlineError('cr-save-error', err.detail || (_editingCharId ? '更新に失敗しました' : '作成に失敗しました'));
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
      // 生成画像の縦横比をCSS変数に反映
      if (d.width && d.height) {
        document.documentElement.style.setProperty('--gen-img-ratio', `${d.width}/${d.height}`);
      }
    }
  } catch (_) {}
}

// 画像生成画面を開く
async function openGenerateScreen() {
  selectedGenIds = new Set();
  await Promise.all([loadGenTemplates(), loadSelectableModels(), loadSdStatus()]);
  await loadPendingImages();
}

// SD ステータス取得
async function loadSdStatus() {
  try {
    const r = await api('/generate/sd-status');
    if (!r.ok) return;
    const d = await r.json();
    sdEnabled = d.enabled;
    const sec = document.getElementById('avatar-gen-section');
    if (sec) sec.style.display = sdEnabled ? 'block' : 'none';
    if (d.width && d.height) {
      document.documentElement.style.setProperty('--gen-img-ratio', `${d.width}/${d.height}`);
    }
  } catch(_) {}
}

// 選択可能モデル読み込み
async function loadSelectableModels() {
  try {
    const r = await api('/generate/selectable-models');
    if (!r.ok) return;
    const models = await r.json();
    const group = document.getElementById('gen-model-group');
    const el    = document.getElementById('gen-models');
    if (!group || !el) return;
    if (!models.length) { group.style.display = 'none'; return; }
    group.style.display = 'block';
    el.innerHTML = models.map(m =>
      `<button class="gen-type-btn" data-model-id="${m.id}" onclick="selectGenModel(this)">${esc(m.display_name)}</button>`
    ).join('');
  } catch(_) {}
}

// モデル選択
function selectGenModel(btn) {
  document.querySelectorAll('#gen-models .gen-type-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
}

// 選択中モデルIDを取得
function getSelectedModelId() {
  const active = document.querySelector('#gen-models .gen-type-btn.active');
  return active ? parseInt(active.dataset.modelId) : null;
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
        const wasActive = this.classList.contains('active');
        el.querySelectorAll('.gen-tmpl-btn').forEach(b => b.classList.remove('active'));
        if (wasActive) {
          // 再クリックで解除
          el.dataset.selectedId = '';
          return;
        }
        this.classList.add('active');
        const idx = parseInt(this.dataset.idx);
        const t = el._templates[idx];
        // template_id を記録（プロンプト欄には入れない）
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
let _genImgRatings = {};  // id -> rating のキャッシュ
function renderGenResults(imgs) {
  const wrap = document.getElementById('gen-results-wrap');
  const grid = document.getElementById('gen-results-grid');
  if (!wrap || !grid) return;
  selectedGenIds = new Set();
  _genImgRatings = {};
  imgs.forEach(img => _genImgRatings[img.id] = img.rating);
  grid.innerHTML = imgs.map(img =>
    `<div class="gen-img-card" id="gcard-${img.id}" data-url="${esc(img.url)}" data-id="${img.id}" onclick="openGenFullscreen(${img.id})">
      <img src="${esc(img.url)}" loading="lazy" alt="">
      <div class="gen-img-select" onclick="event.stopPropagation();selectGenImage(${img.id})">✓</div>
    </div>`
  ).join('');
  wrap.style.display = 'block';
}

// すべて選択 / すべて解除トグル
function selectAllPending() {
  const cards = document.querySelectorAll('.gen-img-card');
  const btn = document.getElementById('btn-select-all');
  const allSelected = cards.length > 0 && cards.length === selectedGenIds.size;
  if (allSelected) {
    selectedGenIds.clear();
    cards.forEach(card => card.classList.remove('selected'));
    if (btn) btn.textContent = '☐ すべて選択';
  } else {
    cards.forEach(card => {
      const id = parseInt(card.dataset.id);
      if (id) { selectedGenIds.add(id); card.classList.add('selected'); }
    });
    if (btn) btn.textContent = '☑ すべて選択';
  }
}

// 画像を選択/解除（右下○タップ）
function selectGenImage(id) {
  if (selectedGenIds.has(id)) {
    selectedGenIds.delete(id);
    document.getElementById('gcard-' + id)?.classList.remove('selected');
  } else {
    selectedGenIds.add(id);
    document.getElementById('gcard-' + id)?.classList.add('selected');
  }
}

// フルスクリーン表示 + 評価UI
function openGenFullscreen(id) {
  const card = document.getElementById('gcard-' + id);
  if (!card) return;
  const url = card.dataset.url;
  const rating = _genImgRatings[id] || null;
  const overlay = document.createElement('div');
  overlay.className = 'gen-fullscreen';
  overlay.innerHTML = `
    <div class="gen-fs-img-wrap">
      <img src="${esc(url)}" alt="" onclick="this.closest('.gen-fullscreen').remove()">
      <div class="gen-fs-rating" data-id="${id}">
        <button class="gen-rate-btn ${rating === -1 ? 'active-neg' : ''}" onclick="event.stopPropagation();rateGenImage(${id},-1,this)">👎</button>
        <button class="gen-rate-btn ${rating === 1 ? 'active-1' : ''}" onclick="event.stopPropagation();rateGenImage(${id},1,this)">いまいち</button>
        <button class="gen-rate-btn ${rating === 2 ? 'active-2' : ''}" onclick="event.stopPropagation();rateGenImage(${id},2,this)">良い</button>
        <button class="gen-rate-btn ${rating === 3 ? 'active-3' : ''}" onclick="event.stopPropagation();rateGenImage(${id},3,this)">凄く良い</button>
      </div>
    </div>`;
  document.body.appendChild(overlay);
}

// 画像評価
async function rateGenImage(id, rating, btnEl) {
  const bar = btnEl.parentElement;
  if (!bar) return;
  const overlay = bar.closest('.gen-fullscreen');

  // マイナス評価の場合はフィードバックポップアップを表示
  if (rating === -1) {
    openNegFeedback(id, overlay);
    return;
  }

  // 評価バーを「ありがとう」に置き換え
  bar.outerHTML = '<div class="gen-fs-thanks">ご意見ありがとうございました</div>';

  try {
    await api('/generate/rate/' + id, {
      method: 'POST',
      body: JSON.stringify({ rating }),
    });
    _genImgRatings[id] = rating;
  } catch(_) {}
}

// マイナス評価フィードバックポップアップ
let _negFbFsOverlay = null;  // フィードバック送信後にフルスクリーンを閉じるための参照

function openNegFeedback(imageId, fsOverlay) {
  _negFbFsOverlay = fsOverlay;
  const reasons = ['顔や表情の魅力がない','スタイルの魅力がない','指定内容と不一致','不自然な骨格や体勢','その他'];
  const overlay = document.createElement('div');
  overlay.className = 'gen-fb-overlay';
  overlay.onclick = e => { if (e.target === overlay) overlay.remove(); };
  overlay.innerHTML = `
    <div class="gen-fb-popup">
      <h4>👎 気になった点を教えてください</h4>
      ${reasons.map((r, i) => `
        <label class="gen-fb-reason">
          <input type="checkbox" value="${esc(r)}" id="fb-r-${i}">
          <span>${esc(r)}</span>
        </label>`).join('')}
      <textarea class="gen-fb-comment" id="fb-comment" rows="3" placeholder="コメント（任意）"></textarea>
      <div class="gen-fb-actions">
        <button class="btn btn-ghost" onclick="this.closest('.gen-fb-overlay').remove()">キャンセル</button>
        <button class="btn btn-primary" onclick="submitNegFeedback(${imageId},this)">送信</button>
      </div>
    </div>`;
  document.body.appendChild(overlay);
}

async function submitNegFeedback(imageId, btnEl) {
  const overlay = btnEl.closest('.gen-fb-overlay');
  const checked = [...overlay.querySelectorAll('input[type=checkbox]:checked')].map(c => c.value);
  const comment = overlay.querySelector('#fb-comment')?.value.trim() || null;

  btnEl.disabled = true;
  btnEl.textContent = '送信中...';
  try {
    await api('/generate/rate/' + imageId, {
      method: 'POST',
      body: JSON.stringify({ rating: -1 }),
    });
    await api('/generate/feedback/' + imageId, {
      method: 'POST',
      body: JSON.stringify({ reasons: checked, comment }),
    });
    _genImgRatings[imageId] = -1;
    overlay.remove();
    // フルスクリーンに「ありがとう」を表示して閉じる
    const fs = _negFbFsOverlay;
    if (fs) {
      const ratingEl = fs.querySelector('.gen-fs-rating');
      if (ratingEl) ratingEl.outerHTML = '<div class="gen-fs-thanks">ご意見ありがとうございました</div>';
      setTimeout(() => fs.remove(), 1200);
    }
    _negFbFsOverlay = null;
  } catch(_) {
    btnEl.disabled = false;
    btnEl.textContent = '送信';
  }
}

// テンプレートプロンプトから性別キーワードを除去
function stripGenderKeywords(prompt) {
  const genderTags = ['1girl', '1boy', '1woman', '1man', '2girls', '2boys', 'multiple girls', 'multiple boys'];
  let result = prompt;
  for (const tag of genderTags) {
    // カンマ区切りを考慮して前後のスペース・カンマごと除去
    result = result.replace(new RegExp(`(^|,\\s*)${tag}(\\s*,|$)`, 'gi'), (_, pre, post) => {
      if (pre && post) return ', ';
      return '';
    });
  }
  return result.replace(/^[\s,]+|[\s,]+$/g, '').replace(/,\s*,/g, ',').trim();
}

// キャラクタータイプ選択
let _lastCharType = '';  // 最後に選択されたキャラタイプ（性別自動入力用）
function selectCharType(btn) {
  document.querySelectorAll('#gen-char-type .gen-type-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  _lastCharType = btn.dataset.type || '';
}

// 選択中のキャラクタータイプキーワードを取得
function getCharTypeKeyword() {
  const active = document.querySelector('#gen-char-type .gen-type-btn.active');
  return active ? active.dataset.keyword : '';
}

// キュー状態ポーリング
let _genPollTimer = null;
let _genCurrentJobId = null;

async function _startJobPolling(jobId, statusEl) {
  _stopQueuePolling();
  _genCurrentJobId = jobId;
  const poll = async () => {
    try {
      const r = await api('/generate/queue-status');
      if (!r.ok) return;
      const d = await r.json();
      if (!statusEl) return;
      if (d.is_stopped) {
        statusEl.innerHTML = `❌ キューが停止しています: ${esc(d.stop_reason)}<br>
          <button class="btn btn-sm btn-primary" style="margin-top:6px" onclick="resumeQueue()">再送する</button>`;
        return;
      }
      // 自分のジョブの状態を確認
      const jr = await api('/generate/queue/' + _genCurrentJobId);
      if (!jr.ok) return;
      const job = await jr.json();

      if (job.status === 'completed') {
        _stopQueuePolling();
        statusEl.style.display = 'none';
        const btn = document.getElementById('btn-gen-6');
        if (btn) { btn.disabled = false; btn.textContent = '✨ 画像を生成する'; }
        await loadPendingImages();
        return;
      }
      if (job.status === 'failed') {
        _stopQueuePolling();
        statusEl.textContent = '❌ ' + (job.error_message || '生成に失敗しました');
        const btn = document.getElementById('btn-gen-6');
        if (btn) { btn.disabled = false; btn.textContent = '✨ 画像を生成する'; }
        return;
      }
      if (job.status === 'cancelled') {
        _stopQueuePolling();
        statusEl.style.display = 'none';
        const btn = document.getElementById('btn-gen-6');
        if (btn) { btn.disabled = false; btn.textContent = '✨ 画像を生成する'; }
        return;
      }

      // pending or processing — 待ち表示
      if (d.my_position === 0 || job.status === 'processing') {
        statusEl.textContent = '⏳ 生成しています...';
      } else if (d.my_position !== null) {
        statusEl.textContent = `⏸ キュー ${d.my_position}番目`;
      } else {
        statusEl.textContent = `⏸ キュー待機中（${d.queue_length}件）`;
      }
    } catch(_) {}
  };
  await poll();
  _genPollTimer = setInterval(poll, 3000);
}

function _stopQueuePolling() {
  if (_genPollTimer) { clearInterval(_genPollTimer); _genPollTimer = null; }
  _genCurrentJobId = null;
}

// キュー再開（再送ボタン）
async function resumeQueue() {
  const r = await api('/generate/queue-resume', { method: 'POST' });
  if (r.ok) {
    showToast('キューを再開しました。再度生成してください。', 'info');
    const status = document.getElementById('gen-main-status');
    if (status) status.style.display = 'none';
    const btn = document.getElementById('btn-gen-6');
    if (btn) { btn.disabled = false; btn.textContent = '✨ 画像を生成する'; }
  } else {
    showToast('再開に失敗しました', 'error');
  }
}

// 画像生成
async function generateImages() {
  const basePrompt = document.getElementById('gen-main-prompt')?.value.trim();
  const tmplContainer = document.getElementById('gen-templates');
  const templateId = tmplContainer?.dataset.selectedId ? parseInt(tmplContainer.dataset.selectedId) : null;
  if (!basePrompt && !templateId) {
    showInlineError('gen-prompt-error', 'プロンプトを入力するか、テンプレートを選択してください', 'gen-main-prompt');
    return;
  }
  clearInlineError('gen-prompt-error');
  let tmplPrompt = '';
  if (templateId && tmplContainer._templates) {
    const tmpl = tmplContainer._templates.find(t => t.id === templateId);
    if (tmpl) tmplPrompt = stripGenderKeywords(tmpl.prompt);
  }

  // キャラクタータイプキーワード + テンプレートプロンプト + ユーザープロンプトを結合
  const typeKeyword = getCharTypeKeyword();
  const parts = [typeKeyword, tmplPrompt, basePrompt].filter(Boolean);
  const prompt = parts.join(', ');

  const btn = document.getElementById('btn-gen-6');
  const status = document.getElementById('gen-main-status');
  btn.disabled = true; btn.textContent = '⏳ キューに追加中...';
  if (status) { status.style.display = 'block'; status.textContent = '⏳ キューに追加しています...'; }

  try {
    const r = await api('/generate/image', {
      method: 'POST',
      body: JSON.stringify({ prompt, template_id: templateId, selected_model_id: getSelectedModelId() }),
    });
    if (!r.ok) {
      const err = await r.json().catch(() => ({}));
      const msg = err.detail || '生成に失敗しました';
      if (r.status === 503 && msg.includes('停止中')) {
        if (status) status.innerHTML = `❌ ${esc(msg)}<br>
          <button class="btn btn-sm btn-primary" style="margin-top:6px" onclick="resumeQueue()">再送する</button>`;
      } else {
        if (status) status.textContent = '❌ ' + msg;
      }
      btn.disabled = false; btn.textContent = '✨ 画像を生成する';
      return;
    }
    const d = await r.json();
    // キューに追加された → ジョブポーリング開始
    btn.textContent = '⏳ 生成待機中...';
    await _startJobPolling(d.job_id, status);
  } catch(e) {
    _stopQueuePolling();
    if (status) status.textContent = '❌ 通信エラー: ' + e.message;
    btn.disabled = false; btn.textContent = '✨ 画像を生成する';
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
  showToast(saved + '枚の画像をギャラリーに保存しました', 'success');
  // ギャラリー（キャラクター作成画面）に戻す
  navTo('create');
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
      `<div class="gen-gallery-item" id="ggrid-${img.id}" onclick="openGalleryLightbox(${img.id}, '${esc(img.url)}', ${img.is_favorite ? 1 : 0})">
        <img src="${esc(img.url)}" loading="lazy" alt="">
        <button class="gen-gallery-fav ${img.is_favorite ? 'active' : ''}" onclick="event.stopPropagation();galleryToggleFav(${img.id}, this)" title="お気に入り">♡</button>
      </div>`
    ).join('');
  } catch(_) {}
}

// ギャラリーのお気に入りトグル（グリッドから直接）
async function galleryToggleFav(id, btnEl) {
  const r = await api('/generate/my-images/' + id + '/favorite', { method: 'POST' });
  if (!r.ok) { showToast('更新に失敗しました', 'error'); return; }
  const d = await r.json();
  btnEl.classList.toggle('active', !!d.is_favorite);
  btnEl.textContent = '♡';
  await loadGallery();
}

// ギャラリーライトボックス（generate画面用）
let _glbCurrentId = null;
let _glbCurrentUrl = null;
let _glbCurrentFav = 0;

function openGalleryLightbox(id, url, isFav) {
  _glbCurrentId  = id;
  _glbCurrentUrl = url;
  _glbCurrentFav = isFav;
  document.getElementById('glb-img').src = url;
  const favBtn = document.getElementById('glb-fav-btn');
  if (favBtn) {
    favBtn.textContent = isFav ? '♥' : '♡';
    favBtn.classList.toggle('active', !!isFav);
  }
  document.getElementById('gen-gallery-lightbox').classList.add('open');
}

function closeGalleryLightbox() {
  document.getElementById('gen-gallery-lightbox').classList.remove('open');
}

function glbBgClick(e) {
  if (e.target === document.getElementById('gen-gallery-lightbox')) closeGalleryLightbox();
}

async function glbDelete() {
  if (!_glbCurrentId) return;
  showConfirm('この画像をギャラリーから削除しますか？\n削除すると元に戻すことはできません。', async () => {
    const r = await api('/generate/my-images/' + _glbCurrentId, { method: 'DELETE' });
    if (r.ok) {
      closeGalleryLightbox();
      await loadGallery();
      showToast('ギャラリーから削除しました', 'info');
    } else {
      showToast('削除に失敗しました', 'error');
    }
  }, '削除する');
}

async function glbToggleFav() {
  if (!_glbCurrentId) return;
  const r = await api('/generate/my-images/' + _glbCurrentId + '/favorite', { method: 'POST' });
  if (!r.ok) { showToast('更新に失敗しました', 'error'); return; }
  const d = await r.json();
  _glbCurrentFav = d.is_favorite;
  const favBtn = document.getElementById('glb-fav-btn');
  if (favBtn) {
    favBtn.textContent = d.is_favorite ? '♥' : '♡';
    favBtn.classList.toggle('active', !!d.is_favorite);
  }
  await loadGallery();
}

function glbUseAsAvatar() {
  if (!_glbCurrentUrl) return;
  sessionStorage.setItem('aic_gen_avatar', _glbCurrentUrl);
  closeGalleryLightbox();
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
  const banner = document.getElementById('gen-done-banner');
  if (!grid) return;
  try {
    // pending画像チェック（生成完了バナー表示用）
    if (banner) {
      try {
        const pr = await api('/generate/pending');
        if (pr.ok) {
          const pd = await pr.json();
          banner.style.display = (pd.images || []).length > 0 ? 'block' : 'none';
        } else {
          banner.style.display = 'none';
        }
      } catch(_) { banner.style.display = 'none'; }
    }
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
  showConfirm('この画像をギャラリーから削除しますか？\n削除すると元に戻すことはできません。', async () => {
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
  // 画像生成のキャラタイプから性別を自動セット
  const activeType = document.querySelector('#gen-char-type .gen-type-btn.active');
  const typeVal = activeType ? activeType.dataset.type : _lastCharType;
  if (typeVal) {
    const genderSel = document.getElementById('cr-gender');
    if (genderSel) genderSel.value = typeVal;
  }
  // 音声モデル読み込み
  const gender = document.getElementById('cr-gender')?.value || '';
  loadVoiceModelsForForm(gender);
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

// === TTS読み上げ ===
// === VOICEVOX TTS ===

// ボタンから再生（トグル）
function _ttsResetBtn(btn) {
  const dur = parseFloat(btn.dataset.duration || '0');
  const durSec = Math.ceil(dur);
  btn.innerHTML = durSec > 0
    ? '▶ <span class="tts-dur">' + durSec + '″</span>'
    : '▶';
  btn.title = '読み上げ';
  btn.classList.remove('tts-playing', 'tts-loading');
}

function ttsPlayFromBtn(btn) {
  if (_ttsPlaying && _ttsPlayingBtn === btn) {
    ttsStopAll();
    return;
  }
  ttsStopAll();

  const msgEl = btn.closest('.msg-ai');
  if (!msgEl) return;
  const rawText = msgEl.dataset.raw || '';
  if (!rawText) return;

  _ttsPlayingBtn = btn;
  // 押した瞬間: ドットアニメーション（読み込み中）
  btn.innerHTML = '<span class="tts-dots"><b></b><b></b><b></b></span>';
  btn.title = '停止';
  btn.classList.remove('tts-playing');
  btn.classList.add('tts-loading');

  btn.dataset.duration = '0';  // 再生開始時に累計をリセット
  const segments = parseMessageSegments(rawText);
  _ttsStopFlag = false;
  _ttsPlaying = true;
  _playTtsQueue(segments).finally(() => {
    if (_ttsPlayingBtn === btn) {
      _ttsResetBtn(btn);
      _ttsPlayingBtn = null;
    }
    _ttsPlaying = false;
  });
}

function ttsStopAll() {
  _ttsStopFlag = true;
  _ttsPlaying = false;
  if (_ttsAudio) {
    _ttsAudio.pause();
    _ttsAudio.src = '';
    _ttsAudio = null;
  }
  if (_ttsPlayingBtn) {
    _ttsResetBtn(_ttsPlayingBtn);
    _ttsPlayingBtn = null;
  }
}

// セグメント配列を先読みパイプラインで順番に再生
async function _playTtsQueue(segments) {
  if (!currentConversationId) return;

  // SE と VOICE セグメントを処理
  let prefetch = null;
  for (let i = 0; i < segments.length; i++) {
    if (_ttsStopFlag) return;
    const seg = segments[i];

    if (seg.type === 'voice') {
      const styleId = _vvStyleMap[seg.style] ?? _vvStyleMap['ノーマル'] ?? Object.values(_vvStyleMap)[0];
      if (styleId == null) continue;

      // プリフェッチがなければフェッチ開始（セグメント固有のvoiceParamsを使用）
      const audioBlobPromise = prefetch || _fetchTtsAudio(seg.text, styleId, seg.voiceParams);
      // 次セグメントがVOICEなら先読み
      prefetch = null;
      for (let j = i + 1; j < segments.length; j++) {
        if (segments[j].type === 'voice') {
          const nextStyleId = _vvStyleMap[segments[j].style] ?? _vvStyleMap['ノーマル'] ?? Object.values(_vvStyleMap)[0];
          if (nextStyleId != null) {
            prefetch = _fetchTtsAudio(segments[j].text, nextStyleId, segments[j].voiceParams);
          }
          break;
        }
      }

      const blob = await audioBlobPromise;
      if (!blob || _ttsStopFlag) return;
      await _playAudioBlob(blob);

    } else if (seg.type === 'se') {
      await _playSe(seg.name);
    }
  }
}

async function _fetchTtsAudio(text, styleId, voiceParams) {
  const vpStr = voiceParams ? JSON.stringify(voiceParams) : '';
  const key = styleId + '|' + text.trim().slice(0, 300) + '|' + vpStr;
  if (_ttsCache.has(key)) return _ttsCache.get(key);
  try {
    const body = { text: text.trim().slice(0, 300), style_id: styleId };
    if (voiceParams) {
      if (voiceParams.speed     != null) body.speed     = voiceParams.speed;
      if (voiceParams.pitch     != null) body.pitch     = voiceParams.pitch;
      if (voiceParams.intonation != null) body.intonation = voiceParams.intonation;
      if (voiceParams.volume    != null) body.volume    = voiceParams.volume;
    }
    const res = await api('/tts/' + currentConversationId, {
      method: 'POST',
      body: JSON.stringify(body),
    });
    if (!res.ok) return null;
    const blob = await res.blob();
    _ttsCache.set(key, blob);
    return blob;
  } catch (_) { return null; }
}

function _playAudioBlob(blob) {
  return new Promise(resolve => {
    if (_ttsStopFlag) { resolve(); return; }
    const url = URL.createObjectURL(blob);
    _ttsAudio = new Audio(url);
    // 再生開始できたら loading → playing（音波アニメーション）に切替
    _ttsAudio.addEventListener('loadedmetadata', () => {
      if (_ttsPlayingBtn && _ttsPlayingBtn.classList.contains('tts-loading')) {
        _ttsPlayingBtn.classList.remove('tts-loading');
        _ttsPlayingBtn.classList.add('tts-playing');
        _ttsPlayingBtn.innerHTML = '<span class="tts-wave"><b></b><b></b><b></b></span>';
      }
    });
    _ttsAudio.onended = () => {
      // duration を蓄積
      const dur = _ttsAudio ? (_ttsAudio.duration || 0) : 0;
      if (_ttsPlayingBtn && !isNaN(dur) && dur > 0) {
        const prev = parseFloat(_ttsPlayingBtn.dataset.duration || '0');
        _ttsPlayingBtn.dataset.duration = String(prev + dur);
      }
      URL.revokeObjectURL(url); _ttsAudio = null; resolve();
    };
    _ttsAudio.onerror = () => { URL.revokeObjectURL(url); _ttsAudio = null; resolve(); };
    _ttsAudio.play().catch(() => { resolve(); });
  });
}

async function _playSe(name) {
  if (_ttsStopFlag) return;
  const url = '/se/' + encodeURIComponent(name) + '.wav';
  try {
    const res = await fetch(url, { method: 'HEAD' });
    if (!res.ok) {
      // SE未実装をログ
      api('/tts/se-miss', { method: 'POST', body: JSON.stringify({ name }) }).catch(() => {});
      return;
    }
    await _playAudioBlob(await (await fetch(url)).blob());
  } catch (_) {
    api('/tts/se-miss', { method: 'POST', body: JSON.stringify({ name }) }).catch(() => {});
  }
}

// 自動読み上げ: 会話の最後のAIメッセージを再生
function ttsAutoPlayLast() {
  if (!_ttsAutoPlay || !_ttsAvailable) return;
  const chatEl = document.getElementById('chat-messages');
  if (!chatEl) return;
  const lastAiEl = [...chatEl.querySelectorAll('.msg-ai[data-msg-id]')].filter(el => !el.classList.contains('msg-soft-deleted')).pop();
  if (!lastAiEl) return;
  const btn = lastAiEl.querySelector('.tts-btn');
  if (btn) ttsPlayFromBtn(btn);
}

// === チャットメニュー ===
let _chatMenuOpen = false;

function toggleChatMenu(e) {
  if (e) e.stopPropagation();
  _chatMenuOpen = !_chatMenuOpen;
  const panel = document.getElementById('chat-menu-panel');
  if (panel) panel.classList.toggle('open', _chatMenuOpen);
  if (_chatMenuOpen) {
    setTimeout(() => {
      document.addEventListener('pointerdown', _closeChatMenuOutside, { once: true });
    }, 10);
  }
}

function _closeChatMenuOutside(e) {
  const panel = document.getElementById('chat-menu-panel');
  const btn = document.getElementById('chat-menu-btn');
  if (panel && !panel.contains(e.target) && btn && !btn.contains(e.target)) {
    _chatMenuOpen = false;
    panel.classList.remove('open');
  }
}

function editCurrentChar() {
  _chatMenuOpen = false;
  document.getElementById('chat-menu-panel')?.classList.remove('open');
  if (currentCharacter) {
    _editReturnScreen = 'chat';
    editCharacter(currentCharacter.id);
  }
}

function toggleAutoVoice() {
  _chatMenuOpen = false;
  document.getElementById('chat-menu-panel')?.classList.remove('open');
  _ttsAutoPlay = !_ttsAutoPlay;
  _updateAutoVoiceIcon();
  showToast(_ttsAutoPlay ? '自動音声をオンにしました' : '自動音声をオフにしました', 'info', 1500);
}

function _updateAutoVoiceIcon() {
  const offIcon = document.getElementById('auto-voice-icon-off');
  const onIcon = document.getElementById('auto-voice-icon-on');
  const label = document.getElementById('auto-voice-label');
  if (!offIcon || !onIcon || !label) return;
  if (_ttsAutoPlay) {
    // 現在オン → クリックするとオフ: 🔊→🔇
    offIcon.textContent = '🔊';
    onIcon.textContent = '🔇';
    label.textContent = '自動音声オフ';
    document.getElementById('auto-voice-btn')?.classList.add('active');
  } else {
    // 現在オフ → クリックするとオン: 🔇→🔊
    offIcon.textContent = '🔇';
    onIcon.textContent = '🔊';
    label.textContent = '自動音声オン';
    document.getElementById('auto-voice-btn')?.classList.remove('active');
  }
}

// キャラクタープロフィール確認（チャットメニューから）
function viewCurrentCharProfile() {
  _chatMenuOpen = false;
  document.getElementById('chat-menu-panel')?.classList.remove('open');
  if (currentCharacter) {
    showCharDetail(currentCharacter.id);
  }
}

// 全会話リセット（チャットメニューから）
function resetConversation() {
  _chatMenuOpen = false;
  document.getElementById('chat-menu-panel')?.classList.remove('open');
  if (!currentConversationId) return;
  showConfirm(
    'すべての会話履歴を削除し、最初の状態に戻します。\nこの操作は取り消せません。',
    async () => {
      try {
        const r = await api(`/conversations/${currentConversationId}/reset`, { method: 'POST' });
        if (r.ok) {
          _ttsCache.clear();
          await openConversation(currentConversationId);
          showToast('会話をリセットしました', 'info', 2000);
        } else {
          showToast('リセットに失敗しました', 'error');
        }
      } catch (e) {
        showToast('リセットに失敗しました', 'error');
      }
    },
    'リセット',
    'btn-danger'
  );
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
      <p style="white-space:pre-line">${esc(msg)}</p>
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

// === contenteditable 高さ自動調整 ===
document.addEventListener('DOMContentLoaded', () => {
  const el = document.getElementById('chat-input');
  if (el) {
    el.addEventListener('input', () => {
      // contenteditableは自動でサイズ調整されるが、max-heightをCSSで制限
      // 空になったらプレースホルダー表示のためtrimチェック
      if (!el.textContent.trim() && !el.querySelector('br')) {
        el.innerHTML = '';
      }
    });
  }
});

// ブラウザ戻る/進むでハッシュが変わったら画面を切り替える
// popstate 時は pushState せず replaceState で対応（戻るループ防止）
window.addEventListener('popstate', () => {
  const screen = location.hash.slice(1) || 'home';
  _navReplace = true;
  navTo(screen);
  _navReplace = false;
});

// === 初期化 ===
(async () => {
  await initAuth();

  // 利用停止ユーザーは停止画面のみ表示
  if (currentUser && currentUser.is_suspended) {
    document.body.innerHTML = `<div style="display:flex;align-items:center;justify-content:center;height:100vh;background:#1a1a2e;color:#ccc;font-family:system-ui,sans-serif;text-align:center;padding:20px"><div><div style="font-size:3rem;margin-bottom:16px">🚫</div><div style="font-size:1.2rem;font-weight:600;color:#e74c3c;margin-bottom:8px">利用停止されています</div><div style="font-size:0.9rem;color:#888">このアカウントは管理者によって利用を停止されました。</div></div></div>`;
    return;
  }

  // SD設定の縦横比を早期に取得（生成画像カードの比率に必要）
  await loadSdStatus();
  // リロード・直リンク時はURLハッシュの画面を復元（初回は replaceState で履歴を汚さない）
  const hashVal = location.hash.slice(1) || 'home';
  const chatMatch = hashVal.match(/^chat\/(\d+)$/);
  _navReplace = true;
  if (chatMatch) {
    openConversation(parseInt(chatMatch[1]));
  } else {
    const validScreens = ['home', 'community', 'chat-list', 'create', 'generate', 'profile', 'char-detail'];
    navTo(validScreens.includes(hashVal) ? hashVal : 'home');
  }
  _navReplace = false;
  await loadConversations();

  // キーボード開閉時にチャット画面を調整（スマホ対応）
  if (window.visualViewport) {
    const fullH = window.innerHeight;
    const onVVResize = () => {
      const chatScreen = document.getElementById('screen-chat');
      if (!chatScreen) return;
      const vvH = window.visualViewport.height;
      const ratio = vvH / fullH;
      const kbOpen = ratio < 0.75;
      // チャット画面の高さをビジュアルビューポートに合わせる
      chatScreen.style.height = kbOpen ? vvH + 'px' : '';
      // キーボードが開いたら背景画像を顔中心に（上端基準）
      if (chatScreen.style.backgroundImage) {
        chatScreen.style.backgroundPosition = kbOpen ? 'center top' : 'center 15%';
        chatScreen.style.backgroundSize = kbOpen ? 'auto 100vh' : 'cover';
      }
      // body に kb-open クラスを付与（CSS連携用）
      document.body.classList.toggle('kb-open', kbOpen);
    };
    window.visualViewport.addEventListener('resize', onVVResize);
    window.visualViewport.addEventListener('scroll', onVVResize);
  }
})();
