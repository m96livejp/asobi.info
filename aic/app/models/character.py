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
    gender = Column(String, nullable=True)  # female, male, other
    profile = Column(Text, nullable=True)
    private_profile = Column(Text, nullable=True)
    first_message = Column(Text, nullable=True)
    voice_model = Column(String, nullable=True)   # VOICEVOX speaker UUID
    tts_styles = Column(Text, nullable=True)       # JSON: [{id: int, name: str}]

    # ジャンル（JSON配列）
    genre_story = Column(Text, default="[]")
    genre_char_type = Column(Text, default="[]")
    genre_personality = Column(Text, default="[]")
    genre_era = Column(Text, default="[]")
    genre_base = Column(Text, default="[]")
    keywords = Column(Text, default="[]")

    # AI審査
    review_status = Column(String, default="pending")  # pending / approved / rejected
    review_note = Column(Text, nullable=True)

    # ソフトデリート: 0=有効, 1=作成者削除, 2=管理者削除
    is_deleted = Column(Integer, default=0)

    # 初期ステータス（AI審査で設定）
    init_relationship = Column(Text, default="")
    init_mood = Column(Text, default="")
    init_environment = Column(Text, default="")
    init_situation = Column(Text, default="")
    init_inventory = Column(Text, default="")
    init_goals = Column(Text, default="")

    # Stable Diffusion 設定（画像変化機能用）
    sd_prompt    = Column(Text, nullable=True)    # 元画像生成時のプロンプト
    sd_neg_prompt = Column(Text, nullable=True)   # ネガティブプロンプト
    sd_seed      = Column(Integer, nullable=True) # 元画像生成時のシード値
    sd_model     = Column(String, nullable=True)  # 使用したSDモデル名

    # BGM設定
    bgm_mode = Column(String, default="none")  # none / manual / auto
    bgm_track_id = Column(Integer, nullable=True)  # FK: bgm_tracks.id (manual時)

    # 管理者フラグ
    is_recommended = Column(Integer, default=0)  # おすすめ表示

    # 評価
    like_count = Column(Integer, default=0)
    use_count = Column(Integer, default=0)

    created_at = Column(DateTime, server_default=func.now())
    updated_at = Column(DateTime, server_default=func.now(), onupdate=func.now())


class BgmTrack(Base):
    """BGM音楽トラック"""
    __tablename__ = "bgm_tracks"

    id = Column(Integer, primary_key=True, autoincrement=True)
    name = Column(String, nullable=False)        # 表示名
    file_path = Column(String, nullable=False)   # /bgm/xxx.mp3 形式（frontend相対）
    created_at = Column(DateTime, server_default=func.now())


class CharacterLike(Base):
    __tablename__ = "character_likes"

    id = Column(Integer, primary_key=True, autoincrement=True)
    user_id = Column(Integer, nullable=False)
    character_id = Column(Integer, nullable=False)
    status = Column(Integer, nullable=False, default=1)  # 1以上: お気に入り, -1: 解除済み
    created_at = Column(DateTime, server_default=func.now())

    __table_args__ = (UniqueConstraint('user_id', 'character_id'),)


class CharacterReport(Base):
    __tablename__ = "character_reports"

    id = Column(Integer, primary_key=True, autoincrement=True)
    character_id = Column(Integer, nullable=False, index=True)
    user_id = Column(Integer, nullable=False, index=True)
    category = Column(String, nullable=False, default="")
    reason = Column(Text, nullable=False, default="")
    status = Column(String, nullable=False, default="pending")  # pending / reviewed / dismissed
    created_at = Column(DateTime, server_default=func.now())
