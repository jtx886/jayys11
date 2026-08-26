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

    public static void setKey(Context c, String k) {
        sp(c).edit().putString("tmdb_key", k == null ? "" : k.trim()).apply();
    }

    /** TMDB 接口地址（默认官方，可换镜像） */
    public static String getApiBase(Context c) {
        String b = sp(c).getString("tmdb_base", "").trim();
        return b.isEmpty() ? "https://api.tmdb.org/3" : b;
    }

    public static void setApiBase(Context c, String b) {
        sp(c).edit().putString("tmdb_base", b == null ? "" : b.trim()).apply();
    }

    /* ---------------- 播放源 ---------------- */

    private static final String SEED_NAME = "默认资源站";
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
        // 按默认源优先排序
        list.sort((a, b) -> (b.def ? 1 : 0) - (a.def ? 1 : 0));
        return list;
    }

    public static void saveSources(Context c, List<Models.Source> list) {
        JSONArray a = new JSONArray();
        for (Models.Source s : list) a.put(s.json());
        sp(c).edit().putString("sources_json", a.toString()).apply();
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

    /** 记录/更新观看：同一部剧同一季只保留一条（更新集数与时间），上限 100 条 */
    public static void addHist(Context c, String type, int id, String title, String poster,
                               String origTitle, String year, int season, int episode) {
        List<Models.Hist> l = Models.Hist.list(loadHistArr(c));
        for (int i = 0; i < l.size(); i++) {
            Models.Hist h = l.get(i);
            if (h.id == id && h.type.equals(type)
                    && (type.equals("movie") || h.season == season)) {
                l.remove(i);
                break;
            }
        }
        Models.Hist h = new Models.Hist();
        h.type = type;
        h.id = id;
        h.title = title;
        h.poster = poster;
        h.origTitle = origTitle;
        h.year = year;
        h.season = Math.max(1, season);
        h.episode = episode;
        h.ts = System.currentTimeMillis();
        l.add(0, h);
        while (l.size() > 100) l.remove(l.size() - 1);
        saveHistArr(c, l);
    }

    public static List<Models.Hist> getHist(Context c) {
        return Models.Hist.list(loadHistArr(c));
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

    private static void saveHistArr(Context c, List<Models.Hist> l) {
        JSONArray a = new JSONArray();
        for (Models.Hist h : l) {
            JSONObject o = new JSONObject();
            try {
                o.put("type", h.type).put("id", h.id).put("title", h.title)
                        .put("poster", h.poster).put("origTitle", h.origTitle)
                        .put("year", h.year).put("season", h.season)
                        .put("episode", h.episode).put("ts", h.ts);
            } catch (Exception ignored) {
            }
            a.put(o);
        }
        sp(c).edit().putString("hist_json", a.toString()).apply();
    }

    /* ---------------- 时间格式化 ---------------- */

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
