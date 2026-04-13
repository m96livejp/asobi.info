<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
require_once '/opt/asobi/shared/assets/php/version.php';
asobiRequireAdmin();
session_write_close();

$commonDb = new PDO('sqlite:/opt/asobi/data/users.sqlite');
$commonDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ─── テーブル作成 & ログファイルインポート ───
$commonDb->exec("CREATE TABLE IF NOT EXISTS api_usage_logs (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    ts           TEXT NOT NULL,
    type         TEXT NOT NULL DEFAULT '',
    site         TEXT NOT NULL DEFAULT '',
    endpoint     TEXT NOT NULL DEFAULT '',
    user_id      INTEGER,
    username     TEXT NOT NULL DEFAULT '',
    char_name    TEXT NOT NULL DEFAULT '',
    provider     TEXT NOT NULL DEFAULT '',
    model        TEXT NOT NULL DEFAULT '',
    input_chars  INTEGER NOT NULL DEFAULT 0,
    output_chars INTEGER NOT NULL DEFAULT 0,
    ip           TEXT NOT NULL DEFAULT '',
    user_agent   TEXT NOT NULL DEFAULT '',
    cost         INTEGER NOT NULL DEFAULT 0,
    currency     TEXT NOT NULL DEFAULT 'points'
)");
foreach (['type TEXT NOT NULL DEFAULT \'\'', 'site TEXT NOT NULL DEFAULT \'\'', 'endpoint TEXT NOT NULL DEFAULT \'\''] as $_col) {
    try { $commonDb->exec("ALTER TABLE api_usage_logs ADD COLUMN $_col"); } catch (Exception $e) {}
}

