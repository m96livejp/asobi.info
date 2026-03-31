import logging
import random
import uuid as uuid_module
from datetime import datetime, timezone

import jwt as pyjwt
from fastapi import APIRouter, HTTPException, Query, status
from fastapi.responses import RedirectResponse
from passlib.context import CryptContext
from sqlalchemy import select

from app.config import settings
from app.deps import DbSession, CurrentUser, OptionalUser
from app.models.user import User
from app.models.social_account import SocialAccount
from app.schemas.user import GuestRegisterRequest, LoginRequest, TokenResponse
from app.schemas.auth import (
    LinkedAccountsResponse, SocialAccountInfo,
)
from app.services.auth_service import create_access_token

logger = logging.getLogger(__name__)
router = APIRouter()
pwd_context = CryptContext(schemes=["bcrypt"], deprecated="auto")


# --- ゲスト認証（既存） ---

@router.post("/guest", response_model=TokenResponse)
async def guest_register(req: GuestRegisterRequest, db: DbSession):
    """ゲスト登録: device_idで新規ユーザーを作成"""
    result = await db.execute(select(User).where(User.device_id == req.device_id))
    existing = result.scalar_one_or_none()
    if existing:
        raise HTTPException(status_code=status.HTTP_409_CONFLICT, detail="Device already registered")

    user = User(device_id=req.device_id, display_name=req.display_name)
    db.add(user)
    await db.flush()

    token = create_access_token(user.id)
    return TokenResponse(access_token=token, user_id=user.id)


@router.post("/login", response_model=TokenResponse)
async def login(req: LoginRequest, db: DbSession):
    """ログイン: device_idで既存ユーザーを検索しJWT返却"""
    from datetime import datetime as dt
    result = await db.execute(select(User).where(User.device_id == req.device_id))
    user = result.scalar_one_or_none()
    if user is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="User not found")

    user.last_login_at = dt.now()
    token = create_access_token(user.id)
    return TokenResponse(access_token=token, user_id=user.id)


# --- asobi.info クロスサイトログイン ---

@router.get("/asobi/url")
async def asobi_login_url(link_token: str = Query(default="")):
    """asobi.info ログインページへのリダイレクトURLを返す。
    link_token が指定された場合は state として tbt-login.php に渡し、callback 経由で戻す。
    """
    import urllib.parse
    url = f"{settings.ASOBI_LOGIN_URL}?callback={urllib.parse.quote(settings.ASOBI_CALLBACK_URL, safe='')}"
    if link_token:
        url += f"&state={urllib.parse.quote(link_token, safe='')}"
    return {"url": url}


@router.get("/asobi/callback")
async def asobi_callback(token: str, db: DbSession, state: str = Query(default="")):
    """asobi.info が発行した短期JWTを受け取り、tbt JWTを発行してフロントへリダイレクト。
    state に既存 tbt JWT が含まれる場合は新規ユーザー作成せず既存ユーザーに asobi_user_id を紐付ける。
    """
    if not settings.ASOBI_JWT_SECRET:
        raise HTTPException(status_code=500, detail="ASOBI_JWT_SECRET not configured")

    try:
        payload = pyjwt.decode(token, settings.ASOBI_JWT_SECRET, algorithms=["HS256"])
    except Exception:
        raise HTTPException(status_code=400, detail="Invalid or expired token")

    if payload.get("purpose") != "tbt_login":
        raise HTTPException(status_code=400, detail="Invalid token purpose")

    asobi_id = int(payload["sub"])
    email = payload.get("email")

    # 連携モード：state に現在ログイン中の tbt JWT が含まれる場合
    if state:
        try:
            state_payload = pyjwt.decode(state, settings.JWT_SECRET_KEY, algorithms=[settings.JWT_ALGORITHM])
            existing_user_id = state_payload.get("sub")
            result = await db.execute(select(User).where(User.id == existing_user_id))
            existing_user = result.scalar_one_or_none()
        except Exception:
            existing_user = None

        if existing_user and not existing_user.asobi_user_id:
            # このasobi_idが既に別のtbtアカウントに紐づいていないか確認する
            # （異なるブラウザユーザーがゲストアカウントを乗っ取るのを防ぐ）
            result = await db.execute(select(User).where(User.asobi_user_id == asobi_id))
            already_linked = result.scalar_one_or_none()
            if already_linked:
                # 既存のtbtアカウントへリダイレクト（ゲストアカウントには連携しない）
                already_linked.last_login_at = datetime.now(timezone.utc)
                await db.flush()
                tbt_token = create_access_token(already_linked.id)
                redirect_url = f"{settings.FRONTEND_URL}/auth-callback.html?token={tbt_token}&user_id={already_linked.id}"
                return RedirectResponse(redirect_url)
            existing_user.asobi_user_id = asobi_id
            existing_user.last_login_at = datetime.now(timezone.utc)
            await db.flush()
            tbt_token = create_access_token(existing_user.id)
            redirect_url = f"{settings.FRONTEND_URL}/auth-callback.html?token={tbt_token}&user_id={existing_user.id}&linked=1"
            return RedirectResponse(redirect_url)

    # ログインモード：asobi_user_id / email でユーザー検索 or 新規作成

    # 1. asobi_user_id で既存ユーザー検索
    result = await db.execute(select(User).where(User.asobi_user_id == asobi_id))
    user = result.scalar_one_or_none()

    # 2. なければ email（verified のみ）で検索し紐付け
    if not user and email:
        result = await db.execute(
            select(User).where(User.email == email, User.email_verified == 1)
        )
        user = result.scalar_one_or_none()
        if user:
            user.asobi_user_id = asobi_id

    # 3. それでもなければ新規ユーザー作成
    if not user:
        user = User(
            id=str(uuid_module.uuid4()),
            asobi_user_id=asobi_id,
            display_name=payload.get("name") or f"冒険者{random.randint(1000,9999)}",
        )
        db.add(user)
        await db.flush()

    user.last_login_at = datetime.now(timezone.utc)
    await db.flush()

    tbt_token = create_access_token(user.id)
    redirect_url = f"{settings.FRONTEND_URL}/auth-callback.html?token={tbt_token}&user_id={user.id}"
    return RedirectResponse(redirect_url)


