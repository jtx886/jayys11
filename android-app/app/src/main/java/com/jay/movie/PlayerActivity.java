package com.jay.movie;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.graphics.Color;
import android.os.Bundle;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.view.WindowManager;
import android.webkit.WebChromeClient;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.TextView;

import com.jay.movie.api.Http;
import com.jay.movie.api.Resolver;
import com.jay.movie.api.Tmdb;
import com.jay.movie.data.Models;
import com.jay.movie.data.Prefs;
import com.jay.movie.ui.FlowLayout;

import java.util.List;

/** 播放页：解析直链（多源自动降级）+ WebView 播放器 + 选集/换源 */
public class PlayerActivity extends Activity {

    public static void start(Context c, String type, int id, int season, int ep,
                             String title, String origTitle, String year,
                             String track, String prefSrc, int epCount) {
        Intent i = new Intent(c, PlayerActivity.class);
        i.putExtra("type", type).putExtra("id", id).putExtra("season", season)
                .putExtra("ep", ep).putExtra("title", title).putExtra("orig", origTitle)
                .putExtra("year", year).putExtra("track", track).putExtra("prefSrc", prefSrc)
                .putExtra("epCount", epCount);
        c.startActivity(i);
    }

    private static final String PLAYER_BASE = "https://svip.ffzyplay.com/?url=";

    private String type = "movie";
    private int mediaId, season, ep, epCount;
    private String title = "", origTitle = "", year = "", track = "orig", prefSrc = "";
    private List<Models.Source> sources;

    private LinearLayout playerRoot;
    private FrameLayout playerWrap;
    private WebView webView;
    private TextView pTitle, pSource, pInfo, pLoad, epLabel;
    private Button btnPrev, btnNext, btnRetry;
    private LinearLayout srcSection, epSection;
    private FlowLayout srcFlow, epFlow;

