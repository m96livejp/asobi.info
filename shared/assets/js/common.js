/* === common.js - あそび 共通JavaScript === */

// フォント設定の読み込み
(function() {
  const link = document.createElement('link');
  link.rel  = 'stylesheet';
  link.href = 'https://asobi.info/assets/css/font.php';
  document.head.appendChild(link);
})();

// モバイルメニュートグル（全サイト共通）
document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.querySelector('.menu-toggle');
  const nav = document.querySelector('.site-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', () => nav.classList.toggle('open'));
  }
});

// ─── ヘッダーユーザーエリア（全サイト統一・即時実行） ───
// common.js はページ末尾で読み込まれるため DOMContentLoaded を待たずに即時実行できる
// 挿入先: .site-header .container（pkq/dbd等）または #asobi-user-area（tbt等）
(function() {
  // CSS インジェクト（common.css 未読込サイト向け）
  if (!document.getElementById('asobi-user-css')) {
    const s = document.createElement('style');
    s.id = 'asobi-user-css';
    s.textContent = [
      '.header-user-area{display:flex;align-items:center;gap:8px;flex-shrink:0;margin-left:12px}',
      '.header-btn-login{font-size:.82rem;font-weight:600;padding:6px 16px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff!important;border-radius:20px;text-decoration:none;white-space:nowrap;transition:opacity .2s}',
      '.header-btn-login:hover{opacity:.85}',
      '.header-user-menu{position:relative}',
      '.header-user-trigger{display:flex;align-items:center;gap:6px;cursor:pointer;padding:3px 6px;border-radius:8px;transition:background .2s;color:inherit;background:none;border:none}',
      '.header-user-trigger:hover{background:rgba(128,128,128,.15)}',
      '.header-user-avatar{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;color:#fff;flex-shrink:0;overflow:hidden}',
      '.header-user-avatar img{width:100%;height:100%;object-fit:cover}',
      '.header-user-dropdown{display:none;position:absolute;top:calc(100% + 8px);right:0;background:#fff;border:1px solid #e0e0e0;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.14);min-width:150px;overflow:hidden;z-index:9999;color:#1d1d1f}',
      '.header-user-menu.open .header-user-dropdown{display:block}',
      '.header-user-dropdown a{display:block;padding:10px 16px;font-size:.85rem;color:#1d1d1f;text-decoration:none;transition:background .15s;white-space:nowrap}',
      '.header-user-dropdown a:hover{background:#f5f5f7}',
      '.header-user-dropdown .hud-divider{height:1px;background:#e0e0e0;margin:4px 0}',
    ].join('');
    document.head.appendChild(s);
  }

  const headerContainer = document.querySelector('.site-header .container');
  const fixedArea = document.getElementById('asobi-user-area');
  if (!headerContainer && !fixedArea) return;
  if (headerContainer && headerContainer.querySelector('.header-user-area')) return;

  const h = location.hostname;

  function buildLoggedInHtml(user) {
    const avatarHtml = user.avatarUrl
      ? `<img src="${escapeHtml(user.avatarUrl)}" alt="">`
      : escapeHtml(user.initial);

    let menuItems = `<a href="/">トップ</a>`;
    const profileUrl = 'https://asobi.info/profile.php' + (h !== 'asobi.info' ? '?back=' + encodeURIComponent(location.origin) : '');
    menuItems += `<a href="${profileUrl}">プロフィール</a>`;

    const siteMenus = {
      'tbt.asobi.info': [
        { label: 'キャラクター', href: '/?page=character' },
        { label: 'ガチャ',       href: '/?page=gacha' },
        { label: 'ランキング',   href: '/?page=ranking' },
      ],
    };
    const siteMenu = siteMenus[h];
    if (siteMenu && siteMenu.length) {
      menuItems += `<div class="hud-divider"></div>`;
      siteMenu.forEach(item => { menuItems += `<a href="${item.href}">${escapeHtml(item.label)}</a>`; });
    }

    if (user.role === 'admin') {
      const siteAdminUrls = {
        'asobi.info':     '/admin/',
        'dbd.asobi.info': 'https://dbd.asobi.info/admin/',
        'pkq.asobi.info': 'https://pkq.asobi.info/admin/',
        'tbt.asobi.info': 'https://tbt.asobi.info/admin/',
        'aic.asobi.info': 'https://aic.asobi.info/admin.html',
      };
      const siteAdmin = siteAdminUrls[h];
      menuItems += `<div class="hud-divider"></div>`;
      if (siteAdmin) menuItems += `<a href="${siteAdmin}">コンテンツ管理</a>`;
      if (h !== 'asobi.info') menuItems += `<a href="https://asobi.info/admin/">asobi.info 全体管理</a>`;
    }

    return `
      <div class="header-user-menu">
        <div class="header-user-trigger" tabindex="0">
          <div class="header-user-avatar">${avatarHtml}</div>
        </div>
        <div class="header-user-dropdown">${menuItems}</div>
      </div>`;
  }

  function attachMenu(root) {
    const menu = root.querySelector('.header-user-menu');
    if (!menu) return;
    root.querySelector('.header-user-trigger').addEventListener('click', e => { e.stopPropagation(); menu.classList.toggle('open'); });
    document.addEventListener('click', () => menu.classList.remove('open'));
  }

  fetch('https://asobi.info/assets/php/me.php', { credentials: 'include' })
    .then(r => r.json())
    .then(user => {
      const loginHtml = `<a href="https://asobi.info/login.php?redirect=${encodeURIComponent(location.href)}" class="header-btn-login">ログイン</a>`;

      if (headerContainer) {
        const area = document.createElement('div');
        area.className = 'header-user-area';
        area.innerHTML = user.loggedIn ? buildLoggedInHtml(user) : loginHtml;
        headerContainer.appendChild(area);
        if (user.loggedIn) attachMenu(area);
      }

      if (fixedArea) {
        fixedArea.style.position = 'relative';
        fixedArea.innerHTML = user.loggedIn ? buildLoggedInHtml(user) : loginHtml;
        if (user.loggedIn) attachMenu(fixedArea);
      }
    })
    .catch(() => {});
})();

