package com.jay.movie.api;

import com.jay.movie.App;
import com.jay.movie.data.Models;
import com.jay.movie.data.Prefs;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** TMDB 接口封装（与网站 includes/tmdb.php 逻辑一致） */
public class Tmdb {

    public static String img(String path, String size) {
        if (path == null || path.isEmpty()) return "";
        return "https://image.tmdb.org/t/p/" + size + path;
    }

    private static JSONObject req(String path, String params) {
        String key = Prefs.getKey(App.ctx());
        if (key.isEmpty()) return null;
        String base = Prefs.getApiBase(App.ctx());
        String url = base + path + "?api_key=" + Http.enc(key) + "&language=zh-CN"
                + (params == null || params.isEmpty() ? "" : "&" + params);
        JSONObject o = Http.getJson(url);
        if (o != null && o.has("status_code")) return null; // 错误响应（无效 Key 等）
        return o;
    }

    /* ---------------- 分类列表 ---------------- */

    public static List<Models.Media> category(String cat, int page) {
        JSONObject o;
        switch (cat) {
            case "tv":      o = req("/tv/popular", "page=" + page); break;
            case "anime":   o = req("/discover/tv", "with_genres=16&sort_by=popularity.desc&page=" + page); break;
            case "variety": o = req("/discover/tv", "with_genres=10764&sort_by=popularity.desc&page=" + page); break;
            default:        o = req("/movie/popular", "page=" + page); break;
        }
        if (o == null) return null;
        String type = cat.equals("movie") ? "movie" : "tv";
        return parseList(o.optJSONArray("results"), type);
    }

    /* ---------------- 搜索 ---------------- */

    public static List<Models.Media> search(String query, int page) {
        JSONObject o = req("/search/multi", "query=" + Http.enc(query) + "&page=" + page + "&include_adult=false");
        if (o == null) return null;
        JSONArray results = o.optJSONArray("results");
        List<Models.Media> l = new ArrayList<>();
        if (results == null) return l;
        for (int i = 0; i < results.length(); i++) {
            JSONObject r = results.optJSONObject(i);
            if (r == null) continue;
            String mt = r.optString("media_type", "");
            if (!mt.equals("movie") && !mt.equals("tv")) continue;
            Models.Media m = parseItem(r, mt);
            if (m.title != null && !m.title.isEmpty()) l.add(m);
        }
        return l;
    }

    /* ---------------- 季级搜索（剧集按季展开成独立条目） ---------------- */

    /** 输入联想：取搜索前 8 条结果的标题（不拉详情，轻量） */
    public static List<Models.Media> suggest(String query) {
        List<Models.Media> base = search(query, 1);
        if (base == null) return null;
        if (base.size() > 8) base = new ArrayList<>(base.subList(0, 8));
        return base;
    }

    /** 季数关键词：第2季 / 第二季 / 第2部 / 2季 / S2 */
    private static final Pattern P_NUM_SEASON = Pattern.compile("(\\d{1,2})\\s*季");
    private static final Pattern P_CN_SEASON = Pattern.compile("第\\s*([0-9一二三四五六七八九十两]+)\\s*[季部]");
    private static final Pattern P_S_SEASON = Pattern.compile("(?i)(?:^|\\s)[sS](\\d{1,2})(?:\\s|$)");

    /**
     * 搜索并展开季：
     * - 关键词含季数（如「斗罗大陆 第3季」）时，剥离季数后搜索，结果只保留该季条目
     * - 普通搜索时，多季剧集会在本体后插入每一季的独立条目（点击直接定位该季）
     */
    public static List<Models.Media> searchWithSeasons(String query, int page) {
        String raw = query == null ? "" : query.trim();
        int qSeason = 0;
        String kw = raw;

        Matcher m = P_NUM_SEASON.matcher(kw);
        if (m.find()) {
            qSeason = parseInt(m.group(1));
            kw = m.replaceAll(" ").trim();
        } else {
            m = P_CN_SEASON.matcher(kw);
            if (m.find()) {
                qSeason = cnNum(m.group(1));
                kw = m.replaceAll(" ").trim();
            } else {
                m = P_S_SEASON.matcher(kw);
                if (m.find()) {
                    qSeason = parseInt(m.group(1));
                    kw = (kw.substring(0, m.start()) + " " + kw.substring(m.end())).trim();
                }
            }
        }
        if (qSeason < 1 || qSeason > 60) qSeason = 0;
        if (kw.isEmpty()) kw = raw;

        List<Models.Media> base = search(kw, page);
        if (base == null) return null;
        if (base.isEmpty() && !kw.equals(raw)) {
            // 清洗后的词搜不到，回退原词
            base = search(raw, page);
            if (base == null) return null;
        }
        return expandSeasons(base, qSeason);
    }

