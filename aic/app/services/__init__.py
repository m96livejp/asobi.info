"""共通ユーティリティ"""
import os
import json
import datetime

_API_USAGE_LOG = "/opt/asobi/aic/data/api_usage.log"


def append_api_usage_log(entry: dict):
    """API利用ログをファイルに追記（JSON Lines形式）"""
    if "ts" not in entry:
        entry["ts"] = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    try:
        with open(_API_USAGE_LOG, "a", encoding="utf-8") as f:
            f.write(json.dumps(entry, ensure_ascii=False) + "\n")
        try:
            os.chmod(_API_USAGE_LOG, 0o666)
        except OSError:
            pass
    except Exception:
        pass
