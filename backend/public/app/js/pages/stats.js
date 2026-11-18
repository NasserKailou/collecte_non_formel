// Statistiques (indicateurs, avancement, agents, évolution, comparaison) et exports.
// Chaque graphique porte un titre explicite et une ligne de lecture ; les tableaux de données restent disponibles.
import { get, post, download } from '../api.js';
import { h, fmtDate, toast, toastError, options, pageHeader } from '../ui.js';
import { can } from '../session.js';
import { C, SEX, PALETTE, fmt, pct, kpi, panel, draw, bars, ring, timeline, movingAverage, toneColor } from '../charts.js';

const charts = [];
const resetCharts = () => { while (charts.length) charts.pop().destroy(); };

// ===================================================================== Statistiques

export async function render(el) {
  resetCharts();
  const camps = (await get('/campaigns')).data.filter((c) => c.status !== 'PREPARATION');
  if (camps.length === 0) {
    el.innerHTML = pageHeader('Statistiques') + '<div class="alert alert-secondary">Aucune campagne ouverte ou clôturée.</div>';
    return;
  }
  el.innerHTML = pageHeader('Statistiques')
    + `<div class="card mb-3"><div class="card-body filters d-flex flex-wrap gap-2 align-items-center">
        <select class="form-select" id="s-camp" aria-label="Campagne">${options(camps.map((c) => ({ id: c.id, name: `${c.code} — ${c.name}` })), camps[0].id)}</select>
        <select class="form-select" id="s-basis" aria-label="Base de calcul"><option value="">Base par défaut (paramètres)</option>
          <option value="validated">Collectes validées</option><option value="submitted">Soumises et validées</option></select>
      </div></div>
      <ul class="nav nav-tabs mb-3" role="tablist">${[['ind', 'Indicateurs'], ['prog', 'Avancement'], ['agents', 'Agents'], ['time', 'Évolution'], ['cmp', 'Comparaison']]
        .map(([k, l], i) => `<li class="nav-item" role="presentation"><button class="nav-link${i === 0 ? ' active' : ''}" type="button" role="tab" data-tab="${k}" aria-selected="${i === 0}">${h(l)}</button></li>`).join('')}</ul>
      <div id="tab"></div>`;
  let current = 'ind';
  const show = async () => {
    resetCharts();
    const box = el.querySelector('#tab');
    box.innerHTML = '<div class="text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Calcul…</div>';
    const cid = el.querySelector('#s-camp').value;
    const basis = el.querySelector('#s-basis').value;
    try {
      if (current === 'ind') await indicatorsTab(box, cid, basis);
      if (current === 'prog') await progressTab(box, cid);
      if (current === 'agents') await agentsTab(box, cid);
      if (current === 'time') await timelineTab(box, cid);
      if (current === 'cmp') await compareTab(box, camps, basis);
    } catch (e) {
      box.innerHTML = '';
      toastError(e);
    }
  };
  el.querySelector('#s-camp').addEventListener('change', show);
  el.querySelector('#s-basis').addEventListener('change', show);
  el.onclick = async (ev) => {
    const tab = ev.target.closest('[data-tab]');
    if (tab) {
      current = tab.dataset.tab;
      el.querySelectorAll('[data-tab]').forEach((t) => { t.classList.toggle('active', t === tab); t.setAttribute('aria-selected', String(t === tab)); });
      await show();
      return;
    }
    const exp = ev.target.closest('[data-export]');
    if (exp) {
      const [dataset, format] = exp.dataset.export.split(':');
      const filters = { campaign_id: Number(el.querySelector('#s-camp').value) };
      if (el.querySelector('#s-basis').value && ['collections', 'indicators'].includes(dataset)) filters.basis = el.querySelector('#s-basis').value;
      if (exp.dataset.level) filters.level = el.querySelector(exp.dataset.level)?.value || 'region';
      exp.disabled = true;
      await runExport(dataset, format, filters);
      exp.disabled = false;
    }
  };
  await show();
}

function exportButtons(items) {
  if (!can('export.create')) return '';
  return `<div class="btn-group btn-group-sm">${items.map(([ds, f, label, level]) => `<button class="btn btn-outline-success" data-export="${h(ds)}:${h(f)}"${level ? ` data-level="${h(level)}"` : ''}>
    <i class="bi bi-download me-1"></i>${h(label)}</button>`).join('')}</div>`;
}

/** Hauteur d'un graphique en barres horizontales selon le nombre de catégories (lisibilité des libellés). */
function fitHeight(box, id, n, per = 28, min = 180) {
  const canvas = box.querySelector(`#${id}`);
  if (canvas) canvas.parentElement.style.height = `${Math.max(min, n * per + 60)}px`;
}

function empty(box, id, text = 'Aucune donnée pour cette base de calcul.') {
  const canvas = box.querySelector(`#${id}`);
  if (canvas) canvas.parentElement.outerHTML = `<p class="text-muted small text-center my-5">${h(text)}</p>`;
}

const sexSeries = (rows) => [
  { label: 'Hommes', data: rows.map((r) => r.male), color: SEX.male },
  { label: 'Femmes', data: rows.map((r) => r.female), color: SEX.female },
];

// ===================================================================== Indicateurs

