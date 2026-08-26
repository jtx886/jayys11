package com.jay.movie;

import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import com.jay.movie.api.Tmdb;
import com.jay.movie.data.Models;
import com.jay.movie.data.Prefs;
import com.jay.movie.ui.ImgLoader;
import com.jay.movie.ui.FlowLayout;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;

import android.widget.ImageView;
import android.widget.FrameLayout;

/** 详情页：影片信息 + 选季/播放源/音轨 + 剧集列表 + 收藏 */
public class DetailActivity extends Activity {

    public static void start(Context c, String type, int id) {
        Intent i = new Intent(c, DetailActivity.class);
        i.putExtra("type", type);
        i.putExtra("id", id);
        c.startActivity(i);
    }

    private String type = "movie";
    private int id;

    private JSONObject detail;
    private String title = "", origTitle = "", year = "", poster = "", backdrop = "";
    private double rating;

    private static class SeasonInfo {
        int no;
        String year = "";
    }

    private final List<SeasonInfo> seasons = new ArrayList<>();
    private int seasonNo = 1;
    private String track = "orig";       // orig | dub
    private String prefSrc = "";         // 空 = 自动
    private List<Models.Episode> episodes = new ArrayList<>();
    private List<Models.Source> sources;
    private boolean overseas;

    private ImageView dPoster, dBackdrop;
    private TextView dTitle, dEn, dMeta, dOverview, dCast, dStatus, epLabel, btnFav;
    private Button btnPlay;
    private LinearLayout seasonSection, srcSection, trackSection, epSection;
    private FlowLayout seasonFlow, srcFlow, trackFlow, epFlow;
    private View root;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_detail);

        type = getIntent().getStringExtra("type");
        if (type == null || (!type.equals("movie") && !type.equals("tv"))) type = "movie";
        id = getIntent().getIntExtra("id", 0);
        if (id <= 0) {
            finish();
            return;
        }

        bindViews();
        sources = Prefs.getSources(this);

        dStatus.setVisibility(View.VISIBLE);
        dStatus.setText("加载中…");
        new Thread(this::loadDetail).start();
    }

    private void bindViews() {
        root = findViewById(R.id.dBack).getRootView();
        dPoster = findViewById(R.id.dPoster);
        dBackdrop = findViewById(R.id.dBackdrop);
        dTitle = findViewById(R.id.dTitle);
        dEn = findViewById(R.id.dEn);
        dMeta = findViewById(R.id.dMeta);
        dOverview = findViewById(R.id.dOverview);
        dCast = findViewById(R.id.dCast);
        dStatus = findViewById(R.id.dStatus);
        btnPlay = findViewById(R.id.btnPlay);
        btnFav = findViewById(R.id.btnFav);
        epLabel = findViewById(R.id.epLabel);
        seasonSection = findViewById(R.id.seasonSection);
        srcSection = findViewById(R.id.srcSection);
        trackSection = findViewById(R.id.trackSection);
        epSection = findViewById(R.id.epSection);
        seasonFlow = findViewById(R.id.seasonFlow);
        srcFlow = findViewById(R.id.srcFlow);
        trackFlow = findViewById(R.id.trackFlow);
        epFlow = findViewById(R.id.epFlow);

        findViewById(R.id.dBack).setOnClickListener(v -> finish());
        btnFav.setOnClickListener(v -> {
            boolean faved = Prefs.toggleFav(this, type, id, title, poster, year);
            btnFav.setText(faved ? "已收藏" : "收藏");
            Toast.makeText(this, faved ? "已加入收藏" : "已取消收藏", Toast.LENGTH_SHORT).show();
        });
        btnPlay.setOnClickListener(v -> play(type.equals("tv") ? 1 : 0));
    }

    private void loadDetail() {
        detail = Tmdb.detail(type, id);
        if (detail == null) {
            runOnUiThread(() -> {
                dStatus.setVisibility(View.VISIBLE);
                dStatus.setText("加载失败，请检查网络或 TMDB Key");
                dStatus.setOnClickListener(v -> {
                    dStatus.setOnClickListener(null);
                    dStatus.setText("加载中…");
                    new Thread(this::loadDetail).start();
                });
            });
            return;
        }

        title = type.equals("movie") ? detail.optString("title", "") : detail.optString("name", "");
        origTitle = type.equals("movie") ? detail.optString("original_title", "") : detail.optString("original_name", "");
        String date = type.equals("movie") ? detail.optString("release_date", "") : detail.optString("first_air_date", "");
        year = date != null && date.length() >= 4 ? date.substring(0, 4) : "";
        poster = Tmdb.img(detail.optString("poster_path", ""), "w342");
        backdrop = Tmdb.img(detail.optString("backdrop_path", ""), "w780");
        rating = detail.optDouble("vote_average", 0);
        String overview = detail.optString("overview", "");
        overseas = Tmdb.isOverseas(detail);

        // 类型
        StringBuilder genres = new StringBuilder();
        JSONArray gs = detail.optJSONArray("genres");
        if (gs != null) {
            for (int i = 0; i < gs.length(); i++) {
                JSONObject g = gs.optJSONObject(i);
                if (g != null) {
                    if (genres.length() > 0) genres.append(" / ");
                    genres.append(g.optString("name", ""));
                }
            }
        }

        // 演员
        StringBuilder cast = new StringBuilder();
        try {
            JSONArray ca = detail.getJSONObject("credits").optJSONArray("cast");
            if (ca != null) {
                for (int i = 0; i < Math.min(ca.length(), 8); i++) {
                    JSONObject p = ca.optJSONObject(i);
                    if (p != null) {
                        if (cast.length() > 0) cast.append("  ");
                        cast.append(p.optString("name", ""));
                    }
                }
            }
        } catch (Exception ignored) {
        }

        // 季列表（跳过特别篇）
        seasons.clear();
        if (type.equals("tv")) {
            JSONArray ss = detail.optJSONArray("seasons");
            if (ss != null) {
                for (int i = 0; i < ss.length(); i++) {
                    JSONObject s = ss.optJSONObject(i);
                    if (s == null) continue;
                    int no = s.optInt("season_number", 0);
                    if (no < 1) continue;
                    SeasonInfo si = new SeasonInfo();
                    si.no = no;
                    String ad = s.optString("air_date", "");
                    si.year = ad != null && ad.length() >= 4 ? ad.substring(0, 4) : "";
                    seasons.add(si);
                }
            }
        }

        runOnUiThread(() -> render(genres.toString(), cast.toString(), overview));
    }

    private void render(String genres, String cast, String overview) {
        dStatus.setVisibility(View.GONE);
        ImgLoader.load(poster, dPoster, R.drawable.ph_poster);
        ImgLoader.load(backdrop, dBackdrop, R.drawable.ph_poster);
        dTitle.setText(title);
        dEn.setText(origTitle);

        StringBuilder meta = new StringBuilder();
        if (!year.isEmpty()) meta.append(year);
        if (!genres.isEmpty()) meta.append(meta.length() > 0 ? " · " : "").append(genres);
        if (rating > 0) meta.append(meta.length() > 0 ? " · " : "").append("★ ").append(String.format(java.util.Locale.CHINA, "%.1f", rating));
        if (type.equals("tv") && !seasons.isEmpty()) meta.append(" · ").append(seasons.size()).append("季");
        dMeta.setText(meta.toString());
        dOverview.setText(overview == null || overview.isEmpty() ? "暂无简介" : overview);
        dCast.setText(cast.isEmpty() ? "" : "主演：" + cast);

        btnFav.setText(Prefs.hasFav(this, type, id) ? "已收藏" : "收藏");
        btnPlay.setText(type.equals("tv") ? "播放第 1 集" : "立即播放");

        // 季选择：仅多季剧集显示
        if (type.equals("tv") && seasons.size() > 1) {
            seasonSection.setVisibility(View.VISIBLE);
            renderSeasonChips();
        }

        // 播放源：≥2 个源才显示选择
        if (sources.size() > 1) {
            srcSection.setVisibility(View.VISIBLE);
            renderSourceChips();
        }

        // 音轨：仅海外影视显示
        if (overseas) {
            trackSection.setVisibility(View.VISIBLE);
            renderTrackChips();
        }

        if (type.equals("tv")) loadSeason();
    }

    private void renderSeasonChips() {
        seasonFlow.removeAllViews();
        for (SeasonInfo s : seasons) {
            TextView chip = makeChip("第 " + s.no + " 季" + (s.year.isEmpty() ? "" : " · " + s.year), s.no == seasonNo);
            chip.setOnClickListener(v -> {
                seasonNo = s.no;
                renderSeasonChips();
                loadSeason();
            });
            seasonFlow.addView(chip);
        }
    }

    private void renderSourceChips() {
        srcFlow.removeAllViews();
        TextView auto = makeChip("自动", prefSrc.isEmpty());
        auto.setOnClickListener(v -> {
            prefSrc = "";
            renderSourceChips();
        });
        srcFlow.addView(auto);
        for (Models.Source s : sources) {
            TextView chip = makeChip(s.name, prefSrc.equals(s.url));
            chip.setOnClickListener(v -> {
                prefSrc = s.url;
                renderSourceChips();
            });
            srcFlow.addView(chip);
        }
    }

    private void renderTrackChips() {
        trackFlow.removeAllViews();
        TextView orig = makeChip("原版", track.equals("orig"));
        orig.setOnClickListener(v -> {
            track = "orig";
            renderTrackChips();
        });
        TextView dub = makeChip("普通话", track.equals("dub"));
        dub.setOnClickListener(v -> {
            track = "dub";
            renderTrackChips();
        });
        trackFlow.addView(orig);
        trackFlow.addView(dub);
    }

    private void loadSeason() {
        epSection.setVisibility(View.VISIBLE);
        epLabel.setText("剧集 · 第 " + seasonNo + " 季");
        epFlow.removeAllViews();
        dStatus.setVisibility(View.VISIBLE);
        dStatus.setText("加载剧集…");

        final int sn = seasonNo;
        new Thread(() -> {
            List<Models.Episode> eps = Tmdb.season(id, sn);
            if (isFinishing()) return;
            runOnUiThread(() -> {
                dStatus.setVisibility(View.GONE);
                episodes = eps == null ? new ArrayList<>() : eps;
                if (episodes.isEmpty()) {
                    TextView t = new TextView(this);
                    t.setText("剧集信息加载失败");
                    t.setTextColor(0xff8b93a7);
                    t.setTextSize(12);
                    t.setPadding(0, 6, 0, 6);
                    epFlow.addView(t);
                    return;
                }
                epLabel.setText("剧集 · 第 " + sn + " 季 · 共 " + episodes.size() + " 集");
                renderEpChips();
            });
        }).start();
    }

    private void renderEpChips() {
        epFlow.removeAllViews();
        for (Models.Episode e : episodes) {
            TextView chip = makeChip(String.valueOf(e.num), false);
            chip.setOnClickListener(v -> play(e.num));
            epFlow.addView(chip);
        }
    }

    /** 去播放页（同时记录观看历史） */
    private void play(int ep) {
        Prefs.addHist(this, type, id, title, poster, origTitle, year,
                type.equals("tv") ? seasonNo : 1, ep);
        PlayerActivity.start(this, type, id, type.equals("tv") ? seasonNo : 1, ep,
                title, origTitle, year, track, prefSrc, episodes.size());
    }

    /** 创建 chip 按钮 */
    private TextView makeChip(String text, boolean selected) {
        TextView t = new TextView(this);
        t.setText(text);
        t.setTextSize(13);
        t.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
        t.setBackgroundResource(R.drawable.chip_bg);
        t.setTextColor(selected ? 0xffffffff : 0xffaab3c5);
        t.setSelected(selected);
        t.setPadding(0, 0, 0, 0);
        t.setMinWidth(0);
        t.setMinimumWidth(0);
        t.setGravity(Gravity.CENTER);
        int pad = (int) (getResources().getDisplayMetrics().density * 16);
        t.setPadding(pad, (int) (getResources().getDisplayMetrics().density * 9), pad, (int) (getResources().getDisplayMetrics().density * 9));
        FlowLayout.MarginLayoutParams lp = new FlowLayout.MarginLayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        int m = (int) (getResources().getDisplayMetrics().density * 7);
        lp.setMargins(m, m, m, m);
        t.setLayoutParams(lp);
        return t;
    }
}
