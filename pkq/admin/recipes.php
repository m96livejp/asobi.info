<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$db = getDb();
// recipe_no単位でまとめる（qualityごとに行があるためGROUP BY）
$recipes = $db->query(
    'SELECT recipe_no, name, ingredient_hint, pokemon_hint, image_path
     FROM recipes
     GROUP BY recipe_no
     ORDER BY recipe_no'
)->fetchAll();

layout_head('料理管理', 'recipes');
?>

<div class="card">
  <div class="card-header">
    料理一覧（<?= count($recipes) ?>件）
    <span style="color:#636e72;font-size:0.85rem;font-weight:400;">行をクリックして編集</span>
  </div>
  <div class="card-body" style="padding:0;overflow-x:auto;">
    <table class="tbl">
      <thead>
        <tr>
          <th>No.</th>
          <th>画像</th>
          <th>料理名</th>
          <th>素材ヒント</th>
          <th>ポケモンヒント</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recipes as $r): ?>
        <tr>
          <td style="color:#636e72;font-weight:600;"><?= $r['recipe_no'] ?></td>
          <td>
            <?php if ($r['image_path']): ?>
              <img src="/images/recipes/<?= htmlspecialchars($r['image_path']) ?>"
                   style="width:40px;height:40px;object-fit:contain;border-radius:6px;">
            <?php else: ?>
              <span style="color:#b2bec3;">—</span>
            <?php endif; ?>
          </td>
          <td style="font-weight:600;"><?= htmlspecialchars($r['name']) ?></td>
          <td style="color:#636e72;font-size:0.82rem;max-width:200px;"><?= htmlspecialchars($r['ingredient_hint'] ?? '—') ?></td>
          <td style="color:#636e72;font-size:0.82rem;max-width:200px;"><?= htmlspecialchars($r['pokemon_hint'] ?? '—') ?></td>
          <td>
            <button class="btn btn-sm btn-primary" onclick='openRecipeEdit(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)'>
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
<div class="modal-overlay" id="recipe-modal">
  <div class="modal">
    <div class="modal-title">料理を編集</div>
    <form id="recipe-form">
      <input type="hidden" id="recipe-no">
      <div class="form-group">
        <label class="form-label">料理名</label>
        <input type="text" class="form-control" id="recipe-name">
      </div>
      <div class="form-group">
        <label class="form-label">素材ヒント（ingredient_hint）</label>
        <input type="text" class="form-control" id="recipe-ingredient-hint"
               placeholder="例: 赤いもの×4以上">
      </div>
      <div class="form-group">
        <label class="form-label">ポケモンヒント（pokemon_hint）</label>
        <input type="text" class="form-control" id="recipe-pokemon-hint"
               placeholder="例: ほのおタイプ">
      </div>
      <div class="form-group">
        <label class="form-label">画像ファイル名（image_path）</label>
        <input type="text" class="form-control" id="recipe-image-path"
               placeholder="例: recipe_1.png">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('recipe-modal')">キャンセル</button>
        <button type="submit" class="btn btn-success">保存</button>
      </div>
    </form>
  </div>
</div>

<script>
function openRecipeEdit(r) {
  document.getElementById('recipe-no').value               = r.recipe_no;
  document.getElementById('recipe-name').value             = r.name;
  document.getElementById('recipe-ingredient-hint').value  = r.ingredient_hint || '';
  document.getElementById('recipe-pokemon-hint').value     = r.pokemon_hint || '';
  document.getElementById('recipe-image-path').value       = r.image_path || '';
  document.getElementById('recipe-modal').classList.add('open');
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}
document.getElementById('recipe-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const result = await adminApi('update_recipe', {
    recipe_no:       document.getElementById('recipe-no').value,
    name:            document.getElementById('recipe-name').value,
    ingredient_hint: document.getElementById('recipe-ingredient-hint').value,
    pokemon_hint:    document.getElementById('recipe-pokemon-hint').value,
    image_path:      document.getElementById('recipe-image-path').value,
  });
  if (result.ok) {
    showToast('保存しました');
    closeModal('recipe-modal');
    setTimeout(() => location.reload(), 600);
  } else {
    showToast(result.error || '保存に失敗しました', 'error');
  }
});
document.getElementById('recipe-modal').addEventListener('click', (e) => {
  if (e.target === e.currentTarget) closeModal('recipe-modal');
});
</script>

<?php layout_foot(); ?>
