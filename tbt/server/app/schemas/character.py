from datetime import datetime

from pydantic import BaseModel


class CharacterTemplateResponse(BaseModel):
    id: int
    name: str
    rarity: int
    base_hp: int
    base_atk: int
    base_def: int
    base_spd: int
    skill_name: str
    skill_description: str
    skill_power: float
    image_url: str

    class Config:
        from_attributes = True


class CharacterResponse(BaseModel):
    id: str
    template_id: int
    template_name: str = ""
    template_rarity: int = 0
    race: str = "warrior"
    level: int
    exp: int
    is_favorite: bool = False
    hp: int
    atk: int
    def_: int
    spd: int
    skill_name: str = ""
    skill_power: float = 1.0
    created_at: datetime

    class Config:
        from_attributes = True


class FavoriteResponse(BaseModel):
    character_id: str
    is_favorite: bool


class TrainRequest(BaseModel):
    soldier_id: str  # 特訓に使うキャラクターID（消える）


class TrainResponse(BaseModel):
    character_id: str
    leveled_up: bool
    old_level: int
    new_level: int
    exp: int
    exp_gained: int  # 今回の特訓で得たEXP
    hp: int
    atk: int
    def_: int
    spd: int
    returned_items: list[str] = []  # 戻ってきた装備品名
