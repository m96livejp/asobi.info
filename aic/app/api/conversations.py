"""会話API"""
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, delete
from ..database import get_db
from ..deps import require_user
from ..models.user import User
from ..models.character import Character
from ..models.conversation import Conversation, Message
from pydantic import BaseModel

router = APIRouter(prefix="/api/conversations", tags=["conversations"])


class ConversationCreate(BaseModel):
    character_id: int


@router.get("")
async def list_conversations(user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    result = await db.execute(
        select(Conversation).where(Conversation.user_id == user.id).order_by(Conversation.updated_at.desc())
    )
    convs = result.scalars().all()
    out = []
    for c in convs:
        # キャラクター名取得
        cr = await db.execute(select(Character.name, Character.avatar_url).where(Character.id == c.character_id))
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

    conv = Conversation(user_id=user.id, character_id=req.character_id, title=f"{char.name}との会話")
    db.add(conv)
    await db.flush()

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
    messages = [{"id": m.id, "role": m.role, "content": m.content, "created_at": str(m.created_at)} for m in result.scalars()]

    result = await db.execute(select(Character).where(Character.id == conv.character_id))
    char = result.scalar_one_or_none()

    return {
        "id": conv.id,
        "character": {"id": char.id, "name": char.name, "avatar_url": char.avatar_url, "ai_model": char.ai_model} if char else None,
        "title": conv.title,
        "messages": messages,
    }


@router.delete("/{conv_id}")
async def delete_conversation(conv_id: int, user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    result = await db.execute(
        select(Conversation).where(Conversation.id == conv_id, Conversation.user_id == user.id)
    )
    conv = result.scalar_one_or_none()
    if not conv:
        raise HTTPException(status_code=404, detail="会話が見つかりません")
    await db.execute(delete(Message).where(Message.conversation_id == conv_id))
    await db.delete(conv)
    await db.commit()
    return {"deleted": True}
