import json
from datetime import datetime

from fastapi import APIRouter, HTTPException, Query
from passlib.context import CryptContext
from pydantic import BaseModel
from sqlalchemy import select, func, or_
from sqlalchemy.orm import selectinload, joinedload

from app.deps import DbSession, AdminUser
from app.models.user import User, AdReward
from app.models.character import Character, CharacterTemplate
from app.models.item import UserItem, ItemTemplate
from app.models.battle import Tournament, TournamentEntry, BattleLog
from app.models.shop import PurchaseHistory, ShopProduct
from app.models.social_account import SocialAccount
from app.models.admin import AppSetting, NpcName, AdminAuditLog
from app.models.gacha import GachaPool, GachaPoolItem, GachaPoolItemEquip
from app.schemas.admin import (
    AdminUserSummary, AdminUserDetail, AdminUserUpdate, AdminUserListResponse,
    AdminCharacterSummary, AdminCharacterUpdate,
    AdminUserItemSummary, AdminUserItemUpdate,
    AdminSettingsResponse, AdminSettingsUpdate,
    NpcNameResponse, NpcNameCreate,
    AdminTournamentSummary,
    AdminAdRewardLog, AdminPurchaseLog,
    AdminAuditLogResponse,
)
from app.services.settings_service import get_all_settings, update_settings
from app.services.auth_service import create_access_token

router = APIRouter()
pwd_context = CryptContext(schemes=["bcrypt"], deprecated="auto")


# ==================== 管理者ログイン ====================

class AdminLoginRequest(BaseModel):
    email: str  # メールアドレスまたは表示名
    password: str


@router.post("/login")
async def admin_login(req: AdminLoginRequest, db: DbSession):
    """管理者専用ログイン（is_admin=1のユーザーのみ、メールアドレスまたは表示名で認証）"""
    from sqlalchemy import or_
    result = await db.execute(
        select(User).where(or_(User.email == req.email, User.display_name == req.email))
    )
    user = result.scalar_one_or_none()

    if user is None or user.password_hash is None:
        raise HTTPException(status_code=401, detail="ログイン情報が正しくありません")

    if not pwd_context.verify(req.password, user.password_hash):
        raise HTTPException(status_code=401, detail="ログイン情報が正しくありません")

    if not user.is_admin:
        raise HTTPException(status_code=403, detail="管理者権限がありません")

    user.last_login_at = datetime.now()
    token = create_access_token(user.id)
    return {"access_token": token, "token_type": "bearer", "user_id": user.id, "display_name": user.display_name}


ADMIN_TOKEN_SECRET = "asobi-tbt-admin-2026-secret-key"


class AsobiTokenLoginRequest(BaseModel):
    token: str


@router.post("/login-asobi")
async def admin_login_asobi(req: AsobiTokenLoginRequest, db: DbSession):
    """asobi.info管理者トークンによる自動ログイン"""
    import hmac
    import hashlib
    import base64
    import time as _time

    try:
        payload_b64, signature = req.token.rsplit(".", 1)
        payload = base64.b64decode(payload_b64).decode("utf-8")
        email, ts_str = payload.rsplit(":", 1)
        timestamp = int(ts_str)
    except Exception:
        raise HTTPException(status_code=401, detail="不正なトークンです")

    # HMAC検証
    expected = hmac.new(
        ADMIN_TOKEN_SECRET.encode(), payload.encode(), hashlib.sha256
    ).hexdigest()
    if not hmac.compare_digest(expected, signature):
        raise HTTPException(status_code=401, detail="トークンの署名が無効です")

    # 有効期限チェック（5分）
    if abs(_time.time() - timestamp) > 300:
        raise HTTPException(status_code=401, detail="トークンの有効期限が切れています")

    # メールアドレスで管理者ユーザーを検索
    result = await db.execute(select(User).where(User.email == email))
    user = result.scalar_one_or_none()

    if user is None or not user.is_admin:
        raise HTTPException(status_code=403, detail="管理者権限がありません")

    user.last_login_at = datetime.now()
    token = create_access_token(user.id)
    return {"access_token": token, "token_type": "bearer", "user_id": user.id, "display_name": user.display_name}


async def _audit_log(db, admin_user_id: str, action: str, target_type: str = None, target_id: str = None, details: dict = None):
    log = AdminAuditLog(
        admin_user_id=admin_user_id,
        action=action,
        target_type=target_type,
        target_id=target_id,
        details_json=json.dumps(details, ensure_ascii=False) if details else None,
    )
    db.add(log)


