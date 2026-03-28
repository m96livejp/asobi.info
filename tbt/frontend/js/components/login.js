/**
 * ログイン画面
 */
const LoginPage = {
    async render(container) {
        container.innerHTML = '<div class="loading"><div class="spinner"></div></div>';

        // asobi セッション確認
        let asobiUser = null;
        try {
            const res = await fetch('https://asobi.info/shared/assets/php/me.php', {
                credentials: 'include',
                cache: 'no-store',
            });
            if (res.ok) {
                const data = await res.json();
                if (data.loggedIn) asobiUser = data;
            }
        } catch {}

        container.innerHTML = `
            <div style="padding:24px 16px;max-width:360px;margin:0 auto;">
                <div class="text-center mb-16">
                    <div style="font-size:48px;margin-bottom:8px;">⚔️</div>
                    <div style="font-size:20px;font-weight:700;">Tournament Battle</div>
                </div>

                <!-- 利用規約 -->
                <div style="background:rgba(0,0,0,0.04);border-radius:10px;padding:12px;margin-bottom:12px;font-size:11px;color:var(--text-secondary);max-height:130px;overflow-y:auto;line-height:1.7;">
                    <div style="font-weight:700;font-size:12px;color:var(--text-primary);margin-bottom:6px;">利用規約</div>
                    <p><b>1. サービス</b><br>本サービスはオンラインゲームとして提供されます。内容は予告なく変更・終了する場合があります。</p>
                    <p style="margin-top:4px;"><b>2. アカウント</b><br>ゲストデータはデバイスに紐付けられます。データ保護のためasobiアカウント連携を推奨します。</p>
                    <p style="margin-top:4px;"><b>3. 禁止事項</b><br>不正アクセス・チート・他ユーザーへの迷惑行為を禁止します。違反時はアカウントを停止します。</p>
                    <p style="margin-top:4px;"><b>4. 免責</b><br>サービス中断・終了によるデータ損失について運営は責任を負いません。</p>
                </div>

                <!-- 同意チェックボックス -->
                <label style="display:flex;align-items:center;gap:10px;margin-bottom:20px;cursor:pointer;">
                    <input type="checkbox" id="tos-agree" style="width:18px;height:18px;cursor:pointer;accent-color:var(--accent);">
                    <span style="font-size:13px;color:var(--text-primary);font-weight:600;">利用規約に同意する</span>
                </label>

                ${asobiUser ? this._renderAsobiStart(asobiUser) : this._renderGuestStart()}
            </div>
        `;

        this.bindEvents(asobiUser);
    },

    // asobi ログイン済みの場合
    _renderAsobiStart(asobiUser) {
        const name = asobiUser.name || asobiUser.username || 'asobiユーザー';
        return `
            <div style="margin-bottom:20px;">
                <div style="font-size:13px;color:var(--text-secondary);text-align:center;margin-bottom:6px;">はじめての方 / アカウントをお持ちの方</div>
                <div style="font-size:12px;color:var(--text-secondary);text-align:center;margin-bottom:10px;">
                    ログイン中: <strong>${this._escape(name)}</strong>
                </div>
                <button class="btn btn-primary btn-sm tos-btn" id="asobi-start-btn" disabled
                    style="width:100%;padding:12px;font-size:14px;opacity:0.5;background:linear-gradient(135deg,#667eea,#764ba2);transition:opacity 0.2s;">
                    asobiアカウントではじめる
                </button>
                <p style="font-size:11px;color:var(--text-secondary);text-align:center;margin-top:8px;">
                    asobiアカウントに紐づけてゲームを開始します
                </p>
            </div>
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
                <div style="flex:1;height:1px;background:var(--text-secondary);opacity:0.3;"></div>
                <span style="font-size:11px;color:var(--text-secondary);">ゲストで遊ぶには</span>
                <div style="flex:1;height:1px;background:var(--text-secondary);opacity:0.3;"></div>
            </div>
            <p style="font-size:11px;color:var(--text-secondary);text-align:center;">
                <a href="https://asobi.info/logout.php?redirect=${encodeURIComponent('https://tbt.asobi.info/')}"
                   style="color:var(--accent);">asobiアカウントからログアウト</a>してください
            </p>
        `;
    },

    // asobi 未ログインの場合
    _renderGuestStart() {
        const loginUrl = 'https://asobi.info/login.php?redirect=' + encodeURIComponent('https://tbt.asobi.info/');
        return `
            <div style="margin-bottom:20px;">
                <div style="font-size:13px;color:var(--text-secondary);text-align:center;margin-bottom:10px;">はじめての方</div>
                <button class="btn btn-primary btn-sm tos-btn" id="guest-start-btn" disabled
                    style="width:100%;padding:12px;font-size:14px;opacity:0.5;transition:opacity 0.2s;">新規ではじめる</button>
                <p style="font-size:11px;color:var(--text-secondary);text-align:center;margin-top:8px;">
                    <a href="${loginUrl}" style="color:var(--accent);">asobiアカウント</a>でログイン後に始めるとデータが保護されます
                </p>
            </div>

            <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
                <div style="flex:1;height:1px;background:var(--text-secondary);opacity:0.3;"></div>
                <span style="font-size:12px;color:var(--text-secondary);">アカウントをお持ちの方</span>
                <div style="flex:1;height:1px;background:var(--text-secondary);opacity:0.3;"></div>
            </div>

            <div style="display:flex;flex-direction:column;gap:10px;">
                <button class="btn btn-sm tos-btn" id="asobi-login-btn" disabled
                    style="width:100%;padding:14px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;opacity:0.5;transition:opacity 0.2s;">
                    asobiアカウントではじめる
                </button>
                <p style="font-size:11px;color:var(--text-secondary);text-align:center;margin-top:4px;">
                    asobi.info のアカウントでログインします
                </p>
            </div>
        `;
    },

    bindEvents(asobiUser) {
        // 利用規約チェックボックス
        const tosCheckbox = document.getElementById('tos-agree');
        const tosBtns = document.querySelectorAll('.tos-btn');
        tosCheckbox?.addEventListener('change', () => {
            tosBtns.forEach(btn => {
                btn.disabled = !tosCheckbox.checked;
                btn.style.opacity = tosCheckbox.checked ? '1' : '0.5';
            });
        });

        const startAsobi = async (btnId) => {
            const btn = document.getElementById(btnId);
            if (!btn) return;
            btn.disabled = true;
            try {
                const res = await fetch('/api/auth/asobi/url');
                if (!res.ok) throw new Error('URLの取得に失敗しました');
                const { url } = await res.json();
                location.href = url;
            } catch (e) {
                await UI.alert(e.message);
                btn.disabled = false;
                // 利用規約に同意済みなら再度有効化
                if (tosCheckbox?.checked) btn.disabled = false;
            }
        };

        if (asobiUser) {
            document.getElementById('asobi-start-btn')?.addEventListener('click', () => startAsobi('asobi-start-btn'));
        } else {
            document.getElementById('asobi-login-btn')?.addEventListener('click', () => startAsobi('asobi-login-btn'));

            document.getElementById('guest-start-btn')?.addEventListener('click', async () => {
                const btn = document.getElementById('guest-start-btn');
                btn.disabled = true;
                try {
                    await Auth.autoLogin();
                    location.reload();
                } catch (e) {
                    await UI.alert(e.message);
                    if (tosCheckbox?.checked) btn.disabled = false;
                }
            });
        }
    },

    _escape(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },
};
