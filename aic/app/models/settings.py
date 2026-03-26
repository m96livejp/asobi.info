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


class AiSettings(Base):
    __tablename__ = "ai_settings"

    id = Column(Integer, primary_key=True, autoincrement=True)
    provider = Column(String, nullable=False, default="claude")       # claude / openai / ollama
    endpoint = Column(String, nullable=True)                          # Ollama等のエンドポイントURL
    api_key = Column(String, nullable=True)                           # APIキー（Claude/OpenAI）
    model = Column(String, nullable=False, default="claude-sonnet-4-20250514")
    max_tokens = Column(Integer, nullable=False, default=1024)
    cost = Column(Integer, nullable=False, default=1)                 # 1メッセージあたりのポイントコスト
    updated_at = Column(DateTime, server_default=func.now(), onupdate=func.now())
