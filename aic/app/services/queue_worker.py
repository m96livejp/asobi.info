"""SD画像生成キューワーカー（バックグラウンドタスク）"""
import asyncio
import base64
import os
import uuid as uuid_mod
from datetime import datetime
from sqlalchemy import select, func, update
from sqlalchemy.ext.asyncio import AsyncSession
import httpx

from ..database import async_session
from ..models.image import GenerationQueue, UserImage, SceneImageTask
from ..models.settings import SdSettings, SdSelectableModel

IMAGE_DIR = "/opt/asobi/aic/frontend/images/avatars"
IMAGE_URL_BASE = "/images/avatars"
BATCH_SIZE = 6
POLL_INTERVAL = 2  # seconds

# ワーカー状態
_worker_stopped = False
_worker_stop_reason = ""
_worker_task: asyncio.Task | None = None


def is_stopped() -> bool:
    return _worker_stopped


def stop_reason() -> str:
    return _worker_stop_reason


def resume():
    global _worker_stopped, _worker_stop_reason
    _worker_stopped = False
    _worker_stop_reason = ""


async def _get_sd_settings(db: AsyncSession) -> SdSettings | None:
    result = await db.execute(select(SdSettings).where(SdSettings.id == 1))
    return result.scalar_one_or_none()


async def _get_avg_generation_seconds(db: AsyncSession) -> float:
    """直近10件の完了ジョブから平均生成時間を算出"""
    result = await db.execute(
        select(GenerationQueue)
        .where(
            GenerationQueue.status == "completed",
            GenerationQueue.started_at.isnot(None),
            GenerationQueue.completed_at.isnot(None),
        )
        .order_by(GenerationQueue.id.desc())
        .limit(10)
    )
    jobs = result.scalars().all()
    if not jobs:
        return 60.0  # デフォルト60秒
    total = 0.0
    count = 0
    for job in jobs:
        if job.started_at and job.completed_at:
            delta = (job.completed_at - job.started_at).total_seconds()
            if delta > 0:
                total += delta
                count += 1
    return total / count if count > 0 else 60.0


async def get_queue_info(db: AsyncSession, user_id: int | None = None) -> dict:
    """キュー状態を返す"""
    # 待ち件数（pending + processing）
    pending_count = (await db.execute(
        select(func.count()).select_from(GenerationQueue)
        .where(GenerationQueue.status.in_(["pending", "processing"]))
    )).scalar() or 0

    # 処理中のジョブ
    current_result = await db.execute(
        select(GenerationQueue).where(GenerationQueue.status == "processing").limit(1)
    )
    current_job = current_result.scalar_one_or_none()

    # ユーザーの順番
    my_position = None
    my_job_id = None
    if user_id:
        my_pending = await db.execute(
            select(GenerationQueue)
            .where(
                GenerationQueue.user_id == user_id,
                GenerationQueue.status.in_(["pending", "processing"]),
            )
            .order_by(GenerationQueue.id)
            .limit(1)
        )
        my_job = my_pending.scalar_one_or_none()
        if my_job:
            my_job_id = my_job.id
            if my_job.status == "processing":
                my_position = 0
            else:
                ahead_count = (await db.execute(
                    select(func.count()).select_from(GenerationQueue)
                    .where(
                        GenerationQueue.status.in_(["pending", "processing"]),
                        GenerationQueue.id < my_job.id,
                    )
                )).scalar() or 0
                my_position = ahead_count

    avg_seconds = await _get_avg_generation_seconds(db)
    estimated_wait = int(my_position * avg_seconds) if my_position is not None else None

    return {
        "queue_length": pending_count,
        "my_position": my_position,
        "my_job_id": my_job_id,
        "estimated_wait_seconds": estimated_wait,
        "avg_generation_seconds": int(avg_seconds),
        "is_stopped": _worker_stopped,
        "stop_reason": _worker_stop_reason,
        "current_job": {
            "id": current_job.id,
            "user_id": current_job.user_id,
            "started_at": str(current_job.started_at) if current_job.started_at else None,
        } if current_job else None,
    }


