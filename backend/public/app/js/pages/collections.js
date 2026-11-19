// Collectes : liste (dont « à traiter par moi »), fiche de contrôle lisible, décisions de validation.
import { get, post, blobUrl } from '../api.js';
import {
  h, badge, fmtDate, modal, toast, toastError, formValues, options, getTerritory, bindGeoSelects, serverTable, pageHeader,
  enhanceForm, REQ, STATUS_LABELS,
} from '../ui.js';
import { can } from '../session.js';

const definitions = {};
let trades = null;
let reasons = null;

// ===================================================================== Liste

export async function list(el) {
  const query = new URLSearchParams(window.location.hash.split('?')[1] || '');
  const camps = (await get('/campaigns')).data.filter((c) => c.status !== 'PREPARATION');
  const canBulk = can('collection.review');
  el.innerHTML = pageHeader('Collectes')
    + `<div class="card mb-3"><div class="card-body filters d-flex flex-wrap gap-2 align-items-center">
        <select class="form-select" id="f-camp" aria-label="Campagne">${options(camps.map((c) => ({ id: c.id, name: `${c.code} — ${c.name}` })), query.get('campaign_id'), { empty: 'Toutes les campagnes' })}</select>
        <select class="form-select" id="f-status" aria-label="Statut">${options(['SOUMISE', 'EN_CONTROLE', 'VALIDEE', 'INVALIDEE', 'REOUVERTE'].map((s) => ({ id: s, name: STATUS_LABELS[s] })), query.get('status'), { empty: 'Tous les statuts' })}</select>
        <select class="form-select" id="f-level" aria-label="Niveau de contrôle">${options([{ id: 1, name: 'Niveau 1 (départemental)' }, { id: 2, name: 'Niveau 2 (régional)' }], '', { empty: 'Tous les niveaux' })}</select>
        <select class="form-select" data-geo="region" aria-label="Région"></select>
        <select class="form-select" data-geo="department" aria-label="Département"></select>
        <select class="form-select" data-geo="commune" aria-label="Commune"></select>
        <input class="form-control" id="f-q" placeholder="Structure (nom ou code)" aria-label="Recherche">
        ${can('collection.review') ? `<div class="form-check"><input class="form-check-input" type="checkbox" id="f-me"${query.get('awaiting_me') === '1' ? ' checked' : ''}><label class="form-check-label" for="f-me">À traiter par moi</label></div>` : ''}
      </div></div>
      ${canBulk ? `<div class="card mb-3 border-success" id="bulk" hidden><div class="card-body d-flex flex-wrap gap-3 align-items-center">
        <span id="bulk-count" class="fw-semibold"></span>
        <button class="btn btn-green" id="bulk-validate"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Valider la sélection</button>
        <button class="btn btn-outline-secondary btn-sm" id="bulk-clear">Tout décocher</button>
        <span class="small text-muted">Les collectes avec avertissements méritent d'être ouvertes avant validation.</span></div></div>` : ''}
      <div class="card"><div class="card-body"><table class="table table-sm table-hover w-100" id="col-table"><thead><tr>
        ${canBulk ? '<th><input class="form-check-input" type="checkbox" id="sel-all" aria-label="Cocher toutes les collectes à contrôler de la page"></th>' : ''}
        <th>Structure</th><th>Commune</th><th>Agent</th><th>Campagne</th><th>Reçue le</th><th>Rév.</th><th>Avert.</th><th>Statut</th><th></th></tr></thead></table></div></div>`;
  const filters = el.querySelector('.filters');
  let table = null;
  const reload = () => table?.ajax.reload();
  await bindGeoSelects(filters, reload);
  const selected = new Map(); // uuid → { name, warnings }
  const pending = (c) => ['SOUMISE', 'EN_CONTROLE'].includes(c.status);
  const updateBulk = () => {
    if (!canBulk) return;
    el.querySelector('#bulk').hidden = selected.size === 0;
    const w = [...selected.values()].filter((s) => s.warnings > 0).length;
    el.querySelector('#bulk-count').textContent = `${selected.size} collecte(s) sélectionnée(s)${w ? ` — dont ${w} avec avertissements` : ''}`;
  };
  const selectColumn = canBulk ? [{ data: null, render: (c) => (pending(c)
    ? `<input class="form-check-input" type="checkbox" data-sel="${h(c.uuid)}" data-w="${h(c.warnings_count || 0)}" data-name="${h(c.structure.name)}"${selected.has(c.uuid) ? ' checked' : ''} aria-label="Sélectionner ${h(c.structure.name)}">`
    : '') }] : [];
  table = serverTable(el.querySelector('#col-table'), [
    ...selectColumn,
    { data: null, render: (c) => `${h(c.structure.name)}<div class="small text-muted"><code>${h(c.structure.official_code)}</code></div>` },
    { data: null, render: (c) => `${h(c.commune)}<div class="small text-muted">${h(c.department)}</div>` },
    { data: null, render: (c) => `${h(c.agent.name)}<div class="small text-muted">${h(c.agent.matricule)}</div>` },
    { data: null, render: (c) => h(c.campaign) },
    { data: null, render: (c) => fmtDate(c.last_submitted_at, true) },
    { data: null, render: (c) => h(c.revision) },
    { data: null, render: (c) => (c.warnings_count ? `<span class="badge text-bg-warning">${h(c.warnings_count)}</span>` : '') },
    { data: null, render: (c) => `${badge(c.status)}${['SOUMISE', 'EN_CONTROLE'].includes(c.status) && c.validation_levels === 2 ? ` <span class="small text-muted">niv. ${h(c.review_level)}</span>` : ''}${c.claimed_by ? `<div class="small text-muted">${h(c.claimed_by)}</div>` : ''}` },
    { data: null, render: (c) => `<a class="btn btn-sm btn-outline-secondary" href="#/collectes/${h(c.uuid)}">Ouvrir</a>` },
  ], (p) => get('/collections', { ...p, campaign_id: el.querySelector('#f-camp').value, status: el.querySelector('#f-status').value,
    review_level: el.querySelector('#f-level').value, q: el.querySelector('#f-q').value.trim(),
    region_id: filters.querySelector('[data-geo="region"]').value, department_id: filters.querySelector('[data-geo="department"]').value,
    commune_id: filters.querySelector('[data-geo="commune"]').value, awaiting_me: el.querySelector('#f-me')?.checked ? 1 : '' }));
  let timer;
  el.querySelector('#f-q').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(reload, 350); });
  ['#f-camp', '#f-status', '#f-level', '#f-me'].forEach((s) => el.querySelector(s)?.addEventListener('change', reload));
  if (!canBulk) return;

  // Validation groupée : cocher les collectes à contrôler, puis « Valider la sélection ».
  table.on('draw', () => { const all = el.querySelector('#sel-all'); if (all) all.checked = false; });
  el.addEventListener('change', (ev) => {
    const box = ev.target;
    if (box.id === 'sel-all') {
      el.querySelectorAll('[data-sel]').forEach((b) => { b.checked = box.checked; b.dispatchEvent(new Event('change', { bubbles: true })); });
      return;
    }
    if (box.dataset?.sel === undefined) return;
    if (box.checked) selected.set(box.dataset.sel, { name: box.dataset.name, warnings: Number(box.dataset.w) });
    else selected.delete(box.dataset.sel);
    updateBulk();
  });
  el.querySelector('#bulk-clear').addEventListener('click', () => {
    selected.clear();
    el.querySelectorAll('[data-sel], #sel-all').forEach((b) => { b.checked = false; });
    updateBulk();
  });
  el.querySelector('#bulk-validate').addEventListener('click', () => {
    const items = [...selected.entries()];
    const withWarnings = items.filter(([, s]) => s.warnings > 0);
    modal({
      title: `Valider ${items.length} collecte(s)`,
      body: `<p>Chaque collecte passe au niveau de contrôle suivant, ou devient validée au dernier niveau. Les mêmes contrôles que pour une validation unitaire s'appliquent.</p>
        ${withWarnings.length ? `<div class="alert alert-warning small mb-3"><strong>${withWarnings.length} collecte(s) ont des avertissements</strong> (questions vides, incohérences) :
          ${withWarnings.slice(0, 8).map(([, s]) => h(s.name)).join(', ')}${withWarnings.length > 8 ? '…' : ''}. Ouvrez-les si vous ne les avez pas encore vérifiées.</div>` : ''}
        <form autocomplete="off"><label class="form-label" for="bulk-comment">Commentaire (facultatif, enregistré sur chaque collecte)</label>
        <textarea class="form-control" id="bulk-comment" name="comment" rows="2" maxlength="500"></textarea></form>`,
      buttons: [{ label: `Valider ${items.length} collecte(s)`, class: 'btn-green', action: async (m) => {
        const comment = m.body.querySelector('#bulk-comment').value.trim();
        const r = (await post('/collections/validate-batch', { uuids: items.map(([u]) => u), ...(comment ? { comment } : {}) })).data;
        selected.clear();
        updateBulk();
        reload();
        if (r.failed.length === 0) {
          toast(`${r.validated.length} collecte(s) validée(s).`);
        } else {
          const names = new Map(items.map(([u, s]) => [u, s.name]));
          modal({ title: `${r.validated.length} validée(s), ${r.failed.length} non validée(s)`,
            body: `<ul class="mb-0">${r.failed.map((f) => `<li><strong>${h(names.get(f.uuid) || f.uuid)}</strong> : ${h(f.message)}</li>`).join('')}</ul>` });
        }
        return true;
      } }],
    });
  });
}

// ===================================================================== Fiche de contrôle

export async function detail(el, { uuid }) {
  const c = (await get(`/collections/${uuid}`)).data;
  definitions[c.questionnaire_version] ??= (await get(`/questionnaires/${c.questionnaire_version}`)).data;
  trades ??= (await get('/referentials/trades')).data.trades;
  const territory = await getTerritory();
  const def = definitions[c.questionnaire_version];
  const previous = (await get(`/collections/${uuid}/previous`)).data;

  const ACTION_BUTTONS = {
    claim: '<button class="btn btn-outline-primary" data-act="claim"><i class="bi bi-hand-index me-1"></i>Prendre en charge</button>',
    release: '<button class="btn btn-outline-secondary" data-act="release">Libérer</button>',
    validate: '<button class="btn btn-green" data-act="validate"><i class="bi bi-check2-circle me-1"></i>Valider</button>',
    invalidate: '<button class="btn btn-outline-danger" data-act="invalidate"><i class="bi bi-x-circle me-1"></i>Invalider</button>',
    reopen: '<button class="btn btn-outline-warning" data-act="reopen">Rouvrir</button>',
    correct: `<a class="btn btn-outline-success" href="#/collectes/${h(uuid)}/corriger"><i class="bi bi-pencil-square me-1"></i>Ouvrir et corriger</a>`,
  };
  const levelText = c.campaign.validation_levels === 2 && ['SOUMISE', 'EN_CONTROLE'].includes(c.status) ? `niveau ${c.review_level} sur 2` : '';
  const keyNumbers = [['APPRENTICES_TOTAL', 'Apprentis (Section IV)'], ['Q2_19.T', 'Maîtres artisans / formateurs'],
    ['Q2_2_INSCRITS_TOTAL', 'Jeunes accompagnés inscrits'], ['Q2_20_T', 'Accompagnés par le FAFPA']];
  const decisionLabel = { VALIDEE: 'Validée', INVALIDEE: 'Invalidée', REOUVERTURE: 'Rouverte' };

  el.innerHTML = `<div class="page-title"><div><a href="#/collectes" class="small"><i class="bi bi-arrow-left"></i> Collectes</a>
      <h1>${h(c.structure.name)} <small class="text-muted fs-6"><code>${h(c.structure.official_code)}</code></small></h1>
      <div class="small text-muted">${h(c.territory.region)} › ${h(c.territory.department)} › ${h(c.territory.commune)}${c.territory.locality ? ` › ${h(c.territory.locality)}` : ''}
        — campagne ${h(c.campaign.code)} — questionnaire ${h(c.questionnaire_version)}</div></div>
      <div class="d-flex flex-wrap gap-2 align-items-center">${badge(c.status)}<span class="small">${h(levelText)}</span>${c.allowed_actions.map((a) => ACTION_BUTTONS[a] || '').join('')}</div></div>
    <div class="row g-3">
      <div class="col-xl-8"><div class="card"><div class="card-body">${renderData(def, c.data, territory)}</div></div></div>
      <div class="col-xl-4">
        <div class="card mb-3"><div class="card-body small">
          <div>Agent : <strong>${h(c.agent.name)}</strong> (${h(c.agent.matricule)})</div>
          <div>Révision ${h(c.revision)} — reçue le ${fmtDate(c.last_submitted_at, true)}</div>
          <div>Collecte sur tablette : ${fmtDate(c.device_started_at, true)} → ${fmtDate(c.device_completed_at, true)}</div>
          <div>Fiche papier signée : ${c.paper_form_signed ? 'oui' : 'non'}</div>
          ${c.claimed_by ? `<div>En contrôle par ${h(c.claimed_by.name)} depuis ${fmtDate(c.claimed_by.at, true)}</div>` : ''}
          <div>GPS : ${c.gps.lat !== null ? `${h(c.gps.lat)}, ${h(c.gps.lng)} (± ${h(c.gps.accuracy_m ?? '?')} m)` : h(c.gps.status)}${c.gps.note ? ` — ${h(c.gps.note)}` : ''}</div>
        </div></div>
        <div class="card mb-3"><div class="card-body"><h2 class="h6">Avertissements (${h(c.warnings.length)})</h2>
          ${c.warnings.length === 0 ? '<p class="small text-muted mb-0">Aucun.</p>' : `<ul class="list-unstyled small mb-0">${c.warnings.map((w) => `<li class="issue-warning ps-2 mb-2">${h(w.message)}<div class="text-muted">${h(w.target)}</div></li>`).join('')}</ul>`}
          ${c.identity_differences.length ? `<h3 class="h6 mt-3">Écarts avec le registre</h3><ul class="small mb-0">${c.identity_differences.map((d) => `<li>${h(d.question)} : registre « ${h(d.registry)} », collecté « ${h(d.collected)} »</li>`).join('')}</ul>` : ''}
          ${c.locked_overrides.length ? `<p class="small text-muted mt-2 mb-0">Identité reprise du registre (la tablette envoyait une autre valeur) : ${h(c.locked_overrides.join(', '))}.</p>` : ''}
        </div></div>
        <div class="card mb-3"><div class="card-body"><h2 class="h6">Chiffres clés${previous ? ` — comparaison avec ${h(previous.campaign)}` : ''}</h2>
          <table class="table table-sm small mb-0"><thead><tr><th></th><th class="text-end">Actuel</th>${previous ? '<th class="text-end">Précédent</th>' : ''}</tr></thead><tbody>
          ${keyNumbers.map(([k, label]) => `<tr><td>${h(label)}</td><td class="text-end">${h(c.computed[k] ?? '—')}</td>${previous ? `<td class="text-end">${h(previous.numbers[k] ?? '—')}</td>` : ''}</tr>`).join('')}
          </tbody></table></div></div>
        ${c.attachments.length ? `<div class="card mb-3"><div class="card-body"><h2 class="h6">Pièces jointes</h2><div class="d-flex flex-wrap gap-2">
          ${c.attachments.map((a) => `<button class="btn btn-sm btn-outline-secondary" data-att="${h(a.id)}"><i class="bi bi-image me-1"></i>${h(a.kind === 'FICHE_SIGNEE' ? 'Fiche signée' : 'Photo')}</button>`).join('')}</div></div></div>` : ''}
        <div class="card mb-3"><div class="card-body"><h2 class="h6">Décisions</h2>${c.reviews.length === 0 ? '<p class="small text-muted mb-0">Aucune.</p>' : `<ul class="list-unstyled small mb-0">${c.reviews.map((r) => `<li class="mb-2">
          <strong>${h(decisionLabel[r.decision] || r.decision)}</strong> — ${Number(r.level) === 9 ? 'administrateur' : `niveau ${h(r.level)}`} — rév. ${h(r.revision)} — ${fmtDate(r.created_at, true)}<br>
          ${h(r.reviewer_name)}${r.reason ? ` — ${h(r.reason)}` : ''}${r.comment ? `<div class="text-muted">${h(r.comment)}</div>` : ''}</li>`).join('')}</ul>`}</div></div>
        <div class="card"><div class="card-body"><h2 class="h6">Historique</h2><ul class="list-unstyled small mb-0">${c.status_history.map((x) => `<li>${fmtDate(x.created_at, true)} : ${h(STATUS_LABELS[x.to_status] || x.to_status)}
          (rév. ${h(x.revision)}) — ${h(x.user_matricule || '')}${x.note ? ` — <span class="text-muted">${h(x.note)}</span>` : ''}</li>`).join('')}</ul></div></div>
      </div></div>`;

  el.onclick = async (ev) => {
    const att = ev.target.closest('[data-att]');
    if (att) {
      try {
        const url = await blobUrl(`/collections/${uuid}/attachments/${att.dataset.att}`);
        const m = modal({ title: 'Pièce jointe', size: 'modal-lg', body: `<img class="img-fluid" alt="Pièce jointe de la collecte" src="${h(url)}">` });
        m.el.addEventListener('hidden.bs.modal', () => URL.revokeObjectURL(url));
      } catch (e) { toastError(e); }
      return;
    }
    const b = ev.target.closest('[data-act]');
    if (!b) return;
    const act = b.dataset.act;
    const refresh = () => detail(el, { uuid });
    try {
      if (act === 'claim' || act === 'release') {
        await post(`/collections/${uuid}/${act}`);
        toast(act === 'claim' ? 'Collecte prise en charge.' : 'Collecte libérée.');
        await refresh();
      }
      // Fenêtre de décision : formulaire contrôlé (enhanceForm) puis envoi ; false garde la fenêtre ouverte.
      const decision = (opts, send) => {
        let check = () => true;
        const m = modal({ title: opts.title, body: `<form autocomplete="off">${opts.body}</form>`,
          buttons: [{ label: opts.label, class: opts.class, action: async (mm) => {
            if (!check()) return false;
            await send(formValues(mm.body.querySelector('form')));
            await refresh();
            return true;
          } }] });
        check = enhanceForm(m.body.querySelector('form'));
      };
      const who = `<p class="small text-muted mb-2">${h(c.structure.name)} — révision ${h(c.revision)}, agent ${h(c.agent.name)}</p>`;
      if (act === 'validate') {
        const last = c.review_level >= c.campaign.validation_levels;
        decision({ title: 'Valider la collecte', label: 'Valider', class: 'btn-green',
          body: `${who}<div class="alert ${last ? 'alert-success' : 'alert-info'} small"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>${last ? 'La collecte sera définitivement validée.' : 'La collecte sera transmise au contrôle régional (niveau 2).'}</div>
          ${c.warnings.length ? `<p class="small text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>${h(c.warnings.length)} avertissement(s) restent signalés sur cette fiche.</p>` : ''}
          <label class="form-label" for="v-comment">Commentaire</label><textarea class="form-control" id="v-comment" name="comment" rows="2" maxlength="2000" aria-describedby="v-comment-hint"></textarea>
          <small class="form-hint" id="v-comment-hint">Facultatif ; visible dans l'historique des décisions.</small>` },
        async (v) => { await post(`/collections/${uuid}/validate`, v); toast('Collecte validée.'); });
      }
      if (act === 'invalidate') {
        reasons ??= (await get('/rejection-reasons')).data;
        decision({ title: 'Invalider la collecte', label: 'Invalider', class: 'btn-danger',
          body: `${who}<p>La collecte sera renvoyée à l'agent pour correction, avec votre motif.</p>
          <div class="mb-3"><label class="form-label" for="i-reason">Motif${REQ}</label><select class="form-select" id="i-reason" name="reason_id" required data-error="Choisir un motif.">${options(reasons.map((r) => ({ id: r.id, name: r.label })), '', { empty: 'Choisir…' })}</select></div>
          <label class="form-label" for="i-comment">Précisions pour l'agent</label><textarea class="form-control" id="i-comment" name="comment" rows="3" maxlength="2000" aria-describedby="i-comment-hint"></textarea>
          <small class="form-hint" id="i-comment-hint">Indiquer les questions à revoir ; le motif et ces précisions sont transmis à la tablette de l'agent.</small>` },
        async (v) => {
          await post(`/collections/${uuid}/invalidate`, { reason_id: Number(v.reason_id), comment: v.comment });
          toast("Collecte invalidée : elle sera renvoyée à l'agent.");
        });
      }
      if (act === 'reopen') {
        decision({ title: 'Rouvrir une collecte validée', label: 'Rouvrir', class: 'btn-warning',
          body: `${who}<div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Mesure exceptionnelle : l'agent pourra modifier et resoumettre la collecte. La réouverture est journalisée.</div>
          <label class="form-label" for="r-reason">Motif${REQ}</label><textarea class="form-control" id="r-reason" name="reason" rows="3" required minlength="3" maxlength="500" data-error="Motif obligatoire (3 caractères au moins)."></textarea>` },
        async (v) => { await post(`/collections/${uuid}/reopen`, v); toast('Collecte rouverte.'); });
      }
    } catch (e) { toastError(e); }
  };
}

