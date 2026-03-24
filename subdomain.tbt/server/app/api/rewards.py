from fastapi import APIRouter, HTTPException

from app.config import settings
from app.deps import CurrentUser, DbSession
from app.schemas.shop import AdRewardRequest, AdRewardResponse
from app.services.reward_service import grant_ad_reward, get_daily_ad_count, get_hourly_ad_count

router = APIRouter()


@router.get("/ad/status")
async def get_ad_status(user: CurrentUser, db: DbSession):
    """広告視聴の残り回数を返す（視聴前の事前チェック用）"""
    daily_count = await get_daily_ad_count(db, user.id)
    hourly_count = await get_hourly_ad_count(db, user.id)
    daily_remaining = max(0, settings.AD_REWARD_DAILY_LIMIT - daily_count)
    hourly_remaining = max(0, settings.AD_REWARD_HOURLY_LIMIT - hourly_count)
    return {
        "daily_remaining": daily_remaining,
        "hourly_remaining": hourly_remaining,
        "can_watch": daily_remaining > 0 and hourly_remaining > 0,
    }


@router.post("/ad", response_model=AdRewardResponse)
async def claim_ad_reward(req: AdRewardRequest, user: CurrentUser, db: DbSession):
    """広告視聴完了後に報酬を付与"""
    amount, daily_remaining, hourly_remaining = await grant_ad_reward(db, user, req.reward_type, req.ad_type)

    if amount == 0:
        if hourly_remaining == 0 and daily_remaining > 0:
            raise HTTPException(status_code=400, detail="1時間あたりの広告視聴上限に達しました。しばらく待ってからお試しください。")
        raise HTTPException(status_code=400, detail="本日の広告視聴上限に達しました。")

    await db.flush()

    return AdRewardResponse(
        reward_type=req.reward_type,
        reward_amount=amount,
        remaining_daily_views=daily_remaining,
        remaining_hourly_views=hourly_remaining,
        points=user.points,
        stamina=user.stamina,
    )
