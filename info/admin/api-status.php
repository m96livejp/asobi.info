<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
asobiRequireAdmin();
session_write_close();

// ─── AJAX: ping チェック（HTML出力より前に処理） ───
if (isset($_GET['check'])) {
    header('Content-Type: application/json; charset=utf-8');
    $url = $_GET['url'] ?? '';
    $result = ['ok' => false, 'ms' => null, 'detail' => ''];

    if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
        $t0 = microtime(true);
        $ctx = stream_context_create(['http' => [
            'timeout'       => 4,
            'ignore_errors' => true,
            'method'        => 'GET',
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        $ms  = (int)((microtime(true) - $t0) * 1000);

        if ($res !== false) {
            $result = ['ok' => true, 'ms' => $ms, 'detail' => mb_substr($res, 0, 80)];
        } else {
            $result = ['ok' => false, 'ms' => $ms, 'detail' => '接続できませんでした'];
        }
    } else {
        $result['detail'] = 'URLが設定されていません';
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── aic.sqlite からAPI設定を読む ───
$aicDbPath = '/opt/asobi/aic/data/aic.sqlite';
$sdEndpoint = '';
$ltEndpoint = '';
$ltMode     = '';
$sdEnabled  = 0;
$ollamaEndpoint = '';
$ollamaModel = '';

if (file_exists($aicDbPath)) {
    try {
        $aicDb = new PDO('sqlite:' . $aicDbPath);
        $aicDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $sd = $aicDb->query("SELECT endpoint, lt_endpoint, lt_mode, enabled FROM sd_settings WHERE id=1")->fetch();
        if ($sd) {
            $sdEndpoint = $sd['endpoint'] ?? '';
            $ltEndpoint = $sd['lt_endpoint'] ?? '';
            $ltMode     = $sd['lt_mode'] ?? 'off';
            $sdEnabled  = (int)($sd['enabled'] ?? 0);
        }

        $ai = $aicDb->query("SELECT provider, endpoint, model FROM ai_settings WHERE id=1")->fetch();
        if ($ai && $ai['provider'] === 'ollama') {
            $ollamaEndpoint = $ai['endpoint'] ?? '';
            $ollamaModel    = $ai['model'] ?? '';
        }
    } catch (Exception $e) { /* DB読み取り失敗は無視 */ }
}

$voicevoxUrl    = 'http://127.0.0.1:50021';
$sdCheckUrl     = $sdEndpoint ? rtrim($sdEndpoint, '/') . '/sdapi/v1/options' : '';
$ltCheckUrl     = $ltEndpoint ? rtrim($ltEndpoint, '/') . '/languages' : '';

$ollamaCheckUrl = '';
if ($ollamaEndpoint) {
    $parsed = parse_url($ollamaEndpoint);
    if ($parsed) {
        $ollamaCheckUrl = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '') . ':' . ($parsed['port'] ?? 80) . '/api/version';
    }
}
$voicevoxCheckUrl = $voicevoxUrl . '/version';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>API接続確認 - 管理画面</title>
  <link rel="stylesheet" href="/assets/css/common.css?v=20260327f">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; color: #1d2d3a; min-height: 100vh; display: flex; flex-direction: column; }
  </style>
</head>
<body>
  <?php $adminActivePage = 'api-status'; require __DIR__ . '/_sidebar.php'; ?>

  <style>
    .api-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 28px; }
    .api-card { background: #fff; border: 1px solid #e0e4e8; border-radius: 10px; padding: 18px 20px; }
    .api-card-header { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
    .api-icon { font-size: 1.6rem; }
    .api-name { font-weight: 700; font-size: 1rem; }
    .api-status { font-size: 0.75rem; padding: 2px 8px; border-radius: 10px; font-weight: 600; margin-left: auto; }
    .api-status.enabled  { background: #eaf6ee; color: #1e8449; }
    .api-status.disabled { background: #f5f5f5; color: #888; }
    .api-status.noconfig { background: #fef9e7; color: #b7950b; }
    .api-info { font-size: 0.8rem; color: #666; margin-bottom: 10px; line-height: 1.6; }
    .api-info span { display: block; }
    .api-info code { background: #f0f2f5; padding: 1px 5px; border-radius: 3px; font-family: monospace; word-break: break-all; }
    .btn-check { font-size: 0.8rem; padding: 6px 14px; background: #5567cc; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-family: inherit; transition: background 0.15s; }
    .btn-check:hover { background: #3a4cc0; }
    .check-result { margin-top: 8px; font-size: 0.8rem; padding: 6px 10px; border-radius: 6px; display: none; }
    .check-result.ok { background: #eaf6ee; color: #1e8449; }
    .check-result.ng { background: #fdecea; color: #c0392b; }
    .note-box { background: #f8f9fe; border: 1px solid #dde1f5; border-radius: 8px; padding: 14px 18px; font-size: 0.83rem; color: #3a4cc0; line-height: 1.7; }
  </style>

  <div class="page-title">API接続確認</div>
  <p style="font-size:0.85rem;color:#6b7a8d;margin-bottom:20px;">
    各外部APIの設定・接続状態を確認できます。設定変更は <a href="https://aic.asobi.info/admin.html" target="_blank" style="color:#5567cc;">AI チャット管理画面</a> から行います。
  </p>

  <div class="api-grid">

    <!-- Stable Diffusion -->
    <div class="api-card">
      <div class="api-card-header">
        <div class="api-icon">🎨</div>
        <div class="api-name">Stable Diffusion</div>
        <?php if ($sdEndpoint && $sdEnabled): ?>
          <span class="api-status enabled">有効</span>
        <?php elseif ($sdEndpoint): ?>
          <span class="api-status disabled">無効</span>
        <?php else: ?>
          <span class="api-status noconfig">未設定</span>
        <?php endif; ?>
      </div>
      <div class="api-info">
        <span><strong>用途:</strong> aic サービスでの画像生成</span>
        <span><strong>エンドポイント:</strong> <?= $sdEndpoint ? '<code>' . htmlspecialchars($sdEndpoint) . '</code>' : '未設定' ?></span>
        <span><strong>有効:</strong> <?= $sdEnabled ? 'はい' : 'いいえ' ?></span>
      </div>
      <?php if ($sdCheckUrl): ?>
      <button class="btn-check" onclick="checkApi(this, <?= htmlspecialchars(json_encode($sdCheckUrl)) ?>)">接続テスト</button>
      <div class="check-result"></div>
      <?php else: ?>
      <span style="font-size:0.8rem;color:#aaa;">エンドポイント未設定のためテスト不可</span>
      <?php endif; ?>
    </div>

    <!-- Ollama -->
    <div class="api-card">
      <div class="api-card-header">
        <div class="api-icon">💬</div>
        <div class="api-name">Ollama（チャットAI）</div>
        <?php if ($ollamaEndpoint): ?>
          <span class="api-status enabled">設定済</span>
        <?php else: ?>
          <span class="api-status noconfig">未設定</span>
        <?php endif; ?>
      </div>
      <div class="api-info">
        <span><strong>用途:</strong> aic サービスでのAIチャット</span>
        <span><strong>エンドポイント:</strong> <?= $ollamaEndpoint ? '<code>' . htmlspecialchars($ollamaEndpoint) . '</code>' : '未設定' ?></span>
        <span><strong>モデル:</strong> <?= $ollamaModel ? htmlspecialchars($ollamaModel) : '未設定' ?></span>
      </div>
      <?php if ($ollamaCheckUrl): ?>
      <button class="btn-check" onclick="checkApi(this, <?= htmlspecialchars(json_encode($ollamaCheckUrl)) ?>)">接続テスト</button>
      <div class="check-result"></div>
      <?php else: ?>
      <span style="font-size:0.8rem;color:#aaa;">エンドポイント未設定のためテスト不可</span>
      <?php endif; ?>
    </div>

    <!-- LibreTranslate -->
    <div class="api-card">
      <div class="api-card-header">
        <div class="api-icon">🌐</div>
        <div class="api-name">LibreTranslate</div>
        <?php
        $ltLabel = match($ltMode) {
            'local' => '有効（ローカル）',
            'free'  => '有効（無料API）',
            'both'  => '有効（両方）',
            default => '無効',
        };
        $ltClass = ($ltMode !== 'off') ? 'enabled' : 'disabled';
        ?>
        <span class="api-status <?= $ltClass ?>"><?= htmlspecialchars($ltLabel) ?></span>
      </div>
      <div class="api-info">
        <span><strong>用途:</strong> aic サービスでの翻訳機能</span>
        <span><strong>モード:</strong> <?= htmlspecialchars($ltMode ?: 'off') ?></span>
        <span><strong>ローカルURL:</strong> <?= $ltEndpoint ? '<code>' . htmlspecialchars($ltEndpoint) . '</code>' : '未設定' ?></span>
      </div>
      <?php if ($ltCheckUrl): ?>
      <button class="btn-check" onclick="checkApi(this, <?= htmlspecialchars(json_encode($ltCheckUrl)) ?>)">ローカルテスト</button>
      <div class="check-result"></div>
      <?php else: ?>
      <span style="font-size:0.8rem;color:#aaa;">ローカルエンドポイント未設定のためテスト不可</span>
      <?php endif; ?>
      <button class="btn-check" style="margin-top:6px;" onclick="checkApi(this, 'https://libretranslate.com/languages', document.getElementById('lt-free-result'))">無料版テスト</button>
      <div class="check-result" id="lt-free-result"></div>
    </div>

    <!-- VoiceVox -->
    <div class="api-card">
      <div class="api-card-header">
        <div class="api-icon">🔊</div>
        <div class="api-name">VOICEVOX</div>
        <span class="api-status noconfig">接続確認要</span>
      </div>
      <div class="api-info">
        <span><strong>用途:</strong> 音声読み上げ機能（voice サービス）</span>
        <span><strong>エンドポイント:</strong> <code><?= htmlspecialchars($voicevoxUrl) ?></code></span>
        <span><strong>デフォルト話者:</strong> ID 3（ずんだもん）</span>
      </div>
      <button class="btn-check" onclick="checkApi(this, <?= htmlspecialchars(json_encode($voicevoxCheckUrl)) ?>)">接続テスト</button>
      <div class="check-result"></div>
    </div>

  </div>

  <div class="note-box">
    <strong>API設定の変更方法:</strong><br>
    · Stable Diffusion / LibreTranslate / Ollama の設定変更 →
      <a href="https://aic.asobi.info/admin.html" target="_blank" style="color:#3a4cc0;">aic 管理画面</a><br>
    · VoiceVox は固定URL（localhost:50021）のため設定変更不要<br>
    · リモートURL: サーバーサイドから疎通確認（タイムアウト4秒）<br>
    · ローカルURL（127.x / localhost）: ブラウザから直接確認（LibreTranslate / VOICEVOX）
  </div>

  <script>
  async function checkApi(btn, url, resultEl) {
      const card = btn.closest('.api-card');
      resultEl = resultEl || card.querySelector('.check-result');
      btn.disabled = true;
      btn.textContent = '確認中...';
      resultEl.style.display = 'none';

      const isLocal = /^https?:\/\/(127\.|localhost)/i.test(url);
      try {
          let ok, ms, detail;
          if (isLocal) {
              // ローカルエンドポイントはブラウザから直接チェック
              const t0 = performance.now();
              const res = await fetch(url, { signal: AbortSignal.timeout(4000) });
              ms = Math.round(performance.now() - t0);
              ok = res.ok;
              detail = res.ok ? '' : 'HTTP ' + res.status;
          } else {
              const res = await fetch('/admin/api-status.php?check=1&url=' + encodeURIComponent(url));
              const data = await res.json();
              ok = data.ok; ms = data.ms; detail = data.detail;
          }
          resultEl.className = 'check-result ' + (ok ? 'ok' : 'ng');
          resultEl.style.display = 'block';
          resultEl.textContent = ok ? '接続OK (' + ms + 'ms)' : 'エラー: ' + (detail || '接続できませんでした');
      } catch (e) {
          resultEl.className = 'check-result ng';
          resultEl.style.display = 'block';
          const msg = e.name === 'TimeoutError' ? 'タイムアウト（4秒）'
                    : (isLocal ? 'CORS エラーまたは未起動（PC上で起動しているか確認してください）'
                               : 'リクエストに失敗しました');
          resultEl.textContent = msg;
      } finally {
          btn.disabled = false;
          btn.textContent = '接続テスト';
      }
  }
  </script>

    </main>
  </div>
</body>
</html>
