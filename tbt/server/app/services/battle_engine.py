import random
import uuid

from app.models.character import Character
from app.schemas.battle import BattleTurnAction, BattleResultResponse


MAX_TURNS = 20
SKILL_CHANCE = 0.3  # 30% chance to use skill


def _get_equip_stats(char: Character) -> dict:
    """装備品のボーナスステータスと特殊効果を集計"""
    stats = {"hp": 0, "atk": 0, "def": 0, "spd": 0}
    effects = {"critical_rate": 0.0, "dodge_rate": 0.0, "counter_rate": 0.0, "heal_per_turn": 0.0}

    seen_templates = set()
    for eq in (char.equipment or []):
        t = eq.item_template
        if not t:
            continue
        # 両手武器は2スロットに同じIDが入るので重複排除
        if t.equip_slot == "weapon_2h" and t.id in seen_templates:
            continue
        seen_templates.add(t.id)

        stats["hp"] += t.bonus_hp
        stats["atk"] += t.bonus_atk
        stats["def"] += t.bonus_def
        stats["spd"] += t.bonus_spd
        if t.effect_name and t.effect_name in effects:
            effects[t.effect_name] += t.effect_value

    return {**stats, **effects}


def calculate_damage(atk: int, def_: int, skill_power: float, is_critical: bool = False) -> int:
    """ダメージ計算: ATK * skill_power * (1 - DEF/(DEF+100)) * random(0.9~1.1)"""
    reduction = 1 - def_ / (def_ + 100)
    base_damage = atk * skill_power * reduction
    variance = random.uniform(0.9, 1.1)
    damage = base_damage * variance
    if is_critical:
        damage *= 1.5
    return max(1, int(damage))


