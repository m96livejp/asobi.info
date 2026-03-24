import json

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models.admin import AppSetting

DEFAULTS: dict[str, object] = {
    "initial_points": 500,
    "initial_premium_currency": 0,
    "initial_normal_tickets": 10,
    "initial_premium_tickets": 1,
    "initial_item_gacha_tickets": 3,
    "initial_premium_item_gacha_tickets": 1,
    "auto_tournament_interval_seconds": 300,
    "auto_tournament_distribution": [3, 2, 1],
    "tournament_auto_create_4": 3,
    "tournament_auto_create_8": 0,
    "tournament_max_concurrent": 10,
    "tournament_npc_join_min_humans": 1,
    "tournament_round_points": [50, 100, 200],
    "tournament_champion_points": 500,
    "tournament_second_points": 200,
    "tournament_champion_chests": 3,
    "tournament_second_chests": 1,
}


async def get_all_settings(db: AsyncSession) -> dict[str, object]:
    """全設定をデフォルト値とDB値をマージして返す"""
    result = await db.execute(select(AppSetting))
    db_settings = {row.key: json.loads(row.value_json) for row in result.scalars().all()}
    merged = {**DEFAULTS, **db_settings}
    return merged


async def get_setting(db: AsyncSession, key: str) -> object:
    """指定キーの設定値を取得（DB優先、なければデフォルト）"""
    result = await db.execute(select(AppSetting).where(AppSetting.key == key))
    row = result.scalar_one_or_none()
    if row:
        return json.loads(row.value_json)
    return DEFAULTS.get(key)


async def update_settings(db: AsyncSession, updates: dict[str, object]) -> dict[str, object]:
    """設定を更新（upsert）"""
    for key, value in updates.items():
        result = await db.execute(select(AppSetting).where(AppSetting.key == key))
        existing = result.scalar_one_or_none()
        if existing:
            existing.value_json = json.dumps(value, ensure_ascii=False)
        else:
            db.add(AppSetting(key=key, value_json=json.dumps(value, ensure_ascii=False)))
    await db.flush()
    return await get_all_settings(db)
