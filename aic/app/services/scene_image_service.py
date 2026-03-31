"""シーン画像生成サービス（チャットステータス変化で一時画像を生成）"""
import asyncio
import base64
import logging
import os
import uuid as uuid_mod
from datetime import datetime
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession
import httpx

logger = logging.getLogger("aic.scene")

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
    if lt_mode in ("free", "both", "both_local_first"):
        endpoints.append((LT_FREE, lt_api_key))
    if lt_mode in ("local", "both", "both_local_first") and sd and sd.lt_endpoint:
        endpoints.append((sd.lt_endpoint.rstrip("/"), ""))
    if lt_mode == "both_local_first" and len(endpoints) == 2:
        endpoints.reverse()
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


def _normalize_model_name(raw_name: str) -> str:
    """モデル名をDB保存用に正規化（パス区切り→_, 拡張子除去, ハッシュ除去）"""
    import re
    n = raw_name.replace("\\", "_").replace("/", "_")
    # 拡張子除去
    for ext in (".safetensors", ".ckpt", ".pt"):
        if n.endswith(ext):
            n = n[:-len(ext)]
            break
    # [hash] 除去 (例: "model_name [abcdef1234]")
    n = re.sub(r"\s*\[[0-9a-fA-F]+\]\s*$", "", n)
    return n.strip()


async def _resolve_model_for_options(endpoint: str, normalized_name: str) -> str:
    """正規化済みモデル名を /sdapi/v1/options に設定可能な実名に逆引き

    Forge では /sdapi/v1/sd-models が 500 を返すことがあるため、
    Gradio /info → /sdapi/v1/sd-models の順にフォールバックする。
    """
    # ① Gradio /info API（Forge対応）
    try:
        async with httpx.AsyncClient(timeout=10) as mc:
            ir = await mc.get(f"{endpoint}/info")
            if ir.status_code == 200:
                info = ir.json()
                ep_info = info.get("named_endpoints", {}).get("/checkpoint_change", {})
                params = ep_info.get("parameters", [])
                if params:
                    enum_list = params[0].get("type", {}).get("enum", [])
                    logger.info(f"_resolve_model: looking for '{normalized_name}' in {len(enum_list)} Gradio models")
                    for raw_name in enum_list:
                        if _normalize_model_name(raw_name) == normalized_name:
                            logger.info(f"_resolve_model: matched Gradio '{raw_name}'")
                            return raw_name
    except Exception as e:
        logger.warning(f"_resolve_model: Gradio /info failed: {e}")

    # ② フォールバック: /sdapi/v1/sd-models（A1111互換）
    try:
        async with httpx.AsyncClient(timeout=10) as mc:
            mr = await mc.get(f"{endpoint}/sdapi/v1/sd-models")
            if mr.status_code == 200:
                sd_models = mr.json()
                logger.info(f"_resolve_model: looking for '{normalized_name}' in {len(sd_models)} sd-models")
                for m in sd_models:
                    title = m.get("title", "")
                    model_name = m.get("model_name", "")
                    if _normalize_model_name(title) == normalized_name:
                        logger.info(f"_resolve_model: matched sd-models title '{title}'")
                        return title
                    if _normalize_model_name(model_name) == normalized_name:
                        logger.info(f"_resolve_model: matched sd-models model_name '{model_name}'")
                        return title
    except Exception as e:
        logger.warning(f"_resolve_model: sd-models API failed: {e}")

    logger.warning(f"_resolve_model: no match for '{normalized_name}', returning as-is")
    return normalized_name


# 後方互換のためエイリアス
_resolve_model_name = _resolve_model_for_options