async def _process_job(job: GenerationQueue, db: AsyncSession):
    """1件のジョブをSD APIに送信して処理する"""
    global _worker_stopped, _worker_stop_reason

    sd = await _get_sd_settings(db)
    if not sd or not sd.enabled or not sd.endpoint:
        job.status = "failed"
        job.error_message = "SD設定が無効です"
        job.completed_at = datetime.now()
        await db.commit()
        return

    endpoint = sd.endpoint.rstrip("/")
    payload = {
        "prompt": job.prompt,
        "negative_prompt": job.negative_prompt or sd.negative_prompt,
        "steps": job.steps or sd.steps,
        "cfg_scale": job.cfg_scale or sd.cfg_scale,
        "width": job.width or sd.width,
        "height": job.height or sd.height,
        "sampler_name": "DPM++ 2M Karras",
        "batch_size": job.batch_size or BATCH_SIZE,
        "n_iter": 1,
    }
    # モデル指定: Forge の Gradio /run/checkpoint_change で実際にモデルをVRAMにロードする
    # （/sdapi/v1/options POST は名前を変えるだけでロードしない）
    if job.model:
        from .scene_image_service import _resolve_model_for_options, _normalize_model_name
        resolved_model = await _resolve_model_for_options(endpoint, job.model)
        print(f"[queue_worker] job.model={job.model!r}  resolved={resolved_model!r}")
        try:
            # 現在のモデルを確認
            async with httpx.AsyncClient(timeout=10) as mc:
                opts_r = await mc.get(f"{endpoint}/sdapi/v1/options")
                current = opts_r.json().get("sd_model_checkpoint") if opts_r.status_code == 200 else None
            current_norm = _normalize_model_name(current) if current else ""
            resolved_norm = _normalize_model_name(resolved_model)
            print(f"[queue_worker] current={current!r} (norm={current_norm!r})  target={resolved_model!r} (norm={resolved_norm!r})")
            if current_norm != resolved_norm:
                print(f"[queue_worker] switching model via Gradio API...")
                async with httpx.AsyncClient(timeout=300) as mc:
                    switch_r = await mc.post(f"{endpoint}/run/checkpoint_change",
                                  json={"data": [resolved_model]})
                    print(f"[queue_worker] checkpoint_change response: {switch_r.status_code}")
                    if switch_r.status_code != 200:
                        print(f"[queue_worker] checkpoint_change error: {switch_r.text[:300]}")
            else:
                print(f"[queue_worker] model already loaded")
        except Exception as e:
            print(f"[queue_worker] model switch failed: {e}")

    try:
        async with httpx.AsyncClient(timeout=180) as client:
            r = await client.post(f"{endpoint}/sdapi/v1/txt2img", json=payload)
            if r.status_code != 200:
                # SD WebUIのエラー詳細を取得
                try:
                    body = r.text[:500]
                except Exception:
                    body = "(レスポンス読取不可)"
                _worker_stopped = True
                _worker_stop_reason = f"SD API {r.status_code}: {body}"
                job.status = "failed"
                job.error_message = _worker_stop_reason
                job.completed_at = datetime.now()
                await db.commit()
                return
            data = r.json()
    except httpx.TimeoutException:
        _worker_stopped = True
        _worker_stop_reason = "タイムアウト: 画像生成に時間がかかっています"
        job.status = "failed"
        job.error_message = _worker_stop_reason
        job.completed_at = datetime.now()
        await db.commit()
        return
    except httpx.RequestError as e:
        _worker_stopped = True
        _worker_stop_reason = f"SD接続エラー: {str(e)}"
        job.status = "failed"
        job.error_message = _worker_stop_reason
        job.completed_at = datetime.now()
        await db.commit()
        return
    except Exception as e:
        _worker_stopped = True
        _worker_stop_reason = f"生成エラー: {str(e)}"
        job.status = "failed"
        job.error_message = _worker_stop_reason
        job.completed_at = datetime.now()
        await db.commit()
        return

    images = data.get("images", [])
    if not images:
        job.status = "failed"
        job.error_message = "画像が生成されませんでした"
        job.completed_at = datetime.now()
        await db.commit()
        return

    # シード値を取得（SD WebUIは info に JSON文字列で all_seeds を返す）
    all_seeds = []
    try:
        import json as _json
        info_str = data.get("info", "")
        if isinstance(info_str, str):
            info_obj = _json.loads(info_str)
        else:
            info_obj = info_str
        all_seeds = info_obj.get("all_seeds", [])
    except Exception:
        pass

    # ファイル保存 & DB登録（status=pending）
    os.makedirs(IMAGE_DIR, exist_ok=True)
    ts = datetime.now().strftime("%Y%m%d%H%M%S")

    for idx, img_b64 in enumerate(images[:job.batch_size or BATCH_SIZE]):
        uid = uuid_mod.uuid4().hex[:8]
        filename = f"gen_{ts}_{uid}.png"
        save_path = os.path.join(IMAGE_DIR, filename)
        with open(save_path, "wb") as f:
            f.write(base64.b64decode(img_b64))
        url = f"{IMAGE_URL_BASE}/{filename}"
        seed_val = all_seeds[idx] if idx < len(all_seeds) else None
        db.add(UserImage(
            user_id=job.user_id,
            url=url,
            prompt=job.prompt,
            original_prompt=getattr(job, 'original_prompt', None),
            template_id=job.template_id,
            model=job.model,
            seed=seed_val,
            status="pending",
        ))

    job.status = "completed"
    job.completed_at = datetime.now()

    # 選択可能モデルの利用回数をカウントアップ
    if job.model:
        sel_result = await db.execute(
            select(SdSelectableModel).where(SdSelectableModel.model_id == job.model)
        )
        sel_model = sel_result.scalar_one_or_none()
        if sel_model:
            sel_model.use_count = (sel_model.use_count or 0) + 1

    await db.commit()


