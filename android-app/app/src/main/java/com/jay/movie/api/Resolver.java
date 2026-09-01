package com.jay.movie.api;

import com.jay.movie.data.Models;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * 播放源解析（网站 includes/source.php 完整移植）
 * 流程：搜索片名 → 智能匹配（名称/年份/季）→ 解析 m3u8 → 多源自动降级
 */
public class Resolver {

    /* ---------------- 结果结构 ---------------- */

    public static class Res {
        public boolean found;
        public Models.Group orig;
        public Models.Group dub;
        public String msg = "";
    }

    public static class PlayResult {
        public boolean ok;
        public String url = "";
        public String entry = "";       // 资源站条目名
        public String msg = "";
        public String sourceName = "";  // 实际使用的源
        public boolean switched;        // 是否发生自动换源
        public boolean fallback;        // 普通话不存在回退原版
    }

    /* ---------------- 简易内存缓存（对齐 PHP 缓存 TTL） ---------------- */

    private static final Map<String, Object> CACHE = new HashMap<>();
    private static final Map<String, Long> CACHE_TTL = new HashMap<>();

    private static Object cacheGet(String key) {
        Long exp = CACHE_TTL.get(key);
        if (exp == null || exp < System.currentTimeMillis()) {
            CACHE.remove(key);
            CACHE_TTL.remove(key);
            return null;
        }
        return CACHE.get(key);
    }

    private static void cacheSet(String key, Object val, long seconds) {
        CACHE.put(key, val);
        CACHE_TTL.put(key, System.currentTimeMillis() + seconds * 1000);
    }

    /* ---------------- 名称工具（PHP 同逻辑） ---------------- */

    private static String norm(String s) {
        if (s == null) return "";
        s = s.toLowerCase();
        s = s.replaceAll("[\\s\\u3000]+", "");
        s = s.replaceAll("[：:·，,。.!！?？\\[\\]()（）{}<>「」『』\\-—_~～*#$@%^&+=|/;；]", "");
        s = s.replace("\"", "").replace("'", "")
                .replace("“", "").replace("”", "").replace("‘", "").replace("’", "");
        return s.trim();
    }

    private static boolean has(String s, String kw) {
        return s != null && s.contains(kw);
    }

    private static boolean starts(String s, String p) {
        return s != null && p != null && !p.isEmpty() && s.startsWith(p);
    }

    private static int cnToInt(String s) {
        Map<Character, Integer> map = new HashMap<>();
        map.put('零', 0); map.put('一', 1); map.put('二', 2); map.put('两', 2);
        map.put('三', 3); map.put('四', 4); map.put('五', 5); map.put('六', 6);
        map.put('七', 7); map.put('八', 8); map.put('九', 9);
        int total = 0, cur = 0;
        for (char c : s.toCharArray()) {
            if (c == '十') {
                cur = (cur == 0 ? 1 : cur) * 10;
                total += cur;
                cur = 0;
            } else if (map.containsKey(c)) {
                cur = map.get(c);
            }
        }
        return total + cur;
    }

    /** 罗马数字转阿拉伯（仅 I/V/X 简单组合） */
    private static int romanToInt(String s) {
        if (s == null || s.isEmpty()) return 0;
        int total = 0;
        for (int i = 0; i < s.length(); i++) {
            int cur = s.charAt(i) == 'I' ? 1 : (s.charAt(i) == 'V' ? 5 : 10);
            int next = i + 1 < s.length() ?
                    (s.charAt(i + 1) == 'I' ? 1 : (s.charAt(i + 1) == 'V' ? 5 : 10)) : 0;
            total += cur < next ? -cur : cur;
        }
        return total;
    }