// ===================================================================== Rendu des réponses à partir de la définition du questionnaire

function renderData(def, data, territory) {
  const optLabel = (item, code) => {
    if (item.options_source?.startsWith('trades.')) {
      if (code === 'AUTRE') return 'Autre';
      return trades.find((t) => String(t.id) === String(code))?.label ?? `Métier n° ${code}`;
    }
    return item.options?.find((o) => String(o.code) === String(code))?.label ?? code;
  };
  const geoName = (code, v) => {
    const list = code.endsWith('_1') ? territory.regions : code.endsWith('_2') ? territory.departments : territory.communes;
    return list.find((x) => x.id === v)?.name ?? v;
  };
  const fmt = (item, v) => {
    if (v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0)) return null;
    switch (item.type) {
      case 'yesno': return v === true ? 'Oui' : v === false ? 'Non' : h(v);
      case 'single': return h(optLabel(item, v));
      case 'multi': return (Array.isArray(v) ? v : [v]).map((x) => h(optLabel(item, x))).join(', ');
      case 'geo': return h(geoName(item.code, v));
      case 'gps': return v.lat !== undefined && v.lat !== null ? `${h(v.lat)}, ${h(v.lng)} (± ${h(v.accuracy_m ?? '?')} m)` : `${h(v.status)} — ${h(v.note || '')}`;
      default: return h(v);
    }
  };
  const getPath = (obj, path) => path.split('.').reduce((o, k) => (o === null || o === undefined ? undefined : o[k]), obj);

  return def.sections.map((s) => {
    const rows = s.items.map((item) => {
      if (['computed', 'computed_grid', 'auto', 'note', 'title'].includes(item.type)) return '';
      const value = getPath(data, item.code);
      const label = `<span class="q-num">${h(item.number || '')}</span>${h(item.label)}`;
      if (item.type === 'grid') {
        if (!value) return '';
        return `<div class="px-2 py-2"><div class="q-label mb-1">${label}</div><div class="table-responsive"><table class="table table-sm table-bordered small mb-0">
          <thead><tr><th></th>${item.columns.map((c) => `<th class="text-end">${h(c.label || c.code)}</th>`).join('')}</tr></thead><tbody>
          ${item.rows.map((r) => `<tr><th>${h(r.label)}</th>${item.columns.map((c) => `<td class="text-end">${h(getPath(value, `${r.code}.${c.code}`) ?? '')}</td>`).join('')}</tr>`).join('')}
          </tbody></table></div></div>`;
      }
      if (item.type === 'roster') {
        if (!Array.isArray(value) || value.length === 0) return `<div class="q-row"><div class="q-label">${label}</div><div class="q-empty">Aucune ligne</div></div>`;
        return `<div class="px-2 py-2"><div class="q-label mb-1">${label} (${value.length})</div><div class="table-responsive"><table class="table table-sm table-bordered small mb-0">
          <thead><tr><th>#</th>${item.columns.map((c) => `<th>${h(c.label || c.code)}</th>`).join('')}</tr></thead><tbody>
          ${value.map((row, i) => `<tr><td>${i + 1}</td>${item.columns.map((c) => `<td>${fmt(c, row[c.code]) ?? ''}</td>`).join('')}</tr>`).join('')}
          </tbody></table></div></div>`;
      }
      const shown = fmt(item, value);
      return shown === null ? '' : `<div class="q-row"><div class="q-label">${label}</div><div>${shown}</div></div>`;
    }).join('');
    return rows ? `<section class="q-section"><h2>${h(s.number ? `${s.number}. ` : '')}${h(s.title)}</h2>${rows}</section>` : '';
  }).join('');
}
