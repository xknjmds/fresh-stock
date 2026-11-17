/**
 * dashboard.js — shadcn-style Chart.js charts for the dashboard.
 * Reads data from window.dashboardData (set in index.php) and colours
 * from the CSS design tokens, so charts follow light / dark mode.
 */
(function () {
    'use strict';
    var data = window.dashboardData;
    if (!data || typeof Chart === 'undefined') return;

    var charts = [];

    /** Read an HSL token from :root, e.g. token('--chart-1', .5) */
    function token(name, alpha) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return alpha === undefined ? 'hsl(' + v + ')' : 'hsl(' + v + ' / ' + alpha + ')';
    }

    function tooltipStyle() {
        return {
            backgroundColor: token('--popover'),
            titleColor: token('--foreground'),
            bodyColor: token('--muted-foreground'),
            borderColor: token('--border'),
            borderWidth: 1,
            padding: 10,
            cornerRadius: 8,
            boxPadding: 4,
            usePointStyle: true,
            titleFont: { weight: '600', size: 12 },
            bodyFont: { size: 12 }
        };
    }

    function render() {
        charts.forEach(function (c) { c.destroy(); });
        charts = [];

        Chart.defaults.font.family = "'Geist', ui-sans-serif, system-ui, sans-serif";
        Chart.defaults.font.size = 12;
        Chart.defaults.color = token('--muted-foreground');

        /* ---- Bar chart: stock in vs out ---- */
        var mv = document.getElementById('movementChart');
        if (mv) {
            charts.push(new Chart(mv, {
                type: 'bar',
                data: {
                    labels: data.days,
                    datasets: [
                        { label: 'Stock in', data: data.in, backgroundColor: token('--chart-1'), hoverBackgroundColor: token('--chart-1', .85), borderRadius: 4, borderSkipped: false, categoryPercentage: .6, barPercentage: .85 },
                        { label: 'Stock out', data: data.out, backgroundColor: token('--chart-2'), hoverBackgroundColor: token('--chart-2', .85), borderRadius: 4, borderSkipped: false, categoryPercentage: .6, barPercentage: .85 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    animation: {
                        duration: 900,
                        easing: 'easeOutQuart',
                        delay: function (ctx) { return ctx.type === 'data' && ctx.mode === 'default' ? ctx.dataIndex * 60 + ctx.datasetIndex * 30 : 0; }
                    },
                    plugins: { legend: { display: false }, tooltip: tooltipStyle() },
                    scales: {
                        x: { grid: { display: false }, border: { display: false }, ticks: { padding: 6 } },
                        y: {
                            beginAtZero: true,
                            border: { display: false },
                            grid: { color: token('--border'), drawTicks: false },
                            ticks: { precision: 0, padding: 10, maxTicksLimit: 5 }
                        }
                    }
                }
            }));
        }

        /* ---- Doughnut: units by category ---- */
        var cat = document.getElementById('categoryChart');
        if (cat) {
            var base = ['--chart-1', '--chart-2', '--chart-3', '--chart-4', '--chart-5'];
            var colors = data.catLabels.map(function (_, i) {
                return token(base[i % base.length], i < base.length ? 1 : .55);
            });
            charts.push(new Chart(cat, {
                type: 'doughnut',
                data: {
                    labels: data.catLabels,
                    datasets: [{ data: data.catValues, backgroundColor: colors, borderColor: token('--card'), borderWidth: 3, hoverOffset: 6, borderRadius: 4 }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '72%',
                    animation: { animateRotate: true, duration: 1100, easing: 'easeOutQuart' },
                    plugins: { legend: { display: false }, tooltip: tooltipStyle() }
                }
            }));

            // Custom legend (top 5 categories) with animated bars
            var legend = document.getElementById('catLegend');
            if (legend) {
                var total = data.catValues.reduce(function (a, b) { return a + b; }, 0) || 1;
                legend.innerHTML = data.catLabels.slice(0, 5).map(function (label, i) {
                    var pct = Math.round(data.catValues[i] / total * 100);
                    return '<div class="d-flex align-items-center gap-2 text-sm">' +
                        '<span class="rounded-1 flex-shrink-0" style="width:8px;height:8px;background:' + colors[i] + '"></span>' +
                        '<span class="flex-grow-1 text-truncate">' + label.replace(/</g, '&lt;') + '</span>' +
                        '<span class="text-muted-fg tabular">' + data.catValues[i] + '</span>' +
                        '<span class="tabular fw-medium text-end" style="width:2.6rem">' + pct + '%</span></div>';
                }).join('');
            }
        }
    }

    render();
    document.addEventListener('themechange', function () { setTimeout(render, 50); });
})();
