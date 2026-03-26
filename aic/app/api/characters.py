"""キャラクターAPI"""
import json
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, or_
from ..database import get_db
from ..deps import get_current_user, require_user
from ..models.user import User
from ..models.character import Character, CharacterLike
from pydantic import BaseModel

router = APIRouter(prefix="/api/characters", tags=["characters"])


class CharacterCreate(BaseModel):
    name: str
    ai_model: str = "claude"
    is_public: int = 0
    char_name: str | None = None
    char_age: str | None = None
    profile: str | None = None
    private_profile: str | None = None
    first_message: str | None = None
    genre_story: list[str] = []
    genre_char_type: list[str] = []
    genre_personality: list[str] = []
    genre_era: list[str] = []
    genre_base: list[str] = []
    keywords: list[str] = []


class CharacterUpdate(CharacterCreate):
    pass


def char_to_dict(c: Character, hide_private: bool = False) -> dict:
    d = {
        "id": c.id,
        "creator_id": c.creator_id,
        "name": c.name,
        "avatar_url": c.avatar_url,
        "ai_model": c.ai_model,
        "is_public": c.is_public,
        "is_sample": c.is_sample,
        "char_name": c.char_name,
        "char_age": c.char_age,
        "profile": c.profile,
        "first_message": c.first_message,
        "genre_story": json.loads(c.genre_story or "[]"),
        "genre_char_type": json.loads(c.genre_char_type or "[]"),
        "genre_personality": json.loads(c.genre_personality or "[]"),
        "genre_era": json.loads(c.genre_era or "[]"),
        "genre_base": json.loads(c.genre_base or "[]"),
        "keywords": json.loads(c.keywords or "[]"),
        "like_count": c.like_count,
        "use_count": c.use_count,
    }
    if not hide_private:
        d["private_profile"] = c.private_profile
    return d


@router.get("/sample")
async def list_sample(db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(Character).where(Character.is_sample == 1).order_by(Character.id))
    return [char_to_dict(c, hide_private=True) for c in result.scalars()]


@router.get("/public")
async def list_public(db: AsyncSession = Depends(get_db)):
    result = await db.execute(
        select(Character).where(Character.is_public == 1).order_by(Character.like_count.desc(), Character.id.desc())
    )
    return [char_to_dict(c, hide_private=True) for c in result.scalars()]


@router.get("/liked")
async def list_liked(user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    result = await db.execute(
        select(Character)
        .join(CharacterLike, CharacterLike.character_id == Character.id)
        .where(CharacterLike.user_id == user.id)
        .order_by(CharacterLike.id.desc())
    )
    return [char_to_dict(c, hide_private=True) for c in result.scalars()]


@router.get("/mine")
async def list_mine(user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    result = await db.execute(
        select(Character).where(Character.creator_id == user.id).order_by(Character.id.desc())
    )
    return [char_to_dict(c) for c in result.scalars()]


@router.get("/{char_id}")
async def get_character(char_id: int, user: User | None = Depends(get_current_user), db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(Character).where(Character.id == char_id))
    c = result.scalar_one_or_none()
    if not c:
        raise HTTPException(status_code=404, detail="キャラクターが見つかりません")
    is_owner = user and user.id == c.creator_id
    if not c.is_public and not c.is_sample and not is_owner:
        raise HTTPException(status_code=403, detail="非公開キャラクターです")
    return char_to_dict(c, hide_private=not is_owner)


@router.post("")
async def create_character(req: CharacterCreate, user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    c = Character(
        creator_id=user.id,
        name=req.name,
        ai_model=req.ai_model,
        is_public=req.is_public,
        char_name=req.char_name,
        char_age=req.char_age,
        profile=req.profile,
        private_profile=req.private_profile,
        first_message=req.first_message,
        genre_story=json.dumps(req.genre_story, ensure_ascii=False),
        genre_char_type=json.dumps(req.genre_char_type, ensure_ascii=False),
        genre_personality=json.dumps(req.genre_personality, ensure_ascii=False),
        genre_era=json.dumps(req.genre_era, ensure_ascii=False),
        genre_base=json.dumps(req.genre_base, ensure_ascii=False),
        keywords=json.dumps(req.keywords[:5], ensure_ascii=False),
    )
    db.add(c)
    await db.commit()
    await db.refresh(c)
    return char_to_dict(c)


@router.put("/{char_id}")
async def update_character(char_id: int, req: CharacterUpdate, user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(Character).where(Character.id == char_id, Character.creator_id == user.id))
    c = result.scalar_one_or_none()
    if not c:
        raise HTTPException(status_code=404, detail="キャラクターが見つかりません")
    for field in ["name", "ai_model", "is_public", "char_name", "char_age", "profile", "private_profile", "first_message"]:
        setattr(c, field, getattr(req, field))
    for field in ["genre_story", "genre_char_type", "genre_personality", "genre_era", "genre_base"]:
        setattr(c, field, json.dumps(getattr(req, field), ensure_ascii=False))
    c.keywords = json.dumps(req.keywords[:5], ensure_ascii=False)
    await db.commit()
    await db.refresh(c)
    return char_to_dict(c)


@router.post("/{char_id}/like")
async def like_character(char_id: int, user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(CharacterLike).where(CharacterLike.user_id == user.id, CharacterLike.character_id == char_id))
    existing = result.scalar_one_or_none()
    if existing:
        await db.delete(existing)
        await db.execute(select(Character).where(Character.id == char_id))
        char = (await db.execute(select(Character).where(Character.id == char_id))).scalar_one_or_none()
        if char:
            char.like_count = max(0, char.like_count - 1)
        await db.commit()
        return {"liked": False}
    else:
        like = CharacterLike(user_id=user.id, character_id=char_id)
        db.add(like)
        char = (await db.execute(select(Character).where(Character.id == char_id))).scalar_one_or_none()
        if char:
            char.like_count += 1
        await db.commit()
        return {"liked": True}