// サブドメインアクセスログ（asobi.info メインは PHP で記録済みのためスキップ）
(function() {
  const h = location.hostname;
  if (h !== 'asobi.info' && h.endsWith('.asobi.info')) {
    fetch('https://asobi.info/assets/php/log-access.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ host: h, path: location.pathname })
    }).catch(() => {});
  }
})();

// APIヘルパー（各サイト独自の API が未定義の場合のみ提供）
if (typeof API === 'undefined') {
  window.API = {
    async get(url, params = {}) {
      const query = new URLSearchParams(params).toString();
      const fullUrl = query ? `${url}?${query}` : url;
      const res = await fetch(fullUrl);
      if (!res.ok) throw new Error(`API Error: ${res.status}`);
      return res.json();
    }
  };
}

// スクロールトップボタン（site-headerがあるページのみ）
(function() {
  const btn = document.createElement('button');
  btn.className = 'scroll-top-btn';
  btn.innerHTML = '▲';
  btn.title = 'トップへ戻る';
  btn.setAttribute('aria-label', 'トップへ戻る');
  document.addEventListener('DOMContentLoaded', () => {
    if (!document.querySelector('.site-header')) return;
    document.body.appendChild(btn);
  });
  window.addEventListener('scroll', () => {
    btn.classList.toggle('visible', window.scrollY > 300);
  }, { passive: true });
  btn.addEventListener('click', () => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
})();

// ─── カスタム確認ダイアログ（alert/confirm 禁止の代替） ───
function asobiConfirm(msg, onOk, okLabel = '実行する', okDanger = true) {
  const CSS_ID = 'asobi-confirm-css';
  if (!document.getElementById(CSS_ID)) {
    const s = document.createElement('style');
    s.id = CSS_ID;
    s.textContent = [
      '.asobi-confirm-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;display:flex;align-items:center;justify-content:center;animation:asobi-cfm-in .12s ease}',
      '@keyframes asobi-cfm-in{from{opacity:0}to{opacity:1}}',
      '.asobi-confirm-box{background:#fff;border-radius:14px;padding:28px 28px 20px;max-width:400px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);animation:asobi-cfm-box .15s ease}',
      '@keyframes asobi-cfm-box{from{transform:scale(.92)}to{transform:scale(1)}}',
      '.asobi-confirm-msg{font-size:.95rem;line-height:1.7;color:#222;margin-bottom:20px;white-space:pre-wrap}',
      '.asobi-confirm-btns{display:flex;gap:10px;justify-content:flex-end}',
      '.asobi-confirm-btns button{padding:8px 20px;border:none;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;font-family:inherit}',
      '.asobi-confirm-cancel{background:#f0f0f0;color:#555}',
      '.asobi-confirm-cancel:hover{background:#e0e0e0}',
      '.asobi-confirm-ok-danger{background:#e53935;color:#fff}',
      '.asobi-confirm-ok-danger:hover{background:#c62828}',
      '.asobi-confirm-ok-normal{background:#1976d2;color:#fff}',
      '.asobi-confirm-ok-normal:hover{background:#1565c0}',
    ].join('');
    document.head.appendChild(s);
  }
  const overlay = document.createElement('div');
  overlay.className = 'asobi-confirm-overlay';
  const okClass = okDanger ? 'asobi-confirm-ok-danger' : 'asobi-confirm-ok-normal';
  overlay.innerHTML = `<div class="asobi-confirm-box">
    <div class="asobi-confirm-msg">${escapeHtml(msg)}</div>
    <div class="asobi-confirm-btns">
      <button class="asobi-confirm-cancel">キャンセル</button>
      <button class="${okClass}">${escapeHtml(okLabel)}</button>
    </div>
  </div>`;
  document.body.appendChild(overlay);
  overlay.querySelector('.asobi-confirm-cancel').addEventListener('click', () => overlay.remove());
  overlay.querySelector('.' + okClass).addEventListener('click', () => { overlay.remove(); onOk(); });
  overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
}

// data-confirm 属性を持つ要素の自動インターセプト（PHP ページ向け）
document.addEventListener('DOMContentLoaded', () => {
  document.addEventListener('click', e => {
    const btn = e.target.closest('[data-confirm]');
    if (!btn) return;
    e.preventDefault();
    const msg = btn.dataset.confirm;
    const okLabel = btn.dataset.confirmOk || '実行する';
    asobiConfirm(msg, () => {
      const form = btn.closest('form');
      if (form) {
        // hidden input で action を上書きする場合の対応
        const nameAttr = btn.dataset.name;
        const valAttr  = btn.dataset.value;
        if (nameAttr) {
          const h = document.createElement('input');
          h.type = 'hidden'; h.name = nameAttr; h.value = valAttr || '';
          form.appendChild(h);
        }
        form.submit();
      } else if (btn.tagName === 'A') {
        location.href = btn.href;
      }
    }, okLabel);
  });
});

// 検索デバウンス
function debounce(fn, delay = 300) {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), delay);
  };
}

// テキストハイライト
function highlightText(text, query) {
  if (!query) return escapeHtml(text);
  const escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const regex = new RegExp(`(${escaped})`, 'gi');
  return escapeHtml(text).replace(regex, '<mark>$1</mark>');
}

// XSS対策
function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}
