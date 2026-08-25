<?php
/**
 * Jay影视 - 影视详情页
 * 电影/剧集详情、季切换、集数封面、音轨切换（海外）、收藏
 */
require __DIR__ . '/includes/init.php';
require __DIR__ . '/includes/layout.php';

$type = get_val('type', 'movie') === 'tv' ? 'tv' : 'movie';
$id   = (int)get_val('id', 0);
if ($id <= 0) redirect('index.php');

$detail = tmdb_detail($type, $id);
if (!$detail || empty($detail['id'])) {
    page_start(['title' => '未找到影片']);
    echo '<div class="empty"><i class="i i-film"></i><h3>未找到该影片</h3><p>数据可能已失效，请返回重新选择</p><div style="margin-top:18px"><a class="btn btn-primary" href="index.php">返回首页</a></div></div>';
    page_end();
    exit;
}

$zhTitle   = $type === 'movie' ? ($detail['title'] ?? '') : ($detail['name'] ?? '');
$origTitle = $type === 'movie' ? ($detail['original_title'] ?? '') : ($detail['original_name'] ?? '');
$date      = $type === 'movie' ? ($detail['release_date'] ?? '') : ($detail['first_air_date'] ?? '');
$year      = $date ? substr($date, 0, 4) : '';
$overview  = $detail['overview'] ?: '暂无简介';
$poster    = tmdb_img($detail['poster_path'] ?? '', 'w500');
$backdrop  = tmdb_img($detail['backdrop_path'] ?? '', 'w1280');
$rating    = isset($detail['vote_average']) ? round((float)$detail['vote_average'], 1) : 0;
$overseas  = tmdb_is_overseas($detail, $type);
$track     = get_val('track', 'orig') === 'dub' ? 'dub' : 'orig';
if (!$overseas) $track = ''; // 国产不区分音轨，自动匹配

/* 收藏状态 */
$faved = false;
$user = current_user();
if ($user) {
    $faved = (bool)db_one("SELECT id FROM favorites WHERE user_id = ? AND media_type = ? AND tmdb_id = ?", [$user['id'], $type, $id]);
}

/* 观看历史（该剧） */
$history = [];
if ($user) {
    foreach (db_all("SELECT * FROM watch_history WHERE user_id = ? AND media_type = ? AND tmdb_id = ?", [$user['id'], $type, $id]) as $h) {
        $history[$h['episode']] = $h;
    }
}

/* 季数据 */
$seasonNo = max(1, (int)get_val('season', 1));
$seasonData = null;
$seasons = [];
if ($type === 'tv' && !empty($detail['seasons'])) {
    foreach ($detail['seasons'] as $s) {
        if ((int)($s['season_number'] ?? 0) < 1) continue; // 跳过特别篇
        $seasons[] = $s;
    }
    if (!$seasons && !empty($detail['seasons'])) $seasons = $detail['seasons'];
    // 若请求季不存在则回退第1季
    $valid = false;
    foreach ($seasons as $s) if ((int)$s['season_number'] === $seasonNo) $valid = true;
    if (!$valid) { $seasonNo = (int)($seasons[0]['season_number'] ?? 1); }
    $seasonData = tmdb_season($id, $seasonNo);
}

$seasonYear = '';
$seasonRating = 0;
$seasonOv = '';
$episodes = [];
if ($seasonData && !empty($seasonData['episodes'])) {
    $episodes = $seasonData['episodes'];
    $seasonOv = $seasonData['overview'] ?: '';
    $seasonRating = isset($seasonData['vote_average']) ? round((float)$seasonData['vote_average'], 1) : 0;
    if (!empty($seasonData['air_date'])) $seasonYear = substr($seasonData['air_date'], 0, 4);
}
if ($seasonYear === '' && !empty($seasons)) {
    foreach ($seasons as $s) {
        if ((int)$s['season_number'] === $seasonNo && !empty($s['air_date'])) $seasonYear = substr($s['air_date'], 0, 4);
    }
}

/* 演员 */
$cast = [];
if (!empty($detail['credits']['cast'])) {
    $cast = array_slice($detail['credits']['cast'], 0, 12);
}

/* 类型 */
$genres = [];
if (!empty($detail['genres'])) {
    foreach ($detail['genres'] as $g) $genres[] = $g['name'];
}

$basePlayUrl = 'play.php?m=' . $type . '&t=' . $id;

/* 播放源选择（后台配置 ≥2 个时开放选择，src=0 表示自动优选） */
$sources = get_all_sources();
$srcSel = (int)get_val('src', 0);
if ($srcSel > 0) {
    $srcOk = false;
    foreach ($sources as $s) if ((int)$s['id'] === $srcSel) $srcOk = true;
    if (!$srcOk) $srcSel = 0; // 无效源回退自动
}
$multiSource = count($sources) >= 2;
$srcQ = $multiSource ? '&src=' . $srcSel : '';
$srcBase = 'detail.php?type=' . $type . '&id=' . $id . ($type === 'tv' ? '&season=' . $seasonNo : '') . ($track !== '' ? '&track=' . $track : '');

