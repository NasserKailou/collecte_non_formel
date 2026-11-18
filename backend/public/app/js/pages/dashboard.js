// Tableau de bord de pilotage de la collecte (périmètre de l'utilisateur) : où en est-on, va-t-on finir à temps,
// où sont les retards, la qualité est-elle au rendez-vous ? Chaque indicateur porte sa définition (infobulle).

import { get } from '../api.js';
import { h, badge, pageHeader, options, fmtDate, STATUS_LABELS } from '../ui.js';
import { can, session } from '../session.js';
import { C, fmt, kpi, panel, draw, bars, ring, timeline, movingAverage, dateRange, shortDate, toneColor } from '../charts.js';

const STATUSES = ['SOUMISE', 'EN_CONTROLE', 'VALIDEE', 'INVALIDEE', 'REOUVERTE'];
const STATUS_COLORS = { SOUMISE: C.blue, EN_CONTROLE: C.purple, VALIDEE: C.green, INVALIDEE: C.red, REOUVERTE: C.orange };
const CHANNELS = { MOBILE: ['Tablette', C.green], WEB: ['Saisie web', C.blue], PAPIER: ['Fiche papier', C.orange] };
const GPS = { OK: ['GPS relevé', C.green], MANUEL: ['Coordonnées saisies', C.blue], INDISPONIBLE: ['Indisponible', C.gold], REFUSE: ['Refusé', C.red] };
const charts = [];
const reset = () => { while (charts.length) charts.pop().destroy(); };

export async function render(el) {
  reset();
  const me = session();
  if (!can('stats.view')) {
    el.innerHTML = pageHeader('Tableau de bord') + `<div class="alert alert-info">Bonjour ${h(me.full_name)}.
      ${can('collection.submit') ? "Vous pouvez collecter avec l'application mobile ou saisir directement ici, y compris les fiches papier." : ''}</div>`
      + (can('collection.submit') ? '<a class="btn btn-brand" href="#/ma-saisie"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Ouvrir « Ma saisie »</a>' : '');
    return;
  }
  const campaigns = (await get('/campaigns')).data.filter((c) => c.status !== 'PREPARATION' && c.status !== 'ARCHIVEE');
  if (campaigns.length === 0) {
    el.innerHTML = pageHeader('Tableau de bord') + "<div class=\"alert alert-secondary\">Aucune campagne ouverte ou terminée. Les indicateurs apparaîtront à l'ouverture de la première campagne.</div>";
    return;
  }
  const current = campaigns.find((c) => c.status === 'OUVERTE') || campaigns[0];
  el.innerHTML = pageHeader('Tableau de bord de la collecte',
    `<div class="d-flex flex-wrap gap-2 align-items-center"><select class="form-select" id="d-camp" aria-label="Campagne suivie">${options(campaigns.map((c) => ({ id: c.id, name: `${c.code} — ${c.name}` })), current.id)}</select>
      <button class="btn btn-outline-secondary" id="d-refresh" type="button" title="Actualiser"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i><span class="visually-hidden">Actualiser</span></button>
      <span class="small text-muted" id="d-time" aria-live="polite"></span></div>`)
    + '<div id="d-body"><div class="text-muted"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Calcul des indicateurs…</div></div>';
  const load = async () => {
    reset();
    await renderCampaign(el.querySelector('#d-body'), Number(el.querySelector('#d-camp').value));
    el.querySelector('#d-time').textContent = `Mis à jour à ${new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`;
  };
  el.querySelector('#d-camp').addEventListener('change', load);
  el.querySelector('#d-refresh').addEventListener('click', load);
  await load();
}

