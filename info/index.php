<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
require_once '/opt/asobi/shared/assets/php/version.php';
$currentUser = asobiIsLoggedIn() ? asobiGetCurrentUser() : null;
session_write_close();
// アクセスログはレスポンス送信後に記録
register_shutdown_function('asobiLogAccess');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>あそび - ゲーム情報ポータル</title>
  <meta name="description" content="ゲーム攻略・情報サイト「あそび」。Dead by Daylight、ポケモンクエストなどの攻略情報をお届けします。">
  <link rel="canonical" href="https://asobi.info/">
  <link rel="stylesheet" href="/assets/css/common.css?v=<?= assetVer('/assets/css/common.css') ?>">
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

    .card-aic { background: #0d1f0d; }
    .card-aic .game-card-inner {
      background: linear-gradient(135deg, rgba(13,31,13,0.88) 0%, rgba(20,80,20,0.75) 40%, rgba(40,160,80,0.55) 100%);
      border: 1px solid rgba(40,160,80,0.4);
    }
    .card-aic h2 { color: #6edd8a; }
    .card-aic .game-card-tag { color: #6edd8a; }

    .card-image { background: #1a0a2e; }
    .card-image .game-card-inner {
      background: linear-gradient(135deg, rgba(26,10,46,0.88) 0%, rgba(80,20,120,0.75) 40%, rgba(160,60,200,0.55) 100%);
      border: 1px solid rgba(160,60,200,0.4);
    }
    .card-image h2 { color: #d88aff; }
    .card-image .game-card-tag { color: #d88aff; }
    .card-aic-badge {
      display: inline-block;
      margin-left: 8px;
      font-size: 0.55rem;
      background: rgba(255,200,0,0.2);
      color: #ffc800;
      border: 1px solid rgba(255,200,0,0.5);
      border-radius: 3px;
      padding: 1px 5px;
      vertical-align: middle;
      letter-spacing: 0.05em;
    }

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

    .card-game { background: #1a1a0a; }
    .card-game .game-card-inner {
      background: linear-gradient(135deg, rgba(26,26,10,0.88) 0%, rgba(60,50,10,0.75) 40%, rgba(180,140,30,0.55) 100%);
      border: 1px solid rgba(180,140,30,0.4);
    }
    .card-game h2 { color: #f0c040; }
    .card-game .game-card-tag { color: #f0c040; }

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
      <div class="site-logo"><a href="/">あそび</a></div>
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
        <a href="https://aic.asobi.info" class="game-card card-aic" style="grid-column:1/-1;">
          <div class="game-card-inner">
            <span class="game-card-arrow">&rarr;</span>
            <div class="game-card-tag">AI Chat<span class="original-badge">ASOBI ORIGINAL</span></div>
            <h2>AIC <span class="card-aic-badge">NEW</span></h2>
            <p>AIキャラクターとチャットできるオリジナルサービス。好きなキャラクターと会話しよう！</p>
          </div>
        </a>

        <a href="https://image.asobi.info" class="game-card card-image">
          <div class="game-card-inner">
            <span class="game-card-arrow">&rarr;</span>
            <div class="game-card-tag">AI Image Generator<span class="original-badge">ASOBI ORIGINAL</span></div>
            <h2>AI画像生成</h2>
            <p>オリジナルAI画像生成ツール。プロンプトを入力して、AIが画像を生成します。</p>
          </div>
        </a>

        <a href="https://tbt.asobi.info" class="game-card card-tbt">
          <div class="game-card-bg" style="background-image:url('/assets/images/card-tbt.jpg')"></div>
          <div class="game-card-inner">
            <span class="game-card-arrow">&rarr;</span>
            <div class="game-card-tag">Original Game<span class="original-badge">ASOBI ORIGINAL</span></div>
            <h2>Tournament Battle</h2>
            <p>あそびオリジナルのトーナメントバトルゲーム。今すぐプレイしよう！</p>
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

        <a href="https://dbd.asobi.info" class="game-card card-dbd">
          <div class="game-card-bg" style="background-image:url('/assets/images/card-dbd.jpg')"></div>
          <div class="game-card-inner">
            <span class="game-card-arrow">&rarr;</span>
            <div class="game-card-tag">Horror / Survival</div>
            <h2>Dead by Daylight</h2>
            <p>キラー・サバイバーのパーク検索、アドオン一覧、キラー能力・速度データベース</p>
          </div>
        </a>

        <a href="https://game.asobi.info" class="game-card card-game" style="grid-column:1/-1;">
          <div class="game-card-inner">
            <span class="game-card-arrow">&rarr;</span>
            <div class="game-card-tag">Retro Game Database</div>
            <h2>ゲームデータベース</h2>
            <p>ファミコン・スーファミ・PCエンジン・メガドライブ・MSXなど、レトロゲームの全タイトル情報を網羅</p>
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
        <a href="/about/currency.html" style="color:inherit;">あそびウォレット</a>
        &nbsp;·&nbsp;
        <a href="/terms.html" style="color:inherit;">利用規約</a>
        &nbsp;·&nbsp;
        <a href="/contact.php" style="color:inherit;">お問い合わせ</a>
      </p>
      <p>&copy; 2026 あそび - ゲーム情報ポータル</p>
    </div>
  </footer>
  <script src="/assets/js/common.js?v=<?= assetVer('/assets/js/common.js') ?>"></script>
</body>
</html>
