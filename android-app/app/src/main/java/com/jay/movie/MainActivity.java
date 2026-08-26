package com.jay.movie;

import android.app.Activity;
import android.app.Fragment;
import android.os.Bundle;
import android.widget.TextView;
import android.widget.Toast;

import com.jay.movie.data.Prefs;
import com.jay.movie.ui.FavFragment;
import com.jay.movie.ui.HomeFragment;
import com.jay.movie.ui.SearchFragment;
import com.jay.movie.ui.SettingsFragment;

/** 主界面：底部导航（首页/搜索/收藏/设置） */
public class MainActivity extends Activity {

    private TextView[] tabs;
    private Fragment[] frags;
    private int cur = -1;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        tabs = new TextView[]{
                findViewById(R.id.tabHome),
                findViewById(R.id.tabSearch),
                findViewById(R.id.tabFav),
                findViewById(R.id.tabSet)
        };
        for (int i = 0; i < tabs.length; i++) {
            final int idx = i;
            tabs[i].setOnClickListener(v -> select(idx));
        }

        // 未配置 TMDB Key → 直接进设置页
        if (Prefs.getKey(this).isEmpty()) {
            select(3);
            Toast.makeText(this, "请先在设置中填入 TMDB API Key", Toast.LENGTH_LONG).show();
        } else {
            select(0);
        }
    }

    private void select(int idx) {
        if (cur == idx) return;
        cur = idx;
        for (int i = 0; i < tabs.length; i++) tabs[i].setSelected(i == idx);

        if (frags == null) {
            frags = new Fragment[]{new HomeFragment(), new SearchFragment(), new FavFragment(), new SettingsFragment()};
        }
        getFragmentManager().beginTransaction()
                .replace(R.id.content, frags[idx])
                .commit();
    }

    @Override
    protected void onResume() {
        super.onResume();
        // 从设置页填完 Key 回来 → 回首页
        if (cur == 3 && !Prefs.getKey(this).isEmpty() && frags != null) {
            // 保持在当前页，用户自行切换
        }
    }
}
