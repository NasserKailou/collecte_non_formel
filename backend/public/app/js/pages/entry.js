// « Ma saisie » : saisie directe ou saisie de fiches papier dans le back-office, à partir de la définition versionnée
// du questionnaire. Contrôle par le serveur (mêmes règles que la tablette), sauvegarde automatique du brouillon,
// soumission par le circuit commun (POST /collections/{uuid}/submissions).

import { get, put, post, del } from '../api.js';
import { h, toast, toastError, pageHeader, confirmBox, REQ, STATUS_LABELS } from '../ui.js';
import { apply, truthy, varPath } from '../jsonlogic.js';

const STATE = {
  A_SAISIR: ['À saisir', 'text-bg-secondary'],
  BROUILLON: ['Brouillon', 'st-PROPOSEE'],
  A_CORRIGER: ['À corriger', 'st-INVALIDEE'],
  SOUMISE: ['Soumise', 'st-VALIDEE'],
};
const MODE = { TABLETTE: 'Tablette (saisie web possible)', PAPIER: 'Questionnaire papier', MIXTE: 'Mixte (tablette, web, papier)' };

// ============================================================================ Liste

export async function list(el) {
  const rows = (await get('/entry/assignments')).data;
  el.innerHTML = pageHeader('Ma saisie')
    + `<div class="card mb-3"><div class="card-body filters d-flex flex-wrap gap-2 align-items-center">
        <input class="form-control" id="e-q" type="search" placeholder="Nom, code, commune…" aria-label="Rechercher une structure">
        <select class="form-select w-auto" id="e-state" aria-label="État">
          <option value="">Tous les états</option>${Object.entries(STATE).map(([k, [l]]) => `<option value="${k}">${h(l)}</option>`).join('')}</select>
        <span class="small text-muted ms-auto" id="e-count"></span>
      </div></div>
      <div class="card"><div class="card-body table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr>
        <th>Structure</th><th>Commune</th><th>Campagne</th><th>État</th><th></th></tr></thead><tbody id="e-rows"></tbody></table></div></div>`;
  const draw = () => {
    const q = el.querySelector('#e-q').value.trim().toLowerCase();
    const st = el.querySelector('#e-state').value;
    const shown = rows.filter((r) => (!st || r.state === st) && (!q || `${r.name} ${r.official_code} ${r.commune} ${r.department}`.toLowerCase().includes(q)));
    el.querySelector('#e-count').textContent = `${shown.length} structure(s) sur ${rows.length}`;
    el.querySelector('#e-rows').innerHTML = shown.length === 0
      ? `<tr><td colspan="5" class="text-muted">${rows.length ? 'Aucune structure pour ce filtre.' : 'Aucune structure ne vous est affectée dans une campagne ouverte.'}</td></tr>`
      : shown.map((r) => {
        const [label, cls] = STATE[r.state];
        const action = r.state === 'SOUMISE' ? 'Voir' : r.state === 'A_SAISIR' ? 'Saisir' : 'Reprendre';
        return `<tr><td><div class="fw-semibold">${h(r.name)}</div><div class="small text-muted"><code>${h(r.official_code || 'non codifiée')}</code></div></td>
          <td>${h(r.commune)}<div class="small text-muted">${h(r.department)}</div></td>
          <td>${h(r.campaign_name)}<div class="small text-muted">${h(MODE[r.collection_mode] || r.collection_mode)}</div></td>
          <td><span class="badge badge-status ${cls}">${h(label)}</span>${r.draft_errors > 0 && r.state === 'BROUILLON' ? `<div class="small text-muted">${h(r.draft_errors)} point(s) à compléter</div>` : ''}</td>
          <td class="text-end"><a class="btn btn-sm ${r.state === 'SOUMISE' ? 'btn-outline-secondary' : 'btn-brand'}" href="#/ma-saisie/${h(r.campaign_structure_id)}">${action}</a></td></tr>`;
      }).join('');
  };
  el.querySelector('#e-q').addEventListener('input', draw);
  el.querySelector('#e-state').addEventListener('change', draw);
  draw();
}

// ======================================================================== Formulaire

