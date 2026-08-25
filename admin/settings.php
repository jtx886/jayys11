<?php
/**
 * Jay影视 - 管理后台 · 网站设置
 * 全站主题颜色 / 站点名称 / TMDB API Key
 */
require __DIR__ . '/_boot.php';

$presets = ['#e50914', '#ff5a3c', '#ff2d78', '#f13c6e', '#3861fb', '#7c4dff', '#00b8a9', '#2ecc97', '#ffb84d', '#ff8a3c'];

if (is_post() && post_val('action') === 'save') {
    csrf_check();
    $siteName = post_val('site_name', 'Jay影视');
    $theme = post_val('theme_color', '#e50914');
    $tmdbKey = trim(post_val('tmdb_api_key', ''));

    if (mb_len($siteName) < 1 || mb_len($siteName) > 20) {
        $_SESSION['flash_err'] = '网站名称需在 1-20 字之间';
    } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $theme)) {
        $_SESSION['flash_err'] = '主题颜色格式不正确';
    } elseif ($tmdbKey !== '' && !preg_match('/^[a-f0-9]{20,40}$/i', $tmdbKey)) {
        $_SESSION['flash_err'] = 'TMDB API Key 格式不正确（应为 32 位十六进制字符串）';
    } else {
        setting_save('site_name', $siteName);
        setting_save('theme_color', strtolower($theme));
        if ($tmdbKey !== '') setting_save('tmdb_api_key', $tmdbKey);
        $_SESSION['flash_ok'] = '网站设置已保存，全站主题颜色已实时更新';
    }
    redirect('settings.php');
}

$curTheme = theme_color();
$curName = site_name();
$curKey = setting('tmdb_api_key', '');

admin_page_start(['active' => 'settings', 'title' => '网站设置']);
?>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

    <!-- 主题颜色 -->
    <div class="panel">
        <div class="panel-head">
            <div class="panel-title"><i class="i i-palette"></i>全站主题颜色</div>
            <span class="tag tag-ok">当前：<?= e($curTheme) ?></span>
        </div>
        <div class="panel-body">
            <div class="swatches">
                <?php foreach ($presets as $c): ?>
                <label class="swatch <?= strtolower($c) === $curTheme ? 'on' : '' ?>" style="background:<?= $c ?>" title="<?= $c ?>">
                    <input type="radio" name="theme_color" value="<?= $c ?>" <?= strtolower($c) === $curTheme ? 'checked' : '' ?> style="display:none" onchange="pickColor('<?= $c ?>')">
                </label>
                <?php endforeach; ?>
            </div>
            <div style="display:flex;align-items:center;gap:12px;margin-top:20px;flex-wrap:wrap">
                <label style="font-size:13px;color:var(--tx2)">自定义颜色：</label>
                <input class="input" id="customColor" style="width:130px" type="color" value="<?= e($curTheme) ?>">
                <input class="input" name="theme_color" id="themeColorInput" style="width:130px" value="<?= e($curTheme) ?>" maxlength="7" placeholder="#e50914">
                <div class="hint" style="margin:0">预设色板或输入十六进制颜色值（如 #3861fb），保存后全站按钮/高亮/渐变同步更新</div>
            </div>
        </div>
    </div>

    <!-- 基础设置 -->
    <div class="panel">
        <div class="panel-head"><div class="panel-title"><i class="i i-gear"></i>基础设置</div></div>
        <div class="panel-body">
            <div class="form-grid">
                <div class="form-item">
                    <label>网站名称</label>
                    <input class="input" name="site_name" value="<?= e($curName) ?>" maxlength="20" required>
                </div>
                <div class="form-item">
                    <label>TMDB API Key（v3）</label>
                    <input class="input" name="tmdb_api_key" value="<?= e($curKey) ?>" placeholder="32位十六进制密钥，留空保持不变">
                    <div class="hint">前往 themoviedb.org 免费申请；本站使用 API 代理 api.tmdb.org 与图片代理 images.tmdb.org</div>
                </div>
            </div>
            <div class="hint" style="margin-top:14px">
                <i class="i i-mail"></i> SMTP 邮件服务已内置 163 邮箱固定配置（<?= e(SMTP_USER) ?>），无需设置
            </div>
        </div>
    </div>

    <button class="btn btn-primary btn-lg" type="submit"><i class="i i-check"></i>保存设置</button>
</form>

<script>
function pickColor(c) {
    document.getElementById('themeColorInput').value = c;
    var swatches = document.querySelectorAll('.swatch');
    swatches.forEach(function (s) { s.classList.remove('on'); });
    event.target.closest('.swatch').classList.add('on');
    document.documentElement.style.setProperty('--accent', c);
}
var colorInput = document.getElementById('customColor');
colorInput.addEventListener('input', function () {
    document.getElementById('themeColorInput').value = colorInput.value;
    document.documentElement.style.setProperty('--accent', colorInput.value);
    document.querySelectorAll('.swatch').forEach(function (s) { s.classList.remove('on'); });
});
</script>
<?php admin_page_end(); ?>
