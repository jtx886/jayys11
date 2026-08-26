package com.jay.movie.data;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;

/**
 * 本地存储（替代网站 MySQL）
 * 全部使用 SharedPreferences + JSON，手机本地保存，卸载即清除，无需任何数据库
 */
public class Prefs {

    private static SharedPreferences sp(Context c) {
        return c.getSharedPreferences("jay_app", Context.MODE_PRIVATE);
    }

    /* ---------------- TMDB 配置 ---------------- */

    /** 内置默认 Key（用户未自定义时使用，开箱即用） */
    private static final String SEED_KEY = "cb44223c5dee5676ed3a839f42ed27e3";

    public static String getKey(Context c) {
        String k = sp(c).getString("tmdb_key", "").trim();
        return k.isEmpty() ? SEED_KEY : k;
    }

    /** TMDB 接口地址（默认官方） */
    public static String getApiBase(Context c) {
        String b = sp(c).getString("tmdb_base", "").trim();
        return b.isEmpty() ? "https://api.tmdb.org/3" : b;
    }

    /* ---------------- 播放源 ---------------- */

    private static final String SEED_NAME = "杰同学";
    private static final String SEED_URL = "https://api.yyzy-tv.vip/inc/apijson.php";

    public static List<Models.Source> getSources(Context c) {
        List<Models.Source> list = new ArrayList<>();
        try {
            JSONArray a = new JSONArray(sp(c).getString("sources_json", "[]"));
            for (int i = 0; i < a.length(); i++) {
                JSONObject o = a.optJSONObject(i);
                if (o != null) list.add(Models.Source.from(o));
            }
        } catch (Exception ignored) {
        }
        if (list.isEmpty()) {
            Models.Source s = new Models.Source();
            s.name = SEED_NAME;
            s.url = SEED_URL;
            s.def = true;
            list.add(s);
            saveSources(c, list);
        }
        // 迁移：旧版默认源改名
        boolean renamed = false;
        for (Models.Source s : list) {
            if ("默认资源站".equals(s.name)) {
                s.name = SEED_NAME;
                renamed = true;
            }
        }
        if (renamed) saveSources(c, list);
        // 按默认源优先排序
        list.sort((a, b) -> (b.def ? 1 : 0) - (a.def ? 1 : 0));
        return list;
    }

    public static void saveSources(Context c, List<Models.Source> list) {
        JSONArray a = new JSONArray();
        for (Models.Source s : list) a.put(s.json());
        sp(c).edit().putString("sources_json", a.toString()).apply();
    }

    /* ---------------- 影视仓（TVBox）配置导入 ---------------- */

    /** 导入结果：added=新增 dup=已存在 skipped=不支持类型（xml/spider 等） */
    public static class ImportResult {
        public int added, dup, skipped;
    }

    /** 解析影视仓/TVBox 配置，把 sites 中 type=1（苹果CMS JSON 采集）的站点导入播放源列表。
     *  兼容三种输入：{"sites":[…]} 标准配置 / 裸数组 [{name,api|url}] 自定义 JSON。
     *  返回 null = 内容不是有效配置 */
    public static ImportResult importTvBoxSites(Context c, String cfg) {
        if (c == null || cfg == null) return null;
        String s = cfg.trim();
        // 防御性裁剪出 JSON 主体（部分接口返回带杂字符）
        int a = s.indexOf('{'), b = s.lastIndexOf('}');
        if (s.startsWith("[")) { a = 0; b = s.length(); }
        if (a < 0 || b <= a) return null;
        s = s.substring(a, b);

        JSONArray sites;
        try {
            if (s.startsWith("[")) {
                sites = new JSONArray(s);
            } else {
                JSONObject o = new JSONObject(s);
                sites = o.optJSONArray("sites");
            }
        } catch (Exception e) {
            return null;
        }
        if (sites == null || sites.length() == 0) return null;

        ImportResult r = new ImportResult();
        List<Models.Source> list = getSources(c);
        for (int i = 0; i < sites.length(); i++) {
            JSONObject st = sites.optJSONObject(i);
            if (st == null) { r.skipped++; continue; }
            String name = st.optString("name", "").trim();
            String api = st.optString("api", "").trim();
            if (api.isEmpty()) api = st.optString("url", "").trim();   // 兼容自定义 JSON 用 url 字段
            int type = st.optInt("type", 1);   // 无 type 字段的自定义 JSON 默认按 CMS 处理
            if (name.isEmpty() || api.isEmpty() || type != 1
                    || !api.toLowerCase().startsWith("http")) {
                r.skipped++;
                continue;
            }
            boolean exists = false;
            for (Models.Source x : list) {
                if (x.url.equals(api)) { exists = true; break; }
            }
            if (exists) { r.dup++; continue; }
            Models.Source so = new Models.Source();
            so.name = name;
            so.url = api;
            list.add(so);
            r.added++;
        }
        if (r.added > 0) {
            // 确保仍有一个默认源
            boolean hasDef = false;
            for (Models.Source x : list) if (x.def) hasDef = true;
            if (!hasDef && !list.isEmpty()) list.get(0).def = true;
            saveSources(c, list);
        }
        return r;
    }

