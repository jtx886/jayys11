<?php
/**
 * Jay影视 - 个人中心
 * 我的收藏 / 观看历史（秒记录）/ 头像上传
 */
require __DIR__ . '/includes/init.php';
require __DIR__ . '/includes/layout.php';

$user = current_user();
if (!$user) redirect('login.php?redirect=' . urlencode('profile.php'));

$error = '';

/* ---------- 头像上传 ---------- */
if (is_post() && post_val('action') === 'upload_avatar') {
    if (!empty($_FILES['avatar']['tmp_name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $f = $_FILES['avatar'];
        $size = (int)$f['size'];
        if ($size > 2 * 1024 * 1024) {
            $error = '头像图片不能超过 2MB';
        } else {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $mimeOk = false;
            if (class_exists('finfo')) {
                $fi = new finfo(FILEINFO_MIME_TYPE);
                $mime = $fi->file($f['tmp_name']);
                $mimeOk = in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
            } else {
                $mimeOk = in_array($ext, $allowed, true);
            }
            if (!$mimeOk || (!in_array($ext, $allowed, true) && $ext !== '')) {
                $error = '仅支持 JPG/PNG/GIF/WEBP 格式图片';
            } else {
                if ($ext === '') $ext = 'jpg';
                $name = 'avatar_' . $user['id'] . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $dir = __DIR__ . '/uploads';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                if (move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
                    // 删除旧头像
                    if ($user['avatar'] && strpos($user['avatar'], 'uploads/') === 0) {
                        $old = __DIR__ . '/' . $user['avatar'];
                        if (is_file($old)) @unlink($old);
                    }
                    db_query("UPDATE users SET avatar = ? WHERE id = ?", ['uploads/' . $name, $user['id']]);
                    $_SESSION['flash_ok'] = '头像更新成功';
                    redirect('profile.php');
                } else {
                    $error = '头像保存失败，请检查 uploads 目录权限';
                }
            }
        }
    } else {
        $error = '请选择要上传的图片';
    }
    if ($error !== '') $_SESSION['flash_err'] = $error;
    redirect('profile.php');
}

/* ---------- 收藏与历史 ---------- */
$favorites = db_all("SELECT * FROM favorites WHERE user_id = ? ORDER BY created_at DESC LIMIT 100", [$user['id']]);
$history   = db_all("SELECT * FROM watch_history WHERE user_id = ? ORDER BY updated_at DESC LIMIT 100", [$user['id']]);

page_start(['active' => '', 'title' => '个人中心']);
?>

<!-- 头部 -->
<div class="prof-head">
    <div class="prof-avatar-box">
        <?php if ($user['avatar']): ?>
        <img class="prof-avatar" src="<?= e($user['avatar']) ?>" alt="">
        <?php else: ?>
        <span class="prof-avatar"><?= e(mb_strtoupper(mb_sub($user['username'], 0, 1))) ?></span>
        <?php endif; ?>
        <div class="avatar-up-mask" id="avatarUpBtn" title="上传头像"><i class="i i-image"></i></div>
        <form method="post" enctype="multipart/form-data" id="avatarForm" style="display:none">
            <input type="hidden" name="action" value="upload_avatar">
            <?= csrf_field() ?>
            <input type="file" name="avatar" id="avatarInput" accept="image/jpeg,image/png,image/gif,image/webp">
        </form>
    </div>
    <div>
        <div class="prof-name"><?= display_username($user) ?></div>
        <div class="prof-mail"><?= e($user['email']) ?> · 注册于 <?= e(date('Y-m-d', strtotime($user['created_at']))) ?></div>
        <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">
            <span class="tag tag-blue"><i class="i i-heart"></i>收藏 <?= count($favorites) ?></span>
            <span class="tag tag-ok"><i class="i i-history"></i>观看 <?= count($history) ?></span>
        </div>
    </div>
    <a class="btn btn-ghost" style="margin-left:auto" href="logout.php"><i class="i i-out"></i>退出登录</a>
</div>

<!-- 选项卡 -->
<div class="prof-tabs">
    <button class="prof-tab on" data-pane="paneFav"><i class="i i-heart"></i> 我的收藏</button>
    <button class="prof-tab" data-pane="paneHist">观看历史</button>
    <button class="prof-tab" data-pane="paneAcc"><i class="i i-user"></i> 账号信息</button>
</div>

<!-- 收藏 -->
<div class="prof-pane" id="paneFav">
    <?php if (empty($favorites)): ?>
    <div class="empty"><i class="i i-heart"></i><h3>暂无收藏</h3><p>在影片详情页点击「收藏」即可收藏喜欢的影片</p><div style="margin-top:18px"><a class="btn btn-primary" href="index.php">去发现影片</a></div></div>
    <?php else: ?>
    <div class="fav-grid">
        <?php foreach ($favorites as $i => $f): ?>
        <div class="fav-item">
            <a class="m-card fade-in <?= 'd' . min(4, (int)ceil(($i + 1) / 8)) ?>" href="detail.php?type=<?= e($f['media_type']) ?>&id=<?= (int)$f['tmdb_id'] ?>">
                <div class="m-poster">
                    <?php if ($f['poster']): ?><img loading="lazy" src="<?= e($f['poster']) ?>" alt=""><?php endif; ?>
                    <span class="m-hover-play"><i class="i i-play"></i></span>
                    <span class="m-tag"><?= $f['media_type'] === 'movie' ? '电影' : '剧集' ?></span>
                </div>
                <div class="m-meta">
                    <div class="m-name" title="<?= e($f['title']) ?>"><?= e($f['title']) ?></div>
                    <div class="m-sub"><span><?= e(date('Y/m/d', strtotime($f['created_at']))) ?></span></div>
                </div>
            </a>
            <button class="btn btn-ghost btn-sm del-fav" data-id="<?= (int)$f['id'] ?>" style="width:100%;margin-top:6px;border-radius:9px"><i class="i i-trash"></i>取消收藏</button>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- 观看历史 -->
<div class="prof-pane" id="paneHist" style="display:none">
    <?php if (empty($history)): ?>
    <div class="empty"><i class="i i-history"></i><h3>暂无观看记录</h3><p>观看影片后，进度会自动记录在这里</p></div>
    <?php else: ?>
    <?php foreach ($history as $h): ?>
    <div class="hist-row">
        <?php if ($h['poster']): ?>
        <img class="hist-poster" src="<?= e($h['poster']) ?>" alt="">
        <?php else: ?>
        <span class="hist-poster" style="display:flex;align-items:center;justify-content:center;color:#39435c"><i class="i i-image"></i></span>
        <?php endif; ?>
        <div class="hist-main">
            <div class="hist-title"><?= e($h['title']) ?><?= $h['media_type'] === 'tv' ? ' · S' . (int)$h['season'] . 'E' . (int)$h['episode'] : '' ?></div>
            <div class="hist-sub">
                <?php if ($h['media_type'] === 'tv'): ?><span><?= e($h['episode_name'] ?: ('第 ' . (int)$h['episode'] . ' 集')) ?></span><?php endif; ?>
                <span>已观看 <?= format_seconds((int)$h['position']) ?></span>
                <span><?= time_ago($h['updated_at']) ?></span>
            </div>
            <div class="hist-progress"><i style="width:<?= min(100, max(4, (int)$h['position'] / 45)) ?>%"></i></div>
        </div>
        <a class="btn btn-primary btn-sm" href="play.php?m=<?= e($h['media_type']) ?>&t=<?= (int)$h['tmdb_id'] ?>&s=<?= (int)$h['season'] ?>&e=<?= (int)$h['episode'] ?>"><i class="i i-play"></i>继续观看</a>
        <button class="icon-btn del-hist" data-id="<?= (int)$h['id'] ?>" title="删除记录"><i class="i i-trash"></i></button>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- 账号 -->
<div class="prof-pane" id="paneAcc" style="display:none">
    <div class="panel">
        <div class="panel-head"><div class="panel-title"><i class="i i-user"></i>账号信息</div></div>
        <div class="panel-body">
            <div class="form-grid">
                <div class="form-item"><label>用户名</label><input class="input" value="<?= e($user['username']) ?>" disabled></div>
                <div class="form-item"><label>邮箱</label><input class="input" value="<?= e($user['email']) ?>" disabled></div>
                <div class="form-item"><label>注册时间</label><input class="input" value="<?= e($user['created_at']) ?>" disabled></div>
                <div class="form-item"><label>账号状态</label>
                    <input class="input" value="<?= (int)$user['status'] === 1 ? '正常' : '封禁中' ?>" disabled>
                </div>
            </div>
            <div class="hint">如需修改密码或账号信息，请联系管理员</div>
        </div>
    </div>
</div>

<script>
(function () {
    var btn = document.getElementById('avatarUpBtn');
    if (btn) btn.addEventListener('click', function () { document.getElementById('avatarInput').click(); });
})();
</script>
<?php page_end(); ?>
