"""チャットAPI（SSEストリーミング）"""
import re
import os
import datetime
from fastapi import APIRouter, Depends, HTTPException, Request
from fastapi.responses import StreamingResponse
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, delete
from ..database import get_db
from ..deps import get_current_user, require_user
from ..models.user import User
from ..models.character import Character
from ..models.conversation import Conversation, Message, ConversationState, ConversationStateLog
from ..models.balance import UserBalance, BalanceTransaction
from ..models.settings import ChatStateConfig
from ..services.chat_service import build_system_prompt, get_stream_func, get_ai_settings, get_cost_from_settings
from ..services.scene_image_service import create_scene_task, _get_sd_settings as _get_sd_settings_for_scene
from pydantic import BaseModel
import json

_API_USAGE_LOG = "/opt/asobi/aic/data/api_usage.log"


def _append_api_usage_log(entry: dict):
    """API利用ログをファイルに追記（JSON Lines形式）"""
    try:
        with open(_API_USAGE_LOG, "a", encoding="utf-8") as f:
            f.write(json.dumps(entry, ensure_ascii=False) + "\n")
    except Exception:
        pass

# STATEタグのパターン
_STATE_TAG_RE = re.compile(r'<<<STATE>>>(.*?)<<</STATE>>>', re.DOTALL)

router = APIRouter(prefix="/api/chat", tags=["chat"])


class ChatRequest(BaseModel):
    message: str


