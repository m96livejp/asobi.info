import random
import uuid

from sqlalchemy import select, func
from sqlalchemy.ext.asyncio import AsyncSession

from app.models.character import Character, CharacterTemplate
from app.models.gacha import GachaPool, GachaPoolItem, GachaHistory
from app.models.user import User
from app.schemas.gacha import GachaResultItem


async def get_pity_count(db: AsyncSession, user_id: str, pool_id: int) -> int:
    """天井カウント: 直近のSSR以上排出からの回数"""
    result = await db.execute(
        select(func.count()).select_from(GachaHistory).where(
            GachaHistory.user_id == user_id,
            GachaHistory.pool_id == pool_id,
        )
    )
    total = result.scalar() or 0

    # 最後のSSR以上の位置を探す
    result = await db.execute(
        select(GachaHistory).where(
            GachaHistory.user_id == user_id,
            GachaHistory.pool_id == pool_id,
            GachaHistory.rarity >= 4,
        ).order_by(GachaHistory.id.desc()).limit(1)
    )
    last_ssr = result.scalar_one_or_none()

    if last_ssr is None:
        return total  # SSR出てない = 全部カウント

    # 最後のSSR以降のカウント
    result = await db.execute(
        select(func.count()).select_from(GachaHistory).where(
            GachaHistory.user_id == user_id,
            GachaHistory.pool_id == pool_id,
            GachaHistory.id > last_ssr.id,
        )
    )
    return result.scalar() or 0


def pick_item(items: list[GachaPoolItem], force_ssr: bool = False) -> GachaPoolItem:
    """重み付きランダムで1つ選ぶ。天井の場合はSSR以上を強制"""
    if force_ssr:
        ssr_items = [item for item in items if item.template.rarity >= 4]
        if ssr_items:
            items = ssr_items

    total_weight = sum(item.weight for item in items)
    r = random.uniform(0, total_weight)
    cumulative = 0
    for item in items:
        cumulative += item.weight
        if r <= cumulative:
            return item
    return items[-1]


async def pull_gacha(
    db: AsyncSession,
    user: User,
    pool: GachaPool,
    count: int,
) -> tuple[list[GachaResultItem], int]:
    """ガチャを引く。天井管理含む"""
    # 所持キャラのテンプレートIDセット
    result = await db.execute(
        select(Character.template_id).where(Character.user_id == user.id)
    )
    owned_templates = set(result.scalars().all())

    pity_count = await get_pity_count(db, user.id, pool.id)
    results: list[GachaResultItem] = []

    for _ in range(count):
        pity_count += 1
        force_ssr = pity_count >= pool.pity_count

        picked = pick_item(pool.items, force_ssr=force_ssr)

        if force_ssr and picked.template.rarity >= 4:
            pity_count = 0  # 天井リセット

        # キャラクターを所持に追加 (種族ランダム決定)
        race = random.choice(["warrior", "mage", "beastman"])
        new_char = Character(
            id=str(uuid.uuid4()),
            user_id=user.id,
            template_id=picked.template_id,
            race=race,
            hp=picked.template.base_hp,
            atk=picked.template.base_atk,
            def_=picked.template.base_def,
            spd=picked.template.base_spd,
        )
        db.add(new_char)

        # 履歴記録
        history = GachaHistory(
            user_id=user.id,
            pool_id=pool.id,
            template_id=picked.template_id,
            rarity=picked.template.rarity,
        )
        db.add(history)

        is_new = picked.template_id not in owned_templates
        owned_templates.add(picked.template_id)

        results.append(GachaResultItem(
            template_id=picked.template_id,
            name=picked.template.name,
            rarity=picked.template.rarity,
            is_new=is_new,
        ))

    return results, pity_count
