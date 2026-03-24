import random
import uuid

from fastapi import APIRouter, HTTPException, status
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.orm import selectinload, joinedload

from app.deps import CurrentUser, DbSession
from app.models.character import Character, CharacterEquipment, CharacterTemplate
from app.models.battle import Tournament, TournamentEntry, BattleLog
from app.api.characters import apply_exp_levelup
from app.models.item import ItemTemplate, UserItem
from app.models.user import User
from app.schemas.battle import (
    TournamentResponse,
    TournamentEntryRequest,
    TournamentBracketResponse,
    TournamentBracketEntry,
    TournamentBracketBattle,
    BattleResultResponse,
)
from app.services.battle_engine import run_battle
from app.services.matchmaking import assign_seeds, get_round_matchups

router = APIRouter()

NPC_DEVICE_PREFIX = "npc_"

# フォールバック用NPCニックネーム（DBから取得できない場合）
_FALLBACK_NPC_NICKNAMES = [
    "アレックス", "リナ", "カイト", "ミル", "ゼノ",
    "ハル", "ソラ", "ユウキ", "レン", "アイリ",
    "シン", "マヤ", "ルカ", "サクラ", "ダイチ",
    "ヒカリ", "タクミ", "ナナ", "リュウ", "ミサキ",
]


async def _get_npc_nicknames(db: AsyncSession) -> list[str]:
    """DBからアクティブなNPC名前リストを取得（フォールバックあり）"""
    from app.models.admin import NpcName
    result = await db.execute(select(NpcName.name).where(NpcName.is_active == 1))
    names = [row[0] for row in result.all()]
    return names if names else _FALLBACK_NPC_NICKNAMES


async def _get_or_create_npc_user(db: AsyncSession, nickname: str) -> User:
    """NPCユーザーをニックネームごとに取得または作成"""
    device_id = NPC_DEVICE_PREFIX + nickname
    result = await db.execute(select(User).where(User.device_id == device_id))
    npc_user = result.scalar_one_or_none()
    if not npc_user:
        npc_user = User(device_id=device_id, display_name=nickname, points=0)
        db.add(npc_user)
        await db.flush()
    return npc_user


async def _create_npc_character(db: AsyncSession, user_id: str, template: CharacterTemplate) -> Character:
    """NPCキャラクターを1体作成"""
    races = ["warrior", "mage", "beastman"]
    char = Character(
        user_id=user_id,
        template_id=template.id,
        race=random.choice(races),
        hp=template.base_hp,
        atk=template.base_atk,
        def_=template.base_def,
        spd=template.base_spd,
    )
    db.add(char)
    await db.flush()
    return char


async def _get_used_nicknames(db: AsyncSession, tournament_id: str) -> set[str]:
    """トーナメントに既に参加しているユーザーの表示名を取得"""
    result = await db.execute(
        select(User.display_name)
        .join(TournamentEntry, TournamentEntry.user_id == User.id)
        .where(TournamentEntry.tournament_id == tournament_id)
    )
    return {row[0] for row in result.all()}


