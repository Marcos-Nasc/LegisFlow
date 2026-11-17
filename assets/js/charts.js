/* ==========================================================================
   ReviverLegisFlow — Gráficos SVG (sem dependências externas)
   Tipos: 'bar' (agrupado ou empilhado), 'line' e 'doughnut'.
   Uso:
     LF.chart('id-do-container', () => ({
       type: 'bar', labels: ['Jan', ...], format: 'int' | 'money',
       series: [{ name: 'Cíveis', data: [...], color: '--chart-1' }]
     }));
   O gráfico é redesenhado ao trocar o tema e ao redimensionar.
   ========================================================================== */
(function () {
  'use strict';
  const LF = (window.LF = window.LF || {});
  const NS = 'http://www.w3.org/2000/svg';
  const css = (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
  const color = (c) => (c && c.indexOf('--') === 0 ? css(c) : c);
  const esc = (s) => (LF.esc ? LF.esc(s) : String(s));

  function fmt(v, f, compact) {
    if (f === 'money') return compact ? LF.moneyCompact(v) : LF.money(v);
    return Number(v || 0).toLocaleString('pt-BR');
  }

  function niceScale(min, max, ticks) {
    if (min === max) { max = min + (min === 0 ? 1 : Math.abs(min) * 0.5); }
    const range = niceNum(max - min, false);
    const step = niceNum(range / (ticks - 1), true);
    const lo = Math.floor(min / step) * step;
    const hi = Math.ceil(max / step) * step;
    const out = [];
    for (let v = lo; v <= hi + step / 2; v += step) out.push(Math.round(v * 100) / 100);
    return out;
  }
  function niceNum(range, round) {
    const exp = Math.floor(Math.log10(range || 1));
    const f = range / Math.pow(10, exp);
    let nf;
    if (round) nf = f < 1.5 ? 1 : f < 3 ? 2 : f < 7 ? 5 : 10;
    else nf = f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10;
    return nf * Math.pow(10, exp);
  }

  function el(tag, attrs, parent) {
    const n = document.createElementNS(NS, tag);
    for (const k in attrs) n.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(n);
    return n;
  }

  function legendHtml(series, extra) {
    return '<div class="legend-inline chart-legend">' + series.map((s, i) =>
      '<span><i style="background:' + color(s.color) + '"></i>' + esc(s.name) + (extra ? ' <strong class="num">' + extra(s, i) + '</strong>' : '') + '</span>').join('') + '</div>';
  }

  /* ---------------- Cartesiano (barras e linhas) ---------------- */
  function renderCartesian(box, cfg) {
    const series = cfg.series.filter((s) => !s.hidden);
    const labels = cfg.labels;
    const n = labels.length;
    const showLegend = cfg.legend !== false && cfg.series.length > 1;
    box.innerHTML = (showLegend ? legendHtml(cfg.series) : '') + '<div class="chart-plot" style="position:relative;flex:1;min-height:0"></div><div class="chart-tip" role="tooltip"></div>';
    box.style.display = 'flex';
    box.style.flexDirection = 'column';
    box.style.gap = '8px';
    const plot = box.querySelector('.chart-plot');
    const tip = box.querySelector('.chart-tip');
    const W = plot.clientWidth, H = plot.clientHeight;
    if (W < 40 || H < 40) return;

    let max = 0, min = 0;
    if (cfg.stacked) {
      for (let i = 0; i < n; i++) {
        let pos = 0, neg = 0;
        series.forEach((s) => { const v = +s.data[i] || 0; if (v >= 0) pos += v; else neg += v; });
        max = Math.max(max, pos); min = Math.min(min, neg);
      }
    } else {
      series.forEach((s) => s.data.forEach((v) => { v = +v || 0; max = Math.max(max, v); min = Math.min(min, v); }));
    }
    const ticks = niceScale(min, max, H < 220 ? 4 : 5);
    const yMin = ticks[0], yMax = ticks[ticks.length - 1];
    const tickLabels = ticks.map((t) => fmt(t, cfg.format, true));
    const padL = Math.max(28, Math.max.apply(null, tickLabels.map((t) => t.length)) * 6.6 + 10);
    const padR = 8, padT = 8, padB = 24;
    const pw = W - padL - padR, ph = H - padT - padB;
    const y = (v) => padT + ph - ((v - yMin) / (yMax - yMin)) * ph;
    const band = pw / n;
    const xc = (i) => padL + band * i + band / 2;

    const svg = el('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': cfg.ariaLabel || 'Gráfico' }, plot);
    const grid = css('--chart-grid'), muted = css('--text-3');

    ticks.forEach((t, i) => {
      el('line', { x1: padL, x2: W - padR, y1: y(t), y2: y(t), stroke: t === 0 ? css('--border-strong') : grid, 'stroke-width': 1 }, svg);
      const tx = el('text', { x: padL - 8, y: y(t) + 4, 'text-anchor': 'end', fill: muted, 'font-size': 11 }, svg);
      tx.textContent = tickLabels[i];
    });
    const step = Math.ceil(n / Math.max(1, Math.floor(pw / 46)));
    labels.forEach((l, i) => {
      if (i % step) return;
      const tx = el('text', { x: xc(i), y: H - 6, 'text-anchor': 'middle', fill: muted, 'font-size': 11 }, svg);
      tx.textContent = l;
    });

    if (cfg.type === 'bar') {
      const groupW = Math.min(band * 0.72, cfg.stacked ? 40 : 18 * series.length + 6);
      const bw = cfg.stacked ? groupW : Math.max(2, (groupW - (series.length - 1) * 2) / series.length);
      const r = Math.min(4, bw / 3);
      for (let i = 0; i < n; i++) {
        let accPos = 0, accNeg = 0;
        series.forEach((s, si) => {
          const v = +s.data[i] || 0;
          let x, y0, y1;
          if (cfg.stacked) {
            x = xc(i) - bw / 2;
            if (v >= 0) { y0 = y(accPos); y1 = y(accPos + v); accPos += v; } else { y0 = y(accNeg + v); y1 = y(accNeg); accNeg += v; }
          } else {
            x = xc(i) - groupW / 2 + si * (bw + 2);
            y0 = y(Math.min(0, v)); y1 = y(Math.max(0, v));
          }
          const h = Math.abs(y0 - y1);
          if (h < 0.5) return;
          const top = Math.min(y0, y1);
          const isTop = !cfg.stacked || si === series.length - 1;
          const d = isTop && v >= 0
            ? 'M' + x + ',' + (top + h) + 'V' + (top + r) + 'Q' + x + ',' + top + ' ' + (x + r) + ',' + top + 'H' + (x + bw - r) + 'Q' + (x + bw) + ',' + top + ' ' + (x + bw) + ',' + (top + r) + 'V' + (top + h) + 'Z'
            : 'M' + x + ',' + top + 'h' + bw + 'v' + h + 'h' + (-bw) + 'Z';
          el('path', { d, fill: color(s.color), 'data-i': i }, svg);
        });
      }
    } else {
      series.forEach((s) => {
        const pts = s.data.map((v, i) => [xc(i), y(+v || 0)]);
        const c = color(s.color);
        if (series.length === 1 && cfg.area !== false) {
          const gid = 'g' + Math.random().toString(36).slice(2);
          const defs = el('defs', {}, svg);
          const g = el('linearGradient', { id: gid, x1: 0, x2: 0, y1: 0, y2: 1 }, defs);
          el('stop', { offset: '0%', 'stop-color': c, 'stop-opacity': 0.22 }, g);
          el('stop', { offset: '100%', 'stop-color': c, 'stop-opacity': 0 }, g);
          el('path', { d: 'M' + pts.map((p) => p.join(',')).join('L') + 'L' + pts[pts.length - 1][0] + ',' + y(Math.max(yMin, 0)) + 'L' + pts[0][0] + ',' + y(Math.max(yMin, 0)) + 'Z', fill: 'url(#' + gid + ')' }, svg);
        }
        el('path', { d: 'M' + pts.map((p) => p.join(',')).join('L'), fill: 'none', stroke: c, 'stroke-width': 2.25, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, svg);
        pts.forEach((p) => el('circle', { cx: p[0], cy: p[1], r: n > 24 ? 0 : 3, fill: css('--surface'), stroke: c, 'stroke-width': 2 }, svg));
      });
    }

    // Interação: guia + tooltip
    const guide = el('rect', { x: 0, y: padT, width: band, height: ph, fill: css('--text'), opacity: 0, 'pointer-events': 'none' }, svg);
    svg.insertBefore(guide, svg.firstChild.nextSibling);
    const hit = el('rect', { x: padL, y: padT, width: pw, height: ph, fill: 'transparent' }, svg);
    const show = (cx, cy) => {
      const i = Math.max(0, Math.min(n - 1, Math.floor((cx - padL) / band)));
      guide.setAttribute('x', padL + band * i); guide.setAttribute('opacity', 0.05);
      let html = '<strong>' + esc(cfg.tooltipTitle ? cfg.tooltipTitle(i) : labels[i]) + '</strong>';
      let total = 0;
      series.forEach((s) => { const v = +s.data[i] || 0; total += v; html += '<div><i style="background:' + color(s.color) + '"></i>' + esc(s.name) + '<b>' + fmt(v, cfg.format) + '</b></div>'; });
      if (cfg.showTotal && series.length > 1) html += '<div class="tip-total">Total<b>' + fmt(total, cfg.format) + '</b></div>';
      tip.innerHTML = html;
      tip.classList.add('is-visible');
      const bw2 = box.clientWidth, tw = tip.offsetWidth;
      let left = xc(i) + 14;
      if (left + tw > bw2) left = xc(i) - tw - 14;
      tip.style.left = Math.max(0, left) + 'px';
      tip.style.top = Math.max(0, (showLegend ? 28 : 0) + Math.min(cy, H - tip.offsetHeight)) + 'px';
    };
    hit.addEventListener('mousemove', (e) => { const r = svg.getBoundingClientRect(); show(e.clientX - r.left, e.clientY - r.top - 20); });
    hit.addEventListener('touchstart', (e) => { const r = svg.getBoundingClientRect(); const t = e.touches[0]; show(t.clientX - r.left, 10); }, { passive: true });
    hit.addEventListener('mouseleave', () => { tip.classList.remove('is-visible'); guide.setAttribute('opacity', 0); });
  }

  /* ---------------- Rosca ---------------- */
  function renderDoughnut(box, cfg) {
    const data = cfg.series.map((s) => Math.max(0, +s.value || 0));
    const total = data.reduce((a, b) => a + b, 0);
    box.innerHTML = '<div class="donut-wrap"><div class="donut-svg"></div><div class="donut-legend"></div></div><div class="chart-tip" role="tooltip"></div>';
    box.classList.add('is-donut');
    const holder = box.querySelector('.donut-svg');
    const size = Math.min(holder.clientWidth || 200, 190);
    const R = size / 2, r = R * 0.68;
    const svg = el('svg', { width: size, height: size, viewBox: '0 0 ' + size + ' ' + size, role: 'img', 'aria-label': cfg.ariaLabel || 'Composição' }, holder);
    if (total <= 0) {
      el('circle', { cx: R, cy: R, r: (R + r) / 2, fill: 'none', stroke: css('--border'), 'stroke-width': R - r }, svg);
    } else {
      let a0 = -Math.PI / 2;
      cfg.series.forEach((s, i) => {
        const frac = data[i] / total;
        if (frac <= 0) return;
        const a1 = a0 + frac * Math.PI * 2 - (frac < 1 ? 0.012 : 0);
        const large = a1 - a0 > Math.PI ? 1 : 0;
        const p = (rad, ang) => [R + rad * Math.cos(ang), R + rad * Math.sin(ang)];
        let d;
        if (frac >= 0.9999) {
          d = 'M' + (R - R) + ',' + R + 'a' + R + ',' + R + ' 0 1,0 ' + 2 * R + ',0a' + R + ',' + R + ' 0 1,0 ' + -2 * R + ',0' +
              'M' + (R - r) + ',' + R + 'a' + r + ',' + r + ' 0 1,1 ' + 2 * r + ',0a' + r + ',' + r + ' 0 1,1 ' + -2 * r + ',0Z';
        } else {
          const [x0, y0] = p(R, a0), [x1, y1] = p(R, a1), [x2, y2] = p(r, a1), [x3, y3] = p(r, a0);
          d = 'M' + x0 + ',' + y0 + 'A' + R + ',' + R + ' 0 ' + large + ',1 ' + x1 + ',' + y1 + 'L' + x2 + ',' + y2 + 'A' + r + ',' + r + ' 0 ' + large + ',0 ' + x3 + ',' + y3 + 'Z';
        }
        el('path', { d, fill: color(s.color), 'fill-rule': 'evenodd' }, svg);
        a0 = a0 + frac * Math.PI * 2;
      });
    }
    const t1 = el('text', { x: R, y: R - 2, 'text-anchor': 'middle', fill: css('--text-3'), 'font-size': 11 }, svg);
    t1.textContent = cfg.centerLabel || 'Total';
    const t2 = el('text', { x: R, y: R + 16, 'text-anchor': 'middle', fill: css('--text'), 'font-size': 15, 'font-weight': 600 }, svg);
    t2.textContent = fmt(total, cfg.format, true);
    box.querySelector('.donut-legend').innerHTML = cfg.series.map((s, i) =>
      '<div class="donut-item"><i style="background:' + color(s.color) + '"></i><span>' + esc(s.name) + '</span><b class="num">' + fmt(data[i], cfg.format) + '</b><small class="num">' +
      (total > 0 ? ((data[i] / total) * 100).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + '%' : '—') + '</small></div>').join('');
  }

  /* ---------------- Registro / ciclo de vida ---------------- */
  const registry = [];
  function draw(entry) {
    const cfg = entry.build();
    entry.box.classList.add('chart');
    if (cfg.type === 'doughnut') renderDoughnut(entry.box, cfg); else renderCartesian(entry.box, cfg);
  }
  LF.chart = function (id, build) {
    const box = document.getElementById(id);
    if (!box) return null;
    const entry = { box, build };
    registry.push(entry);
    draw(entry);
    if (window.ResizeObserver) {
      let w = box.clientWidth, t;
      new ResizeObserver(() => { if (Math.abs(box.clientWidth - w) > 4) { w = box.clientWidth; clearTimeout(t); t = setTimeout(() => draw(entry), 80); } }).observe(box);
    }
    return entry;
  };
  LF.rebuildCharts = function () { registry.forEach(draw); };
})();
