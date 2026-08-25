<?php
/**
 * Jay影视 - 公共函数库
 * 兼容 PHP 7.4 - 8.x
 */

/* ---------------- 基础工具 ---------------- */

function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

function is_post() {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function post_val($key, $default = '') {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function get_val($key, $default = '') {
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function contains($haystack, $needle) {
    return $needle !== '' && strpos((string)$haystack, (string)$needle) !== false;
}

function starts_with($haystack, $needle) {
    return $needle !== '' && substr((string)$haystack, 0, strlen((string)$needle)) === (string)$needle;
}

function mb_len($s) {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function mb_sub($s, $start, $len) {
    return function_exists('mb_substr') ? mb_substr($s, $start, $len, 'UTF-8') : substr($s, $start, $len);
}

function format_seconds($sec) {
    $sec = max(0, (int)$sec);
    $h = floor($sec / 3600);
    $m = floor(($sec % 3600) / 60);
    $s = $sec % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
}

function time_ago($time) {
    $diff = time() - strtotime($time);
    if ($diff < 60)      return '刚刚';
    if ($diff < 3600)    return floor($diff / 60) . ' 分钟前';
    if ($diff < 86400)   return floor($diff / 3600) . ' 小时前';
    if ($diff < 2592000) return floor($diff / 86400) . ' 天前';
    return date('Y-m-d', strtotime($time));
}

/* ---------------- 数据库 ---------------- */

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                // MySQL 会话时区与 PHP 对齐（北京时间），保证 NOW() 与 time() 一致
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'",
            ]);
        } catch (PDOException $ex) {
            die('<!doctype html><meta charset="utf-8"><body style="background:#0b0e14;color:#e8eaf0;font-family:sans-serif;padding:60px;text-align:center"><h2>数据库连接失败</h2><p style="color:#8b93a7">' . e($ex->getMessage()) . '</p></body>');
        }
    }
    return $pdo;
}

function db_query($sql, $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_one($sql, $params = []) {
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_all($sql, $params = []) {
    return db_query($sql, $params)->fetchAll();
}

function db_val($sql, $params = [], $default = null) {
    $v = db_query($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}

/* ---------------- 设置 ---------------- */

function setting($key, $default = '') {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db_all("SELECT skey, svalue FROM settings") as $row) {
                $cache[$row['skey']] = $row['svalue'];
            }
        } catch (Exception $e) { /* 安装前调用 */ }
    }
    return isset($cache[$key]) && $cache[$key] !== '' ? $cache[$key] : $default;
}

function setting_save($key, $value) {
    db_query("INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)", [$key, $value]);
}

function site_name() {
    return setting('site_name', 'Jay影视');
}

function theme_color() {
    $c = setting('theme_color', '#e50914');
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#e50914';
}

/* ---------------- 用户与认证 ---------------- */

function current_user() {
    static $user = null;
    if ($user === null && !empty($_SESSION['uid'])) {
        $user = db_one("SELECT * FROM users WHERE id = ?", [(int)$_SESSION['uid']]);
        if (!$user) { $user = false; }
    }
    return $user ?: null;
}

function is_login() {
    return current_user() !== null;
}

function is_admin() {
    $u = current_user();
    return $u && (int)$u['is_admin'] === 1;
}

function require_login_json() {
    if (!is_login()) {
        json_out(['ok' => false, 'need_login' => true, 'msg' => '需要登录才可以观看哦，如没有账号请注册！']);
    }
}

/** 用户是否处于封禁期 */
function user_banned($user) {
    if (!$user || (int)$user['status'] !== 0) return false;
    $now  = time();
    $end  = $user['ban_end']   ? strtotime($user['ban_end'])   : null;
    $strt = $user['ban_start'] ? strtotime($user['ban_start']) : 0;
    if ($end !== null && $now >= $end) return false; // 已到期，自动解除
    return $now >= $strt;
}

/** 输出用户名（管理员追加红色「开发者」标识） */
function display_username($user, $with_badge = true) {
    $name = e($user['username']);
    if ($with_badge && (int)$user['is_admin'] === 1) {
        $name .= ' <span class="badge-dev">开发者</span>';
    }
    return $name;
}

function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check() {
    $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$token)) {
        json_out(['ok' => false, 'msg' => '会令牌已失效，请刷新页面重试'], 419);
    }
    return true;
}

/* ---------------- HTTP 请求 ---------------- */

function http_proxy_for($url) {
    // 尊重环境变量代理（部分托管/本地环境需要）；生产环境未设置时不生效
    $isHttps = stripos($url, 'https://') === 0;
    $names = $isHttps ? ['HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy']
                      : ['HTTP_PROXY', 'http_proxy'];
    foreach ($names as $n) {
        $v = getenv($n);
        if ($v !== false && $v !== '') return $v;
    }
    return null;
}

function http_get($url, $timeout = 15) {
    if (function_exists('curl_init')) {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) JayMovie/1.0',
        ];
        $proxy = http_proxy_for($url);
        if ($proxy) $opts[CURLOPT_PROXY] = $proxy;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body !== false && $code >= 200 && $code < 300) return $body;
        return $body !== false && $body !== '' ? $body : false;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'header' => "User-Agent: JayMovie/1.0\r\n"]]);
    return @file_get_contents($url, false, $ctx);
}

function http_get_json($url, $timeout = 15) {
    $body = http_get($url, $timeout);
    if ($body === false || $body === '') return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/* ---------------- 媒体缓存 ---------------- */

function cache_get($key) {
    try {
        $row = db_one("SELECT cache_value FROM media_cache WHERE cache_key = ?", [$key]);
        if (!$row) return null;
        $data = @unserialize($row['cache_value']);
        if ($data === false || !is_array($data) || !isset($data['e'], $data['v'])) return null;
        if ($data['e'] !== 0 && $data['e'] < time()) return null; // 过期
        return $data['v'];
    } catch (Exception $e) {
        return null;
    }
}

function cache_set($key, $value, $seconds = 1800) {
    try {
        $payload = serialize(['e' => $seconds > 0 ? time() + $seconds : 0, 'v' => $value]);
        db_query("INSERT INTO media_cache (cache_key, cache_value, created_at) VALUES (?, ?, NOW())
                  ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), created_at = NOW()", [$key, $payload]);
    } catch (Exception $e) { /* 缓存失败不影响业务 */ }
}

/* ---------------- 其他 ---------------- */

function client_ip() {
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}
