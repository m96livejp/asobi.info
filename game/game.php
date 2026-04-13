<?php
/**
 * ゲーム詳細ページ
 * URL: /game.php?platform=nes&slug=contra
 *      または Nginx rewrite: /nes/contra → /game.php?platform=nes&slug=contra
 */
require_once __DIR__ . '/api/db.php';
require_once '/opt/asobi/shared/assets/php/auth.php';
require_once '/opt/asobi/shared/assets/php/version.php';
session_write_close();

$platform = $_GET['platform'] ?? '';
$slug     = $_GET['slug']     ?? '';

if (!in_array($platform, $VALID_PLATFORMS, true) || !preg_match('/^[a-z0-9_\-]+$/', $slug)) {
    http_response_code(404);
    readfile(__DIR__ . '/error.html');
    exit;
}

$db = gameDb();
$stmt = $db->prepare("SELECT * FROM games WHERE platform = ? AND slug = ?");
$stmt->execute([$platform, $slug]);
$game = $stmt->fetch();

if (!$game) {
    http_response_code(404);
    readfile(__DIR__ . '/error.html');
    exit;
}

// サブ画像（スクリーンショット等を複数）
$shotStmt = $db->prepare("SELECT * FROM game_screenshots WHERE game_id = ? ORDER BY sort_order, id");
$shotStmt->execute([$game['id']]);
$screenshots = $shotStmt->fetchAll();

$platformLabels = [
    'nes' => 'ファミコン', 'snes' => 'スーパーファミコン',
    'pce' => 'PCエンジン', 'md'   => 'メガドライブ', 'msx' => 'MSX',
];
$platformLabel = $platformLabels[$platform] ?? $platform;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($game['title']) ?> - <?= htmlspecialchars($platformLabel) ?> | あそびゲーム情報</title>
  <meta name="description" content="<?= htmlspecialchars($game['title']) ?>（<?= htmlspecialchars($platformLabel) ?>）のゲーム情報。<?= $game['description'] ? htmlspecialchars(mb_substr($game['description'], 0, 80)) . '...' : '' ?>">
  <link rel="stylesheet" href="/css/style.css?v=<?= assetVer('/css/style.css') ?>">
