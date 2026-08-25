<?php
/**
 * Jay影视 - 播放源接口解析
 * 接口: https://api.yyzy-tv.vip/inc/apijson.php (苹果CMS JSON 格式)
 * 逻辑: 搜索片名 → 智能匹配(名称/年份/季) → 解析真实 m3u8 直链
 */

/* ---------------- 播放源数据 ---------------- */

function get_all_sources() {
    return db_all("SELECT * FROM play_sources ORDER BY is_default DESC, id ASC");
}

function get_default_source() {
    $s = db_one("SELECT * FROM play_sources WHERE is_default = 1 ORDER BY id ASC LIMIT 1");
    if ($s) return $s;
    return db_one("SELECT * FROM play_sources ORDER BY id ASC LIMIT 1");
}

/** 按 ID 获取播放源 */
function get_source_by_id($id) {
    if ((int)$id <= 0) return null;
    return db_one("SELECT * FROM play_sources WHERE id = ?", [(int)$id]);
}

/* ---------------- 名称工具 ---------------- */

/** 名称标准化：去空格/标点，转小写 */
function norm_name($s) {
    $s = (string)$s;
    if (function_exists('mb_strtolower')) $s = mb_strtolower($s, 'UTF-8');
    else $s = strtolower($s);
    $s = preg_replace('/[\s\x{3000}]+/u', '', $s);
    $s = preg_replace('/[：:·・，,。.!！?？\[\]()（）{}<>「」『』\-—_~～*#\$@%^&+=|\/;；]/u', '', $s);
    $s = str_replace(['"', "'", '“', '”', '‘', '’'], '', $s);
    return trim($s);
}

/** 中文数字转阿拉伯 */
function cn_to_int($s) {
    $map = ['零' => 0, '一' => 1, '二' => 2, '两' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9];
    $chars = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY);
    $total = 0; $cur = 0;
    foreach ($chars as $c) {
        if ($c === '十') { $cur = ($cur === 0 ? 1 : $cur) * 10; $total += $cur; $cur = 0; }
        elseif (isset($map[$c])) { $cur = $map[$c]; }
    }
    return $total + $cur;
}

/** 从片名提取季数：0=无标记(通常第1季) */
function extract_season($name) {
    if (preg_match('/第\s*(\d+)\s*季/u', $name, $m)) return (int)$m[1];
    if (preg_match('/第\s*([一二三四五六七八九十]+)\s*季/u', $name, $m)) return cn_to_int($m[1]);
    if (preg_match('/第\s*(\d+)\s*部/u', $name, $m)) return (int)$m[1];
    if (preg_match('/第\s*([一二三四五六七八九十]+)\s*部/u', $name, $m)) return cn_to_int($m[1]);
    if (preg_match('/season\s*(\d+)/i', $name, $m)) return (int)$m[1];
    if (preg_match('/s(\d{2})e\d{2}/i', $name, $m)) return (int)$m[1];
    return 0;
}

/** 名称是否为国语配音版本 */
function is_dub_name($name) {
    return contains($name, '国语') || contains($name, '普通话') || contains($name, '国配') || contains($name, '中文配音') || contains($name, '配音版');
}

/** 是否粤语版本 */
function is_yue_name($name) {
    return contains($name, '粤语') || contains($name, '粤語');
}

/* ---------------- 接口请求 ---------------- */

function source_search($apiUrl, $kw) {
    $apiUrl = trim((string)$apiUrl);
    if ($apiUrl === '' || $kw === '') return [];
    $cacheKey = 'srcsearch:' . md5($apiUrl . '|' . $kw);
    $cached = cache_get($cacheKey);
    if ($cached !== null) return $cached;

    $sep = contains($apiUrl, '?') ? '&' : '?';
    $url = $apiUrl . $sep . 'ac=videolist&wd=' . rawurlencode($kw);

    $list = [];
    // 接口偶发限流：空结果自动重试一次
    for ($try = 0; $try < 2; $try++) {
        $data = http_get_json($url, 12);
        if (is_array($data) && !empty($data['list']) && is_array($data['list'])) {
            foreach ($data['list'] as $v) {
                if (isset($v['vod_name']) && $v['vod_name'] !== '') $list[] = $v;
            }
            break;
        }
        if ($try === 0) usleep(400000); // 400ms 后重试
    }
    // 空结果仅缓存 60 秒（避免限流空结果长期污染）
    cache_set($cacheKey, $list, $list ? 900 : 60);
    return $list;
}

