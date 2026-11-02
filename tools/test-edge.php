<?php
/**
 * 上传边界测试（CLI）
 *   php tools/test-edge.php
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

$base = getenv('SITE_URL') ?: 'http://127.0.0.1:8088';
$jar  = tempnam(sys_get_temp_dir(), 'ek');
$user = 'admin';
$pass = getenv('ADMIN_PASS') ?: 'Admin@12345';

function ch(string $url, string $jar)
{
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 60,
    ]);
    return $c;
}
function get(string $u, string $jar): string
{
    $c = ch($u, $jar); $r = (string) curl_exec($c); curl_close($c); return $r;
}
function post(string $u, array $f, string $jar, array $h = [], bool $multi = false): string
{
    $c = ch($u, $jar);
    curl_setopt($c, CURLOPT_POST, true);
    curl_setopt($c, CURLOPT_POSTFIELDS, $multi ? $f : http_build_query($f));
    if ($h) curl_setopt($c, CURLOPT_HTTPHEADER, $h);
    $r = (string) curl_exec($c); curl_close($c); return $r;
}
function jj(string $raw): array
{
    $j = json_decode($raw, true);
    return is_array($j) ? $j : ['ok' => false, 'msg' => $raw];
}

// 登录
$html = get($base . '/login', $jar);
preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $html, $m);
post($base . '/login', ['_token' => $m[1], 'username' => $user, 'password' => $pass], $jar);
$adm = get($base . '/admin/files', $jar);
preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $adm, $m2);
$H = ['X-CSRF-Token: ' . $m2[1], 'X-Requested-With: XMLHttpRequest'];

function report(string $title, bool $pass, string $detail): void
{
    printf("[%s] %s\n        %s\n", $pass ? 'PASS' : 'FAIL', $title, $detail);
}

// 准备 5MB 数据
$payload = random_bytes(5 * 1024 * 1024);

echo "=== 上传边界测试 ===\n\n";

// ---------- 1. 分片缺失 ----------
$init = jj(post($base . '/api/upload/init', [
    'name' => 'edge-missing.bin', 'size' => strlen($payload), 'chunks' => 1, 'folder_id' => 0,
], $jar, $H));
$uid = $init['upload_id'] ?? '';
$cs  = (int) ($init['chunk_size'] ?? 0);
$n   = (int) ($init['chunks'] ?? 0);
// 只传前 n-1 片
for ($i = 0; $i < $n - 1; $i++) {
    $t = tempnam(sys_get_temp_dir(), 'c');
    file_put_contents($t, substr($payload, $i * $cs, $cs));
    post($base . '/api/upload/chunk', [
        'upload_id' => $uid, 'index' => $i,
        'file' => new CURLFile($t, 'application/octet-stream', 'b'),
    ], $jar, $H, true);
    @unlink($t);
}
$r = jj(post($base . '/api/upload/finish', ['upload_id' => $uid], $jar, $H));
report(
    '分片缺失时 finish 被拒绝',
    !($r['ok'] ?? false) && str_contains((string) ($r['msg'] ?? ''), '分片不完整'),
    '返回：' . ($r['msg'] ?? json_encode($r))
);

// ---------- 2. 分片序号越界 ----------
$t = tempnam(sys_get_temp_dir(), 'c');
file_put_contents($t, 'x');
$r = jj(post($base . '/api/upload/chunk', [
    'upload_id' => $uid, 'index' => 9999,
    'file' => new CURLFile($t, 'application/octet-stream', 'b'),
], $jar, $H, true));
@unlink($t);
report(
    '分片序号越界被拒绝',
    !($r['ok'] ?? false) && str_contains((string) ($r['msg'] ?? ''), '越界'),
    '返回：' . ($r['msg'] ?? json_encode($r))
);

// ---------- 3. 分片体积异常 ----------
$t = tempnam(sys_get_temp_dir(), 'c');
file_put_contents($t, random_bytes($cs + 200000)); // 超过 chunk_size + 64KB
$r = jj(post($base . '/api/upload/chunk', [
    'upload_id' => $uid, 'index' => 0,
    'file' => new CURLFile($t, 'application/octet-stream', 'b'),
], $jar, $H, true));
@unlink($t);
report(
    '超大分片被拒绝',
    !($r['ok'] ?? false) && str_contains((string) ($r['msg'] ?? ''), '体积异常'),
    '返回：' . ($r['msg'] ?? json_encode($r))
);

// ---------- 4. 非法 upload_id ----------
$r = jj(post($base . '/api/upload/chunk', [
    'upload_id' => '../../etc', 'index' => 0,
    'file' => new CURLFile($t = tempnam(sys_get_temp_dir(), 'c'), 'application/octet-stream', 'b'),
], $jar, $H, true));
@unlink($t);
report(
    '非法 upload_id 被拒绝',
    !($r['ok'] ?? false),
    '返回：' . ($r['msg'] ?? json_encode($r))
);

// ---------- 5. 越权访问他人上传任务 ----------
$other = tempnam(sys_get_temp_dir(), 'ok');
$r = jj(post($base . '/api/upload/finish', ['upload_id' => $uid], $other, $H));
@unlink($other);
report(
    '未登录不能 finish 别人的任务',
    !($r['ok'] ?? false),
    '返回：' . ($r['msg'] ?? json_encode($r))
);

// ---------- 6. abort 清理 ----------
$before = glob(APP_ROOT . '/runtime/chunks/' . $uid . '/*') ?: [];
$r = jj(post($base . '/api/upload/abort', ['upload_id' => $uid], $jar, $H));
$after = glob(APP_ROOT . '/runtime/chunks/' . $uid . '/*') ?: [];
if (!defined('APP_ROOT')) {
    $before = $after = [];
}
report(
    'abort 后分片目录被清空',
    ($r['ok'] ?? false) && count($after) === 0,
    sprintf('残留文件：%d 个', count($after))
);

echo "\n完成。\n";