async def _find_tournaments_to_fill(db: AsyncSession) -> list[str]:
    """NPC自動参加の対象トーナメントIDリストを返す（読み取りのみ）"""
    from datetime import datetime, timedelta
    from app.services.settings_service import get_setting

    interval_str = await get_setting(db, "auto_tournament_interval_seconds")
    interval_sec = int(interval_str) if interval_str else 300
    time_threshold = datetime.utcnow() - timedelta(seconds=interval_sec)

    # NPC参加に必要な最低人間参加者数（0なら人間なしでも参加）
    min_humans_val = await get_setting(db, "tournament_npc_join_min_humans")
    min_humans = int(min_humans_val) if min_humans_val is not None else 1

    result = await db.execute(
        select(Tournament).where(Tournament.status == "recruiting")
    )
    tournaments_list = result.scalars().all()

    target_ids = []
    full_ids = []
    for tournament in tournaments_list:
        entries = tournament.entries

        # 人間のエントリー数をカウント
        human_count = sum(
            1 for e in entries
            if not (e.user and e.user.device_id and e.user.device_id.startswith(NPC_DEVICE_PREFIX))
        )

        # 最低人間参加者数に満たない場合はスキップ
        if human_count < min_humans:
            continue

        # 最後のエントリー（または作成日）から5分経過しているか確認
        if entries:
            latest_entry_time = max(
                (e.created_at for e in entries if e.created_at is not None),
                default=tournament.created_at,
            )
        else:
            latest_entry_time = tournament.created_at

        if latest_entry_time and latest_entry_time > time_threshold:
            continue  # まだ5分経っていない

        remaining_slots = tournament.max_participants - len(entries)
        if remaining_slots <= 0:
            # 満員なのにrecruitingのまま → 起動漏れとして別リストに積む
            full_ids.append((tournament.created_at, tournament.id))
            continue

        target_ids.append((tournament.created_at, tournament.id))

    # 作成日が古い順にソートしてIDだけ返す
    target_ids.sort(key=lambda x: x[0])
    full_ids.sort(key=lambda x: x[0])
    return [tid for _, tid in target_ids], [tid for _, tid in full_ids]


async def auto_create_tournaments(db: AsyncSession) -> int:
    """
    4人/8人それぞれの自動作成数に基づき、不足分のトーナメントを作成する。
    返り値: 作成したトーナメント数
    """
    from app.services.settings_service import get_setting

    threshold_4 = int(await get_setting(db, "tournament_auto_create_4") or 0)
    threshold_8 = int(await get_setting(db, "tournament_auto_create_8") or 0)

    if threshold_4 <= 0 and threshold_8 <= 0:
        return 0

    # 現在のアクティブトーナメントを参加人数別にカウント
    result = await db.execute(
        select(Tournament).where(Tournament.status.in_(["recruiting", "in_progress"]))
    )
    active_tournaments = result.scalars().all()
    active_4 = sum(1 for t in active_tournaments if t.max_participants == 4)
    active_8 = sum(1 for t in active_tournaments if t.max_participants == 8)

    created = 0
    for _ in range(max(0, threshold_4 - active_4)):
        db.add(Tournament(name="トーナメント(4人)", max_participants=4, reward_points=200))
        created += 1

    for _ in range(max(0, threshold_8 - active_8)):
        db.add(Tournament(name="トーナメント(8人)", max_participants=8, reward_points=200))
        created += 1

    if created > 0:
        await db.flush()
        await db.commit()
        print(f"[auto-create] 新規トーナメント {created} 件作成 (4人: {active_4}→{active_4 + max(0, threshold_4 - active_4)}/{threshold_4}, 8人: {active_8}→{active_8 + max(0, threshold_8 - active_8)}/{threshold_8})")

    return created


async def auto_fill_and_run_tournament(db: AsyncSession) -> bool:
    """
    auto_tournament_distribution 設定に従い、古いトーナメントから順に
    指定数ずつNPCを追加する。満員になったら即開催。
    満員なのにrecruitingのままのトーナメントも直接起動する。
    """
    from app.services.settings_service import get_setting
    import json as _json

    # 配分設定を取得: [3, 2, 1] → 1番古いに3体、2番目に2体、3番目に1体
    dist_str = await get_setting(db, "auto_tournament_distribution")
    try:
        distribution = _json.loads(dist_str) if dist_str else [3, 2, 1]
    except (ValueError, TypeError):
        distribution = [3, 2, 1]

    # 対象トーナメントIDを取得（作成日が古い順）
    target_ids, full_ids = await _find_tournaments_to_fill(db)

    executed = False

    # 満員でrecruitingのトーナメントを直接起動（起動漏れリカバリー）
    for tournament_id in full_ids:
        try:
            await _run_full_tournament(db, tournament_id)
            print(f"[npc-fill] 満員トーナメント {tournament_id} を起動")
            executed = True
        except Exception as e:
            await db.rollback()
            print(f"[npc-fill] 満員トーナメント {tournament_id} 起動エラー: {e}")

    # 配分に従いNPCを追加（古い順）
    for i, tournament_id in enumerate(target_ids):
        # 配分リストの範囲外は最後の値を使用
        npc_count = distribution[i] if i < len(distribution) else distribution[-1]
        if npc_count <= 0:
            continue
        try:
            await _add_npcs_and_maybe_run(db, tournament_id, npc_count)
            print(f"[npc-fill] トーナメント {tournament_id} にNPC {npc_count}体追加")
            executed = True
        except Exception as e:
            await db.rollback()
            print(f"[npc-fill] トーナメント {tournament_id} でエラー: {e}")

    return executed


