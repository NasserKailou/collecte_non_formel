// Années statistiques, campagnes (états, ciblage, reconduction) et affectations des structures aux agents.
import { get, post, patch, put, del } from '../api.js';
import {
  h, badge, fmtDate, modal, confirmBox, toast, toastError, formValues, options, getTerritory, bindGeoSelects,
  serverTable, pageHeader, showFormError, enhanceForm, REQ, STATUS_LABELS,
} from '../ui.js';

const REASON_REQUIRED = ['SUSPENDUE', 'CLOTUREE', 'ARCHIVEE'];
const TRANSITION_LABELS = { OUVERTE: 'Ouvrir', SUSPENDUE: 'Suspendre', CLOTUREE: 'Clôturer', ARCHIVEE: 'Archiver' };

// ===================================================================== Campagnes

export async function render(el) {
  el.innerHTML = pageHeader('Années statistiques et campagnes',
    '<button class="btn btn-outline-success" data-action="new-year"><i class="bi bi-plus-lg me-1"></i>Nouvelle année</button>'
    + '<button class="btn btn-brand" data-action="new-campaign"><i class="bi bi-plus-lg me-1"></i>Nouvelle campagne</button>')
    + '<div class="card mb-3"><div class="card-body"><h2 class="h6">Années statistiques</h2><div id="years"></div></div></div>'
    + '<div class="card"><div class="card-body"><h2 class="h6">Campagnes</h2><div id="campaigns"></div></div></div>';
  const load = async () => {
    const years = (await get('/academic-years')).data;
    const camps = (await get('/campaigns')).data;
    el.querySelector('#years').innerHTML = years.length === 0 ? '<p class="text-muted">Aucune année.</p>' : `<table class="table table-sm"><thead><tr>
      <th>Code</th><th>Libellé</th><th>Du</th><th>Au</th><th>Campagnes</th><th>Statut</th><th></th></tr></thead><tbody>${years.map((y) => `<tr>
      <td>${h(y.code)}</td><td>${h(y.label)}</td><td>${fmtDate(y.start_date)}</td><td>${fmtDate(y.end_date)}</td><td>${h(y.campaigns)}</td>
      <td>${y.status === 'ACTIVE' ? '<span class="badge badge-status st-ACTIVE">Active</span>' : badge(y.status)}</td>
      <td>${y.status === 'PREPARATION' ? `<button class="btn btn-sm btn-outline-success" data-action="year-status" data-id="${h(y.id)}" data-to="ACTIVE">Activer</button>` : ''}
          ${y.status === 'ACTIVE' ? `<button class="btn btn-sm btn-outline-secondary" data-action="year-status" data-id="${h(y.id)}" data-to="CLOTUREE">Clôturer</button>` : ''}</td></tr>`).join('')}</tbody></table>`;
    el.querySelector('#campaigns').innerHTML = camps.length === 0 ? '<p class="text-muted">Aucune campagne.</p>' : `<div class="table-responsive"><table class="table table-sm table-hover"><thead><tr>
      <th>Code</th><th>Intitulé</th><th>Année</th><th>Période</th><th>Questionnaire</th><th>Niveaux</th><th>Ciblées</th><th>Affectées</th><th>Statut</th><th></th></tr></thead><tbody>
      ${camps.map((c) => `<tr><td><code>${h(c.code)}</code></td><td>${h(c.name)}</td><td>${h(c.academic_year)}</td><td>${fmtDate(c.opens_on)} → ${fmtDate(c.closes_on)}</td>
        <td>${h(c.questionnaire_version)}</td><td>${h(c.validation_levels)}</td><td>${h(c.targeted)}</td><td>${h(c.assigned)}</td><td>${badge(c.status)}</td>
        <td><button class="btn btn-sm btn-outline-secondary" data-action="open" data-id="${h(c.id)}">Gérer</button></td></tr>`).join('')}</tbody></table></div>`;
    return { years, camps };
  };
  let data = await load();
  const refresh = async () => { data = await load(); };
  el.onclick = async (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    try {
      if (b.dataset.action === 'new-year') yearForm(refresh);
      if (b.dataset.action === 'year-status') {
        const label = b.dataset.to === 'ACTIVE' ? 'Active' : 'Clôturée';
        if (await confirmBox({ title: "Statut de l'année", message: `Passer l'année au statut « ${label} » ?` }) === null) return;
        await patch(`/academic-years/${b.dataset.id}`, { status: b.dataset.to });
        await refresh();
      }
      if (b.dataset.action === 'new-campaign') campaignForm(data.years, refresh);
      if (b.dataset.action === 'open') await campaignCard(Number(b.dataset.id), data.camps, refresh);
    } catch (e) { toastError(e); }
  };
}

