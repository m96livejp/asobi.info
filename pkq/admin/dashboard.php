<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$db = getDb();
$cnt_pokemon     = $db->query('SELECT COUNT(*) FROM pokemon')->fetchColumn();
$cnt_recipes     = $db->query('SELECT COUNT(DISTINCT recipe_no) FROM recipes')->fetchColumn();
$cnt_ingredients = $db->query('SELECT COUNT(*) FROM ingredients')->fetchColumn();
$recent_recipes  = $db->query('SELECT recipe_no, name, ingredient_hint, pokemon_hint FROM recipes GROUP BY recipe_no ORDER BY recipe_no LIMIT 5')->fetchAll();

// 個体値例外件数
$iv_exception_count = 0;
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS iv_observations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pokemon_id INTEGER NOT NULL, pot_type TEXT NOT NULL,
            hp INTEGER NOT NULL, atk INTEGER NOT NULL,
            memo TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (pokemon_id) REFERENCES pokemon(pokedex_no)
        )
    ");
    $iv_rows = $db->query("
        SELECT o.hp, o.atk, o.pot_type, p.base_hp, p.base_atk
        FROM iv_observations o JOIN pokemon p ON o.pokemon_id = p.pokedex_no
    ")->fetchAll();
    $pot_mid_map = ['鉄'=>5,'銅'=>75,'銀'=>200,'金'=>350];
    foreach ($iv_rows as $row) {
        $mid = $pot_mid_map[$row['pot_type']] ?? 0;
        $r_hp  = $row['base_hp']  > 0 ? ((int)$row['hp']  - $mid) / $row['base_hp']  : -1;
        $r_atk = $row['base_atk'] > 0 ? ((int)$row['atk'] - $mid) / $row['base_atk'] : -1;
        if ($r_hp < 0 || $r_hp > 8 || $r_atk < 0 || $r_atk > 8) $iv_exception_count++;
    }
    $iv_total = count($iv_rows);
} catch (Exception $e) {
    $iv_total = 0;
}

layout_head('ダッシュボード', 'dashboard');
?>

<?php if ($iv_exception_count > 0): ?>
<div class="alert" style="background:#fff3cd;color:#856404;border:1px solid #ffc107;display:flex;align-items:center;gap:12px;margin-bottom:24px;">
  <span style="font-size:1.5rem;">⚠️</span>
  <div>
    <strong>個体値データに例外が <?= $iv_exception_count ?> 件あります。</strong>
    <a href="/admin/iv.php" style="margin-left:12px;color:#856404;font-size:0.85rem;text-decoration:underline;">確認する →</a>
  </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px;">
  <div class="card" style="padding:20px;text-align:center;">
    <div style="font-size:2rem;margin-bottom:6px;">⚡</div>
    <div style="font-size:2.2rem;font-weight:700;color:#e17055;"><?= $cnt_pokemon ?></div>
    <div style="color:#636e72;font-size:0.85rem;margin-top:4px;">ポケモン</div>
  </div>
  <div class="card" style="padding:20px;text-align:center;">
    <div style="font-size:2rem;margin-bottom:6px;">🍲</div>
    <div style="font-size:2.2rem;font-weight:700;color:#e17055;"><?= $cnt_recipes ?></div>
    <div style="color:#636e72;font-size:0.85rem;margin-top:4px;">料理</div>
  </div>
  <div class="card" style="padding:20px;text-align:center;">
    <div style="font-size:2rem;margin-bottom:6px;">🥕</div>
    <div style="font-size:2.2rem;font-weight:700;color:#e17055;"><?= $cnt_ingredients ?></div>
    <div style="color:#636e72;font-size:0.85rem;margin-top:4px;">素材</div>
  </div>
  <div class="card" style="padding:20px;text-align:center;">
    <div style="font-size:2rem;margin-bottom:6px;">📊</div>
    <div style="font-size:2.2rem;font-weight:700;color:<?= $iv_exception_count > 0 ? '#e17055' : '#00b894' ?>;"><?= $iv_total ?></div>
    <div style="color:#636e72;font-size:0.85rem;margin-top:4px;">個体値データ</div>
    <?php if ($iv_exception_count > 0): ?>
    <div style="font-size:0.75rem;color:#e17055;margin-top:4px;">⚠️例外 <?= $iv_exception_count ?>件</div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header">
    最近の料理（No.1〜5）
    <a href="/admin/recipes.php" class="btn btn-sm btn-primary">すべて見る</a>
  </div>
  <div class="card-body" style="padding:0;">
    <table class="tbl">
      <thead>
        <tr>
          <th>No.</th>
          <th>料理名</th>
          <th>素材ヒント</th>
          <th>ポケモンヒント</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recent_recipes as $r): ?>
        <tr>
          <td style="color:#636e72;"><?= $r['recipe_no'] ?></td>
          <td style="font-weight:600;"><?= htmlspecialchars($r['name']) ?></td>
          <td style="color:#636e72;font-size:0.82rem;"><?= htmlspecialchars($r['ingredient_hint'] ?? '—') ?></td>
          <td style="color:#636e72;font-size:0.82rem;"><?= htmlspecialchars($r['pokemon_hint'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px;">
  <div class="card" style="padding:20px;">
    <div style="font-weight:600;margin-bottom:12px;">クイックリンク</div>
    <div style="display:flex;flex-direction:column;gap:8px;">
      <a href="/admin/ingredients.php" class="btn btn-secondary">🥕 素材を編集する</a>
      <a href="/admin/recipes.php" class="btn btn-secondary">🍲 料理を編集する</a>
      <a href="/admin/pokemon.php" class="btn btn-secondary">⚡ ポケモンを編集する</a>
      <a href="/admin/iv.php" class="btn btn-secondary">📊 個体値データ管理</a>
      <a href="/admin/settings.php" class="btn btn-secondary">⚙️ 設定</a>
    </div>
  </div>
  <div class="card" style="padding:20px;">
    <div style="font-weight:600;margin-bottom:12px;">サイトリンク</div>
    <div style="display:flex;flex-direction:column;gap:8px;">
      <a href="https://pkq.asobi.info/" target="_blank" class="btn btn-primary">🏠 トップページ</a>
      <a href="https://pkq.asobi.info/recipes.html" target="_blank" class="btn btn-primary">🍲 料理一覧</a>
      <a href="https://pkq.asobi.info/simulator.html" target="_blank" class="btn btn-primary">🧪 シミュレータ</a>
      <a href="https://pkq.asobi.info/pokemon-list.html" target="_blank" class="btn btn-primary">⚡ ポケモン一覧</a>
    </div>
  </div>
</div>

<?php layout_foot(); ?>
