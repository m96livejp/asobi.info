# asobi.info プロジェクト 引継ぎドキュメント

## サイト一覧

| サイト | URL | サーバー | ローカルパス |
|--------|-----|----------|-------------|
| メインサイト | https://asobi.info | WPX | `public_html/` |
| DbD情報サイト | https://dbd.asobi.info | WPX | `public_html/dbd/` |
| ポケモンクエスト | https://pkq.asobi.info | WPX | `public_html/pkq/` |
| Tournament Battle | https://tbt.asobi.info | Conoha VPS | `G:/マイドライブ/claude/トーナメントAPI/` |

---

## サーバー情報

### WPX（メイン・dbd・pkq）

| 項目 | 値 |
|------|-----|
| ホスト | sv6112.wpx.ne.jp |
| SSHポート | 10022 |
| SSHユーザー | m96 |
| SSH秘密鍵 | `G:/マイドライブ/サーバ情報/Key-m96-wpx.key` |
| Webルート | `/home/m96/asobi.info/public_html/` |
| 共通DB | `/home/m96/asobi.info/data/users.sqlite` |

```bash
# デプロイ（単一ファイル）
scp -i "G:/マイドライブ/サーバ情報/Key-m96-wpx.key" -P 10022 -o StrictHostKeyChecking=no \
  "ローカルファイル" m96@sv6112.wpx.ne.jp:/home/m96/asobi.info/public_html/対象パス/

# SSH接続
ssh -i "G:/マイドライブ/サーバ情報/Key-m96-wpx.key" -p 10022 m96@sv6112.wpx.ne.jp
```

### Conoha VPS（tbt.asobi.info）

| 項目 | 値 |
|------|-----|
| ホスト | 133.117.75.23 |
| SSHユーザー | root |
| SSH秘密鍵 | `G:/マイドライブ/サーバ情報/key-m96-conoha.pem` |
| APIルート | `/opt/tournament-api/app/` |
| フロントエンド | `/opt/tournament-api/frontend/` |

```bash
# バックエンドデプロイ＋再起動
scp -i "G:/マイドライブ/サーバ情報/key-m96-conoha.pem" \
  "ローカルファイル" root@133.117.75.23:/opt/tournament-api/app/api/ファイル名
ssh -i "G:/マイドライブ/サーバ情報/key-m96-conoha.pem" root@133.117.75.23 \
  "systemctl restart tournament-api.service"

# フロントエンドデプロイ（再起動不要）
scp -i "G:/マイドライブ/サーバ情報/key-m96-conoha.pem" \
  "ローカルファイル" root@133.117.75.23:/opt/tournament-api/frontend/パス
```

---

## 共通認証システム（asobi.info）

全サブドメイン共有のPHP+SQLiteセッション認証。

### ファイル構成
```
public_html/
├── assets/php/
│   ├── auth.php          # 認証モジュール（全サブドメインから require_once）
│   ├── users_db.php      # DB接続・テーブル定義
│   └── oauth_config.php  # OAuth クライアント設定
├── oauth/
│   ├── start.php         # OAuth開始（プロバイダーへリダイレクト）
│   └── callback.php      # OAuthコールバック処理
├── login.php             # ログインページ
├── logout.php            # ログアウト
├── register.php          # 新規登録
└── profile.php           # プロフィール・ソーシャル連携管理
```

### 使い方（各サブドメインから）
```php
require_once '/home/m96/asobi.info/public_html/assets/php/auth.php';
asobiRequireLogin();   // 未ログインでログインページへリダイレクト
asobiRequireAdmin();   // 管理者以外に403
asobiLogAccess();      // アクセスログ記録（GETのみ）
```

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
```

### OAuth設定（oauth_config.php）
各プロバイダーのコンソールで以下のリダイレクトURIを登録する必要があります：
- Google: `https://asobi.info/oauth/callback.php?provider=google`
- LINE: `https://asobi.info/oauth/callback.php?provider=line`
- Twitter: `https://asobi.info/oauth/callback.php?provider=twitter`

---

## asobi.info メインサイト