    // 全屏视频
    private View customView;
    private FrameLayout fullscreenFrame;
    private WebChromeClient.CustomViewCallback customViewCallback;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_player);

        type = getIntent().getStringExtra("type");
        if (type == null || (!type.equals("movie") && !type.equals("tv"))) type = "movie";
        mediaId = getIntent().getIntExtra("id", 0);
        season = Math.max(1, getIntent().getIntExtra("season", 1));
        ep = getIntent().getIntExtra("ep", 0);
        epCount = getIntent().getIntExtra("epCount", 0);
        title = getIntent().getStringExtra("title");
        origTitle = getIntent().getStringExtra("orig");
        year = getIntent().getStringExtra("year");
        track = getIntent().getStringExtra("track");
        prefSrc = getIntent().getStringExtra("prefSrc");
        if (track == null) track = "orig";
        if (title == null) title = "";

        sources = Prefs.getSources(this);

        bindViews();
        setupWebView();
        resolve();

        if (type.equals("tv")) {
            if (epCount > 0) renderEpChips();
            else fetchEpCount();
        }
    }

    private void bindViews() {
        playerRoot = findViewById(R.id.playerRoot);
        playerWrap = findViewById(R.id.playerWrap);
        webView = findViewById(R.id.player);
        pTitle = findViewById(R.id.pTitle);
        pSource = findViewById(R.id.pSource);
        pInfo = findViewById(R.id.pInfo);
        pLoad = findViewById(R.id.pLoad);
        epLabel = findViewById(R.id.epLabel);
        btnPrev = findViewById(R.id.btnPrev);
        btnNext = findViewById(R.id.btnNext);
        btnRetry = findViewById(R.id.btnRetry);
        srcSection = findViewById(R.id.srcSection);
        epSection = findViewById(R.id.epSection);
        srcFlow = findViewById(R.id.srcFlow);
        epFlow = findViewById(R.id.epFlow);

        pTitle.setText(type.equals("tv") ? title + " 第" + season + "季 第" + ep + "集" : title);

        findViewById(R.id.pBack).setOnClickListener(v -> finish());
        btnPrev.setOnClickListener(v -> {
            if (ep > 1) {
                ep--;
                resolve();
            }
        });
        btnNext.setOnClickListener(v -> {
            if (epCount <= 0 || ep < epCount) {
                ep++;
                resolve();
            }
        });
        btnRetry.setOnClickListener(v -> {
            // 换下一个源重试
            String next = nextSourceUrl();
            if (next != null) prefSrc = next;
            resolve();
        });

        // 播放器高度 = 屏宽 * 9/16
        int w = getResources().getDisplayMetrics().widthPixels;
        ViewGroup.LayoutParams lp = playerWrap.getLayoutParams();
        lp.height = w * 9 / 16;
        playerWrap.setLayoutParams(lp);
    }

    @SuppressLint("SetJavaScriptEnabled")
    private void setupWebView() {
        WebSettings s = webView.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setUseWideViewPort(true);
        s.setLoadWithOverviewMode(true);
        s.setSupportZoom(false);
        s.setMediaPlaybackRequiresUserGesture(false);
        s.setMixedContentMode(WebSettings.MIXED_CONTENT_ALWAYS_ALLOW);

        webView.setWebViewClient(new WebViewClient());
        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onProgressChanged(WebView view, int newProgress) {
                if (newProgress >= 80 && pLoad.getVisibility() == View.VISIBLE) {
                    pLoad.setVisibility(View.GONE);
                }
            }

            @Override
            public void onShowCustomView(View view, CustomViewCallback callback) {
                if (customView != null) {
                    callback.onCustomViewHidden();
                    return;
                }
                customView = view;
                customViewCallback = callback;
                fullscreenFrame = new FrameLayout(PlayerActivity.this);
                fullscreenFrame.setBackgroundColor(Color.BLACK);
                playerRoot.setVisibility(View.GONE);
                ((ViewGroup) getWindow().getDecorView()).addView(fullscreenFrame,
                        new ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT,
                                ViewGroup.LayoutParams.MATCH_PARENT));
                fullscreenFrame.addView(view, new ViewGroup.LayoutParams(
                        ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
                getWindow().addFlags(WindowManager.LayoutParams.FLAG_FULLSCREEN);
            }

            @Override
            public void onHideCustomView() {
                exitFullscreen();
            }
        });
    }

    private void exitFullscreen() {
        if (customView == null) return;
        fullscreenFrame.removeAllViews();
        ((ViewGroup) getWindow().getDecorView()).removeView(fullscreenFrame);
        fullscreenFrame = null;
        customView = null;
        playerRoot.setVisibility(View.VISIBLE);
        if (customViewCallback != null) {
            customViewCallback.onCustomViewHidden();
            customViewCallback = null;
        }
        getWindow().clearFlags(WindowManager.LayoutParams.FLAG_FULLSCREEN);
    }

    /* ---------------- 解析播放 ---------------- */

    private void resolve() {
        pLoad.setVisibility(View.VISIBLE);
        pLoad.setText("正在解析播放地址…");
        btnRetry.setVisibility(View.GONE);
        renderEpChips();
        webView.loadUrl("about:blank");

        final boolean isTv = type.equals("tv");
        final int fEp = ep, fSeason = season;
        final String fTrack = track, fPref = prefSrc;

        new Thread(() -> {
            final Resolver.PlayResult r = Resolver.resolvePlay(sources, fPref, isTv,
                    title, origTitle, parseIntSafe(year), fSeason, fEp, fTrack);
            if (isFinishing()) return;
            runOnUiThread(() -> applyResult(r));
        }).start();
    }

    private void applyResult(Resolver.PlayResult r) {
        pLoad.setVisibility(View.GONE);

        if (r.ok) {
            // 记录观看历史
            Prefs.addHist(this, type, mediaId, title, "", origTitle, year,
                    type.equals("tv") ? season : 1, ep);

            pSource.setText("片源：" + r.sourceName);
            StringBuilder info = new StringBuilder();
            if (r.switched) info.append("所选播放源无资源，已自动切换至「").append(r.sourceName).append("」");
            if (r.fallback) info.append(info.length() > 0 ? "\n" : "").append("无普通话资源，已回退原版");
            if (!r.entry.isEmpty()) info.append(info.length() > 0 ? "\n" : "").append("资源：").append(r.entry);
            if (info.length() == 0) info.append("来源：").append(r.sourceName);
            pInfo.setText(info.toString());
            pInfo.setTextColor(0xffaab3c5);

            webView.loadUrl(PLAYER_BASE + Http.enc(r.url));
            renderSourceChips(r.sourceName);
        } else {
            pSource.setText("");
            pInfo.setText("解析失败：" + r.msg);
            pInfo.setTextColor(0xffff5a5f);
            btnRetry.setVisibility(View.VISIBLE);
            renderSourceChips(r.sourceName);
        }
    }

    /* ---------------- 换源 ---------------- */

    private String nextSourceUrl() {
        if (sources.isEmpty()) return null;
        int start = 0;
        for (int i = 0; i < sources.size(); i++) {
            if (sources.get(i).url.equals(prefSrc)) { start = i; break; }
        }
        return sources.get((start + 1) % sources.size()).url;
    }

    private void renderSourceChips(String usedName) {
        if (sources.size() < 2) return;
        srcSection.setVisibility(View.VISIBLE);
        srcFlow.removeAllViews();
        for (Models.Source s : sources) {
            TextView chip = makeChip(s.name + (s.name.equals(usedName) ? " ✓" : ""), s.name.equals(usedName));
            chip.setOnClickListener(v -> {
                prefSrc = s.url;
                resolve();
            });
            srcFlow.addView(chip);
        }
    }

    /* ---------------- 选集 ---------------- */

    private void fetchEpCount() {
        new Thread(() -> {
            List<Models.Episode> eps = Tmdb.season(mediaId, season);
            if (isFinishing()) return;
            runOnUiThread(() -> {
                if (eps != null && !eps.isEmpty()) {
                    epCount = eps.size();
                    renderEpChips();
                }
            });
        }).start();
    }

    private void renderEpChips() {
        if (!type.equals("tv")) return;
        if (epCount <= 0) return;
        epSection.setVisibility(View.VISIBLE);
        epLabel.setText("选集 · 第 " + season + " 季");
        epFlow.removeAllViews();
        int max = Math.min(epCount, 200);
        for (int i = 1; i <= max; i++) {
            final int num = i;
            TextView chip = makeChip(String.valueOf(num), num == ep);
            chip.setOnClickListener(v -> {
                if (num != ep) {
                    ep = num;
                    resolve();
                }
            });
            epFlow.addView(chip);
        }
    }

    private TextView makeChip(String text, boolean selected) {
        TextView t = new TextView(this);
        t.setText(text);
        t.setTextSize(13);
        t.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
        t.setBackgroundResource(R.drawable.chip_bg);
        t.setTextColor(selected ? 0xffffffff : 0xffaab3c5);
        t.setSelected(selected);
        t.setGravity(Gravity.CENTER);
        t.setMinWidth(0);
        t.setMinimumWidth(0);
        float d = getResources().getDisplayMetrics().density;
        t.setMinWidth((int) (d * 44));
        t.setPadding((int) (d * 12), (int) (d * 9), (int) (d * 12), (int) (d * 9));
        FlowLayout.MarginLayoutParams lp = new FlowLayout.MarginLayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        int m = (int) (d * 7);
        lp.setMargins(m, m, m, m);
        t.setLayoutParams(lp);
        return t;
    }

    private int parseIntSafe(String s) {
        try {
            return Integer.parseInt(s == null ? "0" : s.trim());
        } catch (Exception e) {
            return 0;
        }
    }

    /* ---------------- 生命周期 ---------------- */

    @Override
    public void onBackPressed() {
        if (customView != null) {
            exitFullscreen();
            return;
        }
        super.onBackPressed();
    }

    @Override
    protected void onPause() {
        super.onPause();
        if (webView != null) webView.onPause();
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (webView != null) webView.onResume();
    }

    @Override
    protected void onDestroy() {
        if (webView != null) webView.destroy();
        super.onDestroy();
    }
}
