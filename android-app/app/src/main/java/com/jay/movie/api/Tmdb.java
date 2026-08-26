package com.jay.movie.api;

import com.jay.movie.App;
import com.jay.movie.data.Models;
import com.jay.movie.data.Prefs;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;

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
        m.poster = img(r.optString("poster_path", ""), "w342");
        m.backdrop = img(r.optString("backdrop_path", ""), "w780");
        m.rating = r.optDouble("vote_average", 0);
        return m;
    }

    private static String sub(String date) {
        return date != null && date.length() >= 4 ? date.substring(0, 4) : "";
    }
}
