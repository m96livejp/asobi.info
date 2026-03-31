"""会話API"""
import json
from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, delete
from ..database import get_db
from ..deps import require_user
from ..models.user import User
from ..models.character import Character
from ..models.conversation import Conversation, Message, ConversationState, ConversationStateLog
from ..models.settings import ChatStateConfig, AiSettings, DEFAULT_STATE_FIELDS, STATE_BUILTIN_KEYS
from pydantic import BaseModel

MSG_PAGE_SIZE = 50  # 1ページあたりのメッセージ取得数

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
    if not char.is_public and char.creator_id != user.id:
        raise HTTPException(status_code=403, detail="非公開キャラクターです")

    # 既存の会話があればそれを返す（1ユーザー1キャラ1会話、削除済み除外）
    existing = await db.execute(
        select(Conversation).where(
            Conversation.user_id == user.id,
            Conversation.character_id == req.character_id,
            Conversation.is_deleted == 0,
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

    # needs_retry: 最後のアクティブメッセージがuserかどうか
    last_active_r = await db.execute(
        select(Message).where(Message.conversation_id == conv_id, Message.is_deleted == 0)
        .order_by(Message.id.desc()).limit(1)
    )
    last_active = last_active_r.scalar_one_or_none()
    needs_retry = bool(last_active and last_active.role == "user")

    # 最新 MSG_PAGE_SIZE 件のみ取得（ページネーション）
    msgs_r = await db.execute(
        select(Message).where(Message.conversation_id == conv_id)
        .order_by(Message.id.desc()).limit(MSG_PAGE_SIZE + 1)
    )
    msgs_desc = msgs_r.scalars().all()
    has_more = len(msgs_desc) > MSG_PAGE_SIZE
    msgs_desc = msgs_desc[:MSG_PAGE_SIZE]
    msgs_desc.reverse()  # 昇順に戻す

    messages = [{"id": m.id, "role": m.role, "content": m.content,
                 "state_snapshot": m.state_snapshot,
                 "is_deleted": m.is_deleted, "created_at": str(m.created_at)} for m in msgs_desc]

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
            "tts_styles": json.loads(char.tts_styles) if char.tts_styles else [],
            "bgm_mode": char.bgm_mode or "none",
            "bgm_track_id": char.bgm_track_id,
        } if char else None,
        "title": conv.title,
        "messages": messages,
        "has_more": has_more,
        "needs_retry": needs_retry,
        "tts_available": tts_available,
    }


@router.get("/{conv_id}/messages")
async def get_older_messages(
    conv_id: int,
    before_id: int = Query(..., description="このID未満のメッセージを取得"),
    limit: int = Query(MSG_PAGE_SIZE, le=100),
    user: User = Depends(require_user),
    db: AsyncSession = Depends(get_db),
):
    """古いメッセージのページネーション取得"""
    conv = (await db.execute(
        select(Conversation).where(Conversation.id == conv_id, Conversation.user_id == user.id)
    )).scalar_one_or_none()
    if not conv:
        raise HTTPException(status_code=404, detail="会話が見つかりません")

    msgs_r = await db.execute(
        select(Message).where(Message.conversation_id == conv_id, Message.id < before_id)
        .order_by(Message.id.desc()).limit(limit + 1)
    )
    msgs_desc = msgs_r.scalars().all()
    has_more = len(msgs_desc) > limit
    msgs_desc = msgs_desc[:limit]
    msgs_desc.reverse()

    return {
        "messages": [{"id": m.id, "role": m.role, "content": m.content,
                      "state_snapshot": m.state_snapshot,
                      "is_deleted": m.is_deleted, "created_at": str(m.created_at)} for m in msgs_desc],
        "has_more": has_more,
    }


