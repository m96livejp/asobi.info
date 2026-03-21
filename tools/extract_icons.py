"""
DbD スクリーンショットからアイコンを切り出すスクリプト
4K (3840x2160) のゲーム画面キャプチャから個別アイコンを抽出
"""
import os
import sys
from pathlib import Path
from PIL import Image
import hashlib

# === 設定 ===
CAPTURES_DIR = Path(r"C:\Users\PC_user\OneDrive\Videos\Captures")
OUTPUT_BASE = Path(r"G:\マイドライブ\claude\asobi.info\public_html\dbd\images")
OUTPUT_SIZE = (128, 128)  # Web表示用サイズ

# === キャラクターポートレート グリッド座標 (4K) ===
CHAR_GRID = {
    "origin_x": 515,
    "origin_y": 555,
    "cell_w": 315,
    "cell_h": 375,
    "col_gap": 15,
    "row_gap": 25,
    "cols": 4,
    "rows": 3,
}

# === パークアイコン ダイヤモンドグリッド座標 (4K) ===
# パークは45度回転したダイヤモンド配置
# 奇数行(0,2)は5個、偶数行(1)は4個でオフセット
# 1ページあたり3行(5+4+5=14個)表示
PERK_GRID = {
    "origin_x": 780,
    "origin_y": 1290,
    "cell_size": 170,    # クロップサイズ(片側85px)
    "col_spacing": 250,  # 列間隔
    "row_spacing": 190,  # 行間隔
    "cols_odd": 5,       # 奇数行の列数(row 0, 2)
    "cols_even": 4,      # 偶数行の列数(row 1)
    "even_offset_x": 125,  # 偶数行のXオフセット(col_spacing/2)
    "rows": 3,
}

# === オファリングアイコン 六角形グリッド座標 (4K) ===
# パークと同じUIレイアウト(5-4-5の千鳥配置)
OFFERING_GRID = {
    "origin_x": 780,
    "origin_y": 1290,
    "cell_size": 170,
    "col_spacing": 250,
    "row_spacing": 190,
    "cols_odd": 5,
    "cols_even": 4,
    "even_offset_x": 125,
    "rows": 3,
}

# === アドオングリッド座標 (4K) ===
# アドオンは通常の矩形グリッド（5列 x 3行/ページ）
ADDON_GRID = {
    "origin_x": 836,
    "origin_y": 1253,
    "cell_size": 156,    # クロップサイズ(片側78px)
    "col_spacing": 255,  # 列間隔
    "row_spacing": 224,  # 行間隔
    "cols": 5,
    "rows": 3,
}


def image_hash(img):
    """簡易画像ハッシュ（重複検出用）"""
    small = img.resize((16, 16), Image.LANCZOS).convert("L")
    pixels = list(small.getdata())
    avg = sum(pixels) / len(pixels)
    return "".join("1" if p > avg else "0" for p in pixels)


def hamming_distance(h1, h2):
    """2つのハッシュ間のハミング距離"""
    return sum(c1 != c2 for c1, c2 in zip(h1, h2))


def is_duplicate(h, seen_hashes, threshold=30):
    """既存ハッシュとの類似度で重複判定（閾値以下なら重複）"""
    for existing in seen_hashes:
        if hamming_distance(h, existing) < threshold:
            return True
    return False


def has_enough_detail(img, min_std=15):
    """画像に十分なディテールがあるか（枠線のみ等を除外）"""
    small = img.resize((32, 32), Image.LANCZOS).convert("L")
    pixels = list(small.getdata())
    avg = sum(pixels) / len(pixels)
    variance = sum((p - avg) ** 2 for p in pixels) / len(pixels)
    return variance ** 0.5 > min_std


def is_mostly_dark(img, threshold=30):
    """画像がほぼ真っ暗（空スロット）かチェック"""
    small = img.resize((32, 32), Image.LANCZOS).convert("L")
    avg = sum(small.getdata()) / (32 * 32)
    return avg < threshold


def extract_character_portraits(folder_name, output_subdir):
    """キャラクターポートレートの切り出し（矩形グリッド）"""
    src_dir = CAPTURES_DIR / folder_name
    out_dir = OUTPUT_BASE / output_subdir
    out_dir.mkdir(parents=True, exist_ok=True)

    screenshots = sorted(src_dir.glob("*.png"))
    seen_hashes = set()
    count = 0
    g = CHAR_GRID

    for ss_path in screenshots:
        img = Image.open(ss_path)
        for row in range(g["rows"]):
            for col in range(g["cols"]):
                x = g["origin_x"] + col * (g["cell_w"] + g["col_gap"])
                y = g["origin_y"] + row * (g["cell_h"] + g["row_gap"])
                crop = img.crop((x, y, x + g["cell_w"], y + g["cell_h"]))

                # 空スロット / ディテール不足をスキップ
                if is_mostly_dark(crop, threshold=25):
                    continue
                if not has_enough_detail(crop, min_std=20):
                    continue

                # 重複チェック（中央部分のハッシュで類似判定）
                # スクロールで位置がずれても中央は同じキャラ
                w, h_img = crop.size
                margin_x, margin_y = w // 4, h_img // 4
                center = crop.crop((margin_x, margin_y, w - margin_x, h_img - margin_y))
                h = image_hash(center)
                if is_duplicate(h, seen_hashes, threshold=40):
                    continue
                seen_hashes.add(h)

                count += 1
                resized = crop.resize(OUTPUT_SIZE, Image.LANCZOS)
                filename = f"char_{count:03d}.png"
                resized.save(out_dir / filename)

    print(f"  {output_subdir}: {count} portraits extracted")
    return count