    /** 从片名提取季数：0=无标记（兼容资源站各种命名：第2季/第二部/Season 2/S2/片名2/片名II） */
    private static int extractSeason(String name) {
        if (name == null) return 0;
        Matcher m = Pattern.compile("第\\s*(\\d+)\\s*[季部]").matcher(name);
        if (m.find()) return Integer.parseInt(m.group(1));
        m = Pattern.compile("第\\s*([一二三四五六七八九十]+)\\s*[季部]").matcher(name);
        if (m.find()) return cnToInt(m.group(1));
        m = Pattern.compile("(\\d{1,2})\\s*季").matcher(name);
        if (m.find()) return Integer.parseInt(m.group(1));
        m = Pattern.compile("(?i)season\\s*(\\d+)").matcher(name);
        if (m.find()) return Integer.parseInt(m.group(1));
        m = Pattern.compile("(?i)s(\\d{1,2})\\s*e\\d{1,3}").matcher(name);
        if (m.find()) return Integer.parseInt(m.group(1));
        // 单独的 S2 标记（前后不能是字母数字，避免误伤普通单词）
        m = Pattern.compile("(?i)(?:^|[^a-z0-9])s(\\d{1,2})(?:$|[^a-z0-9])").matcher(name);
        if (m.find()) return Integer.parseInt(m.group(1));
        // 尾部裸数字：「某某剧2」（仅 1-2 位且前面是汉字/字母，排除年份和空格分隔的英文剧名）
        m = Pattern.compile("[\\u4e00-\\u9fa5A-Za-z](\\d{1,2})$").matcher(name.trim());
        if (m.find()) return Integer.parseInt(m.group(1));
        // 尾部罗马数字：「某某剧II」「Show II」（前面须是汉字或空白，避免误判 FXX 这类词尾）
        m = Pattern.compile("(?:[\\u4e00-\\u9fa5]|\\s)([IVX]{1,4})$").matcher(name.trim());
        if (m.find()) return romanToInt(m.group(1));
        return 0;
    }

    private static boolean isDubName(String n) {
        return has(n, "国语") || has(n, "普通话") || has(n, "国配") || has(n, "中文配音") || has(n, "配音版");
    }

    private static boolean isYueName(String n) {
        return has(n, "粤语") || has(n, "粵語");
    }

    /* ---------------- 资源站搜索 ---------------- */

    private static List<JSONObject> search(String apiUrl, String kw) {
        if (apiUrl == null || apiUrl.trim().isEmpty() || kw == null || kw.isEmpty()) return new ArrayList<>();
        String key = "s:" + apiUrl + "|" + kw;
        @SuppressWarnings("unchecked")
        List<JSONObject> cached = (List<JSONObject>) cacheGet(key);
        if (cached != null) return cached;

        String sep = apiUrl.contains("?") ? "&" : "?";
        String url = apiUrl.trim() + sep + "ac=videolist&wd=" + Http.enc(kw);

        List<JSONObject> list = new ArrayList<>();
        for (int tryN = 0; tryN < 2; tryN++) {   // 接口偶发限流：空结果重试一次
            JSONObject data = Http.getJson(url);
            JSONArray arr = data == null ? null : data.optJSONArray("list");
            if (arr != null && arr.length() > 0) {
                for (int i = 0; i < arr.length(); i++) {
                    JSONObject v = arr.optJSONObject(i);
                    if (v != null && !v.optString("vod_name", "").isEmpty()) list.add(v);
                }
                break;
            }
            if (tryN == 0) {
                try { Thread.sleep(400); } catch (InterruptedException ignored) { }
            }
        }
        cacheSet(key, list, list.isEmpty() ? 60 : 900);
        return list;
    }