async function indicatorsTab(box, cid, basis) {
  const i = (await get('/stats/indicators', { campaign_id: cid, basis, group_by: 'region' })).data;
  const a = i.apprentices;
  const t = i.trainers;
  const acc = Object.fromEntries(i.accompanied.map((r) => [r.key, r]));
  const parity = a.male > 0 ? a.female / a.male : null;
  const placement = acc.INSCRITS?.total ? pct(acc.PLACES?.total || 0, acc.INSCRITS.total) : null;
  const tooling = acc.INSCRITS?.total ? pct(acc.OUTILLES?.total || 0, acc.INSCRITS.total) : null;
  const vulnTotal = i.vulnerable.reduce((s, r) => s + r.total, 0);
  const regions = (i.by_territory || []).filter((r) => r.structures > 0);
  const sexTable = (rows, first = '') => `<table class="table table-sm"><thead><tr><th>${h(first)}</th><th class="text-end">Hommes</th><th class="text-end">Femmes</th><th class="text-end">Total</th><th class="text-end">% femmes</th></tr></thead>
    <tbody>${rows.map((r) => `<tr><td>${h(r.label)}</td><td class="text-end">${fmt(r.male)}</td><td class="text-end">${fmt(r.female)}</td><td class="text-end fw-semibold">${fmt(r.total)}</td><td class="text-end">${fmt(pct(r.female, r.total), ' %', 1)}</td></tr>`).join('')
      || '<tr><td colspan="5" class="text-muted">Aucune donnée</td></tr>'}</tbody></table>`;

  const tiles = [
    kpi({ label: 'Structures recensées', value: fmt(i.structures.total), icon: 'building', help: 'Structures de formation dont la collecte entre dans la base de calcul.',
      sub: `${fmt(i.structures.total ? a.total / i.structures.total : null, '', 1)} apprentis en moyenne par structure` }),
    kpi({ label: 'Apprentis', value: fmt(a.total), icon: 'people', help: 'Total des promotions I, II et III, tous secteurs et métiers.',
      sub: `dont ${fmt(a.female)} femmes (${fmt(a.female_share, ' %', 1)})` }),
    kpi({ label: 'Indice de parité F/H', value: fmt(parity, '', 2), icon: 'gender-ambiguous',
      tone: parity === null ? 'neutral' : parity >= 0.97 && parity <= 1.03 ? 'good' : parity >= 0.7 ? 'warn' : 'bad',
      sub: 'apprenties pour 1 apprenti (1 = parité)', help: "Effectif féminin divisé par l'effectif masculin. Parité si l'indice est compris entre 0,97 et 1,03 (convention UNESCO)." }),
    kpi({ label: 'Maîtres artisans / formateurs', value: fmt(t.total), icon: 'person-workspace', help: 'Personnes déclarées dans le tableau de la section III.',
      sub: `${fmt(pct(t.female, t.total), ' %', 1)} de femmes · ${fmt(pct(t.maitres_artisans, t.total), ' %', 0)} de maîtres artisans` }),
    kpi({ label: 'Apprentis par formateur', value: fmt(t.apprentices_per_trainer, '', 1), icon: 'diagram-2',
      tone: t.apprentices_per_trainer === null ? 'neutral' : t.apprentices_per_trainer <= 20 ? 'good' : t.apprentices_per_trainer <= 40 ? 'warn' : 'bad',
      sub: "taux d'encadrement", help: "Nombre d'apprentis rapporté au nombre de maîtres artisans et formateurs (plus il est bas, meilleur est l'encadrement)." }),
    kpi({ label: 'Formateurs certifiés', value: fmt(pct(t.certified, t.total), ' %', 1), icon: 'award',
      tone: t.total === 0 ? 'neutral' : pct(t.certified, t.total) >= 50 ? 'good' : 'warn',
      sub: `${fmt(t.certified)} sur ${fmt(t.total)} · suivi pédagogique ${fmt(pct(t.pedagogical_followup, t.total), ' %', 0)}`, help: 'Part des maîtres artisans et formateurs dont la qualification est certifiée.' }),
    kpi({ label: 'Jeunes accompagnés', value: fmt(acc.INSCRITS?.total ?? 0), icon: 'signpost-split',
      sub: `placement ${fmt(placement, ' %', 1)} · outillage ${fmt(tooling, ' %', 1)}`, help: "Jeunes accompagnés en dehors de la formation (question 2.2). Taux de placement et d'outillage rapportés aux inscrits." }),
    kpi({ label: 'Apprentis vulnérables', value: fmt(vulnTotal), icon: 'heart-pulse',
      sub: `${fmt(pct(vulnTotal, a.total), ' %', 1)} des apprentis`, help: 'Apprentis handicapés, réfugiés, retournés ou déplacés internes (lignes « dont » de la section IV).' }),
  ];

  box.innerHTML = `<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2"><div class="small text-muted">Base : ${i.basis === 'validated' ? 'collectes validées' : 'collectes soumises et validées'} — questionnaire ${h(i.campaign.questionnaire_version)}</div>
      ${exportButtons([['indicators', 'xlsx', 'Excel'], ['indicators', 'pdf', 'Rapport PDF'], ['collections', 'xlsx', 'Microdonnées Excel'], ['collections', 'csv', 'Microdonnées CSV']])}</div>
    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3">${tiles.map((x) => `<div class="col">${x}</div>`).join('')}</div>

    <h2 class="section-title">Apprentis</h2>
    <div class="row g-3">
      <div class="col-lg-6">${panel('c-sector', 'Apprentis par secteur et par sexe', { size: 'tall', sub: 'Effectifs des trois promotions ; barres côte à côte par sexe.' })}</div>
      <div class="col-lg-6">${panel('c-duration', 'Apprentis par durée de formation et par sexe', { size: 'tall', sub: "Durée d'apprentissage déclarée pour chaque métier." })}</div>
      <div class="col-xl-8">${panel('c-region', 'Apprentis par région et indice de parité', { size: 'tall', sub: 'Barres : effectifs par sexe (axe de gauche) ; courbe : indice de parité F/H (axe de droite, 1 = parité).' })}</div>
      <div class="col-xl-4">${panel('c-sex', 'Répartition par sexe', { size: 'tall', sub: 'Part des femmes parmi les apprentis.' })}</div>
      <div class="col-xl-7">${panel('c-trades', 'Les 15 métiers les plus suivis', { sub: "Nombre d'apprentis ; entre parenthèses : nombre de structures qui forment au métier." })}</div>
      <div class="col-xl-5">${panel('c-ratio', "Taux d'encadrement par région", { sub: `Apprentis par maître artisan ou formateur ; orange : au-dessus de la moyenne nationale (${fmt(t.apprentices_per_trainer, '', 1)}).` })}</div>
    </div>

    <h2 class="section-title">Structures</h2>
    <div class="row g-3">
      <div class="col-lg-6">${panel('c-types', 'Structures par type', { sub: 'Type de structure de formation (question 1.9).' })}</div>
      <div class="col-sm-6 col-lg-3">${panel('c-zone', "Zone d'implantation", { size: 'tall', sub: 'Question 1.7.' })}</div>
      <div class="col-sm-6 col-lg-3">${panel('c-legal', 'Statut juridique', { size: 'tall', sub: 'Question 1.8.' })}</div>
    </div>

    <h2 class="section-title">Publics vulnérables et insertion</h2>
    <div class="row g-3">
      <div class="col-lg-6">${panel('c-vuln', 'Apprentis vulnérables par catégorie et par sexe', { size: 'tall', sub: 'Lignes « dont » de la section IV.' })}</div>
      <div class="col-lg-6">${panel('c-acc', 'Jeunes accompagnés : inscrits, placés, outillés', { size: 'tall', sub: `Taux de placement : ${fmt(placement, ' %', 1)} · taux d'outillage : ${fmt(tooling, ' %', 1)} (rapportés aux inscrits).` })}</div>
    </div>

    <h2 class="section-title">Maîtres artisans et formateurs</h2>
    <div class="row g-3">
      <div class="col-sm-6 col-xl-3">${panel('c-grade', 'Grade', { sub: 'Maîtres artisans et formateurs.' })}</div>
      <div class="col-sm-6 col-xl-3">${panel('c-tsex', 'Sexe', { sub: 'Encadrement féminin et masculin.' })}</div>
      <div class="col-sm-6 col-xl-3">${panel('c-nat', 'Nationalité', { sub: 'Nigériens, ressortissants UEMOA, autres.' })}</div>
      <div class="col-sm-6 col-xl-3">${panel('c-profile', 'Profil professionnel', { sub: 'Part des encadrants concernés.' })}</div>
    </div>

    <h2 class="section-title">Équipements et services</h2>
    <div class="row g-3">
      <div class="col-12">${panel('c-equip', "Part des structures disposant de l'équipement ou du service", { sub: 'Questions Oui/Non, classées de la plus à la moins répandue ; vert ≥ 75 %, orange ≥ 50 %, rouge en dessous.' })}</div>
      ${i.multiple_choice.map((q, k) => `<div class="col-lg-6">${panel(`c-mc-${k}`, `${q.number} ${q.label}`, { sub: 'Part des structures citant chaque modalité (réponses multiples : le total peut dépasser 100 %).' })}</div>`).join('')}
    </div>

    <details class="card mt-4"><summary class="card-body py-2 fw-semibold">Tableaux de données détaillés</summary><div class="card-body pt-0"><div class="row g-3">
      <div class="col-lg-6"><h3 class="h6">Apprentis par secteur</h3>${sexTable(a.by_sector, 'Secteur')}</div>
      <div class="col-lg-6"><h3 class="h6">Apprentis par durée</h3>${sexTable(a.by_duration, 'Durée')}</div>
      <div class="col-lg-6"><h3 class="h6">Publics vulnérables (dont)</h3>${sexTable(i.vulnerable, 'Catégorie')}</div>
      <div class="col-lg-6"><h3 class="h6">Jeunes accompagnés</h3>${sexTable(i.accompanied, 'Mesure')}</div>
      <div class="col-lg-6"><h3 class="h6">Métiers les plus suivis</h3><table class="table table-sm"><thead><tr><th>Métier</th><th class="text-end">Apprentis</th><th class="text-end">Structures</th></tr></thead><tbody>
        ${a.top_trades.map((x) => `<tr><td>${h(x.label)}</td><td class="text-end">${fmt(x.total)}</td><td class="text-end">${fmt(x.structures)}</td></tr>`).join('') || '<tr><td colspan="3" class="text-muted">Aucune donnée</td></tr>'}</tbody></table></div>
      <div class="col-lg-6"><h3 class="h6">Formateurs</h3><table class="table table-sm"><tbody>
        ${[['Hommes', t.male], ['Femmes', t.female], ['Maîtres artisans', t.maitres_artisans], ['Formateurs', t.formateurs], ['Certifiés', t.certified],
          ['Formation initiale', t.initial_training], ['Suivi pédagogique', t.pedagogical_followup], ['Nigériens', t.nationality.NIGERIEN],
          ['UEMOA', t.nationality.UEMOA], ['Autres nationalités', t.nationality.AUTRE]].map(([l, v]) => `<tr><td>${h(l)}</td><td class="text-end">${fmt(v)}</td><td class="text-end text-muted">${fmt(pct(v, t.total), ' %', 1)}</td></tr>`).join('')}</tbody></table></div>
      <div class="col-12"><h3 class="h6">Équipements et services</h3><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Question</th><th class="text-end">Réponses</th><th class="text-end">Oui</th><th class="text-end">% Oui</th></tr></thead><tbody>
        ${i.yes_no.map((q) => `<tr><td><span class="text-muted me-1">${h(q.number)}</span>${h(q.label)}</td><td class="text-end">${fmt(q.answered)}</td><td class="text-end">${fmt(q.yes)}</td><td class="text-end">${fmt(q.rate, ' %')}</td></tr>`).join('') || '<tr><td colspan="4" class="text-muted">Aucune donnée</td></tr>'}</tbody></table></div></div>
      <div class="col-12"><h3 class="h6">Par région</h3><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Région</th><th class="text-end">Structures</th><th class="text-end">Apprentis (H)</th><th class="text-end">Apprentis (F)</th><th class="text-end">Apprentis</th><th class="text-end">Indice de parité</th><th class="text-end">Formateurs</th><th class="text-end">Apprentis / formateur</th></tr></thead><tbody>
        ${regions.map((r) => `<tr><td>${h(r.name)}</td><td class="text-end">${fmt(r.structures)}</td><td class="text-end">${fmt(r.apprentices_male)}</td><td class="text-end">${fmt(r.apprentices_female)}</td><td class="text-end">${fmt(r.apprentices)}</td>
          <td class="text-end">${fmt(r.apprentices_male ? r.apprentices_female / r.apprentices_male : null, '', 2)}</td><td class="text-end">${fmt(r.trainers)}</td><td class="text-end">${fmt(r.trainers ? r.apprentices / r.trainers : null, '', 1)}</td></tr>`).join('') || '<tr><td colspan="8" class="text-muted">Aucune donnée</td></tr>'}</tbody></table></div></div>
    </div></div></details>`;

  // ---------------------------------------------------------------- Graphiques
  const g = (id, config, has = true, text = undefined) => (has ? draw(charts, box.querySelector(`#${id}`), config) : empty(box, id, text));
  g('c-sector', bars({ labels: a.by_sector.map((r) => r.label), series: sexSeries(a.by_sector) }), a.by_sector.length > 0);
  g('c-duration', bars({ labels: a.by_duration.map((r) => r.label), series: sexSeries(a.by_duration) }), a.by_duration.length > 0);
  g('c-region', bars({
    labels: regions.map((r) => r.name),
    series: [{ label: 'Hommes', data: regions.map((r) => r.apprentices_male), color: SEX.male }, { label: 'Femmes', data: regions.map((r) => r.apprentices_female), color: SEX.female }],
    line: { label: 'Indice de parité F/H', data: regions.map((r) => (r.apprentices_male ? Math.round((100 * r.apprentices_female) / r.apprentices_male) / 100 : null)), color: C.purple, axis: 'Indice de parité' },
  }), regions.length > 0);
  g('c-sex', ring({ labels: ['Hommes', 'Femmes'], data: [a.male, a.female], colors: [SEX.male, SEX.female], center: fmt(a.female_share, ' %', 0), sub: 'de femmes' }), a.total > 0);

  fitHeight(box, 'c-trades', a.top_trades.length);
  g('c-trades', bars({ labels: a.top_trades.map((x) => `${x.label} (${fmt(x.structures)})`), horizontal: true, legend: false,
    series: [{ label: 'Apprentis', data: a.top_trades.map((x) => x.total), color: C.teal }] }), a.top_trades.length > 0);

  const ratios = regions.filter((r) => r.trainers > 0).map((r) => ({ name: r.name, v: Math.round((10 * r.apprentices) / r.trainers) / 10 })).sort((x, y) => y.v - x.v);
  fitHeight(box, 'c-ratio', ratios.length, 32);
  g('c-ratio', bars({ labels: ratios.map((r) => r.name), horizontal: true, legend: false,
    series: [{ label: 'Apprentis par formateur', data: ratios.map((r) => r.v), colors: ratios.map((r) => (r.v > (t.apprentices_per_trainer ?? 0) ? C.orange : C.green)) }] }),
  ratios.length > 0, 'Aucun formateur déclaré.');

  const types = [...i.structures.by_type].sort((x, y) => y.count - x.count);
  fitHeight(box, 'c-types', types.length);
  g('c-types', bars({ labels: types.map((x) => x.label), horizontal: true, legend: false, series: [{ label: 'Structures', data: types.map((x) => x.count), color: C.blue }] }), types.length > 0);
  const zones = i.structures.by_zone || [];
  g('c-zone', ring({ labels: zones.map((x) => x.label), data: zones.map((x) => x.count), colors: [C.blue, C.gold, C.grey] }), zones.length > 0);
  const legal = i.structures.by_legal_status || [];
  g('c-legal', ring({ labels: legal.map((x) => x.label), data: legal.map((x) => x.count), colors: [C.green, C.orange, C.grey] }), legal.length > 0);

  g('c-vuln', bars({ labels: i.vulnerable.map((r) => r.label), series: sexSeries(i.vulnerable) }), vulnTotal > 0, 'Aucun apprenti vulnérable déclaré.');
  g('c-acc', bars({ labels: i.accompanied.map((r) => r.label), series: sexSeries(i.accompanied) }), (acc.INSCRITS?.total ?? 0) > 0, 'Aucun jeune accompagné déclaré.');

  g('c-grade', ring({ labels: ['Maîtres artisans', 'Formateurs'], data: [t.maitres_artisans, t.formateurs], colors: [C.teal, C.blue] }), t.total > 0, 'Aucun encadrant déclaré.');
  g('c-tsex', ring({ labels: ['Hommes', 'Femmes'], data: [t.male, t.female], colors: [SEX.male, SEX.female], center: fmt(pct(t.female, t.total), ' %', 0), sub: 'de femmes' }), t.total > 0, 'Aucun encadrant déclaré.');
  g('c-nat', ring({ labels: ['Nigériens', 'UEMOA', 'Autres'], data: [t.nationality.NIGERIEN, t.nationality.UEMOA, t.nationality.AUTRE], colors: [C.green, C.gold, C.grey] }), t.total > 0, 'Aucun encadrant déclaré.');
  const profile = [['Certifiés', t.certified], ['Formation initiale', t.initial_training], ['Suivi pédagogique', t.pedagogical_followup]];
  g('c-profile', bars({ labels: profile.map((x) => x[0]), percent: true, legend: false,
    series: [{ label: '% des encadrants', data: profile.map((x) => pct(x[1], t.total)), colors: profile.map((x) => toneColor(pct(x[1], t.total))) }] }), t.total > 0, 'Aucun encadrant déclaré.');

  const equip = [...i.yes_no].filter((q) => q.answered > 0).sort((x, y) => (y.rate ?? 0) - (x.rate ?? 0));
  fitHeight(box, 'c-equip', equip.length, 26);
  g('c-equip', bars({ labels: equip.map((q) => `${q.number} ${q.label.replace(/^La structure dispose-t-elle d(e |'|’)/i, '').replace(/\s*\?$/, '')}`), horizontal: true, percent: true, legend: false,
    series: [{ label: '% de structures', data: equip.map((q) => q.rate), colors: equip.map((q) => toneColor(q.rate)) }] }), equip.length > 0);
  i.multiple_choice.forEach((q, k) => {
    const opts = [...q.options].sort((x, y) => (y.rate ?? 0) - (x.rate ?? 0));
    fitHeight(box, `c-mc-${k}`, opts.length);
    g(`c-mc-${k}`, bars({ labels: opts.map((o) => o.label), horizontal: true, percent: true, legend: false,
      series: [{ label: '% de structures', data: opts.map((o) => o.rate), color: PALETTE[k % PALETTE.length] }] }), opts.length > 0);
  });
}

