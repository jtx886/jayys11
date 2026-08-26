package com.jay.movie.ui;

import android.app.Fragment;
import android.content.DialogInterface;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.AdapterView;
import android.widget.BaseAdapter;
import android.widget.Button;
import android.widget.GridView;
import android.widget.ImageView;
import android.widget.ListView;
import android.widget.TextView;
import android.widget.Toast;

import com.jay.movie.DetailActivity;
import com.jay.movie.PlayerActivity;
import com.jay.movie.R;
import com.jay.movie.data.Models;
import com.jay.movie.data.Prefs;

import java.util.ArrayList;
import java.util.List;

/** 收藏 + 观看历史（本地存储，无需数据库） */
public class FavFragment extends Fragment {

    private View root;
    private GridView favGrid;
    private ListView histList;
    private TextView status, tabFav, tabHist, btnClear;
    private PosterAdapter favAdapter;
    private HistAdapter histAdapter;

    private final List<Models.Media> favs = new ArrayList<>();
    private final List<Models.Hist> hists = new ArrayList<>();
    private boolean showFav = true;

    @Override
    public View onCreateView(LayoutInflater inflater, ViewGroup container, Bundle savedInstanceState) {
        root = inflater.inflate(R.layout.fragment_fav, container, false);
        favGrid = root.findViewById(R.id.favGrid);
        histList = root.findViewById(R.id.histList);
        status = root.findViewById(R.id.favStatus);
        tabFav = root.findViewById(R.id.tFav);
        tabHist = root.findViewById(R.id.tHist);
        btnClear = root.findViewById(R.id.btnClear);

        favAdapter = new PosterAdapter(getActivity(), favs);
        favGrid.setAdapter(favAdapter);
        favGrid.setOnItemClickListener((AdapterView<?> parent, View v, int position, long id) -> {
            Models.Media m = favs.get(position);
            DetailActivity.start(getActivity(), m.type, m.id);
        });
        // 长按取消收藏
        favGrid.setOnItemLongClickListener((AdapterView<?> parent, View v, int position, long id) -> {
            Models.Media m = favs.get(position);
            confirm("取消收藏「" + m.title + "」？", () -> {
                Prefs.toggleFav(getActivity(), m.type, m.id, m.title, m.poster, m.year);
                refresh();
                Toast.makeText(getActivity(), "已取消收藏", Toast.LENGTH_SHORT).show();
            });
            return true;
        });

        histAdapter = new HistAdapter();
        histList.setAdapter(histAdapter);
        histList.setOnItemClickListener((AdapterView<?> parent, View v, int position, long id) -> {
            Models.Hist h = hists.get(position);
            if (h.type.equals("tv")) {
                PlayerActivity.start(getActivity(), h.type, h.id, h.season,
                        h.episode > 0 ? h.episode : 1, h.title, h.origTitle, h.year, "orig", "", 0);
            } else {
                PlayerActivity.start(getActivity(), h.type, h.id, 1, 0, h.title, h.origTitle, h.year, "orig", "", 0);
            }
        });

        tabFav.setOnClickListener(v -> switchTab(true));
        tabHist.setOnClickListener(v -> switchTab(false));
        btnClear.setOnClickListener(v -> confirm("清空全部观看历史？", () -> {
            Prefs.clearHist(getActivity());
            refresh();
        }));

        refresh();
        switchTab(true);
        return root;
    }

    @Override
    public void onResume() {
        super.onResume();
        refresh();
    }

    private void switchTab(boolean fav) {
        showFav = fav;
        tabFav.setSelected(fav);
        tabHist.setSelected(!fav);
        favGrid.setVisibility(fav ? View.VISIBLE : View.GONE);
        histList.setVisibility(fav ? View.GONE : View.VISIBLE);
        btnClear.setVisibility(fav ? View.GONE : View.VISIBLE);
        updateEmpty();
    }

    private void refresh() {
        if (getActivity() == null) return;
        favs.clear();
        favs.addAll(Prefs.getFavs(getActivity()));
        favAdapter.notifyDataSetChanged();

        hists.clear();
        hists.addAll(Prefs.getHist(getActivity()));
        histAdapter.notifyDataSetChanged();
        updateEmpty();
    }

    private void updateEmpty() {
        boolean empty = showFav ? favs.isEmpty() : hists.isEmpty();
        status.setVisibility(empty ? View.VISIBLE : View.GONE);
        status.setText(showFav ? "暂无收藏，去首页逛逛吧" : "暂无观看记录");
        btnClear.setVisibility(!showFav && !hists.isEmpty() ? View.VISIBLE : View.GONE);
    }

    private void confirm(String msg, final Runnable ok) {
        android.app.AlertDialog d = new android.app.AlertDialog.Builder(getActivity())
                .setMessage(msg)
                .setPositiveButton("确定", (DialogInterface dialog, int which) -> ok.run())
                .setNegativeButton("取消", null)
                .show();
        d.getButton(DialogInterface.BUTTON_POSITIVE).setTextColor(0xffe50914);
        d.getButton(DialogInterface.BUTTON_NEGATIVE).setTextColor(0xffaab3c5);
    }

    /** 历史列表适配器 */
    private class HistAdapter extends BaseAdapter {

        @Override
        public int getCount() {
            return hists.size();
        }

        @Override
        public Object getItem(int position) {
            return hists.get(position);
        }

        @Override
        public long getItemId(int position) {
            return hists.get(position).id;
        }

        @Override
        public View getView(int position, View convertView, ViewGroup parent) {
            View v = convertView;
            if (v == null) v = LayoutInflater.from(getActivity()).inflate(R.layout.item_hist, parent, false);
            Models.Hist h = hists.get(position);

            ImageView img = v.findViewById(R.id.hImg);
            TextView title = v.findViewById(R.id.hTitle);
            TextView sub = v.findViewById(R.id.hSub);
            TextView time = v.findViewById(R.id.hTime);

            ImgLoader.load(h.poster, img, R.drawable.ph_poster);
            title.setText(h.title);
            sub.setText(h.type.equals("tv") ? "看到第 " + h.season + " 季 第 " + h.episode + " 集" : "电影");
            time.setText(Prefs.timeAgo(h.ts));
            return v;
        }
    }
}
