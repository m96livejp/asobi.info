"""AI・SD設定モデル"""
from sqlalchemy import Column, Integer, String, Text, Float, DateTime
from sqlalchemy.sql import func
from ..database import Base


class SdSettings(Base):
    """Stable Diffusion 設定（id=1 の1行のみ使用）"""
    __tablename__ = "sd_settings"

    id               = Column(Integer, primary_key=True, autoincrement=True)
    enabled          = Column(Integer, nullable=False, default=0)
    endpoint         = Column(String, nullable=True)          # 例: http://1.2.3.4:17213
    model            = Column(String, nullable=True)          # 使用チェックポイント名
    negative_prompt  = Column(Text,   nullable=False, default="lowres, bad anatomy, bad hands, text, error, cropped, worst quality, low quality, normal quality, jpeg artifacts, signature, watermark, username, blurry")
    steps            = Column(Integer, nullable=False, default=20)
    cfg_scale        = Column(Float,   nullable=False, default=7.0)
    width            = Column(Integer, nullable=False, default=512)
    height           = Column(Integer, nullable=False, default=512)
    lt_endpoint      = Column(String, nullable=True)          # LibreTranslate ローカルエンドポイント（例: http://127.0.0.1:5000）
    lt_mode          = Column(String, nullable=False, default="off")  # off / free / local / both
    lt_api_key       = Column(String, nullable=True)          # libretranslate.com APIキー（無料登録で取得）
    updated_at       = Column(DateTime, server_default=func.now(), onupdate=func.now())


class PromptTemplate(Base):
    """画像生成プロンプトテンプレート（管理者が事前設定）"""
    __tablename__ = "prompt_templates"

    id              = Column(Integer, primary_key=True, autoincrement=True)
    name            = Column(String,  nullable=False)           # テンプレート名
    prompt          = Column(Text,    nullable=False)           # プロンプト本文
    negative_prompt = Column(Text,    nullable=True)            # ネガティブプロンプト（nullでSD設定のデフォルト使用）
    is_active       = Column(Integer, nullable=False, default=1)
    sort_order      = Column(Integer, nullable=False, default=0)
    created_at      = Column(DateTime, server_default=func.now())


class SdSelectableModel(Base):
    """画像生成画面でユーザーが選択できるモデル一覧（管理者が登録）"""
    __tablename__ = "sd_selectable_models"

    id           = Column(Integer, primary_key=True, autoincrement=True)
    model_id     = Column(String,  nullable=False)           # SDのモデル識別子
    display_name = Column(String,  nullable=False)           # 表示名（例: リアル系、アニメ系）
    is_active    = Column(Integer, nullable=False, default=1)
    sort_order   = Column(Integer, nullable=False, default=0)
    use_count    = Column(Integer, nullable=False, default=0) # 利用回数
    created_at   = Column(DateTime, server_default=func.now())


class ChatStateConfig(Base):
    """チャットステータス機能のON/OFF（id=1 の1行のみ使用）"""
    __tablename__ = "chat_state_config"

    id = Column(Integer, primary_key=True)
    enabled = Column(Integer, nullable=False, default=0)  # 0=OFF, 1=ON


class AiSettings(Base):
    __tablename__ = "ai_settings"

    id = Column(Integer, primary_key=True, autoincrement=True)
    provider = Column(String, nullable=False, default="claude")       # claude / openai / ollama
    endpoint = Column(String, nullable=True)                          # Ollama等のエンドポイントURL
    api_key = Column(String, nullable=True)                           # APIキー（Claude/OpenAI）
    model = Column(String, nullable=False, default="claude-sonnet-4-20250514")
    max_tokens = Column(Integer, nullable=False, default=1024)
    cost = Column(Integer, nullable=False, default=1)                 # 1メッセージあたりのポイントコスト
    response_guideline = Column(Text, nullable=True)                  # レスポンス文字数・スタイルの指示文
    voicevox_endpoint = Column(String, nullable=True)                 # VOICEVOX APIエンドポイント
    updated_at = Column(DateTime, server_default=func.now(), onupdate=func.now())


class TtsVoiceModel(Base):
    """TTS音声モデル（管理者がVOICEVOXスピーカーに表示名・性別を設定）"""
    __tablename__ = "tts_voice_models"

    id = Column(Integer, primary_key=True, autoincrement=True)
    speaker_uuid = Column(String, nullable=False)
    speaker_name = Column(String, nullable=True)    # VOICEVOXのスピーカー名（管理用）
    display_name = Column(String, nullable=False)   # 管理者が設定する表示名
    genre = Column(String, nullable=True)           # ジャンル（例: 少女、大人女性、ロボット）
    styles = Column(Text, nullable=True)            # JSON: [{id: int, name: str}]
    show_female = Column(Integer, default=0)        # 女性キャラの選択肢に表示
    show_male = Column(Integer, default=0)          # 男性キャラの選択肢に表示
    show_other = Column(Integer, default=0)         # その他キャラの選択肢に表示
    is_active = Column(Integer, default=1)
    sort_order = Column(Integer, default=0)
    created_at = Column(DateTime, server_default=func.now())