# ==================== プレイヤー ====================

@router.get("/users", response_model=AdminUserListResponse)
async def list_users(
    admin: AdminUser,
    db: DbSession,
    q: str = "",
    name: str = "",
    id_q: str = "",
    email_q: str = "",
    page: int = Query(1, ge=1),
    per_page: int = Query(20, ge=1, le=100),
    sort: str = "created_at_desc",
):
    """プレイヤー一覧（検索・ページネーション付き）"""
    base_query = select(User)
    count_query = select(func.count(User.id))

    if q:
        filter_cond = or_(
            User.display_name.contains(q),
            User.id.contains(q),
            User.email.contains(q),
        )
        base_query = base_query.where(filter_cond)
        count_query = count_query.where(filter_cond)
    else:
        if name:
            base_query = base_query.where(User.display_name.contains(name))
            count_query = count_query.where(User.display_name.contains(name))
        if id_q:
            base_query = base_query.where(User.id.contains(id_q))
            count_query = count_query.where(User.id.contains(id_q))
        if email_q:
            base_query = base_query.where(User.email.contains(email_q))
            count_query = count_query.where(User.email.contains(email_q))

    # ソート
    sort_map = {
        "created_at_asc": User.created_at.asc(),
        "created_at_desc": User.created_at.desc(),
        "points_asc": User.points.asc(),
        "points_desc": User.points.desc(),
        "premium_asc": User.premium_currency.asc(),
        "premium_desc": User.premium_currency.desc(),
        "name_asc": User.display_name.asc(),
        "name_desc": User.display_name.desc(),
        "last_login_asc": User.last_login_at.asc(),
        "last_login_desc": User.last_login_at.desc(),
    }
    base_query = base_query.order_by(sort_map.get(sort, User.created_at.desc()))

    total_result = await db.execute(count_query)
    total = total_result.scalar()

    offset = (page - 1) * per_page
    result = await db.execute(base_query.offset(offset).limit(per_page))
    users = result.scalars().all()

    # キャラクター数を集計
    user_ids = [u.id for u in users]
    char_counts = {}
    if user_ids:
        char_result = await db.execute(
            select(Character.user_id, func.count(Character.id))
            .where(Character.user_id.in_(user_ids))
            .group_by(Character.user_id)
        )
        char_counts = dict(char_result.all())

    summaries = []
    for u in users:
        is_npc = bool(u.device_id and u.device_id.startswith("npc_"))
        has_social = len(u.social_accounts) > 0 if u.social_accounts else False
        summaries.append(AdminUserSummary(
            id=u.id,
            display_name=u.display_name,
            points=u.points,
            premium_currency=u.premium_currency,
            is_admin=u.is_admin,
            admin_memo=u.admin_memo or "",
            character_count=char_counts.get(u.id, 0),
            is_npc=is_npc,
            has_email=u.email is not None,
            has_social=has_social,
            last_login_at=u.last_login_at,
            created_at=u.created_at,
        ))

    return AdminUserListResponse(users=summaries, total=total, page=page, per_page=per_page)


@router.get("/users/{user_id}", response_model=AdminUserDetail)
async def get_user(user_id: str, admin: AdminUser, db: DbSession):
    """プレイヤー詳細"""
    result = await db.execute(select(User).where(User.id == user_id))
    user = result.scalar_one_or_none()
    if not user:
        raise HTTPException(status_code=404, detail="User not found")

    char_result = await db.execute(
        select(func.count(Character.id)).where(Character.user_id == user_id)
    )
    char_count = char_result.scalar()

    social_result = await db.execute(
        select(SocialAccount).where(SocialAccount.user_id == user_id)
    )
    socials = [
        {"provider": s.provider, "email": s.email, "display_name": s.display_name}
        for s in social_result.scalars().all()
    ]

    return AdminUserDetail(
        id=user.id,
        device_id=user.device_id,
        email=user.email,
        email_verified=user.email_verified,
        display_name=user.display_name,
        points=user.points,
        premium_currency=user.premium_currency,
        stamina=user.stamina,
        normal_gacha_tickets=user.normal_gacha_tickets,
        premium_gacha_tickets=user.premium_gacha_tickets,
        is_admin=user.is_admin,
        admin_memo=user.admin_memo or "",
        last_login_at=user.last_login_at,
        created_at=user.created_at,
        updated_at=user.updated_at,
        character_count=char_count,
        social_accounts=socials,
    )


