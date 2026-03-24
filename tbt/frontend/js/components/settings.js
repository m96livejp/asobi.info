/**
 * 設定画面
 */
const SettingsPage = {
    async render(container) {
        container.innerHTML = '<div class="loading"><div class="spinner"></div>読み込み中...</div>';

        try {
            const accounts = await API.getLinkedAccounts();

            container.innerHTML = `
                <div class="card-title mb-8">アカウント設定</div>

                <div class="settings-section">
                    <div class="settings-label">表示名</div>
                    <div class="settings-row">
                        <input type="text" id="settings-name" class="settings-input"
                               value="${this.escape(App.user.display_name)}" maxlength="50">
                        <button class="btn btn-primary btn-sm" id="save-name-btn">保存</button>
                    </div>
                </div>

                <div class="card-title mb-8 mt-16">アカウント連携</div>
                <p style="font-size:12px;color:var(--text-secondary);margin-bottom:12px;">
                    連携するとデバイスが変わってもデータを引き継げます
                </p>

                <div class="settings-section">
                    <div class="settings-row mb-8">
                        <div>
                            <span style="font-weight:600;font-size:13px;">asobiアカウント</span>
                            ${accounts.has_asobi
                                ? '<span style=\'font-size:11px;color:var(--success);margin-left:6px;\'>✅ 連携済み</span>'
                                : '<span style=\'font-size:11px;color:var(--accent);margin-left:6px;\'>未連携</span>'
                            }
                        </div>
                        ${!accounts.has_asobi
                            ? '<button class="btn btn-primary btn-sm" id="asobi-link-btn">連携する</button>'
                            : '<button class="btn btn-secondary btn-sm" id="asobi-unlink-btn" style="font-size:11px;padding:4px 10px;">解除</button>'
                        }
                    </div>
                </div>

                <div class="card-title mb-8 mt-16">その他</div>
                <div class="settings-section">
                    <div class="settings-row">
                        <span style="font-size:13px;color:var(--text-secondary);">ユーザーID</span>
                        <span style="font-size:11px;color:var(--text-secondary);word-break:break-all;">${App.user.id}</span>
                    </div>
                    <button class="btn btn-secondary btn-sm mt-8" id="terms-btn" style="width:100%;">利用規約</button>
                    <button class="btn btn-secondary btn-sm mt-8" id="clear-cache-btn" style="width:100%;">🔄 アプリを最新版に更新</button>
                    <button class="btn btn-secondary btn-sm mt-8" id="logout-btn" style="width:100%;">ログアウト</button>
                    <button class="btn btn-sm mt-8" id="delete-account-btn"
                        style="width:100%;background:var(--accent);color:#2a1040;border:none;border-radius:8px;padding:10px;">アカウント削除</button>
                </div>
            `;

            this.bindEvents(accounts);
        } catch (e) {
            container.innerHTML = `<div class="text-center" style="padding:40px;color:var(--accent);">エラー: ${e.message}</div>`;
        }
    },

    bindEvents(accounts) {
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

        // asobi連携解除
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

        // asobi連携（現在のtbtトークンをlink_tokenとして渡す）
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
                // サービスワーカーの全登録を解除
                const registrations = await navigator.serviceWorker.getRegistrations();
                for (const reg of registrations) {
                    await reg.unregister();
                }
                // 全キャッシュを削除
                const keys = await caches.keys();
                await Promise.all(keys.map(k => caches.delete(k)));
            } catch (e) {
                // SW非対応環境でも続行
            }
            location.reload();
        });

        // ログアウト
        document.getElementById('logout-btn')?.addEventListener('click', async () => {
            if (!accounts.has_asobi) {
                const ok = await UI.confirmDanger(
                    'asobiアカウントが未連携です。\n\n' +
                    '「設定 → 連携する」でasobiアカウントを連携してから\nログアウトすることをお勧めします。\n',
                    [
                        'ログアウトすると、このアカウントには',
                        '二度とログインできなくなります。',
                        'キャラクターや進行状況はすべて失われます。',
                    ]
                );
                if (!ok) return;
                if (!await UI.confirm('本当にログアウトしますか？\n最終確認です。')) return;
            } else {
                if (!await UI.confirm('ログアウトしますか？')) return;
            }
            Auth.logout();
        });

        // アカウント削除
        document.getElementById('delete-account-btn')?.addEventListener('click', async () => {
            const ok = await UI.confirmDanger(
                'アカウントを削除しようとしています。\n',
                [
                    'すべてのキャラクター・進行状況・アイテムが削除されます。',
                    'この操作は取り消せません。',
                ],
                '削除する'
            );
            if (!ok) return;
            if (!await UI.confirm('本当に削除しますか？\n最終確認です。')) return;
            try {
                await API.deleteAccount();
                Storage.clear();
                alert('アカウントを削除しました');
                location.reload();
            } catch (e) {
                alert(e.message);
            }
        });
    },

    escape(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },
};
