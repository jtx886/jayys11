<?php
/**
 * Jay影视 - 安装向导
 * 首次访问填写数据库信息 → 自动建表 → 生成配置 → 销毁安装入口
 * 兼容 PHP 7.4 - 8.x / MySQL 5.6+ / InfinityFree 免费空间
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

header('Content-Type: text/html; charset=utf-8');

/* ---------- 已安装则禁止再次安装 ---------- */
$installed = false;
if (file_exists(__DIR__ . '/includes/config.php')) {
    require __DIR__ . '/includes/config.php';
    if (defined('APP_INSTALLED') && APP_INSTALLED) $installed = true;
}

/* ---------- 环境检测 ---------- */
$env = [
    ['PHP 版本 >= 7.4', version_compare(PHP_VERSION, '7.4.0', '>=')],
    ['PDO MySQL 扩展', extension_loaded('pdo_mysql')],
    ['cURL 扩展 或 allow_url_fopen', function_exists('curl_init') || (bool)ini_get('allow_url_fopen')],
    ['includes/ 目录可写（生成配置）', is_writable(__DIR__ . '/includes')],
    ['uploads/ 目录可写（用户头像）', is_writable(__DIR__ . '/uploads') || @mkdir(__DIR__ . '/uploads', 0755, true)],
];

$err  = '';
$done = false;
$doneInfo = [];

