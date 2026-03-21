<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$db = getDb();
$ingredients = $db->query('SELECT * FROM ingredients ORDER BY sort_order, id')->fetchAll();

layout_head('素材管理', 'ingredients');
?>

<div class="card">
  <div class="card-header">
    素材一覧
    <span style="color:#636e72;font-size:0.85rem;font-weight:400;">行をクリックして編集</span>
  </div>
  <div class="card-body" style="padding:0;overflow-x:auto;">
    <table class="tbl">
      <thead>
        <tr>
          <th>ID</th>
          <th>名前</th>
          <th>色</th>
          <th>カテゴリ</th>
          <th>やわらかさ</th>
          <th>サイズ</th>
          <th>レアリティ</th>
          <th>品質度</th>
          <th>並び順</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ingredients as $ing): ?>
        <tr>
          <td style="color:#636e72;"><?= $ing['id'] ?></td>
          <td style="font-weight:600;"><?= htmlspecialchars($ing['name']) ?></td>
          <td>
            <span class="badge badge-<?= $ing['color'] ?? 'gray' ?>">
              <?= htmlspecialchars($ing['color'] ?? '—') ?>
            </span>
          </td>
          <td><?= htmlspecialchars($ing['category'] ?? '—') ?></td>
          <td><?= htmlspecialchars($ing['softness'] ?? '—') ?></td>
          <td><?= htmlspecialchars($ing['size'] ?? '—') ?></td>
          <td>
            <span class="badge badge-<?= $ing['rarity'] === 'rare' ? 'rare' : 'common' ?>">
              <?= htmlspecialchars($ing['rarity'] ?? '—') ?>
            </span>
          </td>
          <td style="text-align:center;"><?= $ing['quality_point'] ?></td>
          <td style="text-align:center;"><?= $ing['sort_order'] ?></td>
          <td>
            <button class="btn btn-sm btn-primary" onclick='openIngEdit(<?= htmlspecialchars(json_encode($ing), ENT_QUOTES) ?>)'>
              編集
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- 編集モーダル -->
<div class="modal-overlay" id="ing-modal">
  <div class="modal">
    <div class="modal-title">素材を編集</div>
    <form id="ing-form">
      <input type="hidden" id="ing-id">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group" style="grid-column:1/-1;">
          <label class="form-label">名前</label>
          <input type="text" class="form-control" id="ing-name">
        </div>
        <div class="form-group">
          <label class="form-label">色</label>
          <select class="form-control" id="ing-color">
            <option value="">—</option>
            <option value="red">red（赤）</option>
            <option value="blue">blue（青）</option>
            <option value="yellow">yellow（黄）</option>
            <option value="gray">gray（灰）</option>
            <option value="rainbow">rainbow（虹）</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">カテゴリ</label>
          <select class="form-control" id="ing-category">
            <option value="">—</option>
            <option value="mushroom">mushroom（きのこ）</option>
            <option value="plant">plant（植物）</option>
            <option value="sweet">sweet（あまい）</option>
            <option value="mineral">mineral（鉱物）</option>
            <option value="special">special（特殊）</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">やわらかさ</label>
          <select class="form-control" id="ing-softness">
            <option value="">—</option>
            <option value="soft">soft（やわらかい）</option>
            <option value="hard">hard（かたい）</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">サイズ</label>
          <select class="form-control" id="ing-size">
            <option value="">—</option>
            <option value="small">small（小）</option>
            <option value="big">big（大）</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">レアリティ</label>
          <select class="form-control" id="ing-rarity">
            <option value="common">common（ふつう）</option>
            <option value="rare">rare（レア）</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">品質度 (quality_point)</label>
          <select class="form-control" id="ing-quality-point">
            <option value="1">1（ふつう）</option>
            <option value="2">2（おおきい）</option>
            <option value="3">3（レア）</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">並び順 (sort_order)</label>
          <input type="number" class="form-control" id="ing-sort-order" min="0">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('ing-modal')">キャンセル</button>
        <button type="submit" class="btn btn-success">保存</button>
      </div>
    </form>
  </div>
</div>

<script>
function openIngEdit(ing) {
  document.getElementById('ing-id').value            = ing.id;
  document.getElementById('ing-name').value          = ing.name;
  document.getElementById('ing-color').value         = ing.color || '';
  document.getElementById('ing-category').value      = ing.category || '';
  document.getElementById('ing-softness').value      = ing.softness || '';
  document.getElementById('ing-size').value          = ing.size || '';
  document.getElementById('ing-rarity').value        = ing.rarity || 'common';
  document.getElementById('ing-quality-point').value = ing.quality_point ?? 1;
  document.getElementById('ing-sort-order').value    = ing.sort_order ?? 99;
  document.getElementById('ing-modal').classList.add('open');
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}
document.getElementById('ing-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const result = await adminApi('update_ingredient', {
    id:            document.getElementById('ing-id').value,
    name:          document.getElementById('ing-name').value,
    color:         document.getElementById('ing-color').value,
    category:      document.getElementById('ing-category').value,
    softness:      document.getElementById('ing-softness').value,
    size:          document.getElementById('ing-size').value,
    rarity:        document.getElementById('ing-rarity').value,
    quality_point: document.getElementById('ing-quality-point').value,
    sort_order:    document.getElementById('ing-sort-order').value,
  });
  if (result.ok) {
    showToast('保存しました');
    closeModal('ing-modal');
    setTimeout(() => location.reload(), 600);
  } else {
    showToast(result.error || '保存に失敗しました', 'error');
  }
});
// モーダル外クリックで閉じる
document.getElementById('ing-modal').addEventListener('click', (e) => {
  if (e.target === e.currentTarget) closeModal('ing-modal');
});
</script>

<?php layout_foot(); ?>