page_start(['title' => $zhTitle, 'full_width' => false]);
?>

<div class="det-head">
    <div class="det-bg" style="background-image:url('<?= e($backdrop ?: $poster) ?>')"></div>
    <div class="container">
        <div class="det-in">
            <div class="det-poster">
                <?php if ($poster): ?>
                <img src="<?= e($poster) ?>" alt="<?= e($zhTitle) ?>">
                <?php else: ?>
                <div style="aspect-ratio:2/3;display:flex;align-items:center;justify-content:center;color:#39435c;font-size:52px"><i class="i i-image"></i></div>
                <?php endif; ?>
            </div>
            <div class="det-info">
                <h1 class="det-title"><?= e($zhTitle) ?></h1>
                <?php if ($origTitle && $origTitle !== $zhTitle): ?><div class="det-en"><?= e($origTitle) ?></div><?php endif; ?>
                <div class="det-meta">
                    <?php if ($rating > 0): ?><span class="chip rate"><i class="i i-star"></i><?= $rating ?></span><?php endif; ?>
                    <span class="chip"><?= e($year ?: '未知') ?></span>
                    <span class="chip"><?= $type === 'movie' ? '电影' : '剧集' ?></span>
                    <?php foreach (array_slice($genres, 0, 4) as $g): ?><span class="chip"><?= e($g) ?></span><?php endforeach; ?>
                    <?php if ($type === 'tv' && !empty($detail['number_of_seasons'])): ?><span class="chip">共 <?= (int)$detail['number_of_seasons'] ?> 季</span><?php endif; ?>
                </div>
                <div class="det-ov"><?= e($overview) ?></div>

                <?php if ($cast): ?>
                <div class="det-cast">
                    <div class="track-label" style="margin-bottom:12px"><i class="i i-user"></i>主演阵容</div>
                    <div class="cast-row">
                        <?php foreach ($cast as $c): ?>
                        <div class="cast-item">
                            <?php $cp = tmdb_img($c['profile_path'] ?? '', 'w185'); ?>
                            <?php if ($cp): ?>
                            <img loading="lazy" src="<?= e($cp) ?>" alt="<?= e($c['name']) ?>">
                            <?php else: ?>
                            <span class="cast-ph"><i class="i i-user"></i></span>
                            <?php endif; ?>
                            <div class="cast-name" title="<?= e($c['name']) ?>"><?= e($c['name']) ?></div>
                            <div class="cast-role" title="<?= e($c['character'] ?? '') ?>"><?= e($c['character'] ?? '饰') ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="det-actions">
                    <?php $playHref = $basePlayUrl . ($type === 'tv' ? '&s=' . $seasonNo . '&e=1' : '') . ($track !== '' ? '&track=' . $track : '') . $srcQ; ?>
                    <a class="btn btn-primary btn-lg" data-play-link href="<?= e($playHref) ?>" <?= is_login() ? '' : 'data-need-login' ?>><i class="i i-play"></i><?= $type === 'movie' ? '立即播放' : '播放第 1 集' ?></a>
                    <button class="btn <?= $faved ? 'btn-primary' : 'btn-ghost' ?> btn-lg" id="favBtn"
                            data-type="<?= e($type) ?>" data-id="<?= $id ?>" data-title="<?= e($zhTitle) ?>" data-poster="<?= e($poster) ?>">
                        <span><?= $faved ? '已收藏' : '收藏' ?></span>
                    </button>
                </div>
            </div>
        </div>

        <?php if ($type === 'tv' && $seasons): ?>
        <div class="season-bar">
            <div class="sel-label">选择季</div>
            <div class="season-chips">
                <?php foreach ($seasons as $s): $sn = (int)$s['season_number']; ?>
                <a class="schip <?= $sn === $seasonNo ? 'on' : '' ?>"
                   href="detail.php?type=tv&id=<?= $id ?>&season=<?= $sn ?><?= $track !== '' ? '&track=' . $track : '' ?><?= $srcQ ?>">第 <?= $sn ?> 季<?= !empty($s['air_date']) ? '·' . substr($s['air_date'], 0, 4) : '' ?></a>
                <?php endforeach; ?>
            </div>
            <?php if ($multiSource): ?>
            <div class="track-wrap" style="margin:0">
                <span class="track-label">播放源</span>
                <div class="seg">
                    <a class="seg-item src-auto <?= $srcSel === 0 ? 'on' : '' ?>" href="<?= e($srcBase) ?>&src=0">自动</a>
                    <?php foreach ($sources as $s): ?>
                    <a class="seg-item <?= $srcSel === (int)$s['id'] ? 'on' : '' ?>" href="<?= e($srcBase) ?>&src=<?= (int)$s['id'] ?>"><?= e($s['name']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($overseas): ?>
            <div class="track-wrap" style="margin:0 0 0 auto">
                <span class="track-label">音轨</span>
                <div class="seg">
                    <a class="seg-item audio-orig <?= $track === 'dub' ? '' : 'on' ?>" href="detail.php?type=tv&id=<?= $id ?>&season=<?= $seasonNo ?>&track=orig<?= $srcQ ?>">原版</a>
                    <a class="seg-item <?= $track === 'dub' ? 'on' : '' ?>" href="detail.php?type=tv&id=<?= $id ?>&season=<?= $seasonNo ?>&track=dub<?= $srcQ ?>">普通话</a>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php /* 本季信息 */ ?>
        <div class="season-meta">
            <span><b style="color:var(--tx)">第 <?= $seasonNo ?> 季</b><?= $seasonYear ? ' · ' . e($seasonYear) . ' 年' : '' ?></span>
            <span>共 <?= count($episodes) ?> 集</span>
            <?php if ($seasonRating > 0): ?><span class="m-rate"><i class="i i-star"></i><?= $seasonRating ?></span><?php endif; ?>
            <?php if ($seasonOv): ?><span style="flex-basis:100%;color:var(--mut);font-size:13px"><?= e($seasonOv) ?></span><?php endif; ?>
        </div>

        <div class="ep-grid">
            <?php
            $epIdx = 0;
            foreach ($episodes as $ep):
                $epIdx++;
                $epNo = (int)($ep['episode_number'] ?: $epIdx);
                $still = tmdb_img($ep['still_path'] ?? '', 'w300');
                $epHref = $basePlayUrl . '&s=' . $seasonNo . '&e=' . $epNo . ($track !== '' ? '&track=' . $track : '') . $srcQ;
                $hist = isset($history[$epNo]) ? $history[$epNo] : null;
            ?>
            <a class="ep-card fade-in <?= $epIdx > 6 ? 'd' . min(4, (int)(($epIdx - 1) / 6)) : '' ?>" href="<?= e($epHref) ?>" <?= is_login() ? '' : 'data-need-login' ?>>
                <div class="ep-still">
                    <?php if ($still): ?>
                    <img loading="lazy" src="<?= e($still) ?>" alt="">
                    <?php else: ?>
                    <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#2a3245;font-size:30px"><i class="i i-image"></i></div>
                    <?php endif; ?>
                    <span class="ep-play-ov"><i class="i i-play"></i></span>
                    <span class="ep-num">第 <?= $epNo ?> 集</span>
                    <?php if (!empty($ep['vote_average']) && $ep['vote_average'] > 0): ?>
                    <span class="ep-score"><i class="i i-star"></i><?= round((float)$ep['vote_average'], 1) ?></span>
                    <?php endif; ?>
                    <?php if ($hist && (int)$hist['position'] > 10): ?>
                    <span class="ep-watched" style="width:<?= min(100, max(12, (int)$hist['position'] / 60)) ?>%"></span>
                    <?php endif; ?>
                </div>
                <div class="ep-info">
                    <div class="ep-title" title="<?= e($ep['name'] ?? '') ?>"><?php if ($hist && (int)$hist['position'] > 10): ?><i class="i i-eye" style="color:var(--accent);margin-right:4px"></i><?php endif; ?><?= e($ep['name'] ?: ('第 ' . $epNo . ' 集')) ?></div>
                    <div class="ep-date"><?= !empty($ep['air_date']) ? e($ep['air_date']) : '暂无播出日期' ?></div>
                </div>
            </a>
            <?php endforeach; ?>
            <?php if (!$episodes): ?>
            <div class="empty" style="grid-column:1/-1"><i class="i i-tv"></i><h3>本季暂无剧集数据</h3><p>TMDB 收录信息可能不完整</p></div>
            <?php endif; ?>
        </div>
        <?php elseif ($type === 'movie' && ($overseas || $multiSource)): ?>
        <div class="season-bar">
            <?php if ($overseas): ?>
            <div class="track-wrap" style="margin:0">
                <span class="track-label">音轨选择</span>
                <div class="seg">
                    <a class="seg-item audio-orig <?= $track === 'dub' ? '' : 'on' ?>" href="detail.php?type=movie&id=<?= $id ?>&track=orig<?= $srcQ ?>">原版（中文字幕）</a>
                    <a class="seg-item <?= $track === 'dub' ? 'on' : '' ?>" href="detail.php?type=movie&id=<?= $id ?>&track=dub<?= $srcQ ?>">普通话配音</a>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($multiSource): ?>
            <div class="track-wrap" style="margin:<?= $overseas ? '0 0 0 auto' : '0' ?>">
                <span class="track-label">播放源</span>
                <div class="seg">
                    <a class="seg-item src-auto <?= $srcSel === 0 ? 'on' : '' ?>" href="<?= e($srcBase) ?>&src=0">自动</a>
                    <?php foreach ($sources as $s): ?>
                    <a class="seg-item <?= $srcSel === (int)$s['id'] ? 'on' : '' ?>" href="<?= e($srcBase) ?>&src=<?= (int)$s['id'] ?>"><?= e($s['name']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div style="height:30px"></div>
    </div>
</div>

<?php page_end(); ?>
