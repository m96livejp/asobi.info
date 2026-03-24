<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
$currentUser = asobiIsLoggedIn() ? asobiGetCurrentUser() : null;
asobiLogAccess();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>あそび - ゲーム情報ポータル</title>
  <meta name="description" content="ゲーム攻略・情報サイト「あそび」。Dead by Daylight、ポケモンクエストなどの攻略情報をお届けします。">
  <link rel="stylesheet" href="/assets/css/common.css">
  <style>
    body {
      background: #f5f5f7;
      color: #1d1d1f;
      min-height: 100vh;
    }

    .site-header {
      background: rgba(255,255,255,0.85);
      border-bottom: 1px solid #e0e0e0;
    }

    .site-logo { color: #1d1d1f; }

    /* ヘッダー右エリア */
    .header-right {
      display: flex;
      align-items: center;
      gap: 24px;
    }

    /* ユーザーエリア */
    .user-area {
      display: flex;
      align-items: center;
      gap: 10px;
      position: relative;
    }

    .user-area::before {
      content: '';
      display: block;
      width: 1px;
      height: 18px;
      background: #d0d0d5;
    }

    /* 未ログイン */
    .btn-login {
      font-size: 0.875rem;
      font-weight: 500;
      color: #1d1d1f;
      text-decoration: none;
      transition: opacity 0.2s;
    }
    .btn-login:hover { opacity: 0.6; }

    .btn-register {
      font-size: 0.875rem;
      font-weight: 600;
      padding: 7px 16px;
      background: linear-gradient(135deg, #667eea, #764ba2);
      color: #fff;
      border-radius: 20px;
      text-decoration: none;
      transition: opacity 0.2s;
    }
    .btn-register:hover { opacity: 0.85; }

    /* ログイン済み */
    .user-menu {
      position: relative;
    }

    .user-trigger {
      display: flex;
      align-items: center;
      gap: 8px;
      cursor: pointer;
      padding: 4px 8px;
      border-radius: 8px;
      transition: background 0.2s;
      text-decoration: none;
      color: #1d1d1f;
    }
    .user-trigger:hover { background: rgba(0,0,0,0.05); }

    .user-avatar {
      width: 30px;
      height: 30px;
      border-radius: 50%;
      background: linear-gradient(135deg, #667eea, #764ba2);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.8rem;
      font-weight: 700;
      color: #fff;
      flex-shrink: 0;
      overflow: hidden;
    }
    .user-avatar img { width: 100%; height: 100%; object-fit: cover; }

    .user-display-name {
      font-size: 0.875rem;
      font-weight: 500;
      max-width: 100px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .user-caret {
      font-size: 0.6rem;
      opacity: 0.5;
      transition: transform 0.2s;
    }

    /* ドロップダウン */
    .user-dropdown {
      display: none;
      position: absolute;
      top: calc(100% + 8px);
      right: 0;
      background: #fff;
      border: 1px solid #e0e0e0;
      border-radius: 12px;
      box-shadow: 0 8px 32px rgba(0,0,0,0.12);
      min-width: 160px;
      overflow: hidden;
      z-index: 200;
    }
    .user-menu.open .user-dropdown { display: block; }

    .user-dropdown a {
      display: block;
      padding: 11px 16px;
      font-size: 0.875rem;
      color: #1d1d1f;
      text-decoration: none;
      transition: background 0.15s;
    }
    .user-dropdown a:hover { background: #f5f5f7; }
    .user-dropdown .dropdown-divider {
      height: 1px;
      background: #e0e0e0;
      margin: 4px 0;
    }
    .user-dropdown .dropdown-logout { color: #e74c3c; }

    /* モバイル */
    @media (max-width: 768px) {
      .site-header .container { flex-direction: row; align-items: center; }
      .site-nav { display: none; }
      .user-display-name { display: none; }
      .user-area::before { display: none; }
    }

    /* ページコンテンツ */
    .hero {
      text-align: center;
      padding: 56px 20px 44px;
      position: relative;
      overflow: hidden;
      min-height: 220px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
    }

    .hero-bg {
      position: absolute;
      inset: 0;
      background-image: url('/assets/images/hero-gaming.png');
      background-size: cover;
      background-position: center 30%;
      opacity: 0.18;
      pointer-events: none;
    }

    .hero-content {
      position: relative;
      z-index: 1;
    }

    .hero h1 {
      font-size: 3rem;
      font-weight: 700;
      margin-bottom: 12px;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .hero p {
      font-size: 1.2rem;
      color: #6e6e73;
      max-width: 500px;
      margin: 0 auto;
    }

    .games-section { padding: 0 20px 80px; }

    .games-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
      gap: 28px;
      max-width: 900px;
      margin: 0 auto;
    }

    .game-card {
      border-radius: 16px;
      overflow: hidden;
      transition: transform 0.3s, box-shadow 0.3s;
      cursor: pointer;
      position: relative;
    }
    .game-card:hover {
      transform: translateY(-6px);
      box-shadow: 0 20px 60px rgba(0,0,0,0.15);
    }

    .game-card-bg {
      position: absolute;
      inset: 0;
      background-size: cover;
      background-position: center;
    }

    .game-card-inner {
      padding: 40px 32px;
      color: #fff;
      min-height: 260px;
      display: flex;
      flex-direction: column;
      justify-content: flex-end;
      position: relative;
      z-index: 1;
    }

    .game-card-tag {
      font-size: 0.8rem;
      font-weight: 500;
      opacity: 0.8;
      margin-bottom: 8px;
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    .game-card h2 { font-size: 1.8rem; font-weight: 700; margin-bottom: 8px; }
    .game-card p  { font-size: 0.95rem; opacity: 0.9; line-height: 1.5; }

    .game-card-arrow {
      position: absolute;
      top: 24px; right: 24px;
      font-size: 1.4rem;
      opacity: 0.6;
      transition: opacity 0.2s, transform 0.2s;
    }
    .game-card:hover .game-card-arrow { opacity: 1; transform: translateX(4px); }

    .card-tbt { background: #0c0c1d; }
    .card-tbt .game-card-inner {
      background: linear-gradient(135deg, rgba(12,12,29,0.88) 0%, rgba(27,20,100,0.75) 40%, rgba(108,59,170,0.65) 100%);
      border: 1px solid rgba(108,59,170,0.4);
    }
    .card-tbt h2 {
      background: linear-gradient(90deg, #f7d94e, #f0a030);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    .card-tbt .game-card-tag { color: #f7d94e; }

    .original-badge {
      display: inline-block;
      background: linear-gradient(90deg, #f7d94e, #f0a030);
      color: #1b1464;
      font-size: 0.65rem;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 4px;
      margin-left: 8px;
      vertical-align: middle;
      letter-spacing: 0.5px;
    }

    .card-dbd { background: #1a1a2e; }
    .card-dbd .game-card-inner {
      background: linear-gradient(135deg, rgba(26,26,46,0.88) 0%, rgba(22,33,62,0.78) 40%, rgba(15,52,96,0.68) 100%);
      border: 1px solid rgba(231,76,60,0.3);
    }
    .card-dbd h2 { color: #e74c3c; }

    .card-pq { background: #c060d0; }
    .card-pq .game-card-inner {
      background: linear-gradient(135deg, rgba(240,147,251,0.75) 0%, rgba(245,87,108,0.75) 50%, rgba(255,210,0,0.7) 100%);
    }

    .site-footer { color: #6e6e73; }

    @media (max-width: 768px) {
      .hero { min-height: 180px; padding: 44px 20px 36px; }
      .hero h1 { font-size: 2rem; }
      .hero p   { font-size: 1rem; }
      .games-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="container">
      <div class="site-logo">あそび</div>
      <div class="header-right">
        <nav class="site-nav">
          <ul>
            <li><a href="https://tbt.asobi.info">Tournament Battle</a></li>
            <li><a href="https://dbd.asobi.info">DbD</a></li>
            <li><a href="https://pkq.asobi.info">ポケモンクエスト</a></li>
          </ul>
        </nav>

        <div class="user-area">
          <?php if ($currentUser): ?>
            <div class="user-menu">
              <div class="user-trigger" tabindex="0">
                <div class="user-avatar">
                  <?php if ($currentUser['avatar_url']): ?>
                    <img src="<?= htmlspecialchars($currentUser['avatar_url']) ?>" alt="">
                  <?php else: ?>
                    <?= htmlspecialchars(mb_substr($currentUser['display_name'], 0, 1)) ?>
                  <?php endif; ?>
                </div>
                <span class="user-display-name"><?= htmlspecialchars($currentUser['display_name']) ?></span>
                <span class="user-caret">▼</span>
              </div>
              <div class="user-dropdown">
                <a href="/profile.php">プロフィール</a>
                <?php if (asobiIsAdmin()): ?>
                  <div class="dropdown-divider"></div>
                  <a href="/admin/">管理画面</a>
                <?php endif; ?>
              </div>
            </div>
          <?php else: ?>
            <a href="/login.php" class="btn-register">ログイン</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </header>

  <main>
    <section class="hero">
      <div class="hero-bg"></div>
      <div class="hero-content">
        <h1>あそび</h1>
        <p>ゲーム攻略・情報サイト。パーク検索、データベース、シミュレーターなど便利ツールを提供します。</p>
      </div>
    </section>

    <section class="games-section">
      <div class="games-grid">
        <a href="https://tbt.asobi.info" class="game-card card-tbt" style="grid-column:1/-1;">
          <div class="game-card-bg" style="background-image:url('/assets/images/card-tbt.jpg')"></div>
          <div class="game-card-inner">
            <span class="game-card-arrow">&rarr;</span>
            <div class="game-card-tag">Original Game<span class="original-badge">ASOBI ORIGINAL</span></div>
            <h2>Tournament Battle</h2>
            <p>あそびオリジナルのトーナメントバトルゲーム。今すぐプレイしよう！</p>
          </div>
        </a>

        <a href="https://dbd.asobi.info" class="game-card card-dbd">
          <div class="game-card-bg" style="background-image:url('/assets/images/card-dbd.jpg')"></div>
          <div class="game-card-inner">
            <span class="game-card-arrow">&rarr;</span>
            <div class="game-card-tag">Horror / Survival</div>
            <h2>Dead by Daylight</h2>
            <p>キラー・サバイバーのパーク検索、アドオン一覧、キラー能力・速度データベース</p>
          </div>
        </a>

        <a href="https://pkq.asobi.info" class="game-card card-pq">
          <div class="game-card-bg" style="background-image:url('/assets/images/card-pq.jpg')"></div>
          <div class="game-card-inner">
            <span class="game-card-arrow">&rarr;</span>
            <div class="game-card-tag">Adventure / RPG</div>
            <h2>ポケモンクエスト</h2>
            <p>全ポケモン一覧、料理レシピ、素材データベース、料理シミュレーター</p>
          </div>
        </a>
      </div>
    </section>
  </main>

  <footer class="site-footer">
    <div class="container">
      <p style="font-size:0.72rem; margin-bottom:6px; opacity:0.6;">
        <a href="/licenses.html" style="color:inherit;">ライセンス情報</a>
        &nbsp;·&nbsp;
        <a href="/contact.php" style="color:inherit;">お問い合わせ</a>
      </p>
      <p>&copy; 2026 あそび - ゲーム情報ポータル</p>
    </div>
  </footer>
  <script src="/assets/js/common.js"></script>
  <script>
    const userMenu = document.querySelector('.user-menu');
    if (userMenu) {
      userMenu.querySelector('.user-trigger').addEventListener('click', function(e) {
        e.stopPropagation();
        userMenu.classList.toggle('open');
      });
      document.addEventListener('click', function() {
        userMenu.classList.remove('open');
      });
    }
  </script>
</body>
</html>
