package com.jay.movie.ui;

import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.os.Handler;
import android.os.Looper;
import android.util.LruCache;
import android.widget.ImageView;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/** 极简图片加载（LruCache + 线程池，单次下载，零依赖） */
public class ImgLoader {

    private static LruCache<String, Bitmap> cache;
    private static ExecutorService pool;
    private static final Handler MAIN = new Handler(Looper.getMainLooper());

    /** 正在加载的 URL 去重，避免 GridView 复用导致重复排队 */
    private static final java.util.HashSet<String> running = new java.util.HashSet<>();

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
        if (pool == null) pool = Executors.newFixedThreadPool(8);
    }

    public static void load(String url, ImageView iv, int placeholder) {
        init();
        if (url == null || url.isEmpty()) {
            iv.setImageResource(placeholder);
            return;
        }
        Bitmap b = cache.get(url);
        if (b != null) {
            iv.setTag(url);
            iv.setImageBitmap(b);
            return;
        }
        iv.setTag(url);
        iv.setImageResource(placeholder);
        // 同一 URL 只排一个任务
        synchronized (running) {
            if (running.contains(url)) return;
            running.add(url);
        }
        pool.execute(() -> {
            Bitmap bmp = decode(url);
            synchronized (running) {
                running.remove(url);
            }
            if (bmp == null) return;
            cache.put(url, bmp);
            MAIN.post(() -> {
                if (url.equals(iv.getTag())) iv.setImageBitmap(bmp);
            });
        });
    }

    /** 单次下载到内存，再按目标宽度解码（不再二次下载） */
    private static Bitmap decode(String url) {
        HttpURLConnection c = null;
        try {
            c = (HttpURLConnection) new URL(url).openConnection();
            c.setConnectTimeout(8000);
            c.setReadTimeout(10000);
            c.setRequestProperty("User-Agent", "Mozilla/5.0 (Linux; Android 12) Chrome/120.0 Mobile");
            InputStream in = c.getInputStream();
            ByteArrayOutputStream bo = new ByteArrayOutputStream();
            byte[] buf = new byte[16384];
            int n;
            while ((n = in.read(buf)) > 0) bo.write(buf, 0, n);
            in.close();
            byte[] data = bo.toByteArray();

            BitmapFactory.Options o = new BitmapFactory.Options();
            o.inJustDecodeBounds = true;
            BitmapFactory.decodeByteArray(data, 0, data.length, o);

            // 卡片宽约 106dp，取 1.5 倍密度足够清晰
            int targetW = 400;
            int sample = 1;
            while (o.outWidth / (sample * 2) >= targetW) sample *= 2;

            BitmapFactory.Options o2 = new BitmapFactory.Options();
            o2.inSampleSize = sample;
            return BitmapFactory.decodeByteArray(data, 0, data.length, o2);
        } catch (Exception e) {
            return null;
        } finally {
            if (c != null) c.disconnect();
        }
    }
}