    /** vod_play_url → 播放条目列表（选资源最多的一组） */
    private static List<Models.ResItem> parsePlayUrl(String str) {
        List<Models.ResItem> best = new ArrayList<>();
        if (str == null || str.trim().isEmpty()) return best;
        String[] groups = str.split("\\$\\$\\$");
        for (String g : groups) {
            if (g == null || g.trim().isEmpty()) continue;
            List<Models.ResItem> cur = new ArrayList<>();
            for (String ep : g.split("#")) {
                if (ep == null || ep.trim().isEmpty()) continue;
                int pos = ep.indexOf('$');
                String name, url;
                if (pos < 0) { name = ep; url = ""; }
                else { name = ep.substring(0, pos).trim(); url = ep.substring(pos + 1).trim(); }
                if (url.isEmpty() || !url.toLowerCase().startsWith("http")) continue;
                Models.ResItem it = new Models.ResItem();
                it.name = name;
                it.url = url;
                cur.add(it);
            }
            if (cur.size() > best.size()) best = cur;
        }
        return best;
    }

    /* ---------------- 集数匹配 ---------------- */

    private static int epNumber(String name) {
        if (name == null) return 0;
        String t = name.trim();
        Matcher m = Pattern.compile("第\\s*(\\d+)\\s*[集话話期回]").matcher(t);
        if (m.find()) return Integer.parseInt(m.group(1));
        m = Pattern.compile("第\\s*([一二三四五六七八九十]+)\\s*[集话話期回]").matcher(t);
        if (m.find()) return cnToInt(m.group(1));
        m = Pattern.compile("^(\\d+)(?:\\s*[集话話期])?$").matcher(t);
        if (m.matches()) return Integer.parseInt(m.group(1));
        m = Pattern.compile("(?:^|[^\\d])(\\d+)\\s*[集话話期]").matcher(t);
        if (m.find()) return Integer.parseInt(m.group(1));
        m = Pattern.compile("(?i)s\\d{1,2}e(\\d{1,3})").matcher(t);
        if (m.find()) return Integer.parseInt(m.group(1));
        return 0;
    }

    /** 按集数挑选播放地址（合集条目优先精确匹配 S{季}E{集} 格式集名） */
    private static Models.ResItem pickEpisode(List<Models.ResItem> items, int epNo, int season) {
        if (items == null || items.isEmpty()) return null;
        if (epNo > 0) {
            if (season > 0) {
                String pat = "(?i)s0*" + season + "e0*" + epNo + "(?!\\d)";
                for (Models.ResItem it : items) {
                    if (Pattern.compile(pat).matcher(it.name).find()) return it;
                }
            }
            for (Models.ResItem it : items) {
                if (epNumber(it.name) == epNo) return it;
            }
            for (Models.ResItem it : items) {   // 松散匹配
                if (it.name.contains(String.valueOf(epNo))) return it;
            }
        }
        int idx = epNo > 0 ? Math.min(epNo - 1, items.size() - 1) : 0;
        return items.get(idx);
    }

    private static Models.ResItem pickMovieTrack(List<Models.ResItem> items, boolean dub) {
        if (items == null || items.isEmpty()) return null;
        Models.ResItem dubIt = null, origIt = null;
        for (Models.ResItem it : items) {
            if (dubIt == null && isDubName(it.name)) dubIt = it;
            if (origIt == null && !isDubName(it.name)) origIt = it;
        }
        if (origIt == null) origIt = items.get(0);
        if (dub) return dubIt != null ? dubIt : origIt;
        return origIt != null ? origIt : dubIt;
    }

    /* ---------------- 候选评分（PHP score_candidate 同逻辑） ---------------- */