async function renderCampaign(box, cid) {
  const [o, tl, agents, awaiting] = await Promise.all([
    get('/stats/overview', { campaign_id: cid }).then((r) => r.data),
    get('/stats/timeline', { campaign_id: cid, granularity: 'day' }).then((r) => r.data),
    get('/stats/agents', { campaign_id: cid }).then((r) => r.data).catch(() => []),
    can('collection.review') ? get('/collections', { awaiting_me: 1, per_page: 1 }).then((r) => r.meta.total).catch(() => null) : Promise.resolve(null),
  ]);
  const c = o.campaign;
  const span = Math.max(1, dateRange(c.opens_on, c.closes_on).length - 1);
  const elapsed = Math.min(span, Math.max(0, dateRange(c.opens_on, o.today).length - 1));
  const expected = Math.round((100 * elapsed) / span); // part du calendrier écoulée = couverture attendue à rythme régulier
  const p = o.pace;
  const r = o.review;
  const q = o.quality;

  // ------------------------------------------------------------------ Indicateurs clés
  const coverTone = o.coverage_rate === null ? 'neutral' : o.coverage_rate >= expected ? 'good' : o.coverage_rate >= expected - 15 ? 'warn' : 'bad';
  const tiles = [
    kpi({ label: 'Couverture', value: fmt(o.coverage_rate, ' %', 1), tone: coverTone, icon: 'pie-chart',
      sub: `${fmt(o.collected)} / ${fmt(o.targeted)} centres · attendu ≈ ${expected} %`,
      help: 'Centres ayant au moins une collecte reçue, rapportés aux centres ciblés. « Attendu » : part du calendrier écoulée (rythme régulier).' }),
    kpi({ label: 'Validées', value: fmt(o.validated_rate, ' %', 1), tone: o.validated_rate >= 50 ? 'good' : 'neutral', icon: 'patch-check',
      sub: `${fmt(o.by_status.VALIDEE)} validées · ${fmt(o.validated_share, ' %', 1)} des collectées`,
      help: 'Collectes validées au dernier niveau de contrôle, rapportées aux centres ciblés.' }),
    kpi({ label: 'À contrôler', value: fmt(o.pending), tone: r.pending_over_3_days > 0 ? 'bad' : o.pending > 0 ? 'warn' : 'good', icon: 'hourglass-split',
      sub: r.pending_over_3_days > 0 ? `dont ${fmt(r.pending_over_3_days)} depuis plus de 3 jours` : (o.pending ? `ancienneté moyenne ${fmt(r.pending_avg_hours, ' h', 0)}` : 'aucun stock'),
      help: 'Collectes soumises en attente de contrôle (soumises ou en contrôle).', href: `#/collectes?campaign_id=${cid}&status=SOUMISE` }),
    kpi({ label: 'À corriger', value: fmt(o.to_fix), tone: o.to_fix > 0 ? 'warn' : 'good', icon: 'arrow-counterclockwise',
      sub: `refus au 1er contrôle : ${fmt(r.first_pass_rejection_rate, ' %', 1)}`,
      help: 'Collectes renvoyées aux agents. Refus au 1er contrôle : part des collectes dont la première décision est une invalidation (qualité de la saisie).',
      href: `#/collectes?campaign_id=${cid}&status=INVALIDEE` }),
    kpi({ label: 'Rythme (7 derniers jours)', value: `${fmt(p.per_day_7, '', 1)}<small class="fs-6 text-muted"> /jour</small>`, icon: 'speedometer2',
      tone: p.remaining === 0 ? 'good' : p.per_day_7 >= p.required_per_day ? 'good' : p.per_day_7 >= 0.75 * p.required_per_day ? 'warn' : 'bad',
      sub: `requis : ${fmt(p.required_per_day, '', p.required_per_day < 1 ? 2 : 1)} /jour · aujourd'hui : ${fmt(p.today)}`,
      help: 'Moyenne des nouvelles collectes par jour sur 7 jours, comparée au rythme nécessaire pour couvrir les centres restants avant la clôture.' }),
    kpi({ label: 'Fin projetée', value: p.projected_end ? fmtDate(p.projected_end) : '—', icon: 'flag',
      tone: p.projected_end === null ? (p.remaining ? 'bad' : 'neutral') : p.on_track ? 'good' : 'bad',
      sub: `clôture ${fmtDate(c.closes_on)} · ${fmt(p.days_left)} j restants · ${fmt(p.remaining)} centres`,
      help: 'Date à laquelle tous les centres seraient collectés au rythme des 7 derniers jours.' }),
  ];
  if (awaiting !== null) {
    tiles.push(kpi({ label: 'À traiter par moi', value: fmt(awaiting), tone: awaiting > 0 ? 'warn' : 'good', icon: 'inbox',
      sub: 'collectes que je peux valider maintenant', href: '#/collectes?awaiting_me=1', help: 'Collectes de votre périmètre en attente à votre niveau de contrôle.' }));
  }

  box.innerHTML = `<div class="d-flex flex-wrap gap-2 align-items-center mb-3">${badge(c.status)}
      <span class="small text-muted">Du ${fmtDate(c.opens_on)} au ${fmtDate(c.closes_on)} · ${fmt(o.targeted)} centres ciblés · ${fmt(o.unassigned)} non affectés
      · ${fmt(o.agents.active_7_days)} agent(s) actif(s) sur 7 jours / ${fmt(o.agents.assigned)} affecté(s)</span></div>
    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-${tiles.length > 6 ? 4 : 3} row-cols-xxl-${tiles.length} g-3 mb-1">${tiles.map((t) => `<div class="col">${t}</div>`).join('')}</div>

    <h2 class="section-title">Avancement</h2>
    <div class="row g-3">
      <div class="col-xl-8">${panel('d-traj', 'Trajectoire de la collecte', { size: 'tall',
        sub: "Centres collectés et validés (cumul) comparés à la trajectoire cible linéaire, de l'ouverture à la clôture." })}</div>
      <div class="col-xl-4">${panel('d-status', 'État des collectes', { size: 'tall', sub: 'Répartition des collectes reçues ; au centre : couverture.' })}</div>
      <div class="col-xl-7">${panel('d-daily', 'Nouvelles collectes par jour (30 derniers jours)', { size: 'tall',
        sub: 'Barres : collectes reçues ; courbe : moyenne mobile sur 7 jours ; pointillés : rythme requis.' })}</div>
      <div class="col-xl-5">${panel('d-regions', 'Couverture par région', { size: o.regions.length > 6 ? 'xtall' : 'tall',
        sub: 'Part des centres ciblés collectés et validés, régions classées par couverture.' })}</div>
    </div>

    <h2 class="section-title">Contrôle et qualité des données</h2>
    <div class="row g-3">
      <div class="col-lg-4">${panel('d-reasons', 'Motifs de refus', { sub: `${fmt(r.decisions.INVALIDEE)} invalidation(s) pour ${fmt(r.decisions.VALIDEE)} validation(s) prononcées.` })}</div>
      <div class="col-lg-4">${panel('d-gps', 'Localisation des centres', { sub: `Précision moyenne du GPS : ${fmt(q.gps_avg_accuracy_m, ' m', 0)} · ${fmt(q.gps_precise_rate, ' %', 1)} des relevés à ± ${fmt(q.gps_accuracy_threshold_m)} m ou mieux.` })}</div>
      <div class="col-lg-4">${panel('d-channels', 'Canal de saisie', { sub: 'Tablette, saisie web directe ou fiche papier saisie au bureau.' })}</div>
      <div class="col-lg-4"><div class="card h-100"><div class="card-body"><h2 class="h6">Indicateurs de contrôle</h2>
        <dl class="row small mb-0">
          <dt class="col-7 fw-normal text-muted">Délai médian de validation</dt><dd class="col-5 text-end fw-semibold">${delay(r.validation_delay_median_h)}</dd>
          <dt class="col-7 fw-normal text-muted">Délai moyen de validation</dt><dd class="col-5 text-end">${delay(r.validation_delay_avg_h)}</dd>
          <dt class="col-7 fw-normal text-muted">Plus ancienne collecte en attente</dt><dd class="col-5 text-end">${r.oldest_pending_at ? fmtDate(r.oldest_pending_at, true) : '—'}</dd>
          <dt class="col-7 fw-normal text-muted">Refus au 1er contrôle</dt><dd class="col-5 text-end">${fmt(r.first_pass_rejection_rate, ' %', 1)}</dd>
          <dt class="col-7 fw-normal text-muted">Collectes avec avertissement(s)</dt><dd class="col-5 text-end">${fmt(q.with_warnings_rate, ' %', 1)}</dd>
          <dt class="col-7 fw-normal text-muted">Avertissements par collecte</dt><dd class="col-5 text-end">${fmt(q.avg_warnings, '', 2)}</dd>
          <dt class="col-7 fw-normal text-muted">Fiches papier signées</dt><dd class="col-5 text-end">${fmt(q.paper_signed_rate, ' %', 1)}</dd>
          <dt class="col-7 fw-normal text-muted">Centres localisés</dt><dd class="col-5 text-end">${fmt(q.gps_located_rate, ' %', 1)}</dd>
        </dl></div></div></div>
      <div class="col-lg-8"><div class="card h-100"><div class="card-body"><h2 class="h6 mb-0">Agents à suivre</h2>
        <div class="small text-muted mb-2">Les huit agents dont la réalisation est la plus faible ; en rouge : aucune soumission depuis plus de 3 jours.</div>
        <div class="table-responsive">${agentsTable(agents)}</div><a class="small" href="#/statistiques">Tous les agents dans « Statistiques »</a></div></div></div>
    </div>`;
  applyWidths(box);

  // ------------------------------------------------------------------ Graphiques
  const days = dateRange(c.opens_on, c.closes_on);
  const cum = {};
  for (const s of tl.series) cum[String(s.period).slice(0, 10)] = s;
  // Cumul de départ : collectes éventuellement reçues avant la date d'ouverture officielle.
  const before = tl.series.filter((s) => String(s.period).slice(0, 10) < c.opens_on).at(-1);
  let lastS = before ? before.submitted_cumulative : 0;
  let lastV = before ? before.validated_cumulative : 0;
  const collectedLine = [];
  const validatedLine = [];
  for (const d of days) {
    if (d > o.today) { collectedLine.push(null); validatedLine.push(null); continue; }
    if (cum[d]) { lastS = cum[d].submitted_cumulative; lastV = cum[d].validated_cumulative; }
    collectedLine.push(lastS);
    validatedLine.push(lastV);
  }
  const target = days.map((_, i) => Math.round((o.targeted * i) / Math.max(1, days.length - 1)));
  draw(charts, box.querySelector('#d-traj'), timeline({
    labels: days.map(shortDate), yTitle: 'Centres (cumul)',
    lines: [
      { label: 'Collectées (cumul)', data: collectedLine, color: C.blue, width: 3 },
      { label: 'Validées (cumul)', data: validatedLine, color: C.green, fill: C.greenSoft, width: 2 },
      { label: 'Trajectoire cible', data: target, color: C.grey, dash: [6, 4], width: 1.5 },
      { label: `Cible : ${fmt(o.targeted)} centres`, data: days.map(() => o.targeted), color: C.orange, dash: [2, 3], width: 1 },
    ],
  }));

  if (o.collected > 0) {
    draw(charts, box.querySelector('#d-status'), ring({ labels: STATUSES.map((s) => STATUS_LABELS[s]), data: STATUSES.map((s) => o.by_status[s]),
      colors: STATUSES.map((s) => STATUS_COLORS[s]), center: fmt(o.coverage_rate, ' %', 0), sub: 'couverture' }));
  } else empty(box, '#d-status', 'Aucune collecte reçue.');

  const daily = p.daily.map((d) => d.submitted);
  draw(charts, box.querySelector('#d-daily'), timeline({
    labels: p.daily.map((d) => shortDate(d.date)),
    barSeries: { label: 'Collectes reçues', data: daily, color: 'rgba(11,94,215,.55)' },
    lines: [
      { label: 'Moyenne mobile 7 jours', data: movingAverage(daily), color: C.blue, width: 2.5 },
      ...(p.remaining > 0 ? [{ label: `Rythme requis (${fmt(p.required_per_day, '', 1)}/j)`, data: daily.map(() => p.required_per_day), color: C.red, dash: [5, 4], width: 1.5 }] : []),
    ],
  }));

  const regions = [...o.regions].sort((a, b) => (b.coverage_rate ?? 0) - (a.coverage_rate ?? 0));
  if (regions.length) {
    draw(charts, box.querySelector('#d-regions'), bars({
      labels: regions.map((x) => `${x.name} (${fmt(x.collected)}/${fmt(x.targeted)})`), horizontal: true, percent: true,
      series: [
        { label: 'Collectés', data: regions.map((x) => x.coverage_rate), colors: regions.map((x) => toneColor(x.coverage_rate, expected, expected - 15)) },
        { label: 'Validés', data: regions.map((x) => x.validation_rate), color: 'rgba(18,138,62,.45)' },
      ],
    }));
  } else empty(box, '#d-regions', 'Aucun centre ciblé dans votre périmètre.');

  if (r.rejection_reasons.length) {
    draw(charts, box.querySelector('#d-reasons'), bars({ labels: r.rejection_reasons.map((x) => x.label), horizontal: true, legend: false,
      series: [{ label: 'Invalidations', data: r.rejection_reasons.map((x) => x.count), color: C.red }] }));
  } else empty(box, '#d-reasons', 'Aucune invalidation : pas de motif à analyser.');

  const gpsKeys = Object.keys(GPS).filter((k) => q.gps[k] > 0);
  if (gpsKeys.length) {
    draw(charts, box.querySelector('#d-gps'), ring({ labels: gpsKeys.map((k) => GPS[k][0]), data: gpsKeys.map((k) => q.gps[k]), colors: gpsKeys.map((k) => GPS[k][1]),
      center: fmt(q.gps_located_rate, ' %', 0), sub: 'localisés' }));
  } else empty(box, '#d-gps', 'Aucune collecte reçue.');

  const chKeys = Object.keys(CHANNELS).filter((k) => o.channels[k] > 0);
  if (chKeys.length) {
    draw(charts, box.querySelector('#d-channels'), ring({ labels: chKeys.map((k) => CHANNELS[k][0]), data: chKeys.map((k) => o.channels[k]), colors: chKeys.map((k) => CHANNELS[k][1]),
      center: fmt(o.collected), sub: 'collectes' }));
  } else empty(box, '#d-channels', 'Aucune collecte reçue.');
}

