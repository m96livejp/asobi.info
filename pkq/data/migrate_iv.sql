-- 個体値観測データテーブル
CREATE TABLE IF NOT EXISTS iv_observations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    pokemon_id  INTEGER NOT NULL,   -- pokedex_no
    pot_type    TEXT    NOT NULL,   -- 鉄/銅/銀/金
    hp          INTEGER NOT NULL,   -- Lv.100 実測HP
    atk         INTEGER NOT NULL,   -- Lv.100 実測ATK
    memo        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (pokemon_id) REFERENCES pokemon(pokedex_no)
);
