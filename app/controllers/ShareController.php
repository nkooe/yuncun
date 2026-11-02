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
    public function unlock(string $token): void
    {
        [$share, $file, $err] = $this->resolve($token);
        if ($err !== '') {
            $this->renderError($err);
        }

        $input = trim((string) ($_POST['code'] ?? ''));
        if ($input === '' || !hash_equals((string) $share['code'], $input)) {
            view('front/share', [
                'token'    => $token,
                'share'    => $share,
                'file'     => $file,
                'needCode' => true,
                'verified' => false,
                'error'    => '提取码不正确',
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

        // 次数上限
        $max = (int) $share['max_downloads'];
        $done = (int) $share['downloads'];
        if ($max > 0 && $done >= $max) {
            $this->renderError('该分享的下载次数已用完');
        }

        $path = Storage::resolveFilePath($file);
        if ($path === '') {
            $this->renderError('文件实体缺失，请联系分享者');
        }

        // 计数
        Db::exec(
            'UPDATE ' . Db::t('shares') . ' SET `downloads` = `downloads` + 1 WHERE `id` = ?',
            [(int) $share['id']]
        );

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
