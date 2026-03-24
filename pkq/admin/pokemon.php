<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$db = getDb();
$pokemon = $db->query(
    'SELECT * FROM pokemon ORDER BY pokedex_no'
)->fetchAll();

layout_head('ポケモン管理', 'pokemon');
?>

<div class="card">
  <div class="card-header">
    ポケモン一覧（<?= count($pokemon) ?>件）
    <span style="color:#636e72;font-size:0.85rem;font-weight:400;">行をクリックして編集</span>
  </div>
  <div class="card-body" style="padding:0;overflow-x:auto;">
    <table class="tbl">
      <thead>
        <tr>
          <th>No.</th>
          <th>名前</th>
          <th>タイプ1</th>
          <th>タイプ2</th>
          <th>HP</th>
          <th>ATK</th>
          <th>ふつう</th>
          <th>いい</th>
          <th>すごくいい</th>
          <th>スペシャル</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pokemon as $p): ?>
        <tr>
          <td style="color:#636e72;"><?= str_pad($p['pokedex_no'], 3, '0', STR_PAD_LEFT) ?></td>
          <td style="font-weight:600;"><?= htmlspecialchars($p['name']) ?></td>
          <td><?= htmlspecialchars($p['type1']) ?></td>
          <td style="color:#636e72;"><?= htmlspecialchars($p['type2'] ?? '—') ?></td>
          <td style="text-align:right;"><?= $p['base_hp'] ?? '—' ?></td>
          <td style="text-align:right;"><?= $p['base_atk'] ?? '—' ?></td>
          <td style="text-align:right;<?= ($p['quality_normal'] ?? 0) >= 5 ? 'color:#27ae60;font-weight:600;' : 'color:#ccc;' ?>"><?= $p['quality_normal'] ?? 0 ?></td>
          <td style="text-align:right;<?= ($p['quality_good'] ?? 0) >= 5 ? 'color:#2980b9;font-weight:600;' : 'color:#ccc;' ?>"><?= $p['quality_good'] ?? 0 ?></td>
          <td style="text-align:right;<?= ($p['quality_great'] ?? 0) >= 5 ? 'color:#8e44ad;font-weight:600;' : 'color:#ccc;' ?>"><?= $p['quality_great'] ?? 0 ?></td>
          <td style="text-align:right;<?= ($p['quality_special'] ?? 0) >= 5 ? 'color:#e74c3c;font-weight:600;' : 'color:#ccc;' ?>"><?= $p['quality_special'] ?? 0 ?></td>
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
  document.getElementById('pokemon-hp').value    = p.base_hp || '';
  document.getElementById('pokemon-atk').value   = p.base_atk || '';
  document.getElementById('pokemon-q-normal').value  = p.quality_normal || 0;
  document.getElementById('pokemon-q-good').value    = p.quality_good || 0;
  document.getElementById('pokemon-q-great').value   = p.quality_great || 0;
  document.getElementById('pokemon-q-special').value = p.quality_special || 0;
  document.getElementById('pokemon-memo').value = p.memo || '';
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
    base_hp:  document.getElementById('pokemon-hp').value,
    base_atk: document.getElementById('pokemon-atk').value,
    quality_normal:  document.getElementById('pokemon-q-normal').value,
    quality_good:    document.getElementById('pokemon-q-good').value,
    quality_great:   document.getElementById('pokemon-q-great').value,
    quality_special: document.getElementById('pokemon-q-special').value,
    memo: document.getElementById('pokemon-memo').value,
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
