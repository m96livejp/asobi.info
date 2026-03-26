/**
 * 管理画面SPAルーター & メインコントローラー
 */
const AdminApp = {
    currentSection: null,

    sections: {
        players: PlayersSection,
        characters: CharactersSection,
        items: ItemsSection,
        settings: SettingsSection,
        'npc-names': NpcNamesSection,
        tournaments: TournamentsSection,
        logs: LogsSection,
    },

    async init() {
        const token = AdminAPI.getToken();
        if (token) {
            // 既存トークンが有効か確認
            try {
                await AdminAPI.getSettings();
                this.showApp();
                this.navigate('players');
                return;
            } catch {
                localStorage.removeItem('admin_token');
            }
        }

        // asobi.info ログイン済みなら自動ログインを試行
        if (await this.tryAsobiAutoLogin()) {
            this.showApp();
            this.navigate('players');
            return;
        }

        this.showLogin();
    },

    async tryAsobiAutoLogin() {
        try {
            // asobi.info のセッションを確認
            const meRes = await fetch('https://asobi.info/assets/php/me.php', { credentials: 'include' });
            const me = await meRes.json();
            if (!me.loggedIn || me.role !== 'admin') return false;

            // 署名付きトークンを取得
            const tokenRes = await fetch('https://asobi.info/assets/php/admin-token.php', { credentials: 'include' });
            if (!tokenRes.ok) return false;
            const { token } = await tokenRes.json();
            if (!token) return false;

            // TBT管理者として自動ログイン
            const result = await AdminAPI.request('POST', '/admin/login-asobi', { token });
            localStorage.setItem('admin_token', result.access_token);
            return true;
        } catch {
            return false;
        }
    },

    showLogin() {
        document.getElementById('login-screen').style.display = 'flex';
        document.getElementById('admin-layout').style.display = 'none';

        document.getElementById('login-form').onsubmit = async (e) => {
            e.preventDefault();
            const email = document.getElementById('login-email').value;
            const password = document.getElementById('login-password').value;
            const errEl = document.getElementById('login-error');
            errEl.textContent = '';

            try {
                // 管理者専用ログイン（is_admin チェック込み）
                const result = await AdminAPI.login(email, password);
                localStorage.setItem('admin_token', result.access_token);
                this.showApp();
                this.navigate('players');
            } catch (err) {
                errEl.textContent = err.message;
            }
        };
    },

    showApp() {
        document.getElementById('login-screen').style.display = 'none';
        document.getElementById('admin-layout').style.display = 'flex';

        // サイドバーナビゲーション
        document.querySelectorAll('.nav-item[data-section]').forEach(el => {
            el.onclick = () => this.navigate(el.dataset.section);
        });

        // ログアウト
        document.getElementById('logout-btn').onclick = () => {
            localStorage.removeItem('admin_token');
            location.reload();
        };
    },

    navigate(section) {
        this.currentSection = section;

        // サイドバーactive更新
        document.querySelectorAll('.nav-item[data-section]').forEach(el => {
            el.classList.toggle('active', el.dataset.section === section);
        });

        // セクション描画
        const container = document.getElementById('content');
        const sectionHandler = this.sections[section];
        if (sectionHandler) {
            sectionHandler.render(container);
        } else {
            container.innerHTML = '<div class="loading">セクションが見つかりません</div>';
        }
    },

    toast(message, type = 'success') {
        const el = document.createElement('div');
        el.className = `toast toast-${type}`;
        el.textContent = message;
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 3000);
    },

    showModal(html) {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `<div class="modal">${html}</div>`;
        document.body.appendChild(overlay);
        return overlay;
    },

    closeModal() {
        document.querySelector('.modal-overlay')?.remove();
    },

    confirm(msg, okLabel = '削除する') {
        return new Promise(resolve => {
            const overlay = document.createElement('div');
            overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center;animation:cfm-in .12s ease';
            const box = document.createElement('div');
            box.style.cssText = 'background:#1e2433;border:1px solid #2a2f3d;border-radius:14px;padding:28px 28px 20px;max-width:380px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.6)';
            box.innerHTML = `
                <div style="font-size:.95rem;line-height:1.7;color:#dde;margin-bottom:20px;white-space:pre-wrap">${msg.replace(/</g,'&lt;')}</div>
                <div style="display:flex;gap:10px;justify-content:flex-end">
                  <button id="_adm_cancel" style="padding:8px 18px;border:1px solid #3a3f50;border-radius:8px;background:none;color:#aab;font-size:.85rem;font-weight:600;cursor:pointer;font-family:inherit">キャンセル</button>
                  <button id="_adm_ok" style="padding:8px 18px;border:none;border-radius:8px;background:#e53935;color:#fff;font-size:.85rem;font-weight:600;cursor:pointer;font-family:inherit">${okLabel.replace(/</g,'&lt;')}</button>
                </div>`;
            overlay.appendChild(box);
            document.body.appendChild(overlay);
            if (!document.getElementById('_adm_cfm_anim')) {
                const s = document.createElement('style');
                s.id = '_adm_cfm_anim';
                s.textContent = '@keyframes cfm-in{from{opacity:0}to{opacity:1}}';
                document.head.appendChild(s);
            }
            overlay.querySelector('#_adm_ok').addEventListener('click', () => { overlay.remove(); resolve(true); });
            overlay.querySelector('#_adm_cancel').addEventListener('click', () => { overlay.remove(); resolve(false); });
            overlay.addEventListener('click', e => { if (e.target === overlay) { overlay.remove(); resolve(false); } });
        });
    },

    formatDate(dateStr) {
        if (!dateStr) return '-';
        const d = new Date(dateStr);
        return d.toLocaleString('ja-JP', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
    },
};

// 起動
document.addEventListener('DOMContentLoaded', () => AdminApp.init());
