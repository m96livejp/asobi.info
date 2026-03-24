# トーナメントバトルゲーム 引継ぎドキュメント

最終更新: 2026-03-18

---

## 概要

スマホ向け PWA オートバトルゲーム。ガチャでキャラを集め、装備を付けて自動進行のトーナメントに参加する。

- **URL**: `https://tbt.asobi.info`
- **ソース**: `G:\マイドライブ\claude\トーナメントAPI\`

---

## 技術スタック

| 層 | 技術 |
|---|---|
| バックエンド | Python 3.11 + FastAPI + SQLAlchemy 2.0 async (aiosqlite) |
| フロントエンド | Vanilla JS SPA（ビルド不要）+ PWA (Service Worker) |
| DB | SQLite（`tournament.db`、本番VPS上） |
| 認証 | JWT (30日) + ゲスト(device_id) + メール/PW + Google/LINE/X OAuth |
| サーバー | uvicorn port 8001、リバースプロキシ経由で 443 |

---

## ディレクトリ構成

```
トーナメントAPI/
├── server/
│   ├── run.py                        # uvicorn エントリポイント (port 8001)
│   └── app/
│       ├── main.py                   # FastAPI app, lifespan, マスタデータ seed
│       ├── config.py                 # 環境変数設定 (.env)
│       ├── database.py               # DB接続 + init_db + _migrate_columns
│       ├── deps.py                   # CurrentUser, DbSession 依存注入
│       ├── models/
│       │   ├── user.py               # User, AdReward
│       │   ├── character.py          # Character, CharacterTemplate, CharacterEquipment
│       │   ├── battle.py             # Tournament, TournamentEntry, BattleLog
│       │   ├── item.py               # ItemTemplate, UserItem
│       │   ├── gacha.py              # GachaPool, GachaPoolItem, GachaPoolItemEquip
│       │   ├── social_account.py     # SocialAccount (OAuth連携)
│       │   └── shop.py               # ShopProduct
│       ├── schemas/                  # Pydantic スキーマ
│       ├── api/
│       │   ├── auth.py               # ゲスト/メール認証, アカウント管理
│       │   ├── oauth.py              # Google/LINE/X OAuth
│       │   ├── characters.py         # キャラ一覧/詳細/お気に入り/特訓
│       │   ├── tournaments.py        # トーナメント作成/参加/自動バトル
│       │   ├── battles.py            # プラクティスバトル/リプレイ
│       │   ├── gacha.py              # ガチャ
│       │   ├── items.py              # 所持品/装備/アンイクイップ/宝箱使用
│       │   ├── rewards.py            # 広告報酬
│       │   ├── shop.py               # ショップ購入
│       │   └── users.py              # プロフィール更新
│       └── services/
│           ├── battle_engine.py      # run_battle() オートバトル計算
│           ├── matchmaking.py        # assign_seeds(), get_round_matchups()
│           ├── gacha_service.py      # ガチャ抽選
│           ├── item_gacha_service.py # 装備ガチャ
│           ├── oauth_service.py      # OAuth HTTP通信
│           ├── auth_service.py       # JWT生成/検証
│           └── reward_service.py     # 広告報酬
└── frontend/
    ├── index.html                    # SPA エントリ
    ├── auth-callback.html            # OAuth コールバック受け取り
    ├── sw.js                         # Service Worker (現: v13)
    ├── css/style.css
    └── js/
        ├── api.js                    # API クライアント
        ├── app.js                    # ルーティング
        ├── auth.js                   # 認証状態管理
        ├── utils/
        │   ├── ui.js                 # モーダル/アラート/確認ダイアログ
        │   ├── animation.js          # バトルアニメーション
        │   └── storage.js            # localStorage ラッパー
        └── components/
            ├── login.js
            ├── home.js
            ├── character.js          # キャラ一覧/詳細/特訓/装備
            ├── tournament.js         # トーナメント表/参加
            ├── battle.js             # プラクティスバトル
            ├── gacha.js
            ├── items.js
            ├── settings.js           # アカウント連携/ログアウト
            ├── shop.js
            └── ad-banner.js
