<?php
/**
 * Jay影视 - 管理后台 · 播放源管理
 * 新增 / 编辑 / 删除播放源，设置默认播放源
 */
require __DIR__ . '/_boot.php';

if (is_post()) {
    csrf_check();
    $action = post_val('action');

    if ($action === 'add' || $action === 'edit') {
        $name = post_val('name');
        $url = post_val('api_url');
        $id = (int)post_val('id', 0);
        if ($name === '' || mb_len($name) > 50) {
            $_SESSION['flash_err'] = '请输入播放源名称（50字以内）';
        } elseif (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $_SESSION['flash_err'] = '播放源接口地址必须是合法的 http/https URL';
        } elseif ($action === 'add') {
            db_query("INSERT INTO play_sources (name, api_url, is_default) VALUES (?, ?, 0)", [$name, $url]);
            $_SESSION['flash_ok'] = '播放源「' . $name . '」已添加';
        } else {
            db_query("UPDATE play_sources SET name = ?, api_url = ? WHERE id = ?", [$name, $url, $id]);
            $_SESSION['flash_ok'] = '播放源已更新';
        }
    } elseif ($action === 'delete') {
        $id = (int)post_val('id');
        $src = db_one("SELECT * FROM play_sources WHERE id = ?", [$id]);
        if ($src) {
            db_query("DELETE FROM play_sources WHERE id = ?", [$id]);
            $remain = db_val("SELECT COUNT(*) FROM play_sources");
            if ((int)$src['is_default'] === 1 && $remain > 0) {
                db_query("UPDATE play_sources SET is_default = 1 ORDER BY id ASC LIMIT 1");
            }
            $_SESSION['flash_ok'] = '播放源已删除' . ((int)$src['is_default'] === 1 && $remain > 0 ? '，默认源已自动切换' : '');
        }
    } elseif ($action === 'set_default') {
        $id = (int)post_val('id');
        if (db_one("SELECT id FROM play_sources WHERE id = ?", [$id])) {
            db_query("UPDATE play_sources SET is_default = 0");
            db_query("UPDATE play_sources SET is_default = 1 WHERE id = ?", [$id]);
            $_SESSION['flash_ok'] = '已设为默认播放源';
        }
    }
    redirect('sources.php');
}

$sources = db_all("SELECT * FROM play_sources ORDER BY is_default DESC, id ASC");
$editId = (int)get_val('edit', 0);
$editSrc = $editId ? db_one("SELECT * FROM play_sources WHERE id = ?", [$editId]) : null;

admin_page_start(['active' => 'sources', 'title' => '播放源管理']);
?>

<div class="panel">
    <div class="panel-head"><div class="panel-title"><i class="i i-film"></i><?= $editSrc ? '编辑播放源' : '新增播放源' ?></div></div>
    <div class="panel-body">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editSrc ? 'edit' : 'add' ?>">
            <input type="hidden" name="id" value="<?= $editSrc ? (int)$editSrc['id'] : 0 ?>">
            <div class="form-grid" style="grid-template-columns:1fr 2fr auto;align-items:end">
                <div class="form-item">
                    <label>播放源名称<b style="color:var(--err)">*</b></label>
                    <input class="input" name="name" value="<?= $editSrc ? e($editSrc['name']) : '' ?>" placeholder="例如：资源站A" required>
                </div>
                <div class="form-item">
                    <label>接口地址（苹果CMS JSON 格式）<b style="color:var(--err)">*</b></label>
                    <input class="input" name="api_url" value="<?= $editSrc ? e($editSrc['api_url']) : 'https://api.yyzy-tv.vip/inc/apijson.php' ?>" placeholder="https://.../apijson.php" required>
                </div>
                <div class="form-item">
                    <button class="btn btn-primary" type="submit"><i class="i <?= $editSrc ? 'i-check' : 'i-plus' ?>"></i><?= $editSrc ? '保存修改' : '添加播放源' ?></button>
                    <?php if ($editSrc): ?><a class="btn btn-ghost" href="sources.php">取消</a><?php endif; ?>
                </div>
            </div>
            <div class="hint">播放页将优先使用默认播放源解析影片，失败时自动尝试其他源。接口需支持 ?ac=videolist&wd=关键词 搜索。</div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><div class="panel-title"><i class="i i-list"></i>播放源列表（<?= count($sources) ?>）</div></div>
    <div class="table-wrap"><table class="tbl">
        <thead><tr><th>名称</th><th>接口地址</th><th>状态</th><th>添加时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($sources as $s): ?>
        <tr>
            <td style="font-weight:600"><?= e($s['name']) ?></td>
            <td style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--mut)"><?= e($s['api_url']) ?></td>
            <td><?= (int)$s['is_default'] === 1 ? '<span class="tag tag-ok"><i class="i i-check"></i>默认</span>' : '<span class="tag tag-mut">备用</span>' ?></td>
            <td style="color:var(--mut)"><?= e($s['created_at']) ?></td>
            <td>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <?php if ((int)$s['is_default'] !== 1): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="set_default">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <button class="btn btn-sm btn-ok" type="submit"><i class="i i-check"></i>设为默认</button>
                    </form>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-ghost" href="sources.php?edit=<?= (int)$s['id'] ?>"><i class="i i-edit"></i>编辑</a>
                    <form method="post" onsubmit="return confirm('确定删除播放源「<?= e($s['name']) ?>」？')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit"><i class="i i-trash"></i>删除</button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; if (!$sources): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--mut);padding:34px">暂无播放源，请先添加</td></tr>
        <?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php admin_page_end(); ?>
