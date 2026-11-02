<?php
/**
 * 后台：文件夹管理。
 */
declare(strict_types=1);

class AdminFolderController
{
    public function index(): void
    {
        $page = max(1, get_int('page', 1));
        $perPage = max(5, min(100, (int) setting('page_size', 20)));

        $rows = Db::all(
            'SELECT f.*, '
            . '(SELECT COUNT(*) FROM ' . Db::t('files') . ' x '
            . ' WHERE x.`folder_id` = f.`id` AND x.`deleted_at` IS NULL) AS `file_count` '
            . 'FROM ' . Db::t('folders') . ' f '
            . 'WHERE f.`deleted_at` IS NULL '
            . 'ORDER BY f.`sort` ASC, f.`id` ASC'
        );

        // 分页按「顶层文件夹」切分，每个顶层带动它的整棵子树，层级不会被拆断
        $roots = array_values(array_filter($rows, static fn($r) => (int) $r['parent_id'] === 0));
        $total = count($roots);
        $pager = paginate($total, $page, $perPage);
        $pagedRoots = array_slice($roots, (int) $pager['offset'], (int) $pager['per_page']);
        $rootIds = array_map(static fn($r) => (int) $r['id'], $pagedRoots);

        $visible = $this->withDescendants($rows, $rootIds);

        view('admin/folders', [
            'tree'    => AdminFileController::buildTree($visible),
            'rows'    => $visible,
            'levels'  => levels_all(),
            'pager'   => $pager,
            'total'   => $total,
            'active'  => 'folders',
        ], 'admin');
    }

