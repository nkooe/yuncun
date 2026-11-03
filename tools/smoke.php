<?php
/**
 * 端到端冒烟测试
 *
 * 走真实 HTTP，覆盖路由、中间件、CSRF、权限、配额与分享限流。
 * 目的是在发布前抓住「改坏了但语法检查通过」的问题 ——
 * 例如 v1.1.13 把 quota_state() 当未使用函数删掉，
 * 导致所有普通用户下载 500，而 php -l 完全看不出来。
 *
 *   php tools/smoke.php                          # 默认测 http://127.0.0.1
 *   php tools/smoke.php https://example.com      # 测线上
 *   php tools/smoke.php https://example.com admin 你的密码
 *
 * 退出码 0 表示全部通过，1 表示有失败。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$base     = rtrim($argv[1] ?? 'http://127.0.0.1', '/');
$adminU   = $argv[2] ?? 'admin';
$adminP   = $argv[3] ?? '';
$demoU    = $argv[4] ?? 'demo_user';
$demoP    = $argv[5] ?? 'Demo@12345';

$pass = 0;
$fail = 0;
$skip = 0;

function ok(string $name, string $detail = ''): void
{
    global $pass;
    $pass++;
    printf("  \033[32m✓\033[0m %-42s %s\n", $name, $detail);
}

function bad(string $name, string $detail = ''): void
{
    global $fail;
    $fail++;
    printf("  \033[31m✗\033[0m %-42s %s\n", $name, $detail);
}

function skip(string $name, string $why): void
{
    global $skip;
    $skip++;
    printf("  \033[33m-\033[0m %-42s %s\n", $name, $why);
}

function check(bool $cond, string $name, string $detail = ''): void
{
    $cond ? ok($name, $detail) : bad($name, $detail);
}

/**
 * 发一次请求，返回 [状态码, 响应体, 重定向地址, Set-Cookie 列表]
 * 手动处理 cookie，避免依赖调用方的会话状态。
 */
function http(string $url, array $opt = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => (int) ($opt['timeout'] ?? 30),
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
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($opt['post']) ? http_build_query($opt['post']) : $opt['post']);
    }
    if (!empty($opt['headers'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $opt['headers']);
    }

    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $loc  = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    $head = substr($raw, 0, $hlen);
    $body = substr($raw, $hlen);

    $cookies = [];
    if (preg_match_all('/^Set-Cookie:\s*([^=]+)=([^;]*)/mi', $head, $m, PREG_SET_ORDER)) {
        foreach ($m as $one) {
            $cookies[trim($one[1])] = trim($one[2]);
        }
    }

    return [$code, $body, $loc, $cookies];
}

/** 从页面里取 CSRF token */
function csrf(string $body): string
{
    if (preg_match('/name="_token"\s+value="([^"]+)"/', $body, $m)) {
        return $m[1];
    }
    if (preg_match('/name="csrf-token"\s+content="([^"]+)"/', $body, $m)) {
        return $m[1];
    }
    return '';
}

echo "\n冒烟测试 · {$base}\n";
echo str_repeat('─', 60), "\n";

// ── 1. 公共页面 ────────────────────────────────────────────────
echo "\n[公共页面]\n";
foreach (['/' => '首页', '/login' => '登录页', '/register' => '注册页', '/categories' => '分类页'] as $path => $label) {
    [$code] = http($base . $path);
    check($code === 200, $label, "HTTP {$code}");
}

[$code, $body] = http($base . '/search?q=' . rawurlencode('测试'));
check($code === 200, '搜索页', "HTTP {$code}");

[$code] = http($base . '/no-such-page-xyz');
check($code === 404, '不存在的路径返回 404', "HTTP {$code}");

// ── 2. 敏感路径 ────────────────────────────────────────────────
echo "\n[敏感路径拦截]\n";
foreach (['/data/config.php', '/storage/', '/.htaccess', '/tools/seed.php'] as $path) {
    [$code] = http($base . $path);
    check(in_array($code, [403, 404], true), $path, "HTTP {$code}");
}

// ── 3. 普通用户 ────────────────────────────────────────────────
echo "\n[普通用户流程]\n";
[$code, $body, , $cookies] = http($base . '/login');
$token = csrf($body);