async def _run_full_tournament(db: AsyncSession, tournament_id: str):
    """満員(recruiting)のトーナメントをそのまま起動する（起動漏れリカバリー用）"""
    import asyncio

    result = await db.execute(
        select(Tournament).where(
            Tournament.id == tournament_id,
            Tournament.status == "recruiting",
        )
    )
    tournament = result.scalar_one_or_none()
    if not tournament:
        return
    if len(tournament.entries) < tournament.max_participants:
        return  # 満員でなければ何もしない

    tournament.status = "in_progress"
    tournament.current_round = 1
    await db.commit()
    await asyncio.sleep(0)

    refreshed = await db.execute(
        select(Tournament)
        .where(Tournament.id == tournament_id)
        .options(
            selectinload(Tournament.entries).options(
                joinedload(TournamentEntry.character).options(
                    joinedload(Character.template),
                    selectinload(Character.equipment).joinedload(CharacterEquipment.item_template),
                ),
                joinedload(TournamentEntry.user),
            )
        )
        .execution_options(populate_existing=True)
    )
    tournament = refreshed.scalar_one()
    assign_seeds(tournament.entries)
    await db.commit()
    await asyncio.sleep(0)

    while tournament.status == "in_progress":
        await _execute_one_round(db, tournament)
        await db.commit()
        await asyncio.sleep(0)


async def _fill_all_npcs_and_run(db: AsyncSession, tournament_id: str):
    """1つのトーナメントに残り全スロット分のNPCを一括追加して即実行する"""
    import asyncio

    result = await db.execute(
        select(Tournament).where(
            Tournament.id == tournament_id,
            Tournament.status == "recruiting",
        )
    )
    tournament = result.scalar_one_or_none()
    if not tournament:
        return

    entries = tournament.entries
    remaining_slots = tournament.max_participants - len(entries)
    if remaining_slots <= 0:
        return

    templates_result = await db.execute(select(CharacterTemplate))
    templates = templates_result.scalars().all()

    used_names = await _get_used_nicknames(db, tournament_id)
    npc_nicknames = await _get_npc_nicknames(db)

    for i in range(remaining_slots):
        available_names = [n for n in npc_nicknames if n not in used_names]
        if not available_names:
            nickname = f"CPU_{len(entries)+i+1:02d}"
        else:
            nickname = random.choice(available_names)
        used_names.add(nickname)

        template = random.choice(templates)
        npc_user = await _get_or_create_npc_user(db, nickname)
        char = await _create_npc_character(db, npc_user.id, template)
        entry = TournamentEntry(
            tournament_id=tournament_id,
            user_id=npc_user.id,
            character_id=char.id,
        )
        db.add(entry)

    await db.commit()
    await asyncio.sleep(0)

    # 満員 → トーナメント実行
    tournament.status = "in_progress"
    tournament.current_round = 1
    await db.commit()
    await asyncio.sleep(0)

    refreshed = await db.execute(
        select(Tournament)
        .where(Tournament.id == tournament_id)
        .options(
            selectinload(Tournament.entries).options(
                joinedload(TournamentEntry.character).options(
                    joinedload(Character.template),
                    selectinload(Character.equipment).joinedload(CharacterEquipment.item_template),
                ),
                joinedload(TournamentEntry.user),
            )
        )
        .execution_options(populate_existing=True)
    )
    tournament = refreshed.scalar_one()
    assign_seeds(tournament.entries)
    await db.commit()
    await asyncio.sleep(0)

    while tournament.status == "in_progress":
        await _execute_one_round(db, tournament)
        await db.commit()
        await asyncio.sleep(0)


