import random

from fastapi import APIRouter, HTTPException, status
from sqlalchemy import select

from app.deps import CurrentUser, DbSession
from app.models.item import ItemTemplate, UserItem
from app.models.character import CharacterEquipment
from app.schemas.item import (
    ItemTemplateResponse,
    UserItemResponse,
    UseItemResponse,
    EquipRequest,
    UnequipRequest,
    EquipmentSlotResponse,
    CharacterEquipmentResponse,
)

router = APIRouter()

VALID_SLOTS = ["weapon1", "weapon2", "head", "body", "hands", "feet", "accessory1", "accessory2", "accessory3"]


@router.get("", response_model=list[UserItemResponse])
async def list_items(user: CurrentUser, db: DbSession):
    """所持アイテム一覧"""
    result = await db.execute(
        select(UserItem).where(UserItem.user_id == user.id).order_by(UserItem.id.desc())
    )
    items = result.scalars().all()
    return [
        UserItemResponse(
            id=item.id,
            item_template_id=item.item_template_id,
            name=item.item_template.name,
            item_type=item.item_template.item_type,
            rarity=item.item_template.rarity,
            description=item.item_template.description,
            quantity=item.quantity,
            equip_slot=item.item_template.equip_slot,
            equip_race=item.item_template.equip_race,
            bonus_hp=item.item_template.bonus_hp,
            bonus_atk=item.item_template.bonus_atk,
            bonus_def=item.item_template.bonus_def,
            bonus_spd=item.item_template.bonus_spd,
            effect_name=item.item_template.effect_name,
            effect_value=item.item_template.effect_value,
        )
        for item in items
    ]


@router.get("/templates", response_model=list[ItemTemplateResponse])
async def list_templates(db: DbSession):
    """アイテムマスタ一覧"""
    result = await db.execute(select(ItemTemplate).order_by(ItemTemplate.id))
    return result.scalars().all()


@router.post("/{item_id}/use", response_model=UseItemResponse)
async def use_item(item_id: int, user: CurrentUser, db: DbSession):
    """アイテムを使用（宝箱を開ける等）"""
    result = await db.execute(
        select(UserItem).where(UserItem.id == item_id, UserItem.user_id == user.id)
    )
    user_item = result.scalar_one_or_none()
    if not user_item:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Item not found")

    template = user_item.item_template
    response = UseItemResponse(message="")

    if template.item_type == "treasure_pt":
        # PT宝箱: ランダムPT付与
        pt = random.randint(template.min_value, template.max_value)
        user.points += pt
        response.message = f"{pt}PTを獲得しました！"
        response.reward_type = "pt"
        response.reward_value = pt

    elif template.item_type == "treasure_ticket":
        # チケット宝箱: 通常ガチャチケット付与
        tickets = random.randint(template.min_value, template.max_value)
        user.normal_gacha_tickets += tickets
        response.message = f"通常ガチャチケットを{tickets}枚獲得しました！"
        response.reward_type = "ticket"
        response.reward_value = tickets

    elif template.item_type == "treasure_equip":
        # 装備宝箱: ランダム装備品を所持品に追加
        min_rarity = template.min_value
        max_rarity = template.max_value
        equip_result = await db.execute(
            select(ItemTemplate).where(
                ItemTemplate.item_type == "equipment",
                ItemTemplate.rarity >= min_rarity,
                ItemTemplate.rarity <= max_rarity,
            )
        )
        equip_candidates = equip_result.scalars().all()
        if equip_candidates:
            chosen = random.choice(equip_candidates)
            # 重複行が存在する場合は集約してquantityを加算、なければ新規作成
            existing_result = await db.execute(
                select(UserItem).where(
                    UserItem.user_id == user.id,
                    UserItem.item_template_id == chosen.id,
                )
            )
            existing_rows = existing_result.scalars().all()
            if existing_rows:
                for extra in existing_rows[1:]:
                    existing_rows[0].quantity += extra.quantity
                    await db.delete(extra)
                existing_rows[0].quantity += 1
            else:
                db.add(UserItem(user_id=user.id, item_template_id=chosen.id, quantity=1))
            response.message = f"{chosen.name}を獲得しました！"
            response.reward_type = "equipment"
            response.reward_item_name = chosen.name
        else:
            response.message = "装備品が見つかりませんでした"

    else:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="This item cannot be used")

    # 使用したアイテムを減らす
    if user_item.quantity > 1:
        user_item.quantity -= 1
    else:
        await db.delete(user_item)

    await db.flush()
    return response


