-- ポケモンクエスト データベーススキーマ
CREATE TABLE IF NOT EXISTS pokemon (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pokedex_no INTEGER NOT NULL UNIQUE,
    name TEXT NOT NULL,
    type1 TEXT NOT NULL,
    type2 TEXT,
    base_hp INTEGER,
    base_atk INTEGER,
    evolution_from INTEGER,
    evolve_level INTEGER,
    FOREIGN KEY (evolution_from) REFERENCES pokemon(pokedex_no)
);

CREATE TABLE IF NOT EXISTS recipes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    recipe_no INTEGER,
    name TEXT NOT NULL,
    description TEXT,
    quality TEXT CHECK(quality IN ('normal','good','very_good','special')),
    cooking_time INTEGER DEFAULT 3,
    hint TEXT
);

CREATE TABLE IF NOT EXISTS ingredients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    color TEXT,
    type TEXT,
    softness TEXT CHECK(softness IN ('hard','soft')),
    size TEXT CHECK(size IN ('small','big')),
    rarity TEXT CHECK(rarity IN ('common','rare'))
);

CREATE TABLE IF NOT EXISTS recipe_requirements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    recipe_id INTEGER NOT NULL,
    description TEXT,
    FOREIGN KEY (recipe_id) REFERENCES recipes(id)
);

CREATE TABLE IF NOT EXISTS recipe_pokemon (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    recipe_id INTEGER NOT NULL,
    pokemon_id INTEGER NOT NULL,
    rate REAL,
    FOREIGN KEY (recipe_id) REFERENCES recipes(id),
    FOREIGN KEY (pokemon_id) REFERENCES pokemon(pokedex_no)
);

CREATE INDEX IF NOT EXISTS idx_pokemon_type ON pokemon(type1);
CREATE INDEX IF NOT EXISTS idx_recipe_pokemon_recipe ON recipe_pokemon(recipe_id);
CREATE INDEX IF NOT EXISTS idx_recipe_pokemon_pokemon ON recipe_pokemon(pokemon_id);
