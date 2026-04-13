"""キャラクター自動審査サービス"""
import asyncio
import json
import logging
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession
from ..database import async_session
from ..models.character import Character
from ..models.settings import AiSettings
from ..models.notification import Notification

logger = logging.getLogger("aic")


async def _notify_review_result(db: AsyncSession, char: Character, approved: bool, reason: str = ""):
    """審査結果をユーザーに通知"""
    if approved:
        title = f"キャラクター「{char.name}」の審査が承認されました"
        message = "公開できるようになりました。"
        ntype = "character_approved"
    else:
        title = f"キャラクター「{char.name}」の審査が却下されました"
        message = f"理由: {reason}" if reason else "詳細はマイキャラクター画面をご確認ください。"
        ntype = "character_rejected"

    notif = Notification(
        user_id=char.creator_id,
        type=ntype,
        title=title,
        message=message,
        related_id=char.id,
        related_url=f"#characters/{char.id}",
        is_read=0,
    )
    db.add(notif)

DEFAULT_REVIEW_PROMPT = """あなたはキャラクター審査AIです。
ユーザーが作成したAIチャットキャラクターの内容を審査し、公開して問題ないか判定してください。

以下のようなキャラクターはNGとしてください：
- 実在の人物を模倣したキャラクター
- 明らかに違法行為を助長する内容
- 極端に不適切・攻撃的な内容

判定結果を以下のJSON形式のみで返してください。それ以外のテキストは出力しないでください：
{"result": "OK"} または {"result": "NG", "reason": "理由"}"""


async def review_character(char_id: int):
    """バックグラウンドでキャラクターを自動審査する"""
    await asyncio.sleep(1)  # DB書き込み完了を待つ

    async with async_session() as db:
        # AI設定取得
        ai_result = await db.execute(select(AiSettings).where(AiSettings.id == 1))
        ai_settings = ai_result.scalar_one_or_none()
        if not ai_settings or not ai_settings.review_enabled:
            # 審査無効時は自動承認
            char_result = await db.execute(select(Character).where(Character.id == char_id))
            char = char_result.scalar_one_or_none()
            if char and char.review_status == "pending":
                char.review_status = "approved"
                await _notify_review_result(db, char, approved=True)
                await db.commit()
            logger.info(f"[review] char_id={char_id} auto-approved (review disabled)")
            return

        # キャラクター情報取得
        char_result = await db.execute(select(Character).where(Character.id == char_id))
        char = char_result.scalar_one_or_none()
        if not char or char.review_status != "pending":
            return

        # 審査対象テキストを構築
        review_text = _build_review_text(char)
        review_prompt = ai_settings.review_prompt or DEFAULT_REVIEW_PROMPT

        try:
            response = await _call_ollama(ai_settings, review_prompt, review_text)
            result = _parse_review_response(response)

            if result["ok"]:
                char.review_status = "approved"
                char.review_note = "自動審査OK"
                await _notify_review_result(db, char, approved=True)
                logger.info(f"[review] char_id={char_id} approved")
            else:
                char.review_status = "rejected"
                reason = result.get("reason", "不明")
                char.review_note = f"自動審査NG: {reason}"
                await _notify_review_result(db, char, approved=False, reason=reason)
                logger.info(f"[review] char_id={char_id} rejected: {reason}")

            await db.commit()

            # API利用ログ記録
            try:
                from . import append_api_usage_log
                append_api_usage_log({
                    "type": "review",
                    "site": "aic",
                    "endpoint": "/review",
                    "provider": ai_settings.provider or "ollama",
                    "model": ai_settings.model or "",
                    "input_chars": len(review_text),
                    "output_chars": len(response),
                    "char_name": char.name or "",
                })
            except Exception:
                pass

        except Exception as e:
            logger.error(f"[review] char_id={char_id} error: {e}")
            # エラー時は自動承認（審査不能で公開をブロックし続けないため）
            char.review_status = "approved"
            char.review_note = f"自動審査エラー（自動承認）: {str(e)[:200]}"
            await _notify_review_result(db, char, approved=True)
            await db.commit()


def _build_review_text(char: Character) -> str:
    """審査対象テキストを構築"""
    parts = [f"キャラクター名: {char.name}"]
    if char.char_name:
        parts.append(f"名前: {char.char_name}")
    if char.gender:
        parts.append(f"性別: {char.gender}")
    if char.char_age:
        parts.append(f"年齢: {char.char_age}")
    if char.profile:
        parts.append(f"プロフィール: {char.profile}")
    if char.private_profile:
        parts.append(f"非公開プロフィール: {char.private_profile}")
    if char.first_message:
        parts.append(f"最初のメッセージ: {char.first_message}")

    # ジャンルタグ
    for field, label in [
        ("genre_story", "物語ジャンル"),
        ("genre_char_type", "キャラタイプ"),
        ("genre_personality", "性格"),
    ]:
        val = getattr(char, field, None)
        if val:
            try:
                tags = json.loads(val)
                if tags:
                    parts.append(f"{label}: {', '.join(tags)}")
            except (json.JSONDecodeError, TypeError):
                pass

    # キーワード
    if char.keywords:
        try:
            kws = json.loads(char.keywords)
            if kws:
                parts.append(f"キーワード: {', '.join(kws)}")
        except (json.JSONDecodeError, TypeError):
            pass

    return "\n".join(parts)


async def _call_ollama(ai_settings: AiSettings, system_prompt: str, user_text: str) -> str:
    """Ollama APIに審査リクエストを送信（非ストリーミング）"""
    from openai import AsyncOpenAI

    endpoint = (ai_settings.endpoint or "http://localhost:11434").rstrip("/")
    base_url = endpoint.replace("/api/generate", "/v1")
    if not base_url.endswith("/v1"):
        base_url += "/v1"

    client = AsyncOpenAI(base_url=base_url, api_key="ollama")

    response = await client.chat.completions.create(
        model=ai_settings.model or "gemma3:27b",
        messages=[
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": user_text},
        ],
        max_tokens=256,
        stream=False,
    )

    return response.choices[0].message.content or ""


def _parse_review_response(response: str) -> dict:
    """AIレスポンスからOK/NG判定を抽出"""
    # JSONを探す
    text = response.strip()

    # ```json ... ``` ブロックを除去
    if "```" in text:
        import re
        m = re.search(r'```(?:json)?\s*(\{.*?\})\s*```', text, re.DOTALL)
        if m:
            text = m.group(1)

    # JSON部分を抽出
    start = text.find("{")
    end = text.rfind("}") + 1
    if start >= 0 and end > start:
        try:
            data = json.loads(text[start:end])
            result = data.get("result", "").upper()
            if result == "OK":
                return {"ok": True}
            elif result == "NG":
                return {"ok": False, "reason": data.get("reason", "不適切と判定されました")}
        except json.JSONDecodeError:
            pass

    # JSONパース失敗時: テキストにOK/NGを探す
    upper = text.upper()
    if "NG" in upper:
        return {"ok": False, "reason": "自動判定: 不適切な内容の可能性"}
    return {"ok": True}
