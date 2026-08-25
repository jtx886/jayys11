<?php
/**
 * Jay影视 - 登录
 */
require __DIR__ . '/includes/init.php';
require __DIR__ . '/includes/layout.php';

$redirect = get_val('redirect', '');
if (!preg_match('#^https?://#i', $redirect) && $redirect !== '') {
    // 仅允许站内跳转
    if ($redirect[0] !== '/') $redirect = '';
}
$needLogin = get_val('msg', '') === 'needlogin' || $redirect !== '';
$error = '';
$banInfo = !empty($_SESSION['flash_ban']) ? $_SESSION['flash_ban'] : null;
unset($_SESSION['flash_ban']);

if (is_login()) redirect('index.php');

if (is_post() && post_val('action') === 'login') {
    $ident = post_val('identifier');
    $pass  = (string)($_POST['password'] ?? '');
    if ($ident === '' || $pass === '') {
        $error = '请输入账号和密码';
    } else {
        $user = db_one("SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1", [$ident, $ident]);
        if (!$user || !password_verify($pass, $user['password'])) {
            $error = '账号或密码错误';
        } elseif ((int)$user['status'] === 0) {
            $end = $user['ban_end'] ? strtotime($user['ban_end']) : null;
            if ($end !== null && time() >= $end) {
                db_query("UPDATE users SET status = 1, ban_start = NULL, ban_end = NULL, ban_reason = '' WHERE id = ?", [$user['id']]);
                $user['status'] = 1;
            } else {
                $banInfo = ['reason' => $user['ban_reason'], 'start' => $user['ban_start'], 'end' => $user['ban_end']];
            }
        }
        if ($error === '' && $banInfo === null) {
            $_SESSION['uid'] = (int)$user['id'];
            $_SESSION['flash_ok'] = '欢迎回来，' . $user['username'];
            redirect($redirect !== '' ? $redirect : 'index.php');
        }
    }
}

page_start(['title' => '登录']);
?>
<div class="auth-wrap">
    <div class="auth-card">
        <div style="display:flex;justify-content:center;margin-bottom:18px">
            <a class="nav-logo" href="index.php">
                <span class="logo-box"><i class="i i-play"></i></span>
                <span class="logo-text" style="font-size:22px">Jay<b>影视</b></span>
            </a>
        </div>
        <div class="auth-title">欢迎回来</div>
        <div class="auth-sub">登录后即可畅享全网高清影视</div>

        <?php if ($banInfo): ?>
        <div class="auth-err">
            <b style="display:block;margin-bottom:4px"><i class="i i-ban"></i> 账号已被封禁</b>
            <?php if ($banInfo['reason']): ?>封禁原因：<?= e($banInfo['reason']) ?><br><?php endif; ?>
            <?php if ($banInfo['start']): ?>封禁时间：<?= e($banInfo['start']) ?><br><?php endif; ?>
            <?php if ($banInfo['end']): ?>解除时间：<?= e($banInfo['end']) ?><?php else: ?>解除时间：未定<?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?><div class="auth-err"><?= e($error) ?></div><?php endif; ?>

        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="login">
            <?= csrf_field() ?>
            <div class="field">
                <label>邮箱 / 用户名</label>
                <input class="input" type="text" name="identifier" value="<?= e(post_val('identifier')) ?>" placeholder="请输入邮箱或用户名" required>
            </div>
            <div class="field">
                <label>密码</label>
                <input class="input" type="password" name="password" placeholder="请输入密码" required>
            </div>
            <button class="btn btn-primary btn-block btn-lg" type="submit"><i class="i i-out"></i>登 录</button>
            <div class="auth-links">
                <span>还没有账号？<a href="register.php">立即注册</a></span>
                <a href="index.php">返回首页</a>
            </div>
        </form>
    </div>
</div>

<?php if ($needLogin): ?>
<div class="modal-mask" id="loginPrompt">
    <div class="modal">
        <div class="modal-head">
            <div class="modal-icon"><i class="i i-lock"></i></div>
            <div class="modal-title">无法播放</div>
            <button class="icon-btn modal-close" data-close><i class="i i-close"></i></button>
        </div>
        <div class="modal-body">需要登录才可以观看哦，如没有账号请注册！</div>
        <div class="modal-foot">
            <a class="btn btn-ghost" href="register.php">前往注册</a>
            <button class="btn btn-primary" data-close>前往登录</button>
        </div>
    </div>
</div>
<?php endif; ?>
<?php page_end(); ?>