/** 解析 vod_play_url 为 [['name'=>, 'url'=>], ...] */
function parse_play_url($str) {
    $items = [];
    $str = trim((string)$str);
    if ($str === '') return $items;
    $groups = explode('$$$', $str);
    // 选择资源最多的一组
    $best = [];
    foreach ($groups as $g) {
        $g = trim($g);
        if ($g === '') continue;
        $cur = [];
        foreach (explode('#', $g) as $ep) {
            $ep = trim($ep);
            if ($ep === '') continue;
            $pos = strpos($ep, '$');
            if ($pos === false) { $name = $ep; $url = ''; }
            else { $name = trim(substr($ep, 0, $pos)); $url = trim(substr($ep, $pos + 1)); }
            if ($url === '' || stripos($url, 'http') !== 0) continue;
            $cur[] = ['name' => $name, 'url' => $url];
        }
        if (count($cur) > count($best)) $best = $cur;
    }
    return $best;
}

/** 从集名提取集数 */
function ep_number($name) {
    if (preg_match('/第\s*(\d+)\s*[集话話期回]/u', $name, $m)) return (int)$m[1];
    if (preg_match('/第\s*([一二三四五六七八九十]+)\s*[集话話期回]/u', $name, $m)) return cn_to_int($m[1]);
    if (preg_match('/^(\d+)(?:\s*[集话話期])?$/u', trim($name), $m)) return (int)$m[1];
    if (preg_match('/(?:^|[^\d])(\d+)\s*[集话話期]/u', $name, $m)) return (int)$m[1];
    if (preg_match('/s\d{1,2}e(\d{1,3})/i', $name, $m)) return (int)$m[1];
    return 0;
}

/** 按集数挑选播放地址 */
function pick_episode($items, $epNo) {
    if (empty($items)) return null;
    if ($epNo > 0) {
        foreach ($items as $it) {
            if (ep_number($it['name']) === $epNo) return $it;
        }
        // 松散匹配：名称中含数字
        foreach ($items as $it) {
            if (preg_match('/' . (int)$epNo . '/', $it['name'])) return $it;
        }
    }
    $idx = $epNo > 0 ? min($epNo - 1, count($items) - 1) : 0;
    return $items[$idx];
}

/** 电影多版本挑选（音轨） */
function pick_movie_track($items, $track) {
    if (empty($items)) return null;
    $dub = null; $orig = null;
    foreach ($items as $it) {
        if ($dub === null && is_dub_name($it['name'])) $dub = $it;
        if ($orig === null && !is_dub_name($it['name'])) $orig = $it;
    }
    if ($orig === null) $orig = $items[0];
    if ($track === 'dub') return $dub !== null ? $dub : $orig;
    return $orig !== null ? $orig : $dub;
}

/* ---------------- 候选评分 ---------------- */

