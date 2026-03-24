from datetime import datetime, timezone, timedelta

from sqlalchemy import select, func
from sqlalchemy.ext.asyncio import AsyncSession

from app.config import settings
from app.models.user import User, AdReward


async def get_daily_ad_count(db: AsyncSession, user_id: str) -> int:
    """今日の広告視聴回数"""
    today_start = datetime.now(timezone.utc).replace(hour=0, minute=0, second=0, microsecond=0)
    result = await db.execute(
        select(func.count()).select_from(AdReward).where(
            AdReward.user_id == user_id,
            AdReward.created_at >= today_start,
        )
    )
    return result.scalar() or 0


async def get_hourly_ad_count(db: AsyncSession, user_id: str) -> int:
    """直近1時間の広告視聴回数"""
    one_hour_ago = datetime.now(timezone.utc) - timedelta(hours=1)
    result = await db.execute(
        select(func.count()).select_from(AdReward).where(
            AdReward.user_id == user_id,
            AdReward.created_at >= one_hour_ago,
        )
    )
    return result.scalar() or 0


async def grant_ad_reward(
    db: AsyncSession, user: User, reward_type: str, ad_type: str
) -> tuple[int, int, int]:
    """広告報酬を付与。(付与量, 日残り回数, 時間残り回数) を返す"""
    daily_count = await get_daily_ad_count(db, user.id)
    daily_remaining = settings.AD_REWARD_DAILY_LIMIT - daily_count

    if daily_remaining <= 0:
        return 0, 0, 0

    hourly_count = await get_hourly_ad_count(db, user.id)
    hourly_remaining = settings.AD_REWARD_HOURLY_LIMIT - hourly_count

    if hourly_remaining <= 0:
        return 0, daily_remaining, 0

    if reward_type == "points":
        amount = settings.AD_REWARD_POINTS
        user.points += amount
    elif reward_type == "stamina":
        amount = 20
        user.stamina = min(settings.MAX_STAMINA, user.stamina + amount)
    elif reward_type == "gacha_ticket":
        amount = 1
        user.points += 100  # ガチャチケット = 100ポイント相当
    else:
        amount = settings.AD_REWARD_POINTS
        user.points += amount

    reward = AdReward(
        user_id=user.id,
        reward_type=reward_type,
        reward_amount=amount,
        ad_type=ad_type,
    )
    db.add(reward)

    return amount, daily_remaining - 1, hourly_remaining - 1
