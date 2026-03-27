"""画像生成API（Stable Diffusion AUTOMATIC1111）- SQLiteキューベース"""
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
from ..services import queue_worker

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

    # 保存済み画像数チェック
    saved_count = (await db.execute(
        select(func.count()).select_from(UserImage)
        .where(UserImage.user_id == user.id, UserImage.status == "saved", UserImage.is_deleted == 0)
    )).scalar()
    if saved_count >= MAX_SAVED_IMAGES:
        raise HTTPException(status_code=429, detail=f"画像の保存上限（{MAX_SAVED_IMAGES}枚）に達しています。ギャラリーから削除してください。")

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

    # キューに追加
    job = GenerationQueue(
        user_id=user.id,
        prompt=req.prompt,
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
    return {"images": [{"id": img.id, "url": img.url, "prompt": img.prompt, "rating": img.rating} for img in imgs]}


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
        return {"images": [], "count": 0}
    result = await db.execute(
        select(UserImage)
        .where(UserImage.user_id == user.id, UserImage.status == "saved", UserImage.is_deleted == 0)
        .order_by(UserImage.is_favorite.desc(), UserImage.id.desc())
    )
    imgs = result.scalars().all()
    return {
        "images": [{"id": img.id, "url": img.url, "prompt": img.prompt, "is_favorite": img.is_favorite} for img in imgs],
        "count": len(imgs),
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
    img.is_deleted = 1
    await db.commit()
    return {"ok": True}


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
    return [{"id": m.id, "display_name": m.display_name} for m in result.scalars()]


@router.get("/sd-status")
async def sd_status(db: AsyncSession = Depends(get_db)):
    sd = await _get_sd_settings(db)
    return {
        "enabled":      bool(sd and sd.enabled and sd.endpoint),
        "width":        sd.width       if sd else 512,
        "height":       sd.height      if sd else 512,
        "lt_enabled":   bool(sd and sd.lt_endpoint),
    }


# ─────────────────────────────────────────────
# 翻訳（LibreTranslate プロキシ）
# ─────────────────────────────────────────────

class TranslateRequest(BaseModel):
    text: str
    source: str = "ja"
    target: str = "en"


@router.post("/translate")
async def translate_text(
    req: TranslateRequest,
    user: User | None = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    if not user or user.asobi_user_id is None:
        raise HTTPException(status_code=401, detail="ログインが必要です")

    sd = await _get_sd_settings(db)
    if not sd or not sd.lt_endpoint:
        raise HTTPException(status_code=503, detail="翻訳機能が設定されていません")

    endpoint = sd.lt_endpoint.rstrip("/")
    try:
        async with httpx.AsyncClient(timeout=30) as client:
            r = await client.post(
                f"{endpoint}/translate",
                json={"q": req.text, "source": req.source, "target": req.target, "format": "text"},
            )
            r.raise_for_status()
            data = r.json()
    except httpx.TimeoutException:
        raise HTTPException(status_code=504, detail="翻訳サービスがタイムアウトしました")
    except httpx.RequestError as e:
        raise HTTPException(status_code=502, detail=f"翻訳サービスへの接続エラー: {str(e)}")
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"翻訳エラー: {str(e)}")

    translated = data.get("translatedText", "")
    if not translated:
        raise HTTPException(status_code=500, detail="翻訳結果が空です")

    return {"translated_text": translated}
