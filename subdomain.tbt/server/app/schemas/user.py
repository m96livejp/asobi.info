from datetime import datetime

from pydantic import BaseModel


class GuestRegisterRequest(BaseModel):
    device_id: str
    display_name: str = "プレイヤー"


class LoginRequest(BaseModel):
    device_id: str


class TokenResponse(BaseModel):
    access_token: str
    token_type: str = "bearer"
    user_id: str


class UserResponse(BaseModel):
    id: str
    display_name: str
    points: int
    premium_currency: int
    stamina: int
    normal_gacha_tickets: int
    premium_gacha_tickets: int
    item_gacha_tickets: int
    tutorial_completed: int
    created_at: datetime

    class Config:
        from_attributes = True


class UserUpdateRequest(BaseModel):
    display_name: str | None = None
