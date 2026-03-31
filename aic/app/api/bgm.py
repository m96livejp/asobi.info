"""BGM管理API"""
import os
import uuid
from fastapi import APIRouter, Depends, HTTPException, UploadFile, File, Form, Query
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from ..database import get_db
from ..deps import require_user
from ..models.user import User
from ..models.character import BgmTrack, Character
from pydantic import BaseModel

router = APIRouter(prefix="/api/bgm", tags=["bgm"])

BGM_DIR = "/opt/asobi/aic/frontend/bgm"
BGM_URL_PREFIX = "/bgm"
ALLOWED_EXTS = {".mp3", ".ogg", ".wav", ".m4a", ".webm"}


def _track_to_dict(t: BgmTrack) -> dict:
    return {
        "id": t.id,
        "name": t.name,
        "genre": t.genre or "",
        "enabled": t.enabled or 0,
        "url": BGM_URL_PREFIX + "/" + os.path.basename(t.file_path),
    }


# ── BGM一覧取得（誰でも）
@router.get("")
async def list_bgm_tracks(
    enabled_only: bool = Query(False, description="有効なBGMのみ返す"),
    db: AsyncSession = Depends(get_db),
):
    query = select(BgmTrack).order_by(BgmTrack.genre, BgmTrack.name)
    if enabled_only:
        query = query.where(BgmTrack.enabled == 1)
    result = await db.execute(query)
    tracks = result.scalars().all()
    return [_track_to_dict(t) for t in tracks]


# ── ジャンル一覧取得
@router.get("/genres")
async def list_bgm_genres(db: AsyncSession = Depends(get_db)):
    """登録済みBGMのジャンル一覧を返す"""
    result = await db.execute(select(BgmTrack.genre).distinct())
    genres = sorted(set(g for (g,) in result if g))
    return genres


# ── BGMアップロード（管理者のみ）
@router.post("/upload")
async def upload_bgm(
    file: UploadFile = File(...),
    name: str = Form(...),
    genre: str = Form(""),
    user: User = Depends(require_user),
    db: AsyncSession = Depends(get_db),
):
    if user.role != "admin":
        raise HTTPException(status_code=403, detail="管理者のみ操作できます")

    # 拡張子チェック
    _, ext = os.path.splitext(file.filename or "")
    ext = ext.lower()
    if ext not in ALLOWED_EXTS:
        raise HTTPException(status_code=400, detail=f"対応形式: {', '.join(ALLOWED_EXTS)}")

    # ディレクトリ作成
    os.makedirs(BGM_DIR, exist_ok=True)

    # ファイル保存
    file_name = f"bgm_{uuid.uuid4().hex[:12]}{ext}"
    file_path = os.path.join(BGM_DIR, file_name)
    content = await file.read()
    with open(file_path, "wb") as f:
        f.write(content)

    track = BgmTrack(name=name.strip(), file_path=file_path, genre=genre.strip(), enabled=0)
    db.add(track)
    await db.commit()
    await db.refresh(track)

    return _track_to_dict(track)


# ── BGM更新（管理者のみ）
@router.patch("/{track_id}")
async def update_bgm(
    track_id: int,
    user: User = Depends(require_user),
    db: AsyncSession = Depends(get_db),
    name: str | None = None,
    genre: str | None = None,
    enabled: int | None = None,
):
    if user.role != "admin":
        raise HTTPException(status_code=403, detail="管理者のみ操作できます")

    track = (await db.execute(select(BgmTrack).where(BgmTrack.id == track_id))).scalar_one_or_none()
    if not track:
        raise HTTPException(status_code=404, detail="BGMトラックが見つかりません")

    if name is not None:
        track.name = name.strip()
    if genre is not None:
        track.genre = genre.strip()
    if enabled is not None:
        track.enabled = 1 if enabled else 0

    await db.commit()
    await db.refresh(track)
    return _track_to_dict(track)


class BgmUpdateBody(BaseModel):
    name: str | None = None
    genre: str | None = None
    enabled: int | None = None


@router.put("/{track_id}")
async def update_bgm_json(
    track_id: int,
    body: BgmUpdateBody,
    user: User = Depends(require_user),
    db: AsyncSession = Depends(get_db),
):
    """JSON bodyでBGM更新（管理画面から使用）"""
    if user.role != "admin":
        raise HTTPException(status_code=403, detail="管理者のみ操作できます")

    track = (await db.execute(select(BgmTrack).where(BgmTrack.id == track_id))).scalar_one_or_none()
    if not track:
        raise HTTPException(status_code=404, detail="BGMトラックが見つかりません")

    if body.name is not None:
        track.name = body.name.strip()
    if body.genre is not None:
        track.genre = body.genre.strip()
    if body.enabled is not None:
        track.enabled = 1 if body.enabled else 0

    await db.commit()
    await db.refresh(track)
    return _track_to_dict(track)


