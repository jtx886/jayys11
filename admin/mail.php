<?php
/**
 * Jay影视 - 管理后台 · 邮件推送
 * 自定义邮件内容，通过 163 SMTP 向用户发送通知
 */
require __DIR__ . '/_boot.php';

$users = db_all("SELECT id, username, email, status FROM users WHERE is_admin = 0 ORDER BY created_at DESC");
$result = null;

if (is_post() && post_val('action') === 'send') {
    csrf_check();
    $subject = post_val('subject');
    $content = post_val('content');
    $target = $_POST['targets'] ?? [];

    if ($subject === '' || mb_len($subject) > 100) {
        $_SESSION['flash_err'] = '请填写邮件主题（100字以内）';
        redirect('mail.php');
    }
    if ($content === '' || mb_len($content) > 5000) {
        $_SESSION['flash_err'] = '请填写邮件内容（5000字以内）';
        redirect('mail.php');
    }

    // 收件人解析
    $emails = [];
    if (in_array('all', (array)$target, true)) {
        foreach ($users as $u) if ((int)$u['status'] === 1) $emails[] = $u;
    } else {
        $ids = array_map('intval', (array)$target);
        if ($ids) {
            $in = implode(',', $ids);
            foreach (db_all("SELECT id, username, email FROM users WHERE id IN ($in) AND is_admin = 0") as $u) $emails[] = $u;
        }
    }

    if (!$emails) {
        $_SESSION['flash_err'] = '请选择收件用户';
        redirect('mail.php');
    }

    $okCount = 0; $failCount = 0; $failMsg = '';
    foreach ($emails as $u) {
        // 内容简单支持换行
        $htmlContent = nl2br(e($content));
        list($ok, $err) = send_mail($u['email'], $u['username'], $subject, mail_template_custom($u['username'], $htmlContent));
        if ($ok) $okCount++;
        else { $failCount++; if ($failMsg === '') $failMsg = $err; }
    }
    $_SESSION['flash_ok'] = '邮件推送完成：成功 ' . $okCount . ' 封' . ($failCount ? '，失败 ' . $failCount . ' 封（' . $failMsg . '）' : '');
    redirect('mail.php');
}

admin_page_start(['active' => 'mail', 'title' => '邮件推送']);
?>

<div class="panel">
    <div class="panel-head"><div class="panel-title"><i class="i i-mail"></i>发送通知邮件</div>
        <span class="tag tag-blue"><i class="i i-mail"></i>163 SMTP</span>
    </div>
    <div class="panel-body">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send">

            <div class="field">
                <label>邮件主题<b style="color:var(--err)">*</b></label>
                <input class="input" name="subject" maxlength="100" placeholder="例如：网站更新公告" required>
            </div>

            <div class="field">
                <label>邮件内容<b style="color:var(--err)">*</b>（支持换行，将以精美 HTML 模板发送）</label>
                <textarea class="input" name="content" maxlength="5000" style="min-height:170px" placeholder="亲爱的用户：&#10;……" required></textarea>
            </div>

            <div class="field">
                <label>收件人<b style="color:var(--err)">*</b></label>
                <div style="display:flex;gap:10px;margin-bottom:12px">
                    <label class="modal-check" style="margin:0"><input type="radio" name="send_to" value="all" checked id="toAll"><span class="ck"></span>全部用户（<?= count(array_filter($users, function ($u) { return (int)$u['status'] === 1; })) ?> 人）</label>
                    <label class="modal-check" style="margin:0"><input type="radio" name="send_to" value="pick" id="toPick"><span class="ck"></span>指定用户</label>
                </div>
                <div id="pickBox" style="display:none;background:var(--bg);border:1px solid var(--bd);border-radius:12px;padding:14px;max-height:260px;overflow-y:auto">
                    <?php foreach ($users as $u): ?>
                    <label class="modal-check" style="justify-content:flex-start;margin:0 0 10px">
                        <input type="checkbox" name="targets[]" value="<?= (int)$u['id'] ?>" <?= (int)$u['status'] !== 1 ? 'disabled' : '' ?>><span class="ck"></span>
                        <?= e($u['username']) ?> <span style="color:var(--mut);font-size:12px"><?= e($u['email']) ?><?= (int)$u['status'] !== 1 ? '（封禁中）' : '' ?></span>
                    </label>
                    <?php endforeach; if (!$users): ?>
                    <div style="color:var(--mut);text-align:center;padding:20px">暂无普通用户</div>
                    <?php endif; ?>
                </div>
                <div class="hint"><i class="i i-info"></i> 封禁中的用户不会被推送；邮件通过 163 SMTP（jtxnb886@163.com）发送</div>
            </div>

            <button class="btn btn-primary" type="submit"><i class="i i-send"></i>立即发送</button>
        </form>
    </div>
</div>

<script>
(function () {
    var toAll = document.getElementById('toAll'), toPick = document.getElementById('toPick'), box = document.getElementById('pickBox');
    function refresh() { box.style.display = toPick.checked ? 'block' : 'none'; }
    toAll.addEventListener('change', refresh);
    toPick.addEventListener('change', refresh);
    var form = box.closest('form');
    form.addEventListener('submit', function (e) {
        if (toPick.checked) {
            var checked = box.querySelectorAll('input[type=checkbox]:checked');
            if (!checked.length) { e.preventDefault(); alert('请至少选择一位收件用户'); }
        } else {
            var all = document.createElement('input');
            all.type = 'hidden'; all.name = 'targets[]'; all.value = 'all';
            form.appendChild(all);
        }
    });
})();
</script>
<?php admin_page_end(); ?>
