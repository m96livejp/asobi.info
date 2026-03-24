import uuid
from datetime import datetime

from sqlalchemy import String, Integer, Text, DateTime, ForeignKey, func, JSON
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.database import Base


class Tournament(Base):
    __tablename__ = "tournaments"

    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=lambda: str(uuid.uuid4()))
    name: Mapped[str] = mapped_column(String(100))
    status: Mapped[str] = mapped_column(String(20), default="recruiting")  # recruiting, in_progress, finished
    max_participants: Mapped[int] = mapped_column(Integer, default=8)
    current_round: Mapped[int] = mapped_column(Integer, default=0)
    entry_cost_type: Mapped[str] = mapped_column(String(20), default="free")  # free, points, premium
    entry_cost_amount: Mapped[int] = mapped_column(Integer, default=0)
    reward_points: Mapped[int] = mapped_column(Integer, default=100)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=func.now())

    entries = relationship("TournamentEntry", back_populates="tournament", lazy="selectin")
    battles = relationship("BattleLog", back_populates="tournament", lazy="selectin")


class TournamentEntry(Base):
    __tablename__ = "tournament_entries"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    tournament_id: Mapped[str] = mapped_column(String(36), ForeignKey("tournaments.id"), index=True)
    user_id: Mapped[str] = mapped_column(String(36), ForeignKey("users.id"))
    character_id: Mapped[str] = mapped_column(String(36), ForeignKey("characters.id"))
    seed: Mapped[int | None] = mapped_column(Integer, nullable=True)
    eliminated_round: Mapped[int | None] = mapped_column(Integer, nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=func.now())

    tournament = relationship("Tournament", back_populates="entries")
    user = relationship("User", lazy="joined")
    character = relationship("Character", lazy="joined")


class BattleLog(Base):
    __tablename__ = "battle_logs"

    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=lambda: str(uuid.uuid4()))
    tournament_id: Mapped[str | None] = mapped_column(String(36), ForeignKey("tournaments.id"), nullable=True)
    round: Mapped[int] = mapped_column(Integer, default=0)
    attacker_id: Mapped[str] = mapped_column(String(36), ForeignKey("characters.id"))
    defender_id: Mapped[str] = mapped_column(String(36), ForeignKey("characters.id"))
    winner_id: Mapped[str] = mapped_column(String(36))
    battle_data: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=func.now())

    tournament = relationship("Tournament", back_populates="battles")
    attacker = relationship("Character", foreign_keys=[attacker_id], lazy="joined")
    defender = relationship("Character", foreign_keys=[defender_id], lazy="joined")
