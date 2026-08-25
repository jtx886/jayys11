<?php
/**
 * Jay影视 - 管理后台公共引导（含侧栏布局）
 */
require_once __DIR__ . '/../includes/init.php';

if (!is_login()) {
    header('Location: ../login.php?redirect=' . urlencode(basename($_SERVER['SCRIPT_NAME'])));
    exit;
}
if (!is_admin()) {
    $_SESSION['flash_err'] = '无权访问管理后台';
    header('Location: ../index.php');
    exit;
}

function admin_page_start($opts = []) {
    $active = isset($opts['active']) ? $opts['active'] : '';
    $title  = isset($opts['title']) ? $opts['title'] : '管理后台';
    $self   = basename($_SERVER['SCRIPT_NAME']);
    $navs = [
        'dashboard' => ['index.php', '仪表盘', 'i-chart'],
        'users'     => ['users.php', '用户管理', 'i-user'],
        'sources'   => ['sources.php', '播放源管理', 'i-film'],
        'mail'      => ['mail.php', '邮件推送', 'i-mail'],
        'notice'    => ['notice.php', '网站公告', 'i-bell'],
        'feedback'  => ['feedback.php', '反馈管理', 'i-edit'],
        'settings'  => ['settings.php', '网站设置', 'i-palette'],
    ];
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> - <?= e(site_name()) ?>管理后台</title>
<link rel="stylesheet" href="../assets/css/style.css?v=1.0">
<style>:root{--accent:<?= e(theme_color()) ?>;}</style>
</head>
<body>
<div class="admin-body">
    <aside class="admin-side" id="adminSide">
        <div class="admin-logo">
            <span class="logo-box"><i class="i i-play"></i></span>
            <span class="logo-text">Jay<b>影视</b></span>
        </div>
        <nav class="admin-nav">
            <div class="an-group">概览</div>
            <a class="an-item <?= $active === 'dashboard' ? 'on' : '' ?>" href="index.php"><i class="i i-chart"></i>仪表盘</a>
            <div class="an-group">管理</div>
            <a class="an-item <?= $active === 'users' ? 'on' : '' ?>" href="users.php"><i class="i i-user"></i>用户管理</a>
            <a class="an-item <?= $active === 'sources' ? 'on' : '' ?>" href="sources.php"><i class="i i-film"></i>播放源管理</a>
            <a class="an-item <?= $active === 'mail' ? 'on' : '' ?>" href="mail.php"><i class="i i-mail"></i>邮件推送</a>
            <a class="an-item <?= $active === 'notice' ? 'on' : '' ?>" href="notice.php"><i class="i i-bell"></i>网站公告</a>
            <a class="an-item <?= $active === 'feedback' ? 'on' : '' ?>" href="feedback.php"><i class="i i-edit"></i>反馈管理</a>
            <div class="an-group">系统</div>
            <a class="an-item <?= $active === 'settings' ? 'on' : '' ?>" href="settings.php"><i class="i i-palette"></i>网站设置</a>
        </nav>
        <div class="admin-foot">
            <a href="../index.php" style="color:var(--tx2)"><i class="i i-home"></i> 返回前台</a><br>
            <?= e(site_name()) ?> · v1.0
        </div>
    </aside>
    <div class="admin-main">
        <div class="admin-topbar">
            <button class="icon-btn admin-burger" id="adminBurger"><i class="i i-list"></i></button>
            <div class="page-title"><?= e($title) ?></div>
            <div class="nav-user" style="position:relative">
                <button class="nav-avatar-btn" id="avatarBtn">
                    <?php $u = current_user(); if ($u['avatar']): ?>
                    <img class="nav-avatar-img" src="<?= e($u['avatar']) ?>" alt="">
                    <?php else: ?>
                    <span class="nav-avatar-img nav-avatar-txt"><?= e(mb_strtoupper(mb_sub($u['username'], 0, 1))) ?></span>
                    <?php endif; ?>
                </button>
                <div class="nav-user-menu" id="userMenu">
                    <div class="nav-user-head">
                        <span class="um-avatar um-avatar-txt"><?= e(mb_strtoupper(mb_sub($u['username'], 0, 1))) ?></span>
                        <div>
                            <div class="um-name"><?= display_username($u) ?></div>
                            <div class="um-mail">管理员</div>
                        </div>
                    </div>
                    <a class="um-item" href="../profile.php"><i class="i i-user"></i>个人中心</a>
                    <a class="um-item um-out" href="../logout.php"><i class="i i-out"></i>退出登录</a>
                </div>
            </div>
        </div>
        <?php
        if (!empty($_SESSION['flash_ok'])) {
            echo '<div class="toast toast-ok">' . e($_SESSION['flash_ok']) . '</div>';
            unset($_SESSION['flash_ok']);
        }
        if (!empty($_SESSION['flash_err'])) {
            echo '<div class="toast toast-err">' . e($_SESSION['flash_err']) . '</div>';
            unset($_SESSION['flash_err']);
        }
}

function admin_page_end() {
    ?>
    </div>
</div>
<script>
window.JAY = { isLogin: true, isAdmin: true, csrf: '<?= e(csrf_token()) ?>' };
(function () {
    var burger = document.getElementById('adminBurger'), side = document.getElementById('adminSide');
    if (burger) burger.addEventListener('click', function (e) { e.stopPropagation(); side.classList.toggle('open'); });
    document.addEventListener('click', function (e) {
        if (side && !side.contains(e.target) && burger && !burger.contains(e.target)) side.classList.remove('open');
    });
    var avatarBtn = document.getElementById('avatarBtn'), userBox = document.getElementById('navUser') || avatarBtn && avatarBtn.parentElement;
    if (avatarBtn && userBox) {
        avatarBtn.addEventListener('click', function (e) { e.stopPropagation(); userBox.classList.toggle('menu-open'); });
        document.addEventListener('click', function () { userBox.classList.remove('menu-open'); });
        var um = document.getElementById('userMenu');
        if (um) um.addEventListener('click', function (e) { e.stopPropagation(); });
    }
    setTimeout(function () { document.querySelectorAll('.toast').forEach(function (t) { t.remove(); }); }, 5000);
})();
</script>
</body>
</html>
<?php }
