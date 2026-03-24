from fastapi import APIRouter, HTTPException, status
from sqlalchemy import select

from app.deps import CurrentUser, DbSession
from app.models.character import Character, CharacterTemplate, CharacterEquipment
from app.models.item import UserItem
from app.models.battle import Tournament, TournamentEntry
from app.schemas.character import CharacterResponse, CharacterTemplateResponse, TrainRequest, TrainResponse, FavoriteResponse

router = APIRouter()

RARITY_NAMES = {1: "N", 2: "R", 3: "SR", 4: "SSR", 5: "UR"}


def character_to_response(char: Character) -> CharacterResponse:
    return CharacterResponse(
        id=char.id,
        template_id=char.template_id,
        template_name=char.template.name if char.template else "",
        template_rarity=char.template.rarity if char.template else 0,
        race=char.race,
        level=char.level,
        exp=char.exp,
        is_favorite=char.is_favorite,
        hp=char.hp,
        atk=char.atk,
        def_=char.def_,
        spd=char.spd,
        skill_name=char.template.skill_name if char.template else "",
        skill_power=char.template.skill_power if char.template else 1.0,
        created_at=char.created_at,
    )


def required_exp(level: int) -> int:
    """レベルアップに必要な経験値 (3^level: Lv1→2は3, Lv2→3は9, ...)"""
    return 3 ** level


def apply_exp_levelup(char: Character, exp: int = 1) -> bool:
    """EXPを付与してレベルアップ処理。レベルアップしたらTrueを返す"""
    leveled_up = False
    char.exp += exp
    while char.exp >= required_exp(char.level):
        char.exp -= required_exp(char.level)
        char.level += 1
        growth = 1.0 + (char.template.rarity * 0.02) if char.template else 1.02
        char.hp = int(char.hp * growth)
        char.atk = int(char.atk * growth)
        char.def_ = int(char.def_ * growth)
        char.spd = int(char.spd * growth)
        leveled_up = True
    return leveled_up


@router.get("/templates", response_model=list[CharacterTemplateResponse])
async def list_templates(db: DbSession):
    """全キャラクターテンプレート一覧"""
    result = await db.execute(select(CharacterTemplate).order_by(CharacterTemplate.rarity, CharacterTemplate.id))
    return [CharacterTemplateResponse.model_validate(t) for t in result.scalars().all()]


@router.get("", response_model=list[CharacterResponse])
async def list_characters(user: CurrentUser, db: DbSession):
    """所持キャラクター一覧"""
    result = await db.execute(
        select(Character).where(Character.user_id == user.id).order_by(Character.created_at.desc())
    )
    return [character_to_response(c) for c in result.scalars().all()]


@router.get("/{character_id}", response_model=CharacterResponse)
async def get_character(character_id: str, user: CurrentUser, db: DbSession):
    """キャラクター詳細"""
    result = await db.execute(
        select(Character).where(Character.id == character_id, Character.user_id == user.id)
    )
    char = result.scalar_one_or_none()
    if char is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Character not found")
    return character_to_response(char)


@router.post("/{character_id}/favorite", response_model=FavoriteResponse)
async def toggle_favorite(character_id: str, user: CurrentUser, db: DbSession):
    """お気に入り登録/解除のトグル"""
    result = await db.execute(
        select(Character).where(Character.id == character_id, Character.user_id == user.id)
    )
    char = result.scalar_one_or_none()
    if char is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="キャラクターが見つかりません")

    char.is_favorite = not char.is_favorite
    await db.flush()
    return FavoriteResponse(character_id=char.id, is_favorite=char.is_favorite)


@router.post("/{character_id}/train", response_model=TrainResponse)
async def train_character(character_id: str, req: TrainRequest, user: CurrentUser, db: DbSession):
    """特訓: 兵士を消費して経験値を獲得。兵士の装備は所持品に戻る"""
    # 特訓対象キャラ
    result = await db.execute(
        select(Character).where(Character.id == character_id, Character.user_id == user.id)
    )
    char = result.scalar_one_or_none()
    if char is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="キャラクターが見つかりません")

    # 兵士キャラ
    if req.soldier_id == character_id:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="同じキャラクターは使えません")

    soldier_result = await db.execute(
        select(Character).where(Character.id == req.soldier_id, Character.user_id == user.id)
    )
    soldier = soldier_result.scalar_one_or_none()
    if soldier is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="兵士キャラクターが見つかりません")

    # 大会参加中チェック
    active_check = await db.execute(
        select(TournamentEntry.character_id)
        .join(Tournament, TournamentEntry.tournament_id == Tournament.id)
        .where(
            TournamentEntry.character_id == req.soldier_id,
            Tournament.status.in_(["recruiting", "in_progress"]),
        )
    )
    if active_check.scalars().first() is not None:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="大会参加中のキャラクターは特訓に使えません",
        )

    # お気に入りチェック
    if soldier.is_favorite:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="お気に入り登録中のキャラクターは特訓に使えません",
        )

    # 兵士の装備を全て外して所持品に戻す
    equip_result = await db.execute(
        select(CharacterEquipment).where(CharacterEquipment.character_id == req.soldier_id)
    )
    soldier_equips = equip_result.scalars().all()

    returned_items: dict[int, int] = {}  # item_template_id -> 返却数
    returned_names: list[str] = []
    for equip in soldier_equips:
        t = equip.item_template
        if t and t.equip_slot == "weapon_2h":
            # 両手武器は weapon1/weapon2 両スロットに入るが1個として返す
            if equip.slot == "weapon1":
                returned_items[t.id] = returned_items.get(t.id, 0) + 1
                returned_names.append(t.name)
        else:
            returned_items[t.id] = returned_items.get(t.id, 0) + 1
            returned_names.append(t.name)
        await db.delete(equip)

    # 所持品に追加（重複行があれば集約）
    for template_id, count in returned_items.items():
        inv_result = await db.execute(
            select(UserItem).where(
                UserItem.user_id == user.id,
                UserItem.item_template_id == template_id,
            )
        )
        inv_items = inv_result.scalars().all()
        if inv_items:
            inv_items[0].quantity += count
            for extra in inv_items[1:]:
                inv_items[0].quantity += extra.quantity
                await db.delete(extra)
        else:
            db.add(UserItem(user_id=user.id, item_template_id=template_id, quantity=count))

    # EXP計算: 兵士の現在EXP(最低1) + レアリティ固定ボーナス
    # N:+1, R:+5, SR:+10, SSR:+25, UR:+50
    rarity = soldier.template.rarity if soldier.template else 1
    rarity_bonus = {1: 1, 2: 5, 3: 10, 4: 25, 5: 50}.get(rarity, 1)
    exp_gained = max(1, soldier.exp) + rarity_bonus

    # 兵士を削除
    await db.delete(soldier)

    # 特訓EXP付与・レベルアップ判定
    old_level = char.level
    leveled_up = apply_exp_levelup(char, exp=exp_gained)

    await db.flush()

    return TrainResponse(
        character_id=char.id,
        leveled_up=leveled_up,
        old_level=old_level,
        new_level=char.level,
        exp=char.exp,
        exp_gained=exp_gained,
        hp=char.hp,
        atk=char.atk,
        def_=char.def_,
        spd=char.spd,
        returned_items=returned_names,
    )
