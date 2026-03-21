-- DbD オファリング(供物)データ
-- カテゴリ: bp_increase, fog, luck, map_selection, memento_mori, charm, hook, chest, hatch, basement, spawn, event
-- レアリティ: common, uncommon, rare, very_rare, ultra_rare, event

DELETE FROM offerings;

-- === BP増加: キラー用 (処刑カテゴリ) ===
INSERT INTO offerings (name, role, category, rarity, description, sort_order) VALUES
('熱烈なモズのリース', 'killer', 'bp_increase', 'rare', '処刑カテゴリーのBPを100%追加する', 10),
('信心深いモズのリース', 'killer', 'bp_increase', 'uncommon', '処刑カテゴリーのBPを75%追加する', 11),
('モズのリース', 'killer', 'bp_increase', 'common', '処刑カテゴリーのBPを50%追加する', 12);

-- === BP増加: キラー用 (狩猟カテゴリ) ===
INSERT INTO offerings (name, role, category, rarity, description, sort_order) VALUES
('熱烈なフクロウのリース', 'killer', 'bp_increase', 'rare', '狩猟カテゴリーのBPを100%追加する', 20),
('信心深いフクロウのリース', 'killer', 'bp_increase', 'uncommon', '狩猟カテゴリーのBPを75%追加する', 21),
('フクロウのリース', 'killer', 'bp_increase', 'common', '狩猟カテゴリーのBPを50%追加する', 22);

-- === BP増加: キラー用 (残虐カテゴリ) ===
INSERT INTO offerings (name, role, category, rarity, description, sort_order) VALUES
('熱烈なフウキンチョウのリース', 'killer', 'bp_increase', 'rare', '残虐カテゴリーのBPを100%追加する', 30),
('信心深いフウキンチョウのリース', 'killer', 'bp_increase', 'uncommon', '残虐カテゴリーのBPを75%追加する', 31),
('フウキンチョウのリース', 'killer', 'bp_increase', 'common', '残虐カテゴリーのBPを50%追加する', 32);

-- === BP増加: キラー用 (妨害カテゴリ) ===
INSERT INTO offerings (name, role, category, rarity, description, sort_order) VALUES
('熱烈なカラスのリース', 'killer', 'bp_increase', 'rare', '妨害カテゴリーのBPを100%追加する', 40),
('信心深いカラスのリース', 'killer', 'bp_increase', 'uncommon', '妨害カテゴリーのBPを75%追加する', 41),
('カラスのリース', 'killer', 'bp_increase', 'common', '妨害カテゴリーのBPを50%追加する', 42);

-- === BP増加: サバイバー用 (生存カテゴリ) ===
INSERT INTO offerings (name, role, category, rarity, description, sort_order) VALUES
('いい香りのアマランサス', 'survivor', 'bp_increase', 'rare', '生存カテゴリーのBPを100%追加する', 50),
('新鮮なアマランサス', 'survivor', 'bp_increase', 'uncommon', '生存カテゴリーのBPを75%追加する', 51),
('アマランサスの匂い袋', 'survivor', 'bp_increase', 'common', '生存カテゴリーのBPを50%追加する', 52);

-- === BP増加: サバイバー用 (目標カテゴリ) ===
INSERT INTO offerings (name, role, category, rarity, description, sort_order) VALUES
('いい香りのイチゲサクラソウ', 'survivor', 'bp_increase', 'rare', '目標カテゴリーのBPを100%追加する', 60),
('新鮮なイチゲサクラソウ', 'survivor', 'bp_increase', 'uncommon', '目標カテゴリーのBPを75%追加する', 61),
('イチゲサクラソウの香り袋', 'survivor', 'bp_increase', 'common', '目標カテゴリーのBPを50%追加する', 62);

