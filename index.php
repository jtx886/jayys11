<?php
/**
 * Jay影视 - 首页
 */
require __DIR__ . '/includes/init.php';
require __DIR__ . '/includes/layout.php';

$cat  = get_val('cat', '');
$page = max(1, (int)get_val('page', 1));

/* ---------- 公告（仅首页弹窗） ---------- */
$notice = null;
$noticeVer = '';
if ($cat === '' && $page === 1) {
    $notice = db_one("SELECT * FROM notices ORDER BY id DESC LIMIT 1");
    if ($notice) {
        $noticeVer = md5($notice['id'] . '|' . $notice['created_at']);
        $acked = isset($_COOKIE['jay_notice_ack']) ? $_COOKIE['jay_notice_ack'] : '';
        if (urldecode($acked) === $noticeVer) $notice = null; // 已关闭且公告未更新
    }
}

/* ---------- 数据 ---------- */
$hero = null;
$sections = [];
$catTitle = tmdb_category_title($cat ?: 'movie');

if (!tmdb_ready()) {
    // 未配置 TMDB Key
} elseif ($cat !== '') {
    $data = tmdb_category($cat, $page);
    $items = [];
    if (!empty($data['results'])) {
        foreach ($data['results'] as $it) {
            $type = isset($it['media_type']) && $it['media_type'] !== '' ? $it['media_type'] : $cat;
            if ($type !== 'movie' && $type !== 'tv') continue;
            $items[] = tmdb_normalize_item($it, $type === 'movie' ? 'movie' : 'tv');
        }
    }
    $totalPages = min(500, max(1, (int)($data['total_pages'] ?? 1)));
    $sections[] = ['title' => $catTitle, 'items' => $items, 'cat' => $cat, 'page' => $page, 'pages' => $totalPages];
} else {
    $trend = tmdb_trending();
    if (!empty($trend['results'])) {
        $heroItem = null;
        foreach ($trend['results'] as $it) {
            if (!empty($it['backdrop_path'])) { $heroItem = $it; break; }
        }
        if ($heroItem) {
            $type = ($heroItem['media_type'] ?? '') === 'tv' ? 'tv' : 'movie';
            $hero = tmdb_normalize_item($heroItem, $type);
        }
    }
    foreach ([['movie', '热门电影'], ['tv', '热门剧集'], ['anime', '热门动漫'], ['variety', '热门综艺']] as $c) {
        $data = tmdb_category($c[0]);
        $items = [];
        if (!empty($data['results'])) {
            foreach (array_slice($data['results'], 0, 12) as $it) {
                $items[] = tmdb_normalize_item($it, $c[0] === 'movie' ? 'movie' : 'tv');
            }
        }
        if ($items) $sections[] = ['title' => $c[1], 'items' => $items, 'cat' => $c[0]];
    }
}

function media_card($m) {
    $href = 'detail.php?type=' . $m['type'] . '&id=' . $m['id'];
    $needLogin = is_login() ? '' : ' data-need-login';
    echo '<a class="m-card fade-in" href="' . e($href) . '"' . $needLogin . '>'
       . '<div class="m-poster">';
    if ($m['poster']) echo '<img loading="lazy" src="' . e($m['poster']) . '" alt="' . e($m['title']) . '">';
    else echo '<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#39435c;font-size:30px"><i class="i i-image"></i></div>';
    echo '<span class="m-hover-play"><i class="i i-play"></i></span>';
    if ($m['rating'] > 0) echo '<span class="m-tag rec" style="left:auto;right:10px;"><i class="i i-star" style="font-size:10px;margin-right:3px"></i>' . $m['rating'] . '</span>';
    if ($m['type'] === 'tv') echo '<span class="m-tag">剧集</span>';
    echo '</div><div class="m-meta">'
       . '<div class="m-name" title="' . e($m['title']) . '">' . e($m['title']) . '</div>'
       . '<div class="m-sub"><span>' . e($m['year'] ?: '暂无') . '</span></div>'
       . '</div></a>';
}

page_start(['active' => $cat === '' ? 'home' : $cat, 'title' => $cat === '' ? site_name() . ' - 在线高清影视' : $catTitle . ' - ' . site_name()]);
?>

<?php if (!tmdb_ready()): ?>
<div class="empty" style="margin-top:60px">
    <i class="i i-info"></i>
    <h3>TMDB API Key 尚未配置</h3>
    <p>请使用管理员账号登录，前往「管理后台 → 网站设置」填写 TMDB API Key</p>
    <div style="margin-top:18px"><a class="btn btn-primary" href="login.php">前往登录</a></div>