```

---

## DB スキーマ（主要テーブル）

### users
| カラム | 型 | 備考 |
|---|---|---|
| id | UUID | PK |
| device_id | VARCHAR | NULL可 (ゲスト) |
| email | VARCHAR | NULL可, UNIQUE |
| password_hash | VARCHAR | NULL可 |
| display_name | VARCHAR | |
| points | INT | 初期500 |
| premium_currency | INT | |
| stamina | INT | 初期100 |
| normal_gacha_tickets | INT | 初期10 |
| premium_gacha_tickets | INT | 初期1 |

### characters
| カラム | 型 | 備考 |
|---|---|---|
| id | UUID | PK |
| user_id | UUID | FK→users |
| template_id | INT | FK→character_templates |
| race | VARCHAR | warrior / mage / beastman |
| level | INT | 初期1 |
| exp | INT | `required_exp(level) = 3^level` |
| is_favorite | BOOL | お気に入りフラグ |
| hp / atk / def_ / spd | INT | |

### tournaments
| カラム | 型 | 備考 |
|---|---|---|
| id | UUID | PK |
| name | VARCHAR | |
| status | VARCHAR | recruiting / in_progress / finished |
| max_participants | INT | 4 / 8 / 16 のみ |
| current_round | INT | |
| entry_cost_type | VARCHAR | free / points / premium |
| entry_cost_amount | INT | |
| reward_points | INT | |

### tournament_entries
| カラム | 型 | 備考 |
|---|---|---|
| id | INT | PK auto |
| tournament_id | UUID | FK→tournaments |
| user_id | UUID | FK→users |
| character_id | UUID | FK→characters |
| seed | INT | NULL可（開始前はNULL） |
| eliminated_round | INT | NULL=現役, 数値=敗退ラウンド |

### battle_logs
- `battle_data` JSON にターン詳細を格納（リプレイ用）

### item_templates
| カラム | 型 | 備考 |
|---|---|---|
| item_type | VARCHAR | equipment / treasure_pt / treasure_ticket / treasure_equip |
| equip_slot | VARCHAR | weapon_1h / weapon_2h / shield / head / body / hands / feet / accessory |
| equip_race | VARCHAR | all / warrior / mage / beastman |
| bonus_hp/atk/def/spd | INT | 装備ボーナス |
| effect_name | VARCHAR | critical_rate / dodge_rate / counter_rate / heal_per_turn |
| effect_value | FLOAT | |

### user_items
- `user_id` + `item_template_id` で論理的にユニークだが DB 制約なし
- 重複行が存在し得るため **取得・追加時は必ず集約処理**が必要（後述）

---

## ゲームロジック

### レベルアップ
```python
required_exp(level) = 3 ** level  # Lv1→2は3EXP, Lv2→3は9EXP ...
growth = 1.0 + (rarity * 0.02)    # レアリティで成長率が変わる
# N(1):+2%, R(2):+4%, SR(3):+6%, SSR(4):+8%, UR(5):+10%
```

### 特訓（`POST /characters/{id}/train`）
- 兵士キャラを消費して EXP 獲得
- 獲得EXP = `max(1, 兵士.exp × 2^(rarity-1))`
  - N:×1, R:×2, SR:×4, SSR:×8, UR:×16
- 兵士の装備は自動で所持品に返却（weapon_2h は1個として数える）
- **選択不可**: お気に入り登録中 / 大会参加中（recruiting or in_progress）のキャラ

### バトルエンジン（`battle_engine.py`）
- SPD が高い方が先攻
- スキル: 30% 確率で発動（skill_power 倍率で威力UP）
- クリティカル: 装備の `critical_rate` に基づく確率、ダメージ×1.5
- 回避: 装備の `dodge_rate` に基づく確率
- 反撃: 装備の `counter_rate` に基づく確率（威力0.5倍）
- 毎ターン回復: 装備の `heal_per_turn`
- 最大20ターン、HP残量率で勝敗判定

### トーナメント自動進行
- 満員（4/8/16人）になった瞬間に全ラウンドを自動実行（同一APIリクエスト内）
- 勝者に宝箱付与（参加者の平均総合力で宝箱ランク決定）
- 勝者・敗者ともに1EXP付与 → レベルアップ判定
- シード番号はランダム割り当て

---

## 認証フロー

### ゲスト登録
```
POST /api/auth/guest { device_id, display_name } → JWT
```

### メール
```
POST /api/auth/email/register { email, password, display_name? }
POST /api/auth/email/login    { email, password }
```

### OAuth (Google / LINE / X)
```
1. GET /api/auth/oauth/{provider}/url?mode=login|link
   → { url: "https://..." } を返す
2. ブラウザをその URL にリダイレクト
3. 認証後 GET /api/auth/callback/{provider}?code=...&state=...
   → JWT を auth-callback.html に渡す (クエリパラメータ)
4. auth-callback.html が localStorage に保存 → メインアプリにリダイレクト
```

- `state` パラメータは JWT エンコード（mode, user_id, PKCE code_verifier を格納）
- X(Twitter) は PKCE フロー

### アカウントリンク
- ゲストアカウントに後からメール/ソーシャルを紐付け可能
- 最後の認証手段を削除しようとするとゲストアカウントに自動ロールバック
- ゲスト未連携でのログアウト時は警告表示

---

## 重要な実装パターン

### 非同期 SQLAlchemy での eager load 必須箇所

```python
# NG: lazy="select"（デフォルト）は非同期コンテキストでクラッシュ
# NG: run_battle（同期関数）から char.equipment（lazy="selectin"）にアクセスするとクラッシュ

