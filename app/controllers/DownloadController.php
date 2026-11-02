<?php
/**
 * 下载：权限校验 + 配额扣减 + Range 流式输出。
 *
 * 校验顺序（任一失败即拦截）：
 *   文件存在 → 未删除 → 公开性 → 登录要求 → 等级要求 → 下载配额
 */
declare(strict_types=1);

class DownloadController
{
    public function file(string $id): void
    {
        $fileId = (int) $id;

        $file = Db::row(
            'SELECT * FROM ' . Db::t('files')
            . ' WHERE `id` = ? AND `deleted_at` IS NULL AND `status` = 1 LIMIT 1',
            [$fileId]
        );
        if ($file === null) {
            $this->deny('文件不存在或已被删除', 404);
        }

        $user = Auth::user();
        $isAdmin = $user !== null && ($user['role'] ?? '') === 'admin';

        // 1. 非公开文件仅管理员可见
        if ((int) $file['is_public'] !== 1 && !$isAdmin) {
            $this->deny('该资源未公开', 403);
        }

        // 2. 登录要求（全局开关）
        if ($user === null && (int) setting('require_login_download', 0) === 1) {
            $back = '/download/' . $fileId;
            redirect('/login?redirect=' . rawurlencode($back));
        }

        // 2.5 文件级登录要求（该文件设置为「仅登录可见」）
        if ($user === null && (int) ($file['login_required'] ?? 0) === 1) {
            $back = '/download/' . $fileId;
            redirect('/login?redirect=' . rawurlencode($back));
        }

        // 3. 等级要求
        $needLevelId = (int) ($file['level_id'] ?? 0);
        if (!can_access_level($needLevelId, $user)) {
            $lv = level_by_id($needLevelId);
            $lvName = $lv !== null ? $lv['name'] : '更高';
            $this->deny('该资源需要「' . $lvName . '」及以上等级才能下载', 403, true);
        }

        // 4. 配额
        if ($user !== null && !$isAdmin) {
            $q = quota_consume($user);
            if (!$q['ok']) {
                $this->deny(
                    '今日下载次数已用完（' . $q['state']['used'] . '/' . $q['state']['quota'] . '），请稍后再试',
                    403,
                    true
                );
            }
        }

        // 5. 记录开始
        $dlId = 0;
        try {
            $dlId = Db::insert('downloads', [
                'file_id'    => $fileId,
                'user_id'    => $user !== null ? (int) $user['id'] : 0,
                'share_id'   => 0,
                'ip'         => client_ip(),
                'ua'         => user_agent(),
                'bytes_sent' => 0,
                'status'     => 'pending',
                'created_at' => now(),
            ]);
        } catch (Throwable) {
        }

        $this->stream($file, $dlId, $isAdmin);
    }

    /** 物理定位：优先按 sha256，兼容旧数据用 storage_key */
    private function resolvePath(array $file): string
    {
        $sha = (string) ($file['sha256'] ?? '');
        if ($sha !== '' && Storage::exists($sha)) {
            return Storage::absPath($sha);
        }
        $key = (string) ($file['storage_key'] ?? '');
        if ($key !== '') {
            $p = STORAGE_PATH . '/files/' . ltrim(str_replace('..', '', $key), '/');
            if (is_file($p)) {
                return $p;
            }
        }
        return '';
    }

    private function stream(array $file, int $dlId, bool $isAdmin): never
    {
        $path = $this->resolvePath($file);
        if ($path === '') {
            $this->deny('文件实体缺失，请联系管理员', 410);
        }

        $size = (int) filesize($path);
        if ($size <= 0) {
            $this->deny('空文件无法下载', 410);
        }

        // 统一由 Storage 处理：缓冲清理、session 释放、Range 解析、分块输出
        $res = Storage::stream($path, (string) $file['mime'], (string) $file['name']);
        $sent = $res['sent'];
        $aborted = $res['aborted'];

        // 回写记录
        if ($dlId > 0) {
            try {
                Db::update('downloads', [
                    'bytes_sent'  => $sent,
                    'status'      => $aborted ? 'aborted' : 'done',
                    'finished_at' => now(),
                ], '`id` = ?', [$dlId]);
            } catch (Throwable) {
            }
        }

        if (!$aborted && $sent > 0) {
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

    private function deny(string $msg, int $code = 403, bool $needLevel = false): never
    {
        http_response_code($code);

        if (Auth::wantsJson()) {
            json_out(['ok' => false, 'msg' => $msg], $code);
        }

        $navTitle = '无法下载';
        ob_start();
        ?>
        <div class="empty" style="padding:72px 24px">
          <div class="empty-ico">
            <svg viewBox="0 0 48 48" width="64" height="64" fill="none" stroke="#D8D8D8" stroke-width="1.6" stroke-linecap="round">
              <rect x="10" y="21" width="28" height="19" rx="4"/>
              <path d="M17 21v-5a7 7 0 0114 0v5"/>
              <circle cx="24" cy="30" r="2.4" fill="#D8D8D8" stroke="none"/>
            </svg>
          </div>
          <p class="empty-title"><?= e($msg) ?></p>
          <p class="empty-sub"><?= $needLevel ? '升级等级后即可下载' : '请返回上一页重试' ?></p>
          <div style="display:flex;gap:10px;margin-top:20px">
            <a class="btn btn-ghost btn-sm" href="<?= url('/') ?>">返回首页</a>
            <?php if ($needLevel && !Auth::check()): ?>
              <a class="btn btn-primary btn-sm" href="<?= url('/login') ?>">登录账号</a>
            <?php endif; ?>
          </div>
        </div>
        <?php
        $content = ob_get_clean();
        require APP_PATH . '/views/layout/front.php';
        exit;
    }
}
