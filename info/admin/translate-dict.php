<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
require_once '/opt/asobi/shared/assets/php/version.php';
asobiRequireAdmin();
session_write_close();

$FLASK = 'http://127.0.0.1:5050';

function flaskApiKey(): string {
    return trim(@file_get_contents('/opt/asobi/translate/api_key.txt') ?: '');
}

function flaskGet(string $path): array {
    global $FLASK;
    $key = flaskApiKey();
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => 5, 'ignore_errors' => true,
        'header' => $key ? "X-Api-Key: $key\r\n" : '',
    ]]);
    $res = @file_get_contents($FLASK . $path, false, $ctx);
    return $res !== false ? (json_decode($res, true) ?: []) : [];
}

function flaskPost(string $path, array $body): array {
    global $FLASK;
    $key = flaskApiKey();
    $payload = json_encode($body);
    $headers = "Content-Type: application/json\r\nContent-Length: " . strlen($payload);
    if ($key) $headers .= "\r\nX-Api-Key: $key";
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => 5, 'ignore_errors' => true,
        'header' => $headers, 'content' => $payload,
    ]]);
    $res = @file_get_contents($FLASK . $path, false, $ctx);
    return $res !== false ? (json_decode($res, true) ?: []) : ['error' => 'connection failed'];
}

function flaskDelete(string $path): array {
    global $FLASK;
    $key = flaskApiKey();
    $ctx = stream_context_create(['http' => [
        'method' => 'DELETE', 'timeout' => 5, 'ignore_errors' => true,
        'header' => $key ? "X-Api-Key: $key\r\n" : '',
    ]]);
    $res = @file_get_contents($FLASK . $path, false, $ctx);
    return $res !== false ? (json_decode($res, true) ?: []) : ['error' => 'connection failed'];
}