@router.patch("/users/{user_id}")
async def update_user(user_id: str, req: AdminUserUpdate, admin: AdminUser, db: DbSession):
    """プレイヤー情報更新"""
    result = await db.execute(select(User).where(User.id == user_id))
    user = result.scalar_one_or_none()
    if not user:
        raise HTTPException(status_code=404, detail="User not found")

    changes = {}
    for field, value in req.model_dump(exclude_unset=True).items():
        old_val = getattr(user, field)
        setattr(user, field, value)
        changes[field] = {"old": old_val, "new": value}

    await _audit_log(db, admin.id, "update_user", "user", user_id, changes)
    await db.flush()
    return {"message": "Updated", "changes": changes}


@router.post("/users/{user_id}/unlink/{provider}")
async def unlink_provider(user_id: str, provider: str, admin: AdminUser, db: DbSession):
    """OAuthプロバイダーの強制解除"""
    if provider == "email":
        result = await db.execute(select(User).where(User.id == user_id))
        user = result.scalar_one_or_none()
        if not user:
            raise HTTPException(status_code=404, detail="User not found")
        user.email = None
        user.password_hash = None
        user.email_verified = 0
    else:
        result = await db.execute(
            select(SocialAccount).where(
                SocialAccount.user_id == user_id,
                SocialAccount.provider == provider,
            )
        )
        social = result.scalar_one_or_none()
        if not social:
            raise HTTPException(status_code=404, detail="Provider not linked")
        await db.delete(social)

    await _audit_log(db, admin.id, "unlink_provider", "user", user_id, {"provider": provider})
    await db.flush()
    return {"message": f"Unlinked {provider}"}


@router.post("/users/{user_id}/convert-npc")
async def convert_to_npc(user_id: str, admin: AdminUser, db: DbSession):
    """ユーザーをNPC化（ログイン無効化）"""
    result = await db.execute(select(User).where(User.id == user_id))
    user = result.scalar_one_or_none()
    if not user:
        raise HTTPException(status_code=404, detail="User not found")

    # ソーシャルアカウント削除
    social_result = await db.execute(
        select(SocialAccount).where(SocialAccount.user_id == user_id)
    )
    for social in social_result.scalars().all():
        await db.delete(social)

    import time
    user.device_id = f"npc_{user.display_name}_{int(time.time())}"
    user.email = None
    user.password_hash = None
    user.email_verified = 0

    await _audit_log(db, admin.id, "convert_npc", "user", user_id)
    await db.flush()
    return {"message": "Converted to NPC"}


@router.delete("/users/{user_id}")
async def delete_user(user_id: str, admin: AdminUser, db: DbSession):
    """ユーザー削除（関連データ全削除）"""
    from sqlalchemy import delete as sa_delete
    from app.models.character import CharacterEquipment
    from app.models.gacha import GachaHistory
    from app.models.battle import TournamentEntry

    result = await db.execute(select(User).where(User.id == user_id))
    user = result.scalar_one_or_none()
    if not user:
        raise HTTPException(status_code=404, detail="User not found")

    if user.is_admin:
        raise HTTPException(status_code=400, detail="管理者ユーザーは削除できません")

    display_name = user.display_name

    # キャラクターに紐づく装備・トーナメントエントリー削除
    char_ids_result = await db.execute(
        select(Character.id).where(Character.user_id == user_id)
    )
    char_ids = [row[0] for row in char_ids_result.all()]
    if char_ids:
        await db.execute(sa_delete(CharacterEquipment).where(CharacterEquipment.character_id.in_(char_ids)))
        await db.execute(sa_delete(TournamentEntry).where(TournamentEntry.character_id.in_(char_ids)))

    # ユーザー直接参照のデータを削除
    await db.execute(sa_delete(Character).where(Character.user_id == user_id))
    await db.execute(sa_delete(UserItem).where(UserItem.user_id == user_id))
    await db.execute(sa_delete(GachaHistory).where(GachaHistory.user_id == user_id))
    await db.execute(sa_delete(AdReward).where(AdReward.user_id == user_id))
    await db.execute(sa_delete(PurchaseHistory).where(PurchaseHistory.user_id == user_id))
    await db.execute(sa_delete(SocialAccount).where(SocialAccount.user_id == user_id))
    await db.execute(sa_delete(TournamentEntry).where(TournamentEntry.user_id == user_id))

    await db.delete(user)
    await _audit_log(db, admin.id, "delete_user", "user", user_id, {"display_name": display_name})
    await db.flush()
    return {"message": f"{display_name} を削除しました"}


