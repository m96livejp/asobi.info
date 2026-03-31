"""シーン画像タスクAPIエンドポイント"""
import logging
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
import json

logger = logging.getLogger("aic.scene")

from ..database import get_db
from ..deps import require_user
from ..models.user import User
from ..models.image import SceneImageTask
from ..models.conversation import Conversation, ConversationState
from ..models.character import Character
from ..models.settings import AiSettings
from ..models.image import UserImage

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


@router.post("/force/{conversation_id}")
async def force_scene_change(
    conversation_id: int,
    user: User = Depends(require_user),
    db: AsyncSession = Depends(get_db),
):
    """今すぐ画像変更を実行（ハンバーガーメニューから呼び出し）"""
    from ..services.scene_image_service import create_scene_task, _get_sd_settings

    # 会話取得
    conv_result = await db.execute(
        select(Conversation).where(Conversation.id == conversation_id, Conversation.user_id == user.id)
    )
    conv = conv_result.scalar_one_or_none()
    if not conv:
        raise HTTPException(status_code=404, detail="会話が見つかりません")

    # キャラクター取得
    char_result = await db.execute(select(Character).where(Character.id == conv.character_id))
    char = char_result.scalar_one_or_none()
    if not char:
        raise HTTPException(status_code=400, detail="キャラクターが見つかりません")

    # SD設定取得: キャラ設定 → キャラのアバター画像 → ユーザーの最初の生成画像からフォールバック
    _sd_prompt = char.sd_prompt
    _sd_neg_prompt = char.sd_neg_prompt
    _sd_seed = char.sd_seed
    _sd_model = char.sd_model
    if not _sd_prompt or _sd_seed is None:
        # まずキャラクターのアバター画像から取得
        _avatar_img = None
        if char.avatar_url:
            _avatar_result = await db.execute(
                select(UserImage)
                .where(UserImage.url == char.avatar_url, UserImage.seed.isnot(None), UserImage.prompt.isnot(None))
                .limit(1)
            )
            _avatar_img = _avatar_result.scalar_one_or_none()
        if _avatar_img:
            _sd_prompt = _sd_prompt or _avatar_img.prompt
            _sd_seed = _sd_seed if _sd_seed is not None else _avatar_img.seed
            _sd_model = _sd_model or _avatar_img.model
        else:
            # アバター画像がなければユーザーの最初の生成画像からフォールバック
            _first_img_result = await db.execute(
                select(UserImage)
                .where(UserImage.user_id == user.id, UserImage.seed.isnot(None), UserImage.prompt.isnot(None))
                .order_by(UserImage.id.asc())
                .limit(1)
            )
            _fi = _first_img_result.scalar_one_or_none()
            if _fi:
                _sd_prompt = _sd_prompt or _fi.prompt
                _sd_seed = _sd_seed if _sd_seed is not None else _fi.seed
                _sd_model = _sd_model or _fi.model
    logger.info(f"force_scene: user={user.id} conv={conversation_id} sd_prompt={bool(_sd_prompt)} sd_seed={_sd_seed} sd_model={_sd_model}")
    if not _sd_prompt or _sd_seed is None:
        raise HTTPException(status_code=400, detail="画像設定がありません。先に画像を1枚生成してください。")

    # AI設定確認（image_change_enabledまたは管理者）
    ai_settings_result = await db.execute(select(AiSettings).where(AiSettings.id == 1))
    ai_settings = ai_settings_result.scalar_one_or_none()
    is_admin = user.role == "admin"
    if not is_admin and not (ai_settings and getattr(ai_settings, 'image_change_enabled', 0)):
        raise HTTPException(status_code=403, detail="画像変更機能が無効です")

    # 会話ステータス取得
    state_result = await db.execute(
        select(ConversationState).where(ConversationState.conversation_id == conversation_id)
    )
    conv_state = state_result.scalar_one_or_none()

    # ステータス辞書を構築
    scene_state = {}
    if conv_state:
        for k in ("mood", "environment", "situation", "relationship"):
            val = getattr(conv_state, k, None)
            if val:
                scene_state[k] = str(val)

    if not scene_state:
        # ステータスがない場合はデフォルト値で実行
        scene_state = {"mood": "普通", "environment": "屋内"}

    sd = await _get_sd_settings(db)
    task = await create_scene_task(
        db=db,
        conversation_id=conversation_id,
        message_id=None,
        base_prompt=_sd_prompt,
        state_dict=scene_state,
        sd=sd,
        seed=_sd_seed,
        model=_sd_model,
        negative_prompt=_sd_neg_prompt,
    )
    if not task:
        raise HTTPException(status_code=500, detail="タスク作成に失敗しました")

    logger.info(f"force_scene: task created id={task.id} model={_sd_model} scene_state={scene_state}")
    return {"scene_task_id": task.id}
