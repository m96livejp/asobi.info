/**
 * ローカルストレージ管理
 */
const Storage = {
    get(key) {
        try {
            const item = localStorage.getItem(key);
            return item ? JSON.parse(item) : null;
        } catch {
            return null;
        }
    },

    set(key, value) {
        localStorage.setItem(key, JSON.stringify(value));
    },

    remove(key) {
        localStorage.removeItem(key);
    },

    getToken() {
        return this.get('access_token');
    },

    setToken(token) {
        this.set('access_token', token);
    },

    getUserId() {
        return this.get('user_id');
    },

    setUserId(id) {
        this.set('user_id', id);
    },

    getDeviceId() {
        let deviceId = this.get('device_id');
        if (!deviceId) {
            deviceId = 'device_' + Date.now() + '_' + Math.random().toString(36).substring(2, 10);
            this.set('device_id', deviceId);
        }
        return deviceId;
    },

    clear() {
        localStorage.clear();
    },
};
