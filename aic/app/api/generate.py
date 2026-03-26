"""画像生成API（Stable Diffusion AUTOMATIC1111）"""
import base64
import os
import uuid as uuid_mod
from datetime import datetime
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, func, update
from pydantic import BaseModel
import httpx

from ..database import get_db
from ..deps import get_current_user
from ..models.user import User
from ..models.settings import SdSettings, PromptTemplate
from ..models.image import UserImage

router = APIRouter(prefix="/api/generate", tags=["generate"])

IMAGE_DIR = "/opt/asobi/aic/frontend/images/avatars"
IMAGE_URL_BASE = "/images/avatars"
MAX_SAVED_IMAGES = 100
BATCH_SIZE = 6


async def _get_sd_settings(db: AsyncSession) -> SdSettings | None:
    result = await db.execute(select(SdSettings).where(SdSettings.id == 1))
    return result.scalar_one_or_none()


# ─────────────────────────────────────────────
# 生成
# ─────────────────────────────────────────────

class ImageGenRequest(BaseModel):
    prompt: str
    negative_prompt: str | None = None
    template_id: int | None = None


@router.post("/image")
async def generate_image(
    req: ImageGenRequest,
    user: User | None = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    if not user or user.is_guest:
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

    # 保存済み画像数チェック
    saved_count = (await db.execute(
        select(func.count()).select_from(UserImage)
        .where(UserImage.user_id == user.id, UserImage.status == "saved", UserImage.is_deleted == 0)
    )).scalar()
    if saved_count >= MAX_SAVED_IMAGES:
        raise HTTPException(status_code=429, detail=f"画像の保存上限（{MAX_SAVED_IMAGES}枚）に達しています。ギャラリーから削除してください。")

    endpoint = sd.endpoint.rstrip("/")
    payload = {
        "prompt": req.prompt,
        "negative_prompt": req.negative_prompt or sd.negative_prompt,
        "steps": sd.steps,
        "cfg_scale": sd.cfg_scale,
        "width": sd.width,
        "height": sd.height,
        "sampler_name": "DPM++ 2M Karras",
        "batch_size": BATCH_SIZE,
        "n_iter": 1,
    }
    if sd.model:
        payload["override_settings"] = {"sd_model_checkpoint": sd.model}

    try:
        async with httpx.AsyncClient(timeout=180) as client:
            r = await client.post(f"{endpoint}/sdapi/v1/txt2img", json=payload)
            r.raise_for_status()
            data = r.json()
    except httpx.TimeoutException:
        raise HTTPException(status_code=504, detail="タイムアウト: 画像生成に時間がかかっています")
    except httpx.RequestError as e:
        raise HTTPException(status_code=502, detail=f"SD接続エラー: {str(e)}")
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"生成エラー: {str(e)}")

    images = data.get("images", [])
    if not images:
        raise HTTPException(status_code=500, detail="画像が生成されませんでした")

    # ファイル保存 & DB登録（status=pending）
    os.makedirs(IMAGE_DIR, exist_ok=True)
    ts = datetime.now().strftime("%Y%m%d%H%M%S")
    db_images = []

    for img_b64 in images[:BATCH_SIZE]:
        uid = uuid_mod.uuid4().hex[:8]
        filename = f"gen_{ts}_{uid}.png"
        save_path = os.path.join(IMAGE_DIR, filename)
        with open(save_path, "wb") as f:
            f.write(base64.b64decode(img_b64))
        url = f"{IMAGE_URL_BASE}/{filename}"
        img_record = UserImage(
            user_id=user.id,
            url=url,
            prompt=req.prompt,
            template_id=req.template_id,
            status="pending",
        )
        db.add(img_record)
        db_images.append(img_record)

    await db.commit()
    for img in db_images:
        await db.refresh(img)

    return {
        "urls": [img.url for img in db_images],
        "ids": [img.id for img in db_images],
    }


# ─────────────────────────────────────────────
# Pending（生成後・保存/破棄前）
# ─────────────────────────────────────────────

@router.get("/pending")
async def get_pending_images(
    user: User | None = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """未処理の生成画像を返す（画面再訪時のレジューム用）"""
    if not user or user.is_guest:
        return {"images": []}
    result = await db.execute(
        select(UserImage)
        .where(UserImage.user_id == user.id, UserImage.status == "pending")
        .order_by(UserImage.id)
    )
    imgs = result.scalars().all()
    return {"images": [{"id": img.id, "url": img.url, "prompt": img.prompt} for img in imgs]}


@router.post("/save/{image_id}")
async def save_image(
    image_id: int,
    user: User | None = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """pending画像をギャラリーに保存"""
    if not user or user.is_guest:
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
    """pending画像を全て破棄（ファイルはサーバーに残す）"""
    if not user or user.is_guest:
        raise HTTPException(status_code=401, detail="ログインが必要です")
    result = await db.execute(
        select(UserImage).where(UserImage.user_id == user.id, UserImage.status == "pending")
    )
    for img in result.scalars().all():
        img.status = "discarded"
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
    """保存済み画像一覧とカウント"""
    if not user or user.is_guest:
        return {"images": [], "count": 0}
    result = await db.execute(
        select(UserImage)
        .where(UserImage.user_id == user.id, UserImage.status == "saved", UserImage.is_deleted == 0)
        .order_by(UserImage.id.desc())
    )
    imgs = result.scalars().all()
    count = len(imgs)
    return {
        "images": [{"id": img.id, "url": img.url, "prompt": img.prompt} for img in imgs],
        "count": count,
    }


@router.delete("/my-images/{image_id}")
async def delete_my_image(
    image_id: int,
    user: User | None = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """ギャラリー画像をソフトデリート（ファイルはサーバーに残す）"""
    if not user or user.is_guest:
        raise HTTPException(status_code=401, detail="ログインが必要です")
    result = await db.execute(
        select(UserImage).where(
            UserImage.id == image_id,
            UserImage.user_id == user.id,
            UserImage.status == "saved",
            UserImage.is_deleted == 0,
        )
    )
    img = result.scalar_one_or_none()
    if not img:
        raise HTTPException(status_code=404, detail="画像が見つかりません")
    img.is_deleted = 1
    await db.commit()
    return {"ok": True}


# ─────────────────────────────────────────────
# テンプレート・ステータス（公開エンドポイント）
# ─────────────────────────────────────────────

@router.get("/templates")
async def list_templates(db: AsyncSession = Depends(get_db)):
    """有効なプロンプトテンプレート一覧（ユーザー向け）"""
    result = await db.execute(
        select(PromptTemplate)
        .where(PromptTemplate.is_active == 1)
        .order_by(PromptTemplate.sort_order, PromptTemplate.id)
    )
    return [
        {"id": t.id, "name": t.name, "prompt": t.prompt, "negative_prompt": t.negative_prompt}
        for t in result.scalars()
    ]


@router.get("/sd-status")
async def sd_status(db: AsyncSession = Depends(get_db)):
    """SD機能が有効かどうかを返す（フロントエンド表示切替用）"""
    sd = await _get_sd_settings(db)
    return {"enabled": bool(sd and sd.enabled and sd.endpoint)}