def run_battle(char_a: Character, char_b: Character) -> BattleResultResponse:
    """2体のキャラクターでオートバトルを実行（装備ステータス・効果反映）"""
    battle_id = str(uuid.uuid4())

    # 装備ステータス集計
    eq_a = _get_equip_stats(char_a)
    eq_b = _get_equip_stats(char_b)

    # バトル用ステータス (キャラ基礎 + 装備ボーナス)
    total_hp_a = char_a.hp + eq_a["hp"]
    total_hp_b = char_b.hp + eq_b["hp"]
    total_atk_a = char_a.atk + eq_a["atk"]
    total_atk_b = char_b.atk + eq_b["atk"]
    total_def_a = char_a.def_ + eq_a["def"]
    total_def_b = char_b.def_ + eq_b["def"]
    total_spd_a = char_a.spd + eq_a["spd"]
    total_spd_b = char_b.spd + eq_b["spd"]

    hp_a = total_hp_a
    hp_b = total_hp_b
    max_hp_a = total_hp_a
    max_hp_b = total_hp_b

    name_a = char_a.template.name if char_a.template else f"Character {char_a.id[:8]}"
    name_b = char_b.template.name if char_b.template else f"Character {char_b.id[:8]}"

    skill_name_a = char_a.template.skill_name if char_a.template else "攻撃"
    skill_name_b = char_b.template.skill_name if char_b.template else "攻撃"
    skill_power_a = char_a.template.skill_power if char_a.template else 1.0
    skill_power_b = char_b.template.skill_power if char_b.template else 1.0

    turns: list[BattleTurnAction] = []

    for turn in range(1, MAX_TURNS + 1):
        # 毎ターンHP回復 (装飾品効果)
        if eq_a["heal_per_turn"] > 0 and hp_a > 0:
            hp_a = min(max_hp_a, hp_a + int(eq_a["heal_per_turn"]))
        if eq_b["heal_per_turn"] > 0 and hp_b > 0:
            hp_b = min(max_hp_b, hp_b + int(eq_b["heal_per_turn"]))

        # 行動順: SPD が高い方が先攻
        if total_spd_a >= total_spd_b:
            order = [("a", "b"), ("b", "a")]
        else:
            order = [("b", "a"), ("a", "b")]

        for atk_key, def_key in order:
            atk_hp = hp_a if atk_key == "a" else hp_b
            def_hp = hp_a if def_key == "a" else hp_b

            if atk_hp <= 0:
                continue

            atk_stat = total_atk_a if atk_key == "a" else total_atk_b
            def_stat = total_def_a if def_key == "a" else total_def_b
            atk_eq = eq_a if atk_key == "a" else eq_b
            def_eq = eq_a if def_key == "a" else eq_b
            atk_name = name_a if atk_key == "a" else name_b
            atk_id = char_a.id if atk_key == "a" else char_b.id

            # 回避判定
            if random.random() < def_eq["dodge_rate"]:
                turns.append(BattleTurnAction(
                    turn=turn, actor=atk_name, actor_id=atk_id,
                    action="miss", skill_name="回避！", damage=0,
                    target_hp_remaining=def_hp, actor_hp_remaining=atk_hp,
                ))
                continue

            # スキル or 通常攻撃
            use_skill = random.random() < SKILL_CHANCE
            if use_skill:
                action = "skill"
                skill_name = skill_name_a if atk_key == "a" else skill_name_b
                power = skill_power_a if atk_key == "a" else skill_power_b
            else:
                action = "attack"
                skill_name = "通常攻撃"
                power = 1.0

            # クリティカル判定
            is_critical = random.random() < atk_eq["critical_rate"]
            if is_critical:
                action = "critical"

            damage = calculate_damage(atk_stat, def_stat, power, is_critical)

            # ダメージ適用
            if def_key == "a":
                hp_a = max(0, hp_a - damage)
                def_hp_after = hp_a
            else:
                hp_b = max(0, hp_b - damage)
                def_hp_after = hp_b

            turns.append(BattleTurnAction(
                turn=turn, actor=atk_name, actor_id=atk_id,
                action=action, skill_name=skill_name, damage=damage,
                target_hp_remaining=def_hp_after, actor_hp_remaining=atk_hp,
            ))

            # 反撃判定
            if def_hp_after > 0 and random.random() < def_eq["counter_rate"]:
                counter_atk = total_atk_a if def_key == "a" else total_atk_b
                counter_def = total_def_a if atk_key == "a" else total_def_b
                counter_damage = calculate_damage(counter_atk, counter_def, 0.5)
                counter_name = name_a if def_key == "a" else name_b
                counter_id = char_a.id if def_key == "a" else char_b.id

                if atk_key == "a":
                    hp_a = max(0, hp_a - counter_damage)
                    counter_target_hp = hp_a
                else:
                    hp_b = max(0, hp_b - counter_damage)
                    counter_target_hp = hp_b

                turns.append(BattleTurnAction(
                    turn=turn, actor=counter_name, actor_id=counter_id,
                    action="counter", skill_name="反撃！", damage=counter_damage,
                    target_hp_remaining=counter_target_hp, actor_hp_remaining=def_hp_after,
                ))

            # 決着判定
            if def_hp_after <= 0:
                return BattleResultResponse(
                    battle_id=battle_id,
                    winner_id=atk_id,
                    winner_name=atk_name,
                    loser_name=name_b if atk_key == "a" else name_a,
                    turns=turns,
                    total_turns=turn,
                )
            if atk_key == "a" and hp_a <= 0 or atk_key == "b" and hp_b <= 0:
                winner_key = def_key
                return BattleResultResponse(
                    battle_id=battle_id,
                    winner_id=char_a.id if winner_key == "a" else char_b.id,
                    winner_name=name_a if winner_key == "a" else name_b,
                    loser_name=name_b if winner_key == "a" else name_a,
                    turns=turns,
                    total_turns=turn,
                )

    # 最大ターン到達 → 残HP率で判定
    ratio_a = hp_a / max_hp_a if max_hp_a > 0 else 0
    ratio_b = hp_b / max_hp_b if max_hp_b > 0 else 0

    if ratio_a >= ratio_b:
        winner_id, winner_name, loser_name = char_a.id, name_a, name_b
    else:
        winner_id, winner_name, loser_name = char_b.id, name_b, name_a

    return BattleResultResponse(
        battle_id=battle_id,
        winner_id=winner_id,
        winner_name=winner_name,
        loser_name=loser_name,
        turns=turns,
        total_turns=MAX_TURNS,
    )
