from fastapi import APIRouter
from sqlalchemy import select, func

from app.deps import CurrentUser, DbSession
from app.models.user import User
from app.schemas.user import UserResponse, UserUpdateRequest

router = APIRouter()

NPC_DEVICE_PREFIX = "npc_"


@router.get("/me", response_model=UserResponse)
async def get_profile(user: CurrentUser):
    """ログインユーザーの情報を返す"""
    return UserResponse.model_validate(user)


@router.patch("/me", response_model=UserResponse)
async def update_profile(req: UserUpdateRequest, user: CurrentUser, db: DbSession):
    """表示名の更新"""
    if req.display_name is not None:
        user.display_name = req.display_name
    await db.flush()
    return UserResponse.model_validate(user)


@router.get("/ranking")
async def get_ranking(user: CurrentUser, db: DbSession, limit: int = 50):
    """ポイントランキング（NPC除外、上位N名）"""
    result = await db.execute(
        select(User)
        .where(~User.device_id.like(f"{NPC_DEVICE_PREFIX}%"))
        .order_by(User.points.desc())
        .limit(limit)
    )
    top_users = result.scalars().all()

    ranking = [
        {
            "rank": i + 1,
            "display_name": u.display_name or "名無し",
            "points": u.points,
            "is_me": u.id == user.id,
        }
        for i, u in enumerate(top_users)
    ]

    # 自分がトップN外の場合、自分の順位を別途取得
    my_rank = None
    my_in_top = any(r["is_me"] for r in ranking)
    if not my_in_top:
        count_result = await db.execute(
            select(func.count(User.id)).where(
                ~User.device_id.like(f"{NPC_DEVICE_PREFIX}%"),
                User.points > user.points,
            )
        )
        my_rank = (count_result.scalar() or 0) + 1

    return {
        "ranking": ranking,
        "my_rank": my_rank,
        "my_points": user.points,
        "my_display_name": user.display_name or "名無し",
    }
