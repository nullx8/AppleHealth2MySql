/*!
 * health-charts.js – draws health charts into the page (no iframes, no jQuery).
 *
 * Usage (anywhere in a page / WordPress post):
 *   <div data-health-chart="bp" data-period="last" data-periods="last,1,7,30,90,all" data-height="380"></div>
 *   <div data-health-chart="heartrate" data-period="36"></div>
 *   <div data-health-chart="sleep"></div>            <div data-health-chart="weight"></div>
 *   <div data-health-chart="respiratory"></div>
 *   <div data-health-chart="activity" data-goals="300,30,12"></div>       move kcal, exercise min, stand h
 *   <div data-health-chart="steps" data-periods="30,months"></div>
 *   <div data-health-chart="fitness" data-periods="months,weeks"></div>
 *   <div data-health-chart="heatmap" data-period="56" data-metric="steps"></div>   (data-metric="hr" for heart rate)
 *   <script src="https://dev.0x8.in.th/lib/health/health-charts.js" defer></script>
 *
 * Data comes from chartdata.php next to this script. Chart.js is loaded automatically if missing.
 *
 * Colours follow the page theme via CSS variables (set on :root or any parent):
 *   --health-chart-1 / -2      main / second series     (default: currentColor / lighter mix)
 *   --health-chart-text / -grid  axis labels / grid lines
 *   --health-chart-move / -exercise / -stand   activity rings (defaults: orange / green / blue)
 * Period buttons get the class "health-chart-btn" (+ "is-active").
 */
