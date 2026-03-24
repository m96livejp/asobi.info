<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$db = new PDO('sqlite:' . __DIR__ . '/../data/pokemon_quest.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$msg = ''; $msg_type = '';
$fieldTypes = ['all'=>'共通','name'=>'ポケモン名','number'=>'数字','move'=>'わざ名'];

// 個別追加
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $input  = trim($_POST['input_text'] ?? '');
    $output = trim($_POST['output_text'] ?? '');
    $field  = $_POST['field_type'] ?? 'all';
    $match  = ($field === 'all') ? 'exact' : 'replace';
    if ($input && $output) {
        try {
            $db->prepare('INSERT OR REPLACE INTO voice_fixes (input_text, output_text, match_type, field_type) VALUES (?,?,?,?)')
               ->execute([$input, $output, $match, $field]);
            $msg = "「{$input}」→「{$output}」を追加しました"; $msg_type = 'success';
        } catch (Exception $e) {
            $msg = 'エラー: ' . $e->getMessage(); $msg_type = 'error';
        }
    } else {
        $msg = '入力テキストと変換先を入力してください'; $msg_type = 'error';
    }
}

// 一括追加
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk') {
    $lines = explode("\n", $_POST['bulk_text'] ?? '');
    $field  = $_POST['field_type'] ?? 'all';
    $match  = ($field === 'all') ? 'exact' : 'replace';
    $count = 0; $skipped = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (!$line) continue;
        $parts = preg_split('/[,\t→＝=]/', $line, 2);
        if (count($parts) === 2) {
            $inp = trim($parts[0]);
            $out = trim($parts[1]);
            if ($inp && $out) {
                $existing = $db->prepare('SELECT output_text FROM voice_fixes WHERE input_text=? AND field_type=?');
                $existing->execute([$inp, $field]);
                $row = $existing->fetch();
                if ($row && $row['output_text'] === $out) { $skipped[] = $inp; continue; }
                try {
                    $db->prepare('INSERT OR REPLACE INTO voice_fixes (input_text, output_text, match_type, field_type) VALUES (?,?,?,?)')
                       ->execute([$inp, $out, $match, $field]);
                    $count++;
                } catch (Exception $e) {}
            }
        }
    }
    $msg = "{$count}件追加しました";
    if (!empty($skipped)) $msg .= '　スキップ: ' . implode(', ', $skipped);
    $msg_type = 'success';
}

// まとめて追加（複数の認識テキスト → 1つの変換先）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'batch') {
    $lines = explode("\n", $_POST['batch_inputs'] ?? '');
    $output = trim($_POST['batch_output'] ?? '');
    $field  = $_POST['field_type'] ?? 'all';
    $match  = ($field === 'all') ? 'exact' : 'replace';
    $count = 0; $skipped = [];
    if ($output) {
        foreach ($lines as $line) {
            $inp = trim($line);
            if (!$inp) continue;
            $existing = $db->prepare('SELECT output_text FROM voice_fixes WHERE input_text=? AND field_type=?');
            $existing->execute([$inp, $field]);
            $row = $existing->fetch();
            if ($row && $row['output_text'] === $output) {
                $skipped[] = $inp;
                continue;
            }
            try {
                $db->prepare('INSERT OR REPLACE INTO voice_fixes (input_text, output_text, match_type, field_type) VALUES (?,?,?,?)')
                   ->execute([$inp, $output, $match, $field]);
                $count++;
            } catch (Exception $e) {}
        }
    }
    $msg = "{$count}件追加しました（→ {$output}）";
    if (!empty($skipped)) $msg .= '　スキップ: ' . implode(', ', $skipped);
    $msg_type = 'success';
}

// 削除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $db->prepare('DELETE FROM voice_fixes WHERE id=?')->execute([$id]);
        $msg = '削除しました'; $msg_type = 'success';
    }
}

// 適用場所変更
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_type') {
    $id = (int)($_POST['id'] ?? 0);
    $newType = $_POST['new_type'] ?? '';
    if ($id && isset($fieldTypes[$newType])) {
        $match = ($newType === 'all') ? 'exact' : 'replace';
        $db->prepare('UPDATE voice_fixes SET field_type=?, match_type=? WHERE id=?')->execute([$newType, $match, $id]);
    }
    header('Location: /admin/voice-fixes.php');
    exit;
}

