"""デイリーポイント回復サービス（バックグラウンドタスク）"""
import asyncio
import logging
import os
from datetime import date, datetime

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from ..database import async_session
from ..models.balance import UserBalance, BalanceTransaction
from ..models.settings import AiSettings

logger = logging.getLogger("aic")

_LAST_RUN_FILE = "/opt/asobi/aic/data/daily_recovery_last.txt"
_CHECK_INTERVAL = 3600  # 1時間ごとにチェック
_recovery_task: asyncio.Task | None = None


def _read_last_run_date() -> str:
    try:
        with open(_LAST_RUN_FILE, "r") as f:
            return f.read().strip()
    except Exception:
        return ""


def _write_last_run_date(d: str):
    try:
        os.makedirs(os.path.dirname(_LAST_RUN_FILE), exist_ok=True)
        with open(_LAST_RUN_FILE, "w") as f:
            f.write(d)
    except Exception as e:
        logger.error(f"[daily_recovery] last run write error: {e}")


async def _get_ai_settings(db: AsyncSession) -> AiSettings | None:
    result = await db.execute(select(AiSettings).where(AiSettings.id == 1))
    return result.scalar_one_or_none()


async def run_recovery(db: AsyncSession) -> dict:
    """デイリーポイント回復を実行して結果を返す"""
    cfg = await _get_ai_settings(db)
    if not cfg or not getattr(cfg, "daily_point_recovery_enabled", 0):
        return {"skipped": True, "reason": "disabled"}

    threshold = getattr(cfg, "daily_point_recovery_threshold", 100) or 100

    result = await db.execute(
        select(UserBalance).where(UserBalance.points < threshold)
    )
    balances = result.scalars().all()

    recovered = 0
    for bal in balances:
        old_pts = bal.points
        bal.points = threshold
        tx = BalanceTransaction(
            user_id=bal.user_id,
            currency="points",
            amount=threshold - old_pts,
            type="daily_recovery",
            memo=f"デイリー回復: {old_pts}→{threshold}pt",
        )
        db.add(tx)
        recovered += 1

    await db.commit()
    today = date.today().isoformat()
    _write_last_run_date(today)
    logger.info(f"[daily_recovery] ran: {recovered} users recovered to {threshold}pt")
    return {"recovered": recovered, "threshold": threshold, "date": today}


async def _recovery_loop():
    """毎時チェックし、今日まだ回復していなければ実行"""
    while True:
        try:
            today = date.today().isoformat()
            last = _read_last_run_date()
            if last != today:
                async with async_session() as db:
                    result = await run_recovery(db)
                    if not result.get("skipped"):
                        logger.info(f"[daily_recovery] {result}")
        except Exception as e:
            logger.error(f"[daily_recovery] error: {e}")
        await asyncio.sleep(_CHECK_INTERVAL)


def start_recovery_worker():
    """バックグラウンドワーカーを起動（FastAPI lifespan から呼ぶ）"""
    global _recovery_task
    _recovery_task = asyncio.create_task(_recovery_loop())
    logger.info("[daily_recovery] worker started")
