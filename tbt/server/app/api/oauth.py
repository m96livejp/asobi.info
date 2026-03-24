"""OAuth認証エンドポイント"""
from datetime import datetime, timedelta, timezone
from typing import Optional
from urllib.parse import urlencode

import jwt
from fastapi import APIRouter, HTTPException, Query, status
from fastapi.responses import RedirectResponse
from pydantic import BaseModel
from sqlalchemy import select

from app.config import settings
from app.deps import DbSession, CurrentUser
from app.models.user import User
from app.models.social_account import SocialAccount
from app.schemas.auth import OAuthUrlResponse
from app.services.auth_service import create_access_token
from app.services import oauth_service

router = APIRouter()

VALID_PROVIDERS = {"google", "line", "twitter"}


def _create_state_token(mode: str, user_id: Optional[str] = None, code_verifier: Optional[str] = None) -> str:
    """stateパラメータをJWTとしてエンコード（5分有効）"""
    payload = {
        "mode": mode,
        "exp": datetime.now(timezone.utc) + timedelta(minutes=5),
    }
    if user_id:
        payload["user_id"] = user_id
    if code_verifier:
        payload["code_verifier"] = code_verifier
    return jwt.encode(payload, settings.JWT_SECRET_KEY, algorithm=settings.JWT_ALGORITHM)


def _decode_state_token(state: str) -> dict:
    """stateパラメータをデコード"""
    try:
        return jwt.decode(state, settings.JWT_SECRET_KEY, algorithms=[settings.JWT_ALGORITHM])
    except jwt.ExpiredSignatureError:
        raise HTTPException(status_code=400, detail="認証セッションが期限切れです。もう一度やり直してください。")
    except jwt.InvalidTokenError:
        raise HTTPException(status_code=400, detail="無効な認証セッションです。")


@router.get("/oauth/{provider}/url", response_model=OAuthUrlResponse)
async def get_oauth_url(
    provider: str,
    mode: str = Query("login", regex="^(login)$"),
):
    """OAuth認証URL生成（ログインモード、認証不要）"""
    if provider not in VALID_PROVIDERS:
        raise HTTPException(status_code=400, detail=f"未対応のプロバイダー: {provider}")

    code_verifier = None
    code_challenge = None
    if provider == "twitter":
        code_verifier, code_challenge = oauth_service.generate_pkce()

    state = _create_state_token(mode="login", code_verifier=code_verifier)
    url = oauth_service.get_auth_url(provider, state, code_challenge)

    return OAuthUrlResponse(url=url)


@router.get("/oauth/{provider}/url/link", response_model=OAuthUrlResponse)
async def get_oauth_url_link(
    provider: str,
    user: CurrentUser,
):
    """OAuth認証URL生成（リンクモード、認証必須）"""
    if provider not in VALID_PROVIDERS:
        raise HTTPException(status_code=400, detail=f"未対応のプロバイダー: {provider}")

    code_verifier = None
    code_challenge = None
    if provider == "twitter":
        code_verifier, code_challenge = oauth_service.generate_pkce()

    state = _create_state_token(mode="link", user_id=user.id, code_verifier=code_verifier)
    url = oauth_service.get_auth_url(provider, state, code_challenge)

    return OAuthUrlResponse(url=url)


