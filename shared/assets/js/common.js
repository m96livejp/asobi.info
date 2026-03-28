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
      '.header-user-dropdown{position:absolute;top:calc(100% + 8px);right:0;background:#fff;border:1px solid #e0e0e0;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.14);min-width:150px;overflow:hidden;z-index:9999;color:#1d1d1f;opacity:0;visibility:hidden;transform:translateY(-4px);transition:opacity .15s,visibility .15s,transform .15s;pointer-events:none}',
      '.header-user-menu.open .header-user-dropdown{opacity:1;visibility:visible;transform:translateY(0);pointer-events:auto}',
      '.header-user-dropdown a,.header-user-dropdown button.hud-link{display:block;padding:10px 16px;font-size:.85rem;color:#1d1d1f;text-decoration:none;transition:background .15s;white-space:nowrap;width:100%;text-align:left;background:none;border:none;cursor:pointer;font-family:inherit}',
      '.header-user-dropdown a:hover,.header-user-dropdown button.hud-link:hover{background:#f5f5f7}',
      '.header-user-dropdown .hud-divider{height:1px;background:#e0e0e0;margin:4px 0}',
      // サイトプロフィール編集オーバーレイ
      '.asobi-sp-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center}',
      '.asobi-sp-overlay.open{display:flex}',
      '.asobi-sp-box{background:#fff;border-radius:16px;padding:24px;max-width:380px;width:90%;box-shadow:0 16px 48px rgba(0,0,0,.2);animation:asobi-sp-in .15s ease}',
      '@keyframes asobi-sp-in{from{transform:scale(.92);opacity:0}to{transform:scale(1);opacity:1}}',
      '.asobi-sp-title{font-size:1rem;font-weight:700;margin-bottom:4px;color:#1d1d1f}',
      '.asobi-sp-sub{font-size:.8rem;color:#888;margin-bottom:16px}',
      '.asobi-sp-label{font-size:.82rem;font-weight:600;color:#555;margin-bottom:6px;display:block}',
      '.asobi-sp-input{width:100%;padding:10px 14px;border:1px solid #ddd;border-radius:10px;font-size:.95rem;outline:none;box-sizing:border-box;font-family:inherit}',
      '.asobi-sp-input:focus{border-color:#667eea}',
      '.asobi-sp-error{font-size:.8rem;color:#e53935;margin-top:6px;min-height:1.2em}',
      '.asobi-sp-btns{display:flex;gap:8px;justify-content:flex-end;margin-top:16px}',
      '.asobi-sp-btns button{padding:8px 20px;border:none;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;font-family:inherit}',
      '.asobi-sp-cancel{background:#f0f0f0;color:#555}',
      '.asobi-sp-cancel:hover{background:#e0e0e0}',
      '.asobi-sp-save{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff}',
      '.asobi-sp-save:hover{opacity:.85}',
    ].join('');
    document.head.appendChild(s);
  }

  const headerContainer = document.querySelector('.site-header .container');
  const fixedArea = document.getElementById('asobi-user-area');
  if (!headerContainer && !fixedArea) return;
  if (headerContainer && headerContainer.querySelector('.header-user-area')) return;

  const h = location.hostname;

  // サイト別プロフィールリンク
  const siteProfileRoutes = {
    'aic.asobi.info': { type: 'hash', target: '#profile' },
    'tbt.asobi.info': { type: 'hash', target: '#settings' },
  };

  function buildLoggedInHtml(user) {
    const avatarHtml = user.avatarUrl
      ? `<img src="${escapeHtml(user.avatarUrl)}" alt="">`
      : escapeHtml(user.initial);

    let menuItems = '';
    const isTop = (h === 'asobi.info');

    // ── サイト固有（サブドメインのみ） ──
    if (!isTop) {
      // 1. サイトに戻る
      menuItems += `<a href="/">サイトに戻る</a>`;

      // 2. プロフィール/設定（サイト別）
      const siteSettings = {
        'aic.asobi.info': { label: 'プロフィール', nav: 'profile' },
        'tbt.asobi.info': { label: '設定', nav: 'settings' },
      };
      const setting = siteSettings[h];
      if (setting) {
        menuItems += `<a href="#${setting.nav}" onclick="if(typeof App!=='undefined'&&App.navigate){App.navigate('${setting.nav}');this.closest('.header-user-menu').classList.remove('open');return false;}else if(typeof navTo==='function'){navTo('${setting.nav}');this.closest('.header-user-menu').classList.remove('open');return false;}">${setting.label}</a>`;
      } else {
        menuItems += `<a href="https://asobi.info/profile.php?back=${encodeURIComponent(location.origin + '/')}">プロフィール</a>`;
      }

      // 3. コンテンツ管理（admin のみ・サイト固有）
      if (user.role === 'admin') {
        const siteAdminUrls = {
          'dbd.asobi.info': 'https://dbd.asobi.info/admin/',
          'pkq.asobi.info': 'https://pkq.asobi.info/admin/',
          'tbt.asobi.info': 'https://tbt.asobi.info/admin/',
          'aic.asobi.info': 'https://aic.asobi.info/admin.html',
        };
        const siteAdmin = siteAdminUrls[h];
        if (siteAdmin) menuItems += `<a href="${siteAdmin}">🔒 コンテンツ管理</a>`;
      }

      menuItems += `<div class="hud-divider"></div>`;

      // 4. asobi.info TOP
      menuItems += `<a href="https://asobi.info/">asobi.info TOP</a>`;
    } else {
      // asobi.info メインサイト
      menuItems += `<a href="https://asobi.info/profile.php">プロフィール</a>`;
    }

    // ── asobi.info 共通 ──
    // 5. asobi.info 管理（admin のみ）
    if (user.role === 'admin') {
      menuItems += `<a href="https://asobi.info/admin/">🔒 管理画面</a>`;
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

      if (headerContainer && !fixedArea) {
        const area = document.createElement('div');
        area.className = 'header-user-area';
        area.innerHTML = user.loggedIn ? buildLoggedInHtml(user) : loginHtml;
        headerContainer.appendChild(area);
        if (user.loggedIn) attachMenu(area);
      }

      if (fixedArea) {
        // position:fixed 指定済みの場合はそのまま維持（tbt等）、未指定なら relative を設定
        if (getComputedStyle(fixedArea).position !== 'fixed') {
          fixedArea.style.position = 'relative';
        }
        fixedArea.innerHTML = user.loggedIn ? buildLoggedInHtml(user) : loginHtml;
        if (user.loggedIn) attachMenu(fixedArea);
      }
    })
    .catch(() => {});
})();

