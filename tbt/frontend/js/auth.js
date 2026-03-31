/**
 * 認証管理
 */
const Auth = {
    async init() {
        const token = Storage.getToken();
        if (token) {
            try {
                const user = await API.getProfile();
                const me = await this._checkAsobiSession();
                if (user.asobi_user_id) {
                    // asobi連携済み: セッションが別ユーザーならクリア
                    if (me && me.userId && me.userId !== user.asobi_user_id) {
                        Storage.clear();
                        return null;
                    }
                } else {
                    // ゲスト: asobiにログイン中なら別ユーザーの可能性があるのでクリア
                    if (me && me.userId) {
                        Storage.clear();
                        return null;
                    }
                }
                return user;
            } catch {
                // トークン無効 → トークン削除
                Storage.remove('access_token');
            }
        }

        // device_idがあれば自動ログイン試行
        const deviceId = Storage.get('device_id');
        if (deviceId) {
            try {
                const result = await API.login(deviceId);
                Storage.setToken(result.access_token);
                Storage.setUserId(result.user_id);
                const user = await API.getProfile();
                const me = await this._checkAsobiSession();
                if (user.asobi_user_id) {
                    if (me && me.userId && me.userId !== user.asobi_user_id) {
                        Storage.clear();
                        return null;
                    }
                } else {
                    if (me && me.userId) {
                        Storage.clear();
                        return null;
                    }
                }
                return user;
            } catch {
                // device_idでのログイン失敗 → ログイン画面へ
            }
        }

        // ログイン画面を表示（nullを返す）
        return null;
    },

    async _checkAsobiSession() {
        try {
            const res = await fetch('https://asobi.info/shared/assets/php/me.php', {
                credentials: 'include',
                cache: 'no-store',
            });
            if (!res.ok) return null;
            const data = await res.json();
            return data.loggedIn ? data : null;
        } catch {
            return null;
        }
    },

    async autoLogin(displayName) {
        const deviceId = Storage.getDeviceId();
        try {
            // 既存ユーザーでログイン
            const result = await API.login(deviceId);
            Storage.setToken(result.access_token);
            Storage.setUserId(result.user_id);
            return await API.getProfile();
        } catch {
            // 新規ゲスト登録
            return await this.register(deviceId, displayName);
        }
    },

    async register(deviceId, displayName) {
        const name = displayName || ('冒険者' + Math.floor(Math.random() * 9000 + 1000));
        const result = await API.guestRegister(deviceId, name);
        Storage.setToken(result.access_token);
        Storage.setUserId(result.user_id);
        return await API.getProfile();
    },

    logout() {
        Storage.clear();
        location.reload();
    },
};
