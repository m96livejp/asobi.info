"""キャラクターAPI"""
import json
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from ..database import get_db
from ..deps import get_current_user, require_user
from ..models.user import User
from ..models.character import Character, CharacterLike, CharacterReport
from ..models.conversation import Conversation
from pydantic import BaseModel

router = APIRouter(prefix="/api/characters", tags=["characters"])


class CharacterCreate(BaseModel):
    name: str
    ai_model: str = "claude"
    is_public: int = 0
    avatar_url: str | None = None
    char_name: str | None = None
    char_age: str | None = None
    gender: str | None = None
    profile: str | None = None
    private_profile: str | None = None
    first_message: str | None = None
    genre_story: list[str] = []
    genre_char_type: list[str] = []
    genre_personality: list[str] = []
    genre_era: list[str] = []
    genre_base: list[str] = []
    keywords: list[str] = []
    voice_model: str | None = None
    tts_styles: list = []
    bgm_mode: str = "none"
    bgm_track_id: int | None = None
    sd_prompt: str | None = None
    sd_neg_prompt: str | None = None
    sd_seed: int | None = None
    sd_model: str | None = None


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
        "is_deleted": c.is_deleted,
        "review_status": c.review_status,
        "char_name": c.char_name,
        "char_age": c.char_age,
        "gender": c.gender,
        "profile": c.profile,
        "first_message": c.first_message,
        "genre_story": json.loads(c.genre_story or "[]"),
        "genre_char_type": json.loads(c.genre_char_type or "[]"),
        "genre_personality": json.loads(c.genre_personality or "[]"),
        "genre_era": json.loads(c.genre_era or "[]"),
        "genre_base": json.loads(c.genre_base or "[]"),
        "keywords": json.loads(c.keywords or "[]"),
        "is_recommended": c.is_recommended,
        "like_count": c.like_count,
        "use_count": c.use_count,
        "voice_model": c.voice_model,
        "tts_styles": json.loads(c.tts_styles or "[]") if c.tts_styles else [],
        "bgm_mode": c.bgm_mode or "none",
        "bgm_track_id": c.bgm_track_id,
        "sd_prompt": c.sd_prompt,
        "sd_neg_prompt": c.sd_neg_prompt,
        "sd_seed": c.sd_seed,
        "sd_model": c.sd_model,
    }
    if not hide_private:
        d["private_profile"] = c.private_profile
    return d


@router.get("/sample")
async def list_sample(db: AsyncSession = Depends(get_db)):
    """おすすめキャラクター一覧（is_recommended=1）"""
    result = await db.execute(
        select(Character).where(Character.is_recommended == 1, Character.is_deleted == 0, Character.is_public == 1).order_by(Character.id.desc())
    )
    return [char_to_dict(c, hide_private=True) for c in result.scalars()]


@router.get("/public")
async def list_public(db: AsyncSession = Depends(get_db)):
    result = await db.execute(
        select(Character).where(
            Character.is_public == 1,
            Character.is_deleted == 0,
            Character.review_status == "approved",
        ).order_by(Character.is_recommended.desc(), Character.like_count.desc(), Character.id.desc())
    )
    return [char_to_dict(c, hide_private=True) for c in result.scalars()]


