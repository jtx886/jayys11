<?php
/**
 * Jay影视 - 退出登录
 */
require __DIR__ . '/includes/init.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
session_name('JAYSESS');
session_start();
$_SESSION['flash_ok'] = '已安全退出登录';
redirect('index.php');