-- === BP増加: サバイバー用 (大胆カテゴリ) ===
INSERT INTO offerings (name, role, category, rarity, description, sort_order) VALUES
('いい香りのナデシコ', 'survivor', 'bp_increase', 'rare', '大胆カテゴリーのBPを100%追加する', 70),
('新鮮なナデシコ', 'survivor', 'bp_increase', 'uncommon', '大胆カテゴリーのBPを75%追加する', 71),
('ナデシコの匂い袋', 'survivor', 'bp_increase', 'common', '大胆カテゴリーのBPを50%追加する', 72);

-- === BP増加: サバイバー用 (協力カテゴリ) ===
INSERT INTO offerings (name, role, category, rarity, description, sort_order) VALUES
('いい香りの月桂樹', 'survivor', 'bp_increase', 'rare', '協力カテゴリーのBPを100%追加する', 80),
('新鮮な月桂樹', 'survivor', 'bp_increase', 'uncommon', '協力カテゴリーのBPを75%追加する', 81),
('月桂樹の匂い袋', 'survivor', 'bp_increase', 'common', '協力カテゴリーのBPを50%追加する', 82);

-- === BP増加: 共通 ===
INSERT INTO offerings (name, role, category, rarity, description, sort_order) VALUES
('血塗れのパーティーリボン', 'shared', 'bp_increase', 'rare', '全プレイヤーの全カテゴリーのBPを100%追加する', 90),
('縛られた封筒', 'shared', 'bp_increase', 'rare', '全プレイヤーの全カテゴリーのBPを25%追加する', 91),
('封がされた封筒', 'shared', 'bp_increase', 'uncommon', '自分の全カテゴリーのBPを25%追加する', 92),
('脱出だ！ケーキ', 'shared', 'bp_increase', 'uncommon', '全プレイヤーの全カテゴリーのBPを100%追加する', 93),
('空の抜け殻', 'shared', 'bp_increase', 'uncommon', '全プレイヤーの全カテゴリーのBPを100%追加する', 94),
('生存者のプリン', 'shared', 'bp_increase', 'uncommon', '全プレイヤーの全カテゴリーのBPを100%追加する', 95);

-- === 霧 ===
INSERT INTO offerings (name, role, category, rarity, description, sort_order) VALUES
('濁った試験薬', 'shared', 'fog', 'very_rare', '闇の霧の濃度がすごく増加する', 100),
('霞がかった試験薬', 'shared', 'fog', 'uncommon', '闇の霧の濃度がそこそこ増加する', 101),
('澄んだ試験薬', 'shared', 'fog', 'uncommon', '闇の霧の濃度がそこそこ減少する', 102),
('薄い試験薬', 'shared', 'fog', 'common', '闇の霧の濃度が少し増加する', 103);

-- === 運 ===
INSERT INTO offerings (name, role, category, rarity, description, sort_order) VALUES
('ヴィゴの塩漬け唇入り瓶', 'survivor', 'luck', 'very_rare', '全サバイバーが自力脱出能力を得て、運が3%増加する', 110),
('粉末入れのポーチ', 'survivor', 'luck', 'rare', '自力脱出能力を得て、自分の運が3%増加する', 111),
('黒塩の像', 'survivor', 'luck', 'uncommon', '自分の運が3%増加する', 112),
('クリーム粉のポーチ', 'survivor', 'luck', 'uncommon', '自分の運が2%増加する', 113),
('塩のポーチ', 'survivor', 'luck', 'common', '自分の運が1%増加する', 114),
('白い粉のポーチ', 'survivor', 'luck', 'common', '自分の運が1%増加する', 115);

