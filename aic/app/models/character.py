"""AIキャラクターモデル"""
from sqlalchemy import Column, Integer, String, Text, DateTime, UniqueConstraint
from sqlalchemy.sql import func
from ..database import Base

class Character(Base):
    __tablename__ = "characters"

    id = Column(Integer, primary_key=True, autoincrement=True)
    creator_id = Column(Integer, nullable=False)
    name = Column(String, nullable=False)
    avatar_url = Column(Text, nullable=True)
    ai_model = Column(String, nullable=False, default="claude")
    is_public = Column(Integer, default=0)
    is_sample = Column(Integer, default=0)

    # プロンプト設定
    char_name = Column(String, nullable=True)
    char_age = Column(String, nullable=True)
    profile = Column(Text, nullable=True)
    private_profile = Column(Text, nullable=True)
    first_message = Column(Text, nullable=True)
    voice_model = Column(String, nullable=True)

    # ジャンル（JSON配列）
    genre_story = Column(Text, default="[]")
    genre_char_type = Column(Text, default="[]")
    genre_personality = Column(Text, default="[]")
    genre_era = Column(Text, default="[]")
    genre_base = Column(Text, default="[]")
    keywords = Column(Text, default="[]")

    # 評価
    like_count = Column(Integer, default=0)
    use_count = Column(Integer, default=0)

    created_at = Column(DateTime, server_default=func.now())
    updated_at = Column(DateTime, server_default=func.now(), onupdate=func.now())


class CharacterLike(Base):
    __tablename__ = "character_likes"

    id = Column(Integer, primary_key=True, autoincrement=True)
    user_id = Column(Integer, nullable=False)
    character_id = Column(Integer, nullable=False)
    created_at = Column(DateTime, server_default=func.now())

    __table_args__ = (UniqueConstraint('user_id', 'character_id'),)
