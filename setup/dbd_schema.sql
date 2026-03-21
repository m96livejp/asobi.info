-- DbD データベーススキーマ
CREATE TABLE IF NOT EXISTS killers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    name_en TEXT NOT NULL,
    real_name TEXT,
    chapter TEXT,
    power_name TEXT,
    power_description TEXT,
    speed REAL DEFAULT 4.6,
    terror_radius INTEGER DEFAULT 32,
    height TEXT DEFAULT 'average',
    difficulty TEXT DEFAULT 'intermediate',
    released_at TEXT
);

CREATE TABLE IF NOT EXISTS survivors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    name_en TEXT NOT NULL,
    chapter TEXT,
    released_at TEXT
);

CREATE TABLE IF NOT EXISTS perks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    name_en TEXT,
    role TEXT NOT NULL CHECK(role IN ('killer', 'survivor')),
    character_name TEXT,
    description TEXT,
    teachable_level INTEGER
);

CREATE TABLE IF NOT EXISTS addons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    killer_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    name_en TEXT,
    rarity TEXT CHECK(rarity IN ('common','uncommon','rare','very_rare','ultra_rare','event')),
    description TEXT,
    FOREIGN KEY (killer_id) REFERENCES killers(id)
);

CREATE INDEX IF NOT EXISTS idx_perks_role ON perks(role);
CREATE INDEX IF NOT EXISTS idx_perks_character ON perks(character_name);
CREATE INDEX IF NOT EXISTS idx_addons_killer ON addons(killer_id);
