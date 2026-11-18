// Graphiques communs du back-office (Chart.js 4) : palette accessible, formats français, barres groupées ou
// horizontales, anneaux avec valeur centrale, courbes et graphiques mixtes, cartes d'indicateurs (KPI).
// Règles de lecture appliquées : axes à zéro, séries côte à côte (jamais empilées pour comparer des effectifs),
// tri décroissant des catégories, légende portant les totaux, pourcentages bornés à 100.

import { h } from './ui.js';

export const C = {
  green: '#128a3e', orange: '#d35400', blue: '#0b5ed7', purple: '#6f42c1', teal: '#0f8a7e', gold: '#b8860b',
  red: '#b02a37', grey: '#6c757d', light: '#ced4da', greenSoft: 'rgba(18,138,62,.18)',
};
export const PALETTE = [C.green, C.orange, C.blue, C.purple, C.teal, C.gold, C.red, C.grey];
export const SEX = { male: C.green, female: C.orange };

export const fmt = (v, suffix = '', digits) => {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '—';
  const n = Number(v);
  return `${n.toLocaleString('fr-FR', digits === undefined ? {} : { minimumFractionDigits: digits, maximumFractionDigits: digits })}${suffix}`;
};
/** Pourcentage arrondi à 0,1 point (null si dénominateur nul). */
export const pct = (num, den) => (den > 0 ? Math.round((1000 * num) / den) / 10 : null);

const tooltipFr = (percent) => ({
  callbacks: {
    label: (ctx) => {
      const horizontal = ctx.chart.options.indexAxis === 'y';
      const v = horizontal ? ctx.parsed.x : ctx.parsed.y;
      const isPct = percent && ctx.dataset.yAxisID !== 'y1';
      return `${ctx.dataset.label ? `${ctx.dataset.label} : ` : ''}${fmt(v, isPct ? ' %' : '', isPct ? 1 : undefined)}`;
    },
  },
});

/** Crée le graphique et l'enregistre pour destruction au changement de vue. */
export function draw(registry, canvas, config) {
  if (!canvas) return null;
  const c = new Chart(canvas, config);
  registry.push(c);
  return c;
}

/**
 * Barres : séries côte à côte. `horizontal` pour les libellés longs ; `percent` borne l'axe à 100 ;
 * `line` ajoute une série en courbe (ex. indice de parité) sur un axe secondaire.
 */
export function bars({ labels, series, horizontal = false, percent = false, line = null, max, legend = true, sumInLegend = true }) {
  const value = horizontal ? 'x' : 'y';
  const cat = horizontal ? 'y' : 'x';
  const total = (s) => s.data.reduce((a, b) => a + (Number(b) || 0), 0);
  const datasets = series.map((s) => ({
    type: 'bar', label: sumInLegend && !percent ? `${s.label} (${fmt(total(s))})` : s.label, data: s.data,
    backgroundColor: s.colors || s.color, borderRadius: 3, maxBarThickness: horizontal ? 22 : 38, order: 2,
  }));
  if (line) {
    datasets.push({ type: 'line', label: line.label, data: line.data, borderColor: line.color || C.blue, backgroundColor: line.color || C.blue,
      yAxisID: 'y1', tension: 0.2, pointRadius: 4, order: 1, borderDash: line.dash || [] });
  }
  const scales = {
    [cat]: { ticks: { autoSkip: false, maxRotation: horizontal ? 0 : 50 }, grid: { display: false } },
    [value]: { beginAtZero: true, max: percent ? (max ?? 100) : max, ticks: { precision: percent ? undefined : 0, callback: (v) => fmt(v, percent ? ' %' : '') } },
  };
  if (line) scales.y1 = { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: !!line.axis, text: line.axis || '' } };
  return {
    type: 'bar',
    data: { labels, datasets },
    options: {
      indexAxis: horizontal ? 'y' : 'x', maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
      plugins: { legend: { display: legend, position: 'top' }, tooltip: tooltipFr(percent) }, scales,
    },
  };
}

/** Anneau ; `center` affiche un texte au centre (ex. taux de couverture). */
export function ring({ labels, data, colors, center = null, sub = null, legend = 'bottom' }) {
  const total = data.reduce((a, b) => a + (Number(b) || 0), 0);
  return {
    type: 'doughnut',
    data: { labels: labels.map((l, i) => `${l} (${fmt(data[i])})`), datasets: [{ data, backgroundColor: colors || PALETTE, borderWidth: 1 }] },
    options: {
      maintainAspectRatio: false, cutout: '62%',
      plugins: {
        legend: { display: !!legend, position: legend || 'bottom', labels: { boxWidth: 12 } },
        tooltip: { callbacks: { label: (ctx) => `${ctx.label} — ${fmt(total ? (100 * ctx.parsed) / total : 0, ' %', 1)}` } },
      },
    },
    plugins: center === null ? [] : [{
      id: 'centerText',
      afterDraw(chart) {
        const { ctx, chartArea } = chart;
        if (!chartArea) return;
        const x = (chartArea.left + chartArea.right) / 2;
        const y = (chartArea.top + chartArea.bottom) / 2;
        ctx.save();
        ctx.textAlign = 'center';
        ctx.fillStyle = '#212529';
        ctx.font = '600 1.35rem system-ui, sans-serif';
        ctx.fillText(center, x, sub ? y : y + 6);
        if (sub) {
          ctx.font = '0.75rem system-ui, sans-serif';
          ctx.fillStyle = '#5c6670';
          ctx.fillText(sub, x, y + 18);
        }
        ctx.restore();
      },
    }],
  };
}

