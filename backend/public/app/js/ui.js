// Utilitaires d'interface. Règle : toute donnée dynamique insérée en HTML passe par h() (échappement).
import { get } from './api.js';

export function h(value) {
  if (value === null || value === undefined) return '';
  return String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

export const STATUS_LABELS = {
  SOUMISE: 'Soumise', EN_CONTROLE: 'En contrôle', VALIDEE: 'Validée', INVALIDEE: 'Invalidée', REOUVERTE: 'Rouverte',
  ACTIVE: 'Active', PROPOSEE: 'Proposée', FERMEE: 'Fermée', REJETEE: 'Rejetée', FUSIONNEE: 'Fusionnée',
  PREPARATION: 'En préparation', OUVERTE: 'Ouverte', SUSPENDUE: 'Suspendue', CLOTUREE: 'Clôturée', ARCHIVEE: 'Archivée',
  ACTIF: 'Actif', INACTIF: 'Inactif', PREVISUALISE: 'Prévisualisé', IMPORTE: 'Importé', ANNULE: 'Annulé',
};
export const ROLE_LABELS = { ADMIN: 'Administrateur national', SUP_REG: 'Superviseur régional', SUP_DEP: 'Superviseur départemental', AGENT: 'Agent recenseur', VIEWER: 'Lecteur' };

export function badge(status) {
  return `<span class="badge badge-status st-${h(status)}">${h(STATUS_LABELS[status] || status)}</span>`;
}

export function fmtDate(v, withTime = false) {
  if (!v) return '';
  const d = new Date(String(v).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return h(v);
  return withTime ? d.toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : d.toLocaleDateString('fr-FR');
}

// ------------------------------------------------------------------ Notifications

export function toast(message, type = 'success') {
  const el = document.createElement('div');
  el.className = `toast align-items-center text-bg-${type === 'error' ? 'danger' : type} border-0`;
  el.setAttribute('role', type === 'error' ? 'alert' : 'status');
  el.innerHTML = `<div class="d-flex"><div class="toast-body">${h(message)}</div>
    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fermer"></button></div>`;
  document.getElementById('toasts').appendChild(el);
  const t = new bootstrap.Toast(el, { delay: type === 'error' ? 8000 : 4000 });
  el.addEventListener('hidden.bs.toast', () => el.remove());
  t.show();
}

/** Message d'erreur lisible, avec le détail des champs en erreur. */
export function errorText(e) {
  const list = e?.details?.errors;
  if (Array.isArray(list) && list.length) {
    return e.message + ' ' + list.slice(0, 5).map((x) => (typeof x === 'string' ? x : x.message)).join(' ; ') + (list.length > 5 ? ' …' : '');
  }
  if (Array.isArray(e?.details?.problems)) return e.message + ' ' + e.details.problems.join(' ; ');
  return e?.message || 'Erreur inattendue.';
}
export function toastError(e) { toast(errorText(e), 'error'); }

// ------------------------------------------------------------------ Fenêtres modales

/**
 * Fenêtre modale. buttons : [{label, class, action: async (modal) => bool|void}] ; une action qui renvoie false garde la fenêtre ouverte.
 */
export function modal({ title, body, buttons = [], size = '' }) {
  const el = document.createElement('div');
  el.className = 'modal fade';
  el.tabIndex = -1;
  el.innerHTML = `<div class="modal-dialog modal-dialog-scrollable ${size}"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h5">${h(title)}</h2>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
    <div class="modal-body">${body}</div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fermer</button></div>
  </div></div>`;
  document.body.appendChild(el);
  const bs = new bootstrap.Modal(el);
  const footer = el.querySelector('.modal-footer');
  const api = { el, close: () => bs.hide(), body: el.querySelector('.modal-body') };
  buttons.forEach((b) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `btn ${b.class || 'btn-brand'}`;
    btn.textContent = b.label;
    btn.addEventListener('click', async () => {
      // Retour visible pendant l'enregistrement (et pas de double envoi).
      btn.disabled = true;
      const label = btn.textContent;
      btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${h(label)}…`;
      btn.setAttribute('aria-busy', 'true');
      try {
        const keep = await b.action(api);
        if (keep !== false) bs.hide();
      } catch (e) {
        showFormError(api.body, e);
      } finally {
        btn.disabled = false;
        btn.textContent = label;
        btn.removeAttribute('aria-busy');
      }
    });
    footer.appendChild(btn);
  });
  el.addEventListener('hidden.bs.modal', () => el.remove());
  bs.show();
  return api;
}

/** Marque « obligatoire » des libellés (le caractère requis est porté par l'attribut required, lu par les lecteurs d'écran). */
export const REQ = '<span class="required-mark" aria-hidden="true">*</span>';

/** Message d'erreur sous un champ, relié par aria-describedby (annoncé par les lecteurs d'écran). */
export function setFieldError(field, message) {
  const id = `${field.id || field.name}-error`;
  let msg = document.getElementById(id);
  if (!message) {
    field.classList.remove('is-invalid');
    field.removeAttribute('aria-invalid');
    msg?.remove();
    return;
  }
  if (!msg) {
    msg = document.createElement('div');
    msg.id = id;
    msg.className = 'invalid-feedback d-block';
    (field.closest('.input-group') || field).insertAdjacentElement('afterend', msg);
  }
  msg.textContent = message;
  field.classList.add('is-invalid');
  field.setAttribute('aria-invalid', 'true');
  const described = new Set((field.getAttribute('aria-describedby') || '').split(' ').filter(Boolean));
  described.add(id);
  field.setAttribute('aria-describedby', [...described].join(' '));
}

/**
 * Erreurs d'un formulaire : message sous chaque champ concerné et résumé en tête (liens vers les champs),
 * qui reçoit le focus après un enregistrement refusé.
 */
export function showFormError(container, e) {
  container.querySelectorAll('.is-invalid').forEach((x) => setFieldError(x, null));
  container.querySelector('.form-error')?.remove();
  const items = [];
  (e?.details?.errors || []).forEach((x) => {
    if (typeof x !== 'object' || !x) { items.push({ text: String(x) }); return; }
    const field = x.field ? container.querySelector(`[name="${CSS.escape(String(x.field).split('.')[0])}"]`) : null;
    if (field) {
      if (!field.id) field.id = `f-${field.name}-${Math.random().toString(36).slice(2, 7)}`;
      setFieldError(field, x.message);
      // Un champ caché (ex. liste construite à la main) renvoie le focus vers le contrôle qui le remplit.
      items.push({ text: x.message, href: field.dataset.focusTarget || field.id });
    } else {
      items.push({ text: x.message });
    }
  });
  const box = document.createElement('div');
  box.className = 'alert alert-danger form-error';
  box.setAttribute('role', 'alert');
  box.tabIndex = -1;
  box.innerHTML = `<p class="fw-semibold mb-1"><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>${h(e?.message || 'Certaines informations sont à corriger.')}</p>`
    + (items.length ? `<ul class="mb-0 small">${items.map((i) => `<li>${i.href ? `<a href="#${h(i.href)}" data-focus="${h(i.href)}">${h(i.text)}</a>` : h(i.text)}</li>`).join('')}</ul>`
      : (Array.isArray(e?.details?.problems) ? `<ul class="mb-0 small">${e.details.problems.map((p) => `<li>${h(p)}</li>`).join('')}</ul>` : ''));
  box.addEventListener('click', (ev) => {
    const a = ev.target.closest('[data-focus]');
    if (a) { ev.preventDefault(); document.getElementById(a.dataset.focus)?.focus(); }
  });
  container.prepend(box);
  box.focus();
}

/**
 * Contrôle en sortie de champ (règles HTML : required, pattern, type, min/max) avec messages en français
 * (attribut data-error, sinon message générique). Les contrôles métier restent faits par le serveur.
 * Renvoie une fonction de contrôle complet à appeler avant l'envoi.
 */
export function enhanceForm(form) {
  const message = (f) => {
    if (f.validity.valid) return null;
    if (f.dataset.error) return f.dataset.error;
    if (f.validity.valueMissing) return 'Ce champ est obligatoire.';
    if (f.validity.typeMismatch) return f.type === 'email' ? 'Adresse e-mail invalide.' : 'Format invalide.';
    if (f.validity.patternMismatch) return 'Format invalide.';
    if (f.validity.rangeUnderflow || f.validity.rangeOverflow) return `Valeur attendue entre ${f.min || '…'} et ${f.max || '…'}.`;
    return 'Valeur invalide.';
  };
  form.setAttribute('novalidate', '');
  // Pas de rechargement de page sur Entrée : l'enregistrement passe par le bouton de la fenêtre.
  form.addEventListener('submit', (ev) => ev.preventDefault());
  form.querySelectorAll('input, select, textarea').forEach((f) => {
    f.addEventListener('blur', () => { if (f.value !== '' || f.dataset.touched) setFieldError(f, message(f)); f.dataset.touched = '1'; });
    f.addEventListener('input', () => { if (f.classList.contains('is-invalid')) setFieldError(f, message(f)); });
  });
  return () => {
    const bad = [...form.querySelectorAll('input, select, textarea')].filter((f) => !f.disabled && !f.validity.valid);
    if (bad.length === 0) return true;
    showFormError(form.closest('.modal-body') || form, { message: 'Certaines informations sont à corriger.',
      details: { errors: bad.map((f) => ({ field: f.name, message: `${(f.labels?.[0]?.textContent || f.name).replace('*', '').trim()} : ${message(f)}` })) } });
    return false;
  };
}

/** Confirmation, avec saisie facultative d'un motif. Renvoie le motif (ou true), ou null si annulé. */
export function confirmBox({ title, message, confirmLabel = 'Confirmer', reason = false, reasonLabel = 'Motif', danger = false }) {
  return new Promise((resolve) => {
    let done = false;
    let check = null;
    const m = modal({
      title,
      body: `<p>${h(message)}</p>${reason ? `<form autocomplete="off"><label class="form-label" for="cb-reason">${h(reasonLabel)}${REQ}</label>
        <textarea class="form-control" id="cb-reason" name="reason" rows="3" required minlength="3" maxlength="500" aria-describedby="cb-reason-hint"
          data-error="Motif obligatoire (3 caractères au moins)."></textarea>
        <small class="form-hint" id="cb-reason-hint">Conservé dans le journal d'audit.</small></form>` : ''}`,
      buttons: [{
        label: confirmLabel, class: danger ? 'btn-danger' : 'btn-brand',
        action: (mm) => {
          const ta = mm.body.querySelector('#cb-reason');
          if (ta) ta.value = ta.value.trim();
          if (check && !check()) return false;
          const v = reason ? ta.value : true;
          done = true;
          resolve(v);
          return true;
        },
      }],
    });
    const f = m.body.querySelector('form');
    check = f ? enhanceForm(f) : null;
    m.el.addEventListener('hidden.bs.modal', () => { if (!done) resolve(null); });
  });
}

export function secretBox(title, intro, secret, extraHtml = '') {
  return modal({
    title,
    body: `<p>${h(intro)}</p><div class="secret-box mb-3">${h(secret)}</div>
      <p class="small text-danger mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Il ne sera plus jamais affiché. Notez-le maintenant.</p>${extraHtml}`,
  });
}

// ------------------------------------------------------------------ Formulaires

/** Valeurs d'un formulaire (champs vides ⇒ null ; cases à cocher ⇒ booléen). */
export function formValues(form) {
  const out = {};
  form.querySelectorAll('[name]').forEach((el) => {
    if (el.type === 'checkbox') out[el.name] = el.checked;
    else if (el.type === 'number') out[el.name] = el.value === '' ? null : Number(el.value);
    else out[el.name] = el.value.trim() === '' ? null : el.value.trim();
  });
  return out;
}

export function options(list, selected, { value = 'id', label = 'name', empty = null } = {}) {
  return (empty !== null ? `<option value="">${h(empty)}</option>` : '')
    + list.map((o) => `<option value="${h(o[value])}"${String(o[value]) === String(selected ?? '') ? ' selected' : ''}>${h(o[label])}</option>`).join('');
}

// ------------------------------------------------------------------ Territoire (mis en cache)

let territory = null;
export async function getTerritory() {
  if (!territory) territory = (await get('/referentials/territory')).data;
  return territory;
}

/** Sélecteurs région → département → commune enchaînés (<select data-geo="region|department|commune">). */
export async function bindGeoSelects(root, onChange = () => {}, { form = false } = {}) {
  const t = await getTerritory();
  const r = root.querySelector('[data-geo="region"]');
  const d = root.querySelector('[data-geo="department"]');
  const c = root.querySelector('[data-geo="commune"]');
  const fill = (sel, list, empty) => { if (sel) { const v = sel.value; sel.innerHTML = options(list, v, { empty: form ? 'Choisir…' : empty }); } };
  const deptRegion = (deptId) => t.departments.find((x) => x.id === deptId)?.region_id;
  fill(r, t.regions, 'Toutes les régions');
  const refreshD = () => fill(d, t.departments.filter((x) => !r || !r.value || String(x.region_id) === r.value), 'Tous les départements');
  const refreshC = () => fill(c, t.communes.filter((x) => (d && d.value)
    ? String(x.department_id) === d.value
    : (!r || !r.value || String(deptRegion(x.department_id)) === r.value)), 'Toutes les communes');
  refreshD();
  refreshC();
  r?.addEventListener('change', () => { if (d) d.value = ''; refreshD(); refreshC(); onChange(); });
  d?.addEventListener('change', () => {
    // Département choisi sans région : la région est déduite.
    if (r && d.value && !r.value) { r.value = String(deptRegion(Number(d.value)) ?? ''); refreshD(); }
    if (c) c.value = '';
    refreshC();
    onChange();
  });
  c?.addEventListener('change', () => {
    // Commune choisie directement : région et département sont déduits.
    const com = t.communes.find((x) => String(x.id) === c.value);
    if (com && d && !d.value) {
      if (r) { r.value = String(deptRegion(com.department_id) ?? ''); refreshD(); }
      d.value = String(com.department_id);
      refreshC();
      c.value = String(com.id);
    }
    onChange();
  });
}

// ------------------------------------------------------------------ Tableaux (DataTables, pagination serveur)

export const DT_FR = {
  emptyTable: 'Aucune donnée', info: '_START_ à _END_ sur _TOTAL_', infoEmpty: '0 élément', lengthMenu: '_MENU_ par page',
  loadingRecords: 'Chargement…', processing: 'Chargement…', zeroRecords: 'Aucun résultat',
  paginate: { first: '«', last: '»', next: '›', previous: '‹' },
};

/**
 * Tableau paginé côté serveur. fetchPage({page, per_page}) renvoie l'enveloppe {data, meta}.
 * Les colonnes doivent utiliser render() avec échappement.
 */
export function serverTable(table, columns, fetchPage, { pageLength = 25 } = {}) {
  return new DataTable(table, {
    serverSide: true, processing: true, searching: false, ordering: false, pageLength, lengthMenu: [25, 50, 100, 200],
    language: DT_FR, columns, autoWidth: false,
    ajax: async (d, cb) => {
      try {
        const r = await fetchPage({ page: Math.floor(d.start / d.length) + 1, per_page: d.length });
        cb({ draw: d.draw, recordsTotal: r.meta.total, recordsFiltered: r.meta.total, data: r.data });
      } catch (e) {
        toastError(e);
        cb({ draw: d.draw, recordsTotal: 0, recordsFiltered: 0, data: [] });
      }
    },
  });
}

export const text = (key) => ({ data: null, render: (row) => h(key.split('.').reduce((o, k) => o?.[k], row)) });

export function pageHeader(title, actionsHtml = '') {
  return `<div class="page-title"><h1>${h(title)}</h1><div class="d-flex gap-2 flex-wrap">${actionsHtml}</div></div>`;
}
