import asyncio
from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app.config import settings
from app.database import init_db, async_session
from app.api import auth, users, characters, gacha, battles, tournaments, rewards, shop, items, admin

DEFAULT_TOURNAMENT_INTERVAL = 3 * 60  # デフォルト3分


async def _tournament_scheduler():
    """定期的に募集中トーナメントにNPCを自動参加させるバックグラウンドタスク"""
    # workers>1 環境での重複実行を防ぐためランダムオフセット
    import random as _random
    await asyncio.sleep(15 + _random.uniform(0, 10))
    while True:
        try:
            async with async_session() as db:
                created = await tournaments.auto_create_tournaments(db)
                if created:
                    print(f"[scheduler] 新規トーナメント {created} 件作成")
            async with async_session() as db:
                executed = await tournaments.auto_fill_and_run_tournament(db)
                if executed:
                    print("[scheduler] NPC自動参加でトーナメントを実行しました")
            # 埋めた後に再度補充（埋め後のゼロ状態を防ぐ）
            async with async_session() as db:
                created2 = await tournaments.auto_create_tournaments(db)
                if created2:
                    print(f"[scheduler] 補充: 新規トーナメント {created2} 件作成")
            # DB設定からインターバルを取得
            async with async_session() as db:
                from app.services.settings_service import get_setting
                interval_str = await get_setting(db, "auto_tournament_interval_seconds")
                interval = int(interval_str) if interval_str else DEFAULT_TOURNAMENT_INTERVAL
        except Exception as e:
            print(f"[scheduler] エラー: {e}")
            interval = DEFAULT_TOURNAMENT_INTERVAL
        await asyncio.sleep(interval)


@asynccontextmanager
async def lifespan(app: FastAPI):
    await init_db()
    await seed_master_data()
    await seed_npc_names()
    task = asyncio.create_task(_tournament_scheduler())
    yield
    if task:
        task.cancel()
        try:
            await task
        except asyncio.CancelledError:
            pass


app = FastAPI(title=settings.APP_NAME, lifespan=lifespan)

app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.CORS_ORIGINS,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

app.include_router(auth.router, prefix="/api/auth", tags=["Auth"])
app.include_router(users.router, prefix="/api/users", tags=["Users"])
app.include_router(characters.router, prefix="/api/characters", tags=["Characters"])
app.include_router(gacha.router, prefix="/api/gacha", tags=["Gacha"])
app.include_router(battles.router, prefix="/api/battles", tags=["Battles"])
app.include_router(tournaments.router, prefix="/api/tournaments", tags=["Tournaments"])
app.include_router(rewards.router, prefix="/api/rewards", tags=["Rewards"])
app.include_router(shop.router, prefix="/api/shop", tags=["Shop"])
app.include_router(items.router, prefix="/api/items", tags=["Items"])
app.include_router(admin.router, prefix="/api/admin", tags=["Admin"])


