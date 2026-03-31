/**
 * 管理画面API通信クライアント
 */
const AdminAPI = {
    BASE_URL: location.hostname === 'localhost' ? 'http://localhost:8001/api' : '/api',

    getToken() {
        return localStorage.getItem('admin_token');
    },

    async request(method, path, body = null) {
        const headers = { 'Content-Type': 'application/json' };
        const token = this.getToken();
        if (token) {
            headers['Authorization'] = `Bearer ${token}`;
        }

        const options = { method, headers };
        if (body) {
            options.body = JSON.stringify(body);
        }

        const res = await fetch(this.BASE_URL + path, options);
        if (res.status === 401 || res.status === 403) {
            localStorage.removeItem('admin_token');
            location.reload();
            throw new Error('認証が必要です');
        }
        if (!res.ok) {
            const err = await res.json().catch(() => ({ detail: `HTTP ${res.status}` }));
            throw new Error(err.detail || `HTTP ${res.status}`);
        }
        return res.json();
    },

    get(path) { return this.request('GET', path); },
    post(path, body) { return this.request('POST', path, body); },
    patch(path, body) { return this.request('PATCH', path, body); },
    del(path) { return this.request('DELETE', path); },

    // Auth（管理者専用エンドポイント）
    login(email, password) {
        return this.request('POST', '/admin/login', { email, password });
    },

    // Users
    getUsers(params = {}) {
        const qs = new URLSearchParams(params).toString();
        return this.get(`/admin/users?${qs}`);
    },
    getUser(id) { return this.get(`/admin/users/${id}`); },
    updateUser(id, data) { return this.patch(`/admin/users/${id}`, data); },
    deleteUser(id) { return this.del(`/admin/users/${id}`); },
    unlinkProvider(userId, provider) { return this.post(`/admin/users/${userId}/unlink/${provider}`); },
    convertNpc(userId) { return this.post(`/admin/users/${userId}/convert-npc`); },

    // Characters
    getCharacters(params = {}) {
        const qs = new URLSearchParams(params).toString();
        return this.get(`/admin/characters?${qs}`);
    },
    updateCharacter(id, data) { return this.patch(`/admin/characters/${id}`, data); },

    // Items
    getItems(params = {}) {
        const qs = new URLSearchParams(params).toString();
        return this.get(`/admin/items?${qs}`);
    },
    updateItem(id, data) { return this.patch(`/admin/items/${id}`, data); },

    // Settings
    getSettings() { return this.get('/admin/settings'); },
    updateSettings(settings) { return this.patch('/admin/settings', { settings }); },

    // NPC Names
    getNpcNames() { return this.get('/admin/npc-names'); },
    createNpcName(name) { return this.post('/admin/npc-names', { name }); },
    deactivateNpcName(id) { return this.del(`/admin/npc-names/${id}`); },
    activateNpcName(id) { return this.post(`/admin/npc-names/${id}/activate`); },
    deleteNpcName(id) { return this.del(`/admin/npc-names/${id}/permanent`); },

    // Tournaments
    getTournaments(params = {}) {
        const qs = new URLSearchParams(params).toString();
        return this.get(`/admin/tournaments?${qs}`);
    },
    deleteTournament(id) { return this.del(`/admin/tournaments/${id}`); },
    removeEntry(tournamentId, entryId) { return this.del(`/admin/tournaments/${tournamentId}/entries/${entryId}`); },

    // Gacha
    getGachaPools() { return this.get('/admin/gacha/pools'); },
    updateGachaPool(id, data) { return this.patch(`/admin/gacha/pools/${id}`, data); },
    updateGachaWeights(poolId, weights) { return this.patch(`/admin/gacha/pools/${poolId}/weights`, { weights }); },

    // Logs
    getAdRewards(params = {}) {
        const qs = new URLSearchParams(params).toString();
        return this.get(`/admin/logs/ad-rewards?${qs}`);
    },
    getPurchases(params = {}) {
        const qs = new URLSearchParams(params).toString();
        return this.get(`/admin/logs/purchases?${qs}`);
    },
    getAuditLogs(params = {}) {
        const qs = new URLSearchParams(params).toString();
        return this.get(`/admin/logs/audit?${qs}`);
    },
};
