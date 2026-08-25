<?php
/**
 * Jay影视 - 管理后台 · 仪表盘
 * 最新注册用户 / 最新反馈 / 观看历史 / 收藏数据 + 独立筛选模块
 */
require __DIR__ . '/_boot.php';

/* 统计 */
$statUsers   = (int)db_val("SELECT COUNT(*) FROM users");
$statToday   = (int)db_val("SELECT COUNT(*) FROM users WHERE created_at >= ?", [date('Y-m-d 00:00:00')]);
$statFb      = (int)db_val("SELECT COUNT(*) FROM feedbacks");
$statHist    = (int)db_val("SELECT COUNT(*) FROM watch_history");
$statFav     = (int)db_val("SELECT COUNT(*) FROM favorites");
$statBanned  = (int)db_val("SELECT COUNT(*) FROM users WHERE status = 0");

/* 最新注册用户 */
$latestUsers = db_all("SELECT * FROM users ORDER BY created_at DESC LIMIT 6");

/* 最新反馈 */
$latestFb = db_all(
    "SELECT f.*, u.username, u.avatar, u.is_admin,
     (SELECT COUNT(*) FROM feedback_replies r WHERE r.feedback_id = f.id) AS rc
     FROM feedbacks f JOIN users u ON u.id = f.user_id ORDER BY f.created_at DESC LIMIT 5");

/* ---------- 模块：观看历史（支持筛选用户） ---------- */
$hFilter = get_val('h_user', '');
$hPage = max(1, (int)get_val('h_page', 1));
$hPer = 10;
$hWhere = '';
$hParams = [];
if ($hFilter !== '') {
    $hWhere = " WHERE u.username LIKE ? ";
    $hParams = ['%' . $hFilter . '%'];
}
$hTotal = (int)db_val("SELECT COUNT(*) FROM watch_history h JOIN users u ON u.id = h.user_id" . $hWhere, $hParams);
$hPages = max(1, (int)ceil($hTotal / $hPer));
$hOffset = ($hPage - 1) * $hPer;
$histories = db_all(
    "SELECT h.*, u.username, u.avatar, u.is_admin FROM watch_history h JOIN users u ON u.id = h.user_id $hWhere
     ORDER BY h.updated_at DESC LIMIT $hPer OFFSET $hOffset", $hParams);

/* ---------- 模块：用户收藏（支持筛选用户） ---------- */
$fFilter = get_val('f_user', '');
$fPage = max(1, (int)get_val('f_page', 1));
$fPer = 10;
$fWhere = '';
$fParams = [];
if ($fFilter !== '') {
    $fWhere = " WHERE u.username LIKE ? ";
    $fParams = ['%' . $fFilter . '%'];
}
$fTotal = (int)db_val("SELECT COUNT(*) FROM favorites fv JOIN users u ON u.id = fv.user_id" . $fWhere, $fParams);
$fPages = max(1, (int)ceil($fTotal / $fPer));
$fOffset = ($fPage - 1) * $fPer;
$favorites = db_all(
    "SELECT fv.*, u.username, u.avatar, u.is_admin FROM favorites fv JOIN users u ON u.id = fv.user_id $fWhere
     ORDER BY fv.created_at DESC LIMIT $fPer OFFSET $fOffset", $fParams);

function pager_qs($base, $params, $page, $pages, $key) {
    if ($pages <= 1) return '';
    $html = '<div class="pager" style="margin:14px 0 4px">';
    $mk = function ($p, $label, $dis = false) use ($base, $params, $key) {
        $qs = http_build_query(array_merge($params, [$key => $p]));
        return '<a class="pg-btn' . ($dis ? '' : '') . '" ' . ($dis ? 'disabled' : 'href="' . $base . '?' . $qs . '"') . '>' . $label . '</a>';
    };
    $html .= $mk($page - 1, '<i class="i i-arrow-l"></i>', $page <= 1);
    $from = max(1, $page - 2); $to = min($pages, $from + 4); $from = max(1, $to - 4);
    for ($p = $from; $p <= $to; $p++) {
        $qs = http_build_query(array_merge($params, [$key => $p]));
        $html .= '<a class="pg-btn ' . ($p === $page ? 'on' : '') . '" href="' . $base . '?' . $qs . '">' . $p . '</a>';
    }
    $html .= $mk($page + 1, '<i class="i i-arrow-r"></i>', $page >= $pages);
    return $html . '</div>';
}

admin_page_start(['active' => 'dashboard', 'title' => '仪表盘']);
?>

<!-- 统计卡片 -->
<div class="stat-grid">
    <div class="stat-card"><div class="stat-ico"><i class="i i-user"></i></div><div class="stat-num"><?= $statUsers ?></div><div class="stat-label">注册用户（今日 +<?= $statToday ?>）</div></div>
    <div class="stat-card"><div class="stat-ico blue"><i class="i i-history"></i></div><div class="stat-num"><?= $statHist ?></div><div class="stat-label">观看记录总数</div></div>
    <div class="stat-card"><div class="stat-ico gold"><i class="i i-heart"></i></div><div class="stat-num"><?= $statFav ?></div><div class="stat-label">用户收藏总数</div></div>
    <div class="stat-card"><div class="stat-ico green"><i class="i i-edit"></i></div><div class="stat-num"><?= $statFb ?></div><div class="stat-label">反馈总数（封禁 <?= $statBanned ?> 人）</div></div>
</div>

<!-- 最新注册 + 最新反馈 -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:22px" class="dash-two">
    <div class="panel">
        <div class="panel-head"><div class="panel-title"><i class="i i-user"></i>最新注册用户</div><a class="section-more" href="users.php">管理 <i class="i i-arrow-r"></i></a></div>
        <div class="table-wrap"><table class="tbl">
            <thead><tr><th>用户</th><th>邮箱</th><th>状态</th><th>注册时间</th></tr></thead>
            <tbody>
            <?php foreach ($latestUsers as $u): ?>
            <tr>
                <td>
                    <div class="user-cell">
                        <?php if ($u['avatar']): ?><img src="<?= e($u['avatar']) ?>" alt=""><?php else: ?><span class="u-ph"><?= e(mb_strtoupper(mb_sub($u['username'], 0, 1))) ?></span><?php endif; ?>
                        <span><?= display_username($u) ?></span>
                    </div>
                </td>
                <td style="color:var(--mut)"><?= e($u['email']) ?></td>
                <td><?= (int)$u['status'] === 1 ? '<span class="tag tag-ok">正常</span>' : '<span class="tag tag-err">封禁</span>' ?></td>
                <td style="color:var(--mut)"><?= e($u['created_at']) ?></td>
            </tr>
            <?php endforeach; if (!$latestUsers): ?>
            <tr><td colspan="4" style="text-align:center;color:var(--mut);padding:30px">暂无注册用户</td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>
    </div>
    <div class="panel">
        <div class="panel-head"><div class="panel-title"><i class="i i-edit"></i>最新反馈</div><a class="section-more" href="feedback.php">管理 <i class="i i-arrow-r"></i></a></div>
        <div class="table-wrap"><table class="tbl">
            <thead><tr><th>用户</th><th>标题</th><th>回复</th><th>时间</th></tr></thead>
            <tbody>
            <?php foreach ($latestFb as $f): ?>
            <tr>
                <td><div class="user-cell"><span><?= display_username($f) ?></span></div></td>
                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($f['title']) ?></td>
                <td><span class="tag tag-blue"><?= (int)$f['rc'] ?></span></td>
                <td style="color:var(--mut)"><?= e(time_ago($f['created_at'])) ?></td>
            </tr>
            <?php endforeach; if (!$latestFb): ?>
            <tr><td colspan="4" style="text-align:center;color:var(--mut);padding:30px">暂无反馈</td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>

<!-- 独立模块：观看历史 -->
<div class="panel">
    <div class="panel-head">
        <div class="panel-title"><i class="i i-history"></i>观看历史（<?= $hTotal ?>）</div>
        <form method="get" style="display:flex;gap:10px">
            <input class="input" style="width:200px;padding:8px 12px" name="h_user" value="<?= e($hFilter) ?>" placeholder="按用户名筛选">
            <button class="btn btn-sm btn-primary" type="submit"><i class="i i-search"></i>筛选</button>
        </form>
    </div>
    <div class="table-wrap"><table class="tbl">
        <thead><tr><th>用户</th><th>影片</th><th>季/集</th><th>已观看时长</th><th>最近观看</th></tr></thead>
        <tbody>
        <?php foreach ($histories as $h): ?>
        <tr>
            <td><div class="user-cell">
                <?php if ($h['avatar']): ?><img src="<?= e($h['avatar']) ?>" alt=""><?php else: ?><span class="u-ph"><?= e(mb_strtoupper(mb_sub($h['username'], 0, 1))) ?></span><?php endif; ?>
                <span><?= display_username($h) ?></span>
            </div></td>
            <td style="display:flex;align-items:center;gap:10px">
                <?php if ($h['poster']): ?><img src="<?= e($h['poster']) ?>" style="width:32px;height:44px;border-radius:6px;object-fit:cover" alt=""><?php endif; ?>
                <?= e($h['title']) ?>
            </td>
            <td><?= $h['media_type'] === 'tv' ? 'S' . (int)$h['season'] . 'E' . (int)$h['episode'] : '电影' ?></td>
            <td><span class="tag tag-gold" style="background:rgba(255,194,75,.12);color:var(--gold)"><?= format_seconds((int)$h['position']) ?></span></td>
            <td style="color:var(--mut)"><?= e(time_ago($h['updated_at'])) ?></td>
        </tr>
        <?php endforeach; if (!$histories): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--mut);padding:30px">暂无观看记录</td></tr>
        <?php endif; ?>
        </tbody>
    </table></div>
    <?= pager_qs('index.php', ['h_user' => $hFilter], $hPage, $hPages, 'h_page') ?>
</div>

<!-- 独立模块：用户收藏 -->
<div class="panel">
    <div class="panel-head">
        <div class="panel-title"><i class="i i-heart"></i>用户收藏（<?= $fTotal ?>）</div>
        <form method="get" style="display:flex;gap:10px">
            <input class="input" style="width:200px;padding:8px 12px" name="f_user" value="<?= e($fFilter) ?>" placeholder="按用户名筛选">
            <button class="btn btn-sm btn-primary" type="submit"><i class="i i-search"></i>筛选</button>
        </form>
    </div>
    <div class="table-wrap"><table class="tbl">
        <thead><tr><th>用户</th><th>影片</th><th>类型</th><th>收藏时间</th></tr></thead>
        <tbody>
        <?php foreach ($favorites as $f): ?>
        <tr>
            <td><div class="user-cell">
                <?php if ($f['avatar']): ?><img src="<?= e($f['avatar']) ?>" alt=""><?php else: ?><span class="u-ph"><?= e(mb_strtoupper(mb_sub($f['username'], 0, 1))) ?></span><?php endif; ?>
                <span><?= display_username($f) ?></span>
            </div></td>
            <td style="display:flex;align-items:center;gap:10px">
                <?php if ($f['poster']): ?><img src="<?= e($f['poster']) ?>" style="width:32px;height:44px;border-radius:6px;object-fit:cover" alt=""><?php endif; ?>
                <?= e($f['title']) ?>
            </td>
            <td><?= $f['media_type'] === 'movie' ? '<span class="tag tag-mut">电影</span>' : '<span class="tag tag-blue">剧集</span>' ?></td>
            <td style="color:var(--mut)"><?= e($f['created_at']) ?></td>
        </tr>
        <?php endforeach; if (!$favorites): ?>
        <tr><td colspan="4" style="text-align:center;color:var(--mut);padding:30px">暂无收藏记录</td></tr>
        <?php endif; ?>
        </tbody>
    </table></div>
    <?= pager_qs('index.php', ['f_user' => $fFilter], $fPage, $fPages, 'f_page') ?>
</div>

<style>
@media(max-width:1024px){.dash-two{grid-template-columns:1fr !important}}
</style>
<?php admin_page_end(); ?>