async def seed_master_data():
    """初回起動時にマスタデータを投入"""
    from sqlalchemy import select
    from app.database import async_session
    from app.models.character import CharacterTemplate
    from app.models.gacha import GachaPool, GachaPoolItem, GachaPoolItemEquip
    from app.models.item import ItemTemplate

    async with async_session() as db:
        result = await db.execute(select(CharacterTemplate).limit(1))
        if result.scalar_one_or_none() is not None:
            # アイテムテンプレートだけ未投入の場合は追加
            item_result = await db.execute(select(ItemTemplate).limit(1))
            if item_result.scalar_one_or_none() is None:
                await _seed_items(db)
                await db.commit()
            # アイテムガチャプール未投入の場合は追加
            item_pool_result = await db.execute(
                select(GachaPool).where(GachaPool.pool_type == "item").limit(1)
            )
            if item_pool_result.scalar_one_or_none() is None:
                await _seed_item_gacha_pool(db)
                await db.commit()
            # UR装備が未投入の場合は追加
            ur_equip_result = await db.execute(
                select(ItemTemplate).where(ItemTemplate.rarity == 5, ItemTemplate.item_type == "equipment").limit(1)
            )
            if ur_equip_result.scalar_one_or_none() is None:
                await _seed_ur_equipment(db)
                await db.commit()
            # プレミアム装備ガチャプール未投入の場合は追加
            premium_item_pool_result = await db.execute(
                select(GachaPool).where(GachaPool.pool_type == "item", GachaPool.cost_type == "premium").limit(1)
            )
            if premium_item_pool_result.scalar_one_or_none() is None:
                await _seed_premium_item_gacha_pool(db)
                await db.commit()
            return

        # キャラクターテンプレート
        templates = [
            # Rarity 1 (N) - 一般キャラ
            CharacterTemplate(id=1, name="見習い剣士", rarity=1, base_hp=100, base_atk=20, base_def=15, base_spd=10, skill_name="斬撃", skill_description="基本的な剣攻撃", skill_power=1.2, image_url=""),
            CharacterTemplate(id=2, name="村の弓使い", rarity=1, base_hp=80, base_atk=25, base_def=10, base_spd=15, skill_name="矢の雨", skill_description="複数の矢を放つ", skill_power=1.3, image_url=""),
            CharacterTemplate(id=3, name="初級魔法使い", rarity=1, base_hp=70, base_atk=30, base_def=8, base_spd=12, skill_name="ファイアボール", skill_description="炎の球を放つ", skill_power=1.4, image_url=""),
            CharacterTemplate(id=4, name="街の盾兵", rarity=1, base_hp=130, base_atk=12, base_def=25, base_spd=8, skill_name="シールドバッシュ", skill_description="盾で攻撃", skill_power=1.1, image_url=""),
            CharacterTemplate(id=5, name="旅の踊り子", rarity=1, base_hp=90, base_atk=18, base_def=12, base_spd=20, skill_name="舞踏の一撃", skill_description="素早い連撃", skill_power=1.2, image_url=""),
            # Rarity 2 (R)
            CharacterTemplate(id=6, name="騎士団の剣士", rarity=2, base_hp=140, base_atk=30, base_def=22, base_spd=14, skill_name="連続斬り", skill_description="素早く2回斬る", skill_power=1.5, image_url=""),
            CharacterTemplate(id=7, name="森のレンジャー", rarity=2, base_hp=110, base_atk=35, base_def=15, base_spd=20, skill_name="精密射撃", skill_description="急所を狙う一射", skill_power=1.6, image_url=""),
            CharacterTemplate(id=8, name="宮廷魔術師", rarity=2, base_hp=100, base_atk=40, base_def=12, base_spd=16, skill_name="ブリザード", skill_description="氷の嵐を呼ぶ", skill_power=1.7, image_url=""),
            CharacterTemplate(id=9, name="傭兵団長", rarity=2, base_hp=160, base_atk=28, base_def=28, base_spd=12, skill_name="戦場の咆哮", skill_description="気合で攻撃力UP", skill_power=1.4, image_url=""),
            CharacterTemplate(id=10, name="盗賊ギルドの頭", rarity=2, base_hp=100, base_atk=32, base_def=14, base_spd=25, skill_name="影の一撃", skill_description="見えない速さで斬る", skill_power=1.6, image_url=""),
            # Rarity 3 (SR)
            CharacterTemplate(id=11, name="聖騎士アルベルト", rarity=3, base_hp=200, base_atk=42, base_def=35, base_spd=18, skill_name="聖なる裁き", skill_description="神聖な力で攻撃", skill_power=1.8, image_url=""),
            CharacterTemplate(id=12, name="氷の魔女エリーゼ", rarity=3, base_hp=140, base_atk=55, base_def=18, base_spd=22, skill_name="絶対零度", skill_description="全てを凍らせる", skill_power=2.0, image_url=""),
            CharacterTemplate(id=13, name="暗殺者シャドウ", rarity=3, base_hp=130, base_atk=50, base_def=15, base_spd=30, skill_name="暗殺術", skill_description="一撃必殺を狙う", skill_power=2.2, image_url=""),
            # Rarity 4 (SSR)
            CharacterTemplate(id=14, name="竜騎士レオン", rarity=4, base_hp=280, base_atk=60, base_def=45, base_spd=22, skill_name="竜の息吹", skill_description="竜の力を借りた一撃", skill_power=2.5, image_url=""),
            CharacterTemplate(id=15, name="大魔導師メルリン", rarity=4, base_hp=180, base_atk=75, base_def=25, base_spd=28, skill_name="メテオストライク", skill_description="隕石を落とす", skill_power=2.8, image_url=""),
            # Rarity 5 (UR)
            CharacterTemplate(id=16, name="神剣の勇者アリア", rarity=5, base_hp=350, base_atk=80, base_def=55, base_spd=30, skill_name="神剣・天照", skill_description="神の剣で全てを斬る", skill_power=3.0, image_url=""),
        ]
        db.add_all(templates)

        # ガチャプール: 通常ガチャ
        normal_pool = GachaPool(id=1, name="通常ガチャ", description="いつでも引ける基本のガチャ", cost_type="points", cost_amount=100, pity_count=100)
        db.add(normal_pool)
        await db.flush()

        # ガチャ排出アイテム (重みで確率を決定)
        pool_items = [
            *[GachaPoolItem(pool_id=1, template_id=i, weight=12) for i in range(1, 6)],
            *[GachaPoolItem(pool_id=1, template_id=i, weight=5) for i in range(6, 11)],
            *[GachaPoolItem(pool_id=1, template_id=i, weight=3) for i in range(11, 14)],
            *[GachaPoolItem(pool_id=1, template_id=i, weight=2) for i in range(14, 16)],
            GachaPoolItem(pool_id=1, template_id=16, weight=1),
        ]
        db.add_all(pool_items)

        # プレミアムガチャ
        premium_pool = GachaPool(id=2, name="プレミアムガチャ", description="SSR以上確定！有料通貨で引ける", cost_type="premium", cost_amount=300, pity_count=50)
        db.add(premium_pool)
        await db.flush()

        premium_items = [
            *[GachaPoolItem(pool_id=2, template_id=i, weight=5) for i in range(6, 11)],
            *[GachaPoolItem(pool_id=2, template_id=i, weight=8) for i in range(11, 14)],
            *[GachaPoolItem(pool_id=2, template_id=i, weight=5) for i in range(14, 16)],
            GachaPoolItem(pool_id=2, template_id=16, weight=2),
        ]
        db.add_all(premium_items)

        # アイテムマスタデータ
        await _seed_items(db)

        # アイテムガチャプール
        await _seed_item_gacha_pool(db)

        # プレミアム装備ガチャプール
        await _seed_premium_item_gacha_pool(db)

        await db.commit()