</head>
<body>
  <header class="site-header">
    <div class="container">
      <div class="site-logo">
        <a href="/">ゲーム<span>.asobi.info</span></a>
      </div>
      <nav class="site-nav">
        <?php
        $platforms = ['nes' => 'FC', 'snes' => 'SFC', 'pce' => 'PCE', 'md' => 'MD', 'msx' => 'MSX'];
        foreach ($platforms as $key => $label):
        ?>
        <a href="/<?= $key ?>/" <?= $key === $platform ? 'class="active"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
  </header>

  <main>
    <div class="page-content">
      <div class="breadcrumb">
        <a href="/">ゲーム情報</a> &rsaquo;
        <a href="/<?= $platform ?>/"><?= htmlspecialchars($platformLabel) ?></a> &rsaquo;
        <?= htmlspecialchars($game['title']) ?>
      </div>

      <?php
        // 表示する画像を優先度順に決定
        $primaryImg = $game['title_image'] ?? $game['box_image'] ?? $game['cart_image'] ?? null;
        $hasGallery = !empty($game['box_image']) || !empty($game['title_image']) || !empty($game['cart_image']);
      ?>
      <div class="game-detail">
        <div>
          <div class="game-detail-img">
            <?php if ($primaryImg): ?>
            <img src="<?= htmlspecialchars($primaryImg) ?>" alt="<?= htmlspecialchars($game['title']) ?>">
            <?php else: ?>
            🎮
            <?php endif; ?>
          </div>
        </div>

        <div class="game-detail-body">
          <h1><?= htmlspecialchars($game['title']) ?></h1>
          <?php if ($game['title_en']): ?>
          <div class="game-en"><?= htmlspecialchars($game['title_en']) ?></div>
          <?php endif; ?>

          <div class="game-meta">
            <span class="game-meta-badge"><strong>機種</strong> <?= htmlspecialchars($platformLabel) ?></span>
            <?php if (!empty($game['genre'])): ?>
            <span class="game-meta-badge"><strong>ジャンル</strong> <?= htmlspecialchars($game['genre']) ?></span>
            <?php endif; ?>
            <?php if (!empty($game['developer'])): ?>
            <span class="game-meta-badge"><strong>開発</strong> <?= htmlspecialchars($game['developer']) ?></span>
            <?php endif; ?>
            <?php if (!empty($game['publisher'])): ?>
            <span class="game-meta-badge"><strong>発売</strong> <?= htmlspecialchars($game['publisher']) ?></span>
            <?php endif; ?>
            <?php if (!empty($game['release_date'])): ?>
            <span class="game-meta-badge"><strong>発売日</strong> <?= htmlspecialchars($game['release_date']) ?></span>
            <?php elseif (!empty($game['release_year'])): ?>
            <span class="game-meta-badge"><strong>発売年</strong> <?= $game['release_year'] ?>年</span>
            <?php endif; ?>
            <?php if (!empty($game['price'])): ?>
            <span class="game-meta-badge"><strong>定価</strong> &yen;<?= number_format($game['price']) ?></span>
            <?php endif; ?>
            <?php if (!empty($game['players'])): ?>
            <span class="game-meta-badge"><strong>プレイ人数</strong> <?= htmlspecialchars($game['players']) ?></span>
            <?php endif; ?>
            <?php if (!empty($game['rom_size'])): ?>
            <span class="game-meta-badge"><strong>ROM</strong> <?= htmlspecialchars($game['rom_size']) ?></span>
            <?php endif; ?>
            <?php if (!empty($game['catalog_no'])): ?>
            <span class="game-meta-badge"><strong>型番</strong> <?= htmlspecialchars($game['catalog_no']) ?></span>
            <?php endif; ?>
            <?php if (!empty($game['md_number'])): ?>
            <span class="game-meta-badge"><strong>ROM No.</strong> <?= htmlspecialchars($game['md_number']) ?></span>
            <?php endif; ?>
          </div>

          <?php if ($game['description']): ?>
          <div class="game-desc"><?= nl2br(htmlspecialchars($game['description'])) ?></div>
          <?php endif; ?>

          <?php if (!empty($game['description_en'])): ?>
          <div class="game-desc-en">
            <h3>Overview</h3>
            <?= nl2br(htmlspecialchars($game['description_en'])) ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php
        // メイン画像（パッケージ・タイトル画面・カートリッジ）
        $mainImages = [
            ['key' => 'box_image',   'label' => 'パッケージ'],
            ['key' => 'title_image', 'label' => 'タイトル画面'],
            ['key' => 'cart_image',  'label' => 'カートリッジ'],
        ];
        $hasMainImages = false;
        foreach ($mainImages as $mi) {
            if (!empty($game[$mi['key']])) { $hasMainImages = true; break; }
        }
        $hasAnyGallery = $hasMainImages || !empty($screenshots);
      ?>
      <?php if ($hasAnyGallery): ?>
      <section class="game-gallery">
        <h2>ギャラリー</h2>

        <?php if ($hasMainImages): ?>
        <div class="gallery-grid gallery-main">
          <?php foreach ($mainImages as $mi):
              if (empty($game[$mi['key']])) continue;
          ?>
          <figure class="gallery-item">
            <button type="button" class="gallery-trigger"
                    data-full="<?= htmlspecialchars($game[$mi['key']]) ?>"
                    data-caption="<?= htmlspecialchars($game['title'] . ' / ' . $mi['label']) ?>">
              <img src="<?= htmlspecialchars($game[$mi['key']]) ?>" alt="<?= htmlspecialchars($game['title']) ?> <?= $mi['label'] ?>" loading="lazy">
            </button>
            <figcaption><?= $mi['label'] ?></figcaption>
          </figure>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($screenshots)): ?>
        <h3 class="gallery-subheading">スクリーンショット</h3>
        <div class="gallery-grid gallery-screens">
          <?php foreach ($screenshots as $s): ?>
          <figure class="gallery-item">
            <button type="button" class="gallery-trigger"
                    data-full="<?= htmlspecialchars($s['image_path']) ?>"
                    data-caption="<?= htmlspecialchars($s['caption'] ?: $game['title']) ?>">
              <img src="<?= htmlspecialchars($s['image_path']) ?>" alt="<?= htmlspecialchars($s['caption'] ?: $game['title']) ?>" loading="lazy">
            </button>
            <?php if (!empty($s['caption'])): ?>
            <figcaption><?= htmlspecialchars($s['caption']) ?></figcaption>
            <?php endif; ?>
          </figure>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($game['source_url'])): ?>
        <p class="gallery-source">
          画像・概要出典:
          <a href="<?= htmlspecialchars($game['source_url']) ?>" target="_blank" rel="noopener nofollow">
            <?= htmlspecialchars($game['source_attribution'] ?: 'Sega Retro') ?>
          </a>
        </p>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <!-- 裏技・コードセクション -->
      <section class="tips-section">
        <h2>裏技・コード</h2>
        <div class="tips-list" id="tips-list">
          <div class="loading"><div class="spinner"></div></div>
        </div>

        <details class="tips-form-toggle">
          <summary>裏技・コード情報を投稿する</summary>
          <div class="tips-form" id="tips-form">
            <?php if (asobiIsLoggedIn()): ?>
            <p style="font-size:0.82rem;color:var(--text2);margin-bottom:10px;">
              投稿者: <strong><?= htmlspecialchars($_SESSION['asobi_user_name'] ?? 'ユーザー') ?></strong>
            </p>
            <?php else: ?>
            <p style="font-size:0.82rem;color:var(--text2);margin-bottom:10px;">
              <a href="https://asobi.info/login.php?redirect=<?= urlencode('https://game.asobi.info/' . $platform . '/' . $slug . '.html') ?>" style="color:var(--accent);">ログイン</a>するとユーザー名で投稿できます。
            </p>
            <?php endif; ?>
            <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
              <select id="tip-category" style="padding:7px 12px;border-radius:6px;border:1px solid var(--border);background:var(--card-bg,#fff);font-size:0.88rem;">
                <option value="cheat">裏技</option>
                <option value="code">コマンド</option>
                <option value="bug">バグ技</option>
                <option value="other">その他</option>
              </select>
              <input type="text" id="tip-title" placeholder="タイトル（例：無限1UP）" maxlength="100" style="flex:1;min-width:180px;padding:7px 12px;border-radius:6px;border:1px solid var(--border);font-size:0.88rem;">
            </div>
            <textarea id="tip-content" placeholder="裏技の手順やコマンドを入力してください..." maxlength="2000" rows="4"></textarea>
            <div class="form-hint">※ 投稿は確認後に公開されます（最大2000文字）</div>
            <button class="btn-submit" id="tip-submit">投稿する</button>
            <div class="form-msg" id="tip-msg"></div>
          </div>
        </details>
      </section>

      <!-- コメントセクション -->
      <section class="comments-section">
        <h2>コメント</h2>
        <div class="comment-list" id="comment-list">
          <div class="loading"><div class="spinner"></div></div>
        </div>

        <div class="comment-form" id="comment-form">
          <h3>コメントを投稿する</h3>
          <?php if (asobiIsLoggedIn()): ?>
          <p style="font-size:0.82rem;color:var(--text2);margin-bottom:10px;">
            投稿者: <strong><?= htmlspecialchars($_SESSION['asobi_user_name'] ?? 'ユーザー') ?></strong>
          </p>
          <?php else: ?>
          <p style="font-size:0.82rem;color:var(--text2);margin-bottom:10px;">
            <a href="https://asobi.info/login.php?redirect=<?= urlencode('https://game.asobi.info/' . $platform . '/' . $slug . '.html') ?>" style="color:var(--accent);">ログイン</a>するとユーザー名で投稿できます（ゲストのまま投稿も可）。
          </p>
          <?php endif; ?>
          <textarea id="comment-content" placeholder="ゲームの感想・攻略情報などを書いてください..." maxlength="1000"></textarea>
          <div class="form-hint">※ コメントは確認後に公開されます（最大1000文字）</div>
          <button class="btn-submit" id="comment-submit">投稿する</button>
          <div class="form-msg" id="form-msg"></div>
        </div>
      </section>
    </div>
  </main>

  <!-- 画像ライトボックス -->
  <div id="lightbox" class="lightbox" hidden role="dialog" aria-modal="true" aria-label="画像拡大">
    <button type="button" class="lightbox-close" aria-label="閉じる">×</button>
    <figure class="lightbox-figure">
      <img src="" alt="" class="lightbox-img">
      <figcaption class="lightbox-caption"></figcaption>
    </figure>
  </div>

  <footer class="site-footer">
    <p>
      <a href="/">ゲーム情報トップ</a>
      &nbsp;·&nbsp;
      <a href="/<?= $platform ?>/"><?= htmlspecialchars($platformLabel) ?></a>
      &nbsp;·&nbsp;
      <a href="https://asobi.info/">あそび</a>
    </p>
    <p style="margin-top:6px;">&copy; 2026 あそび</p>
  </footer>

  <script>
  const GAME_ID = <?= (int)$game['id'] ?>;
  const IS_LOGGED_IN = <?= asobiIsLoggedIn() ? 'true' : 'false' ?>;

  (function() {
    // === 裏技 ===
    const CATEGORY_LABELS = {cheat:'裏技', code:'コマンド', bug:'バグ技', other:'その他'};

    async function loadTips() {
      const list = document.getElementById('tips-list');
      try {
        const res = await fetch('/api/tips.php?action=list&game_id=' + GAME_ID);
        const data = await res.json();
        if (!data.ok) throw new Error(data.error);
        if (data.tips.length === 0) {
          list.innerHTML = '<p class="comment-empty">まだ裏技情報はありません。情報をお持ちの方はぜひ投稿してください！</p>';
          return;
        }
        list.innerHTML = data.tips.map(t => `
          <div class="tip-item">
            <div class="tip-header">
              <span class="tip-category tip-cat-${escHtml(t.category)}">${escHtml(CATEGORY_LABELS[t.category] || t.category)}</span>
              <span class="tip-title">${escHtml(t.title)}</span>
            </div>
            <div class="tip-body">${escHtml(t.content).replace(/\n/g, '<br>')}</div>
            <div class="tip-footer">
              <span>${escHtml(t.username || 'ゲスト')}</span>
              <span>${escHtml(t.created_at.slice(0, 10))}</span>
            </div>
          </div>
        `).join('');
      } catch (e) {
        list.innerHTML = '<p class="comment-empty">裏技情報の読み込みに失敗しました。</p>';
      }
    }

    document.getElementById('tip-submit')?.addEventListener('click', async function() {
      const category = document.getElementById('tip-category').value;
      const title = document.getElementById('tip-title').value.trim();
      const content = document.getElementById('tip-content').value.trim();
      const msg = document.getElementById('tip-msg');

      if (!title) { showTipMsg('タイトルを入力してください', false); return; }
      if (!content) { showTipMsg('内容を入力してください', false); return; }

      this.disabled = true;
      this.textContent = '送信中...';
      msg.style.display = 'none';

      try {
        const res = await fetch('/api/tips.php?action=post', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({game_id: GAME_ID, category, title, content}),
          credentials: 'include',
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'エラーが発生しました');
        document.getElementById('tip-title').value = '';
        document.getElementById('tip-content').value = '';
        showTipMsg(data.message, true);
        if (data.status === 'approved') loadTips();
      } catch (e) {
        showTipMsg(e.message, false);
      } finally {
        this.disabled = false;
        this.textContent = '投稿する';
      }
    });

    function showTipMsg(text, ok) {
      const msg = document.getElementById('tip-msg');
      msg.className = 'form-msg ' + (ok ? 'ok' : 'err');
      msg.textContent = text;
      msg.style.display = 'block';
    }

    loadTips();

    // === コメント ===
    async function loadComments() {
      const list = document.getElementById('comment-list');
      try {
        const res = await fetch('/api/comments.php?action=list&game_id=' + GAME_ID);
        const data = await res.json();
        if (!data.ok) throw new Error(data.error);
        if (data.comments.length === 0) {
          list.innerHTML = '<p class="comment-empty">まだコメントはありません。最初のコメントを投稿してみてください！</p>';
          return;
        }
        list.innerHTML = data.comments.map(c => `
          <div class="comment-item">
            <div class="comment-header">
              <span class="comment-user">${escHtml(c.username)}</span>
              <span class="comment-date">${escHtml(c.created_at.slice(0, 16))}</span>
            </div>
            <div class="comment-body">${escHtml(c.content)}</div>
          </div>
        `).join('');
      } catch (e) {
        list.innerHTML = '<p class="comment-empty">コメントの読み込みに失敗しました。</p>';
      }
    }

    document.getElementById('comment-submit')?.addEventListener('click', async function() {
      const content = document.getElementById('comment-content').value.trim();
      const msg = document.getElementById('form-msg');
      if (!content) { showMsg('コメントを入力してください', false); return; }

      this.disabled = true;
      this.textContent = '送信中...';
      msg.style.display = 'none';

      try {
        const res = await fetch('/api/comments.php?action=post', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({game_id: GAME_ID, content}),
          credentials: 'include',
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'エラーが発生しました');
        document.getElementById('comment-content').value = '';
        showMsg(data.message, true);
        if (data.status === 'approved') loadComments();
      } catch (e) {
        showMsg(e.message, false);
      } finally {
        this.disabled = false;
        this.textContent = '投稿する';
      }
    });

    function showMsg(text, ok) {
      const msg = document.getElementById('form-msg');
      msg.className = 'form-msg ' + (ok ? 'ok' : 'err');
      msg.textContent = text;
      msg.style.display = 'block';
    }

    function escHtml(s) {
      const d = document.createElement('div');
      d.textContent = s;
      return d.innerHTML;
    }

    loadComments();
  })();

  // === ライトボックス（画像クリックで拡大） ===
  (function() {
    const lightbox = document.getElementById('lightbox');
    if (!lightbox) return;
    const img = lightbox.querySelector('.lightbox-img');
    const cap = lightbox.querySelector('.lightbox-caption');
    const closeBtn = lightbox.querySelector('.lightbox-close');

    function open(src, caption) {
      img.src = src;
      img.alt = caption || '';
      cap.textContent = caption || '';
      lightbox.hidden = false;
      document.documentElement.style.overflow = 'hidden';
    }
    function close() {
      lightbox.hidden = true;
      img.src = '';
      document.documentElement.style.overflow = '';
    }

    document.querySelectorAll('.gallery-trigger').forEach(btn => {
      btn.addEventListener('click', () => {
        open(btn.dataset.full, btn.dataset.caption);
      });
    });

    // 背景クリック / ボタンで閉じる
    lightbox.addEventListener('click', e => {
      if (e.target === lightbox || e.target === img || e.target.closest('.lightbox-close')) {
        close();
      }
    });
    closeBtn.addEventListener('click', close);

    // ESC で閉じる
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && !lightbox.hidden) close();
    });
  })();
  </script>
  <script src="https://asobi.info/assets/js/common.js?v=<?= assetVer('/assets/js/common.js') ?>"></script>
</body>
</html>
