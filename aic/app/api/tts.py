"""TTS (VOICEVOX) API"""
from fastapi import APIRouter, Depends, HTTPException
from fastapi.responses import Response
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from ..database import get_db
from ..deps import require_user, require_admin
from ..models.user import User
from ..models.character import Character
from ..models.conversation import Conversation, SEMissLog
from ..models.settings import AiSettings, TtsVoiceModel
from ..services import append_api_usage_log
from pydantic import BaseModel
import httpx
import json

router = APIRouter(prefix="/api/tts", tags=["tts"])


class TTSRequest(BaseModel):
    text: str
    style_id: int | None = None    # None の場合はキャラクターの既定スタイルを使用
    speed: int | None = None       # 0〜100（50=普通）
    pitch: int | None = None       # 0〜100
    intonation: int | None = None  # 0〜100
    volume: int | None = None      # 0〜100


def _apply_voice_params(query: dict, req: TTSRequest, params_config: dict | None) -> dict:
    """0〜100 の値を min/max レンジに変換して audio_query に適用"""
    if not params_config:
        return query
    mapping = [
        ("speed",      "speedScale",      req.speed),
        ("pitch",      "pitchScale",      req.pitch),
        ("intonation", "intonationScale", req.intonation),
        ("volume",     "volumeScale",     req.volume),
    ]
    for field, query_key, val in mapping:
        if val is not None and field in params_config:
            cfg = params_config[field]
            min_v = float(cfg.get("min", 1.0))
            max_v = float(cfg.get("max", 1.0))
            actual = min_v + (max_v - min_v) * (val / 100.0)
            query[query_key] = round(actual, 4)
    return query


class SEMissRequest(BaseModel):
    name: str


async def _get_vv_url(db: AsyncSession) -> str:
    ai_res = await db.execute(select(AiSettings).where(AiSettings.id == 1))
    ai = ai_res.scalar_one_or_none()
    return (ai.voicevox_endpoint if ai and ai.voicevox_endpoint else None) or "http://127.0.0.1:50021"


@router.get("/voice-models")
async def list_voice_models(gender: str | None = None, db: AsyncSession = Depends(get_db)):
    """公開中の音声モデル一覧（キャラクター作成用）"""
    result = await db.execute(
        select(TtsVoiceModel).where(TtsVoiceModel.is_active == 1).order_by(TtsVoiceModel.sort_order, TtsVoiceModel.id)
    )
    rows = result.scalars().all()
    out = []
    for r in rows:
        # gender フィルタ
        if gender == "female" and not r.show_female:
            continue
        if gender == "male" and not r.show_male:
            continue
        if gender == "other" and not r.show_other:
            continue
        out.append({
            "id": r.id,
            "speaker_uuid": r.speaker_uuid,
            "display_name": r.display_name,
            "styles": json.loads(r.styles or "[]"),
        })
    return out


class PreviewRequest(BaseModel):
    style_id: int
    text: str = "こんにちは、よろしくお願いします。"


@router.post("/preview")
async def preview_voice(
    req: PreviewRequest,
    user: User = Depends(require_user),
    db: AsyncSession = Depends(get_db),
):
    """音声モデル試聴（キャラクター作成・編集時のプレビュー用）"""
    vv_url = await _get_vv_url(db)
    text = req.text.strip()[:100] or "こんにちは"
    try:
        async with httpx.AsyncClient(timeout=30.0) as client:
            q_res = await client.post(
                f"{vv_url}/audio_query",
                params={"text": text, "speaker": req.style_id},
            )
            q_res.raise_for_status()
            s_res = await client.post(
                f"{vv_url}/synthesis",
                params={"speaker": req.style_id},
                json=q_res.json(),
                headers={"Content-Type": "application/json"},
            )
            s_res.raise_for_status()
            return Response(content=s_res.content, media_type="audio/wav")
    except httpx.HTTPError as e:
        raise HTTPException(status_code=502, detail=f"VOICEVOX error: {str(e)}")


@router.post("/se-miss")
async def log_se_miss(
    req: SEMissRequest,
    user: User = Depends(require_user),
    db: AsyncSession = Depends(get_db),
):
    """存在しないSE名をDBに記録"""
    name = req.name.strip()[:100]
    if not name:
        return {"ok": False}
    result = await db.execute(select(SEMissLog).where(SEMissLog.se_name == name))
    row = result.scalar_one_or_none()
    if row:
        row.count = (row.count or 0) + 1
    else:
        db.add(SEMissLog(se_name=name, count=1))
    await db.commit()
    return {"ok": True}


