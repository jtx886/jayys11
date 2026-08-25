<?php
/**
 * Jay影视 - 播放页
 * 流程: 登录校验 → TMDB 取标题 → 播放源接口取真实 m3u8 直链
 *       → urlencode 编码 → 拼接解析播放器 → iframe 嵌入播放
 */
require __DIR__ . '/includes/init.php';
require __DIR__ . '/includes/layout.php';

/* ---------- 登录校验：未登录直接跳转登录页 ---------- */
$user = current_user();
if (!$user) {
    $self = 'play.php?' . $_SERVER['QUERY_STRING'];
    redirect('login.php?msg=needlogin&redirect=' . urlencode($self));
}

$m     = get_val('m', 'movie') === 'tv' ? 'tv' : 'movie';
$tmdbId = (int)get_val('t', 0);
// 季参数兼容：季选择器传 season，剧集链接传 s
$season = max(1, (int)(get_val('season', '') !== '' ? get_val('season') : get_val('s', 1)));
$ep     = max(1, (int)get_val('e', 1));
$track  = get_val('track', 'orig') === 'dub' ? 'dub' : 'orig';

if ($tmdbId <= 0) redirect('index.php');

/* ---------- TMDB 信息 ---------- */
$detail = tmdb_detail($m, $tmdbId);
if (!$detail) {
    page_start(['title' => '播放失败']);
    echo '<div class="empty"><i class="i i-info"></i><h3>影片信息获取失败</h3><p>请稍后重试或返回重新进入</p><div style="margin-top:18px"><a class="btn btn-primary" href="index.php">返回首页</a></div></div>';
    page_end();
    exit;
}

$zhTitle   = $m === 'movie' ? ($detail['title'] ?? '') : ($detail['name'] ?? '');
$origTitle = $m === 'movie' ? ($detail['original_title'] ?? '') : ($detail['original_name'] ?? '');
$date      = $m === 'movie' ? ($detail['release_date'] ?? '') : ($detail['first_air_date'] ?? '');
$poster    = tmdb_img($detail['poster_path'] ?? '', 'w342');
$backdrop  = tmdb_img($detail['backdrop_path'] ?? '', 'w780');
$rating    = isset($detail['vote_average']) ? round((float)$detail['vote_average'], 1) : 0;

/* 海外/国产 判断（国产不区分音轨） */
$overseas = tmdb_is_overseas($detail, $m);
if (!$overseas) $track = 'orig';

/* 剧集：本季播出年份（用于资源匹配） */
$seasonYear = $date ? substr($date, 0, 4) : '';
$epName = '';
if ($m === 'tv') {
    $seasonData = tmdb_season($tmdbId, $season);
    if ($seasonData && !empty($seasonData['air_date'])) $seasonYear = substr($seasonData['air_date'], 0, 4);
    if ($seasonData && !empty($seasonData['episodes'])) {
        foreach ($seasonData['episodes'] as $eItem) {
            if ((int)($eItem['episode_number'] ?? 0) === $ep) {
                $epName = $eItem['name'] ?: ('第 ' . $ep . ' 集');
                break;
            }
        }
    }
}
if ($epName === '') $epName = '第 ' . $ep . ' 集';

/* ---------- 解析真实播放直链 ---------- */
$resolve = resolve_play_url($m, $zhTitle, $origTitle, $m === 'tv' ? $seasonYear : substr($date, 0, 4), $season, $ep, $overseas ? $track : 'orig');

$playerUrl = '';
if ($resolve['ok']) {
    // 核心规则：真实 m3u8 直链 urlencode 后拼接到解析播放器 url 参数
    $playerUrl = 'https://svip.ffzyplay.com/?url=' . rawurlencode($resolve['url']);
}

/* ---------- 观看历史（恢复进度） ---------- */
$hist = db_one("SELECT * FROM watch_history WHERE user_id = ? AND media_type = ? AND tmdb_id = ? AND season = ? AND episode = ?", [$user['id'], $m, $tmdbId, $season, $ep]);
$savedPos = $hist ? (int)$hist['position'] : 0;

/* 同剧全部集数（侧栏） */
$histAll = [];
if ($m === 'tv') {
    foreach (db_all("SELECT * FROM watch_history WHERE user_id = ? AND media_type = 'tv' AND tmdb_id = ? AND season = ?", [$user['id'], $tmdbId, $season]) as $h) {
        $histAll[(int)$h['episode']] = $h;
    }
}
$seasonCount = 1;
if ($m === 'tv' && !empty($detail['seasons'])) {
    $seasonCount = 0;
    foreach ($detail['seasons'] as $s) if ((int)($s['season_number'] ?? 0) >= 1) $seasonCount++;
    if ($seasonCount < 1) $seasonCount = 1;
}