// ===================================================================== Avancement

async function progressTab(box, cid) {
  box.innerHTML = `<div class="d-flex flex-wrap gap-2 align-items-center mb-2"><select class="form-select w-auto" id="p-level" aria-label="Niveau">
      <option value="region">Par région</option><option value="department">Par département</option><option value="commune">Par commune</option></select>
      ${exportButtons([['progress', 'xlsx', 'Excel', '#p-level'], ['progress', 'csv', 'CSV', '#p-level'], ['progress', 'pdf', 'PDF', '#p-level']])}</div><div id="p-body"></div>`;
  const load = async () => {
    resetCharts();
    const p = (await get('/stats/progress', { campaign_id: cid, level: box.querySelector('#p-level').value })).data;
    const line = (r, total = false) => `<tr class="${total ? 'fw-semibold table-light' : ''}"><td>${h(r.name)}</td><td class="text-end">${fmt(r.targeted)}</td><td class="text-end">${fmt(r.assigned)}</td>
      <td class="text-end">${fmt(r.collected)}</td><td class="text-end">${fmt(r.soumise + r.en_controle)}</td><td class="text-end">${fmt(r.validee)}</td><td class="text-end">${fmt(r.invalidee + r.reouverte)}</td>
      <td class="text-end">${fmt(r.not_collected)}</td><td class="text-end">${fmt(r.coverage_rate, ' %')}</td><td class="text-end">${fmt(r.validation_rate, ' %')}</td></tr>`;
    const tot = p.total;
    const many = p.rows.length > 25;
    const ranked = [...p.rows].sort((x, y) => (x.coverage_rate ?? 0) - (y.coverage_rate ?? 0));
    const shown = many ? ranked.slice(0, 25) : [...ranked].reverse();
    box.querySelector('#p-body').innerHTML = `<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-3">
        <div class="col">${kpi({ label: 'Couverture', value: fmt(tot.coverage_rate, ' %', 1), sub: `${fmt(tot.collected)} / ${fmt(tot.targeted)} centres`, icon: 'pie-chart' })}</div>
        <div class="col">${kpi({ label: 'Validation', value: fmt(tot.validation_rate, ' %', 1), sub: `${fmt(tot.validee)} centres validés`, icon: 'patch-check' })}</div>
        <div class="col">${kpi({ label: 'Non affectés', value: fmt(tot.targeted - tot.assigned), sub: 'centres sans agent', icon: 'person-x', tone: tot.targeted - tot.assigned > 0 ? 'warn' : 'good' })}</div>
        <div class="col">${kpi({ label: 'Écart entre unités', value: p.rows.length ? `${fmt(ranked[0]?.coverage_rate, ' %', 0)} – ${fmt(ranked.at(-1)?.coverage_rate, ' %', 0)}` : '—', sub: 'couverture minimale – maximale', icon: 'arrows-expand' })}</div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-xl-7">${panel('c-prog', many ? 'Centres par état : les 25 unités les plus en retard' : 'Centres par état et par unité', { sub: 'Validés, collectés en attente ou à corriger, non collectés — barres côte à côte.' })}</div>
        <div class="col-xl-5">${panel('c-cov', many ? 'Couverture : les 25 unités les plus en retard' : 'Taux de couverture et de validation', { sub: 'Part des centres ciblés ; vert ≥ 75 %, orange ≥ 50 %, rouge en dessous.' })}</div>
      </div>
      <div class="card"><div class="card-body table-responsive"><table class="table table-sm"><thead><tr><th>Unité</th><th class="text-end">Ciblées</th><th class="text-end">Affectées</th><th class="text-end">Collectées</th>
      <th class="text-end">À contrôler</th><th class="text-end">Validées</th><th class="text-end">À corriger</th><th class="text-end">Non collectées</th><th class="text-end">Couverture</th><th class="text-end">Validation</th></tr></thead>
      <tbody>${p.rows.map((r) => line(r)).join('')}${line(tot, true)}</tbody></table></div></div>`;
    if (!p.rows.length) {
      empty(box, 'c-prog');
      empty(box, 'c-cov');
      return;
    }
    fitHeight(box, 'c-prog', shown.length, 40, 260);
    draw(charts, box.querySelector('#c-prog'), bars({ labels: shown.map((r) => r.name), horizontal: true, series: [
      { label: 'Validés', data: shown.map((r) => r.validee), color: C.green },
      { label: 'En attente ou à corriger', data: shown.map((r) => r.collected - r.validee), color: C.blue },
      { label: 'Non collectés', data: shown.map((r) => r.not_collected), color: C.grey },
    ] }));
    fitHeight(box, 'c-cov', shown.length, 34, 260);
    draw(charts, box.querySelector('#c-cov'), bars({ labels: shown.map((r) => r.name), horizontal: true, percent: true, series: [
      { label: 'Couverture', data: shown.map((r) => r.coverage_rate), colors: shown.map((r) => toneColor(r.coverage_rate)) },
      { label: 'Validation', data: shown.map((r) => r.validation_rate), color: 'rgba(18,138,62,.4)' },
    ] }));
  };
  box.querySelector('#p-level').addEventListener('change', () => load().catch(toastError));
  await load();
}

