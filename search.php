<?php
/**
 * Jay影视 - 搜索页
 */
require __DIR__ . '/includes/init.php';
require __DIR__ . '/includes/layout.php';

$q = trim(get_val('q', get_val('query', '')));
$page = max(1, (int)get_val('page', 1));
$items = [];
$pages = 1;
$total = 0;

if ($q !== '' && tmdb_ready()) {
    $data = tmdb_search($q, $page);
    if (!empty($data['results'])) {
        foreach ($data['results'] as $it) {
            $mt = isset($it['media_type']) ? $it['media_type'] : '';
            if ($mt !== 'movie' && $mt !== 'tv') continue;
            if (empty($it['poster_path']) && empty($it['backdrop_path'])) continue;
            $items[] = tmdb_normalize_item($it, $mt);
        }
        $pages = min(500, max(1, (int)($data['total_pages'] ?? 1)));
        $total = (int)($data['total_results'] ?? count($items));
    }
}

page_start(['active' => '', 'title' => $q !== '' ? '"' . $q . '" 的搜索结果' : '搜索']);
?>
<div class="search-head">
    <h2><?php if ($q !== ''): ?>搜索 <span><?= e($q) ?></span> <?= $total ? '· 共 ' . $total . ' 条结果' : '' ?><?php else: ?>搜索影片<?php endif; ?></h2>
    <p>数据来源于 TMDB，支持电影、剧集、动漫、综艺</p>
</div>

<?php if ($q !== '' && empty($items)): ?>
<div class="empty">
    <i class="i i-search"></i>
    <h3>未找到相关影片</h3>
    <p>换个关键词试试，例如「阿凡达」「庆余年」「海贼王」</p>
    <div style="margin-top:18px"><a class="btn btn-ghost" href="index.php"><i class="i i-home"></i>返回首页</a></div>
</div>
<?php elseif ($q !== ''): ?>
<div class="media-grid">
    <?php $i = 0; foreach ($items as $m): $i++; ?>
    <a class="m-card fade-in <?= 'd' . min(4, (int)ceil($i / 8)) ?>" href="detail.php?type=<?= $m['type'] ?>&id=<?= $m['id'] ?>" <?= is_login() ? '' : 'data-need-login' ?>>
        <div class="m-poster">
            <?php if ($m['poster']): ?>
            <img loading="lazy" src="<?= e($m['poster']) ?>" alt="<?= e($m['title']) ?>">
            <?php endif; ?>
            <span class="m-hover-play"><i class="i i-play"></i></span>
            <?php if ($m['rating'] > 0): ?><span class="m-tag rec" style="left:auto;right:10px"><i class="i i-star" style="font-size:10px;margin-right:3px"></i><?= $m['rating'] ?></span><?php endif; ?>
            <span class="m-tag"><?= $m['type'] === 'movie' ? '电影' : '剧集' ?></span>
        </div>
        <div class="m-meta">
            <div class="m-name" title="<?= e($m['title']) ?>"><?= e($m['title']) ?></div>
            <div class="m-sub"><span><?= e($m['year'] ?: '暂无') ?></span></div>
        </div>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($pages > 1): ?>
<div class="pager">
    <a class="pg-btn" <?= $page > 1 ? 'href="search.php?q=' . urlencode($q) . '&page=' . ($page - 1) . '"' : 'disabled' ?>><i class="i i-arrow-l"></i></a>
    <?php
    $from = max(1, $page - 2); $to = min($pages, $from + 4); $from = max(1, $to - 4);
    for ($p = $from; $p <= $to; $p++): ?>
    <a class="pg-btn <?= $p === $page ? 'on' : '' ?>" href="search.php?q=<?= urlencode($q) ?>&page=<?= $p ?>"><?= $p ?></a>
    <?php endfor; ?>
    <a class="pg-btn" <?= $page < $pages ? 'href="search.php?q=' . urlencode($q) . '&page=' . ($page + 1) . '"' : 'disabled' ?>><i class="i i-arrow-r"></i></a>
</div>
<?php endif; ?>
<?php else: ?>
<div class="empty">
    <i class="i i-search"></i>
    <h3>输入关键词开始搜索</h3>
    <p>在顶部搜索框输入影片名称</p>
</div>
<?php endif; ?>
<?php page_end(); ?>
