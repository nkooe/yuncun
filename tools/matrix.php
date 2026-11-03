<?php
/**
 * 权限矩阵回归
 *
 * 一次性把「文件属性 × 身份 × 入口」全部跑完，而不是每次凭经验猜方向。
 *
 * 存在的理由：分享链接绕过 level_id 那个漏洞，如果一开始就按矩阵跑，
 * 第一次就能发现。之前的排查是「想到一个方向就测一个」，漏了分享入口。
 *
 *   php tools/matrix.php                          # 默认 http://127.0.0.1
 *   php tools/matrix.php https://example.com
 *   php tools/matrix.php https://example.com admin 你的密码
 *
 * 脚本会临时修改测试文件的属性，结束时恢复原值。
 * 退出码 0 表示矩阵全部符合预期。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = dirname(__DIR__);
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $root);
}
require $root . '/app/bootstrap.php';

// 默认用 https：站点强制 HTTPS 时 http:// 会 301 跳转，拿不到页面。
$base   = rtrim($argv[1] ?? 'https://127.0.0.1', '/');
$adminP = $argv[3] ?? '';

// 走 127.0.0.1 时必须带 Host 头，否则 nginx 找不到对应 vhost。
// 第四个参数可以直接指定，默认从 APP_ROOT 的 config 里取 site url。
$GLOBALS['hostHeader'] = $argv[4] ?? '';
if ($GLOBALS['hostHeader'] === '') {
    $h = parse_url($base, PHP_URL_HOST);
    if (!filter_var($h, FILTER_VALIDATE_IP)) {
        $GLOBALS['hostHeader'] = $h;      // 用真实域名访问时自动带上
    } else {
        $su = (string) setting('site_url', '');
        $sh2 = $su !== '' ? parse_url($su, PHP_URL_HOST) : '';
        $GLOBALS['hostHeader'] = $sh2 !== '' ? $sh2 : '';
    }
}

// ── HTTP 工具 ──────────────────────────────────────────────────

function req(string $url, array $opt = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING       => '',
    ]);

    if (!empty($opt['cookies'])) {
        $pairs = [];
        foreach ($opt['cookies'] as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $pairs));
    }
    if (!empty($opt['post'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['post']));
    }
    $headers = [];
    if (!empty($GLOBALS['hostHeader'])) {
        $headers[] = 'Host: ' . $GLOBALS['hostHeader'];
    }
    if (!empty($opt['range'])) {
        $headers[] = 'Range: ' . $opt['range'];
    }
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $head = substr($raw, 0, $hlen);
    $body = substr($raw, $hlen);

    $cookies = [];
    if (preg_match_all('/^Set-Cookie:\s*([^=]+)=([^;]*)/mi', $head, $m, PREG_SET_ORDER)) {
        foreach ($m as $one) {
            $cookies[trim($one[1])] = trim($one[2]);
        }
    }

    return [$code, $body, $cookies, $head];
}

function csrf(string $body): string
{
    return preg_match('/name="_token"\s+value="([^"]+)"/', $body, $m) ? $m[1] : '';
}

/** 登录一个账号，返回可用 cookie */
function login(string $base, string $user, string $pass): array
{
    [$c, $b, $ck] = req($base . '/login');
    $tk = csrf($b);
    if ($tk === '') {
        return [];
    }
    [$c2, , $ck2] = req($base . '/login', [
        'post'    => ['_token' => $tk, 'username' => $user, 'password' => $pass],
        'cookies' => $ck,
    ]);
    return array_merge($ck, $ck2);
}

$pass = 0;
$fail = 0;
$skip = 0;

function ok(string $m): void
{
    global $pass;
    $pass++;
    printf("  \033[32m✓\033[0m %s\n", $m);
}

function bad(string $m, string $want, string $got): void
{
    global $fail;
    $fail++;
    printf("  \033[31m✗\033[0m %s\n      期望 %s，实际 %s\n", $m, $want, $got);
}

function skip(string $m, string $why): void
{
    global $skip;
    $skip++;
    printf("  \033[33m-\033[0m %s  (%s)\n", $m, $why);
}

// ── 准备数据 ────────────────────────────────────────────────────

$file = Db::row('SELECT * FROM ' . Db::t('files') . ' WHERE `deleted_at` IS NULL AND `status` = 1 ORDER BY `id` ASC LIMIT 1');
if ($file === null) {
    fwrite(STDERR, "没有可用文件，先跑 tools/seed.php\n");
    exit(1);
}
$fid = (int) $file['id'];
$orig = [
    'status'         => (int) $file['status'],
    'is_public'      => (int) $file['is_public'],
    'login_required' => (int) $file['login_required'],
    'level_id'       => (int) $file['level_id'],
    'view_level_id'  => (int) $file['view_level_id'],
];

