<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$db = getDb();
$pokemon = $db->query(
    'SELECT * FROM pokemon ORDER BY pokedex_no'
)->fetchAll();

// レシピ一覧（重複排除）
$recipes = $db->query(
    'SELECT MIN(id) as id, recipe_no, name, image_path FROM recipes GROUP BY recipe_no ORDER BY recipe_no'
)->fetchAll();

// ポケモンごとのレシピ紐付け
$recipeMap = [];
$rpRows = $db->query(
    'SELECT rp.pokemon_id, r.recipe_no FROM recipe_pokemon rp JOIN recipes r ON rp.recipe_id = r.id'
)->fetchAll();
foreach ($rpRows as $row) {
    $recipeMap[(int)$row['pokemon_id']][] = (int)$row['recipe_no'];
}
// pokemonデータにレシピ情報を追加
foreach ($pokemon as &$p) {
    $p['recipes'] = array_unique($recipeMap[(int)$p['pokedex_no']] ?? []);
}

layout_head('ポケモン管理', 'pokemon');
?>

<div class="card">
  <div class="card-header">
    ポケモン一覧（<?= count($pokemon) ?>件）
    <span style="color:#636e72;font-size:0.85rem;font-weight:400;">編集ボタンをクリックして編集</span>
  </div>
  <div class="card-body" style="padding:0;overflow-x:auto;">
    <table class="tbl">
      <thead>
        <tr>
          <th>No.</th>
          <th>名前</th>
          <th>タイプ</th>
          <th>色</th>
          <th>HP/ATK</th>
          <th>品質</th>
          <th>レシピ</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pokemon as $p):
          $qn = $p['quality_normal'] ?? 0; $qg = $p['quality_good'] ?? 0;
          $qr = $p['quality_great'] ?? 0; $qs = $p['quality_special'] ?? 0;
          $recipeNames = [];
          foreach ($recipes as $r) {
            if (in_array($r['recipe_no'], $p['recipes'])) $recipeNames[] = $r['name'];
          }
        ?>
        <tr>
          <td style="color:#636e72;"><?= str_pad($p['pokedex_no'], 3, '0', STR_PAD_LEFT) ?></td>
          <td style="font-weight:600;"><?= htmlspecialchars($p['name']) ?></td>
          <td style="font-size:0.8rem;"><?= htmlspecialchars($p['type1']) ?><?= $p['type2'] ? '/'.htmlspecialchars($p['type2']) : '' ?></td>
          <td style="font-size:0.8rem;"><?php
            $colorLabels = ['red'=>'赤','blue'=>'青','yellow'=>'黄','gray'=>'白'];
            $colorColors = ['red'=>'#e74c3c','blue'=>'#2980b9','yellow'=>'#f1c40f','gray'=>'#95a5a6'];
            $c = $p['color'] ?? '';
            if ($c && isset($colorLabels[$c])):
          ?><span style="color:<?= $colorColors[$c] ?>;font-weight:600;"><?= $colorLabels[$c] ?></span><?php else: ?>—<?php endif; ?></td>
          <td style="text-align:right;font-size:0.8rem;"><?= $p['base_hp'] ?? '—' ?>/<?= $p['base_atk'] ?? '—' ?></td>
          <td style="font-size:0.8rem;white-space:nowrap;"><?= $qn ?>:<?= $qg ?>:<?= $qr ?>:<?= $qs ?></td>
          <td style="font-size:0.7rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars(implode(', ', $recipeNames)) ?>"><?= htmlspecialchars(implode(', ', $recipeNames)) ?: '—' ?></td>
          <td>
            <button class="btn btn-sm btn-primary" onclick='openPokemonEdit(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)'>
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
<div class="modal-overlay" id="pokemon-modal">
  <div class="modal">
    <div class="modal-title">ポケモンを編集</div>
    <form id="pokemon-form">
      <input type="hidden" id="pokemon-id">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group" style="grid-column:1/-1;">
          <label class="form-label">名前</label>
          <input type="text" class="form-control" id="pokemon-name">
        </div>
        <div class="form-group">
          <label class="form-label">タイプ1</label>
          <input type="text" class="form-control" id="pokemon-type1">
        </div>
        <div class="form-group">
          <label class="form-label">タイプ2（任意）</label>
          <input type="text" class="form-control" id="pokemon-type2">
        </div>
        <div class="form-group">
          <label class="form-label">色</label>
          <select class="form-control" id="pokemon-color">
            <option value="">未設定</option>
            <option value="red">red（赤）</option>
            <option value="blue">blue（青）</option>
            <option value="yellow">yellow（黄）</option>
            <option value="gray">gray（白/灰）</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">HP</label>
          <input type="number" class="form-control" id="pokemon-hp" min="1">
        </div>
        <div class="form-group">
          <label class="form-label">ATK</label>
          <input type="number" class="form-control" id="pokemon-atk" min="1">
        </div>
        <div class="form-group" style="grid-column:1/-1;">
          <label class="form-label">品質（0〜100）</label>
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;">
            <div><label style="font-size:0.8rem;color:#636e72;">ふつう</label><input type="number" class="form-control" id="pokemon-q-normal" min="0" max="100"></div>
            <div><label style="font-size:0.8rem;color:#636e72;">いい</label><input type="number" class="form-control" id="pokemon-q-good" min="0" max="100"></div>
            <div><label style="font-size:0.8rem;color:#636e72;">すごくいい</label><input type="number" class="form-control" id="pokemon-q-great" min="0" max="100"></div>
            <div><label style="font-size:0.8rem;color:#636e72;">スペシャル</label><input type="number" class="form-control" id="pokemon-q-special" min="0" max="100"></div>
          </div>
        </div>
        <div class="form-group" style="grid-column:1/-1;">
          <label class="form-label">入手レシピ</label>
          <div id="recipe-checkboxes" style="display:grid;grid-template-columns:repeat(3,1fr);gap:4px;font-size:0.8rem;">
            <?php foreach ($recipes as $r): ?>
            <label style="display:flex;align-items:center;gap:4px;cursor:pointer;padding:2px 4px;border-radius:4px;background:#f8f8f8;">
              <input type="checkbox" class="recipe-cb" data-recipe-no="<?= $r['recipe_no'] ?>" style="width:16px;height:16px;">
              <?= htmlspecialchars($r['name']) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-group" style="grid-column:1/-1;">
          <label class="form-label">メモ</label>
          <textarea class="form-control" id="pokemon-memo" rows="3" style="resize:vertical;"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('pokemon-modal')">キャンセル</button>
        <button type="submit" class="btn btn-success">保存</button>
      </div>
    </form>
  </div>