function score_candidate($vod, $base, $baseOrig, $targetYear, $season, $type) {
    $name = (string)$vod['vod_name'];
    $n = norm_name($name);
    if ($n === '' || $base === '') return 0;
    if (empty($vod['_items'])) return 0;

    $score = 0;
    if ($n === $base || ($baseOrig !== '' && $n === $baseOrig)) $score = 100;
    elseif ($baseOrig !== '' && starts_with($n, $baseOrig) && mb_len($baseOrig) >= 2) $score = 85;
    elseif (starts_with($n, $base)) $score = 80;
    elseif (starts_with($base, $n) && mb_len($n) >= 2) $score = 75;
    elseif (contains($n, $base)) $score = 60;
    elseif ($baseOrig !== '' && contains($n, $baseOrig) && mb_len($baseOrig) >= 2) $score = 55;
    elseif (contains($base, $n) && mb_len($n) >= 2) $score = 45;
    else return 0;

    if (is_yue_name($name)) $score -= 45;                 // 粤语版本降权
    if (contains($name, '预告')) $score -= 70;
    if (contains($name, '特辑') || contains($name, '花絮') || contains($name, '幕后') || contains($name, '解说')) $score -= 55;

    $vy = isset($vod['vod_year']) ? (int)$vod['vod_year'] : 0;

    if ($type === 'tv') {
        $sMark = extract_season($name);
        if ($season > 1) {
            if ($sMark === $season) $score += 45;
            elseif ($sMark === 0) $score -= 35;
            else $score -= 55;
            if ($targetYear > 0 && $vy > 0) {
                $d = abs($vy - $targetYear);
                if ($d === 0) $score += 30; elseif ($d === 1) $score += 16; elseif ($d <= 2) $score += 5; elseif ($d > 5) $score -= 20;
            }
        } else {
            if ($sMark === 0 || $sMark === 1) $score += 25;
            else $score -= 60;
            if ($targetYear > 0 && $vy > 0 && abs($vy - $targetYear) > 4) $score -= 15;
        }
    } else {
        if ($targetYear > 0 && $vy > 0) {
            $d = abs($vy - $targetYear);
            if ($d === 0) $score += 30; elseif ($d === 1) $score += 15; elseif ($d > 3) $score -= 20;
        }
    }
    return $score;
}

/* ---------------- 主解析 ---------------- */

/**
 * 在指定播放源中解析媒体
 * 返回 ['found'=>bool, 'orig'=>['name','items'], 'dub'=>null|['name','items'], 'msg'=>string]
 */
function source_resolve_media($apiUrl, $type, $zhTitle, $origTitle, $year, $season = 1) {
    $season = max(1, (int)$season);
    $cacheKey = 'srcres:' . md5($apiUrl . '|' . $type . '|' . $zhTitle . '|' . $origTitle . '|' . $year . '|' . $season);
    $cached = cache_get($cacheKey);
    if ($cached !== null) return $cached;

    $notFound = ['found' => false, 'orig' => null, 'dub' => null, 'msg' => '播放源中未找到该片资源'];

    // 多关键词搜索
    $keywords = [];
    if ($zhTitle !== '') $keywords[] = $zhTitle;
    if ($origTitle !== '' && norm_name($origTitle) !== norm_name($zhTitle)) $keywords[] = $origTitle;

    $candidates = [];
    foreach ($keywords as $kw) {
        foreach (source_search($apiUrl, $kw) as $v) {
            $v['_items'] = parse_play_url(isset($v['vod_play_url']) ? $v['vod_play_url'] : '');
            if (!empty($v['_items'])) $candidates[] = $v;
        }
    }

    // 去重
    $uniq = [];
    foreach ($candidates as $v) {
        $uniq[$v['vod_id']] = $v;
    }
    $candidates = array_values($uniq);

    if (empty($candidates)) {
        cache_set($cacheKey, $notFound, 300);
        return $notFound;
    }

    $base = norm_name($zhTitle);
    $baseOrig = norm_name($origTitle);
    $targetYear = (int)$year;

    $best = null; $bestScore = -9999;
    $bestDub = null; $bestDubScore = -9999;
    foreach ($candidates as $v) {
        $score = score_candidate($v, $base, $baseOrig, $targetYear, $season, $type);
        if ($score <= 0) continue;
        if (is_dub_name($v['vod_name'])) {
            if ($score > $bestDubScore) { $bestDubScore = $score; $bestDub = $v; }
        } else {
            if ($score > $bestScore) { $bestScore = $score; $best = $v; }
        }
    }
    if ($best === null && $bestDub !== null) { $best = $bestDub; $bestScore = $bestDubScore; $bestDub = null; }
    if ($best === null) {
        cache_set($cacheKey, $notFound, 300);
        return $notFound;
    }

    $makeGroup = function ($entry) {
        if (!$entry) return null;
        return [
            'name'  => $entry['vod_name'],
            'items' => $entry['_items'],
        ];
    };

    $res = [
        'found' => true,
        'orig'  => $makeGroup($best),
        'dub'   => $makeGroup($bestDub),
        'msg'   => '',
    ];
    cache_set($cacheKey, $res, 600);
    return $res;
}