# ==================== キャラクター ====================

@router.get("/characters")
async def list_characters(
    admin: AdminUser,
    db: DbSession,
    user_id: str = "",
    q: str = "",
    player_q: str = "",
    char_q: str = "",
    sort: str = "created_at_desc",
    page: int = Query(1, ge=1),
    per_page: int = Query(50, ge=1, le=200),
):
    """キャラクター一覧（ページネーション付き）"""
    # 検索用のJOIN条件
    join_query = (
        select(Character)
        .join(CharacterTemplate, Character.template_id == CharacterTemplate.id)
        .join(User, Character.user_id == User.id)
    )
    count_query = (
        select(func.count(Character.id))
        .join(CharacterTemplate, Character.template_id == CharacterTemplate.id)
        .join(User, Character.user_id == User.id)
    )

    if user_id:
        join_query = join_query.where(Character.user_id == user_id)
        count_query = count_query.where(Character.user_id == user_id)
    if q:
        search_cond = or_(
            User.display_name.contains(q),
            CharacterTemplate.name.contains(q),
        )
        join_query = join_query.where(search_cond)
        count_query = count_query.where(search_cond)
    else:
        if player_q:
            join_query = join_query.where(User.display_name.contains(player_q))
            count_query = count_query.where(User.display_name.contains(player_q))
        if char_q:
            join_query = join_query.where(CharacterTemplate.name.contains(char_q))
            count_query = count_query.where(CharacterTemplate.name.contains(char_q))

    total_result = await db.execute(count_query)
    total = total_result.scalar()

    char_sort_map = {
        "created_at_asc": Character.created_at.asc(),
        "created_at_desc": Character.created_at.desc(),
        "name_asc": CharacterTemplate.name.asc(),
        "name_desc": CharacterTemplate.name.desc(),
        "rarity_asc": CharacterTemplate.rarity.asc(),
        "rarity_desc": CharacterTemplate.rarity.desc(),
        "level_asc": Character.level.asc(),
        "level_desc": Character.level.desc(),
    }
    join_query = join_query.options(joinedload(Character.user), joinedload(Character.template))
    join_query = join_query.order_by(char_sort_map.get(sort, Character.created_at.desc()))
    offset = (page - 1) * per_page
    result = await db.execute(join_query.offset(offset).limit(per_page))
    chars = result.unique().scalars().all()

    items = [
        AdminCharacterSummary(
            id=c.id,
            user_id=c.user_id,
            user_name=c.user.display_name if c.user else "???",
            template_name=c.template.name if c.template else "???",
            race=c.race,
            level=c.level,
            rarity=c.template.rarity if c.template else 1,
            hp=c.hp,
            atk=c.atk,
            def_=c.def_,
            spd=c.spd,
            created_at=c.created_at,
        )
        for c in chars
    ]
    return {"items": items, "total": total, "page": page, "per_page": per_page}


@router.patch("/characters/{character_id}")
async def update_character(character_id: str, req: AdminCharacterUpdate, admin: AdminUser, db: DbSession):
    """キャラクターステータス更新"""
    result = await db.execute(select(Character).where(Character.id == character_id))
    char = result.scalar_one_or_none()
    if not char:
        raise HTTPException(status_code=404, detail="Character not found")

    changes = {}
    for field, value in req.model_dump(exclude_unset=True).items():
        attr_name = "def_" if field == "def_" else field
        old_val = getattr(char, attr_name)
        setattr(char, attr_name, value)
        changes[field] = {"old": old_val, "new": value}

    await _audit_log(db, admin.id, "update_character", "character", character_id, changes)
    await db.flush()
    return {"message": "Updated", "changes": changes}


# ==================== アイテム ====================

