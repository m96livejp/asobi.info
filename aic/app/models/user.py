"""ユーザーモデル"""
from sqlalchemy import Column, Integer, String, Text, DateTime
from sqlalchemy.sql import func
from ..database import Base

class User(Base):
    __tablename__ = "users"

    id = Column(Integer, primary_key=True, autoincrement=True)
    asobi_user_id = Column(Integer, unique=True, nullable=True)  # asobi.info連携
    device_id = Column(String, unique=True, nullable=True)       # ゲスト用
    display_name = Column(String, nullable=False, default="ゲスト")
    avatar_url = Column(Text, nullable=True)
    role = Column(String, nullable=False, default="user")
    created_at = Column(DateTime, server_default=func.now())
    updated_at = Column(DateTime, server_default=func.now(), onupdate=func.now())
