<?php
/**
 * 个人资料与密码（管理员与普通用户共用）。
 */
declare(strict_types=1);

class ProfileController
{
    public function show(): void
    {
        $u = Auth::user();
        if ($u === null) {
            redirect('/login');
        }

        $level = level_by_id((int) ($u['level_id'] ?? 0));

        view('user/profile', [
            'user'  => $u,
            'level' => $level,
        ], 'front');
    }

    public function save(): void
    {
        $u = Auth::user();
        if ($u === null) {
            redirect('/login');
        }
        $uid = (int) $u['id'];

        $nickname = trim((string) ($_POST['nickname'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));

        if ($nickname === '' || mb_strlen($nickname) > 32) {
            flash('error', '昵称不能为空，且不超过 32 字');
            redirect('/user/profile');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', '邮箱格式不正确');
            redirect('/user/profile');
        }
        if ($email !== '') {
            $dup = Db::val(
                'SELECT `id` FROM ' . Db::t('users') . ' WHERE `email` = ? AND `id` <> ? LIMIT 1',
                [$email, $uid]
            );
            if ($dup !== null) {
                flash('error', '该邮箱已被其他账号使用');
                redirect('/user/profile');
            }
        }

        Db::update('users', [
            'nickname'   => $nickname,
            'email'      => $email !== '' ? $email : null,
            'updated_at' => now(),
        ], '`id` = ?', [$uid]);

        Auth::log((string) $u['username'], 'ok', '修改个人资料', $uid);
        flash('success', '资料已保存');
        redirect('/user/profile');
    }

    public function changePassword(): void
    {
        $u = Auth::user();
        if ($u === null) {
            redirect('/login');
        }
        $uid = (int) $u['id'];

        $old     = (string) ($_POST['old_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password2'] ?? '');

        if ($old === '' || $new === '') {
            flash('error', '请填写完整的密码信息');
            redirect('/user/profile');
        }
        if (!password_verify($old, (string) $u['password_hash'])) {
            Auth::log((string) $u['username'], 'fail', '修改密码：原密码错误', $uid);
            flash('error', '原密码不正确');
            redirect('/user/profile');
        }
        if (strlen($new) < 6) {
            flash('error', '新密码至少 6 位');
            redirect('/user/profile');
        }
        if ($new !== $confirm) {
            flash('error', '两次输入的新密码不一致');
            redirect('/user/profile');
        }
        if ($new === $old) {
            flash('error', '新密码不能与原密码相同');
            redirect('/user/profile');
        }

        Db::update('users', [
            'password_hash' => password_hash($new, PASSWORD_DEFAULT),
            'updated_at'    => now(),
        ], '`id` = ?', [$uid]);

        Auth::log((string) $u['username'], 'ok', '修改密码成功', $uid);

        // 改密后强制重新登录（防止旧会话被他人继续使用）
        Auth::logout();
        flash('success', '密码已修改，请用新密码重新登录');
        redirect('/login');
    }
}