@router.get("/items")
async def list_items(
    admin: AdminUser,
    db: DbSession,
    user_id: str = "",
    user_name: str = "",
    item_name: str = "",
    sort: str = "created_at_desc",
    page: int = Query(1, ge=1),
    per_page: int = Query(50, ge=1, le=200),
):
    """UserItem一覧（ページネーション付き）"""
    base_query = (
        select(UserItem)
        .join(ItemTemplate, UserItem.item_template_id == ItemTemplate.id)
        .join(User, UserItem.user_id == User.id)
    )
    count_query = (
        select(func.count(UserItem.id))
        .join(ItemTemplate, UserItem.item_template_id == ItemTemplate.id)
        .join(User, UserItem.user_id == User.id)
    )

    if user_id:
        cond = UserItem.user_id.contains(user_id)
        base_query = base_query.where(cond)
        count_query = count_query.where(cond)
    if user_name:
        cond = User.display_name.contains(user_name)
        base_query = base_query.where(cond)
        count_query = count_query.where(cond)
    if item_name:
        cond = ItemTemplate.name.contains(item_name)
        base_query = base_query.where(cond)
        count_query = count_query.where(cond)

    total_result = await db.execute(count_query)
    total = total_result.scalar()

    item_sort_map = {
        "created_at_asc": UserItem.created_at.asc(),
        "created_at_desc": UserItem.created_at.desc(),
        "name_asc": ItemTemplate.name.asc(),
        "name_desc": ItemTemplate.name.desc(),
        "rarity_asc": ItemTemplate.rarity.asc(),
        "rarity_desc": ItemTemplate.rarity.desc(),
        "quantity_asc": UserItem.quantity.asc(),
        "quantity_desc": UserItem.quantity.desc(),
    }
    base_query = base_query.options(joinedload(UserItem.item_template))
    base_query = base_query.order_by(item_sort_map.get(sort, UserItem.created_at.desc()))
    offset = (page - 1) * per_page
    result = await db.execute(base_query.offset(offset).limit(per_page))
    items = result.unique().scalars().all()

    # ユーザー名マップ
    uids = list({i.user_id for i in items})
    user_names = {}
    if uids:
        u_result = await db.execute(select(User.id, User.display_name).where(User.id.in_(uids)))
        user_names = dict(u_result.all())

    item_list = [
        AdminUserItemSummary(
            id=i.id,
            user_id=i.user_id,
            user_name=user_names.get(i.user_id, ""),
            item_name=i.item_template.name if i.item_template else "???",
            item_type=i.item_template.item_type if i.item_template else "",
            rarity=i.item_template.rarity if i.item_template else 1,
            quantity=i.quantity,
            created_at=i.created_at,
        )
        for i in items
    ]
    return {"items": item_list, "total": total, "page": page, "per_page": per_page}


@router.patch("/items/{item_id}")
async def update_item(item_id: int, req: AdminUserItemUpdate, admin: AdminUser, db: DbSession):
    """UserItem更新"""
    result = await db.execute(select(UserItem).where(UserItem.id == item_id))
    item = result.scalar_one_or_none()
    if not item:
        raise HTTPException(status_code=404, detail="Item not found")

    changes = {}
    if req.quantity is not None:
        changes["quantity"] = {"old": item.quantity, "new": req.quantity}
        item.quantity = req.quantity
    if req.user_id is not None:
        changes["user_id"] = {"old": item.user_id, "new": req.user_id}
        item.user_id = req.user_id

    await _audit_log(db, admin.id, "update_item", "user_item", str(item_id), changes)
    await db.flush()
    return {"message": "Updated", "changes": changes}


# ==================== 設定 ====================

@router.get("/settings", response_model=AdminSettingsResponse)
async def get_settings(admin: AdminUser, db: DbSession):
    """全設定取得"""
    all_settings = await get_all_settings(db)
    return AdminSettingsResponse(settings=all_settings)


@router.patch("/settings")
async def patch_settings(req: AdminSettingsUpdate, admin: AdminUser, db: DbSession):
    """設定更新"""
    updated = await update_settings(db, req.settings)
    await _audit_log(db, admin.id, "update_settings", "settings", None, req.settings)
    return {"message": "Updated", "settings": updated}


# ==================== NPC名前 ====================

@router.get("/npc-names", response_model=list[NpcNameResponse])
async def list_npc_names(admin: AdminUser, db: DbSession):
    """NPC名前一覧"""
    result = await db.execute(select(NpcName).order_by(NpcName.id))
    return [NpcNameResponse.model_validate(n) for n in result.scalars().all()]


