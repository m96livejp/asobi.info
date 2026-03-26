/* === common.js - あそび 共通JavaScript === */

// フォント設定の読み込み
(function() {
  const link = document.createElement('link');
  link.rel  = 'stylesheet';
  link.href = 'https://asobi.info/assets/css/font.php';
  document.head.appendChild(link);
})();

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
