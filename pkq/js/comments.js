// コメント機能（共通）
const Comments = {
  currentUser: null,
  pageType: '',
  pageId: 0,

  async init(pageType, pageId) {
    this.pageType = pageType;
    this.pageId = pageId;

    // ログイン状態確認
    try {
      this.currentUser = await API.get('/api/me.php');
    } catch (e) {
      this.currentUser = null;
    }

    // UIセットアップ
    const formEl = document.getElementById('comment-form');
    const noteEl = document.getElementById('comment-login-note');
    if (this.currentUser && this.currentUser.id) {
      if (formEl) formEl.style.display = 'block';
      if (noteEl) noteEl.style.display = 'none';
    } else {
      if (formEl) formEl.style.display = 'none';
      if (noteEl) noteEl.style.display = 'block';
    }

    await this.load();
  },

  async load() {
    const listEl = document.getElementById('comments-list');
    if (!listEl) return;

    try {
      const comments = await API.get('/api/comments.php', {
        page_type: this.pageType,
        page_id: this.pageId
      });

      if (!comments || comments.length === 0) {
        listEl.innerHTML = '<p style="color:var(--text-secondary);font-size:0.85rem;">まだコメントはありません。</p>';
        return;
      }

      listEl.innerHTML = comments.map(c => this.renderComment(c)).join('');
    } catch (e) {
      listEl.innerHTML = '<p style="color:var(--text-secondary);font-size:0.85rem;">コメントの読み込みに失敗しました。</p>';
    }
  },

  renderComment(c) {
    const esc = s => String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    const name = esc(c.display_name || c.username);
    const avatar = c.avatar_url
      ? `<img src="${esc(c.avatar_url)}" class="comment-avatar" alt="">`
      : `<div class="comment-avatar" style="display:flex;align-items:center;justify-content:center;font-size:1rem;color:var(--text-secondary);">👤</div>`;

    const canDelete = this.currentUser && (
      this.currentUser.id === c.user_id ||
      this.currentUser.role === 'admin'
    );
    const deleteBtn = canDelete
      ? `<button class="comment-delete" onclick="Comments.deleteComment(${c.id})">削除</button>`
      : '';

    const date = c.created_at ? c.created_at.replace(/(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}).*/, '$1/$2/$3 $4:$5') : '';

    return `<div class="comment-item" id="comment-${c.id}">
      ${avatar}
      <div class="comment-body">
        <div class="comment-meta">
          <span class="comment-name">${name}</span>
          <span class="comment-date">${date}</span>
          ${deleteBtn}
        </div>
        <div class="comment-text">${esc(c.content)}</div>
      </div>
    </div>`;
  },

  async post() {
    const input = document.getElementById('comment-input');
    const btn = document.getElementById('comment-submit-btn');
    if (!input || !btn) return;

    const content = input.value.trim();
    if (!content) return;

    btn.disabled = true;
    btn.textContent = '投稿中...';

    try {
      const res = await fetch('/api/comments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          page_type: this.pageType,
          page_id: this.pageId,
          content: content
        })
      });
      const data = await res.json();
      if (data.error) {
        alert(data.error);
      } else {
        input.value = '';
        await this.load();
      }
    } catch (e) {
      alert('投稿に失敗しました。');
    }

    btn.disabled = false;
    btn.textContent = '投稿する';
  },

  async deleteComment(id) {
    if (!confirm('このコメントを削除しますか？')) return;

    try {
      const res = await fetch('/api/comments.php?id=' + id, { method: 'DELETE' });
      const data = await res.json();
      if (data.ok) {
        const el = document.getElementById('comment-' + id);
        if (el) el.remove();
        // リストが空になったら再描画
        const listEl = document.getElementById('comments-list');
        if (listEl && !listEl.querySelector('.comment-item')) {
          listEl.innerHTML = '<p style="color:var(--text-secondary);font-size:0.85rem;">まだコメントはありません。</p>';
        }
      } else {
        alert(data.error || '削除に失敗しました。');
      }
    } catch (e) {
      alert('削除に失敗しました。');
    }
  }
};