// 全件取得
$allFixes = $db->query('SELECT * FROM voice_fixes ORDER BY field_type, id DESC')->fetchAll(PDO::FETCH_ASSOC);

// 処理順を計算（replaceタイプは文字数長い順、exactは完全一致なので順序不問）
$replaceItems = array_filter($allFixes, fn($f) => $f['match_type'] === 'replace');
usort($replaceItems, fn($a, $b) => mb_strlen($b['input_text']) - mb_strlen($a['input_text']));
$processOrder = [];
$order = 1;
foreach ($replaceItems as $r) {
    $processOrder[$r['id']] = $order++;
}
// exactは「—」表示
foreach ($allFixes as $f) {
    if ($f['match_type'] === 'exact' && !isset($processOrder[$f['id']])) {
        $processOrder[$f['id']] = null;
    }
}

layout_head('音声認識 補正辞書', 'voice-fixes');
?>
<style>
  .vf-table { width:100%; border-collapse:collapse; font-size:0.85rem; }
  .vf-table th, .vf-table td { padding:5px 8px; border-bottom:1px solid #ddd; text-align:left; }
  .vf-table th { background:#f5f5f5; font-weight:600; cursor:pointer; }
  .vf-table th:hover { background:#eee; }
  .vf-form { display:flex; gap:8px; align-items:center; margin-bottom:16px; flex-wrap:wrap; }
  .vf-form input, .vf-form select { padding:6px 10px; border:2px solid #ddd; border-radius:6px; font-size:0.9rem; }
  .vf-form button { padding:6px 16px; border:none; border-radius:6px; font-size:0.85rem; font-weight:600; cursor:pointer; }
  .vf-add { background:#27ae60; color:#fff; }
  .vf-del { background:none; border:none; color:#e74c3c; cursor:pointer; font-size:0.8rem; padding:2px 6px; }
  .vf-del:hover { text-decoration:underline; }
  .vf-bulk { width:100%; min-height:80px; padding:10px; border:2px solid #ddd; border-radius:8px; font-size:0.85rem; font-family:inherit; resize:vertical; }
  .vf-msg { padding:8px 14px; border-radius:8px; margin-bottom:12px; font-size:0.85rem; }
  .vf-msg.success { background:#d4edda; color:#155724; }
  .vf-msg.error { background:#f8d7da; color:#721c24; }
  .vf-section { margin-bottom:16px; padding:14px; background:#fff; border-radius:10px; border:1px solid #e0e0e0; }
  .vf-section h4 { margin-bottom:8px; font-size:0.95rem; }
  .vf-search { padding:6px 10px; border:2px solid #ddd; border-radius:6px; font-size:0.85rem; width:250px; margin-bottom:10px; }
  .vf-type-sel { padding:2px 6px; border:1px solid #ddd; border-radius:4px; font-size:0.8rem; cursor:pointer; background:#fff; }
  .vf-count { font-size:0.82rem; color:#636e72; margin-bottom:8px; }
  .vf-table tbody tr:nth-child(even) { background:#f9f9f9; }
  .vf-filter-btn { padding:4px 12px; border:1.5px solid #ddd; border-radius:16px; font-size:0.82rem; font-weight:600; cursor:pointer; background:transparent; color:#636e72; }
  .vf-filter-btn:hover { border-color:#e17055; }
  .vf-filter-btn.active { background:#e17055; border-color:#e17055; color:#fff; }
  .vf-del { white-space:nowrap; }
</style>

<?php if ($msg): ?>
<div class="vf-msg <?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
  <div class="vf-section">
    <h4>CSV追加</h4>
    <form method="POST">
      <input type="hidden" name="action" value="bulk">
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">
        <select name="field_type">
          <?php foreach ($fieldTypes as $ft => $label): ?>
          <option value="<?= $ft ?>"><?= $label ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="vf-add">追加</button>
      </div>
      <div style="font-size:0.75rem;color:#888;margin-bottom:4px;line-height:1.5;">変換前文字列,変換後文字列 の形式で1行ずつ入力<br>例：球根,キュウコン / 返信,へんしん</div>
      <textarea name="bulk_text" class="vf-bulk" style="min-height:120px;" placeholder="CSV形式（1行1つ、カンマ区切り）&#10;例：&#10;六本,ロコン&#10;球根,キュウコン"></textarea>
    </form>
  </div>
  <div class="vf-section">
    <h4>まとめて追加</h4>
    <form method="POST">
      <input type="hidden" name="action" value="batch">
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">
        <select name="field_type">
          <?php foreach ($fieldTypes as $ft => $label): ?>
          <option value="<?= $ft ?>"><?= $label ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="vf-add">追加</button>
      </div>
      <div style="font-size:0.75rem;color:#888;margin-bottom:4px;line-height:1.5;">複数の認識テキストを同じ変換先に一括登録<br>例：返信、変身 → へんしん</div>
      <div style="display:flex;gap:8px;">
        <div style="flex:1;position:relative;">
          <textarea id="batch-inputs" name="batch_inputs" class="vf-bulk" style="min-height:120px;padding-right:30px;" placeholder="認識テキスト（1行1つ）&#10;例：&#10;返信&#10;変身"></textarea>
          <button type="button" onclick="startBatchVoice()" style="position:absolute;right:6px;top:6px;background:none;border:none;cursor:pointer;font-size:0.9rem;" title="音声で追加">🎤</button>
        </div>
        <div style="display:flex;align-items:flex-start;font-size:1.2rem;padding-top:6px;">→</div>
        <div style="position:relative;align-self:flex-start;">
          <input type="text" id="batch-output" name="batch_output" placeholder="変換先" required style="width:120px;padding:6px 10px;padding-right:28px;border:2px solid #ddd;border-radius:6px;font-size:0.9rem;">
          <button type="button" onclick="startBatchOutputVoice()" style="position:absolute;right:4px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:0.9rem;" title="音声入力">🎤</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="vf-section">
  <h4 style="margin-bottom:10px;">一覧（<span id="vf-visible-count"><?= count($allFixes) ?></span> / <?= count($allFixes) ?>件）</h4>
  <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
    <button class="vf-filter-btn active" data-type="" onclick="filterByType(this)">すべて</button>
    <?php foreach ($fieldTypes as $ft => $label): ?>
    <button class="vf-filter-btn" data-type="<?= $ft ?>" onclick="filterByType(this)"><?= $label ?></button>
    <?php endforeach; ?>
    <div style="position:relative;flex:1;min-width:150px;">
      <input type="text" id="vf-filter" class="vf-search" style="width:100%;padding-right:30px;" placeholder="検索..." oninput="filterVF()">
      <button type="button" onclick="startVFVoice()" style="position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:0.9rem;" title="音声入力">🎤</button>
    </div>
  </div>
  <table class="vf-table" id="vf-table">
    <thead>
      <tr>
        <th style="width:40px;">順</th>
        <th style="width:120px;">適用場所</th>
        <th onclick="sortVF('input')" style="cursor:pointer;">認識テキスト <span id="sort-input-icon">⇅</span></th>
        <th onclick="sortVF('output')" style="cursor:pointer;">変換先 <span id="sort-output-icon">⇅</span></th>
        <th style="width:60px;white-space:nowrap;"></th>
      </tr>
    </thead>
    <tbody>
      <tr style="background:#eafaf1;">
        <form method="POST">
        <input type="hidden" name="action" value="add">
        <td style="text-align:center;color:#aaa;">—</td>
        <td>
          <select name="field_type" class="vf-type-sel" style="width:100%;">
            <?php foreach ($fieldTypes as $ft => $label): ?>
            <option value="<?= $ft ?>"><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td><input type="text" name="input_text" placeholder="認識テキスト" required style="width:100%;padding:3px 6px;border:1px solid #ccc;border-radius:4px;font-size:0.85rem;"></td>
        <td><input type="text" name="output_text" placeholder="変換先" required style="width:100%;padding:3px 6px;border:1px solid #ccc;border-radius:4px;font-size:0.85rem;"></td>
        <td style="white-space:nowrap;"><button type="submit" class="vf-add" style="padding:3px 10px;font-size:0.8rem;white-space:nowrap;">追加</button></td>
        </form>
      </tr>
      <?php foreach ($allFixes as $f): ?>
      <tr data-type="<?= $f['field_type'] ?>" data-input="<?= htmlspecialchars($f['input_text']) ?>" data-output="<?= htmlspecialchars($f['output_text']) ?>" data-search="<?= htmlspecialchars(strtolower($f['input_text'] . ' ' . $f['output_text'] . ' ' . ($fieldTypes[$f['field_type']] ?? ''))) ?>">
        <td style="text-align:center;font-size:0.75rem;color:#888;"><?= isset($processOrder[$f['id']]) && $processOrder[$f['id']] !== null ? $processOrder[$f['id']] : '—' ?></td>
        <td>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="change_type">
            <input type="hidden" name="id" value="<?= $f['id'] ?>">
            <select name="new_type" class="vf-type-sel" onchange="this.form.submit()">
              <?php foreach ($fieldTypes as $ft => $label): ?>
              <option value="<?= $ft ?>" <?= $f['field_type'] === $ft ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </td>
        <td><?= htmlspecialchars($f['input_text']) ?></td>
        <td><?= htmlspecialchars($f['output_text']) ?></td>
        <td>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $f['id'] ?>">
            <button type="submit" class="vf-del">削除</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
let _vfTypeFilter = '';
function filterByType(btn) {
  document.querySelectorAll('.vf-filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  _vfTypeFilter = btn.dataset.type;
  filterVF();
}
function filterVF() {
  const q = document.getElementById('vf-filter').value.toLowerCase();
  let visible = 0;
  document.querySelectorAll('#vf-table tbody tr').forEach(tr => {
    const matchType = !_vfTypeFilter || tr.dataset.type === _vfTypeFilter;
    const matchText = !q || (tr.dataset.search || '').includes(q);
    const show = matchType && matchText;
    tr.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  document.getElementById('vf-visible-count').textContent = visible;
}
let _sortCol = '', _sortAsc = true;
function sortVF(col) {
  if (_sortCol === col) { _sortAsc = !_sortAsc; } else { _sortCol = col; _sortAsc = true; }
  const tbody = document.querySelector('#vf-table tbody');
  const rows = Array.from(tbody.querySelectorAll('tr[data-input]'));
  rows.sort((a, b) => {
    const va = (a.dataset[col] || '').toLowerCase();
    const vb = (b.dataset[col] || '').toLowerCase();
    return _sortAsc ? va.localeCompare(vb, 'ja') : vb.localeCompare(va, 'ja');
  });
  rows.forEach(r => tbody.appendChild(r));
  document.getElementById('sort-input-icon').textContent = '⇅';
  document.getElementById('sort-output-icon').textContent = '⇅';
  document.getElementById('sort-' + col + '-icon').textContent = _sortAsc ? '↑' : '↓';
}
function _startSR(onResult) {
  if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) return;
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  const rec = new SR();
  rec.lang = 'ja-JP';
  rec.continuous = false;
  rec.interimResults = false;
  rec.onresult = function(e) {
    onResult(e.results[0][0].transcript.replace(/[、。，．,.！？!?・\s]/g, ''));
  };
  rec.onerror = function() {};
  rec.start();
}
function startBatchVoice() {
  const ta = document.getElementById('batch-inputs');
  _startSR(function(text) {
    if (ta.value && !ta.value.endsWith('\n')) ta.value += '\n';
    ta.value += text;
  });
}
function startBatchOutputVoice() {
  const el = document.getElementById('batch-output');
  _startSR(function(text) { el.value = text; });
}
function startVFVoice() {
  if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) return;
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  const rec = new SR();
  rec.lang = 'ja-JP';
  rec.continuous = false;
  rec.interimResults = false;
  const el = document.getElementById('vf-filter');
  el.value = '';
  el.placeholder = '🎤 話してください...';
  rec.onresult = function(e) {
    el.value = e.results[0][0].transcript.replace(/[、。，．,.！？!?・\s]/g, '');
    el.placeholder = '検索...';
    filterVF();
  };
  rec.onend = function() { el.placeholder = '検索...'; };
  rec.onerror = function() { el.placeholder = '検索...'; };
  rec.start();
}
</script>

<?php layout_foot(); ?>