async def _seed_items(db):
    """アイテムマスタデータを投入"""
    from app.models.item import ItemTemplate

    item_templates = [
        # === 宝箱 ===
        ItemTemplate(id=1, name="普通の宝箱", item_type="treasure_pt", rarity=1, description="10〜30PTが入っている", min_value=10, max_value=30),
        ItemTemplate(id=2, name="銀の宝箱", item_type="treasure_pt", rarity=2, description="30〜100PTが入っている", min_value=30, max_value=100),
        ItemTemplate(id=3, name="金の宝箱", item_type="treasure_pt", rarity=3, description="100〜500PTが入っている", min_value=100, max_value=500),
        ItemTemplate(id=4, name="装備宝箱・小", item_type="treasure_equip", rarity=1, description="N〜Rの装備が出る", min_value=1, max_value=2),
        ItemTemplate(id=5, name="装備宝箱・中", item_type="treasure_equip", rarity=2, description="R〜SRの装備が出る", min_value=2, max_value=3),
        ItemTemplate(id=6, name="装備宝箱・大", item_type="treasure_equip", rarity=3, description="SR〜SSRの装備が出る", min_value=3, max_value=4),
        ItemTemplate(id=7, name="チケット宝箱", item_type="treasure_ticket", rarity=1, description="通常ガチャチケット1〜3枚", min_value=1, max_value=3),

        # === 武器 (片手) ===
        ItemTemplate(id=101, name="木の剣", item_type="equipment", rarity=1, description="初心者向けの木製の剣", equip_slot="weapon_1h", equip_race="all", bonus_atk=5),
        ItemTemplate(id=102, name="鉄の剣", item_type="equipment", rarity=2, description="戦士向けの頑丈な剣", equip_slot="weapon_1h", equip_race="warrior", bonus_atk=12),
        ItemTemplate(id=103, name="魔法の杖", item_type="equipment", rarity=2, description="魔力を増幅する杖", equip_slot="weapon_1h", equip_race="mage", bonus_atk=10, bonus_spd=3),
        ItemTemplate(id=104, name="獣の爪", item_type="equipment", rarity=2, description="獣人専用の鋭い爪", equip_slot="weapon_1h", equip_race="beastman", bonus_atk=8, bonus_spd=5),
        ItemTemplate(id=105, name="鋼の剣", item_type="equipment", rarity=3, description="精錬された鋼の剣", equip_slot="weapon_1h", equip_race="warrior", bonus_atk=20, bonus_spd=2),
        ItemTemplate(id=106, name="賢者の杖", item_type="equipment", rarity=3, description="古の賢者が使った杖", equip_slot="weapon_1h", equip_race="mage", bonus_atk=18, bonus_spd=5),
        ItemTemplate(id=107, name="鬼の爪", item_type="equipment", rarity=3, description="鬼の骨から作られた爪", equip_slot="weapon_1h", equip_race="beastman", bonus_atk=15, bonus_spd=8),
        ItemTemplate(id=108, name="炎の剣", item_type="equipment", rarity=4, description="炎を纏う伝説の剣", equip_slot="weapon_1h", equip_race="all", bonus_atk=30, effect_name="critical_rate", effect_value=0.05),
        ItemTemplate(id=109, name="闇の杖", item_type="equipment", rarity=4, description="闇の力が宿る杖", equip_slot="weapon_1h", equip_race="mage", bonus_atk=28, bonus_spd=8, effect_name="critical_rate", effect_value=0.08),

        # === 武器 (両手) ===
        ItemTemplate(id=120, name="大剣", item_type="equipment", rarity=2, description="両手で振るう重い剣", equip_slot="weapon_2h", equip_race="warrior", bonus_atk=20, bonus_spd=-3),
        ItemTemplate(id=121, name="聖なる大剣", item_type="equipment", rarity=4, description="聖なる力が宿る大剣", equip_slot="weapon_2h", equip_race="warrior", bonus_atk=45, bonus_hp=30, effect_name="heal_per_turn", effect_value=5.0),

        # === 盾 (武器スロット) ===
        ItemTemplate(id=130, name="木の盾", item_type="equipment", rarity=1, description="軽い木製の盾", equip_slot="shield", equip_race="all", bonus_def=5),
        ItemTemplate(id=131, name="鉄の盾", item_type="equipment", rarity=2, description="戦士向けの頑丈な盾", equip_slot="shield", equip_race="warrior", bonus_def=12, bonus_hp=10),
        ItemTemplate(id=132, name="魔法の盾", item_type="equipment", rarity=3, description="魔力で守る盾", equip_slot="shield", equip_race="mage", bonus_def=15, bonus_hp=20),

        # === 防具 (頭) ===
        ItemTemplate(id=201, name="革の帽子", item_type="equipment", rarity=1, description="軽い革の帽子", equip_slot="head", equip_race="all", bonus_def=3),
        ItemTemplate(id=202, name="鉄兜", item_type="equipment", rarity=2, description="戦士向けの鉄兜", equip_slot="head", equip_race="warrior", bonus_def=8, bonus_hp=10),
        ItemTemplate(id=203, name="魔導師の帽子", item_type="equipment", rarity=2, description="魔力を高める帽子", equip_slot="head", equip_race="mage", bonus_def=4, bonus_atk=5),
        ItemTemplate(id=204, name="獣耳の兜", item_type="equipment", rarity=2, description="獣人の耳に合う兜", equip_slot="head", equip_race="beastman", bonus_def=5, bonus_spd=4),

        # === 防具 (体) ===
        ItemTemplate(id=211, name="革の鎧", item_type="equipment", rarity=1, description="軽い革鎧", equip_slot="body", equip_race="all", bonus_def=5, bonus_hp=10),
        ItemTemplate(id=212, name="鋼の鎧", item_type="equipment", rarity=2, description="重厚な鋼の鎧", equip_slot="body", equip_race="warrior", bonus_def=15, bonus_hp=20),
        ItemTemplate(id=213, name="魔導師のローブ", item_type="equipment", rarity=2, description="魔力を高めるローブ", equip_slot="body", equip_race="mage", bonus_def=6, bonus_atk=8),
        ItemTemplate(id=214, name="獣皮のベスト", item_type="equipment", rarity=2, description="獣人向けの動きやすい鎧", equip_slot="body", equip_race="beastman", bonus_def=8, bonus_spd=5),

        # === 防具 (手) ===
        ItemTemplate(id=221, name="革の手袋", item_type="equipment", rarity=1, description="基本的な手袋", equip_slot="hands", equip_race="all", bonus_def=2, bonus_atk=2),
        ItemTemplate(id=222, name="鉄の篭手", item_type="equipment", rarity=2, description="戦士向けの篭手", equip_slot="hands", equip_race="warrior", bonus_def=6, bonus_atk=4),

        # === 防具 (足) ===
        ItemTemplate(id=231, name="革のブーツ", item_type="equipment", rarity=1, description="軽いブーツ", equip_slot="feet", equip_race="all", bonus_spd=3),
        ItemTemplate(id=232, name="鉄のブーツ", item_type="equipment", rarity=2, description="頑丈なブーツ", equip_slot="feet", equip_race="warrior", bonus_def=5, bonus_spd=2),
        ItemTemplate(id=233, name="疾風のブーツ", item_type="equipment", rarity=3, description="風のように速く走れるブーツ", equip_slot="feet", equip_race="all", bonus_spd=10),

        # === 装飾品 ===
        ItemTemplate(id=301, name="力の指輪", item_type="equipment", rarity=1, description="攻撃力が少し上がる指輪", equip_slot="accessory", equip_race="all", bonus_atk=5),
        ItemTemplate(id=302, name="守りのペンダント", item_type="equipment", rarity=1, description="防御力が少し上がるペンダント", equip_slot="accessory", equip_race="all", bonus_def=5),
        ItemTemplate(id=303, name="クリティカルの腕輪", item_type="equipment", rarity=2, description="会心の一撃が出やすくなる", equip_slot="accessory", equip_race="all", effect_name="critical_rate", effect_value=0.05),
        ItemTemplate(id=304, name="回避のマント", item_type="equipment", rarity=3, description="攻撃を避けやすくなる", equip_slot="accessory", equip_race="all", effect_name="dodge_rate", effect_value=0.03),
        ItemTemplate(id=305, name="反撃のリング", item_type="equipment", rarity=3, description="攻撃を受けた時反撃する", equip_slot="accessory", equip_race="all", effect_name="counter_rate", effect_value=0.1),
        ItemTemplate(id=306, name="癒しのアミュレット", item_type="equipment", rarity=3, description="毎ターンHPが少し回復する", equip_slot="accessory", equip_race="all", effect_name="heal_per_turn", effect_value=3.0),

        # === UR装備 ===
        ItemTemplate(id=110, name="神剣ラグナロク", item_type="equipment", rarity=5, description="伝説の神剣。全てを超越する力を持つ", equip_slot="weapon_1h", equip_race="all", bonus_atk=70, bonus_spd=10, effect_name="critical_rate", effect_value=0.20),
        ItemTemplate(id=111, name="神聖鎧エギル", item_type="equipment", rarity=5, description="神が鍛えた不壊の鎧", equip_slot="body", equip_race="all", bonus_def=55, bonus_hp=150, effect_name="heal_per_turn", effect_value=8.0),
    ]
    db.add_all(item_templates)
    await db.flush()