// 确保这个文件有一个带提取码的分享，用于测分享入口
$share = Db::row('SELECT * FROM ' . Db::t('shares') . ' WHERE `file_id` = ? LIMIT 1', [$fid]);
if ($share === null) {
    Db::insert('shares', [
        'file_id'       => $fid,
        'token'         => bin2hex(random_bytes(6)),
        'code'          => 'test',
        'max_downloads' => 0,
        'downloads'     => 0,
        'status'        => 1,
        'created_at'    => now(),
    ]);
    $share = Db::row('SELECT * FROM ' . Db::t('shares') . ' WHERE `file_id` = ? LIMIT 1', [$fid]);
}
$token = (string) $share['token'];
$code  = (string) $share['code'];

$origShare = ['code' => $code, 'downloads' => (int) $share['downloads']];

echo "\n权限矩阵 · {$base}\n";
echo "测试文件: #{$fid} {$file['name']}\n";
echo "分享 token: {$token}\n";
echo str_repeat('─', 78), "\n";

// 身份
$ids = [
    '游客'   => [],
    'user'   => login($base, 'demo_user', 'Demo@12345'),
    'vip'    => login($base, 'demo_vip', 'Demo@12345'),
    'super'  => login($base, 'demo_super', 'Demo@12345'),
];
if ($adminP !== '') {
    $adm = login($base, $argv[2] ?? 'admin', $adminP);
    if ($adm) {
        $ids['admin'] = $adm;
    }
}

echo "Host 头: " . ($GLOBALS['hostHeader'] ?: '(未设置)') . "\n";
$okIds = [];
foreach ($ids as $k => $v) {
    if (!empty($v)) {
        $okIds[] = $k;
    }
}
echo "登录成功: " . ($okIds ? implode(', ', $okIds) : '全部失败（检查账号密码）') . "\n";
if (!$okIds || $okIds === ['游客']) {
    fwrite(STDERR, "\n没有任何身份登录成功，矩阵无法运行。\n");
    exit(1);
}

$has = fn(string $k) => !empty($ids[$k]);

/** 走 /download/{id} 取结果码 */
function hitDownload(string $base, int $fid, array $ck): int
{
    [$c] = req($base . '/download/' . $fid, ['cookies' => $ck, 'range' => 'bytes=0-1023']);
    return $c;
}

/** 走 /s/{token}/download 取结果码（自动处理提取码） */
function hitShare(string $base, string $token, string $code, array $ck): int
{
    [$c0, $b0, $ck0] = req($base . '/s/' . $token, ['cookies' => $ck]);
    $all = array_merge($ck, $ck0);

    if ($code !== '') {
        $tk = csrf($b0);
        if ($tk !== '') {
            [, , $ck1] = req($base . '/s/' . $token, [
                'post'    => ['_token' => $tk, 'code' => $code],
                'cookies' => $all,
            ]);
            $all = array_merge($all, $ck1);
        }
    }

    [$c] = req($base . '/s/' . $token . '/download', ['cookies' => $all, 'range' => 'bytes=0-1023']);
    return $c;
}

/** 断言一组入口的结果 */
function assertPair(string $label, int $gotDl, int $gotSh, array $wantDl, array $wantSh): void
{
    $dOk = in_array($gotDl, $wantDl, true);
    $sOk = in_array($gotSh, $wantSh, true);

    if ($dOk && $sOk) {
        ok(sprintf('%-34s 下载 %d  分享 %d', $label, $gotDl, $gotSh));
    } else {
        bad(
            sprintf('%-34s 下载 %d  分享 %d', $label, $gotDl, $gotSh),
            sprintf('下载 ∈ {%s}  分享 ∈ {%s}', implode(',', $wantDl), implode(',', $wantSh)),
            sprintf('下载 %d  分享 %d', $gotDl, $gotSh)
        );
    }
}

// ── 组合 A：正常公开 ────────────────────────────────────────────
echo "\n[A] 正常公开  status=1 is_public=1 login=0 level=0\n";
Db::update('files', [
    'status' => 1, 'is_public' => 1, 'login_required' => 0,
    'level_id' => 0, 'view_level_id' => 0,
], '`id` = ?', [$fid]);

foreach (['游客', 'user', 'vip', 'super'] as $who) {
    if (!$has($who)) {
        continue;
    }
    $dl = hitDownload($base, $fid, $ids[$who]);
    $sh = hitShare($base, $token, $code, $ids[$who]);
    // 游客可能被全局 require_login_download 拦住（302 跳登录）
    $wantDl = $who === '游客' ? [200, 206, 302] : [200, 206];
    assertPair($who, $dl, $sh, $wantDl, [200, 206]);
}

// ── 组合 B：仅登录可见 ──────────────────────────────────────────
echo "\n[B] 仅登录  status=1 is_public=1 login=1 level=0\n";
Db::update('files', ['login_required' => 1], '`id` = ?', [$fid]);

