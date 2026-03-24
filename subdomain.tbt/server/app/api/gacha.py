from fastapi import APIRouter, HTTPException, status
from sqlalchemy import select, func

from app.deps import CurrentUser, DbSession
from app.models.gacha import GachaPool, GachaPoolItemEquip, GachaHistory
from app.schemas.gacha import (
    GachaPoolResponse,
    GachaPullRequest,
    GachaPullResponse,
    GachaHistoryItem,
    GachaHistoryResponse,
)
from app.services.gacha_service import pull_gacha, get_pity_count
from app.services.item_gacha_service import pull_item_gacha

router = APIRouter()

RARITY_NAMES = {1: "N", 2: "R", 3: "SR", 4: "SSR", 5: "UR"}


@router.get("/pools", response_model=list[GachaPoolResponse])
async def list_pools(db: DbSession):
    """アクティブなガチャプール一覧 (確率表示付き)"""
    result = await db.execute(select(GachaPool).where(GachaPool.is_active == 1))
    pools = result.scalars().all()

    responses = []
    for pool in pools:
        rates: dict[str, float] = {}

        if pool.pool_type == "item":
            # アイテムガチャ: item_templates からレアリティ計算
            result = await db.execute(
                select(GachaPoolItemEquip).where(GachaPoolItemEquip.pool_id == pool.id)
            )
            equip_items = result.scalars().all()
            total_weight = sum(item.weight for item in equip_items)
            if total_weight > 0:
                rarity_weights: dict[int, int] = {}
                for item in equip_items:
                    rarity = item.item_template.rarity
                    rarity_weights[rarity] = rarity_weights.get(rarity, 0) + item.weight
                for rarity, weight in sorted(rarity_weights.items()):
                    rates[RARITY_NAMES.get(rarity, str(rarity))] = round(weight / total_weight * 100, 2)
        else:
            # キャラガチャ: character_templates からレアリティ計算
            total_weight = sum(item.weight for item in pool.items)
            rarity_weights: dict[int, int] = {}
            for item in pool.items:
                rarity = item.template.rarity
                rarity_weights[rarity] = rarity_weights.get(rarity, 0) + item.weight
            for rarity, weight in sorted(rarity_weights.items()):
                rates[RARITY_NAMES.get(rarity, str(rarity))] = round(weight / total_weight * 100, 2)

        responses.append(GachaPoolResponse(
            id=pool.id,
            name=pool.name,
            description=pool.description,
            pool_type=pool.pool_type,
            cost_type=pool.cost_type,
            cost_amount=pool.cost_amount,
            pity_count=pool.pity_count,
            rates=rates,
        ))

    return responses


@router.post("/pull", response_model=GachaPullResponse)
async def pull(req: GachaPullRequest, user: CurrentUser, db: DbSession):
    """ガチャを引く (1回 or 10連)"""
    if req.count not in (1, 10):
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Count must be 1 or 10")

    result = await db.execute(select(GachaPool).where(GachaPool.id == req.pool_id, GachaPool.is_active == 1))
    pool = result.scalar_one_or_none()
    if not pool:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Gacha pool not found")

    total_cost = pool.cost_amount * req.count

    # チケット or 通貨で消費
    if req.use_ticket:
        if pool.pool_type == "item":
            # 装備ガチャ専用チケット
            if user.item_gacha_tickets < req.count:
                raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="装備ガチャチケットが不足しています")
            user.item_gacha_tickets -= req.count
        elif pool.cost_type == "points":
            if user.normal_gacha_tickets < req.count:
                raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="通常ガチャチケットが不足しています")
            user.normal_gacha_tickets -= req.count
        elif pool.cost_type == "premium":
            if user.premium_gacha_tickets < req.count:
                raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="プレミアムガチャチケットが不足しています")
            user.premium_gacha_tickets -= req.count
    else:
        if pool.cost_type == "points":
            if user.points < total_cost:
                raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="ポイントが不足しています")
            user.points -= total_cost
        elif pool.cost_type == "premium":
            if user.premium_currency < total_cost:
                raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="GEMが不足しています")
            user.premium_currency -= total_cost

    if pool.pool_type == "item":
        results = await pull_item_gacha(db, user, pool, req.count)
        pity_after = 0  # アイテムガチャは天井なし
    else:
        results, pity_after = await pull_gacha(db, user, pool, req.count)

    await db.flush()

    return GachaPullResponse(
        results=results,
        remaining_points=user.points,
        remaining_premium=user.premium_currency,
        remaining_normal_tickets=user.normal_gacha_tickets,
        remaining_premium_tickets=user.premium_gacha_tickets,
        remaining_item_gacha_tickets=user.item_gacha_tickets,
        pity_counter=pool.pity_count - pity_after,
    )


@router.get("/history", response_model=GachaHistoryResponse)
async def get_history(pool_id: int, user: CurrentUser, db: DbSession):
    """ガチャ履歴"""
    result = await db.execute(
        select(GachaHistory).where(
            GachaHistory.user_id == user.id,
            GachaHistory.pool_id == pool_id,
        ).order_by(GachaHistory.id.desc()).limit(100)
    )
    history = result.scalars().all()

    count_result = await db.execute(
        select(func.count()).select_from(GachaHistory).where(
            GachaHistory.user_id == user.id,
            GachaHistory.pool_id == pool_id,
        )
    )
    total = count_result.scalar() or 0

    return GachaHistoryResponse(
        history=[
            GachaHistoryItem(
                template_id=h.template_id,
                name=h.template.name if h.template else "",
                rarity=h.rarity,
                created_at=h.created_at,
            )
            for h in history
        ],
        total_pulls=total,
    )