// ===================================================================== Agents

async function agentsTab(box, cid) {
  const rows = (await get('/stats/agents', { campaign_id: cid })).data;
  const active = rows.filter((r) => r.assigned > 0);
  const totals = active.reduce((s, r) => ({ assigned: s.assigned + r.assigned, collected: s.collected + r.collected, invalidated: s.invalidated + r.invalidated }), { assigned: 0, collected: 0, invalidated: 0 });
  const rates = active.map((r) => r.completion_rate ?? 0).sort((x, y) => x - y);
  const median = rates.length ? (rates.length % 2 ? rates[(rates.length - 1) / 2] : (rates[rates.length / 2 - 1] + rates[rates.length / 2]) / 2) : null;
  const ranked = [...active].sort((x, y) => (x.completion_rate ?? 0) - (y.completion_rate ?? 0)).slice(0, 30);
  const invalRate = pct(totals.invalidated, totals.collected);
  box.innerHTML = `<div class="d-flex justify-content-between flex-wrap gap-2 mb-3"><div class="small text-muted">${fmt(active.length)} agent(s) affecté(s) dans votre périmètre.</div>${exportButtons([['agents', 'xlsx', 'Excel'], ['agents', 'csv', 'CSV']])}</div>
    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-3">
      <div class="col">${kpi({ label: 'Réalisation globale', value: fmt(pct(totals.collected, totals.assigned), ' %', 1), sub: `${fmt(totals.collected)} / ${fmt(totals.assigned)} structures`, icon: 'clipboard-check' })}</div>
      <div class="col">${kpi({ label: 'Réalisation médiane', value: fmt(median, ' %', 1), sub: 'la moitié des agents fait mieux', icon: 'bar-chart-steps', help: 'Médiane des taux de réalisation individuels (moins sensible aux valeurs extrêmes que la moyenne).' })}</div>
      <div class="col">${kpi({ label: 'Invalidation globale', value: fmt(invalRate, ' %', 1), sub: `${fmt(totals.invalidated)} collecte(s) renvoyée(s)`, icon: 'arrow-counterclockwise', tone: invalRate === null ? 'neutral' : invalRate > 15 ? 'warn' : 'good' })}</div>
      <div class="col">${kpi({ label: 'Agents sans collecte', value: fmt(active.filter((r) => r.collected === 0).length), sub: 'à relancer en priorité', icon: 'person-exclamation', tone: active.some((r) => r.collected === 0) ? 'bad' : 'good' })}</div>
    </div>
    <div class="row g-3 mb-3"><div class="col-12">${panel('c-agents', ranked.length < active.length ? 'Réalisation et invalidation : les 30 agents les plus en retard' : 'Réalisation et invalidation par agent', { sub: 'Réalisation : structures collectées / affectées ; invalidation : collectes renvoyées / collectées.' })}</div></div>
    <div class="card"><div class="card-body table-responsive"><table class="table table-sm table-hover">
    <thead><tr><th>Agent</th><th class="text-end">Affectées</th><th class="text-end">Collectées</th><th class="text-end">À contrôler</th><th class="text-end">Validées</th><th class="text-end">Invalidées</th>
    <th class="text-end">Restantes</th><th class="text-end">Réalisation</th><th class="text-end">Invalidation</th><th>Dernière soumission</th></tr></thead><tbody>
    ${rows.map((r) => `<tr><td>${h(r.name)} <span class="small text-muted">${h(r.matricule)}</span></td><td class="text-end">${fmt(r.assigned)}</td><td class="text-end">${fmt(r.collected)}</td>
      <td class="text-end">${fmt(r.pending)}</td><td class="text-end">${fmt(r.validated)}</td><td class="text-end ${r.invalidated ? 'text-danger' : ''}">${fmt(r.invalidated)}</td>
      <td class="text-end">${fmt(r.remaining)}</td><td class="text-end">${fmt(r.completion_rate, ' %')}</td><td class="text-end">${fmt(r.invalidation_rate, ' %')}</td><td>${fmtDate(r.last_submitted_at, true)}</td></tr>`).join('')
      || '<tr><td colspan="10" class="text-muted">Aucun agent affecté dans votre périmètre.</td></tr>'}</tbody></table></div></div>`;
  if (!ranked.length) { empty(box, 'c-agents', 'Aucun agent affecté.'); return; }
  fitHeight(box, 'c-agents', ranked.length, 34, 240);
  draw(charts, box.querySelector('#c-agents'), bars({ labels: ranked.map((r) => r.name), horizontal: true, percent: true, series: [
    { label: 'Réalisation', data: ranked.map((r) => r.completion_rate), colors: ranked.map((r) => toneColor(r.completion_rate)) },
    { label: 'Invalidation', data: ranked.map((r) => r.invalidation_rate), color: 'rgba(176,42,55,.55)' },
  ] }));
}

