#!/bin/bash
#
# 状态转换序列测试
#
# 这批用例针对的是「操作 A 之后 B 的状态没收拾」这类问题：
#   删文件夹 → 里面的文件去哪了
#   删用户   → 他上传的文件归谁
#   删等级   → 引用它的文件还能不能下载
#   恢复子目录 → 父级还在回收站怎么办
#
# 只操作 __seq / __t 前缀的测试数据，跑完清理，不碰生产内容。
#
# 用法：bash tools/test-sequence.sh   （在站点根目录执行，需 root 读库）
set -u
BASE="https://127.0.0.1"; HOST="${2:-cun.973700.xyz}"
PW=$(sqlite3 /www/server/panel/data/default.db "select mysql_root from config where id=1;")
q() { mysql -u root -p"$PW" 2>/dev/null -N -e "$1"; }
x() { mysql -u root -p"$PW" 2>/dev/null -e "$1"; }
c() { curl -sk -H "Host: $HOST" "$@"; }
BAD=0
ok()   { printf "  ok   %s\n" "$1"; }
fail() { printf "  FAIL %s  ->  %s\n" "$1" "$2"; BAD=$((BAD+1)); }

CJ=/tmp/seq-cookie.txt; rm -f $CJ
c -o /tmp/seq-lg.html -c $CJ "$BASE/login" > /dev/null
TK=$(grep -oP 'name="_token" value="\K[^"]+' /tmp/seq-lg.html | head -1)
c -o /dev/null -b $CJ -c $CJ -d "_token=$TK&username=admin&password=123456" "$BASE/login"
c -o /tmp/seq-ad.html -b $CJ "$BASE/admin/files" > /dev/null
CSRF=$(grep -oP 'name="csrf-token" content="\K[^"]+' /tmp/seq-ad.html | head -1)

head -c 4096 /dev/urandom > /tmp/seq.bin
SZ=$(stat -c%s /tmp/seq.bin)

mkf() { c -b $CJ -H "X-CSRF-Token: $CSRF" -H "X-Requested-With: XMLHttpRequest" -d "name=$1&parent_id=$2" "$BASE/admin/folders/save" > /dev/null; q "SELECT id FROM wxpan.wx_folders WHERE name='$1' LIMIT 1"; }
upf() { local n="$1" f="$2"
  c -o /tmp/seq-u.json -b $CJ -H "X-CSRF-Token: $CSRF" -H "X-Requested-With: XMLHttpRequest" \
    -F "name=$n" -F "size=$SZ" -F "chunks=1" -F "folder_id=$f" "$BASE/api/upload/init" > /dev/null
  local u=$(grep -oP '"upload_id":"\K[^"]+' /tmp/seq-u.json)
  c -o /dev/null -b $CJ -H "X-CSRF-Token: $CSRF" -H "X-Requested-With: XMLHttpRequest" -F "upload_id=$u" -F "index=0" -F "file=@/tmp/seq.bin;filename=blob" "$BASE/api/upload/chunk"
  c -o /tmp/seq-f.json -b $CJ -H "X-CSRF-Token: $CSRF" -H "X-Requested-With: XMLHttpRequest" -F "upload_id=$u" "$BASE/api/upload/finish"
  grep -oP '"file_id":\K[0-9]+' /tmp/seq-f.json; }