# ── BGM削除（管理者のみ）
@router.delete("/{track_id}")
async def delete_bgm(track_id: int, user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    if user.role != "admin":
        raise HTTPException(status_code=403, detail="管理者のみ操作できます")

    track = (await db.execute(select(BgmTrack).where(BgmTrack.id == track_id))).scalar_one_or_none()
    if not track:
        raise HTTPException(status_code=404, detail="BGMトラックが見つかりません")

    # ファイル削除
    try:
        if os.path.exists(track.file_path):
            os.remove(track.file_path)
    except Exception:
        pass

    # このBGMを使用しているキャラクターのbgm_track_idをnullに
    chars_result = await db.execute(select(Character).where(Character.bgm_track_id == track_id))
    for char in chars_result.scalars().all():
        char.bgm_track_id = None
        if char.bgm_mode == "manual":
            char.bgm_mode = "none"

    await db.delete(track)
    await db.commit()
    return {"deleted": True}


# ── BGM自動選択（チャット中のauto modeで使用）
class BgmSuggestRequest(BaseModel):
    current_track_id: int | None = None


@router.post("/suggest")
async def suggest_bgm(
    req: BgmSuggestRequest,
    conv_id: int,
    user: User = Depends(require_user),
    db: AsyncSession = Depends(get_db),
):
    """AIにBGM変更が必要かどうかを判断させる"""
    from ..models.conversation import Conversation, Message
    from ..services.chat_service import get_stream_func, get_ai_settings

    conv = (await db.execute(
        select(Conversation).where(Conversation.id == conv_id, Conversation.user_id == user.id)
    )).scalar_one_or_none()
    if not conv:
        raise HTTPException(status_code=404, detail="会話が見つかりません")

    char = (await db.execute(select(Character).where(Character.id == conv.character_id))).scalar_one_or_none()
    if not char or char.bgm_mode != "auto":
        return {"change": False}

    # 有効なBGMトラックのみ使用
    tracks_result = await db.execute(
        select(BgmTrack).where(BgmTrack.enabled == 1).order_by(BgmTrack.id)
    )
    tracks = tracks_result.scalars().all()
    if not tracks:
        return {"change": False}

    # 最新メッセージ数件を取得（BGM判断のコンテキスト）
    msgs_result = await db.execute(
        select(Message)
        .where(Message.conversation_id == conv_id, Message.is_deleted == 0)
        .order_by(Message.id.desc()).limit(6)
    )
    recent_msgs = list(reversed(msgs_result.scalars().all()))
    msg_context = "\n".join(f"[{m.role}]: {m.content[:200]}" for m in recent_msgs)

    # 現在のBGM名
    current_name = "なし"
    if req.current_track_id:
        cur = next((t for t in tracks if t.id == req.current_track_id), None)
        if cur:
            current_name = cur.name

    track_list = "\n".join(
        f"ID:{t.id} - {t.name}" + (f" [{t.genre}]" if t.genre else "")
        for t in tracks
    )

    system = (
        "あなたは会話の雰囲気に合わせてBGMを選ぶAIです。\n"
        "以下の会話内容と現在のBGMを元に、BGMの変更が必要かどうかを判断してください。\n"
        "現在のBGM: " + current_name + "\n"
        "利用可能なBGM一覧:\n" + track_list + "\n\n"
        "以下のJSON形式のみで返答してください（他のテキストは含めないこと）:\n"
        '{"change": true, "track_id": <ID>} または {"change": false}'
    )
    messages = [{"role": "user", "content": "最近の会話:\n" + msg_context}]

    ai_settings = await get_ai_settings(db)
    stream_func = get_stream_func(ai_settings.provider if ai_settings else "ollama")

    full = ""
    try:
        async for chunk in stream_func(system, messages, ai_settings):
            full += chunk
            if len(full) > 200:
                break
    except Exception:
        return {"change": False}

    import json as _json
    try:
        data = _json.loads(full.strip())
        if data.get("change") and data.get("track_id"):
            # 有効なtrack_idか確認
            valid = any(t.id == int(data["track_id"]) for t in tracks)
            if valid:
                return {"change": True, "track_id": int(data["track_id"])}
    except Exception:
        pass

    return {"change": False}