-- === マップ指定 ===
INSERT INTO offerings (name, role, category, rarity, description, sort_order) VALUES
('マクミランの指骨', 'shared', 'map_selection', 'rare', 'マクミラン・エステートでの儀式の確率がすごく増加する', 200),
('アザロフのカギ', 'shared', 'map_selection', 'rare', 'オートヘイヴン・レッカーズでの儀式の確率がすごく増加する', 201),
('割れた眼鏡', 'shared', 'map_selection', 'rare', 'レリー記念研究所での儀式の確率がすごく増加する', 202),
('傷んだ写真', 'shared', 'map_selection', 'rare', 'レッドフォレストでの儀式の確率がすごく増加する', 203),
('おばあちゃんの料理本', 'shared', 'map_selection', 'rare', 'バックウォーター・スワンプでの儀式の確率がすごく増加する', 204),
('最後のマスク', 'shared', 'map_selection', 'rare', 'コールドウィンド・ファームでの儀式の確率がすごく増加する', 205),
('ハーメルンの笛吹き', 'shared', 'map_selection', 'rare', 'スプリングウッドでの儀式の確率がすごく増加する', 206),
('山岡家の家紋', 'shared', 'map_selection', 'rare', '山岡邸での儀式の確率がすごく増加する', 207),
('ハート型のロケットペンダント', 'shared', 'map_selection', 'rare', 'オーモンドでの儀式の確率がすごく増加する', 208),
('ジグゾーパズルのピース', 'shared', 'map_selection', 'rare', 'ギデオン食肉工場での儀式の確率がすごく増加する', 209),
('ストロード不動産のカギ', 'shared', 'map_selection', 'rare', 'ハドンフィールドでの儀式の確率がすごく増加する', 210),
('黒焦げた結婚写真', 'shared', 'map_selection', 'rare', 'グレイブス・オブ・グリンヴェイルでの儀式の確率がすごく増加する', 211),
('メアリーへの手紙', 'shared', 'map_selection', 'rare', 'サイレントヒルでの儀式の確率がすごく増加する', 212),
('R.P.D.のバッジ', 'shared', 'map_selection', 'rare', 'ラクーンシティでの儀式の確率がすごく増加する', 213),
('烏の眼', 'shared', 'map_selection', 'rare', 'ウィザーズ・デンでの儀式の確率がすごく増加する', 214),
('膿漿ローム', 'shared', 'map_selection', 'rare', 'ノストロモの残骸での儀式の確率がすごく増加する', 215),
('牛脂ミックス', 'shared', 'map_selection', 'rare', 'ダイアー・メサでの儀式の確率がすごく増加する', 216),
('砕けたボトル', 'shared', 'map_selection', 'rare', 'トバへの橋での儀式の確率がすごく増加する', 217),
('不思議な惑星の植物', 'shared', 'map_selection', 'rare', 'ドヴァルカでの儀式の確率がすごく増加する', 218),
('エアロックの扉', 'shared', 'map_selection', 'rare', 'ノストロモの残骸での儀式の確率がすごく増加する', 219),
('VIPリストバンド', 'shared', 'map_selection', 'rare', 'グリーンヴィルでの儀式の確率がすごく増加する', 220),
('生贄の魔除け', 'shared', 'map_selection', 'rare', 'フォーゴットン・ルーインでの儀式の確率がすごく増加する', 221);

-- === メメント・モリ ===
INSERT INTO offerings (name, role, category, rarity, description, sort_order) VALUES
('黒壇のメメント・モリ', 'killer', 'memento_mori', 'ultra_rare', '処刑段階2段目以降の全サバイバーを自分の手で殺害できる', 300),
('象牙のメメント・モリ', 'killer', 'memento_mori', 'rare', '処刑段階2段目以降のサバイバー1人を自分の手で殺害できる', 301);

-- === 消費無効(魔除け) ===
INSERT INTO offerings (name, role, category, rarity, description, sort_order) VALUES
('白の魔除け', 'survivor', 'charm', 'very_rare', '死亡時にアイテムとアドオンを失わない', 400),
('黒の魔除け', 'killer', 'charm', 'very_rare', 'マッチ終了後にアドオンを失わない', 401);

