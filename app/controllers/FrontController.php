<?php
/**
 * 前台：资源列表、搜索。
 */
declare(strict_types=1);

class FrontController
{
    /** 前台是否允许游客查看 */
    private function guardGuest(): void
    {
        if (Auth::check()) {
            return;
        }
        if ((int) setting('guest_visible', 1) === 0) {
            redirect('/login?redirect=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'));
        }
    }

    public function index(): void
    {
        $this->guardGuest();

        $page    = max(1, get_int('page', 1));
        $perPage = max(1, min(100, (int) setting('page_size', 20)));
        $canDownload = $this->canDownload();

        $where  = 'f.`deleted_at` IS NULL AND f.`status` = 1 AND f.`is_public` = 1';
        [$visSql, $visParams] = $this->visibilityFilter('f');
        $where .= $visSql;

        $total  = (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('files') . ' f WHERE ' . $where, $visParams);
        $pager  = paginate($total, $page, $perPage);

        $files = Db::all(
            'SELECT f.`id`, f.`name`, f.`ext`, f.`size`, f.`downloads`, f.`created_at`, '
            . 'f.`level_id`, f.`view_level_id`, f.`login_required`, d.`name` AS folder_name '
            . 'FROM ' . Db::t('files') . ' f '
            . 'LEFT JOIN ' . Db::t('folders') . ' d ON d.`id` = f.`folder_id` '
            . 'WHERE ' . $where . ' '
            . 'ORDER BY f.`created_at` DESC '
            . 'LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset'],
            $visParams
        );

        $folders = Db::all(
            'SELECT `id`,`name`,(SELECT COUNT(*) FROM ' . Db::t('files') . ' f '
            . 'WHERE f.`folder_id` = ' . Db::t('folders') . '.`id` AND f.`deleted_at` IS NULL) AS `cnt` '
            . 'FROM ' . Db::t('folders')
            . ' WHERE `deleted_at` IS NULL AND `parent_id` = 0 AND `is_public` = 1'
            . ' ORDER BY `sort` ASC, `id` ASC LIMIT 12'
        );

        // 统计口径必须与列表一致，否则会出现「显示 30 个、实际只能看到 22 个」
        $stats = [
            'files'     => $total,
            'size'      => (int) Db::val(
                'SELECT COALESCE(SUM(f.`size`),0) FROM ' . Db::t('files') . ' f WHERE ' . $where,
                $visParams
            ),
            'downloads' => (int) Db::val(
                'SELECT COALESCE(SUM(f.`downloads`),0) FROM ' . Db::t('files') . ' f WHERE ' . $where,
                $visParams
            ),
        ];

        view('front/index', [
            'files'       => $files,
            'folders'     => $folders,
            'pager'       => $pager,
            'stats'       => $stats,
            'canDownload' => $canDownload,
            'keyword'     => '',
        ], 'front');
    }

    public function search(): void
    {
        $this->guardGuest();

        $kw = mb_substr(get('q'), 0, 64);
        $page = max(1, get_int('page', 1));
        $perPage = max(1, min(100, (int) setting('page_size', 20)));
        $canDownload = $this->canDownload();

        if ($kw === '') {
            redirect('/');
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $kw) . '%';
        $where = 'f.`deleted_at` IS NULL AND f.`status` = 1 AND f.`is_public` = 1 AND (f.`name` LIKE ? OR f.`orig_name` LIKE ?)';
        $params = [$like, $like];

        [$visSql, $visParams] = $this->visibilityFilter('f');
        $where .= $visSql;
        $params = array_merge($params, $visParams);

        $total = (int) Db::val(
            'SELECT COUNT(*) FROM ' . Db::t('files') . ' f WHERE ' . $where,
            $params
        );
        $pager = paginate($total, $page, $perPage);

        $files = Db::all(
            'SELECT f.`id`, f.`name`, f.`ext`, f.`size`, f.`downloads`, f.`created_at`, '
            . 'f.`level_id`, f.`view_level_id`, f.`login_required`, d.`name` AS folder_name '
            . 'FROM ' . Db::t('files') . ' f '
            . 'LEFT JOIN ' . Db::t('folders') . ' d ON d.`id` = f.`folder_id` '
            . 'WHERE ' . $where . ' ORDER BY f.`created_at` DESC '
            . 'LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset'],
            $params
        );

        view('front/index', [
            'files'       => $files,
            'folders'     => [],
            'pager'       => $pager,
            'stats'       => null,
            'canDownload' => $canDownload,
            'keyword'     => $kw,
        ], 'front');
    }

    /** 当前访客是否有下载权限（列表层） */
    private function canDownload(): bool
    {
        if (Auth::check()) {
            return true;
        }
        return (int) setting('require_login_download', 0) === 0;
    }

    /**
     * 可见性过滤条件（拼到带 f. 别名的 files 查询里）。
     *
     * - 未登录：排除 login_required = 1 的资源
     * - hide_locked = 1：等级不足的直接不出现在列表中
     * - hide_locked = 0：仍返回，由视图层显示为「锁定」
     *
     * @return array{0:string,1:array}
     */
    private function visibilityFilter(string $alias = 'f'): array
    {
        $user = Auth::user();
        $sql = '';
        $params = [];

        if ($user === null) {
            $sql .= " AND {$alias}.`login_required` = 0";
        }

        $isAdmin = $user !== null && ($user['role'] ?? '') === 'admin';
        if (!$isAdmin && (int) setting('hide_locked', 0) === 1) {
            $sort = user_level_sort($user);
            $lv = Db::t('levels');
            $sql .= " AND ({$alias}.`view_level_id` = 0 OR EXISTS ("
                . "SELECT 1 FROM {$lv} v WHERE v.`id` = {$alias}.`view_level_id` AND v.`sort` <= ?))";
            $params[] = $sort;
            $sql .= " AND ({$alias}.`level_id` = 0 OR EXISTS ("
                . "SELECT 1 FROM {$lv} d WHERE d.`id` = {$alias}.`level_id` AND d.`sort` <= ?))";
            $params[] = $sort;
        }

        return [$sql, $params];
    }

    /** 该资源是否对当前访客可见（视图层二次判断用） */
    private function isVisible(array $row): bool
    {
        $user = Auth::user();
        if ((int) ($row['login_required'] ?? 0) === 1 && $user === null) {
            return false;
        }
        if ((int) setting('hide_locked', 0) === 1) {
            return can_access_level((int) ($row['view_level_id'] ?? 0), $user)
                && can_access_level((int) ($row['level_id'] ?? 0), $user);
        }
        return true;
    }

    /** 分类总览：按文件夹浏览 */
    public function categories(): void
    {
        $this->guardGuest();

        [$visSql, $visParams] = $this->visibilityFilter('f');

        $folders = Db::all(
            'SELECT f.*, '
            . '(SELECT COUNT(*) FROM ' . Db::t('files') . ' x '
            . '  WHERE x.`folder_id` = f.`id` AND x.`deleted_at` IS NULL AND x.`status` = 1 AND x.`is_public` = 1) AS file_count, '
            . '(SELECT COUNT(*) FROM ' . Db::t('folders') . ' c '
            . '  WHERE c.`parent_id` = f.`id` AND c.`deleted_at` IS NULL AND c.`is_public` = 1) AS sub_count '
            . 'FROM ' . Db::t('folders') . ' f '
            . 'WHERE f.`deleted_at` IS NULL AND f.`parent_id` = 0 AND f.`is_public` = 1'
            . $visSql
            . ' ORDER BY f.`sort` ASC, f.`id` ASC',
            $visParams
        );

        $uncategorized = (int) Db::val(
            'SELECT COUNT(*) FROM ' . Db::t('files')
            . ' WHERE `folder_id` = 0 AND `deleted_at` IS NULL AND `status` = 1 AND `is_public` = 1'
        );

        $totalFiles = (int) Db::val(
            'SELECT COUNT(*) FROM ' . Db::t('files')
            . ' WHERE `deleted_at` IS NULL AND `status` = 1 AND `is_public` = 1'
        );

        view('front/categories', [
            'folders'       => $folders,
            'uncategorized' => $uncategorized,
            'totalFiles'    => $totalFiles,
            'canDownload'   => $this->canDownload(),
        ], 'front');
    }

    /** 文件夹详情：子目录 + 文件列表 */
    public function folder(string $id): void
    {
        $this->guardGuest();
        if (!ctype_digit($id)) {
            http_response_code(404);
            view('errors/404', [], 'front');
            return;
        }
        $folderId = (int) $id;

        $folder = Db::row(
            'SELECT * FROM ' . Db::t('folders')
            . ' WHERE `id` = ? AND `deleted_at` IS NULL AND `is_public` = 1 LIMIT 1',
            [$folderId]
        );
        if ($folder === null) {
            http_response_code(404);
            view('errors/404', [], 'front');
            return;
        }

        // 面包屑（带环路保护）
        $crumbs = [];
        $cur = $folder;
        $guard = 0;
        while ($cur !== null && $guard++ < 30) {
            array_unshift($crumbs, ['id' => (int) $cur['id'], 'name' => (string) $cur['name']]);
            $pid = (int) $cur['parent_id'];
            if ($pid === 0) {
                break;
            }
            $cur = Db::row(
                'SELECT `id`,`name`,`parent_id` FROM ' . Db::t('folders') . ' WHERE `id` = ?',
                [$pid]
            );
        }

        [$visSql, $visParams] = $this->visibilityFilter('f');

        $subs = Db::all(
            'SELECT f.*, '
            . '(SELECT COUNT(*) FROM ' . Db::t('files') . ' x '
            . '  WHERE x.`folder_id` = f.`id` AND x.`deleted_at` IS NULL AND x.`status` = 1 AND x.`is_public` = 1) AS file_count '
            . 'FROM ' . Db::t('folders') . ' f '
            . 'WHERE f.`parent_id` = ? AND f.`deleted_at` IS NULL AND f.`is_public` = 1'
            . $visSql
            . ' ORDER BY f.`sort` ASC, f.`id` ASC',
            array_merge([$folderId], $visParams)
        );

        $page = max(1, get_int('page', 1));
        $perPage = max(1, min(100, (int) setting('page_size', 20)));

        $where = 'f.`folder_id` = ? AND f.`deleted_at` IS NULL AND f.`status` = 1 AND f.`is_public` = 1';
        [$visSql2, $visParams2] = $this->visibilityFilter('f');
        $where .= $visSql2;

        $total = (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('files') . ' f WHERE ' . $where, array_merge([$folderId], $visParams2));
        $pager = paginate($total, $page, $perPage);

        $files = Db::all(
            'SELECT f.`id`, f.`name`, f.`ext`, f.`size`, f.`downloads`, f.`created_at`, '
            . 'f.`level_id`, f.`view_level_id`, f.`login_required`, d.`name` AS folder_name '
            . 'FROM ' . Db::t('files') . ' f '
            . 'LEFT JOIN ' . Db::t('folders') . ' d ON d.`id` = f.`folder_id` '
            . 'WHERE ' . $where . ' ORDER BY f.`created_at` DESC '
            . 'LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset'],
            array_merge([$folderId], $visParams2)
        );

        view('front/folder', [
            'folder'      => $folder,
            'crumbs'      => $crumbs,
            'subs'        => $subs,
            'files'       => $files,
            'pager'       => $pager,
            'canDownload' => $this->canDownload(),
        ], 'front');
    }
}
