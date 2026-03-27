/**
 * 設定画面
 */
const SettingsPage = {
    async render(container) {
        container.innerHTML = '<div class="loading"><div class="spinner"></div>読み込み中...</div>';

        try {
            const accounts = await API.getLinkedAccounts();
            const isGuest = !accounts.has_asobi;

            container.innerHTML = `
                <div class="card-title mb-8">設定</div>

                <div class="settings-section">
                    <div class="settings-label">表示名</div>
                    <div class="settings-row">
                        <input type="text" id="settings-name" class="settings-input"
                               value="${this.escape(App.user.display_name)}" maxlength="50">
                        <button class="btn btn-primary btn-sm" id="save-name-btn">保存</button>
                    </div>
                </div>

                ${isGuest ? this.renderGuest() : this.renderLinked()}

                <div class="card-title mb-8 mt-16">その他</div>
                <div class="settings-section">
                    <div class="settings-row">
                        <span style="font-size:13px;color:var(--text-secondary);">ユーザーID</span>
                        <span style="font-size:11px;color:var(--text-secondary);word-break:break-all;">${App.user.id}</span>
                    </div>
                    <button class="btn btn-secondary btn-sm mt-8" id="terms-btn" style="width:100%;">利用規約</button>
                    <button class="btn btn-secondary btn-sm mt-8" id="clear-cache-btn" style="width:100%;">🔄 アプリを最新版に更新</button>
                </div>
            `;

            this.bindEvents(accounts, isGuest);
        } catch (e) {
            container.innerHTML = `<div class="text-center" style="padding:40px;color:var(--accent);">エラー: ${e.message}</div>`;
        }
    },

    // ─── ゲスト状態の表示 ───
    renderGuest() {
        return `
            <div class="card-title mb-8 mt-16">asobiアカウント</div>
            <div class="settings-section">
                <p style="font-size:12px;color:var(--text-secondary);margin-bottom:12px;line-height:1.7;">
                    asobiアカウントでログインすると、データが保存され<br>
                    別の端末からでも同じデータで遊べます。
                </p>
                <button class="btn btn-primary btn-sm" id="asobi-link-btn" style="width:100%;">asobiアカウントでログイン</button>
            </div>

            <div class="card-title mb-8 mt-16">ゲストデータ</div>
            <div class="settings-section">
                <p style="font-size:12px;color:var(--text-secondary);margin-bottom:12px;line-height:1.7;">
                    現在はゲストとしてプレイ中です。<br>
                    ゲストデータはこの端末にのみ保存されています。
                </p>
                <button class="btn btn-sm mt-8" id="guest-delete-btn"
                    style="width:100%;background:#e0e0e0;color:#555;border:none;border-radius:8px;padding:10px;">ゲストデータを削除</button>
            </div>`;
    },

    // ─── asobi連携済みの表示 ───
    renderLinked() {
        return `
            <div class="card-title mb-8 mt-16">asobiアカウント</div>
            <div class="settings-section">
                <div class="settings-row mb-8">
                    <div>
                        <span style="font-weight:600;font-size:13px;">asobiアカウント</span>
                        <span style='font-size:11px;color:var(--success);margin-left:6px;'>✅ 連携済み</span>
                    </div>
                    <button class="btn btn-secondary btn-sm" id="asobi-unlink-btn" style="font-size:11px;padding:4px 10px;">解除</button>
                </div>
            </div>

            <div class="card-title mb-8 mt-16">アカウント操作</div>
            <div class="settings-section">
                <button class="btn btn-secondary btn-sm mt-8" id="logout-btn" style="width:100%;">ログアウト</button>
                <button class="btn btn-sm mt-8" id="delete-account-btn"
                    style="width:100%;background:var(--accent);color:#2a1040;border:none;border-radius:8px;padding:10px;">アカウント削除</button>
            </div>`;
    },

    bindEvents(accounts, isGuest) {
        // 表示名保存
        document.getElementById('save-name-btn')?.addEventListener('click', async () => {
            const name = document.getElementById('settings-name').value.trim();
            if (!name) return;
            try {
                await API.updateProfile({ display_name: name });
                App.user.display_name = name;
                alert('保存しました');
            } catch (e) {
                alert(e.message);
            }
        });

        // asobi連携解除（連携済みのみ）
        document.getElementById('asobi-unlink-btn')?.addEventListener('click', async () => {
            if (!await UI.confirm('asobiアカウントの連携を解除しますか？\n\n解除後もこのアカウントの他の認証手段（デバイスIDなど）でログインできます。')) return;
            try {
                await API.unlinkAccount('asobi');
                alert('asobiアカウントの連携を解除しました');
                App.navigate('settings');
            } catch (e) {
                alert(e.message);
            }
        });

        // asobi連携・ログイン（ゲストのみ）
        document.getElementById('asobi-link-btn')?.addEventListener('click', async () => {
            try {
                const linkToken = Storage.getToken() || '';
                const res = await fetch('/api/auth/asobi/url?link_token=' + encodeURIComponent(linkToken));
                if (!res.ok) throw new Error('URLの取得に失敗しました');
                const { url } = await res.json();
                location.href = url;
            } catch (e) {
                alert(e.message);
            }
        });

        // 利用規約
        document.getElementById('terms-btn')?.addEventListener('click', () => App.navigate('terms'));

        // キャッシュクリア・最新版更新
        document.getElementById('clear-cache-btn')?.addEventListener('click', async () => {
            if (!await UI.confirm('アプリを最新版に更新します。\nキャッシュをクリアしてリロードします。')) return;
            try {
                const registrations = await navigator.serviceWorker.getRegistrations();
                for (const reg of registrations) {
                    await reg.unregister();
                }
                const keys = await caches.keys();
                await Promise.all(keys.map(k => caches.delete(k)));
            } catch (e) {
                // SW非対応環境でも続行
            }
            location.reload();
        });

        if (isGuest) {
            // ─── ゲスト：データ削除 ───
            document.getElementById('guest-delete-btn')?.addEventListener('click', async () => {
                const ok = await UI.confirmDanger(
                    'ゲストデータを削除します。\n',
                    [
                        'キャラクターや進行状況はすべて失われます。',
                        'この操作は取り消せません。',
                    ],
                    'ゲストデータを削除'
                );
                if (!ok) return;
                if (!await UI.confirm('本当に削除しますか？\n\nasobiアカウントでログインすれば\nいつでも新しく始められます。')) return;
                try {
                    await API.deleteAccount();
                    Storage.clear();
                    location.reload();
                } catch (e) {
                    // API失敗でもローカルデータはクリア
                    Storage.clear();
                    location.reload();
                }
            });
        } else {
            // ─── 連携済み：ログアウト ───
            document.getElementById('logout-btn')?.addEventListener('click', async () => {
                if (!await UI.confirm('ログアウトしますか？')) return;
                Auth.logout();
            });

            // ─── 連携済み：アカウント削除 ───
            document.getElementById('delete-account-btn')?.addEventListener('click', async () => {
                const ok = await UI.confirmDanger(
                    'ゲームデータを削除します。\n',
                    [
                        'すべてのキャラクター・進行状況・アイテムが削除されます。',
                        'asobiアカウント自体は削除されません。',
                        'この操作は取り消せません。',
                    ],
                    '削除する'
                );
                if (!ok) return;
                if (!await UI.confirm('本当に削除しますか？\n最終確認です。')) return;
                try {
                    await API.deleteAccount();
                    Storage.clear();
                    location.reload();
                } catch (e) {
                    alert(e.message);
                }
            });
        }
    },

    escape(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },
};
