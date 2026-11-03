<?php
/**
 * 后台：用户等级管理。
 *
 * 等级用 sort 数值比较：用户等级 sort >= 资源要求等级 sort 才能访问。
 */
declare(strict_types=1);

class AdminLevelController
{
    public function index(): void
    {
        $page = max(1, get_int('page', 1));
        $perPage = max(5, min(100, (int) setting('page_size', 20)));

        $total = (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('levels'));
        $pager = paginate($total, $page, $perPage);

        $levels = Db::all(
            'SELECT * FROM ' . Db::t('levels') . ' ORDER BY `sort` ASC, `id` ASC '
            . 'LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset']
        );

        // 各等级的使用量
        $userCnt = [];
        foreach (Db::all('SELECT `level_id`, COUNT(*) AS c FROM ' . Db::t('users') . ' GROUP BY `level_id`') as $r) {
            $userCnt[(int) $r['level_id']] = (int) $r['c'];
        }
        $fileCnt = [];
        foreach (Db::all('SELECT `level_id`, COUNT(*) AS c FROM ' . Db::t('files') . ' WHERE `deleted_at` IS NULL GROUP BY `level_id`') as $r) {
            $fileCnt[(int) $r['level_id']] = (int) $r['c'];
        }
        $folderCnt = [];
        foreach (Db::all('SELECT `level_id`, COUNT(*) AS c FROM ' . Db::t('folders') . ' WHERE `deleted_at` IS NULL GROUP BY `level_id`') as $r) {
            $folderCnt[(int) $r['level_id']] = (int) $r['c'];
        }

        view('admin/levels', [
            'levels'    => $levels,
            'userCnt'   => $userCnt,
            'fileCnt'   => $fileCnt,
            'folderCnt' => $folderCnt,
            'pager'     => $pager,
            'active'    => 'levels',
        ], 'admin');
    }

    public function save(): void
    {
        $id     = (int) post('id');
        $name   = post('name');
        $sort   = (int) post('sort', '0');
        $color  = post('color', '#07C160');
        $desc   = mb_substr(post('description'), 0, 255);
        $isDefault = ((string) post('is_default', '0') === '1') ? 1 : 0;

        if ($name === '' || mb_strlen($name) > 32) {
            $this->respond(false, '等级名称不能为空，且不超过 32 字');
        }
        if (!preg_match('/^#[0-9A-Fa-f]{3,8}$/', $color)) {
            $color = '#07C160';
        }
        // 同名去重
        $dup = Db::val(
            'SELECT `id` FROM ' . Db::t('levels') . ' WHERE `name` = ? AND `id` <> ? LIMIT 1',
            [$name, $id]
        );
        if ($dup !== null) {
            $this->respond(false, '已存在同名等级');
        }

        Db::begin();
        try {
            if ($isDefault === 1) {
                Db::exec('UPDATE ' . Db::t('levels') . ' SET `is_default` = 0');
            }
            if ($id > 0) {
                $exists = Db::val('SELECT 1 FROM ' . Db::t('levels') . ' WHERE `id` = ?', [$id]);
                if ($exists === null) {
                    Db::rollback();
                    $this->respond(false, '等级不存在');
                }
                Db::update('levels', [
                    'name' => $name, 'sort' => $sort, 'color' => $color,
                    'description' => $desc, 'is_default' => $isDefault,
                ], '`id` = ?', [$id]);
            } else {
                Db::insert('levels', [
                    'name' => $name, 'sort' => $sort, 'color' => $color,
                    'description' => $desc, 'is_default' => $isDefault,
                    'created_at' => now(),
                ]);
            }
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            $this->respond(false, '保存失败：' . $e->getMessage());
        }

        admin_audit(
            $id > 0 ? 'update_level' : 'create_level',
            'level',
            $id,
            sprintf('%s等级：%s（权重 %d）', $id > 0 ? '更新' : '新建', $name, $sort)
        );

        $this->respond(true, '已保存');
    }

    public function delete(): void
    {
        $id = (int) post('id');
        if ($id <= 0) {
            $this->respond(false, '参数错误');
        }
        $lv = Db::row('SELECT * FROM ' . Db::t('levels') . ' WHERE `id` = ?', [$id]);
        if ($lv === null) {
            $this->respond(false, '等级不存在');
        }
        if ((int) $lv['is_default'] === 1) {
            $this->respond(false, '默认等级不能删除，请先把其他等级设为默认');
        }

        // 四类引用都要查。原先只查了 level_id（下载门槛）与用户等级，
        // 漏了 view_level_id（可见门槛）。漏掉的后果是：删除等级后，
        // 把它设为「可见等级」的文件仍带着一个指向已不存在等级的 id，
        // visibilityFilter 里的 EXISTS 永远为假，这些文件对全部非管理员
        // 永久消失，而且界面上看不出原因。
        $users       = (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('users') . ' WHERE `level_id` = ?', [$id]);
        $files       = (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('files') . ' WHERE `level_id` = ? AND `deleted_at` IS NULL', [$id]);
        $folders     = (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('folders') . ' WHERE `level_id` = ? AND `deleted_at` IS NULL', [$id]);
        $filesView   = (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('files') . ' WHERE `view_level_id` = ? AND `deleted_at` IS NULL', [$id]);
        $foldersView = (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('folders') . ' WHERE `view_level_id` = ? AND `deleted_at` IS NULL', [$id]);

        if ($users + $files + $folders + $filesView + $foldersView > 0) {
            $parts = [];
            if ($users > 0) {
                $parts[] = "{$users} 个用户";
            }
            if ($files > 0) {
                $parts[] = "{$files} 个文件设为可下载等级";
            }
            if ($folders > 0) {
                $parts[] = "{$folders} 个文件夹设为可下载等级";
            }
            if ($filesView > 0) {
                $parts[] = "{$filesView} 个文件设为可见等级";
            }
            if ($foldersView > 0) {
                $parts[] = "{$foldersView} 个文件夹设为可见等级";
            }
            $this->respond(false, '仍被使用中：' . implode('、', $parts) . '，请先解除关联');
        }

        Db::exec('DELETE FROM ' . Db::t('levels') . ' WHERE `id` = ?', [$id]);
        admin_audit('delete_level', 'level', $id, '删除等级：' . (string) ($lv['name'] ?? $id));
        $this->respond(true, '已删除');
    }

    private function respond(bool $ok, string $msg): never
    {
        admin_respond($ok, $msg, '/admin/levels');
    }
}