// ─── AJAX ハンドラ ───
header_remove();
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['api'];

    if ($action === 'list') {
        echo json_encode(flaskGet('/dict'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'add') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $type = $body['type'] ?? '';
        $key  = trim($body['key'] ?? '');
        $val  = trim($body['value'] ?? '');
        if (!in_array($type, ['pre', 'post']) || !$key || !$val) {
            echo json_encode(['error' => 'invalid params'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(flaskPost("/dict/$type", ['key' => $key, 'value' => $val]), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'del') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $type = $body['type'] ?? '';
        $key  = trim($body['key'] ?? '');
        if (!in_array($type, ['pre', 'post']) || !$key) {
            echo json_encode(['error' => 'invalid params'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(flaskDelete('/dict/' . $type . '/' . rawurlencode($key)), JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['error' => 'unknown action'], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>翻訳辞書 - 管理画面</title>
  <link rel="stylesheet" href="/assets/css/common.css?v=<?= assetVer('/assets/css/common.css') ?>">
  <style>*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; color: #1d2d3a; min-height: 100vh; display: flex; flex-direction: column; }</style>
</head>
<body>
  <?php $adminActivePage = 'translate-dict'; require __DIR__ . '/_sidebar.php'; ?>

  <style>
    .dict-card { background: #fff; border: 1px solid #e0e4e8; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
    .dict-card-title { font-size: 1.05rem; font-weight: 700; padding: 10px 14px; border-left: 4px solid #5567cc; background: #f0f2fb; border-radius: 0 6px 6px 0; color: #1d2d3a; margin-bottom: 16px; }
    .dict-card-title.post { border-left-color: #e67e22; background: #fef6ec; }
    .dict-desc { font-size: 0.82rem; color: #6b7a8d; margin-bottom: 14px; padding: 8px 12px; background: #f8f9fc; border-radius: 6px; }
    table.dict-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-bottom: 14px; }
    .dict-table th { background: #f5f7fa; color: #6b7a8d; font-weight: 600; font-size: 0.72rem; letter-spacing: 0.04em; padding: 8px 12px; text-align: left; border-bottom: 2px solid #e0e4e8; }
    .dict-table td { padding: 9px 12px; border-bottom: 1px solid #f0f2f5; vertical-align: middle; }
    .dict-table tr:hover td { background: #f9fbfc; }
    .dict-table .key-cell { font-weight: 600; color: #1d2d3a; }
    .dict-table .val-cell { color: #2471a3; font-family: monospace; }
    .dict-table .del-btn { background: none; border: none; color: #ccc; font-size: 1rem; cursor: pointer; padding: 2px 6px; border-radius: 4px; }
    .dict-table .del-btn:hover { color: #c0392b; background: #fdecea; }
    .add-row { display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; }
    .add-row .field { display: flex; flex-direction: column; gap: 3px; flex: 1; min-width: 140px; }
    .add-row label { font-size: 0.72rem; font-weight: 600; color: #555; }
    .add-row input { padding: 7px 10px; border: 1px solid #cdd1d8; border-radius: 6px; font-size: 0.85rem; font-family: inherit; }
    .add-row input:focus { outline: none; border-color: #5567cc; }
    .btn-add { padding: 7px 18px; background: #5567cc; color: #fff; border: none; border-radius: 6px; font-size: 0.85rem; cursor: pointer; font-family: inherit; white-space: nowrap; }
    .btn-add:hover { background: #3a4cc0; }
    .btn-add:disabled { opacity: 0.6; cursor: default; }
    .empty-msg { color: #9ba8b5; font-size: 0.83rem; padding: 10px 0; }
    .toast { position: fixed; bottom: 24px; right: 24px; background: #1e8449; color: #fff; padding: 10px 20px; border-radius: 8px; font-size: 0.85rem; display: none; z-index: 999; }
    .toast.ng { background: #c0392b; }
  </style>

  <div class="page-title">翻訳辞書</div>
  <p style="font-size:0.83rem;color:#6b7a8d;margin-bottom:20px;">
    プロンプト翻訳パイプライン（<code>pipeline: true</code>）で使用する辞書です。
    カンマ区切りで分割したトークン単位で適用されます。
  </p>

  <!-- 事前辞書 -->
  <div class="dict-card">
    <div class="dict-card-title">事前辞書（翻訳前に置換）</div>
    <div class="dict-desc">
      翻訳エンジンを通さず、このテーブルの値に直接置き換えます。<br>
      エンジンが苦手な単語（カタカナ語、固有名詞など）を登録してください。
    </div>
    <table class="dict-table" id="pre-table">
      <thead><tr><th>入力（日本語）</th><th>変換後（英語）</th><th></th></tr></thead>
      <tbody id="pre-body"><tr><td colspan="3" class="empty-msg">読み込み中...</td></tr></tbody>
    </table>
    <div class="add-row">
      <div class="field">
        <label>入力（日本語）</label>
        <input type="text" id="pre-key" placeholder="例: ドラゴン">
      </div>
      <div class="field">
        <label>変換後（英語）</label>
        <input type="text" id="pre-val" placeholder="例: dragon">
      </div>
      <button class="btn-add" onclick="addEntry('pre')">追加</button>
    </div>
  </div>

  <!-- 事後辞書 -->
  <div class="dict-card">
    <div class="dict-card-title post">事後辞書（翻訳後に補正）</div>
    <div class="dict-desc">
      翻訳エンジンの出力をこのテーブルで補正します。<br>
      エンジンが誤訳した結果を正しい英語に修正するために使います。
    </div>
    <table class="dict-table" id="post-table">
      <thead><tr><th>エンジン出力</th><th>補正後（英語）</th><th></th></tr></thead>
      <tbody id="post-body"><tr><td colspan="3" class="empty-msg">読み込み中...</td></tr></tbody>
    </table>
    <div class="add-row">
      <div class="field">
        <label>エンジン出力</label>
        <input type="text" id="post-key" placeholder="例: a maid">
      </div>
      <div class="field">
        <label>補正後（英語）</label>
        <input type="text" id="post-val" placeholder="例: maid">
      </div>
      <button class="btn-add" onclick="addEntry('post')">追加</button>
    </div>
  </div>

  <div class="toast" id="toast"></div>

  <script>
  function showToast(msg, ok = true) {
      const t = document.getElementById('toast');
      t.textContent = msg;
      t.className = 'toast' + (ok ? '' : ' ng');
      t.style.display = 'block';
      setTimeout(() => { t.style.display = 'none'; }, 2000);
  }

  function renderTable(type, entries) {
      const tbody = document.getElementById(type + '-body');
      const keys = Object.keys(entries);
      if (keys.length === 0) {
          tbody.innerHTML = '<tr><td colspan="3" class="empty-msg">登録なし</td></tr>';
          return;
      }
      tbody.innerHTML = keys.map(k => `
          <tr>
            <td class="key-cell">${esc(k)}</td>
            <td class="val-cell">${esc(entries[k])}</td>
            <td><button class="del-btn" onclick="delEntry('${type}', ${JSON.stringify(k)})" title="削除">✕</button></td>
          </tr>`).join('');
  }

  async function loadAll() {
      const res = await fetch('?api=list');
      const d = await res.json();
      renderTable('pre',  d.pre  || {});
      renderTable('post', d.post || {});
  }

  async function addEntry(type) {
      const key = document.getElementById(type + '-key').value.trim();
      const val = document.getElementById(type + '-val').value.trim();
      if (!key || !val) { showToast('キーと値を入力してください', false); return; }
      const res = await fetch('?api=add', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({type, key, value: val})
      });
      const d = await res.json();
      if (d.ok) {
          document.getElementById(type + '-key').value = '';
          document.getElementById(type + '-val').value = '';
          showToast('追加しました');
          loadAll();
      } else {
          showToast(d.error || '追加に失敗しました', false);
      }
  }

  async function delEntry(type, key) {
      const res = await fetch('?api=del', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({type, key})
      });
      const d = await res.json();
      if (d.ok) { showToast('削除しました'); loadAll(); }
      else showToast(d.error || '削除に失敗しました', false);
  }

  function esc(s) {
      return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  loadAll();

  // Enterキーで追加
  ['pre','post'].forEach(type => {
      ['key','val'].forEach(f => {
          document.getElementById(type + '-' + f).addEventListener('keydown', e => {
              if (e.key === 'Enter') addEntry(type);
          });
      });
  });
  </script>

  </main></div>
</body>
</html>