@router.get("/equipment/{character_id}", response_model=CharacterEquipmentResponse)
async def get_equipment(character_id: str, user: CurrentUser, db: DbSession):
    """キャラクターの装備一覧"""
    from app.models.character import Character
    char_result = await db.execute(
        select(Character).where(Character.id == character_id, Character.user_id == user.id)
    )
    char = char_result.scalar_one_or_none()
    if not char:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Character not found")

    result = await db.execute(
        select(CharacterEquipment).where(CharacterEquipment.character_id == character_id)
    )
    equips = result.scalars().all()

    slots = []
    total_hp = total_atk = total_def = total_spd = 0
    for eq in equips:
        t = eq.item_template
        slots.append(EquipmentSlotResponse(
            slot=eq.slot,
            item_template_id=t.id,
            name=t.name,
            rarity=t.rarity,
            bonus_hp=t.bonus_hp,
            bonus_atk=t.bonus_atk,
            bonus_def=t.bonus_def,
            bonus_spd=t.bonus_spd,
            effect_name=t.effect_name,
            effect_value=t.effect_value,
        ))
        total_hp += t.bonus_hp
        total_atk += t.bonus_atk
        total_def += t.bonus_def
        total_spd += t.bonus_spd

    return CharacterEquipmentResponse(
        slots=slots,
        total_bonus_hp=total_hp,
        total_bonus_atk=total_atk,
        total_bonus_def=total_def,
        total_bonus_spd=total_spd,
    )


@router.post("/equipment/{character_id}/equip")
async def equip_item(character_id: str, req: EquipRequest, user: CurrentUser, db: DbSession):
    """装備する"""
    from app.models.character import Character

    if req.slot not in VALID_SLOTS:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=f"Invalid slot: {req.slot}")

    # キャラ確認
    char_result = await db.execute(
        select(Character).where(Character.id == character_id, Character.user_id == user.id)
    )
    char = char_result.scalar_one_or_none()
    if not char:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Character not found")

    # アイテム確認
    item_result = await db.execute(select(ItemTemplate).where(ItemTemplate.id == req.item_template_id))
    item_template = item_result.scalar_one_or_none()
    if not item_template or item_template.item_type != "equipment":
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Invalid equipment")

    # 種族チェック
    if item_template.equip_race != "all" and item_template.equip_race != char.race:
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=f"This equipment is for {item_template.equip_race} only")

    # スロット互換性チェック
    slot_type = item_template.equip_slot  # weapon_1h, weapon_2h, shield, head, body, hands, feet, accessory
    if not _is_slot_compatible(req.slot, slot_type):
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail=f"Cannot equip {slot_type} in slot {req.slot}")

    # 両手武器の場合、weapon1とweapon2の両方を占有
    if slot_type == "weapon_2h":
        # weapon1とweapon2の既存装備を全て外す
        for s in ["weapon1", "weapon2"]:
            existing = await db.execute(
                select(CharacterEquipment).where(
                    CharacterEquipment.character_id == character_id,
                    CharacterEquipment.slot == s,
                )
            )
            for old in existing.scalars().all():
                await db.delete(old)
        # weapon1に装備
        db.add(CharacterEquipment(character_id=character_id, slot="weapon1", item_template_id=req.item_template_id))
        db.add(CharacterEquipment(character_id=character_id, slot="weapon2", item_template_id=req.item_template_id))
    else:
        # 既存装備を全て外す（重複行も含め）
        existing = await db.execute(
            select(CharacterEquipment).where(
                CharacterEquipment.character_id == character_id,
                CharacterEquipment.slot == req.slot,
            )
        )
        for old in existing.scalars().all():
            await db.delete(old)

        # 片手武器/盾をweapon1/2に装備する場合、両手武器チェック
        if req.slot in ["weapon1", "weapon2"]:
            other_slot = "weapon2" if req.slot == "weapon1" else "weapon1"
            other_result = await db.execute(
                select(CharacterEquipment).where(
                    CharacterEquipment.character_id == character_id,
                    CharacterEquipment.slot == other_slot,
                )
            )
            for other in other_result.scalars().all():
                if other.item_template and other.item_template.equip_slot == "weapon_2h":
                    await db.delete(other)

        db.add(CharacterEquipment(character_id=character_id, slot=req.slot, item_template_id=req.item_template_id))

    # 所持品から1つ減らす
    inv_result = await db.execute(
        select(UserItem).where(
            UserItem.user_id == user.id,
            UserItem.item_template_id == req.item_template_id,
        )
    )
    inv_items = inv_result.scalars().all()
    if inv_items:
        # 重複行がある場合は合計数量を計算して最初の行に集約
        total_qty = sum(i.quantity for i in inv_items)
        for i in inv_items[1:]:
            await db.delete(i)
        if total_qty > 1:
            inv_items[0].quantity = total_qty - 1
        else:
            await db.delete(inv_items[0])

    await db.flush()
    return {"message": f"{item_template.name}を装備しました"}


