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


DEFAULT_RESPONSE_GUIDELINE = "キャラクターとして自然に会話してください。設定に忠実に、一人称や口調を維持してください。返答は基本的に20〜70文字の短い返事を心がけてください。長い説明が必要な場合のみ例外的に長くしてください。箇条書きや見出しは使わず、会話調で答えてください。返答は必ず日本語で行ってください。"

DEFAULT_STATE_INSTRUCTION = (
    "## STATEブロック（必須）\n"
    "会話テキストの後に必ず以下の形式でステータスを返す。\n"
    "ステータスはキャラクターの性格に基づき、会話の流れに応じて自然に変化させる。\n"
    "「未指定」のステータスは会話の中で明確にわかった場合のみ更新する。推測や想像で埋めない。\n"
    "記憶には会話の中で覚えておくべき重要事項を追加・削除する。\n"
    "各ステータスは短く簡潔に記述する。\n"
    "【重要】全てのステータス値と記憶はなるべく日本語で記述する。固有名詞以外英語は使わない。"
)

DEFAULT_TTS_INSTRUCTION = (
    "## 音声スタイル指示\n"
    "返答は以下の形式で記述する：[SE名]{スタイル}テキスト\n"
    "- [SE名]: 動作や効果音（例：[ドアをノックする]）。不要な場合は省略可。\n"
    "- {スタイル}: 次の中から必ず1つ選択: {styles}\n"
    "- テキスト: キャラクターのセリフ。\n"
    "- セグメントは意味のまとまり（文や節）ごとに区切る。1文字ずつ区切らない。\n"
    "- 記号のみ（？！…など）のセグメントにはスタイルを付けない。\n"
    "例：[ドアをノックする]{元気}失礼します。{ノーマル}初めまして。\n"
    "スタイルは全てのセグメントに必ず付ける。"
)

DEFAULT_TTS_INSTRUCTION_PARAMS = (
    "## 音声スタイル指示\n"
    "返答は以下の形式で記述する：[SE名]{スタイル:速度:ピッチ:抑揚:音量}テキスト\n"
    "- [SE名]: 動作や効果音（例：[ドアをノックする]）。不要な場合は省略可。\n"
    "- {スタイル}: 次の中から必ず1つ選択: {styles}\n"
    "- 速度:ピッチ:抑揚:音量 は各0〜100の整数（50=普通の状態）。会話の感情に合わせて変化させる。\n"
    "- テキスト: キャラクターのセリフ。\n"
    "- セグメントは意味のまとまり（文や節）ごとに区切る。1文字ずつ区切らない。\n"
    "- 記号のみ（？！…など）のセグメントにはスタイルを付けない。\n"
    "例：[ドアをノックする]{元気:65:55:70:50}失礼します！{驚き:75:60:80:55}え、本当に？\n"
    "スタイルとパラメータは全てのセグメントに必ず付ける。"
)


def build_system_prompt(character, conv_state=None, state_enabled: bool = False, response_guideline: str | None = None, state_fields: list | None = None, tts_voice_params: str | None = None, state_instruction: str | None = None, tts_instruction: str | None = None, tts_instruction_params: str | None = None) -> str:
    """キャラクター設定からシステムプロンプトを組み立てる

    Args:
        character: Character モデル
        conv_state: ConversationState モデル（ステータス機能ON時）
        state_enabled: ステータス機能が有効かどうか
        response_guideline: レスポンス指示文（Noneの場合はデフォルト使用）
        state_fields: ステータスフィールド定義リスト [{key, label, default, enabled}]
        state_instruction: STATEブロック指示テキスト（Noneの場合はデフォルト使用）
        tts_instruction: TTS音声スタイル指示テキスト（通常、Noneの場合はデフォルト使用）
        tts_instruction_params: TTS音声スタイル指示テキスト（パラメータ付き、Noneの場合はデフォルト使用）
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

        # STATE JSONテンプレートを動的生成（TTS指示の後に配置するため保持）
        # キーは英語だが、例示値を日本語にしてOllamaが日本語で返しやすくする
        state_template_keys = {}
        field_examples = {
            "relationship": "友好的",
            "mood": "嬉しい",
            "environment": "自宅のリビング",
            "situation": "雑談中",
            "inventory": "なし",
            "goals": "仲良くなりたい",
        }
        for f in active_fields:
            key = f["key"]
            state_template_keys[key] = field_examples.get(key, f.get("default", "（日本語で記述）"))
        state_template_keys["memories"] = ["ユーザーの名前は○○", "好きな食べ物は△△"]
        state_template_str = json.dumps(state_template_keys, ensure_ascii=False)
        _instr_text = state_instruction if state_instruction else DEFAULT_STATE_INSTRUCTION
        _state_instruction_final = f"{_instr_text}\n\n<<<STATE>>>\n{state_template_str}\n<<</STATE>>>"
    else:
        _state_instruction_final = None

    # TTS音声スタイル指示（キャラクターに音声設定がある場合）
    if character.voice_model and character.tts_styles:
        try:
            styles = json.loads(character.tts_styles or "[]")
            style_names = [s["name"] for s in styles if "name" in s]
        except Exception:
            style_names = []
        if style_names:
            style_str = "、".join(style_names)
            if tts_voice_params:
                _tts_text = tts_instruction_params if tts_instruction_params else DEFAULT_TTS_INSTRUCTION_PARAMS
            else:
                _tts_text = tts_instruction if tts_instruction else DEFAULT_TTS_INSTRUCTION
            parts.append(_tts_text.replace("{styles}", style_str))

    # STATEブロック指示はTTS指示の後に配置（最後に置くことでAIが確実に従う）
    if _state_instruction_final:
        parts.append(_state_instruction_final)

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