@router.post("/npc-names", response_model=NpcNameResponse)
async def create_npc_name(req: NpcNameCreate, admin: AdminUser, db: DbSession):
    """NPC名前追加"""
    # 重複チェック
    result = await db.execute(select(NpcName).where(NpcName.name == req.name))
    existing = result.scalar_one_or_none()
    if existing:
        if not existing.is_active:
            existing.is_active = 1
            await db.flush()
            return NpcNameResponse.model_validate(existing)
        raise HTTPException(status_code=409, detail="Name already exists")

    npc_name = NpcName(name=req.name)
    db.add(npc_name)
    await db.flush()
    await _audit_log(db, admin.id, "create_npc_name", "npc_name", str(npc_name.id), {"name": req.name})
    return NpcNameResponse.model_validate(npc_name)


@router.delete("/npc-names/{name_id}")
async def deactivate_npc_name(name_id: int, admin: AdminUser, db: DbSession):
    """NPC名前を無効化"""
    result = await db.execute(select(NpcName).where(NpcName.id == name_id))
    npc_name = result.scalar_one_or_none()
    if not npc_name:
        raise HTTPException(status_code=404, detail="NPC name not found")

    npc_name.is_active = 0
    await _audit_log(db, admin.id, "deactivate_npc_name", "npc_name", str(name_id), {"name": npc_name.name})
    await db.flush()
    return {"message": "Deactivated"}


@router.post("/npc-names/{name_id}/activate")
async def activate_npc_name(name_id: int, admin: AdminUser, db: DbSession):
    """NPC名前を有効化"""
    result = await db.execute(select(NpcName).where(NpcName.id == name_id))
    npc_name = result.scalar_one_or_none()
    if not npc_name:
        raise HTTPException(status_code=404, detail="NPC name not found")

    npc_name.is_active = 1
    await _audit_log(db, admin.id, "activate_npc_name", "npc_name", str(name_id), {"name": npc_name.name})
    await db.flush()
    return {"message": "Activated"}


@router.delete("/npc-names/{name_id}/permanent")
async def delete_npc_name(name_id: int, admin: AdminUser, db: DbSession):
    """NPC名前を完全削除"""
    result = await db.execute(select(NpcName).where(NpcName.id == name_id))
    npc_name = result.scalar_one_or_none()
    if not npc_name:
        raise HTTPException(status_code=404, detail="NPC name not found")

    name = npc_name.name
    await db.delete(npc_name)
    await _audit_log(db, admin.id, "delete_npc_name", "npc_name", str(name_id), {"name": name})
    await db.flush()
    return {"message": "Deleted"}


# ==================== トーナメント ====================

@router.get("/tournaments", response_model=list[AdminTournamentSummary])
async def list_tournaments(
    admin: AdminUser,
    db: DbSession,
    status: str = "",
    page: int = Query(1, ge=1),
    per_page: int = Query(20, ge=1, le=100),
):
    """トーナメント一覧"""
    query = select(Tournament)
    if status:
        query = query.where(Tournament.status == status)
    query = query.order_by(Tournament.created_at.desc())

    offset = (page - 1) * per_page
    result = await db.execute(query.offset(offset).limit(per_page))
    tournaments = result.scalars().all()

    return [
        AdminTournamentSummary(
            id=t.id,
            name=t.name,
            status=t.status,
            max_participants=t.max_participants,
            current_participants=len(t.entries),
            current_round=t.current_round,
            reward_points=t.reward_points,
            created_at=t.created_at,
        )
        for t in tournaments
    ]


@router.delete("/tournaments/{tournament_id}")
async def delete_tournament(tournament_id: str, admin: AdminUser, db: DbSession):
    """トーナメント削除"""
    result = await db.execute(select(Tournament).where(Tournament.id == tournament_id))
    tournament = result.scalar_one_or_none()
    if not tournament:
        raise HTTPException(status_code=404, detail="Tournament not found")

    # バトルログ削除
    for battle in tournament.battles:
        await db.delete(battle)
    # エントリー削除
    for entry in tournament.entries:
        await db.delete(entry)
    await db.delete(tournament)

    await _audit_log(db, admin.id, "delete_tournament", "tournament", tournament_id)
    await db.flush()
    return {"message": "Deleted"}


@router.delete("/tournaments/{tournament_id}/entries/{entry_id}")
async def remove_entry(tournament_id: str, entry_id: int, admin: AdminUser, db: DbSession):
    """トーナメント参加者除外"""
    result = await db.execute(
        select(TournamentEntry).where(
            TournamentEntry.id == entry_id,
            TournamentEntry.tournament_id == tournament_id,
        )
    )
    entry = result.scalar_one_or_none()
    if not entry:
        raise HTTPException(status_code=404, detail="Entry not found")

    await db.delete(entry)
    await _audit_log(db, admin.id, "remove_entry", "tournament_entry", str(entry_id),
                     {"tournament_id": tournament_id})
    await db.flush()
    return {"message": "Removed"}


