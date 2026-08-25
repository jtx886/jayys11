<?php
/**
 * Jay影视 - TMDB API 封装
 * API 代理:  https://api.tmdb.org
 * 图片代理:  https://images.tmdb.org/t/p
 */

define('TMDB_API_BASE', 'https://api.tmdb.org/3');
define('TMDB_IMG_BASE', 'https://images.tmdb.org/t/p');

function tmdb_key() {
    $key = setting('tmdb_api_key', '');
    return trim($key);
}

/**
 * 请求 TMDB（带 MySQL 缓存）
 * $path 例如 '/movie/popular'；$params 为附加查询参数
 */
function tmdb_get($path, $params = [], $cacheSeconds = 1800) {
    $key = tmdb_key();
    if ($key === '') return null;

    $qs = ['api_key' => $key, 'language' => 'zh-CN'];
    foreach ($params as $k => $v) $qs[$k] = $v;
    $url = TMDB_API_BASE . $path . '?' . http_build_query($qs);

    $cacheKey = 'tmdb:' . md5($url);
    if ($cacheSeconds > 0) {
        $cached = cache_get($cacheKey);
        if ($cached !== null) return $cached;
    }

    $data = http_get_json($url, 12);
    if ($data === null) return null;
    if ($cacheSeconds > 0) cache_set($cacheKey, $data, $cacheSeconds);
    return $data;
}

function tmdb_img($path, $size = 'w500') {
    if (!$path) return '';
    return TMDB_IMG_BASE . '/' . $size . $path;
}

/** 是否已配置 TMDB Key */
function tmdb_ready() {
    return tmdb_key() !== '';
}

/* ---------------- 业务封装 ---------------- */

/** 首页各分类数据 */
function tmdb_category($cat, $page = 1) {
    switch ($cat) {
        case 'movie':  return tmdb_get('/movie/popular', ['page' => $page]);
        case 'tv':     return tmdb_get('/tv/popular',    ['page' => $page]);
        case 'anime':  return tmdb_get('/discover/tv', ['with_genres' => 16, 'sort_by' => 'popularity.desc', 'page' => $page]);
        case 'variety':return tmdb_get('/discover/tv', ['with_genres' => 10764, 'sort_by' => 'popularity.desc', 'page' => $page]);
    }
    return null;
}

function tmdb_category_title($cat) {
    $map = ['movie' => '电影', 'tv' => '剧集', 'anime' => '动漫', 'variety' => '综艺'];
    return isset($map[$cat]) ? $map[$cat] : '电影';
}

/** 搜索（multi） */
function tmdb_search($query, $page = 1) {
    return tmdb_get('/search/multi', ['query' => $query, 'page' => $page, 'include_adult' => 'false']);
}

/** 详情（含 credits） */
function tmdb_detail($type, $id) {
    if ($type !== 'movie' && $type !== 'tv') return null;
    return tmdb_get('/' . $type . '/' . (int)$id, ['append_to_response' => 'credits'], 86400);
}

/** 剧集某一季（含每集封面） */
function tmdb_season($tvId, $seasonNo) {
    return tmdb_get('/tv/' . (int)$tvId . '/season/' . (int)$seasonNo, [], 86400);
}

/** 趋势（首页 Hero） */
function tmdb_trending() {
    return tmdb_get('/trending/movie/day');
}

/** 判断是否海外影视（非国产） */
function tmdb_is_overseas($detail, $type) {
    $langs = [];
    if ($type === 'movie') {
        $langs = isset($detail['original_language']) ? [$detail['original_language']] : [];
        if (!empty($detail['production_countries'])) {
            foreach ($detail['production_countries'] as $c) $langs[] = $c['iso_3166_1'];
        }
    } else {
        $langs = isset($detail['original_language']) ? [$detail['original_language']] : [];
        if (!empty($detail['origin_country'])) {
            foreach ($detail['origin_country'] as $c) $langs[] = $c;
        }
    }
    $langs = array_map('strtolower', $langs);
    // 含中文/中国标记 → 国产；否则海外
    foreach ($langs as $l) {
        if ($l === 'zh' || $l === 'cn' || $l === 'hk' || $l === 'tw' || $l === 'mo') {
            // 香港/台湾也归为中文区，但台配/粤配场景复杂，统一按国产逻辑隐藏音轨切换
            if ($l === 'hk' || $l === 'mo') return false;
            return false;
        }
    }
    return true;
}

/** 详情页展示用的标准化数据 */
function tmdb_normalize_item($item, $type) {
    $title = '';
    if ($type === 'movie') {
        $title = $item['title'] ?? ($item['original_title'] ?? '');
    } else {
        $title = $item['name'] ?? ($item['original_name'] ?? '');
    }
    $date = $type === 'movie' ? ($item['release_date'] ?? '') : ($item['first_air_date'] ?? '');
    return [
        'type'       => $type,
        'id'         => $item['id'] ?? 0,
        'title'      => $title,
        'poster'     => tmdb_img($item['poster_path'] ?? '', 'w342'),
        'backdrop'   => tmdb_img($item['backdrop_path'] ?? '', 'w780'),
        'rating'     => isset($item['vote_average']) ? round((float)$item['vote_average'], 1) : 0,
        'year'       => $date ? substr($date, 0, 4) : '',
        'overview'   => isset($item['overview']) && $item['overview'] !== '' ? $item['overview'] : '暂无简介',
    ];
}
