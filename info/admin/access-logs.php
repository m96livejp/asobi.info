<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
asobiRequireAdmin();

$db = asobiUsersDb();

// フィルター
$filterIp   = trim($_GET['ip']   ?? '');
$filterHost = trim($_GET['host'] ?? '');
$filterPath = trim($_GET['path'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

// WHERE 条件
$where = [];
$params = [];
if ($filterIp   !== '') { $where[] = 'ip = ?';         $params[] = $filterIp; }
if ($filterHost !== '') { $where[] = 'host LIKE ?';    $params[] = '%' . $filterHost . '%'; }
if ($filterPath !== '') { $where[] = 'path LIKE ?';    $params[] = '%' . $filterPath . '%'; }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// 件数・データ取得
$total = $db->prepare("SELECT COUNT(*) FROM access_logs $whereSQL");
$total->execute($params);
$totalCount = (int)$total->fetchColumn();
$totalPages = (int)ceil($totalCount / $perPage);

$stmt = $db->prepare("SELECT * FROM access_logs $whereSQL ORDER BY id DESC LIMIT ? OFFSET ?");
$stmt->execute([...$params, $perPage, $offset]);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ホスト一覧（フィルター用）
$hosts = $db->query("SELECT DISTINCT host FROM access_logs WHERE host != '' ORDER BY host")->fetchAll(PDO::FETCH_COLUMN);

// クエリ文字列再構成ヘルパー
function buildQuery(array $extra = []): string {
    global $filterIp, $filterHost, $filterPath, $page;
    $base = array_filter([
        'ip'   => $filterIp,
        'host' => $filterHost,
        'path' => $filterPath,
        'page' => $page > 1 ? $page : null,
    ]);
    return http_build_query(array_merge($base, $extra));
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>アクセスログ - 管理画面</title>
  <link rel="stylesheet" href="/assets/css/common.css?v=20260327e">
  <style>
    body { background: #f5f5f5; color: #222; font-family: sans-serif; margin: 0; padding: 0; display: flex; flex-direction: column; min-height: 100vh; }
    h1 { font-size: 1.4rem; margin-bottom: 20px; }
    .filter-bar { background: #fff; border: 1px solid #e0e4e8; border-radius: 10px; padding: 14px 16px; margin-bottom: 18px; display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
    .filter-bar label { font-size: 0.8rem; color: #637080; display: block; margin-bottom: 4px; }
    .filter-bar input, .filter-bar select { border: 1px solid #d0d6e0; border-radius: 6px; padding: 6px 10px; font-size: 0.85rem; }
    .filter-bar button { background: #5567cc; color: #fff; border: none; border-radius: 6px; padding: 7px 16px; font-size: 0.85rem; cursor: pointer; }
    .filter-bar .reset-link { font-size: 0.82rem; color: #888; text-decoration: none; align-self: center; }
    .total-info { font-size: 0.85rem; color: #637080; margin-bottom: 10px; }
    .log-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.07); font-size: 0.84rem; }
    .log-table th { background: #f0f2f7; padding: 9px 10px; text-align: left; font-size: 0.78rem; color: #637080; font-weight: 600; border-bottom: 1px solid #e0e4e8; white-space: nowrap; }
    .log-table td { padding: 8px 10px; border-bottom: 1px solid #f0f2f7; vertical-align: top; }
    .log-table tr:last-child td { border-bottom: none; }
    .log-table tr:hover td { background: #f7f9fc; }
    .ip-link { color: #5567cc; cursor: pointer; text-decoration: underline; font-size: 0.83rem; }
    .path-cell { max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .badge { display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: 0.72rem; font-weight: 600; background: #eef1fb; color: #5567cc; }
    .pagination { display: flex; gap: 6px; margin-top: 16px; flex-wrap: wrap; }
    .pagination a, .pagination span { padding: 6px 12px; border-radius: 6px; font-size: 0.84rem; text-decoration: none; border: 1px solid #ddd; color: #1d2d3a; }
    .pagination a:hover { background: #f0f2f7; }
    .pagination .cur { background: #5567cc; color: #fff; border-color: #5567cc; }
    /* IPログモーダル */
    #ip-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 9999; align-items: center; justify-content: center; }
    #ip-modal.open { display: flex; }
    .ip-modal-box { background: #fff; border-radius: 12px; padding: 24px; width: min(700px, 95vw); max-height: 80vh; overflow-y: auto; box-shadow: 0 8px 32px rgba(0,0,0,.2); }
    .ip-modal-box h3 { margin: 0 0 16px; font-size: 1rem; color: #1d2d3a; }
    .ip-modal-box h4 { font-size: 0.85rem; color: #637080; margin: 14px 0 8px; border-bottom: 1px solid #eee; padding-bottom: 4px; }
    .ip-modal-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
    .ip-modal-table th { background: #f5f7fa; padding: 6px 8px; text-align: left; color: #637080; font-size: 0.75rem; }
    .ip-modal-table td { padding: 6px 8px; border-bottom: 1px solid #f0f2f7; }
    .ip-modal-close { float: right; background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #888; margin-top: -4px; }
  </style>
</head>
<body>
  <?php $adminActivePage = 'access-logs'; require __DIR__ . '/_sidebar.php'; ?>

      <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:8px;">
        <h1 style="margin:0">アクセス生ログ</h1>
        <a href="/admin/access-stats.php" style="font-size:0.8rem;color:#9ba8b5;text-decoration:none;">← 📊 アクセス統計グラフに戻る</a>
      </div>

      <form class="filter-bar" method="get">
        <div>
          <label>IPアドレス</label>
          <input type="text" name="ip" value="<?= htmlspecialchars($filterIp) ?>" placeholder="例: 1.2.3.4">
        </div>
        <div>
          <label>ホスト</label>
          <select name="host">
            <option value="">全て</option>
            <?php foreach ($hosts as $h): ?>
            <option value="<?= htmlspecialchars($h) ?>" <?= $filterHost === $h ? 'selected' : '' ?>><?= htmlspecialchars($h) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>パス</label>
          <input type="text" name="path" value="<?= htmlspecialchars($filterPath) ?>" placeholder="例: /api/">
        </div>
        <button type="submit">絞り込み</button>
        <?php if ($filterIp || $filterHost || $filterPath): ?>
        <a href="/admin/access-logs.php" class="reset-link">リセット</a>
        <?php endif; ?>
      </form>

      <div class="total-info"><?= number_format($totalCount) ?> 件中 <?= number_format($offset + 1) ?>〜<?= number_format(min($offset + $perPage, $totalCount)) ?> 件表示</div>

      <?php if (empty($logs)): ?>
      <p style="color:#888;padding:20px 0;">ログがありません。</p>
      <?php else: ?>
      <table class="log-table">
        <thead>
          <tr>
            <th>日時</th>
            <th>ホスト</th>
            <th>パス</th>
            <th>ユーザーID</th>
            <th>IPアドレス</th>
            <th>ブラウザ</th>
            <th>デバイス</th>
            <th>OS</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
          <tr>
            <td style="white-space:nowrap;font-size:0.8rem;"><?= htmlspecialchars(substr($log['created_at'], 0, 16)) ?></td>
            <td><?= htmlspecialchars($log['host']) ?></td>
            <td class="path-cell" title="<?= htmlspecialchars($log['path']) ?>"><?= htmlspecialchars($log['path']) ?></td>
            <td><?= $log['user_id'] ? '<span class="badge">' . htmlspecialchars($log['user_id']) . '</span>' : '<span style="color:#ccc">-</span>' ?></td>
            <td><?php if ($log['ip']): ?><span class="ip-link" onclick="showIpLog(<?= htmlspecialchars(json_encode($log['ip'])) ?>)"><?= htmlspecialchars($log['ip']) ?></span><?php else: ?>-<?php endif; ?></td>
            <td><?= htmlspecialchars($log['browser']) ?></td>
            <td><?= htmlspecialchars($log['device']) ?></td>
            <td><?= htmlspecialchars($log['os']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php if ($page > 1): ?>
        <a href="?<?= buildQuery(['page' => $page - 1]) ?>">← 前へ</a>
        <?php endif; ?>
        <?php
        $start = max(1, $page - 3);
        $end   = min($totalPages, $page + 3);
        if ($start > 1) { echo '<span>...</span>'; }
        for ($i = $start; $i <= $end; $i++):
        ?>
        <?php if ($i === $page): ?>
        <span class="cur"><?= $i ?></span>
        <?php else: ?>
        <a href="?<?= buildQuery(['page' => $i]) ?>"><?= $i ?></a>
        <?php endif; ?>
        <?php endfor; ?>
        <?php if ($end < $totalPages) { echo '<span>...</span>'; } ?>
        <?php if ($page < $totalPages): ?>
        <a href="?<?= buildQuery(['page' => $page + 1]) ?>">次へ →</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>

    </main>
  </div>

  <!-- IPログモーダル -->
  <div id="ip-modal">
    <div class="ip-modal-box">
      <button class="ip-modal-close" onclick="closeIpLog()">✕</button>
      <h3 id="ip-modal-title">IP: —</h3>
      <div id="ip-modal-body"><p style="color:#888">読み込み中...</p></div>
    </div>
  </div>

  <script src="/assets/js/common.js?v=20260327h"></script>
  <script>
  async function showIpLog(ip) {
    document.getElementById('ip-modal-title').textContent = 'IP: ' + ip;
    document.getElementById('ip-modal-body').innerHTML = '<p style="color:#888">読み込み中...</p>';
    document.getElementById('ip-modal').classList.add('open');
    try {
      const res = await fetch('/admin/ip-logs.php?ip=' + encodeURIComponent(ip));
      const data = await res.json();
      let html = '';
      // ログイン履歴
      html += '<h4>ログイン履歴</h4>';
      if (data.logins && data.logins.length) {
        html += '<table class="ip-modal-table"><thead><tr><th>日時</th><th>ユーザー名</th><th>ブラウザ</th><th>デバイス</th></tr></thead><tbody>';
        data.logins.forEach(r => {
          html += `<tr><td>${r.created_at.slice(0,16)}</td><td>${esc(r.username)}</td><td>${esc(r.browser)}</td><td>${esc(r.device)}</td></tr>`;
        });
        html += '</tbody></table>';
      } else { html += '<p style="color:#aaa;font-size:0.82rem">なし</p>'; }
      // アクセス履歴
      html += '<h4>アクセス履歴（直近50件）</h4>';
      if (data.accesses && data.accesses.length) {
        html += '<table class="ip-modal-table"><thead><tr><th>日時</th><th>ホスト</th><th>パス</th><th>ブラウザ</th></tr></thead><tbody>';
        data.accesses.forEach(r => {
          html += `<tr><td>${r.created_at.slice(0,16)}</td><td>${esc(r.host)}</td><td>${esc(r.path)}</td><td>${esc(r.browser)}</td></tr>`;
        });
        html += '</tbody></table>';
      } else { html += '<p style="color:#aaa;font-size:0.82rem">なし</p>'; }
      document.getElementById('ip-modal-body').innerHTML = html;
    } catch(e) {
      document.getElementById('ip-modal-body').innerHTML = '<p style="color:red">取得エラー</p>';
    }
  }
  function closeIpLog() {
    document.getElementById('ip-modal').classList.remove('open');
  }
  document.getElementById('ip-modal').addEventListener('click', e => {
    if (e.target === document.getElementById('ip-modal')) closeIpLog();
  });
  function esc(s) {
    if (!s) return '-';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  </script>
</body>
</html>