    /* ---------------- 收藏 ---------------- */

    public static List<Models.Media> getFavs(Context c) {
        List<Models.Media> l = new ArrayList<>();
        try {
            JSONArray a = new JSONArray(sp(c).getString("favs_json", "[]"));
            for (int i = 0; i < a.length(); i++) {
                JSONObject o = a.optJSONObject(i);
                if (o == null) continue;
                Models.Media m = new Models.Media();
                m.type = o.optString("type", "movie");
                m.id = o.optInt("id");
                m.title = o.optString("title", "");
                m.poster = o.optString("poster", "");
                m.year = o.optString("year", "");
                l.add(m);
            }
        } catch (Exception ignored) {
        }
        return l;
    }

    public static boolean hasFav(Context c, String type, int id) {
        for (Models.Media m : getFavs(c)) {
            if (m.id == id && m.type.equals(type)) return true;
        }
        return false;
    }

    /** 返回 true=已收藏，false=已取消 */
    public static boolean toggleFav(Context c, String type, int id, String title, String poster, String year) {
        List<Models.Media> l = getFavs(c);
        for (int i = 0; i < l.size(); i++) {
            Models.Media m = l.get(i);
            if (m.id == id && m.type.equals(type)) {
                l.remove(i);
                saveFavs(c, l);
                return false;
            }
        }
        Models.Media m = new Models.Media();
        m.type = type;
        m.id = id;
        m.title = title;
        m.poster = poster;
        m.year = year;
        l.add(0, m);
        saveFavs(c, l);
        return true;
    }

    private static void saveFavs(Context c, List<Models.Media> l) {
        JSONArray a = new JSONArray();
        for (Models.Media m : l) a.put(m.toFavJson());
        sp(c).edit().putString("favs_json", a.toString()).apply();
    }

    /* ---------------- 观看历史 ---------------- */

    /** 记录/更新观看：同一部剧同一季只保留一条（更新集数/进度/时间），上限 100 条。
     *  poster 为空时沿用旧条目海报（播放页没有海报时避免覆盖成空） */
    public static void addHist(Context c, String type, int id, String title, String poster,
                               String origTitle, String year, int season, int episode,
                               long pos, long dur, String track) {
        List<Models.Hist> l = Models.Hist.list(loadHistArr(c));
        Models.Hist old = null;
        for (int i = 0; i < l.size(); i++) {
            Models.Hist h = l.get(i);
            if (h.id == id && h.type.equals(type)
                    && (type.equals("movie") || h.season == season)) {
                old = h;
                l.remove(i);
                break;
            }
        }
        Models.Hist h = new Models.Hist();
        h.type = type;
        h.id = id;
        h.title = title;
        // 海报为空沿用旧海报
        h.poster = poster == null || poster.isEmpty() ? (old == null ? "" : old.poster) : poster;
        h.origTitle = origTitle;
        h.year = year;
        h.season = Math.max(1, season);
        h.episode = episode;
        h.pos = pos;
        h.dur = dur;
        h.track = track == null || track.isEmpty() ? "orig" : track;
        h.ts = System.currentTimeMillis();
        l.add(0, h);
        while (l.size() > 100) l.remove(l.size() - 1);
        saveHistArr(c, l);
    }