(function () {
  'use strict';

  var script = document.currentScript;
  var BASE = script ? script.src.replace(/[^\/]*$/, '') : '';
  // <script src="…" data-endpoint="/php/text/get-vitals-bits.php?bit=chart"> lets a local proxy serve the data
  var DATA_URL = (script && script.getAttribute('data-endpoint')) || BASE + 'chartdata.php';
  var SEP = DATA_URL.indexOf('?') >= 0 ? '&' : '?';
  var CHARTJS = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js';
  var SVGNS = 'http://www.w3.org/2000/svg';

  var PERIOD_LABELS = { last: 'Last', '1': 'Daily avg', '7': '7 days', '24': '24 h', '25': '25 h', '30': '30 days',
                        '36': '48 h', '56': '8 weeks', '72': '3 days', '90': '3 months', all: 'All',
                        months: 'Monthly', weeks: 'Weekly' };

  // ---------------------------------------------------------------- helpers

  function loadChartJs(cb) {
    if (window.Chart) { return cb(); }
    var s = document.createElement('script');
    s.src = CHARTJS;
    s.onload = cb;
    document.head.appendChild(s);
  }

  function cssVar(el, name, fallback) {
    var v = getComputedStyle(el).getPropertyValue(name).trim();
    return v || fallback;
  }

  function alpha(color, a) {
    return 'color-mix(in srgb, ' + color + ' ' + Math.round(a * 100) + '%, transparent)';
  }

  // Canvas needs plain colour strings: resolve color-mix()/var() through the browser.
  var probe;
  function resolve(color) {
    if (!/color-mix|var\(/.test(color)) { return color; }
    probe = probe || Object.assign(document.createElement('span'), { style: 'display:none' });
    if (!probe.isConnected) { document.body.appendChild(probe); }
    probe.style.color = color;
    return getComputedStyle(probe).color;
  }

  function colors(el) {
    var current = getComputedStyle(el).color;
    var c1 = cssVar(el, '--health-chart-1', current);
    return {
      1: resolve(c1),
      2: resolve(cssVar(el, '--health-chart-2', 'color-mix(in srgb, ' + c1 + ' 55%, white)')),
      text: resolve(cssVar(el, '--health-chart-text', current)),
      grid: resolve(cssVar(el, '--health-chart-grid', alpha(current, 0.12))),
      move: resolve(cssVar(el, '--health-chart-move', '#f39a32')),
      exercise: resolve(cssVar(el, '--health-chart-exercise', '#35e07a')),
      stand: resolve(cssVar(el, '--health-chart-stand', c1))
    };
  }

  function fetchData(set, period, extra) {
    var url = DATA_URL + SEP + 'set=' + encodeURIComponent(set) + '&p=' + encodeURIComponent(period) + (extra || '');
    return fetch(url).then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); });
  }

  function el(tag, attrs, parent) {
    var e = tag.indexOf('svg:') === 0 ? document.createElementNS(SVGNS, tag.slice(4)) : document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') { e.textContent = attrs[k]; } else { e.setAttribute(k, attrs[k]); }
    });
    if (parent) { parent.appendChild(e); }
    return e;
  }

  function fmt(n) { return Number(n).toLocaleString('en-US'); }

  function baseOptions(c, extra) {
    var o = {
      responsive: true, maintainAspectRatio: false, animation: false,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { display: false, labels: { color: c.text, boxWidth: 12 } } },
      scales: {
        x: { grid: { display: false }, ticks: { color: c.text, maxRotation: 0, autoSkipPadding: 12 } },
        y: { grid: { color: c.grid }, ticks: { color: c.text } }
      }
    };
    return Object.assign(o, extra || {});
  }

  function line(label, data, color, opts) {
    return Object.assign({ type: 'line', label: label, data: data, borderColor: color, backgroundColor: resolve(alpha(color, 0.25)),
      fill: false, tension: 0.4, cubicInterpolationMode: 'monotone', pointRadius: 0, borderWidth: 2, spanGaps: true }, opts || {});
  }

  function bar(label, data, color, opts) {
    return Object.assign({ type: 'bar', label: label, data: data, backgroundColor: resolve(alpha(color, 0.75)), borderWidth: 0 }, opts || {});
  }

  // ---------------------------------------------------------------- chart definitions
  // chart(data, colors, box) -> Chart.js config     custom(wrap, data, colors, box) -> draws itself

  var SETS = {
    bp: {
      period: 'last', height: 380,
      chart: function (d, c) {
        return { type: 'line', data: { labels: d.map(function (r) { return r.date; }), datasets: [
          line('Systolic', d.map(function (r) { return r.SYS; }), c[2], { fill: 'origin', pointRadius: 2 }),
          line('Diastolic', d.map(function (r) { return r.DIA; }), c[1], { fill: 'origin', pointRadius: 2 })
        ] }, options: baseOptions(c, { plugins: { legend: { display: true, labels: { color: c.text, boxWidth: 12 } } } }) };
      }
    },
    heartrate: {
      period: '36', height: 220,
      chart: function (d, c) {
        return { type: 'line', data: { labels: d.map(function (r) { return r.date; }),
          datasets: [line('Pulse', d.map(function (r) { return r.heartrate; }), c[1])] }, options: baseOptions(c) };
      }
    },
    sleep: {
      period: '90', height: 220,
      chart: function (d, c) {
        return { type: 'bar', data: { labels: d.map(function (r) { return r.date; }),
          datasets: [bar('Sleeping hours', d.map(function (r) { return r.sleep; }), c[1])] }, options: baseOptions(c) };
      }
    },
    weight: {
      period: '90', height: 220,
      chart: function (d, c) {
        return { type: 'line', data: { labels: d.map(function (r) { return r.date; }),
          datasets: [line('Weight', d.map(function (r) { return r.avg; }), c[1], { pointRadius: 3 })] }, options: baseOptions(c) };
      }
    },
    respiratory: {
      period: '90', height: 220,
      chart: function (d, c) {
        return { type: 'bar', data: { labels: d.map(function (r) { return r.date; }),
          datasets: [bar('Respiratory rate', d.map(function (r) { return r.respiratory; }), c[1])] }, options: baseOptions(c) };
      }
    },

    // Steps: daily bars + 7-day average, or monthly average steps/day since 2015.
    steps: {
      period: '30', height: 240,
      chart: function (d, c, box, period) {
        var labels = d.map(function (r) { return r.date; });
        if (period === 'months') {
          return { type: 'bar', data: { labels: labels,
            datasets: [bar('Steps per day (monthly average)', d.map(function (r) { return r.steps; }), c[1])] },
            options: baseOptions(c) };
        }
        return { type: 'bar', data: { labels: labels, datasets: [
          line('7-day average', d.map(function (r) { return r.avg7; }), c[2], { order: 0 }),
          bar('Steps', d.map(function (r) { return r.steps; }), c[1], { order: 1 })
        ] }, options: baseOptions(c, { plugins: { legend: { display: true, labels: { color: c.text, boxWidth: 12 } } } }) };
      }
    },

    // Resting heart rate (left axis) and HRV (right axis).
    fitness: {
      period: 'months', height: 240,
      chart: function (d, c) {
        var o = baseOptions(c, { plugins: { legend: { display: true, labels: { color: c.text, boxWidth: 12 } } } });
        o.scales.y = { position: 'left', grid: { color: c.grid }, ticks: { color: c.text },
                       title: { display: true, text: 'resting bpm', color: c.text } };
        o.scales.y2 = { position: 'right', grid: { display: false }, ticks: { color: c.text },
                        title: { display: true, text: 'HRV ms', color: c.text } };
        return { type: 'line', data: { labels: d.map(function (r) { return r.date; }), datasets: [
          line('Resting heart rate', d.map(function (r) { return r.resting; }), c[1], { yAxisID: 'y', pointRadius: 2 }),
          line('Heart rate variability', d.map(function (r) { return r.hrv; }), c.move, { yAxisID: 'y2', pointRadius: 2 })
        ] }, options: o };
      }
    },

    // Activity: rings for today + last 7 days as % of goal.
    activity: {
      period: 'x', height: 0,
      custom: function (wrap, d, c, box) {
        var g = (box.getAttribute('data-goals') || '300,30,12').split(',').map(Number);
        var t = d.today;
        var rings = [
          { key: 'move', label: 'Move', value: t.move, goal: g[0], unit: 'kcal', color: c.move },
          { key: 'exercise', label: 'Exercise', value: t.exercise, goal: g[1], unit: 'min', color: c.exercise },
          { key: 'stand', label: 'Stand', value: t.stand, goal: g[2], unit: 'h', color: c.stand }
        ];
        wrap.innerHTML = '';
        var top = el('div', { class: 'hc-activity' }, wrap);

        // rings
        var svg = el('svg:svg', { viewBox: '0 0 120 120', class: 'hc-rings', role: 'img',
          'aria-label': rings.map(function (r) { return r.label + ' ' + r.value + '/' + r.goal + ' ' + r.unit; }).join(', ') }, top);
        rings.forEach(function (r, i) {
          var rad = 50 - i * 15, circ = 2 * Math.PI * rad, frac = Math.min(r.value / r.goal, 1);
          el('svg:circle', { cx: 60, cy: 60, r: rad, fill: 'none', stroke: resolve(alpha(r.color, 0.18)), 'stroke-width': 12 }, svg);
          var arc = el('svg:circle', { cx: 60, cy: 60, r: rad, fill: 'none', stroke: r.color, 'stroke-width': 12,
            'stroke-linecap': 'round', 'stroke-dasharray': (circ * frac) + ' ' + circ, transform: 'rotate(-90 60 60)' }, svg);
          if (frac === 0) { arc.setAttribute('stroke-width', 0); }
        });

        // figures
        var list = el('div', { class: 'hc-figures' }, top);
        rings.forEach(function (r) {
          var row = el('div', { class: 'hc-fig' }, list);
          el('span', { class: 'hc-dot', style: 'background:' + r.color }, row);
          el('span', { class: 'hc-label', text: r.label }, row);
          var v = el('span', { class: 'hc-value' }, row);
          el('strong', { text: fmt(r.value) }, v);
          v.appendChild(document.createTextNode(' / ' + fmt(r.goal) + ' ' + r.unit));
        });
        var extra = el('div', { class: 'hc-extra' }, list);
        [['Steps', fmt(t.steps)], ['Distance', t.km.toFixed(1) + ' km'], ['Floors', fmt(t.floors)]].forEach(function (x) {
          var s = el('span', {}, extra);
          el('small', { text: x[0] }, s);
          el('strong', { text: x[1] }, s);
        });
        if (t.until) {
          el('div', { class: 'hc-window', text: 'last 24 h · until ' + t.until }, list);
        }

        // last 7 days, % of goal
        var cw = el('div', { style: 'position:relative;height:130px;margin-top:12px' }, wrap);
        var canvas = el('canvas', {}, cw);
        var pct = function (k, goal) { return d.days.map(function (x) { return Math.round(Math.min(x[k] / goal, 2) * 100); }); };
        var o = baseOptions(c, { plugins: { legend: { display: false }, tooltip: { callbacks: {
          label: function (ctx) { var r = rings[ctx.datasetIndex], day = d.days[ctx.dataIndex];
            return r.label + ': ' + fmt(day[r.key]) + ' ' + r.unit + ' (' + ctx.raw + '%)'; } } } } });
        o.scales.y.ticks.callback = function (v) { return v + '%'; };
        o.scales.y.suggestedMax = 100;
        return new window.Chart(canvas, { type: 'bar', data: {
          labels: d.days.map(function (x) { return x.weekday; }),
          datasets: rings.map(function (r) { return bar(r.label, pct(r.key, r.goal), r.color, { borderRadius: 3 }); })
        }, options: o });
      }
    },

    // Smokes and coffees (70 mg caffeine per cup) per day, grouped bars like the activity days, one shared axis.
    intake: {
      period: '30', height: 260,
      chart: function (d, c, box, period) {
        var monthly = period === 'months';
        var o = baseOptions(c, { plugins: { legend: { display: true, labels: { color: c.text, boxWidth: 12 } },
          tooltip: { callbacks: { label: function (ctx) {
            var r = d[ctx.dataIndex], mg = ctx.datasetIndex ? r.caffeine : r.nicotine;
            var v = typeof ctx.raw === 'number' ? fmt(ctx.raw) : '–';
            return ctx.dataset.label + ': ' + v + (typeof mg === 'number' && mg > 0
              ? ' (' + fmt(mg) + ' mg ' + (ctx.datasetIndex ? 'caffeine' : 'nicotine') + ')' : '');
          } } } } });
        o.scales.y = { beginAtZero: true, grid: { color: c.grid }, ticks: { color: c.text, precision: 0 },
                       title: { display: true, text: monthly ? 'per day (avg)' : 'per day', color: c.text } };
        return { type: 'bar', data: {
          labels: d.map(function (r) { return monthly ? r.date : r.weekday.charAt(0) + ' ' + r.date; }),
          datasets: [
            bar('Smokes', d.map(function (r) { return r.smokes; }), c.move, { borderRadius: 3 }),
            bar('Coffees', d.map(function (r) { return r.coffee; }), c[1], { borderRadius: 3 })
          ] }, options: o };
      }
    },

    // ECG strip on "paper": 25 mm/s, 10 mm/mV, rows of 10 s (SVG, units = mm).
    // Fills the height the card gets (e.g. from a taller neighbour in the same row): the rows grow,
    // the grid stays square. Without a given height the rows are ±1 mV (20 mm).
    // Colours: --health-chart-ecg (trace, default text colour), --health-chart-ecg-grid (paper, default red).
    ecg: {
      period: 'last', height: 0,
      custom: function (wrap, d, c, box) {
        wrap.innerHTML = '';
        if (wrap._ro) { wrap._ro.disconnect(); }
        box.classList.add('hc-ecg-box');
        var secRow = parseFloat(box.getAttribute('data-row-seconds') || 10);
        var rows = Math.ceil(d.seconds / secRow), W = secRow * 25, perRow = Math.round(secRow * d.hz);
        var trace = cssVar(box, '--health-chart-ecg', getComputedStyle(box).color);
        var paper = cssVar(box, '--health-chart-ecg-grid', '#e5484d');
        var area = el('div', { class: 'hc-ecg-area' }, wrap);
        var cap = el('div', { class: 'hc-window' }, wrap);
        cap.textContent = d.date + ' · ' + d.classification + (d.bpm ? ' · ' + d.bpm + ' bpm' : '') + ' · 25 mm/s · 10 mm/mV';

        function draw() {
          var w = area.clientWidth, h = area.clientHeight;
          // row height in whole mm: fill the area (grid stays square), at least 16 mm
          var rowH = w > 0 && h > 0 ? Math.max(16, Math.floor(h / w * W / rows)) : 20, H = rows * rowH;
          area.innerHTML = '';
          var svg = el('svg:svg', { viewBox: '-0.5 -0.5 ' + (W + 1) + ' ' + (H + 1), class: 'hc-ecg', role: 'img',
            preserveAspectRatio: 'xMidYMid meet',
            'aria-label': 'ECG ' + d.date + ', ' + d.classification + (d.bpm ? ', ' + d.bpm + ' bpm' : '') }, area);
          var small = '', big = '', x, y;
          for (x = 0; x <= W; x += 1) { if (x % 5) { small += 'M' + x + ' 0V' + H; } else { big += 'M' + x + ' 0V' + H; } }
          for (y = 0; y <= H; y += 1) { if (y % 5) { small += 'M0 ' + y + 'H' + W; } else { big += 'M0 ' + y + 'H' + W; } }
          el('svg:path', { d: small, stroke: paper, 'stroke-opacity': 0.16, 'stroke-width': 0.1, fill: 'none' }, svg);
          el('svg:path', { d: big, stroke: paper, 'stroke-opacity': 0.42, 'stroke-width': 0.22, fill: 'none' }, svg);
          for (var r = 0; r < rows; r++) {
            var mid = r * rowH + rowH / 2, pts = [];
            d.uv.slice(r * perRow, (r + 1) * perRow).forEach(function (v, i) {
              var yy = Math.max(r * rowH + 0.5, Math.min((r + 1) * rowH - 0.5, mid - v / 100));
              pts.push((i * 25 / d.hz).toFixed(2) + ',' + yy.toFixed(2));
            });
            el('svg:polyline', { points: pts.join(' '), fill: 'none', stroke: trace, 'stroke-width': 1.6,
              'stroke-linejoin': 'round', 'vector-effect': 'non-scaling-stroke' }, svg);
            el('svg:text', { x: 1, y: r * rowH + 3.6, 'font-size': 2.6, fill: trace, 'fill-opacity': 0.6,
              text: (r * secRow) + ' s' }, svg);
          }
        }
        draw();
        if (window.ResizeObserver) {
          var last = area.clientWidth + 'x' + area.clientHeight, pending = false;
          wrap._ro = new ResizeObserver(function () {
            var now = area.clientWidth + 'x' + area.clientHeight;
            if (now === last || pending) { return; }
            pending = true;
            requestAnimationFrame(function () { pending = false; last = area.clientWidth + 'x' + area.clientHeight; draw(); });
          });
          wrap._ro.observe(area);
        }
        return null;
      }
    },

    // Heatmap: hours (rows) x days (columns), SVG.
    heatmap: {
      period: '56', height: 0,
      extra: function (box) { return '&m=' + (box.getAttribute('data-metric') === 'hr' ? 'hr' : 'steps'); },
      custom: function (wrap, d, c) {
        wrap.innerHTML = '';
        var n = d.days.length, cell = 10, gap = 2, left = 26, bottom = 16;
        var W = left + n * (cell + gap), H = 24 * (cell + gap) + bottom;
        var idx = {}; d.days.forEach(function (day, i) { idx[day] = i; });
        var max = 0, min = Infinity;
        d.cells.forEach(function (x) { max = Math.max(max, x[2]); min = Math.min(min, x[2]); });
        if (d.metric === 'steps') { min = 0; }
        var svg = el('svg:svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'hc-heatmap', preserveAspectRatio: 'xMinYMin meet' }, wrap);
        // empty grid
        for (var i = 0; i < n; i++) {
          for (var h = 0; h < 24; h++) {
            el('svg:rect', { x: left + i * (cell + gap), y: h * (cell + gap), width: cell, height: cell, rx: 2,
              fill: resolve(alpha(c.text, 0.07)) }, svg);
          }
        }
        // values
        d.cells.forEach(function (x) {
          var i = idx[x[0]]; if (i === undefined || !(x[2] > 0)) { return; }
          var t = max > min ? (x[2] - min) / (max - min) : 1;
          var r = el('svg:rect', { x: left + i * (cell + gap), y: x[1] * (cell + gap), width: cell, height: cell, rx: 2,
            fill: resolve(alpha(c[1], 0.15 + 0.85 * Math.sqrt(t))) }, svg);
          el('svg:title', { text: x[0] + ' ' + (x[1] < 10 ? '0' : '') + x[1] + ':00  ' + fmt(x[2]) + ' ' + d.unit }, r);
        });
        // labels
        [0, 6, 12, 18].forEach(function (h) {
          el('svg:text', { x: left - 5, y: h * (cell + gap) + cell - 1, 'text-anchor': 'end', 'font-size': 8, fill: c.text,
            text: (h < 10 ? '0' : '') + h }, svg);
        });
        d.days.forEach(function (day, i) {
          if (i % 7 === 0) {
            el('svg:text', { x: left + i * (cell + gap), y: H - 3, 'font-size': 8, fill: c.text, text: day.slice(5) }, svg);
          }
        });
        return null;
      }
    }
  };

  // ---------------------------------------------------------------- rendering

  function render(box) {
    var set = box.getAttribute('data-health-chart');
    var cfg = SETS[set];
    if (!cfg) { box.textContent = 'unknown chart: ' + set; return; }

    var period = box.getAttribute('data-period') || cfg.period;
    var height = parseInt(box.getAttribute('data-height') || cfg.height, 10);
    var periods = (box.getAttribute('data-periods') || '').split(',').filter(Boolean);

    box.classList.add('health-chart');
    box.innerHTML = '';

    var nav;
    if (periods.length) {
      nav = el('div', { class: 'health-chart-periods' }, box);
      periods.forEach(function (p) {
        var b = el('button', { type: 'button', class: 'health-chart-btn' + (p === period ? ' is-active' : ''),
          'data-period': p, text: PERIOD_LABELS[p] || p }, nav);
        b.addEventListener('click', function () {
          period = p;
          nav.querySelectorAll('.health-chart-btn').forEach(function (x) { x.classList.toggle('is-active', x === b); });
          load();
        });
      });
      if (set === 'bp') {
        fetchData('bpcounts', 'x').then(function (cnt) {
          nav.querySelectorAll('.health-chart-btn').forEach(function (b) {
            var n = cnt[b.dataset.period];
            if (n !== undefined) { b.textContent = (PERIOD_LABELS[b.dataset.period] || b.dataset.period) + ' (' + n + ')'; }
          });
        }).catch(function () {});
      }
    }

    var wrap = el('div', { style: 'position:relative' + (height ? ';height:' + height + 'px' : '') }, box);
    var chart = null, last = null;

    function draw(data) {
      var c = colors(box);
      window.Chart.defaults.font.family = getComputedStyle(box).fontFamily;
      if (chart) { chart.destroy(); chart = null; }
      if (cfg.custom) {
        chart = cfg.custom(wrap, data, c, box);
        return;
      }
      wrap.innerHTML = '';
      chart = new window.Chart(el('canvas', {}, wrap), cfg.chart(data, c, box, period));
    }

    function isEmpty(d) { return Array.isArray(d) ? !d.length : !d; }

    function load() {
      box.classList.add('is-loading');
      fetchData(set, period, cfg.extra ? cfg.extra(box) : '').then(function (data) {
        last = data;
        box.classList.remove('is-loading');
        if (isEmpty(data)) {
          if (chart) { chart.destroy(); chart = null; }
          wrap.innerHTML = '';
          wrap.setAttribute('data-empty', 'no data for this period');
        } else {
          wrap.removeAttribute('data-empty');
          draw(data);
        }
      }).catch(function () {
        box.classList.remove('is-loading');
        wrap.setAttribute('data-empty', 'chart data unavailable');
      });
    }
    load();

    // Re-colour when the page switches light/dark.
    var redraw = function () { if (last && !isEmpty(last)) { draw(last); } };
    var mq = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)');
    if (mq && mq.addEventListener) { mq.addEventListener('change', redraw); }
    new MutationObserver(redraw).observe(document.documentElement,
      { attributes: true, attributeFilter: ['class', 'data-theme', 'data-bs-theme'] });
  }

  function init() {
    var boxes = document.querySelectorAll('[data-health-chart]');
    if (!boxes.length) { return; }
    if (!document.getElementById('health-charts-css')) {
      var st = document.createElement('style');
      st.id = 'health-charts-css';
      st.textContent =
        '.health-chart-periods{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 8px}' +
        '.health-chart-btn{font:inherit;font-size:.8em;padding:2px 10px;border-radius:999px;cursor:pointer;' +
        'border:1px solid currentColor;background:transparent;color:inherit;opacity:.7}' +
        '.health-chart-btn.is-active{opacity:1;background:var(--health-chart-1,currentColor);' +
        'border-color:var(--health-chart-1,currentColor);color:var(--health-chart-on-1,#fff)}' +
        '.health-chart [data-empty]{min-height:120px}' +
        '.health-chart [data-empty]::after{content:attr(data-empty);position:absolute;inset:0;display:grid;' +
        'place-items:center;opacity:.6;font-size:.9em}' +
        '.health-chart.is-loading canvas{opacity:.4}' +
        '.hc-activity{display:flex;align-items:center;gap:18px}' +
        '.hc-rings{width:120px;height:120px;flex:none}' +
        '.hc-ecg-box{display:flex;flex-direction:column;height:100%}' +
        '.hc-ecg-box>div:last-child{flex:1;display:flex;flex-direction:column}' +
        '.hc-ecg-area{flex:1;position:relative;min-height:150px}' +
        '.hc-ecg{position:absolute;inset:0;width:100%;height:100%;display:block}' +
        '.hc-figures{flex:1;min-width:0;display:grid;gap:6px}' +
        '.hc-fig{display:flex;align-items:center;gap:8px;font-size:.92em}' +
        '.hc-dot{width:9px;height:9px;border-radius:50%;flex:none}' +
        '.hc-label{opacity:.75}.hc-value{margin-left:auto;white-space:nowrap;opacity:.8}.hc-value strong{opacity:1}' +
        '.hc-extra{display:flex;gap:14px;margin-top:6px;padding-top:8px;border-top:1px solid var(--health-chart-grid,rgba(127,127,127,.2))}' +
        '.hc-extra span{display:grid;line-height:1.25}.hc-extra small{opacity:.65;font-size:.75em}' +
        '.hc-window{font-size:.75em;opacity:.6;margin-top:2px}' +
        '.hc-heatmap{display:block;width:100%;height:auto}';
      document.head.appendChild(st);
    }
    loadChartJs(function () { boxes.forEach(render); });
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
