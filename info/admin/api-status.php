<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
asobiRequireAdmin();
session_write_close();

$commonDb = new PDO('sqlite:/opt/asobi/data/users.sqlite');
$commonDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function getSetting(PDO $db, string $key, string $default = ''): string {
    $r = $db->prepare("SELECT value FROM site_settings WHERE key=?");
    $r->execute([$key]);
    $row = $r->fetch();
    return $row ? $row['value'] : $default;
}
function saveSetting(PDO $db, string $key, string $value): void {
    $db->prepare("INSERT INTO site_settings(key,value) VALUES(?,?)
                  ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=datetime('now','localtime')")
       ->execute([$key, $value]);
}

// ─── POST: 設定保存 ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_save'])) {
    header('Content-Type: application/json; charset=utf-8');
    $fields = ['api_sd_endpoint','api_sd_enabled','api_lt_mode','api_lt_endpoint','api_lt_apikey',
               'api_ollama_endpoint','api_ollama_model','api_voicevox_url'];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) saveSetting($commonDb, $f, trim($_POST[$f]));
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ─── AJAX: 接続チェック ───
if (isset($_GET['check'])) {
    header('Content-Type: application/json; charset=utf-8');
    $url = $_GET['url'] ?? '';
    $result = ['ok' => false, 'ms' => null, 'detail' => ''];
    if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
        $t0 = microtime(true);
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true, 'method' => 'GET']]);
        $res = @file_get_contents($url, false, $ctx);
        $ms  = (int)((microtime(true) - $t0) * 1000);
        if ($res !== false) {
            $result = ['ok' => true, 'ms' => $ms, 'detail' => mb_substr($res, 0, 120)];
        } else {
            $result = ['ok' => false, 'ms' => $ms, 'detail' => '接続できませんでした'];
        }
    } else {
        $result['detail'] = 'URLが設定されていません';
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── AJAX: 翻訳テスト ───
if (isset($_GET['translate'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ltMode     = getSetting($commonDb, 'api_lt_mode', 'off');
    $ltEndpoint = getSetting($commonDb, 'api_lt_endpoint');
    $ltApiKey   = getSetting($commonDb, 'api_lt_apikey');
    $text   = trim($_POST['text'] ?? '');
    $source = $_POST['source'] ?? 'ja';
    $target = $_POST['target'] ?? 'en';
    if (!$text) { echo json_encode(['ok' => false, 'error' => 'テキストを入力してください'], JSON_UNESCAPED_UNICODE); exit; }
    $endpoints = [];
    if (in_array($ltMode, ['local','both']) && $ltEndpoint)
        $endpoints[] = ['url' => rtrim($ltEndpoint, '/'), 'key' => ''];
    if (in_array($ltMode, ['free','both']))
        $endpoints[] = ['url' => 'https://libretranslate.com', 'key' => $ltApiKey];
    if (!$endpoints) { echo json_encode(['ok' => false, 'error' => 'LibreTranslate が無効または未設定です'], JSON_UNESCAPED_UNICODE); exit; }
    $translated = null; $usedEp = ''; $ms = 0;
    foreach ($endpoints as $ep) {
        $payload = json_encode(['q' => $text, 'source' => $source, 'target' => $target, 'api_key' => $ep['key']]);
        $t0 = microtime(true);
        $ctx = stream_context_create(['http' => ['method' => 'POST',
            'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($payload),
            'content' => $payload, 'timeout' => 8, 'ignore_errors' => true]]);
        $res = @file_get_contents($ep['url'] . '/translate', false, $ctx);
        $ms  = (int)((microtime(true) - $t0) * 1000);
        if ($res !== false) {
            $data = json_decode($res, true);
            if (!empty($data['translatedText'])) { $translated = $data['translatedText']; $usedEp = $ep['url']; break; }
        }
    }
    echo $translated !== null
        ? json_encode(['ok' => true, 'result' => $translated, 'endpoint' => $usedEp, 'ms' => $ms], JSON_UNESCAPED_UNICODE)
        : json_encode(['ok' => false, 'error' => '翻訳に失敗しました（' . $ms . 'ms）'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── AJAX: VOICEVOXスピーカー一覧 ───
if (isset($_GET['vvspeakers'])) {
    header('Content-Type: application/json; charset=utf-8');
    $vvBase = rtrim(getSetting($commonDb, 'api_voicevox_url', 'http://133.117.75.23:50021'), '/');
    $ctx = stream_context_create(['http'=>['method'=>'GET','timeout'=>5,'ignore_errors'=>true]]);
    $res = @file_get_contents($vvBase . '/speakers', false, $ctx);
    if ($res === false) { echo '[]'; exit; }
    echo $res;
    exit;
}

// ─── AJAX: VOICEVOX音声プロキシ ───
if (isset($_GET['voicevox'])) {
    header('Content-Type: application/json; charset=utf-8');
    $text    = trim($_GET['text'] ?? '');
    $speaker = (int)($_GET['speaker'] ?? 3);
    $vvBase  = rtrim(getSetting($commonDb, 'api_voicevox_url', 'http://133.117.75.23:50021'), '/');
    if (!$text) { echo json_encode(['ok'=>false,'error'=>'テキストが空です']); exit; }

    // 1) audio_query
    $qUrl = $vvBase . '/audio_query?speaker=' . $speaker . '&text=' . rawurlencode($text);
    $ctx  = stream_context_create(['http'=>['method'=>'POST','timeout'=>10,'ignore_errors'=>true,
              'header'=>"Accept: application/json\r\n",'content'=>'']]);
    $qRes = @file_get_contents($qUrl, false, $ctx);
    if ($qRes === false || !($qJson = json_decode($qRes, true))) {
        echo json_encode(['ok'=>false,'error'=>'audio_query失敗']); exit;
    }

    // 2) synthesis
    $sUrl  = $vvBase . '/synthesis?speaker=' . $speaker;
    $body  = json_encode($qJson);
    $ctx2  = stream_context_create(['http'=>['method'=>'POST','timeout'=>15,'ignore_errors'=>true,
               'header'=>"Content-Type: application/json\r\nAccept: audio/wav\r\n",'content'=>$body]]);
    $audio = @file_get_contents($sUrl, false, $ctx2);
    if ($audio === false || strlen($audio) < 100) {
        echo json_encode(['ok'=>false,'error'=>'synthesis失敗']); exit;
    }
    header('Content-Type: audio/wav');
    header('Content-Length: ' . strlen($audio));
    echo $audio;
    exit;
}

// ─── 設定読み込み ───
$sdEndpoint     = getSetting($commonDb, 'api_sd_endpoint');
$sdEnabled      = (int)getSetting($commonDb, 'api_sd_enabled', '0');
$ltMode         = getSetting($commonDb, 'api_lt_mode', 'off');
$ltEndpoint     = getSetting($commonDb, 'api_lt_endpoint');
$ltApiKey       = getSetting($commonDb, 'api_lt_apikey');
$ollamaEndpoint = getSetting($commonDb, 'api_ollama_endpoint');
$ollamaModel    = getSetting($commonDb, 'api_ollama_model');
$voicevoxUrl    = getSetting($commonDb, 'api_voicevox_url', 'http://133.117.75.23:50021');

$sdCheckUrl      = $sdEndpoint ? rtrim($sdEndpoint, '/') . '/sdapi/v1/options' : '';
$ltCheckUrl      = $ltEndpoint ? rtrim($ltEndpoint, '/') . '/languages' : '';
$voicevoxCheckUrl = rtrim($voicevoxUrl, '/') . '/version';
$ollamaCheckUrl  = '';
if ($ollamaEndpoint) {
    $p = parse_url($ollamaEndpoint);
    $ollamaCheckUrl = ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '') . '/api/version';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>API管理 - 管理画面</title>
  <link rel="stylesheet" href="/assets/css/common.css?v=20260327f">
  <style>*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; color: #1d2d3a; min-height: 100vh; display: flex; flex-direction: column; }</style>
</head>
<body>
  <?php $adminActivePage = 'api-status'; require __DIR__ . '/_sidebar.php'; ?>

  <style>
    /* グリッド */
    .api-grid { display: flex; flex-direction: column; gap: 16px; margin-bottom: 24px; }

    /* カード */
    .api-card { background: #fff; border: 1px solid #e0e4e8; border-radius: 10px; padding: 18px 20px; }
    .api-card-header { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
    .api-icon { font-size: 1.5rem; }
    .api-name { font-weight: 700; font-size: 1rem; }
    .api-badge { font-size: 0.72rem; padding: 2px 8px; border-radius: 10px; font-weight: 600; margin-left: auto; white-space: nowrap; }
    .api-badge.enabled  { background: #eaf6ee; color: #1e8449; }
    .api-badge.disabled { background: #f5f5f5; color: #888; }
    .api-badge.noconfig { background: #fef9e7; color: #b7950b; }

    /* フォームフィールド */
    .field { margin-bottom: 10px; }
    .field label { display: block; font-size: 0.78rem; font-weight: 600; color: #555; margin-bottom: 3px; }
    .field input[type=text], .field select {
      width: 100%; padding: 7px 10px; border: 1px solid #cdd1d8; border-radius: 6px;
      font-size: 0.83rem; font-family: inherit; background: #fff; color: #1d2d3a;
    }
    .field input[type=text]:focus, .field select:focus { outline: none; border-color: #5567cc; }
    .field-row { display: flex; gap: 8px; }
    .field-row .field { flex: 1; }

    /* ボタン */
    .btn { font-size: 0.83rem; padding: 7px 16px; border: none; border-radius: 6px; cursor: pointer; font-family: inherit; transition: background 0.15s; }
    .btn-primary { background: #5567cc; color: #fff; }
    .btn-primary:hover { background: #3a4cc0; }
    .btn-check { font-size: 0.8rem; padding: 6px 14px; background: #5567cc; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-family: inherit; transition: background 0.15s; }
    .btn-check:hover { background: #3a4cc0; }
    .btn-check:disabled { opacity: 0.6; cursor: default; }

    /* テスト結果 */
    .check-result { margin-top: 6px; font-size: 0.8rem; padding: 6px 10px; border-radius: 6px; display: none; }
    .check-result.ok { background: #eaf6ee; color: #1e8449; }
    .check-result.ng { background: #fdecea; color: #c0392b; }

    /* 翻訳テスト */
    .test-section { margin-top: 12px; padding-top: 12px; border-top: 1px solid #eee; }
    .test-section-title { font-size: 0.78rem; font-weight: 600; color: #555; margin-bottom: 6px; }
    .lt-trans-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .lt-trans-col { display: flex; flex-direction: column; gap: 4px; }
    .lt-trans-col textarea { width: 100%; padding: 7px 10px; border: 1px solid #cdd1d8; border-radius: 6px; font-size: 0.82rem; font-family: inherit; resize: vertical; min-height: 70px; }
    .lt-trans-col .btn-check { width: 100%; }
    .lt-result-row { display: flex; align-items: baseline; gap: 6px; padding: 5px 8px; border-radius: 6px; background: #eaf6ee; font-size: 0.82rem; margin-bottom: 4px; }
    .lt-result-row.ng { background: #fdecea; }
    .lt-res-label { font-weight: 700; color: #1e8449; white-space: nowrap; min-width: 3em; }
    .lt-result-row.ng .lt-res-label { color: #c0392b; }
    .lt-res-text { flex: 1; color: #1d2d3a; word-break: break-all; }
    .lt-res-ms { color: #888; white-space: nowrap; font-size: 0.75rem; }
    .test-row { display: flex; gap: 6px; align-items: flex-start; }
    .test-row textarea { flex: 1; padding: 7px 10px; border: 1px solid #cdd1d8; border-radius: 6px; font-size: 0.82rem; font-family: inherit; resize: vertical; min-height: 58px; }
    .test-row .test-controls { display: flex; flex-direction: column; gap: 4px; }
    .test-row select { padding: 7px 8px; border: 1px solid #cdd1d8; border-radius: 6px; font-size: 0.82rem; background: #fff; }
    .test-result { margin-top: 6px; font-size: 0.82rem; padding: 7px 10px; border-radius: 6px; display: none; word-break: break-all; }
    .test-result.ok { background: #eaf6ee; color: #1e6a34; cursor: pointer; }
    .test-result.ng { background: #fdecea; color: #c0392b; }

    /* VOICEVOXキュー行 */
    .vv-row { display: flex; gap: 6px; align-items: center; }
    .vv-row input[type=text] { flex: 1; padding: 6px 10px; border: 1px solid #cdd1d8; border-radius: 6px; font-size: 0.82rem; font-family: inherit; }
    .vv-row select { padding: 5px 8px; border: 1px solid #cdd1d8; border-radius: 6px; font-size: 0.8rem; background: #fff; max-width: 130px; }
    .vv-row .vv-del, .vv-row .vv-dl { background: none; border: none; color: #aaa; font-size: 1rem; cursor: pointer; padding: 2px 4px; line-height: 1; }
    .vv-row .vv-del:hover { color: #c0392b; }
    .vv-row .vv-dl:hover { color: #2980b9; }
    .vv-row.playing { background: #eaf6ee; border-radius: 6px; }

    /* 保存通知 */
    .save-toast { position: fixed; bottom: 24px; right: 24px; background: #1e8449; color: #fff; padding: 10px 20px; border-radius: 8px; font-size: 0.85rem; display: none; z-index: 999; }

    /* セパレータ */
    .section-sep { border: none; border-top: 1px solid #e0e4e8; margin: 24px 0; }
  </style>

  <div class="page-title">API管理</div>

  <!-- ── 設定フォーム ── -->
  <div class="api-grid" id="settings-grid">

    <!-- Stable Diffusion 設定 -->
    <div class="api-card">
      <div class="api-card-header">
        <div class="api-icon">🎨</div>
        <div class="api-name">Stable Diffusion</div>
        <span class="api-badge <?= ($sdEndpoint && $sdEnabled) ? 'enabled' : ($sdEndpoint ? 'disabled' : 'noconfig') ?>">
          <?= ($sdEndpoint && $sdEnabled) ? '有効' : ($sdEndpoint ? '無効' : '未設定') ?>
        </span>
      </div>
      <div class="field">
        <label>エンドポイント</label>
        <input type="text" name="api_sd_endpoint" value="<?= htmlspecialchars($sdEndpoint) ?>" placeholder="例: http://153.242.124.35:17213">
      </div>
      <div class="field">
        <label>有効</label>
        <select name="api_sd_enabled">
          <option value="1" <?= $sdEnabled ? 'selected' : '' ?>>有効</option>
          <option value="0" <?= !$sdEnabled ? 'selected' : '' ?>>無効</option>
        </select>
      </div>
      <div style="display:flex;gap:6px;margin-top:4px">
        <button class="btn btn-primary" onclick="saveCard(this)">保存</button>
        <?php if ($sdCheckUrl): ?>
        <button class="btn-check" onclick="checkApi(this, <?= htmlspecialchars(json_encode($sdCheckUrl)) ?>)">接続テスト</button>
        <?php endif; ?>
      </div>
      <div class="check-result"></div>
    </div>

    <!-- Ollama 設定 -->
    <div class="api-card">
      <div class="api-card-header">
        <div class="api-icon">💬</div>
        <div class="api-name">Ollama（チャットAI）</div>
        <span class="api-badge <?= $ollamaEndpoint ? 'enabled' : 'noconfig' ?>"><?= $ollamaEndpoint ? '設定済' : '未設定' ?></span>
      </div>
      <div class="field">
        <label>エンドポイント</label>
        <input type="text" name="api_ollama_endpoint" value="<?= htmlspecialchars($ollamaEndpoint) ?>" placeholder="例: http://153.242.124.35:17214/api/generate">
      </div>
      <div class="field">
        <label>モデル</label>
        <input type="text" name="api_ollama_model" value="<?= htmlspecialchars($ollamaModel) ?>" placeholder="例: gemma3:27b">
      </div>
      <div style="display:flex;gap:6px;margin-top:4px">
        <button class="btn btn-primary" onclick="saveCard(this)">保存</button>
        <?php if ($ollamaCheckUrl): ?>
        <button class="btn-check" onclick="checkApi(this, <?= htmlspecialchars(json_encode($ollamaCheckUrl)) ?>)">接続テスト</button>
        <?php endif; ?>
      </div>
      <div class="check-result"></div>
    </div>

    <!-- LibreTranslate 設定 -->
    <div class="api-card">
      <div class="api-card-header">
        <div class="api-icon">🌐</div>
        <div class="api-name">LibreTranslate</div>
        <?php
        $ltLabel = match($ltMode) { 'local'=>'ローカル', 'free'=>'無料API', 'both'=>'両方', default=>'無効' };
        $ltClass = $ltMode !== 'off' ? 'enabled' : 'disabled';
        ?>
        <span class="api-badge <?= $ltClass ?>"><?= $ltLabel ?></span>
      </div>
      <div class="field">
        <label>モード</label>
        <select name="api_lt_mode">
          <option value="off"   <?= $ltMode==='off'   ? 'selected' : '' ?>>無効</option>
          <option value="local" <?= $ltMode==='local'  ? 'selected' : '' ?>>ローカルのみ</option>
          <option value="free"  <?= $ltMode==='free'   ? 'selected' : '' ?>>無料版のみ（libretranslate.com）</option>
          <option value="both"  <?= $ltMode==='both'   ? 'selected' : '' ?>>ローカル優先→無料版</option>
        </select>
      </div>
      <div class="field">
        <label>ローカルエンドポイント</label>
        <input type="text" name="api_lt_endpoint" value="<?= htmlspecialchars($ltEndpoint) ?>" placeholder="例: http://153.242.124.35:17212">
      </div>
      <div class="field">
        <label>APIキー（libretranslate.com 無料版用）</label>
        <input type="text" name="api_lt_apikey" value="<?= htmlspecialchars($ltApiKey) ?>" placeholder="未登録の場合は空欄">
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px">
        <button class="btn btn-primary" onclick="saveCard(this)">保存</button>
        <?php if ($ltCheckUrl): ?>
        <button class="btn-check" onclick="checkApi(this, <?= htmlspecialchars(json_encode($ltCheckUrl)) ?>)">ローカル接続テスト</button>
        <?php endif; ?>
        <button class="btn-check" onclick="checkApi(this, 'https://libretranslate.com/languages', document.getElementById('lt-free-result'))">無料版テスト</button>
      </div>
      <div class="check-result"></div>
      <div class="check-result" id="lt-free-result"></div>

      <?php if ($ltMode !== 'off'): ?>
      <div class="test-section">
        <div class="test-section-title">翻訳テスト（2段階確認）</div>
        <div class="lt-trans-grid">
          <div class="lt-trans-col">
            <textarea id="lt-ja" placeholder="日本語テキストを入力..."></textarea>
            <button class="btn-check" id="lt-btn-ja2en" onclick="doTranslate('ja','en')">日→英</button>
          </div>
          <div class="lt-trans-col">
            <textarea id="lt-en" placeholder="英語結果がここにセットされます"></textarea>
            <button class="btn-check" id="lt-btn-en2ja" onclick="doTranslate('en','ja')">英→日</button>
          </div>
        </div>
        <div id="lt-results" style="margin-top:6px;display:none">
          <div class="lt-result-row" id="lt-res-ja2en" style="display:none">
            <span class="lt-res-label">日→英</span>
            <span class="lt-res-text" id="lt-res-ja2en-text"></span>
            <span class="lt-res-ms" id="lt-res-ja2en-ms"></span>
          </div>
          <div class="lt-result-row" id="lt-res-en2ja" style="display:none">
            <span class="lt-res-label">英→日</span>
            <span class="lt-res-text" id="lt-res-en2ja-text"></span>
            <span class="lt-res-ms" id="lt-res-en2ja-ms"></span>
          </div>
        </div>
        <div class="test-result" id="lt-test-result"></div>
      </div>
      <?php endif; ?>
    </div>

    <!-- VOICEVOX 設定 -->
    <div class="api-card">
      <div class="api-card-header">
        <div class="api-icon">🔊</div>
        <div class="api-name">VOICEVOX</div>
        <span class="api-badge <?= $voicevoxUrl ? 'enabled' : 'noconfig' ?>"><?= $voicevoxUrl ? '設定済' : '未設定' ?></span>
      </div>
      <div class="field">
        <label>エンドポイント</label>
        <input type="text" name="api_voicevox_url" value="<?= htmlspecialchars($voicevoxUrl) ?>" placeholder="例: http://133.117.75.23:50021">
      </div>
      <div style="display:flex;gap:6px;margin-top:4px">
        <button class="btn btn-primary" onclick="saveCard(this)">保存</button>
        <button class="btn-check" onclick="checkApi(this, <?= htmlspecialchars(json_encode($voicevoxCheckUrl)) ?>)">接続テスト</button>
      </div>
      <div class="check-result"></div>

      <div class="test-section">
        <div class="test-section-title" style="display:flex;align-items:center;justify-content:space-between">
          読み上げテスト
          <div style="display:flex;gap:6px">
            <button class="btn-check" onclick="addVvRow()">＋行追加</button>
            <button class="btn-check" id="vv-play-btn" onclick="doVoiceQueue()">▶ 再生</button>
            <button class="btn-check" id="vv-stop-btn" onclick="stopVoiceQueue()" style="display:none;background:#c0392b">■ 停止</button>
          </div>
        </div>
        <div id="vv-queue" style="margin-top:8px;display:flex;flex-direction:column;gap:6px"></div>
        <div class="test-result" id="vv-test-result"></div>
      </div>
    </div>

  </div>

  <div class="save-toast" id="save-toast">保存しました</div>

  <script>
  // ── 設定保存（カード単位） ──
  async function saveCard(btn) {
      const card = btn.closest('.api-card');
      const inputs = card.querySelectorAll('input[name], select[name]');
      const fd = new FormData();
      fd.append('_save', '1');
      inputs.forEach(el => fd.append(el.name, el.value));
      btn.disabled = true;
      try {
          const res = await fetch('', { method: 'POST', body: fd });
          const data = await res.json();
          if (data.ok) {
              const t = document.getElementById('save-toast');
              t.style.display = 'block';
              setTimeout(() => { t.style.display = 'none'; location.reload(); }, 1200);
          }
      } finally { btn.disabled = false; }
  }

  // ── 接続テスト ──
  async function checkApi(btn, url, resultEl) {
      const card = btn.closest('.api-card');
      resultEl = resultEl || card.querySelector('.check-result');
      const orig = btn.textContent;
      btn.disabled = true; btn.textContent = '確認中...';
      resultEl.style.display = 'none';
      try {
          const res = await fetch('/admin/api-status.php?check=1&url=' + encodeURIComponent(url));
          const data = await res.json();
          resultEl.className = 'check-result ' + (data.ok ? 'ok' : 'ng');
          resultEl.style.display = 'block';
          resultEl.textContent = data.ok ? '接続OK (' + data.ms + 'ms)' : 'エラー: ' + (data.detail || '接続できませんでした');
      } catch (e) {
          resultEl.className = 'check-result ng'; resultEl.style.display = 'block';
          resultEl.textContent = 'リクエストに失敗しました';
      } finally { btn.disabled = false; btn.textContent = orig; }
  }

  // ── 翻訳テスト（2段階：日→英→日） ──
  async function doTranslate(src, tgt) {
      const srcId    = src === 'ja' ? 'lt-ja' : 'lt-en';
      const rowId    = src === 'ja' ? 'lt-res-ja2en' : 'lt-res-en2ja';
      const textId   = rowId + '-text';
      const msId     = rowId + '-ms';
      const text     = document.getElementById(srcId).value.trim();
      const errEl    = document.getElementById('lt-test-result');
      if (!text) { errEl.className = 'test-result ng'; errEl.style.display = 'block'; errEl.textContent = 'テキストを入力してください'; return; }
      errEl.style.display = 'none';
      const allBtns = document.querySelectorAll('.lt-trans-col .btn-check');
      allBtns.forEach(b => { b.disabled = true; });
      const row = document.getElementById(rowId);
      row.style.display = 'flex'; row.className = 'lt-result-row';
      document.getElementById(textId).textContent = '変換中...';
      document.getElementById(msId).textContent = '';
      document.getElementById('lt-results').style.display = 'block';
      try {
          const fd = new FormData();
          fd.append('text', text); fd.append('source', src); fd.append('target', tgt);
          const res = await fetch('/admin/api-status.php?translate=1', { method: 'POST', body: fd });
          const data = await res.json();
          if (data.ok) {
              document.getElementById(textId).textContent = data.result;
              document.getElementById(msId).textContent = data.ms + 'ms';
              // 日→英の結果は英語欄に自動セット（ワンクリック再変換用）
              if (tgt === 'en') {
                  document.getElementById('lt-en').value = data.result;
              }
          } else {
              row.className = 'lt-result-row ng';
              document.getElementById(textId).textContent = data.error;
          }
      } catch (e) {
          row.className = 'lt-result-row ng';
          document.getElementById(textId).textContent = '通信エラー';
      } finally { allBtns.forEach(b => { b.disabled = false; }); }
  }

  // ── VOICEVOXスピーカー一覧ロード ──
  let _vvSpeakers = [];
  async function loadVvSpeakers() {
      try {
          const res = await fetch('/admin/api-status.php?vvspeakers=1');
          if (!res.ok) throw new Error();
          _vvSpeakers = await res.json();
          addVvRow(); // 初期行
      } catch (e) {
          document.getElementById('vv-test-result').className = 'test-result ng';
          document.getElementById('vv-test-result').style.display = 'block';
          document.getElementById('vv-test-result').textContent = 'スピーカー一覧の取得に失敗しました';
      }
  }

  function makeCharOptions(selectedUuid) {
      return _vvSpeakers.map(s =>
          `<option value="${s.speaker_uuid}"${s.speaker_uuid === selectedUuid ? ' selected' : ''}>${s.name}</option>`
      ).join('');
  }
  function makeStyleOptions(uuid, selectedId) {
      const sp = _vvSpeakers.find(s => s.speaker_uuid === uuid);
      if (!sp) return '<option value="">-</option>';
      return sp.styles.map(st =>
          `<option value="${st.id}"${st.id == selectedId ? ' selected' : ''}>${st.name}</option>`
      ).join('');
  }

  function addVvRow(text = '', uuid = null, styleId = null) {
      const queue = document.getElementById('vv-queue');
      const lastRow = queue.lastElementChild;
      const defaultUuid = uuid || lastRow?.querySelector('.vv-char')?.value || (_vvSpeakers[0]?.speaker_uuid ?? '');
      const defaultStyleId = styleId || lastRow?.querySelector('.vv-style')?.value || (_vvSpeakers[0]?.styles[0]?.id ?? '');
      const row = document.createElement('div');
      row.className = 'vv-row';
      row.innerHTML = `
        <input type="text" class="vv-text" placeholder="読み上げるテキスト" value="${text.replace(/"/g,'&quot;')}">
        <select class="vv-char" onchange="syncVvStyle(this)">${makeCharOptions(defaultUuid)}</select>
        <select class="vv-style">${makeStyleOptions(defaultUuid, defaultStyleId)}</select>
        <button class="vv-dl" onclick="downloadVvRow(this)" title="WAVをダウンロード">⬇</button>
        <button class="vv-del" onclick="this.closest('.vv-row').remove()" title="削除">✕</button>
      `;
      queue.appendChild(row);
  }

  function syncVvStyle(charSel) {
      const row = charSel.closest('.vv-row');
      row.querySelector('.vv-style').innerHTML = makeStyleOptions(charSel.value, null);
  }

  // ── 音声キャッシュ（ページ内メモリ） ──
  const _vvCache = new Map();
  async function fetchAudio(text, speaker) {
      const key = `${speaker}|${text}`;
      if (_vvCache.has(key)) return _vvCache.get(key);
      const res = await fetch('/admin/api-status.php?voicevox=1&text=' + encodeURIComponent(text) + '&speaker=' + speaker);
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const blob = await res.blob();
      _vvCache.set(key, blob);
      return blob;
  }

  // ── キュー再生 ──
  let _vvStop = false;
  async function doVoiceQueue() {
      const rows = document.querySelectorAll('#vv-queue .vv-row');
      const resultEl = document.getElementById('vv-test-result');
      const items = [...rows].map(r => ({
          text: r.querySelector('.vv-text').value.trim(),
          speaker: r.querySelector('.vv-style').value,
          row: r
      })).filter(i => i.text && i.speaker);
      if (!items.length) {
          resultEl.className = 'test-result ng'; resultEl.style.display = 'block';
          resultEl.textContent = 'テキストを入力してください'; return;
      }
      _vvStop = false;
      document.getElementById('vv-play-btn').style.display = 'none';
      document.getElementById('vv-stop-btn').style.display = '';
      resultEl.style.display = 'none';
      let errors = 0;
      // 最初のアイテムを先行フェッチ
      let prefetch = fetchAudio(items[0].text, items[0].speaker).catch(() => null);
      for (let i = 0; i < items.length; i++) {
          if (_vvStop) break;
          const item = items[i];
          item.row.classList.add('playing');
          // 次のアイテムを再生中に並列フェッチ開始
          const nextPrefetch = (i + 1 < items.length)
              ? fetchAudio(items[i + 1].text, items[i + 1].speaker).catch(() => null)
              : null;
          try {
              const blob = await prefetch;
              if (!blob) throw new Error('取得失敗');
              await new Promise((resolve, reject) => {
                  const url = URL.createObjectURL(blob);
                  const audio = new Audio(url);
                  audio.onended = () => { URL.revokeObjectURL(url); resolve(); };
                  audio.onerror = () => { URL.revokeObjectURL(url); reject(new Error('再生エラー')); };
                  if (_vvStop) { URL.revokeObjectURL(url); resolve(); return; }
                  audio.play();
              });
          } catch (e) {
              errors++;
          } finally {
              item.row.classList.remove('playing');
              prefetch = nextPrefetch;
          }
      }
      document.getElementById('vv-play-btn').style.display = '';
      document.getElementById('vv-stop-btn').style.display = 'none';
      if (!_vvStop) {
          resultEl.className = errors ? 'test-result ng' : 'test-result ok';
          resultEl.style.display = 'block';
          resultEl.textContent = errors ? `${errors}件のエラーが発生しました` : '再生完了';
      }
  }

  async function downloadVvRow(btn) {
      const row = btn.closest('.vv-row');
      const text    = row.querySelector('.vv-text').value.trim();
      const speaker = row.querySelector('.vv-style').value;
      if (!text || !speaker) return;
      const orig = btn.textContent;
      btn.textContent = '…'; btn.disabled = true;
      try {
          const blob = await fetchAudio(text, speaker);
          const url = URL.createObjectURL(blob);
          const a = document.createElement('a');
          const charName = row.querySelector('.vv-char').selectedOptions[0]?.text ?? 'voice';
          const styleName = row.querySelector('.vv-style').selectedOptions[0]?.text ?? '';
          const safe = text.slice(0, 20).replace(/[\\/:*?"<>|]/g, '_');
          a.href = url;
          a.download = `${charName}_${styleName}_${safe}.wav`;
          a.click();
          setTimeout(() => URL.revokeObjectURL(url), 1000);
      } catch (e) {
          const resultEl = document.getElementById('vv-test-result');
          resultEl.className = 'test-result ng'; resultEl.style.display = 'block';
          resultEl.textContent = 'ダウンロード失敗: ' + e.message;
      } finally { btn.textContent = orig; btn.disabled = false; }
  }

  function stopVoiceQueue() {
      _vvStop = true;
      document.getElementById('vv-play-btn').style.display = '';
      document.getElementById('vv-stop-btn').style.display = 'none';
  }

  document.addEventListener('DOMContentLoaded', loadVvSpeakers);
  </script>

  <script src="/assets/js/common.js?v=20260327h"></script>
</body>
</html>