@router.get("/liked")
async def list_liked(user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    result = await db.execute(
        select(Character)
        .join(CharacterLike, CharacterLike.character_id == Character.id)
        .where(CharacterLike.user_id == user.id, CharacterLike.status >= 1, Character.is_deleted == 0)
        .order_by(CharacterLike.id.desc())
    )
    return [char_to_dict(c, hide_private=True) for c in result.scalars()]


@router.get("/mine")
async def list_mine(user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    result = await db.execute(
        select(Character).where(Character.creator_id == user.id, Character.is_deleted == 0).order_by(Character.id.desc())
    )
    return [char_to_dict(c) for c in result.scalars()]


@router.get("/chatting-public")
async def list_chatting_public(user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    """会話中の公開キャラクター（自分が作成者でないもの）"""
    # キャラクターIDと最新の会話IDを取得
    conv_result = await db.execute(
        select(Conversation.character_id, Conversation.id).where(
            Conversation.user_id == user.id,
            Conversation.is_deleted == 0,
        ).order_by(Conversation.updated_at.desc())
    )
    char_conv_map: dict[int, int] = {}
    for char_id, conv_id in conv_result.all():
        if char_id not in char_conv_map:
            char_conv_map[char_id] = conv_id
    char_ids = list(char_conv_map.keys())
    if not char_ids:
        return []
    # 公開キャラかつ自分が作成者でないもの（削除済み含む: フロントで表示制御）
    result = await db.execute(
        select(Character).where(
            Character.id.in_(char_ids),
            Character.creator_id != user.id,
            Character.is_public == 1
        ).order_by(Character.name)
    )
    out = []
    for c in result.scalars():
        d = char_to_dict(c, hide_private=True)
        d["conv_id"] = char_conv_map.get(c.id)
        out.append(d)
    return out


@router.get("/{char_id}")
async def get_character(char_id: int, user: User | None = Depends(get_current_user), db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(Character).where(Character.id == char_id))
    c = result.scalar_one_or_none()
    if not c:
        raise HTTPException(status_code=404, detail="キャラクターが見つかりません")
    is_owner = user and user.id == c.creator_id
    is_admin = user and user.role == "admin"
    # 削除済みキャラクター: オーナー・管理者以外にも is_deleted 情報は返す（フロントで表示制御）
    if c.is_deleted >= 1 and not is_owner and not is_admin:
        # 最低限の情報のみ返す（フロント側で削除メッセージ表示用）
        return {
            "id": c.id, "name": c.name, "avatar_url": c.avatar_url,
            "is_deleted": c.is_deleted, "is_owner": False, "is_admin": False,
        }
    if not c.is_public and not is_owner and not is_admin:
        raise HTTPException(status_code=403, detail="非公開キャラクターです")
    d = char_to_dict(c, hide_private=not is_owner and not is_admin)
    d["is_owner"] = bool(is_owner)
    d["is_admin"] = bool(is_admin)
    # いいね状態
    d["liked"] = False
    if user:
        lr = await db.execute(
            select(CharacterLike).where(CharacterLike.user_id == user.id, CharacterLike.character_id == char_id)
        )
        like_row = lr.scalar_one_or_none()
        d["liked"] = bool(like_row and like_row.status >= 1)
    return d


@router.post("")
async def create_character(req: CharacterCreate, user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    c = Character(
        creator_id=user.id,
        name=req.name,
        avatar_url=req.avatar_url,
        ai_model=req.ai_model,
        is_public=req.is_public,
        char_name=req.char_name,
        char_age=req.char_age,
        gender=req.gender,
        profile=req.profile,
        private_profile=req.private_profile,
        first_message=req.first_message,
        genre_story=json.dumps(req.genre_story, ensure_ascii=False),
        genre_char_type=json.dumps(req.genre_char_type, ensure_ascii=False),
        genre_personality=json.dumps(req.genre_personality, ensure_ascii=False),
        genre_era=json.dumps(req.genre_era, ensure_ascii=False),
        genre_base=json.dumps(req.genre_base, ensure_ascii=False),
        keywords=json.dumps(req.keywords[:5], ensure_ascii=False),
        voice_model=req.voice_model,
        tts_styles=json.dumps(req.tts_styles, ensure_ascii=False) if req.tts_styles else None,
        bgm_mode=req.bgm_mode or "none",
        bgm_track_id=req.bgm_track_id,
        # SD設定は管理者のみ設定可能
        sd_prompt=(req.sd_prompt or None) if user.role == "admin" else None,
        sd_neg_prompt=(req.sd_neg_prompt or None) if user.role == "admin" else None,
        sd_seed=req.sd_seed if user.role == "admin" else None,
        sd_model=(req.sd_model or None) if user.role == "admin" else None,
    )
    db.add(c)
    await db.commit()
    await db.refresh(c)
    return char_to_dict(c)


@router.put("/{char_id}")
async def update_character(char_id: int, req: CharacterUpdate, user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(Character).where(Character.id == char_id))
    c = result.scalar_one_or_none()
    if not c:
        raise HTTPException(status_code=404, detail="キャラクターが見つかりません")
    if c.creator_id != user.id and user.role != "admin":
        raise HTTPException(status_code=403, detail="編集権限がありません")
    for field in ["name", "avatar_url", "ai_model", "is_public", "char_name", "char_age", "gender", "profile", "private_profile", "first_message", "voice_model"]:
        setattr(c, field, getattr(req, field))
    for field in ["genre_story", "genre_char_type", "genre_personality", "genre_era", "genre_base"]:
        setattr(c, field, json.dumps(getattr(req, field), ensure_ascii=False))
    c.keywords = json.dumps(req.keywords[:5], ensure_ascii=False)
    c.tts_styles = json.dumps(req.tts_styles, ensure_ascii=False) if req.tts_styles else None
    c.bgm_mode = req.bgm_mode or "none"
    c.bgm_track_id = req.bgm_track_id
    # SD設定は管理者のみ変更可能
    if user.role == "admin":
        c.sd_prompt = req.sd_prompt or None
        c.sd_neg_prompt = req.sd_neg_prompt or None
        c.sd_seed = req.sd_seed
        c.sd_model = req.sd_model or None
    await db.commit()
    await db.refresh(c)
    return char_to_dict(c)


@router.delete("/{char_id}")
async def delete_character(char_id: int, user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    """作成者によるキャラクター削除（ソフトデリート）"""
    result = await db.execute(select(Character).where(Character.id == char_id, Character.creator_id == user.id))
    c = result.scalar_one_or_none()
    if not c:
        raise HTTPException(status_code=404, detail="キャラクターが見つかりません")
    c.is_deleted = 1
    await db.commit()
    return {"ok": True}


@router.post("/{char_id}/like")
async def like_character(char_id: int, user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    result = await db.execute(select(CharacterLike).where(CharacterLike.user_id == user.id, CharacterLike.character_id == char_id))
    existing = result.scalar_one_or_none()
    if existing:
        if existing.status >= 1:
            # お気に入り中 → 解除（status=-1、like_countは減らさない）
            existing.status = -1
            await db.commit()
            return {"liked": False, "status": -1}
        else:
            # 解除済み → 再お気に入り（status=1、like_countは既に計上済みなので増やさない）
            existing.status = 1
            await db.commit()
            return {"liked": True, "status": 1}
    else:
        # 初めてのお気に入り → 新規レコード + like_count累計+1
        like = CharacterLike(user_id=user.id, character_id=char_id, status=1)
        db.add(like)
        char = (await db.execute(select(Character).where(Character.id == char_id))).scalar_one_or_none()
        if char:
            char.like_count += 1
        await db.commit()
        return {"liked": True, "status": 1}


class ReportRequest(BaseModel):
    reason: str


@router.post("/{char_id}/report")
async def report_character(char_id: int, req: ReportRequest, user: User = Depends(require_user), db: AsyncSession = Depends(get_db)):
    """キャラクター不正報告"""
    import logging
    # カテゴリと詳細を分離（改行区切り: 1行目=カテゴリ, 2行目以降=詳細）
    lines = req.reason.strip().split('\n', 1)
    category = lines[0] if lines else ""
    detail = lines[1].strip() if len(lines) > 1 else ""
    report = CharacterReport(
        character_id=char_id,
        user_id=user.id,
        category=category,
        reason=detail[:1000],
    )
    db.add(report)
    await db.commit()
    logging.getLogger("aic").warning(
        f"Character report: char_id={char_id}, user_id={user.id}, category={category}, reason={detail[:500]}"
    )
    return {"ok": True}