@router.post("/{conv_id}/reset")
async def reset_conversation(conv_id: int, user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    result = await db.execute(
        select(Conversation).where(Conversation.id == conv_id, Conversation.user_id == user.id)
    )
    conv = result.scalar_one_or_none()
    if not conv:
        raise HTTPException(status_code=404, detail="会話が見つかりません")

    # メッセージを全てソフト削除
    msgs_result = await db.execute(
        select(Message).where(Message.conversation_id == conv_id)
    )
    for m in msgs_result.scalars().all():
        m.is_deleted = 1

    # キャラクター取得
    char_result = await db.execute(select(Character).where(Character.id == conv.character_id))
    char = char_result.scalar_one_or_none()

    # ConversationStateをキャラクターの初期値にリセット
    try:
        state_result = await db.execute(
            select(ConversationState).where(ConversationState.conversation_id == conv_id)
        )
        state = state_result.scalar_one_or_none()
        if state and char:
            state.relationship = char.init_relationship or ""
            state.mood = char.init_mood or ""
            state.environment = char.init_environment or ""
            state.situation = char.init_situation or ""
            state.inventory = char.init_inventory or ""
            state.goals = char.init_goals or ""
            state.memories = "[]"
    except Exception:
        pass

    # 最初のメッセージを再追加
    if char and char.first_message:
        first_msg = Message(conversation_id=conv_id, role="assistant", content=char.first_message)
        db.add(first_msg)

    # updated_atを更新して会話リストの並びに反映
    from datetime import datetime
    conv.updated_at = datetime.now()

    await db.commit()
    return {"reset": True}


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


@router.delete("/{conv_id}/purge")
async def purge_conversation(conv_id: int, user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    """会話・メッセージ・ステータスをDBから完全削除"""
    result = await db.execute(
        select(Conversation).where(Conversation.id == conv_id, Conversation.user_id == user.id)
    )
    conv = result.scalar_one_or_none()
    if not conv:
        raise HTTPException(status_code=404, detail="会話が見つかりません")

    # ステータスログを削除
    await db.execute(delete(ConversationStateLog).where(ConversationStateLog.conversation_id == conv_id))
    # ステータスを削除
    await db.execute(delete(ConversationState).where(ConversationState.conversation_id == conv_id))
    # メッセージを削除
    await db.execute(delete(Message).where(Message.conversation_id == conv_id))
    # 会話を削除
    await db.delete(conv)
    await db.commit()
    return {"purged": True}


# ── ステータス取得（自分の会話のみ）
@router.get("/{conv_id}/state")
async def get_conversation_state(conv_id: int, user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    conv = (await db.execute(
        select(Conversation).where(Conversation.id == conv_id, Conversation.user_id == user.id)
    )).scalar_one_or_none()
    if not conv:
        raise HTTPException(status_code=404, detail="会話が見つかりません")

    cfg = (await db.execute(select(ChatStateConfig).where(ChatStateConfig.id == 1))).scalar_one_or_none()
    if not cfg or not cfg.enabled:
        raise HTTPException(status_code=404, detail="ステータス機能は無効です")

    state = (await db.execute(
        select(ConversationState).where(ConversationState.conversation_id == conv_id)
    )).scalar_one_or_none()
    if not state:
        raise HTTPException(status_code=404, detail="ステータスデータがありません")

    fields_cfg = json.loads(cfg.fields_json) if cfg.fields_json else DEFAULT_STATE_FIELDS
    extra = json.loads(state.extra_fields or "{}") if state.extra_fields else {}

    fields = []
    for f in fields_cfg:
        if not f.get("enabled", True):
            continue
        key = f["key"]
        label = f.get("label", key)
        value = getattr(state, key, "") if key in STATE_BUILTIN_KEYS else extra.get(key, "")
        fields.append({"key": key, "label": label, "value": value or ""})

    memories = json.loads(state.memories or "[]") if state.memories else []

    return {
        "conv_id": conv_id,
        "fields": fields,
        "memories": memories,
        "updated_at": str(state.updated_at) if state.updated_at else None,
    }


# ── ステータス更新（管理者のみ・自分の会話）
class StateUpdateBody(BaseModel):
    fields: dict = {}
    memories: list[str] = []


@router.put("/{conv_id}/state")
async def update_conversation_state(
    conv_id: int, body: StateUpdateBody,
    user: User = Depends(require_user), db: AsyncSession = Depends(get_db)
):
    if user.role != "admin":
        raise HTTPException(status_code=403, detail="管理者のみ編集できます")

    conv = (await db.execute(
        select(Conversation).where(Conversation.id == conv_id, Conversation.user_id == user.id)
    )).scalar_one_or_none()
    if not conv:
        raise HTTPException(status_code=404, detail="会話が見つかりません")

    state = (await db.execute(
        select(ConversationState).where(ConversationState.conversation_id == conv_id)
    )).scalar_one_or_none()
    if not state:
        raise HTTPException(status_code=404, detail="ステータスデータがありません")

    extra = json.loads(state.extra_fields or "{}") if state.extra_fields else {}
    for key, val in body.fields.items():
        if key in STATE_BUILTIN_KEYS:
            setattr(state, key, str(val))
        else:
            extra[key] = str(val)
    state.extra_fields = json.dumps(extra, ensure_ascii=False)
    state.memories = json.dumps(body.memories, ensure_ascii=False)

    await db.commit()
    return {"ok": True}
