import uuid
from datetime import datetime

from sqlalchemy import String, Integer, DateTime, func
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.database import Base


class User(Base):
    __tablename__ = "users"

    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=lambda: str(uuid.uuid4()))
    device_id: Mapped[str | None] = mapped_column(String(255), unique=True, index=True, nullable=True)
    email: Mapped[str | None] = mapped_column(String(255), unique=True, index=True, nullable=True)
    password_hash: Mapped[str | None] = mapped_column(String(255), nullable=True)
    email_verified: Mapped[int] = mapped_column(Integer, default=0)
    display_name: Mapped[str] = mapped_column(String(50), default="プレイヤー")
    points: Mapped[int] = mapped_column(Integer, default=500)  # 初期ポイント
    premium_currency: Mapped[int] = mapped_column(Integer, default=0)
    stamina: Mapped[int] = mapped_column(Integer, default=100)
    stamina_updated_at: Mapped[datetime] = mapped_column(DateTime, default=func.now())
    normal_gacha_tickets: Mapped[int] = mapped_column(Integer, default=10)
    premium_gacha_tickets: Mapped[int] = mapped_column(Integer, default=1)
    item_gacha_tickets: Mapped[int] = mapped_column(Integer, default=3)
    tutorial_completed: Mapped[int] = mapped_column(Integer, default=0)
    is_admin: Mapped[int] = mapped_column(Integer, default=0)
    admin_memo: Mapped[str] = mapped_column(String(500), default="")
    asobi_user_id: Mapped[int | None] = mapped_column(Integer, unique=True, index=True, nullable=True)
    last_login_at: Mapped[datetime | None] = mapped_column(DateTime, nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime, default=func.now(), onupdate=func.now())

    characters = relationship("Character", back_populates="user", lazy="selectin")
    social_accounts = relationship("SocialAccount", back_populates="user", lazy="selectin")


class AdReward(Base):
    __tablename__ = "ad_rewards"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    user_id: Mapped[str] = mapped_column(String(36), index=True)
    reward_type: Mapped[str] = mapped_column(String(20))  # points, stamina, gacha_ticket
    reward_amount: Mapped[int] = mapped_column(Integer)
    ad_type: Mapped[str] = mapped_column(String(50), default="rewarded")
    created_at: Mapped[datetime] = mapped_column(DateTime, default=func.now())
