# asobi.info プロジェクト 引継ぎドキュメント

## サイト一覧

| サイト | URL | サーバー | ローカルパス | リモートパス |
|--------|-----|----------|-------------|-------------|
| メインサイト | https://asobi.info | Conoha VPS | `info/` | `/opt/asobi/info/` |
| DbD情報サイト | https://dbd.asobi.info | Conoha VPS | `dbd/` | `/opt/asobi/dbd/` |
| ポケモンクエスト | https://pkq.asobi.info | Conoha VPS | `pkq/` | `/opt/asobi/pkq/` |
| Tournament Battle | https://tbt.asobi.info | Conoha VPS | `tbt/` | `/opt/asobi/tbt/` |
| 共通ファイル | — | Conoha VPS | `shared/assets/` | `/opt/asobi/shared/assets/` |

---

## サーバー情報

### Conoha VPS（全サイト共通）

| 項目 | 値 |
|------|-----|
| ホスト | 133.117.75.23 |
| SSHユーザー | root |
| SSH秘密鍵 | `G:/マイドライブ/サーバ情報/key-m96-conoha.pem` |
| 共通DB | `/opt/asobi/data/users.sqlite` |

```bash
# SSH接続
ssh -i "G:/マイドライブ/サーバ情報/key-m96-conoha.pem" root@133.117.75.23

# デプロイ（deploy.shを使用）
bash deploy.sh pkq        # pkq全体
bash deploy.sh dbd        # dbd全体
bash deploy.sh info       # メインサイト全体
bash deploy.sh shared     # 共通assets全体
bash deploy.sh all        # 全サイト

# 単一ファイルデプロイ
scp -i "G:/マイドライブ/サーバ情報/key-m96-conoha.pem" \
  "ローカルファイル" root@133.117.75.23:/opt/asobi/対象サイト/パス
```

---

## 共通認証システム（asobi.info）

全サブドメイン共有のPHP+SQLiteセッション認証。

### ファイル構成
```
shared/assets/
├── php/
│   ├── auth.php          # 認証モジュール（全サブドメインから require_once）
│   ├── users_db.php      # DB接続・テーブル定義
│   ├── oauth_config.php  # OAuth クライアント設定
│   ├── me.php            # ログイン状態JSON API（CORS対応）
│   └── log-access.php    # アクセスログ記録API（JS→AJAX）
├── css/
│   ├── common.css        # 全サブドメイン共通スタイル
│   └── font.php          # フォント設定（管理画面で切り替え）
└── js/
    └── common.js         # 共通JS（debounce, API.get等）

info/
├── login.php             # ログインページ
├── logout.php            # ログアウト
├── register.php          # 新規登録
├── profile.php           # プロフィール・ソーシャル連携管理
├── contact.php           # 問い合わせフォーム（form@asobi.info宛）
└── licenses.html         # ライセンス・著作権表示ページ
```

### 使い方（各サブドメインから）
```php
require_once '/opt/asobi/shared/assets/php/auth.php';
asobiRequireLogin();   // 未ログインでログインページへリダイレクト
asobiRequireAdmin();   // 管理者以外に403
// ※アクセスログはJS（common.js）がlog-access.phpへAJAX送信
```

### セッションロック対策
me.php・log-access.phpはセッション読み取り直後に `session_write_close()` を呼び、
PHPセッションファイルのロックを早期解放する（並列リクエストの遅延防止）。

### セッションキー
- `$_SESSION['asobi_user_id']` — ユーザーID
- `$_SESSION['asobi_user_username']` — ユーザー名
- `$_SESSION['asobi_user_name']` — 表示名
- `$_SESSION['asobi_user_role']` — 'user' または 'admin'
- `$_SESSION['asobi_user_avatar']` — アバターURL

