package com.jay.movie.ui;

import android.app.Fragment;
import android.content.Intent;
import android.os.Bundle;
import android.view.Gravity;
import android.view.KeyEvent;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.view.inputmethod.EditorInfo;
import android.widget.AdapterView;
import android.widget.Button;
import android.widget.EditText;
import android.widget.GridView;
import android.widget.TextView;

import com.jay.movie.DetailActivity;
import com.jay.movie.R;
import com.jay.movie.api.Tmdb;
import com.jay.movie.data.Models;
import com.jay.movie.data.Prefs;

import java.util.ArrayList;
import java.util.List;

/** 搜索页：热门搜索 + 搜索历史 + 结果网格（剧集按季展开） */
public class SearchFragment extends Fragment {

    /** 内置热门搜索词 */
    private static final String[] HOT_WORDS = {
            "斗罗大陆", "海贼王", "火影忍者", "鬼灭之刃", "庆余年", "长相思",
            "流浪地球", "哪吒", "沙丘", "奥本海默", "狂飙", "三体",
            "龙珠", "名侦探柯南", "咒术回战", "甄嬛传"
    };

    private View root, hintBox, histSection;
    private EditText input;
    private GridView grid;
    private TextView status;
    private PosterAdapter adapter;
    private final List<Models.Media> items = new ArrayList<>();
    private boolean loading;

    @Override
    public View onCreateView(LayoutInflater inflater, ViewGroup container, Bundle savedInstanceState) {
        root = inflater.inflate(R.layout.fragment_search, container, false);
        input = root.findViewById(R.id.sInput);
        grid = root.findViewById(R.id.sGrid);
        status = root.findViewById(R.id.sStatus);
        hintBox = root.findViewById(R.id.hintBox);
        histSection = root.findViewById(R.id.histSection);
        Button btn = root.findViewById(R.id.sBtn);
        Button clear = root.findViewById(R.id.btnClearHist);

        adapter = new PosterAdapter(getActivity(), items);
        grid.setAdapter(adapter);
        grid.setOnItemClickListener((AdapterView<?> parent, View v, int position, long id) -> {
            Models.Media m = items.get(position);
            DetailActivity.start(getActivity(), m.type, m.id, m.season);
        });

        btn.setOnClickListener(v -> doSearch());
        input.setOnEditorActionListener((TextView v, int actionId, KeyEvent event) -> {
            if (actionId == EditorInfo.IME_ACTION_SEARCH) {
                doSearch();
                return true;
            }
            return false;
        });

        clear.setOnClickListener(v -> {
            if (getActivity() == null) return;
            Prefs.clearSearches(getActivity());
            renderHistory();
        });

        renderHot();
        renderHistory();
        return root;
    }

    /** 热门搜索词 */
    private void renderHot() {
        FlowLayout flow = root.findViewById(R.id.hotFlow);
        flow.removeAllViews();
        for (final String w : HOT_WORDS) flow.addView(makeChip(w, false, v -> {
            input.setText(w);
            doSearch();
        }));
    }

    /** 搜索历史 */
    private void renderHistory() {
        if (getActivity() == null) return;
        List<String> hist = Prefs.getSearches(getActivity());
        FlowLayout flow = root.findViewById(R.id.histFlow);
        flow.removeAllViews();
        if (hist.isEmpty()) {
            histSection.setVisibility(View.GONE);
            return;
        }
        histSection.setVisibility(View.VISIBLE);
        for (final String w : hist) flow.addView(makeChip(w, true, v -> {
            input.setText(w);
            doSearch();
        }));
    }

    /** 关键词 chip */
    private TextView makeChip(String text, boolean small, View.OnClickListener click) {
        TextView t = new TextView(getActivity());
        t.setText(text);
        t.setTextSize(small ? 12.5f : 13f);
        t.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
        t.setBackgroundResource(R.drawable.chip_bg);
        t.setTextColor(0xffaab3c5);
        t.setGravity(Gravity.CENTER);
        t.setMinWidth(0);
        t.setMinimumWidth(0);
        int padH = (int) (getResources().getDisplayMetrics().density * 15);
        int padV = (int) (getResources().getDisplayMetrics().density * 8);
        t.setPadding(padH, padV, padH, padV);
        t.setOnClickListener(click);
        FlowLayout.MarginLayoutParams lp = new FlowLayout.MarginLayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        int m = (int) (getResources().getDisplayMetrics().density * 7);
        lp.setMargins(m, m, m, m);
        t.setLayoutParams(lp);
        return t;
    }

    private void doSearch() {
        final String q = input.getText().toString().trim();
        if (q.isEmpty() || loading) return;

        if (getActivity() != null) Prefs.addSearch(getActivity(), q);
        renderHistory();

        items.clear();
        adapter.notifyDataSetChanged();
        hintBox.setVisibility(View.GONE);
        grid.setVisibility(View.GONE);
        status.setVisibility(View.VISIBLE);
        status.setText("搜索中…");
        loading = true;

        new Thread(() -> {
            List<Models.Media> list = null;
            try {
                list = Tmdb.searchWithSeasons(q, 1);
            } catch (Exception ignored) {
            }
            final List<Models.Media> finalList = list;
            if (root == null) return;
            root.post(() -> {
                if (root == null) return;
                loading = false;
                if (finalList == null) {
                    status.setVisibility(View.VISIBLE);
                    status.setText("搜索失败，请检查网络");
                    return;
                }
                if (finalList.isEmpty()) {
                    status.setVisibility(View.VISIBLE);
                    status.setText("未找到「" + q + "」相关影视");
                    return;
                }
                status.setVisibility(View.GONE);
                items.addAll(finalList);
                adapter.notifyDataSetChanged();
                grid.setVisibility(View.VISIBLE);
            });
        }).start();
    }
}
