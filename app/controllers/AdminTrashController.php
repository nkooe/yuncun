<?php
/**
 * 后台：回收站。
 *
 * 删除的文件/文件夹保留 deleted_at，超过 recycle_days 后自动永久清理。
 */
declare(strict_types=1);

class AdminTrashController
{
    public function index(): void
    {
        $tab  = get('tab', 'files') === 'folders' ? 'folders' : 'files';
        $page = max(1, get_int('page', 1));
        $perPage = max(5, min(100, (int) setting('page_size', 20)));

        housekeeping(true);   // 管理员正在看回收站，忽略节流立即清理

        $pager = ['total' => 0, 'page' => 1, 'pages' => 1, 'has_prev' => false, 'has_next' => false, 'per_page' => $perPage, 'offset' => 0];
        $files = [];
        $folders = [];

        if ($tab === 'files') {
            $total = (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('files') . ' WHERE `deleted_at` IS NOT NULL');
            $pager = paginate($total, $page, $perPage);
            $files = Db::all(
                'SELECT f.*, d.`name` AS folder_name FROM ' . Db::t('files') . ' f '
                . 'LEFT JOIN ' . Db::t('folders') . ' d ON d.`id` = f.`folder_id` '
                . 'WHERE f.`deleted_at` IS NOT NULL '
                . 'ORDER BY f.`deleted_at` DESC '
                . 'LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset']
            );
        } else {
            $totalF = (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('folders') . ' WHERE `deleted_at` IS NOT NULL');
            $pager = paginate($totalF, $page, $perPage);
            $folders = Db::all(
                'SELECT * FROM ' . Db::t('folders') . ' WHERE `deleted_at` IS NOT NULL '
                . 'ORDER BY `deleted_at` DESC '
                . 'LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset']
            );
        }

        $counts = [
            'files'   => (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('files') . ' WHERE `deleted_at` IS NOT NULL'),
            'folders' => (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('folders') . ' WHERE `deleted_at` IS NOT NULL'),
            'size'    => (int) Db::val('SELECT COALESCE(SUM(`size`),0) FROM ' . Db::t('files') . ' WHERE `deleted_at` IS NOT NULL'),
        ];

        view('admin/trash', [
            'tab'         => $tab,
            'files'       => $files,
            'folders'     => $folders,
            'pager'       => $pager,
            'counts'      => $counts,
            'recycleDays' => (int) setting('recycle_days', 30),
            'active'      => 'trash',
        ], 'admin');
    }

    /** 恢复文件（父目录不存在则回到根） */
    public function restore(): void
    {
        $ids = $this->idsFromRequest();
        if (!$ids) {
            $this->respond(false, '未选择项目');
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = Db::all('SELECT `id`,`folder_id` FROM ' . Db::t('files') . " WHERE `id` IN ({$in}) AND `deleted_at` IS NOT NULL", $ids);

        $n = 0;
        foreach ($rows as $r) {
            $fid = (int) $r['folder_id'];
            $folderOk = $fid > 0
                ? Db::val('SELECT 1 FROM ' . Db::t('folders') . ' WHERE `id` = ? AND `deleted_at` IS NULL', [$fid])
                : null;
            $data = ['deleted_at' => null, 'updated_at' => now()];
            if ($fid > 0 && $folderOk === null) {
                $data['folder_id'] = 0; // 原目录已删，回到根目录
            }
            Db::update('files', $data, '`id` = ?', [(int) $r['id']]);
            $n++;
        }

        $this->respond(true, "已恢复 {$n} 个文件", ['count' => $n]);
    }

    /** 恢复文件夹（含其子孙，按层级由上至下） */
    public function restoreFolder(): void
    {
        $id = (int) post('id');
        if ($id <= 0) {
            $this->respond(false, '参数错误');
        }
        $folder = Db::row('SELECT * FROM ' . Db::t('folders') . ' WHERE `id` = ?', [$id]);
        if ($folder === null) {
            $this->respond(false, '文件夹不存在');
        }

        // 删除时是整棵子树一起进回收站（AdminFolderController::delete 用
        // collectSubtree），恢复也只恢复一层的话就和删除不对称：删一个三层
        // 目录，回收站里显示 3 个文件夹、3 个文件，点恢复却只回来 1 个文件夹
        // 和它的直接文件，剩下 2 层还留在回收站里，容易被误清空。
        $ids = [$id];
        $queue = [$id];
        while ($queue) {
            $cur = array_shift($queue);
            $children = Db::all(
                'SELECT `id` FROM ' . Db::t('folders') . ' WHERE `parent_id` = ?',
                [$cur]
            );
            foreach ($children as $c) {
                $cid = (int) $c['id'];
                if (!in_array($cid, $ids, true)) {
                    $ids[] = $cid;
                    $queue[] = $cid;
                }
            }
        }

        $now = now();
        $in  = implode(',', array_fill(0, count($ids), '?'));

        Db::begin();
        try {
            // 先恢复整棵子树
            Db::exec(
                'UPDATE ' . Db::t('folders') . " SET `deleted_at` = NULL, `updated_at` = ? WHERE `id` IN ({$in})",
                array_merge([$now], $ids)
            );

            // 再检查根节点的父级：父级仍被删除时挂回根目录，
            // 否则恢复后它会指向一个不在列表里的父级，界面上就看不见了。
            $pid = (int) $folder['parent_id'];
            if ($pid > 0) {
                $ok = Db::val(
                    'SELECT 1 FROM ' . Db::t('folders') . ' WHERE `id` = ? AND `deleted_at` IS NULL',
                    [$pid]
                );
                if ($ok === null) {
                    Db::exec(
                        'UPDATE ' . Db::t('folders') . ' SET `parent_id` = 0, `updated_at` = ? WHERE `id` = ?',
                        [$now, $id]
                    );
                }
            }

            // 最后一并恢复这些目录里的文件
            $n = Db::exec(
                'UPDATE ' . Db::t('files') . " SET `deleted_at` = NULL, `updated_at` = ?"
                . " WHERE `folder_id` IN ({$in}) AND `deleted_at` IS NOT NULL",
                array_merge([$now], $ids)
            );

            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            $this->respond(false, '恢复失败：' . $e->getMessage());
        }

        $extra = count($ids) > 1 ? '（含 ' . (count($ids) - 1) . ' 个子目录）' : '';
        $this->respond(true, '文件夹已恢复' . $extra . ((int) $n > 0 ? "，含 {$n} 个文件" : ''));
    }

    /** 永久删除选中的文件 */
    public function purge(): void
    {
        $ids = $this->idsFromRequest();
        if (!$ids) {
            $this->respond(false, '未选择项目');
        }
        $n = AdminFileController::purge(['ids' => array_slice($ids, 0, 200)]);
        $this->respond(true, "已永久删除 {$n} 个文件", ['count' => $n]);
    }

    /** 永久删除文件夹（含子孙与内部文件） */
    public function purgeFolder(): void
    {
        $id = (int) post('id');
        if ($id <= 0) {
            $this->respond(false, '参数错误');
        }

        $all = Db::all('SELECT `id`,`parent_id` FROM ' . Db::t('folders'));
        $ids = [$id];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($all as $r) {
                if (in_array((int) $r['parent_id'], $ids, true) && !in_array((int) $r['id'], $ids, true)) {
                    $ids[] = (int) $r['id'];
                    $changed = true;
                }
            }
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $fileIds = array_column(
            Db::all('SELECT `id` FROM ' . Db::t('files') . " WHERE `folder_id` IN ({$in})", $ids),
            'id'
        );
        $purged = $fileIds ? AdminFileController::purge(['ids' => $fileIds]) : 0;

        Db::exec('DELETE FROM ' . Db::t('folders') . " WHERE `id` IN ({$in})", $ids);

        $this->respond(true, '已永久删除 ' . count($ids) . ' 个文件夹、' . $purged . ' 个文件');
    }

    /** 清空回收站 */
    public function empty(): void
    {
        $fileIds = array_column(
            Db::all('SELECT `id` FROM ' . Db::t('files') . ' WHERE `deleted_at` IS NOT NULL'),
            'id'
        );
        $purged = $fileIds ? AdminFileController::purge(['ids' => $fileIds]) : 0;
        $folders = Db::exec('DELETE FROM ' . Db::t('folders') . ' WHERE `deleted_at` IS NOT NULL');

        admin_audit('empty_trash', 'trash', 0, "清空回收站：{$purged} 文件 / {$folders} 文件夹");
        $this->respond(true, "回收站已清空（{$purged} 个文件）");
    }

    // ---------- 内部 ----------


    private function idsFromRequest(): array
    {
        $raw = $_POST['ids'] ?? '';
        $ids = is_array($raw) ? $raw : explode(',', (string) $raw);
        return array_values(array_filter(array_map('intval', $ids), static fn($v) => $v > 0));
    }

    private function respond(bool $ok, string $msg, array $extra = []): never
    {
        admin_respond($ok, $msg, '/admin/trash', $extra);
    }
}
