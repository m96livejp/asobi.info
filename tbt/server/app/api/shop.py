from fastapi import APIRouter, HTTPException
from sqlalchemy import select

from app.deps import CurrentUser, DbSession
from app.models.shop import ShopProduct, PurchaseHistory
from app.schemas.shop import ShopProductResponse, PurchaseRequest, PurchaseResponse

router = APIRouter()


@router.get("/products", response_model=list[ShopProductResponse])
async def list_products(db: DbSession):
    """ショップ商品一覧"""
    result = await db.execute(select(ShopProduct).where(ShopProduct.is_active == 1))
    return [ShopProductResponse.model_validate(p) for p in result.scalars().all()]


@router.post("/purchase", response_model=PurchaseResponse)
async def purchase(req: PurchaseRequest, user: CurrentUser, db: DbSession):
    """商品購入 (現時点ではテスト用に直接付与。本番ではStripe連携)"""
    result = await db.execute(select(ShopProduct).where(ShopProduct.id == req.product_id))
    product = result.scalar_one_or_none()
    if not product:
        raise HTTPException(status_code=404, detail="Product not found")

    # TODO: Stripe決済連携 (Phase 2)
    # 現時点ではテスト用に直接付与
    total_premium = product.premium_currency_amount + product.bonus_amount
    user.premium_currency += total_premium

    history = PurchaseHistory(
        user_id=user.id,
        product_id=product.id,
        amount=product.price_yen,
        premium_currency_granted=total_premium,
        status="completed",
    )
    db.add(history)
    await db.flush()

    return PurchaseResponse(
        status="completed",
        message=f"{product.name}を購入しました。{total_premium}プレミアム通貨を獲得！",
        premium_currency_granted=total_premium,
    )