function delay(hours) {
  if (hours === null || hours === undefined) return '—';
  return hours < 48 ? fmt(hours, ' h', 1) : fmt(hours / 24, ' j', 1);
}

function empty(box, sel, text) {
  const canvas = box.querySelector(sel);
  if (canvas) canvas.parentElement.innerHTML = `<p class="text-muted small text-center mt-5">${h(text)}</p>`;
}

/** Largeurs des barres de progression : fixées en JavaScript (la CSP interdit les attributs style). */
function applyWidths(root) {
  root.querySelectorAll('.progress-bar[data-w]').forEach((b) => {
    const w = Number(b.dataset.w);
    b.style.width = `${w}%`;
    b.classList.add(w >= 75 ? 'bg-success' : w >= 40 ? 'bg-warning' : 'bg-danger');
  });
}

function agentsTable(agents) {
  const rows = agents.filter((a) => a.assigned > 0).sort((a, b) => (a.completion_rate ?? 0) - (b.completion_rate ?? 0)).slice(0, 8);
  if (!rows.length) return '<p class="text-muted small">Aucun agent affecté dans votre périmètre.</p>';
  const stale = (d) => !d || (Date.now() - new Date(String(d).replace(' ', 'T')).getTime()) > 3 * 86400000;
  return `<table class="table table-sm align-middle mb-1"><thead><tr><th>Agent</th><th class="text-end">Collectées / affectées</th><th>Réalisation</th>
      <th class="text-end">Invalidation</th><th>Dernière soumission</th></tr></thead><tbody>
    ${rows.map((a) => `<tr><td>${h(a.name)} <span class="small text-muted">${h(a.matricule)}</span></td>
      <td class="text-end">${fmt(a.collected)} / ${fmt(a.assigned)}</td>
      <td class="w-25"><div class="d-flex align-items-center gap-2"><div class="progress mini-progress flex-grow-1" role="progressbar" aria-label="Réalisation de ${h(a.name)}"
          aria-valuenow="${Math.round(a.completion_rate ?? 0)}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" data-w="${Math.round(a.completion_rate ?? 0)}"></div></div>
        <span class="small">${fmt(a.completion_rate, ' %', 0)}</span></div></td>
      <td class="text-end ${a.invalidation_rate > 20 ? 'text-danger fw-semibold' : ''}">${fmt(a.invalidation_rate, ' %', 0)}</td>
      <td class="small ${stale(a.last_submitted_at) ? 'text-danger' : ''}">${a.last_submitted_at ? fmtDate(a.last_submitted_at, true) : 'jamais'}</td></tr>`).join('')}
    </tbody></table>`;
}