@router.post("/{conversation_id}")
async def send_message(
    conversation_id: int,
    req: ChatRequest,
    request: Request,
    user: User = Depends(require_user),
    db: AsyncSession = Depends(get_db)
):
    # 会話取得
    result = await db.execute(
        select(Conversation).where(Conversation.id == conversation_id, Conversation.user_id == user.id)
    )
    conv = result.scalar_one_or_none()
    if not conv:
        raise HTTPException(status_code=404, detail="会話が見つかりません")

    # キャラクター取得
    result = await db.execute(select(Character).where(Character.id == conv.character_id))
    char = result.scalar_one_or_none()
    if not char:
        raise HTTPException(status_code=404, detail="キャラクターが見つかりません")

    # 削除済みキャラクターへのメッセージ送信を拒否
    if char.is_deleted >= 1:
        raise HTTPException(status_code=403, detail="このキャラクターは削除されたため、メッセージを送れません")

    # AI設定取得
    ai_settings = await get_ai_settings(db)

    # ポイント確認
    cost = get_cost_from_settings(ai_settings)
    result = await db.execute(select(UserBalance).where(UserBalance.user_id == user.id))
    balance = result.scalar_one_or_none()
    if not balance:
        balance = UserBalance(user_id=user.id, points=0, crystals=0)
        db.add(balance)
        await db.flush()

    if balance.points < cost and balance.crystals < cost:
        raise HTTPException(status_code=402, detail="ポイントが不足しています")

    # ユーザーメッセージ保存
    user_msg = Message(conversation_id=conversation_id, role="user", content=req.message)
    db.add(user_msg)
    await db.commit()

    # 過去メッセージ取得（最新20件・ソフトデリート除外）
    result = await db.execute(
        select(Message)
        .where(Message.conversation_id == conversation_id, Message.is_deleted == 0)
        .order_by(Message.id.desc())
        .limit(20)
    )
    past_messages = list(reversed(result.scalars().all()))
    messages = [{"role": m.role, "content": m.content} for m in past_messages]

    # ステータス機能の有効/無効チェック + フィールド設定読み込み
    state_enabled = False
    conv_state = None
    state_fields = None
    try:
        from ..models.settings import DEFAULT_STATE_FIELDS
        cfg_result = await db.execute(select(ChatStateConfig).where(ChatStateConfig.id == 1))
        cfg = cfg_result.scalar_one_or_none()
        state_enabled = bool(cfg and cfg.enabled)
        if cfg and cfg.fields_json:
            state_fields = json.loads(cfg.fields_json)
        else:
            state_fields = DEFAULT_STATE_FIELDS
    except Exception:
        pass

    if state_enabled:
        state_result = await db.execute(
            select(ConversationState).where(ConversationState.conversation_id == conversation_id)
        )
        conv_state = state_result.scalar_one_or_none()

    # システムプロンプト
    rg = (ai_settings.response_guideline if ai_settings and ai_settings.response_guideline is not None else None)
    tts_vp = ai_settings.tts_voice_params if ai_settings else None
    system_prompt = build_system_prompt(char, conv_state=conv_state, state_enabled=state_enabled, response_guideline=rg, state_fields=state_fields, tts_voice_params=tts_vp)
    provider = ai_settings.provider if ai_settings else char.ai_model
    stream_func = get_stream_func(provider)

    should_buffer = state_enabled

    async def event_stream():
        full_response = ""
        state_tag_started = False
        buffer = ""
        try:
            async for chunk in stream_func(system_prompt, messages, ai_settings):
                full_response += chunk

                if should_buffer:
                    # STATE/VOICEタグのフィルタリング
                    buffer += chunk
                    if not state_tag_started:
                        # どちらかのタグの最初の出現位置を探す
                        found_pos = None
                        for marker in ("<<<STATE>>>", "<<<VOICE>>>"):
                            if marker in buffer:
                                pos = buffer.index(marker)
                                if found_pos is None or pos < found_pos:
                                    found_pos = pos
                        if found_pos is not None:
                            before_tag = buffer[:found_pos]
                            if before_tag:
                                yield f"data: {json.dumps({'text': before_tag}, ensure_ascii=False)}\n\n"
                            state_tag_started = True
                            buffer = ""
                        elif "<<<" in buffer and len(buffer) < 20:
                            # タグの途中かもしれない → バッファに溜める
                            pass
                        else:
                            # タグなし → そのまま送信
                            yield f"data: {json.dumps({'text': buffer}, ensure_ascii=False)}\n\n"
                            buffer = ""
                    # state_tag_started == True の場合はバッファに蓄積のみ（送信しない）
                else:
                    yield f"data: {json.dumps({'text': chunk}, ensure_ascii=False)}\n\n"

        except Exception as e:
            import logging
            logging.getLogger("aic").error(f"Chat stream error: {e}")
            err_msg = str(e) if user.role == 'admin' else "エラーが発生しました。しばらくしてから再試行してください。"
            yield f"data: {json.dumps({'error': err_msg}, ensure_ascii=False)}\n\n"
            return

        # バッファに残りがあればフラッシュ（どちらのタグも来なかった場合）
        if should_buffer and buffer and not state_tag_started:
            yield f"data: {json.dumps({'text': buffer}, ensure_ascii=False)}\n\n"

        # STATEタグからステータスJSONを抽出
        visible_text = full_response
        if state_enabled:
            match = _STATE_TAG_RE.search(full_response)
            if match:
                visible_text = _STATE_TAG_RE.sub("", full_response).strip()
                try:
                    state_json = json.loads(match.group(1).strip())
                    # conversation_state を更新
                    if conv_state:
                        fixed_keys = {"relationship", "mood", "environment", "situation", "inventory", "goals"}
                        active_keys = {f["key"] for f in (state_fields or []) if f.get("enabled", True)}
                        try:
                            extra = json.loads(conv_state.extra_fields or "{}") if hasattr(conv_state, 'extra_fields') else {}
                        except:
                            extra = {}
                        for key, val in state_json.items():
                            if key == "memories":
                                if isinstance(val, list):
                                    conv_state.memories = json.dumps(val, ensure_ascii=False)
                            elif key in fixed_keys and key in active_keys:
                                setattr(conv_state, key, str(val))
                            elif key not in fixed_keys and key in active_keys:
                                extra[key] = str(val)
                        if hasattr(conv_state, 'extra_fields'):
                            conv_state.extra_fields = json.dumps(extra, ensure_ascii=False)
                        # ログに記録
                        log = ConversationStateLog(
                            conversation_id=conversation_id,
                            relationship=conv_state.relationship,
                            mood=conv_state.mood,
                            environment=conv_state.environment,
                            situation=conv_state.situation,
                            inventory=conv_state.inventory,
                            goals=conv_state.goals,
                            memories=conv_state.memories,
                            extra_fields=conv_state.extra_fields if hasattr(conv_state, 'extra_fields') else None,
                        )
                        db.add(log)
                except (json.JSONDecodeError, Exception) as e:
                    import logging
                    logging.getLogger("aic").warning(f"STATE parse error: {e}")

        # AIレスポンス保存 & ポイント消費（リトライ付き）
        state_snapshot_text = None
        _scene_task_id = None  # 画像変化タスクID
        if state_enabled:
            match2 = _STATE_TAG_RE.search(full_response)
            if match2:
                state_snapshot_text = match2.group(1).strip()

        currency = "points"
        save_ok = False
        ai_msg_id = None
        for _retry in range(3):
            try:
                ai_msg = Message(conversation_id=conversation_id, role="assistant", content=visible_text,
                                 state_snapshot=state_snapshot_text)
                db.add(ai_msg)
                await db.flush()  # IDを確定させる
                ai_msg_id = ai_msg.id

                result2 = await db.execute(select(UserBalance).where(UserBalance.user_id == user.id))
                bal = result2.scalar_one()
                if bal.points >= cost:
                    bal.points -= cost
                    currency = "points"
                else:
                    bal.crystals -= cost
                    currency = "crystals"

                tx = BalanceTransaction(
                    user_id=user.id, currency=currency, amount=-cost,
                    type="chat", memo=f"{char.name} ({provider}:{ai_settings.model if ai_settings else char.ai_model})"
                )
                db.add(tx)
                await db.commit()
                save_ok = True
                break
            except Exception as e:
                import logging, asyncio
                logging.getLogger("aic").error(f"Chat save retry {_retry}: {e}")
                await db.rollback()
                await asyncio.sleep(0.5)
        if not save_ok:
            import logging
            logging.getLogger("aic").error(f"Chat save FAILED after 3 retries: conv={conversation_id}")

        # 画像変化機能：ステータス変化がある場合にシーン画像タスクを作成
        if save_ok and state_enabled and state_snapshot_text and ai_settings and getattr(ai_settings, 'image_change_enabled', 0):
            try:
                if char.sd_prompt and char.sd_seed is not None:
                    # state_jsonからステータス辞書を構築
                    _scene_state = {}
                    try:
                        _sj = json.loads(state_snapshot_text)
                        for _k in ("mood", "environment", "situation", "relationship"):
                            if _k in _sj:
                                _scene_state[_k] = str(_sj[_k])
                    except Exception:
                        pass
                    if _scene_state:
                        _sd_settings = await _get_sd_settings_for_scene(db)
                        _scene_task = await create_scene_task(
                            db=db,
                            conversation_id=conversation_id,
                            message_id=ai_msg_id,
                            base_prompt=char.sd_prompt,
                            state_dict=_scene_state,
                            sd=_sd_settings,
                            seed=char.sd_seed,
                            model=char.sd_model,
                        )
                        if _scene_task:
                            _scene_task_id = _scene_task.id
            except Exception as _e:
                import logging
                logging.getLogger("aic").warning(f"Scene task create error: {_e}")

        # API利用ログを記録
        if save_ok:
            try:
                _ip = request.client.host if request.client else ""
                _ua = request.headers.get("user-agent", "")
                _model_name = ai_settings.model if ai_settings and ai_settings.model else (char.ai_model or "")
                _input_chars = len(req.message) + sum(len(m["content"]) for m in messages)
                _output_chars = len(visible_text)
                _append_api_usage_log({
                    "ts": datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                    "user_id": user.id,
                    "username": user.username or "",
                    "char_name": char.name or "",
                    "provider": provider or "",
                    "model": _model_name,
                    "input_chars": _input_chars,
                    "output_chars": _output_chars,
                    "ip": _ip,
                    "user_agent": _ua,
                    "cost": cost,
                    "currency": currency,
                })
            except Exception:
                pass

        done_payload: dict = {'done': True, 'cost': cost, 'currency': currency}
        if state_snapshot_text:
            done_payload['state_snapshot'] = state_snapshot_text
        if ai_msg_id:
            done_payload['ai_msg_id'] = ai_msg_id
        if _scene_task_id:
            done_payload['scene_task_id'] = _scene_task_id
        yield f"data: {json.dumps(done_payload, ensure_ascii=False)}\n\n"

    return StreamingResponse(event_stream(), media_type="text/event-stream")


