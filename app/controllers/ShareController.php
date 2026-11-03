<?php
/**
 * 分享链接（前台）。
 *
 * 分享不受登录墙与等级限制 —— 它的语义就是"把这个文件开放给持有链接的人"，
 * 但仍受：文件是否有效、提取码、有效期、下载次数上限 约束。
 */
declare(strict_types=1);

class ShareController
{
    public function show(string $token): void
    {
        [$share, $file, $err] = $this->resolve($token);
        if ($err !== '') {
            $this->renderError($err);
        }

        $needCode = (string) $share['code'] !== '';
        $verified = $this->isVerified($token, $share);

        view('front/share', [
            'token'    => $token,
            'share'    => $share,
            'file'     => $file,
            'needCode' => $needCode,
            'verified' => $verified,
            'error'    => '',
        ], 'front');
    }

    /** 提交提取码 */
    /** 同一来源在窗口期内允许的提取码错误次数与窗口时长（秒） */
    private const CODE_MAX_FAIL = 5;
    private const CODE_LOCK_SECONDS = 900;

    /**
     * 统计当前 IP 对指定分享在窗口期内的提取码错误次数。
     *
     * 用数据库而不是 SESSION：SESSION 按浏览器隔离，清掉 cookie 计数就归零，
     * 攻击者循环换会话即可无限尝试。按 IP + token 记录才能挡住脚本。
     */
    private function codeFailCount(string $token): int
    {
        return (int) Db::val(
            'SELECT COUNT(*) FROM ' . Db::t('login_logs')
            . ' WHERE ip = ? AND username = ? AND msg = ? AND created_at > ?',
            [client_ip(), mb_substr($token, 0, 32), '提取码', date('Y-m-d H:i:s', time() - self::CODE_LOCK_SECONDS)]
        );
    }

    /** 记录一次提取码错误，供 codeFailCount() 统计 */
    private function logCodeFail(string $token): void
    {
        try {
            Db::insert('login_logs', [
                'user_id'    => 0,
                'username'   => mb_substr($token, 0, 32),
                'ip'         => client_ip(),
                'ua'         => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'status'     => 'fail',
                'msg'        => '提取码',
                'created_at' => now(),
            ]);
        } catch (Throwable) {
        }
    }

    public function unlock(string $token): void
    {
        [$share, $file, $err] = $this->resolve($token);
        if ($err !== '') {
            $this->renderError($err);
        }

        // 提取码只有 4 位，全量尝试只要几千次，必须限流
        if ($this->codeFailCount($token) >= self::CODE_MAX_FAIL) {
            $wait = (int) ceil(self::CODE_LOCK_SECONDS / 60);
            view('front/share', [
                'token'    => $token,
                'share'    => $share,
                'file'     => $file,
                'needCode' => true,
                'verified' => false,
                'error'    => "尝试过于频繁，请 {$wait} 分钟后再试",
            ], 'front');
            return;
        }

        $input = trim((string) ($_POST['code'] ?? ''));
        if ($input === '' || !hash_equals((string) $share['code'], $input)) {
            $this->logCodeFail($token);
            $failed = $this->codeFailCount($token);
            $left   = max(0, self::CODE_MAX_FAIL - $failed);
            $msg    = $left > 0
                ? "提取码不正确，还可尝试 {$left} 次"
                : '提取码连续错误次数过多，请 15 分钟后再试';

            view('front/share', [
                'token'    => $token,
                'share'    => $share,
                'file'     => $file,
                'needCode' => true,
                'verified' => false,
                'error'    => $msg,
            ], 'front');
            return;
        }

        $_SESSION['_share_ok'][$token] = time();
        redirect('/s/' . $token);
    }