# OK: トーナメント自動バトル前に全関連を明示的にプリロード
from sqlalchemy.orm import selectinload, joinedload

refreshed = await db.execute(
    select(Tournament)
    .where(Tournament.id == tournament_id)
    .options(
        selectinload(Tournament.entries).options(
            joinedload(TournamentEntry.character).options(
                joinedload(Character.template),
                selectinload(Character.equipment)
                    .joinedload(CharacterEquipment.item_template),
            ),
            joinedload(TournamentEntry.user),
        )
    )
    .execution_options(populate_existing=True)  # identity map キャッシュを強制上書き
)
tournament = refreshed.scalar_one()
# populate_existing=True が必要な理由:
# 参加チェック時に equipment なしで identity map に登録されたキャラを
# 上書きしないと equipment 未ロードのキャッシュが返ってしまう
```

### scalar_one_or_none() の注意

**複数行が存在し得るクエリには使わない**。使い分けは以下の通り：

```python
# OK: PK や UNIQUE 制約のある1件取得
result.scalar_one_or_none()

# NG→OK: 複数行が存在し得る場合（UserItem, TournamentEntry, SocialAccount 等）
result.scalars().first()      # 存在チェック
result.scalars().all()        # 全件取得
```

### UserItem の重複行対応

```python
# 追加・更新時は必ず重複集約処理を行う
existing_rows = result.scalars().all()
if existing_rows:
    for extra in existing_rows[1:]:
        existing_rows[0].quantity += extra.quantity
        await db.delete(extra)
    existing_rows[0].quantity += 1
else:
    db.add(UserItem(user_id=user_id, item_template_id=template_id, quantity=1))
```

### DB マイグレーション

Alembic 未使用。`database.py` の `_migrate_columns()` に ALTER TABLE を追記してカラム追加：

```python
def _migrate_columns(conn):
    import sqlalchemy as sa
    inspector = sa.inspect(conn)
    if inspector.has_table("characters"):
        existing = {col["name"] for col in inspector.get_columns("characters")}
        if "new_column" not in existing:
            conn.execute(sa.text("ALTER TABLE characters ADD COLUMN new_column TYPE DEFAULT value"))
```

### カスタムダイアログ

`window.alert` は `ui.js` でオーバーライド済み（ドメイン表示なし）。
`confirm` はオーバーライドしていないので **必ず `await UI.confirm()` を使うこと**：

```javascript
// NG: ブラウザネイティブ（ドメインがタイトルバーに表示される）
if (!confirm("削除しますか？")) return;

// OK: カスタムモーダル
if (!await UI.confirm("削除しますか？")) return;
```

---

## Service Worker キャッシュ

- `sw.js` の `CACHE_NAME` を変更することで全クライアントのキャッシュを強制更新
- 現在: **v13**
- 静的アセット（HTML/CSS/JS）のみキャッシュ。API レスポンスはキャッシュしない

```javascript
const CACHE_NAME = 'tournament-battle-v13';  // 変更するたびにインクリメント
```

---

## 環境変数（`server/.env`）

```env
DATABASE_URL=sqlite+aiosqlite:///./tournament.db
JWT_SECRET_KEY=（本番用の強力なランダムキー）
DEBUG=False

# OAuth
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://tbt.asobi.info/api/auth/callback/google

LINE_CHANNEL_ID=
LINE_CHANNEL_SECRET=
LINE_REDIRECT_URI=https://tbt.asobi.info/api/auth/callback/line

TWITTER_CLIENT_ID=
TWITTER_CLIENT_SECRET=
TWITTER_REDIRECT_URI=https://tbt.asobi.info/api/auth/callback/twitter

