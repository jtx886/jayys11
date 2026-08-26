package com.jay.movie;

import android.annotation.SuppressLint;
import android.app.DownloadManager;
import android.content.Context;
import android.content.Intent;
import android.graphics.Bitmap;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.view.View;
import android.webkit.CookieManager;
import android.webkit.DownloadListener;
import android.webkit.GeolocationPermissions;
import android.webkit.PermissionRequest;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.ProgressBar;
import android.widget.Toast;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.Nullable;
import androidx.appcompat.app.AppCompatActivity;

import java.net.URISyntaxException;

/**
 * Jay影视 WebView 壳
 * 加载服务器上的网站，本地不存储任何数据（数据库在服务器端）。
 */
public class MainActivity extends AppCompatActivity {

    /** TODO: 部署后替换为你的实际网址（带 https:// 和结尾斜杠） */
    private static final String HOME_URL = "https://__YOUR_DOMAIN__/";
    private static final String FALLBACK_URL = "https://__YOUR_DOMAIN__/";

    private WebView webView;
    private ProgressBar progressBar;
    private ValueCallback<Uri[]> filePathCallback;

    // 文件选择器（网页 <input type=file>，如上传头像）
    private final ActivityResultLauncher<Intent> fileChooser =
            registerForActivityResult(new ActivityResultContracts.StartActivityForResult(), result -> {
                if (filePathCallback == null) return;
                Uri[] results = null;
                if (result.getResultCode() == RESULT_OK && result.getData() != null
                        && result.getData().getData() != null) {
                    results = new Uri[]{result.getData().getData()};
                }
                filePathCallback.onReceiveValue(results);
                filePathCallback = null;
            });

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        webView = findViewById(R.id.webView);
        progressBar = findViewById(R.id.progressBar);
        setupWebView();

        // 冷启动外部链接进入时直接加载该链接
        String launchUrl = null;
        if (getIntent() != null && Intent.ACTION_VIEW.equals(getIntent().getAction())
                && getIntent().getData() != null) {
            launchUrl = getIntent().getData().toString();
        }
        if (savedInstanceState != null) {
            webView.restoreState(savedInstanceState);       // 旋转屏恢复状态
        } else {
            webView.loadUrl(launchUrl != null ? launchUrl : HOME_URL);
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private void setupWebView() {
        WebSettings s = webView.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);                      // localStorage/sessionStorage
        s.setDatabaseEnabled(true);                        // WebView 的 localStorage 基础
        s.setUseWideViewPort(true);
        s.setLoadWithOverviewMode(true);
        s.setSupportZoom(false);
        s.setMediaPlaybackRequiresUserGesture(false);      // 允许自动播放
        s.setMixedContentMode(WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE);
        s.setCacheMode(WebSettings.LOAD_DEFAULT);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            // 视频画中画时 WebView 继续渲染
            webView.setOffscreenPreRaster(true);
        }
        // User-Agent 追加标记（服务端可识别 App 访问）
        s.setUserAgentString(s.getUserAgentString() + " JayMovieApp/1.0");

