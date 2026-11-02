<?php
/**
 * 后台：资源（文件）管理。
 */
declare(strict_types=1);

class AdminFileController
{
    public function index(): void
    {
        $folderId = get_int('folder_id', 0);
        $q        = mb_substr(get('q'), 0, 64);
        $page     = max(1, get_int('page', 1));
        $perPage  = max(5, min(100, (int) setting('page_size', 20)));

        $where  = ['f.`deleted_at` IS NULL'];
        $params = [];

        if ($folderId > 0) {
            $where[] = 'f.`folder_id` = ?';
            $params[] = $folderId;
        }
        if ($q !== '') {
            $where[] = '(f.`name` LIKE ? OR f.`orig_name` LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('files') . ' f WHERE ' . $whereSql, $params);
        $pager = paginate($total, $page, $perPage);

        $files = Db::all(
            'SELECT f.*, d.`name` AS folder_name '
            . 'FROM ' . Db::t('files') . ' f '
            . 'LEFT JOIN ' . Db::t('folders') . ' d ON d.`id` = f.`folder_id` '
            . 'WHERE ' . $whereSql . ' '
            . 'ORDER BY f.`id` DESC '
            . 'LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset'],
            $params
        );

        $folders = Db::all(
            'SELECT `id`,`name`,`parent_id` FROM ' . Db::t('folders')
            . ' WHERE `deleted_at` IS NULL ORDER BY `sort` ASC, `id` ASC'
        );

        $totals = [
            'count' => (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('files') . ' WHERE `deleted_at` IS NULL'),
            'size'  => (int) Db::val('SELECT COALESCE(SUM(`size`),0) FROM ' . Db::t('files') . ' WHERE `deleted_at` IS NULL'),
            'trash' => (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('files') . ' WHERE `deleted_at` IS NOT NULL'),
        ];

        view('admin/files', [
            'files'      => $files,
            'folders'    => $folders,
            'folderTree' => self::buildTree($folders),
            'levels'     => levels_all(),
            'pager'      => $pager,
            'totals'     => $totals,
            'curFolder'  => $folderId,
            'keyword'    => $q,
            'freeSpace'  => Storage::freeSpace(),
            'maxUpload'  => (int) setting('max_upload_size', 2147483648),
            'active'     => 'files',
        ], 'admin');
    }

    /** 新建/更新单个文件属性 */
    public function update(): void
    {
        $id = (int) post('id');
        if ($id <= 0) {
            flash('error', '参数错误');
            redirect('/admin/files');
        }

        $file = Db::row('SELECT * FROM ' . Db::t('files') . ' WHERE `id` = ? AND `deleted_at` IS NULL', [$id]);
        if ($file === null) {
            flash('error', '文件不存在');
            redirect('/admin/files');
        }

        $data = ['updated_at' => now()];

        if (array_key_exists('name', $_POST)) {
            $name = trim((string) $_POST['name']);
            if ($name !== '' && mb_strlen($name) <= 255) {
                $data['name'] = $name;
            }
        }
        if (array_key_exists('folder_id', $_POST)) {
            $fid = (int) $_POST['folder_id'];
            if ($fid > 0) {
                $exists = Db::val(
                    'SELECT 1 FROM ' . Db::t('folders') . ' WHERE `id` = ? AND `deleted_at` IS NULL',
                    [$fid]
                );
                if ($exists === null) {
                    flash('error', '目标文件夹不存在');
                    redirect('/admin/files');
                }
            }
            $data['folder_id'] = max(0, $fid);
        }
        if (array_key_exists('is_public', $_POST)) {
            $data['is_public'] = ((string) $_POST['is_public'] === '1') ? 1 : 0;
        }
        if (array_key_exists('level_id', $_POST)) {
            $lid = (int) $_POST['level_id'];
            if ($lid > 0 && level_by_id($lid) === null) {
                flash('error', '等级不存在');
                redirect('/admin/files');
            }
            $data['level_id'] = max(0, $lid);
        }
        if (array_key_exists('view_level_id', $_POST)) {
            $vlid = (int) $_POST['view_level_id'];
            if ($vlid > 0 && level_by_id($vlid) === null) {
                flash('error', '可见等级不存在');
                redirect('/admin/files');
            }
            $data['view_level_id'] = max(0, $vlid);
        }
        if (array_key_exists('login_required', $_POST)) {
            $data['login_required'] = ((string) $_POST['login_required'] === '1') ? 1 : 0;
        }

        Db::update('files', $data, '`id` = ?', [$id]);
        self::audit('update_file', $id, '更新文件 #' . $id);

        flash('success', '已保存');
        redirect('/admin/files' . $this->backQuery());
    }