$dl = hitDownload($base, $fid, $ids['游客']);
$sh = hitShare($base, $token, $code, $ids['游客']);
assertPair('游客（应被拦）', $dl, $sh, [302, 403, 404], [200, 206]);
foreach (['user', 'vip', 'super'] as $who) {
    if (!$has($who)) {
        continue;
    }
    $dl = hitDownload($base, $fid, $ids[$who]);
    $sh = hitShare($base, $token, $code, $ids[$who]);
    assertPair($who . '（应通过）', $dl, $sh, [200, 206], [200, 206]);
}
Db::update('files', ['login_required' => 0], '`id` = ?', [$fid]);

// ── 组合 C / D：等级要求 ────────────────────────────────────────
foreach ([2 => 'VIP', 3 => '超级会员'] as $lvId => $lvName) {
    echo "\n[等级] 要求 {$lvName}  level_id={$lvId}\n";
    Db::update('files', ['level_id' => $lvId], '`id` = ?', [$fid]);

    $cases = [
        '游客'  => [403, 404, 302],
        'user'  => [403, 404],
        'vip'   => $lvId <= 2 ? [200, 206] : [403, 404],
        'super' => [200, 206],
    ];
    foreach ($cases as $who => $want) {
        if (!$has($who)) {
            continue;
        }
        $dl = hitDownload($base, $fid, $ids[$who]);
        $sh = hitShare($base, $token, $code, $ids[$who]);
        assertPair($who, $dl, $sh, $want, $want);
    }
    Db::update('files', ['level_id' => 0], '`id` = ?', [$fid]);
}

// ── 组合 E：未公开 ──────────────────────────────────────────────
echo "\n[E] 未公开  is_public=0\n";
Db::update('files', ['is_public' => 0], '`id` = ?', [$fid]);

$dl = hitDownload($base, $fid, $ids['super']);
$sh = hitShare($base, $token, $code, $ids['super']);
assertPair('super（下载应拒，分享应放）', $dl, $sh, [403, 404], [200, 206]);

if ($has('admin')) {
    $dl = hitDownload($base, $fid, $ids['admin']);
    assertPair('admin（应通过）', $dl, 200, [200, 206], [200, 206]);
}
Db::update('files', ['is_public' => 1], '`id` = ?', [$fid]);

// ── 组合 F：已禁用 ──────────────────────────────────────────────
echo "\n[F] 已禁用  status=0\n";
Db::update('files', ['status' => 0], '`id` = ?', [$fid]);

foreach (['user', 'super'] as $who) {
    if (!$has($who)) {
        continue;
    }
    $dl = hitDownload($base, $fid, $ids[$who]);
    $sh = hitShare($base, $token, $code, $ids[$who]);
    assertPair($who, $dl, $sh, [403, 404], [403, 404]);
}
Db::update('files', ['status' => 1], '`id` = ?', [$fid]);

// ── 组合 G：已删除 ──────────────────────────────────────────────
echo "\n[G] 已进回收站  deleted_at != NULL\n";
Db::update('files', ['deleted_at' => now()], '`id` = ?', [$fid]);

if ($has('super')) {
    $dl = hitDownload($base, $fid, $ids['super']);
    $sh = hitShare($base, $token, $code, $ids['super']);
    assertPair('super', $dl, $sh, [403, 404], [403, 404]);
}
Db::update('files', ['deleted_at' => null], '`id` = ?', [$fid]);

// ── 元信息入口：不该出现的不能出现 ───────────────────────────────
echo "\n[元信息] 未公开文件是否从列表泄露\n";
Db::update('files', ['is_public' => 0], '`id` = ?', [$fid]);
$needle = mb_substr((string) $file['name'], 0, 8);

foreach (['游客' => [], 'super' => $ids['super'] ?? []] as $who => $ck) {
    if ($who === 'super' && !$has('super')) {
        continue;
    }
    foreach (['/' => '首页', '/categories' => '分类', '/folder/' . (int) $file['folder_id'] => '文件夹'] as $p => $label) {
        [$c, $b] = req($base . $p, ['cookies' => $ck]);
        $found = $needle !== '' && mb_strpos($b, $needle) !== false;
        if ($found) {
            bad("{$who} {$label} 泄露未公开文件", '不出现', '出现了');
        } else {
            ok("{$who} {$label} 未泄露");
        }
    }
}
Db::update('files', ['is_public' => 1], '`id` = ?', [$fid]);

// ── 恢复 ────────────────────────────────────────────────────────
Db::update('files', $orig, '`id` = ?', [$fid]);
Db::update('shares', ['downloads' => $origShare['downloads']], '`id` = ?', [(int) $share['id']]);

echo "\n", str_repeat('─', 78), "\n";
printf("  通过 %d  失败 %d  跳过 %d\n", $pass, $fail, $skip);
echo "  已恢复测试文件的原始属性\n\n";
exit($fail > 0 ? 1 : 0);
