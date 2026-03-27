#!/bin/bash
# SQLiteデータベースを構築するスクリプト
# Usage: bash setup/build_databases.sh

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BASE_DIR="$(dirname "$SCRIPT_DIR")"

echo "=== データベース構築 ==="

# ポケモンクエストDB
PQ_DB="$BASE_DIR/pkq/data/pokemon_quest.sqlite"
echo "--- ポケモンクエスト DB を構築中... ---"
rm -f "$PQ_DB"
sqlite3 "$PQ_DB" < "$SCRIPT_DIR/pq_schema.sql"
sqlite3 "$PQ_DB" < "$SCRIPT_DIR/pq_data.sql"
echo "ポケモンクエスト DB 完了: $(sqlite3 "$PQ_DB" 'SELECT COUNT(*) FROM pokemon;') ポケモン"

# DbD DB
DBD_DB="$BASE_DIR/dbd/data/dbd.sqlite"
echo "--- DbD DB を構築中... ---"
rm -f "$DBD_DB"
sqlite3 "$DBD_DB" < "$SCRIPT_DIR/dbd_schema.sql"
sqlite3 "$DBD_DB" < "$SCRIPT_DIR/dbd_data.sql"
echo "DbD DB 完了: $(sqlite3 "$DBD_DB" 'SELECT COUNT(*) FROM killers;') キラー, $(sqlite3 "$DBD_DB" 'SELECT COUNT(*) FROM perks;') パーク"

echo ""
echo "=== 全データベース構築完了 ==="
