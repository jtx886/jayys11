package com.jay.movie.ui;

import android.content.Context;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.BaseAdapter;
import android.widget.ImageView;
import android.widget.TextView;

import com.jay.movie.R;
import com.jay.movie.data.Models;

import java.util.List;

/** 海报网格适配器（首页/搜索/收藏） */
public class PosterAdapter extends BaseAdapter {

    private final List<Models.Media> data;
    private final LayoutInflater inf;

    public PosterAdapter(Context c, List<Models.Media> data) {
        this.data = data;
        this.inf = LayoutInflater.from(c);
    }

    @Override
    public int getCount() {
        return data.size();
    }

    @Override
    public Object getItem(int position) {
        return data.get(position);
    }

    @Override
    public long getItemId(int position) {
        return data.get(position).id;
    }

    @Override
    public View getView(int position, View convertView, ViewGroup parent) {
        View v = convertView;
        if (v == null) v = inf.inflate(R.layout.item_poster, parent, false);
        Models.Media m = data.get(position);

        ImageView img = v.findViewById(R.id.pImg);
        TextView title = v.findViewById(R.id.pTitle);
        TextView sub = v.findViewById(R.id.pSub);

        ImgLoader.load(m.poster, img, R.drawable.ph_poster);
        title.setText(m.title);
        String s = m.year == null ? "" : m.year;
        if (m.rating > 0) s += (s.isEmpty() ? "" : "  ") + "★ " + String.format(java.util.Locale.CHINA, "%.1f", m.rating);
        sub.setText(s);
        return v;
    }
}
