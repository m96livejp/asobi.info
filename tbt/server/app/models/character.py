import uuid
from datetime import datetime

from sqlalchemy import String, Integer, Float, Text, DateTime, ForeignKey, func
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.database import Base


class CharacterTemplate(Base):
    __tablename__ = "character_templates"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    name: Mapped[str] = mapped_column(String(100))
    rarity: Mapped[int] = mapped_column(Integer)  # 1:N, 2:R, 3:SR, 4:SSR, 5:UR
    base_hp: Mapped[int] = mapped_column(Integer)
    base_atk: Mapped[int] = mapped_column(Integer)
    base_def: Mapped[int] = mapped_column(Integer)
    base_spd: Mapped[int] = mapped_column(Integer)
    skill_name: Mapped[str] = mapped_column(String(100), default="")
    skill_description: Mapped[str] = mapped_column(Text, default="")
    skill_power: Mapped[float] = mapped_column(Float, default=1.0)
    image_url: Mapped[str] = mapped_column(String(255), default="")


class Character(Base):
    __tablename__ = "characters"

    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=lambda: str(uuid.uuid4()))
    user_id: Mapped[str] = mapped_column(String(36), ForeignKey("users.id"), index=True)
    template_id: Mapped[int] = mapped_column(Integer, ForeignKey("character_templates.id"))
    race: Mapped[str] = mapped_column(String(20), default="warrior")  # warrior, mage, beastman
    level: Mapped[int] = mapped_column(Integer, default=1)
    exp: Mapped[int] = mapped_column(Integer, default=0)
    is_favorite: Mapped[bool] = mapped_column(default=False)
    hp: Mapped[int] = mapped_column(Integer)
    atk: Mapped[int] = mapped_column(Integer)
    def_: Mapped[int] = mapped_column("def", Integer)
    spd: Mapped[int] = mapped_column(Integer)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=func.now())

    user = relationship("User", back_populates="characters")
    template = relationship("CharacterTemplate", lazy="joined")
    equipment = relationship("CharacterEquipment", back_populates="character", lazy="selectin")


class CharacterEquipment(Base):
    __tablename__ = "character_equipment"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    character_id: Mapped[str] = mapped_column(String(36), ForeignKey("characters.id"), index=True)
    slot: Mapped[str] = mapped_column(String(20))  # weapon1, weapon2, head, body, hands, feet, accessory1-3
    item_template_id: Mapped[int] = mapped_column(Integer, ForeignKey("item_templates.id"))
    created_at: Mapped[datetime] = mapped_column(DateTime, default=func.now())

    character = relationship("Character", back_populates="equipment")
    item_template = relationship("ItemTemplate", lazy="joined")
