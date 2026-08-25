<?php
/**
 * Jay影视 - 应用引导
 */

if (!defined('JAY_INIT')) {
    // 防止直接访问
    if (basename($_SERVER['SCRIPT_NAME']) === 'init.php') { http_response_code(403); exit('Forbidden'); }
    define('JAY_INIT', true);
}

// 未安装 → 跳转安装向导
if (!file_exists(__DIR__ . '/config.php')) {
    if (basename($_SERVER['SCRIPT_NAME']) !== 'install.php') {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        header('Location: ' . ($base === '' ? '' : $base) . '/install.php');
        exit;
    }
}

require_once __DIR__ . '/config.php';

if (!defined('APP_INSTALLED')) {
    if (basename($_SERVER['SCRIPT_NAME']) !== 'install.php') {
        header('Location: install.php');
        exit;
    }
}

// 错误报告（兼容 7.4/8.x，避免 8.x 弃用警告刷屏）
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    session_name('JAYSESS');
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/smtp.php';
require_once __DIR__ . '/tmdb.php';
require_once __DIR__ . '/source.php';

db(); // 连接数据库

/* ---------- 封禁状态检查 ---------- */
$user = current_user();
if ($user !== null) {
    if ((int)$user['status'] === 0) {
        $now = time();
        $end = $user['ban_end'] ? strtotime($user['ban_end']) : null;
        if ($end !== null && $now >= $end) {
            // 封禁到期自动解除
            db_query("UPDATE users SET status = 1, ban_start = NULL, ban_end = NULL, ban_reason = '' WHERE id = ?", [$user['id']]);
            $user['status'] = 1;
        } else {
            // 处于封禁期，强制登出
            $_SESSION = [];
            session_destroy();
            $msg = '账号处于封禁期间，无法访问';
            if (session_status() === PHP_SESSION_NONE) { session_name('JAYSESS'); session_start(); }
            $_SESSION['flash_ban'] = [
                'reason' => $user['ban_reason'],
                'start'  => $user['ban_start'],
                'end'    => $user['ban_end'],
            ];
            if (basename($_SERVER['SCRIPT_NAME']) !== 'login.php') {
                redirect('login.php');
            }
        }
    }
    if (!empty($_SESSION['uid']) && $user) {
        // 保持全局可用
        $GLOBALS['CURRENT_USER'] = $user;
    }
}
