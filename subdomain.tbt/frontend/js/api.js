/**
 * API通信クライアント
 */
const API = {
    // 開発時: localhost:8001, 本番時: 同一ドメイン
    BASE_URL: location.hostname === 'localhost' ? 'http://localhost:8001/api' : '/api',

    async request(method, path, body = null) {
        const headers = { 'Content-Type': 'application/json' };
        const token = Storage.getToken();
        if (token) {
            headers['Authorization'] = `Bearer ${token}`;
        }

        const options = { method, headers };
        if (body) {
            options.body = JSON.stringify(body);
        }

        const res = await fetch(this.BASE_URL + path, options);
        if (!res.ok) {
            const err = await res.json().catch(() => ({ detail: 'Network error' }));
            throw new Error(err.detail || `HTTP ${res.status}`);
        }
        return res.json();
    },

    get(path) { return this.request('GET', path); },
    post(path, body) { return this.request('POST', path, body); },
    patch(path, body) { return this.request('PATCH', path, body); },
    delete(path) { return this.request('DELETE', path); },

    // Auth - Guest
    guestRegister(deviceId, displayName) {
        return this.post('/auth/guest', { device_id: deviceId, display_name: displayName });
    },
    login(deviceId) {
        return this.post('/auth/login', { device_id: deviceId });
    },

    // Auth - OAuth
    getOAuthUrl(provider, mode) {
        const endpoint = mode === 'link'
            ? `/auth/oauth/${provider}/url/link`
            : `/auth/oauth/${provider}/url?mode=${mode}`;
        return this.get(endpoint);
    },

    // Auth - Email
    emailRegister(email, password, displayName) {
        return this.post('/auth/email/register', { email, password, display_name: displayName });
    },
    emailLogin(email, password) {
        return this.post('/auth/email/login', { email, password });
    },

    // Auth - Account Management
    getLinkedAccounts() { return this.get('/auth/me/accounts'); },
    unlinkAccount(provider) { return this.delete(`/auth/me/accounts/${provider}`); },
    deleteAccount() { return this.delete('/auth/me'); },

    // User
    getProfile() { return this.get('/users/me'); },
    updateProfile(data) { return this.patch('/users/me', data); },
    getRanking(limit = 50) { return this.get(`/users/ranking?limit=${limit}`); },

    // Characters
    getCharacters() { return this.get('/characters'); },
    getCharacter(id) { return this.get(`/characters/${id}`); },
    getTemplates() { return this.get('/characters/templates'); },
    toggleFavorite(id) { return this.post(`/characters/${id}/favorite`); },
    trainCharacter(id, soldierId) { return this.post(`/characters/${id}/train`, { soldier_id: soldierId }); },

    // Gacha
    getGachaPools() { return this.get('/gacha/pools'); },
    pullGacha(poolId, count, useTicket = false) { return this.post('/gacha/pull', { pool_id: poolId, count, use_ticket: useTicket }); },
    getGachaHistory(poolId) { return this.get(`/gacha/history?pool_id=${poolId}`); },

    // Battles
    practiceBattle(attackerId, defenderId) {
        return this.post(`/battles/practice?attacker_id=${attackerId}&defender_id=${defenderId}`);
    },
    getBattle(battleId) { return this.get(`/battles/${battleId}`); },

    // Tournaments
    getTournaments() { return this.get('/tournaments'); },
    getActiveCharacterIds() { return this.get('/tournaments/active-character-ids'); },
    createTournament(name, maxParticipants, rewardPoints) {
        return this.post(`/tournaments?name=${encodeURIComponent(name)}&max_participants=${maxParticipants}&reward_points=${rewardPoints}`);
    },
    enterTournament(tournamentId, characterId) {
        return this.post(`/tournaments/${tournamentId}/entry`, { character_id: characterId });
    },
    executeBattle(tournamentId) {
        return this.post(`/tournaments/${tournamentId}/battle`);
    },
    getBracket(tournamentId) {
        return this.get(`/tournaments/${tournamentId}/bracket`);
    },

    // Items
    getItems() { return this.get('/items'); },
    useItem(itemId) { return this.post(`/items/${itemId}/use`); },
    getEquipment(charId) { return this.get(`/items/equipment/${charId}`); },
    equip(charId, slot, templateId) { return this.post(`/items/equipment/${charId}/equip`, { slot, item_template_id: templateId }); },
    unequip(charId, slot) { return this.post(`/items/equipment/${charId}/unequip`, { slot }); },

    // Rewards
    getAdStatus() { return this.get('/rewards/ad/status'); },
    claimAdReward(rewardType) {
        return this.post('/rewards/ad', { reward_type: rewardType, ad_type: 'rewarded' });
    },

    // Shop
    getProducts() { return this.get('/shop/products'); },
    purchase(productId) { return this.post('/shop/purchase', { product_id: productId }); },
};