/** Courbes (et barres éventuelles) dans le temps. */
export function timeline({ labels, lines = [], barSeries = null, yTitle = '', y1Title = '' }) {
  const datasets = [];
  if (barSeries) {
    datasets.push({ type: 'bar', label: barSeries.label, data: barSeries.data, backgroundColor: barSeries.color || C.light, yAxisID: barSeries.axis || 'y', order: 9, borderRadius: 2 });
  }
  lines.forEach((l, i) => datasets.push({
    type: 'line', label: l.label, data: l.data, borderColor: l.color, backgroundColor: l.fill || l.color, fill: !!l.fill, yAxisID: l.axis || 'y',
    borderDash: l.dash || [], pointRadius: l.points ?? 0, tension: 0.15, spanGaps: true, order: i + 1, borderWidth: l.width || 2,
  }));
  const scales = {
    x: { grid: { display: false }, ticks: { maxTicksLimit: 12, maxRotation: 0 } },
    y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: !!yTitle, text: yTitle } },
  };
  if (datasets.some((d) => d.yAxisID === 'y1')) {
    scales.y1 = { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, ticks: { precision: 0 }, title: { display: !!y1Title, text: y1Title } };
  }
  return {
    type: 'bar', data: { labels, datasets },
    options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'top' }, tooltip: tooltipFr(false) }, scales },
  };
}

/**
 * Carte d'indicateur. tone : good | warn | bad | neutral (bordure colorée doublée d'une icône : l'information ne
 * repose jamais sur la seule couleur). `help` : définition de l'indicateur (infobulle et texte pour lecteur d'écran).
 */
export function kpi({ label, value, sub = '', tone = 'neutral', icon = null, help = '', href = null }) {
  const icons = { good: 'check-circle', warn: 'exclamation-triangle', bad: 'x-octagon', neutral: 'info-circle' };
  const inner = `<div class="card-body"><div class="d-flex justify-content-between align-items-start gap-2">
      <div class="label">${h(label)}</div><i class="bi bi-${h(icon || icons[tone] || icons.neutral)} kpi-icon" aria-hidden="true"></i></div>
      <div class="value">${value}</div>${sub ? `<div class="small text-muted">${sub}</div>` : ''}
      ${help ? `<div class="visually-hidden">${h(help)}</div>` : ''}</div>`;
  const attrs = `class="card stat-card kpi tone-${h(tone)} h-100${href ? ' text-decoration-none' : ''}"${help ? ` title="${h(help)}"` : ''}`;
  return href ? `<a ${attrs} href="${h(href)}">${inner}</a>` : `<div ${attrs}>${inner}</div>`;
}

/** Bloc graphique dans une carte : titre, sous-titre (définition / lecture), zone de dessin, note éventuelle. */
export function panel(id, title, { sub = '', size = '', note = '', aria = '' } = {}) {
  return `<div class="card h-100"><div class="card-body d-flex flex-column"><h2 class="h6 mb-0">${h(title)}</h2>
    ${sub ? `<div class="small text-muted mb-2">${sub}</div>` : '<div class="mb-2"></div>'}
    <div class="chart-box ${h(size)}"><canvas id="${h(id)}" role="img" aria-label="${h(aria || title)}"></canvas></div>
    ${note ? `<div class="small text-muted mt-2">${note}</div>` : ''}</div></div>`;
}

/** Couleur d'une barre de taux selon des seuils (≥ bon : vert, ≥ moyen : orange, sinon rouge). */
export const toneColor = (v, good = 75, fair = 50) => (v === null || v === undefined ? C.light : v >= good ? C.green : v >= fair ? C.orange : C.red);

/** Moyenne mobile (fenêtre n) d'une série. */
export const movingAverage = (data, n = 7) => data.map((_, i) => (i + 1 < n ? null : Math.round((10 * data.slice(i + 1 - n, i + 1).reduce((a, b) => a + b, 0)) / n) / 10));

/** Liste des dates (AAAA-MM-JJ) entre deux bornes incluses. */
export function dateRange(from, to) {
  const out = [];
  const d = new Date(`${from}T00:00:00Z`);
  const end = new Date(`${to}T00:00:00Z`);
  while (d <= end && out.length < 1000) {
    out.push(d.toISOString().slice(0, 10));
    d.setUTCDate(d.getUTCDate() + 1);
  }
  return out;
}

export const shortDate = (iso) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}`;
