"""aic.asobi.info - AIチャットサービス"""
from contextlib import asynccontextmanager
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from .config import get_settings
from .database import init_db
from .api import auth, characters, conversations, chat, balance, admin, generate, tts, bgm, scene

settings = get_settings()

@asynccontextmanager
async def lifespan(app: FastAPI):
    await init_db()
    # サンプルキャラクター初期化
    from .services.init_samples import init_sample_characters
    await init_sample_characters()
    # SD画像生成キューワーカー起動
    from .services.queue_worker import start_worker
    start_worker()
    yield

app = FastAPI(title="aic.asobi.info", lifespan=lifespan)

app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.CORS_ORIGINS,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

app.include_router(auth.router)
app.include_router(characters.router)
app.include_router(conversations.router)
app.include_router(chat.router)
app.include_router(balance.router)
app.include_router(admin.router)
app.include_router(generate.router)
app.include_router(tts.router)
app.include_router(bgm.router)
app.include_router(scene.router)

@app.get("/api/health")
async def health():
    return {"status": "ok", "service": "aic"}
