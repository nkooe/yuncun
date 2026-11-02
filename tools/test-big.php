<?php
/**
 * 大文件上传/下载实测（CLI）
 *
 *   php tools/test-big.php <文件路径> [--download]
 *
 * 走真实 HTTP 接口，验证：
 *   分片上传 → 合并 → sha256 一致性 → 下载/断点续传
 */
declare(strict_types=1);

$base  = getenv('SITE_URL') ?: 'http://127.0.0.1:8088';
$file  = $argv[1] ?? '';
$doDl  = in_array('--download', $argv ?? [], true);
$user  = getenv('ADMIN_USER') ?: 'admin';
$pass  = getenv('ADMIN_PASS') ?: 'Admin@12345';

if (!is_file($file)) {
    fwrite(STDERR, "用法: php tools/test-big.php <文件路径> [--download]\n");
    exit(1);
}

$size = filesize($file);
$name = basename($file);
$jar  = tempnam(sys_get_temp_dir(), 'wxck');

function curlInit(string $url, string $jar)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    return $ch;
}

function httpGet(string $url, string $jar): string
{
    $ch = curlInit($url, $jar);
    $r = (string) curl_exec($ch);
    curl_close($ch);
    return $r;
}

function httpPost(string $url, array $fields, string $jar, array $headers = [], bool $multipart = false): string
{
    $ch = curlInit($url, $jar);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $fields : http_build_query($fields));
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $r = (string) curl_exec($ch);
    curl_close($ch);
    return $r;
}

function bytes(int $n): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $v = (float) $n;
    while ($v >= 1024 && $i < 3) { $v /= 1024; $i++; }
    return round($v, 2) . ' ' . $u[$i];
}

$t0 = microtime(true);

// ---------- 登录 ----------
$html = httpGet($base . '/login', $jar);
if (!preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $html, $m)) {
    exit("登录页解析失败\n");
}
httpPost($base . '/login', ['_token' => $m[1], 'username' => $user, 'password' => $pass], $jar);

$adm = httpGet($base . '/admin/files', $jar);
if (!preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $adm, $m2)) {
    exit("取 CSRF 失败，可能未登录\n");
}
$csrf = $m2[1];
$H = ['X-CSRF-Token: ' . $csrf, 'X-Requested-With: XMLHttpRequest'];

echo "=== 大文件上传实测 ===\n";
echo "文件: {$name}  " . bytes($size) . "\n";
echo "源 SHA256: " . hash_file('sha256', $file) . "\n\n";

// ---------- init ----------
$guessChunks = (int) ceil($size / (2 * 1024 * 1024));
$initRaw = httpPost($base . '/api/upload/init', [
    'name' => $name, 'size' => $size, 'chunks' => $guessChunks, 'folder_id' => 0,
], $jar, $H);
$init = json_decode($initRaw, true);
if (!($init['ok'] ?? false)) {
    exit("init 失败: " . $initRaw . "\n");
}
$uploadId  = $init['upload_id'];
$chunkSize = (int) $init['chunk_size'];
$chunks    = (int) $init['chunks'];
printf("init OK  chunks=%d  chunk_size=%s\n", $chunks, bytes($chunkSize));

// ---------- 分片 ----------
$fp = fopen($file, 'rb');
$sent = 0;
$t1 = microtime(true);
for ($i = 0; $i < $chunks; $i++) {
    $data = fread($fp, $chunkSize);
    if ($data === false) {
        exit("读文件失败 @ {$i}\n");
    }
    $tmp = tempnam(sys_get_temp_dir(), 'ch');
    file_put_contents($tmp, $data);

    $raw = httpPost($base . '/api/upload/chunk', [
        'upload_id' => $uploadId,
        'index'     => $i,
        'file'      => new CURLFile($tmp, 'application/octet-stream', 'blob'),
    ], $jar, $H, true);
    @unlink($tmp);

    $j = json_decode($raw, true);
    if (!($j['ok'] ?? false)) {
        exit("分片 {$i} 失败: {$raw}\n");
    }
    $sent += strlen($data);
    if ($i % 10 === 0 || $i === $chunks - 1) {
        printf("  片 %3d/%d  %s\n", $i + 1, $chunks, bytes($sent));
    }
}
fclose($fp);
$t2 = microtime(true);
printf("分片完成，耗时 %.1fs，平均 %s/s\n\n", $t2 - $t1, bytes((int) ($size / max(0.001, $t2 - $t1))));

// ---------- finish ----------
$finRaw = httpPost($base . '/api/upload/finish', ['upload_id' => $uploadId], $jar, $H);
$fin = json_decode($finRaw, true);
if (!($fin['ok'] ?? false)) {
    exit("finish 失败: " . $finRaw . "\n");
}
$t3 = microtime(true);
printf("finish OK  耗时 %.1fs\n", $t3 - $t2);
printf("  服务端 size   : %s (%d bytes)\n", $fin['size_h'], $fin['size']);
printf("  服务端 sha256 : %s\n", $fin['sha256']);
printf("  秒传 deduped  : %s\n", $fin['deduped'] ? 'true' : 'false');
printf("  大小一致      : %s\n", (int) $fin['size'] === $size ? 'YES' : 'NO');
printf("  sha256 一致   : %s\n",
    $fin['sha256'] === hash_file('sha256', $file) ? 'YES' : 'NO');
echo "\n总耗时: " . round(microtime(true) - $t0, 1) . "s\n";

// ---------- 下载 ----------
if ($doDl) {
    echo "\n=== 下载实测 ===\n";
    $out = sys_get_temp_dir() . '/dl_' . $name;

    // 完整下载
    $t4 = microtime(true);
    $ch = curlInit($base . '/download/' . $fin['file_id'], $jar);
    $fh = fopen($out, 'wb');
    curl_setopt($ch, CURLOPT_FILE, $fh);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $got  = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    curl_close($ch);
    fclose($fh);
    $t5 = microtime(true);
    printf("完整下载  HTTP %d  %s  耗时 %.1fs  速度 %s/s\n",
        $code, bytes((int) $got), $t5 - $t4, bytes((int) ($got / max(0.001, $t5 - $t4))));
    printf("  内容 sha256 一致: %s\n",
        hash_file('sha256', $out) === hash_file('sha256', $file) ? 'YES' : 'NO');

    // Range 续传：模拟断点，取后半段
    $half = (int) ($size / 2);
    $ch = curlInit($base . '/download/' . $fin['file_id'], $jar);
    $fh2 = fopen(sys_get_temp_dir() . '/dl_part2.bin', 'wb');
    curl_setopt($ch, CURLOPT_FILE, $fh2);
    curl_setopt($ch, CURLOPT_RANGE, $half . '-');
    curl_exec($ch);
    $code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $got2  = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    curl_close($ch);
    fclose($fh2);
    $expect2 = $size - $half;
    printf("断点续传  HTTP %d  收到 %s  期望 %s  %s\n",
        $code2, bytes((int) $got2), bytes($expect2),
        (int) $got2 === $expect2 ? 'OK' : 'MISMATCH');

    // 拼接校验：后半段 == 原文件后半段
    $srcTail = file_get_contents($file, false, null, $half);
    $dlTail  = file_get_contents(sys_get_temp_dir() . '/dl_part2.bin');
    printf("  后续段内容一致: %s\n", $srcTail === $dlTail ? 'YES' : 'NO');

    @unlink($out);
    @unlink(sys_get_temp_dir() . '/dl_part2.bin');
}

@unlink($jar);
echo "\n完成。\n";