    /**
     * 保留指定根节点及其全部后代。
     * @param array<int,array> $rows
     * @param array<int,int>   $rootIds
     * @return array<int,array>
     */
    private function withDescendants(array $rows, array $rootIds): array
    {
        if (!$rootIds) {
            return [];
        }
        $keep = $rootIds;
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($rows as $r) {
                $id = (int) $r['id'];
                if (in_array((int) $r['parent_id'], $keep, true) && !in_array($id, $keep, true)) {
                    $keep[] = $id;
                    $changed = true;
                }
            }
        }
        return array_values(array_filter($rows, static fn($r) => in_array((int) $r['id'], $keep, true)));
    }

    /** 新建或更新 */
    public function save(): void
    {
        $id       = (int) post('id');
        $name     = post('name');
        $parentId = (int) post('parent_id', '0');
        $sort     = (int) post('sort', '0');
        $desc     = mb_substr(post('description'), 0, 255);

        if ($name === '' || mb_strlen($name) > 128) {
            $this->respond(false, '文件夹名称不能为空，且不超过 128 字');
        }

        // 父级校验：不能选自己，不能选自己的子孙
        if ($parentId > 0) {
            $exists = Db::val(
                'SELECT 1 FROM ' . Db::t('folders') . ' WHERE `id` = ? AND `deleted_at` IS NULL',
                [$parentId]
            );
            if ($exists === null) {
                $this->respond(false, '父文件夹不存在');
            }
            if ($id > 0 && $parentId === $id) {
                $this->respond(false, '不能把文件夹放到自己下面');
            }
            if ($id > 0 && $this->isDescendant($parentId, $id)) {
                $this->respond(false, '不能把文件夹移动到自己的子目录中');
            }
        }

        $now = now();
        $levelId = (int) post('level_id', '0');
        if ($levelId > 0 && level_by_id($levelId) === null) {
            $this->respond(false, '等级不存在');
        }
        $viewLevelId = (int) post('view_level_id', '0');
        if ($viewLevelId > 0 && level_by_id($viewLevelId) === null) {
            $this->respond(false, '可见等级不存在');
        }

        $data = [
            'parent_id'     => $parentId,
            'name'          => $name,
            'description'   => $desc,
            'sort'          => $sort,
            'is_public'     => ((string) post('is_public', '1') === '1') ? 1 : 0,
            'level_id'      => max(0, $levelId),
            'view_level_id' => max(0, $viewLevelId),
            'login_required' => ((string) post('login_required', '0') === '1') ? 1 : 0,
            'updated_at'    => $now,
        ];

        if ($id > 0) {
            $exists = Db::val('SELECT 1 FROM ' . Db::t('folders') . ' WHERE `id` = ? AND `deleted_at` IS NULL', [$id]);
            if ($exists === null) {
                $this->respond(false, '文件夹不存在');
            }
            Db::update('folders', $data, '`id` = ?', [$id]);
            admin_audit('update_folder', 'folder', $id, '更新文件夹：' . $name);
            $this->respond(true, '已保存');
        }

        // 同名去重（同一父级下）
        $dup = Db::val(
            'SELECT 1 FROM ' . Db::t('folders')
            . ' WHERE `parent_id` = ? AND `name` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$parentId, $name]
        );
        if ($dup !== null) {
            $this->respond(false, '同级目录下已存在同名文件夹');
        }

        $data['created_at'] = $now;
        $newId = Db::insert('folders', $data);
        admin_audit('create_folder', 'folder', (int) $newId, '新建文件夹：' . $name);
        $this->respond(true, '已创建', ['id' => $newId]);
    }

    /** 删除（软删除，子文件夹与文件一并标记） */
    public function delete(): void
    {
        $id = (int) post('id');
        if ($id <= 0) {
            $this->respond(false, '参数错误');
        }
        $f = Db::row('SELECT * FROM ' . Db::t('folders') . ' WHERE `id` = ? AND `deleted_at` IS NULL', [$id]);
        if ($f === null) {
            $this->respond(false, '文件夹不存在');
        }

        $ids = $this->collectSubtree($id);
        $in  = implode(',', array_fill(0, count($ids), '?'));

        Db::begin();
        try {
            Db::exec(
                'UPDATE ' . Db::t('folders') . " SET `deleted_at` = ?, `updated_at` = ? WHERE `id` IN ({$in})",
                array_merge([now(), now()], $ids)
            );
            // 目录内的文件一并进回收站
            $n = Db::exec(
                'UPDATE ' . Db::t('files') . " SET `deleted_at` = ?, `updated_at` = ? WHERE `folder_id` IN ({$in}) AND `deleted_at` IS NULL",
                array_merge([now(), now()], $ids)
            );
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            $this->respond(false, '删除失败：' . $e->getMessage());
        }

        admin_audit(
            'delete_folder',
            'folder',
            (int) ($ids[0] ?? 0),
            sprintf('删除文件夹 %d 个（含文件 %d 个）', count($ids), (int) $n)
        );

        $this->respond(true, '已移入回收站', ['folders' => count($ids), 'files' => (int) $n]);
    }

    /** 收集自身 + 全部子孙 id */
    private function collectSubtree(int $rootId): array
    {
        $all = Db::all('SELECT `id`,`parent_id` FROM ' . Db::t('folders') . ' WHERE `deleted_at` IS NULL');
        $ids = [$rootId];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($all as $r) {
                $pid = (int) $r['parent_id'];
                $cid = (int) $r['id'];
                if (in_array($pid, $ids, true) && !in_array($cid, $ids, true)) {
                    $ids[] = $cid;
                    $changed = true;
                }
            }
        }
        return $ids;
    }

    /** $maybeChild 是否是 $ancestorId 的后代 */
    private function isDescendant(int $maybeChild, int $ancestorId): bool
    {
        $cur = $maybeChild;
        $guard = 0;
        while ($cur > 0 && $guard++ < 64) {
            if ($cur === $ancestorId) {
                return true;
            }
            $p = Db::val('SELECT `parent_id` FROM ' . Db::t('folders') . ' WHERE `id` = ?', [$cur]);
            $cur = (int) ($p ?? 0);
        }
        return false;
    }

    private function respond(bool $ok, string $msg, array $extra = []): never
    {
        if (Auth::wantsJson()) {
            json_out(['ok' => $ok, 'msg' => $msg] + $extra, $ok ? 200 : 400);
        }
        flash($ok ? 'success' : 'error', $msg);
        redirect('/admin/folders');
    }
}