$_logFile = '/opt/asobi/aic/data/api_usage.log';
$_tmpFile = $_logFile . '.import_lock';
if (file_exists($_logFile) && !file_exists($_tmpFile) && filesize($_logFile) > 0) {
    if (@rename($_logFile, $_tmpFile)) {
        $stmt = $commonDb->prepare(
            "INSERT INTO api_usage_logs(ts,type,site,endpoint,user_id,username,char_name,provider,model,input_chars,output_chars,ip,user_agent,cost,currency)
             VALUES(:ts,:type,:site,:ep,:uid,:uname,:char,:prov,:model,:inc,:outc,:ip,:ua,:cost,:cur)"
        );
        $commonDb->beginTransaction();
        foreach (file($_tmpFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $d = @json_decode($line, true);
            if (!$d) continue;
            $stmt->execute([
                ':ts'    => $d['ts']           ?? date('Y-m-d H:i:s'),
                ':type'  => $d['type']         ?? '',
                ':site'  => $d['site']         ?? '',
                ':ep'    => $d['endpoint']     ?? '',
                ':uid'   => $d['user_id']      ?? null,
                ':uname' => $d['username']     ?? '',
                ':char'  => $d['char_name']    ?? '',
                ':prov'  => $d['provider']     ?? '',
                ':model' => $d['model']        ?? '',
                ':inc'   => (int)($d['input_chars']  ?? 0),
                ':outc'  => (int)($d['output_chars'] ?? 0),
                ':ip'    => $d['ip']           ?? '',
                ':ua'    => $d['user_agent']   ?? '',
                ':cost'  => (int)($d['cost']   ?? 0),
                ':cur'   => $d['currency']     ?? 'points',
            ]);
        }
        $commonDb->commit();
        @unlink($_tmpFile);
    }
}

// ─── 集計 ───
$usageTotal  = (int)$commonDb->query("SELECT COUNT(*) FROM api_usage_logs WHERE ts >= datetime('now','localtime','-30 days')")->fetchColumn();
$usageToday  = (int)$commonDb->query("SELECT COUNT(*) FROM api_usage_logs WHERE date(ts)=date('now','localtime')")->fetchColumn();
$usageByUser = $commonDb->query("SELECT COALESCE(NULLIF(username,''),'(不明)') AS username, COUNT(*) AS cnt FROM api_usage_logs WHERE ts >= datetime('now','localtime','-30 days') GROUP BY username ORDER BY cnt DESC LIMIT 10")->fetchAll();
$usageByModelChat = $commonDb->query("SELECT COALESCE(NULLIF(model,''),'(不明)') AS model, provider, COUNT(*) AS cnt FROM api_usage_logs WHERE ts >= datetime('now','localtime','-30 days') AND (type IN ('','chat','review') OR type IS NULL) GROUP BY model,provider ORDER BY cnt DESC LIMIT 10")->fetchAll();
$usageByModelImage = $commonDb->query("SELECT COALESCE(NULLIF(model,''),'(不明)') AS model, provider, COUNT(*) AS cnt FROM api_usage_logs WHERE ts >= datetime('now','localtime','-30 days') AND type='image' GROUP BY model,provider ORDER BY cnt DESC LIMIT 10")->fetchAll();
$usageByDay  = $commonDb->query("SELECT date(ts) AS day, COUNT(*) AS cnt FROM api_usage_logs WHERE ts >= datetime('now','localtime','-29 days') GROUP BY day ORDER BY day")->fetchAll();
$usageByDayMap = []; foreach($usageByDay as $r) $usageByDayMap[$r['day']] = (int)$r['cnt'];
$usageDayLabels = $usageDayData = [];
for($i=29;$i>=0;$i--){$d=date('Y-m-d',strtotime("-{$i} days"));$usageDayLabels[]=date('m/d',strtotime($d));$usageDayData[]=$usageByDayMap[$d]??0;}

$_apiTypes = ['chat', 'image', 'tts', 'translate', 'review'];
$_typeMap = array_fill_keys($_apiTypes, 0);
$_typeRows = $commonDb->query("SELECT COALESCE(NULLIF(type,''),'chat') AS api_type, COUNT(*) AS cnt FROM api_usage_logs WHERE ts >= datetime('now','localtime','-30 days') GROUP BY api_type")->fetchAll();
foreach ($_typeRows as $r) { $_typeMap[$r['api_type']] = (int)$r['cnt']; }
if (isset($_typeMap['error'])) { $_typeMap['chat'] += $_typeMap['error']; unset($_typeMap['error']); }
$usageByType = $_typeMap;

$_typeTodayMap = array_fill_keys($_apiTypes, 0);
$_typeTodayRows = $commonDb->query("SELECT COALESCE(NULLIF(type,''),'chat') AS api_type, COUNT(*) AS cnt FROM api_usage_logs WHERE date(ts)=date('now','localtime') GROUP BY api_type")->fetchAll();
foreach ($_typeTodayRows as $r) { $_typeTodayMap[$r['api_type']] = (int)$r['cnt']; }
if (isset($_typeTodayMap['error'])) { $_typeTodayMap['chat'] += $_typeTodayMap['error']; unset($_typeTodayMap['error']); }
$usageTodayByType = $_typeTodayMap;

$_typeDayRows = $commonDb->query("SELECT date(ts) AS day, COALESCE(NULLIF(type,''),'chat') AS api_type, COUNT(*) AS cnt FROM api_usage_logs WHERE ts >= datetime('now','localtime','-29 days') GROUP BY day, api_type ORDER BY day")->fetchAll();
$_typeDayMap = [];
foreach ($_typeDayRows as $r) {
    $t = ($r['api_type'] === 'error') ? 'chat' : $r['api_type'];
    $_typeDayMap[$r['day']][$t] = (int)$r['cnt'] + ($_typeDayMap[$r['day']][$t] ?? 0);
}
$usageDayChat = $usageDayImage = $usageDayTts = $usageDayTranslate = $usageDayReview = [];
for($i=29;$i>=0;$i--){
    $d=date('Y-m-d',strtotime("-{$i} days"));
    $usageDayChat[]=$_typeDayMap[$d]['chat']??0;
    $usageDayImage[]=$_typeDayMap[$d]['image']??0;
    $usageDayTts[]=$_typeDayMap[$d]['tts']??0;
    $usageDayTranslate[]=$_typeDayMap[$d]['translate']??0;
    $usageDayReview[]=$_typeDayMap[$d]['review']??0;
}

$usageBySite = $commonDb->query("SELECT COALESCE(NULLIF(site,''),'(不明)') AS site, COUNT(*) AS cnt FROM api_usage_logs WHERE ts >= datetime('now','localtime','-30 days') GROUP BY site ORDER BY cnt DESC")->fetchAll();
$usageByEndpoint = $commonDb->query("SELECT COALESCE(NULLIF(site,''),'?') AS site, COALESCE(NULLIF(endpoint,''),'?') AS endpoint, COALESCE(NULLIF(type,''),'chat') AS api_type, COUNT(*) AS cnt FROM api_usage_logs WHERE ts >= datetime('now','localtime','-30 days') GROUP BY site, endpoint, api_type ORDER BY cnt DESC LIMIT 20")->fetchAll();

$typeLabels = ['chat' => '💬 チャット', 'image' => '🎨 画像生成', 'tts' => '🔊 音声合成', 'translate' => '🌐 翻訳', 'review' => '🔍 審査'];
$typeColors = ['chat' => '#5567cc', 'image' => '#e67e22', 'tts' => '#27ae60', 'translate' => '#8e44ad', 'review' => '#e74c3c'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>API利用状況 - 管理画面</title>
  <link rel="stylesheet" href="/assets/css/common.css?v=<?= assetVer('/assets/css/common.css') ?>">
  <style>*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; color: #1d2d3a; min-height: 100vh; display: flex; flex-direction: column; }</style>
</head>
<body>
  <?php $adminActivePage = 'api-usage'; require __DIR__ . '/_sidebar.php'; ?>

  <div class="page-title">API利用状況</div>

  <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
    <div style="background:#fff;border:1px solid #e0e4e8;border-radius:10px;padding:14px 22px;flex:1 1 120px;min-width:110px;">
      <div style="font-size:0.72rem;color:#9ba8b5;margin-bottom:4px;">本日 合計</div>
      <div style="font-size:1.5rem;font-weight:700;color:#5567cc;"><?= number_format($usageToday) ?></div>
    </div>
    <div style="background:#fff;border:1px solid #e0e4e8;border-radius:10px;padding:14px 22px;flex:1 1 120px;min-width:110px;">
      <div style="font-size:0.72rem;color:#9ba8b5;margin-bottom:4px;">直近30日 合計</div>
      <div style="font-size:1.5rem;font-weight:700;color:#1d2d3a;"><?= number_format($usageTotal) ?></div>
    </div>
  </div>

  <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px;">
    <?php foreach ($typeLabels as $tKey => $tLabel): ?>
    <div style="background:#fff;border:1px solid #e0e4e8;border-radius:10px;padding:12px 18px;flex:1 1 100px;min-width:100px;">
      <div style="font-size:0.72rem;color:#9ba8b5;margin-bottom:2px;"><?= $tLabel ?></div>
      <div style="display:flex;align-items:baseline;gap:8px;">
        <span style="font-size:1.2rem;font-weight:700;color:<?= $typeColors[$tKey] ?>;"><?= number_format($usageTodayByType[$tKey] ?? 0) ?></span>
        <span style="font-size:0.72rem;color:#9ba8b5;">/ <?= number_format($usageByType[$tKey] ?? 0) ?></span>
      </div>
      <div style="font-size:0.65rem;color:#bbb;">本日 / 30日</div>
    </div>
    <?php endforeach; ?>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
    <div style="grid-column:1/-1;background:#fff;border:1px solid #e0e4e8;border-radius:10px;padding:16px 18px;">
      <div style="font-size:0.85rem;font-weight:600;color:#637080;margin-bottom:12px;padding-bottom:6px;border-bottom:1px solid #f0f2f7;">日別API利用回数（直近30日）</div>
      <div style="position:relative;height:160px;"><canvas id="usageDayChart"></canvas></div>
    </div>
    <!-- サイト別 -->
    <div style="background:#fff;border:1px solid #e0e4e8;border-radius:10px;padding:16px 18px;">
      <div style="font-size:0.85rem;font-weight:600;color:#637080;margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid #f0f2f7;">サイト別（直近30日）</div>
      <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
        <thead><tr><th style="text-align:left;padding:4px 6px;color:#637080;font-size:0.74rem;">サイト</th><th style="text-align:right;padding:4px 6px;color:#637080;font-size:0.74rem;">回数</th></tr></thead>
        <tbody>
          <?php $maxS = max(1,(int)(($usageBySite[0]['cnt']??1)));
          foreach($usageBySite as $r): $pct=round($r['cnt']/$maxS*100); ?>
          <tr>
            <td style="padding:4px 6px;"><?= htmlspecialchars($r['site']) ?><div style="background:#eef1fb;border-radius:3px;height:4px;margin-top:2px;"><div style="background:#e17055;border-radius:3px;height:4px;width:<?=$pct?>%"></div></div></td>
            <td style="text-align:right;padding:4px 6px;font-weight:600;color:#5567cc;"><?= number_format($r['cnt']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($usageBySite)): ?><tr><td colspan="2" style="padding:8px 6px;color:#9ba8b5;text-align:center;">データなし</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <!-- ユーザー別 -->
    <div style="background:#fff;border:1px solid #e0e4e8;border-radius:10px;padding:16px 18px;">
      <div style="font-size:0.85rem;font-weight:600;color:#637080;margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid #f0f2f7;">ユーザー別（直近30日）</div>
      <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
        <thead><tr><th style="text-align:left;padding:4px 6px;color:#637080;font-size:0.74rem;">ユーザー</th><th style="text-align:right;padding:4px 6px;color:#637080;font-size:0.74rem;">回数</th></tr></thead>
        <tbody>
          <?php $maxU = max(1,(int)(($usageByUser[0]['cnt']??1)));
          foreach($usageByUser as $r): $pct=round($r['cnt']/$maxU*100); ?>
          <tr>
            <td style="padding:4px 6px;"><?= htmlspecialchars($r['username']) ?><div style="background:#eef1fb;border-radius:3px;height:4px;margin-top:2px;"><div style="background:#7b8ed4;border-radius:3px;height:4px;width:<?=$pct?>%"></div></div></td>
            <td style="text-align:right;padding:4px 6px;font-weight:600;color:#5567cc;"><?= number_format($r['cnt']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($usageByUser)): ?><tr><td colspan="2" style="padding:8px 6px;color:#9ba8b5;text-align:center;">データなし</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <!-- チャットモデル別 -->
    <div style="background:#fff;border:1px solid #e0e4e8;border-radius:10px;padding:16px 18px;">
      <div style="font-size:0.85rem;font-weight:600;color:#637080;margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid #f0f2f7;">💬 チャットモデル別（直近30日）</div>
      <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
        <thead><tr><th style="text-align:left;padding:4px 6px;color:#637080;font-size:0.74rem;">モデル</th><th style="text-align:right;padding:4px 6px;color:#637080;font-size:0.74rem;">回数</th></tr></thead>
        <tbody>
          <?php $maxMC = max(1,(int)(($usageByModelChat[0]['cnt']??1)));
          foreach($usageByModelChat as $r): $pct=round($r['cnt']/$maxMC*100); ?>
          <tr>
            <td style="padding:4px 6px;"><?= htmlspecialchars($r['model'] ?: $r['provider'] ?: '(不明)') ?><div style="background:#eef1fb;border-radius:3px;height:4px;margin-top:2px;"><div style="background:#7b8ed4;border-radius:3px;height:4px;width:<?=$pct?>%"></div></div></td>
            <td style="text-align:right;padding:4px 6px;font-weight:600;color:#5567cc;"><?= number_format($r['cnt']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($usageByModelChat)): ?><tr><td colspan="2" style="padding:8px 6px;color:#9ba8b5;text-align:center;">データなし</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <!-- 画像生成モデル別 -->
    <div style="background:#fff;border:1px solid #e0e4e8;border-radius:10px;padding:16px 18px;">
      <div style="font-size:0.85rem;font-weight:600;color:#637080;margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid #f0f2f7;">🎨 画像生成モデル別（直近30日）</div>
      <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
        <thead><tr><th style="text-align:left;padding:4px 6px;color:#637080;font-size:0.74rem;">モデル</th><th style="text-align:right;padding:4px 6px;color:#637080;font-size:0.74rem;">回数</th></tr></thead>
        <tbody>
          <?php $maxMI = max(1,(int)(($usageByModelImage[0]['cnt']??1)));
          foreach($usageByModelImage as $r): $pct=round($r['cnt']/$maxMI*100); ?>
          <tr>
            <td style="padding:4px 6px;"><?= htmlspecialchars($r['model'] ?: $r['provider'] ?: '(不明)') ?><div style="background:#eef1fb;border-radius:3px;height:4px;margin-top:2px;"><div style="background:#e8a87c;border-radius:3px;height:4px;width:<?=$pct?>%"></div></div></td>
            <td style="text-align:right;padding:4px 6px;font-weight:600;color:#e67e22;"><?= number_format($r['cnt']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($usageByModelImage)): ?><tr><td colspan="2" style="padding:8px 6px;color:#9ba8b5;text-align:center;">データなし</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <!-- エンドポイント別 -->
    <div style="grid-column:1/-1;background:#fff;border:1px solid #e0e4e8;border-radius:10px;padding:16px 18px;">
      <div style="font-size:0.85rem;font-weight:600;color:#637080;margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid #f0f2f7;">エンドポイント別（直近30日）</div>
      <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
        <thead><tr>
          <th style="text-align:left;padding:4px 6px;color:#637080;font-size:0.74rem;">サイト</th>
          <th style="text-align:left;padding:4px 6px;color:#637080;font-size:0.74rem;">エンドポイント</th>
          <th style="text-align:left;padding:4px 6px;color:#637080;font-size:0.74rem;">種別</th>
          <th style="text-align:right;padding:4px 6px;color:#637080;font-size:0.74rem;">回数</th>
        </tr></thead>
        <tbody>
          <?php foreach($usageByEndpoint as $r): ?>
          <tr>
            <td style="padding:4px 6px;font-size:0.78rem;"><?= htmlspecialchars($r['site']) ?></td>
            <td style="padding:4px 6px;font-size:0.78rem;font-family:monospace;"><?= htmlspecialchars($r['endpoint']) ?></td>
            <td style="padding:4px 6px;font-size:0.78rem;"><?= htmlspecialchars($r['api_type']) ?></td>
            <td style="text-align:right;padding:4px 6px;font-weight:600;color:#5567cc;"><?= number_format($r['cnt']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($usageByEndpoint)): ?><tr><td colspan="4" style="padding:8px 6px;color:#9ba8b5;text-align:center;">データなし</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
  <script>
  new Chart(document.getElementById('usageDayChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($usageDayLabels, JSON_UNESCAPED_UNICODE) ?>,
      datasets: [
        { label: 'チャット', data: <?= json_encode($usageDayChat) ?>, backgroundColor: 'rgba(85,103,204,0.75)', borderRadius: 2 },
        { label: '画像生成', data: <?= json_encode($usageDayImage) ?>, backgroundColor: 'rgba(230,126,34,0.75)', borderRadius: 2 },
        { label: '音声合成', data: <?= json_encode($usageDayTts) ?>, backgroundColor: 'rgba(39,174,96,0.75)', borderRadius: 2 },
        { label: '翻訳',     data: <?= json_encode($usageDayTranslate) ?>, backgroundColor: 'rgba(142,68,173,0.75)', borderRadius: 2 },
        { label: '審査',     data: <?= json_encode($usageDayReview) ?>, backgroundColor: 'rgba(231,76,60,0.75)', borderRadius: 2 },
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: true, position: 'top', labels: { boxWidth: 12, font: { size: 10 } } } },
      scales: {
        x: { stacked: true, ticks: { font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 15 }, grid: { display: false } },
        y: { stacked: true, ticks: { font: { size: 10 } }, beginAtZero: true, grid: { color: '#f0f2f7' } }
      }
    }
  });
  </script>

  <script src="/assets/js/common.js?v=<?= assetVer('/assets/js/common.js') ?>"></script>
</body>
</html>
