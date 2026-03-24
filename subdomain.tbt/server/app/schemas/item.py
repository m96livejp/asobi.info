from datetime import datetime

from pydantic import BaseModel


class ItemTemplateResponse(BaseModel):
    id: int
    name: str
    item_type: str
    rarity: int
    description: str
    equip_slot: str
    equip_race: str
    bonus_hp: int
    bonus_atk: int
    bonus_def: int
    bonus_spd: int
    effect_name: str
    effect_value: float

    class Config:
        from_attributes = True


class UserItemResponse(BaseModel):
    id: int
    item_template_id: int
    name: str
    item_type: str
    rarity: int
    description: str
    quantity: int
    equip_slot: str = ""   # weapon_1h, weapon_2h, shield, head, body, hands, feet, accessory
    equip_race: str = "all"
    bonus_hp: int = 0
    bonus_atk: int = 0
    bonus_def: int = 0
    bonus_spd: int = 0
    effect_name: str = ""
    effect_value: float = 0.0

    class Config:
        from_attributes = True


class UseItemResponse(BaseModel):
    message: str
    reward_type: str = ""  # pt, equipment, ticket
    reward_value: int = 0
    reward_item_name: str = ""


class EquipRequest(BaseModel):
    slot: str  # weapon1, weapon2, head, body, hands, feet, accessory1-3
    item_template_id: int


class UnequipRequest(BaseModel):
    slot: str


class EquipmentSlotResponse(BaseModel):
    slot: str
    item_template_id: int
    name: str
    rarity: int
    bonus_hp: int
    bonus_atk: int
    bonus_def: int
    bonus_spd: int
    effect_name: str
    effect_value: float

    class Config:
        from_attributes = True


class CharacterEquipmentResponse(BaseModel):
    slots: list[EquipmentSlotResponse]
    total_bonus_hp: int = 0
    total_bonus_atk: int = 0
    total_bonus_def: int = 0
    total_bonus_spd: int = 0
