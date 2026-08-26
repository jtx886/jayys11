package com.jay.movie;

import android.app.Application;
import android.content.Context;

import com.jay.movie.ui.ImgLoader;

/** 应用入口：提供全局 Context 与图片缓存初始化 */
public class App extends Application {

    private static Application inst;

    @Override
    public void onCreate() {
        super.onCreate();
        inst = this;
        ImgLoader.init();
    }

    public static Context ctx() {
        return inst;
    }
}