@router.post("/{conversation_id}")
async def synthesize(
    conversation_id: int,
    req: TTSRequest,
    user: User = Depends(require_user),
    db: AsyncSession = Depends(get_db),
):
    """テキストを音声合成（会話のキャラクター設定を使用）"""
    conv_res = await db.execute(
        select(Conversation).where(Conversation.id == conversation_id, Conversation.user_id == user.id)
    )
    conv = conv_res.scalar_one_or_none()
    if not conv:
        raise HTTPException(status_code=404, detail="会話が見つかりません")

    char_res = await db.execute(select(Character).where(Character.id == conv.character_id))
    char = char_res.scalar_one_or_none()
    if not char or not char.voice_model:
        raise HTTPException(status_code=404, detail="音声設定がありません")

    # style_id が未指定の場合、キャラクターの tts_styles から既定スタイルを解決
    style_id = req.style_id
    if style_id is None:
        try:
            styles = json.loads(char.tts_styles or "[]")
            # "ノーマル" を優先、なければ先頭スタイルを使用
            normal = next((s["id"] for s in styles if s.get("name") == "ノーマル"), None)
            style_id = normal if normal is not None else (styles[0]["id"] if styles else None)
        except Exception:
            style_id = None
        if style_id is None:
            raise HTTPException(status_code=400, detail="音声スタイルが設定されていません")

    vv_url = await _get_vv_url(db)
    text = req.text.strip()[:300]
    if not text:
        raise HTTPException(status_code=400, detail="テキストが空です")

    # tts_voice_params 取得
    vp_config = None
    if req.speed is not None or req.pitch is not None or req.intonation is not None or req.volume is not None:
        try:
            ai_res = await db.execute(select(AiSettings).where(AiSettings.id == 1))
            ai = ai_res.scalar_one_or_none()
            if ai and ai.tts_voice_params:
                vp_config = json.loads(ai.tts_voice_params)
        except Exception:
            pass

    try:
        async with httpx.AsyncClient(timeout=30.0) as client:
            q_res = await client.post(
                f"{vv_url}/audio_query",
                params={"text": text, "speaker": style_id},
            )
            q_res.raise_for_status()
            query = _apply_voice_params(q_res.json(), req, vp_config)
            s_res = await client.post(
                f"{vv_url}/synthesis",
                params={"speaker": style_id},
                json=query,
                headers={"Content-Type": "application/json"},
            )
            s_res.raise_for_status()
            append_api_usage_log({
                "type": "tts",
                "site": "aic",
                "endpoint": "/api/tts",
                "user_id": user.id if user else None,
                "provider": "voicevox",
                "model": char.voice_model or "",
                "input_chars": len(text),
                "output_chars": len(s_res.content),
            })
            return Response(content=s_res.content, media_type="audio/wav")
    except httpx.HTTPError as e:
        raise HTTPException(status_code=502, detail=f"VOICEVOX error: {str(e)}")


# === 管理者用エンドポイント ===

class VoiceModelCreate(BaseModel):
    speaker_uuid: str
    speaker_name: str | None = None
    display_name: str
    genre: str | None = None
    styles: list = []
    show_female: int = 0
    show_male: int = 0
    show_other: int = 0
    is_active: int = 1
    sort_order: int = 0


class VoiceModelUpdate(VoiceModelCreate):
    pass


class VoiceModelPatch(BaseModel):
    """インライン編集用（指定フィールドのみ更新）"""
    display_name: str | None = None
    genre: str | None = None
    show_female: int | None = None
    show_male: int | None = None
    show_other: int | None = None
    is_active: int | None = None
    sort_order: int | None = None


class TestSynthRequest(BaseModel):
    style_id: int
    text: str = "こんにちは、よろしくお願いします。"


