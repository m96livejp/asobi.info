"""認証API"""
import jwt
import json
import uuid
from datetime import datetime, timedelta
from fastapi import APIRouter, Depends, HTTPException
from fastapi.responses import HTMLResponse
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from ..config import get_settings
from ..database import get_db
from ..deps import get_current_user, require_user
from ..models.user import User
from ..models.balance import UserBalance, BalanceTransaction
from pydantic import BaseModel

router = APIRouter(prefix="/api/auth", tags=["auth"])
settings = get_settings()


def create_token(user_id: int) -> str:
    payload = {
        "sub": str(user_id),
        "iat": datetime.utcnow(),
        "exp": datetime.utcnow() + timedelta(hours=settings.JWT_EXPIRATION_HOURS),
    }
    return jwt.encode(payload, settings.JWT_SECRET, algorithm=settings.JWT_ALGORITHM)


def user_dict(user: User) -> dict:
    return {
        "id": user.id,
        "display_name": user.display_name,
        "avatar_url": user.avatar_url,
        "role": user.role,
        "is_guest": user.asobi_user_id is None,
        "is_suspended": bool(user.is_suspended),
    }


class GuestRequest(BaseModel):
    device_id: str | None = None
    display_name: str = "ゲスト"


@router.post("/guest")
async def guest_login(req: GuestRequest, db: AsyncSession = Depends(get_db)):
    """ゲストログイン（device_idで識別）"""
    device_id = req.device_id or str(uuid.uuid4())

    result = await db.execute(select(User).where(User.device_id == device_id))
    user = result.scalar_one_or_none()

    if not user:
        user = User(device_id=device_id, display_name=req.display_name)
        db.add(user)
        await db.flush()
        # 残高レコードが既に存在する場合はスキップ（孤児レコード対策）
        existing_bal = await db.execute(select(UserBalance).where(UserBalance.user_id == user.id))
        if not existing_bal.scalar_one_or_none():
            balance = UserBalance(user_id=user.id, points=100, crystals=0)
            db.add(balance)
            tx = BalanceTransaction(user_id=user.id, currency="points", amount=100, type="signup", memo="初回登録ボーナス")
            db.add(tx)
        await db.commit()
        await db.refresh(user)

    token = create_token(user.id)
    return {"token": token, "user": user_dict(user)}


@router.get("/me")
async def get_me(user: User | None = Depends(get_current_user)):
    """現在のユーザー情報を取得"""
    if not user:
        raise HTTPException(status_code=401, detail="未認証")
    return user_dict(user)


class AsobiCallbackRequest(BaseModel):
    token: str


async def _process_asobi_token(token_str: str, db: AsyncSession) -> User:
    """asobi.info JWTを検証してユーザーを取得または作成する"""
    payload = jwt.decode(token_str, settings.JWT_SECRET, algorithms=[settings.JWT_ALGORITHM])
    if payload.get("purpose") != "aic_login":
        raise ValueError("Invalid token purpose")

    asobi_id = int(payload["sub"])
    name = payload.get("name", "ユーザー")
    avatar = payload.get("avatar") or ""
    role = payload.get("role", "user")  # asobi.info のロールを引き継ぐ

    result = await db.execute(select(User).where(User.asobi_user_id == asobi_id))
    user = result.scalar_one_or_none()

    if not user:
        user = User(asobi_user_id=asobi_id, display_name=name, avatar_url=avatar or None, role=role)
        db.add(user)
        await db.flush()
        # 残高レコードが既に存在する場合はスキップ（孤児レコード対策）
        existing_bal = await db.execute(select(UserBalance).where(UserBalance.user_id == user.id))
        if not existing_bal.scalar_one_or_none():
            balance = UserBalance(user_id=user.id, points=100, crystals=0)
            db.add(balance)
            tx = BalanceTransaction(user_id=user.id, currency="points", amount=100, type="signup", memo="初回登録ボーナス")
            db.add(tx)
    else:
        user.display_name = name
        user.avatar_url = avatar or None
        user.role = role  # ログインのたびに asobi.info のロールを同期

    await db.commit()
    await db.refresh(user)
    return user


@router.post("/asobi/callback")
async def asobi_callback(req: AsobiCallbackRequest, db: AsyncSession = Depends(get_db)):
    """asobi.infoからのJWTコールバック（API用）"""
    try:
        user = await _process_asobi_token(req.token, db)
        token = create_token(user.id)
        return {"token": token, "user": user_dict(user)}
    except (jwt.InvalidTokenError, ValueError):
        raise HTTPException(status_code=401, detail="Invalid token")


@router.get("/asobi/callback")
async def asobi_callback_redirect(token: str, db: AsyncSession = Depends(get_db)):
    """asobi.infoからのGETリダイレクトコールバック（ブラウザ用）"""
    try:
        user = await _process_asobi_token(token, db)
        new_token = create_token(user.id)
        token_json = json.dumps(new_token)
        return HTMLResponse(content=f"""<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>ログイン中...</title></head>
<body><script>
localStorage.setItem('aic_token', {token_json});
document.cookie = 'aic_token=' + encodeURIComponent({token_json}) + ';path=/;max-age=2592000;SameSite=Lax;Secure';
window.location.replace('/');
</script></body></html>""")
    except (jwt.InvalidTokenError, ValueError, Exception):
        return HTMLResponse(content="""<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta http-equiv="refresh" content="1;url=/"></head>
<body style="display:flex;align-items:center;justify-content:center;height:100vh;background:#1a1a2e;color:#ccc;font-family:system-ui,sans-serif">
<div style="text-align:center"><p style="margin-bottom:12px">ログイン処理中...</p>
<p style="font-size:0.85rem;color:#888">自動的に移動します</p></div>
<script>window.location.replace('/');</script>
</body></html>""")


class ProfileUpdate(BaseModel):
    display_name: str | None = None


@router.put("/profile")
async def update_profile(req: ProfileUpdate, user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    """ユーザープロフィール更新"""
    if req.display_name is not None:
        name = req.display_name.strip()
        if not name or len(name) > 30:
            raise HTTPException(status_code=400, detail="名前は1〜30文字で入力してください")
        user.display_name = name
    await db.commit()
    await db.refresh(user)
    return user_dict(user)
