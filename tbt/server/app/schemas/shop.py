from pydantic import BaseModel


class ShopProductResponse(BaseModel):
    id: int
    name: str
    description: str
    price_yen: int
    premium_currency_amount: int
    bonus_amount: int

    class Config:
        from_attributes = True


class PurchaseRequest(BaseModel):
    product_id: int


class PurchaseResponse(BaseModel):
    status: str
    message: str
    premium_currency_granted: int = 0


class AdRewardRequest(BaseModel):
    ad_type: str = "rewarded"
    reward_type: str = "points"  # points, stamina, gacha_ticket


class AdRewardResponse(BaseModel):
    reward_type: str
    reward_amount: int
    remaining_daily_views: int
    remaining_hourly_views: int = 0
    points: int = 0
    stamina: int = 0