def extract_perk_icons(folder_name, output_subdir, grid=None):
    """パークアイコンの切り出し（ダイヤモンドグリッド）"""
    src_dir = CAPTURES_DIR / folder_name
    out_dir = OUTPUT_BASE / output_subdir
    out_dir.mkdir(parents=True, exist_ok=True)

    if grid is None:
        grid = PERK_GRID

    screenshots = sorted(src_dir.glob("*.png"))
    seen_hashes = set()
    count = 0
    g = grid

    for ss_path in screenshots:
        img = Image.open(ss_path)
        for row in range(g["rows"]):
            is_even_row = (row % 2 == 1)
            num_cols = g["cols_even"] if is_even_row else g["cols_odd"]
            x_offset = g["even_offset_x"] if is_even_row else 0

            for col in range(num_cols):
                cx = g["origin_x"] + x_offset + col * g["col_spacing"]
                cy = g["origin_y"] + row * g["row_spacing"]
                half = g["cell_size"] // 2

                # ダイヤモンドのバウンディングボックスをクロップ
                x1 = cx - half
                y1 = cy - half
                x2 = cx + half
                y2 = cy + half

                if x1 < 0 or y1 < 0 or x2 > img.width or y2 > img.height:
                    continue

                crop = img.crop((x1, y1, x2, y2))

                if is_mostly_dark(crop, threshold=20):
                    continue
                if not has_enough_detail(crop, min_std=15):
                    continue

                h = image_hash(crop)
                if is_duplicate(h, seen_hashes, threshold=30):
                    continue
                seen_hashes.add(h)

                count += 1
                resized = crop.resize(OUTPUT_SIZE, Image.LANCZOS)
                filename = f"icon_{count:03d}.png"
                resized.save(out_dir / filename)

    print(f"  {output_subdir}: {count} icons extracted")
    return count


def extract_offering_icons(folder_name, output_subdir):
    """オファリングアイコンの切り出し"""
    return extract_perk_icons(folder_name, output_subdir, grid=OFFERING_GRID)


def extract_addon_icons(folder_name, output_subdir):
    """アドオンアイコンの切り出し（矩形グリッド）"""
    src_dir = CAPTURES_DIR / folder_name
    out_dir = OUTPUT_BASE / output_subdir
    out_dir.mkdir(parents=True, exist_ok=True)

    screenshots = sorted(src_dir.glob("*.png"))
    seen_hashes = set()
    count = 0
    g = ADDON_GRID

    for ss_path in screenshots:
        img = Image.open(ss_path)
        for row in range(g["rows"]):
            for col in range(g["cols"]):
                cx = g["origin_x"] + col * g["col_spacing"]
                cy = g["origin_y"] + row * g["row_spacing"]
                half = g["cell_size"] // 2

                x1 = cx - half
                y1 = cy - half
                x2 = cx + half
                y2 = cy + half

                if x1 < 0 or y1 < 0 or x2 > img.width or y2 > img.height:
                    continue

                crop = img.crop((x1, y1, x2, y2))

                if is_mostly_dark(crop, threshold=20):
                    continue
                if not has_enough_detail(crop, min_std=15):
                    continue

                h = image_hash(crop)
                if is_duplicate(h, seen_hashes, threshold=30):
                    continue
                seen_hashes.add(h)

                count += 1
                resized = crop.resize(OUTPUT_SIZE, Image.LANCZOS)
                filename = f"icon_{count:03d}.png"
                resized.save(out_dir / filename)

    print(f"  {output_subdir}: {count} icons extracted")
    return count


def main():
    print("=== DbD アイコン切り出し ===\n")

    # キャラクターポートレート
    print("[キラー一覧]")
    extract_character_portraits("キラー一覧", "characters/killer")

    print("[サバイバー一覧]")
    extract_character_portraits("サバイバー一覧", "characters/survivor")

    # パークアイコン
    print("[キラーパーク]")
    extract_perk_icons("キラーパーク一覧", "perks/killer")

    print("[サバイバーパーク]")
    extract_perk_icons("サバイバーパーク一覧", "perks/survivor")

    # オファリング
    print("[キラーオファリング]")
    extract_offering_icons("キラーオファリング", "offerings")

    print("[サバイバーオファリング]")
    extract_offering_icons("サバイバーオファリング", "offerings")

    # アドオン
    print("[サバイバーアドオン]")
    extract_addon_icons("サバイバーアドオン", "addons")

    print("\n=== 完了 ===")


if __name__ == "__main__":
    main()
