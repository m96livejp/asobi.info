"""ユーザー通知 API"""
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, update, func
from ..database import get_db
from ..deps import require_user
from ..models.user import User
from ..models.notification import Notification

router = APIRouter(prefix="/api/notifications", tags=["notifications"])


@router.get("")
async def list_notifications(user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    """自分宛の通知一覧（新しい順、最大50件）"""
    result = await db.execute(
        select(Notification)
        .where(Notification.user_id == user.id)
        .order_by(Notification.id.desc())
        .limit(50)
    )
    rows = result.scalars().all()

    # 未読件数
    unread_result = await db.execute(
        select(func.count(Notification.id))
        .where(Notification.user_id == user.id)
        .where(Notification.is_read == 0)
    )
    unread_count = unread_result.scalar() or 0

    return {
        "notifications": [
            {
                "id": n.id,
                "type": n.type,
                "title": n.title,
                "message": n.message,
                "related_id": n.related_id,
                "related_url": n.related_url,
                "is_read": n.is_read,
                "created_at": n.created_at.strftime("%Y-%m-%d %H:%M:%S") if n.created_at else None,
            }
            for n in rows
        ],
        "unread_count": unread_count,
    }


@router.get("/unread-count")
async def unread_count(user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    """未読件数のみ取得（軽量・ポーリング用）"""
    result = await db.execute(
        select(func.count(Notification.id))
        .where(Notification.user_id == user.id)
        .where(Notification.is_read == 0)
    )
    return {"unread_count": result.scalar() or 0}


@router.post("/{notif_id}/read")
async def mark_read(notif_id: int, user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    """通知を既読にする"""
    result = await db.execute(
        select(Notification)
        .where(Notification.id == notif_id)
        .where(Notification.user_id == user.id)
    )
    notif = result.scalar_one_or_none()
    if not notif:
        raise HTTPException(status_code=404, detail="通知が見つかりません")
    notif.is_read = 1
    await db.commit()
    return {"ok": True}


@router.post("/read-all")
async def mark_all_read(user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    """全通知を既読にする"""
    await db.execute(
        update(Notification)
        .where(Notification.user_id == user.id)
        .where(Notification.is_read == 0)
        .values(is_read=1)
    )
    await db.commit()
    return {"ok": True}


@router.delete("/{notif_id}")
async def delete_notification(notif_id: int, user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    """通知を削除"""
    result = await db.execute(
        select(Notification)
        .where(Notification.id == notif_id)
        .where(Notification.user_id == user.id)
    )
    notif = result.scalar_one_or_none()
    if not notif:
        raise HTTPException(status_code=404, detail="通知が見つかりません")
    await db.delete(notif)
    await db.commit()
    return {"ok": True}