</div>
<?php elseif ($cat !== ''): ?>
<!-- 分类页 -->
<div class="search-head">
    <h2><?= e($catTitle) ?><span> · 第 <?= $page ?> 页</span></h2>
    <p>基于 TMDB 数据实时呈现</p>
</div>
<?php if (empty($sections[0]['items'])): ?>
<div class="empty"><i class="i i-film"></i><h3>暂无数据</h3><p>获取数据失败，请稍后刷新重试</p></div>
<?php else: ?>
<div class="media-grid">
    <?php foreach ($sections[0]['items'] as $m) media_card($m); ?>
</div>
<?php if ($sections[0]['pages'] > 1): ?>
<div class="pager">
    <a class="pg-btn" <?= $page > 1 ? 'href="index.php?cat=' . e($cat) . '&page=' . ($page - 1) . '"' : 'disabled' ?>><i class="i i-arrow-l"></i></a>
    <?php
    $pages = $sections[0]['pages'];
    $from = max(1, $page - 2); $to = min($pages, $from + 4); $from = max(1, $to - 4);
    for ($p = $from; $p <= $to; $p++): ?>
    <a class="pg-btn <?= $p === $page ? 'on' : '' ?>" href="index.php?cat=<?= e($cat) ?>&page=<?= $p ?>"><?= $p ?></a>
    <?php endfor; ?>
    <a class="pg-btn" <?= $page < $pages ? 'href="index.php?cat=' . e($cat) . '&page=' . ($page + 1) . '"' : 'disabled' ?>><i class="i i-arrow-r"></i></a>
</div>
<?php endif; ?>
<?php endif; ?>

<?php else: ?>
<!-- 首页 -->
<?php if ($hero): ?>
<section class="hero">
    <div class="hero-bg" style="background-image:url('<?= e($hero['backdrop'] ?: $hero['poster']) ?>')"></div>
    <div class="hero-in">
        <div class="hero-tag"><i class="i i-fire"></i>今日热门 TOP1</div>
        <h1 class="hero-title"><?= e($hero['title']) ?></h1>
        <div class="hero-meta">
            <span class="m-rate"><i class="i i-star"></i><?= $hero['rating'] ?></span>
            <span class="chip"><?= e($hero['year'] ?: '未知年份') ?></span>
            <span class="chip"><?= $hero['type'] === 'movie' ? '电影' : '剧集' ?></span>
        </div>
        <p class="hero-ov"><?= e($hero['overview']) ?></p>
        <div class="hero-btns">
            <a class="btn btn-primary btn-lg" href="play.php?m=<?= $hero['type'] ?>&t=<?= $hero['id'] ?>&s=1&e=1" <?= is_login() ? '' : 'data-need-login' ?>><i class="i i-play"></i>立即播放</a>
            <a class="btn btn-ghost btn-lg" href="detail.php?type=<?= $hero['type'] ?>&id=<?= $hero['id'] ?>"><i class="i i-info"></i>查看详情</a>
        </div>
    </div>
</section>
<?php endif; ?>

<?php foreach ($sections as $i => $sec): ?>
<section class="section">
    <div class="section-head">
        <div class="section-title"><span class="bar"></span><?= e($sec['title']) ?></div>
        <a class="section-more" href="index.php?cat=<?= e($sec['cat']) ?>">查看更多 <i class="i i-arrow-r"></i></a>
    </div>
    <div class="media-grid">
        <?php foreach ($sec['items'] as $m) media_card($m); ?>
    </div>
</section>
<?php endforeach; ?>

<?php if (empty($sections)): ?>
<div class="empty"><i class="i i-film"></i><h3>获取影视数据失败</h3><p>请检查 TMDB API Key 是否正确，或稍后刷新重试</p></div>
<?php endif; ?>
<?php endif; ?>

<?php
// 输出公告弹窗（仅首页）
if ($notice): ?>
<div class="modal-mask" id="noticeModal" data-ver="<?= e($noticeVer) ?>">
    <div class="modal">
        <div class="modal-head">
            <div class="modal-icon"><i class="i i-bell"></i></div>
            <div class="modal-title">网站公告</div>
            <button class="icon-btn modal-close" data-close-notice><i class="i i-close"></i></button>
        </div>
        <div class="modal-body">
            <div style="white-space:pre-wrap;text-align:left;line-height:2"><?= e($notice['content']) ?></div>
            <label class="modal-check">
                <input type="checkbox" id="noticeNoMore"><span class="ck"></span>不再提示
            </label>
        </div>
        <div class="modal-foot">
            <button class="btn btn-primary" data-close-notice>我知道了</button>
        </div>
    </div>
</div>
<?php endif; ?>
<?php page_end(); ?>