@router.patch("/{conversation_id}/messages/{message_id}/hide")
async def soft_delete_message(
    conversation_id: int,
    message_id: int,
    user: User = Depends(require_user),
    db: AsyncSession = Depends(get_db)
):
    """メッセージのソフトデリート（AI送信履歴から除外）"""
    result = await db.execute(
        select(Conversation).where(Conversation.id == conversation_id, Conversation.user_id == user.id)
    )
    if not result.scalar_one_or_none():
        raise HTTPException(status_code=404, detail="会話が見つかりません")
    result = await db.execute(
        select(Message).where(Message.id == message_id, Message.conversation_id == conversation_id)
    )
    msg = result.scalar_one_or_none()
    if not msg:
        raise HTTPException(status_code=404, detail="メッセージが見つかりません")
    msg.is_deleted = 1 - msg.is_deleted  # トグル
    await db.commit()
    return {"is_deleted": msg.is_deleted}


@router.delete("/{conversation_id}/messages/{message_id}")
async def delete_message(
    conversation_id: int,
    message_id: int,
    user: User = Depends(require_user),
    db: AsyncSession = Depends(get_db)
):
    """メッセージ削除（自分の会話のメッセージのみ）"""
    # 会話の所有権確認
    result = await db.execute(
        select(Conversation).where(Conversation.id == conversation_id, Conversation.user_id == user.id)
    )
    if not result.scalar_one_or_none():
        raise HTTPException(status_code=404, detail="会話が見つかりません")

    # メッセージ確認・削除
    result = await db.execute(
        select(Message).where(Message.id == message_id, Message.conversation_id == conversation_id)
    )
    msg = result.scalar_one_or_none()
    if not msg:
        raise HTTPException(status_code=404, detail="メッセージが見つかりません")

    await db.delete(msg)
    await db.commit()
    return {"deleted": True}
