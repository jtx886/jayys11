<?php
/**
 * Jay影视 - 页面布局（导航栏 + 页脚）
 * 需在 init.php 之后引入
 */

if (!defined('JAY_INIT')) { http_response_code(403); exit('Forbidden'); }

function page_start($opts = []) {
    $active  = isset($opts['active']) ? $opts['active'] : '';
    $title   = isset($opts['title']) ? $opts['title'] : site_name();
    $fullW   = !empty($opts['full_width']);
    $navs    = [
        'home'    => ['首页', 'index.php'],
        'movie'   => ['电影', 'index.php?cat=movie'],
        'tv'      => ['剧集', 'index.php?cat=tv'],
        'anime'   => ['动漫', 'index.php?cat=anime'],
        'variety' => ['综艺', 'index.php?cat=variety'],
        'feedback'=> ['反馈', 'feedback.php'],
    ];
    $user    = current_user();
    $q       = get_val('q', get_val('query', ''));
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= e($title) ?> - <?= e(site_name()) ?></title>
<meta name="description" content="<?= e(site_name()) ?> - 在线高清影视播放">
<link rel="stylesheet" href="assets/css/style.css?v=1.0">
<style>:root{--accent:<?= e(theme_color()) ?>;}</style>
</head>
<body>
<div class="nav-wrap">
<nav class="nav container <?= $fullW ? '' : '' ?>">
    <a class="nav-logo" href="index.php">
        <span class="logo-box"><i class="i i-play"></i></span>
        <span class="logo-text">Jay<b>影视</b></span>
    </a>
    <button class="nav-burger" id="navBurger" aria-label="菜单"><span></span><span></span><span></span></button>
    <div class="nav-menu" id="navMenu">
        <?php foreach ($navs as $key => $nav): ?>
        <a href="<?= $nav[1] ?>" class="nav-link <?= $active === $key ? 'active' : '' ?>"><?= e($nav[0]) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="nav-right">
        <form class="nav-search" action="search.php" method="get">
            <i class="i i-search"></i>
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="搜索影视 / 剧集 / 综艺" autocomplete="off">
        </form>
        <?php if ($user): ?>
        <div class="nav-user" id="navUser">
            <button class="nav-avatar-btn" id="avatarBtn">
                <?php if ($user['avatar']): ?>
                <img class="nav-avatar-img" src="<?= e($user['avatar']) ?>" alt="">
                <?php else: ?>
                <span class="nav-avatar-img nav-avatar-txt"><?= e(mb_strtoupper(mb_sub($user['username'], 0, 1))) ?></span>
                <?php endif; ?>
            </button>
            <div class="nav-user-menu" id="userMenu">
                <div class="nav-user-head">
                    <?php if ($user['avatar']): ?>
                    <img class="um-avatar" src="<?= e($user['avatar']) ?>" alt="">
                    <?php else: ?>
                    <span class="um-avatar um-avatar-txt"><?= e(mb_strtoupper(mb_sub($user['username'], 0, 1))) ?></span>
                    <?php endif; ?>
                    <div>
                        <div class="um-name"><?= display_username($user) ?></div>
                        <div class="um-mail"><?= e($user['email']) ?></div>
                    </div>
                </div>
                <a class="um-item" href="profile.php"><i class="i i-user"></i>个人中心</a>
                <?php if ((int)$user['is_admin'] === 1): ?>
                <a class="um-item" href="admin/index.php"><i class="i i-gear"></i>管理后台</a>
                <?php endif; ?>
                <a class="um-item um-out" href="logout.php"><i class="i i-out"></i>退出登录</a>
            </div>
        </div>
        <?php else: ?>
        <div class="nav-auth">
            <a class="btn btn-ghost btn-sm" href="login.php">登录</a>
            <a class="btn btn-primary btn-sm" href="register.php">注册</a>
        </div>
        <?php endif; ?>
    </div>
</nav>
</div>
<main class="<?= $fullW ? '' : 'container' ?>" id="main">
<?php
    // 全局提示 (Toast)
    if (!empty($_SESSION['flash_ok'])) {
        echo '<div class="toast toast-ok" data-toast>' . e($_SESSION['flash_ok']) . '<button class="toast-x" onclick="this.parentElement.remove()">&times;</button></div>';
        unset($_SESSION['flash_ok']);
    }
    if (!empty($_SESSION['flash_err'])) {
        echo '<div class="toast toast-err" data-toast>' . e($_SESSION['flash_err']) . '<button class="toast-x" onclick="this.parentElement.remove()">&times;</button></div>';
        unset($_SESSION['flash_err']);
    }
}

function page_end() {
    $user = current_user();
    ?>
</main>
<footer class="footer">
    <div class="container">
        <div class="footer-top">
            <div class="footer-brand">
                <span class="logo-box sm"><i class="i i-play"></i></span>
                <span>Jay影视</span>
            </div>
            <div class="footer-links">
                <a href="index.php">首页</a><a href="index.php?cat=movie">电影</a><a href="index.php?cat=tv">剧集</a><a href="index.php?cat=anime">动漫</a><a href="index.php?cat=variety">综艺</a><a href="feedback.php">反馈</a>
            </div>
        </div>
        <div class="footer-note">本站所有影视数据来源于 TMDB，视频内容来自第三方播放源，仅供学习交流使用。</div>
    </div>
</footer>
<script>
window.JAY = {
    isLogin: <?= $user ? 'true' : 'false' ?>,
    isAdmin: <?= ($user && (int)$user['is_admin'] === 1) ? 'true' : 'false' ?>,
    csrf: '<?= e(csrf_token()) ?>',
    needLoginMsg: '需要登录才可以观看哦，如没有账号请注册！'
};
</script>
<script src="assets/js/main.js?v=1.0"></script>
</body>
</html>
<?php }
