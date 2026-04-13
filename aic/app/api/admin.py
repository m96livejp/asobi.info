"""管理者API"""
import os
import shutil
import uuid
from fastapi import APIRouter, Depends, HTTPException, Query, Body
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, func, delete, update
from ..database import get_db
from ..deps import require_admin
from ..models.user import User
from ..models.character import Character, CharacterReport
from ..models.conversation import Conversation, Message
from ..models.settings import AiSettings, SdSettings, PromptTemplate, SdSelectableModel, ChatStateConfig
from ..models.image import UserImage, ImageFeedback, GenerationQueue
from ..models.balance import UserBalance, BalanceTransaction
from ..services import queue_worker
from pydantic import BaseModel
import httpx
import json

router = APIRouter(prefix="/api/admin", tags=["admin"])


# ─────────────────────────────────────────────
# 共通ヘルパー
# ─────────────────────────────────────────────

async def _get_or_create_settings(db: AsyncSession) -> AiSettings:
    result = await db.execute(select(AiSettings).where(AiSettings.id == 1))
    s = result.scalar_one_or_none()
    if not s:
        s = AiSettings(id=1, provider="claude", model="claude-sonnet-4-20250514", max_tokens=1024, cost=1)
        db.add(s)
        await db.commit()
        await db.refresh(s)
    return s


# ─────────────────────────────────────────────
# ダッシュボード統計
# ─────────────────────────────────────────────

