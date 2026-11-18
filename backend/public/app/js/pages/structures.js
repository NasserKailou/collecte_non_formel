// Registre des structures : liste, création (doublons), fiche, historique, décisions sur les propositions.
import { get, post, patch } from '../api.js';
import {
  h, badge, fmtDate, modal, confirmBox, toast, toastError, formValues, options, getTerritory, bindGeoSelects,
  serverTable, pageHeader, showFormError, setFieldError, enhanceForm, REQ, STATUS_LABELS,
} from '../ui.js';
import { can } from '../session.js';

const ZONES = [{ id: 'URBAINE', name: 'Urbaine' }, { id: 'RURALE', name: 'Rurale' }];
const LEGAL = [{ id: 'PUBLIC', name: 'Public' }, { id: 'PRIVE', name: 'Privé' }];
let types = null;
const getTypes = async () => (types ??= (await get('/referentials/structure-types')).data.map((t) => ({ id: t.code, name: `${t.code}. ${t.label}`, is_other: t.is_other })));

export async function render(el) {
  const query = new URLSearchParams(window.location.hash.split('?')[1] || '');
  await getTypes();
  el.innerHTML = pageHeader('Structures', can('structure.manage') ? '<button class="btn btn-brand" data-action="create"><i class="bi bi-plus-lg me-1"></i>Nouvelle structure</button>' : '')
    + `<div class="card mb-3"><div class="card-body filters d-flex flex-wrap gap-2">
        <input class="form-control" id="f-q" placeholder="Nom ou code" aria-label="Recherche">
        <select class="form-select" id="f-status" aria-label="Statut">${options(['ACTIVE', 'PROPOSEE', 'FERMEE', 'REJETEE', 'FUSIONNEE'].map((s) => ({ id: s, name: STATUS_LABELS[s] })), query.get('status'), { empty: 'Tous les statuts' })}</select>
        <select class="form-select" id="f-type" aria-label="Type">${options(types, '', { empty: 'Tous les types' })}</select>
        <select class="form-select" data-geo="region" aria-label="Région"></select>
        <select class="form-select" data-geo="department" aria-label="Département"></select>
        <select class="form-select" data-geo="commune" aria-label="Commune"></select>
      </div></div>
      <div class="card"><div class="card-body"><table class="table table-sm table-hover w-100" id="st-table"><thead><tr>
        <th>Code</th><th>Nom</th><th>Type</th><th>Commune</th><th>Département</th><th>Statut</th><th></th></tr></thead></table></div></div>`;
  const filters = el.querySelector('.filters');
  let table = null;
  const reload = () => table?.ajax.reload();
  await bindGeoSelects(filters, reload);
  table = serverTable(el.querySelector('#st-table'), [
    { data: null, render: (s) => s.official_code ? `<code>${h(s.official_code)}</code>` : '<span class="text-muted small">non codifiée</span>' },
    { data: null, render: (s) => h(s.name) },
    { data: null, render: (s) => h(s.type_label) },
    { data: null, render: (s) => h(s.commune) },
    { data: null, render: (s) => h(s.department) },
    { data: null, render: (s) => badge(s.status) },
    { data: null, render: (s) => `<button class="btn btn-sm btn-outline-secondary" data-action="open" data-uuid="${h(s.uuid)}">Ouvrir</button>` },
  ], (p) => get('/structures', { ...p, q: el.querySelector('#f-q').value.trim(), status: el.querySelector('#f-status').value, type_code: el.querySelector('#f-type').value,
    region_id: filters.querySelector('[data-geo="region"]').value, department_id: filters.querySelector('[data-geo="department"]').value,
    commune_id: filters.querySelector('[data-geo="commune"]').value }));
  let timer;
  el.querySelector('#f-q').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(reload, 350); });
  ['#f-status', '#f-type'].forEach((s) => el.querySelector(s).addEventListener('change', reload));
  el.onclick = async (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    try {
      if (b.dataset.action === 'create') await structureForm(null, reload);
      if (b.dataset.action === 'open') await structureCard(b.dataset.uuid, reload);
    } catch (e) { toastError(e); }
  };
}