@router.post("/equipment/{character_id}/unequip")
async def unequip_item(character_id: str, req: UnequipRequest, user: CurrentUser, db: DbSession):
    """装備を外す"""
    from app.models.character import Character

    char_result = await db.execute(
        select(Character).where(Character.id == character_id, Character.user_id == user.id)
    )
    if not char_result.scalar_one_or_none():
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Character not found")

    result = await db.execute(
        select(CharacterEquipment).where(
            CharacterEquipment.character_id == character_id,
            CharacterEquipment.slot == req.slot,
        )
    )
    equips = result.scalars().all()
    if not equips:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="No equipment in this slot")

    equip = equips[0]
    item_template = equip.item_template

    # 両手武器の場合、両スロット全て解除
    if item_template and item_template.equip_slot == "weapon_2h":
        for s in ["weapon1", "weapon2"]:
            r = await db.execute(
                select(CharacterEquipment).where(
                    CharacterEquipment.character_id == character_id,
                    CharacterEquipment.slot == s,
                )
            )
            for e in r.scalars().all():
                await db.delete(e)
    else:
        for e in equips:
            await db.delete(e)

    # 所持品に戻す（重複行があれば集約してquantityを加算）
    inv_result = await db.execute(
        select(UserItem).where(
            UserItem.user_id == user.id,
            UserItem.item_template_id == item_template.id,
        )
    )
    inv_rows = inv_result.scalars().all()
    if inv_rows:
        for extra in inv_rows[1:]:
            inv_rows[0].quantity += extra.quantity
            await db.delete(extra)
        inv_rows[0].quantity += 1
    else:
        db.add(UserItem(user_id=user.id, item_template_id=item_template.id, quantity=1))

    await db.flush()
    return {"message": f"{item_template.name}を外しました"}


def _is_slot_compatible(slot: str, equip_slot: str) -> bool:
    """スロットと装備タイプの互換性チェック"""
    mapping = {
        "weapon1": ["weapon_1h", "weapon_2h"],
        "weapon2": ["weapon_1h", "shield"],
        "head": ["head"],
        "body": ["body"],
        "hands": ["hands"],
        "feet": ["feet"],
        "accessory1": ["accessory"],
        "accessory2": ["accessory"],
        "accessory3": ["accessory"],
    }
    return equip_slot in mapping.get(slot, [])
