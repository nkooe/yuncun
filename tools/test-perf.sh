#!/bin/bash
#
# 分页与列表性能压测
#
# 用法：bash tools/test-perf.sh        （站点根目录执行，需 root 读库）
#
# 造 1000 条 __perf 前缀记录，测完只删这些，并核对基线指纹。
# 用假 sha256 与 storage_key，不会在 storage/files 产生任何 bin 文件。
#
# 三个踩过的坑，别改回去：
#   1. bash 的 UID 是只读变量，存用户 id 要用别的名字
#   2. 列名是 orig_name，不是 original_name
#   3. storage_key 是 NOT NULL 且无默认值，必须显式给
#
set -u
BASE="https://127.0.0.1"
HOST="${1:-cun.973700.xyz}"
PW=$(sqlite3 /www/server/panel/data/default.db "select mysql_root from config where id=1;")
q() { mysql -u root -p"$PW" 2>/dev/null -N -e "$1"; }
c() { curl -sk -H "Host: $HOST" "$@"; }

# 中途出错也要把测试数据清掉，否则 1000 条会留在生产库里
cleanup() {
  mysql -u root -p"$PW" 2>/dev/null -e "DELETE FROM wxpan.wx_files WHERE name LIKE '__perf%';" 2>/dev/null
  rm -f /tmp/perf-*.html /tmp/perf.sql /tmp/perf-cookie.txt 2>/dev/null
}
trap cleanup EXIT INT TERM

BASE_FILES=$(q "SELECT COUNT(*) FROM wxpan.wx_files")
BASE_BIN=$(find storage/files -name '*.bin' 2>/dev/null | wc -l)
BASE_NAMES=$(q "SELECT IFNULL(GROUP_CONCAT(name ORDER BY id),'') FROM wxpan.wx_files")
BASE_FP=$(q "SELECT IFNULL(MD5(GROUP_CONCAT(CONCAT_WS('|',id,name,size,downloads) ORDER BY id)),'') FROM wxpan.wx_files")
echo "=== 基线：记录 $BASE_FILES / bin $BASE_BIN ==="
echo "    文件：$BASE_NAMES"
echo "    指纹：${BASE_FP:0:16}..."

CJ=/tmp/perf-cookie.txt; rm -f $CJ
c -o /tmp/perf-lg.html -c $CJ "$BASE/login" > /dev/null
TK=$(grep -oP 'name="_token" value="\K[^"]+' /tmp/perf-lg.html | head -1)
c -o /dev/null -b $CJ -c $CJ -d "_token=$TK&username=admin&password=123456" "$BASE/login"

UPID=$(q "SELECT id FROM wxpan.wx_users WHERE role='admin' LIMIT 1")
FOLDER=$(q "SELECT id FROM wxpan.wx_folders WHERE deleted_at IS NULL LIMIT 1")

echo ""
echo "=== 生成 1000 条 ==="
{
  echo "SET autocommit=0; START TRANSACTION;"
  for i in $(seq 1 1000); do
    H=$(printf '%064d' $i | tr '0-9' 'abcdef12')
    SK="${H:0:2}/${H:2:2}/${H}.bin"
    printf "INSERT INTO wxpan.wx_files (folder_id,name,orig_name,ext,mime,size,storage_key,sha256,downloads,is_public,level_id,view_level_id,login_required,sort,status,uploader_id,created_at,updated_at) VALUES (%s,'__perf_%04d.pdf','__perf_%04d.pdf','pdf','application/pdf',%d,'%s','%s',%d,1,0,0,0,0,1,%s,NOW(),NOW());\n" \
      "${FOLDER:-0}" $i $i $((i*1024)) "$SK" "$H" $((i%500)) "${UPID:-1}"
  done
  echo "COMMIT;"
} > /tmp/perf.sql

S=$(date +%s%N)
ERR=$(mysql -u root -p"$PW" wxpan < /tmp/perf.sql 2>&1)
E=$(date +%s%N)
[ -n "$ERR" ] && echo "  导入报错: $ERR"
echo "  插入耗时 $(( (E-S)/1000000 )) ms"

