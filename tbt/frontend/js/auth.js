/**
 * 認証管理
 */
const Auth = {
    async init() {
        const token = Storage.getToken();
        if (token) {
            try {
                const user = await API.getProfile();
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
                return await API.getProfile();
            } catch {
                // device_idでのログイン失敗 → ログイン画面へ
            }
        }

        // ログイン画面を表示（nullを返す）
        return null;
    },

    async autoLogin() {
        const deviceId = Storage.getDeviceId();
        try {
            // 既存ユーザーでログイン
            const result = await API.login(deviceId);
            Storage.setToken(result.access_token);
            Storage.setUserId(result.user_id);
            return await API.getProfile();
        } catch {
            // 新規ゲスト登録
            return await this.register(deviceId);
        }
    },

    async register(deviceId) {
        const name = 'プレイヤー' + Math.floor(Math.random() * 9999);
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