    private static int score(String vodName, String vodYear, int itemCount,
                             String base, String baseOrig, int targetYear, int season, boolean isTv) {
        String n = norm(vodName);
        if (n.isEmpty() || base.isEmpty() || itemCount == 0) return 0;

        int sc;
        if (n.equals(base) || (!baseOrig.isEmpty() && n.equals(baseOrig))) sc = 100;
        else if (!baseOrig.isEmpty() && starts(n, baseOrig) && baseOrig.length() >= 2) sc = 85;
        else if (starts(n, base)) sc = 80;
        else if (starts(base, n) && n.length() >= 2) sc = 75;
        else if (n.contains(base)) sc = 60;
        else if (!baseOrig.isEmpty() && n.contains(baseOrig) && baseOrig.length() >= 2) sc = 55;
        else if (base.contains(n) && n.length() >= 2) sc = 45;
        else return 0;

        if (isYueName(vodName)) sc -= 45;
        if (has(vodName, "预告")) sc -= 70;
        if (has(vodName, "特辑") || has(vodName, "花絮") || has(vodName, "幕后") || has(vodName, "解说")) sc -= 55;

        int vy = 0;
        try { vy = Integer.parseInt(vodYear == null ? "" : vodYear.trim()); } catch (Exception ignored) { }

        if (isTv) {
            int sMark = extractSeason(vodName);
            if (season > 1) {
                if (sMark == season) sc += 50;
                else if (sMark == 0) sc -= 35;
                else sc -= 55;
                if (targetYear > 0 && vy > 0) {
                    int d = Math.abs(vy - targetYear);
                    if (d == 0) sc += 30; else if (d == 1) sc += 16;
                    else if (d <= 2) sc += 5; else if (d > 5) sc -= 20;
                }
            } else {
                if (sMark == 1) sc += 40;       // 明确标记第1季的条目优先于无标记条目
                else if (sMark == 0) sc += 25;
                else sc -= 60;
                if (targetYear > 0 && vy > 0 && Math.abs(vy - targetYear) > 4) sc -= 15;
            }
        } else {
            if (targetYear > 0 && vy > 0) {
                int d = Math.abs(vy - targetYear);
                if (d == 0) sc += 30; else if (d == 1) sc += 15; else if (d > 3) sc -= 20;
            }
        }
        return sc;
    }

    /* ---------------- 单源解析 ---------------- */

    @SuppressWarnings("unchecked")
    public static Res resolveMedia(String apiUrl, boolean isTv, String zhTitle, String origTitle,
                                   int year, int season) {
        season = Math.max(1, season);
        String key = "r:" + apiUrl + "|" + (isTv ? "tv" : "mv") + "|" + zhTitle + "|" + origTitle + "|" + year + "|" + season;
        Object cached = cacheGet(key);
        if (cached != null) return (Res) cached;

        Res notFound = new Res();
        notFound.found = false;
        notFound.msg = "播放源中未找到该片资源";

        // 多关键词搜索（中文名 + 原名；第2季起追加季关键词，覆盖资源站按季分条收录）
        List<String> kws = new ArrayList<>();
        if (zhTitle != null && !zhTitle.isEmpty()) kws.add(zhTitle);
        if (origTitle != null && !origTitle.isEmpty() && !norm(origTitle).equals(norm(zhTitle))) kws.add(origTitle);
        if (isTv && season > 1) {
            if (zhTitle != null && !zhTitle.isEmpty()) {
                kws.add(zhTitle + "第" + season + "季");
                kws.add(zhTitle + season);
            }
            if (origTitle != null && !origTitle.isEmpty()) {
                kws.add(origTitle + " season " + season);
                kws.add(origTitle + " S" + season);
            }
        }

        Map<String, JSONObject> uniq = new LinkedHashMap<>();  // vod_id 去重（保持搜索命中顺序）
        for (String kw : kws) {
            for (JSONObject v : search(apiUrl, kw)) {
                List<Models.ResItem> items = parsePlayUrl(v.optString("vod_play_url", ""));
                if (!items.isEmpty()) {
                    v.opt("_items");
                    try {
                        v.put("_items", items);
                    } catch (Exception ignored) {
                    }
                    uniq.put(v.optString("vod_id", String.valueOf(v.hashCode())), v);
                }
            }
        }
        if (uniq.isEmpty()) {
            cacheSet(key, notFound, 300);
            return notFound;
        }

        String base = norm(zhTitle);
        String baseOrig = norm(origTitle);

        JSONObject best = null; int bestScore = -9999;
        JSONObject bestDub = null; int bestDubScore = -9999;
        for (JSONObject v : uniq.values()) {
            String name = v.optString("vod_name", "");
            List<Models.ResItem> items = (List<Models.ResItem>) v.opt("_items");
            if (items == null) continue;
            int sc = score(name, v.optString("vod_year", ""), items.size(), base, baseOrig, year, season, isTv);
            if (sc <= 0) continue;
            if (isDubName(name)) {
                if (sc > bestDubScore) { bestDubScore = sc; bestDub = v; }
            } else {
                if (sc > bestScore) { bestScore = sc; best = v; }
            }
        }
        if (best == null && bestDub != null) { best = bestDub; bestDub = null; }
        if (best == null) {
            cacheSet(key, notFound, 300);
            return notFound;
        }

        Res res = new Res();
        res.found = true;
        res.orig = toGroup(best);
        res.dub = bestDub != null ? toGroup(bestDub) : null;
        cacheSet(key, res, 600);
        return res;
    }

