<?php
require_once '/opt/asobi/shared/assets/php/auth.php';
require_once '/opt/asobi/shared/assets/php/version.php';
asobiRequireAdmin();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>コンテンツ構成 - 管理画面</title>
  <link rel="stylesheet" href="/assets/css/common.css?v=<?= assetVer('/assets/css/common.css') ?>">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; color: #1d2d3a; min-height: 100vh; display: flex; flex-direction: column; }
  </style>
</head>
<body>
  <?php $adminActivePage = 'content-design'; require __DIR__ . '/_sidebar.php'; ?>

  <style>
    .cd-section { margin-bottom: 32px; }
    .cd-section h2 { font-size: 1.05rem; font-weight: 700; margin-bottom: 16px; padding: 10px 14px; border-left: 4px solid #5567cc; background: #f0f2fb; border-radius: 0 6px 6px 0; color: #1d2d3a; }
    .cd-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .cd-table th { background: #f5f7fa; color: #6b7a8d; font-weight: 600; text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.04em; padding: 8px 12px; text-align: left; border-bottom: 2px solid #e0e4e8; }
    .cd-table td { padding: 10px 12px; border-bottom: 1px solid #f0f2f5; vertical-align: top; }
    .cd-table tr:hover td { background: #f9fbfc; }
    .badge { display: inline-block; font-size: 0.72rem; font-weight: 600; padding: 2px 8px; border-radius: 10px; }
    .badge-info  { background: #e8f4fd; color: #2471a3; }
    .badge-game  { background: #eaf6ee; color: #1e8449; }
    .badge-tools { background: #fdf2e9; color: #d35400; }
    .badge-base  { background: #f4f4f4; color: #555; }
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
        <tr><th>サブドメイン</th><th>種別</th><th>目的</th><th>ページ形式</th><th>ローカルパス</th></tr>
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
          <td><a href="https://aic.asobi.info/" target="_blank" style="color:#3498db;">aic.asobi.info</a></td>
          <td><span class="badge badge-tools">ツール</span></td>
          <td>AIチャット（Ollama連携）。画像生成・翻訳機能付き</td>
          <td>SPA (JS) + FastAPI</td>
          <td><code>aic/</code></td>
        </tr>
        <tr>
          <td><a href="https://tbt.asobi.info/" target="_blank" style="color:#3498db;">tbt.asobi.info</a></td>
          <td><span class="badge badge-game">オリジナルゲーム</span></td>
          <td>Tournament Battle。収益化目的のオリジナルコンテンツ</td>
          <td>SPA (JS) + FastAPI</td>
          <td><code>tbt/</code></td>
        </tr>
        <tr>
          <td><a href="https://pkq.asobi.info/" target="_blank" style="color:#3498db;">pkq.asobi.info</a></td>
          <td><span class="badge badge-info">情報サイト</span></td>
          <td>ポケモンクエスト情報。SEOで集客</td>
          <td>HTML（静的）</td>
          <td><code>pkq/</code></td>
        </tr>
        <tr>
          <td><a href="https://dbd.asobi.info/" target="_blank" style="color:#3498db;">dbd.asobi.info</a></td>
          <td><span class="badge badge-info">情報サイト</span></td>
          <td>Dead by Daylight 攻略情報。SEOで集客</td>
          <td>HTML（静的）</td>
          <td><code>dbd/</code></td>
        </tr>
        <tr>
          <td><a href="https://game.asobi.info/" target="_blank" style="color:#3498db;">game.asobi.info</a></td>
          <td><span class="badge badge-info">情報サイト</span></td>
          <td>レトロゲーム情報DB（NES/SFC/PCE/MD/MSX）。SEOで集客</td>
          <td>HTML（静的）+ PHP</td>
          <td><code>game/</code></td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="cd-section">
    <h2>コンテンツ設計方針</h2>
    <ul class="policy-list">
      <li>
        <strong>情報サイト（pkq / dbd / game）はSEO優先でHTMLを基本とする</strong><br>
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
        <tr><th>種別</th><th>場所</th><th>備考</th></tr>
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
          <td>Stable Diffusion Forge（ローカル）</td>
          <td>aicで使用。管理画面から接続確認。下記「画像生成AI連携」参照</td>
        </tr>
        <tr>
          <td>AIチャットAPI</td>
          <td>Ollama（ローカル）</td>
          <td>aicで使用。管理画面からモデル管理</td>
        </tr>
        <tr>
          <td>翻訳API</td>
          <td><code>/opt/asobi/translate/</code>（サーバー内蔵）</td>
          <td>opus-mt-ja-en (ctranslate2)。aic・image で使用。<code>localhost:5050</code> で常駐</td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="cd-section">
    <h2>ポート転送（ルーター → ローカルPC）</h2>
    <p style="font-size:0.83rem;color:#6b7a8d;margin-bottom:12px;">
      ConohaサーバーからローカルPCのサービスにアクセスするためのポート転送設定。
      外部IP: <code>153.242.124.35</code>
    </p>
    <table class="cd-table">
      <thead>
        <tr><th>サービス</th><th>外部ポート</th><th>ローカルポート</th><th>用途</th></tr>
      </thead>
      <tbody>
        <tr>
          <td>ComfyUI</td>
          <td><code>17210</code></td>
          <td><code>8000</code></td>
          <td>ワークフロー型画像・動画生成（AnimateDiff等）</td>
        </tr>
        <tr>
          <td>Forge WebUI</td>
          <td><code>17211</code></td>
          <td><code>7860</code></td>
          <td>画像生成API（aic画像生成で使用）</td>
        </tr>
        <tr>
          <td>LibreTranslate</td>
          <td><code>17212</code></td>
          <td><code>5000</code></td>
          <td>翻訳API（aicチャット翻訳で使用）</td>
        </tr>
        <tr>
          <td>Stable Diffusion</td>
          <td><code>17213</code></td>
          <td><code>1233</code></td>
          <td>SD系画像生成</td>
        </tr>
        <tr>
          <td>Ollama</td>
          <td><code>17214</code></td>
          <td><code>11434</code></td>
          <td>LLM推論API（aicチャットで使用）</td>
        </tr>
      </tbody>
    </table>
    <div class="note-box">
      このページは確認専用です。コンテンツ構成・方針が変更になった場合のみ更新します。<br>
      日常的な作業状況はTODO管理ページ（<a href="/admin/todos.php" style="color:#6d4c00;">todos.php</a>）で管理します。
    </div>
  </div>

  <div class="cd-section">
    <h2>API連携の注意事項</h2>
    <p style="font-size:0.83rem;color:#6b7a8d;margin-bottom:16px;">
      外部APIとの連携で過去にトラブルが発生した事項をまとめたもの。同じ問題を繰り返さないための備忘録。
    </p>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">🔴 過去に起きたトラブル</h3>
    <ul class="policy-list">
      <li>
        <strong>① フォルダ区切り文字がアンダースコアに変換されてしまう</strong><br>
        Forgeのモデル名にはフォルダ区切り <code>\</code> が含まれる（例: <code>A_写真風\yayoiMix_v25.safetensors</code>）。<br>
        DB保存時に <code>\</code> → <code>_</code> に正規化するが、API呼び出し時には<strong>元のバックスラッシュ付き形式に戻す逆引きが必要</strong>。<br>
        正規化したまま渡すとForge側でモデルを認識できない。
      </li>
      <li>
        <strong>② Forgeのモデル切替APIが通常のSD WebUIと異なる</strong><br>
        A1111（通常のSD WebUI）では <code>POST /sdapi/v1/options</code> でモデルがロードされるが、
        <strong>Forgeでは名前だけ変わりVRAMにロードされない</strong>。<br>
        Forgeでは <code>POST /run/checkpoint_change</code>（Gradio API）を使う必要がある。
        <code>override_settings</code>、<code>reload-checkpoint</code> 等も全て効かない。
      </li>
      <li>
        <strong>③ バックスラッシュのエスケープ問題</strong><br>
        モデル名の <code>\</code> が文字列リテラルに埋め込まれた際、特殊文字として解釈されて壊れる。<br>
        <b>発生する場所:</b>
        <ul style="margin:4px 0 4px 20px;">
          <li><b>JS文字列リテラル:</b> <code>onclick="fn('A_写真風\3Guofeng3...')"</code> → <code>\3</code> がオクタルエスケープとして解釈される。HTMLエスケープ（<code>esc()</code>）はバックスラッシュを変換しないため防げない</li>
          <li><b>Python文字列:</b> <code>\a</code>（アラーム）、<code>\n</code>（改行）として壊れる</li>
          <li><b>シェル/curl:</b> 引用符の種類によりエスケープが変わる</li>
        </ul>
        <b>対策:</b>
        <ul style="margin:4px 0 4px 20px;">
          <li><b>JS:</b> onclick属性への直接埋め込みを避け、data属性+イベントリスナーまたはJSオブジェクト経由で値を渡す（image/admin/models.phpで実際に発生→修正済み）</li>
          <li><b>Python:</b> httpx・json.dumpsを使えば自動エスケープされる</li>
          <li><b>PHP:</b> json_encode()を使えば自動エスケープされる</li>
        </ul>
      </li>
    </ul>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">画像生成AI（Forge）モデル切り替え</h3>
    <table class="cd-table">
      <thead>
        <tr><th>方法</th><th>効果</th><th>備考</th></tr>
      </thead>
      <tbody>
        <tr>
          <td><code>POST /run/checkpoint_change</code></td>
          <td style="color:#1e8449;font-weight:600;">✓ VRAMにロードされる</td>
          <td>Gradio API。<code>{"data": ["モデル名"]}</code> で呼ぶ。唯一の正しい方法</td>
        </tr>
        <tr>
          <td><code>POST /sdapi/v1/options</code></td>
          <td style="color:#c0392b;">✗ 名前だけ（ロードされない）</td>
          <td>A1111ではブロッキングでロードするが、Forgeでは非ブロッキング</td>
        </tr>
        <tr>
          <td><code>override_settings</code> in txt2img</td>
          <td style="color:#c0392b;">✗ 効果なし</td>
          <td>Forgeでは無視。常にVRAMのモデルが使われる</td>
        </tr>
        <tr>
          <td><code>reload / unload checkpoint</code></td>
          <td style="color:#c0392b;">✗ 効果なし</td>
          <td>即200を返すが何もしない</td>
        </tr>
      </tbody>
    </table>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">モデル名の正規化と逆引き</h3>
    <table class="cd-table">
      <thead>
        <tr><th>形式</th><th>例</th><th>用途</th></tr>
      </thead>
      <tbody>
        <tr>
          <td>Forge内部形式</td>
          <td><code>A_写真風\yayoiMix_v25.safetensors</code></td>
          <td>Gradio <code>/info</code> API、<code>/run/checkpoint_change</code> に渡す値</td>
        </tr>
        <tr>
          <td>DB保存形式（正規化）</td>
          <td><code>A_写真風_yayoiMix_v25</code></td>
          <td><code>sd_settings.model</code>、<code>sd_selectable_models.model_id</code></td>
        </tr>
      </tbody>
    </table>
    <div class="note-box" style="margin-top:8px;">
      <strong>正規化:</strong> <code>\ /</code> → <code>_</code>、拡張子除去（.safetensors等）、ハッシュ <code>[abc123]</code> 除去<br>
      <strong>逆引き:</strong> Gradio <code>/info</code> API の enum一覧から正規化名でマッチング → 元のForge形式を復元<br>
      <strong>注意:</strong> <code>/sdapi/v1/sd-models</code> はForgeで500エラーになることがある → <code>/info</code> APIを優先
    </div>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">その他のAPI注意事項</h3>
    <ul class="policy-list">
      <li>
        <strong>透かし処理はサーバーサイド（Pillow）で行う</strong><br>
        Forge拡張 <code>sd-webui-watermark</code> はAPI経由で動作しない。画像保存時にPillowで合成する。
      </li>
      <li>
        <strong>ControlNet APIバグ修正が必要</strong><br>
        Forge内蔵ControlNetの <code>get_input_data()</code> でAPI経由の画像入力が dict→ndarray 変換に失敗する。
        手動修正が必要。Forgeアップデート時に再適用。
      </li>
    </ul>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">利用箇所</h3>
    <table class="cd-table">
      <thead>
        <tr><th>機能</th><th>ファイル</th><th>説明</th></tr>
      </thead>
      <tbody>
        <tr>
          <td>通常画像生成</td>
          <td><code>aic/app/services/queue_worker.py</code></td>
          <td>キューベースで1件ずつ処理。モデル選択に応じて切り替え</td>
        </tr>
        <tr>
          <td>シーン画像生成</td>
          <td><code>aic/app/services/scene_image_service.py</code></td>
          <td>チャット中のステータス変化で一時画像を生成</td>
        </tr>
        <tr>
          <td>透かし処理</td>
          <td><code>aic/app/services/watermark.py</code></td>
          <td>Pillowで画像保存時にテキスト/画像透かしを合成</td>
        </tr>
        <tr>
          <td>モデル名逆引き</td>
          <td><code>aic/app/services/scene_image_service.py</code></td>
          <td><code>_resolve_model_for_options()</code> / <code>_normalize_model_name()</code></td>
        </tr>
        <tr>
          <td>画像生成（image）</td>
          <td><code>image/api/queue-worker.php</code></td>
          <td>キューベースで1件ずつ処理。Forge排他ロックでAICとの競合防止</td>
        </tr>
        <tr>
          <td>サンプル生成（image管理）</td>
          <td><code>image/admin/api.php</code></td>
          <td>モデル管理画面からのサンプル画像生成。<code>forgeGenerate()</code>経由</td>
        </tr>
        <tr>
          <td>Forge共通関数（PHP）</td>
          <td><code>image/api/db.php</code></td>
          <td><code>normalizeModelName()</code> / <code>resolveModelName()</code> / <code>switchForgeModel()</code> / <code>forgeLock()</code></td>
        </tr>
        <tr>
          <td>Forge排他ロック</td>
          <td><code>/tmp/asobi_forge.lock</code></td>
          <td>AIC（Python）とimage（PHP）が同一Forgeインスタンスへの同時リクエストを防止。<code>flock</code>ベース</td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- ===== 音声再生の実装方針 ===== -->
  <div class="cd-section">
    <h2>音声再生（TTS）の実装方針</h2>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">🎯 体感速度を最優先にした並列処理</h3>
    <p style="font-size:0.84rem;line-height:1.7;color:#3a4a5a;margin-bottom:12px;">
      チャットの送信〜AI回答〜音声再生まで、すべてのステップを可能な限り並列化し、ユーザーが「待っている」と感じる時間を最小化している。
    </p>

    <table class="cd-table">
      <thead>
        <tr><th>フェーズ</th><th>表示上の動き</th><th>裏での処理</th></tr>
      </thead>
      <tbody>
        <tr>
          <td><strong>① 送信直後</strong></td>
          <td>ユーザーのメッセージが1文字ずつゆっくり表示される</td>
          <td>AI APIへのfetchは<strong>先行送信済み</strong>（awaitしない）。<br>タイプ演出中にAIが応答を生成開始</td>
        </tr>
        <tr>
          <td><strong>② タイプ完了</strong></td>
          <td>「・・・」のtyping indicatorを表示</td>
          <td>先行fetchのPromiseをawait。すでにレスポンスが返っていれば即座にストリーミング開始</td>
        </tr>
        <tr>
          <td><strong>③ ストリーミング中</strong></td>
          <td>AIの回答テキストが1文字ずつ表示される</td>
          <td>テキストチャンクからTTSセグメントを<strong>随時抽出</strong>し、VOICEVOX合成を先行リクエスト</td>
        </tr>
        <tr>
          <td><strong>④ 音声再生</strong></td>
          <td>テキストアニメーションと音声が並行で再生</td>
          <td>再生中に<strong>次セグメントを先読み（prefetch）</strong>。セグメント間0.5秒ディレイ</td>
        </tr>
      </tbody>
    </table>

    <div class="note-box" style="margin-top:8px;">
      <strong>ポイント:</strong> ①のタイプ演出（250ms/文字・最大3秒）は、AIのレスポンス待ち時間を「自然な表示アニメーション」として錯覚させる役割を持つ。<br>
      短いメッセージなら演出が終わる前にAI応答が届き、長いメッセージなら演出自体が十分な待機時間を提供する。
    </div>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">🎭 インライン感情マーカーによる表現と早期再生</h3>
    <p style="font-size:0.84rem;line-height:1.7;color:#3a4a5a;margin-bottom:12px;">
      AIの応答テキストに<strong>ユーザーには見えない感情マーカー</strong>を埋め込ませることで、1つのメッセージ内で感情が変化する自然な会話を実現している。<br>
      同時に、このマーカーが<strong>音声セグメントの区切り</strong>として機能し、全文が返る前に音声再生を開始できる。
    </p>

    <table class="cd-table">
      <thead>
        <tr><th>項目</th><th>内容</th></tr>
      </thead>
      <tbody>
        <tr>
          <td><strong>AIへの指示</strong></td>
          <td>
            システムプロンプトに音声スタイル指示を注入（<code>chat_service.py</code>）。<br>
            キャラクターに設定されたVOICEVOXスタイル一覧を <code>{styles}</code> として埋め込む。<br>
            例: <em>「次の中から必ず1つ選択: ノーマル、嬉しい、怒り、悲しみ、ささやき」</em>
          </td>
        </tr>
        <tr>
          <td><strong>AIの出力形式</strong></td>
          <td>
            <code>[SE名]{スタイル:速度:ピッチ:抑揚:音量}セリフ</code><br>
            例: <code>[ドアをノックする]{元気:60:55:70:50}失礼します。{ノーマル:50:50:50:50}初めまして。</code><br>
            1メッセージ内で感情が変化する会話が可能（<code>{元気}…{ノーマル}…{悲しみ}…</code>）
          </td>
        </tr>
        <tr>
          <td><strong>表示処理</strong></td>
          <td>
            フロントエンドで <code>{...}</code> <code>[...]</code> マーカーをすべて除去してユーザーに表示。<br>
            管理者のみ「🧠 感情表示」トグルONで <code class="emotion-tag">emotion-tag</code> として可視化可能
          </td>
        </tr>
        <tr>
          <td><strong>早期再生への活用</strong></td>
          <td>
            ストリーミング中に次の <code>{</code> が到着した時点で<strong>前セグメントが確定</strong>。<br>
            AIの応答がまだ途中でも、確定セグメントの音声合成を即座にリクエスト → <strong>全文を待たず再生開始</strong>
          </td>
        </tr>
      </tbody>
    </table>

    <div class="note-box" style="margin-top:8px;">
      <strong>設計意図:</strong> 感情マーカーは「表現の豊かさ」と「再生速度」の二つの目的を同時に果たす。<br>
      AIが感情ごとにセリフを区切ることで、声色の変化（スタイル・パラメータ切替）と早期再生（セグメント確定による先行合成）の両方が自然に実現される。
    </div>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">🔄 音声モデル変更時のスタイル不一致対策</h3>
    <p style="font-size:0.84rem;line-height:1.7;color:#3a4a5a;margin-bottom:12px;">
      音声モデル（VOICEVOX話者）を変更すると、以前のモデルにあったスタイル名が新しいモデルに存在しない場合がある。<br>
      例: 旧モデルに「ツンデレ」スタイルがあったが、新モデルには「ノーマル」「嬉しい」しかない場合。<br>
      この不一致を<strong>フロントエンド・バックエンドの両方</strong>で多層的にフォールバックする。
    </p>

    <table class="cd-table">
      <thead>
        <tr><th>レイヤー</th><th>処理</th><th>コード</th></tr>
      </thead>
      <tbody>
        <tr>
          <td><strong>① AI出力</strong></td>
          <td>
            システムプロンプトにキャラクターの<strong>現在のスタイル一覧</strong>を毎回注入。<br>
            AIは提示されたスタイルのみ使用するため、モデル変更後は自然に新スタイルで応答
          </td>
          <td><code>chat_service.py</code><br><code>build_system_prompt()</code></td>
        </tr>
        <tr>
          <td><strong>② フロントエンド</strong></td>
          <td>
            会話のキャラクターからスタイル名→IDマップ（<code>_vvStyleMap</code>）を構築。<br>
            AIが返したスタイル名がマップに無い場合 → <strong>先頭スタイル</strong>（通常ノーマル）で代替。<br>
            マップ自体が空の場合 → <code>null</code> を送信しバックエンドに委任
          </td>
          <td><code>app.js</code><br><code>_vvStyleMap[style] ?? Object.values(_vvStyleMap)[0] ?? null</code></td>
        </tr>
        <tr>
          <td><strong>③ バックエンド</strong></td>
          <td>
            <code>style_id</code> が <code>null</code> で来た場合、キャラクターの <code>tts_styles</code> から解決。<br>
            「ノーマル」を優先検索 → 見つからなければ先頭スタイル → どちらもなければ400エラー
          </td>
          <td><code>tts.py</code><br><code>next((s for s if name=="ノーマル"), styles[0])</code></td>
        </tr>
      </tbody>
    </table>

    <div class="note-box" style="margin-top:8px;">
      <strong>過去メッセージの再生:</strong> 旧モデルのスタイル名で保存されたメッセージも、②③のフォールバックにより新モデルの既定スタイルで再生される。<br>
      声色は変わるが、エラーにはならず再生が途切れることがない。
    </div>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">ストリーミング早期TTS（リアルタイム音声合成）</h3>
    <ul class="policy-list">
      <li>
        <strong>セグメント抽出</strong><br>
        バッファに蓄積されたストリーミングテキストを <code>parseMessageSegments()</code> で解析。<br>
        次の <code>{</code> が到着 → 前セグメント確定 → VOICEVOX合成リクエスト即送信。最終チャンクでは残りを全て確定。
      </li>
      <li>
        <strong>テキストアニメーター</strong><br>
        音声再生と並列でAIの回答テキストを55ms/文字で表示。タイマーベースで音声再生を待たず独立動作。<br>
        DOM要素がなくなった場合（会話切替時）は自動で中断。
      </li>
      <li>
        <strong>先読み（prefetch）</strong><br>
        現在のセグメントを再生中に、次のVOICEセグメントの合成を先行リクエスト。<br>
        再生が終わった瞬間に次の音声が用意されている状態を目指す。
      </li>
      <li>
        <strong>キャッシュ</strong><br>
        <code>styleId|text|voiceParams</code> をキーにクライアント側でBlobキャッシュ。<br>
        同一会話内の既読メッセージ再生はサーバー通信なしで即再生。
      </li>
    </ul>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">SE（効果音）システム</h3>
    <ul class="policy-list">
      <li>
        <strong>再生:</strong> <code>/se/{name}.wav</code> からHEADリクエストで存在チェック → 存在すれば再生<br>
        <strong>未登録SE:</strong> DBの <code>se_miss_logs</code> に自動記録 → 管理画面で未実装SE一覧を確認可能
      </li>
    </ul>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">利用箇所</h3>
    <table class="cd-table">
      <thead>
        <tr><th>機能</th><th>ファイル</th><th>説明</th></tr>
      </thead>
      <tbody>
        <tr>
          <td>TTS API</td>
          <td><code>aic/app/api/tts.py</code></td>
          <td>VOICEVOX連携。音声パラメータ(0-100)→VOICEVOX値に変換</td>
        </tr>
        <tr>
          <td>フロントエンド再生</td>
          <td><code>aic/frontend/js/app.js</code></td>
          <td>並列fetch・ストリーミング早期TTS・prefetch・キャッシュ・SE再生</td>
        </tr>
        <tr>
          <td>管理設定</td>
          <td><code>aic/frontend/admin.html</code></td>
          <td>VOICEVOX接続先・TTS有効化・音声パラメータ範囲・感情/SE設定</td>
        </tr>
        <tr>
          <td>モデル設定</td>
          <td><code>aic/app/models/settings.py</code></td>
          <td>TtsVoiceModel（話者・スタイル）、AiSettings（TTS設定）</td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- ===== 翻訳システム ===== -->
  <div class="cd-section">
    <h2>翻訳システム（asobi-translate）</h2>

    <p style="font-size:0.84rem;line-height:1.7;color:#3a4a5a;margin-bottom:12px;">
      Conoha VPS 上に常駐する翻訳サービス。aic・image の両サイトが共有する。
      モデルは起動時に一度だけロードされるため、2回目以降は数十ms で応答する。
    </p>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">構成</h3>
    <table class="cd-table">
      <thead>
        <tr><th>項目</th><th>内容</th></tr>
      </thead>
      <tbody>
        <tr><td>モデル</td><td>Helsinki-NLP/opus-mt-ja-en（ctranslate2 int8量子化・75MB）</td></tr>
        <tr><td>エンジン</td><td>ctranslate2 + sentencepiece</td></tr>
        <tr><td>モデルロード</td><td>起動時のみ（systemd 常駐）。2回目以降はロード済みの状態で処理</td></tr>
        <tr><td>スクリプト</td><td><code>/opt/asobi/translate/server.py</code></td></tr>
        <tr><td>モデルファイル</td><td><code>/opt/asobi/translate/opus-mt-ja-en/</code>（model.bin, source.spm, target.spm 等）</td></tr>
        <tr><td>仮想環境</td><td><code>/opt/asobi/translate/venv/</code>（Flask, ctranslate2, sentencepiece, gunicorn）</td></tr>
        <tr><td>APIキー</td><td><code>/opt/asobi/translate/api_key.txt</code>（600権限, www-dataオーナー）</td></tr>
        <tr><td>systemd</td><td><code>asobi-translate.service</code>（gunicorn, workers=2, 自動起動）</td></tr>
        <tr><td>内部URL</td><td><code>http://127.0.0.1:5050</code></td></tr>
        <tr><td>外部URL</td><td><code>https://asobi.info/api/translate/</code>（nginx プロキシ経由）</td></tr>
      </tbody>
    </table>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">API仕様</h3>
    <table class="cd-table">
      <thead>
        <tr><th>エンドポイント</th><th>メソッド</th><th>説明</th></tr>
      </thead>
      <tbody>
        <tr>
          <td><code>/translate</code></td>
          <td>POST</td>
          <td>
            ヘッダー: <code>X-Api-Key: &lt;key&gt;</code><br>
            ボディ: <code>{"text": "...", "mode": "opus_mt", "source": "ja", "target": "en", "pipeline": false}</code><br>
            レスポンス: <code>{"translated_text": "...", "source": "opus_mt|mymemory|prompt_pipeline|original"}</code>
          </td>
        </tr>
        <tr>
          <td><code>/health</code></td>
          <td>GET</td>
          <td>サービス死活確認。<code>{"status":"ok","opus_mt":true}</code></td>
        </tr>
        <tr>
          <td><code>/dict</code></td>
          <td>GET</td>
          <td>翻訳辞書の全エントリ取得。<code>{"pre":{...},"post":{...}}</code></td>
        </tr>
        <tr>
          <td><code>/dict/&lt;pre|post&gt;</code></td>
          <td>POST</td>
          <td>辞書エントリ追加。ボディ: <code>{"key":"...","value":"..."}</code></td>
        </tr>
        <tr>
          <td><code>/dict/&lt;pre|post&gt;/&lt;key&gt;</code></td>
          <td>DELETE</td>
          <td>辞書エントリ削除</td>
        </tr>
      </tbody>
    </table>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">翻訳モード（modeパラメーター）</h3>
    <table class="cd-table">
      <thead>
        <tr><th>mode</th><th>動作</th></tr>
      </thead>
      <tbody>
        <tr><td><code>opus_mt</code></td><td>サーバー内蔵モデルのみ。外部通信なし</td></tr>
        <tr><td><code>opus_mt_first</code></td><td>opus_mt → 失敗時 MyMemory にフォールバック</td></tr>
        <tr><td><code>mymemory</code></td><td>MyMemory API（無料・1日1000req）のみ</td></tr>
        <tr><td><code>mymemory_first</code></td><td>MyMemory → 失敗時 opus_mt にフォールバック</td></tr>
      </tbody>
    </table>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">pipelineパラメーター（画像生成プロンプト向け）</h3>
    <p style="font-size:0.83rem;line-height:1.7;color:#3a4a5a;margin-bottom:8px;">
      <code>pipeline: true</code> を指定すると、プロンプト向けの補正翻訳パイプラインが有効になる。
      画像生成系（aic・image）はすべて <code>pipeline: true</code> で呼び出している。
    </p>
    <table class="cd-table">
      <thead>
        <tr><th>pipeline</th><th>動作</th></tr>
      </thead>
      <tbody>
        <tr>
          <td><code>false</code>（デフォルト）</td>
          <td>テキスト全体をそのままエンジンへ渡す。チャット・自然文向け</td>
        </tr>
        <tr>
          <td><code>true</code></td>
          <td>
            ① <strong>改行を保持</strong>したまま行単位に分割（SD の優先度グループを維持）<br>
            ② 各行内の区切り文字（、。・）を半角カンマに統一してトークン化<br>
            ③ <strong>事前辞書</strong>でヒットした語はエンジンを通さず直接置換<br>
            ④ 日本語を含むトークンのみ翻訳エンジンへ（英語はそのまま通す）<br>
            ⑤ <strong>事後辞書</strong>でエンジン出力を補正<br>
            ⑥ 行内をカンマ結合・行間を改行で結合して出力
          </td>
        </tr>
      </tbody>
    </table>
    <div class="note-box" style="margin-top:8px;">
      <strong>改行と優先度:</strong> Stable Diffusion（Forge）では同一行内のトークンが互いに強く影響し合い、異なる行のトークンは独立した優先度グループになる。
      pipeline モードは改行を保持するため、ユーザーが意図した優先度グループがそのまま Forge に伝わる。<br>
      <strong>辞書管理:</strong> <a href="/admin/translate-dict.php" style="color:#5567cc;">翻訳辞書ページ</a> で事前・事後辞書の登録・削除が可能。
    </div>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">各サービスの設定場所</h3>
    <table class="cd-table">
      <thead>
        <tr><th>サービス</th><th>設定場所</th><th>使用方法</th></tr>
      </thead>
      <tbody>
        <tr>
          <td>image.asobi.info</td>
          <td><a href="https://image.asobi.info/admin/settings.php" target="_blank" style="color:#3498db;">管理画面 → 翻訳設定</a></td>
          <td>翻訳モードを選択して保存。<code>translate.php</code> が <code>localhost:5050</code> に中継</td>
        </tr>
        <tr>
          <td>aic.asobi.info</td>
          <td><a href="https://aic.asobi.info/admin.html" target="_blank" style="color:#3498db;">管理画面 → 翻訳設定</a></td>
          <td>翻訳モードで「opus-mt（サーバー内蔵）のみ」を選択</td>
        </tr>
        <tr>
          <td>外部サーバー</td>
          <td>—</td>
          <td><code>https://asobi.info/api/translate/translate</code> に <code>X-Api-Key</code> ヘッダーを付けてPOST</td>
        </tr>
      </tbody>
    </table>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">サービス管理コマンド</h3>
    <pre style="background:#f5f7fa;padding:12px 16px;border-radius:8px;font-size:0.82rem;color:#3a4a5a;overflow-x:auto;">systemctl status asobi-translate    # 状態確認
systemctl restart asobi-translate   # 再起動
journalctl -u asobi-translate -n 50 # ログ確認</pre>

    <div class="note-box">
      <strong>APIキーの確認:</strong> <code>cat /opt/asobi/translate/api_key.txt</code><br>
      <strong>モデル変換元:</strong> Windows PC の <code>D:\ai\libretranslate\venv</code> で <code>ct2-transformers-converter</code> を使用して変換し、サーバーにアップロード。<br>
      <strong>技術的なポイント:</strong> MarianMT (opus-mt) は ctranslate2 で翻訳する際、ソーストークン末尾に <code>&lt;/s&gt;</code> を付加しないとデコーダーが正しく停止しない。
    </div>
  </div>

  <!-- ===== TODO管理ワークフロー ===== -->
  <div class="cd-section">
    <h2>TODO管理ワークフロー（Claude連携）</h2>

    <p style="font-size:0.84rem;line-height:1.7;color:#3a4a5a;margin-bottom:12px;">
      複数サイトの管理をAPI経由で一元化。Claudeが自動でTODOを処理し、ステータスを遷移させる。
    </p>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">APIエンドポイント</h3>
    <table class="cd-table">
      <thead>
        <tr><th>メソッド</th><th>action</th><th>説明</th></tr>
      </thead>
      <tbody>
        <tr><td>GET</td><td>list</td><td>一覧取得（site/area/statusフィルター対応）</td></tr>
        <tr><td>GET</td><td>get</td><td>単一取得（id指定）</td></tr>
        <tr><td>POST</td><td>add</td><td>新規追加</td></tr>
        <tr><td>POST</td><td>update_status</td><td>ステータス更新（<strong>確認待ちはstatus_note必須</strong>）</td></tr>
        <tr><td>POST</td><td>update_note</td><td>対応メモ更新</td></tr>
        <tr><td>POST</td><td>update_result</td><td>確認結果更新（自動遷移あり）</td></tr>
        <tr><td>POST</td><td>hold_answer</td><td>保留解除回答（→未着手に自動遷移）</td></tr>
        <tr><td>POST</td><td>reset_stale</td><td>放置「対応中」を「未着手」に戻す</td></tr>
      </tbody>
    </table>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">ステータスフロー</h3>
    <pre style="background:#f5f7fa;padding:12px 16px;border-radius:8px;font-size:0.82rem;color:#3a4a5a;overflow-x:auto;">未着手 → 対応中 → 確認待ち → 完了
                      ↓
                    保留 → 未着手（hold_answerで戻る）
                    NG  → 未着手（update_resultで戻る）</pre>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#e74c3c;">⚠️ APIバリデーション（仕組みによる強制ルール）</h3>
    <p style="font-size:0.84rem;line-height:1.7;color:#3a4a5a;margin-bottom:8px;">
      以下のルールはAPIバリデーションで強制されている。指示やメモリではなく、APIコード（<code>info/api/todos.php</code>）のバリデーションにより物理的に違反できない。
    </p>
    <table class="cd-table">
      <thead>
        <tr><th>ルール</th><th>実装</th><th>経緯</th></tr>
      </thead>
      <tbody>
        <tr>
          <td><strong>確認待ちへの遷移にはstatus_note（対応メモ）が必須</strong></td>
          <td><code>update_status</code> で <code>status=確認待ち</code> の場合、<code>status_note</code> が空だと<strong>400エラー</strong>で拒否</td>
          <td>対応メモなしの確認待ち遷移が数十回繰り返し発生したため、仕組みで強制</td>
        </tr>
        <tr>
          <td><strong>対応メモは10文字以上</strong></td>
          <td>10文字未満の<code>status_note</code>は<strong>400エラー</strong>で拒否</td>
          <td>「OK」「完了」等の内容のないメモを防止</td>
        </tr>
      </tbody>
    </table>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">Claudeの処理フロー</h3>
    <ol style="font-size:0.84rem;line-height:1.9;color:#3a4a5a;padding-left:20px;">
      <li>セッション開始時に <code>action=reset_stale</code>（60分以上放置の対応中→未着手に戻す）</li>
      <li><code>action=list&amp;status=未着手</code> で一覧取得、優先度順に選択</li>
      <li><code>action=update_status</code> で <code>status=対応中</code> に変更</li>
      <li>実装作業を実施</li>
      <li><code>action=update_status</code> で <code>status=確認待ち</code> + <code>status_note=対応内容</code> を<strong>同時に送信</strong>
        <br>→ status_noteなしだとAPIが400エラーを返すため、省略は不可能</li>
      <li>次のTODOに即座に進む（確認待ちになったらユーザーの確認を待たない）</li>
    </ol>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">対応メモ（status_note）の書き方</h3>
    <ul style="font-size:0.84rem;line-height:1.9;color:#3a4a5a;padding-left:20px;">
      <li>変更ファイル名・変更箇所・実装内容を<strong>具体的</strong>に記述</li>
      <li>管理者が読んで何をしたか完全に把握できるレベルで書く</li>
      <li>「OK」「完了」「対応しました」等の内容のないメモはAPIが拒否する</li>
    </ul>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">自動遷移ルール</h3>
    <table class="cd-table">
      <thead>
        <tr><th>トリガー</th><th>遷移</th><th>備考</th></tr>
      </thead>
      <tbody>
        <tr><td>確認結果OK</td><td>→ 完了</td><td>completed_at記録</td></tr>
        <tr><td>確認待ち + NG</td><td>→ 未着手</td><td>対応メモにNG理由自動追記、確認結果リセット</td></tr>
        <tr><td>保留解除回答入力</td><td>→ 未着手</td><td>hold_answerで回答を記録</td></tr>
        <tr><td>対応中への遷移</td><td>started_at記録</td><td>COALESCE: 初回のみ</td></tr>
      </tbody>
    </table>

    <h3 style="font-size:0.9rem;margin:16px 0 8px;color:#1d2d3a;">利用箇所</h3>
    <table class="cd-table">
      <thead>
        <tr><th>機能</th><th>ファイル</th><th>説明</th></tr>
      </thead>
      <tbody>
        <tr><td>TODO API</td><td><code>info/api/todos.php</code></td><td>CRUD + 自動遷移 + バリデーション（確認待ちstatus_note強制）</td></tr>
        <tr><td>API認証</td><td><code>shared/assets/php/api_auth.php</code></td><td>Bearerトークン認証</td></tr>
        <tr><td>管理画面</td><td><code>info/admin/todos.php</code></td><td>フィルター・編集・確認結果入力</td></tr>
        <tr><td>DBスキーマ</td><td><code>shared/assets/php/users_db.php</code></td><td>content_todosテーブル定義</td></tr>
      </tbody>
    </table>
  </div>

    </main>
  </div>
  <script src="/assets/js/common.js?v=<?= assetVer('/assets/js/common.js') ?>"></script>
</body>
</html>
