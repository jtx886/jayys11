package com.jay.movie.ui;

import android.app.Fragment;
import android.content.Intent;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.AbsListView;
import android.widget.AdapterView;
import android.widget.GridView;
import android.widget.TextView;

import com.jay.movie.DetailActivity;
import com.jay.movie.R;
import com.jay.movie.api.Tmdb;
import com.jay.movie.data.Models;

import java.util.ArrayList;
import java.util.List;

/** 首页：电影 / 剧集 / 动漫 / 综艺 分类 + 网格 + 滚动分页 */
public class HomeFragment extends Fragment {

    private View root;
    private GridView grid;
    private TextView status;
    private PosterAdapter adapter;

    private final List<Models.Media> items = new ArrayList<>();
    private final TextView[] catTabs = new TextView[4];
    private final String[] cats = {"movie", "tv", "anime", "variety"};

    private String cat = "movie";
    private int page = 1;
    private boolean loading, ended, errorMode;

    @Override
    public View onCreateView(LayoutInflater inflater, ViewGroup container, Bundle savedInstanceState) {
        root = inflater.inflate(R.layout.fragment_home, container, false);
        grid = root.findViewById(R.id.homeGrid);
        status = root.findViewById(R.id.homeStatus);

        catTabs[0] = root.findViewById(R.id.cMovie);
        catTabs[1] = root.findViewById(R.id.cTv);
        catTabs[2] = root.findViewById(R.id.cAnime);
        catTabs[3] = root.findViewById(R.id.cVariety);
        for (int i = 0; i < catTabs.length; i++) {
            final int idx = i;
            catTabs[i].setOnClickListener(v -> switchCat(cats[idx]));
        }

        adapter = new PosterAdapter(getActivity(), items);
        grid.setAdapter(adapter);
        grid.setOnItemClickListener((AdapterView<?> parent, View v, int position, long id) -> {
            Models.Media m = items.get(position);
            DetailActivity.start(getActivity(), m.type, m.id);
        });

        // 滚动到底自动加载下一页
        grid.setOnScrollListener(new AbsListView.OnScrollListener() {
            @Override
            public void onScrollStateChanged(AbsListView view, int scrollState) {
            }

            @Override
            public void onScroll(AbsListView view, int firstVisible, int visibleCount, int totalCount) {
                if (!loading && !ended && errorMode == false && totalCount > 0
                        && firstVisible + visibleCount >= totalCount - 6) {
                    load();
                }
            }
        });

        // 状态点击 = 出错重试
        status.setOnClickListener(v -> {
            if (errorMode) reload();
        });

        if (items.isEmpty()) reload();
        else selectCatTab();
        return root;
    }

    private void switchCat(String c) {
        if (c.equals(cat)) return;
        cat = c;
        reload();
    }

    private void reload() {
        page = 1;
        items.clear();
        adapter.notifyDataSetChanged();
        ended = false;
        errorMode = false;
        selectCatTab();
        load();
    }

    private void selectCatTab() {
        for (int i = 0; i < cats.length; i++) catTabs[i].setSelected(cats[i].equals(cat));
    }

    private void load() {
        if (loading || ended) return;
        loading = true;
        status.setVisibility(View.VISIBLE);
        status.setText(items.isEmpty() ? "加载中…" : "加载更多…");

        new Thread(() -> {
            List<Models.Media> list = null;
            try {
                list = Tmdb.category(cat, page);
            } catch (Exception ignored) {
            }
            final List<Models.Media> finalList = list;
            if (root == null) return;
            root.post(() -> {
                if (root == null) return;
                loading = false;
                status.setVisibility(View.GONE);
                if (finalList == null) {
                    errorMode = true;
                    status.setVisibility(View.VISIBLE);
                    status.setText("加载失败，点击重试");
                    return;
                }
                if (finalList.isEmpty()) {
                    ended = true;
                    if (items.isEmpty()) {
                        status.setVisibility(View.VISIBLE);
                        status.setText("没有数据");
                    }
                    return;
                }
                items.addAll(finalList);
                page++;
                adapter.notifyDataSetChanged();
            });
        }).start();
    }
}
