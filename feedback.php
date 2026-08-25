<?php
/**
 * Jay影视 - 反馈中心
 * 全部用户可浏览公开反馈、点赞、回复；管理员回复优先展示
 */
require __DIR__ . '/includes/init.php';
require __DIR__ . '/includes/layout.php';

$user = current_user();
$page = max(1, (int)get_val('page', 1));
$perPage = 8;
$offset = ($page - 1) * $perPage;

$total = (int)db_val("SELECT COUNT(*) FROM feedbacks");
$pages = max(1, (int)ceil($total / $perPage));

/* 反馈列表（含作者、点赞数、回复数） */
$list = db_all(
    "SELECT f.id, f.title, f.content, f.created_at,
            u.username, u.avatar, u.is_admin,
            (SELECT COUNT(*) FROM feedback_likes l WHERE l.feedback_id = f.id) AS like_count,
            (SELECT COUNT(*) FROM feedback_replies r WHERE r.feedback_id = f.id) AS reply_count,
            (SELECT COUNT(*) FROM feedback_replies r WHERE r.feedback_id = f.id AND r.user_id = ?) AS my_reply_count
     FROM feedbacks f JOIN users u ON u.id = f.user_id
     ORDER BY f.created_at DESC
     LIMIT $perPage OFFSET $offset", [$user ? $user['id'] : 0]);

/* 当前用户点赞状态 */
$likedIds = [];
if ($user && $list) {
    $ids = implode(',', array_map(function ($f) { return (int)$f['id']; }, $list));
    if ($ids !== '') {
        foreach (db_all("SELECT feedback_id FROM feedback_likes WHERE user_id = ? AND feedback_id IN ($ids)", [$user['id']]) as $r) {
            $likedIds[(int)$r['feedback_id']] = true;
        }
    }
}

/* 侧栏统计 */
$statUsers = (int)db_val("SELECT COUNT(*) FROM users");
$statReplies = (int)db_val("SELECT COUNT(*) FROM feedback_replies");

/* 提交反馈 */
if (is_post() && post_val('action') === 'post_feedback' && $user) {
    $title = post_val('title');
    $content = post_val('content');
    if (mb_len($title) < 2 || mb_len($title) > 60) {
        $_SESSION['flash_err'] = '反馈标题需在 2-60 字之间';
    } elseif (mb_len($content) < 5 || mb_len($content) > 2000) {
        $_SESSION['flash_err'] = '反馈内容需在 5-2000 字之间';
    } else {
        db_query("INSERT INTO feedbacks (user_id, title, content) VALUES (?, ?, ?)", [$user['id'], $title, $content]);
        $_SESSION['flash_ok'] = '反馈提交成功，感谢您的建议！';
    }
    redirect('feedback.php');
}

function avatar_html($u, $size) {
    if (!empty($u['avatar'])) {
        return '<img class="fb-avatar" style="width:' . $size . 'px;height:' . $size . 'px" src="' . e($u['avatar']) . '" alt="">';
    }
    return '<span class="fb-avatar" style="width:' . $size . 'px;height:' . $size . 'px">' . e(mb_strtoupper(mb_sub($u['username'], 0, 1))) . '</span>';
}

page_start(['active' => 'feedback', 'title' => '反馈中心']);
?>
<div class="fb-layout">
    <!-- 反馈列表 -->
    <div>
        <div class="section-head">
            <div class="section-title"><span class="bar"></span>公开反馈<?= $total ? '（' . $total . '）' : '' ?></div>
        </div>

        <?php if (empty($list)): ?>
        <div class="fb-empty card">
            <div class="empty">
                <i class="i i-edit"></i>
                <h3>还没有反馈</h3>
                <p>来发布第一条反馈吧，您的建议对我们很重要</p>
            </div>
        </div>
        <?php endif; ?>

        <?php foreach ($list as $f): ?>
        <div class="fb-card" data-id="<?= (int)$f['id'] ?>">
            <div class="fb-head">
                <?php echo avatar_html($f, 46); ?>
                <div class="fb-head-r">
                    <div class="fb-user">
                        <span><?= display_username($f) ?></span>
                        <?php if ($user && (int)$f['username'] === 0 && $f['username'] === $user['username']): ?>
                        <span class="badge-me">我</span>
                        <?php endif; ?>
                    </div>
                    <div class="fb-time"><?= time_ago($f['created_at']) ?> · <?= e(date('Y-m-d', strtotime($f['created_at']))) ?></div>
                </div>
            </div>
            <div class="fb-title"><?= e($f['title']) ?></div>
            <div class="fb-content"><?= e($f['content']) ?></div>
            <div class="fb-foot">
                <button class="fb-act fb-like <?= isset($likedIds[(int)$f['id']]) ? 'liked' : '' ?>" data-id="<?= (int)$f['id'] ?>">
                    <i class="i i-heart"></i><span>赞</span><span><?= (int)$f['like_count'] ?></span>
                </button>
                <span class="fb-act" style="cursor:default"><i class="i i-edit"></i><span>回复</span><span><?= (int)$f['reply_count'] ?></span></span>
            </div>
            <!-- 回复区（AJAX 加载：管理员优先，>3条折叠） -->
            <div class="fb-replies-box"></div>
            <?php if ($user): ?>
            <form class="fb-reply-form" data-id="<?= (int)$f['id'] ?>">
                <?php echo avatar_html(['username' => $user['username'], 'avatar' => $user['avatar']], 36); ?>
                <input class="input" type="text" maxlength="1000" placeholder="友善回复，理性发言…">
                <button class="btn btn-primary btn-sm" type="submit"><i class="i i-send"></i>回复</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if ($pages > 1): ?>
        <div class="pager">
            <a class="pg-btn" <?= $page > 1 ? 'href="feedback.php?page=' . ($page - 1) . '"' : 'disabled' ?>><i class="i i-arrow-l"></i></a>
            <?php
            $from = max(1, $page - 2); $to = min($pages, $from + 4); $from = max(1, $to - 4);
            for ($p = $from; $p <= $to; $p++): ?>
            <a class="pg-btn <?= $p === $page ? 'on' : '' ?>" href="feedback.php?page=<?= $p ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a class="pg-btn" <?= $page < $pages ? 'href="feedback.php?page=' . ($page + 1) . '"' : 'disabled' ?>><i class="i i-arrow-r"></i></a>
        </div>
        <?php endif; ?>
    </div>

    <!-- 侧栏 -->
    <div>
        <?php if ($user): ?>
        <div class="fb-side-card">
            <div class="fb-side-title"><i class="i i-edit"></i>发布反馈</div>
            <form method="post">
                <input type="hidden" name="action" value="post_feedback">
                <?= csrf_field() ?>
                <div class="field">
                    <label>反馈标题</label>
                    <input class="input" type="text" name="title" maxlength="60" placeholder="一句话概括您的建议" required>
                </div>
                <div class="field">
                    <label>反馈内容</label>
                    <textarea class="input" name="content" maxlength="2000" placeholder="详细描述您遇到的问题或建议…" style="min-height:120px;resize:vertical" required></textarea>
                </div>
                <button class="btn btn-primary btn-block" type="submit"><i class="i i-send"></i>提交反馈</button>
            </form>
        </div>
        <?php else: ?>
        <div class="fb-side-card" style="text-align:center">
            <div class="fb-side-title" style="justify-content:center"><i class="i i-user"></i>登录后参与互动</div>
            <p style="color:var(--mut);font-size:13px;line-height:1.9;margin-bottom:18px">登录后即可发布反馈、点赞与回复</p>
            <a class="btn btn-primary btn-block" href="login.php">立即登录</a>
            <a class="btn btn-ghost btn-block" style="margin-top:10px" href="register.php">注册账号</a>
        </div>
        <?php endif; ?>

        <div class="fb-side-card" style="margin-top:18px">
            <div class="fb-side-title"><i class="i i-chart"></i>反馈统计</div>
            <div class="fb-stat-row"><span>反馈总数</span><b><?= $total ?></b></div>
            <div class="fb-stat-row"><span>回复总数</span><b><?= $statReplies ?></b></div>
            <div class="fb-stat-row"><span>注册用户</span><b><?= $statUsers ?></b></div>
        </div>
    </div>
</div>
<?php page_end(); ?>