async def _worker_loop():
    """メインワーカーループ: pending ジョブを1件ずつ処理（通常生成 + シーン画像）"""
    global _worker_stopped
    while True:
        try:
            if _worker_stopped:
                await asyncio.sleep(POLL_INTERVAL)
                continue

            async with async_session() as db:
                # 最古のpendingジョブを取得（通常生成キュー）
                result = await db.execute(
                    select(GenerationQueue)
                    .where(GenerationQueue.status == "pending")
                    .order_by(GenerationQueue.id)
                    .limit(1)
                )
                job = result.scalar_one_or_none()

                if job:
                    # processing に更新
                    job.status = "processing"
                    job.started_at = datetime.now()
                    await db.commit()

                    # SD API に送信
                    await _process_job(job, db)
                    continue  # 次のジョブをすぐチェック

                # 通常キューが空 → シーン画像タスクをチェック
                scene_result = await db.execute(
                    select(SceneImageTask)
                    .where(SceneImageTask.status == "pending")
                    .order_by(SceneImageTask.id)
                    .limit(1)
                )
                scene_task = scene_result.scalar_one_or_none()

                if scene_task:
                    from .scene_image_service import process_scene_task
                    await process_scene_task(scene_task.id)
                    continue  # 次のジョブをすぐチェック

                # どちらもなければスリープ
                await asyncio.sleep(POLL_INTERVAL)

        except Exception as e:
            print(f"[queue_worker] unexpected error: {e}")
            await asyncio.sleep(POLL_INTERVAL * 2)


async def _recover_stale_jobs():
    """起動時に processing のまま残ったジョブを pending に戻す（前回の異常終了対策）"""
    try:
        async with async_session() as db:
            result = await db.execute(
                select(GenerationQueue).where(GenerationQueue.status == "processing")
            )
            stale = result.scalars().all()
            for job in stale:
                job.status = "pending"
                job.started_at = None
                print(f"[queue_worker] recovered stale job {job.id} -> pending")
            if stale:
                await db.commit()
    except Exception as e:
        print(f"[queue_worker] recovery error: {e}")


def start_worker():
    """ワーカーを起動する（FastAPI lifespan から呼ぶ）"""
    global _worker_task

    async def _init_and_run():
        await _recover_stale_jobs()
        await _worker_loop()

    _worker_task = asyncio.create_task(_init_and_run())
    print("[queue_worker] started")