    @SuppressWarnings("unchecked")
    private static Models.Group toGroup(JSONObject v) {
        Models.Group g = new Models.Group();
        g.name = v.optString("vod_name", "");
        Object o = v.opt("_items");
        if (o instanceof List) g.items = (List<Models.ResItem>) o;
        return g;
    }

    /* ---------------- 多源降级 + 最终直链 ---------------- */

    /**
     * 多源解析最终播放直链（对齐网站 resolve_play_url）
     * @param prefSourceUrl 指定优先源 URL，空串 = 自动（默认源优先）
     * @param track         orig | dub
     */
    public static PlayResult resolvePlay(List<Models.Source> sources, String prefSourceUrl,
                                         boolean isTv, String zhTitle, String origTitle,
                                         int year, int season, int ep, String track) {
        boolean dub = "dub".equals(track);
        PlayResult r = new PlayResult();

        if (sources == null || sources.isEmpty()) {
            r.msg = "尚未配置播放源，请在设置中添加";
            return r;
        }

        // 指定源最前，其余按默认源优先顺序（自动降级）
        List<Models.Source> ordered = new ArrayList<>();
        if (prefSourceUrl != null && !prefSourceUrl.isEmpty()) {
            for (Models.Source s : sources) {
                if (s.url.equals(prefSourceUrl)) ordered.add(s);
            }
        }
        for (Models.Source s : sources) {
            if (prefSourceUrl == null || prefSourceUrl.isEmpty() || !s.url.equals(prefSourceUrl)) ordered.add(s);
        }

        Res res = null;
        Models.Source used = null;
        for (Models.Source s : ordered) {
            Res one = resolveMedia(s.url, isTv, zhTitle, origTitle, year, season);
            if (one.found) { res = one; used = s; break; }
            if (res == null) res = one;
        }
        if (res == null) res = new Res();
        if (!res.found) {
            r.msg = res.msg != null && !res.msg.isEmpty() ? res.msg : "播放源中未找到该片资源";
            r.sourceName = used != null ? used.name : "";
            return r;
        }

        r.sourceName = used != null ? used.name : "";
        r.switched = prefSourceUrl != null && !prefSourceUrl.isEmpty()
                && (used == null || !used.url.equals(prefSourceUrl));

        Models.Group g = (dub && res.dub != null) ? res.dub : res.orig;
        if (dub && res.dub == null) { g = res.orig; r.fallback = true; }

        if (g == null || g.items.isEmpty()) {
            r.msg = "未找到可播放的资源";
            return r;
        }
        r.entry = g.name;

        Models.ResItem item = isTv ? pickEpisode(g.items, ep, season) : pickMovieTrack(g.items, dub);
        if (item == null) {
            r.msg = "未找到对应集数资源";
            return r;
        }
        r.ok = true;
        r.url = item.url;
        return r;
    }
}