</div>

<script>
function openPokemonEdit(p) {
  document.getElementById('pokemon-id').value    = p.id;
  document.getElementById('pokemon-name').value  = p.name;
  document.getElementById('pokemon-type1').value = p.type1;
  document.getElementById('pokemon-type2').value = p.type2 || '';
  document.getElementById('pokemon-color').value  = p.color || '';
  document.getElementById('pokemon-hp').value    = p.base_hp || '';
  document.getElementById('pokemon-atk').value   = p.base_atk || '';
  document.getElementById('pokemon-q-normal').value  = p.quality_normal || 0;
  document.getElementById('pokemon-q-good').value    = p.quality_good || 0;
  document.getElementById('pokemon-q-great').value   = p.quality_great || 0;
  document.getElementById('pokemon-q-special').value = p.quality_special || 0;
  document.getElementById('pokemon-memo').value = p.memo || '';
  // レシピチェックボックスを復元
  const pRecipes = p.recipes || [];
  document.querySelectorAll('.recipe-cb').forEach(cb => {
    cb.checked = pRecipes.includes(parseInt(cb.dataset.recipeNo));
  });
  document.getElementById('pokemon-modal').classList.add('open');
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}
document.getElementById('pokemon-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const result = await adminApi('update_pokemon', {
    id:       document.getElementById('pokemon-id').value,
    name:     document.getElementById('pokemon-name').value,
    type1:    document.getElementById('pokemon-type1').value,
    type2:    document.getElementById('pokemon-type2').value,
    color:    document.getElementById('pokemon-color').value,
    base_hp:  document.getElementById('pokemon-hp').value,
    base_atk: document.getElementById('pokemon-atk').value,
    quality_normal:  document.getElementById('pokemon-q-normal').value,
    quality_good:    document.getElementById('pokemon-q-good').value,
    quality_great:   document.getElementById('pokemon-q-great').value,
    quality_special: document.getElementById('pokemon-q-special').value,
    memo: document.getElementById('pokemon-memo').value,
    recipes: Array.from(document.querySelectorAll('.recipe-cb:checked')).map(cb => parseInt(cb.dataset.recipeNo)),
  });
  if (result.ok) {
    showToast('保存しました');
    closeModal('pokemon-modal');
    setTimeout(() => location.reload(), 600);
  } else {
    showToast(result.error || '保存に失敗しました', 'error');
  }
});
document.getElementById('pokemon-modal').addEventListener('click', (e) => {
  if (e.target === e.currentTarget) closeModal('pokemon-modal');
});
</script>

<?php layout_foot(); ?>
