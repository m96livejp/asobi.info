-- ユーザー投稿の料理結果テーブル
CREATE TABLE IF NOT EXISTS iv_reports (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    pokemon_id  INTEGER NOT NULL,   -- pokedex_no
    pot_type    TEXT    NOT NULL,   -- 鉄/銅/銀/金
    quality     TEXT    NOT NULL,   -- ふつう/いい/すごくいい/スペシャル
    level       INTEGER NOT NULL DEFAULT 100,
    hp          INTEGER NOT NULL,
    atk         INTEGER NOT NULL,
    user_id     INTEGER,            -- asobiアカウントID（ログイン時）
    username    TEXT,               -- 表示名（ログイン時）
    ip          TEXT,               -- IPアドレス（常に記録）
    memo        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (pokemon_id) REFERENCES pokemon(pokedex_no)
);
