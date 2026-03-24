"""OAuth プロバイダーサービス (Google, LINE, X/Twitter)"""
from __future__ import annotations

import hashlib
import base64
import secrets
from dataclasses import dataclass
from urllib.parse import urlencode

import httpx

from app.config import settings


@dataclass
class OAuthUserInfo:
    provider_id: str
    email: str | None
    display_name: str | None
    username: str | None = None  # @username (Twitter等)


def generate_pkce() -> tuple[str, str]:
    """PKCE code_verifier と code_challenge を生成"""
    code_verifier = secrets.token_urlsafe(64)[:128]
    digest = hashlib.sha256(code_verifier.encode()).digest()
    code_challenge = base64.urlsafe_b64encode(digest).rstrip(b"=").decode()
    return code_verifier, code_challenge


# --- Google OAuth 2.0 ---

def google_auth_url(state: str) -> str:
    params = {
        "client_id": settings.GOOGLE_CLIENT_ID,
        "redirect_uri": settings.GOOGLE_REDIRECT_URI,
        "response_type": "code",
        "scope": "openid email profile",
        "state": state,
        "access_type": "offline",
        "prompt": "select_account",
    }
    return "https://accounts.google.com/o/oauth2/v2/auth?" + urlencode(params)


async def google_exchange(code: str) -> OAuthUserInfo:
    async with httpx.AsyncClient() as client:
        # code → token
        token_resp = await client.post(
            "https://oauth2.googleapis.com/token",
            data={
                "code": code,
                "client_id": settings.GOOGLE_CLIENT_ID,
                "client_secret": settings.GOOGLE_CLIENT_SECRET,
                "redirect_uri": settings.GOOGLE_REDIRECT_URI,
                "grant_type": "authorization_code",
            },
        )
        token_resp.raise_for_status()
        access_token = token_resp.json()["access_token"]

        # token → userinfo
        user_resp = await client.get(
            "https://www.googleapis.com/oauth2/v2/userinfo",
            headers={"Authorization": f"Bearer {access_token}"},
        )
        user_resp.raise_for_status()
        info = user_resp.json()

    return OAuthUserInfo(
        provider_id=info["id"],
        email=info.get("email"),
        display_name=info.get("name"),
    )


# --- LINE Login (OpenID Connect) ---

def line_auth_url(state: str) -> str:
    params = {
        "response_type": "code",
        "client_id": settings.LINE_CHANNEL_ID,
        "redirect_uri": settings.LINE_REDIRECT_URI,
        "state": state,
        "scope": "profile openid email",
    }
    return "https://access.line.me/oauth2/v2.1/authorize?" + urlencode(params)


async def line_exchange(code: str) -> OAuthUserInfo:
    async with httpx.AsyncClient() as client:
        token_resp = await client.post(
            "https://api.line.me/oauth2/v2.1/token",
            data={
                "grant_type": "authorization_code",
                "code": code,
                "redirect_uri": settings.LINE_REDIRECT_URI,
                "client_id": settings.LINE_CHANNEL_ID,
                "client_secret": settings.LINE_CHANNEL_SECRET,
            },
        )
        if token_resp.status_code != 200:
            raise Exception(f"LINE token error: {token_resp.status_code} {token_resp.text}")

        tokens = token_resp.json()
        access_token = tokens["access_token"]

        # profile
        profile_resp = await client.get(
            "https://api.line.me/v2/profile",
            headers={"Authorization": f"Bearer {access_token}"},
        )
        profile_resp.raise_for_status()
        profile = profile_resp.json()

        # email from id_token verify
        email = None
        if "id_token" in tokens:
            verify_resp = await client.post(
                "https://api.line.me/oauth2/v2.1/verify",
                data={
                    "id_token": tokens["id_token"],
                    "client_id": settings.LINE_CHANNEL_ID,
                },
            )
            if verify_resp.status_code == 200:
                email = verify_resp.json().get("email")

    return OAuthUserInfo(
        provider_id=profile["userId"],
        email=email,
        display_name=profile.get("displayName"),
    )


# --- X/Twitter OAuth 2.0 + PKCE ---

def twitter_auth_url(state: str, code_challenge: str) -> str:
    params = {
        "response_type": "code",
        "client_id": settings.TWITTER_CLIENT_ID,
        "redirect_uri": settings.TWITTER_REDIRECT_URI,
        "scope": "users.read tweet.read offline.access",
        "state": state,
        "code_challenge": code_challenge,
        "code_challenge_method": "S256",
    }
    return "https://twitter.com/i/oauth2/authorize?" + urlencode(params)


async def twitter_exchange(code: str, code_verifier: str) -> OAuthUserInfo:
    async with httpx.AsyncClient() as client:
        token_resp = await client.post(
            "https://api.twitter.com/2/oauth2/token",
            auth=(settings.TWITTER_CLIENT_ID, settings.TWITTER_CLIENT_SECRET),
            data={
                "code": code,
                "grant_type": "authorization_code",
                "redirect_uri": settings.TWITTER_REDIRECT_URI,
                "code_verifier": code_verifier,
            },
        )
        token_resp.raise_for_status()
        access_token = token_resp.json()["access_token"]

        user_resp = await client.get(
            "https://api.twitter.com/2/users/me",
            params={"user.fields": "id,name,username"},
            headers={"Authorization": f"Bearer {access_token}"},
        )
        user_resp.raise_for_status()
        data = user_resp.json()["data"]

    return OAuthUserInfo(
        provider_id=data["id"],
        email=None,  # Twitter API v2 doesn't provide email easily
        display_name=data.get("name"),
        username=data.get("username"),  # @username
    )


# --- Provider Dispatch ---

PROVIDERS = {"google", "line", "twitter"}


def get_auth_url(provider: str, state: str, code_challenge: str | None = None) -> str:
    if provider == "google":
        return google_auth_url(state)
    elif provider == "line":
        return line_auth_url(state)
    elif provider == "twitter":
        if code_challenge is None:
            raise ValueError("Twitter requires code_challenge for PKCE")
        return twitter_auth_url(state, code_challenge)
    raise ValueError(f"Unknown provider: {provider}")


async def exchange_code(
    provider: str, code: str, code_verifier: str | None = None
) -> OAuthUserInfo:
    if provider == "google":
        return await google_exchange(code)
    elif provider == "line":
        return await line_exchange(code)
    elif provider == "twitter":
        if code_verifier is None:
            raise ValueError("Twitter requires code_verifier for PKCE")
        return await twitter_exchange(code, code_verifier)
    raise ValueError(f"Unknown provider: {provider}")
