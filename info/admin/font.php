<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
asobiRequireAdmin();

require_once '/opt/asobi/shared/assets/php/users_db.php';
$db = asobiUsersDb();

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $family = $_POST['font_family'] ?? 'migu-1c';
    $format = $_POST['font_format'] ?? 'woff2';
    $allowed_families = ['migu-1c', 'migu-1p', 'migu-1m', 'migu-2m', 'none'];
    $allowed_formats  = ['woff2', 'ttf'];
    if (in_array($family, $allowed_families) && in_array($format, $allowed_formats)) {
        $stmt = $db->prepare("INSERT INTO site_settings (key, value, updated_at) VALUES (?, ?, datetime('now','localtime'))
                              ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at");
        $stmt->execute(['font_family', $family]);
        $stmt->execute(['font_format', $format]);
        $message = '保存しました。';
    }
}

$rows    = $db->query("SELECT key, value FROM site_settings WHERE key IN ('font_family','font_format')")->fetchAll(PDO::FETCH_KEY_PAIR);
$current_family = $rows['font_family'] ?? 'migu-1c';
$current_format = $rows['font_format'] ?? 'woff2';

$fonts = [
    'migu-1c' => ['name' => 'Migu 1C', 'label' => 'Clear版（推奨）', 'desc' => '英字デザイン改善。全角ひらがな・カタカナもプロポーショナル。視認性と美しさのバランスが良い。'],
    'migu-1p' => ['name' => 'Migu 1P', 'label' => 'Proportional版', 'desc' => 'プロポーショナルフォント。文字幅が自然で読みやすい標準的なデザイン。'],
    'migu-1m' => ['name' => 'Migu 1M', 'label' => 'Monospace版', 'desc' => '等幅フォント。数字・記号の桁がそろい、コードや表に適している。'],
    'migu-2m' => ['name' => 'Migu 2M', 'label' => 'Monospace版（控えめ）', 'desc' => '等幅フォント。濁点・半濁点が控えめでスッキリした印象。'],
    'none'    => ['name' => 'システムデフォルト', 'label' => '適用しない', 'desc' => 'ブラウザ・OSのデフォルトフォントを使用する。'],
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>フォント設定 - 管理画面</title>
  <link rel="stylesheet" href="/assets/css/common.css?v=20260327e">
  <style>
    body { background: #f5f5f5; color: #222; font-family: sans-serif; margin: 0; padding: 0; display: flex; flex-direction: column; min-height: 100vh; }
    h1 { font-size: 1.4rem; margin-bottom: 24px; }
    .card { background: #fff; border-radius: 10px; padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    .card h2 { font-size: 1rem; margin: 0 0 16px; padding-bottom: 8px; border-bottom: 1px solid #eee; }
    .font-option { border: 2px solid #ddd; border-radius: 8px; padding: 14px 16px; margin-bottom: 10px; cursor: pointer; transition: border-color .15s; }
    .font-option:has(input:checked) { border-color: #3498db; background: #f0f8ff; }
    .font-option label { display: flex; align-items: flex-start; gap: 12px; cursor: pointer; }
    .font-option input[type=radio] { margin-top: 3px; flex-shrink: 0; }
    .font-option-body {}
    .font-name { font-weight: 700; font-size: 0.95rem; }
    .font-label { font-size: 0.78rem; color: #3498db; font-weight: 600; margin-left: 6px; }
    .font-desc { font-size: 0.8rem; color: #666; margin-top: 4px; }
    .format-row { display: flex; gap: 12px; }
    .format-option { flex: 1; border: 2px solid #ddd; border-radius: 8px; padding: 12px; cursor: pointer; text-align: center; transition: border-color .15s; }
    .format-option:has(input:checked) { border-color: #27ae60; background: #f0fff4; }
    .format-option label { cursor: pointer; display: block; }
    .format-option .format-name { font-weight: 700; font-size: 0.95rem; }
    .format-option .format-desc { font-size: 0.78rem; color: #666; margin-top: 4px; }
    .btn-save { width: 100%; padding: 12px; background: #3498db; color: #fff; border: none; border-radius: 8px; font-size: 1rem; font-weight: 700; cursor: pointer; font-family: inherit; }
    .btn-save:hover { background: #2980b9; }
    .message { background: #d4edda; color: #155724; border-radius: 6px; padding: 10px 14px; margin-bottom: 16px; font-size: 0.9rem; }
    .preview-box { background: #f9f9f9; border: 1px solid #eee; border-radius: 8px; padding: 16px; margin-top: 16px; }
    .preview-box p { margin: 0 0 6px; font-size: 1rem; }
    .preview-box small { color: #888; font-size: 0.75rem; }
    .font-wrap { max-width: 640px; }
  </style>
</head>
<body>
  <?php $adminActivePage = 'font'; require __DIR__ . '/_sidebar.php'; ?>

  <div class="font-wrap">
  <h1>フォント設定</h1>

  <?php if ($message): ?>
    <div class="message"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <form method="post">
    <div class="card">
      <h2>フォント種類</h2>
      <?php foreach ($fonts as $key => $font): ?>
        <div class="font-option">
          <label>
            <input type="radio" name="font_family" value="<?= $key ?>" <?= $current_family === $key ? 'checked' : '' ?>>
            <div class="font-option-body">
              <div>
                <span class="font-name"><?= $font['name'] ?></span>
                <span class="font-label"><?= $font['label'] ?></span>
              </div>
              <div class="font-desc"><?= $font['desc'] ?></div>
            </div>
          </label>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <h2>フォント形式</h2>
      <div class="format-row">
        <div class="format-option">
          <label>
            <input type="radio" name="font_format" value="woff2" <?= $current_format === 'woff2' ? 'checked' : '' ?>>
            <div class="format-name">WOFF2</div>
            <div class="format-desc">推奨。ファイルサイズが小さく読み込みが速い（約1.4MB）</div>
          </label>
        </div>
        <div class="format-option">
          <label>
            <input type="radio" name="font_format" value="ttf" <?= $current_format === 'ttf' ? 'checked' : '' ?>>
            <div class="format-name">TTF</div>
            <div class="format-desc">オリジナル形式。やや大きい（約3MB）</div>
          </label>
        </div>
      </div>
    </div>

    <div class="card">
      <h2>プレビュー</h2>
      <div class="preview-box" id="preview-box">
        <p>あのイーハトーヴォのすきとおった風、夏でも底に冷たさをもつ青いそら</p>
        <p>The quick brown fox jumps over the lazy dog. 0123456789</p>
        <p>ポケモンクエスト・レシピ・個体値チェッカー</p>
        <small>※ 現在のフォント設定でプレビューしています</small>
      </div>
      <div style="margin-top:12px;">
        <a href="/sample/" target="_blank" style="font-size:0.85rem;color:#3498db;text-decoration:none;font-weight:600;">
          フォントサンプルページで詳細確認 &rarr;
        </a>
        <span style="font-size:0.75rem;color:#999;margin-left:6px;">（全フォント・全サイズの比較）</span>
      </div>
    </div>

    <button type="submit" class="btn-save">保存する</button>
  </form>

  <div class="card" style="margin-top:20px;">
    <h2>新規ページへの実装方法</h2>
    <p style="font-size:0.82rem;color:#555;margin-bottom:14px;line-height:1.7;">
      フォント読み込みの遅延を最小化するための実装ルールです。<br>
      新しいページ・コンテンツを追加する際はこの方式を守ってください。
    </p>

    <div style="margin-bottom:16px;">
      <div style="font-size:0.8rem;font-weight:700;color:#e05050;margin-bottom:6px;">NG（bodyに直接適用しない）</div>
      <pre style="background:#fff8f8;border:1px solid #f5c6c6;border-radius:6px;padding:12px;font-size:0.8rem;overflow-x:auto;color:#c00;">body { font-family: 'Migu 1C', sans-serif; }</pre>
    </div>

    <div style="margin-bottom:16px;">
      <div style="font-size:0.8rem;font-weight:700;color:#27ae60;margin-bottom:6px;">OK（mainにのみ適用）</div>
      <pre style="background:#f0fff4;border:1px solid #b2dfdb;border-radius:6px;padding:12px;font-size:0.8rem;overflow-x:auto;color:#155724;">body { font-family: system-ui, -apple-system, sans-serif; }
main { font-family: 'Migu 1C', system-ui, sans-serif; }</pre>
    </div>

    <div style="margin-bottom:16px;">
      <div style="font-size:0.8rem;font-weight:700;color:#2980b9;margin-bottom:6px;">@font-face 定義（shared/css/font.php が自動適用）</div>
      <pre style="background:#f0f8ff;border:1px solid #bee3f8;border-radius:6px;padding:12px;font-size:0.8rem;overflow-x:auto;color:#1a5276;">/* HTMLの &lt;head&gt; に追加 */
&lt;link rel="stylesheet" href="https://asobi.info/assets/css/font.php"&gt;</pre>
    </div>

    <div style="background:#fffde7;border:1px solid #ffe082;border-radius:6px;padding:12px;font-size:0.8rem;color:#5d4037;line-height:1.7;">
      <strong>ポイント</strong><br>
      · <code>font-display: swap</code> は font.php 側で設定済み（手動設定不要）<br>
      · h1タイトルはシステムフォントのままでOK（bodyに適用しない理由）<br>
      · WOFF2形式を優先使用（TTFより約60%小さい）<br>
      · 既存サイト（dbd/pkq/tbt/aic）はすべて上記方式で実装済み
    </div>
  </div>
  </div>
  </main>
  </div>

<script>
// フォント選択でプレビューを更新
const radios = document.querySelectorAll('input[name=font_family], input[name=font_format]');
radios.forEach(r => r.addEventListener('change', updatePreview));

function updatePreview() {
  const family = document.querySelector('input[name=font_family]:checked')?.value || 'migu-1c';
  const format = document.querySelector('input[name=font_format]:checked')?.value || 'woff2';
  if (family === 'none') {
    document.getElementById('preview-box').style.fontFamily = 'sans-serif';
    return;
  }
  const ext = format === 'woff2' ? 'woff2' : 'ttf';
  const fmt = format === 'woff2' ? 'woff2' : 'truetype';
  const url = `/assets/fonts/${family}-regular.${ext}`;
  const css = `@font-face { font-family: 'PreviewFont'; src: url('${url}') format('${fmt}'); font-display: swap; }`;
  let style = document.getElementById('preview-style');
  if (!style) { style = document.createElement('style'); style.id = 'preview-style'; document.head.appendChild(style); }
  style.textContent = css;
  document.getElementById('preview-box').style.fontFamily = "'PreviewFont', sans-serif";
}
</script>
</body>
</html>
