from __future__ import annotations

from datetime import datetime
from typing import Optional

from pydantic import BaseModel, field_validator


class OAuthUrlResponse(BaseModel):
    url: str


class EmailRegisterRequest(BaseModel):
    email: str
    password: str
    display_name: str | None = None

    @field_validator("email")
    @classmethod
    def validate_email(cls, v: str) -> str:
        if "@" not in v or "." not in v.split("@")[-1]:
            raise ValueError("有効なメールアドレスを入力してください")
        return v.lower().strip()

    @field_validator("password")
    @classmethod
    def validate_password(cls, v: str) -> str:
        if len(v) < 8:
            raise ValueError("パスワードは8文字以上で入力してください")
        return v


class EmailLoginRequest(BaseModel):
    email: str
    password: str


class SocialAccountInfo(BaseModel):
    provider: str
    email: str | None
    display_name: str | None
    username: str | None = None  # @username (Twitter等)
    created_at: datetime

    class Config:
        from_attributes = True


class LinkedAccountsResponse(BaseModel):
    has_device_id: bool
    has_asobi: bool = False
    has_email: bool
    email: str | None
    email_verified: bool = False
    social_accounts: list[SocialAccountInfo]