async def _add_npcs_and_maybe_run(db: AsyncSession, tournament_id: str, count: int):
    """トーナメントにNPCをcount体追加。満員になったら実行する"""
    import asyncio

    result = await db.execute(
        select(Tournament).where(
            Tournament.id == tournament_id,
            Tournament.status == "recruiting",
        )
    )
    tournament = result.scalar_one_or_none()
    if not tournament:
        return

    entries = tournament.entries
    remaining_slots = tournament.max_participants - len(entries)
    if remaining_slots <= 0:
        return

    # 追加数は残りスロット数を超えない
    add_count = min(count, remaining_slots)

    used_names = await _get_used_nicknames(db, tournament_id)
    npc_nicknames = await _get_npc_nicknames(db)
    templates_result = await db.execute(select(CharacterTemplate))
    templates = templates_result.scalars().all()

    for i in range(add_count):
        available_names = [n for n in npc_nicknames if n not in used_names]
        if not available_names:
            nickname = f"CPU_{len(entries)+i+1:02d}"
        else:
            nickname = random.choice(available_names)
        used_names.add(nickname)

        template = random.choice(templates)
        npc_user = await _get_or_create_npc_user(db, nickname)
        char = await _create_npc_character(db, npc_user.id, template)
        entry = TournamentEntry(
            tournament_id=tournament_id,
            user_id=npc_user.id,
            character_id=char.id,
        )
        db.add(entry)

    await db.commit()
    await asyncio.sleep(0)

    # 満員になったかチェック
    refreshed_result = await db.execute(
        select(Tournament).where(Tournament.id == tournament_id)
    )
    tournament = refreshed_result.scalar_one()
    if len(tournament.entries) < tournament.max_participants:
        return  # まだ満員でない → 次の周期でまた追加

    # 満員 → トーナメント実行
    tournament.status = "in_progress"
    tournament.current_round = 1
    await db.commit()
    await asyncio.sleep(0)

    refreshed = await db.execute(
        select(Tournament)
        .where(Tournament.id == tournament_id)
        .options(
            selectinload(Tournament.entries).options(
                joinedload(TournamentEntry.character).options(
                    joinedload(Character.template),
                    selectinload(Character.equipment).joinedload(CharacterEquipment.item_template),
                ),
                joinedload(TournamentEntry.user),
            )
        )
        .execution_options(populate_existing=True)
    )
    tournament = refreshed.scalar_one()
    assign_seeds(tournament.entries)
    await db.commit()
    await asyncio.sleep(0)

    while tournament.status == "in_progress":
        await _execute_one_round(db, tournament)
        await db.commit()
        await asyncio.sleep(0)


def _calc_avg_power(entries: list[TournamentEntry]) -> float:
    """参加者の平均総合力を計算"""
    powers = []
    for e in entries:
        if e.character:
            c = e.character
            powers.append(c.hp + c.atk + c.def_ + c.spd)
    return sum(powers) / len(powers) if powers else 0


async def _award_treasure_chest(db, user_id: str, avg_power: float):
    """勝者に宝箱を付与。avg_powerに応じてランクを決定"""
    # 宝箱のitem_typeプレフィックスで検索
    if avg_power >= 1500:
        # 高ランク: 金の宝箱系
        chest_names = ["金の宝箱", "装備宝箱・大", "チケット宝箱"]
        weights = [40, 40, 20]
    elif avg_power >= 500:
        # 中ランク: 銀の宝箱系
        chest_names = ["銀の宝箱", "装備宝箱・中", "チケット宝箱"]
        weights = [40, 40, 20]
    else:
        # 低ランク: 普通の宝箱系
        chest_names = ["普通の宝箱", "装備宝箱・小", "チケット宝箱"]
        weights = [50, 35, 15]

    chosen_name = random.choices(chest_names, weights=weights, k=1)[0]

    result = await db.execute(
        select(ItemTemplate).where(ItemTemplate.name == chosen_name)
    )
    chest_template = result.scalar_one_or_none()
    if not chest_template:
        return None

    # 既に所持していたらquantity追加（重複行があれば集約）、なければ新規
    result = await db.execute(
        select(UserItem).where(
            UserItem.user_id == user_id,
            UserItem.item_template_id == chest_template.id,
        )
    )
    existing_rows = result.scalars().all()
    if existing_rows:
        for extra in existing_rows[1:]:
            existing_rows[0].quantity += extra.quantity
            await db.delete(extra)
        existing_rows[0].quantity += 1
    else:
        db.add(UserItem(
            user_id=user_id,
            item_template_id=chest_template.id,
            quantity=1,
        ))

    return chest_template.name