if (!$installed && isset($_POST['action']) && $_POST['action'] === 'install') {
    $dbHost = trim((string)($_POST['db_host'] ?? ''));
    $dbName = trim((string)($_POST['db_name'] ?? ''));
    $dbUser = trim((string)($_POST['db_user'] ?? ''));
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $admMail = trim((string)($_POST['admin_email'] ?? ''));
    $admName = trim((string)($_POST['admin_user'] ?? ''));
    $admPass = (string)($_POST['admin_pass'] ?? '');

    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        $err = '请完整填写数据库信息';
    } elseif (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
        $err = '数据库名只能包含字母、数字、下划线';
    } elseif (!filter_var($admMail, FILTER_VALIDATE_EMAIL)) {
        $err = '管理员邮箱格式不正确';
    } elseif ($admName === '' || mb_strlen($admName, 'UTF-8') < 2 || mb_strlen($admName, 'UTF-8') > 20) {
        $err = '管理员用户名需在 2-20 字之间';
    } elseif ($admPass === '' || mb_strlen($admPass, 'UTF-8') < 6 || mb_strlen($admPass, 'UTF-8') > 32) {
        $err = '管理员密码需在 6-32 字之间';
    } else {
        /* ---------- 连接数据库（localhost 自动回退 127.0.0.1） ---------- */
        $pdo = null;
        $hosts = strcasecmp($dbHost, 'localhost') === 0 ? ['localhost', '127.0.0.1'] : [$dbHost];
        $connErr = '';
        foreach ($hosts as $h) {
            try {
                $pdo = new PDO("mysql:host={$h};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 8,
                ]);
                break;
            } catch (Exception $e) {
                $connErr = $e->getMessage();
                $pdo = null;
                /* 库不存在时尝试自动创建（本地环境） */
                try {
                    $pdo2 = new PDO("mysql:host={$h};charset=utf8mb4", $dbUser, $dbPass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT => 8,
                    ]);
                    $pdo2->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $pdo = new PDO("mysql:host={$h};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT => 8,
                    ]);
                    break;
                } catch (Exception $e2) {
                    $connErr = $e2->getMessage();
                    $pdo = null;
                }
            }
        }
        if (!$pdo) {
            $err = '数据库连接失败：' . $connErr . '（InfinityFree 请填写控制台提供的数据库主机名，如 sqlxxx.infinityfree.com）';
        } else {
            try {
                $pdo->exec("SET NAMES utf8mb4");

                /* ---------- 建表 ---------- */
                $tables = [
"CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    username VARCHAR(50) NOT NULL,
    password VARCHAR(255) NOT NULL,
    avatar VARCHAR(500) NOT NULL DEFAULT '',
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    status TINYINT(1) NOT NULL DEFAULT 1,
    ban_reason VARCHAR(500) NOT NULL DEFAULT '',
    ban_start DATETIME NULL DEFAULT NULL,
    ban_end DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_email (email),
    UNIQUE KEY uk_username (username)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS email_codes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    code VARCHAR(10) NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_email (email)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS favorites (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    media_type VARCHAR(10) NOT NULL DEFAULT 'movie',
    tmdb_id INT UNSIGNED NOT NULL DEFAULT 0,
    title VARCHAR(255) NOT NULL DEFAULT '',
    poster VARCHAR(500) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_fav (user_id, media_type, tmdb_id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS watch_history (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    media_type VARCHAR(10) NOT NULL DEFAULT 'movie',
    tmdb_id INT UNSIGNED NOT NULL DEFAULT 0,
    title VARCHAR(255) NOT NULL DEFAULT '',
    poster VARCHAR(500) NOT NULL DEFAULT '',
    season SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    episode SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    episode_name VARCHAR(255) NOT NULL DEFAULT '',
    position INT UNSIGNED NOT NULL DEFAULT 0,
    duration INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_hist (user_id, media_type, tmdb_id, season, episode)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS feedbacks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user (user_id),
    KEY idx_created (created_at)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS feedback_replies (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    feedback_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fb (feedback_id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS feedback_likes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    feedback_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_like (feedback_id, user_id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS notices (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS play_sources (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    api_url VARCHAR(500) NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS settings (
    skey VARCHAR(60) NOT NULL,
    svalue TEXT NULL,
    PRIMARY KEY (skey)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS media_cache (
    cache_key VARCHAR(100) NOT NULL,
    cache_value LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cache_key)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ];
                foreach ($tables as $sql) $pdo->exec($sql);

                /* ---------- 初始数据 ---------- */
                $st = $pdo->prepare("INSERT IGNORE INTO settings (skey, svalue) VALUES (?, ?)");
                $st->execute(['site_name', 'Jay影视']);
                $st->execute(['theme_color', '#e50914']);
                $st->execute(['tmdb_api_key', '']);

                if ((int)$pdo->query("SELECT COUNT(*) FROM play_sources")->fetchColumn() === 0) {
                    $pdo->prepare("INSERT INTO play_sources (name, api_url, is_default) VALUES (?, ?, 1)")
                        ->execute(['默认资源站', 'https://api.yyzy-tv.vip/inc/apijson.php']);
                }

                if ((int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn() === 0) {
                    $pdo->prepare("INSERT INTO users (email, username, password, is_admin, status) VALUES (?, ?, ?, 1, 1)")
                        ->execute([$admMail, $admName, password_hash($admPass, PASSWORD_DEFAULT)]);
                }

                /* ---------- 生成配置文件 ---------- */
                $cfg = "<?php\n"
                     . "// Jay影视 配置文件（由安装向导生成）\n"
                     . "define('DB_HOST', " . var_export($dbHost, true) . ");\n"
                     . "define('DB_NAME', " . var_export($dbName, true) . ");\n"
                     . "define('DB_USER', " . var_export($dbUser, true) . ");\n"
                     . "define('DB_PASS', " . var_export($dbPass, true) . ");\n"
                     . "define('APP_INSTALLED', true);\n";
                if (@file_put_contents(__DIR__ . '/includes/config.php', $cfg) === false) {
                    throw new Exception('无法写入 includes/config.php，请检查目录权限');
                }
                @chmod(__DIR__ . '/includes/config.php', 0644);

                $done = true;
                $doneInfo = ['host' => $dbHost, 'name' => $dbName, 'admin' => $admName];

                /* ---------- 销毁安装入口 ---------- */
                @unlink(__FILE__);
            } catch (Exception $e) {
                $err = '安装失败：' . $e->getMessage();
            }
        }
    }
}

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>安装向导 - Jay影视</title>
<link rel="stylesheet" href="assets/css/style.css?v=1.0">
<style>
body{background:radial-gradient(1200px 600px at 70% -10%,rgba(229,9,20,.16),transparent 60%),#0b0f17;margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;color:#e8eaf0;min-height:100vh}
.inst-card{width:100%;max-width:520px;margin:40px auto;background:rgba(20,24,33,.94);border:1px solid rgba(255,255,255,.08);border-radius:20px;padding:36px 34px;box-shadow:0 24px 70px rgba(0,0,0,.55);animation:up .5s ease both}
@keyframes up{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:none}}
.inst-logo{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:10px}
.logo-box{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#e50914,#ff5a3c);display:inline-flex;align-items:center;justify-content:center;box-shadow:0 6px 18px rgba(229,9,20,.4)}
.logo-text{font-size:22px;font-weight:800;color:#fff}
.logo-text b{color:#e50914}
.inst-title{text-align:center;font-size:23px;font-weight:800;margin:0 0 4px}
.inst-sub{text-align:center;color:#8b93a7;font-size:13px;margin:0 0 24px}
.env-list{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:14px;padding:6px 16px;margin-bottom:24px}
.env-row{display:flex;align-items:center;justify-content:space-between;padding:9px 0;font-size:13.5px;color:#c3c9d6;border-bottom:1px dashed rgba(255,255,255,.06)}
.env-row:last-child{border-bottom:none}
.env-ico{width:18px;height:18px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;flex:none}
.env-ok{background:rgba(46,204,151,.15);border:1px solid #2ecc97}
.env-no{background:rgba(239,68,68,.15);border:1px solid #ef4444}
.env-ok::after{content:'';width:4px;height:8px;border-right:2px solid #2ecc97;border-bottom:2px solid #2ecc97;transform:rotate(45deg) translate(-1px,-1px)}
.env-no::after,.env-no::before{content:'';position:absolute;width:10px;height:2px;background:#ef4444;border-radius:2px}
.env-no{position:relative}
.env-no::after{transform:rotate(45deg)}
.env-no::before{transform:rotate(-45deg)}
.field{margin-bottom:16px}
.field label{display:block;font-size:13px;color:#aeb6c8;margin-bottom:7px;font-weight:600}
.input{width:100%;box-sizing:border-box;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:11px 14px;color:#e8eaf0;font-size:14px;outline:none;transition:border-color .25s,box-shadow .25s}
.input:focus{border-color:#e50914;box-shadow:0 0 0 3px rgba(229,9,20,.18)}
.hint{font-size:12px;color:#7d8598;margin-top:6px;line-height:1.7}
.inst-err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.4);color:#ff9d9d;border-radius:10px;padding:12px 16px;font-size:13.5px;margin-bottom:18px;line-height:1.8;animation:shake .4s ease}
@keyframes shake{0%,100%{transform:none}25%{transform:translateX(-5px)}75%{transform:translateX(5px)}}
.divider{display:flex;align-items:center;gap:12px;color:#6b7385;font-size:12px;margin:22px 0 18px}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:rgba(255,255,255,.08)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:none;cursor:pointer;border-radius:12px;font-weight:700;transition:transform .2s,box-shadow .2s,opacity .2s;text-decoration:none}
.btn-primary{background:linear-gradient(135deg,#e50914,#ff5a3c);color:#fff;padding:13px 20px;font-size:15px;width:100%;box-shadow:0 8px 24px rgba(229,9,20,.35)}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 12px 30px rgba(229,9,20,.45)}
.success-ring{width:76px;height:76px;margin:6px auto 18px;border-radius:50%;background:rgba(46,204,151,.12);border:2px solid #2ecc97;display:flex;align-items:center;justify-content:center;animation:pop .5s cubic-bezier(.34,1.56,.64,1) both}
@keyframes pop{from{transform:scale(.4);opacity:0}to{transform:scale(1);opacity:1}}
.success-ring::after{content:'';width:16px;height:30px;border-right:4px solid #2ecc97;border-bottom:4px solid #2ecc97;transform:rotate(45deg) translate(-2px,-4px)}
.done-box{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:14px;padding:16px 20px;font-size:13.5px;color:#c3c9d6;margin:20px 0;line-height:2}
.done-box b{color:#fff}
.tag-ok{display:inline-block;background:rgba(46,204,151,.14);color:#2ecc97;border:1px solid rgba(46,204,151,.4);border-radius:20px;padding:2px 12px;font-size:12px;font-weight:600}
@media(max-width:560px){.inst-card{margin:16px 12px;padding:28px 22px}}
</style>
</head>
<body>

<?php if ($done): ?>
<div class="inst-card" style="text-align:center">
    <div class="inst-logo">
        <span class="logo-box"><i class="i i-play"></i></span>
        <span class="logo-text">Jay<b>影视</b></span>
    </div>
    <div class="success-ring"></div>
    <div class="inst-title">安装完成</div>
    <p class="inst-sub">安装入口已自动销毁，即将进入首页…</p>
    <div class="done-box">
        <div>数据库：<b><?= e($doneInfo['name']) ?></b> @ <?= e($doneInfo['host']) ?></div>
        <div>管理员：<b><?= e($doneInfo['admin']) ?></b>（登录后台：admin/index.php）</div>
        <div>默认播放源与全站配置已初始化 <span class="tag-ok">就绪</span></div>
    </div>
    <div class="hint">提示：请前往后台「网站设置」填写 TMDB API Key 以启用影视数据。<br>若浏览器未自动跳转，<a href="index.php" style="color:#e50914">点击这里进入首页</a></div>
    <script>setTimeout(function(){location.href='index.php';},3500);</script>
</div>

<?php elseif ($installed): ?>
<div class="inst-card" style="text-align:center">
    <div class="inst-logo">
        <span class="logo-box"><i class="i i-play"></i></span>
        <span class="logo-text">Jay<b>影视</b></span>
    </div>
    <div class="inst-title">已是安装状态</div>
    <p class="inst-sub">检测到站点已完成安装，安装向导已停用。为安全起见请删除本文件。</p>
    <a class="btn btn-primary" href="index.php">进入首页</a>
</div>

<?php else: ?>
<div class="inst-card">
    <div class="inst-logo">
        <span class="logo-box"><i class="i i-play"></i></span>
        <span class="logo-text">Jay<b>影视</b></span>
    </div>
    <div class="inst-title">安装向导</div>
    <p class="inst-sub">填写数据库信息，自动建表并生成配置，完成后安装入口将自动销毁</p>

    <div class="env-list">
        <?php foreach ($env as $it): ?>
        <div class="env-row">
            <span><?= e($it[0]) ?></span>
            <span class="env-ico <?= $it[1] ? 'env-ok' : 'env-no' ?>" title="<?= $it[1] ? '通过' : '未通过' ?>"></span>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($err): ?><div class="inst-err"><?= e($err) ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="action" value="install">

        <div class="divider">数据库信息</div>
        <div class="field">
            <label>数据库主机</label>
            <input class="input" type="text" name="db_host" value="<?= e($_POST['db_host'] ?? '127.0.0.1') ?>" required>
            <div class="hint">本地环境填 127.0.0.1；InfinityFree 填控制台数据库主机名（如 sqlxxx.infinityfree.com）</div>
        </div>
        <div class="field">
            <label>数据库名</label>
            <input class="input" type="text" name="db_name" value="<?= e($_POST['db_name'] ?? '') ?>" required>
        </div>
        <div class="field">
            <label>数据库用户名</label>
            <input class="input" type="text" name="db_user" value="<?= e($_POST['db_user'] ?? '') ?>" required>
        </div>
        <div class="field">
            <label>数据库密码</label>
            <input class="input" type="password" name="db_pass" value="<?= e($_POST['db_pass'] ?? '') ?>">
        </div>

        <div class="divider">管理员账号（后台登录）</div>
        <div class="field">
            <label>管理员邮箱</label>
            <input class="input" type="email" name="admin_email" value="<?= e($_POST['admin_email'] ?? 'admin@jaymovie.local') ?>" required>
        </div>
        <div class="field">
            <label>管理员用户名</label>
            <input class="input" type="text" name="admin_user" value="<?= e($_POST['admin_user'] ?? '杰同学') ?>" required>
        </div>
        <div class="field">
            <label>管理员密码</label>
            <input class="input" type="text" name="admin_pass" value="<?= e($_POST['admin_pass'] ?? '101113') ?>" required>
        </div>

        <button class="btn btn-primary" type="submit">开始安装</button>
        <div class="hint" style="text-align:center;margin-top:12px">安装将创建 11 张业务数据表并写入默认配置与播放源</div>
    </form>
</div>
<?php endif; ?>

</body>
</html>