# ==================== ログ ====================

@router.get("/logs/ad-rewards", response_model=list[AdminAdRewardLog])
async def list_ad_rewards(
    admin: AdminUser,
    db: DbSession,
    user_id: str = "",
    page: int = Query(1, ge=1),
    per_page: int = Query(50, ge=1, le=200),
):
    """広告報酬ログ"""
    query = select(AdReward)
    if user_id:
        query = query.where(AdReward.user_id == user_id)
    query = query.order_by(AdReward.created_at.desc())

    offset = (page - 1) * per_page
    result = await db.execute(query.offset(offset).limit(per_page))
    rewards = result.scalars().all()

    # ユーザー名マップ
    uids = list({r.user_id for r in rewards})
    user_names = {}
    if uids:
        u_result = await db.execute(select(User.id, User.display_name).where(User.id.in_(uids)))
        user_names = dict(u_result.all())

    return [
        AdminAdRewardLog(
            id=r.id,
            user_id=r.user_id,
            user_name=user_names.get(r.user_id, ""),
            reward_type=r.reward_type,
            reward_amount=r.reward_amount,
            ad_type=r.ad_type,
            created_at=r.created_at,
        )
        for r in rewards
    ]


@router.get("/logs/purchases", response_model=list[AdminPurchaseLog])
async def list_purchases(
    admin: AdminUser,
    db: DbSession,
    user_id: str = "",
    page: int = Query(1, ge=1),
    per_page: int = Query(50, ge=1, le=200),
):
    """購入履歴ログ"""
    query = select(PurchaseHistory).join(ShopProduct, PurchaseHistory.product_id == ShopProduct.id)
    if user_id:
        query = query.where(PurchaseHistory.user_id == user_id)
    query = query.order_by(PurchaseHistory.created_at.desc())

    offset = (page - 1) * per_page
    result = await db.execute(query.offset(offset).limit(per_page))
    purchases = result.scalars().all()

    # ユーザー名・商品名マップ
    uids = list({p.user_id for p in purchases})
    user_names = {}
    if uids:
        u_result = await db.execute(select(User.id, User.display_name).where(User.id.in_(uids)))
        user_names = dict(u_result.all())

    pids = list({p.product_id for p in purchases})
    product_names = {}
    if pids:
        p_result = await db.execute(select(ShopProduct.id, ShopProduct.name).where(ShopProduct.id.in_(pids)))
        product_names = dict(p_result.all())

    return [
        AdminPurchaseLog(
            id=p.id,
            user_id=p.user_id,
            user_name=user_names.get(p.user_id, ""),
            product_name=product_names.get(p.product_id, ""),
            amount=p.amount,
            premium_currency_granted=p.premium_currency_granted,
            status=p.status,
            created_at=p.created_at,
        )
        for p in purchases
    ]


@router.get("/logs/audit", response_model=list[AdminAuditLogResponse])
async def list_audit_logs(
    admin: AdminUser,
    db: DbSession,
    page: int = Query(1, ge=1),
    per_page: int = Query(50, ge=1, le=200),
):
    """管理操作ログ"""
    query = select(AdminAuditLog).order_by(AdminAuditLog.created_at.desc())
    offset = (page - 1) * per_page
    result = await db.execute(query.offset(offset).limit(per_page))
    return [AdminAuditLogResponse.model_validate(log) for log in result.scalars().all()]


# ==================== ガチャ ====================

RARITY_NAMES = {1: "N", 2: "R", 3: "SR", 4: "SSR", 5: "UR"}


