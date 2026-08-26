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
                             String track, String prefSrc, int epCount,
                             String poster, long resumePos) {
        Intent i = new Intent(c, PlayerActivity.class);
        i.putExtra("type", type).putExtra("id", id).putExtra("season", season)
                .putExtra("ep", ep).putExtra("title", title).putExtra("orig", origTitle)
                .putExtra("year", year).putExtra("track", track).putExtra("prefSrc", prefSrc)
                .putExtra("epCount", epCount).putExtra("poster", poster)
                .putExtra("pos", resumePos);
        c.startActivity(i);
    }

    private static final String PLAYER_BASE = "https://svip.ffzyplay.com/?url=";

    private String type = "movie";
    private int mediaId, season, ep, epCount;
    private String title = "", origTitle = "", year = "", track = "orig", prefSrc = "";
    private String poster = "";

    /* ---------------- 续播进度 ---------------- */
    private long resumePos;      // 本次要跳转到的位置（秒）
    private long curPos, curDur; // JS 轮询到的实时进度
    private int playGen;         // 播放代数：换集后丢弃旧集仍在途的轮询结果
    private final android.os.Handler handler = new android.os.Handler();

    /** 轮询播放进度（每 5 秒读一次 video 元素，存入观看历史） */
    private static final String JS_POLL =
            "(function(){var v=document.querySelector('video');" +
                    "return v?(v.currentTime+'/'+(isFinite(v.duration)?v.duration:0)+'/'+v.paused)" +
                    ":'0/0/true'})()";

    /** 健壮续播：等视频元数据就绪后再 seek（页面自身 ready 时 seek 对 HLS 常失效）。
     *  TARGET 为 null 时回退用播放器 localStorage 里保存的进度 */
    private static String seekJs(Long target) {
        return "(function(){var t=" + (target == null ? "null" : target) + ";var n=0;" +
                "var timer=setInterval(function(){" +
                "var v=document.querySelector('video');" +
                "if(v&&v.readyState>=1&&v.duration>0&&isFinite(v.duration)){" +
                "var p=(t!=null)?t:(parseFloat(localStorage.getItem(window.video_hash||''))||0);" +
                "if(p>5&&p<v.duration-10){v.currentTime=p;try{window.player.play()}catch(e){v.play()}}" +
                "clearInterval(timer)}else if(++n>120){clearInterval(timer)}},500)})()";
    }

    /* ---------------- 进度轮询：每 5 秒读取播放位置并写入观看历史 ---------------- */

    private final Runnable pollTask = new Runnable() {
        @Override
        public void run() {
            if (isFinishing() || webView == null) return;
            final int gen = playGen;
            webView.evaluateJavascript(JS_POLL, res -> parsePoll(res, gen));
            handler.postDelayed(this, 5000);
        }
    };

    private void startPoll() {
        handler.removeCallbacks(pollTask);
        handler.postDelayed(pollTask, 5000);
    }

    private void stopPoll() {
        handler.removeCallbacks(pollTask);
    }

    /** 解析 JS 轮询结果 "位置/时长/是否暂停"，写入观看历史（gen 不匹配 = 旧集的过期结果，丢弃） */
    private void parsePoll(String res, int gen) {
        if (res == null || isFinishing() || gen != playGen) return;
        try {
            String[] p = res.replace("\"", "").trim().split("/");
            if (p.length < 2) return;
            long pos = (long) Double.parseDouble(p[0]);
            long dur = (long) Double.parseDouble(p[1]);
            if (dur <= 0 || pos <= 3) return;
            curPos = pos;
            curDur = dur;
            Prefs.addHist(this, type, mediaId, title, poster, origTitle, year,
                    type.equals("tv") ? season : 1, ep, pos, dur, track);
        } catch (Exception ignored) {
        }
    }

    /** 退出/切后台时保存当前进度到观看历史 */
    private void saveProgress() {
        if (curPos > 3 && curDur > 0) {
            Prefs.addHist(this, type, mediaId, title, poster, origTitle, year,
                    type.equals("tv") ? season : 1, ep, curPos, curDur, track);
        }
    }

    /** 换集：清空进度并使旧轮询失效，从头播放 */
    private void resetProgress() {
        playGen++;
        resumePos = 0;
        curPos = 0;
        curDur = 0;
    }

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
        poster = getIntent().getStringExtra("poster");
        resumePos = getIntent().getLongExtra("pos", 0);
        if (track == null) track = "orig";
        if (title == null) title = "";
        if (poster == null) poster = "";

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
                resetProgress();   // 换集从头看
                resolve();
            }
        });
        btnNext.setOnClickListener(v -> {
            if (epCount <= 0 || ep < epCount) {
                ep++;
                resetProgress();   // 换集从头看
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

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public void onPageFinished(WebView view, String url) {
                if (url == null || !url.startsWith(PLAYER_BASE)) return;
                // 注入健壮续播：指定位置优先，否则用播放器 localStorage 进度
                final long target = resumePos;
                if (target > 5) {
                    resumePos = 0;   // 只对本次加载生效，换集后从头播
                    view.evaluateJavascript(seekJs(target), null);
                    pInfo.append("\n已从上次进度 " + Prefs.fmtPos(target) + " 续播");
                } else {
                    view.evaluateJavascript(seekJs(null), null);
                }
                startPoll();
            }
        });
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
            // 记录观看历史（保留当前进度与时长，之后由轮询持续更新）
            long pos0 = curPos > 5 ? curPos : Math.max(resumePos, 0);
            Models.Hist oh = Prefs.findHist(this, type, mediaId);
            long dur0 = curDur > 0 ? curDur : (oh == null ? 0 : oh.dur);
            Prefs.addHist(this, type, mediaId, title, poster, origTitle, year,
                    type.equals("tv") ? season : 1, ep, pos0, dur0, track);

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
                if (curPos > 5) resumePos = curPos;   // 换源后回到当前进度
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
                    resetProgress();   // 换集从头看
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
        saveProgress();
        if (webView != null) webView.onPause();
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (webView != null) webView.onResume();
    }

    @Override
    protected void onDestroy() {
        stopPoll();
        saveProgress();
        if (webView != null) webView.destroy();
        super.onDestroy();
    }
}