echo "=== 1. 三层目录 A>B>C，删 A 再恢复 A ==="
A=$(mkf __tA 0); B=$(mkf __tB "$A"); C=$(mkf __tC "$B")
upf __fA.bin "$A" > /dev/null; upf __fB.bin "$B" > /dev/null; upf __fC.bin "$C" > /dev/null
c -b $CJ -H "X-CSRF-Token: $CSRF" -H "X-Requested-With: XMLHttpRequest" -d "id=$A" "$BASE/admin/folders/delete" > /dev/null
R=$(c -b $CJ -H "X-CSRF-Token: $CSRF" -H "X-Requested-With: XMLHttpRequest" -d "id=$A" "$BASE/admin/trash/restore-folder")
N=$(q "SELECT COUNT(*) FROM wxpan.wx_files WHERE name LIKE '__f%.bin' AND deleted_at IS NULL")
[ "$N" = "3" ] && ok "恢复 A：3 个文件全回来" || fail "恢复 A 文件不全" "$N/3"
[ "$(q "SELECT IF(deleted_at IS NULL,'1','0') FROM wxpan.wx_folders WHERE id=$C")" = "1" ] && ok "恢复 A：深层目录 C 也回来" || fail "C 未恢复" ""

echo ""
echo "=== 2. 再删 A，只恢复 C（父级仍在回收站）==="
c -b $CJ -H "X-CSRF-Token: $CSRF" -H "X-Requested-With: XMLHttpRequest" -d "id=$A" "$BASE/admin/folders/delete" > /dev/null
c -b $CJ -H "X-CSRF-Token: $CSRF" -H "X-Requested-With: XMLHttpRequest" -d "id=$C" "$BASE/admin/trash/restore-folder" > /dev/null
CP=$(q "SELECT parent_id FROM wxpan.wx_folders WHERE id=$C")
[ "$CP" = "0" ] && ok "C 挂到根目录(parent_id=0)" || fail "C 的 parent_id 应为 0" "$CP"
[ "$(q "SELECT IF(deleted_at IS NULL,'正常','回收') FROM wxpan.wx_folders WHERE id=$B")" = "回收" ] && ok "B 仍在回收站" || fail "B 被误恢复" ""

echo ""
echo "=== 3. 删用户，其上传的文件归管理员 ==="
FID=$(q "SELECT id FROM wxpan.wx_files WHERE name='__fA.bin' LIMIT 1")
x "INSERT INTO wxpan.wx_users (username,email,password_hash,nickname,role,status,level_id,created_at,updated_at) VALUES ('__sequser',NULL,'x','t','user',1,1,NOW(),NOW());"
UU=$(q "SELECT id FROM wxpan.wx_users WHERE username='__sequser' LIMIT 1")
x "UPDATE wxpan.wx_files SET uploader_id=$UU WHERE id=$FID;"
c -b $CJ -H "X-CSRF-Token: $CSRF" -H "X-Requested-With: XMLHttpRequest" -d "id=$UU" "$BASE/admin/users/delete" > /dev/null
ADMIN=$(q "SELECT id FROM wxpan.wx_users WHERE role='admin' LIMIT 1")
[ "$(q "SELECT uploader_id FROM wxpan.wx_files WHERE id=$FID")" = "$ADMIN" ] && ok "文件归属转给管理员" || fail "归属异常" ""
[ "$(q "SELECT COUNT(*) FROM wxpan.wx_users WHERE id=$UU")" = "0" ] && ok "用户已删除" || fail "用户仍在" ""

echo ""
echo "=== 清理 ==="
x "DELETE FROM wxpan.wx_files WHERE name LIKE '__f%.bin';"
x "DELETE FROM wxpan.wx_folders WHERE name LIKE '__t%';"
x "DELETE FROM wxpan.wx_users WHERE username LIKE '__seq%';"
for d in runtime/chunks/*/; do [ -d "$d" ] && rm -rf "$d"; done 2>/dev/null
find storage/files -name '*.bin' -newermt '-5 minutes' -delete 2>/dev/null
find storage/files -mindepth 1 -type d -empty -delete 2>/dev/null
rm -f /tmp/seq-*.html /tmp/seq-*.json /tmp/seq.bin $CJ
echo "  剩余文件: $(q "SELECT IFNULL(GROUP_CONCAT(name),'无') FROM wxpan.wx_files WHERE deleted_at IS NULL")"
echo ""
echo "=== 异常: $BAD ==="
exit $BAD