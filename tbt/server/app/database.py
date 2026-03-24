from sqlalchemy.ext.asyncio import AsyncSession, async_sessionmaker, create_async_engine
from sqlalchemy.orm import DeclarativeBase

from app.config import settings

engine = create_async_engine(
    settings.DATABASE_URL,
    echo=settings.DEBUG,
    connect_args={"timeout": 30},
)
async_session = async_sessionmaker(engine, class_=AsyncSession, expire_on_commit=False)


class Base(DeclarativeBase):
    pass


async def get_db():
    async with async_session() as session:
        try:
            yield session
            await session.commit()
        except Exception:
            await session.rollback()
            raise


async def init_db():
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
        # 既存テーブルに新カラムを追加（ALTER TABLE、既に存在する場合はスキップ）
        await conn.run_sync(_migrate_columns)


def _migrate_columns(conn):
    """既存テーブルにカラムを追加するマイグレーション"""
    import sqlalchemy as sa
    inspector = sa.inspect(conn)

    # users テーブルに email, password_hash カラムを追加
    if inspector.has_table("users"):
        existing = {col["name"] for col in inspector.get_columns("users")}
        if "email" not in existing:
            conn.execute(sa.text("ALTER TABLE users ADD COLUMN email VARCHAR(255)"))
        if "password_hash" not in existing:
            conn.execute(sa.text("ALTER TABLE users ADD COLUMN password_hash VARCHAR(255)"))

    # users テーブルに email_verified カラムを追加
    if inspector.has_table("users"):
        existing = {col["name"] for col in inspector.get_columns("users")}
        if "email_verified" not in existing:
            conn.execute(sa.text("ALTER TABLE users ADD COLUMN email_verified INTEGER DEFAULT 0"))

    # characters テーブルに is_favorite カラムを追加
    if inspector.has_table("characters"):
        existing = {col["name"] for col in inspector.get_columns("characters")}
        if "is_favorite" not in existing:
            conn.execute(sa.text("ALTER TABLE characters ADD COLUMN is_favorite BOOLEAN DEFAULT 0"))

    # tournament_entries テーブルに created_at カラムを追加
    if inspector.has_table("tournament_entries"):
        existing = {col["name"] for col in inspector.get_columns("tournament_entries")}
        if "created_at" not in existing:
            conn.execute(sa.text("ALTER TABLE tournament_entries ADD COLUMN created_at DATETIME"))

    # users テーブルに管理者カラムを追加
    if inspector.has_table("users"):
        existing = {col["name"] for col in inspector.get_columns("users")}
        if "is_admin" not in existing:
            conn.execute(sa.text("ALTER TABLE users ADD COLUMN is_admin INTEGER DEFAULT 0"))
        if "admin_memo" not in existing:
            conn.execute(sa.text("ALTER TABLE users ADD COLUMN admin_memo TEXT DEFAULT ''"))
        if "last_login_at" not in existing:
            conn.execute(sa.text("ALTER TABLE users ADD COLUMN last_login_at DATETIME"))

    # users テーブルに装備ガチャチケットカラムを追加
    if inspector.has_table("users"):
        existing = {col["name"] for col in inspector.get_columns("users")}
        if "item_gacha_tickets" not in existing:
            conn.execute(sa.text("ALTER TABLE users ADD COLUMN item_gacha_tickets INTEGER DEFAULT 3"))

    # users テーブルに asobi.info 連携IDカラムを追加
    if inspector.has_table("users"):
        existing = {col["name"] for col in inspector.get_columns("users")}
        if "asobi_user_id" not in existing:
            conn.execute(sa.text("ALTER TABLE users ADD COLUMN asobi_user_id INTEGER"))
