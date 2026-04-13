<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
require_once '/opt/asobi/shared/assets/php/version.php';
asobiRequireAdmin();

$db = asobiUsersDb();

// ── 日別PV（直近30日）
$daily = $db->query("
  SELECT date(created_at) AS day, COUNT(*) AS cnt
  FROM access_logs
  WHERE created_at >= datetime('now','localtime','-29 days')
  GROUP BY day
  ORDER BY day
")->fetchAll(PDO::FETCH_ASSOC);

// ── ホスト別PV（直近30日）
$hostStats = $db->query("
  SELECT COALESCE(NULLIF(host,''),'(不明)') AS host, COUNT(*) AS cnt
  FROM access_logs
  WHERE created_at >= datetime('now','localtime','-29 days')
  GROUP BY host ORDER BY cnt DESC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// ── 人気ページ Top10（直近30日）
$topPages = $db->query("
  SELECT host, path, COUNT(*) AS cnt
  FROM access_logs
  WHERE created_at >= datetime('now','localtime','-29 days')
  GROUP BY host, path ORDER BY cnt DESC LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// ── 国別統計（直近30日）
$countries = $db->query("
  SELECT
    CASE WHEN country = 'JP' THEN '日本'
         WHEN country = 'OTHER' THEN '海外'
         ELSE '(不明)' END AS country_label,
    country,
    COUNT(*) AS cnt
  FROM access_logs
  WHERE created_at >= datetime('now','localtime','-29 days')
  GROUP BY country ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── ブラウザ統計（直近30日）
$browsers = $db->query("
  SELECT COALESCE(NULLIF(browser,''),'(不明)') AS browser, COUNT(*) AS cnt
  FROM access_logs
  WHERE created_at >= datetime('now','localtime','-29 days')
  GROUP BY browser ORDER BY cnt DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// ── デバイス統計（直近30日）
$devices = $db->query("
  SELECT COALESCE(NULLIF(device,''),'(不明)') AS device, COUNT(*) AS cnt
  FROM access_logs
  WHERE created_at >= datetime('now','localtime','-29 days')
  GROUP BY device ORDER BY cnt DESC LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// ── OS統計（直近30日）
$osList = $db->query("
  SELECT COALESCE(NULLIF(os,''),'(不明)') AS os, COUNT(*) AS cnt
  FROM access_logs
  WHERE created_at >= datetime('now','localtime','-29 days')
  GROUP BY os ORDER BY cnt DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// ── 日別UV（直近30日）
$dailyUv = $db->query("
  SELECT date(created_at) AS day, COUNT(DISTINCT ip) AS cnt
  FROM access_logs
  WHERE created_at >= datetime('now','localtime','-29 days')
  GROUP BY day ORDER BY day
")->fetchAll(PDO::FETCH_ASSOC);

// ── サマリ
$pvToday  = $db->query("SELECT COUNT(*) FROM access_logs WHERE date(created_at)=date('now','localtime')")->fetchColumn();
$uvToday  = $db->query("SELECT COUNT(DISTINCT ip) FROM access_logs WHERE date(created_at)=date('now','localtime')")->fetchColumn();
$pvWeek   = $db->query("SELECT COUNT(*) FROM access_logs WHERE created_at>=datetime('now','localtime','-7 days')")->fetchColumn();
$pvMonth  = $db->query("SELECT COUNT(*) FROM access_logs WHERE strftime('%Y-%m',created_at)=strftime('%Y-%m',datetime('now','localtime'))")->fetchColumn();
$pvTotal  = $db->query("SELECT COUNT(*) FROM access_logs")->fetchColumn();

// ── 直近30日の全日付を埋める
$dailyMap = [];
foreach ($daily as $r) $dailyMap[$r['day']] = (int)$r['cnt'];
$uvMap = [];
foreach ($dailyUv as $r) $uvMap[$r['day']] = (int)$r['cnt'];
$labels = $pvData = $uvData = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $labels[]  = date('m/d', strtotime($d));
    $pvData[]  = $dailyMap[$d] ?? 0;
    $uvData[]  = $uvMap[$d]    ?? 0;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>アクセス統計 - 管理画面</title>
  <link rel="stylesheet" href="/assets/css/common.css?v=<?= assetVer('/assets/css/common.css') ?>">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
  <style>
    body { background: #f0f2f5; color: #1d2d3a; font-family: sans-serif; margin: 0; padding: 0; display: flex; flex-direction: column; min-height: 100vh; }
    h1 { font-size: 1.3rem; margin-bottom: 6px; }
    .page-header { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 8px; }
    .raw-log-link { font-size: 0.8rem; color: #9ba8b5; text-decoration: none; }
    .raw-log-link:hover { color: #5567cc; text-decoration: underline; }

    /* サマリ */
    .summary-row { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 28px; }
    .sum-card { background: #fff; border: 1px solid #e0e4e8; border-radius: 10px; padding: 14px 20px; flex: 1 1 130px; min-width: 120px; box-shadow: 0 1px 4px rgba(0,0,0,.05); }
    .sum-label { font-size: 0.73rem; color: #9ba8b5; margin-bottom: 4px; }
    .sum-value { font-size: 1.6rem; font-weight: 700; color: #1d2d3a; }
    .sum-value.accent { color: #5567cc; }

    /* グラフカード */
    .card { background: #fff; border: 1px solid #e0e4e8; border-radius: 12px; padding: 20px 22px; margin-bottom: 22px; box-shadow: 0 1px 4px rgba(0,0,0,.05); }
    .card-title { font-size: 0.88rem; font-weight: 600; color: #637080; margin-bottom: 16px; border-bottom: 1px solid #f0f2f7; padding-bottom: 8px; }
    .chart-wrap { position: relative; height: 220px; }

    /* 2カラムグリッド */
    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
    @media (max-width: 768px) { .two-col { grid-template-columns: 1fr; } }

    /* テーブル */
    .stat-table { width: 100%; border-collapse: collapse; font-size: 0.83rem; }
    .stat-table th { background: #f5f7fa; padding: 7px 10px; text-align: left; color: #637080; font-size: 0.75rem; font-weight: 600; border-bottom: 1px solid #e8eaf0; }
    .stat-table td { padding: 7px 10px; border-bottom: 1px solid #f3f5f8; }
    .stat-table tr:last-child td { border-bottom: none; }
    .stat-table tr:hover td { background: #f9fafb; }
    .rank-num { color: #9ba8b5; font-size: 0.75rem; width: 24px; }
    .bar-bg { background: #eef1fb; border-radius: 4px; height: 6px; margin-top: 3px; }
    .bar-fill { background: #7b8ed4; border-radius: 4px; height: 6px; }
    .path-cell { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cnt-badge { font-weight: 600; color: #5567cc; }
  </style>
</head>
<body>
  <?php $adminActivePage = 'access-stats'; require __DIR__ . '/_sidebar.php'; ?>

      <div class="page-header">
        <h1>📊 アクセス統計</h1>
        <a href="/admin/access-logs.php" class="raw-log-link">📋 生ログ一覧 →</a>
      </div>

      <!-- サマリ -->
      <div class="summary-row">
        <div class="sum-card">
          <div class="sum-label">本日 PV</div>
          <div class="sum-value accent"><?= number_format($pvToday) ?></div>
        </div>
        <div class="sum-card">
          <div class="sum-label">本日 UV</div>
          <div class="sum-value"><?= number_format($uvToday) ?></div>
        </div>
        <div class="sum-card">
          <div class="sum-label">週間 PV</div>
          <div class="sum-value"><?= number_format($pvWeek) ?></div>
        </div>
        <div class="sum-card">
          <div class="sum-label">月間 PV</div>
          <div class="sum-value"><?= number_format($pvMonth) ?></div>
        </div>
        <div class="sum-card">
          <div class="sum-label">累計 PV</div>
          <div class="sum-value"><?= number_format($pvTotal) ?></div>
        </div>
      </div>

      <!-- 日別PV/UVグラフ -->
      <div class="card">
        <div class="card-title">日別 PV / UV（直近30日）</div>
        <div class="chart-wrap">
          <canvas id="dailyChart"></canvas>
        </div>
      </div>

      <div class="two-col">
        <!-- ホスト別 -->
        <div class="card">
          <div class="card-title">ホスト別 PV（直近30日）</div>
          <div class="chart-wrap" style="height:180px">
            <canvas id="hostChart"></canvas>
          </div>
        </div>
        <!-- デバイス別 -->
        <div class="card">
          <div class="card-title">デバイス別（直近30日）</div>
          <div class="chart-wrap" style="height:180px">
            <canvas id="deviceChart"></canvas>
          </div>
        </div>
      </div>

      <!-- 国別統計 -->
      <div class="card">
        <div class="card-title">国別アクセス（直近30日）</div>
        <table class="stat-table">
          <thead><tr><th>国</th><th style="text-align:right;">PV</th><th style="text-align:right;">割合</th></tr></thead>
          <tbody>
            <?php
            $totalCountry = array_sum(array_column($countries, 'cnt'));
            foreach ($countries as $c):
              $pct = $totalCountry > 0 ? round($c['cnt'] / $totalCountry * 100, 1) : 0;
              $color = $c['country'] === 'JP' ? '#4caf50' : ($c['country'] === 'OTHER' ? '#ff9800' : '#9e9e9e');
            ?>
            <tr>
              <td><span style="display:inline-block;width:10px;height:10px;background:<?= $color ?>;border-radius:50%;margin-right:6px;"></span><?= htmlspecialchars($c['country_label']) ?></td>
              <td style="text-align:right;"><?= number_format($c['cnt']) ?></td>
              <td style="text-align:right;"><?= $pct ?>%</td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="two-col">
        <!-- 人気ページ -->
        <div class="card">
          <div class="card-title">人気ページ Top15（直近30日）</div>
          <table class="stat-table">
            <thead><tr><th>#</th><th>ページ</th><th>PV</th></tr></thead>
            <tbody>
              <?php
              $maxPage = max(1, (int)($topPages[0]['cnt'] ?? 1));
              foreach ($topPages as $i => $r):
                $pct = round($r['cnt'] / $maxPage * 100);
              ?>
              <tr>
                <td class="rank-num"><?= $i+1 ?></td>
                <td>
                  <div class="path-cell" title="<?= htmlspecialchars($r['host'].$r['path']) ?>">
                    <?= htmlspecialchars($r['host'].$r['path']) ?>
                  </div>
                  <div class="bar-bg"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
                </td>
                <td class="cnt-badge"><?= number_format($r['cnt']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <!-- ブラウザ・OS -->
        <div>
          <div class="card" style="margin-bottom:18px">
            <div class="card-title">ブラウザ別（直近30日）</div>
            <table class="stat-table">
              <thead><tr><th>ブラウザ</th><th>PV</th></tr></thead>
              <tbody>
                <?php
                $maxBr = max(1, (int)($browsers[0]['cnt'] ?? 1));
                foreach ($browsers as $r):
                  $pct = round($r['cnt'] / $maxBr * 100);
                ?>
                <tr>
                  <td>
                    <?= htmlspecialchars($r['browser']) ?>
                    <div class="bar-bg"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
                  </td>
                  <td class="cnt-badge"><?= number_format($r['cnt']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="card">
            <div class="card-title">OS別（直近30日）</div>
            <table class="stat-table">
              <thead><tr><th>OS</th><th>PV</th></tr></thead>
              <tbody>
                <?php
                $maxOs = max(1, (int)($osList[0]['cnt'] ?? 1));
                foreach ($osList as $r):
                  $pct = round($r['cnt'] / $maxOs * 100);
                ?>
                <tr>
                  <td>
                    <?= htmlspecialchars($r['os']) ?>
                    <div class="bar-bg"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
                  </td>
                  <td class="cnt-badge"><?= number_format($r['cnt']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </main>
  </div>

  <script src="/assets/js/common.js?v=<?= assetVer('/assets/js/common.js') ?>"></script>
  <script>
  // ── 日別PV/UVグラフ
  new Chart(document.getElementById('dailyChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>,
      datasets: [
        {
          label: 'PV',
          data: <?= json_encode($pvData) ?>,
          backgroundColor: 'rgba(101,120,210,0.7)',
          borderRadius: 3,
          order: 2
        },
        {
          label: 'UV',
          data: <?= json_encode($uvData) ?>,
          type: 'line',
          borderColor: '#f093fb',
          backgroundColor: 'rgba(240,147,251,0.15)',
          borderWidth: 2,
          pointRadius: 2,
          tension: 0.3,
          fill: false,
          order: 1
        }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { labels: { font: { size: 11 } } } },
      scales: {
        x: { ticks: { font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 15 }, grid: { display: false } },
        y: { ticks: { font: { size: 10 } }, beginAtZero: true, grid: { color: '#f0f2f7' } }
      }
    }
  });

  // ── ホスト別ドーナツ
  const hostLabels = <?= json_encode(array_column($hostStats, 'host'), JSON_UNESCAPED_UNICODE) ?>;
  const hostData   = <?= json_encode(array_column($hostStats, 'cnt')) ?>;
  new Chart(document.getElementById('hostChart'), {
    type: 'doughnut',
    data: {
      labels: hostLabels,
      datasets: [{ data: hostData, backgroundColor: ['#6578d2','#f093fb','#6edd8a','#f7d94e','#f56565','#4aabd9','#e5a24a','#9b8ed4','#79c7b4','#d46e6e'] }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'right', labels: { font: { size: 10 }, padding: 8, boxWidth: 12 } } }
    }
  });

  // ── デバイス別ドーナツ
  const devLabels = <?= json_encode(array_column($devices, 'device'), JSON_UNESCAPED_UNICODE) ?>;
  const devData   = <?= json_encode(array_column($devices, 'cnt')) ?>;
  new Chart(document.getElementById('deviceChart'), {
    type: 'doughnut',
    data: {
      labels: devLabels,
      datasets: [{ data: devData, backgroundColor: ['#6578d2','#f093fb','#6edd8a','#f7d94e','#f56565','#4aabd9'] }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'right', labels: { font: { size: 10 }, padding: 8, boxWidth: 12 } } }
    }
  });
  </script>
</body>
</html>
