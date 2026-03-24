from datetime import datetime

from sqlalchemy import String, Integer, Float, Text, DateTime, ForeignKey, func
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.database import Base


class ItemTemplate(Base):
    __tablename__ = "item_templates"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    name: Mapped[str] = mapped_column(String(100))
    item_type: Mapped[str] = mapped_column(String(30))
    # treasure_pt, treasure_equip, treasure_ticket, equipment, ticket_normal, ticket_premium
    rarity: Mapped[int] = mapped_column(Integer, default=1)  # 1:N ~ 5:UR
    description: Mapped[str] = mapped_column(Text, default="")
    # 宝箱用
    min_value: Mapped[int] = mapped_column(Integer, default=0)
    max_value: Mapped[int] = mapped_column(Integer, default=0)
    # 装備用
    equip_slot: Mapped[str] = mapped_column(String(20), default="")
    # weapon_1h, weapon_2h, shield, head, body, hands, feet, accessory
    equip_race: Mapped[str] = mapped_column(String(20), default="all")
    # all, warrior, mage, beastman
    bonus_hp: Mapped[int] = mapped_column(Integer, default=0)
    bonus_atk: Mapped[int] = mapped_column(Integer, default=0)
    bonus_def: Mapped[int] = mapped_column(Integer, default=0)
    bonus_spd: Mapped[int] = mapped_column(Integer, default=0)
    effect_name: Mapped[str] = mapped_column(String(50), default="")
    # critical_rate, dodge_rate, counter_rate, heal_per_turn
    effect_value: Mapped[float] = mapped_column(Float, default=0.0)
    image_url: Mapped[str] = mapped_column(String(255), default="")


class UserItem(Base):
    __tablename__ = "user_items"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    user_id: Mapped[str] = mapped_column(String(36), ForeignKey("users.id"), index=True)
    item_template_id: Mapped[int] = mapped_column(Integer, ForeignKey("item_templates.id"))
    quantity: Mapped[int] = mapped_column(Integer, default=1)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=func.now())

    item_template = relationship("ItemTemplate", lazy="joined")
