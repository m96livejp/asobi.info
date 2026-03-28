<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
asobiRequireAdmin();
session_write_close();

// ─── AJAX: ping チェック ───
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

// ─── AJAX: 翻訳テスト ───
if (isset($_GET['translate'])) {
    header('Content-Type: application/json; charset=utf-8');

    $aicDbPath = '/opt/asobi/aic/data/aic.sqlite';
    $ltEndpoint = '';
    $ltMode     = 'off';
    $ltApiKey   = '';

    if (file_exists($aicDbPath)) {
        try {
            $db = new PDO('sqlite:' . $aicDbPath);
            $row = $db->query("SELECT lt_endpoint, lt_mode, lt_api_key FROM sd_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $ltEndpoint = $row['lt_endpoint'] ?? '';
                $ltMode     = $row['lt_mode'] ?? 'off';
                $ltApiKey   = $row['lt_api_key'] ?? '';
            }
        } catch (Exception $e) {}
    }

    $text   = trim($_POST['text'] ?? '');
    $source = $_POST['source'] ?? 'ja';
    $target = $_POST['target'] ?? 'en';

    if (!$text) {
        echo json_encode(['ok' => false, 'error' => 'テキストを入力してください'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $endpoints = [];
    if (in_array($ltMode, ['local', 'both']) && $ltEndpoint)
        $endpoints[] = ['url' => rtrim($ltEndpoint, '/'), 'key' => ''];
    if (in_array($ltMode, ['free', 'both']))
        $endpoints[] = ['url' => 'https://libretranslate.com', 'key' => $ltApiKey];

    if (!$endpoints) {
        echo json_encode(['ok' => false, 'error' => 'LibreTranslate が無効または未設定です'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $translated = null;
    $usedEndpoint = '';
    $ms = 0;
    foreach ($endpoints as $ep) {
        $payload = json_encode(['q' => $text, 'source' => $source, 'target' => $target, 'api_key' => $ep['key']]);
        $t0 = microtime(true);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload),
            'content'       => $payload,
            'timeout'       => 8,
            'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($ep['url'] . '/translate', false, $ctx);
        $ms  = (int)((microtime(true) - $t0) * 1000);
        if ($res !== false) {
            $data = json_decode($res, true);
            if (!empty($data['translatedText'])) {
                $translated   = $data['translatedText'];
                $usedEndpoint = $ep['url'];
                break;
            }
        }
    }

    if ($translated !== null) {
        echo json_encode(['ok' => true, 'result' => $translated, 'endpoint' => $usedEndpoint, 'ms' => $ms], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false, 'error' => '翻訳に失敗しました（' . $ms . 'ms）'], JSON_UNESCAPED_UNICODE);
    }
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

$voicevoxUrl    = 'http://133.117.75.23:50021';
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
    .api-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 16px; margin-bottom: 28px; }
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
    .translate-test { margin-top: 12px; border-top: 1px solid #e0e4e8; padding-top: 12px; }
    .translate-test-row { display: flex; gap: 6px; align-items: flex-start; margin-bottom: 6px; }
    .translate-test textarea { flex: 1; padding: 7px 10px; border: 1px solid #cdd1d8; border-radius: 6px; font-size: 0.82rem; font-family: inherit; resize: vertical; min-height: 60px; }
    .translate-test select { padding: 7px 8px; border: 1px solid #cdd1d8; border-radius: 6px; font-size: 0.82rem; font-family: inherit; background: #fff; }
    .translate-result { font-size: 0.82rem; padding: 7px 10px; border-radius: 6px; display: none; margin-top: 4px; word-break: break-all; }
    .translate-result.ok { background: #eaf6ee; color: #1e6a34; }
    .translate-result.ng { background: #fdecea; color: #c0392b; }
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
      <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <?php if ($ltCheckUrl): ?>
        <button class="btn-check" onclick="checkApi(this, <?= htmlspecialchars(json_encode($ltCheckUrl)) ?>)">接続テスト</button>
        <?php else: ?>
        <span style="font-size:0.8rem;color:#aaa;">エンドポイント未設定のためテスト不可</span>
        <?php endif; ?>
        <button class="btn-check" onclick="checkApi(this, 'https://libretranslate.com/languages', document.getElementById('lt-free-result'))">無料版テスト</button>
      </div>
      <div class="check-result"></div>
      <div class="check-result" id="lt-free-result"></div>

      <?php if ($ltMode !== 'off'): ?>
      <div class="translate-test">
        <div style="font-size:0.8rem;font-weight:600;margin-bottom:6px;color:#444;">翻訳テスト</div>
        <div class="translate-test-row">
          <textarea id="lt-test-input" placeholder="翻訳するテキストを入力..."></textarea>
          <div style="display:flex;flex-direction:column;gap:4px;">
            <select id="lt-test-dir">
              <option value="ja|en">日→英</option>
              <option value="en|ja">英→日</option>
            </select>
            <button class="btn-check" onclick="doTranslateTest()">送信</button>
          </div>
        </div>
        <div class="translate-result" id="lt-test-result"></div>
      </div>
      <?php endif; ?>
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
    · Stable Diffusion / Ollama: サーバーサイドから疎通確認（タイムアウト4秒）<br>
    · LibreTranslate（ローカル）/ VOICEVOX: ブラウザから直接確認（PC上で起動している必要があります）
  </div>

  <script>
  async function doTranslateTest() {
      const text = document.getElementById('lt-test-input').value.trim();
      const dir  = document.getElementById('lt-test-dir').value.split('|');
      const resultEl = document.getElementById('lt-test-result');
      if (!text) { resultEl.className = 'translate-result ng'; resultEl.style.display = 'block'; resultEl.textContent = 'テキストを入力してください'; return; }
      resultEl.style.display = 'none';
      const btn = document.querySelector('.translate-test .btn-check');
      btn.disabled = true; btn.textContent = '送信中...';
      try {
          const fd = new FormData();
          fd.append('text', text); fd.append('source', dir[0]); fd.append('target', dir[1]);
          const res = await fetch('/admin/api-status.php?translate=1', { method: 'POST', body: fd });
          const data = await res.json();
          resultEl.className = 'translate-result ' + (data.ok ? 'ok' : 'ng');
          resultEl.style.display = 'block';
          resultEl.textContent = data.ok
              ? data.result + '　（' + data.endpoint + '  ' + data.ms + 'ms）'
              : data.error;
      } catch (e) {
          resultEl.className = 'translate-result ng'; resultEl.style.display = 'block'; resultEl.textContent = '通信エラー';
      } finally {
          btn.disabled = false; btn.textContent = '送信';
      }
  }

  async function checkApi(btn, url, resultEl, forceClient, forceServer) {
      const card = btn.closest('.api-card');
      resultEl = resultEl || card.querySelector('.check-result');
      const origText = btn.textContent;
      btn.disabled = true;
      btn.textContent = '確認中...';
      resultEl.style.display = 'none';

      const useClient = !forceServer && (forceClient || /^https?:\/\/(127\.|localhost)/i.test(url));
      try {
          let ok, ms, detail;
          if (useClient) {
              // ブラウザから直接チェック（ローカルAPI / ユーザーPC上のサービス）
              const t0 = performance.now();
              const res = await fetch(url, { signal: AbortSignal.timeout(6000) });
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
          const msg = e.name === 'TimeoutError' ? 'タイムアウト（6秒）'
                    : (useClient ? 'CORS エラーまたは未起動（PC上で起動しているか確認してください）'
                                 : 'リクエストに失敗しました');
          resultEl.textContent = msg;
      } finally {
          btn.disabled = false;
          btn.textContent = origText;
      }
  }
  </script>

    </main>
  </div>
</body>
</html>
