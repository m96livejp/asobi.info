from fastapi import APIRouter, HTTPException, status
from sqlalchemy import select
from sqlalchemy.orm import joinedload

from app.deps import CurrentUser, DbSession
from app.models.character import Character
from app.models.battle import BattleLog
from app.schemas.battle import BattleResultResponse, BattleTurnAction
from app.services.battle_engine import run_battle

router = APIRouter()


@router.get("/{battle_id}", response_model=BattleResultResponse)
async def get_battle(battle_id: str, db: DbSession):
    """バトルログを取得（トーナメント表からの履歴再生用）"""
    result = await db.execute(
        select(BattleLog)
        .options(
            joinedload(BattleLog.attacker).joinedload(Character.user),
            joinedload(BattleLog.defender).joinedload(Character.user),
        )
        .where(BattleLog.id == battle_id)
    )
    log = result.scalar_one_or_none()
    if not log:
        raise HTTPException(status_code=404, detail="バトルが見つかりません")

    turns = [BattleTurnAction(**t) for t in log.battle_data.get("turns", [])]
    attacker_name = log.attacker.template.name if log.attacker and log.attacker.template else "???"
    defender_name = log.defender.template.name if log.defender and log.defender.template else "???"
    winner_name = attacker_name if log.winner_id == log.attacker_id else defender_name
    loser_name = defender_name if log.winner_id == log.attacker_id else attacker_name
    attacker_player = log.attacker.user.display_name if log.attacker and log.attacker.user else ""
    defender_player = log.defender.user.display_name if log.defender and log.defender.user else ""
    attacker_rarity = log.attacker.template.rarity if log.attacker and log.attacker.template else 1
    defender_rarity = log.defender.template.rarity if log.defender and log.defender.template else 1

    return BattleResultResponse(
        battle_id=log.id,
        winner_id=log.winner_id,
        winner_name=winner_name,
        loser_name=loser_name,
        turns=turns,
        total_turns=len(turns),
        attacker_player_name=attacker_player,
        defender_player_name=defender_player,
        attacker_rarity=attacker_rarity,
        defender_rarity=defender_rarity,
    )


@router.post("/practice", response_model=BattleResultResponse)
async def practice_battle(
    attacker_id: str,
    defender_id: str,
    user: CurrentUser,
    db: DbSession,
):
    """練習バトル: 自分のキャラ同士で戦わせる"""
    result = await db.execute(
        select(Character).where(Character.id == attacker_id, Character.user_id == user.id)
    )
    attacker = result.scalar_one_or_none()
    if not attacker:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Attacker not found")

    result = await db.execute(select(Character).where(Character.id == defender_id))
    defender = result.scalar_one_or_none()
    if not defender:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Defender not found")

    battle_result = run_battle(attacker, defender)

    # バトルログ保存
    log = BattleLog(
        id=battle_result.battle_id,
        attacker_id=attacker.id,
        defender_id=defender.id,
        winner_id=battle_result.winner_id,
        battle_data={"turns": [t.model_dump() for t in battle_result.turns]},
    )
    db.add(log)
    await db.flush()

    return battle_result
