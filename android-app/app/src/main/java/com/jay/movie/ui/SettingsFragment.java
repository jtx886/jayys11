package com.jay.movie.ui;

import android.app.Fragment;
import android.app.AlertDialog;
import android.content.DialogInterface;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import com.jay.movie.R;
import com.jay.movie.data.Models;
import com.jay.movie.data.Prefs;

import java.util.List;

/** 设置页：播放源管理（TMDB Key 已内置，无需展示） */
public class SettingsFragment extends Fragment {

    private LinearLayout srcList;

    @Override
    public View onCreateView(LayoutInflater inflater, ViewGroup container, Bundle savedInstanceState) {
        View root = inflater.inflate(R.layout.fragment_settings, container, false);
        srcList = root.findViewById(R.id.srcList);

        root.findViewById(R.id.btnAddSrc).setOnClickListener(v -> showAddDialog());

        renderSources();
        return root;
    }

    /* ---------------- 播放源管理 ---------------- */

    private void renderSources() {
        if (getActivity() == null) return;
        List<Models.Source> sources = Prefs.getSources(getActivity());
        srcList.removeAllViews();
        for (Models.Source s : sources) {
            View row = LayoutInflater.from(getActivity()).inflate(R.layout.item_source, srcList, false);
            TextView name = row.findViewById(R.id.rowName);
            TextView url = row.findViewById(R.id.rowUrl);
            TextView defTag = row.findViewById(R.id.rowDef);
            Button del = row.findViewById(R.id.rowDel);

            name.setText(s.name + (s.def ? "（默认）" : ""));
            defTag.setVisibility(s.def ? View.VISIBLE : View.GONE);
            url.setText(s.url);
            // 点击行 = 设为默认源
            row.setOnClickListener(v -> {
                List<Models.Source> list = Prefs.getSources(getActivity());
                for (Models.Source x : list) x.def = x.url.equals(s.url);
                Prefs.saveSources(getActivity(), list);
                renderSources();
                Toast.makeText(getActivity(), "已将「" + s.name + "」设为默认源", Toast.LENGTH_SHORT).show();
            });
            del.setOnClickListener(v -> {
                if (getActivity() == null) return;
                new AlertDialog.Builder(getActivity())
                        .setMessage("删除播放源「" + s.name + "」？")
                        .setPositiveButton("删除", (DialogInterface d, int w) -> {
                            List<Models.Source> list = Prefs.getSources(getActivity());
                            for (int i = 0; i < list.size(); i++) {
                                if (list.get(i).url.equals(s.url)) {
                                    list.remove(i);
                                    break;
                                }
                            }
                            if (list.isEmpty()) {
                                Toast.makeText(getActivity(), "至少保留一个播放源", Toast.LENGTH_SHORT).show();
                                return;
                            }
                            boolean hasDef = false;
                            for (Models.Source x : list) if (x.def) hasDef = true;
                            if (!hasDef) list.get(0).def = true;
                            Prefs.saveSources(getActivity(), list);
                            renderSources();
                        })
                        .setNegativeButton("取消", null)
                        .show();
            });
            srcList.addView(row);
        }
    }

    private void showAddDialog() {
        if (getActivity() == null) return;
        View v = LayoutInflater.from(getActivity()).inflate(R.layout.dialog_add_source, null);
        EditText nameEt = v.findViewById(R.id.srcName);
        EditText urlEt = v.findViewById(R.id.srcUrl);

        new AlertDialog.Builder(getActivity())
                .setView(v)
                .setPositiveButton("添加", (DialogInterface d, int w) -> {
                    String name = nameEt.getText().toString().trim();
                    String url = urlEt.getText().toString().trim();
                    if (name.isEmpty() || url.isEmpty()) {
                        Toast.makeText(getActivity(), "名称和地址都不能为空", Toast.LENGTH_SHORT).show();
                        return;
                    }
                    if (!url.toLowerCase().startsWith("http")) {
                        Toast.makeText(getActivity(), "地址必须以 http(s):// 开头", Toast.LENGTH_SHORT).show();
                        return;
                    }
                    List<Models.Source> list = Prefs.getSources(getActivity());
                    for (Models.Source x : list) {
                        if (x.url.equals(url)) {
                            Toast.makeText(getActivity(), "该源已存在", Toast.LENGTH_SHORT).show();
                            return;
                        }
                    }
                    Models.Source s = new Models.Source();
                    s.name = name;
                    s.url = url;
                    list.add(s);
                    Prefs.saveSources(getActivity(), list);
                    renderSources();
                    Toast.makeText(getActivity(), "已添加「" + name + "」", Toast.LENGTH_SHORT).show();
                })
                .setNegativeButton("取消", null)
                .show();
    }
}