### users.sqlite テーブル構成
```sql
-- ユーザー
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL UNIQUE COLLATE NOCASE,
    email         TEXT UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    display_name  TEXT,
    avatar_url    TEXT,
    role          TEXT NOT NULL DEFAULT 'user' CHECK(role IN ('user','admin')),
    status        TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','suspended')),
    created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    last_login_at TEXT
);

-- ソーシャルアカウント連携（Google/LINE/Twitter）
CREATE TABLE social_accounts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    provider     TEXT NOT NULL,      -- google, line, twitter
    provider_id  TEXT NOT NULL,
    email        TEXT,
    display_name TEXT,
    username     TEXT,
    created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE(provider, provider_id)
);

-- アクセスログ
CREATE TABLE access_logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    host       TEXT NOT NULL DEFAULT '',
    path       TEXT NOT NULL,
    user_id    INTEGER,
    ip         TEXT,
    referer    TEXT,
    user_agent TEXT,
    browser    TEXT NOT NULL DEFAULT '',
    device     TEXT NOT NULL DEFAULT '',
    os         TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- ログイン履歴
CREATE TABLE login_logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    username   TEXT NOT NULL,
    ip         TEXT,
    user_agent TEXT,
    browser    TEXT NOT NULL DEFAULT '',
    device     TEXT NOT NULL DEFAULT '',
    os         TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- 禁止ワード
CREATE TABLE banned_words (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    word       TEXT NOT NULL,
    normalized TEXT NOT NULL,
    category   TEXT NOT NULL DEFAULT 'content',
    action     TEXT NOT NULL DEFAULT 'block',
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- 問い合わせ
CREATE TABLE contact_submissions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    ip         TEXT,
    name       TEXT,
    email      TEXT,
    category   TEXT,
    message    TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    type       TEXT,
    company    TEXT,
    department TEXT,
    phone      TEXT
);
```

### OAuth設定（oauth_config.php）
各プロバイダーのコンソールで以下のリダイレクトURIを登録する必要があります：
- Google: `https://asobi.info/oauth/callback.php?provider=google`
- LINE: `https://asobi.info/oauth/callback.php?provider=line`
- Twitter: `https://asobi.info/oauth/callback.php?provider=twitter`

### ログインメニュー共通ルール

全サブドメインで統一されたヘッダーログインメニューの実装規則。

#### ゲスト（未ログイン）
- ヘッダー右端に「ログイン」リンクを表示
- リンク先: `https://asobi.info/login.php?redirect=<現在のURL>`（ログイン後に元のページへ戻る）

#### ログイン済みユーザー
- ヘッダー右端にアバター画像（32px丸）+ 表示名を表示
- クリックでドロップダウンメニューを開閉

#### ドロップダウンメニュー構成
メニュー項目は「サイト固有項目」→「asobi.info共通項目」の順で構成する。

```
[サイト固有の項目]          ← サブドメインごとに異なる
──────────────────         ← 区切り線（divider）
asobi.info TOP             ← 共通：https://asobi.info/ へのリンク
🔒 asobi.info 管理          ← 共通：admin専用（role=admin のみ表示）
```

#### サイト固有メニュー例
| サイト | 固有項目 |
|--------|----------|
| AIC (チャット画面 app.js) | サイトに戻る / プロフィール / 🔒 コンテンツ管理(admin) |
| AIC (管理画面 admin.html) | チャットに戻る |
| メインサイト (info) | プロフィール |
| DbD・PKQ等 | （各サイトの管理画面リンク等） |

#### 実装上の注意
- AICのチャット画面ではメインヘッダーとチャットヘッダーの2箇所にログインメニューを配置（同一のメニュー構造を複製）
- admin専用項目は `user.role === 'admin'` で表示制御する
- ログイン状態の取得は `https://asobi.info/assets/php/me.php`（CORS対応済み）を使用
- アバターURLが未設定の場合はデフォルトアイコン（SVGまたはプレースホルダー）を表示

---

## asobi.info メインサイト

### ファイル構成
```
info/
├── index.php             # トップページ（ゲームカード一覧）
├── contact.php           # 問い合わせフォーム
├── licenses.html         # ライセンス・著作権表示
├── admin/
│   ├── index.php         # 管理ダッシュボード（PV/UV統計・ログ）
│   ├── users.php         # ユーザー管理
│   ├── banned-words.php  # 禁止ワード管理
│   ├── font.php          # フォント切り替え
│   └── ip-logs.php       # IP別アクセス履歴API（管理者のみ）
└── oauth/
    ├── start.php
    └── callback.php
```

