package com.jay.movie.ui;

import android.app.Fragment;
import android.os.Bundle;
import android.view.Gravity;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.HorizontalScrollView;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;

import com.jay.movie.DetailActivity;
import com.jay.movie.R;
import com.jay.movie.api.Tmdb;
import com.jay.movie.data.Models;

import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.AtomicInteger;

/** 首页：热门电影 / 热门剧集 / 热门动漫 / 热门综艺 四板块推荐流 */
public class HomeFragment extends Fragment {

    private View root;
    private TextView status;

    /** 板块配置：标题 + 分类 */
    private static final String[] CATS = {"movie", "tv", "anime", "variety"};
    private static final String[] TITLES = {"热门电影", "热门剧集", "热门动漫", "热门综艺"};
    private final LinearLayout[] sections = new LinearLayout[4];
    private final int[] pages = {1, 1, 1, 1};

    private static final int ROW_COUNT = 12;          // 每板块展示数量
    private static final int MAX_PAGE = 5;            // 换一批循环上限
    private final ExecutorService pool = Executors.newFixedThreadPool(4);
    private final AtomicInteger pending = new AtomicInteger();

    @Override
    public View onCreateView(LayoutInflater inflater, ViewGroup container, Bundle savedInstanceState) {
        root = inflater.inflate(R.layout.fragment_home, container, false);
        status = root.findViewById(R.id.homeStatus);

        int[] ids = {R.id.secMovie, R.id.secTv, R.id.secAnime, R.id.secVariety};
        for (int i = 0; i < 4; i++) {
            sections[i] = root.findViewById(ids[i]);
            final int idx = i;
            View head = buildHead(TITLES[i]);
            Button refresh = head.findViewById(R.id.secMore);
            refresh.setText("换一批");
            refresh.setOnClickListener(v -> {
                pages[idx] = pages[idx] >= MAX_PAGE ? 1 : pages[idx] + 1;
                loadSection(idx);
            });
            sections[i].addView(head);
            sections[i].addView(buildRow());
        }

        reloadAll();
        return root;
    }

    private void reloadAll() {
        status.setVisibility(View.VISIBLE);
        status.setText("加载中…");
        pending.set(4);
        for (int i = 0; i < 4; i++) loadSection(i);
    }

    /** 加载某个板块当前页 */
    private void loadSection(final int idx) {
        LinearLayout row = (LinearLayout) sections[idx].getChildAt(1);
        row.removeAllViews();
        TextView loading = new TextView(getActivity());
        loading.setText("加载中…");
        loading.setTextColor(0xff8b93a7);
        loading.setTextSize(12);
        loading.setPadding(30, 20, 30, 20);
        row.addView(loading);

        pool.execute(() -> {
            List<Models.Media> list = null;
            try {
                list = Tmdb.category(CATS[idx], pages[idx]);
            } catch (Exception ignored) {
            }
            if (list == null) list = new ArrayList<>();
            if (list.size() > ROW_COUNT) list = new ArrayList<>(list.subList(0, ROW_COUNT));
            final List<Models.Media> finalList = list;
            if (root == null || getActivity() == null) return;
            root.post(() -> {
                if (root == null || getActivity() == null) return;
                row.removeAllViews();
                if (finalList.isEmpty()) {
                    TextView t = new TextView(getActivity());
                    t.setText("加载失败，点击「换一批」重试");
                    t.setTextColor(0xff8b93a7);
                    t.setTextSize(12);
                    t.setPadding(30, 20, 30, 20);
                    row.addView(t);
                } else {
                    for (Models.Media m : finalList) row.addView(makeCard(m));
                }
                if (pending.get() > 0 && pending.decrementAndGet() == 0) {
                    status.setVisibility(View.GONE);
                }
            });
        });
    }

    /* ---------------- 视图构建 ---------------- */

    /** 板块标题行（含右侧按钮） */
    private View buildHead(String title) {
        LinearLayout head = new LinearLayout(getActivity());
        head.setOrientation(LinearLayout.HORIZONTAL);
        head.setGravity(Gravity.CENTER_VERTICAL);

        TextView t = new TextView(getActivity());
        t.setText(title);
        t.setTextColor(0xffe8ecf5);
        t.setTextSize(15.5f);
        t.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
        LinearLayout.LayoutParams tp = new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1);
        t.setLayoutParams(tp);
        head.addView(t);

        Button more = new Button(getActivity());
        more.setId(R.id.secMore);
        more.setTextSize(12);
        more.setTextColor(0xffaab3c5);
        more.setBackgroundResource(R.drawable.chip_bg);
        more.setMinWidth(0);
        more.setMinimumWidth(0);
        more.setMinHeight(0);
        more.setMinimumHeight(0);
        int pad = (int) (getResources().getDisplayMetrics().density * 12);
        more.setPadding(pad, 0, pad, 0);
        LinearLayout.LayoutParams mp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, (int) (getResources().getDisplayMetrics().density * 30));
        head.addView(more, mp);

        int m = (int) (getResources().getDisplayMetrics().density * 16);
        LinearLayout.LayoutParams hp = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        hp.setMargins(m, (int) (getResources().getDisplayMetrics().density * 12), m, 0);
        head.setLayoutParams(hp);
        return head;
    }

    /** 横向卡片条容器 */
    private View buildRow() {
        HorizontalScrollView hsv = new HorizontalScrollView(getActivity());
        hsv.setFillViewport(true);
        hsv.setHorizontalScrollBarEnabled(false);

        LinearLayout row = new LinearLayout(getActivity());
        row.setOrientation(LinearLayout.HORIZONTAL);
        hsv.addView(row);
        return hsv;
    }

    /** 单个海报卡片（复用 item_poster 布局） */
    private View makeCard(final Models.Media m) {
        View card = LayoutInflater.from(getActivity()).inflate(R.layout.item_poster, null, false);
        ImageView img = card.findViewById(R.id.pImg);
        TextView title = card.findViewById(R.id.pTitle);
        TextView sub = card.findViewById(R.id.pSub);

        ImgLoader.load(m.poster, img, R.drawable.ph_poster);
        title.setText(m.title == null || m.title.isEmpty() ? m.origTitle : m.title);
        StringBuilder s = new StringBuilder();
        if (!m.year.isEmpty()) s.append(m.year);
        if (m.rating > 0) s.append(s.length() > 0 ? " · " : "").append("★").append(String.format(java.util.Locale.CHINA, "%.1f", m.rating));
        sub.setText(s.toString());

        card.setOnClickListener(v ->
                DetailActivity.start(getActivity(), m.type, m.id, m.season));

        int w = (int) (getResources().getDisplayMetrics().density * 106);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(w, ViewGroup.LayoutParams.WRAP_CONTENT);
        int m1 = (int) (getResources().getDisplayMetrics().density * 6);
        lp.setMargins(m1, 0, m1, 0);
        card.setLayoutParams(lp);
        if (((LinearLayout) card).getChildCount() > 0) {
            View first = ((LinearLayout) card).getChildAt(0);
            ViewGroup.LayoutParams ip = first.getLayoutParams();
            ip.width = ViewGroup.LayoutParams.MATCH_PARENT;
            first.setLayoutParams(ip);
        }
        return card;
    }

    @Override
    public void onDestroyView() {
        super.onDestroyView();
        root = null;
    }

    @Override
    public void onDestroy() {
        super.onDestroy();
        pool.shutdownNow();
    }
}