    public function download(string $token): void
    {
        [$share, $file, $err] = $this->resolve($token);
        if ($err !== '') {
            $this->renderError($err);
        }

        if ((string) $share['code'] !== '' && !$this->isVerified($token, $share)) {
            redirect('/s/' . $token);
        }

        // 等级要求：分享链接不能成为绕过下载权限的通道。
        // 之前这里只校验了 token、提取码与下载次数，没有校验文件的
        // level_id，导致设置「超级会员可下载」的文件，普通会员甚至游客
        // 只要拿到分享链接就能下载（实测直接下载 403、走分享 200）。
        // is_public 与 login_required 不在这里拦：分享本身就是管理员
        // 主动发出的外链授权，语义上已经覆盖了这两项。
        $needLevelId = (int) ($file['level_id'] ?? 0);
        if (!can_access_level($needLevelId, Auth::user())) {
            $lv     = level_by_id($needLevelId);
            $lvName = $lv !== null ? $lv['name'] : '更高';
            $this->renderError('该资源需要「' . $lvName . '」及以上等级才能下载');
        }

        // 先确认实体存在，避免文件缺失也消耗下载名额
        $path = Storage::resolveFilePath($file);
        if ($path === '') {
            $this->renderError('文件实体缺失，请联系分享者');
        }

        // 原子占位：把「检查上限」与「计数 +1」合成一条 UPDATE。
        // 原来先读 downloads 再判断、最后另发一条 UPDATE 递增，
        // 两步之间存在窗口，并发请求会各自读到旧值而一起通过，
        // 导致实际下载次数超过 max_downloads。
        $max = (int) $share['max_downloads'];
        if ($max > 0) {
            $claimed = Db::exec(
                'UPDATE ' . Db::t('shares')
                . ' SET `downloads` = `downloads` + 1'
                . ' WHERE `id` = ? AND `downloads` < `max_downloads`',
                [(int) $share['id']]
            );
            if ($claimed === 0) {
                $this->renderError('该分享的下载次数已用完');
            }
        } else {
            Db::exec(
                'UPDATE ' . Db::t('shares') . ' SET `downloads` = `downloads` + 1 WHERE `id` = ?',
                [(int) $share['id']]
            );
        }

        $dlId = 0;
        try {
            $dlId = Db::insert('downloads', [
                'file_id'    => (int) $file['id'],
                'user_id'    => Auth::id(),
                'share_id'   => (int) $share['id'],
                'ip'         => client_ip(),
                'ua'         => user_agent(),
                'bytes_sent' => 0,
                'status'     => 'pending',
                'created_at' => now(),
            ]);
        } catch (Throwable) {
        }

        $res = Storage::stream($path, (string) $file['mime'], (string) $file['name']);

        if ($dlId > 0) {
            try {
                Db::update('downloads', [
                    'bytes_sent'  => $res['sent'],
                    'status'      => $res['aborted'] ? 'aborted' : 'done',
                    'finished_at' => now(),
                ], '`id` = ?', [$dlId]);
            } catch (Throwable) {
            }
        }
        if (!$res['aborted'] && $res['sent'] > 0) {
            try {
                Db::exec(
                    'UPDATE ' . Db::t('files') . ' SET `downloads` = `downloads` + 1 WHERE `id` = ?',
                    [(int) $file['id']]
                );
            } catch (Throwable) {
            }
        }
        exit;
    }

    // ---------- 内部 ----------

    /** @return array{0:?array,1:?array,2:string} */
    private function resolve(string $token): array
    {
        if (!preg_match('/^[a-zA-Z0-9]{8,24}$/', $token)) {
            return [null, null, '分享链接格式不正确'];
        }

        $share = Db::row(
            'SELECT * FROM ' . Db::t('shares') . ' WHERE `token` = ? AND `status` = 1 LIMIT 1',
            [$token]
        );
        if ($share === null) {
            return [null, null, '分享不存在或已被取消'];
        }

        $exp = $share['expires_at'] ?? null;
        if (!empty($exp) && strtotime((string) $exp) < time()) {
            return [null, null, '分享链接已过期'];
        }

        $max = (int) $share['max_downloads'];
        if ($max > 0 && (int) $share['downloads'] >= $max) {
            return [null, null, '分享的下载次数已用完'];
        }

        $file = Db::row(
            'SELECT * FROM ' . Db::t('files') . ' WHERE `id` = ? AND `deleted_at` IS NULL AND `status` = 1 LIMIT 1',
            [(int) $share['file_id']]
        );
        if ($file === null) {
            return [null, null, '分享的文件已被删除'];
        }

        return [$share, $file, ''];
    }

    private function isVerified(string $token, array $share): bool
    {
        if ((string) $share['code'] === '') {
            return true;
        }
        $at = (int) ($_SESSION['_share_ok'][$token] ?? 0);
        // 验证有效期 2 小时
        return $at > 0 && (time() - $at) < 7200;
    }

    private function renderError(string $msg): never
    {
        http_response_code(404);
        $navTitle = '分享不可用';
        ob_start();
        ?>
        <div class="empty" style="padding:80px 24px">
          <div class="empty-ico">
            <svg viewBox="0 0 48 48" width="64" height="64" fill="none" stroke="#D8D8D8" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20 28l8-8"/><path d="M24 16l3-3a6 6 0 018.5 8.5l-3 3"/>
              <path d="M24 32l-3 3a6 6 0 01-8.5-8.5l3-3"/>
            </svg>
          </div>
          <p class="empty-title"><?= e($msg) ?></p>
          <p class="empty-sub">请向分享者确认链接是否仍然有效</p>
          <a class="btn btn-ghost btn-sm" href="<?= url('/') ?>" style="margin-top:20px">返回首页</a>
        </div>
        <?php
        $content = ob_get_clean();
        require APP_PATH . '/views/layout/front.php';
        exit;
    }
}
