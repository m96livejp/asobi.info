"""シーン画像タスクAPIエンドポイント"""
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select

from ..database import get_db
from ..deps import require_user
from ..models.user import User
from ..models.image import SceneImageTask
from ..models.conversation import Conversation

router = APIRouter(prefix="/api/scene", tags=["scene"])


@router.get("/{task_id}")
async def get_scene_task(
    task_id: int,
    user: User = Depends(require_user),
    db: AsyncSession = Depends(get_db),
):
    """シーン画像タスクのステータスを取得"""
    result = await db.execute(
        select(SceneImageTask).where(SceneImageTask.id == task_id)
    )
    task = result.scalar_one_or_none()
    if not task:
        raise HTTPException(status_code=404, detail="タスクが見つかりません")

    # 自分の会話のタスクのみ参照可能
    conv_result = await db.execute(
        select(Conversation).where(
            Conversation.id == task.conversation_id,
            Conversation.user_id == user.id,
        )
    )
    conv = conv_result.scalar_one_or_none()
    if not conv and not (user.asobi_user_id is not None):
        # 管理者は全て参照可（asobi_user_idがある = 認証済み）
        raise HTTPException(status_code=403, detail="アクセスできません")

    return {
        "id": task.id,
        "status": task.status,
        "image_url": task.image_url,
        "error_message": task.error_message,
        "created_at": task.created_at,
        "completed_at": task.completed_at,
    }
