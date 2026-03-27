"""チャットAPI（SSEストリーミング）"""
from fastapi import APIRouter, Depends, HTTPException
from fastapi.responses import StreamingResponse
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from ..database import get_db
from ..deps import get_current_user, require_user
from ..models.user import User
from ..models.character import Character
from ..models.conversation import Conversation, Message
from ..models.balance import UserBalance, BalanceTransaction
from ..services.chat_service import build_system_prompt, get_stream_func, get_ai_settings, get_cost_from_settings
from pydantic import BaseModel
import json

router = APIRouter(prefix="/api/chat", tags=["chat"])


class ChatRequest(BaseModel):
    message: str


@router.post("/{conversation_id}")
async def send_message(
    conversation_id: int,
    req: ChatRequest,
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

    # 過去メッセージ取得（最新20件）
    result = await db.execute(
        select(Message)
        .where(Message.conversation_id == conversation_id)
        .order_by(Message.id.desc())
        .limit(20)
    )
    past_messages = list(reversed(result.scalars().all()))
    messages = [{"role": m.role, "content": m.content} for m in past_messages]

    # システムプロンプト
    system_prompt = build_system_prompt(char)
    provider = ai_settings.provider if ai_settings else char.ai_model
    stream_func = get_stream_func(provider)

    async def event_stream():
        full_response = ""
        try:
            async for chunk in stream_func(system_prompt, messages, ai_settings):
                full_response += chunk
                yield f"data: {json.dumps({'text': chunk}, ensure_ascii=False)}\n\n"
        except Exception as e:
            yield f"data: {json.dumps({'error': str(e)}, ensure_ascii=False)}\n\n"
            return

        # AIレスポンス保存 & ポイント消費（リトライ付き）
        currency = "points"
        for _retry in range(3):
            try:
                async with db.begin():
                    ai_msg = Message(conversation_id=conversation_id, role="assistant", content=full_response)
                    db.add(ai_msg)

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

                    result3 = await db.execute(select(Character).where(Character.id == char.id))
                    c = result3.scalar_one()
                    c.use_count += 1
                break
            except Exception:
                import asyncio
                await asyncio.sleep(0.5)

        yield f"data: {json.dumps({'done': True, 'cost': cost, 'currency': currency}, ensure_ascii=False)}\n\n"

    return StreamingResponse(event_stream(), media_type="text/event-stream")