N=$(q "SELECT COUNT(*) FROM wxpan.wx_files WHERE name LIKE '__perf%'")
echo "  已插入 $N / 1000"
if [ "$N" != "1000" ]; then
  echo "  FAIL，清理后退出"
  mysql -u root -p"$PW" 2>/dev/null -e "DELETE FROM wxpan.wx_files WHERE name LIKE '__perf%';"
  rm -f /tmp/*.html /tmp/perf.sql $CJ
  exit 1
fi

echo ""
echo "=== 页面响应（3 次取最快）==="
t() {
  local desc="$1" url="${2:-}" ck="${3:-}" best=999999 code=""
  for r in 1 2 3; do
    local s=$(date +%s%N)
    code=$(c -o /tmp/perf-p.html -w "%{http_code}" ${ck:+-b $ck} "$url")
    local e=$(date +%s%N); local ms=$(( (e-s)/1000000 ))
    [ $ms -lt $best ] && best=$ms
  done
  printf "  %-24s %s  %5d ms  %7d 字节\n" "$desc" "$code" "$best" "$(wc -c < /tmp/perf-p.html)"
}

t "首页"          "$BASE/"
t "首页 第2页"    "$BASE/?page=2"
t "首页 第999页"  "$BASE/?page=999"
t "分类页"        "$BASE/categories"
t "搜索"          "$BASE/search?q=__perf"
t "后台列表"      "$BASE/admin/files" "$CJ"
t "后台 第2页"    "$BASE/admin/files?page=2" "$CJ"
t "后台 第50页"   "$BASE/admin/files?page=50" "$CJ"
t "后台 第500页"  "$BASE/admin/files?page=500" "$CJ"
t "后台 搜索"     "$BASE/admin/files?q=__perf" "$CJ"
t "仪表盘"        "$BASE/admin" "$CJ"
t "回收站"        "$BASE/admin/trash" "$CJ"

echo ""
echo "=== 深分页 OFFSET ==="
for off in 0 100 500 980; do
  s=$(date +%s%N)
  mysql -u root -p"$PW" 2>/dev/null -N -e "SELECT COUNT(*) FROM (SELECT id FROM wxpan.wx_files WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 20 OFFSET $off) t;" > /dev/null
  e=$(date +%s%N)
  printf "  OFFSET %-4s %4d ms\n" "$off" $(( (e-s)/1000000 ))
done

echo ""
echo "=== 执行计划 ==="
mysql -u root -p"$PW" 2>/dev/null -e "EXPLAIN SELECT f.*, d.name AS folder_name FROM wxpan.wx_files f LEFT JOIN wxpan.wx_folders d ON d.id=f.folder_id WHERE f.deleted_at IS NULL ORDER BY f.id DESC LIMIT 20 OFFSET 500\G" | grep -E "type:|key:|rows:|Extra:" | sed 's/^/    /'

echo ""
echo "=== 清理 ==="
BEFORE=$(q "SELECT COUNT(*) FROM wxpan.wx_files WHERE name LIKE '__perf%'")
mysql -u root -p"$PW" 2>/dev/null -e "DELETE FROM wxpan.wx_files WHERE name LIKE '__perf%';"
AFTER=$(q "SELECT COUNT(*) FROM wxpan.wx_files WHERE name LIKE '__perf%'")
echo "  删除 $BEFORE 条，残留 $AFTER 条"

AF=$(q "SELECT COUNT(*) FROM wxpan.wx_files")
AB=$(find storage/files -name '*.bin' 2>/dev/null | wc -l)
AN=$(q "SELECT IFNULL(GROUP_CONCAT(name ORDER BY id),'') FROM wxpan.wx_files")
AFP=$(q "SELECT IFNULL(MD5(GROUP_CONCAT(CONCAT_WS('|',id,name,size,downloads) ORDER BY id)),'') FROM wxpan.wx_files")

echo ""
echo "=== 核对基线 ==="
echo "  记录 $AF / $BASE_FILES    $([ "$AF" = "$BASE_FILES" ] && echo ok || echo FAIL)"
echo "  bin  $AB / $BASE_BIN    $([ "$AB" = "$BASE_BIN" ] && echo ok || echo FAIL)"
echo "  名称 $([ "$AN" = "$BASE_NAMES" ] && echo 一致 || echo "差异：$AN")"
echo "  指纹 $([ "$AFP" = "$BASE_FP" ] && echo 完全一致 || echo 不一致)"

rm -f /tmp/*.html /tmp/perf.sql $CJ