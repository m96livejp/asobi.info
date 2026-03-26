"""残高API"""
from fastapi import APIRouter, Depends
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from ..database import get_db
from ..deps import require_user
from ..models.user import User
from ..models.balance import UserBalance, BalanceTransaction

router = APIRouter(prefix="/api/balance", tags=["balance"])


@router.get("")
async def get_balance(user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(UserBalance).where(UserBalance.user_id == user.id))
    bal = result.scalar_one_or_none()
    if not bal:
        bal = UserBalance(user_id=user.id, points=0, crystals=0)
        db.add(bal)
        await db.commit()
        await db.refresh(bal)
    return {"points": bal.points, "crystals": bal.crystals}


@router.get("/history")
async def get_history(user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    result = await db.execute(
        select(BalanceTransaction)
        .where(BalanceTransaction.user_id == user.id)
        .order_by(BalanceTransaction.id.desc())
        .limit(50)
    )
    return [{
        "id": t.id,
        "currency": t.currency,
        "amount": t.amount,
        "type": t.type,
        "memo": t.memo,
        "created_at": str(t.created_at),
    } for t in result.scalars()]
