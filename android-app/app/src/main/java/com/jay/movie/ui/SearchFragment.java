package com.jay.movie.ui;

import android.app.Fragment;
import android.content.Intent;
import android.os.Bundle;
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

import java.util.ArrayList;
import java.util.List;

/** 搜索页 */
public class SearchFragment extends Fragment {

    private View root;
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
        Button btn = root.findViewById(R.id.sBtn);

        adapter = new PosterAdapter(getActivity(), items);
        grid.setAdapter(adapter);
        grid.setOnItemClickListener((AdapterView<?> parent, View v, int position, long id) -> {
            Models.Media m = items.get(position);
            DetailActivity.start(getActivity(), m.type, m.id);
        });

        btn.setOnClickListener(v -> doSearch());
        input.setOnEditorActionListener((TextView v, int actionId, KeyEvent event) -> {
            if (actionId == EditorInfo.IME_ACTION_SEARCH) {
                doSearch();
                return true;
            }
            return false;
        });
        return root;
    }

    private void doSearch() {
        String q = input.getText().toString().trim();
        if (q.isEmpty() || loading) return;
        items.clear();
        adapter.notifyDataSetChanged();
        status.setVisibility(View.VISIBLE);
        status.setText("搜索中…");
        loading = true;

        new Thread(() -> {
            List<Models.Media> list = null;
            try {
                list = Tmdb.search(q, 1);
            } catch (Exception ignored) {
            }
            final List<Models.Media> finalList = list;
            if (root == null) return;
            root.post(() -> {
                if (root == null) return;
                loading = false;
                if (finalList == null) {
                    status.setVisibility(View.VISIBLE);
                    status.setText("搜索失败，请检查网络或 TMDB Key");
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
            });
        }).start();
    }
}
