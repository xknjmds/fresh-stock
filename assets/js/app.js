/**
 * app.js — FreshStock front-end behaviour
 *  - theme toggle (light / dark, persisted)
 *  - sonner-style toasts
 *  - delete confirmation (Bootstrap modal "alert dialog", POST + CSRF)
 *  - Ctrl/⌘+K command menu
 *  - top progress bar on navigation
 *  - scroll reveal, count-up numbers, progress bars, staggered rows
 *  - animated segmented tabs, form validation, live table filter
 */
(function () {
    'use strict';

    var root = document.documentElement;
    var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
    var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };

    /* ---------- Top progress bar ---------- */
    var bar = null, barTimer = null;
    function progressStart() {
        bar = bar || $('#nprogress');
        if (!bar) return;
        bar.style.opacity = '1';
        bar.style.width = '0';
        requestAnimationFrame(function () { bar.style.width = '35%'; });
        clearInterval(barTimer);
        barTimer = setInterval(function () {
            var w = parseFloat(bar.style.width) || 0;
            if (w < 90) bar.style.width = (w + (90 - w) * 0.1) + '%';
        }, 250);
    }
    window.addEventListener('pageshow', function () {
        // Reset when coming back via browser history
        if (bar) { clearInterval(barTimer); bar.style.opacity = '0'; bar.style.width = '0'; }
    });

    document.addEventListener('DOMContentLoaded', function () {

        /* ---------- Theme ---------- */
        function syncThemeIcons() {
            var dark = root.getAttribute('data-bs-theme') === 'dark';
            $$('.theme-icon-light').forEach(function (i) { i.className = 'bi ' + (dark ? 'bi-moon-stars' : 'bi-sun') + ' theme-icon-light'; });
        }
        syncThemeIcons();
        $$('[data-theme-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
                // Smooth cross-fade where supported (View Transitions API)
                var apply = function () {
                    root.setAttribute('data-bs-theme', next);
                    try { localStorage.setItem('theme', next); } catch (e) {}
                    syncThemeIcons();
                    document.dispatchEvent(new CustomEvent('themechange', { detail: next }));
                };
                if (document.startViewTransition) document.startViewTransition(apply); else apply();
            });
        });

        /* ---------- Toasts ---------- */
        function dismissToast(t) {
            if (!t || t.classList.contains('leaving')) return;
            t.classList.add('leaving');
            setTimeout(function () { t.remove(); }, 300);
        }
        $$('.s-toast').forEach(function (t, i) {
            t.style.animationDelay = (i * 80) + 'ms';
            var timer = setTimeout(function () { dismissToast(t); }, 4500 + i * 300);
            t.addEventListener('mouseenter', function () { clearTimeout(timer); });
            t.addEventListener('mouseleave', function () { timer = setTimeout(function () { dismissToast(t); }, 2000); });
            $('.t-close', t).addEventListener('click', function () { dismissToast(t); });
        });

        /* ---------- Progress bar triggers ---------- */
        document.addEventListener('click', function (e) {
            var a = e.target.closest('a[href]');
            if (!a || e.ctrlKey || e.metaKey || e.shiftKey || a.target === '_blank') return;
            var href = a.getAttribute('href');
            if (!href || href.charAt(0) === '#' || href.indexOf('export.php') === 0 || href.indexOf('mailto:') === 0 || href.indexOf('tel:') === 0) return;
            progressStart();
        });

        /* ---------- Scroll reveal ---------- */
        var reveals = $$('.reveal');
        if ('IntersectionObserver' in window) {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (en) {
                    if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); }
                });
            }, { threshold: 0.08 });
            reveals.forEach(function (el, i) {
                el.style.transitionDelay = (el.dataset.delay || Math.min(i, 8) * 50) + 'ms';
                io.observe(el);
            });
        } else {
            reveals.forEach(function (el) { el.classList.add('in'); });
        }

        /* ---------- Count-up numbers ---------- */
        $$('[data-count]').forEach(function (el) {
            var target = parseFloat(el.dataset.count) || 0;
            var dec = parseInt(el.dataset.decimals || '0', 10);
            var prefix = el.dataset.prefix || '';
            var start = null, dur = 1100;
            var fmt = function (v) { return prefix + v.toLocaleString(undefined, { minimumFractionDigits: dec, maximumFractionDigits: dec }); };
            requestAnimationFrame(function step(ts) {
                if (!start) start = ts;
                var p = Math.min((ts - start) / dur, 1);
                el.textContent = fmt(target * (1 - Math.pow(1 - p, 4))); // ease-out quart
                if (p < 1) requestAnimationFrame(step);
            });
        });

        /* ---------- Progress bars ---------- */
        setTimeout(function () {
            $$('.s-progress > span[data-width]').forEach(function (b) { b.style.width = b.dataset.width + '%'; });
        }, 200);

        /* ---------- Staggered table rows ---------- */
        $$('table.animate-rows tbody tr').forEach(function (tr, i) {
            tr.classList.add('row-anim');
            tr.style.animationDelay = Math.min(i * 30, 600) + 'ms';
        });

        /* ---------- Segmented tabs with sliding indicator ---------- */
        $$('.s-tabs').forEach(function (tabs) {
            var ind = document.createElement('span');
            ind.className = 'indicator';
            tabs.prepend(ind);
            function move(el, instant) {
                if (!el) return;
                if (instant) ind.style.transition = 'none';
                ind.style.width = el.offsetWidth + 'px';
                ind.style.transform = 'translateX(' + el.offsetLeft + 'px)';
                if (instant) { ind.offsetHeight; ind.style.transition = ''; }
            }
            var active = $('.active', tabs);
            move(active, true);
            $$('a, button', tabs).forEach(function (t) {
                t.addEventListener('click', function () { $$('.active', tabs).forEach(function (x) { x.classList.remove('active'); }); t.classList.add('active'); move(t); });
            });
            window.addEventListener('resize', function () { move($('.active', tabs), true); });
        });

        /* ---------- Delete confirmation dialog ---------- */
        var dialogEl = $('#confirmDialog');
        var dialog = dialogEl ? bootstrap.Modal.getOrCreateInstance(dialogEl) : null;
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.btn-delete');
            if (!btn || !dialog) return;
            e.preventDefault();
            $('#confirmTitle').textContent = 'Delete ' + (btn.dataset.name ? '"' + btn.dataset.name + '"' : 'this item') + '?';
            $('#confirmText').textContent = btn.dataset.warning || 'This action cannot be undone. This will permanently remove the record from the database.';
            $('#deleteForm').action = btn.dataset.url;
            $('#deleteId').value = btn.dataset.id;
            dialog.show();
        });
        if (dialogEl) {
            dialogEl.addEventListener('shown.bs.modal', function () { $('#confirmBtn').focus(); });
            $('#deleteForm').addEventListener('submit', function () {
                var b = $('#confirmBtn');
                b.disabled = true;
                b.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Deleting';
                progressStart();
            });
        }

        /* ---------- Form validation + loading state ---------- */
        $$('form.needs-validation').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (!form.checkValidity()) {
                    e.preventDefault();
                    e.stopPropagation();
                    form.classList.add('was-validated');
                    var bad = $(':invalid', form);
                    if (bad) {
                        bad.focus();
                        bad.animate([{ transform: 'translateX(0)' }, { transform: 'translateX(-4px)' }, { transform: 'translateX(4px)' }, { transform: 'translateX(0)' }], { duration: 240 });
                    }
                    return;
                }
                var btn = $('[type="submit"]', form);
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> ' + (btn.dataset.loading || 'Saving...');
                }
                progressStart();
            });
        });

        /* ---------- Live table filter ---------- */
        $$('[data-table-filter]').forEach(function (input) {
            var table = $(input.dataset.tableFilter);
            if (!table) return;
            input.addEventListener('input', function () {
                var q = input.value.toLowerCase().trim(), shown = 0;
                $$('tbody tr:not(.no-filter)', table).forEach(function (tr) {
                    var hit = tr.textContent.toLowerCase().indexOf(q) !== -1;
                    tr.style.display = hit ? '' : 'none';
                    if (hit) shown++;
                });
                var empty = $('.filter-empty', table);
                if (empty) empty.style.display = shown ? 'none' : '';
            });
        });

        /* ---------- Tooltips ---------- */
        $$('[data-bs-toggle="tooltip"]').forEach(function (el) { new bootstrap.Tooltip(el, { trigger: 'hover' }); });

        /* ---------- Command menu (Ctrl/⌘ + K) ---------- */
        var cmdEl = $('#cmdk');
        if (cmdEl && window.cmdData) {
            var cmd = bootstrap.Modal.getOrCreateInstance(cmdEl);
            var input = $('#cmdInput'), list = $('#cmdList');
            var items = [], sel = 0;

            var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };

            function render() {
                var q = input.value.toLowerCase().trim();
                var pages = cmdData.pages.filter(function (p) { return p.label.toLowerCase().indexOf(q) !== -1; });
                var prods = q ? cmdData.products.filter(function (p) { return (p.name + ' ' + p.sku).toLowerCase().indexOf(q) !== -1; }).slice(0, 8) : [];
                items = [];
                var html = '';
                if (pages.length) {
                    html += '<div class="cmd-group">Pages</div>';
                    pages.forEach(function (p) { items.push(p.url); html += '<div class="cmd-item" data-i="' + (items.length - 1) + '"><i class="bi ' + p.icon + '"></i>' + esc(p.label) + '</div>'; });
                }
                if (prods.length) {
                    html += '<div class="cmd-group">Products</div>';
                    prods.forEach(function (p) {
                        items.push('product_form.php?id=' + p.id);
                        html += '<div class="cmd-item" data-i="' + (items.length - 1) + '"><i class="bi bi-box"></i>' + esc(p.name) + '<span class="meta font-mono">' + esc(p.sku) + ' · ' + p.qty + '</span></div>';
                    });
                }
                list.innerHTML = html || '<div class="cmd-empty">No results found.</div>';
                sel = 0;
                highlight();
            }
            function highlight() {
                $$('.cmd-item', list).forEach(function (el, i) {
                    el.classList.toggle('selected', i === sel);
                    if (i === sel) el.scrollIntoView({ block: 'nearest' });
                });
            }
            function go(i) { if (items[i]) { progressStart(); location.href = items[i]; } }

            input.addEventListener('input', render);
            input.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowDown') { e.preventDefault(); sel = Math.min(sel + 1, items.length - 1); highlight(); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); sel = Math.max(sel - 1, 0); highlight(); }
                else if (e.key === 'Enter') { e.preventDefault(); go(sel); }
            });
            list.addEventListener('mousemove', function (e) { var it = e.target.closest('.cmd-item'); if (it) { sel = +it.dataset.i; highlight(); } });
            list.addEventListener('click', function (e) { var it = e.target.closest('.cmd-item'); if (it) go(+it.dataset.i); });
            cmdEl.addEventListener('show.bs.modal', function () { input.value = ''; render(); });
            cmdEl.addEventListener('shown.bs.modal', function () { input.focus(); });
            document.addEventListener('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); cmd.toggle(); }
            });
        }
    });
})();