    public static List<Models.Hist> getHist(Context c) {
        return Models.Hist.list(loadHistArr(c));
    }

    /** 查某部影视的最新观看记录（不看季，取最近一条） */
    public static Models.Hist findHist(Context c, String type, int id) {
        for (Models.Hist h : Models.Hist.list(loadHistArr(c))) {
            if (h.id == id && h.type.equals(type)) return h;
        }
        return null;
    }

    public static void clearHist(Context c) {
        sp(c).edit().putString("hist_json", "[]").apply();
    }

    private static JSONArray loadHistArr(Context c) {
        try {
            return new JSONArray(sp(c).getString("hist_json", "[]"));
        } catch (Exception e) {
            return new JSONArray();
        }
    }

    /* ---------------- 搜索历史 ---------------- */

    /** 记录一次搜索（去重置顶，上限 10 条） */
    public static void addSearch(Context c, String kw) {
        if (kw == null || kw.trim().isEmpty()) return;
        List<String> l = getSearches(c);
        l.remove(kw);
        l.add(0, kw);
        while (l.size() > 10) l.remove(l.size() - 1);
        JSONArray a = new JSONArray();
        for (String s : l) a.put(s);
        sp(c).edit().putString("searches_json", a.toString()).apply();
    }

    public static List<String> getSearches(Context c) {
        List<String> l = new ArrayList<>();
        try {
            JSONArray a = new JSONArray(sp(c).getString("searches_json", "[]"));
            for (int i = 0; i < a.length(); i++) {
                String s = a.optString(i, "");
                if (!s.isEmpty()) l.add(s);
            }
        } catch (Exception ignored) {
        }
        return l;
    }

    public static void clearSearches(Context c) {
        sp(c).edit().putString("searches_json", "[]").apply();
    }

    private static void saveHistArr(Context c, List<Models.Hist> l) {
        JSONArray a = new JSONArray();
        for (Models.Hist h : l) {
            JSONObject o = new JSONObject();
            try {
                o.put("type", h.type).put("id", h.id).put("title", h.title)
                        .put("poster", h.poster).put("origTitle", h.origTitle)
                        .put("year", h.year).put("season", h.season)
                        .put("episode", h.episode).put("ts", h.ts)
                        .put("pos", h.pos).put("dur", h.dur).put("track", h.track);
            } catch (Exception ignored) {
            }
            a.put(o);
        }
        sp(c).edit().putString("hist_json", a.toString()).apply();
    }

    /* ---------------- 时间格式化 ---------------- */

    /** 秒 → "mm:ss" / "h:mm:ss" */
    public static String fmtPos(long sec) {
        if (sec <= 0) return "";
        long h = sec / 3600, m = (sec % 3600) / 60, s = sec % 60;
        return h > 0 ? String.format(java.util.Locale.CHINA, "%d:%02d:%02d", h, m, s)
                : String.format(java.util.Locale.CHINA, "%02d:%02d", m, s);
    }

    /** 历史条目进度文案：看了一半显示"12:34/45:10"，看完/没看显示空 */
    public static String histProgress(Models.Hist h) {
        if (h == null || h.pos < 10 || h.dur <= 0) return "";
        if (h.pos >= h.dur - 15) return "";       // 已看完
        return fmtPos(h.pos) + "/" + fmtPos(h.dur);
    }

    public static String timeAgo(long ts) {
        if (ts <= 0) return "";
        long d = (System.currentTimeMillis() - ts) / 1000;
        if (d < 60) return "刚刚";
        if (d < 3600) return (d / 60) + "分钟前";
        if (d < 86400) return (d / 3600) + "小时前";
        if (d < 86400 * 30) return (d / 86400) + "天前";
        return new java.text.SimpleDateFormat("yyyy-MM-dd", java.util.Locale.CHINA).format(new java.util.Date(ts));
    }
}