# --- アカウント管理 ---

@router.get("/me/accounts", response_model=LinkedAccountsResponse)
async def get_linked_accounts(user: CurrentUser, db: DbSession):
    """リンク済みアカウント一覧"""
    result = await db.execute(
        select(SocialAccount).where(SocialAccount.user_id == user.id)
    )
    socials = result.scalars().all()

    return LinkedAccountsResponse(
        has_device_id=user.device_id is not None,
        has_asobi=user.asobi_user_id is not None,
        has_email=user.email is not None,
        email=user.email,
        email_verified=bool(user.email_verified),
        social_accounts=[
            SocialAccountInfo(
                provider=s.provider,
                email=s.email,
                display_name=s.display_name,
                username=s.username,
                created_at=s.created_at,
            )
            for s in socials
        ],
    )


@router.delete("/me/accounts/{provider}")
async def unlink_account(provider: str, user: CurrentUser, db: DbSession):
    """ソーシャルアカウント解除（最後の認証手段ならゲストに戻す）"""
    if provider == "asobi":
        # asobi.info 連携解除
        if user.asobi_user_id is None:
            raise HTTPException(status_code=404, detail="asobiアカウントは連携されていません")
        user.asobi_user_id = None
        await db.flush()
    elif provider == "email":
        # メール連携解除
        if user.email is None:
            raise HTTPException(status_code=404, detail="メールは連携されていません")
        user.email = None
        user.password_hash = None
        await db.flush()
    else:
        result = await db.execute(
            select(SocialAccount).where(
                SocialAccount.user_id == user.id,
                SocialAccount.provider == provider,
            )
        )
        social = result.scalar_one_or_none()
        if social is None:
            raise HTTPException(status_code=404, detail="リンクされていません")
        await db.delete(social)
        await db.flush()

    # 他に認証手段が残っているかチェック
    other_socials = await db.execute(
        select(SocialAccount).where(SocialAccount.user_id == user.id)
    )
    has_social = other_socials.scalars().first() is not None
    has_email = user.email is not None and user.password_hash is not None
    has_device = user.device_id is not None
    has_asobi = user.asobi_user_id is not None

    # 認証手段がなくなったらdevice_idを割り当ててゲストに戻す
    if not has_social and not has_email and not has_device and not has_asobi:
        device_id = f"device_{__import__('time').time_ns()}_{random.randint(1000,9999)}"
        user.device_id = device_id
        await db.flush()
        return {"message": f"{provider}の連携を解除しました（ゲストアカウントに戻りました）", "device_id": device_id}

    return {"message": f"{provider}の連携を解除しました"}


@router.delete("/me")
async def delete_account(user: CurrentUser, db: DbSession):
    """アカウント削除（関連データ全削除＋ユーザー削除）"""
    from sqlalchemy import delete as sa_delete
    from app.models.character import Character, CharacterEquipment
    from app.models.gacha import GachaHistory
    from app.models.battle import TournamentEntry, BattleLog
    from app.models.item import UserItem
    from app.models.shop import PurchaseHistory
    from app.models.user import AdReward

    user_id = user.id

    # キャラクターに紐づく装備を削除
    char_ids_result = await db.execute(
        select(Character.id).where(Character.user_id == user_id)
    )
    char_ids = [row[0] for row in char_ids_result.all()]
    if char_ids:
        # バトルログのattacker/defender参照はキャラ削除で壊れるためNULL化せずログは残す
        await db.execute(
            sa_delete(CharacterEquipment).where(CharacterEquipment.character_id.in_(char_ids))
        )
        # トーナメントエントリー削除
        await db.execute(
            sa_delete(TournamentEntry).where(TournamentEntry.character_id.in_(char_ids))
        )

    # ユーザー直接参照のデータを削除
    await db.execute(sa_delete(Character).where(Character.user_id == user_id))
    await db.execute(sa_delete(UserItem).where(UserItem.user_id == user_id))
    await db.execute(sa_delete(GachaHistory).where(GachaHistory.user_id == user_id))
    await db.execute(sa_delete(AdReward).where(AdReward.user_id == user_id))
    await db.execute(sa_delete(PurchaseHistory).where(PurchaseHistory.user_id == user_id))
    await db.execute(sa_delete(SocialAccount).where(SocialAccount.user_id == user_id))
    # user_idで参照されるトーナメントエントリー（キャラ経由で消えなかったもの）
    await db.execute(sa_delete(TournamentEntry).where(TournamentEntry.user_id == user_id))

    # ユーザー削除
    await db.delete(user)
    await db.flush()
    return {"message": "アカウントを削除しました"}
