/* === common.js - あそび 共通JavaScript === */

// モバイルメニュートグル + ヘッダーユーザーエリア
document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.querySelector('.menu-toggle');
  const nav = document.querySelector('.site-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', () => {
      nav.classList.toggle('open');
    });
  }

  // ヘッダーユーザーエリア自動挿入（既に存在する場合はスキップ）
  const headerContainer = document.querySelector('.site-header .container');
  if (headerContainer && !headerContainer.querySelector('.header-user-area, .user-area')) {
    fetch('https://asobi.info/assets/php/me.php', { credentials: 'include' })
      .then(r => r.json())
      .then(user => {
        const area = document.createElement('div');
        area.className = 'header-user-area';
        if (user.loggedIn) {
          const avatarHtml = user.avatarUrl
            ? `<img src="${escapeHtml(user.avatarUrl)}" alt="">`
            : escapeHtml(user.initial);
          let adminLink = '';
          if (user.role === 'admin') {
            const h = location.hostname;
            const subAdminUrls = {
              'dbd.asobi.info':           'https://dbd.asobi.info/admin/',
              'pkq.asobi.info':           'https://pkq.asobi.info/admin/',
              'tbt.asobi.info':           'https://tbt.asobi.info/admin/',
            };
            const subAdminUrl = subAdminUrls[h];
            adminLink = subAdminUrl
              ? `<a href="${subAdminUrl}">サイト管理画面</a><div class="hud-divider"></div><a href="https://asobi.info/admin/">asobi.info 管理画面</a><div class="hud-divider"></div>`
              : `<a href="https://asobi.info/admin/">管理画面</a><div class="hud-divider"></div>`;
          }
          area.innerHTML = `
            <div class="header-user-menu">
              <div class="header-user-trigger" tabindex="0">
                <div class="header-user-avatar">${avatarHtml}</div>
                <span class="header-user-name">${escapeHtml(user.displayName)}</span>
                <span class="header-user-caret">▼</span>
              </div>
              <div class="header-user-dropdown">
                <a href="https://asobi.info/">asobi.info トップ</a>
                <div class="hud-divider"></div>
                <a href="https://asobi.info/profile.php">プロフィール</a>
                ${adminLink}
              </div>
            </div>`;
          const trigger = area.querySelector('.header-user-trigger');
          const menu = area.querySelector('.header-user-menu');
          trigger.addEventListener('click', e => { e.stopPropagation(); menu.classList.toggle('open'); });
          document.addEventListener('click', () => menu.classList.remove('open'));
        } else {
          area.innerHTML = `<a href="https://asobi.info/login.php?redirect=${encodeURIComponent(location.href)}" class="header-btn-login">ログイン</a>`;
        }
        headerContainer.appendChild(area);
      })
      .catch(() => {});
  }
});

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

// APIヘルパー
const API = {
  async get(url, params = {}) {
    const query = new URLSearchParams(params).toString();
    const fullUrl = query ? `${url}?${query}` : url;
    const res = await fetch(fullUrl);
    if (!res.ok) throw new Error(`API Error: ${res.status}`);
    return res.json();
  }
};

// スクロールトップボタン
(function() {
  const btn = document.createElement('button');
  btn.className = 'scroll-top-btn';
  btn.innerHTML = '▲';
  btn.title = 'トップへ戻る';
  btn.setAttribute('aria-label', 'トップへ戻る');
  document.addEventListener('DOMContentLoaded', () => {
    document.body.appendChild(btn);
  });
  window.addEventListener('scroll', () => {
    btn.classList.toggle('visible', window.scrollY > 300);
  }, { passive: true });
  btn.addEventListener('click', () => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
})();

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
