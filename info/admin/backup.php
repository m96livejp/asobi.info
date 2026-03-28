<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
asobiRequireAdmin();
session_write_close();

// ─── 手動バックアップ実行（HTML出力より前に処理） ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    shell_exec('nohup /opt/asobi/backup.sh >> /var/log/asobi_backup.log 2>&1 &');
    header('Location: /admin/backup.php?started=1');
    exit;
}

// ─── ログ解析 ───
$logFile = '/var/log/asobi_backup.log';
$isRunning = (bool)shell_exec('pgrep -f backup.sh 2>/dev/null');
$history = [];

if (file_exists($logFile)) {
    $all = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $current = null;
    foreach ($all as $line) {
        if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] === バックアップ開始/', $line, $m)) {
            $current = ['start' => $m[1], 'end' => null, 'status' => 'running'];
        } elseif ($current && preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] === バックアップ完了/', $line, $m)) {
            $current['end'] = $m[1];
            $current['status'] = 'ok';
            array_unshift($history, $current);
            $current = null;
        } elseif ($current && preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (ERROR|エラー)/', $line, $m)) {
            $current['end'] = $m[1];
            $current['status'] = 'error';
            array_unshift($history, $current);
            $current = null;
        }
    }
    if ($current) {
        array_unshift($history, $current);
    }
    $history = array_slice($history, 0, 10);
}

$latest = $history[0] ?? null;
$latestStatus = $isRunning ? 'running' : ($latest['status'] ?? null);
$started = !empty($_GET['started']);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>バックアップ管理 - 管理画面</title>
  <link rel="stylesheet" href="/assets/css/common.css?v=20260327f">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; color: #1d2d3a; min-height: 100vh; display: flex; flex-direction: column; }
  </style>
