"""シーン画像生成サービス（チャットステータス変化で一時画像を生成）"""
import asyncio
import base64
import os
import uuid as uuid_mod
from datetime import datetime
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession
import httpx

from ..database import async_session
from ..models.image import SceneImageTask
from ..models.settings import SdSettings

SCENE_IMAGE_DIR = "/opt/asobi/aic/frontend/images/scenes"
SCENE_IMAGE_URL_BASE = "/images/scenes"
_SCENE_WORKER_RUNNING = False


async def _get_sd_settings(db: AsyncSession) -> SdSettings | None:
    result = await db.execute(select(SdSettings).where(SdSettings.id == 1))
    return result.scalar_one_or_none()


async def _translate_to_english(sd: SdSettings, text: str) -> str:
    """日本語テキストを英語に翻訳（LibreTranslate使用。失敗時は原文）"""
    if not text:
        return text
    lt_mode = (sd.lt_mode if sd else None) or "off"
    if lt_mode == "off":
        return text
    lt_api_key = (sd.lt_api_key or "") if sd else ""
    LT_FREE = "https://libretranslate.com"
    endpoints = []
    if lt_mode in ("free", "both"):
        endpoints.append((LT_FREE, lt_api_key))
    if lt_mode in ("local", "both") and sd and sd.lt_endpoint:
        endpoints.append((sd.lt_endpoint.rstrip("/"), ""))
    for ep, key in endpoints:
        try:
            payload: dict = {"q": text, "source": "ja", "target": "en", "format": "text"}
            if key:
                payload["api_key"] = key
            async with httpx.AsyncClient(timeout=15) as client:
                r = await client.post(f"{ep}/translate", json=payload)
                r.raise_for_status()
                result = r.json().get("translatedText", "")
                if result:
                    return result
        except Exception:
            pass
    return text  # 翻訳失敗時は原文


def _build_scene_prompt(base_prompt: str, state_dict: dict) -> str:
    """ベースプロンプト + ステータス情報でシーンプロンプトを構築"""
    state_parts = []
    field_map = {
        "mood": "mood",
        "environment": "environment",
        "situation": "situation",
    }
    for key, label in field_map.items():
        val = state_dict.get(key, "")
        if val and val not in ("普通", "不明", "特になし", "なし", "normal", "unknown", "none"):
            state_parts.append(val)
    if state_parts:
        scene_desc = ", ".join(state_parts)
        return f"{base_prompt}, {scene_desc}"
    return base_prompt


async def process_scene_task(task_id: int):
    """シーン画像タスクを処理する（バックグラウンドで呼ぶ）"""
    async with async_session() as db:
        result = await db.execute(
            select(SceneImageTask).where(SceneImageTask.id == task_id)
        )
        task = result.scalar_one_or_none()
        if not task or task.status != "pending":
            return

        task.status = "processing"
        await db.commit()

        sd = await _get_sd_settings(db)
        if not sd or not sd.enabled or not sd.endpoint:
            task.status = "failed"
            task.error_message = "SD設定が無効です"
            task.completed_at = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            await db.commit()
            return

        prompt = task.prompt_used or ""
        endpoint = sd.endpoint.rstrip("/")
        payload = {
            "prompt": prompt,
            "negative_prompt": sd.negative_prompt or "",
            "steps": sd.steps or 20,
            "cfg_scale": sd.cfg_scale or 7.0,
            "width": sd.width or 512,
            "height": sd.height or 768,
            "sampler_name": "DPM++ 2M Karras",
            "batch_size": 1,
            "n_iter": 1,
        }

        # キャラクターのシードが設定されていればprompt_usedにシードが含まれているはず
        # シード値をprompt_usedのメタデータから取得（フォーマット: "__seed__:XXXXX__" を含む）
        import re
        seed_match = re.search(r"__seed__:(-?\d+)__", prompt)
        if seed_match:
            payload["seed"] = int(seed_match.group(1))
            payload["prompt"] = re.sub(r"__seed__:-?\d+__", "", prompt).strip(", ")

        try:
            async with httpx.AsyncClient(timeout=180) as client:
                r = await client.post(f"{endpoint}/sdapi/v1/txt2img", json=payload)
                if r.status_code != 200:
                    task.status = "failed"
                    task.error_message = f"SD API エラー: {r.status_code}"
                    task.completed_at = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                    await db.commit()
                    return
                data = r.json()
        except Exception as e:
            task.status = "failed"
            task.error_message = f"生成エラー: {str(e)}"
            task.completed_at = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            await db.commit()
            return

        images = data.get("images", [])
        if not images:
            task.status = "failed"
            task.error_message = "画像が生成されませんでした"
            task.completed_at = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            await db.commit()
            return

        # 画像保存
        os.makedirs(SCENE_IMAGE_DIR, exist_ok=True)
        ts = datetime.now().strftime("%Y%m%d%H%M%S")
        uid = uuid_mod.uuid4().hex[:8]
        filename = f"scene_{ts}_{uid}.png"
        save_path = os.path.join(SCENE_IMAGE_DIR, filename)
        with open(save_path, "wb") as f:
            f.write(base64.b64decode(images[0]))

        task.status = "done"
        task.image_url = f"{SCENE_IMAGE_URL_BASE}/{filename}"
        task.completed_at = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        await db.commit()


async def create_scene_task(db: AsyncSession, conversation_id: int, message_id: int | None,
                             base_prompt: str, state_dict: dict,
                             sd: SdSettings | None, seed: int | None = None,
                             model: str | None = None) -> SceneImageTask | None:
    """シーン画像タスクを作成してIDを返す"""
    if not base_prompt:
        return None

    # ステータスの翻訳が必要な場合は変換
    translated_state = {}
    if sd:
        for key in ("mood", "environment", "situation"):
            val = state_dict.get(key, "")
            if val:
                translated = await _translate_to_english(sd, val)
                translated_state[key] = translated
    else:
        translated_state = state_dict

    # プロンプト構築
    prompt = _build_scene_prompt(base_prompt, translated_state)
    # シード値をプロンプトにメタデータとして埋め込む
    if seed is not None:
        prompt = f"{prompt}, __seed__:{seed}__"

    task = SceneImageTask(
        conversation_id=conversation_id,
        message_id=message_id,
        status="pending",
        prompt_used=prompt,
    )
    db.add(task)
    await db.flush()

    # バックグラウンドでSD処理を起動
    asyncio.create_task(process_scene_task(task.id))

    return task
