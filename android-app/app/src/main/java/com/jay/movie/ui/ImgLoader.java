package com.jay.movie.ui;

import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.os.Handler;
import android.os.Looper;
import android.util.LruCache;
import android.widget.ImageView;

import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/** 极简图片加载（LruCache + 线程池，零依赖） */
public class ImgLoader {

    private static LruCache<String, Bitmap> cache;
    private static ExecutorService pool;
    private static final Handler MAIN = new Handler(Looper.getMainLooper());

    public static void init() {
        if (cache == null) {
            int max = (int) (Runtime.getRuntime().maxMemory() / 8);
            cache = new LruCache<String, Bitmap>(max) {
                @Override
                protected int sizeOf(String key, Bitmap value) {
                    return value.getByteCount() / 1024;
                }
            };
        }
        if (pool == null) pool = Executors.newFixedThreadPool(4);
    }

    public static void load(String url, ImageView iv, int placeholder) {
        init();
        if (url == null || url.isEmpty()) {
            iv.setImageResource(placeholder);
            return;
        }
        Bitmap b = cache.get(url);
        if (b != null) {
            iv.setImageBitmap(b);
            return;
        }
        iv.setImageResource(placeholder);
        iv.setTag(url);
        pool.execute(() -> {
            Bitmap bmp = decode(url, 340);
            if (bmp == null) return;
            cache.put(url, bmp);
            MAIN.post(() -> {
                if (url.equals(iv.getTag())) iv.setImageBitmap(bmp);
            });
        });
    }

    private static Bitmap decode(String url, int targetW) {
        HttpURLConnection c = null;
        try {
            c = (HttpURLConnection) new URL(url).openConnection();
            c.setConnectTimeout(10000);
            c.setReadTimeout(12000);
            c.setRequestProperty("User-Agent", "Mozilla/5.0 (Linux; Android 12) Chrome/120.0 Mobile");
            InputStream in = c.getInputStream();
            BitmapFactory.Options o = new BitmapFactory.Options();
            o.inJustDecodeBounds = true;
            BitmapFactory.decodeStream(in, null, o);
            in.close();

            int sample = 1;
            while (o.outWidth / (sample * 2) >= targetW) sample *= 2;

            c = (HttpURLConnection) new URL(url).openConnection();
            c.setConnectTimeout(10000);
            c.setReadTimeout(12000);
            c.setRequestProperty("User-Agent", "Mozilla/5.0 (Linux; Android 12) Chrome/120.0 Mobile");
            in = c.getInputStream();
            BitmapFactory.Options o2 = new BitmapFactory.Options();
            o2.inSampleSize = sample;
            Bitmap b = BitmapFactory.decodeStream(in, null, o2);
            in.close();
            return b;
        } catch (Exception e) {
            return null;
        } finally {
            if (c != null) c.disconnect();
        }
    }
}