function yearForm(done) {
  const y = new Date().getFullYear();
  const m = modal({
    title: 'Nouvelle année statistique',
    body: `<form autocomplete="off"><p class="small text-muted mb-3">Les champs marqués <span class="required-mark" aria-hidden="true">*</span> sont obligatoires.</p>
      <div class="row g-3"><div class="col-md-4"><label class="form-label" for="y-code">Code${REQ}</label>
        <input class="form-control" id="y-code" name="code" value="${y}-${y + 1}" required maxlength="20" aria-describedby="y-code-hint">
        <small class="form-hint" id="y-code-hint">Par exemple ${y}-${y + 1}.</small></div>
      <div class="col-md-8"><label class="form-label" for="y-label">Libellé</label><input class="form-control" id="y-label" name="label" maxlength="100" placeholder="Année statistique ${y}-${y + 1}"></div>
      <div class="col-sm-6"><label class="form-label" for="y-s">Début${REQ}</label><input class="form-control" type="date" id="y-s" name="start_date" value="${y}-10-01" required></div>
      <div class="col-sm-6"><label class="form-label" for="y-e">Fin${REQ}</label><input class="form-control" type="date" id="y-e" name="end_date" value="${y + 1}-09-30" required></div></div></form>`,
    buttons: [{ label: 'Créer', action: async (mm) => {
      if (!check() || !checkDates(mm.body, '#y-s', '#y-e', 'La fin doit être postérieure au début.')) return false;
      await post('/academic-years', formValues(mm.body.querySelector('form')));
      toast('Année créée.');
      await done();
      return true;
    } }],
  });
  const check = enhanceForm(m.body.querySelector('form'));
}

/** Contrôle « date de fin après date de début » (message sous le champ de fin et résumé). */
function checkDates(body, startSel, endSel, message) {
  const s = body.querySelector(startSel);
  const e = body.querySelector(endSel);
  if (!s || !e || !s.value || !e.value || e.value >= s.value) return true;
  showFormError(body, { message: 'Certaines informations sont à corriger.', details: { errors: [{ field: e.name, message }] } });
  return false;
}

