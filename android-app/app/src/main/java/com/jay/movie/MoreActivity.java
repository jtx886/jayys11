package com.jay.movie;

import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import android.view.View;
import android.widget.AbsListView;
import android.widget.AdapterView;
import android.widget.GridView;
import android.widget.TextView;

import com.jay.movie.api.Tmdb;
import com.jay.movie.data.Models;
import com.jay.movie.ui.PosterAdapter;

import java.util.ArrayList;
import java.util.List;

/** 查看更多：某分类的完整网格列表，滚动自动翻页 */
public class MoreActivity extends Activity {

    public static void start(Context c, String cat, String title) {
        Intent i = new Intent(c, MoreActivity.class);
        i.putExtra("cat", cat);
        i.putExtra("title", title);
        c.startActivity(i);
    }

    private String cat = "movie";
    private int page = 1;
    private boolean loading, ended, errorMode;

    private GridView grid;
    private TextView status;
    private PosterAdapter adapter;
    private final List<Models.Media> items = new ArrayList<>();

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_more);

        cat = getIntent().getStringExtra("cat");
        if (cat == null || cat.isEmpty()) cat = "movie";
        String title = getIntent().getStringExtra("title");
        if (title == null) title = "影视";

        grid = findViewById(R.id.mGrid);
        status = findViewById(R.id.mStatus);
        TextView tvTitle = findViewById(R.id.mTitle);
        tvTitle.setText(title);
        findViewById(R.id.mBack).setOnClickListener(v -> finish());

        adapter = new PosterAdapter(this, items);
        grid.setAdapter(adapter);
        grid.setOnItemClickListener((AdapterView<?> parent, View v, int position, long id) -> {
            Models.Media m = items.get(position);
            DetailActivity.start(this, m.type, m.id, m.season);
        });

        grid.setOnScrollListener(new AbsListView.OnScrollListener() {
            @Override
            public void onScrollStateChanged(AbsListView view, int scrollState) {
            }

            @Override
            public void onScroll(AbsListView view, int firstVisible, int visibleCount, int totalCount) {
                if (!loading && !ended && !errorMode && totalCount > 0
                        && firstVisible + visibleCount >= totalCount - 6) {
                    load();
                }
            }
        });

        status.setOnClickListener(v -> {
            if (errorMode) {
                errorMode = false;
                load();
            }
        });

        load();
    }

    private void load() {
        if (loading || ended) return;
        loading = true;
        status.setVisibility(View.VISIBLE);
        status.setText(items.isEmpty() ? "加载中…" : "加载更多…");

        final int p = page;
        new Thread(() -> {
            List<Models.Media> list = null;
            try {
                list = Tmdb.category(cat, p);
            } catch (Exception ignored) {
            }
            final List<Models.Media> finalList = list;
            runOnUiThread(() -> {
                if (isFinishing()) return;
                loading = false;
                status.setVisibility(View.GONE);
                if (finalList == null) {
                    errorMode = true;
                    status.setVisibility(View.VISIBLE);
                    status.setText(items.isEmpty() ? "加载失败，点击重试" : "加载更多失败，点击重试");
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