### 管理画面
- URL: `https://asobi.info/admin/`
- asobiRequireAdmin() による保護
- 機能: PV/UV集計・日別グラフ・人気ページ・ブラウザ統計・ログイン履歴・IP別アクセス詳細・禁止ワード管理・フォント切り替え

---

## DbD情報サイト (dbd.asobi.info)

Dead by Daylight の攻略情報。キラー・サバイバーのパーク、アドオン、オファリング、アイテム、能力・速度。

### ファイル構成
```
dbd/
├── index.html
├── killer-perks.html / killer-addons.html / killer-offerings.html
├── killer-abilities.html / killer-speed.html
├── survivor-perks.html / survivor-offerings.html / survivor-items.html
├── css/style.css            # ダークテーマ（--accent: #e74c3c）
├── js/app.js                # DbDApp オブジェクト
├── api/                     # PHP API群
└── admin/                   # asobiRequireAdmin() 保護
```

### DBパス
`/opt/asobi/dbd/data/dbd.sqlite`

### 未完了
- 各 image_path カラムがほぼ未設定（killers, survivors, perks, addons）
- `survivor-addons.html` 未作成

---

## ポケモンクエスト (pkq.asobi.info)

ポケモンクエストのレシピ・素材・ポケモン検索サイト。

### ファイル構成
```
pkq/
├── index.html
├── recipes.html / simulator.html / pokemon-list.html / moves.html
├── iv-checker.html          # 個体値チェッカー
├── iv-report.php            # 料理結果投稿
├── iv-report-list.php       # 料理結果一覧
├── pokemon-detail.html / recipe-detail.html
├── css/style.css
├── js/
├── api/
│   ├── db.php
│   ├── ingredients.php / recipes.php / pokemon.php / pokedex.php
└── admin/                   # asobiRequireAdmin() 保護
    ├── dashboard.php / ingredients.php / recipes.php
    ├── pokemon.php / settings.php / iv.php
    └── layout.php
```

### DBパス
`/opt/asobi/pkq/data/pokemon_quest.sqlite`

### 個体値計算式
```
stat = 種族値 + level + 鍋の値(固定) + 個体値
鍋の値/個体値上限：鉄(0/10) 銅(50/50) 銀(150/100) 金(300/100)
```

---

## Tournament Battle (tbt.asobi.info)

トーナメント対戦ゲーム。FastAPI + PostgreSQL + JWT認証 + OAuth。

### アーキテクチャ
- バックエンド: FastAPI (Python) → `/opt/asobi/tbt/app/`
- フロントエンド: 静的HTML/JS → `/opt/asobi/tbt/frontend/`
- DB: PostgreSQL（SQLAlchemy）
- 認証: JWT + デバイスID（ゲスト）+ OAuth（Google/LINE/Twitter）

---

## Webフォント

使用フォント：Migu フォント（IPA Font License v1.0）
- Migu 1P: プロポーショナル
- Migu 1C: 英字改善・全角かなもプロポーショナル（**デフォルト**）
- Migu 1M: 等幅（数字の桁ずれなし）
- Migu 2M: 等幅・濁点控えめ

管理画面（/admin/font.php）でフォント種類を切り替え可能。TTF・WOFF2両形式を用意。

### 適用ルール（必須）
```css
/* NG: bodyに適用しない */
body { font-family: 'Migu 1C', sans-serif; }

/* OK: mainのみに適用（タイトル含む） */
body { font-family: system-ui, -apple-system, sans-serif; }
main { font-family: 'Migu 1C', system-ui, sans-serif; }
```
- `font-display: swap` 必須
- 新規ページ追加時も同じルールを守ること

---

## 共通スタイル・JS（shared/assets/）

### common.css
- ヘッダー `.site-header` / `.site-logo` / `.site-nav` / `.container`
- フッター `.site-footer`
- レスポンシブ対応（768px以下でモバイルメニュー）

### common.js
- `debounce(fn, wait)` — 検索入力のデバウンス
- `API.get(path, params)` — fetch ラッパー（JSON取得）
- モバイルメニュートグル（`.site-nav.open`）