    /** 把结果中的剧集展开出各季条目 */
    private static List<Models.Media> expandSeasons(List<Models.Media> base, int qSeason) {
        List<Models.Media> out = new ArrayList<>();

        // 统计 tv 条目，需要拉详情的并行拉取
        List<Models.Media> tvs = new ArrayList<>();
        for (Models.Media m : base) if (m.type.equals("tv")) tvs.add(m);

        // 指定季时全部尝试匹配；普通搜索只展开前 4 部（控制请求量）
        int expand = qSeason > 0 ? tvs.size() : Math.min(4, tvs.size());
        JSONObject[] details = new JSONObject[tvs.size()];
        if (expand > 0) {
            ExecutorService pool = Executors.newFixedThreadPool(4);
            CountDownLatch latch = new CountDownLatch(expand);
            for (int i = 0; i < expand; i++) {
                final int idx = i;
                final int tvId = tvs.get(idx).id;
                pool.execute(() -> {
                    try {
                        details[idx] = req("/tv/" + tvId, "");
                    } catch (Exception ignored) {
                    }
                    latch.countDown();
                });
            }
            try {
                latch.await(12, TimeUnit.SECONDS);
            } catch (Exception ignored) {
            }
            pool.shutdown();
        }

        for (Models.Media m : base) {
            if (!m.type.equals("tv")) {
                out.add(m);
                continue;
            }
            int idx = tvs.indexOf(m);
            List<JSONObject> ss = idx >= 0 && idx < expand ? seasonsOf(details[idx]) : new ArrayList<>();

            if (qSeason > 0) {
                // 只要指定季
                for (JSONObject s : ss) {
                    if (s.optInt("season_number", 0) == qSeason) {
                        out.add(seasonItem(m, s));
                        break;
                    }
                }
                continue;
            }
            out.add(m);
            if (ss.size() > 1) {
                for (JSONObject s : ss) {
                    if (s.optInt("season_number", 0) < 1) continue;
                    out.add(seasonItem(m, s));
                }
            }
        }
        return out;
    }

    /** 详情里的季列表（过滤特别篇） */
    private static List<JSONObject> seasonsOf(JSONObject detail) {
        List<JSONObject> l = new ArrayList<>();
        if (detail == null) return l;
        JSONArray ss = detail.optJSONArray("seasons");
        if (ss == null) return l;
        for (int i = 0; i < ss.length(); i++) {
            JSONObject s = ss.optJSONObject(i);
            if (s != null && s.optInt("season_number", 0) >= 1) l.add(s);
        }
        return l;
    }

    /** 由 tv 本体 + 季对象生成独立条目 */
    private static Models.Media seasonItem(Models.Media tv, JSONObject s) {
        Models.Media m = new Models.Media();
        m.type = "tv";
        m.id = tv.id;
        int no = s.optInt("season_number", 1);
        m.season = no;
        String base = tv.title == null || tv.title.isEmpty() ? tv.origTitle : tv.title;
        m.title = base + " 第" + no + "季";
        m.origTitle = tv.origTitle;
        String pp = s.optString("poster_path", "");
        m.poster = pp == null || pp.isEmpty() ? tv.poster : img(pp, "w185");
        m.backdrop = tv.backdrop;
        String ad = s.optString("air_date", "");
        m.year = ad != null && ad.length() >= 4 ? ad.substring(0, 4) : tv.year;
        m.rating = s.optDouble("vote_average", 0) > 0 ? s.optDouble("vote_average", 0) : tv.rating;
        return m;
    }

    private static int parseInt(String s) {
        try {
            return Integer.parseInt(s.trim());
        } catch (Exception e) {
            return 0;
        }
    }

    /** 中文数字（支持到九十九） */
    private static int cnNum(String s) {
        if (s == null) return 0;
        s = s.trim();
        if (s.matches("[0-9]+")) return parseInt(s);
        int total = 0, cur = 0;
        for (char ch : s.toCharArray()) {
            if (ch == '两') {
                cur = 2;
                continue;
            }
            int v = "零一二三四五六七八九".indexOf(ch);
            if (v >= 0) cur = v;
            else if (ch == '十') {
                total += (cur == 0 ? 1 : cur) * 10;
                cur = 0;
            }
        }
        return total + cur;
    }

    /* ---------------- 详情 / 季 ---------------- */

    public static JSONObject detail(String type, int id) {
        if (!type.equals("movie") && !type.equals("tv")) return null;
        return req("/" + type + "/" + id, "append_to_response=credits");
    }

    public static List<Models.Episode> season(int tvId, int seasonNo) {
        JSONObject o = req("/tv/" + tvId + "/season/" + seasonNo, "");
        List<Models.Episode> l = new ArrayList<>();
        if (o == null) return l;
        JSONArray eps = o.optJSONArray("episodes");
        if (eps == null) return l;
        for (int i = 0; i < eps.length(); i++) {
            JSONObject e = eps.optJSONObject(i);
            if (e == null) continue;
            Models.Episode ep = new Models.Episode();
            ep.num = e.optInt("episode_number", i + 1);
            ep.name = e.optString("name", "");
            ep.date = e.optString("air_date", "");
            ep.still = img(e.optString("still_path", ""), "w300");
            l.add(ep);
        }
        return l;
    }

    /** 是否海外影视（原语言非中文 → 显示音轨选择） */
    public static boolean isOverseas(JSONObject detail) {
        if (detail == null) return false;
        String lang = detail.optString("original_language", "");
        return !lang.isEmpty() && !lang.equals("zh");
    }

    /* ---------------- 解析 ---------------- */

    private static List<Models.Media> parseList(JSONArray a, String type) {
        List<Models.Media> l = new ArrayList<>();
        if (a == null) return l;
        for (int i = 0; i < a.length(); i++) {
            JSONObject r = a.optJSONObject(i);
            if (r == null) continue;
            l.add(parseItem(r, type));
        }
        return l;
    }

    private static Models.Media parseItem(JSONObject r, String type) {
        Models.Media m = new Models.Media();
        m.type = type;
        m.id = r.optInt("id");
        if (type.equals("movie")) {
            m.title = r.optString("title", "");
            m.origTitle = r.optString("original_title", "");
            m.year = sub(r.optString("release_date", ""));
        } else {
            m.title = r.optString("name", "");
            m.origTitle = r.optString("original_name", "");
            m.year = sub(r.optString("first_air_date", ""));
        }
        m.poster = img(r.optString("poster_path", ""), "w185");
        m.backdrop = img(r.optString("backdrop_path", ""), "w780");
        m.rating = r.optDouble("vote_average", 0);
        return m;
    }

    private static String sub(String date) {
        return date != null && date.length() >= 4 ? date.substring(0, 4) : "";
    }
}