-- === フック生成変更 ===
INSERT INTO offerings (name, role, category, rarity, description, sort_order) VALUES
('石化した樫の木', 'killer', 'hook', 'very_rare', 'フックの生成数がすごく増加する', 500),
('腐敗した樫の木', 'killer', 'hook', 'rare', 'フックの生成数がそこそこ増加する', 501),
('腐った樫の木', 'killer', 'hook', 'uncommon', 'フックの生成数が少し増加する', 502),
('カビが生えた樫の木', 'killer', 'hook', 'common', 'フックの生成数がわずかに増加する', 503);

-- === 宝箱(チェスト) ===
INSERT INTO offerings (name, role, category, rarity, description, sort_order) VALUES
('輝くコイン', 'survivor', 'chest', 'very_rare', 'マップに出現するチェスト数がすごく増加する', 600),
('割れたコイン', 'survivor', 'chest', 'rare', 'マップに出現するチェスト数がそこそこ増加する', 601),
('変色したコイン', 'survivor', 'chest', 'uncommon', 'マップに出現するチェスト数が少し増加する', 602),
('傷ついたコイン', 'survivor', 'chest', 'common', 'マップに出現するチェスト数がわずかに増加する', 603);

-- === ハッチ位置指定 ===
INSERT INTO offerings (name, role, category, rarity, description, sort_order) VALUES
('注釈付きの設計図', 'shared', 'hatch', 'very_rare', 'ハッチがキラーの居場所の遠くに出現しやすくなる', 700),
('ヴィゴの設計図', 'shared', 'hatch', 'rare', 'ハッチの出現位置がメインの建物に近くなる', 701);

-- === 地下室位置指定 ===
INSERT INTO offerings (name, role, category, rarity, description, sort_order) VALUES
('血塗られた設計図', 'killer', 'basement', 'uncommon', '地下室がメインの建物に出現しやすくなる', 800),
('破れた設計図', 'survivor', 'basement', 'uncommon', '地下室がメインの建物から離れた場所に出現しやすくなる', 801);

-- === サバイバー出現位置 ===
INSERT INTO offerings (name, role, category, rarity, description, sort_order) VALUES
('別離の覆布', 'survivor', 'spawn', 'uncommon', 'サバイバーがそれぞれ離れた場所に出現する', 900),
('ヴィゴの覆布', 'survivor', 'spawn', 'uncommon', 'サバイバーが近い場所に出現する確率が増加する', 901),
('同盟の覆布', 'survivor', 'spawn', 'common', 'サバイバーが少し近い場所に出現する確率が増加する', 902),
('消滅の覆布', 'survivor', 'spawn', 'common', 'サバイバーが少し離れた場所に出現する確率が増加する', 903);

-- === イベント ===
INSERT INTO offerings (name, role, category, rarity, description, sort_order) VALUES
('むごたらしいケーキ', 'shared', 'event', 'event', '全プレイヤーの全カテゴリーのBPを104%追加する', 1000),
('パチュラの花弁', 'shared', 'event', 'event', 'イベントの花を収集できるようになる', 1001),
('呪われた種', 'shared', 'event', 'event', 'イベントアイテムが出現するようになる', 1002),
('やばいフラン', 'shared', 'event', 'event', '全プレイヤーの全カテゴリーのBPを104%追加する', 1003),
('謎めいた占い棒', 'shared', 'event', 'event', 'イベントアイテムが出現するようになる', 1004),
('テラーミス', 'shared', 'event', 'event', '全プレイヤーの全カテゴリーのBPを104%追加する', 1005),
('足指のヤドリギ', 'shared', 'event', 'event', 'イベントアイテムが出現するようになる', 1006),
('悲鳴を上げるコブラー', 'shared', 'event', 'event', '全プレイヤーの全カテゴリーのBPを104%追加する', 1007),
('ココナッツスクリームパイ', 'shared', 'event', 'event', '全プレイヤーの全カテゴリーのBPを104%追加する', 1008);
