<?php
require_once '/opt/asobi/shared/assets/php/version.php';
/**
 * プラットフォーム ランディングページ（注目・ジャンル別・メーカー別）
 * 各プラットフォームフォルダのindex.phpからincludeして使う
 */

$PLATFORM_META = [
    'nes'  => ['name' => 'ファミコン',           'en' => 'Family Computer / NES',     'icon' => '🔴', 'desc' => 'ファミリーコンピューター（NES）の全タイトル情報。1983年発売の国民的ゲーム機のソフト一覧。'],
    'snes' => ['name' => 'スーパーファミコン',   'en' => 'Super Famicom / SNES',      'icon' => '🟣', 'desc' => 'スーパーファミコン（SNES）の全タイトル情報。1990年発売の16ビット機のソフト一覧。'],
    'pce'  => ['name' => 'PCエンジン',           'en' => 'PC Engine / TurboGrafx-16', 'icon' => '⚪', 'desc' => 'PCエンジン（TurboGrafx-16）の全タイトル情報。NECとハドソンが共同開発したゲーム機のソフト一覧。'],
    'md'   => ['name' => 'メガドライブ',         'en' => 'Mega Drive / Genesis',      'icon' => '⚫', 'desc' => 'メガドライブ（Mega Drive / Genesis）の全タイトル情報。セガの16ビット機のソフト一覧。'],
    'msx'  => ['name' => 'MSX',                  'en' => 'MSX / MSX2',                'icon' => '🟢', 'desc' => 'MSX・MSX2の全タイトル情報。1983年登場のマイコン規格のソフト一覧。'],
];

if (!isset($PLATFORM_KEY) || !isset($PLATFORM_META[$PLATFORM_KEY])) {
    http_response_code(404);
    exit('Not Found');
}

$meta = $PLATFORM_META[$PLATFORM_KEY];
$title = $meta['name'] . ' - ゲーム情報';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($title) ?> - あそびゲーム情報</title>
  <meta name="description" content="<?= htmlspecialchars($meta['desc']) ?>">
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
        ?>
        <a href="/">TOP</a>
        <?php
        foreach ($platforms as $key => $label):
        ?>
        <a href="/<?= $key ?>/" <?= $key === $PLATFORM_KEY ? 'class="active"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
  </header>

  <main>
    <section class="platform-hero">
      <div class="platform-hero-inner">
        <span class="platform-hero-icon"><?= $meta['icon'] ?></span>
        <div>
          <h1><?= htmlspecialchars($meta['name']) ?></h1>
          <p class="platform-hero-en"><?= htmlspecialchars($meta['en']) ?></p>
        </div>
      </div>
      <a href="/<?= $PLATFORM_KEY ?>/list.php" class="btn-all-games">全タイトル一覧</a>
    </section>

    <div class="page-content">
      <!-- 注目ゲーム -->
      <section class="top-section">
        <h2 class="section-title">注目のゲーム</h2>
        <div class="game-grid" id="popular-games">
          <div class="loading"><div class="spinner"></div><p>読み込み中...</p></div>
        </div>
      </section>

      <!-- ジャンル別 -->
      <section class="top-section">
        <h2 class="section-title">ジャンル別</h2>
        <div class="genre-grid" id="genre-list">
          <div class="loading"><div class="spinner"></div></div>
        </div>
      </section>

      <!-- メーカー別 -->
      <section class="top-section">
        <h2 class="section-title">メーカー別</h2>
        <div class="tag-grid" id="publisher-list">
          <div class="loading"><div class="spinner"></div></div>
        </div>
      </section>
    </div>
  </main>

  <footer class="site-footer">
    <p>
      <a href="/">ゲーム情報トップ</a>
      &nbsp;·&nbsp;
      <a href="https://asobi.info/">あそび</a>
      &nbsp;·&nbsp;
      <a href="https://asobi.info/contact.php">お問い合わせ</a>
    </p>
    <p style="margin-top:6px;">&copy; 2026 あそび</p>
  </footer>

  <script>const PLATFORM_KEY = '<?= $PLATFORM_KEY ?>';</script>
  <script src="/js/platform-top.js?v=<?= assetVer('/js/platform-top.js') ?>"></script>
  <script src="https://asobi.info/assets/js/common.js?v=<?= assetVer('/assets/js/common.js') ?>"></script>
</body>
</html>
