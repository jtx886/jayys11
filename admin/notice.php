<?php
/**
 * Jay影视 - 管理后台 · 网站公告
 * 公告仅首页弹窗展示；用户勾选「不再提示」存 Cookie；新公告发布后 Cookie 失效重新弹出
 */
require __DIR__ . '/_boot.php';

if (is_post()) {
    csrf_check();
    $action = post_val('action');
    if ($action === 'add') {
        $content = post_val('content');
        if ($content === '' || mb_len($content) > 3000) {
            $_SESSION['flash_err'] = '公告内容需在 1-3000 字之间';
        } else {
            db_query("INSERT INTO notices (content) VALUES (?)", [$content]);
            $_SESSION['flash_ok'] = '公告已发布，首页将重新弹窗展示（旧「不再提示」Cookie 自动失效）';
        }
    } elseif ($action === 'delete') {
        db_query("DELETE FROM notices WHERE id = ?", [(int)post_val('id')]);
        $_SESSION['flash_ok'] = '公告已删除';
    }
    redirect('notice.php');
}

$notices = db_all("SELECT * FROM notices ORDER BY id DESC");
$cur = $notices ? $notices[0] : null;

admin_page_start(['active' => 'notice', 'title' => '网站公告']);
?>

<?php if ($cur): ?>
<div class="track-note" style="margin-bottom:20px">
    <i class="i i-bell"></i>
    <div>当前生效公告（发布于 <?= e($cur['created_at']) ?>）将在首页弹窗展示。发布新公告后，用户已勾选的「不再提示」将自动失效并重新弹窗。</div>
</div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head"><div class="panel-title"><i class="i i-plus"></i>发布新公告</div></div>
    <div class="panel-body">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="field">
                <label>公告内容<b style="color:var(--err)">*</b>（仅首页弹窗显示，其他页面不展示）</label>
                <textarea class="input" name="content" maxlength="3000" style="min-height:150px" placeholder="例如：&#10;网站已上线全新播放引擎，观影体验更流畅！&#10;如遇问题欢迎前往反馈中心留言～" required></textarea>
            </div>
            <button class="btn btn-primary" type="submit"><i class="i i-bell"></i>发布公告</button>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><div class="panel-title"><i class="i i-list"></i>公告历史（<?= count($notices) ?>）</div></div>
    <div class="table-wrap"><table class="tbl">
        <thead><tr><th style="width:90px">#</th><th>内容</th><th style="width:170px">发布时间</th><th style="width:90px">状态</th><th style="width:90px">操作</th></tr></thead>
        <tbody>
        <?php foreach ($notices as $i => $n): ?>
        <tr>
            <td><?= (int)$n['id'] ?></td>
            <td style="max-width:480px;white-space:pre-wrap;color:var(--tx2)"><?= e(mb_sub($n['content'], 0, 120)) . (mb_len($n['content']) > 120 ? '…' : '') ?></td>
            <td style="color:var(--mut)"><?= e($n['created_at']) ?></td>
            <td><?= $i === 0 ? '<span class="tag tag-ok">生效中</span>' : '<span class="tag tag-mut">历史</span>' ?></td>
            <td>
                <form method="post" onsubmit="return confirm('确定删除该公告？')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit"><i class="i i-trash"></i>删除</button>
                </form>
            </td>
        </tr>
        <?php endforeach; if (!$notices): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--mut);padding:34px">暂无公告</td></tr>
        <?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php admin_page_end(); ?>
