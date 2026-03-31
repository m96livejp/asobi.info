<?php
/**
 * プラットフォーム一覧ページ生成用共通テンプレート
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
$title = $meta['name'] . ' ゲーム一覧';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($title) ?> - あそびゲーム情報</title>
  <meta name="description" content="<?= htmlspecialchars($meta['desc']) ?>">
  <link rel="stylesheet" href="/css/style.css?v=20260328a">
</head>
<body>
  <header class="site-header">
    <div class="container">
      <div class="site-logo">
        <a href="/">ゲーム情報</a><span class="sub">by あそび</span>
      </div>
      <nav class="site-nav">
        <?php
        $platforms = ['nes' => 'FC', 'snes' => 'SFC', 'pce' => 'PCE', 'md' => 'MD', 'msx' => 'MSX'];
        foreach ($platforms as $key => $label):
        ?>
        <a href="/<?= $key ?>/" <?= $key === $PLATFORM_KEY ? 'class="active"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
  </header>

  <main>
    <div class="page-content">
      <div class="breadcrumb">
        <a href="/">ゲーム情報</a> &rsaquo; <?= htmlspecialchars($meta['icon'] . ' ' . $meta['name']) ?>
      </div>

      <h1 class="page-title"><?= htmlspecialchars($meta['icon'] . ' ' . $meta['name']) ?></h1>
      <p class="page-subtitle"><?= htmlspecialchars($meta['en']) ?></p>

      <div class="search-bar">
        <input type="text" id="search-input" placeholder="タイトルで検索..." autocomplete="off">
        <button id="search-btn">検索</button>
      </div>

      <div class="game-grid" id="game-list">
        <div class="loading"><div class="spinner"></div><p>読み込み中...</p></div>
      </div>

      <div class="pagination" id="pagination"></div>
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
  <script src="/js/platform.js?v=20260328a"></script>
  <script src="https://asobi.info/assets/js/common.js?v=20260327i"></script>
</body>
</html>