### ファイル構成
```
public_html/
├── index.php             # トップページ（ゲームカード一覧）
├── admin/
│   ├── index.php         # 管理ダッシュボード（PV/UV統計・ログ）
│   ├── users.php         # ユーザー管理
│   └── ip-logs.php       # IP別アクセス履歴API（管理者のみ）
└── assets/
    ├── css/common.css    # 全サブドメイン共通スタイル
    └── js/common.js      # 共通JS（debounce, API.get等）
```

### 管理画面
- URL: `https://asobi.info/admin/`
- asobiRequireAdmin() による保護
- 機能: PV/UV集計・日別グラフ・人気ページ・ブラウザ統計・ログイン履歴・IP別アクセス詳細

---

## DbD情報サイト (dbd.asobi.info)

Dead by Daylight の攻略情報。キラー・サバイバーのパーク、アドオン、オファリング、アイテム、能力・速度。

### ファイル構成
```
public_html/dbd/
├── index.html               # トップページ
├── killer-perks.html
├── killer-addons.html
├── killer-offerings.html
├── killer-abilities.html
├── killer-speed.html
├── survivor-perks.html
├── survivor-offerings.html
├── survivor-items.html
├── css/style.css            # ダークテーマ（--accent: #e74c3c）
├── js/app.js                # DbDApp オブジェクト
├── api/                     # PHP API群
│   ├── db.php
│   ├── perks.php / killers.php / characters.php
│   ├── addons.php / offerings.php / items.php
└── admin/                   # asobiRequireAdmin() 保護
```

### DBパス
`/home/m96/asobi.info/public_html/dbd/data/dbd.sqlite`

### 管理画面
- URL: `https://dbd.asobi.info/admin/`
- asobiRequireAdmin() を使用（共通認証に統合済み）

### 未完了
- 各 image_path カラムがほぼ未設定（killers, survivors, perks, addons）
- `survivor-addons.html` 未作成

---

## ポケモンクエスト (pkq.asobi.info)

ポケモンクエストのレシピ・素材・ポケモン検索サイト。

### ファイル構成
```
public_html/pkq/
├── index.html
├── recipes.html / simulator.html / pokemon-list.html
├── api/
│   ├── db.php
│   ├── ingredients.php / recipes.php / pokemon.php
└── admin/                   # asobiRequireAdmin() 保護
    ├── auth.php             # require_once auth.php + asobiRequireAdmin()
    ├── dashboard.php / ingredients.php / recipes.php
    ├── pokemon.php / settings.php / api.php
    └── layout.php           # 管理画面共通レイアウト
```

### 注意事項
- サブドメイン `pkq.asobi.info` の Webルートは `/pkq/`
- 管理画面内のリンクは `/admin/xxx.php`（`/pkq/admin/xxx.php` ではない）
- ログアウトは `https://asobi.info/logout.php` へリダイレクト

### DBパス
`/home/m96/asobi.info/public_html/pkq/data/pokemon_quest.sqlite`

---

## Tournament Battle (tbt.asobi.info)

トーナメント対戦ゲーム。FastAPI + PostgreSQL + JWT認証 + OAuth。

### アーキテクチャ
- バックエンド: FastAPI (Python) → `/opt/tournament-api/app/`
- フロントエンド: 静的HTML/JS → `/opt/tournament-api/frontend/`
- DB: PostgreSQL（SQLAlchemy）
- 認証: JWT + デバイスID（ゲスト）+ OAuth（Google/LINE/Twitter）

### OAuth対応プロバイダー（tbt独自）
- Google / LINE / Twitter（PKCE）
- コールバック: `https://tbt.asobi.info/api/auth/callback/{provider}`
- ソーシャルアカウント: `social_accounts` テーブル（PostgreSQL）

### 主要APIエンドポイント
- `POST /api/auth/login` — デバイスIDでログイン
- `GET /api/oauth/{provider}/url` — OAuth URL生成
- `GET /api/callback/{provider}` — OAuthコールバック
- `GET /api/users/me` — プロフィール取得

---

## 共通スタイル・JS（asobi.info/assets/）

### common.css
- ヘッダー `.site-header` / `.site-logo` / `.site-nav` / `.container`
- フッター `.site-footer`
- レスポンシブ対応（768px以下でモバイルメニュー）

### common.js
- `debounce(fn, wait)` — 検索入力のデバウンス
- `API.get(path, params)` — fetch ラッパー（JSON取得）
- モバイルメニュートグル（`.site-nav.open`）
