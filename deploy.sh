#!/bin/bash
# あそび サイト デプロイスクリプト
# Usage: bash deploy.sh

SSH_KEY="G:/マイドライブ/サーバ情報/Key-m96-wpx.key"
SSH_USER="m96"
SSH_HOST="sv6112.wpx.ne.jp"
SSH_PORT="10022"
REMOTE_DIR="/home/m96/asobi.info/public_html"
LOCAL_DIR="G:/マイドライブ/claude/asobi.info/public_html"

echo "=== あそび サイト デプロイ ==="
echo "ターゲット: ${SSH_USER}@${SSH_HOST}:${REMOTE_DIR}"

# SCP でファイルをアップロード (SQLiteは除外 - サーバーのDBを保持)
echo ""
echo "--- ファイルをアップロード中... ---"
# assets, css, js, images, admin, api(非data), htmlファイルを個別にアップロード
scp -i "$SSH_KEY" -P $SSH_PORT -r "$LOCAL_DIR/assets" "${SSH_USER}@${SSH_HOST}:${REMOTE_DIR}/"
scp -i "$SSH_KEY" -P $SSH_PORT "$LOCAL_DIR/index.html" "${SSH_USER}@${SSH_HOST}:${REMOTE_DIR}/"
scp -i "$SSH_KEY" -P $SSH_PORT -r "$LOCAL_DIR/pkq" "${SSH_USER}@${SSH_HOST}:${REMOTE_DIR}/"
# DbD (dataディレクトリのSQLiteを除く)
scp -i "$SSH_KEY" -P $SSH_PORT "$LOCAL_DIR/dbd"/*.html "${SSH_USER}@${SSH_HOST}:${REMOTE_DIR}/dbd/"
scp -i "$SSH_KEY" -P $SSH_PORT -r "$LOCAL_DIR/dbd/css" "${SSH_USER}@${SSH_HOST}:${REMOTE_DIR}/dbd/"
scp -i "$SSH_KEY" -P $SSH_PORT -r "$LOCAL_DIR/dbd/js" "${SSH_USER}@${SSH_HOST}:${REMOTE_DIR}/dbd/"
scp -i "$SSH_KEY" -P $SSH_PORT -r "$LOCAL_DIR/dbd/api" "${SSH_USER}@${SSH_HOST}:${REMOTE_DIR}/dbd/"
scp -i "$SSH_KEY" -P $SSH_PORT -r "$LOCAL_DIR/dbd/admin" "${SSH_USER}@${SSH_HOST}:${REMOTE_DIR}/dbd/"
scp -i "$SSH_KEY" -P $SSH_PORT -r "$LOCAL_DIR/dbd/images" "${SSH_USER}@${SSH_HOST}:${REMOTE_DIR}/dbd/"

echo ""
echo "--- .htaccess をアップロード中... ---"
scp -i "$SSH_KEY" -P $SSH_PORT "$LOCAL_DIR/dbd/.htaccess" "${SSH_USER}@${SSH_HOST}:${REMOTE_DIR}/dbd/.htaccess"
scp -i "$SSH_KEY" -P $SSH_PORT "$LOCAL_DIR/pkq/.htaccess" "${SSH_USER}@${SSH_HOST}:${REMOTE_DIR}/pkq/.htaccess"

echo ""
echo "--- パーミッション設定中... ---"
ssh -i "$SSH_KEY" -p $SSH_PORT "${SSH_USER}@${SSH_HOST}" << 'ENDSSH'
  chmod 755 /home/m96/asobi.info/public_html/dbd/data
  chmod 644 /home/m96/asobi.info/public_html/dbd/data/dbd.sqlite 2>/dev/null
  chmod 755 /home/m96/asobi.info/public_html/pkq/data
  chmod 644 /home/m96/asobi.info/public_html/pkq/data/pokemon_quest.sqlite 2>/dev/null
  echo "パーミッション設定完了"
ENDSSH

echo ""
echo "=== デプロイ完了 ==="
echo "トップページ: https://asobi.info"
echo "DbD: https://dbd.asobi.info"
echo "ポケクエ: https://pkq.asobi.info"
