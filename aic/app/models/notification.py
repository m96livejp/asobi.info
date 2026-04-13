"""ユーザー通知モデル"""
from sqlalchemy import Column, Integer, String, Text, DateTime
from sqlalchemy.sql import func
from ..database import Base


class Notification(Base):
    __tablename__ = "notifications"

    id          = Column(Integer, primary_key=True, autoincrement=True)
    user_id     = Column(Integer, nullable=False, index=True)
    type        = Column(String, nullable=False)            # 'character_approved' / 'character_rejected' / 'system'
    title       = Column(String, nullable=False)
    message     = Column(Text, nullable=True)
    related_id  = Column(Integer, nullable=True)            # character_id 等の関連ID
    related_url = Column(String, nullable=True)             # 遷移先URL（フロントで利用）
    is_read     = Column(Integer, nullable=False, default=0)  # 0=未読 / 1=既読
    created_at  = Column(DateTime, server_default=func.now())