@router.get("", response_model=list[TournamentResponse])
async def list_tournaments(user: CurrentUser, db: DbSession):
    """トーナメント一覧"""
    result = await db.execute(select(Tournament).order_by(Tournament.created_at.desc()).limit(20))
    tournaments = result.scalars().all()

    # 自分のエントリーをtournament_idでマップ
    my_entries: dict[str, str] = {}
    for t in tournaments:
        for e in t.entries:
            if e.user_id == user.id and e.character and e.character.template:
                my_entries[t.id] = e.character.template.name
                break

    return [
        TournamentResponse(
            id=t.id,
            name=t.name,
            status=t.status,
            max_participants=t.max_participants,
            current_participants=len(t.entries),
            current_round=t.current_round,
            entry_cost_type=t.entry_cost_type,
            entry_cost_amount=t.entry_cost_amount,
            reward_points=t.reward_points,
            created_at=t.created_at,
            my_character_name=my_entries.get(t.id),
        )
        for t in tournaments
    ]


@router.get("/active-character-ids")
async def get_active_character_ids(user: CurrentUser, db: DbSession):
    """アクティブなトーナメントに出場中の自分のキャラクターIDリストを返す"""
    result = await db.execute(
        select(TournamentEntry.character_id)
        .join(Tournament, TournamentEntry.tournament_id == Tournament.id)
        .where(
            TournamentEntry.user_id == user.id,
            Tournament.status.in_(["recruiting", "in_progress"]),
        )
    )
    return {"character_ids": [row[0] for row in result.all()]}


@router.post("", response_model=TournamentResponse)
async def create_tournament(
    name: str = "トーナメント",
    max_participants: int = 8,
    reward_points: int = 200,
    user: CurrentUser = None,
    db: DbSession = None,
):
    """トーナメント作成 (誰でも作成可能)"""
    if max_participants not in (4, 8, 16):
        raise HTTPException(status_code=400, detail="Participants must be 4, 8, or 16")

    tournament = Tournament(
        name=name,
        max_participants=max_participants,
        reward_points=reward_points,
    )
    db.add(tournament)
    await db.flush()

    return TournamentResponse(
        id=tournament.id,
        name=tournament.name,
        status=tournament.status,
        max_participants=tournament.max_participants,
        current_participants=0,
        current_round=tournament.current_round,
        entry_cost_type=tournament.entry_cost_type,
        entry_cost_amount=tournament.entry_cost_amount,
        reward_points=tournament.reward_points,
        created_at=tournament.created_at,
    )


