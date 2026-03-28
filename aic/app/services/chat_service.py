"""AI チャットサービス（ストリーミング対応）"""
import json
from typing import AsyncGenerator
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from ..config import get_settings
from ..models.settings import AiSettings

settings = get_settings()


async def get_ai_settings(db: AsyncSession) -> AiSettings | None:
    """DBからAI設定を取得"""
    result = await db.execute(select(AiSettings).where(AiSettings.id == 1))
    return result.scalar_one_or_none()


def build_system_prompt(character, conv_state=None, state_enabled: bool = False) -> str:
    """キャラクター設定からシステムプロンプトを組み立てる

    Args:
        character: Character モデル
        conv_state: ConversationState モデル（ステータス機能ON時）
        state_enabled: ステータス機能が有効かどうか
    """
    parts = []
    if character.char_name:
        parts.append(f"あなたの名前は「{character.char_name}」です。")
    if character.char_age:
        parts.append(f"年齢は{character.char_age}です。")
    if character.gender:
        gender_map = {"female": "女性", "male": "男性", "other": "人外・性別なし"}
        parts.append(f"性別は{gender_map.get(character.gender, character.gender)}です。")
    if character.profile:
        parts.append(f"プロフィール: {character.profile}")
    if character.private_profile:
        parts.append(f"追加設定: {character.private_profile}")

    # ジャンル情報
    genres = []
    for field, label in [
        ('genre_story', '物語'),
        ('genre_char_type', 'キャラクタータイプ'),
        ('genre_personality', '性格'),
        ('genre_era', '時代設定'),
        ('genre_base', 'ベース'),
    ]:
        val = getattr(character, field, '[]')
        try:
            items = json.loads(val) if val else []
        except:
            items = []
        if items:
            genres.append(f"{label}: {', '.join(items)}")
    if genres:
        parts.append("ジャンル設定:\n" + "\n".join(genres))

    # キーワード
    try:
        kw = json.loads(character.keywords) if character.keywords else []
    except:
        kw = []
    if kw:
        parts.append(f"キーワード: {', '.join(kw)}")

    parts.append("キャラクターとして自然に会話してください。設定に忠実に、一人称や口調を維持してください。返答は20〜70文字程度を目安に簡潔にしてください。ただし、内容に応じて長くなっても構いません。")

    # ステータス機能が有効な場合
    if state_enabled and conv_state:
        status_section = (
            "\n\n## 現在のステータス\n"
            f"- キャラクターとの関係性: {conv_state.relationship or '初対面'}\n"
            f"- キャラクターの気分: {conv_state.mood or '普通'}\n"
            f"- 環境と場所: {conv_state.environment or '不明'}\n"
            f"- 状況: {conv_state.situation or '特になし'}\n"
            f"- 所持品: {conv_state.inventory or 'なし'}\n"
            f"- 目標: {conv_state.goals or '特になし'}"
        )
        parts.append(status_section)

        # 記憶
        try:
            memories = json.loads(conv_state.memories or "[]")
        except:
            memories = []
        if memories:
            mem_lines = "\n".join(f"- {m}" for m in memories)
            parts.append(f"## 記憶\n{mem_lines}")

        # 応答ルール
        parts.append(
            "## 応答ルール\n"
            "通常の会話テキストを返した後、必ず以下の形式でステータスを返してください。\n"
            "ステータスはキャラクターの性格に基づき、会話の流れに応じて自然に変化させてください。\n"
            "記憶には会話の中で覚えておくべき重要事項を追加・削除してください。\n"
            "各ステータスは短く簡潔に記述してください。\n\n"
            "<<<STATE>>>\n"
            '{"relationship":"...","mood":"...","environment":"...","situation":"...","inventory":"...","goals":"...","memories":["..."]}\n'
            "<<</STATE>>>"
        )

    return "\n\n".join(parts)


async def stream_claude(system_prompt: str, messages: list, ai_settings: AiSettings) -> AsyncGenerator[str, None]:
    """Claude API ストリーミング"""
    import anthropic
    client = anthropic.AsyncAnthropic(api_key=ai_settings.api_key or settings.ANTHROPIC_API_KEY)

    api_messages = [{"role": m["role"], "content": m["content"]} for m in messages]

    async with client.messages.stream(
        model=ai_settings.model or settings.CLAUDE_MODEL,
        max_tokens=ai_settings.max_tokens or 1024,
        system=system_prompt,
        messages=api_messages,
    ) as stream:
        async for text in stream.text_stream:
            yield text


async def stream_openai(system_prompt: str, messages: list, ai_settings: AiSettings) -> AsyncGenerator[str, None]:
    """OpenAI API ストリーミング"""
    from openai import AsyncOpenAI
    client = AsyncOpenAI(api_key=ai_settings.api_key or settings.OPENAI_API_KEY)

    api_messages = [{"role": "system", "content": system_prompt}]
    for m in messages:
        api_messages.append({"role": m["role"], "content": m["content"]})

    stream = await client.chat.completions.create(
        model=ai_settings.model or settings.OPENAI_MODEL,
        messages=api_messages,
        max_tokens=ai_settings.max_tokens or 1024,
        stream=True,
    )
    async for chunk in stream:
        delta = chunk.choices[0].delta
        if delta.content:
            yield delta.content


async def stream_ollama(system_prompt: str, messages: list, ai_settings: AiSettings) -> AsyncGenerator[str, None]:
    """Ollama API ストリーミング（OpenAI互換）"""
    from openai import AsyncOpenAI
    endpoint = (ai_settings.endpoint or "http://localhost:11434").rstrip("/")
    # /api/generate → OpenAI互換エンドポイントに変換
    base_url = endpoint.replace("/api/generate", "/v1")
    if not base_url.endswith("/v1"):
        base_url += "/v1"

    client = AsyncOpenAI(base_url=base_url, api_key="ollama")

    api_messages = [{"role": "system", "content": system_prompt}]
    for m in messages:
        api_messages.append({"role": m["role"], "content": m["content"]})

    stream = await client.chat.completions.create(
        model=ai_settings.model or "gemma3:27b",
        messages=api_messages,
        max_tokens=ai_settings.max_tokens or 1024,
        stream=True,
    )
    async for chunk in stream:
        delta = chunk.choices[0].delta
        if delta.content:
            yield delta.content


async def stream_gemini(system_prompt: str, messages: list, ai_settings: AiSettings) -> AsyncGenerator[str, None]:
    """Gemini API ストリーミング"""
    import google.generativeai as genai
    genai.configure(api_key=ai_settings.api_key or settings.GOOGLE_API_KEY)
    model = genai.GenerativeModel(
        model_name=ai_settings.model or settings.GEMINI_MODEL,
        system_instruction=system_prompt,
    )

    # Gemini用にメッセージ変換
    history = []
    for m in messages[:-1]:
        role = "user" if m["role"] == "user" else "model"
        history.append({"role": role, "parts": [m["content"]]})

    chat = model.start_chat(history=history)
    last_msg = messages[-1]["content"] if messages else ""

    response = await chat.send_message_async(last_msg, stream=True)
    async for chunk in response:
        if chunk.text:
            yield chunk.text


def get_stream_func(provider: str):
    """プロバイダからストリーム関数を返す"""
    funcs = {
        "claude": stream_claude,
        "openai": stream_openai,
        "ollama": stream_ollama,
        "gemini": stream_gemini,
        # 後方互換
        "chatgpt": stream_openai,
    }
    return funcs.get(provider, stream_claude)


def get_cost_from_settings(ai_settings: AiSettings | None) -> int:
    """DB設定からコストを取得"""
    if ai_settings:
        return ai_settings.cost
    return 1
