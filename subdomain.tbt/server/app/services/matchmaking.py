import random

from app.models.battle import TournamentEntry


def assign_seeds(entries: list[TournamentEntry]) -> list[TournamentEntry]:
    """エントリーにシード番号をランダムに割り当て"""
    random.shuffle(entries)
    for i, entry in enumerate(entries):
        entry.seed = i + 1
    return entries


def get_round_matchups(entries: list[TournamentEntry], round_num: int) -> list[tuple[TournamentEntry, TournamentEntry]]:
    """指定ラウンドの対戦カードを生成。敗退していないエントリーのみ"""
    active = sorted(
        [e for e in entries if e.eliminated_round is None and e.character is not None],
        key=lambda e: e.seed or 0,
    )

    matchups = []
    for i in range(0, len(active) - 1, 2):
        matchups.append((active[i], active[i + 1]))

    return matchups