async def _execute_one_round(db, tournament) -> list[BattleResultResponse]:
    """1ラウンド分のバトルを実行してDBに保存。ラウンド進行・終了判定も行う"""
    from app.services.settings_service import get_setting

    matchups = get_round_matchups(tournament.entries, tournament.current_round)
    if not matchups:
        return []

    # ラウンド別ポイントを設定から取得
    round_points_val = await get_setting(db, "tournament_round_points")
    if isinstance(round_points_val, list) and round_points_val:
        round_points_list = [int(p) for p in round_points_val]
    else:
        round_points_list = [50, 100, 200]
    round_idx = tournament.current_round - 1
    points_for_round = round_points_list[min(round_idx, len(round_points_list) - 1)]

    avg_power = _calc_avg_power(tournament.entries)
    results: list[BattleResultResponse] = []

    for entry_a, entry_b in matchups:
        # キャラクターが存在しない場合（FK不整合）はスキップして相手を不戦勝に
        if entry_a.character is None or entry_b.character is None:
            loser = entry_a if entry_a.character is None else entry_b
            loser.eliminated_round = tournament.current_round
            continue
        battle_result = run_battle(entry_a.character, entry_b.character)

        winner_entry = entry_a if battle_result.winner_id == entry_a.character_id else entry_b
        loser_entry = entry_b if winner_entry == entry_a else entry_a
        loser_entry.eliminated_round = tournament.current_round

        # ラウンド勝利ポイントを付与
        if points_for_round > 0 and winner_entry.user:
            winner_entry.user.points += points_for_round

        await _award_treasure_chest(db, winner_entry.user_id, avg_power)

        # 勝者・敗者ともに1EXP付与してレベルアップ判定
        for entry in [winner_entry, loser_entry]:
            if entry.character:
                apply_exp_levelup(entry.character, exp=1)

        log = BattleLog(
            id=battle_result.battle_id,
            tournament_id=tournament.id,
            round=tournament.current_round,
            attacker_id=entry_a.character_id,
            defender_id=entry_b.character_id,
            winner_id=battle_result.winner_id,
            battle_data={"turns": [t.model_dump() for t in battle_result.turns]},
        )
        db.add(log)
        results.append(battle_result)

    remaining = [e for e in tournament.entries if e.eliminated_round is None]
    if len(remaining) <= 1:
        tournament.status = "finished"

        # === 順位報酬 ===
        champion_pts = await get_setting(db, "tournament_champion_points") or 0
        second_pts = await get_setting(db, "tournament_second_points") or 0
        champion_chests = await get_setting(db, "tournament_champion_chests") or 0
        second_chests = await get_setting(db, "tournament_second_chests") or 0

        # 優勝者
        if remaining:
            champ = remaining[0]
            if champ.user and not (champ.user.device_id or "").startswith(NPC_DEVICE_PREFIX):
                champ.user.points += int(champion_pts)
                for _ in range(int(champion_chests)):
                    await _award_treasure_chest(db, champ.user_id, avg_power)

        # 準優勝（最終ラウンドで敗退した人）
        runner_ups = [e for e in tournament.entries
                      if e.eliminated_round == tournament.current_round]
        for runner in runner_ups:
            if runner.user and not (runner.user.device_id or "").startswith(NPC_DEVICE_PREFIX):
                runner.user.points += int(second_pts)
                for _ in range(int(second_chests)):
                    await _award_treasure_chest(db, runner.user_id, avg_power)
    else:
        tournament.current_round += 1

    return results


