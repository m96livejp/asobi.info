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


def build_system_prompt(character) -> str:
    """キャラクター設定からシステムプロンプトを組み立てる"""
    parts = []
    if character.char_name:
        parts.append(f"あなたの名前は「{character.char_name}」です。")
    if character.char_age:
        parts.append(f"年齢は{character.char_age}です。")
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

    parts.append("キャラクターとして自然に会話してください。設定に忠実に、一人称や口調を維持してください。")
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