        // Cookie 持久化（登录态保活）
        CookieManager cm = CookieManager.getInstance();
        cm.setAcceptCookie(true);
        cm.setAcceptThirdPartyCookies(webView, true);

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                String url = request.getUrl().toString();
                // 站内链接 → WebView 内打开
                if (url.startsWith("http") || url.startsWith("https")) {
                    return isExternal(url) && openExternal(url);
                }
                // 微信/支付宝等 scheme → 跳系统
                return handleScheme(url);
            }

            @Override
            public void onPageStarted(WebView view, String url, Bitmap favicon) {
                progressBar.setVisibility(View.VISIBLE);
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                progressBar.setVisibility(View.GONE);
                CookieManager.getInstance().flush();
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, android.webkit.WebResourceError error) {
                // 仅主文档加载失败时显示提示（子资源失败不影响）
                if (request.isForMainFrame()) {
                    Toast.makeText(MainActivity.this, "加载失败，请检查网络后重试", Toast.LENGTH_SHORT).show();
                }
            }
        });

        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onProgressChanged(WebView view, int newProgress) {
                progressBar.setProgress(newProgress);
                progressBar.setVisibility(newProgress >= 100 ? View.GONE : View.VISIBLE);
            }

            // 网页全屏视频
            @Override
            public void onShowCustomView(View view, CustomViewCallback callback) {
                fullscreenView = view;
                fullscreenCallback = callback;
                setContentView(view);
                hideSystemBars();
            }

            @Override
            public void onHideCustomView() {
                if (fullscreenView != null) {
                    setContentView(R.layout.activity_main);
                    webView = findViewById(R.id.webView);
                    progressBar = findViewById(R.id.progressBar);
                    setupWebView();
                    if (webView.getUrl() == null) webView.loadUrl(HOME_URL);
                    fullscreenView = null;
                    fullscreenCallback = null;
                    showSystemBars();
                }
            }

            // 文件选择
            @Override
            public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback, FileChooserParams params) {
                if (filePathCallback != null) filePathCallback.onReceiveValue(null);
                filePathCallback = callback;
                try {
                    fileChooser.launch(params.createIntent());
                } catch (Exception e) {
                    filePathCallback = null;
                    return false;
                }
                return true;
            }

            @Override
            public void onGeolocationPermissionsShowPrompt(String origin, GeolocationPermissions.Callback callback) {
                callback.invoke(origin, false, false); // 影视站不需要定位
            }
        });

        // 网页内下载 → 系统下载管理器
        webView.setDownloadListener(new DownloadListener() {
            @Override
            public void onDownloadStart(String url, String userAgent, String contentDisposition, String mimeType, long contentLength) {
                try {
                    DownloadManager.Request req = new DownloadManager.Request(Uri.parse(url));
                    req.setMimeType(mimeType);
                    String cookies = CookieManager.getInstance().getCookie(url);
                    if (cookies != null) req.addRequestHeader("cookie", cookies);
                    req.addRequestHeader("User-Agent", userAgent);
                    req.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
                    req.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, guessFileName(url, contentDisposition, mimeType));
                    DownloadManager dm = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
                    if (dm != null) {
                        dm.enqueue(req);
                        Toast.makeText(MainActivity.this, "已开始下载", Toast.LENGTH_SHORT).show();
                    }
                } catch (Exception e) {
                    openExternal(url);
                }
            }
        });
    }

    private View fullscreenView;
    private CustomViewCallback fullscreenCallback;

    private void hideSystemBars() {
        getWindow().getDecorView().setSystemUiVisibility(
                View.SYSTEM_UI_FLAG_FULLSCREEN | View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
                        | View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY);
    }

    private void showSystemBars() {
        getWindow().getDecorView().setSystemUiVisibility(View.SYSTEM_UI_FLAG_VISIBLE);
    }

    /** 是否站外链接 */
    private boolean isExternal(String url) {
        try {
            String host = Uri.parse(url).getHost();
            String homeHost = Uri.parse(HOME_URL).getHost();
            return host != null && homeHost != null && !host.endsWith(homeHost);
        } catch (Exception e) {
            return false;
        }
    }

    /** 站外 http(s) 链接跳系统浏览器 */
    private boolean openExternal(String url) {
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
        } catch (Exception e) {
            Toast.makeText(this, "无法打开链接", Toast.LENGTH_SHORT).show();
        }
        return true;
    }

    /** 处理特殊 scheme（weixin:// alipays:// 等） */
    private boolean handleScheme(String url) {
        try {
            Intent intent = Intent.parseUri(url, Intent.URI_INTENT_SCHEME);
            startActivity(intent);
        } catch (URISyntaxException | android.content.ActivityNotFoundException e) {
            // 无法唤起 App 时打开兜底网页
            if (url.contains("scheme=https")) {
                try {
                    Uri fallback = Uri.parse(url.substring(url.indexOf("scheme=") + 12).replace("https", "https"));
                    startActivity(new Intent(Intent.ACTION_VIEW, fallback));
                } catch (Exception ignored) {
                }
            }
        }
        return true;
    }

    private String guessFileName(String url, String contentDisposition, String mimeType) {
        String name = android.webkit.URLUtil.guessFileName(url, contentDisposition, mimeType);
        return name == null ? "download" : name;
    }

    /* ---------- 生命周期：保持登录态与返回键 ---------- */

    @Override
    protected void onPause() {
        super.onPause();
        CookieManager.getInstance().flush();
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        super.onSaveInstanceState(outState);
        webView.saveState(outState);
    }

    @Override
    public void onBackPressed() {
        // 全屏视频退出
        if (fullscreenView != null) {
            onHideCustomView();
            return;
        }
        // 网页可后退 → 后退；否则退出
        if (webView.canGoBack()) {
            webView.goBack();
        } else {
            super.onBackPressed();
        }
    }
}