@router.post("/{tournament_id}/entry")
async def enter_tournament(
    tournament_id: str,
    req: TournamentEntryRequest,
    user: CurrentUser,
    db: DbSession,
):
    """トーナメントに参加。満員になったら全ラウンドを自動実行"""
    result = await db.execute(select(Tournament).where(Tournament.id == tournament_id))
    tournament = result.scalar_one_or_none()
    if not tournament:
        raise HTTPException(status_code=404, detail="トーナメントが見つかりません")
    if tournament.status != "recruiting":
        raise HTTPException(status_code=400, detail="このトーナメントは現在参加募集中ではありません")
    if len(tournament.entries) >= tournament.max_participants:
        raise HTTPException(status_code=400, detail="トーナメントの参加者が満員です")

    # 重複参加チェック
    for entry in tournament.entries:
        if entry.user_id == user.id:
            raise HTTPException(status_code=400, detail="すでにこのトーナメントに参加しています")

    # キャラ確認
    result = await db.execute(
        select(Character).where(Character.id == req.character_id, Character.user_id == user.id)
    )
    char = result.scalar_one_or_none()
    if not char:
        raise HTTPException(status_code=404, detail="キャラクターが見つかりません")

    # キャラがすでに別のアクティブなトーナメントに出場中かチェック
    result = await db.execute(
        select(TournamentEntry)
        .join(Tournament, TournamentEntry.tournament_id == Tournament.id)
        .where(
            TournamentEntry.character_id == req.character_id,
            Tournament.status.in_(["recruiting", "in_progress"]),
            TournamentEntry.tournament_id != tournament_id,
        )
    )
    if result.scalars().first():
        raise HTTPException(status_code=400, detail="このキャラクターはすでに別のトーナメントに出場中です")

    entry = TournamentEntry(
        tournament_id=tournament_id,
        user_id=user.id,
        character_id=req.character_id,
    )
    db.add(entry)
    await db.flush()

    # 満員になったら自動開始・全ラウンド自動実行
    if len(tournament.entries) + 1 >= tournament.max_participants:
        tournament.status = "in_progress"
        tournament.current_round = 1
        await db.flush()
        # character.equipment (selectin) を同期の run_battle から参照するため、
        # populate_existing=True でキャッシュを強制上書きして全関連を再ロードする
        # （8人目のキャラは参加チェック時に equipment なしで identity map に登録済みのため
        #   populate_existing がないと古いキャッシュ＝equipment 未ロードが返ってしまう）
        refreshed = await db.execute(
            select(Tournament)
            .where(Tournament.id == tournament_id)
            .options(
                selectinload(Tournament.entries).options(
                    joinedload(TournamentEntry.character).options(
                        joinedload(Character.template),
                        selectinload(Character.equipment)
                        .joinedload(CharacterEquipment.item_template),
                    ),
                    joinedload(TournamentEntry.user),
                )
            )
            .execution_options(populate_existing=True)
        )
        tournament = refreshed.scalar_one()
        assign_seeds(tournament.entries)
        await db.flush()

        # 全ラウンドを自動実行
        while tournament.status == "in_progress":
            await _execute_one_round(db, tournament)
            await db.flush()

    return {"message": "Entered tournament", "participants": len(tournament.entries) + 1}


@router.get("/{tournament_id}/bracket", response_model=TournamentBracketResponse)
async def get_bracket(tournament_id: str, db: DbSession):
    """トーナメント表"""
    result = await db.execute(select(Tournament).where(Tournament.id == tournament_id))
    tournament = result.scalar_one_or_none()
    if not tournament:
        raise HTTPException(status_code=404, detail="トーナメントが見つかりません")

    # キャラクターIDからプレイヤー名・レアリティを引くルックアップ
    char_info: dict[str, tuple[str, int]] = {}
    for e in tournament.entries:
        pname = e.user.display_name if e.user else "???"
        rarity = e.character.template.rarity if e.character and e.character.template else 1
        char_info[e.character_id] = (pname, rarity)

    entries = [
        TournamentBracketEntry(
            seed=e.seed or 0,
            user_name=e.user.display_name if e.user else "???",
            character_name=e.character.template.name if e.character and e.character.template else "???",
            character_id=e.character_id,
            eliminated_round=e.eliminated_round,
            rarity=e.character.template.rarity if e.character and e.character.template else 1,
        )
        for e in sorted(tournament.entries, key=lambda x: x.seed or 0)
    ]

    battles = [
        TournamentBracketBattle(
            battle_id=b.id,
            round=b.round,
            attacker_name=b.attacker.template.name if b.attacker and b.attacker.template else "???",
            defender_name=b.defender.template.name if b.defender and b.defender.template else "???",
            winner_name=(b.attacker.template.name if b.winner_id == b.attacker_id else b.defender.template.name)
            if b.attacker and b.defender and b.attacker.template and b.defender.template
            else "???",
            attacker_player_name=char_info.get(b.attacker_id, ("???", 1))[0],
            defender_player_name=char_info.get(b.defender_id, ("???", 1))[0],
            attacker_rarity=char_info.get(b.attacker_id, ("???", 1))[1],
            defender_rarity=char_info.get(b.defender_id, ("???", 1))[1],
        )
        for b in sorted(tournament.battles, key=lambda x: x.round)
    ]

    return TournamentBracketResponse(
        tournament_id=tournament.id,
        tournament_name=tournament.name,
        status=tournament.status,
        current_round=tournament.current_round,
        max_participants=tournament.max_participants,
        entries=entries,
        battles=battles,
    )
