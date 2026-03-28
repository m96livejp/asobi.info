<?php
$pageTitle = 'コンテンツ構成';
require_once __DIR__ . '/_sidebar.php';
?>
<style>
  .cd-section { margin-bottom: 32px; }
  .cd-section h2 { font-size: 1rem; font-weight: 700; margin-bottom: 14px; padding-bottom: 8px; border-bottom: 2px solid #e0e4e8; color: #1d2d3a; }
  .cd-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
  .cd-table th { background: #f5f7fa; color: #6b7a8d; font-weight: 600; text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.04em; padding: 8px 12px; text-align: left; border-bottom: 2px solid #e0e4e8; }
  .cd-table td { padding: 10px 12px; border-bottom: 1px solid #f0f2f5; vertical-align: top; }
  .cd-table tr:hover td { background: #f9fbfc; }
  .badge { display: inline-block; font-size: 0.72rem; font-weight: 600; padding: 2px 8px; border-radius: 10px; }
  .badge-info { background: #e8f4fd; color: #2471a3; }
  .badge-game { background: #eaf6ee; color: #1e8449; }
  .badge-tools { background: #fdf2e9; color: #d35400; }
  .badge-wip { background: #fef9e7; color: #b7950b; }
  .badge-base { background: #f4f4f4; color: #555; }
  .policy-list { list-style: none; padding: 0; }
  .policy-list li { padding: 9px 14px; border-left: 3px solid #5567cc; margin-bottom: 8px; background: #f8f9fe; border-radius: 0 6px 6px 0; font-size: 0.87rem; line-height: 1.6; }
  .policy-list li strong { color: #3a4cc0; }
  .note-box { background: #fffde7; border: 1px solid #ffe082; border-radius: 8px; padding: 12px 16px; font-size: 0.83rem; color: #6d4c00; line-height: 1.7; margin-top: 12px; }
  code { background: #f0f2f5; padding: 1px 5px; border-radius: 3px; font-size: 0.82rem; font-family: monospace; }
</style>

<div class="page-title">コンテンツ構成</div>
<p style="font-size:0.85rem;color:#6b7a8d;margin-bottom:24px;">
  サイト全体のコンテンツ構成・方針の確認ページです。構成や方針が変わらない限り更新しません。
</p>

<div class="cd-section">
  <h2>サイト一覧</h2>
  <table class="cd-table">
    <thead>
      <tr>
        <th>サブドメイン</th>
        <th>種別</th>
        <th>目的</th>
        <th>ページ形式</th>
        <th>ローカルパス</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><a href="https://asobi.info/" target="_blank" style="color:#3498db;">asobi.info</a></td>
        <td><span class="badge badge-base">ポータル</span></td>
        <td>各サブコンテンツへのハブ、共通認証・管理</td>
        <td>PHP</td>
        <td><code>info/</code></td>
      </tr>
      <tr>
        <td><a href="https://dbd.asobi.info/" target="_blank" style="color:#3498db;">dbd.asobi.info</a></td>
        <td><span class="badge badge-info">情報サイト</span></td>
        <td>Dead by Daylight 攻略情報。SEOで集客</td>
        <td>HTML（静的）</td>
        <td><code>dbd/</code></td>
      </tr>
      <tr>
        <td><a href="https://pkq.asobi.info/" target="_blank" style="color:#3498db;">pkq.asobi.info</a></td>
        <td><span class="badge badge-info">情報サイト</span></td>
        <td>ポケモンクエスト情報。SEOで集客</td>
        <td>HTML（静的）</td>
        <td><code>pkq/</code></td>
      </tr>
      <tr>
        <td><a href="https://game.asobi.info/" target="_blank" style="color:#3498db;">game.asobi.info</a></td>
        <td><span class="badge badge-info">情報サイト</span></td>
        <td>レトロゲーム情報DB（NES/SFC/PCE/MD/MSX）。SEOで集客</td>
        <td>HTML（静的）+ PHP</td>
        <td><code>game/</code></td>
      </tr>
      <tr>
        <td><a href="https://tbt.asobi.info/" target="_blank" style="color:#3498db;">tbt.asobi.info</a></td>
        <td><span class="badge badge-game">オリジナルゲーム</span></td>
        <td>Tournament Battle。収益化目的のオリジナルコンテンツ</td>
        <td>SPA (JS) + FastAPI</td>
        <td><code>tbt/</code></td>
      </tr>
      <tr>
        <td><a href="https://aic.asobi.info/" target="_blank" style="color:#3498db;">aic.asobi.info</a></td>
        <td><span class="badge badge-tools">ツール</span></td>
        <td>AIチャット（Ollama連携）。画像生成・翻訳機能付き</td>
        <td>SPA (JS) + FastAPI</td>
        <td><code>aic/</code></td>
      </tr>
    </tbody>
  </table>
</div>

<div class="cd-section">
  <h2>コンテンツ設計方針</h2>
  <ul class="policy-list">
    <li>
      <strong>情報サイト（dbd / pkq / game）はSEO優先でHTMLを基本とする</strong><br>
      ゲームデータはHTMLページとして出力。クローラーへの蓄積・インデックスに有利なため。
      JavaScriptで動的に生成するのではなく、可能な限り静的HTML（またはSSR PHP）で提供する。
    </li>
    <li>
      <strong>tbtは収益化優先で開発する</strong><br>
      オリジナルゲームとしてユーザー獲得・収益（広告・課金）が目標。
      機能追加の優先度判断時は tbt の収益化目標を考慮する。
    </li>
    <li>
      <strong>認証はasobi.info共通のPHP+SQLite認証を全サイトで使用する</strong><br>
      右上ユーザーメニューはcommon.jsで統一。新規コンテンツ追加時も同じ仕組みを使う。
      tbt/aicのみJWT+FastAPIのため、クロスサイトトークン発行で連携している。
    </li>
    <li>
      <strong>コメント・投稿は管理者のみ即時公開、一般ユーザーは審査後公開</strong><br>
      game.asobi.info / info/contact.php などの投稿フォームはすべてこのポリシーを適用する。
    </li>
    <li>
      <strong>ページ追加時はWebフォント実装ルールを守る</strong><br>
      bodyにフォントを適用せず、mainにのみ適用。font.phpをlink rel=stylesheetで読み込む。
      詳細は <a href="/admin/font.php" style="color:#5567cc;">フォント設定ページ</a> を参照。
    </li>
    <li>
      <strong>alert/confirm/prompt は全サイト使用禁止</strong><br>
      代替: インラインエラー表示 / 右下トースト通知 / 中央オーバーレイダイアログ（UI.confirmDanger等）
    </li>
  </ul>
</div>

<div class="cd-section">
  <h2>共通インフラ</h2>
  <table class="cd-table">
    <thead>
      <tr>
        <th>種別</th>
        <th>場所</th>
        <th>備考</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>共通CSS/JS</td>
        <td><code>shared/assets/css/</code>, <code>shared/assets/js/</code></td>
        <td>ヘッダー、フッター、ユーザーメニュー等</td>
      </tr>
      <tr>
        <td>共通認証</td>
        <td><code>shared/assets/php/auth.php</code></td>
        <td>PHP+SQLiteセッション認証。全PHPサイト共用</td>
      </tr>
      <tr>
        <td>ユーザーDB</td>
        <td><code>/opt/asobi/data/users.sqlite</code></td>
        <td>ユーザー・ソーシャル連携・アクセスログ・設定</td>
      </tr>
      <tr>
        <td>Webフォント</td>
        <td><code>shared/assets/fonts/</code></td>
        <td>Migu 1C（デフォルト）/ 1P / 1M / 2M。WOFF2+TTF</td>
      </tr>
      <tr>
        <td>画像生成API</td>
        <td>Stable Diffusion（ローカル）</td>
        <td>aicで使用。管理画面から接続確認</td>
      </tr>
      <tr>
        <td>AIチャットAPI</td>
        <td>Ollama（ローカル）</td>
        <td>aicで使用。管理画面からモデル管理</td>
      </tr>
      <tr>
        <td>翻訳API</td>
        <td>LibreTranslate（ローカル）</td>
        <td>aicで使用</td>
      </tr>
    </tbody>
  </table>
  <div class="note-box">
    このページは確認専用です。コンテンツ構成・方針が変更になった場合のみ更新します。<br>
    日常的な作業状況はTODO管理ページ（<a href="/admin/todos.php" style="color:#6d4c00;">todos.php</a>）で管理します。
  </div>
</div>

    </main>
  </div>
</body>
</html>
