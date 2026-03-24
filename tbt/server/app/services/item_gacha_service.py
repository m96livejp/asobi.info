import random

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models.gacha import GachaPool, GachaPoolItemEquip
from app.models.item import UserItem
from app.models.user import User
from app.schemas.gacha import GachaResultItem


async def pull_item_gacha(
    db: AsyncSession,
    user: User,
    pool: GachaPool,
    count: int,
) -> list[GachaResultItem]:
    """アイテムガチャを引く"""
    result = await db.execute(
        select(GachaPoolItemEquip).where(GachaPoolItemEquip.pool_id == pool.id)
    )
    pool_items = result.scalars().all()
    if not pool_items:
        return []

    # 所持アイテムテンプレートIDセット
    result = await db.execute(
        select(UserItem.item_template_id).where(UserItem.user_id == user.id)
    )
    owned_items = set(result.scalars().all())

    results: list[GachaResultItem] = []

    for _ in range(count):
        # 重み付きランダム
        total_weight = sum(item.weight for item in pool_items)
        r = random.uniform(0, total_weight)
        cumulative = 0
        picked = pool_items[-1]
        for item in pool_items:
            cumulative += item.weight
            if r <= cumulative:
                picked = item
                break

        template = picked.item_template

        # 所持アイテムに追加 (装備は個別、消費はquantity加算)
        existing_result = await db.execute(
            select(UserItem).where(
                UserItem.user_id == user.id,
                UserItem.item_template_id == template.id,
            )
        )
        existing = existing_result.scalar_one_or_none()

        if template.item_type == "equipment":
            # 装備品は常に新規追加
            db.add(UserItem(
                user_id=user.id,
                item_template_id=template.id,
                quantity=1,
            ))
        else:
            if existing:
                existing.quantity += 1
            else:
                db.add(UserItem(
                    user_id=user.id,
                    item_template_id=template.id,
                    quantity=1,
                ))

        is_new = template.id not in owned_items
        owned_items.add(template.id)

        results.append(GachaResultItem(
            template_id=template.id,
            name=template.name,
            rarity=template.rarity,
            is_new=is_new,
        ))

    return results