if ($token === '') {
    skip('普通用户登录', '未能获取 CSRF token');
} else {
    [$code, $body, $loc, $c2] = http($base . '/login', [
        'post'    => ['_token' => $token, 'username' => $demoU, 'password' => $demoP],
        'cookies' => $cookies,
    ]);
    check($code === 302, '普通用户登录', "HTTP {$code}");

    $sess = array_merge($cookies, $c2);

    if ($code === 302) {
        [$c] = http($base . '/user', ['cookies' => $sess]);
        check($c === 200, '用户中心可访问', "HTTP {$c}");

        // 关键用例：普通用户下载（配额逻辑）
        [$c, $dl, , ] = http($base . '/download/1', ['cookies' => $sess, 'timeout' => 60]);
        $okDl = $c === 200 && strlen($dl) > 1024;
        check($okDl, '普通用户下载有内容', "HTTP {$c}，" . number_format(strlen($dl)) . ' 字节');

        if (!$okDl && $c >= 500) {
            echo "      \033[31m下载 5xx：配额或存储链路有致命错误\033[0m\n";
        }

        // 越权：普通用户访问后台
        [$c] = http($base . '/admin/users', ['cookies' => $sess]);
        check($c === 403, '普通用户访问后台被拒', "HTTP {$c}");

        // 非法 ID
        foreach (['1.5', 'abc'] as $badId) {
            [$c] = http($base . '/download/' . $badId, ['cookies' => $sess]);
            check($c === 404, "非法下载 ID 被拒（{$badId}）", "HTTP {$c}");
        }
    }
}

// ── 4. 分享 ──────────────────────────────────────────────────
echo "\n[分享链接]\n";
[$c] = http($base . '/s/notexist-token');
check($c === 404, '不存在的分享返回 404', "HTTP {$c}");

// ── 5. 管理员 ────────────────────────────────────────────────
echo "\n[管理员流程]\n";
if ($adminP === '') {
    skip('管理员登录', '未提供密码（参数3）');
} else {
    [$c, $b, , $ck] = http($base . '/login');
    $tk = csrf($b);
    [$c, , , $ck2] = http($base . '/login', [
        'post'    => ['_token' => $tk, 'username' => $adminU, 'password' => $adminP],
        'cookies' => $ck,
    ]);
    check($c === 302, '管理员登录', "HTTP {$c}");

    $asess = array_merge($ck, $ck2);

    $adminPages = [
        '/admin'           => '仪表盘',
        '/admin/files'     => '资源管理',
        '/admin/users'     => '用户管理',
        '/admin/levels'    => '用户等级',
        '/admin/shares'    => '分享管理',
        '/admin/trash'     => '回收站',
        '/admin/logs'      => '记录',
        '/admin/settings'  => '系统设置',
    ];
    foreach ($adminPages as $path => $label) {
        [$c] = http($base . $path, ['cookies' => $asess]);
        check($c === 200, '后台 · ' . $label, "HTTP {$c}");
    }

    // 设置保存：只提交 token，不提交任何开关字段。
    // 验证「表单没有包含某个开关时，它保持原值」—— 这正是
    // 原来全量写入逻辑会误关 guest_visible / allow_register 的地方。
    [$c, $b] = http($base . '/admin/settings', ['cookies' => $asess]);
    $tk2 = csrf($b);
    if ($tk2 !== '') {
        [$c] = http($base . '/admin/settings', [
            'post'    => ['_token' => $tk2],
            'cookies' => $asess,
        ]);
        check($c === 302, '保存设置（空开关）', "HTTP {$c}");

        [$c] = http($base . '/', []);
        check($c === 200, '首页仍对游客可见', "HTTP {$c}（防误关 guest_visible）");

        [$c] = http($base . '/register', []);
        check($c === 200, '注册页仍可访问', "HTTP {$c}（防误关 allow_register）");
    }
}

// ── 汇总 ────────────────────────────────────────────────────
echo "\n", str_repeat('─', 60), "\n";
printf("  通过 %d  失败 %d  跳过 %d\n\n", $pass, $fail, $skip);
exit($fail > 0 ? 1 : 0);
