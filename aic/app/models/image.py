"""ユーザー生成画像モデル"""
from sqlalchemy import Column, Integer, String, Text, DateTime
from sqlalchemy.sql import func
from ..database import Base


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
    created_at  = Column(DateTime, server_default=func.now())