function campaignForm(years, done, c = null) {
  const versions = ['2025.1'];
  const frozen = c && c.status !== 'PREPARATION';
  const md = modal({
    title: c ? `Modifier ${c.code}` : 'Nouvelle campagne',
    size: 'modal-lg',
    body: `<form autocomplete="off"><p class="small text-muted mb-3">Les champs marqués <span class="required-mark" aria-hidden="true">*</span> sont obligatoires.</p>
      <fieldset class="form-section"><legend>1. Identification</legend><div class="row g-3">
      ${c ? '' : `<div class="col-md-4"><label class="form-label" for="c-code">Code${REQ}</label><input class="form-control" id="c-code" name="code" placeholder="EFTPNF-2026-2027" required maxlength="40"
          aria-describedby="c-code-hint"><small class="form-hint" id="c-code-hint">Non modifiable ensuite.</small></div>
      <div class="col-md-4"><label class="form-label" for="c-year">Année statistique${REQ}</label><select class="form-select" id="c-year" name="academic_year_id" required>${options(years.filter((y) => y.status !== 'CLOTUREE').map((y) => ({ id: y.id, name: y.code })), '', { empty: 'Choisir…' })}</select></div>`}
      <div class="col-md-${c ? 12 : 4}"><label class="form-label" for="c-name">Intitulé${REQ}</label><input class="form-control" id="c-name" name="name" value="${h(c?.name)}" required maxlength="150"></div>
      </div></fieldset>
      <fieldset class="form-section"><legend>2. Calendrier</legend><div class="row g-3">
      <div class="col-sm-6"><label class="form-label" for="c-o">Ouverture prévue${REQ}</label><input class="form-control" type="date" id="c-o" name="opens_on" value="${h(c?.opens_on)}" required></div>
      <div class="col-sm-6"><label class="form-label" for="c-c">Clôture prévue${REQ}</label><input class="form-control" type="date" id="c-c" name="closes_on" value="${h(c?.closes_on)}" required></div>
      </div></fieldset>
      <fieldset class="form-section"><legend>3. Questionnaire et contrôle</legend>
      ${frozen ? '<p class="small text-muted mb-2"><i class="bi bi-lock me-1" aria-hidden="true"></i>Questionnaire et niveaux de validation figés depuis l\'ouverture de la campagne.</p>' : `<div class="row g-3"><div class="col-md-4"><label class="form-label" for="c-v">Version du questionnaire${REQ}</label><select class="form-select" id="c-v" name="questionnaire_version" required>${options(versions.map((v) => ({ id: v, name: v })), c?.questionnaire_version || '2025.1')}</select></div>
      <div class="col-md-8"><label class="form-label" for="c-l">Circuit de validation${REQ}</label><select class="form-select" id="c-l" name="validation_levels" required aria-describedby="c-l-hint">${options([{ id: 2, name: 'Départementale puis régionale' }, { id: 1, name: 'Départementale seule' }], c?.validation_levels ?? 2)}</select>
        <small class="form-hint" id="c-l-hint">Figé à l'ouverture de la campagne.</small></div></div>`}
      </fieldset>
      <label class="form-label" for="c-i">Consignes aux agents</label><textarea class="form-control" id="c-i" name="instructions" rows="3" maxlength="2000" aria-describedby="c-i-hint">${h(c?.instructions)}</textarea>
      <small class="form-hint" id="c-i-hint">Transmises aux tablettes des agents lors de la synchronisation.</small>
    </form>`,
    buttons: [{ label: 'Enregistrer', action: async (m) => {
      if (!check() || !checkDates(m.body, '#c-o', '#c-c', 'La clôture doit être postérieure à l\'ouverture.')) return false;
      const v = formValues(m.body.querySelector('form'));
      if (v.validation_levels !== undefined && v.validation_levels !== null) v.validation_levels = Number(v.validation_levels);
      if (v.academic_year_id) v.academic_year_id = Number(v.academic_year_id);
      if (c) await patch(`/campaigns/${c.id}`, v);
      else await post('/campaigns', v);
      toast('Campagne enregistrée.');
      await done();
      return true;
    } }],
  });
  const check = enhanceForm(md.body.querySelector('form'));
}

async function campaignCard(id, allCampaigns, onChanged) {
  const c = (await get(`/campaigns/${id}`)).data;
  const editable = !['CLOTUREE', 'ARCHIVEE'].includes(c.status);
  const m = modal({
    title: `${c.name} (${c.code})`,
    size: 'modal-xl',
    body: `<div class="d-flex flex-wrap gap-2 align-items-center mb-3">${badge(c.status)}
        ${c.allowed_transitions.map((t) => `<button class="btn btn-sm ${t === 'OUVERTE' ? 'btn-green' : 'btn-outline-secondary'}" data-t="${h(t)}">${h(TRANSITION_LABELS[t])}</button>`).join('')}
        ${editable ? '<button class="btn btn-sm btn-outline-secondary" data-do="edit"><i class="bi bi-pencil me-1"></i>Modifier</button>' : ''}
        <a class="btn btn-sm btn-outline-success" href="#/affectations?campaign_id=${h(c.id)}" data-bs-dismiss="modal">Affectations</a></div>
      <div class="row g-3">
        <div class="col-lg-5"><ul class="list-group small">
          <li class="list-group-item">Année : ${h(c.academic_year)} — questionnaire ${h(c.questionnaire_version)}</li>
          <li class="list-group-item">Période : ${fmtDate(c.opens_on)} → ${fmtDate(c.closes_on)}</li>
          <li class="list-group-item">Validation : ${c.validation_levels === 2 ? 'départementale puis régionale' : 'départementale seule'}</li>
          <li class="list-group-item">Structures ciblées : <strong>${h(c.targeted)}</strong> — affectées : <strong>${h(c.assigned)}</strong> — non affectées : <strong>${h(c.unassigned)}</strong></li>
          ${c.instructions ? `<li class="list-group-item">Consignes : ${h(c.instructions)}</li>` : ''}
        </ul>
        <h3 class="h6 mt-3">Historique des états</h3><ul class="small">${c.status_history.map((x) => `<li>${fmtDate(x.created_at, true)} : ${h(STATUS_LABELS[x.to_status] || x.to_status)}${x.reason ? ` — ${h(x.reason)}` : ''} (${h(x.user_matricule || '')})</li>`).join('')}</ul></div>
        <div class="col-lg-7">${editable ? `<div class="border rounded p-3 mb-3"><h3 class="h6">Cibler les structures actives</h3>
          <div class="row g-2 targeting"><div class="col-md-4"><select class="form-select form-select-sm" data-geo="region" aria-label="Région"></select></div>
          <div class="col-md-4"><select class="form-select form-select-sm" data-geo="department" aria-label="Département"></select></div>
          <div class="col-md-4"><select class="form-select form-select-sm" data-geo="commune" aria-label="Commune"></select></div></div>
          <p class="small text-muted mt-2 mb-2">Sans sélection : toutes les structures actives de votre périmètre. Les structures déjà ciblées ne sont pas dupliquées.</p>
          <button class="btn btn-sm btn-brand" data-do="target">Cibler</button></div>` : ''}
          ${c.status === 'PREPARATION' ? `<div class="border rounded p-3"><h3 class="h6">Reconduire depuis une campagne précédente</h3>
          <select class="form-select form-select-sm mb-2" id="clone-src" aria-label="Campagne source">${options(allCampaigns.filter((x) => x.id !== c.id).map((x) => ({ id: x.id, name: `${x.code} — ${x.name}` })), '', { empty: '—' })}</select>
          <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="clone-asg"><label class="form-check-label" for="clone-asg">Reprendre aussi les affectations encore valables</label></div>
          <button class="btn btn-sm btn-outline-success" data-do="clone">Reconduire</button></div>` : ''}</div>
      </div>`,
  });
  if (editable) await bindGeoSelects(m.body.querySelector('.targeting'));
  m.body.addEventListener('click', async (ev) => {
    const t = ev.target.closest('[data-t]');
    const d = ev.target.closest('[data-do]');
    try {
      if (t) {
        const to = t.dataset.t;
        const reason = await confirmBox({ title: `${TRANSITION_LABELS[to]} la campagne`,
          message: to === 'CLOTUREE' ? 'Plus aucune soumission ne sera acceptée ; la campagne passera en lecture seule.' : `Passer la campagne au statut « ${STATUS_LABELS[to]} » ?`,
          reason: REASON_REQUIRED.includes(to), danger: to === 'CLOTUREE' || to === 'ARCHIVEE', confirmLabel: TRANSITION_LABELS[to] });
        if (reason === null) return;
        await post(`/campaigns/${c.id}/transition`, { to, reason: typeof reason === 'string' ? reason : undefined });
        toast('Statut de la campagne modifié.');
        m.close();
        await onChanged();
      }
      if (d?.dataset.do === 'edit') { m.close(); campaignForm([], onChanged, c); }
      if (d?.dataset.do === 'target') {
        const g = (k) => m.body.querySelector(`.targeting [data-geo="${k}"]`).value;
        const filter = g('commune') ? { commune_id: Number(g('commune')) } : g('department') ? { department_id: Number(g('department')) } : g('region') ? { region_id: Number(g('region')) } : { all: true };
        const r = (await post(`/campaigns/${c.id}/structures`, { filter })).data;
        toast(`${r.added} structure(s) ajoutée(s), ${r.already_targeted} déjà ciblée(s).`);
        m.close();
        await onChanged();
      }
      if (d?.dataset.do === 'clone') {
        const src = m.body.querySelector('#clone-src').value;
        if (!src) { showFormError(m.body, { message: 'Choisir la campagne source.' }); return; }
        const r = (await post(`/campaigns/${c.id}/clone-from/${src}`, { copy_assignments: m.body.querySelector('#clone-asg').checked })).data;
        toast(`${r.structures_copied} structure(s) et ${r.assignments_copied} affectation(s) reprises ; ${r.structures_skipped} structure(s) écartée(s) (non actives).`);
        m.close();
        await onChanged();
      }
    } catch (e) { toastError(e); }
  });
}

// ===================================================================== Affectations

export async function assignments(el) {
  const query = new URLSearchParams(window.location.hash.split('?')[1] || '');
  const camps = (await get('/campaigns')).data.filter((c) => !['CLOTUREE', 'ARCHIVEE'].includes(c.status));
  if (camps.length === 0) {
    el.innerHTML = pageHeader('Affectations') + '<div class="alert alert-secondary">Aucune campagne en préparation ou en cours.</div>';
    return;
  }
  const agents = (await get('/users', { role: 'AGENT', status: 'ACTIF', per_page: 200 })).data;
  el.innerHTML = pageHeader('Affectations des structures aux agents', "<button class=\"btn btn-outline-success\" data-action=\"areas\"><i class=\"bi bi-geo me-1\"></i>Zones de travail d'un agent</button>")
    + `<div class="card mb-3"><div class="card-body filters d-flex flex-wrap gap-2 align-items-center">
        <select class="form-select" id="f-camp" aria-label="Campagne">${options(camps.map((c) => ({ id: c.id, name: `${c.code} — ${c.name}` })), query.get('campaign_id') || camps[0].id)}</select>
        <select class="form-select" data-geo="region" aria-label="Région"></select>
        <select class="form-select" data-geo="department" aria-label="Département"></select>
        <select class="form-select" data-geo="commune" aria-label="Commune"></select>
        <input class="form-control" id="f-q" placeholder="Nom ou code" aria-label="Recherche">
        <div class="form-check"><input class="form-check-input" type="checkbox" id="f-un" checked><label class="form-check-label" for="f-un">Non affectées seulement</label></div>
      </div></div>
      <div class="card mb-3"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
        <span><strong id="sel-count">0</strong> structure(s) sélectionnée(s)</span>
        <select class="form-select w-auto" id="agent" aria-label="Agent">${options(agents.map((a) => ({ id: a.uuid, name: `${a.full_name} (${a.matricule}) — ${a.scopes.map((s) => s.label).join(', ')}` })), '', { empty: 'Choisir un agent…' })}</select>
        <button class="btn btn-brand" id="assign" disabled>Affecter la sélection</button>
      </div></div>
      <div class="card"><div class="card-body"><table class="table table-sm table-hover w-100" id="asg-table"><thead><tr>
        <th><input class="form-check-input" type="checkbox" id="sel-all" aria-label="Tout sélectionner sur la page"></th><th>Code</th><th>Structure</th><th>Commune</th><th>Agent</th><th>Collecte</th><th></th>
      </tr></thead></table></div></div>`;
  const selected = new Set();
  const filters = el.querySelector('.filters');
  let table = null;
  const refreshSel = () => {
    el.querySelector('#sel-count').textContent = String(selected.size);
    el.querySelector('#assign').disabled = selected.size === 0 || !el.querySelector('#agent').value;
  };
  const reload = () => { selected.clear(); refreshSel(); table?.ajax.reload(); };
  await bindGeoSelects(filters, reload);
  table = serverTable(el.querySelector('#asg-table'), [
    { data: null, render: (r) => `<input class="form-check-input" type="checkbox" data-cs="${h(r.campaign_structure_id)}" aria-label="Sélectionner ${h(r.structure.name)}"${selected.has(r.campaign_structure_id) ? ' checked' : ''}>` },
    { data: null, render: (r) => `<code>${h(r.structure.official_code)}</code>` },
    { data: null, render: (r) => h(r.structure.name) },
    { data: null, render: (r) => `${h(r.structure.commune)}<div class="small text-muted">${h(r.structure.department)}</div>` },
    { data: null, render: (r) => r.assignment ? `${h(r.assignment.agent.name)} <span class="small text-muted">(${h(r.assignment.agent.matricule)})</span>` : '<span class="text-danger small">non affectée</span>' },
    { data: null, render: (r) => r.collection_status ? badge(r.collection_status) : '' },
    { data: null, render: (r) => r.assignment ? `<button class="btn btn-sm btn-outline-danger" data-end="${h(r.assignment.id)}">Retirer</button>` : '' },
  ], (p) => get(`/campaigns/${el.querySelector('#f-camp').value}/assignments`, { ...p, unassigned: el.querySelector('#f-un').checked ? 1 : '',
    q: el.querySelector('#f-q').value.trim(), department_id: filters.querySelector('[data-geo="department"]').value,
    commune_id: filters.querySelector('[data-geo="commune"]').value }), { pageLength: 100 });
  let timer;
  el.querySelector('#f-q').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(reload, 350); });
  ['#f-camp', '#f-un'].forEach((s) => el.querySelector(s).addEventListener('change', reload));
  el.querySelector('#agent').addEventListener('change', refreshSel);
  el.onchange = (ev) => {
    if (ev.target.dataset.cs) {
      const id = Number(ev.target.dataset.cs);
      if (ev.target.checked) selected.add(id); else selected.delete(id);
      refreshSel();
    }
    if (ev.target.id === 'sel-all') {
      el.querySelectorAll('[data-cs]').forEach((cb) => {
        cb.checked = ev.target.checked;
        const id = Number(cb.dataset.cs);
        if (cb.checked) selected.add(id); else selected.delete(id);
      });
      refreshSel();
    }
  };
  el.onclick = async (ev) => {
    try {
      if (ev.target.closest('#assign')) {
        const r = (await post(`/campaigns/${el.querySelector('#f-camp').value}/assignments`, { agent_uuid: el.querySelector('#agent').value, campaign_structure_ids: [...selected] })).data;
        toast(`${r.assigned} affectée(s), ${r.reassigned} réaffectée(s), ${r.unchanged} inchangée(s).`);
        reload();
      }
      const end = ev.target.closest('[data-end]');
      if (end) {
        const reason = await confirmBox({ title: "Retirer l'affectation", message: "La structure redeviendra non affectée ; l'historique est conservé.", reason: true, confirmLabel: 'Retirer', danger: true });
        if (reason === null) return;
        await del(`/assignments/${end.dataset.end}`, { reason });
        toast('Affectation retirée.');
        reload();
      }
      if (ev.target.closest('[data-action="areas"]')) await areasModal(el.querySelector('#f-camp').value, agents);
    } catch (e) { toastError(e); }
  };
}

async function areasModal(campaignId, agents) {
  const t = await getTerritory();
  const m = modal({
    title: "Zones de travail d'un agent",
    size: 'modal-lg',
    body: `<form autocomplete="off"><p class="form-section-intro">Les zones de travail étendent, pour cette campagne, les communes où l'agent peut recevoir des structures et en proposer de nouvelles.</p>
      <label class="form-label" for="ar-agent">Agent${REQ}</label>
      <select class="form-select mb-3" id="ar-agent" name="agent" required data-error="Choisir un agent.">${options(agents.map((a) => ({ id: a.uuid, name: `${a.full_name} (${a.matricule})` })), '', { empty: 'Choisir…' })}</select>
      <label class="form-label" for="ar-communes">Communes</label>
      <select class="form-select" id="ar-communes" multiple size="12" aria-describedby="ar-hint ar-count">${t.regions.map((r) => `<optgroup label="${h(r.name)}">${t.departments.filter((d) => d.region_id === r.id)
        .flatMap((d) => t.communes.filter((c) => c.department_id === d.id).map((c) => `<option value="${h(c.id)}">${h(d.name)} — ${h(c.name)}</option>`)).join('')}</optgroup>`).join('')}</select>
      <small class="form-hint" id="ar-hint">Ctrl + clic (ou Cmd + clic) pour en choisir plusieurs ; la sélection remplace les zones de votre périmètre (vide : elles sont retirées).</small>
      <p class="small fw-semibold mt-1 mb-0" id="ar-count" aria-live="polite">0 commune sélectionnée</p></form>`,
    buttons: [{ label: 'Enregistrer', action: async (mm) => {
      if (!check()) return false;
      const agent = mm.body.querySelector('#ar-agent').value;
      const ids = [...mm.body.querySelector('#ar-communes').selectedOptions].map((o) => Number(o.value));
      const r = (await put(`/campaigns/${campaignId}/agents/${agent}/areas`, { commune_ids: ids })).data;
      toast(`${r.length} commune(s) dans la zone de travail.`);
      return true;
    } }],
  });
  const check = enhanceForm(m.body.querySelector('form'));
  const list = m.body.querySelector('#ar-communes');
  list.addEventListener('change', () => {
    const n = list.selectedOptions.length;
    m.body.querySelector('#ar-count').textContent = `${n} commune${n > 1 ? 's' : ''} sélectionnée${n > 1 ? 's' : ''}`;
  });
}
