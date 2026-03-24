from datetime import datetime

from pydantic import BaseModel


# --- プレイヤー ---

class AdminUserSummary(BaseModel):
    id: str
    display_name: str
    points: int
    premium_currency: int
    is_admin: int = 0
    admin_memo: str = ""
    character_count: int = 0
    is_npc: bool = False
    has_email: bool = False
    has_social: bool = False
    last_login_at: datetime | None = None
    created_at: datetime

    class Config:
        from_attributes = True


class AdminUserDetail(BaseModel):
    id: str
    device_id: str | None = None
    email: str | None = None
    email_verified: int = 0
    display_name: str
    points: int
    premium_currency: int
    stamina: int
    normal_gacha_tickets: int
    premium_gacha_tickets: int
    is_admin: int = 0
    admin_memo: str = ""
    last_login_at: datetime | None = None
    created_at: datetime
    updated_at: datetime
    character_count: int = 0
    social_accounts: list[dict] = []

    class Config:
        from_attributes = True


class AdminUserUpdate(BaseModel):
    display_name: str | None = None
    points: int | None = None
    premium_currency: int | None = None
    stamina: int | None = None
    normal_gacha_tickets: int | None = None
    premium_gacha_tickets: int | None = None
    admin_memo: str | None = None
    is_admin: int | None = None


class AdminUserListResponse(BaseModel):
    users: list[AdminUserSummary]
    total: int
    page: int
    per_page: int


# --- キャラクター ---

class AdminCharacterSummary(BaseModel):
    id: str
    user_id: str
    user_name: str
    template_name: str
    race: str
    level: int
    rarity: int
    hp: int
    atk: int
    def_: int
    spd: int
    created_at: datetime

    class Config:
        from_attributes = True


class AdminCharacterUpdate(BaseModel):
    hp: int | None = None
    atk: int | None = None
    def_: int | None = None
    spd: int | None = None
    level: int | None = None
    exp: int | None = None


# --- アイテム ---

class AdminUserItemSummary(BaseModel):
    id: int
    user_id: str
    user_name: str
    item_name: str
    item_type: str
    rarity: int
    quantity: int
    created_at: datetime

    class Config:
        from_attributes = True


class AdminUserItemUpdate(BaseModel):
    user_id: str | None = None
    quantity: int | None = None


# --- 設定 ---

class AdminSettingsResponse(BaseModel):
    settings: dict[str, object]


class AdminSettingsUpdate(BaseModel):
    settings: dict[str, object]


# --- NPC名前 ---

class NpcNameResponse(BaseModel):
    id: int
    name: str
    is_active: int

    class Config:
        from_attributes = True


class NpcNameCreate(BaseModel):
    name: str


# --- トーナメント ---

class AdminTournamentSummary(BaseModel):
    id: str
    name: str
    status: str
    max_participants: int
    current_participants: int
    current_round: int
    reward_points: int
    created_at: datetime

    class Config:
        from_attributes = True


# --- ログ ---

class AdminAdRewardLog(BaseModel):
    id: int
    user_id: str
    user_name: str = ""
    reward_type: str
    reward_amount: int
    ad_type: str
    created_at: datetime

    class Config:
        from_attributes = True


class AdminPurchaseLog(BaseModel):
    id: int
    user_id: str
    user_name: str = ""
    product_name: str = ""
    amount: int
    premium_currency_granted: int
    status: str
    created_at: datetime

    class Config:
        from_attributes = True


# --- 監査ログ ---

class AdminAuditLogResponse(BaseModel):
    id: int
    admin_user_id: str
    action: str
    target_type: str | None = None
    target_id: str | None = None
    details_json: str | None = None
    created_at: datetime

    class Config:
        from_attributes = True
