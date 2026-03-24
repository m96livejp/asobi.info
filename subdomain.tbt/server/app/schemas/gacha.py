from datetime import datetime

from pydantic import BaseModel


class GachaPoolResponse(BaseModel):
    id: int
    name: str
    description: str
    pool_type: str = "character"  # character or item
    cost_type: str
    cost_amount: int
    pity_count: int
    rates: dict[str, float] = {}  # レアリティ別確率

    class Config:
        from_attributes = True


class GachaPullRequest(BaseModel):
    pool_id: int
    count: int = 1  # 1 or 10
    use_ticket: bool = False  # チケットで引く場合True


class GachaResultItem(BaseModel):
    template_id: int
    name: str
    rarity: int
    is_new: bool = False


class GachaPullResponse(BaseModel):
    results: list[GachaResultItem]
    remaining_points: int = 0
    remaining_premium: int = 0
    remaining_normal_tickets: int = 0
    remaining_premium_tickets: int = 0
    remaining_item_gacha_tickets: int = 0
    pity_counter: int = 0  # 天井までの残り


class GachaHistoryItem(BaseModel):
    template_id: int
    name: str
    rarity: int
    created_at: datetime


class GachaHistoryResponse(BaseModel):
    history: list[GachaHistoryItem]
    total_pulls: int
