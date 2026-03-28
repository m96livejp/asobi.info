"""会話モデル"""
from sqlalchemy import Column, Integer, String, Text, DateTime
from sqlalchemy.sql import func
from ..database import Base

class Conversation(Base):
    __tablename__ = "conversations"

    id = Column(Integer, primary_key=True, autoincrement=True)
    user_id = Column(Integer, nullable=False)
    character_id = Column(Integer, nullable=False)
    title = Column(String, nullable=True)
    is_deleted = Column(Integer, nullable=False, default=0)  # 0=通常 1=ソフトデリート
    created_at = Column(DateTime, server_default=func.now())
    updated_at = Column(DateTime, server_default=func.now(), onupdate=func.now())


class ConversationState(Base):
    """会話ごとのキャラクターステータス・記憶"""
    __tablename__ = "conversation_state"

    id = Column(Integer, primary_key=True, autoincrement=True)
    conversation_id = Column(Integer, nullable=False, unique=True)
    relationship = Column(Text, default="")
    mood = Column(Text, default="")
    environment = Column(Text, default="")
    situation = Column(Text, default="")
    inventory = Column(Text, default="")
    goals = Column(Text, default="")
    memories = Column(Text, default="[]")    # JSON配列
    extra_fields = Column(Text, nullable=True)  # JSON: {key: value} カスタムフィールド
    updated_at = Column(DateTime, server_default=func.now(), onupdate=func.now())


class ConversationStateLog(Base):
    """STATEが更新されるたびに記録するログ"""
    __tablename__ = "conversation_state_logs"

    id = Column(Integer, primary_key=True, autoincrement=True)
    conversation_id = Column(Integer, nullable=False)
    relationship = Column(Text, default="")
    mood = Column(Text, default="")
    environment = Column(Text, default="")
    situation = Column(Text, default="")
    inventory = Column(Text, default="")
    goals = Column(Text, default="")
    memories = Column(Text, default="[]")
    extra_fields = Column(Text, nullable=True)  # JSON: {key: value} カスタムフィールド
    created_at = Column(DateTime, server_default=func.now())


class SEMissLog(Base):
    """存在しないSE名をログに記録（後から実装用）"""
    __tablename__ = "se_miss_logs"

    id = Column(Integer, primary_key=True, autoincrement=True)
    se_name = Column(String, nullable=False, unique=True)
    count = Column(Integer, default=1)
    last_seen = Column(DateTime, server_default=func.now(), onupdate=func.now())


class Message(Base):
    __tablename__ = "messages"

    id = Column(Integer, primary_key=True, autoincrement=True)
    conversation_id = Column(Integer, nullable=False)
    role = Column(String, nullable=False)  # 'user' / 'assistant'
    content = Column(Text, nullable=False)
    token_count = Column(Integer, default=0)
    is_deleted = Column(Integer, nullable=False, default=0)  # 0=通常 1=ソフトデリート
    created_at = Column(DateTime, server_default=func.now())
