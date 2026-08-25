<?php
/**
 * Jay影视 - 管理后台 · 反馈管理
 * 查看全部用户反馈，支持以管理员身份回复
 */
require __DIR__ . '/_boot.php';

if (is_post()) {
    csrf_check();
    $action = post_val('action');
    if ($action === 'reply') {
        $fid = (int)post_val('fid');
        $content = post_val('content');
        if (!db_one("SELECT id FROM feedbacks WHERE id = ?", [$fid])) {
            $_SESSION['flash_err'] = '反馈不存在';
        } elseif ($content === '' || mb_len($content) > 1000) {
            $_SESSION['flash_err'] = '回复内容需在 1-1000 字之间';
        } else {
            db_query("INSERT INTO feedback_replies (feedback_id, user_id, content) VALUES (?, ?, ?)", [$fid, current_user()['id'], $content]);
            $_SESSION['flash_ok'] = '回复成功，前台将优先展示该官方回复';
        }
    } elseif ($action === 'delete') {
        $fid = (int)post_val('fid');
        db_query("DELETE FROM feedbacks WHERE id = ?", [$fid]);
        db_query("DELETE FROM feedback_replies WHERE feedback_id = ?", [$fid]);
        db_query("DELETE FROM feedback_likes WHERE feedback_id = ?", [$fid]);
        $_SESSION['flash_ok'] = '反馈已删除';
    }
    redirect('feedback.php?page=' . (int)get_val('page', 1) . '&q=' . urlencode(get_val('q', '')));
}

$q = get_val('q', '');
$page = max(1, (int)get_val('page', 1));
$per = 8;
$where = '';
$params = [];
if ($q !== '') {
    $where = " WHERE f.title LIKE ? OR f.content LIKE ? OR u.username LIKE ? ";
    $params = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%'];
}
$total = (int)db_val("SELECT COUNT(*) FROM feedbacks f JOIN users u ON u.id = f.user_id" . $where, $params);
$pages = max(1, (int)ceil($total / $per));
$offset = ($page - 1) * $per;
$list = db_all(
    "SELECT f.*, u.username, u.avatar, u.is_admin,
        (SELECT COUNT(*) FROM feedback_replies r WHERE r.feedback_id = f.id) AS rc,
        (SELECT COUNT(*) FROM feedback_likes l WHERE l.feedback_id = f.id) AS lc
     FROM feedbacks f JOIN users u ON u.id = f.user_id $where
     ORDER BY f.created_at DESC LIMIT $per OFFSET $offset", $params);

/* 各反馈的已有回复（用于展开） */
$replyMap = [];
if ($list) {
    $ids = implode(',', array_map(function ($f) { return (int)$f['id']; }, $list));
    foreach (db_all("SELECT r.*, u.username, u.avatar, u.is_admin FROM feedback_replies r JOIN users u ON u.id = r.user_id WHERE r.feedback_id IN ($ids) ORDER BY u.is_admin DESC, r.created_at ASC") as $r) {
        $replyMap[(int)$r['feedback_id']][] = $r;
    }
}

admin_page_start(['active' => 'feedback', 'title' => '反馈管理']);
?>

<div class="panel">
    <div class="panel-head">
        <div class="panel-title"><i class="i i-edit"></i>全部反馈（<?= $total ?>）</div>
        <form method="get" style="display:flex;gap:10px">
            <input class="input" style="width:220px;padding:8px 12px" name="q" value="<?= e($q) ?>" placeholder="搜索标题 / 内容 / 用户">
            <button class="btn btn-sm btn-primary" type="submit"><i class="i i-search"></i>搜索</button>
        </form>
    </div>
    <div class="panel-body">
        <?php foreach ($list as $f): ?>
        <div class="fb-card" style="margin-bottom:16px">
            <div class="fb-head">
                <?php if ($f['avatar']): ?>
                <img class="fb-avatar" src="<?= e($f['avatar']) ?>" alt="">
                <?php else: ?>
                <span class="fb-avatar"><?= e(mb_strtoupper(mb_sub($f['username'], 0, 1))) ?></span>
                <?php endif; ?>
                <div class="fb-head-r">
                    <div class="fb-user"><span><?= display_username($f) ?></span></div>
                    <div class="fb-time"><?= e($f['created_at']) ?> · 赞 <?= (int)$f['lc'] ?> · 回复 <?= (int)$f['rc'] ?></div>
                </div>
                <form method="post" style="margin-left:auto" onsubmit="return confirm('确定删除该反馈及其全部回复？')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="fid" value="<?= (int)$f['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit"><i class="i i-trash"></i>删除</button>
                </form>
            </div>
            <div class="fb-title"><?= e($f['title']) ?></div>
            <div class="fb-content"><?= e($f['content']) ?></div>

            <?php $replies = isset($replyMap[(int)$f['id']]) ? $replyMap[(int)$f['id']] : []; ?>
            <?php if ($replies): ?>
            <div style="margin-top:14px">
                <div class="track-label" style="margin-bottom:8px"><i class="i i-edit"></i>回复（<?= count($replies) ?>）</div>
                <?php foreach ($replies as $r): ?>
                <div class="reply-block">
                    <div class="fb-reply-top">
                        <span class="fb-reply-name"><?= display_username($r) ?></span>
                        <?php if ((int)$r['is_admin'] === 1): ?><span class="admin-tag">官方回复</span><?php endif; ?>
                        <span class="fb-reply-time"><?= e(time_ago($r['created_at'])) ?></span>
                    </div>
                    <div class="fb-reply-txt"><?= e($r['content']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <form method="post" style="display:flex;gap:10px;margin-top:12px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reply">
                <input type="hidden" name="fid" value="<?= (int)$f['id'] ?>">
                <input class="input" name="content" maxlength="1000" placeholder="以管理员身份回复（前台优先展示）…" required>
                <button class="btn btn-sm btn-primary" type="submit"><i class="i i-send"></i>回复</button>
            </form>
        </div>
        <?php endforeach; if (!$list): ?>
        <div class="empty"><i class="i i-edit"></i><h3>暂无反馈</h3></div>
        <?php endif; ?>
    </div>
    <?php if ($pages > 1): ?>
    <div class="pager" style="margin:10px 18px 18px">
        <?php
        $from = max(1, $page - 2); $to = min($pages, $from + 4); $from = max(1, $to - 4);
        for ($p = $from; $p <= $to; $p++): ?>
        <a class="pg-btn <?= $p === $page ? 'on' : '' ?>" href="feedback.php?q=<?= urlencode($q) ?>&page=<?= $p ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
<?php admin_page_end(); ?>
