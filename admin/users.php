<?php
/**
 * Jay影视 - 管理后台 · 用户管理
 * 封禁用户（自定义开始/解除时间）+ 自动发送封禁邮件通知
 */
require __DIR__ . '/_boot.php';

$msg = '';

/* ---------- 封禁 ---------- */
if (is_post() && post_val('action') === 'ban') {
    $uid = (int)post_val('uid');
    $reason = post_val('reason');
    $start = post_val('ban_start');
    $end = post_val('ban_end');
    $target = db_one("SELECT * FROM users WHERE id = ? AND is_admin = 0", [$uid]);
    if (!$target) {
        $_SESSION['flash_err'] = '用户不存在或不可封禁管理员';
    } elseif ($reason === '' || $start === '' || $end === '') {
        $_SESSION['flash_err'] = '请完整填写封禁原因、开始时间与解除时间';
    } elseif (strtotime($end) <= strtotime($start)) {
        $_SESSION['flash_err'] = '解除时间必须晚于封禁开始时间';
    } else {
        db_query("UPDATE users SET status = 0, ban_reason = ?, ban_start = ?, ban_end = ? WHERE id = ?", [$reason, $start, $end, $uid]);
        // 自动发送封禁通知邮件
        list($ok, $err) = send_mail($target['email'], $target['username'], 'Jay影视 账号封禁通知', mail_template_ban($target['username'], $reason, $start, $end));
        $_SESSION['flash_ok'] = '已封禁用户 ' . $target['username'] . ($ok ? '，封禁通知邮件已发送' : '（邮件发送失败：' . $err . '）');
    }
    redirect('users.php?q=' . urlencode(get_val('q', '')));
}

/* ---------- 解除封禁 ---------- */
if (is_post() && post_val('action') === 'unban') {
    $uid = (int)post_val('uid');
    db_query("UPDATE users SET status = 1, ban_start = NULL, ban_end = NULL, ban_reason = '' WHERE id = ?", [$uid]);
    $_SESSION['flash_ok'] = '已解除封禁';
    redirect('users.php?q=' . urlencode(get_val('q', '')));
}

/* ---------- 列表 ---------- */
$q = get_val('q', '');
$page = max(1, (int)get_val('page', 1));
$per = 12;
$where = '';
$params = [];
if ($q !== '') {
    $where = " WHERE username LIKE ? OR email LIKE ? ";
    $params = ['%' . $q . '%', '%' . $q . '%'];
}
$total = (int)db_val("SELECT COUNT(*) FROM users" . $where, $params);
$pages = max(1, (int)ceil($total / $per));
$offset = ($page - 1) * $per;
$users = db_all("SELECT * FROM users $where ORDER BY is_admin DESC, created_at DESC LIMIT $per OFFSET $offset", $params);

$now = date('Y-m-d\TH:i');
$defaultEnd = date('Y-m-d\TH:i', time() + 7 * 86400);

admin_page_start(['active' => 'users', 'title' => '用户管理']);
?>