</head>
<body>
  <?php $adminActivePage = 'backup'; require __DIR__ . '/_sidebar.php'; ?>

  <style>
    .backup-status { display:flex; align-items:center; gap:14px; padding:18px 22px; border-radius:10px; margin-bottom:24px; }
    .backup-status.ok      { background:#eaf6ee; border:1px solid #b2dfdb; }
    .backup-status.warn    { background:#fef9e7; border:1px solid #ffe082; }
    .backup-status.running { background:#e8f4fd; border:1px solid #bee3f8; }
    .backup-status.error   { background:#fdecea; border:1px solid #f5c6cb; }
    .status-dot { width:13px; height:13px; border-radius:50%; flex-shrink:0; }
    .dot-ok      { background:#27ae60; }
    .dot-warn    { background:#f39c12; }
    .dot-running { background:#3498db; animation:pulse 1s infinite; }
    .dot-error   { background:#e74c3c; }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
    .status-text { font-size:0.92rem; line-height:1.5; }
    .status-text strong { font-size:1rem; }
    .btn-run { padding:10px 24px; background:#5567cc; color:#fff; border:none; border-radius:8px; font-size:0.9rem; font-weight:700; cursor:pointer; font-family:inherit; white-space:nowrap; }
    .btn-run:hover { background:#3a4cc0; }
    .btn-run:disabled { background:#aaa; cursor:not-allowed; }
    .history-table { width:100%; border-collapse:collapse; font-size:0.85rem; }
    .history-table th { background:#f5f7fa; color:#6b7a8d; font-weight:600; font-size:0.72rem; text-transform:uppercase; padding:8px 12px; text-align:left; border-bottom:2px solid #e0e4e8; }
    .history-table td { padding:10px 12px; border-bottom:1px solid #f0f2f5; }
    .result-ok      { color:#1e8449; font-weight:600; }
    .result-error   { color:#c0392b; font-weight:600; }
    .result-running { color:#2471a3; font-weight:600; }
    .msg-ok { background:#eaf6ee; border:1px solid #b2dfdb; border-radius:8px; padding:10px 14px; font-size:0.88rem; color:#1e8449; margin-bottom:16px; }
  </style>

  <div class="page-title">バックアップ管理</div>

  <?php if ($started): ?>
  <div class="msg-ok">バックアップを開始しました。完了まで数分かかります。</div>
  <?php endif; ?>

  <!-- ステータス -->
  <?php
  $cls    = match($latestStatus) { 'ok' => 'ok', 'error' => 'error', 'running' => 'running', default => 'warn' };
  $dotCls = match($latestStatus) { 'ok' => 'dot-ok', 'error' => 'dot-error', 'running' => 'dot-running', default => 'dot-warn' };
  ?>
  <div class="backup-status <?= $cls ?>">
    <div class="status-dot <?= $dotCls ?>"></div>
    <div class="status-text">
      <?php if ($isRunning): ?>
        <strong>実行中</strong><br>
        <span style="font-size:0.82rem;color:#555;">開始: <?= htmlspecialchars($latest['start'] ?? '—') ?></span>
      <?php elseif ($latestStatus === 'ok'): ?>
        <strong>正常完了</strong><br>
        <span style="font-size:0.82rem;color:#555;">最終バックアップ: <?= htmlspecialchars($latest['end'] ?? $latest['start'] ?? '—') ?></span>
      <?php elseif ($latestStatus === 'error'): ?>
        <strong>エラー</strong><br>
        <span style="font-size:0.82rem;color:#555;">最終実行: <?= htmlspecialchars($latest['start'] ?? '—') ?></span>
      <?php else: ?>
        <strong>実行記録なし</strong>
      <?php endif; ?>
    </div>
    <form method="post" style="margin-left:auto;">
      <input type="hidden" name="action" value="run">
      <button type="submit" class="btn-run" <?= $isRunning ? 'disabled' : '' ?>>
        <?= $isRunning ? '実行中...' : '今すぐ実行' ?>
      </button>
    </form>
  </div>

  <!-- 実行履歴 -->
  <div class="card-title mb-8">実行履歴</div>
  <?php if (empty($history)): ?>
  <p style="font-size:0.85rem;color:#999;margin-bottom:20px;">実行記録がありません。</p>
  <?php else: ?>
  <table class="history-table" style="margin-bottom:24px;">
    <thead>
      <tr><th>開始日時</th><th>完了日時</th><th>結果</th></tr>
    </thead>
    <tbody>
      <?php foreach ($history as $h): ?>
      <tr>
        <td><?= htmlspecialchars($h['start']) ?></td>
        <td><?= htmlspecialchars($h['end'] ?? '—') ?></td>
        <td>
          <?php if ($h['status'] === 'ok'): ?>
            <span class="result-ok">完了</span>
          <?php elseif ($h['status'] === 'error'): ?>
            <span class="result-error">エラー</span>
          <?php else: ?>
            <span class="result-running">実行中</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <!-- バックアップ情報 -->
  <div style="background:#f8f9fe;border:1px solid #dde1f5;border-radius:8px;padding:16px 20px;font-size:0.83rem;color:#3a4a5a;line-height:1.8;">
    <div style="margin-bottom:8px;"><strong>バックアップ対象</strong></div>
    <div>· SQLiteデータベース（users / dbd / pkq / game / aic / tbt）</div>
    <div>· サイトファイル一式（/opt/asobi/）</div>
    <div>· Nginx設定ファイル</div>
    <div style="margin-top:10px;margin-bottom:8px;"><strong>バックアップ先</strong></div>
    <div>· WPXレンタルサーバー（sv6112.wpx.ne.jp）</div>
    <div>· 保存パス: /home/m96/backups/asobi/YYYY-MM-DD/</div>
    <div>· 保持期間: 直近7日分</div>
    <div style="margin-top:10px;margin-bottom:8px;"><strong>自動実行</strong></div>
    <div>· 毎日 午前5時（Conoha VPS cron）</div>
  </div>

  <?php if ($isRunning): ?>
  <script>setTimeout(() => location.reload(), 30000);</script>
  <?php endif; ?>

    </main>
  </div>
</body>
</html>
