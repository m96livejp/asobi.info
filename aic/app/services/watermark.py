"""画像透かし処理"""
import os
from PIL import Image, ImageDraw, ImageFont, ImageEnhance


def apply_watermark(image_path: str, text: str | None = None, image_wm_path: str | None = None,
                    opacity: float = 0.3, scale: float = 0.15, margin: int = 10):
    """画像ファイルに透かしを適用して上書き保存する。

    Args:
        image_path: 対象画像ファイルパス
        text: テキスト透かし（Noneなら省略）
        image_wm_path: 画像透かしファイルパス（Noneなら省略）
        opacity: 透明度 0.0〜1.0
        scale: 画像サイズに対する透かしサイズ比率
        margin: 右下からのマージン（px）
    """
    if not text and not image_wm_path:
        return

    base = Image.open(image_path).convert("RGBA")
    overlay = Image.new("RGBA", base.size, (0, 0, 0, 0))
    bw, bh = base.size

    # 画像透かし（優先）
    if image_wm_path and os.path.isfile(image_wm_path):
        wm_img = Image.open(image_wm_path).convert("RGBA")
        # スケーリング
        target_w = int(bw * scale)
        ratio = target_w / wm_img.width
        target_h = int(wm_img.height * ratio)
        wm_img = wm_img.resize((target_w, target_h), Image.LANCZOS)
        # 透明度適用
        alpha = wm_img.split()[3]
        alpha = ImageEnhance.Brightness(alpha).enhance(opacity)
        wm_img.putalpha(alpha)
        # 右下に配置
        x = bw - target_w - margin
        y = bh - target_h - margin
        overlay.paste(wm_img, (x, y), wm_img)

    # テキスト透かし（画像透かしの上に重ねるか、画像透かしがなければ単独）
    if text:
        draw = ImageDraw.Draw(overlay)
        # フォントサイズを画像サイズに合わせる
        font_size = max(12, int(bh * scale * 0.5))
        try:
            # サーバー上のフォントを試す
            for fp in ["/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
                       "/usr/share/fonts/truetype/noto/NotoSansCJK-Bold.ttc",
                       "/opt/asobi/shared/assets/fonts/migu-1c-bold.ttf"]:
                if os.path.isfile(fp):
                    font = ImageFont.truetype(fp, font_size)
                    break
            else:
                font = ImageFont.load_default()
        except Exception:
            font = ImageFont.load_default()

        # テキストサイズ計測
        bbox = draw.textbbox((0, 0), text, font=font)
        tw = bbox[2] - bbox[0]
        th = bbox[3] - bbox[1]

        # 画像透かしがある場合はその上に配置、なければ右下
        if image_wm_path and os.path.isfile(image_wm_path):
            tx = bw - tw - margin
            ty = bh - target_h - th - margin * 2  # 画像透かしの上
        else:
            tx = bw - tw - margin
            ty = bh - th - margin

        # 影（読みやすさ向上）
        alpha_val = int(255 * opacity)
        draw.text((tx + 1, ty + 1), text, fill=(0, 0, 0, alpha_val), font=font)
        draw.text((tx, ty), text, fill=(255, 255, 255, alpha_val), font=font)

    # 合成して保存
    result = Image.alpha_composite(base, overlay)
    # PNGとして保存（元の形式を維持）
    ext = os.path.splitext(image_path)[1].lower()
    if ext in ('.jpg', '.jpeg'):
        result = result.convert("RGB")
        result.save(image_path, "JPEG", quality=95)
    else:
        result.save(image_path, "PNG")