<div class="panel">
    <div class="panel-head">
        <div class="panel-title"><i class="i i-user"></i>用户列表（<?= $total ?>）</div>
        <form method="get" style="display:flex;gap:10px">
            <input class="input" style="width:220px;padding:8px 12px" name="q" value="<?= e($q) ?>" placeholder="搜索用户名 / 邮箱">
            <button class="btn btn-sm btn-primary" type="submit"><i class="i i-search"></i>搜索</button>
        </form>
    </div>
    <div class="table-wrap"><table class="tbl">
        <thead><tr><th>用户</th><th>邮箱</th><th>状态</th><th>封禁信息</th><th>注册时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
            <td>
                <div class="user-cell">
                    <?php if ($u['avatar']): ?><img src="<?= e($u['avatar']) ?>" alt=""><?php else: ?><span class="u-ph"><?= e(mb_strtoupper(mb_sub($u['username'], 0, 1))) ?></span><?php endif; ?>
                    <span><?= display_username($u) ?></span>
                </div>
            </td>
            <td style="color:var(--mut)"><?= e($u['email']) ?></td>
            <td><?= (int)$u['is_admin'] === 1 ? '<span class="tag tag-blue">管理员</span>' : ((int)$u['status'] === 1 ? '<span class="tag tag-ok">正常</span>' : '<span class="tag tag-err">封禁中</span>') ?></td>
            <td style="max-width:220px">
                <?php if ((int)$u['status'] === 0): ?>
                <div class="ban-info" style="margin:0">
                    原因：<?= e($u['ban_reason'] ?: '未填写') ?><br>
                    <?= e($u['ban_start']) ?> ~ <?= e($u['ban_end'] ?: '无限期') ?>
                </div>
                <?php else: ?>
                <span style="color:var(--mut)">—</span>
                <?php endif; ?>
            </td>
            <td style="color:var(--mut)"><?= e($u['created_at']) ?></td>
            <td>
                <?php if ((int)$u['is_admin'] !== 1): ?>
                    <?php if ((int)$u['status'] === 1): ?>
                    <button class="btn btn-danger btn-sm" onclick="openBan(<?= (int)$u['id'] ?>, '<?= e($u['username']) ?>')"><i class="i i-ban"></i>封禁</button>
                    <?php else: ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('确定解除对该用户的封禁？')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="unban">
                        <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                        <button class="btn btn-ok btn-sm" type="submit"><i class="i i-check"></i>解封</button>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; if (!$users): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--mut);padding:34px">未找到用户</td></tr>
        <?php endif; ?>
        </tbody>
    </table></div>
    <?php if ($pages > 1): ?>
    <div class="pager" style="margin:14px 0 4px">
        <?php
        $from = max(1, $page - 2); $to = min($pages, $from + 4); $from = max(1, $to - 4);
        for ($p = $from; $p <= $to; $p++): ?>
        <a class="pg-btn <?= $p === $page ? 'on' : '' ?>" href="users.php?q=<?= urlencode($q) ?>&page=<?= $p ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<!-- 封禁弹窗 -->
<div class="modal-mask" id="banModal" style="display:none">
    <div class="modal modal-lg">
        <div class="modal-head" style="text-align:left">
            <div class="modal-title" style="display:flex;align-items:center;gap:10px"><i class="i i-ban" style="color:var(--err)"></i>封禁用户 <b id="banUserName" style="color:var(--err)"></b></div>
            <button class="icon-btn modal-close" type="button" onclick="closeBan()"><i class="i i-close"></i></button>
        </div>
        <form method="post" class="modal-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="ban">
            <input type="hidden" name="uid" id="banUid" value="">
            <div class="field">
                <label>封禁原因（将发送邮件通知用户）<b style="color:var(--err)">*</b></label>
                <input class="input" name="reason" required placeholder="例如：发布违规内容">
            </div>
            <div class="form-grid">
                <div class="form-item">
                    <label>封禁开始时间<b style="color:var(--err)">*</b></label>
                    <input class="input" type="datetime-local" name="ban_start" value="<?= $now ?>" required>
                </div>
                <div class="form-item">
                    <label>封禁解除时间<b style="color:var(--err)">*</b></label>
                    <input class="input" type="datetime-local" name="ban_end" value="<?= $defaultEnd ?>" required>
                </div>
            </div>
            <div class="hint" style="margin-bottom:6px"><i class="i i-mail"></i> 封禁后将自动调用 163 SMTP 向用户邮箱发送通知（含原因/开始/解除时间）</div>
            <div style="display:flex;gap:12px;margin-top:16px">
                <button class="btn btn-ghost" type="button" onclick="closeBan()">取消</button>
                <button class="btn btn-danger" type="submit"><i class="i i-ban"></i>确认封禁</button>
            </div>
        </form>
    </div>
</div>

<script>
function openBan(uid, name) {
    document.getElementById('banUid').value = uid;
    document.getElementById('banUserName').textContent = name;
    document.getElementById('banModal').style.display = 'flex';
}
function closeBan() { document.getElementById('banModal').style.display = 'none'; }
document.getElementById('banModal').addEventListener('click', function (e) {
    if (e.target === this) closeBan();
});
</script>
<?php admin_page_end(); ?>