@router.get("/callback/{provider}")
async def oauth_callback(
    provider: str,
    db: DbSession,
    code: str = Query(...),
    state: str = Query(...),
):
    """OAuthコールバック処理"""
    if provider not in VALID_PROVIDERS:
        return _redirect_error("未対応のプロバイダーです")

    try:
        state_data = _decode_state_token(state)
    except HTTPException as e:
        return _redirect_error(e.detail)
    mode = state_data.get("mode", "login")
    code_verifier = state_data.get("code_verifier")

    # プロバイダーからユーザー情報取得
    try:
        user_info = await oauth_service.exchange_code(provider, code, code_verifier)
    except Exception as e:
        return _redirect_error(f"認証に失敗しました: {str(e)}")

    try:
        if mode == "link":
            # アカウントリンクモード
            user_id = state_data.get("user_id")
            if not user_id:
                return _redirect_error("リンク情報が不正です")

            # 既に他のユーザーにリンクされていないかチェック
            result = await db.execute(
                select(SocialAccount).where(
                    SocialAccount.provider == provider,
                    SocialAccount.provider_id == user_info.provider_id,
                )
            )
            existing = result.scalar_one_or_none()
            if existing:
                if existing.user_id == user_id:
                    return _redirect_error("既にリンク済みです")
                return _redirect_error("このアカウントは別のユーザーにリンクされています")

            # リンク作成
            social = SocialAccount(
                user_id=user_id,
                provider=provider,
                provider_id=user_info.provider_id,
                email=user_info.email,
                display_name=user_info.display_name,
                username=user_info.username,
            )
            db.add(social)
            await db.flush()

            token = create_access_token(user_id)
            return _redirect_success(token, user_id)

        else:
            # ログインモード
            # 既存のSocialAccountを検索
            result = await db.execute(
                select(SocialAccount).where(
                    SocialAccount.provider == provider,
                    SocialAccount.provider_id == user_info.provider_id,
                )
            )
            existing = result.scalar_one_or_none()

            if existing:
                # 既存ユーザーでログイン
                token = create_access_token(existing.user_id)
                return _redirect_success(token, existing.user_id)

            # 未連携 → 確認画面へリダイレクト
            confirm_token = _create_confirm_token(
                provider=provider,
                provider_id=user_info.provider_id,
                email=user_info.email,
                display_name=user_info.display_name,
                username=user_info.username,
            )
            return _redirect_confirm(provider, user_info.display_name or "", confirm_token)

    except Exception as e:
        return _redirect_error(f"アカウント処理に失敗しました: {str(e)}")


def _create_confirm_token(provider: str, provider_id: str, email: Optional[str], display_name: Optional[str], username: Optional[str] = None) -> str:
    """新規作成確認用トークン（10分有効）"""
    payload = {
        "type": "oauth_confirm",
        "provider": provider,
        "provider_id": provider_id,
        "email": email,
        "display_name": display_name,
        "username": username,
        "exp": datetime.now(timezone.utc) + timedelta(minutes=10),
    }
    return jwt.encode(payload, settings.JWT_SECRET_KEY, algorithm=settings.JWT_ALGORITHM)


class OAuthConfirmRequest(BaseModel):
    confirm_token: str


@router.post("/oauth/confirm")
async def oauth_confirm(req: OAuthConfirmRequest, db: DbSession):
    """OAuth新規アカウント作成確認"""
    try:
        data = jwt.decode(req.confirm_token, settings.JWT_SECRET_KEY, algorithms=[settings.JWT_ALGORITHM])
    except jwt.ExpiredSignatureError:
        raise HTTPException(status_code=400, detail="確認の有効期限が切れました。もう一度ログインしてください。")
    except jwt.InvalidTokenError:
        raise HTTPException(status_code=400, detail="無効なトークンです。")

    if data.get("type") != "oauth_confirm":
        raise HTTPException(status_code=400, detail="無効なトークンです。")

    provider = data["provider"]
    provider_id = data["provider_id"]

    # 既に作成済みでないか再チェック
    result = await db.execute(
        select(SocialAccount).where(
            SocialAccount.provider == provider,
            SocialAccount.provider_id == provider_id,
        )
    )
    if result.scalar_one_or_none():
        raise HTTPException(status_code=409, detail="既にアカウントが作成されています。ログインしてください。")

    # 新規ユーザー作成
    display_name = data.get("display_name") or f"プレイヤー{__import__('random').randint(1000, 9999)}"
    user = User(display_name=display_name)
    db.add(user)
    await db.flush()

    social = SocialAccount(
        user_id=user.id,
        provider=provider,
        provider_id=provider_id,
        email=data.get("email"),
        display_name=data.get("display_name"),
        username=data.get("username"),
    )
    db.add(social)
    await db.flush()

    token = create_access_token(user.id)
    return {"access_token": token, "user_id": user.id}


def _redirect_success(token: str, user_id: str) -> RedirectResponse:
    params = urlencode({"token": token, "user_id": user_id})
    return RedirectResponse(url=f"{settings.FRONTEND_URL}/auth-callback.html?{params}")


def _redirect_confirm(provider: str, display_name: str, confirm_token: str) -> RedirectResponse:
    params = urlencode({"confirm": "1", "provider": provider, "name": display_name, "confirm_token": confirm_token})
    return RedirectResponse(url=f"{settings.FRONTEND_URL}/auth-callback.html?{params}")


def _redirect_error(message: str) -> RedirectResponse:
    params = urlencode({"error": message})
    return RedirectResponse(url=f"{settings.FRONTEND_URL}/auth-callback.html?{params}")