@router.get("/stats")
async def get_stats(admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    user_count      = (await db.execute(select(func.count()).select_from(User))).scalar()
    guest_count     = (await db.execute(select(func.count()).select_from(User).where(User.asobi_user_id == None))).scalar()
    char_count      = (await db.execute(select(func.count()).select_from(Character))).scalar()
    public_count    = (await db.execute(select(func.count()).select_from(Character).where(Character.is_public == 1))).scalar()
    conv_count      = (await db.execute(select(func.count()).select_from(Conversation))).scalar()
    msg_count       = (await db.execute(select(func.count()).select_from(Message))).scalar()
    return {
        "users":         user_count,
        "guests":        guest_count,
        "characters":    char_count,
        "public_chars":  public_count,
        "conversations": conv_count,
        "messages":      msg_count,
    }


# ─────────────────────────────────────────────
# AI設定
# ─────────────────────────────────────────────

class AiSettingsUpdate(BaseModel):
    provider: str
    endpoint: str | None = None
    api_key: str | None = None
    model: str = ""
    max_tokens: int = 1024
    cost: int = 1
    response_guideline: str | None = None
    voicevox_endpoint: str | None = None
    state_instruction: str | None = None
    tts_instruction: str | None = None
    tts_instruction_params: str | None = None


@router.get("/ai-settings")
async def get_ai_settings(admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    from ..services.chat_service import (
        DEFAULT_RESPONSE_GUIDELINE, DEFAULT_STATE_INSTRUCTION,
        DEFAULT_TTS_INSTRUCTION, DEFAULT_TTS_INSTRUCTION_PARAMS,
    )
    from ..services.review_service import DEFAULT_REVIEW_PROMPT
    s = await _get_or_create_settings(db)
    return {"provider": s.provider, "endpoint": s.endpoint, "api_key": s.api_key,
            "model": s.model, "max_tokens": s.max_tokens, "cost": s.cost,
            "response_guideline": s.response_guideline or "",
            "voicevox_endpoint": s.voicevox_endpoint or "",
            "tts_mode": s.tts_mode or "disabled",
            "tts_emotion": s.tts_emotion or 0,
            "tts_se": s.tts_se or 0,
            "tts_autoplay": s.tts_autoplay or 0,
            "tts_voice_params": s.tts_voice_params or None,
            "image_change_enabled": s.image_change_enabled or 0,
            "image_change_revert_turns": s.image_change_revert_turns or 10,
            "daily_point_recovery_enabled": s.daily_point_recovery_enabled or 0,
            "daily_point_recovery_threshold": s.daily_point_recovery_threshold or 100,
            "state_instruction": s.state_instruction or "",
            "tts_instruction": s.tts_instruction or "",
            "tts_instruction_params": s.tts_instruction_params or "",
            "review_enabled": s.review_enabled or 0,
            "review_prompt": s.review_prompt or "",
            "defaults": {
                "response_guideline": DEFAULT_RESPONSE_GUIDELINE,
                "state_instruction": DEFAULT_STATE_INSTRUCTION,
                "tts_instruction": DEFAULT_TTS_INSTRUCTION,
                "tts_instruction_params": DEFAULT_TTS_INSTRUCTION_PARAMS,
                "review_prompt": DEFAULT_REVIEW_PROMPT,
            }}


@router.put("/ai-settings")
async def update_ai_settings(req: AiSettingsUpdate, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    s = await _get_or_create_settings(db)
    s.provider = req.provider; s.endpoint = req.endpoint; s.api_key = req.api_key
    s.model = req.model; s.max_tokens = req.max_tokens; s.cost = req.cost
    s.response_guideline = req.response_guideline or None
    s.voicevox_endpoint = req.voicevox_endpoint or None
    s.state_instruction = req.state_instruction or None
    s.tts_instruction = req.tts_instruction or None
    s.tts_instruction_params = req.tts_instruction_params or None
    await db.commit()
    return {"ok": True}


class AiSettingsPatch(BaseModel):
    voicevox_endpoint: str | None = None
    tts_mode: str | None = None          # disabled / all / admin_only
    tts_emotion: int | None = None
    tts_se: int | None = None
    tts_autoplay: int | None = None
    tts_voice_params: str | None = None  # JSON文字列 or "" で削除
    image_change_enabled: int | None = None
    image_change_revert_turns: int | None = None
    daily_point_recovery_enabled: int | None = None
    daily_point_recovery_threshold: int | None = None
    review_enabled: int | None = None
    review_prompt: str | None = None


@router.patch("/ai-settings")
async def patch_ai_settings(req: AiSettingsPatch, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    """部分更新"""
    s = await _get_or_create_settings(db)
    if req.voicevox_endpoint is not None:
        s.voicevox_endpoint = req.voicevox_endpoint or None
    if req.tts_mode is not None and req.tts_mode in ("disabled", "all", "admin_only"):
        s.tts_mode = req.tts_mode
    if req.tts_emotion is not None:
        s.tts_emotion = req.tts_emotion
    if req.tts_se is not None:
        s.tts_se = req.tts_se
    if req.tts_autoplay is not None:
        s.tts_autoplay = req.tts_autoplay
    if req.tts_voice_params is not None:
        s.tts_voice_params = req.tts_voice_params or None
    if req.image_change_enabled is not None:
        s.image_change_enabled = req.image_change_enabled
    if req.image_change_revert_turns is not None:
        s.image_change_revert_turns = max(1, req.image_change_revert_turns)
    if req.daily_point_recovery_enabled is not None:
        s.daily_point_recovery_enabled = req.daily_point_recovery_enabled
    if req.daily_point_recovery_threshold is not None:
        s.daily_point_recovery_threshold = max(1, req.daily_point_recovery_threshold)
    if req.review_enabled is not None:
        s.review_enabled = req.review_enabled
    if req.review_prompt is not None:
        s.review_prompt = req.review_prompt or None
    await db.commit()
    return {"ok": True}


@router.post("/run-daily-recovery")
async def run_daily_recovery_now(admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    """デイリーポイント回復を今すぐ手動実行（テスト用）"""
    from ..services.daily_recovery import run_recovery
    result = await run_recovery(db)
    return result


@router.get("/state-fields")
async def get_state_fields(admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    from ..models.settings import DEFAULT_STATE_FIELDS
    cfg_r = await db.execute(select(ChatStateConfig).where(ChatStateConfig.id == 1))
    cfg = cfg_r.scalar_one_or_none()
    if cfg and cfg.fields_json:
        return json.loads(cfg.fields_json)
    return DEFAULT_STATE_FIELDS


@router.put("/state-fields")
async def update_state_fields(fields: list = Body(...), admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    from ..models.settings import STATE_BUILTIN_KEYS, STATE_CUSTOM_PREFIX
    import re as _re
    # カスタムフィールドにプレフィックスを付与 + 変数名を a-z0-9 のみに制限
    normalized = []
    for f in fields:
        key = f.get("key", "")
        if key and key not in STATE_BUILTIN_KEYS:
            # プレフィックス部分を除いた生キーを取得
            raw = key[len(STATE_CUSTOM_PREFIX):] if key.startswith(STATE_CUSTOM_PREFIX) else key
            raw = _re.sub(r'[^a-z0-9]', '', raw)
            if not raw:
                continue  # 無効なキーはスキップ
            f = dict(f)
            f["key"] = STATE_CUSTOM_PREFIX + raw
        normalized.append(f)
    cfg_r = await db.execute(select(ChatStateConfig).where(ChatStateConfig.id == 1))
    cfg = cfg_r.scalar_one_or_none()
    if not cfg:
        cfg = ChatStateConfig(id=1, enabled=0)
        db.add(cfg)
    cfg.fields_json = json.dumps(normalized, ensure_ascii=False)
    await db.commit()
    return {"ok": True}


class AiTestRequest(BaseModel):
    provider: str
    endpoint: str | None = None
    api_key: str | None = None


@router.post("/ai-test")
async def test_ai_connection(req: AiTestRequest, admin: User = Depends(require_admin)):
    try:
        if req.provider == "ollama":   return await _test_ollama(req.endpoint)
        if req.provider == "openai":   return await _test_openai(req.api_key)
        if req.provider == "claude":   return await _test_claude(req.api_key)
        if req.provider == "gemini":   return await _test_gemini(req.api_key)
        return {"ok": False, "error": f"不明なプロバイダ: {req.provider}"}
    except Exception as e:
        return {"ok": False, "error": str(e)}


async def _test_ollama(endpoint):
    endpoint = (endpoint or "http://localhost:11434").rstrip("/")
    base = endpoint.replace("/api/generate", "").replace("/v1", "")
    async with httpx.AsyncClient(timeout=10) as c:
        r = await c.get(f"{base}/api/tags"); r.raise_for_status()
        models = [{"id": m["name"], "name": m["name"]} for m in r.json().get("models", [])]
        return {"ok": True, "models": models}

async def _test_openai(api_key):
    if not api_key: return {"ok": False, "error": "APIキーが未設定です"}
    async with httpx.AsyncClient(timeout=10) as c:
        r = await c.get("https://api.openai.com/v1/models", headers={"Authorization": f"Bearer {api_key}"})
        r.raise_for_status()
        # チャット対応モデルのみ（embeddings・whisper等を除外）
        chat_keywords = ("gpt", "o1", "o3", "o4", "chatgpt")
        models = [{"id": m["id"], "name": m["id"]} for m in r.json().get("data", [])
                  if any(k in m["id"] for k in chat_keywords)]
        return {"ok": True, "models": sorted(models, key=lambda x: x["id"])}

async def _test_claude(api_key):
    if not api_key: return {"ok": False, "error": "APIキーが未設定です"}
    async with httpx.AsyncClient(timeout=10) as c:
        r = await c.get("https://api.anthropic.com/v1/models",
                        headers={"x-api-key": api_key, "anthropic-version": "2023-06-01"})
        r.raise_for_status()
        models = [{"id": m["id"], "name": m.get("display_name", m["id"])} for m in r.json().get("data", [])]
        return {"ok": True, "models": sorted(models, key=lambda x: x["id"])}

async def _test_gemini(api_key):
    if not api_key: return {"ok": False, "error": "APIキーが未設定です"}
    async with httpx.AsyncClient(timeout=10) as c:
        r = await c.get(f"https://generativelanguage.googleapis.com/v1beta/models?key={api_key}")
        r.raise_for_status()
        models = [{"id": m["name"].replace("models/", ""), "name": m.get("displayName", m["name"])}
                  for m in r.json().get("models", []) if "generateContent" in m.get("supportedGenerationMethods", [])]
        return {"ok": True, "models": models}


# ─────────────────────────────────────────────
# ユーザー管理
# ─────────────────────────────────────────────

@router.get("/users")
async def list_users(admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    from ..models.character import Character
    from ..models.image import UserImage

    # ユーザーごとのキャラ数
    char_counts = {}
    cr = await db.execute(
        select(Character.creator_id, func.count()).group_by(Character.creator_id)
    )
    for uid, cnt in cr.all():
        char_counts[uid] = cnt

    # ユーザーごとの画像数
    img_counts = {}
    ir = await db.execute(
        select(UserImage.user_id, func.count()).group_by(UserImage.user_id)
    )
    for uid, cnt in ir.all():
        img_counts[uid] = cnt

    # ユーザーごとの残高
    bal_map = {}
    br = await db.execute(select(UserBalance))
    for b in br.scalars():
        bal_map[b.user_id] = b

    result = await db.execute(select(User).order_by(User.id.desc()))
    users = result.scalars().all()
    return [{
        "id": u.id,
        "display_name": u.display_name,
        "role": u.role,
        "is_guest": u.asobi_user_id is None,
        "asobi_user_id": u.asobi_user_id,
        "char_count": char_counts.get(u.id, 0),
        "image_count": img_counts.get(u.id, 0),
        "is_suspended": bool(u.is_suspended),
        "last_active": str(u.updated_at) if u.updated_at else "",
        "created_at": str(u.created_at),
        "points": bal_map[u.id].points if u.id in bal_map else 0,
        "crystals": bal_map[u.id].crystals if u.id in bal_map else 0,
    } for u in users]


class UserRoleUpdate(BaseModel):
    role: str  # "user" or "admin"


@router.patch("/users/{user_id}/role")
async def update_user_role(user_id: int, req: UserRoleUpdate,
                           admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    if req.role not in ("user", "admin"):
        raise HTTPException(status_code=400, detail="role は user または admin")
    result = await db.execute(select(User).where(User.id == user_id))
    u = result.scalar_one_or_none()
    if not u: raise HTTPException(status_code=404, detail="ユーザーが見つかりません")
    u.role = req.role
    await db.commit()
    return {"ok": True}


@router.patch("/users/{user_id}/suspend")
async def suspend_user(user_id: int, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    if user_id == admin.id:
        raise HTTPException(status_code=400, detail="自分自身は停止できません")
    result = await db.execute(select(User).where(User.id == user_id))
    u = result.scalar_one_or_none()
    if not u: raise HTTPException(status_code=404, detail="ユーザーが見つかりません")
    u.is_suspended = 0 if u.is_suspended else 1
    await db.commit()
    return {"ok": True, "is_suspended": bool(u.is_suspended)}


class AddPointsRequest(BaseModel):
    amount: int  # 付与ポイント数（正の整数）
    currency: str = "points"  # "points" or "crystals"


@router.post("/users/{user_id}/add-points")
async def add_points(user_id: int, req: AddPointsRequest,
                     admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    """ユーザーにポイント/クリスタルを付与する"""
    if req.amount <= 0 or req.amount > 100000:
        raise HTTPException(status_code=400, detail="amount は 1〜100000 の範囲で指定してください")
    if req.currency not in ("points", "crystals"):
        raise HTTPException(status_code=400, detail="currency は points または crystals")
    result = await db.execute(select(UserBalance).where(UserBalance.user_id == user_id))
    bal = result.scalar_one_or_none()
    if not bal:
        bal = UserBalance(user_id=user_id, points=0, crystals=0)
        db.add(bal)
        await db.flush()
    if req.currency == "points":
        bal.points += req.amount
    else:
        bal.crystals += req.amount
    tx = BalanceTransaction(
        user_id=user_id, currency=req.currency, amount=req.amount,
        type="admin_grant", memo=f"管理者({admin.display_name})が付与"
    )
    db.add(tx)
    await db.commit()
    return {"ok": True, "points": bal.points, "crystals": bal.crystals}


@router.delete("/users/{user_id}")
async def delete_user(user_id: int, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    if user_id == admin.id:
        raise HTTPException(status_code=400, detail="自分自身は削除できません")
    result = await db.execute(select(User).where(User.id == user_id))
    u = result.scalar_one_or_none()
    if not u: raise HTTPException(status_code=404, detail="ユーザーが見つかりません")
    if u.role == 'admin':
        raise HTTPException(status_code=400, detail="管理者ユーザーは削除できません。先に管理者権限を外してください")
    await db.delete(u)
    await db.commit()
    return {"ok": True}


# ─────────────────────────────────────────────
# ユーザー会話閲覧（管理者用）
# ─────────────────────────────────────────────

@router.get("/users/{user_id}/conversations")
async def list_user_conversations(user_id: int, include_deleted: bool = Query(False),
                                  admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    """指定ユーザーの会話一覧"""
    q = select(Conversation).where(Conversation.user_id == user_id)
    if not include_deleted:
        q = q.where(Conversation.is_deleted == 0)
    result = await db.execute(q.order_by(Conversation.updated_at.desc()))
    convs = result.scalars().all()
    out = []
    for c in convs:
        cr = await db.execute(select(Character.name, Character.avatar_url, Character.is_deleted).where(Character.id == c.character_id))
        char_row = cr.first()
        mr = await db.execute(
            select(func.count()).select_from(Message).where(Message.conversation_id == c.id)
        )
        msg_count = mr.scalar()
        out.append({
            "id": c.id,
            "character_id": c.character_id,
            "character_name": char_row[0] if char_row else "不明",
            "character_avatar": char_row[1] if char_row else None,
            "character_is_deleted": char_row[2] if char_row else 0,
            "title": c.title,
            "message_count": msg_count,
            "is_deleted": c.is_deleted,
            "updated_at": str(c.updated_at),
            "created_at": str(c.created_at),
        })
    return out


@router.patch("/conversations/{conv_id}/delete")
async def admin_toggle_conv_deleted(conv_id: int, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    """会話のソフトデリートをトグル（管理者）"""
    conv = (await db.execute(select(Conversation).where(Conversation.id == conv_id))).scalar_one_or_none()
    if not conv:
        raise HTTPException(status_code=404, detail="会話が見つかりません")
    conv.is_deleted = 0 if conv.is_deleted else 1
    await db.commit()
    return {"ok": True, "is_deleted": conv.is_deleted}


@router.get("/conversations/{conv_id}/messages")
async def get_conversation_messages(conv_id: int, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    """会話のメッセージ一覧（管理者用）"""
    conv = (await db.execute(select(Conversation).where(Conversation.id == conv_id))).scalar_one_or_none()
    if not conv:
        raise HTTPException(status_code=404, detail="会話が見つかりません")

    # ユーザー情報
    user = (await db.execute(select(User).where(User.id == conv.user_id))).scalar_one_or_none()
    # キャラクター情報
    char = (await db.execute(select(Character).where(Character.id == conv.character_id))).scalar_one_or_none()

    result = await db.execute(
        select(Message).where(Message.conversation_id == conv_id).order_by(Message.id)
    )
    messages = [{
        "id": m.id, "role": m.role, "content": m.content,
        "state_snapshot": m.state_snapshot if hasattr(m, 'state_snapshot') else None,
        "created_at": str(m.created_at)
    } for m in result.scalars()]

    return {
        "conversation": {
            "id": conv.id,
            "title": conv.title,
            "user_id": conv.user_id,
            "user_name": user.display_name if user else f"User#{conv.user_id}",
            "character_id": conv.character_id,
            "character_name": char.name if char else "不明",
            "character_avatar": char.avatar_url if char else None,
            "tts_styles": json.loads(char.tts_styles or "[]") if char and char.tts_styles else [],
            "created_at": str(conv.created_at),
            "updated_at": str(conv.updated_at),
        },
        "messages": messages,
    }


@router.delete("/messages/{msg_id}")
async def admin_delete_message(msg_id: int, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    """メッセージをDBから完全削除（管理者専用）"""
    msg = (await db.execute(select(Message).where(Message.id == msg_id))).scalar_one_or_none()
    if not msg:
        raise HTTPException(status_code=404, detail="メッセージが見つかりません")
    await db.delete(msg)
    await db.commit()
    return {"ok": True}


# ─────────────────────────────────────────────
# キャラクター管理
# ─────────────────────────────────────────────

@router.get("/characters")
async def list_all_characters(
    include_deleted: bool = Query(False),
    admin: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    q = select(Character).order_by(Character.id.desc())
    if not include_deleted:
        q = q.where(Character.is_deleted == 0)
    result = await db.execute(q)
    chars = result.scalars().all()
    # キャラIDごとのAIメッセージ数（role=assistant, 非削除）を一括取得
    msg_q = await db.execute(
        select(Conversation.character_id, func.count(Message.id).label("cnt"))
        .join(Message, Message.conversation_id == Conversation.id)
        .where(Message.role == "assistant", Message.is_deleted == 0)
        .group_by(Conversation.character_id)
    )
    msg_map = {row.character_id: row.cnt for row in msg_q}
    # 作成者IDから表示名を一括取得
    creator_ids = list({c.creator_id for c in chars})
    creator_map: dict[int, str] = {}
    if creator_ids:
        user_q = await db.execute(select(User.id, User.display_name).where(User.id.in_(creator_ids)))
        for row in user_q:
            creator_map[row.id] = row.display_name or f"ID:{row.id}"
    # キャラIDごとの不正報告数を一括取得
    report_q = await db.execute(
        select(CharacterReport.character_id, func.count(CharacterReport.id).label("cnt"))
        .where(CharacterReport.status == "pending")
        .group_by(CharacterReport.character_id)
    )
    report_map = {row.character_id: row.cnt for row in report_q}
    # BGMトラック名を一括取得
    from ..models.character import BgmTrack
    bgm_ids = list({c.bgm_track_id for c in chars if c.bgm_track_id})
    bgm_map: dict[int, str] = {}
    if bgm_ids:
        bgm_q = await db.execute(select(BgmTrack.id, BgmTrack.name).where(BgmTrack.id.in_(bgm_ids)))
        for row in bgm_q:
            bgm_map[row.id] = row.name
    # 音声モデル名を一括取得
    from ..models.settings import TtsVoiceModel
    voice_uuids = list({c.voice_model for c in chars if c.voice_model})
    voice_map: dict[str, str] = {}
    if voice_uuids:
        voice_q = await db.execute(select(TtsVoiceModel.speaker_uuid, TtsVoiceModel.display_name).where(TtsVoiceModel.speaker_uuid.in_(voice_uuids)))
        for row in voice_q:
            voice_map[row.speaker_uuid] = row.display_name
    return [{
        "id": c.id,
        "name": c.name,
        "avatar_url": c.avatar_url,
        "char_name": c.char_name,
        "char_age": c.char_age,
        "gender": c.gender,
        "profile": c.profile,
        "private_profile": c.private_profile,
        "first_message": c.first_message,
        "genre_story": c.genre_story,
        "genre_char_type": c.genre_char_type,
        "genre_personality": c.genre_personality,
        "keywords": c.keywords,
        "voice_model": c.voice_model,
        "voice_name": voice_map.get(c.voice_model or "", ""),
        "tts_styles": c.tts_styles,
        "bgm_mode": c.bgm_mode,
        "bgm_track_id": c.bgm_track_id,
        "bgm_name": bgm_map.get(c.bgm_track_id or 0, ""),
        "review_note": c.review_note,
        "creator_id": c.creator_id,
        "creator_name": creator_map.get(c.creator_id, f"ID:{c.creator_id}"),
        "is_deleted": c.is_deleted,
        "is_public": c.is_public,
        "review_status": c.review_status,
        "is_recommended": c.is_recommended,
        "like_count": c.like_count,
        "use_count": c.use_count,
        "msg_count": msg_map.get(c.id, 0),
        "report_count": report_map.get(c.id, 0),
        "created_at": str(c.created_at),
    } for c in chars]


class CharacterUpdate(BaseModel):
    is_public: int | None = None
    review_status: str | None = None
    is_recommended: int | None = None


@router.get("/characters/{char_id}/conversations")
async def list_character_conversations(char_id: int, include_deleted: bool = Query(False),
                                       admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    """キャラクターと会話しているユーザー一覧と各会話情報"""
    q = select(Conversation).where(Conversation.character_id == char_id)
    if not include_deleted:
        q = q.where(Conversation.is_deleted == 0)
    result = await db.execute(q.order_by(Conversation.updated_at.desc()))
    convs = result.scalars().all()
    out = []
    for c in convs:
        ur = await db.execute(select(User.display_name, User.avatar_url).where(User.id == c.user_id))
        user_row = ur.first()
        mr = await db.execute(
            select(func.count()).select_from(Message).where(Message.conversation_id == c.id)
        )
        msg_count = mr.scalar()
        out.append({
            "id": c.id,
            "user_id": c.user_id,
            "user_name": user_row[0] if user_row else f"user#{c.user_id}",
            "user_avatar": user_row[1] if user_row else None,
            "title": c.title,
            "message_count": msg_count,
            "is_deleted": c.is_deleted,
            "updated_at": str(c.updated_at),
            "created_at": str(c.created_at),
        })
    return out


@router.patch("/characters/{char_id}")
async def update_character(char_id: int, req: CharacterUpdate,
                           admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(Character).where(Character.id == char_id))
    c = result.scalar_one_or_none()
    if not c: raise HTTPException(status_code=404, detail="キャラクターが見つかりません")
    if req.is_public is not None: c.is_public = req.is_public

    # 審査ステータス変更時は通知を作成（管理者による手動変更も対象）
    prev_status = c.review_status
    if req.review_status is not None and req.review_status in ("pending", "approved", "rejected"):
        c.review_status = req.review_status
        if prev_status != req.review_status and req.review_status in ("approved", "rejected"):
            from ..services.review_service import _notify_review_result
            await _notify_review_result(
                db, c,
                approved=(req.review_status == "approved"),
                reason=(c.review_note or "管理者により判定"),
            )

    if req.is_recommended is not None: c.is_recommended = req.is_recommended
    await db.commit()
    return {"ok": True}


@router.post("/characters/{char_id}/review")
async def trigger_character_review(char_id: int, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    """AI再審査をトリガー"""
    result = await db.execute(select(Character).where(Character.id == char_id))
    c = result.scalar_one_or_none()
    if not c: raise HTTPException(status_code=404, detail="キャラクターが見つかりません")
    c.review_status = "pending"
    c.review_note = None
    await db.commit()
    # バックグラウンドで審査実行
    import asyncio
    from ..services.review_service import review_character
    asyncio.create_task(review_character(char_id))
    return {"ok": True}


@router.delete("/characters/{char_id}")
async def delete_character(char_id: int, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    """管理者によるキャラクター削除（ソフトデリート: is_deleted=2）"""
    result = await db.execute(select(Character).where(Character.id == char_id))
    c = result.scalar_one_or_none()
    if not c: raise HTTPException(status_code=404, detail="キャラクターが見つかりません")
    c.is_deleted = 2
    await db.commit()
    return {"ok": True}


@router.put("/characters/{char_id}/restore")
async def restore_character(char_id: int, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    """管理者によるキャラクター復元"""
    result = await db.execute(select(Character).where(Character.id == char_id))
    c = result.scalar_one_or_none()
    if not c: raise HTTPException(status_code=404, detail="キャラクターが見つかりません")
    c.is_deleted = 0
    await db.commit()
    return {"ok": True}


@router.delete("/characters/{char_id}/purge")
async def purge_character(char_id: int, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    """キャラクター完全削除（DBから物理削除、関連する会話・メッセージも全削除）"""
    result = await db.execute(select(Character).where(Character.id == char_id))
    c = result.scalar_one_or_none()
    if not c:
        raise HTTPException(status_code=404, detail="キャラクターが見つかりません")
    # 関連する会話を取得
    conv_result = await db.execute(select(Conversation).where(Conversation.character_id == char_id))
    convs = conv_result.scalars().all()
    for conv in convs:
        # メッセージ削除
        await db.execute(delete(Message).where(Message.conversation_id == conv.id))
        # ステータス削除
        from ..models.conversation import ConversationState, ConversationStateLog
        await db.execute(delete(ConversationStateLog).where(ConversationStateLog.conversation_id == conv.id))
        await db.execute(delete(ConversationState).where(ConversationState.conversation_id == conv.id))
        await db.delete(conv)
    # キャラクター削除
    await db.delete(c)
    await db.commit()
    return {"ok": True}


# ─────────────────────────────────────────────
# SD設定
# ─────────────────────────────────────────────

async def _get_or_create_sd(db: AsyncSession) -> SdSettings:
    result = await db.execute(select(SdSettings).where(SdSettings.id == 1))
    s = result.scalar_one_or_none()
    if not s:
        s = SdSettings(id=1)
        db.add(s)
        await db.commit()
        await db.refresh(s)
    return s


class SdSettingsUpdate(BaseModel):
    enabled: int = 0
    endpoint: str | None = None
    model: str | None = None
    negative_prompt: str = ""
    steps: int = 20
    cfg_scale: float = 7.0
    width: int = 512
    height: int = 512
    lt_endpoint: str | None = None
    lt_mode: str = "off"  # off / free / local / both
    lt_api_key: str | None = None
    max_images: int = 100
    wm_enabled: int = 0
    wm_text: str | None = None
    wm_image_path: str | None = None
    wm_opacity: float = 0.3
    wm_scale: float = 0.15


@router.get("/sd-settings")
async def get_sd_settings(admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    s = await _get_or_create_sd(db)
    return {
        "enabled": s.enabled, "endpoint": s.endpoint, "model": s.model,
        "negative_prompt": s.negative_prompt,
        "steps": s.steps, "cfg_scale": s.cfg_scale,
        "width": s.width, "height": s.height,
        "lt_endpoint": s.lt_endpoint,
        "lt_mode": s.lt_mode or "off",
        "lt_api_key": s.lt_api_key or "",
        "max_images": s.max_images if s.max_images is not None else 100,
        "wm_enabled": s.wm_enabled if s.wm_enabled is not None else 0,
        "wm_text": s.wm_text or "",
        "wm_image_path": s.wm_image_path or "",
        "wm_opacity": s.wm_opacity if s.wm_opacity is not None else 0.3,
        "wm_scale": s.wm_scale if s.wm_scale is not None else 0.15,
    }


@router.put("/sd-settings")
async def update_sd_settings(req: SdSettingsUpdate, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    s = await _get_or_create_sd(db)
    s.enabled = req.enabled; s.endpoint = req.endpoint; s.model = req.model
    s.negative_prompt = req.negative_prompt; s.steps = req.steps
    s.cfg_scale = req.cfg_scale; s.width = req.width; s.height = req.height
    s.lt_endpoint = req.lt_endpoint or None
    s.lt_mode = req.lt_mode if req.lt_mode in ("off", "free", "local", "both", "opus_mt", "opus_mt_first", "both_local_first") else "off"
    s.lt_api_key = req.lt_api_key or None
    s.max_images = max(1, req.max_images) if req.max_images else 100
    s.wm_enabled = req.wm_enabled
    s.wm_text = req.wm_text or None
    s.wm_image_path = req.wm_image_path or None
    s.wm_opacity = req.wm_opacity
    s.wm_scale = req.wm_scale
    await db.commit()
    return {"ok": True}


class SdTestRequest(BaseModel):
    endpoint: str | None = None


@router.post("/sd-test")
async def test_sd_connection(req: SdTestRequest = SdTestRequest(), admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    # フォームの入力値を優先し、なければ保存済みの値を使用
    endpoint = req.endpoint
    if not endpoint:
        s = await _get_or_create_sd(db)
        endpoint = s.endpoint
    if not endpoint:
        return {"ok": False, "error": "エンドポイントが未設定です"}
    endpoint = endpoint.rstrip("/")
    try:
        async with httpx.AsyncClient(timeout=10) as c:
            # 疎通確認: /sdapi/v1/options（軽量・現在のモデル名を取得）
            r = await c.get(f"{endpoint}/sdapi/v1/options")
            r.raise_for_status()
            opts = r.json()
            from ..services.scene_image_service import _normalize_model_name as _norm
            current_model = _norm(opts.get("sd_model_checkpoint", ""))
            # モデル一覧取得: Gradio /info API を優先（Forge対応）
            models = []
            source = ""
            try:
                ir = await c.get(f"{endpoint}/info")
                if ir.status_code == 200:
                    info = ir.json()
                    ep = info.get("named_endpoints", {}).get("/checkpoint_change", {})
                    params = ep.get("parameters", [])
                    if params:
                        enum_list = params[0].get("type", {}).get("enum", [])
                        # Gradioはファイルパス形式（A_写真風\model.safetensors）を返す
                        # 正規化してDB保存用の model_id にする
                        from ..services.scene_image_service import _normalize_model_name
                        normalized = [_normalize_model_name(name) for name in enum_list]
                        models = normalized
                        source = "gradio"
            except Exception:
                pass
            # フォールバック: sd-models API（A1111互換）
            if not models:
                try:
                    mr = await c.get(f"{endpoint}/sdapi/v1/sd-models")
                    if mr.status_code == 200:
                        from ..services.scene_image_service import _normalize_model_name
                        models = [_normalize_model_name(m.get("model_name", m.get("title", ""))) for m in mr.json()]
                        source = "sd-models"
                except Exception:
                    pass
            return {"ok": True, "models": models, "current_model": current_model, "source": source}
    except httpx.ConnectError as e:
        return {"ok": False, "error": f"Connection refused: {endpoint} に接続できません。画像生成AIが --listen --api オプションで起動しているか確認してください。"}
    except httpx.TimeoutException:
        return {"ok": False, "error": f"Timeout: {endpoint} への接続がタイムアウトしました。ポート転送・ファイアウォールを確認してください。"}
    except httpx.HTTPStatusError as e:
        return {"ok": False, "error": f"HTTP {e.response.status_code}: APIが有効か確認してください（--api オプション必要）"}
    except Exception as e:
        return {"ok": False, "error": str(e)}


class LtTestRequest(BaseModel):
    endpoint: str | None = None
    mode: str | None = None          # off / free / local / both / both_local_first
    api_key: str | None = None       # libretranslate.com APIキー


async def _test_lt_endpoint(endpoint: str, api_key: str = "") -> dict:
    """1つのLibreTranslateエンドポイントをテスト"""
    endpoint = endpoint.rstrip("/")
    try:
        async with httpx.AsyncClient(timeout=10) as c:
            r = await c.get(f"{endpoint}/languages")
            r.raise_for_status()
            langs = r.json()
            has_ja = any(l.get("code") == "ja" for l in langs)
            has_en = any(l.get("code") == "en" for l in langs)
            return {"ok": True, "ja": has_ja, "en": has_en, "endpoint": endpoint}
    except httpx.ConnectError:
        return {"ok": False, "error": f"接続できません: {endpoint}", "endpoint": endpoint}
    except httpx.TimeoutException:
        return {"ok": False, "error": f"タイムアウト: {endpoint}", "endpoint": endpoint}
    except Exception as e:
        return {"ok": False, "error": str(e), "endpoint": endpoint}


async def _test_opus_mt() -> dict:
    """opus-mt サーバー（localhost:5050）の接続テスト"""
    import httpx
    ep = "http://127.0.0.1:5050"
    try:
        api_key = ""
        try:
            api_key = open("/opt/asobi/translate/api_key.txt").read().strip()
        except Exception:
            pass
        headers = {"X-Api-Key": api_key} if api_key else {}
        async with httpx.AsyncClient(timeout=10) as c:
            r = await c.post(
                f"{ep}/translate",
                json={"text": "テスト", "mode": "opus_mt", "pipeline": True},
                headers=headers,
            )
            r.raise_for_status()
            data = r.json()
            translated = data.get("translated_text", "")
            return {"ok": bool(translated), "label": "opus-mt (サーバー内蔵)", "ja": True, "en": True, "endpoint": ep}
    except httpx.ConnectError:
        return {"ok": False, "label": "opus-mt (サーバー内蔵)", "error": f"接続できません: {ep}", "endpoint": ep}
    except httpx.TimeoutException:
        return {"ok": False, "label": "opus-mt (サーバー内蔵)", "error": f"タイムアウト: {ep}", "endpoint": ep}
    except Exception as e:
        return {"ok": False, "label": "opus-mt (サーバー内蔵)", "error": str(e), "endpoint": ep}


@router.post("/lt-test")
async def test_lt_connection(req: LtTestRequest = LtTestRequest(), admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    mode = req.mode or "local"
    s = await _get_or_create_sd(db)

    # opus_mt モードのテスト
    if mode in ("opus_mt", "opus_mt_first"):
        opus_result = await _test_opus_mt()
        results = [opus_result]
        if mode == "opus_mt_first":
            # フォールバック先（無料版）もテスト
            r = await _test_lt_endpoint("https://libretranslate.com", req.api_key or s.lt_api_key or "")
            r["label"] = "無料版 (libretranslate.com)"
            results.append(r)
        all_ok = all(r["ok"] for r in results)
        return {"ok": all_ok, "results": results}

    # テスト対象エンドポイントを構築
    targets = []  # [(label, endpoint, api_key)]
    if mode in ("free", "both", "both_local_first"):
        targets.append(("無料版 (libretranslate.com)", "https://libretranslate.com", req.api_key or s.lt_api_key or ""))
    if mode in ("local", "both", "both_local_first"):
        ep = (req.endpoint or "").strip().rstrip("/") or (s.lt_endpoint or "").rstrip("/")
        if ep:
            targets.append(("ローカル", ep, ""))

    if not targets:
        return {"ok": False, "error": "テスト対象のエンドポイントがありません", "results": []}

    # 全エンドポイントをテスト
    results = []
    for label, ep, key in targets:
        r = await _test_lt_endpoint(ep, key)
        r["label"] = label
        results.append(r)

    all_ok = all(r["ok"] for r in results)
    return {"ok": all_ok, "results": results}


# ─────────────────────────────────────────────
# プロンプトテンプレート管理
# ─────────────────────────────────────────────

def _tmpl_dict(t: PromptTemplate) -> dict:
    return {"id": t.id, "name": t.name, "prompt": t.prompt,
            "negative_prompt": t.negative_prompt, "is_active": t.is_active, "sort_order": t.sort_order}


@router.get("/sd-templates")
async def list_templates(admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(PromptTemplate).order_by(PromptTemplate.sort_order, PromptTemplate.id))
    return [_tmpl_dict(t) for t in result.scalars()]


class TemplateCreate(BaseModel):
    name: str
    prompt: str
    negative_prompt: str | None = None
    is_active: int = 1
    sort_order: int = 0


@router.post("/sd-templates")
async def create_template(req: TemplateCreate, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    t = PromptTemplate(**req.model_dump())
    db.add(t)
    await db.commit()
    await db.refresh(t)
    return _tmpl_dict(t)


@router.put("/sd-templates/{tmpl_id}")
async def update_template(tmpl_id: int, req: TemplateCreate,
                          admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(PromptTemplate).where(PromptTemplate.id == tmpl_id))
    t = result.scalar_one_or_none()
    if not t: raise HTTPException(status_code=404, detail="テンプレートが見つかりません")
    for k, v in req.model_dump().items(): setattr(t, k, v)
    await db.commit()
    return _tmpl_dict(t)


@router.delete("/sd-templates/{tmpl_id}")
async def delete_template(tmpl_id: int, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(PromptTemplate).where(PromptTemplate.id == tmpl_id))
    t = result.scalar_one_or_none()
    if not t: raise HTTPException(status_code=404, detail="テンプレートが見つかりません")
    await db.delete(t)
    await db.commit()
    return {"ok": True}


# ─────────────────────────────────────────────
# 選択可能モデル管理
# ─────────────────────────────────────────────

class SelectableModelBody(BaseModel):
    model_id: str
    display_name: str
    is_active: int = 1
    sort_order: int = 0


def _selmodel_dict(m: SdSelectableModel) -> dict:
    return {"id": m.id, "model_id": m.model_id, "display_name": m.display_name,
            "preview_image": m.preview_image or "",
            "is_active": m.is_active, "sort_order": m.sort_order, "use_count": m.use_count or 0}


@router.get("/selectable-models")
async def list_selectable_models(admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(SdSelectableModel).order_by(SdSelectableModel.sort_order, SdSelectableModel.id))
    return [_selmodel_dict(m) for m in result.scalars()]


@router.post("/selectable-models")
async def create_selectable_model(body: SelectableModelBody, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    m = SdSelectableModel(**body.model_dump())
    db.add(m)
    await db.commit()
    await db.refresh(m)
    return _selmodel_dict(m)


@router.put("/selectable-models/{model_id}")
async def update_selectable_model(model_id: int, body: SelectableModelBody, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(SdSelectableModel).where(SdSelectableModel.id == model_id))
    m = result.scalar_one_or_none()
    if not m: raise HTTPException(status_code=404, detail="モデルが見つかりません")
    m.model_id     = body.model_id
    m.display_name = body.display_name
    m.is_active    = body.is_active
    m.sort_order   = body.sort_order
    await db.commit()
    return _selmodel_dict(m)


@router.delete("/selectable-models/{model_id}")
async def delete_selectable_model(model_id: int, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(SdSelectableModel).where(SdSelectableModel.id == model_id))
    m = result.scalar_one_or_none()
    if not m: raise HTTPException(status_code=404, detail="モデルが見つかりません")
    # プレビュー画像ファイルも削除
    if m.preview_image:
        preview_path = os.path.join(_FRONTEND_ROOT, m.preview_image.lstrip("/"))
        if os.path.exists(preview_path):
            try: os.remove(preview_path)
            except OSError: pass
    await db.delete(m)
    await db.commit()
    return {"ok": True}


# ─────────────────────────────────────────────
# モデル代表画像（プレビュー）管理
# ─────────────────────────────────────────────

_MODEL_PREVIEW_DIR = "/opt/asobi/aic/frontend/images/model_previews"
_MODEL_PREVIEW_URL = "/images/model_previews"


@router.get("/selectable-models/{sm_id}/preview-candidates")
async def list_preview_candidates(
    sm_id: int,
    admin: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
    limit: int = Query(50, le=200),
    offset: int = Query(0, ge=0),
):
    """そのモデルで過去に生成された画像一覧（プレビュー候補）"""
    result = await db.execute(select(SdSelectableModel).where(SdSelectableModel.id == sm_id))
    m = result.scalar_one_or_none()
    if not m:
        raise HTTPException(status_code=404, detail="モデルが見つかりません")

    q = (
        select(UserImage.id, UserImage.url, UserImage.prompt, UserImage.created_at)
        .where(UserImage.model == m.model_id, UserImage.status.in_(["saved", "pending"]))
        .order_by(UserImage.id.desc())
        .limit(limit).offset(offset)
    )
    rows = (await db.execute(q)).all()
    total_q = select(func.count()).select_from(UserImage).where(
        UserImage.model == m.model_id, UserImage.status.in_(["saved", "pending"])
    )
    total = (await db.execute(total_q)).scalar()

    return {
        "total": total,
        "images": [
            {"id": r[0], "url": r[1], "prompt": r[2] or "", "created_at": str(r[3]) if r[3] else ""}
            for r in rows
        ],
    }


class SetPreviewBody(BaseModel):
    image_id: int


@router.post("/selectable-models/{sm_id}/preview")
async def set_model_preview(
    sm_id: int,
    body: SetPreviewBody,
    admin: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    """ユーザー画像を代表画像としてコピー・設定する"""
    result = await db.execute(select(SdSelectableModel).where(SdSelectableModel.id == sm_id))
    m = result.scalar_one_or_none()
    if not m:
        raise HTTPException(status_code=404, detail="モデルが見つかりません")

    img_result = await db.execute(select(UserImage).where(UserImage.id == body.image_id))
    img = img_result.scalar_one_or_none()
    if not img or not img.url:
        raise HTTPException(status_code=404, detail="画像が見つかりません")

    # 元画像のファイルパス
    src_path = os.path.join(_FRONTEND_ROOT, img.url.lstrip("/"))
    if not os.path.exists(src_path):
        raise HTTPException(status_code=404, detail="元画像ファイルが見つかりません")

    # プレビューディレクトリ作成
    os.makedirs(_MODEL_PREVIEW_DIR, exist_ok=True)

    # 旧プレビュー画像を削除
    if m.preview_image:
        old_path = os.path.join(_FRONTEND_ROOT, m.preview_image.lstrip("/"))
        if os.path.exists(old_path):
            try: os.remove(old_path)
            except OSError: pass

    # 新しいファイル名でコピー（元データ削除時のリンク切れ防止）
    ext = os.path.splitext(src_path)[1] or ".png"
    new_filename = f"model_{sm_id}_{uuid.uuid4().hex[:8]}{ext}"
    dst_path = os.path.join(_MODEL_PREVIEW_DIR, new_filename)
    shutil.copy2(src_path, dst_path)

    # DB更新
    m.preview_image = f"{_MODEL_PREVIEW_URL}/{new_filename}"
    await db.commit()

    return {"ok": True, "preview_image": m.preview_image}


@router.delete("/selectable-models/{sm_id}/preview")
async def delete_model_preview(
    sm_id: int,
    admin: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    """代表画像を削除する"""
    result = await db.execute(select(SdSelectableModel).where(SdSelectableModel.id == sm_id))
    m = result.scalar_one_or_none()
    if not m:
        raise HTTPException(status_code=404, detail="モデルが見つかりません")

    if m.preview_image:
        file_path = os.path.join(_FRONTEND_ROOT, m.preview_image.lstrip("/"))
        if os.path.exists(file_path):
            try: os.remove(file_path)
            except OSError: pass
        m.preview_image = None
        await db.commit()

    return {"ok": True}


# ─────────────────────────────────────────────
# 生成画像管理
# ─────────────────────────────────────────────

@router.get("/images")
async def list_all_images(
    admin: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
    status: str | None = Query(None),
    statuses: str | None = Query(None),  # カンマ区切り複数指定
    user_id: int | None = Query(None),
    rating: str | None = Query(None),   # "null"=未評価, "-1","1","2","3"=評価値
    ratings: str | None = Query(None),  # カンマ区切り複数指定
    username: str | None = Query(None), # display_name 部分一致
    limit: int = Query(200, le=500),
    offset: int = Query(0, ge=0),
):
    """全ユーザーの生成画像一覧（管理者用）"""
    from sqlalchemy import or_, and_

    def _status_clause(stat_list):
        clauses = []
        for s in stat_list:
            if s == "deleted":
                clauses.append(UserImage.is_deleted == 1)
            elif s in ("saved", "pending", "discarded"):
                clauses.append(and_(UserImage.status == s, UserImage.is_deleted == 0))
        return or_(*clauses) if clauses else None

    def _rating_clause(rating_list):
        clauses = []
        for r in rating_list:
            if r == "null":
                clauses.append(UserImage.rating == None)  # noqa: E711
            else:
                try:
                    clauses.append(UserImage.rating == int(r))
                except ValueError:
                    pass
        return or_(*clauses) if clauses else None

    q = select(UserImage, User.display_name).join(User, User.id == UserImage.user_id, isouter=True)
    # 複数ステータス
    stat_list = []
    if statuses:
        stat_list = [s.strip() for s in statuses.split(',') if s.strip()]
    elif status:
        stat_list = [status]
    sc = _status_clause(stat_list)
    if sc is not None:
        q = q.where(sc)

    if user_id:
        q = q.where(UserImage.user_id == user_id)

    # 複数評価
    rating_list = []
    if ratings:
        rating_list = [r.strip() for r in ratings.split(',') if r.strip()]
    elif rating is not None:
        rating_list = [rating]
    rc = _rating_clause(rating_list)
    if rc is not None:
        q = q.where(rc)

    if username:
        q = q.where(User.display_name.ilike(f"%{username}%"))
    q = q.order_by(UserImage.id.desc()).limit(limit).offset(offset)
    result = await db.execute(q)
    rows = result.all()

    # total count
    cq = select(func.count()).select_from(UserImage).join(User, User.id == UserImage.user_id, isouter=True)
    if sc is not None:
        cq = cq.where(sc)
    if user_id:
        cq = cq.where(UserImage.user_id == user_id)
    if rc is not None:
        cq = cq.where(rc)
    if username:
        cq = cq.where(User.display_name.ilike(f"%{username}%"))
    total = (await db.execute(cq)).scalar()

    # SD設定から画像サイズ取得
    from ..models.settings import SdSettings as _Sd
    sd_r = await db.execute(select(_Sd).where(_Sd.id == 1))
    sd = sd_r.scalar_one_or_none()

    # キャラクターで使用中のURL一覧を取得（このページの画像URLのみ）
    from ..models.character import Character
    page_urls = [img.url for img, _ in rows if img.url]
    used_urls: set[str] = set()
    if page_urls:
        char_q = await db.execute(
            select(Character.avatar_url).where(
                Character.avatar_url.in_(page_urls), Character.is_deleted == 0
            )
        )
        used_urls = {r[0] for r in char_q.all() if r[0]}

    return {
        "total": total,
        "width": sd.width if sd else 512,
        "height": sd.height if sd else 512,
        "images": [{
            "id": img.id,
            "user_id": img.user_id,
            "display_name": display_name or f"User#{img.user_id}",
            "url": img.url,
            "prompt": img.prompt,
            "original_prompt": img.original_prompt,
            "template_id": img.template_id,
            "model": img.model,
            "seed": img.seed,
            "status": img.status,
            "rating": img.rating,
            "is_deleted": img.is_deleted,
            "is_used": img.url in used_urls if img.url else False,
            "created_at": str(img.created_at) if img.created_at else "",
        } for img, display_name in rows],
    }


_FRONTEND_ROOT = "/opt/asobi/aic/frontend"


@router.delete("/images/purge-discarded")
async def admin_purge_discarded_images(
    admin: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    """破棄済み・削除済み画像を一括削除（ファイル＋DB）。キャラクター使用中の画像は除外"""
    from ..models.character import Character
    from sqlalchemy import or_
    used_q = await db.execute(
        select(Character.avatar_url).where(Character.is_deleted == 0, Character.avatar_url.isnot(None))
    )
    used_urls = {r[0] for r in used_q.all()}

    result = await db.execute(
        select(UserImage).where(or_(UserImage.status == "discarded", UserImage.is_deleted == 1))
    )
    imgs = result.scalars().all()
    deleted = 0
    skipped = 0
    for img in imgs:
        if img.url and img.url in used_urls:
            skipped += 1
            continue
        if img.url:
            file_path = os.path.join(_FRONTEND_ROOT, img.url.lstrip("/"))
            if os.path.exists(file_path):
                try:
                    os.remove(file_path)
                except OSError:
                    pass
        await db.delete(img)
        deleted += 1
    await db.commit()
    return {"ok": True, "deleted": deleted, "skipped": skipped}


@router.delete("/images/{image_id}")
async def admin_delete_image(
    image_id: int,
    admin: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    """画像ファイルを削除してからDBレコードを削除（管理者用）
    - ファイルが存在する場合: ファイル削除確認後にDB削除
    - ファイルが既に存在しない場合: DBレコードのみ削除
    """
    result = await db.execute(select(UserImage).where(UserImage.id == image_id))
    img = result.scalar_one_or_none()
    if not img:
        raise HTTPException(status_code=404, detail="画像が見つかりません")

    # キャラクターで使用中チェック
    if img.url:
        from ..models.character import Character
        char_q = await db.execute(
            select(Character.name).where(Character.avatar_url == img.url, Character.is_deleted == 0)
        )
        used_char = char_q.scalar_one_or_none()
        if used_char:
            raise HTTPException(status_code=409, detail=f"「{used_char}」で使用中のため削除できません")

    # URL → ファイルパスに変換（例: /images/avatars/gen_xxx.png → /opt/asobi/aic/frontend/images/avatars/gen_xxx.png）
    file_deleted = False
    file_existed = False
    file_path = None
    if img.url:
        file_path = os.path.join(_FRONTEND_ROOT, img.url.lstrip("/"))

    if file_path and os.path.exists(file_path):
        file_existed = True
        try:
            os.remove(file_path)
            # 削除されたことを確認
            if not os.path.exists(file_path):
                file_deleted = True
            else:
                raise HTTPException(status_code=500, detail="ファイルの削除に失敗しました（削除後も存在しています）")
        except OSError as e:
            raise HTTPException(status_code=500, detail=f"ファイル削除エラー: {e}")

    # ファイルが確認できた（削除済み or 最初から存在しない）場合のみDB削除
    await db.delete(img)
    await db.commit()

    return {
        "ok": True,
        "file_existed": file_existed,
        "file_deleted": file_deleted,
    }


# ─────────────────────────────────────────────
# 画像フィードバック一覧
# ─────────────────────────────────────────────

@router.get("/image-feedbacks")
async def list_image_feedbacks(
    admin: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
    limit: int = Query(100, le=500),
    offset: int = Query(0, ge=0),
):
    """マイナス評価フィードバック一覧"""
    from ..models.settings import PromptTemplate as _PT
    q = (
        select(ImageFeedback, User.display_name, UserImage.url, UserImage.prompt, UserImage.model, UserImage.template_id)
        .join(User, User.id == ImageFeedback.user_id, isouter=True)
        .join(UserImage, UserImage.id == ImageFeedback.image_id, isouter=True)
        .order_by(ImageFeedback.id.desc())
        .limit(limit).offset(offset)
    )
    result = await db.execute(q)
    rows = result.all()

    # テンプレート名のマップを取得
    tmpl_result = await db.execute(select(_PT.id, _PT.name))
    tmpl_map = {r[0]: r[1] for r in tmpl_result.all()}

    cq = select(func.count()).select_from(ImageFeedback)
    total = (await db.execute(cq)).scalar()

    return {
        "total": total,
        "feedbacks": [{
            "id": fb.id,
            "image_id": fb.image_id,
            "user_id": fb.user_id,
            "display_name": display_name or f"User#{fb.user_id}",
            "image_url": image_url or "",
            "prompt": prompt or "",
            "model": model or "",
            "template_name": tmpl_map.get(template_id, "") if template_id else "",
            "reasons": fb.reasons,
            "comment": fb.comment or "",
            "created_at": str(fb.created_at) if fb.created_at else "",
        } for fb, display_name, image_url, prompt, model, template_id in rows],
    }


# ─────────────────────────────────────────────
# 生成キュー管理
# ─────────────────────────────────────────────

@router.get("/queue")
async def get_queue(
    admin: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
    limit: int = Query(50, le=200),
):
    """生成キューの状態と最近のジョブ一覧"""
    # ワーカー状態
    info = await queue_worker.get_queue_info(db)

    # 最近のジョブ一覧
    result = await db.execute(
        select(GenerationQueue, User.display_name)
        .join(User, User.id == GenerationQueue.user_id, isouter=True)
        .order_by(GenerationQueue.id.desc())
        .limit(limit)
    )
    rows = result.all()

    # ステータス別集計
    counts_result = await db.execute(
        select(GenerationQueue.status, func.count())
        .group_by(GenerationQueue.status)
    )
    status_counts = {row[0]: row[1] for row in counts_result.all()}

    return {
        "worker": info,
        "status_counts": status_counts,
        "jobs": [{
            "id": job.id,
            "user_id": job.user_id,
            "display_name": display_name or f"User#{job.user_id}",
            "status": job.status,
            "prompt": (job.prompt[:80] + "...") if job.prompt and len(job.prompt) > 80 else job.prompt,
            "model": job.model,
            "batch_size": job.batch_size,
            "error_message": job.error_message,
            "created_at": str(job.created_at) if job.created_at else "",
            "started_at": str(job.started_at) if job.started_at else "",
            "completed_at": str(job.completed_at) if job.completed_at else "",
        } for job, display_name in rows],
    }


@router.post("/queue/resume")
async def admin_queue_resume(admin: User = Depends(require_admin)):
    """停止中のワーカーを再開"""
    queue_worker.resume()
    return {"ok": True}


@router.delete("/queue/{job_id}")
async def admin_cancel_job(
    job_id: int,
    admin: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    """ジョブをキャンセル（pending のみ）"""
    result = await db.execute(
        select(GenerationQueue).where(
            GenerationQueue.id == job_id,
            GenerationQueue.status == "pending",
        )
    )
    job = result.scalar_one_or_none()
    if not job:
        raise HTTPException(status_code=404, detail="キャンセルできるジョブが見つかりません")
    job.status = "cancelled"
    await db.commit()
    return {"ok": True}


@router.delete("/queue-clear/{status}")
async def admin_clear_queue(
    status: str,
    admin: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    """指定ステータスのジョブを一括削除"""
    if status not in ("completed", "failed", "cancelled"):
        raise HTTPException(status_code=400, detail="削除できるのは completed/failed/cancelled のみです")
    result = await db.execute(
        delete(GenerationQueue).where(GenerationQueue.status == status)
    )
    await db.commit()
    return {"ok": True, "deleted": result.rowcount}


# ─────────────────────────────────────────────
# チャットステータス機能設定
# ─────────────────────────────────────────────

async def _get_or_create_chat_state_config(db: AsyncSession) -> ChatStateConfig:
    result = await db.execute(select(ChatStateConfig).where(ChatStateConfig.id == 1))
    cfg = result.scalar_one_or_none()
    if not cfg:
        cfg = ChatStateConfig(id=1, enabled=0)
        db.add(cfg)
        await db.commit()
        await db.refresh(cfg)
    return cfg


@router.get("/chat-state-config")
async def get_chat_state_config(admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    cfg = await _get_or_create_chat_state_config(db)
    return {"enabled": cfg.enabled}


class ChatStateConfigUpdate(BaseModel):
    enabled: int = 0


@router.put("/chat-state-config")
async def update_chat_state_config(req: ChatStateConfigUpdate, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    cfg = await _get_or_create_chat_state_config(db)
    cfg.enabled = 1 if req.enabled else 0
    await db.commit()
    return {"ok": True}


# ─────────────────────────────────────────────
# チャット用システムプロンプト確認
# ─────────────────────────────────────────────

@router.get("/chat-prompt-info")
async def chat_prompt_info(
    admin: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    """チャット用システムプロンプトのテンプレート構造とキャラ一覧を返す"""
    from ..services.chat_service import build_system_prompt

    # キャラクター一覧（プレビュー用）
    result = await db.execute(select(Character).order_by(Character.id))
    chars = [{"id": c.id, "name": c.name, "char_name": c.char_name} for c in result.scalars()]

    return {
        "template_description": (
            "キャラクター設定からシステムプロンプトを自動構築します:\n"
            "1. キャラクター名 → 「あなたの名前は〇〇です。」\n"
            "2. 年齢 → 「年齢は〇〇です。」\n"
            "3. 性別 → 「性別は〇〇です。」\n"
            "4. プロフィール → 「プロフィール: ...」\n"
            "5. 非公開設定 → 「追加設定: ...」\n"
            "6. ジャンル設定（物語/キャラタイプ/性格/時代/ベース）\n"
            "7. キーワード\n"
            "8. 末尾固定指示: 「キャラクターとして自然に会話してください。設定に忠実に、一人称や口調を維持してください。」"
        ),
        "characters": chars,
    }


@router.get("/chat-prompt-preview/{char_id}")
async def chat_prompt_preview(
    char_id: int,
    admin: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    """指定キャラクターの実際のシステムプロンプトをプレビュー"""
    from ..services.chat_service import build_system_prompt

    result = await db.execute(select(Character).where(Character.id == char_id))
    c = result.scalar_one_or_none()
    if not c:
        raise HTTPException(status_code=404, detail="キャラクターが見つかりません")

    prompt = build_system_prompt(c)
    return {"character_name": c.name, "char_name": c.char_name, "prompt": prompt}


# ─────────────────────────────────────────────
# チャット送信ペイロード確認
# ─────────────────────────────────────────────

@router.get("/conversations/{conversation_id}/state-logs")
async def get_state_logs(
    conversation_id: int,
    admin: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    """会話のSTATE更新ログ一覧"""
    from ..models.conversation import ConversationStateLog
    result = await db.execute(
        select(ConversationStateLog)
        .where(ConversationStateLog.conversation_id == conversation_id)
        .order_by(ConversationStateLog.id.asc())
    )
    logs = result.scalars().all()
    return [
        {
            "id": l.id,
            "relationship": l.relationship,
            "mood": l.mood,
            "environment": l.environment,
            "situation": l.situation,
            "inventory": l.inventory,
            "goals": l.goals,
            "memories": json.loads(l.memories or "[]"),
            "created_at": l.created_at.strftime("%Y-%m-%d %H:%M:%S") if l.created_at else "",
        }
        for l in logs
    ]


@router.get("/chat-payload-preview/{conversation_id}")
async def chat_payload_preview(
    conversation_id: int,
    admin: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    """指定会話で実際にAIに送られる内容を順番どおりに返す"""
    from ..models.conversation import Conversation, Message, ConversationState
    from ..models.settings import AiSettings, ChatStateConfig
    from ..services.chat_service import build_system_prompt, DEFAULT_RESPONSE_GUIDELINE

    # 会話取得
    conv_result = await db.execute(select(Conversation).where(Conversation.id == conversation_id))
    conv = conv_result.scalar_one_or_none()
    if not conv:
        raise HTTPException(status_code=404, detail="会話が見つかりません")

    # キャラクター
    char_result = await db.execute(select(Character).where(Character.id == conv.character_id))
    char = char_result.scalar_one_or_none()

    # AI設定
    ai_result = await db.execute(select(AiSettings).where(AiSettings.id == 1))
    ai_settings = ai_result.scalar_one_or_none()

    # ステータス機能
    cfg_result = await db.execute(select(ChatStateConfig).where(ChatStateConfig.id == 1))
    cfg = cfg_result.scalar_one_or_none()
    state_enabled = bool(cfg and cfg.enabled)
    conv_state = None
    if state_enabled:
        st_result = await db.execute(
            select(ConversationState).where(ConversationState.conversation_id == conversation_id)
        )
        conv_state = st_result.scalar_one_or_none()

    rg = (ai_settings.response_guideline if ai_settings and ai_settings.response_guideline is not None else None)
    system_prompt = build_system_prompt(char, conv_state=conv_state, state_enabled=state_enabled, response_guideline=rg)

    # 過去メッセージ最新20件（古い順）
    msg_result = await db.execute(
        select(Message)
        .where(Message.conversation_id == conversation_id)
        .order_by(Message.id.desc())
        .limit(20)
    )
    past_messages = list(reversed(msg_result.scalars().all()))

    payload = [{"index": 0, "role": "system", "content": system_prompt}]
    for i, m in enumerate(past_messages, start=1):
        payload.append({"index": i, "role": m.role, "content": m.content})

    return {
        "conversation_id": conversation_id,
        "character_name": char.name if char else "?",
        "provider": ai_settings.provider if ai_settings else "unknown",
        "history_count": len(past_messages),
        "payload": payload,
    }


# ─────────────────────────────────────────────
# 不正報告管理
# ─────────────────────────────────────────────

@router.get("/reports")
async def list_reports(
    admin: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
    status: str = Query("pending"),
):
    """不正報告一覧（キャラ通報数・作者通報数付き）"""
    from sqlalchemy import case, literal_column
    from sqlalchemy.orm import aliased

    Reporter = aliased(User)
    Creator = aliased(User)

    # メインクエリ: 報告 + キャラ + 報告者 + 作成者
    q = (
        select(
            CharacterReport,
            Character.name.label("char_name_display"),
            Character.char_name,
            Character.creator_id,
            Reporter.display_name.label("reporter_name"),
            Creator.display_name.label("creator_name"),
        )
        .join(Character, Character.id == CharacterReport.character_id, isouter=True)
        .join(Reporter, Reporter.id == CharacterReport.user_id, isouter=True)
        .join(Creator, Creator.id == Character.creator_id, isouter=True)
    )
    if status != "all":
        q = q.where(CharacterReport.status == status)
    q = q.order_by(CharacterReport.id.desc())
    result = await db.execute(q)
    rows = result.all()

    # キャラごと通報数
    char_counts_q = await db.execute(
        select(CharacterReport.character_id, func.count())
        .group_by(CharacterReport.character_id)
    )
    char_report_counts = {row[0]: row[1] for row in char_counts_q.all()}

    # 作者ごと通報数（作者が作ったキャラへの合計通報数）
    creator_counts_q = await db.execute(
        select(Character.creator_id, func.count())
        .select_from(CharacterReport)
        .join(Character, Character.id == CharacterReport.character_id)
        .group_by(Character.creator_id)
    )
    creator_report_counts = {row[0]: row[1] for row in creator_counts_q.all()}

    return {
        "reports": [{
            "id": r.id,
            "character_id": r.character_id,
            "character_name": char_name_display or f"(削除済み#{r.character_id})",
            "char_name": char_name or "",
            "char_report_count": char_report_counts.get(r.character_id, 0),
            "creator_id": creator_id,
            "creator_name": creator_name or f"User#{creator_id}" if creator_id else "不明",
            "creator_report_count": creator_report_counts.get(creator_id, 0) if creator_id else 0,
            "reporter_id": r.user_id,
            "reporter_name": reporter_name or f"User#{r.user_id}",
            "category": r.category,
            "reason": r.reason,
            "status": r.status,
            "created_at": str(r.created_at) if r.created_at else "",
        } for r, char_name_display, char_name, creator_id, reporter_name, creator_name in rows],
    }


class ReportStatusUpdate(BaseModel):
    status: str  # reviewed / dismissed


@router.put("/reports/{report_id}")
async def update_report_status(
    report_id: int,
    req: ReportStatusUpdate,
    admin: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    """報告ステータス更新"""
    if req.status not in ("pending", "reviewed", "dismissed"):
        raise HTTPException(status_code=400, detail="無効なステータス")
    result = await db.execute(select(CharacterReport).where(CharacterReport.id == report_id))
    report = result.scalar_one_or_none()
    if not report:
        raise HTTPException(status_code=404, detail="報告が見つかりません")
    report.status = req.status
    await db.commit()
    return {"ok": True}


_API_USAGE_LOG = "/opt/asobi/aic/data/api_usage.log"


@router.get("/chat-errors")
async def get_chat_errors(admin: User = Depends(require_admin)):
    """チャットエラーログを返す（api_usage.logのtypeがerrorのエントリ）"""
    import json as _json
    errors = []
    try:
        with open(_API_USAGE_LOG, "r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if not line:
                    continue
                try:
                    entry = _json.loads(line)
                    if entry.get("type") == "error":
                        errors.append(entry)
                except Exception:
                    pass
    except Exception:
        pass
    errors.sort(key=lambda x: x.get("ts", ""), reverse=True)
    return {"errors": errors[:500]}


@router.get("/api-usage")
async def get_api_usage(admin: User = Depends(require_admin)):
    """API利用状況の集計（api_usage.logから集計）"""
    import json as _json
    import datetime as _dt
    entries = []
    try:
        with open(_API_USAGE_LOG, "r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if not line:
                    continue
                try:
                    entry = _json.loads(line)
                    entries.append(entry)
                except Exception:
                    pass
    except Exception:
        pass

    today_str = _dt.datetime.now().strftime("%Y-%m-%d")
    # 今日のチャット成功件数
    today_calls = sum(1 for e in entries if e.get("ts", "").startswith(today_str) and e.get("type") != "error")
    total_calls = sum(1 for e in entries if e.get("type") != "error")
    error_count = sum(1 for e in entries if e.get("type") == "error")

    # プロバイダ別集計（成功のみ）
    provider_counts: dict = {}
    for e in entries:
        if e.get("type") == "error":
            continue
        p = e.get("provider", "unknown")
        provider_counts[p] = provider_counts.get(p, 0) + 1

    # 直近7日分の日別集計
    daily: dict = {}
    for e in entries:
        if e.get("type") == "error":
            continue
        ts = e.get("ts", "")
        if ts:
            day = ts[:10]
            daily[day] = daily.get(day, 0) + 1
    # 直近7日だけ
    sorted_daily = sorted(daily.items(), reverse=True)[:7]

    return {
        "today_calls": today_calls,
        "total_calls": total_calls,
        "error_count": error_count,
        "by_provider": provider_counts,
        "daily": sorted_daily,
    }
