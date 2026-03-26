"""aic.asobi.info 設定"""
from pydantic_settings import BaseSettings
from functools import lru_cache

class Settings(BaseSettings):
    # DB
    DATABASE_URL: str = "sqlite+aiosqlite:///./data/aic.sqlite"

    # JWT
    JWT_SECRET: str = "fc8a8081db151abd74523baa6d1630c7478f6cef9145428a895c3269568c5920"
    JWT_ALGORITHM: str = "HS256"
    JWT_EXPIRATION_HOURS: int = 720  # 30日

    # AI API Keys
    ANTHROPIC_API_KEY: str = ""
    OPENAI_API_KEY: str = ""
    GOOGLE_API_KEY: str = ""

    # AI Models
    CLAUDE_MODEL: str = "claude-sonnet-4-20250514"
    OPENAI_MODEL: str = "gpt-4o-mini"
    GEMINI_MODEL: str = "gemini-1.5-flash"

    # Chat cost (points per message)
    COST_CLAUDE: int = 3
    COST_CHATGPT: int = 2
    COST_GEMINI: int = 1

    # asobi.info
    ASOBI_LOGIN_URL: str = "https://asobi.info/oauth/aic-login.php"
    ASOBI_CALLBACK_URL: str = "https://aic.asobi.info/api/auth/asobi/callback"
    FRONTEND_URL: str = "https://aic.asobi.info"

    # CORS
    CORS_ORIGINS: list = ["https://aic.asobi.info", "https://asobi.info"]

    class Config:
        env_file = ".env"

@lru_cache()
def get_settings():
    return Settings()