async def _seed_item_gacha_pool(db):
    """アイテムガチャプールを投入"""
    from app.models.gacha import GachaPool, GachaPoolItemEquip

    item_pool = GachaPool(
        name="装備ガチャ", description="装備品が手に入るガチャ",
        pool_type="item", cost_type="points", cost_amount=150, pity_count=50,
    )
    db.add(item_pool)
    await db.flush()

    item_gacha_entries = [
        # N装備 (weight=15)
        *[GachaPoolItemEquip(pool_id=item_pool.id, item_template_id=tid, weight=15) for tid in [101, 130, 201, 211, 221, 231, 301, 302]],
        # R装備 (weight=8)
        *[GachaPoolItemEquip(pool_id=item_pool.id, item_template_id=tid, weight=8) for tid in [102, 103, 104, 120, 131, 202, 203, 204, 212, 213, 214, 222, 232, 303]],
        # SR装備 (weight=3)
        *[GachaPoolItemEquip(pool_id=item_pool.id, item_template_id=tid, weight=3) for tid in [105, 106, 107, 132, 233, 304, 305, 306]],
        # SSR装備 (weight=1)
        *[GachaPoolItemEquip(pool_id=item_pool.id, item_template_id=tid, weight=1) for tid in [108, 109, 121]],
        # UR装備 (weight=1) ※合計261中2 ≈ 0.77%
        *[GachaPoolItemEquip(pool_id=item_pool.id, item_template_id=tid, weight=1) for tid in [110, 111]],
    ]
    db.add_all(item_gacha_entries)