@router.get("/gacha/pools")
async def admin_gacha_pools(admin: AdminUser, db: DbSession):
    """ガチャプール一覧（全プール、アイテム・確率付き）"""
    result = await db.execute(select(GachaPool).order_by(GachaPool.id))
    pools = result.scalars().all()

    out = []
    for pool in pools:
        pool_data = {
            "id": pool.id,
            "name": pool.name,
            "description": pool.description,
            "pool_type": pool.pool_type,
            "cost_type": pool.cost_type,
            "cost_amount": pool.cost_amount,
            "is_active": pool.is_active,
            "pity_count": pool.pity_count,
            "items": [],
            "rates": {},
        }

        if pool.pool_type == "item":
            eq_result = await db.execute(
                select(GachaPoolItemEquip).where(GachaPoolItemEquip.pool_id == pool.id)
            )
            equip_items = eq_result.scalars().all()
            total_weight = sum(i.weight for i in equip_items)
            rarity_weights: dict[int, int] = {}
            for i in equip_items:
                tmpl = i.item_template
                pool_data["items"].append({
                    "id": i.id,
                    "template_id": i.item_template_id,
                    "name": tmpl.name if tmpl else "???",
                    "rarity": tmpl.rarity if tmpl else 1,
                    "weight": i.weight,
                })
                r = tmpl.rarity if tmpl else 1
                rarity_weights[r] = rarity_weights.get(r, 0) + i.weight
            for r, w in sorted(rarity_weights.items()):
                pool_data["rates"][RARITY_NAMES.get(r, str(r))] = round(w / total_weight * 100, 2) if total_weight else 0
        else:
            total_weight = sum(i.weight for i in pool.items)
            rarity_weights: dict[int, int] = {}
            for i in pool.items:
                tmpl = i.template
                pool_data["items"].append({
                    "id": i.id,
                    "template_id": i.template_id,
                    "name": tmpl.name if tmpl else "???",
                    "rarity": tmpl.rarity if tmpl else 1,
                    "weight": i.weight,
                })
                r = tmpl.rarity if tmpl else 1
                rarity_weights[r] = rarity_weights.get(r, 0) + i.weight
            for r, w in sorted(rarity_weights.items()):
                pool_data["rates"][RARITY_NAMES.get(r, str(r))] = round(w / total_weight * 100, 2) if total_weight else 0

        out.append(pool_data)
    return out


class GachaPoolUpdate(BaseModel):
    name: str | None = None
    description: str | None = None
    cost_amount: int | None = None
    is_active: int | None = None
    pity_count: int | None = None


@router.patch("/gacha/pools/{pool_id}")
async def admin_update_gacha_pool(pool_id: int, req: GachaPoolUpdate, admin: AdminUser, db: DbSession):
    """ガチャプール設定更新"""
    result = await db.execute(select(GachaPool).where(GachaPool.id == pool_id))
    pool = result.scalar_one_or_none()
    if not pool:
        raise HTTPException(status_code=404, detail="Pool not found")

    changes = {}
    for field, value in req.model_dump(exclude_unset=True).items():
        old_val = getattr(pool, field)
        setattr(pool, field, value)
        changes[field] = {"old": old_val, "new": value}

    await _audit_log(db, admin.id, "update_gacha_pool", "gacha_pool", str(pool_id), changes)
    await db.flush()
    return {"message": "Updated", "changes": changes}


class GachaWeightUpdate(BaseModel):
    weights: dict[int, int]  # {item_id: new_weight}


@router.patch("/gacha/pools/{pool_id}/weights")
async def admin_update_gacha_weights(pool_id: int, req: GachaWeightUpdate, admin: AdminUser, db: DbSession):
    """ガチャプール内アイテムの重み一括更新"""
    result = await db.execute(select(GachaPool).where(GachaPool.id == pool_id))
    pool = result.scalar_one_or_none()
    if not pool:
        raise HTTPException(status_code=404, detail="Pool not found")

    changes = {}
    if pool.pool_type == "item":
        for item_id, new_weight in req.weights.items():
            r = await db.execute(select(GachaPoolItemEquip).where(GachaPoolItemEquip.id == item_id, GachaPoolItemEquip.pool_id == pool_id))
            item = r.scalar_one_or_none()
            if item:
                changes[str(item_id)] = {"old": item.weight, "new": new_weight, "name": item.item_template.name if item.item_template else ""}
                item.weight = new_weight
    else:
        for item_id, new_weight in req.weights.items():
            r = await db.execute(select(GachaPoolItem).where(GachaPoolItem.id == item_id, GachaPoolItem.pool_id == pool_id))
            item = r.scalar_one_or_none()
            if item:
                changes[str(item_id)] = {"old": item.weight, "new": new_weight, "name": item.template.name if item.template else ""}
                item.weight = new_weight

    await _audit_log(db, admin.id, "update_gacha_weights", "gacha_pool", str(pool_id), changes)
    await db.flush()
    return {"message": "Updated", "changes": changes}