// ===================================================================== Formulaire (création / modification)

async function structureForm(s, onSaved) {
  const t = await getTerritory();
  const tps = await getTypes();
  const commune = s ? t.communes.find((c) => c.id === s.commune_id) : null;
  const dept = commune ? t.departments.find((d) => d.id === commune.department_id) : null;
  const codeRequired = s?.status !== 'PROPOSEE';
  const today = new Date().toISOString().slice(0, 10);
  const m = modal({
    title: s ? `Modifier « ${s.name} »` : 'Nouvelle structure',
    size: 'modal-lg',
    body: `<form autocomplete="off">
      <p class="small text-muted mb-3">Les champs marqués <span class="required-mark" aria-hidden="true">*</span> sont obligatoires. Les numéros (1.7, 1.8, 1.9) renvoient au questionnaire.</p>
      <div class="d-none" id="dup-box" aria-live="polite"></div>
      <fieldset class="form-section"><legend>1. Identification</legend>
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label" for="s-code">Code officiel${codeRequired ? REQ : ''}</label>
            <input class="form-control font-monospace" id="s-code" name="official_code" inputmode="numeric" maxlength="10" value="${h(s?.official_code)}"
              pattern="[0-9]{10}" ${codeRequired ? 'required' : ''} data-error="Le code officiel compte exactement 10 chiffres." aria-describedby="s-code-hint">
            <small class="form-hint" id="s-code-hint">${codeRequired ? '10 chiffres.' : 'Facultatif pour une structure proposée : attribué à l\'approbation.'}</small></div>
          <div class="col-md-8"><label class="form-label" for="s-name">Nom de la structure${REQ}</label><input class="form-control" id="s-name" name="name" value="${h(s?.name)}" maxlength="150" required></div>
          <div class="col-md-6"><label class="form-label" for="s-type">Type de structure (1.9)${REQ}</label><select class="form-select" id="s-type" name="type_code" required>${options(tps, s?.type_code, { empty: 'Choisir…' })}</select></div>
          <div class="col-md-6" id="s-other-wrap"><label class="form-label" for="s-other">Préciser le type${REQ}</label><input class="form-control" id="s-other" name="other_type_label" value="${h(s?.other_type_label)}" maxlength="150"></div>
          <div class="col-sm-6 col-md-3"><label class="form-label" for="s-legal">Statut (1.8)</label><select class="form-select" id="s-legal" name="legal_status">${options(LEGAL, s?.legal_status, { empty: 'Choisir…' })}</select></div>
          <div class="col-sm-6 col-md-3"><label class="form-label" for="s-zone">Zone (1.7)</label><select class="form-select" id="s-zone" name="zone">${options(ZONES, s?.zone, { empty: 'Choisir…' })}</select></div>
          <div class="col-sm-6 col-md-3"><label class="form-label" for="s-auth">Date d'autorisation</label><input class="form-control" type="date" id="s-auth" name="authorization_date" value="${h(s?.authorization_date)}" max="${today}"></div>
          <div class="col-sm-6 col-md-3"><label class="form-label" for="s-open">Date d'ouverture</label><input class="form-control" type="date" id="s-open" name="opening_date" value="${h(s?.opening_date)}" max="${today}"></div>
        </div></fieldset>
      <fieldset class="form-section"><legend>2. Localisation</legend>
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label" for="s-r">Région${REQ}</label><select class="form-select" id="s-r" data-geo="region" required></select></div>
          <div class="col-md-4"><label class="form-label" for="s-d">Département${REQ}</label><select class="form-select" id="s-d" data-geo="department" required></select></div>
          <div class="col-md-4"><label class="form-label" for="s-c">Commune${REQ}</label><select class="form-select" id="s-c" data-geo="commune" name="commune_id" required data-error="Choisir la commune (après la région et le département)."></select></div>
          <div class="col-12"><label class="form-label" for="s-loc">Village / quartier</label><input class="form-control" id="s-loc" name="locality_label" value="${h(s?.locality_label)}" maxlength="150"></div>
        </div></fieldset>
      <fieldset class="form-section" aria-describedby="s-gps-intro"><legend>3. Coordonnées GPS</legend>
        <p class="form-section-intro" id="s-gps-intro">Facultatif. Degrés décimaux (WGS 84), à relever sur place : latitude entre 11,3 et 23,7 ; longitude entre 0 et 16,2.</p>
        <div class="row g-3">
          <div class="col-sm-4"><label class="form-label" for="s-lat">Latitude</label><input class="form-control" type="number" step="0.000001" min="11.3" max="23.7" inputmode="decimal" id="s-lat" name="latitude" value="${h(s?.latitude)}" placeholder="13.512345" data-error="Latitude hors du Niger (11,3 à 23,7)."></div>
          <div class="col-sm-4"><label class="form-label" for="s-lng">Longitude</label><input class="form-control" type="number" step="0.000001" min="0" max="16.2" inputmode="decimal" id="s-lng" name="longitude" value="${h(s?.longitude)}" placeholder="2.112345" data-error="Longitude hors du Niger (0 à 16,2)."></div>
          <div class="col-sm-4"><label class="form-label" for="s-acc">Précision (m)</label><input class="form-control" type="number" step="0.1" min="0" inputmode="decimal" id="s-acc" name="gps_accuracy_m" value="${h(s?.gps_accuracy_m)}"></div>
        </div></fieldset>
      ${s ? `<fieldset class="form-section"><legend>4. Traçabilité</legend>
        <label class="form-label" for="s-reason">Motif de la modification</label><input class="form-control" id="s-reason" name="reason" maxlength="500" aria-describedby="s-reason-hint">
        <small class="form-hint" id="s-reason-hint">Conservé dans l'historique de la structure.</small></fieldset>` : ''}
    </form>`,
    buttons: [{
      label: 'Enregistrer',
      action: async (mm) => {
        if (!check()) return false;
        const v = formValues(mm.body.querySelector('form'));
        v.commune_id = v.commune_id ? Number(v.commune_id) : null;
        if (mm.confirmDuplicates) v.confirm_duplicates = true;
        try {
          if (s) await patch(`/structures/${s.uuid}`, v);
          else await post('/structures', v);
        } catch (e) {
          if (e.code === 'DUPLICATE_SUSPECTED') {
            const box = mm.body.querySelector('#dup-box');
            box.classList.remove('d-none');
            box.innerHTML = `<div class="alert alert-warning" tabindex="-1"><p class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Doublons probables</p><ul class="mb-2">${e.details.candidates.map((c) => `<li>
              ${h(c.name)} (${h(c.official_code || 'non codifiée')}, ${h(c.commune)}) — ${h(c.reasons.join(', '))}</li>`).join('')}</ul>
              S'il ne s'agit pas de la même structure, cliquez de nouveau sur « Enregistrer » pour confirmer la création.</div>`;
            box.firstElementChild.focus();
            mm.confirmDuplicates = true;
            return false;
          }
          throw e;
        }
        toast(s ? 'Structure modifiée.' : 'Structure créée.');
        onSaved();
        return true;
      },
    }],
  });
  const form = m.body.querySelector('form');
  // Divulgation progressive : la précision n'est demandée (et exigée) que pour le type « Autre ».
  const typeSel = form.querySelector('#s-type');
  const toggleOther = () => {
    const other = !!tps.find((x) => x.id === typeSel.value)?.is_other;
    form.querySelector('#s-other-wrap').classList.toggle('d-none', !other);
    form.querySelector('#s-other').required = other;
    if (!other) setFieldError(form.querySelector('#s-other'), null);
  };
  typeSel.addEventListener('change', toggleOther);
  toggleOther();
  await bindGeoSelects(form, () => {}, { form: true });
  const check = enhanceForm(form);
  if (dept) {
    const set = (geo, value) => {
      const sel = form.querySelector(`[data-geo="${geo}"]`);
      sel.value = String(value);
      sel.dispatchEvent(new Event('change'));
    };
    set('region', dept.region_id);
    set('department', dept.id);
    form.querySelector('[data-geo="commune"]').value = String(s.commune_id);
  }
}

// ===================================================================== Fiche

async function structureCard(uuid, onChanged) {
  const s = (await get(`/structures/${uuid}`)).data;
  const hist = (await get(`/structures/${uuid}/history`)).data;
  const manage = can('structure.manage');
  const editable = ['ACTIVE', 'PROPOSEE', 'FERMEE'].includes(s.status);
  const row = (label, value) => `<div class="q-row"><div class="q-label">${h(label)}</div><div>${value || '<span class="q-empty">—</span>'}</div></div>`;
  const m = modal({
    title: s.name,
    size: 'modal-xl',
    body: `<div class="d-flex flex-wrap gap-2 mb-3">${badge(s.status)}
        ${manage && editable ? '<button class="btn btn-sm btn-outline-secondary" data-do="edit"><i class="bi bi-pencil me-1"></i>Modifier</button>' : ''}
        ${manage && s.status === 'PROPOSEE' ? '<button class="btn btn-sm btn-green" data-do="approve">Approuver</button><button class="btn btn-sm btn-outline-danger" data-do="reject">Rejeter</button>' : ''}
        ${manage && editable ? '<button class="btn btn-sm btn-outline-warning" data-do="merge">Fusionner (doublon)</button>' : ''}
        ${manage && s.status === 'ACTIVE' ? '<button class="btn btn-sm btn-outline-secondary" data-do="close">Fermer</button>' : ''}
        ${manage && s.status === 'FERMEE' ? '<button class="btn btn-sm btn-outline-success" data-do="reopen">Rouvrir</button>' : ''}
      </div>
      <div class="row"><div class="col-lg-6">
        ${row('Code officiel', s.official_code ? `<code>${h(s.official_code)}</code>` : '')}
        ${row('Type', h(s.type_label) + (s.other_type_label ? ` — ${h(s.other_type_label)}` : ''))}
        ${row('Statut juridique', h(LEGAL.find((x) => x.id === s.legal_status)?.name))}
        ${row('Zone', h(ZONES.find((x) => x.id === s.zone)?.name))}
        ${row('Localisation', `${h(s.region)} › ${h(s.department)} › ${h(s.commune)}${s.locality_label ? ` › ${h(s.locality_label)}` : ''}`)}
        ${row('GPS', s.latitude !== null ? `${h(s.latitude)}, ${h(s.longitude)}${s.gps_accuracy_m ? ` (± ${h(s.gps_accuracy_m)} m)` : ''}` : '')}
        ${row("Date d'autorisation", fmtDate(s.authorization_date))}
        ${row("Date d'ouverture", fmtDate(s.opening_date))}
        ${s.merged_into_uuid ? row('Fusionnée dans', `<code>${h(s.merged_into_uuid)}</code>`) : ''}
      </div><div class="col-lg-6"><h3 class="h6">Historique</h3><ul class="list-group list-group-flush small">
        ${hist.map((x) => `<li class="list-group-item px-0"><strong>${h(x.action)}</strong> — ${fmtDate(x.created_at, true)} — ${h(x.user_name || '')}
          ${x.reason ? `<div class="text-muted">${h(x.reason)}</div>` : ''}
          ${x.changes && x.action === 'MODIFICATION' ? `<div>${Object.entries(x.changes).map(([k, c]) => `${h(k)} : ${h(c.avant ?? '—')} → ${h(c.apres ?? '—')}`).join('<br>')}</div>` : ''}</li>`).join('')}
      </ul></div></div>`,
  });
  m.body.addEventListener('click', async (ev) => {
    const b = ev.target.closest('[data-do]');
    if (!b) return;
    const act = b.dataset.do;
    const done = () => { m.close(); onChanged(); };
    try {
      if (act === 'edit') { m.close(); await structureForm(s, onChanged); return; }
      if (act === 'approve') { approveForm(s, done); return; }
      if (act === 'merge') { mergeForm(s, done); return; }
      const labels = { reject: ['Rejeter la proposition', 'Rejeter'], close: ['Fermer la structure', 'Fermer'], reopen: ['Rouvrir la structure', 'Rouvrir'] };
      const reason = await confirmBox({ title: labels[act][0], message: s.name, reason: true, confirmLabel: labels[act][1], danger: act !== 'reopen' });
      if (reason === null) return;
      await post(`/structures/${s.uuid}/${act}`, { reason });
      toast('Décision enregistrée.');
      done();
    } catch (e) { toastError(e); }
  });
}

function approveForm(s, done) {
  const m = modal({
    title: 'Approuver la structure proposée',
    body: `<form autocomplete="off"><p><strong>${h(s.name)}</strong> — ${h(s.commune)}</p>
      <div class="mb-3"><label class="form-label" for="ap-code">Code officiel attribué${REQ}</label>
        <input class="form-control font-monospace" id="ap-code" name="official_code" inputmode="numeric" maxlength="10" required pattern="[0-9]{10}"
          data-error="Le code officiel compte exactement 10 chiffres." aria-describedby="ap-code-hint">
        <small class="form-hint" id="ap-code-hint">10 chiffres ; la structure devient active et visible des agents.</small></div>
      <label class="form-label" for="ap-comment">Commentaire</label><input class="form-control" id="ap-comment" name="comment" maxlength="500"></form>`,
    buttons: [{ label: 'Approuver', class: 'btn-green', action: async (mm) => {
      if (!check()) return false;
      await post(`/structures/${s.uuid}/approve`, formValues(mm.body.querySelector('form')));
      toast('Structure approuvée.');
      done();
      return true;
    } }],
  });
  const check = enhanceForm(m.body.querySelector('form'));
}

function mergeForm(s, done) {
  const m = modal({
    title: 'Fusionner un doublon',
    body: `<form autocomplete="off"><div class="alert alert-warning small"><strong>${h(s.name)}</strong> sera marquée comme doublon de la structure de référence choisie ci-dessous. Ses collectes restent consultables.</div>
      <label class="form-label" for="mg-q">Rechercher (nom ou code), dans la même commune</label>
      <input class="form-control mb-2" id="mg-q" type="search" aria-controls="mg-target">
      <label class="form-label" for="mg-target">Structure de référence${REQ}</label>
      <select class="form-select mb-3" id="mg-target" name="target_uuid" size="6" required data-error="Choisir la structure de référence dans la liste."></select>
      <label class="form-label" for="mg-reason">Motif${REQ}</label><input class="form-control" id="mg-reason" name="reason" required minlength="3" maxlength="500"
        data-error="Motif obligatoire (3 caractères au moins)."></form>`,
    buttons: [{ label: 'Fusionner', class: 'btn-danger', action: async (mm) => {
      if (!check()) return false;
      const v = formValues(mm.body.querySelector('form'));
      await post(`/structures/${s.uuid}/merge`, v);
      toast('Fusion enregistrée.');
      done();
      return true;
    } }],
  });
  const check = enhanceForm(m.body.querySelector('form'));
  let timer;
  const search = m.body.querySelector('#mg-q');
  search.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      const r = await get('/structures', { q: search.value.trim(), status: 'ACTIVE', commune_id: s.commune_id, per_page: 50 }).catch(() => ({ data: [] }));
      m.body.querySelector('[name="target_uuid"]').innerHTML = options(r.data.filter((x) => x.uuid !== s.uuid)
        .map((x) => ({ id: x.uuid, name: `${x.name} (${x.official_code || 'non codifiée'})` })), '');
    }, 300);
  });
  search.dispatchEvent(new Event('input'));
}