    /** 移入回收站（软删除）；hard=1 时直接永久删除 */
    public function delete(): void
    {
        $id = (int) post('id');
        $hard = (string) post('hard', '0') === '1';

        $file = $id > 0
            ? Db::row('SELECT * FROM ' . Db::t('files') . ' WHERE `id` = ?', [$id])
            : null;

        if ($file === null) {
            $this->respond(false, '文件不存在');
        }

        if ($hard) {
            $purged = $this->purge(['id' => $id]);
            self::audit('purge_file', $id, '永久删除：' . $file['name']);
            $this->respond(true, '已永久删除', ['purged' => $purged]);
        }

        Db::update('files', ['deleted_at' => now(), 'updated_at' => now()], '`id` = ?', [$id]);
        self::audit('delete_file', $id, '移入回收站：' . $file['name']);

        $this->respond(true, '已移入回收站', ['id' => $id]);
    }

    /** 批量移入回收站；hard=1 时永久删除 */
    public function batchDelete(): void
    {
        $raw = $_POST['ids'] ?? '';
        $ids = is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($v) => $v > 0));
        $hard = (string) post('hard', '0') === '1';

        if (!$ids) {
            $this->respond(false, '未选择文件');
        }
        $ids = array_slice($ids, 0, 200);
        $in = implode(',', array_fill(0, count($ids), '?'));

        if ($hard) {
            $purged = $this->purge(['ids' => $ids]);
            self::audit('batch_purge_files', 0, "批量永久删除 {$purged} 个");
            $this->respond(true, "已永久删除 {$purged} 个", ['count' => $purged]);
        }

        $n = Db::exec(
            'UPDATE ' . Db::t('files') . " SET `deleted_at` = ?, `updated_at` = ? WHERE `id` IN ({$in}) AND `deleted_at` IS NULL",
            array_merge([now(), now()], $ids)
        );
        self::audit('batch_delete_files', 0, "批量移入回收站 {$n} 个");

        $this->respond(true, "已移入回收站 {$n} 个", ['count' => $n]);
    }

    /**
     * 永久删除：先删记录，再按引用计数决定是否删物理文件。
     * @param array{id?:int,ids?:array} $selector
     */
    public static function purge(array $selector): int
    {
        if (isset($selector['id'])) {
            $rows = Db::all('SELECT `id`,`sha256` FROM ' . Db::t('files') . ' WHERE `id` = ?', [(int) $selector['id']]);
        } else {
            $ids = $selector['ids'] ?? [];
            if (!$ids) {
                return 0;
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $rows = Db::all('SELECT `id`,`sha256` FROM ' . Db::t('files') . " WHERE `id` IN ({$in})", $ids);
        }

        $deleted = 0;
        foreach ($rows as $r) {
            $fid = (int) $r['id'];
            $sha = (string) $r['sha256'];

            // 删除记录
            Db::exec('DELETE FROM ' . Db::t('files') . ' WHERE `id` = ?', [$fid]);
            $deleted++;

            // 没有其他记录引用同一内容时才删物理文件
            if ($sha !== '') {
                $other = (int) Db::val(
                    'SELECT COUNT(*) FROM ' . Db::t('files') . ' WHERE `sha256` = ? LIMIT 1',
                    [$sha]
                );
                if ($other === 0) {
                    Storage::delete($sha);
                }
            }
        }
        return $deleted;
    }

    // ---------- 工具 ----------

    /** 把扁平文件夹列表转成层级树 */
    public static function buildTree(array $rows, int $parentId = 0, int $depth = 0): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ((int) $r['parent_id'] === $parentId) {
                $r['depth'] = $depth;
                $out[] = $r;
                foreach (self::buildTree($rows, (int) $r['id'], $depth + 1) as $child) {
                    $out[] = $child;
                }
            }
        }
        return $out;
    }

    private function backQuery(): string
    {
        $parts = [];
        $f = (int) post('folder_id');
        if ($f > 0) {
            $parts[] = 'folder_id=' . $f;
        }
        return $parts ? '?' . implode('&', $parts) : '';
    }

    /** 前端用 fetch 调用的接口返回 JSON，表单提交则跳回列表 */
    private function respond(bool $ok, string $msg, array $extra = []): never
    {
        if (Auth::wantsJson()) {
            json_out(['ok' => $ok, 'msg' => $msg] + $extra, $ok ? 200 : 400);
        }
        flash($ok ? 'success' : 'error', $msg);
        redirect('/admin/files');
    }

    private static function audit(string $action, int $targetId, string $detail): void
    {
        try {
            Db::insert('admin_logs', [
                'admin_id'    => Auth::id(),
                'action'      => $action,
                'target_type' => 'file',
                'target_id'   => $targetId,
                'detail'      => mb_substr($detail, 0, 500),
                'ip'          => client_ip(),
                'created_at'  => now(),
            ]);
        } catch (Throwable) {
        }
    }
}