FRONTEND_URL=https://tbt.asobi.info
```

---

## サーバー起動

```bash
cd server
pip install -r requirements.txt   # 初回のみ
python run.py                      # uvicorn port 8001 で起動
# または
uvicorn app.main:app --host 0.0.0.0 --port 8001 --reload
```

---

## API エンドポイント一覧

### 認証 `/api/auth`
| メソッド | パス | 説明 |
|---|---|---|
| POST | `/guest` | ゲスト登録 |
| POST | `/login` | ゲストログイン (device_id) |
| POST | `/email/register` | メール登録 |
| POST | `/email/login` | メールログイン |
| GET | `/me/accounts` | リンク済みアカウント一覧 |
| DELETE | `/me/accounts/{provider}` | アカウント連携解除 |
| DELETE | `/me` | アカウント削除 |
| GET | `/oauth/{provider}/url` | OAuth認証URL取得 |
| GET | `/callback/{provider}` | OAuthコールバック |

### キャラクター `/api/characters`
| メソッド | パス | 説明 |
|---|---|---|
| GET | `` | 所持キャラ一覧 |
| GET | `/{id}` | キャラ詳細 |
| GET | `/templates` | テンプレート一覧 |
| POST | `/{id}/favorite` | お気に入りトグル |
| POST | `/{id}/train` | 特訓（兵士消費） |

### トーナメント `/api/tournaments`
| メソッド | パス | 説明 |
|---|---|---|
| GET | `` | トーナメント一覧（最新20件） |
| POST | `` | トーナメント作成 |
| POST | `/{id}/entry` | 参加（満員で自動バトル開始） |
| GET | `/{id}/bracket` | トーナメント表 |
| GET | `/active-character-ids` | アクティブ参加中キャラIDリスト |

### バトル `/api/battles`
| メソッド | パス | 説明 |
|---|---|---|
| POST | `/practice` | プラクティスバトル |
| GET | `/{id}` | バトルログ取得（リプレイ用） |

### アイテム `/api/items`
| メソッド | パス | 説明 |
|---|---|---|
| GET | `` | 所持品一覧 |
| POST | `/{id}/use` | アイテム使用（宝箱開封等） |
| GET | `/equipment/{char_id}` | キャラの装備情報 |
| POST | `/equipment/{char_id}/equip` | 装備 |
| POST | `/equipment/{char_id}/unequip` | 装備解除 |

---

## このセッションで修正したバグ一覧

| # | ファイル | 問題 | 修正内容 |
|---|---|---|---|
| 1 | `tournaments.py` | `scalar_one_or_none()` で重複行時に `MultipleResultsFound` 例外 | → `scalars().first()` |
| 2 | `tournaments.py` | `db.refresh()` が identity map キャッシュを返し equipment 未ロードのまま `run_battle` がクラッシュ → **Network error** | → `populate_existing=True` 付き明示 eager load |
| 3 | `characters.py` | 特訓の大会参加チェックで同上 | → `scalars().first()` |
| 4 | `items.py` | `unequip` で UserItem 重複行時に例外 | → `scalars().all()` で集約 |
| 5 | `items.py` | 宝箱開封で UserItem を重複作成（毎回 `db.add()` していた） | → 既存行チェック＋集約 |
| 6 | `auth.py` | `unlink_account` で複数ソーシャルアカウント時に例外 | → `scalars().first()` |
| 7 | `matchmaking.py` | character が None のエントリが対戦に入りクラッシュ | → `e.character is not None` フィルタ追加 |
| 8 | `tournaments.py` | character が None の場合に `run_battle` がクラッシュ | → None チェックで不戦勝処理 |
| 9 | `character.js` | 特訓確認が native `confirm()`（ブラウザのドメイン表示あり） | → `await UI.confirm()` |
| 10 | `character.js` | 特訓後に詳細画面が閉じる | → 最新データ再取得して詳細を再表示 |
| 11 | `tournament.js` | エラー後にトーナメントリスト未更新（古い状態のまま） | → catch 内でも `this.render()` を呼ぶ |
| 12 | CSS `.bt-round` | トーナメント表の幅が狭くキャラ名が切れる | → `min-width: 140px / max-width: 180px` |
| 13 | CSS `.bt-slot` | 再生ボタンが上下の試合結果にくっついて見づらい | → padding 拡大、再生ボタン中央揃え全幅 |
| 14 | `battles.py` | リプレイボタンでバトルログ取得時に lazy load クラッシュ | → `joinedload` で attacker/defender の user を事前ロード |

---

## 残課題・未実装

- **OAuth アプリ登録**: Google/LINE/X のデベロッパーコンソールで CLIENT_ID/SECRET の取得・設定が必要
- **広告SDK**: AdMob 等の実装が必要（現状はモック）
- **プッシュ通知**: 未実装
- **管理画面**: なし（直接DBまたはAPIで操作）
- **本番DB**: SQLite のまま（マルチサーバー化・負荷増時は MySQL/PostgreSQL 移行を推奨）
- **FK制約**: SQLite はデフォルトで FK 制約を適用しない（`PRAGMA foreign_keys = ON` が未設定）

---

## 注意事項

1. **サーバー再起動が必要な変更**: Python ファイルを変更したらサーバー再起動が必要（`--reload` オプション使用中は自動再起動）
2. **フロントエンド変更後**: `sw.js` の `CACHE_NAME` をインクリメントしてキャッシュを更新すること
3. **DB変更**: カラム追加は `database.py` の `_migrate_columns()` に追記する。テーブル新規作成は `Base.metadata.create_all` で自動対応
4. **テスト用DB**: 本番DBを直接使っているため、テスト時はDBをバックアップしてから行うこと
