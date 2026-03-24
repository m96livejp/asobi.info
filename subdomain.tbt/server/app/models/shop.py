from datetime import datetime

from sqlalchemy import String, Integer, DateTime, ForeignKey, func
from sqlalchemy.orm import Mapped, mapped_column

from app.database import Base


class ShopProduct(Base):
    __tablename__ = "shop_products"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    name: Mapped[str] = mapped_column(String(100))
    description: Mapped[str] = mapped_column(String(255), default="")
    price_yen: Mapped[int] = mapped_column(Integer)  # 日本円
    premium_currency_amount: Mapped[int] = mapped_column(Integer)  # 付与する有料通貨
    bonus_amount: Mapped[int] = mapped_column(Integer, default=0)  # ボーナス
    is_active: Mapped[int] = mapped_column(Integer, default=1)


class PurchaseHistory(Base):
    __tablename__ = "purchase_history"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    user_id: Mapped[str] = mapped_column(String(36), ForeignKey("users.id"), index=True)
    stripe_payment_id: Mapped[str | None] = mapped_column(String(255), nullable=True)
    product_id: Mapped[int] = mapped_column(Integer, ForeignKey("shop_products.id"))
    amount: Mapped[int] = mapped_column(Integer)
    premium_currency_granted: Mapped[int] = mapped_column(Integer)
    status: Mapped[str] = mapped_column(String(20), default="pending")  # pending, completed, failed, refunded
    created_at: Mapped[datetime] = mapped_column(DateTime, default=func.now())
