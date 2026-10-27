/**
 * Greywarden chart initialiser for Chart.js 4.
 *
 * Every <canvas data-gw-chart='{...}'> rendered by app\widgets\Chart is turned
 * into a chart. Colours are NOT hard-coded: each dataset has a "tone" which
 * maps to the CSS custom property --gw-chart-{tone} (see greywarden.css).
 * Axis/grid/text/tooltip colours come from --gw-chart-text, --gw-chart-grid,
 * --gw-chart-font and --gw-chart-tooltip-{bg,border,text}. Restyle charts entirely from CSS.
 */
(function () {
    'use strict';

    function cssVar(name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name);
        return (v && v.trim()) || fallback;
    }

    function toneColor(tone) {
        return cssVar('--gw-chart-' + tone, cssVar('--gw-chart-neutral', '#8b949e'));
    }

    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function build(canvas) {
        var cfg;
        try { cfg = JSON.parse(canvas.getAttribute('data-gw-chart')); } catch (e) { return; }
        var text = cssVar('--gw-chart-text', '#8f9cb3');
        var grid = cssVar('--gw-chart-grid', 'rgba(255,255,255,0.08)');
        var font = cssVar('--gw-chart-font', 'system-ui, sans-serif');
        var tipBg = cssVar('--gw-chart-tooltip-bg', '#1a2436');
        var tipBorder = cssVar('--gw-chart-tooltip-border', '#2a374d');
        var tipText = cssVar('--gw-chart-tooltip-text', '#f2f5fa');

        window.Chart.defaults.color = text;
        window.Chart.defaults.font.family = font;
        window.Chart.defaults.font.size = 11;
        window.Chart.defaults.borderColor = grid;

        var isBar = cfg.type === 'bar';
        var datasets = (cfg.datasets || []).map(function (ds) {
            var color = toneColor(ds.tone || 'neutral');
            var out = Object.assign({}, ds);
            delete out.tone;
            out.backgroundColor = out.backgroundColor || color;
            out.hoverBackgroundColor = out.hoverBackgroundColor || color;
            out.borderColor = out.borderColor || color;
            out.borderWidth = out.borderWidth === undefined ? 0 : out.borderWidth;
            out.borderRadius = out.borderRadius === undefined ? (cfg.stacked ? 2 : 4) : out.borderRadius;
            if (isBar) {
                out.maxBarThickness = cfg.horizontal ? 22 : 30;
                out.categoryPercentage = 0.72;
                out.barPercentage = 0.9;
            }
            return out;
        });

        var axisIndex = cfg.horizontal ? 'y' : 'x';
        var valueAxis = cfg.horizontal ? 'x' : 'y';
        var scale = function (isValue) {
            return {
                stacked: !!cfg.stacked,
                beginAtZero: true,
                border: { display: false },
                grid: { color: grid, display: isValue, drawTicks: false },
                ticks: { precision: 0, padding: 8, color: text }
            };
        };
        var scales = {};
        scales[valueAxis] = scale(true);
        scales[axisIndex] = scale(false);

        var options = {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: axisIndex,
            animation: reducedMotion ? false : { duration: 500 },
            layout: { padding: { top: 4 } },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'bottom',
                    align: 'start',
                    labels: { usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 8, boxHeight: 8, padding: 14, color: text, font: { size: 11.5 } }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    backgroundColor: tipBg,
                    borderColor: tipBorder,
                    borderWidth: 1,
                    titleColor: tipText,
                    bodyColor: tipText,
                    footerColor: text,
                    padding: 10,
                    cornerRadius: 6,
                    caretSize: 5,
                    boxWidth: 8,
                    boxHeight: 8,
                    boxPadding: 4,
                    usePointStyle: true,
                    titleFont: { weight: '600', size: 12 },
                    bodyFont: { size: 12 }
                }
            },
            scales: scales,
            onClick: function (evt, elements) {
                if (cfg.links && cfg.links.length && elements.length) {
                    var url = cfg.links[elements[0].index];
                    if (url) { window.location.href = url; }
                }
            },
            onHover: function (evt, elements) {
                evt.native.target.style.cursor = (cfg.links && cfg.links.length && elements.length) ? 'pointer' : 'default';
            }
        };
        if (cfg.type === 'doughnut' || cfg.type === 'pie') {
            delete options.scales;
        }
        Object.assign(options, cfg.options || {});

        new window.Chart(canvas, { type: cfg.type, data: { labels: cfg.labels, datasets: datasets }, options: options });
    }

    function init() {
        if (!window.Chart) { return; }
        document.querySelectorAll('canvas[data-gw-chart]').forEach(build);
    }

    // Wait for the web font so canvas text uses it (falls back after a short delay).
    function start() {
        if (document.fonts && document.fonts.ready) {
            var done = false;
            var go = function () { if (!done) { done = true; init(); } };
            document.fonts.ready.then(go);
            setTimeout(go, 1500);
        } else {
            init();
        }
    }

    if (document.readyState !== 'loading') { start(); } else { document.addEventListener('DOMContentLoaded', start); }
})();
