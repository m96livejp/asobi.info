"""画像生成API（Stable Diffusion）- SQLiteキューベース"""
import os
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, func
from pydantic import BaseModel

from ..database import get_db
from ..deps import get_current_user
from ..models.user import User
from ..models.settings import SdSettings, PromptTemplate, SdSelectableModel
from ..models.image import UserImage, ImageFeedback, GenerationQueue
from ..services import queue_worker, append_api_usage_log

router = APIRouter(prefix="/api/generate", tags=["generate"])

IMAGE_DIR = "/opt/asobi/aic/frontend/images/avatars"
MAX_SAVED_IMAGES = 100
MAX_QUEUE_SIZE = 100
BATCH_SIZE = 6


async def _get_sd_settings(db: AsyncSession) -> SdSettings | None:
    result = await db.execute(select(SdSettings).where(SdSettings.id == 1))
    return result.scalar_one_or_none()


# ─────────────────────────────────────────────
# 生成（キューに追加）
# ─────────────────────────────────────────────

class ImageGenRequest(BaseModel):
    prompt: str
    negative_prompt: str | None = None
    template_id: int | None = None
    selected_model_id: int | None = None


@router.post("/image")
async def generate_image(
    req: ImageGenRequest,
    user: User | None = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    if not user or user.asobi_user_id is None:
        raise HTTPException(status_code=401, detail="ログインが必要です")

    sd = await _get_sd_settings(db)
    if not sd or not sd.enabled or not sd.endpoint:
        raise HTTPException(status_code=503, detail="画像生成が無効です。管理者にお問い合わせください。")

    # 未処理のpending画像があれば生成不可
    pending_count = (await db.execute(
        select(func.count()).select_from(UserImage)
        .where(UserImage.user_id == user.id, UserImage.status == "pending")
    )).scalar()
    if pending_count > 0:
        raise HTTPException(status_code=409, detail="前回の生成結果を保存または破棄してから生成してください")

    # 既にキューにpending/processingのジョブがある場合は拒否
    existing_job = (await db.execute(
        select(func.count()).select_from(GenerationQueue)
        .where(
            GenerationQueue.user_id == user.id,
            GenerationQueue.status.in_(["pending", "processing"]),
        )
    )).scalar()
    if existing_job > 0:
        raise HTTPException(status_code=409, detail="処理中のジョブがあります。完了をお待ちください。")

    # 保存済み画像数チェック（SD設定の max_images を使用）
    max_imgs = sd.max_images if sd.max_images is not None else MAX_SAVED_IMAGES
    saved_count = (await db.execute(
        select(func.count()).select_from(UserImage)
        .where(UserImage.user_id == user.id, UserImage.status == "saved", UserImage.is_deleted == 0)
    )).scalar()
    if saved_count >= max_imgs:
        raise HTTPException(status_code=429, detail=f"画像の保存上限（{max_imgs}枚）に達しています。ギャラリーから削除してください。")

    # キュー停止中チェック
    if queue_worker.is_stopped():
        raise HTTPException(status_code=503, detail=f"生成キューが停止中です: {queue_worker.stop_reason()}")

    # キューが満杯なら拒否
    queue_count = (await db.execute(
        select(func.count()).select_from(GenerationQueue)
        .where(GenerationQueue.status.in_(["pending", "processing"]))
    )).scalar()
    if queue_count >= MAX_QUEUE_SIZE:
        raise HTTPException(status_code=503, detail="生成キューが満杯です。しばらくしてからお試しください。")

    # 選択モデルの解決
    use_model = sd.model
    if req.selected_model_id:
        sel_result = await db.execute(
            select(SdSelectableModel).where(
                SdSelectableModel.id == req.selected_model_id,
                SdSelectableModel.is_active == 1,
            )
        )
        sel = sel_result.scalar_one_or_none()
        if sel:
            use_model = sel.model_id

    # 自動翻訳（lt_mode が off 以外なら日→英翻訳を試みる）
    final_prompt = req.prompt
    lt_mode = (sd.lt_mode if sd else None) or "off"
    if lt_mode != "off" and req.prompt:
        translated = await _auto_translate(sd, req.prompt)
        if translated:
            final_prompt = translated
            append_api_usage_log({
                "type": "translate",
                "site": "aic",
                "endpoint": "/api/generate/translate",
                "user_id": user.id if user else None,
                "provider": "auto",
                "model": "ja→en",
                "input_chars": len(req.prompt),
                "output_chars": len(translated),
            })

    # キューに追加
    job = GenerationQueue(
        user_id=user.id,
        prompt=final_prompt,
        original_prompt=req.prompt if final_prompt != req.prompt else None,
        negative_prompt=req.negative_prompt or sd.negative_prompt,
        model=use_model,
        template_id=req.template_id,
        steps=sd.steps,
        cfg_scale=sd.cfg_scale,
        width=sd.width,
        height=sd.height,
        batch_size=BATCH_SIZE,
    )
    db.add(job)
    await db.commit()
    await db.refresh(job)

    # キュー情報を返す
    info = await queue_worker.get_queue_info(db, user.id)
    return {
        "queued": True,
        "job_id": job.id,
        **info,
    }


# ─────────────────────────────────────────────
# キュー状態
# ─────────────────────────────────────────────

@router.get("/queue-status")
async def get_queue_status(
    user: User | None = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """生成キューの状態を返す"""
    user_id = user.id if user else None
    return await queue_worker.get_queue_info(db, user_id)


@router.get("/queue/{job_id}")
async def get_job_status(
    job_id: int,
    user: User | None = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """特定ジョブの状態を返す"""
    if not user:
        raise HTTPException(status_code=401, detail="ログインが必要です")
    result = await db.execute(
        select(GenerationQueue).where(
            GenerationQueue.id == job_id,
            GenerationQueue.user_id == user.id,
        )
    )
    job = result.scalar_one_or_none()
    if not job:
        raise HTTPException(status_code=404, detail="ジョブが見つかりません")
    return {
        "id": job.id,
        "status": job.status,
        "error_message": job.error_message,
        "created_at": str(job.created_at) if job.created_at else None,
        "started_at": str(job.started_at) if job.started_at else None,
        "completed_at": str(job.completed_at) if job.completed_at else None,
    }


@router.delete("/queue/{job_id}")
async def cancel_job(
    job_id: int,
    user: User | None = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """pendingジョブをキャンセル"""
    if not user:
        raise HTTPException(status_code=401, detail="ログインが必要です")
    result = await db.execute(
        select(GenerationQueue).where(
            GenerationQueue.id == job_id,
            GenerationQueue.user_id == user.id,
            GenerationQueue.status == "pending",
        )
    )
    job = result.scalar_one_or_none()
    if not job:
        raise HTTPException(status_code=404, detail="キャンセルできるジョブが見つかりません")
    job.status = "cancelled"
    await db.commit()
    return {"ok": True}


@router.post("/queue-resume")
async def queue_resume(user: User | None = Depends(get_current_user)):
    """停止中のキューを再開"""
    if not user or user.asobi_user_id is None:
        raise HTTPException(status_code=401, detail="ログインが必要です")
    queue_worker.resume()
    return {"ok": True}


# ─────────────────────────────────────────────
# Pending（生成後・保存/破棄前）
# ─────────────────────────────────────────────

@router.get("/pending")
async def get_pending_images(
    user: User | None = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """未処理の生成画像を返す（画面再訪時のレジューム用）"""
    if not user or user.asobi_user_id is None:
        return {"images": []}
    result = await db.execute(
        select(UserImage)
        .where(UserImage.user_id == user.id, UserImage.status == "pending")
        .order_by(UserImage.id)
    )
    imgs = result.scalars().all()
    return {"images": [{"id": img.id, "url": img.url, "prompt": img.prompt, "rating": img.rating, "seed": img.seed} for img in imgs]}


@router.post("/save/{image_id}")
async def save_image(
    image_id: int,
    user: User | None = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """pending画像をギャラリーに保存"""
    if not user or user.asobi_user_id is None:
        raise HTTPException(status_code=401, detail="ログインが必要です")
    result = await db.execute(
        select(UserImage).where(
            UserImage.id == image_id,
            UserImage.user_id == user.id,
            UserImage.status == "pending",
        )
    )
    img = result.scalar_one_or_none()
    if not img:
        raise HTTPException(status_code=404, detail="画像が見つかりません")
    # 透かし処理
    sd = await _get_sd_settings(db)
    if sd and sd.wm_enabled and img.url:
        try:
            from ..services.watermark import apply_watermark
            # URLからファイルパスを算出
            filename = img.url.split("/")[-1]
            file_path = os.path.join(IMAGE_DIR, filename)
            if os.path.isfile(file_path):
                apply_watermark(
                    file_path,
                    text=sd.wm_text,
                    image_wm_path=sd.wm_image_path,
                    opacity=sd.wm_opacity if sd.wm_opacity is not None else 0.3,
                    scale=sd.wm_scale if sd.wm_scale is not None else 0.15,
                )
        except Exception as e:
            import traceback
            traceback.print_exc()
            # 透かし失敗でも保存は続行
    img.status = "saved"
    await db.commit()
    return {"ok": True}


@router.post("/discard-pending")
async def discard_pending(
    user: User | None = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """pending画像を全て破棄"""
    if not user or user.asobi_user_id is None:
        raise HTTPException(status_code=401, detail="ログインが必要です")
    result = await db.execute(
        select(UserImage).where(UserImage.user_id == user.id, UserImage.status == "pending")
    )
    for img in result.scalars().all():
        img.status = "discarded"
    await db.commit()
    return {"ok": True}


@router.post("/discard/{image_id}")
async def discard_single_image(
    image_id: int,
    user: User | None = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """pending画像を1枚だけ破棄"""
    if not user or user.asobi_user_id is None:
        raise HTTPException(status_code=401, detail="ログインが必要です")
    result = await db.execute(
        select(UserImage).where(
            UserImage.id == image_id,
            UserImage.user_id == user.id,
            UserImage.status == "pending",
        )
    )
    img = result.scalar_one_or_none()
    if not img:
        raise HTTPException(status_code=404, detail="画像が見つかりません")
    img.status = "discarded"
    await db.commit()
    return {"ok": True}


# ─────────────────────────────────────────────
# 画像評価
# ─────────────────────────────────────────────

class RateRequest(BaseModel):
    rating: int | None

@router.post("/rate/{image_id}")
async def rate_image(
    image_id: int,
    req: RateRequest,
    user: User | None = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    if not user or user.asobi_user_id is None:
        raise HTTPException(status_code=401, detail="ログインが必要です")
    if req.rating is not None and req.rating not in (-1, 1, 2, 3):
        raise HTTPException(status_code=400, detail="不正な評価値です")
    result = await db.execute(
        select(UserImage).where(
            UserImage.id == image_id,
            UserImage.user_id == user.id,
            UserImage.status.in_(["pending", "saved"]),
        )
    )
    img = result.scalar_one_or_none()
    if not img:
        raise HTTPException(status_code=404, detail="画像が見つかりません")
    img.rating = req.rating
    await db.commit()
    return {"ok": True, "rating": img.rating}


class FeedbackRequest(BaseModel):
    reasons: list[str] = []
    comment: str | None = None

@router.post("/feedback/{image_id}")
async def submit_feedback(
    image_id: int,
    req: FeedbackRequest,
    user: User | None = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    if not user or user.asobi_user_id is None:
        raise HTTPException(status_code=401, detail="ログインが必要です")
    fb = ImageFeedback(
        image_id=image_id,
        user_id=user.id,
        reasons=",".join(req.reasons),
        comment=req.comment,
    )
    db.add(fb)
    await db.commit()
    return {"ok": True}


# ─────────────────────────────────────────────
# マイギャラリー
# ─────────────────────────────────────────────

@router.get("/my-images")
async def get_my_images(
    user: User | None = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    if not user or user.asobi_user_id is None:
        return {"images": [], "count": 0, "max_images": MAX_SAVED_IMAGES}
    sd = await _get_sd_settings(db)
    max_imgs = (sd.max_images if sd and sd.max_images is not None else MAX_SAVED_IMAGES)
    result = await db.execute(
        select(UserImage)
        .where(UserImage.user_id == user.id, UserImage.status == "saved", UserImage.is_deleted == 0)
        .order_by(UserImage.is_favorite.desc(), UserImage.id.desc())
    )
    imgs = result.scalars().all()
    # 画像URLからキャラクター使用状況を取得
    img_urls = [img.url for img in imgs if img.url]
    used_map: dict[str, str] = {}  # url -> character name
    if img_urls:
        from ..models.character import Character
        char_result = await db.execute(
            select(Character.avatar_url, Character.name)
            .where(Character.avatar_url.in_(img_urls), Character.is_deleted == 0)
        )
        for row in char_result:
            used_map[row.avatar_url] = row.name
    return {
        "images": [{"id": img.id, "url": img.url, "prompt": img.prompt, "is_favorite": img.is_favorite, "seed": img.seed, "used_by": used_map.get(img.url)} for img in imgs],
        "count": len(imgs),
        "max_images": max_imgs,
    }


@router.post("/my-images/{image_id}/favorite")
async def toggle_favorite(
    image_id: int,
    user: User | None = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    if not user or user.asobi_user_id is None:
        raise HTTPException(status_code=401, detail="ログインが必要です")
    result = await db.execute(
        select(UserImage).where(
            UserImage.id == image_id, UserImage.user_id == user.id,
            UserImage.status == "saved", UserImage.is_deleted == 0,
        )
    )
    img = result.scalar_one_or_none()
    if not img:
        raise HTTPException(status_code=404, detail="画像が見つかりません")
    img.is_favorite = 0 if img.is_favorite else 1
    await db.commit()
    return {"ok": True, "is_favorite": img.is_favorite}


@router.delete("/my-images/{image_id}")
async def delete_my_image(
    image_id: int,
    user: User | None = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    if not user or user.asobi_user_id is None:
        raise HTTPException(status_code=401, detail="ログインが必要です")
    result = await db.execute(
        select(UserImage).where(
            UserImage.id == image_id, UserImage.user_id == user.id,
            UserImage.status == "saved", UserImage.is_deleted == 0,
        )
    )
    img = result.scalar_one_or_none()
    if not img:
        raise HTTPException(status_code=404, detail="画像が見つかりません")
    # 使用中チェック
    if img.url:
        from ..models.character import Character
        char_result = await db.execute(
            select(Character.name).where(Character.avatar_url == img.url, Character.is_deleted == 0)
        )
        used_char = char_result.scalar_one_or_none()
        if used_char:
            raise HTTPException(status_code=409, detail=f"「{used_char}」で使用中のため削除できません")
    img.is_deleted = 1
    await db.commit()
    return {"ok": True}


@router.delete("/my-images")
async def delete_all_my_images(
    user: User | None = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """保存済み画像を一括削除（使用中のキャラクターアバターは除外）"""
    if not user or user.asobi_user_id is None:
        raise HTTPException(status_code=401, detail="ログインが必要です")
    from ..models.character import Character
    # 使用中のアバターURLを取得
    used_result = await db.execute(
        select(Character.avatar_url).where(Character.is_deleted == 0, Character.avatar_url.isnot(None))
    )
    used_urls = {r[0] for r in used_result.all()}
    # 削除対象を取得
    result = await db.execute(
        select(UserImage).where(
            UserImage.user_id == user.id,
            UserImage.status == "saved",
            UserImage.is_deleted == 0,
        )
    )
    imgs = result.scalars().all()
    deleted = 0
    skipped = 0
    for img in imgs:
        if img.url and img.url in used_urls:
            skipped += 1
            continue
        img.is_deleted = 1
        deleted += 1
    await db.commit()
    return {"ok": True, "deleted": deleted, "skipped": skipped}


# ─────────────────────────────────────────────
# テンプレート・ステータス
# ─────────────────────────────────────────────

@router.get("/templates")
async def list_templates(db: AsyncSession = Depends(get_db)):
    result = await db.execute(
        select(PromptTemplate)
        .where(PromptTemplate.is_active == 1)
        .order_by(PromptTemplate.sort_order, PromptTemplate.id)
    )
    return [
        {"id": t.id, "name": t.name, "prompt": t.prompt, "negative_prompt": t.negative_prompt}
        for t in result.scalars()
    ]


@router.get("/selectable-models")
async def list_selectable_models(db: AsyncSession = Depends(get_db)):
    result = await db.execute(
        select(SdSelectableModel)
        .where(SdSelectableModel.is_active == 1)
        .order_by(SdSelectableModel.sort_order, SdSelectableModel.id)
    )
    return [{"id": m.id, "display_name": m.display_name, "preview_image": m.preview_image or ""} for m in result.scalars()]


@router.get("/sd-status")
async def sd_status(db: AsyncSession = Depends(get_db)):
    sd = await _get_sd_settings(db)
    lt_mode = (sd.lt_mode if sd else None) or "off"
    return {
        "enabled":      bool(sd and sd.enabled and sd.endpoint),
        "width":        sd.width       if sd else 512,
        "height":       sd.height      if sd else 512,
        "lt_enabled":   lt_mode != "off",
    }


# ─────────────────────────────────────────────
# 翻訳（LibreTranslate プロキシ）
# ─────────────────────────────────────────────

LT_FREE_ENDPOINT = "https://libretranslate.com"
OPUS_MT_ENDPOINT = "http://127.0.0.1:5050"
OPUS_MT_KEY_FILE = "/opt/asobi/translate/api_key.txt"


def _read_opus_mt_key() -> str:
    try:
        return open(OPUS_MT_KEY_FILE).read().strip()
    except Exception:
        return ""


async def _translate_opus_mt(text: str) -> str | None:
    """opus-mt サーバー（localhost:5050）で翻訳"""
    import httpx
    api_key = _read_opus_mt_key()
    try:
        headers = {"X-Api-Key": api_key} if api_key else {}
        async with httpx.AsyncClient(timeout=15) as client:
            r = await client.post(
                f"{OPUS_MT_ENDPOINT}/translate",
                json={"text": text, "mode": "opus_mt", "pipeline": True},
                headers=headers,
            )
            r.raise_for_status()
            data = r.json()
            result = data.get("translated_text", "")
            if result and result != text and data.get("source") != "original":
                return result
    except Exception:
        pass
    return None


def _contains_japanese(text: str) -> bool:
    """テキストに日本語文字（ひらがな・カタカナ・漢字）が含まれるかチェック"""
    import unicodedata
    for ch in text:
        try:
            name = unicodedata.name(ch, "")
        except ValueError:
            continue
        if any(k in name for k in ("CJK", "HIRAGANA", "KATAKANA")):
            return True
    return False


async def _auto_translate(sd: SdSettings, text: str) -> str | None:
    """プロンプトを自動翻訳（日→英）。失敗時はNone（原文のまま生成）"""
    lt_mode = (sd.lt_mode if sd else None) or "off"
    if lt_mode == "off":
        return None

    # 日本語文字が含まれない場合は翻訳不要
    if not _contains_japanese(text):
        return None

    # opus_mt モード
    if lt_mode in ("opus_mt", "opus_mt_first"):
        result = await _translate_opus_mt(text)
        if result:
            return result
        if lt_mode == "opus_mt":
            return None

    lt_api_key = (sd.lt_api_key or "") if sd else ""
    endpoints = []
    if lt_mode in ("free", "both"):
        endpoints.append((LT_FREE_ENDPOINT, lt_api_key))
    if lt_mode in ("local", "both", "both_local_first"):
        if sd and sd.lt_endpoint:
            endpoints.append((sd.lt_endpoint.rstrip("/"), ""))
    if lt_mode == "both_local_first" and len(endpoints) == 2:
        endpoints.reverse()
    for ep, key in endpoints:
        result = await _try_translate(ep, text, "ja", "en", api_key=key)
        if result:
            return result
    return None


class TranslateRequest(BaseModel):
    text: str
    source: str = "ja"
    target: str = "en"


async def _try_translate(endpoint: str, text: str, source: str, target: str, api_key: str = "") -> str | None:
    """1つのエンドポイントで翻訳を試みる。成功時はテキスト、失敗時はNone"""
    import httpx
    try:
        payload: dict = {"q": text, "source": source, "target": target, "format": "text"}
        if api_key:
            payload["api_key"] = api_key
        async with httpx.AsyncClient(timeout=15) as client:
            r = await client.post(f"{endpoint}/translate", json=payload)
            r.raise_for_status()
            data = r.json()
            result = data.get("translatedText", "")
            return result if result else None
    except Exception:
        return None


@router.post("/translate")
async def translate_text(
    req: TranslateRequest,
    user: User | None = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    if not user or user.asobi_user_id is None:
        raise HTTPException(status_code=401, detail="ログインが必要です")

    sd = await _get_sd_settings(db)
    lt_mode = (sd.lt_mode if sd else None) or "off"
    if lt_mode == "off":
        raise HTTPException(status_code=503, detail="翻訳機能が無効です")

    # 日本語文字が含まれない場合は翻訳不要（原文をそのまま返す）
    if req.source == "ja" and not _contains_japanese(req.text):
        return {"translated_text": req.text, "source": "original", "skipped": True}

    # opus_mt モード
    if lt_mode in ("opus_mt", "opus_mt_first"):
        result = await _translate_opus_mt(req.text)
        if result:
            append_api_usage_log({
                "type": "translate",
                "site": "aic",
                "endpoint": "/api/translate",
                "user_id": user.id if user else None,
                "provider": "opus_mt",
                "model": f"{req.source}→{req.target}",
                "input_chars": len(req.text),
                "output_chars": len(result),
            })
            return {"translated_text": result, "source": "opus_mt"}
        if lt_mode == "opus_mt":
            raise HTTPException(status_code=502, detail="翻訳に失敗しました（opus_mt）")
        # opus_mt_first はフォールバック継続

    lt_api_key = (sd.lt_api_key or "") if sd else ""

    # 試行するエンドポイントを構築
    endpoints = []
    if lt_mode in ("free", "both", "both_local_first", "opus_mt_first"):
        endpoints.append(("無料版", LT_FREE_ENDPOINT, lt_api_key))
    if lt_mode in ("local", "both", "both_local_first"):
        if sd and sd.lt_endpoint:
            endpoints.append(("ローカル", sd.lt_endpoint.rstrip("/"), ""))
    if lt_mode == "both_local_first" and len(endpoints) == 2:
        endpoints.reverse()

    if not endpoints:
        raise HTTPException(status_code=503, detail="翻訳エンドポイントが設定されていません")

    # 順番に試行（フォールバック）
    last_label = ""
    for label, endpoint, key in endpoints:
        last_label = label
        result = await _try_translate(endpoint, req.text, req.source, req.target, api_key=key)
        if result:
            append_api_usage_log({
                "type": "translate",
                "site": "aic",
                "endpoint": "/api/translate",
                "user_id": user.id if user else None,
                "provider": label,
                "model": f"{req.source}→{req.target}",
                "input_chars": len(req.text),
                "output_chars": len(result),
            })
            return {"translated_text": result, "source": label}

    raise HTTPException(status_code=502, detail=f"翻訳に失敗しました（{last_label}）")
