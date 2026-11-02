<?php
/**
 * 登录 / 注册 / 登出。
 */
declare(strict_types=1);

class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            redirect(Auth::isAdmin() ? '/admin' : '/');
        }
        $redirect = get('redirect', '/');
        view('auth/login', ['redirect' => $redirect], 'auth');
    }

    public function doLogin(): void
    {
        $username = post('username');
        $password = (string) ($_POST['password'] ?? '');
        $redirect = post('redirect', '/');

        $res = Auth::attempt($username, $password);
        if (!$res['ok']) {
            flash('error', $res['msg']);
            $_SESSION['_old_username'] = $username;
            redirect('/login?redirect=' . rawurlencode($redirect));
        }

        flash('success', '登录成功，欢迎回来');
        if ($redirect !== '' && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//')) {
            redirect($redirect);
        }
        redirect(Auth::isAdmin() ? '/admin' : '/');
    }

    public function showRegister(): void
    {
        if (Auth::check()) {
            redirect('/');
        }
        if ((int) setting('allow_register', 1) === 0) {
            flash('error', '本站已关闭注册，请联系管理员开通账号');
            redirect('/login');
        }
        view('auth/register', [], 'auth');
    }

    public function doRegister(): void
    {
        if ((int) setting('allow_register', 1) === 0) {
            flash('error', '本站已关闭注册');
            redirect('/login');
        }

        $username = post('username');
        $email    = post('email');
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['password2'] ?? '');

        $errors = [];
        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
            $errors[] = '账号需为 3–32 位字母、数字或下划线';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '邮箱格式不正确';
        }
        if (strlen($password) < 6) {
            $errors[] = '密码至少 6 位';
        }
        if ($password !== $confirm) {
            $errors[] = '两次输入的密码不一致';
        }
        if (!$errors) {
            $exists = Db::val('SELECT 1 FROM ' . Db::t('users') . ' WHERE `username` = ?', [$username]);
            if ($exists !== null) {
                $errors[] = '该账号已被占用';
            }
            if ($email !== '') {
                $e2 = Db::val('SELECT 1 FROM ' . Db::t('users') . ' WHERE `email` = ?', [$email]);
                if ($e2 !== null) {
                    $errors[] = '该邮箱已被注册';
                }
            }
        }

        if ($errors) {
            foreach ($errors as $er) {
                flash('error', $er);
            }
            $_SESSION['_old'] = ['username' => $username, 'email' => $email];
            redirect('/register');
        }

        $now = now();
        $id = Db::insert('users', [
            'username'       => $username,
            'email'          => $email !== '' ? $email : null,
            'password_hash'  => password_hash($password, PASSWORD_DEFAULT),
            'nickname'       => $username,
            'role'           => 'user',
            'status'         => 1,
            'download_quota' => (int) setting('default_download_quota', 0),
            'quota_period'   => (string) setting('default_quota_period', 'total'),
            'storage_quota'  => (int) setting('default_storage_quota', 0),
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $user = Db::row('SELECT * FROM ' . Db::t('users') . ' WHERE `id` = ?', [$id]);
        if ($user) {
            Auth::establish($user);
        }
        flash('success', '注册成功');
        redirect('/user');
    }

    public function logout(): void
    {
        Auth::logout();
        redirect('/');
    }
}
