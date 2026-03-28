"""会話API"""
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, delete
from ..database import get_db
from ..deps import require_user
from ..models.user import User
from ..models.character import Character
from ..models.conversation import Conversation, Message, ConversationState
from ..models.settings import ChatStateConfig, AiSettings
from pydantic import BaseModel

router = APIRouter(prefix="/api/conversations", tags=["conversations"])


class ConversationCreate(BaseModel):
    character_id: int


@router.get("")
async def list_conversations(user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    result = await db.execute(
        select(Conversation).where(Conversation.user_id == user.id, Conversation.is_deleted == 0).order_by(Conversation.updated_at.desc())
    )
    convs = result.scalars().all()
    out = []
    for c in convs:
        # キャラクター情報取得
        cr = await db.execute(select(Character.name, Character.avatar_url, Character.is_deleted).where(Character.id == c.character_id))
        char_row = cr.first()
        # 最新メッセージ
        mr = await db.execute(
            select(Message.content).where(Message.conversation_id == c.id).order_by(Message.id.desc()).limit(1)
        )
        last_msg = mr.scalar_one_or_none()
        out.append({
            "id": c.id,
            "character_id": c.character_id,
            "character_name": char_row[0] if char_row else "不明",
            "character_avatar": char_row[1] if char_row else None,
            "character_is_deleted": char_row[2] if char_row else 0,
            "title": c.title,
            "last_message": (last_msg[:50] + "...") if last_msg and len(last_msg) > 50 else last_msg,
            "updated_at": str(c.updated_at),
        })
    return out


@router.post("")
async def create_conversation(req: ConversationCreate, user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    # キャラクター確認
    result = await db.execute(select(Character).where(Character.id == req.character_id))
    char = result.scalar_one_or_none()
    if not char:
        raise HTTPException(status_code=404, detail="キャラクターが見つかりません")
    if not char.is_public and not char.is_sample and char.creator_id != user.id:
        raise HTTPException(status_code=403, detail="非公開キャラクターです")

    # 既存の会話があればそれを返す（1ユーザー1キャラ1会話）
    existing = await db.execute(
        select(Conversation).where(
            Conversation.user_id == user.id,
            Conversation.character_id == req.character_id
        ).order_by(Conversation.updated_at.desc()).limit(1)
    )
    existing_conv = existing.scalar_one_or_none()
    if existing_conv:
        return {"id": existing_conv.id, "character_id": existing_conv.character_id, "title": existing_conv.title}

    conv = Conversation(user_id=user.id, character_id=req.character_id, title=f"{char.name}との会話")
    db.add(conv)
    await db.flush()

    # use_count（ユニークユーザー累計）を+1
    char.use_count = (char.use_count or 0) + 1

    # ステータス機能ON時、初期ステータスをキャラクターからコピー
    try:
        cfg_result = await db.execute(select(ChatStateConfig).where(ChatStateConfig.id == 1))
        cfg = cfg_result.scalar_one_or_none()
        if cfg and cfg.enabled:
            state = ConversationState(
                conversation_id=conv.id,
                relationship=char.init_relationship or "",
                mood=char.init_mood or "",
                environment=char.init_environment or "",
                situation=char.init_situation or "",
                inventory=char.init_inventory or "",
                goals=char.init_goals or "",
                memories="[]",
            )
            db.add(state)
    except Exception:
        pass

    # 最初のメッセージ
    if char.first_message:
        msg = Message(conversation_id=conv.id, role="assistant", content=char.first_message)
        db.add(msg)

    await db.commit()
    await db.refresh(conv)
    return {"id": conv.id, "character_id": conv.character_id, "title": conv.title}


@router.get("/{conv_id}")
async def get_conversation(conv_id: int, user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    result = await db.execute(
        select(Conversation).where(Conversation.id == conv_id, Conversation.user_id == user.id)
    )
    conv = result.scalar_one_or_none()
    if not conv:
        raise HTTPException(status_code=404, detail="会話が見つかりません")

    result = await db.execute(
        select(Message).where(Message.conversation_id == conv_id).order_by(Message.id)
    )
    all_messages = result.scalars().all()
    messages = [{"id": m.id, "role": m.role, "content": m.content,
                 "is_deleted": m.is_deleted, "created_at": str(m.created_at)} for m in all_messages]

    # 最後のアクティブ（非削除）メッセージがuserかどうか
    active = [m for m in all_messages if not m.is_deleted]
    needs_retry = bool(active and active[-1].role == "user")

    result = await db.execute(select(Character).where(Character.id == conv.character_id))
    char = result.scalar_one_or_none()

    # TTS利用可否（tts_mode + ユーザーロール）
    tts_available = False
    try:
        ai_res = await db.execute(select(AiSettings).where(AiSettings.id == 1))
        ai = ai_res.scalar_one_or_none()
        if ai and char and char.voice_model:
            if ai.tts_mode == "all":
                tts_available = True
            elif ai.tts_mode == "admin_only" and user.role == "admin":
                tts_available = True
    except Exception:
        pass

    return {
        "id": conv.id,
        "character": {
            "id": char.id, "name": char.name, "avatar_url": char.avatar_url,
            "ai_model": char.ai_model, "is_deleted": char.is_deleted,
            "voice_model": char.voice_model,
            "tts_styles": __import__("json").loads(char.tts_styles or "[]") if char.tts_styles else [],
        } if char else None,
        "title": conv.title,
        "messages": messages,
        "needs_retry": needs_retry,
        "tts_available": tts_available,
    }


@router.delete("/{conv_id}")
async def delete_conversation(conv_id: int, user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    result = await db.execute(
        select(Conversation).where(Conversation.id == conv_id, Conversation.user_id == user.id)
    )
    conv = result.scalar_one_or_none()
    if not conv:
        raise HTTPException(status_code=404, detail="会話が見つかりません")
    conv.is_deleted = 1
    await db.commit()
    return {"deleted": True}
