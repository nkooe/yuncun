<?php
/**
 * 后台：分享链接管理。
 */
declare(strict_types=1);

class AdminShareController
{
    public function index(): void
    {
        $page = max(1, get_int('page', 1));
        $perPage = max(5, min(100, (int) setting('page_size', 20)));

        $total = (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('shares'));
        $pager = paginate($total, $page, $perPage);

        $rows = Db::all(
            'SELECT s.*, f.`name` AS file_name, f.`ext`, f.`size` '
            . 'FROM ' . Db::t('shares') . ' s '
            . 'LEFT JOIN ' . Db::t('files') . ' f ON f.`id` = s.`file_id` '
            . 'ORDER BY s.`id` DESC '
            . 'LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset']
        );

        view('admin/shares', [
            'rows'   => $rows,
            'pager'  => $pager,
            'active' => 'shares',
        ], 'admin');
    }

    /** 为某个文件生成分享链接 */
    public function create(): void
    {
        $fileId = (int) post('file_id');
        $file = $fileId > 0
            ? Db::row('SELECT * FROM ' . Db::t('files') . ' WHERE `id` = ? AND `deleted_at` IS NULL', [$fileId])
            : null;
        if ($file === null) {
            $this->respond(false, '文件不存在');
        }

        $code = trim((string) post('code'));
        if ($code === '') {
            // 4 位随机提取码
            $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
            $code = '';
            for ($i = 0; $i < 4; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
        }
        if (mb_strlen($code) > 16) {
            $this->respond(false, '提取码不能超过 16 位');
        }

        $days = (int) post('days', '0');
        $max  = (int) post('max_downloads', '0');

        $token = bin2hex(random_bytes(6)); // 12 位
        $expiresAt = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;

        $id = Db::insert('shares', [
            'file_id'       => $fileId,
            'user_id'       => Auth::id(),
            'token'         => $token,
            'code'          => $code,
            'max_downloads' => max(0, $max),
            'downloads'     => 0,
            'expires_at'    => $expiresAt,
            'status'        => 1,
            'created_at'    => now(),
        ]);

        $url = $this->absoluteUrl('/s/' . $token);

        admin_audit(
            'create_share',
            'share',
            (int) $id,
            sprintf('创建分享：文件 #%d，提取码 %s', $fileId, $code !== '' ? $code : '无')
        );

        $this->respond(true, '分享链接已生成', [
            'id'    => $id,
            'token' => $token,
            'code'  => $code,
            'url'   => $url,
        ]);
    }

    /** 取消分享 */
    public function revoke(): void
    {
        $id = (int) post('id');
        if ($id <= 0) {
            $this->respond(false, '参数错误');
        }
        $n = Db::exec('UPDATE ' . Db::t('shares') . ' SET `status` = 0 WHERE `id` = ?', [$id]);
        if ($n > 0) {
            admin_audit('revoke_share', 'share', $id, '取消分享 #' . $id);
        }
        $this->respond($n > 0, $n > 0 ? '已取消分享' : '分享不存在');
    }

    // ---------- 内部 ----------

    private function absoluteUrl(string $path): string
    {
        $siteUrl = (string) setting('site_url', '');
        if ($siteUrl !== '') {
            return rtrim($siteUrl, '/') . $path;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host . base_path() . $path;
    }

    private function respond(bool $ok, string $msg, array $extra = []): never
    {
        admin_respond($ok, $msg, '/admin/shares', $extra);
    }
}
