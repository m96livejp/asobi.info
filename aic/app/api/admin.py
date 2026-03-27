"""管理者API"""
import os
from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, func, delete
from ..database import get_db
from ..deps import require_admin
from ..models.user import User
from ..models.character import Character
from ..models.conversation import Conversation, Message
from ..models.settings import AiSettings, SdSettings, PromptTemplate, SdSelectableModel
from ..models.image import UserImage, ImageFeedback
from pydantic import BaseModel
import httpx

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


@router.get("/ai-settings")
async def get_ai_settings(admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    s = await _get_or_create_settings(db)
    return {"provider": s.provider, "endpoint": s.endpoint, "api_key": s.api_key,
            "model": s.model, "max_tokens": s.max_tokens, "cost": s.cost}


@router.put("/ai-settings")
async def update_ai_settings(req: AiSettingsUpdate, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    s = await _get_or_create_settings(db)
    s.provider = req.provider; s.endpoint = req.endpoint; s.api_key = req.api_key
    s.model = req.model; s.max_tokens = req.max_tokens; s.cost = req.cost
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
    result = await db.execute(select(User).order_by(User.id.desc()))
    users = result.scalars().all()
    return [{
        "id": u.id,
        "display_name": u.display_name,
        "role": u.role,
        "is_guest": u.asobi_user_id is None,
        "asobi_user_id": u.asobi_user_id,
        "created_at": str(u.created_at),
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


@router.delete("/users/{user_id}")
async def delete_user(user_id: int, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    if user_id == admin.id:
        raise HTTPException(status_code=400, detail="自分自身は削除できません")
    result = await db.execute(select(User).where(User.id == user_id))
    u = result.scalar_one_or_none()
    if not u: raise HTTPException(status_code=404, detail="ユーザーが見つかりません")
    await db.delete(u)
    await db.commit()
    return {"ok": True}


# ─────────────────────────────────────────────
# キャラクター管理
# ─────────────────────────────────────────────

@router.get("/characters")
async def list_all_characters(admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(Character).order_by(Character.id.desc()))
    chars = result.scalars().all()
    return [{
        "id": c.id,
        "name": c.name,
        "creator_id": c.creator_id,
        "is_public": c.is_public,
        "is_sample": c.is_sample,
        "like_count": c.like_count,
        "use_count": c.use_count,
        "created_at": str(c.created_at),
    } for c in chars]


class CharacterUpdate(BaseModel):
    is_public: int | None = None
    is_sample: int | None = None


@router.patch("/characters/{char_id}")
async def update_character(char_id: int, req: CharacterUpdate,
                           admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(Character).where(Character.id == char_id))
    c = result.scalar_one_or_none()
    if not c: raise HTTPException(status_code=404, detail="キャラクターが見つかりません")
    if req.is_public is not None: c.is_public = req.is_public
    if req.is_sample is not None: c.is_sample = req.is_sample
    await db.commit()
    return {"ok": True}


@router.delete("/characters/{char_id}")
async def delete_character(char_id: int, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(Character).where(Character.id == char_id))
    c = result.scalar_one_or_none()
    if not c: raise HTTPException(status_code=404, detail="キャラクターが見つかりません")
    await db.execute(delete(Message).where(
        Message.conversation_id.in_(
            select(Conversation.id).where(Conversation.character_id == char_id)
        )
    ))
    await db.execute(delete(Conversation).where(Conversation.character_id == char_id))
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


@router.get("/sd-settings")
async def get_sd_settings(admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    s = await _get_or_create_sd(db)
    return {
        "enabled": s.enabled, "endpoint": s.endpoint, "model": s.model,
        "negative_prompt": s.negative_prompt,
        "steps": s.steps, "cfg_scale": s.cfg_scale,
        "width": s.width, "height": s.height,
        "lt_endpoint": s.lt_endpoint,
    }


@router.put("/sd-settings")
async def update_sd_settings(req: SdSettingsUpdate, admin: User = Depends(require_admin), db: AsyncSession = Depends(get_db)):
    s = await _get_or_create_sd(db)
    s.enabled = req.enabled; s.endpoint = req.endpoint; s.model = req.model
    s.negative_prompt = req.negative_prompt; s.steps = req.steps
    s.cfg_scale = req.cfg_scale; s.width = req.width; s.height = req.height
    s.lt_endpoint = req.lt_endpoint or None
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
            # まず疎通確認
            try:
                ping = await c.get(f"{endpoint}/")
            except Exception:
                pass
            # モデル一覧取得
            r = await c.get(f"{endpoint}/sdapi/v1/sd-models")
            r.raise_for_status()
            models = [m.get("model_name", m.get("title", "")) for m in r.json()]
            return {"ok": True, "models": models}
    except httpx.ConnectError as e:
        return {"ok": False, "error": f"Connection refused: {endpoint} に接続できません。A1111 が --listen オプションで起動しているか確認してください。"}
    except httpx.TimeoutException:
        return {"ok": False, "error": f"Timeout: {endpoint} への接続がタイムアウトしました。ポート転送・ファイアウォールを確認してください。"}
    except httpx.HTTPStatusError as e:
        return {"ok": False, "error": f"HTTP {e.response.status_code}: APIが有効か確認してください（--api オプション必要）"}
    except Exception as e:
        return {"ok": False, "error": str(e)}


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
            "is_active": m.is_active, "sort_order": m.sort_order}


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
    await db.delete(m)
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
    user_id: int | None = Query(None),
    limit: int = Query(200, le=500),
    offset: int = Query(0, ge=0),
):
    """全ユーザーの生成画像一覧（管理者用）"""
    q = select(UserImage, User.display_name).join(User, User.id == UserImage.user_id, isouter=True)
    if status:
        q = q.where(UserImage.status == status)
    if user_id:
        q = q.where(UserImage.user_id == user_id)
    q = q.order_by(UserImage.id.desc()).limit(limit).offset(offset)
    result = await db.execute(q)
    rows = result.all()

    # total count
    cq = select(func.count()).select_from(UserImage)
    if status:
        cq = cq.where(UserImage.status == status)
    if user_id:
        cq = cq.where(UserImage.user_id == user_id)
    total = (await db.execute(cq)).scalar()

    return {
        "total": total,
        "images": [{
            "id": img.id,
            "user_id": img.user_id,
            "display_name": display_name or f"User#{img.user_id}",
            "url": img.url,
            "prompt": img.prompt,
            "template_id": img.template_id,
            "status": img.status,
            "is_deleted": img.is_deleted,
            "created_at": str(img.created_at) if img.created_at else "",
        } for img, display_name in rows],
    }


_FRONTEND_ROOT = "/opt/asobi/aic/frontend"


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
    q = (
        select(ImageFeedback, User.display_name, UserImage.url, UserImage.prompt)
        .join(User, User.id == ImageFeedback.user_id, isouter=True)
        .join(UserImage, UserImage.id == ImageFeedback.image_id, isouter=True)
        .order_by(ImageFeedback.id.desc())
        .limit(limit).offset(offset)
    )
    result = await db.execute(q)
    rows = result.all()

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
            "reasons": fb.reasons,
            "comment": fb.comment or "",
            "created_at": str(fb.created_at) if fb.created_at else "",
        } for fb, display_name, image_url, prompt in rows],
    }