// ===================================================================== Évolution

async function timelineTab(box, cid) {
  box.innerHTML = `<div class="mb-2"><select class="form-select w-auto" id="t-gran" aria-label="Granularité"><option value="day">Par jour</option><option value="week">Par semaine</option></select></div>
    <div class="row g-3"><div class="col-12">${panel('c-time', 'Collectes reçues et validées', { size: 'xtall', sub: 'Barres : nouvelles collectes de la période (axe de gauche) ; courbes pleines : cumuls (axe de droite) ; pointillés : moyenne mobile.' })}</div></div>`;
  const load = async () => {
    resetCharts();
    const gran = box.querySelector('#t-gran').value;
    const t = (await get('/stats/timeline', { campaign_id: cid, granularity: gran })).data;
    if (!t.series.length) { empty(box, 'c-time', 'Aucune collecte reçue.'); return; }
    const per = t.series.map((s) => s.submitted);
    draw(charts, box.querySelector('#c-time'), timeline({
      labels: t.series.map((s) => (gran === 'week' ? `sem. du ${fmtDate(s.period)}` : fmtDate(s.period))), yTitle: gran === 'week' ? 'Par semaine' : 'Par jour', y1Title: 'Cumul',
      barSeries: { label: 'Collectes reçues', data: per, color: 'rgba(211,84,0,.55)' },
      lines: [
        { label: 'Validées (période)', data: t.series.map((s) => s.validated), color: C.green, points: 3 },
        { label: gran === 'week' ? 'Moyenne mobile 4 semaines' : 'Moyenne mobile 7 jours', data: movingAverage(per, gran === 'week' ? 4 : 7), color: C.orange, dash: [5, 4] },
        { label: 'Reçues (cumul)', data: t.series.map((s) => s.submitted_cumulative), color: C.blue, axis: 'y1', width: 3 },
        { label: 'Validées (cumul)', data: t.series.map((s) => s.validated_cumulative), color: C.teal, axis: 'y1', width: 3 },
      ],
    }));
  };
  box.querySelector('#t-gran').addEventListener('change', () => load().catch(toastError));
  await load();
}