export async function form(el, { cs }) {
  const s = (await get(`/entry/${encodeURIComponent(cs)}`)).data;
  const def = s.definition;
  const state = {
    data: s.data || {},
    channel: s.channel,
    paper: s.paper || { form_ref: '', interview_date: '', enumerator: '' },
    validation: s.validation,
    section: 0,
    saving: false,
    dirty: false,
    timer: null,
  };
  const editable = s.editable;
  const allowed = s.campaign.allowed_channels.filter((c) => c !== 'MOBILE');

  el.innerHTML = `<div class="page-title"><div><a href="#/ma-saisie" class="small"><i class="bi bi-arrow-left" aria-hidden="true"></i> Ma saisie</a>
      <h1>${h(s.structure.name)} <small class="text-muted fs-6"><code>${h(s.structure.official_code || '')}</code></small></h1>
      <div class="small text-muted">${h(s.structure.region)} › ${h(s.structure.department)} › ${h(s.structure.commune)} — ${h(s.campaign.name)} — questionnaire ${h(s.campaign.questionnaire_version)}</div></div>
      <div class="d-flex flex-wrap gap-2 align-items-center"><span class="small text-muted" id="f-saved" aria-live="polite"></span>
        ${editable ? `<button class="btn btn-outline-secondary" data-act="discard">Abandonner le brouillon</button>
        <button class="btn btn-brand" data-act="submit"><i class="bi bi-send me-1" aria-hidden="true"></i>Soumettre</button>` : ''}</div></div>
    ${banner(s)}
    ${editable ? channelBlock(allowed, state) : ''}
    <div class="row g-3">
      <div class="col-lg-3"><nav class="card"><div class="list-group list-group-flush" id="f-sections" aria-label="Sections du questionnaire"></div></nav>
        <div class="card mt-3"><div class="card-body"><h2 class="h6">Contrôle</h2><div id="f-issues" class="small" aria-live="polite"></div></div></div></div>
      <div class="col-lg-9"><div class="card"><div class="card-body" id="f-body"></div></div>
        <div class="d-flex justify-content-between mt-3"><button class="btn btn-outline-secondary" data-act="prev"><i class="bi bi-chevron-left" aria-hidden="true"></i> Section précédente</button>
          <button class="btn btn-outline-secondary" data-act="next">Section suivante <i class="bi bi-chevron-right" aria-hidden="true"></i></button></div></div>
    </div>`;

  const body = el.querySelector('#f-body');

  // ------------------------------------------------------------------ Rendu
  const visCtx = () => ({ ...state.data, ...(state.validation?.computed || {}), _ctx: {} });
  const visible = (item, ctx) => !item.visible_if || truthy(apply(item.visible_if, ctx));
  const allIssues = () => [...(state.validation?.errors || []), ...(state.validation?.warnings || [])];
  const issuesFor = (target) => allIssues().filter((i) => i.target === target);
  const inSection = (sec, i) => sec.items.some((it) => i.target === it.code || i.target.startsWith(`${it.code}.`) || i.target.startsWith(`${it.code}[`));
  const sectionIssues = (sec) => (state.validation?.errors || []).filter((i) => inSection(sec, i));
  const sectionMissing = (sec) => (state.validation?.warnings || []).filter((i) => i.code === 'REQUIRED' && inSection(sec, i));

  const drawNav = () => {
    el.querySelector('#f-sections').innerHTML = def.sections.map((sec, i) => {
      const n = sectionIssues(sec).length;
      const m = sectionMissing(sec).length;
      return `<button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2 ${i === state.section ? 'active' : ''}"
        data-section="${i}" ${i === state.section ? 'aria-current="true"' : ''}><span>${h(sec.number)}. ${h(sec.title)}</span><span class="text-nowrap">`
        + `${n ? `<span class="badge text-bg-danger" title="Erreurs à corriger">${n}</span>` : ''}`
        + `${m ? ` <span class="badge text-bg-warning" title="Questions non renseignées">${m}</span>` : ''}</span></button>`;
    }).join('');
    const v = state.validation;
    el.querySelector('#f-issues').innerHTML = !v ? '<span class="text-muted">Lecture seule.</span>'
      : (v.errors.length === 0 ? '<p class="text-success mb-2"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Aucune erreur bloquante.</p>' : `<p class="text-danger fw-semibold mb-2">${v.errors.length} erreur(s) bloquante(s)</p>`)
        + (v.warnings.length ? `<p class="text-warning-emphasis mb-2">${v.warnings.length} avertissement(s)</p>` : '')
        + issueSummary(v);
  };

  /** Erreurs d'abord (toutes), puis incohérences, puis questions vides regroupées par section (une ligne chacune). */
  const issueSummary = (v) => {
    const link = (i) => `<li class="mb-1"><a href="#" data-goto="${h(i.target)}" class="${i.severity === 'error' ? 'text-danger' : 'text-warning-emphasis'}">${h(i.message)}</a></li>`;
    const others = v.warnings.filter((i) => i.code !== 'REQUIRED');
    const missing = def.sections.map((sec, idx) => ({ sec, idx, n: sectionMissing(sec).length })).filter((x) => x.n > 0);
    return `<ul class="list-unstyled mb-2">${v.errors.map(link).join('')}${others.slice(0, 20).map(link).join('')}</ul>`
      + (missing.length ? `<p class="mb-1 fw-semibold text-warning-emphasis">Questions non renseignées (non bloquant) :</p><ul class="list-unstyled mb-0">`
        + missing.map((x) => `<li class="mb-1"><a href="#" data-section="${x.idx}" class="text-warning-emphasis">${h(x.sec.number)}. ${h(x.sec.title)} — ${x.n}</a></li>`).join('') + '</ul>' : '');
  };

  const drawSection = () => {
    const sec = def.sections[state.section];
    const ctx = visCtx();
    let group = null;
    body.innerHTML = `<h2 class="h5 mb-3" tabindex="-1">${h(sec.number)}. ${h(sec.title)}</h2>` + sec.items.filter((it) => visible(it, ctx)).map((it) => {
      const g = it.group && it.group !== group ? `<h3 class="h6 text-success mt-4">${h(it.group)}</h3>` : '';
      group = it.group || group;
      return `${g}<div class="mb-4" data-item="${h(it.code)}">${renderItem(it, ctx)}</div>`;
    }).join('');
    el.querySelector('[data-act="prev"]').disabled = state.section === 0;
    el.querySelector('[data-act="next"]').disabled = state.section === def.sections.length - 1;
    drawNav();
  };

  // Question attendue : repère orange discret (jamais bloquant), comme sur la tablette.
  const label = (it) => `${it.number ? `<span class="text-muted me-1">${h(it.number)}</span>` : ''}${h(it.label)}${it.required ? '<span class="expected-mark" title="À renseigner" aria-hidden="true">•</span>' : ''}`;
  const help = (it) => (it.help ? `<div class="form-hint mb-1">${h(it.help)}</div>` : '');
  const feedback = (target) => issuesFor(target).map((i) => `<div class="small mt-1 ${i.severity === 'error' ? 'text-danger' : 'text-warning-emphasis'}"><i class="bi ${i.severity === 'error' ? 'bi-exclamation-circle' : 'bi-exclamation-triangle'} me-1" aria-hidden="true"></i>${h(i.message)}</div>`).join('');
  const dis = editable ? '' : 'disabled';
  const domId = (path) => `f-${path.replace(/[^A-Za-z0-9_-]/g, '_')}`;

  function optionsOf(it) {
    if (it.options) return it.options.map((o) => ({ code: o.code, label: o.label }));
    if (it.options_source?.startsWith('trades.')) return [...(s.trades[it.options_source] || []), { code: 'AUTRE', label: 'Autre (préciser)' }];
    return [];
  }

  /** Champ de saisie ; `target` = cible des anomalies du moteur (diffère du chemin pour les lignes de tableau). */
  function control(it, path, value, target = path) {
    const id = domId(path);
    const attrs = `data-path="${h(path)}" data-type="${h(it.type)}"`;
    const invalid = issuesFor(target).some((i) => i.severity === 'error') ? ' is-invalid' : '';
    switch (it.type) {
      case 'text':
        return it.multiline ? `<textarea class="form-control${invalid}" rows="3" id="${id}" ${attrs} ${dis}>${h(value ?? '')}</textarea>`
          : `<input class="form-control${invalid}" id="${id}" ${attrs} value="${h(value ?? '')}" ${it.max_length ? `maxlength="${h(it.max_length)}"` : ''} ${dis}>`;
      case 'phone':
        return `<input class="form-control${invalid}" type="tel" inputmode="numeric" maxlength="8" placeholder="8 chiffres" id="${id}" ${attrs} value="${h(value ?? '')}" ${dis}>`;
      case 'integer':
        return `<input class="form-control${invalid}" type="number" inputmode="numeric" step="1" ${it.min !== undefined ? `min="${h(it.min)}"` : ''} ${it.max !== undefined ? `max="${h(it.max)}"` : ''} id="${id}" ${attrs} value="${h(value ?? '')}" ${dis}>`;
      case 'date':
        return `<input class="form-control${invalid}" ${it.partial ? 'placeholder="AAAA-MM-JJ, AAAA-MM ou AAAA" inputmode="numeric"' : 'type="date"'} id="${id}" ${attrs} value="${h(value ?? '')}" ${dis}>`;
      case 'yesno':
        return `<div class="btn-group" role="radiogroup" aria-label="${h(it.label)}">${[[true, 'Oui'], [false, 'Non']].map(([v, l]) => `
          <input type="radio" class="btn-check" name="${id}" id="${id}-${v}" ${attrs} data-value="${v}" ${value === v ? 'checked' : ''} ${dis}>
          <label class="btn btn-outline-success" for="${id}-${v}">${l}</label>`).join('')}</div>`;
      case 'single': {
        const opts = optionsOf(it);
        if (opts.length <= 6) {
          return `<div role="radiogroup" aria-label="${h(it.label)}">${opts.map((o, k) => `<div class="form-check"><input class="form-check-input" type="radio" name="${id}" id="${id}-${k}" ${attrs}
            data-value="${h(JSON.stringify(o.code))}" ${value === o.code ? 'checked' : ''} ${dis}><label class="form-check-label" for="${id}-${k}">${h(o.label)}</label></div>`).join('')}</div>`;
        }
        return `<select class="form-select${invalid}" id="${id}" ${attrs} ${dis}><option value="">Choisir…</option>${opts.map((o) => `<option value="${h(JSON.stringify(o.code))}" ${value === o.code ? 'selected' : ''}>${h(o.label)}</option>`).join('')}</select>`;
      }
      case 'multi': {
        const sel = Array.isArray(value) ? value : [];
        return `<div role="group" aria-label="${h(it.label)}">${optionsOf(it).map((o, k) => `<div class="form-check"><input class="form-check-input" type="checkbox" id="${id}-${k}" ${attrs}
          data-value="${h(JSON.stringify(o.code))}" ${sel.includes(o.code) ? 'checked' : ''} ${dis}><label class="form-check-label" for="${id}-${k}">${h(o.label)}</label></div>`).join('')}</div>`;
      }
      case 'gps': return gpsControl(value || {});
      default: return `<div class="text-muted">Type « ${h(it.type)} » non pris en charge.</div>`;
    }
  }

  function gpsControl(g) {
    const status = g.status || '';
    const known = status === 'MANUEL' || status === 'OK';
    return `<div class="row g-2 align-items-end">
      <div class="col-sm-4"><label class="form-label small" for="gps-status">Relevé</label>
        <select class="form-select" id="gps-status" data-gps-field="status" ${dis}>
          <option value="">Choisir…</option><option value="MANUEL" ${known ? 'selected' : ''}>Coordonnées connues (fiche ou relevé)</option>
          <option value="INDISPONIBLE" ${status === 'INDISPONIBLE' ? 'selected' : ''}>Position indisponible</option><option value="REFUSE" ${status === 'REFUSE' ? 'selected' : ''}>Relevé refusé</option></select></div>
      ${known ? `<div class="col-sm-3"><label class="form-label small" for="gps-lat">Latitude</label><input class="form-control" id="gps-lat" type="number" step="0.000001" data-gps-field="lat" value="${h(g.lat ?? '')}" placeholder="13.512345" ${dis}></div>
        <div class="col-sm-3"><label class="form-label small" for="gps-lng">Longitude</label><input class="form-control" id="gps-lng" type="number" step="0.000001" data-gps-field="lng" value="${h(g.lng ?? '')}" placeholder="2.112345" ${dis}></div>
        <div class="col-sm-2">${editable ? '<button type="button" class="btn btn-outline-success w-100" data-act="geoloc" title="Position de cet appareil (seulement si vous êtes sur place)" aria-label="Utiliser la position de cet appareil"><i class="bi bi-crosshair" aria-hidden="true"></i></button>' : ''}</div>`
        : status ? `<div class="col-sm-8"><label class="form-label small" for="gps-note">Motif${REQ}</label><input class="form-control" id="gps-note" data-gps-field="note" value="${h(g.note ?? '')}" ${dis}></div>` : ''}
    </div><div class="form-hint">Saisie au bureau : reporter les coordonnées notées sur la fiche (degrés décimaux), sinon indiquer pourquoi elles manquent.</div>`;
  }

  function renderItem(it, ctx) {
    switch (it.type) {
      case 'geo': {
        const name = { region: s.structure.region, department: s.structure.department, commune: s.structure.commune }[it.level];
        return `<div class="form-label">${label(it)}</div><div class="form-control-plaintext border rounded px-2 bg-light">${h(name || '—')}</div><div class="form-hint">Repris du registre (non modifiable).</div>`;
      }
      case 'auto':
        return `<div class="form-label">${label(it)}</div><div class="form-control-plaintext text-muted">Renseigné automatiquement par le serveur.</div>`;
      case 'computed':
      case 'computed_grid':
        return `<div class="form-label">${label(it)}</div>${computedView(it)}${help(it)}`;
      case 'grid': return gridView(it);
      case 'roster': return rosterView(it, ctx);
      default: {
        const value = varPath(it.code, state.data);
        if (it.locked) return `<div class="form-label">${label(it)}</div><div class="form-control-plaintext border rounded px-2 bg-light">${h(display(it, value))}</div><div class="form-hint">Repris du registre (non modifiable).</div>`;
        const lbl = ['yesno', 'single', 'multi', 'gps'].includes(it.type)
          ? `<div class="form-label fw-semibold">${label(it)}</div>`
          : `<label class="form-label fw-semibold" for="${domId(it.code)}">${label(it)}</label>`;
        return lbl + help(it) + control(it, it.code, value) + feedback(it.code);
      }
    }
  }

  function display(it, v) {
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v === 'boolean') return v ? 'Oui' : 'Non';
    const opts = optionsOf(it);
    const name = (x) => opts.find((o) => o.code === x)?.label ?? String(x);
    return Array.isArray(v) ? v.map(name).join(' ; ') : (opts.length ? name(v) : String(v));
  }

  function computedView(it) {
    const val = varPath(it.source || it.code, state.validation?.computed || {});
    if (it.type === 'computed_grid') {
      return `<div class="table-responsive"><table class="table table-sm table-bordered small mb-1"><thead><tr><th></th>${it.columns.map((c) => `<th class="text-end">${h(c.label)}</th>`).join('')}</tr></thead>
        <tbody>${it.rows.map((r) => `<tr><th>${h(r.label)}</th>${it.columns.map((c) => `<td class="text-end">${h(varPath(`${r.code}.${c.code}`, val) ?? 0)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
    }
    if (val && typeof val === 'object') return `<div>${['M', 'F', 'T'].filter((k) => k in val).map((k) => `${{ M: 'Hommes', F: 'Femmes', T: 'Total' }[k]} : <strong>${h(val[k])}</strong>`).join(' · ')}</div>`;
    return `<div><strong>${h(val ?? 0)}</strong></div>`;
  }

  function gridView(it) {
    const cell = { ...it.cell, required: it.required };
    const cells = new Set(it.rows.flatMap((r) => it.columns.map((c) => `${it.code}.${r.code}.${c.code}`)));
    const own = allIssues().filter((i) => (i.target === it.code || i.target.startsWith(`${it.code}.`)) && !cells.has(i.target));
    return `<div class="form-label fw-semibold">${label(it)}</div>${help(it)}<div class="table-responsive"><table class="table table-sm table-bordered align-middle">
      <thead><tr><th scope="col"></th>${it.columns.map((c) => `<th scope="col" class="small">${h(c.label)}</th>`).join('')}</tr></thead><tbody>
      ${it.rows.map((r) => `<tr><th scope="row" class="small">${h(r.label)}</th>${it.columns.map((c) => {
        const path = `${it.code}.${r.code}.${c.code}`;
        const bad = issuesFor(path).filter((i) => i.severity === 'error');
        return `<td><input class="form-control form-control-sm${bad.length ? ' is-invalid' : ''}" type="number" min="${h(cell.min ?? 0)}" ${cell.max !== undefined ? `max="${h(cell.max)}"` : ''} step="1" inputmode="numeric"
          data-path="${h(path)}" data-type="integer" value="${h(varPath(path, state.data) ?? '')}" aria-label="${h(`${r.label} — ${c.label}`)}" ${bad.length ? `title="${h(bad[0].message)}"` : ''} ${dis}></td>`;
      }).join('')}</tr>`).join('')}</tbody></table></div>`
      + own.map((i) => `<div class="small ${i.severity === 'error' ? 'text-danger' : 'text-warning-emphasis'}">${h(i.message)}</div>`).join('');
  }

  function rosterView(it, ctx) {
    const raw = varPath(it.code, state.data);
    const rows = Array.isArray(raw) ? raw : [];
    const rowLabel = (i) => (it.row_label || 'Ligne {_line}').replace('{_line}', i + 1);
    return `<div class="d-flex justify-content-between align-items-center mb-2"><div class="form-label fw-semibold mb-0">${label(it)}</div>
        <span class="small text-muted">${rows.filter(Boolean).length} ligne(s)</span></div>${help(it)}${feedback(it.code)}
      ${rows.map((row, i) => {
        if (!row) return '';
        const rctx = { ...row, _line: i + 1, _root: ctx };
        const errs = (state.validation?.errors || []).filter((x) => x.target.startsWith(`${it.code}[${i}]`)).length;
        return `<div class="border rounded p-3 mb-2"><div class="d-flex justify-content-between align-items-center mb-2">
            <strong>${h(rowLabel(i))}</strong><div>${errs ? `<span class="badge text-bg-danger me-2">${errs}</span>` : ''}
            ${editable ? `<button type="button" class="btn btn-sm btn-outline-danger" data-remove-row="${h(it.code)}" data-index="${i}" aria-label="Supprimer ${h(rowLabel(i))}"><i class="bi bi-trash" aria-hidden="true"></i></button>` : ''}</div></div>
          <div class="row g-3">${it.columns.filter((c) => visible(c, rctx)).map((c) => {
            const path = `${it.code}.${i}.${c.code}`;
            const target = `${it.code}[${i}].${c.code}`;
            const lblHtml = `${h(c.label)}${c.required ? REQ : ''}`;
            const lbl = ['yesno', 'single', 'multi'].includes(c.type) ? `<div class="form-label small fw-semibold">${lblHtml}</div>` : `<label class="form-label small fw-semibold" for="${domId(path)}">${lblHtml}</label>`;
            return `<div class="col-md-6">${lbl}${control(c, path, row[c.code], target)}${feedback(target)}</div>`;
          }).join('')}</div></div>`;
      }).join('')}
      ${editable && rows.length < (it.max_rows ?? 99) ? `<button type="button" class="btn btn-outline-success" data-add-row="${h(it.code)}"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Ajouter : ${h(rowLabel(rows.length))}</button>` : ''}`;
  }

  // ------------------------------------------------------------- Données
  function setPath(path, value) {
    const segs = path.split('.');
    let cur = state.data;
    for (let i = 0; i < segs.length - 1; i++) {
      const k = Array.isArray(cur) ? Number(segs[i]) : segs[i];
      if (cur[k] === null || typeof cur[k] !== 'object') cur[k] = {};
      cur = cur[k];
    }
    const last = Array.isArray(cur) ? Number(segs.at(-1)) : segs.at(-1);
    if (value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0 && !Array.isArray(cur[last]))) {
      if (Array.isArray(cur)) cur[last] = undefined; else delete cur[last];
    } else {
      cur[last] = value;
    }
  }

  function readInput(input) {
    const type = input.dataset.type;
    if (type === 'integer') {
      if (input.value === '') return null;
      const n = Number(input.value);
      return Number.isInteger(n) ? n : input.value;
    }
    if (type === 'yesno') return input.dataset.value === 'true';
    if (type === 'single') return input.tagName === 'SELECT' ? (input.value === '' ? null : JSON.parse(input.value)) : JSON.parse(input.dataset.value);
    if (type === 'multi') {
      return [...body.querySelectorAll(`input[data-path="${CSS.escape(input.dataset.path)}"]`)].filter((x) => x.checked).map((x) => JSON.parse(x.dataset.value));
    }
    return input.value;
  }

  /** Les lignes supprimées des tableaux laissent des trous : on les retire avant l'envoi. */
  function clean(v) {
    if (Array.isArray(v)) return v.filter((x) => x !== undefined && x !== null).map(clean);
    if (v && typeof v === 'object') return Object.fromEntries(Object.entries(v).filter(([, x]) => x !== undefined).map(([k, x]) => [k, clean(x)]));
    return v;
  }

  const savedLabel = el.querySelector('#f-saved');
  function scheduleSave() {
    if (!editable) return;
    state.dirty = true;
    savedLabel.textContent = 'Modifications non enregistrées…';
    clearTimeout(state.timer);
    state.timer = setTimeout(save, 1200);
  }

  async function save() {
    if (!state.dirty || state.saving) return;
    state.saving = true;
    state.dirty = false;
    try {
      const r = (await put(`/entry/${cs}/draft`, { data: clean(state.data), channel: state.channel, paper: state.channel === 'PAPIER' ? state.paper : null })).data;
      state.validation = r.validation;
      savedLabel.textContent = `Brouillon enregistré à ${new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`;
      refreshIssues();
    } catch (e) {
      state.dirty = true;
      savedLabel.textContent = "Échec de l'enregistrement : nouvel essai automatique.";
      toastError(e);
      clearTimeout(state.timer);
      state.timer = setTimeout(save, 10000);
    } finally {
      state.saving = false;
    }
  }

  /** Met à jour les messages sans perdre le champ en cours de saisie (focus et position du curseur conservés). */
  function refreshIssues() {
    const active = document.activeElement;
    const path = active?.dataset?.path;
    let pos = null;
    try { pos = active?.selectionStart ?? null; } catch { pos = null; }
    drawSection();
    if (path) {
      const again = body.querySelector(`[data-path="${CSS.escape(path)}"]:not([type="radio"]):not([type="checkbox"])`);
      if (again) {
        again.focus();
        try { if (pos !== null) again.setSelectionRange(pos, pos); } catch { /* champ numérique */ }
      }
    }
  }

  // ------------------------------------------------------------ Événements
  function gpsChanged(t) {
    const g = { lat: null, lng: null, accuracy_m: null, captured_at: null, status: null, note: null, ...(varPath('GPS', state.data) || {}) };
    const f = t.dataset.gpsField;
    g[f] = f === 'lat' || f === 'lng' ? (t.value === '' ? null : Number(t.value)) : (t.value || null);
    if (f === 'status' && g.status !== 'MANUEL') { g.lat = null; g.lng = null; g.accuracy_m = null; }
    setPath('GPS', g.status ? g : null);
    if (f === 'status') drawSection();
    scheduleSave();
  }

  el.oninput = (ev) => {
    const t = ev.target;
    if (t.dataset.paper) {
      state.paper[t.dataset.paper] = t.value;
      scheduleSave();
      return;
    }
    if (t.dataset.gpsField) {
      if (t.tagName !== 'SELECT') gpsChanged(t);
      return;
    }
    if (!t.dataset.path || ['radio', 'checkbox'].includes(t.type) || t.tagName === 'SELECT') return;
    setPath(t.dataset.path, readInput(t));
    scheduleSave();
  };
  el.onchange = (ev) => {
    const t = ev.target;
    if (t.name === 'channel') {
      state.channel = t.value;
      el.querySelector('#paper-block')?.classList.toggle('d-none', state.channel !== 'PAPIER');
      scheduleSave();
      return;
    }
    if (t.dataset.gpsField) {
      if (t.tagName === 'SELECT') gpsChanged(t);
      return;
    }
    if (!t.dataset.path) return;
    if (['radio', 'checkbox'].includes(t.type) || t.tagName === 'SELECT') {
      setPath(t.dataset.path, readInput(t));
      drawSection(); // la visibilité d'autres questions peut dépendre de cette réponse
      scheduleSave();
    }
  };

  el.onclick = async (ev) => {
    const b = ev.target.closest('[data-section],[data-act],[data-goto],[data-add-row],[data-remove-row]');
    if (!b) return;
    if (b.dataset.section !== undefined) {
      ev.preventDefault(); // liens « questions non renseignées » du panneau de contrôle (href="#")
      state.section = Number(b.dataset.section);
      drawSection();
      body.querySelector('h2')?.focus();
      return;
    }
    if (b.dataset.goto !== undefined) {
      ev.preventDefault();
      const target = b.dataset.goto;
      const idx = def.sections.findIndex((sec) => sec.items.some((it) => target === it.code || target.startsWith(`${it.code}.`) || target.startsWith(`${it.code}[`)));
      if (idx >= 0) { state.section = idx; drawSection(); }
      const m = target.match(/^(.*)\[(\d+)\]\.(.*)$/);
      body.querySelector(`[data-path="${CSS.escape(m ? `${m[1]}.${m[2]}.${m[3]}` : target)}"]`)?.focus();
      return;
    }
    if (b.dataset.addRow) {
      const raw = varPath(b.dataset.addRow, state.data);
      const rows = Array.isArray(raw) ? clean(raw) : [];
      rows.push({});
      setPath(b.dataset.addRow, rows);
      drawSection();
      scheduleSave();
      return;
    }
    if (b.dataset.removeRow) {
      if (await confirmBox({ title: 'Supprimer la ligne', message: `Supprimer la ligne ${Number(b.dataset.index) + 1} ?`, confirmLabel: 'Supprimer', danger: true }) === null) return;
      const rows = clean(varPath(b.dataset.removeRow, state.data) || []);
      rows.splice(Number(b.dataset.index), 1);
      setPath(b.dataset.removeRow, rows);
      drawSection();
      scheduleSave();
      return;
    }
    switch (b.dataset.act) {
      case 'prev': state.section = Math.max(0, state.section - 1); drawSection(); window.scrollTo(0, 0); break;
      case 'next': state.section = Math.min(def.sections.length - 1, state.section + 1); drawSection(); window.scrollTo(0, 0); break;
      case 'geoloc':
        if (!navigator.geolocation) { toast('Géolocalisation non disponible sur cet appareil.', 'error'); break; }
        navigator.geolocation.getCurrentPosition((pos) => {
          setPath('GPS', { lat: Number(pos.coords.latitude.toFixed(6)), lng: Number(pos.coords.longitude.toFixed(6)), accuracy_m: Number(pos.coords.accuracy.toFixed(1)),
            captured_at: new Date().toISOString(), status: 'OK', note: null });
          drawSection();
          scheduleSave();
        }, (err) => toast(`Position indisponible : ${err.message}`, 'error'), { enableHighAccuracy: true, timeout: 30000 });
        break;
      case 'discard':
        if (await confirmBox({ title: 'Abandonner le brouillon', message: "La saisie en cours sera effacée (la dernière version soumise, s'il y en a une, est conservée).", confirmLabel: 'Abandonner', danger: true }) === null) return;
        try {
          clearTimeout(state.timer);
          state.dirty = false;
          await del(`/entry/${cs}/draft`);
          toast('Brouillon abandonné.');
          window.location.hash = '#/ma-saisie';
        } catch (e) { toastError(e); }
        break;
      case 'submit': await submit(b); break;
      default:
    }
  };

  async function submit(btn) {
    clearTimeout(state.timer);
    if (state.channel === 'PAPIER' && ['form_ref', 'interview_date', 'enumerator'].some((k) => !String(state.paper[k] || '').trim())) {
      toast("Renseigner les références de la fiche papier (numéro, date de l'entretien, enquêteur).", 'error');
      el.querySelector('#paper-ref')?.focus();
      return;
    }
    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');
    try {
      state.dirty = true;
      await save();
      if (state.validation?.errors.length) {
        toast(`${state.validation.errors.length} erreur(s) bloquante(s) à corriger avant de soumettre.`, 'error');
        return;
      }
      const r = (await post(`/collections/${s.uuid}/submissions`, {
        campaign_structure_id: s.campaign_structure_id, questionnaire_version: s.campaign.questionnaire_version, base_revision: s.base_revision,
        data: clean(state.data), channel: state.channel, ...(state.channel === 'PAPIER' ? { paper: state.paper } : {}),
      })).data;
      state.dirty = false;
      toast(`Collecte soumise (révision ${r.revision})${r.warnings?.length ? ` avec ${r.warnings.length} avertissement(s)` : ''}.`);
      window.location.hash = '#/ma-saisie';
    } catch (e) {
      if (e.status === 422 && Array.isArray(e.details?.errors)) {
        state.validation = { ...(state.validation || {}), errors: e.details.errors.filter((x) => x.target), warnings: e.details.warnings || [] };
        drawSection();
      }
      toastError(e);
    } finally {
      btn.disabled = false;
      btn.removeAttribute('aria-busy');
    }
  }

  // En quittant la page : dernier enregistrement du brouillon.
  window.addEventListener('hashchange', () => { if (state.dirty) save(); }, { once: true });

  drawSection();
}

function banner(s) {
  if (!s.editable) {
    const via = { MOBILE: 'sur tablette', WEB: 'sur le web', PAPIER: 'depuis une fiche papier' }[s.collection.channel] || '';
    return `<div class="alert alert-success">Collecte ${h(STATUS_LABELS[s.collection.status] || s.collection.status)} (révision ${h(s.collection.revision)}, saisie ${h(via)}) : lecture seule.</div>`;
  }
  if (s.collection?.review) {
    return `<div class="alert alert-danger"><strong>Renvoyée pour correction${s.collection.review.reason ? ` : ${h(s.collection.review.reason)}` : ''}.</strong>${s.collection.review.comment ? `<div>${h(s.collection.review.comment)}</div>` : ''}</div>`;
  }
  return s.source === 'BROUILLON' ? '<div class="alert alert-info py-2 small">Reprise du brouillon enregistré.</div>' : '';
}

function channelBlock(allowed, state) {
  const radios = allowed.length > 1 ? `<div class="mb-2"><span class="form-label fw-semibold me-3">Mode de saisie</span>
      ${allowed.map((c) => `<div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="channel" id="ch-${c}" value="${c}" ${state.channel === c ? 'checked' : ''}>
        <label class="form-check-label" for="ch-${c}">${c === 'PAPIER' ? "Saisie d'une fiche papier" : 'Saisie directe'}</label></div>`).join('')}</div>` : '';
  if (!radios && state.channel !== 'PAPIER') return '';
  const p = state.paper;
  return `<div class="card mb-3"><div class="card-body">${radios}
    <div id="paper-block" class="${state.channel === 'PAPIER' ? '' : 'd-none'}"><fieldset class="form-section mb-0"><legend>Références de la fiche papier</legend><div class="row g-3">
      <div class="col-md-4"><label class="form-label" for="paper-ref">N° de la fiche${REQ}</label><input class="form-control" id="paper-ref" data-paper="form_ref" maxlength="50" value="${h(p.form_ref || '')}"></div>
      <div class="col-md-4"><label class="form-label" for="paper-date">Date de l'entretien${REQ}</label><input class="form-control" type="date" id="paper-date" data-paper="interview_date" value="${h(p.interview_date || '')}"></div>
      <div class="col-md-4"><label class="form-label" for="paper-enum">Enquêteur (nom sur la fiche)${REQ}</label><input class="form-control" id="paper-enum" data-paper="enumerator" maxlength="150" value="${h(p.enumerator || '')}"></div>
    </div></fieldset></div></div></div>`;
}
