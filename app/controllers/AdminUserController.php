<?php
/**
 * 后台：用户管理（等级 / 配额 / 状态）。
 */
declare(strict_types=1);

class AdminUserController
{
    public function index(): void
    {
        $q    = mb_substr(get('q'), 0, 64);
        $page = max(1, get_int('page', 1));
        $perPage = max(5, min(100, (int) setting('page_size', 20)));

        $where = '1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (u.`username` LIKE ? OR u.`nickname` LIKE ? OR u.`email` LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $params = [$like, $like, $like];
        }

        $total = (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('users') . ' u WHERE ' . $where, $params);
        $pager = paginate($total, $page, $perPage);

        $users = Db::all(
            'SELECT u.*, l.`name` AS level_name, l.`color` AS level_color, '
            . '(SELECT COUNT(*) FROM ' . Db::t('downloads') . ' d WHERE d.`user_id` = u.`id`) AS dl_count '
            . 'FROM ' . Db::t('users') . ' u '
            . 'LEFT JOIN ' . Db::t('levels') . ' l ON l.`id` = u.`level_id` '
            . 'WHERE ' . $where . ' '
            . 'ORDER BY u.`id` DESC '
            . 'LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset'],
            $params
        );

        view('admin/users', [
            'users'   => $users,
            'levels'  => levels_all(),
            'pager'   => $pager,
            'keyword' => $q,
            'active'  => 'users',
        ], 'admin');
    }

    public function update(): void
    {
        $id = (int) post('id');
        $u = $id > 0 ? Db::row('SELECT * FROM ' . Db::t('users') . ' WHERE `id` = ?', [$id]) : null;
        if ($u === null) {
            $this->respond(false, '用户不存在');
        }

        $data = ['updated_at' => now()];

        if (array_key_exists('level_id', $_POST)) {
            $lid = (int) $_POST['level_id'];
            if ($lid > 0 && level_by_id($lid) === null) {
                $this->respond(false, '等级不存在');
            }
            $data['level_id'] = max(0, $lid);
        }
        if (array_key_exists('download_quota', $_POST)) {
            $data['download_quota'] = max(0, (int) $_POST['download_quota']);
        }
        if (array_key_exists('quota_period', $_POST)) {
            $p = (string) $_POST['quota_period'];
            $data['quota_period'] = in_array($p, ['total', 'daily', 'monthly'], true) ? $p : 'total';
        }
        if (array_key_exists('status', $_POST)) {
            $st = (int) $_POST['status'];
            // 不允许把自己禁用
            if ($id === Auth::id() && $st !== 1) {
                $this->respond(false, '不能禁用自己的账号');
            }
            $data['status'] = $st === 1 ? 1 : 0;
        }
        if (array_key_exists('nickname', $_POST)) {
            $nk = trim((string) $_POST['nickname']);
            if ($nk !== '') {
                $data['nickname'] = mb_substr($nk, 0, 32);
            }
        }
        if (array_key_exists('password', $_POST) && (string) $_POST['password'] !== '') {
            $pw = (string) $_POST['password'];
            if (strlen($pw) < 6) {
                $this->respond(false, '密码至少 6 位');
            }
            $data['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
        }

        Db::update('users', $data, '`id` = ?', [$id]);
        admin_audit('update_user', 'user', $id, '更新用户：' . $u['username']);

        $this->respond(true, '已保存');
    }

    private function respond(bool $ok, string $msg): never
    {
        admin_respond($ok, $msg, '/admin/users');
    }
}