async def _seed_premium_item_gacha_pool(db):
    """プレミアム装備ガチャプールを投入（SR以上確定・GEM消費）"""
    from app.models.gacha import GachaPool, GachaPoolItemEquip

    premium_item_pool = GachaPool(
        name="プレミアム装備ガチャ", description="SR以上確定！GEMで引ける高レアリティ装備ガチャ",
        pool_type="item", cost_type="premium", cost_amount=300, pity_count=50,
    )
    db.add(premium_item_pool)
    await db.flush()

    premium_item_entries = [
        # SR装備 (weight=12)
        *[GachaPoolItemEquip(pool_id=premium_item_pool.id, item_template_id=tid, weight=12) for tid in [105, 106, 107, 132, 233, 304, 305, 306]],
        # SSR装備 (weight=4)
        *[GachaPoolItemEquip(pool_id=premium_item_pool.id, item_template_id=tid, weight=4) for tid in [108, 109, 121]],
        # UR装備 (weight=1) ※合計99+3=102中2 ≈ 2%
        *[GachaPoolItemEquip(pool_id=premium_item_pool.id, item_template_id=tid, weight=1) for tid in [110, 111]],
    ]
    db.add_all(premium_item_entries)


async def _seed_ur_equipment(db):
    """UR装備を既存DBに追加（移行用）"""
    from sqlalchemy import select
    from app.models.item import ItemTemplate
    from app.models.gacha import GachaPool, GachaPoolItemEquip

    ur_items = [
        ItemTemplate(id=110, name="神剣ラグナロク", item_type="equipment", rarity=5, description="伝説の神剣。全てを超越する力を持つ", equip_slot="weapon_1h", equip_race="all", bonus_atk=70, bonus_spd=10, effect_name="critical_rate", effect_value=0.20),
        ItemTemplate(id=111, name="神聖鎧エギル", item_type="equipment", rarity=5, description="神が鍛えた不壊の鎧", equip_slot="body", equip_race="all", bonus_def=55, bonus_hp=150, effect_name="heal_per_turn", effect_value=8.0),
    ]
    db.add_all(ur_items)
    await db.flush()

    # 既存のアイテムガチャプールにUR装備を追加
    pool_result = await db.execute(select(GachaPool).where(GachaPool.pool_type == "item").limit(1))
    item_pool = pool_result.scalar_one_or_none()
    if item_pool:
        gacha_entries = [
            GachaPoolItemEquip(pool_id=item_pool.id, item_template_id=110, weight=1),
            GachaPoolItemEquip(pool_id=item_pool.id, item_template_id=111, weight=1),
        ]
        db.add_all(gacha_entries)
        await db.flush()


async def seed_npc_names():
    """NPC名前をDBにシード（未登録分のみ追加）"""
    from sqlalchemy import select
    from app.models.admin import NpcName

    default_names = [
        "アレックス", "リナ", "カイト", "ミル", "ゼノ",
        "ハル", "ソラ", "ユウキ", "レン", "アイリ",
        "シン", "マヤ", "ルカ", "サクラ", "ダイチ",
        "ヒカリ", "タクミ", "ナナ", "リュウ", "ミサキ",
        "コウ", "アオイ", "ケン", "メイ", "ジン",
        "フウカ", "テツ", "モモ", "カズマ", "チヒロ",
        "ノア", "リオ", "ハヤテ", "ツバサ", "イズミ",
        "ギン", "アカネ", "シオン", "ユズ", "ライト",
    ]

    async with async_session() as db:
        result = await db.execute(select(NpcName).limit(1))
        if result.scalar_one_or_none() is not None:
            return  # 既にシード済み

        for name in default_names:
            db.add(NpcName(name=name))
        await db.commit()


@app.get("/")
async def root():
    return {"message": "Tournament Battle API", "docs": "/docs"}