/* 本季集数（侧栏快捷集数列表） */
$totalEps = 0;
if ($m === 'tv' && isset($seasonData) && !empty($seasonData['episodes'])) {
    $totalEps = count($seasonData['episodes']);
}

page_start(['title' => $zhTitle . ($m === 'tv' ? ' ' . $epName : ''), 'full_width' => false]);
?>

<div class="play-wrap">
    <!-- 顶部信息 -->
    <div class="play-head">
        <div>
            <div class="play-title">
                <a href="detail.php?type=<?= $m ?>&id=<?= $tmdbId ?>" style="color:inherit"><?= e($zhTitle) ?></a>
                <?php if ($m === 'tv'): ?><span class="chip">S<?= $season ?>E<?= $ep ?></span><span class="chip"><?= e($epName) ?></span><?php endif; ?>
                <?php if ($rating > 0): ?><span class="chip rate"><i class="i i-star"></i><?= $rating ?></span><?php endif; ?>
            </div>
            <div class="play-sub">
                <span>已观看 <b id="watchPos" style="color:var(--gold)"><?= format_seconds($savedPos) ?></b></span>
                <?php if ($resolve['ok']): ?><span class="play-status"><span class="dot"></span>片源：<?= e($resolve['entry']) ?></span><?php endif; ?>
                <?php if ($overseas && $track === 'dub'): ?><span class="tag tag-blue"><i class="i i-film"></i>普通话配音</span><?php elseif ($overseas): ?><span class="tag tag-blue"><i class="i i-film"></i>原版音轨</span><?php endif; ?>
            </div>
        </div>
        <div class="play-track">
            <?php if ($overseas): ?>
            <div class="seg" id="trackSeg">
                <a class="seg-item audio-orig <?= $track === 'dub' ? '' : 'on' ?>" href="play.php?m=<?= $m ?>&t=<?= $tmdbId ?>&s=<?= $season ?>&e=<?= $ep ?>&track=orig">原版</a>
                <a class="seg-item <?= $track === 'dub' ? 'on' : '' ?>" href="play.php?m=<?= $m ?>&t=<?= $tmdbId ?>&s=<?= $season ?>&e=<?= $ep ?>&track=dub">普通话</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 播放器 -->
    <?php if ($resolve['ok'] && $playerUrl !== ''): ?>
    <div class="play-box" id="playerBox"
         data-m="<?= $m ?>" data-t="<?= $tmdbId ?>" data-s="<?= $season ?>" data-e="<?= $ep ?>"
         data-position="<?= $savedPos ?>"
         data-title="<?= e($zhTitle) ?>" data-poster="<?= e($poster) ?>" data-ename="<?= e($epName) ?>">
        <iframe src="<?= e($playerUrl) ?>" allowfullscreen allow="autoplay; fullscreen; encrypted-media; picture-in-picture" scrolling="no"></iframe>
    </div>
    <?php if ($resolve['fallback']): ?>
    <div class="track-note"><i class="i i-info"></i>当前播放源暂无「普通话配音」版本，已为您自动切换至原版音轨。</div>
    <?php endif; ?>
    <?php else: ?>
    <div class="play-box">
        <div class="no-source">
            <i class="i i-film"></i>
            <h3 style="font-size:17px;color:var(--tx)">未能匹配到可用片源</h3>
            <p style="max-width:420px;line-height:1.9"><?= e($resolve['msg'] ?: '播放源中暂无该片资源，请稍后再试或更换影片') ?></p>
            <a class="btn btn-ghost" href="javascript:location.reload()"><i class="i i-history"></i>刷新重试</a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($m === 'tv'): ?>
    <!-- 剧集快捷切换 -->
    <div class="play-sidebar">
        <div class="section-head">
            <div class="section-title" style="font-size:16px"><span class="bar"></span>剧集列表<?= $seasonCount > 1 ? '（第 ' . $season . ' 季）' : '' ?></div>
        </div>
        <?php if ($seasonCount > 1): ?>
        <div class="season-chips" style="margin-bottom:14px">
            <?php for ($s = 1; $s <= $seasonCount; $s++): ?>
            <a class="schip <?= $s === $season ? 'on' : '' ?>"
               href="play.php?m=tv&t=<?= $tmdbId ?>&s=<?= $s ?>&e=1<?= $overseas ? '&track=' . $track : '' ?>">第 <?= $s ?> 季</a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
        <div class="play-eps">
            <?php
            $maxEps = max($totalEps, $ep, 12);
            for ($i = 1; $i <= $maxEps; $i++):
                $watched = isset($histAll[$i]);
            ?>
            <a class="ep-btn <?= $i === $ep ? 'on' : '' ?>" href="play.php?m=tv&t=<?= $tmdbId ?>&s=<?= $season ?>&e=<?= $i ?><?= $overseas ? '&track=' . $track : '' ?>">
                <?= $watched && $i !== $ep ? '✓ ' : '' ?><?= $i ?>
            </a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php page_end(); ?>