// ===================================================================== Comparaison

async function compareTab(box, camps, basis) {
  box.innerHTML = `<div class="card mb-3"><div class="card-body"><label class="form-label" for="cmp-sel">Campagnes à comparer (Ctrl + clic, 2 à 6)</label>
      <select class="form-select mb-2" id="cmp-sel" multiple size="5">${options(camps.map((c) => ({ id: c.id, name: `${c.code} — ${c.name}` })), '')}</select>
      <button class="btn btn-brand btn-sm" id="cmp-go" type="button">Comparer</button></div></div><div id="cmp-body"></div>`;
  box.querySelector('#cmp-go').addEventListener('click', async () => {
    const ids = [...box.querySelector('#cmp-sel').selectedOptions].map((o) => o.value);
    try {
      resetCharts();
      const c = (await get('/stats/compare', { campaign_ids: ids.join(','), basis })).data;
      const rows = [['Structures recensées', 'structures'], ['Apprentis', 'apprentices'], ['dont femmes', 'apprentices_female'], ['Part des femmes', 'female_share', ' %'],
        ['Formateurs', 'trainers'], ['Apprentis par formateur', 'apprentices_per_trainer'], ['Jeunes accompagnés inscrits', 'accompanied_inscrits'], ['Publics vulnérables', 'vulnerable']];
      const evolution = (key) => {
        const first = c.campaigns[0]?.[key];
        const last = c.campaigns.at(-1)?.[key];
        return first ? fmt(Math.round((1000 * (last - first)) / first) / 10, ' %', 1) : '—';
      };
      box.querySelector('#cmp-body').innerHTML = `${c.notes.map((n) => `<div class="alert alert-${c.comparable ? 'secondary' : 'warning'} small">${h(n)}</div>`).join('')}
        <div class="row g-3 mb-3"><div class="col-xl-7">${panel('c-cmp', 'Apprentis par sexe et part des femmes', { size: 'tall', sub: 'Barres : effectifs (axe de gauche) ; courbe : part des femmes en % (axe de droite).' })}</div>
          <div class="col-xl-5">${panel('c-cmp2', 'Structures et encadrement', { size: 'tall', sub: 'Barres : structures recensées ; courbe : apprentis par formateur.' })}</div></div>
        <div class="card"><div class="card-body table-responsive"><table class="table table-sm"><thead><tr><th></th>${c.campaigns.map((x) => `<th class="text-end">${h(x.campaign.code)}<div class="small text-muted fw-normal">${h(x.campaign.questionnaire_version)}</div></th>`).join('')}<th class="text-end">Évolution (première → dernière)</th></tr></thead>
        <tbody>${rows.map(([label, key, suffix]) => `<tr><td>${h(label)}</td>${c.campaigns.map((x) => `<td class="text-end">${fmt(x[key], suffix || '')}</td>`).join('')}<td class="text-end text-muted">${suffix ? '' : evolution(key)}</td></tr>`).join('')}</tbody></table></div></div>`;
      const labels = c.campaigns.map((x) => x.campaign.code);
      draw(charts, box.querySelector('#c-cmp'), bars({ labels,
        series: [{ label: 'Hommes', data: c.campaigns.map((x) => (x.apprentices ?? 0) - (x.apprentices_female ?? 0)), color: SEX.male }, { label: 'Femmes', data: c.campaigns.map((x) => x.apprentices_female), color: SEX.female }],
        line: { label: 'Part des femmes (%)', data: c.campaigns.map((x) => x.female_share), color: C.purple, axis: '% femmes' } }));
      draw(charts, box.querySelector('#c-cmp2'), bars({ labels, series: [{ label: 'Structures', data: c.campaigns.map((x) => x.structures), color: C.blue }],
        line: { label: 'Apprentis par formateur', data: c.campaigns.map((x) => x.apprentices_per_trainer), color: C.orange, axis: 'Apprentis / formateur' } }));
    } catch (e) { toastError(e); }
  });
}

