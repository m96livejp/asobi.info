from datetime import datetime

from sqlalchemy import String, Integer, Text, DateTime, ForeignKey, func
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.database import Base


class GachaPool(Base):
    __tablename__ = "gacha_pools"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    name: Mapped[str] = mapped_column(String(100))
    description: Mapped[str] = mapped_column(Text, default="")
    pool_type: Mapped[str] = mapped_column(String(20), default="character")  # character or item
    cost_type: Mapped[str] = mapped_column(String(20))  # points or premium
    cost_amount: Mapped[int] = mapped_column(Integer)
    is_active: Mapped[int] = mapped_column(Integer, default=1)
    pity_count: Mapped[int] = mapped_column(Integer, default=100)  # 天井回数

    items = relationship("GachaPoolItem", back_populates="pool", lazy="selectin")


class GachaPoolItem(Base):
    __tablename__ = "gacha_pool_items"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    pool_id: Mapped[int] = mapped_column(Integer, ForeignKey("gacha_pools.id"))
    template_id: Mapped[int] = mapped_column(Integer, ForeignKey("character_templates.id"))
    weight: Mapped[int] = mapped_column(Integer)

    pool = relationship("GachaPool", back_populates="items")
    template = relationship("CharacterTemplate", lazy="joined")


class GachaPoolItemEquip(Base):
    """アイテムガチャプールの排出アイテム"""
    __tablename__ = "gacha_pool_items_equip"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    pool_id: Mapped[int] = mapped_column(Integer, ForeignKey("gacha_pools.id"))
    item_template_id: Mapped[int] = mapped_column(Integer, ForeignKey("item_templates.id"))
    weight: Mapped[int] = mapped_column(Integer)

    pool = relationship("GachaPool")
    item_template = relationship("ItemTemplate", lazy="joined")


class GachaHistory(Base):
    __tablename__ = "gacha_history"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    user_id: Mapped[str] = mapped_column(String(36), ForeignKey("users.id"), index=True)
    pool_id: Mapped[int] = mapped_column(Integer, ForeignKey("gacha_pools.id"))
    template_id: Mapped[int] = mapped_column(Integer, ForeignKey("character_templates.id"))
    rarity: Mapped[int] = mapped_column(Integer)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=func.now())

    template = relationship("CharacterTemplate", lazy="joined")
