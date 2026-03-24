from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    APP_NAME: str = "Tournament Battle API"
    DEBUG: bool = True

    # Database
    DATABASE_URL: str = "sqlite+aiosqlite:///./tournament.db"

    # JWT
    JWT_SECRET_KEY: str = "change-this-secret-key-in-production"
    JWT_ALGORITHM: str = "HS256"
    JWT_EXPIRATION_HOURS: int = 720  # 30 days

    # OAuth - Google
    GOOGLE_CLIENT_ID: str = ""
    GOOGLE_CLIENT_SECRET: str = ""
    GOOGLE_REDIRECT_URI: str = "https://tbt.asobi.info/api/auth/callback/google"

    # OAuth - LINE
    LINE_CHANNEL_ID: str = ""
    LINE_CHANNEL_SECRET: str = ""
    LINE_REDIRECT_URI: str = "https://tbt.asobi.info/api/auth/callback/line"

    # OAuth - X (Twitter)
    TWITTER_CLIENT_ID: str = ""
    TWITTER_CLIENT_SECRET: str = ""
    TWITTER_REDIRECT_URI: str = "https://tbt.asobi.info/api/auth/callback/twitter"

    # Frontend URL
    FRONTEND_URL: str = "https://tbt.asobi.info"

    # asobi.info クロスサイトログイン
    ASOBI_JWT_SECRET: str = ""  # tbt_config.php の TBT_SHARED_SECRET と同じ値を .env で設定
    ASOBI_LOGIN_URL: str = "https://asobi.info/oauth/tbt-login.php"
    ASOBI_CALLBACK_URL: str = "https://tbt.asobi.info/api/auth/asobi/callback"

    # SMTP (メール認証)
    SMTP_HOST: str = "sv6112.wpx.ne.jp"
    SMTP_PORT: int = 587
    SMTP_USER: str = "web@asobi.info"
    SMTP_PASSWORD: str = ""
    SMTP_FROM: str = "noreply@asobi.info"
    SMTP_FROM_NAME: str = "Tournament Battle"

    # CORS
    CORS_ORIGINS: list[str] = ["*"]

    # Game settings
    MAX_STAMINA: int = 100
    STAMINA_RECOVERY_MINUTES: int = 5  # 1 stamina per 5 min
    AD_REWARD_DAILY_LIMIT: int = 40
    AD_REWARD_HOURLY_LIMIT: int = 5
    AD_REWARD_POINTS: int = 50

    class Config:
        env_file = ".env"


settings = Settings()
