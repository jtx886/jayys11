/* Jay影视 - 全站交互脚本 */
(function () {
    'use strict';

    var JAY = window.JAY || { isLogin: false, csrf: '' };

    /* ---------------- 工具 ---------------- */
    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function api(action, data) {
        var body = new FormData();
        body.append('action', action);
        body.append('csrf', JAY.csrf);
        if (data) { for (var k in data) { if (data.hasOwnProperty(k)) body.append(k, data[k]); } }
        return fetch('api.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .catch(function () { return { ok: false, msg: '网络请求失败' }; });
    }

    function toast(msg, ok) {
        $$('.toast').forEach(function (t) { t.remove(); });
        var el = document.createElement('div');
        el.className = 'toast ' + (ok ? 'toast-ok' : 'toast-err');
        el.textContent = msg;
        document.body.appendChild(el);
        setTimeout(function () { el.remove(); }, 4600);
    }

    window.JAY_UTIL = { api: api, toast: toast, $: $, $$: $$ };

    /* ---------------- 导航 ---------------- */
    var burger = $('#navBurger'), navMenu = $('#navMenu');
    if (burger && navMenu) {
        burger.addEventListener('click', function () {
            burger.classList.toggle('open');
            navMenu.classList.toggle('open');
        });
    }
    var avatarBtn = $('#avatarBtn'), userBox = $('#navUser');
    if (avatarBtn && userBox) {
        avatarBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            userBox.classList.toggle('menu-open');
        });
        document.addEventListener('click', function () { userBox.classList.remove('menu-open'); });
        var userMenu = $('#userMenu');
        if (userMenu) userMenu.addEventListener('click', function (e) { e.stopPropagation(); });
    }

    /* ---------------- Toast 自动消失 ---------------- */
    setTimeout(function () { $$('.toast').forEach(function (t) { t.remove(); }); }, 5300);

    /* ---------------- 未登录播放拦截弹窗 ---------------- */
    function showLoginModal() {
        $$('[data-modal]').forEach(function (m) { m.remove(); });
        var mask = document.createElement('div');
        mask.className = 'modal-mask';
        mask.setAttribute('data-modal', 'login');
        mask.innerHTML =
            '<div class="modal">' +
            '<div class="modal-head"><div class="modal-icon"><i class="i i-lock"></i></div>' +
            '<div class="modal-title">无法播放</div>' +
            '<button class="icon-btn modal-close" data-close><i class="i i-close"></i></button></div>' +
            '<div class="modal-body">需要登录才可以观看哦，如没有账号请注册！</div>' +
            '<div class="modal-foot">' +
            '<a class="btn btn-ghost" href="register.php">前往注册</a>' +
            '<a class="btn btn-primary" href="login.php?redirect=' + encodeURIComponent(location.pathname + location.search) + '">前往登录</a>' +
            '</div></div>';
        document.body.appendChild(mask);
        mask.addEventListener('click', function (e) {
            if (e.target === mask || e.target.closest('[data-close]')) mask.remove();
        });
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest('[data-need-login]');
        if (link && !JAY.isLogin) {
            e.preventDefault();
            showLoginModal();
        }
    });

    /* ---------------- 公告弹窗 ---------------- */
    var noticeModal = $('#noticeModal');
    if (noticeModal) {
        var ver = noticeModal.getAttribute('data-ver') || '';
        var noticeClosed = false;
        var checkbox = $('#noticeNoMore');

        function closeNotice() {
            noticeModal.remove();
            if (checkbox && checkbox.checked) {
                var d = new Date();
                d.setTime(d.getTime() + 365 * 86400 * 1000);
                document.cookie = 'jay_notice_ack=' + encodeURIComponent(ver) + ';path=/;expires=' + d.toGMTString();
            }
            noticeClosed = true;
        }
        $$('#noticeModal [data-close-notice]').forEach(function (btn) {
            btn.addEventListener('click', closeNotice);
        });
        noticeModal.addEventListener('click', function (e) {
            if (e.target === noticeModal) closeNotice();
        });
    }

    /* ---------------- 登录页提示弹窗 ---------------- */
    var loginPrompt = $('#loginPrompt');
    if (loginPrompt) {
        loginPrompt.addEventListener('click', function (e) {
            if (e.target === loginPrompt || e.target.closest('[data-close]')) loginPrompt.remove();
        });
    }

    /* ---------------- 详情页：收藏 ---------------- */
    /* 季切换与音轨切换已改为纯链接（<a href>），不依赖 JS */

    var favBtn = $('#favBtn');
    if (favBtn) {
        favBtn.addEventListener('click', function () {
            if (!JAY.isLogin) { showLoginModal(); return; }
            api('toggle_favorite', {
                media_type: favBtn.getAttribute('data-type'),
                tmdb_id: favBtn.getAttribute('data-id'),
                title: favBtn.getAttribute('data-title'),
                poster: favBtn.getAttribute('data-poster')
            }).then(function (res) {
                if (res.ok) {
                    favBtn.classList.toggle('btn-ghost', !res.faved);
                    favBtn.classList.toggle('btn-primary', res.faved);
                    var label = favBtn.querySelector('span');
                    if (label) label.textContent = res.faved ? '已收藏' : '收藏';
                    toast(res.faved ? '已加入收藏' : '已取消收藏', true);
                } else if (res.need_login) { showLoginModal(); }
                else { toast(res.msg || '操作失败'); }
            });
        });
    }

    /* ---------------- 播放页：观看进度心跳 ---------------- */
    var playerBox = $('#playerBox');
    if (playerBox) {
        var posEl = $('#watchPos');
        var position = parseInt(playerBox.getAttribute('data-position') || '0', 10) || 0;
        var historyKey = {
            m: playerBox.getAttribute('data-m'),
            t: playerBox.getAttribute('data-t'),
            s: playerBox.getAttribute('data-s') || 1,
            e: playerBox.getAttribute('data-e') || 1
        };

        function fmt(sec) {
            var h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60), s = sec % 60;
            return (h > 0 ? h + ':' : '') + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
        }
        setInterval(function () {
            if (document.visibilityState === 'visible') {
                position++;
                if (posEl) posEl.textContent = fmt(position);
                if (position % 15 === 0) sendHeartbeat(false);
            }
        }, 1000);

        function sendHeartbeat(sync) {
            var body = new FormData();
            body.append('action', 'heartbeat');
            body.append('csrf', JAY.csrf);
            body.append('media_type', historyKey.m);
            body.append('tmdb_id', historyKey.t);
            body.append('season', historyKey.s);
            body.append('episode', historyKey.e);
            body.append('position', position);
            body.append('title', playerBox.getAttribute('data-title') || '');
            body.append('poster', playerBox.getAttribute('data-poster') || '');
            body.append('episode_name', playerBox.getAttribute('data-ename') || '');
            if (sync && navigator.sendBeacon) {
                navigator.sendBeacon('api.php', body);
            } else {
                fetch('api.php', { method: 'POST', body: body, credentials: 'same-origin', keepalive: true }).catch(function () {});
            }
        }
        window.addEventListener('pagehide', function () { sendHeartbeat(true); });
        sendHeartbeat(false);
    }

    /* ---------------- 反馈系统 ---------------- */
    function bindLikeButtons(root) {
        $$('.fb-like', root || document).forEach(function (btn) {
            if (btn._bind) return; btn._bind = true;
            btn.addEventListener('click', function () {
                if (!JAY.isLogin) { showLoginModal(); return; }
                api('like_feedback', { id: btn.getAttribute('data-id') }).then(function (res) {
                    if (res.ok) {
                        btn.classList.toggle('liked', res.liked);
                        var cnt = btn.querySelector('span:last-child');
                        if (cnt) cnt.textContent = res.count;
                    } else if (res.need_login) { showLoginModal(); }
                    else { toast(res.msg || '操作失败'); }
                });
            });
        });
    }

    function bindReplyForms(root) {
        $$('.fb-reply-form', root || document).forEach(function (form) {
            if (form._bind) return; form._bind = true;
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (!JAY.isLogin) { showLoginModal(); return; }
                var input = form.querySelector('input');
                var content = (input ? input.value : '').trim();
                if (!content) { toast('请输入回复内容'); return; }
                api('reply_feedback', { id: form.getAttribute('data-id'), content: content }).then(function (res) {
                    if (res.ok) {
                        input.value = '';
                        toast('回复成功', true);
                        // 刷新整页回复区域
                        var card = form.closest('.fb-card');
                        if (card) loadReplies(card, true);
                    } else if (res.need_login) { showLoginModal(); }
                    else { toast(res.msg || '回复失败'); }
                });
            });
        });
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function renderReply(r) {
        var avatarHtml = r.avatar
            ? '<img class="fb-avatar" src="' + esc(r.avatar) + '" alt="">'
            : '<span class="fb-avatar">' + esc((r.username || '?').charAt(0).toUpperCase()) + '</span>';
        var badge = r.is_admin == 1 ? ' <span class="badge-dev">开发者</span>' : '';
        var adminCls = r.is_admin == 1 ? ' admin-reply' : '';
        return '<div class="fb-reply' + adminCls + '">' + avatarHtml +
            '<div class="fb-reply-b"><div class="fb-reply-top">' +
            '<span class="fb-reply-name">' + esc(r.username) + '</span>' + badge +
            (r.is_admin == 1 ? '<span class="admin-tag">官方回复</span>' : '') +
            '<span class="fb-reply-time">' + esc(r.time) + '</span></div>' +
            '<div class="fb-reply-txt">' + esc(r.content) + '</div></div></div>';
    }

    function loadReplies(card, expandAll) {
        var id = card.getAttribute('data-id');
        var box = card.querySelector('.fb-replies-box');
        if (!box) return;
        fetch('api.php?action=get_replies&id=' + id, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok) return;
                var replies = res.replies || [];
                var total = replies.length;
                var html = '';
                var showCount = expandAll || total <= 3 ? total : 3;
                for (var i = 0; i < showCount; i++) html += renderReply(replies[i]);
                if (total > 3 && !expandAll) {
                    html += '<button class="fb-expand" data-expand><i class="i i-caret-d"></i>展开全部 ' + total + ' 条回复</button>';
                }
                box.innerHTML = html;
                var expandBtn = box.querySelector('[data-expand]');
                if (expandBtn) {
                    expandBtn.addEventListener('click', function () { loadReplies(card, true); });
                }
            });
    }

    $$('.fb-card').forEach(function (card) {
        loadReplies(card, false);
    });
    bindLikeButtons();
    bindReplyForms();

    /* ---------------- 个人中心 ---------------- */
    $$('.prof-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            $$('.prof-tab').forEach(function (t) { t.classList.toggle('on', t === tab); });
            $$('.prof-pane').forEach(function (p) { p.style.display = 'none'; });
            var pane = $('#' + tab.getAttribute('data-pane'));
            if (pane) pane.style.display = '';
        });
    });

    $$('.del-fav').forEach(function (btn) {
        btn.addEventListener('click', function () {
            api('remove_favorite', { id: btn.getAttribute('data-id') }).then(function (res) {
                if (res.ok) { var row = btn.closest('.fav-item'); if (row) row.remove(); toast('已删除收藏', true); }
                else toast(res.msg || '删除失败');
            });
        });
    });

    $$('.del-hist').forEach(function (btn) {
        btn.addEventListener('click', function () {
            api('remove_history', { id: btn.getAttribute('data-id') }).then(function (res) {
                if (res.ok) { var row = btn.closest('.hist-row'); if (row) row.remove(); toast('已删除记录', true); }
                else toast(res.msg || '删除失败');
            });
        });
    });

    var avatarInput = $('#avatarInput');
    if (avatarInput) {
        avatarInput.addEventListener('change', function () {
            if (avatarInput.files.length) $('#avatarForm').submit();
        });
    }

    /* ---------------- 通用确认删除（后台） ---------------- */
    window.jayConfirm = function (msg) { return window.confirm(msg); };
})();
