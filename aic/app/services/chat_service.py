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


DEFAULT_RESPONSE_GUIDELINE = "キャラクターとして自然に会話してください。設定に忠実に、一人称や口調を維持してください。返答は20〜70文字程度を目安に簡潔にしてください。ただし、内容に応じて長くなっても構いません。"


def build_system_prompt(character, conv_state=None, state_enabled: bool = False, response_guideline: str | None = None, state_fields: list | None = None) -> str:
    """キャラクター設定からシステムプロンプトを組み立てる

    Args:
        character: Character モデル
        conv_state: ConversationState モデル（ステータス機能ON時）
        state_enabled: ステータス機能が有効かどうか
        response_guideline: レスポンス指示文（Noneの場合はデフォルト使用）
        state_fields: ステータスフィールド定義リスト [{key, label, default, enabled}]
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

    guideline = response_guideline if response_guideline is not None else DEFAULT_RESPONSE_GUIDELINE
    if guideline:
        parts.append(guideline)

    # ステータス機能が有効な場合
    if state_enabled and conv_state:
        from ..models.settings import DEFAULT_STATE_FIELDS
        fields = state_fields if state_fields is not None else DEFAULT_STATE_FIELDS
        active_fields = [f for f in fields if f.get("enabled", True)]

        # 固定キーのカラムマップ
        fixed_keys = {"relationship", "mood", "environment", "situation", "inventory", "goals"}

        # カスタムフィールド値を取得
        try:
            extra = json.loads(conv_state.extra_fields or "{}") if hasattr(conv_state, 'extra_fields') else {}
        except:
            extra = {}

        # ステータス現在値を構築
        status_lines = []
        for f in active_fields:
            key = f["key"]
            label = f["label"]
            default = f.get("default", "")
            if key in fixed_keys:
                val = getattr(conv_state, key, None) or default
            else:
                val = extra.get(key) or default
            status_lines.append(f"- {label}: {val}")

        parts.append("\n\n## 現在のステータス\n" + "\n".join(status_lines))

        # 記憶
        try:
            memories = json.loads(conv_state.memories or "[]")
        except:
            memories = []
        if memories:
            mem_lines = "\n".join(f"- {m}" for m in memories)
            parts.append(f"## 記憶\n{mem_lines}")

        # STATE JSONテンプレートを動的生成
        state_template_keys = {f["key"]: "..." for f in active_fields}
        state_template_keys["memories"] = ["..."]
        state_template_str = json.dumps(state_template_keys, ensure_ascii=False)

        parts.append(
            "## 応答ルール\n"
            "通常の会話テキストを返した後、必ず以下の形式でステータスを返してください。\n"
            "ステータスはキャラクターの性格に基づき、会話の流れに応じて自然に変化させてください。\n"
            "記憶には会話の中で覚えておくべき重要事項を追加・削除してください。\n"
            "各ステータスは短く簡潔に記述してください。\n\n"
            f"<<<STATE>>>\n{state_template_str}\n<<</STATE>>>"
        )

    # TTS音声スタイル指示（キャラクターに音声設定がある場合）
    if character.voice_model and character.tts_styles:
        try:
            styles = json.loads(character.tts_styles or "[]")
            style_names = [s["name"] for s in styles if "name" in s]
        except Exception:
            style_names = []
        if style_names:
            style_str = "、".join(style_names)
            parts.append(
                "## 音声スタイル指示\n"
                "返答は以下の形式で記述してください：[SE名]{スタイル}テキスト\n"
                "- [SE名]: 動作や効果音（例：[ドアをノックする]）。不要な場合は省略可。\n"
                f"- {{スタイル}}: 次の中から必ず1つ選択: {style_str}\n"
                "- テキスト: キャラクターのセリフ。\n"
                "例：[ドアをノックする]{元気}失礼します。{ノーマル}初めまして。\n"
                "スタイルは全てのセグメントに必ず付けてください。"
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
