"""DB接続管理"""
from sqlalchemy.ext.asyncio import create_async_engine, AsyncSession, async_sessionmaker
from sqlalchemy.orm import DeclarativeBase
from .config import get_settings

settings = get_settings()
engine = create_async_engine(settings.DATABASE_URL, echo=False)
async_session = async_sessionmaker(engine, class_=AsyncSession, expire_on_commit=False)

class Base(DeclarativeBase):
    pass

async def get_db():
    async with async_session() as session:
        yield session

async def init_db():
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
        # カラム追加マイグレーション（SQLiteはADD COLUMNのみ対応）
        migrations = [
            "ALTER TABLE characters ADD COLUMN is_recommended INTEGER DEFAULT 0",
            "ALTER TABLE ai_settings ADD COLUMN tts_voice_params TEXT DEFAULT NULL",
            "ALTER TABLE characters ADD COLUMN bgm_mode TEXT DEFAULT 'none'",
            "ALTER TABLE characters ADD COLUMN bgm_track_id INTEGER DEFAULT NULL",
            "ALTER TABLE bgm_tracks ADD COLUMN genre TEXT DEFAULT ''",
            "ALTER TABLE bgm_tracks ADD COLUMN enabled INTEGER DEFAULT 0",
        ]
        for sql in migrations:
            try:
                await conn.execute(__import__("sqlalchemy").text(sql))
            except Exception:
                pass  # カラムが既に存在する場合は無視
