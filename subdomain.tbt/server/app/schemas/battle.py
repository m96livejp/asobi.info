from datetime import datetime

from pydantic import BaseModel


class BattleTurnAction(BaseModel):
    turn: int
    actor: str  # character name
    actor_id: str
    action: str  # "attack" or "skill"
    skill_name: str = ""
    damage: int
    target_hp_remaining: int
    actor_hp_remaining: int


class BattleResultResponse(BaseModel):
    battle_id: str
    winner_id: str
    winner_name: str
    loser_name: str
    turns: list[BattleTurnAction]
    total_turns: int
    attacker_player_name: str = ""
    defender_player_name: str = ""
    attacker_rarity: int = 1
    defender_rarity: int = 1


class TournamentResponse(BaseModel):
    id: str
    name: str
    status: str
    max_participants: int
    current_participants: int = 0
    current_round: int
    entry_cost_type: str
    entry_cost_amount: int
    reward_points: int
    created_at: datetime
    my_character_name: str | None = None

    class Config:
        from_attributes = True


class TournamentEntryRequest(BaseModel):
    character_id: str


class TournamentBracketEntry(BaseModel):
    seed: int
    user_name: str
    character_name: str
    character_id: str
    eliminated_round: int | None = None
    rarity: int = 1


class TournamentBracketBattle(BaseModel):
    battle_id: str
    round: int
    attacker_name: str
    defender_name: str
    winner_name: str
    attacker_player_name: str = ""
    defender_player_name: str = ""
    attacker_rarity: int = 1
    defender_rarity: int = 1


class TournamentBracketResponse(BaseModel):
    tournament_id: str
    tournament_name: str
    status: str
    current_round: int
    max_participants: int
    entries: list[TournamentBracketEntry]
    battles: list[TournamentBracketBattle]
