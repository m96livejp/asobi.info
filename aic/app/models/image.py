"""ユーザー生成画像モデル"""
from sqlalchemy import Column, Integer, String, Text, DateTime, Float
from sqlalchemy.sql import func
from ..database import Base


class ImageFeedback(Base):
    """マイナス評価時のフィードバック"""
    __tablename__ = "image_feedbacks"

    id          = Column(Integer, primary_key=True, autoincrement=True)
    image_id    = Column(Integer, nullable=False, index=True)
    user_id     = Column(Integer, nullable=False, index=True)
    reasons     = Column(Text, nullable=False, default="")   # カンマ区切り
    comment     = Column(Text, nullable=True)
    created_at  = Column(DateTime, server_default=func.now())


class GenerationQueue(Base):
    """画像生成キュー。SQLiteで永続化し、待ち人数・推定時間を算出可能にする。"""
    __tablename__ = "generation_queue"

    id              = Column(Integer, primary_key=True, autoincrement=True)
    user_id         = Column(Integer, nullable=False, index=True)
    status          = Column(String, nullable=False, default="pending", index=True)
    # pending / processing / completed / failed / cancelled
    prompt          = Column(Text, nullable=False)
    negative_prompt = Column(Text, nullable=True)
    model           = Column(String, nullable=True)
    template_id     = Column(Integer, nullable=True)
    steps           = Column(Integer, nullable=True)
    cfg_scale       = Column(Float, nullable=True)
    width           = Column(Integer, nullable=True)
    height          = Column(Integer, nullable=True)
    batch_size      = Column(Integer, nullable=False, default=6)
    error_message   = Column(Text, nullable=True)
    created_at      = Column(DateTime, server_default=func.now())
    started_at      = Column(DateTime, nullable=True)
    completed_at    = Column(DateTime, nullable=True)


class UserImage(Base):
    """ユーザーが生成した画像。status で pending/saved/discarded を管理する。
    - pending: 生成直後・保存/破棄前（画面を閉じても次回再表示）
    - saved:   ギャラリーに保存済み（100枚上限にカウント）
    - discarded: 破棄（ファイルはサーバーに残る）
    """
    __tablename__ = "user_images"

    id          = Column(Integer, primary_key=True, autoincrement=True)
    user_id     = Column(Integer, nullable=False, index=True)
    url         = Column(String,  nullable=False)
    prompt      = Column(Text,    nullable=True)
    template_id = Column(Integer, nullable=True)   # 使用したテンプレートID（nullable）
    status      = Column(String,  nullable=False, default="pending")   # pending/saved/discarded
    is_deleted  = Column(Integer, nullable=False, default=0)           # saved画像のソフトデリート
    is_favorite = Column(Integer, nullable=False, default=0)           # お気に入りフラグ
    rating      = Column(Integer, nullable=True)                         # 評価: -1=悪い, 1=まあ良い, 2=良い, 3=凄く良い
    model       = Column(String, nullable=True)                          # 使用モデル名
    seed        = Column(Integer, nullable=True)                           # 生成時のシード値
    created_at  = Column(DateTime, server_default=func.now())
