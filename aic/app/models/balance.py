"""残高モデル"""
from sqlalchemy import Column, Integer, String, Text, DateTime
from sqlalchemy.sql import func
from ..database import Base

class UserBalance(Base):
    __tablename__ = "user_balance"

    user_id = Column(Integer, primary_key=True)
    points = Column(Integer, nullable=False, default=100)  # 無料ポイント（初期100）
    crystals = Column(Integer, nullable=False, default=0)   # 有料通貨
    updated_at = Column(DateTime, server_default=func.now(), onupdate=func.now())


class BalanceTransaction(Base):
    __tablename__ = "balance_transactions"

    id = Column(Integer, primary_key=True, autoincrement=True)
    user_id = Column(Integer, nullable=False)
    currency = Column(String, nullable=False)  # 'points' / 'crystals'
    amount = Column(Integer, nullable=False)    # +獲得 / -消費
    type = Column(String, nullable=False)       # 'chat','reward','convert','purchase','signup'
    memo = Column(Text, nullable=True)
    created_at = Column(DateTime, server_default=func.now())
