<?php
/**
 * Jay影视 - AJAX 接口
 */
require __DIR__ . '/includes/init.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

function out($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

/* ---------- 游客仅可访问的接口 ---------- */
if ($action === 'get_replies') {
    $fid = (int)($_GET['id'] ?? 0);
    if ($fid <= 0) out(['ok' => false, 'msg' => '参数错误']);
    $replies = db_all(
        "SELECT r.id, r.content, r.created_at, u.username, u.avatar, u.is_admin
         FROM feedback_replies r JOIN users u ON u.id = r.user_id
         WHERE r.feedback_id = ?
         ORDER BY u.is_admin DESC, r.created_at ASC", [$fid]);
    $list = [];
    foreach ($replies as $r) {
        $list[] = [
            'id' => (int)$r['id'],
            'username' => $r['username'],
            'avatar' => $r['avatar'],
            'is_admin' => (int)$r['is_admin'],
            'content' => $r['content'],
            'time' => time_ago($r['created_at']),
        ];
    }
    out(['ok' => true, 'replies' => $list]);
}

/* ---------- 需要登录的接口 ---------- */
$user = current_user();
if (!$user) out(['ok' => false, 'need_login' => true, 'msg' => '需要登录才可以观看哦，如没有账号请注册！']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($action, ['send_code'], true)) {
    csrf_check();
}

switch ($action) {

    /* 注册验证码 */
    case 'send_code': {
        $email = post_val('email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out(['ok' => false, 'msg' => '邮箱格式不正确']);
        if (db_one("SELECT id FROM users WHERE email = ?", [$email])) out(['ok' => false, 'msg' => '该邮箱已被注册，请直接登录']);
        // 频率限制 60 秒
        $last = db_one("SELECT created_at FROM email_codes WHERE email = ? ORDER BY id DESC LIMIT 1", [$email]);
        if ($last && time() - strtotime($last['created_at']) < 60) {
            out(['ok' => false, 'msg' => '发送过于频繁，请 60 秒后重试']);
        }
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        db_query("INSERT INTO email_codes (email, code, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))", [$email, $code]);
        list($ok, $err) = send_mail($email, $email, 'Jay影视 注册验证码', mail_template_code($code));
        if ($ok) out(['ok' => true, 'msg' => '验证码已发送']);
        out(['ok' => false, 'msg' => '邮件发送失败：' . $err]);
    }

    /* 收藏切换 */
    case 'toggle_favorite': {
        $mt = post_val('media_type') === 'tv' ? 'tv' : 'movie';
        $tid = (int)post_val('tmdb_id');
        if ($tid <= 0) out(['ok' => false, 'msg' => '参数错误']);
        $ex = db_one("SELECT id FROM favorites WHERE user_id = ? AND media_type = ? AND tmdb_id = ?", [$user['id'], $mt, $tid]);
        if ($ex) {
            db_query("DELETE FROM favorites WHERE id = ?", [$ex['id']]);
            out(['ok' => true, 'faved' => false]);
        }
        db_query("INSERT INTO favorites (user_id, media_type, tmdb_id, title, poster) VALUES (?, ?, ?, ?, ?)", [
            $user['id'], $mt, $tid, post_val('title'), post_val('poster'),
        ]);
        out(['ok' => true, 'faved' => true]);
    }

    /* 删除收藏 */
    case 'remove_favorite': {
        $id = (int)post_val('id');
        db_query("DELETE FROM favorites WHERE id = ? AND user_id = ?", [$id, $user['id']]);
        out(['ok' => true]);
    }

    /* 观看进度心跳 */
    case 'heartbeat': {
        $mt = post_val('media_type') === 'tv' ? 'tv' : 'movie';
        $tid = (int)post_val('tmdb_id');
        $season = max(1, (int)post_val('season', 1));
        $episode = max(1, (int)post_val('episode', 1));
        $position = max(0, (int)post_val('position', 0));
        if ($tid <= 0) out(['ok' => false]);
        db_query("INSERT INTO watch_history (user_id, media_type, tmdb_id, title, poster, season, episode, episode_name, position, duration, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
                   ON DUPLICATE KEY UPDATE position = GREATEST(position, VALUES(position)), title = VALUES(title), poster = VALUES(poster),
                   episode_name = VALUES(episode_name), updated_at = NOW()", [
            $user['id'], $mt, $tid, post_val('title'), post_val('poster'), $season, $episode, post_val('episode_name'), $position,
        ]);
        out(['ok' => true]);
    }

    /* 删除观看记录 */
    case 'remove_history': {
        $id = (int)post_val('id');
        db_query("DELETE FROM watch_history WHERE id = ? AND user_id = ?", [$id, $user['id']]);
        out(['ok' => true]);
    }

    /* 反馈点赞 */
    case 'like_feedback': {
        $fid = (int)post_val('id');
        if ($fid <= 0 || !db_one("SELECT id FROM feedbacks WHERE id = ?", [$fid])) out(['ok' => false, 'msg' => '反馈不存在']);
        $ex = db_one("SELECT id FROM feedback_likes WHERE feedback_id = ? AND user_id = ?", [$fid, $user['id']]);
        if ($ex) {
            db_query("DELETE FROM feedback_likes WHERE id = ?", [$ex['id']]);
        } else {
            db_query("INSERT INTO feedback_likes (feedback_id, user_id) VALUES (?, ?)", [$fid, $user['id']]);
        }
        $count = (int)db_val("SELECT COUNT(*) FROM feedback_likes WHERE feedback_id = ?", [$fid]);
        out(['ok' => true, 'liked' => !$ex, 'count' => $count]);
    }

    /* 回复反馈 */
    case 'reply_feedback': {
        $fid = (int)post_val('id');
        $content = post_val('content');
        if (!db_one("SELECT id FROM feedbacks WHERE id = ?", [$fid])) out(['ok' => false, 'msg' => '反馈不存在']);
        if ($content === '' || mb_len($content) > 1000) out(['ok' => false, 'msg' => '回复内容需在 1-1000 字之间']);
        db_query("INSERT INTO feedback_replies (feedback_id, user_id, content) VALUES (?, ?, ?)", [$fid, $user['id'], $content]);
        out(['ok' => true]);
    }

    /* 上传头像（form 提交到 profile.php，此处不处理） */

    default:
        out(['ok' => false, 'msg' => '未知操作']);
}
