// Paramètres de la plateforme et journaux (audit, connexions, synchronisations).
import { get, patch } from '../api.js';
import { h, fmtDate, toast, toastError, serverTable, pageHeader } from '../ui.js';

// ===================================================================== Paramètres

export async function settings(el) {
  const list = (await get('/settings')).data;
  el.innerHTML = pageHeader('Paramètres de la plateforme')
    + `<div class="card"><div class="card-body"><form id="settings-form">
      <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Paramètre</th><th>Description</th><th class="w-25">Valeur</th><th>Modifié le</th></tr></thead><tbody>
      ${list.map((s) => `<tr><td><code>${h(s.key)}</code></td><td class="small">${h(s.description)}</td><td>${input(s)}</td><td class="small">${fmtDate(s.updated_at, true)}</td></tr>`).join('')}
      </tbody></table></div><button class="btn btn-brand" type="submit">Enregistrer les modifications</button></form></div></div>`;
  el.querySelector('#settings-form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const changes = {};
    list.forEach((s) => {
      const field = el.querySelector(`[data-key="${CSS.escape(s.key)}"]`);
      let v = field.type === 'checkbox' ? field.checked : field.value;
      if (s.type === 'int') v = parseInt(v, 10);
      if (s.type === 'float') v = parseFloat(v);
      const before = s.type === 'bool' ? s.value === '1' : s.type === 'int' ? parseInt(s.value, 10) : s.type === 'float' ? parseFloat(s.value) : s.value;
      if (v !== before) changes[s.key] = v;
    });
    if (Object.keys(changes).length === 0) { toast('Aucune modification.', 'secondary'); return; }
    try {
      await patch('/settings', changes);
      toast('Paramètres enregistrés.');
      await settings(el);
    } catch (e) { toastError(e); }
  });
}

function input(s) {
  const id = `set-${s.key}`;
  if (s.type === 'bool') return `<input class="form-check-input" type="checkbox" id="${h(id)}" data-key="${h(s.key)}"${s.value === '1' ? ' checked' : ''} aria-label="${h(s.key)}">`;
  if (s.key === 'stats_basis') {
    return `<select class="form-select" id="${h(id)}" data-key="${h(s.key)}" aria-label="${h(s.key)}">
      <option value="validated"${s.value === 'validated' ? ' selected' : ''}>Collectes validées</option>
      <option value="submitted"${s.value === 'submitted' ? ' selected' : ''}>Soumises et validées</option></select>`;
  }
  const type = s.type === 'int' || s.type === 'float' ? 'number' : 'text';
  return `<input class="form-control" type="${type}" ${s.type === 'float' ? 'step="0.1"' : ''} min="0" id="${h(id)}" data-key="${h(s.key)}" value="${h(s.value)}" aria-label="${h(s.key)}">`;
}

// ===================================================================== Journaux

const LOGS = {
  audit: {
    path: '/audit-logs', title: "Actions (journal d'audit)", filters: [['action', 'Action (ex. USER_CREATE)'], ['entity_type', 'Objet (ex. user)']],
    head: ['Date', 'Utilisateur', 'Action', 'Objet', 'Motif', 'Adresse IP'],
    columns: [
      { data: null, render: (r) => fmtDate(r.created_at, true) }, { data: null, render: (r) => h(r.matricule || '—') },
      { data: null, render: (r) => `<code>${h(r.action)}</code>` }, { data: null, render: (r) => `${h(r.entity_type)} ${h(r.entity_id || '')}` },
      { data: null, render: (r) => h(r.reason || '') }, { data: null, render: (r) => h(r.ip || '') }],
  },
  login: {
    path: '/login-logs', title: 'Connexions', filters: [['identifier', 'Identifiant saisi'], ['channel', 'WEB ou MOBILE']],
    head: ['Date', 'Identifiant', 'Canal', 'Résultat', 'Adresse IP'],
    columns: [
      { data: null, render: (r) => fmtDate(r.created_at, true) }, { data: null, render: (r) => h(r.identifier) },
      { data: null, render: (r) => h(r.channel) },
      { data: null, render: (r) => (Number(r.success) ? '<span class="text-success">réussie</span>' : `<span class="text-danger">échec</span> ${h(r.reason || '')}`) },
      { data: null, render: (r) => h(r.ip) }],
  },
  sync: {
    path: '/sync-logs', title: 'Synchronisations des tablettes', filters: [['outcome', 'Résultat (ACCEPTE, REJOUE, CONFLIT, REFUSE…)'], ['collection_uuid', 'Collecte (UUID)']],
    head: ['Date', 'Agent', 'Sens', 'Résultat', 'Détail', 'Durée'],
    columns: [
      { data: null, render: (r) => fmtDate(r.created_at, true) }, { data: null, render: (r) => h(r.matricule) }, { data: null, render: (r) => h(r.direction) },
      { data: null, render: (r) => `${h(r.outcome)} <span class="text-muted small">(${h(r.http_status)})</span>` },
      { data: null, render: (r) => `<span class="small">${h(r.detail || '')}</span>` }, { data: null, render: (r) => `${h(r.duration_ms ?? '')} ms` }],
  },
};

export async function logs(el) {
  el.innerHTML = pageHeader('Journaux')
    + `<ul class="nav nav-tabs mb-3" role="tablist">${Object.entries(LOGS).map(([k, l], i) => `<li class="nav-item" role="presentation">
        <button class="nav-link${i === 0 ? ' active' : ''}" data-log="${k}" type="button" role="tab" aria-selected="${i === 0}">${h(l.title)}</button></li>`).join('')}</ul><div id="log-box"></div>`;
  const show = (key) => {
    const l = LOGS[key];
    const box = el.querySelector('#log-box');
    box.innerHTML = `<div class="card mb-3"><div class="card-body filters d-flex flex-wrap gap-2">
        ${l.filters.map(([k, label]) => `<input class="form-control" data-f="${h(k)}" placeholder="${h(label)}" aria-label="${h(label)}">`).join('')}
        <input class="form-control" data-f="matricule" placeholder="Matricule" aria-label="Matricule">
        <input class="form-control" type="date" data-f="from" aria-label="Depuis le"><input class="form-control" type="date" data-f="to" aria-label="Jusqu'au">
        <button class="btn btn-outline-secondary" id="log-apply" type="button">Filtrer</button></div></div>
      <div class="card"><div class="card-body"><table class="table table-sm w-100" id="log-table"><thead><tr>${l.head.map((x) => `<th>${h(x)}</th>`).join('')}</tr></thead></table></div></div>`;
    const params = () => Object.fromEntries([...box.querySelectorAll('[data-f]')].map((i) => [i.dataset.f, i.value.trim()]));
    const table = serverTable(box.querySelector('#log-table'), l.columns, (p) => get(l.path, { ...p, ...params() }), { pageLength: 50 });
    box.querySelector('#log-apply').addEventListener('click', () => table.ajax.reload());
  };
  el.onclick = (ev) => {
    const b = ev.target.closest('[data-log]');
    if (!b) return;
    el.querySelectorAll('[data-log]').forEach((x) => { x.classList.toggle('active', x === b); x.setAttribute('aria-selected', String(x === b)); });
    show(b.dataset.log);
  };
  show('audit');
}
