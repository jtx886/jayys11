<?php
/**
 * Jay影视 - 注册（邮箱验证码）
 */
require __DIR__ . '/includes/init.php';
require __DIR__ . '/includes/layout.php';

if (is_login()) redirect('index.php');

$error = '';
$old = ['email' => '', 'username' => ''];

if (is_post() && post_val('action') === 'register') {
    $email    = post_val('email');
    $username = post_val('username');
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');
    $code     = post_val('code');
    $old = ['email' => $email, 'username' => $username];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '请输入正确的邮箱地址';
    } elseif (mb_len($username) < 2 || mb_len($username) > 20) {
        $error = '用户名长度需在 2-20 个字符之间';
    } elseif (mb_len($password) < 6 || mb_len($password) > 32) {
        $error = '密码长度需在 6-32 个字符之间';
    } elseif ($password !== $password2) {
        $error = '两次输入的密码不一致';
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $error = '请输入 6 位邮箱验证码';
    } elseif (db_one("SELECT id FROM users WHERE email = ?", [$email])) {
        $error = '该邮箱已被注册';
    } elseif (db_one("SELECT id FROM users WHERE username = ?", [$username])) {
        $error = '该用户名已被使用';
    } else {
        $row = db_one("SELECT * FROM email_codes WHERE email = ? AND code = ? AND used = 0 ORDER BY id DESC LIMIT 1", [$email, $code]);
        $valid = false;
        if ($row) {
            if (strtotime($row['expires_at']) >= time()) {
                $valid = true;
                db_query("UPDATE email_codes SET used = 1 WHERE id = ?", [$row['id']]);
            } else {
                $error = '验证码已过期，请重新获取';
            }
        }
        if ($valid && $error === '') {
            // 显式写入 PHP 生成的北京时间（init.php 已设 Asia/Shanghai），不依赖 MySQL 会话时区
            db_query("INSERT INTO users (email, username, password, created_at) VALUES (?, ?, ?, ?)", [$email, $username, password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
            $uid = (int)db()->lastInsertId();
            $_SESSION['uid'] = $uid;
            $_SESSION['flash_ok'] = '注册成功，欢迎加入 ' . site_name();
            redirect('index.php');
        }
        if (!$row && $error === '') $error = '验证码错误';
    }
}

page_start(['title' => '注册']);
?>
<div class="auth-wrap">
    <div class="auth-card">
        <div style="display:flex;justify-content:center;margin-bottom:18px">
            <a class="nav-logo" href="index.php">
                <span class="logo-box"><i class="i i-play"></i></span>
                <span class="logo-text" style="font-size:22px">Jay<b>影视</b></span>
            </a>
        </div>
        <div class="auth-title">创建账号</div>
        <div class="auth-sub">注册后即可收藏影片、记录观看进度、参与反馈</div>

        <?php if ($error): ?><div class="auth-err"><?= e($error) ?></div><?php endif; ?>

        <form method="post" autocomplete="off" id="regForm">
            <input type="hidden" name="action" value="register">
            <?= csrf_field() ?>
            <div class="field">
                <label>邮箱 <b>*</b></label>
                <input class="input" type="email" name="email" value="<?= e($old['email']) ?>" placeholder="用于接收验证码" required>
            </div>
            <div class="field">
                <label>用户名 <b>*</b></label>
                <input class="input" type="text" name="username" value="<?= e($old['username']) ?>" placeholder="2-20 个字符" required>
            </div>
            <div class="field">
                <label>密码 <b>*</b></label>
                <input class="input" type="password" name="password" placeholder="6-32 位密码" required>
            </div>
            <div class="field">
                <label>确认密码 <b>*</b></label>
                <input class="input" type="password" name="password2" placeholder="再次输入密码" required>
            </div>
            <div class="field">
                <label>邮箱验证码 <b>*</b></label>
                <div class="code-row">
                    <input class="input" type="text" name="code" maxlength="6" placeholder="6 位验证码" required>
                    <button type="button" class="code-btn" id="sendCodeBtn">获取验证码</button>
                </div>
                <div class="hint">验证码将发送至您的邮箱，10 分钟内有效</div>
            </div>
            <button class="btn btn-primary btn-block btn-lg" type="submit">注 册</button>
            <div class="auth-links">
                <span>已有账号？<a href="login.php">立即登录</a></span>
                <a href="index.php">返回首页</a>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var btn = document.getElementById('sendCodeBtn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var email = (document.querySelector('[name=email]').value || '').trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { alert('请先输入正确的邮箱地址'); return; }
        btn.disabled = true;
        btn.textContent = '发送中...';
        var body = new FormData();
        body.append('action', 'send_code');
        body.append('csrf', window.JAY ? JAY.csrf : '');
        body.append('email', email);
        fetch('api.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.ok) {
                    var left = 60;
                    btn.textContent = left + 's 后重发';
                    var timer = setInterval(function () {
                        left--;
                        if (left <= 0) { clearInterval(timer); btn.disabled = false; btn.textContent = '获取验证码'; }
                        else btn.textContent = left + 's 后重发';
                    }, 1000);
                    alert('验证码已发送至 ' + email + '，请注意查收（含垃圾邮件箱）');
                } else {
                    btn.disabled = false;
                    btn.textContent = '获取验证码';
                    alert(res.msg || '发送失败，请稍后重试');
                }
            })
            .catch(function () { btn.disabled = false; btn.textContent = '获取验证码'; alert('网络错误'); });
    });
})();
</script>
<?php page_end(); ?>