async def process_scene_task(task_id: int):
    """シーン画像タスクを処理する（バックグラウンドで呼ぶ）"""
    import re
    try:
        async with async_session() as db:
            result = await db.execute(
                select(SceneImageTask).where(SceneImageTask.id == task_id)
            )
            task = result.scalar_one_or_none()
            if not task or task.status != "pending":
                logger.warning(f"process_scene_task: task {task_id} not found or not pending (status={task.status if task else 'N/A'})")
                return

            task.status = "processing"
            await db.commit()
            logger.info(f"process_scene_task: id={task_id} prompt={task.prompt_used[:80] if task.prompt_used else ''}")

            sd = await _get_sd_settings(db)
            if not sd or not sd.enabled or not sd.endpoint:
                logger.warning(f"process_scene_task: SD disabled/no endpoint. enabled={sd.enabled if sd else None} endpoint={sd.endpoint if sd else None}")
                task.status = "failed"
                task.error_message = "SD設定が無効です"
                task.completed_at = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                await db.commit()
                return

            prompt = task.prompt_used or ""
            endpoint = sd.endpoint.rstrip("/")
            # ネガティブプロンプト: タスク固有 → SD設定グローバル の優先順
            neg_prompt = task.negative_prompt if task.negative_prompt else (sd.negative_prompt or "")
            payload = {
                "prompt": prompt,
                "negative_prompt": neg_prompt,
                "steps": sd.steps or 20,
                "cfg_scale": sd.cfg_scale or 7.0,
                "width": sd.width or 512,
                "height": sd.height or 512,
                "sampler_name": "DPM++ 2M Karras",
                "batch_size": 1,
                "n_iter": 1,
            }

            # モデル指定（元画像と同じモデルを使用）
            # Forge の Gradio /run/checkpoint_change で実際にモデルをVRAMにロードする
            if task.model:
                resolved_model = await _resolve_model_for_options(endpoint, task.model)
                try:
                    async with httpx.AsyncClient(timeout=10) as mc:
                        opts_r = await mc.get(f"{endpoint}/sdapi/v1/options")
                        current = opts_r.json().get("sd_model_checkpoint") if opts_r.status_code == 200 else None
                    if current and _normalize_model_name(current) != _normalize_model_name(resolved_model):
                        logger.info(f"process_scene_task: switching model {current} -> {resolved_model}")
                        async with httpx.AsyncClient(timeout=300) as mc:
                            switch_r = await mc.post(f"{endpoint}/run/checkpoint_change",
                                          json={"data": [resolved_model]})
                            logger.info(f"process_scene_task: model switch response {switch_r.status_code}")
                    else:
                        logger.info(f"process_scene_task: model already loaded: {current}")
                except Exception as _e:
                    logger.warning(f"process_scene_task: model switch failed: {_e}")

            # シード値をprompt_usedのメタデータから取得
            seed_match = re.search(r"__seed__:(-?\d+)__", prompt)
            if seed_match:
                payload["seed"] = int(seed_match.group(1))
                payload["prompt"] = re.sub(r"__seed__:-?\d+__", "", prompt).strip(", ")

            logger.info(f"process_scene_task: sending to {endpoint}/sdapi/v1/txt2img model={task.model} prompt={payload['prompt'][:60]} seed={payload.get('seed')}")

            try:
                async with httpx.AsyncClient(timeout=120) as client:
                    r = await client.post(f"{endpoint}/sdapi/v1/txt2img", json=payload)
                    if r.status_code != 200:
                        err_detail = r.text[:200] if r.text else ""
                        logger.error(f"process_scene_task: SD API error {r.status_code}: {err_detail}")
                        task.status = "failed"
                        task.error_message = f"SD API エラー: {r.status_code}"
                        task.completed_at = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                        await db.commit()
                        return
                    data = r.json()
            except Exception as e:
                logger.error(f"process_scene_task: request error: {e}")
                task.status = "failed"
                task.error_message = f"生成エラー: {str(e)}"
                task.completed_at = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                await db.commit()
                return

            # 実際に使われたモデルをログに記録
            try:
                import json as _json
                _info_str = data.get("info", "")
                _info_obj = _json.loads(_info_str) if isinstance(_info_str, str) else _info_str
                _actual_model = _info_obj.get("sd_model_name", "?")
                _actual_hash = _info_obj.get("sd_model_hash", "?")
                logger.info(f"process_scene_task: actual model used: {_actual_model} (hash={_actual_hash})")
            except Exception:
                pass

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
            logger.info(f"process_scene_task: done id={task_id} url={task.image_url}")


    except Exception as e:
        logger.error(f"process_scene_task: unhandled error for task {task_id}: {e}", exc_info=True)
        try:
            async with async_session() as db2:
                result2 = await db2.execute(select(SceneImageTask).where(SceneImageTask.id == task_id))
                t2 = result2.scalar_one_or_none()
                if t2 and t2.status == "processing":
                    t2.status = "failed"
                    t2.error_message = f"内部エラー: {str(e)}"
                    t2.completed_at = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                    await db2.commit()
        except Exception:
            pass


async def create_scene_task(db: AsyncSession, conversation_id: int, message_id: int | None,
                             base_prompt: str, state_dict: dict,
                             sd: SdSettings | None, seed: int | None = None,
                             model: str | None = None,
                             negative_prompt: str | None = None) -> SceneImageTask | None:
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
        negative_prompt=negative_prompt or "",
        model=model or "",
    )
    db.add(task)
    await db.commit()
    await db.refresh(task)
    logger.info(f"create_scene_task: id={task.id} model={model} prompt={prompt[:80]}")

    # キューワーカーが自動的にpendingタスクを拾って処理する
    return task