@router.post("/admin/test-synthesis")
async def admin_test_synthesis(
    req: TestSynthRequest,
    user: User = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    """管理者用：音声モデル試聴（任意のstyle_idでテスト合成）"""
    vv_url = await _get_vv_url(db)
    text = req.text.strip()[:100] or "こんにちは"
    try:
        async with httpx.AsyncClient(timeout=30.0) as client:
            q_res = await client.post(
                f"{vv_url}/audio_query",
                params={"text": text, "speaker": req.style_id},
            )
            q_res.raise_for_status()
            s_res = await client.post(
                f"{vv_url}/synthesis",
                params={"speaker": req.style_id},
                json=q_res.json(),
                headers={"Content-Type": "application/json"},
            )
            s_res.raise_for_status()
            return Response(content=s_res.content, media_type="audio/wav")
    except httpx.HTTPError as e:
        raise HTTPException(status_code=502, detail=f"VOICEVOX error: {str(e)}")


@router.get("/admin/speakers")
async def admin_get_speakers(user: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    """VOICEVOX スピーカー一覧取得（管理者のみ）"""
    vv_url = await _get_vv_url(db)
    try:
        async with httpx.AsyncClient(timeout=10.0) as client:
            res = await client.get(f"{vv_url}/speakers")
            res.raise_for_status()
            return res.json()
    except Exception as e:
        raise HTTPException(status_code=502, detail=f"VOICEVOX error: {str(e)}")


@router.post("/admin/sync-speakers")
async def admin_sync_speakers(user: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    """VOICEVOXスピーカーをDBに同期（未登録→追加、既存→styles/speaker_nameのみ更新して設定を保持）"""
    vv_url = await _get_vv_url(db)
    try:
        async with httpx.AsyncClient(timeout=10.0) as client:
            res = await client.get(f"{vv_url}/speakers")
            res.raise_for_status()
            speakers = res.json()
    except Exception as e:
        raise HTTPException(status_code=502, detail=f"VOICEVOX error: {str(e)}")

    added = 0
    updated = 0
    for sp in speakers:
        uuid = sp.get("speaker_uuid") or sp.get("uuid") or ""
        name = sp.get("name", "")
        styles = [{"id": s["id"], "name": s["name"]} for s in sp.get("styles", [])]
        if not uuid:
            continue
        result = await db.execute(select(TtsVoiceModel).where(TtsVoiceModel.speaker_uuid == uuid))
        row = result.scalar_one_or_none()
        if row:
            # 既存: speaker_name と styles のみ更新（管理者が設定した表示名・性別フラグは保持）
            row.speaker_name = name
            row.styles = json.dumps(styles, ensure_ascii=False)
            updated += 1
        else:
            # 新規: デフォルト値で追加（表示名=スピーカー名）
            db.add(TtsVoiceModel(
                speaker_uuid=uuid,
                speaker_name=name,
                display_name=name,
                genre=None,
                styles=json.dumps(styles, ensure_ascii=False),
                show_female=0,
                show_male=0,
                show_other=0,
                is_active=0,
                sort_order=99,
            ))
            added += 1

    await db.commit()
    return {"ok": True, "added": added, "updated": updated, "total": len(speakers)}


@router.get("/admin/voice-models")
async def admin_list_voice_models(user: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    """音声モデル一覧（管理者）"""
    result = await db.execute(
        select(TtsVoiceModel).order_by(TtsVoiceModel.sort_order, TtsVoiceModel.id)
    )
    rows = result.scalars().all()
    return [{
        "id": r.id,
        "speaker_uuid": r.speaker_uuid,
        "speaker_name": r.speaker_name,
        "display_name": r.display_name,
        "genre": r.genre,
        "styles": json.loads(r.styles or "[]"),
        "show_female": r.show_female,
        "show_male": r.show_male,
        "show_other": r.show_other,
        "is_active": r.is_active,
        "sort_order": r.sort_order,
    } for r in rows]


@router.post("/admin/voice-models")
async def admin_create_voice_model(req: VoiceModelCreate, user: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    row = TtsVoiceModel(
        speaker_uuid=req.speaker_uuid,
        speaker_name=req.speaker_name,
        display_name=req.display_name,
        genre=req.genre,
        styles=json.dumps(req.styles, ensure_ascii=False),
        show_female=req.show_female,
        show_male=req.show_male,
        show_other=req.show_other,
        is_active=req.is_active,
        sort_order=req.sort_order,
    )
    db.add(row)
    await db.commit()
    await db.refresh(row)
    return {"id": row.id}


@router.patch("/admin/voice-models/{vm_id}")
async def admin_patch_voice_model(vm_id: int, req: VoiceModelPatch, user: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    """インライン編集用（指定フィールドのみ更新）"""
    result = await db.execute(select(TtsVoiceModel).where(TtsVoiceModel.id == vm_id))
    row = result.scalar_one_or_none()
    if not row:
        raise HTTPException(status_code=404)
    if req.display_name is not None: row.display_name = req.display_name
    if req.genre is not None: row.genre = req.genre
    if req.show_female is not None: row.show_female = req.show_female
    if req.show_male is not None: row.show_male = req.show_male
    if req.show_other is not None: row.show_other = req.show_other
    if req.is_active is not None: row.is_active = req.is_active
    if req.sort_order is not None: row.sort_order = req.sort_order
    await db.commit()
    return {"ok": True}


@router.put("/admin/voice-models/{vm_id}")
async def admin_update_voice_model(vm_id: int, req: VoiceModelUpdate, user: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(TtsVoiceModel).where(TtsVoiceModel.id == vm_id))
    row = result.scalar_one_or_none()
    if not row:
        raise HTTPException(status_code=404)
    row.speaker_uuid = req.speaker_uuid
    row.speaker_name = req.speaker_name
    row.display_name = req.display_name
    row.genre = req.genre
    row.styles = json.dumps(req.styles, ensure_ascii=False)
    row.show_female = req.show_female
    row.show_male = req.show_male
    row.show_other = req.show_other
    row.is_active = req.is_active
    row.sort_order = req.sort_order
    await db.commit()
    return {"ok": True}


@router.delete("/admin/voice-models/{vm_id}")
async def admin_delete_voice_model(vm_id: int, user: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(TtsVoiceModel).where(TtsVoiceModel.id == vm_id))
    row = result.scalar_one_or_none()
    if not row:
        raise HTTPException(status_code=404)
    await db.delete(row)
    await db.commit()
    return {"ok": True}


@router.get("/admin/se-miss-logs")
async def admin_se_miss_logs(user: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    """SE未実装ログ一覧（管理者）"""
    from sqlalchemy import desc
    result = await db.execute(select(SEMissLog).order_by(desc(SEMissLog.count)).limit(100))
    rows = result.scalars().all()
    return [{"id": r.id, "se_name": r.se_name, "count": r.count, "last_seen": str(r.last_seen)} for r in rows]
