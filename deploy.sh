#!/bin/bash
# asobi.info デプロイスクリプト（Conoha VPS）
# Usage: bash deploy.sh [pkq|dbd|info|shared|all|ファイルパス]

KEY="G:/マイドライブ/サーバ情報/key-m96-conoha.pem"
HOST="root@133.117.75.23"
LOCAL_BASE="G:/マイドライブ/claude/asobi.info"

# ===== 旧パスチェック（ロールバック防止） =====
check_old_paths() {
    local dir=$1
    local old=$(grep -rn '/home/m96' "$dir" 2>/dev/null | grep '\.php' | grep -v '.sqlite' | grep -v 'deploy.sh')
    if [ -n "$old" ]; then
        echo "❌ 旧パスが残っています！デプロイを中止します。"
        echo "$old"
        exit 1
    fi
    echo "✅ パスチェックOK: $dir"
}

deploy() {
    local src=$1
    local dst=$2
    check_old_paths "$LOCAL_BASE/$src"
    echo "=== $src → $dst ==="
    scp -r -i "$KEY" "$LOCAL_BASE/$src/"* "$HOST:$dst/"
    ssh -i "$KEY" "$HOST" "chown -R www-data:www-data $dst/"
    echo "✅ $src デプロイ完了"
}

# 単一ファイルデプロイ
deploy_file() {
    local file=$1
    local site=$2
    local dst="/opt/asobi/$site/$(basename $file)"
    check_old_paths "$file"
    echo "=== $file → $dst ==="
    scp -i "$KEY" "$file" "$HOST:$dst"
    ssh -i "$KEY" "$HOST" "chown www-data:www-data $dst"
    echo "✅ $(basename $file) デプロイ完了"
}

SITE=${1:-""}
if [ -z "$SITE" ]; then
    echo "使い方:"
    echo "  bash deploy.sh pkq          # pkq全体をデプロイ"
    echo "  bash deploy.sh dbd          # dbd全体をデプロイ"
    echo "  bash deploy.sh info         # メインサイト全体をデプロイ"
    echo "  bash deploy.sh shared       # 共通assets全体をデプロイ"
    echo "  bash deploy.sh all          # 全サイトをデプロイ"
    exit 1
fi

case "$SITE" in
    pkq)    deploy "pkq" "/opt/asobi/pkq" ;;
    dbd)    deploy "dbd" "/opt/asobi/dbd" ;;
    info)   deploy "info" "/opt/asobi/info" ;;
    shared) deploy "shared" "/opt/asobi/shared" ;;
    all)
        deploy "shared" "/opt/asobi/shared"
        deploy "info" "/opt/asobi/info"
        deploy "pkq" "/opt/asobi/pkq"
        deploy "dbd" "/opt/asobi/dbd"
        ;;
    *)  echo "不明なサイト: $SITE" && exit 1 ;;
esac

echo ""
echo "=== デプロイ完了 ==="
echo "  https://asobi.info"
echo "  https://pkq.asobi.info"
echo "  https://dbd.asobi.info"
echo "  https://tbt.asobi.info"