async function runExport(dataset, format, filters) {
  try {
    const job = (await post('/exports', { dataset, format, filters })).data;
    if (job.status === 'TERMINE' && job.file) {
      await download(`/files/${job.file.uuid}`, job.file.name);
      toast('Export téléchargé.');
    } else if (job.status === 'ECHEC') {
      toast(job.error || "L'export a échoué.", 'error');
    } else {
      toast('Export en préparation : il apparaîtra dans la page « Exports ».', 'info');
    }
  } catch (e) { toastError(e); }
}

// ===================================================================== Mes exports

export async function exportsPage(el) {
  const camps = (await get('/campaigns')).data.filter((c) => c.status !== 'PREPARATION');
  el.innerHTML = pageHeader('Exports', '<button class="btn btn-outline-secondary" id="x-refresh" type="button"><i class="bi bi-arrow-clockwise me-1"></i>Actualiser</button>')
    + `<div class="card mb-3"><div class="card-body"><form class="row g-2 align-items-end" id="x-form">
        <div class="col-md-4"><label class="form-label" for="x-camp">Campagne</label><select class="form-select" id="x-camp">${options(camps.map((c) => ({ id: c.id, name: `${c.code} — ${c.name}` })), camps[0]?.id)}</select></div>
        <div class="col-md-3"><label class="form-label" for="x-ds">Données</label><select class="form-select" id="x-ds">
          <option value="collections">Microdonnées des collectes</option><option value="indicators">Indicateurs</option><option value="progress">Avancement</option><option value="agents">Performance des agents</option></select></div>
        <div class="col-md-2"><label class="form-label" for="x-fmt">Format</label><select class="form-select" id="x-fmt"></select></div>
        <div class="col-md-2"><label class="form-label" for="x-basis">Base</label><select class="form-select" id="x-basis"><option value="">Par défaut</option><option value="validated">Validées</option><option value="submitted">Soumises et validées</option></select></div>
        <div class="col-md-1"><button class="btn btn-brand w-100" type="submit">Lancer</button></div>
      </form><p class="small text-muted mt-2 mb-0">Les microdonnées n'incluent pas les noms et téléphones des répondants ni les noms des formateurs. Fichiers disponibles 7 jours, pour vous seul.</p></div></div>
      <div class="card"><div class="card-body" id="x-list"></div></div>`;
  const formats = { collections: ['xlsx', 'csv'], indicators: ['xlsx', 'pdf'], progress: ['xlsx', 'csv', 'pdf'], agents: ['xlsx', 'csv'] };
  const refreshFormats = () => { el.querySelector('#x-fmt').innerHTML = options(formats[el.querySelector('#x-ds').value].map((f) => ({ id: f, name: f.toUpperCase() })), ''); };
  refreshFormats();
  el.querySelector('#x-ds').addEventListener('change', refreshFormats);
  const stateBadge = (j) => (j.status === 'TERMINE' ? '<span class="badge badge-status st-VALIDEE">Prêt</span>'
    : j.status === 'ECHEC' ? `<span class="badge text-bg-danger">Échec</span> <span class="small">${h(j.error)}</span>` : '<span class="badge text-bg-secondary">En préparation</span>');
  const loadList = async () => {
    const jobs = (await get('/exports')).data;
    el.querySelector('#x-list').innerHTML = jobs.length === 0 ? '<p class="text-muted mb-0">Aucun export.</p>' : `<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Demandé le</th><th>Données</th><th>Format</th><th>Lignes</th><th>État</th><th></th></tr></thead><tbody>
      ${jobs.map((j) => `<tr><td>${fmtDate(j.created_at, true)}</td><td>${h(j.dataset_label)}</td><td>${h(j.format.toUpperCase())}</td><td>${fmt(j.rows)}</td><td>${stateBadge(j)}</td>
        <td>${j.file ? `<button class="btn btn-sm btn-outline-success" data-file="${h(j.file.uuid)}" data-name="${h(j.file.name)}"><i class="bi bi-download me-1"></i>Télécharger</button>` : (j.status === 'TERMINE' ? '<span class="small text-muted">expiré</span>' : '')}</td></tr>`).join('')}</tbody></table></div>`;
  };
  el.querySelector('#x-form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const filters = { campaign_id: Number(el.querySelector('#x-camp').value) };
    if (el.querySelector('#x-basis').value) filters.basis = el.querySelector('#x-basis').value;
    try {
      const job = (await post('/exports', { dataset: el.querySelector('#x-ds').value, format: el.querySelector('#x-fmt').value, filters })).data;
      toast(job.status === 'TERMINE' ? 'Export prêt.' : job.status === 'ECHEC' ? (job.error || 'Échec.') : 'Export en préparation.', job.status === 'ECHEC' ? 'error' : 'success');
      await loadList();
    } catch (e) { toastError(e); }
  });
  el.querySelector('#x-refresh').addEventListener('click', () => loadList().catch(toastError));
  el.onclick = (ev) => {
    const b = ev.target.closest('[data-file]');
    if (b) download(`/files/${b.dataset.file}`, b.dataset.name).catch(toastError);
  };
  if (camps.length === 0) el.querySelector('#x-form').innerHTML = '<p class="text-muted mb-0">Aucune campagne ouverte ou clôturée.</p>';
  await loadList();
}