/**
 * 遍历播放源解析（可指定优先源）
 * $preferredSourceId > 0 时该源最先尝试，失败按"默认源→备用源"顺序自动降级
 * 返回 [resolveResult, sourceRow|null]
 */
function resolve_with_sources($type, $zhTitle, $origTitle, $year, $season = 1, $preferredSourceId = 0) {
    $sources = get_all_sources();
    $preferredSourceId = (int)$preferredSourceId;

    // 指定源排到最前，其余保持"默认源优先"顺序
    if ($preferredSourceId > 0) {
        $ordered = [];
        foreach ($sources as $s) {
            if ((int)$s['id'] === $preferredSourceId) $ordered[] = $s;
        }
        foreach ($sources as $s) {
            if ((int)$s['id'] !== $preferredSourceId) $ordered[] = $s;
        }
        $sources = $ordered;
    }

    $last = null;
    foreach ($sources as $s) {
        $res = source_resolve_media($s['api_url'], $type, $zhTitle, $origTitle, $year, $season);
        if (!empty($res['found'])) return [$res, $s];
        if ($last === null) $last = $res;
    }
    if ($last === null) $last = ['found' => false, 'orig' => null, 'dub' => null, 'msg' => '尚未配置播放源，请联系管理员'];
    return [$last, null];
}

/**
 * 解析最终播放直链
 * $type movie|tv, $track orig|dub, $ep 集数(tv)
 * $preferredSourceId 指定优先播放源（0=自动，默认源优先）
 * 返回 ['ok'=>bool,'url'=>m3u8,'entry'=>名称,'fallback'=>bool,'msg'=>,
 *       'source_id'=>实际使用源ID,'source_name'=>实际使用源名,
 *       'source_switched'=>是否发生源自动切换,'preferred_name'=>用户指定的源名]
 */
function resolve_play_url($type, $zhTitle, $origTitle, $year, $season, $ep, $track, $preferredSourceId = 0) {
    $preferredSourceId = (int)$preferredSourceId;
    $preferredName = '';
    if ($preferredSourceId > 0) {
        $ps = get_source_by_id($preferredSourceId);
        if ($ps) $preferredName = $ps['name'];
        else $preferredSourceId = 0; // 无效源 ID 回退自动
    }

    list($res, $source) = resolve_with_sources($type, $zhTitle, $origTitle, $year, $season, $preferredSourceId);
    $sourceId = $source ? (int)$source['id'] : 0;
    $sourceName = $source ? $source['name'] : '';
    $switched = ($preferredSourceId > 0 && $sourceId !== $preferredSourceId);

    if (empty($res['found'])) {
        return ['ok' => false, 'url' => '', 'entry' => '', 'fallback' => false, 'msg' => $res['msg'],
                'source_id' => $sourceId, 'source_name' => $sourceName,
                'source_switched' => false, 'preferred_name' => $preferredName];
    }

    $group = ($track === 'dub' && !empty($res['dub'])) ? $res['dub'] : $res['orig'];
    $fallback = false;
    if ($track === 'dub' && empty($res['dub'])) {
        // 无国语资源，回退原版
        $group = $res['orig'];
        $fallback = true;
    }

    if ($type === 'movie') {
        $item = pick_movie_track($group['items'], $track === 'dub' ? 'dub' : 'orig');
    } else {
        $item = pick_episode($group['items'], (int)$ep);
    }

    if (!$item) {
        return ['ok' => false, 'url' => '', 'entry' => $group['name'], 'fallback' => false, 'msg' => '未找到对应集数资源',
                'source_id' => $sourceId, 'source_name' => $sourceName,
                'source_switched' => false, 'preferred_name' => $preferredName];
    }
    return ['ok' => true, 'url' => $item['url'], 'entry' => $group['name'], 'fallback' => $fallback, 'msg' => '',
            'source_id' => $sourceId, 'source_name' => $sourceName,
            'source_switched' => $switched, 'preferred_name' => $preferredName];
}
