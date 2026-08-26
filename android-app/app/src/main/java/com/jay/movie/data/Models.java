package com.jay.movie.data;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;

/** 数据模型（影片 / 剧集 / 播放源 / 收藏 / 历史） */
public class Models {

    /** 影片条目（首页/搜索/收藏列表用） */
    public static class Media {
        public int id;
        public String type = "movie";   // movie | tv
        public String title = "";
        public String origTitle = "";
        public String poster = "";      // 完整 URL
        public String backdrop = "";    // 完整 URL
        public String year = "";
        public double rating;
        public int season;              // >0 = 该条目代表某一季（点击直接定位）

        public JSONObject toFavJson() {
            JSONObject o = new JSONObject();
            try {
                o.put("type", type).put("id", id).put("title", title)
                        .put("poster", poster).put("year", year).put("ts", System.currentTimeMillis());
            } catch (Exception ignored) {
            }
            return o;
        }
    }

    /** TMDB 某一集 */
    public static class Episode {
        public int num;
        public String name = "";
        public String still = "";
        public String date = "";
    }

    /** 播放源（对应网站后台 play_sources 表，本地化为 JSON 存储） */
    public static class Source {
        public String name = "";
        public String url = "";
        public boolean def;             // 是否默认源

        public JSONObject json() {
            JSONObject o = new JSONObject();
            try {
                o.put("name", name).put("url", url).put("def", def);
            } catch (Exception ignored) {
            }
            return o;
        }

        public static Source from(JSONObject o) {
            Source s = new Source();
            s.name = o.optString("name", "");
            s.url = o.optString("url", "");
            s.def = o.optBoolean("def", false);
            return s;
        }
    }

    /** 观看历史条目 */
    public static class Hist {
        public String type = "movie";
        public int id;
        public String title = "";
        public String poster = "";
        public String origTitle = "";
        public String year = "";
        public int season = 1;
        public int episode;             // 电影为 0
        public long ts;

        public static List<Hist> list(JSONArray a) {
            List<Hist> l = new ArrayList<>();
            for (int i = 0; i < a.length(); i++) {
                JSONObject o = a.optJSONObject(i);
                if (o == null) continue;
                Hist h = new Hist();
                h.type = o.optString("type", "movie");
                h.id = o.optInt("id");
                h.title = o.optString("title", "");
                h.poster = o.optString("poster", "");
                h.origTitle = o.optString("origTitle", "");
                h.year = o.optString("year", "");
                h.season = o.optInt("season", 1);
                h.episode = o.optInt("episode", 0);
                h.ts = o.optLong("ts", 0);
                l.add(h);
            }
            return l;
        }
    }

    /** 播放条目（资源站 vod_play_url 拆分后） */
    public static class ResItem {
        public String name = "";
        public String url = "";
    }

    /** 资源站匹配到的影片分组（原版/国语） */
    public static class Group {
        public String name = "";
        public List<ResItem> items = new ArrayList<>();
    }
}
