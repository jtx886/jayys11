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
import com.jay.movie.api.Http;
import com.jay.movie.data.Models;
import com.jay.movie.data.Prefs;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.List;

/** 设置页：播放源管理（TMDB Key 已内置，无需展示） */
public class SettingsFragment extends Fragment {

    private LinearLayout srcList;

    @Override
    public View onCreateView(LayoutInflater inflater, ViewGroup container, Bundle savedInstanceState) {
        View root = inflater.inflate(R.layout.fragment_settings, container, false);
        srcList = root.findViewById(R.id.srcList);

        root.findViewById(R.id.btnAddSrc).setOnClickListener(v -> showAddDialog());
        root.findViewById(R.id.btnImportCfg).setOnClickListener(v -> showImportDialog());

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

    /* ---------------- 影视仓（TVBox）配置导入 ---------------- */

    private void showImportDialog() {
        if (getActivity() == null) return;
        View v = LayoutInflater.from(getActivity()).inflate(R.layout.dialog_import_cfg, null);
        EditText et = v.findViewById(R.id.cfgInput);

        new AlertDialog.Builder(getActivity())
                .setView(v)
                .setPositiveButton("导入", (DialogInterface d, int w) -> {
                    String input = et.getText().toString().trim();
                    if (input.isEmpty()) {
                        Toast.makeText(getActivity(), "请粘贴配置链接或 JSON 内容", Toast.LENGTH_SHORT).show();
                        return;
                    }
                    doImport(input);
                })
                .setNegativeButton("取消", null)
                .show();
    }

    private void doImport(String input) {
        Toast.makeText(getActivity(), "正在导入配置…", Toast.LENGTH_SHORT).show();
        new Thread(() -> {
            String cfg = input;
            if (input.toLowerCase().startsWith("http")) {   // 链接 → 先拉取内容
                try {
                    cfg = Http.get(input);
                } catch (Exception e) {
                    cfg = null;
                }
                if (cfg == null || cfg.trim().isEmpty()) {
                    runUi("配置链接获取失败，请检查地址");
                    return;
                }
            }
            // 影视仓仓库格式：{"storeHouse":[{sourceName,sourceUrl}]} → 逐个拉取子配置再导入
            JSONArray store = extractJsonArray(cfg, "storeHouse");
            if (store != null && store.length() > 0) {
                importStoreHouse(store);
                return;
            }
            runUi(fmtImport(Prefs.importTvBoxSites(getActivity(), cfg)));
        }).start();
    }

    /** 从文本裁剪出 JSON 主体并取指定数组字段（容错：前后带杂字符） */
    private static JSONArray extractJsonArray(String text, String key) {
        if (text == null) return null;
        String s = text.trim();
        int a = s.indexOf('{'), b = s.lastIndexOf('}');
        if (a < 0 || b <= a) return null;
        try {
            return new JSONObject(s.substring(a, b)).optJSONArray(key);
        } catch (Exception e) {
            return null;
        }
    }

    /** 影视仓仓库：逐个拉取 sourceUrl 指向的子配置并导入（上限 20 个防超时） */
    private void importStoreHouse(JSONArray store) {
        Prefs.ImportResult total = new Prefs.ImportResult();
        int okCfg = 0, failCfg = 0;
        int n = Math.min(store.length(), 20);
        for (int i = 0; i < n; i++) {
            JSONObject sh = store.optJSONObject(i);
            String subUrl = sh == null ? "" : sh.optString("sourceUrl", "").trim();
            if (subUrl.isEmpty() || !subUrl.toLowerCase().startsWith("http")) { failCfg++; continue; }
            try {
                String sub = Http.get(subUrl);
                Prefs.ImportResult r = Prefs.importTvBoxSites(getActivity(), sub);
                if (r != null && r.added + r.dup + r.skipped > 0) {
                    total.added += r.added;
                    total.dup += r.dup;
                    total.skipped += r.skipped;
                    okCfg++;
                } else {
                    failCfg++;
                }
            } catch (Exception e) {
                failCfg++;
            }
        }
        runUi("仓库导入完成：成功 " + okCfg + " 个仓库，新增 " + total.added + " 个源"
                + (total.dup > 0 ? "，已存在 " + total.dup + " 个" : "")
                + (failCfg > 0 ? "，失败 " + failCfg + " 个仓库" : ""));
    }

    private static String fmtImport(Prefs.ImportResult r) {
        if (r == null) return "解析失败：不是有效的影视仓/TVBox 配置";
        if (r.added == 0 && r.dup == 0 && r.skipped == 0) return "配置中没有可用站点";
        return "导入完成：新增 " + r.added + " 个源"
                + (r.dup > 0 ? "，已存在 " + r.dup + " 个" : "")
                + (r.skipped > 0 ? "，跳过不支持类型（xml/spider 等）" + r.skipped + " 个" : "");
    }

    private void runUi(String msg) {
        if (getActivity() == null) return;
        getActivity().runOnUiThread(() -> {
            renderSources();
            Toast.makeText(getActivity(), msg, Toast.LENGTH_LONG).show();
        });
    }
}