// ─── サイト別プロフィール編集オーバーレイ（PHP系サイト向け） ───
function asobiOpenSiteProfile() {
  // ドロップダウンを閉じる
  document.querySelectorAll('.header-user-menu').forEach(m => m.classList.remove('open'));

  let overlay = document.getElementById('asobi-sp-overlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.id = 'asobi-sp-overlay';
    overlay.className = 'asobi-sp-overlay';
    overlay.innerHTML = `
      <div class="asobi-sp-box">
        <div class="asobi-sp-title">プロフィール</div>
        <div class="asobi-sp-sub" id="asobi-sp-site"></div>
        <label class="asobi-sp-label">このサイトでの表示名</label>
        <input type="text" class="asobi-sp-input" id="asobi-sp-name" maxlength="30" placeholder="名前を入力...">
        <div class="asobi-sp-error" id="asobi-sp-error"></div>
        <div class="asobi-sp-btns">
          <button class="asobi-sp-cancel" onclick="asobiCloseSiteProfile()">閉じる</button>
          <button class="asobi-sp-save" onclick="asobiSaveSiteProfile()">保存</button>
        </div>
      </div>`;
    overlay.addEventListener('click', e => { if (e.target === overlay) asobiCloseSiteProfile(); });
    document.body.appendChild(overlay);
  }

  const siteNames = {
    'asobi.info':     'asobi.info',
    'dbd.asobi.info': 'DbD情報サイト',
    'pkq.asobi.info': 'ポケモンクエスト',
    'tbt.asobi.info': 'Tournament Battle',
    'aic.asobi.info': 'AIチャット',
  };
  document.getElementById('asobi-sp-site').textContent = siteNames[location.hostname] || location.hostname;
  document.getElementById('asobi-sp-error').textContent = '';
  document.getElementById('asobi-sp-name').value = '';

  overlay.classList.add('open');

  // 現在のサイト別名前を取得
  const _spSite = encodeURIComponent(location.hostname);
  fetch(`https://asobi.info/assets/php/site-profile.php?site=${_spSite}`, { credentials: 'include' })
    .then(r => { if (!r.ok) throw new Error(r.status); return r.json(); })
    .then(data => {
      const input = document.getElementById('asobi-sp-name');
      if (input && (data.displayName || data.mainName)) {
        input.value = data.displayName || data.mainName;
      }
    })
    .catch(() => {});
}

function asobiCloseSiteProfile() {
  const overlay = document.getElementById('asobi-sp-overlay');
  if (overlay) overlay.classList.remove('open');
}

function asobiSaveSiteProfile() {
  const input = document.getElementById('asobi-sp-name');
  const errorEl = document.getElementById('asobi-sp-error');
  const name = (input?.value || '').trim();
  if (!name) {
    errorEl.textContent = '名前を入力してください';
    return;
  }
  errorEl.textContent = '';

  const saveBtn = document.querySelector('.asobi-sp-save');
  if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = '保存中...'; }

  fetch(`https://asobi.info/assets/php/site-profile.php?site=${encodeURIComponent(location.hostname)}`, {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ display_name: name }),
  })
    .then(r => {
      if (!r.ok) return r.json().then(e => { throw new Error(e.error || '保存に失敗しました'); });
      return r.json();
    })
    .then(() => {
      asobiCloseSiteProfile();
      // 成功トースト（簡易）
      const toast = document.createElement('div');
      toast.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#333;color:#fff;padding:10px 24px;border-radius:20px;font-size:.88rem;z-index:99999;animation:asobi-sp-in .2s ease';
      toast.textContent = '保存しました';
      document.body.appendChild(toast);
      setTimeout(() => toast.remove(), 2000);
    })
    .catch(e => {
      errorEl.textContent = e.message;
    })
    .finally(() => {
      if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = '保存'; }
    });
}

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
