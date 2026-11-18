// Utilisateurs (gestion par périmètre) et import Excel des agents.
import { get, post, patch, api, download } from '../api.js';
import {
  h, badge, fmtDate, modal, confirmBox, secretBox, toast, toastError, formValues, options, getTerritory,
  serverTable, pageHeader, ROLE_LABELS, showFormError, setFieldError, enhanceForm, REQ,
} from '../ui.js';
import { can, session } from '../session.js';

const ROLE_LEVELS = {
  ADMIN: ['NATIONAL'], SUP_REG: ['REGION'], SUP_DEP: ['DEPARTMENT'], AGENT: ['DEPARTMENT', 'COMMUNE'], VIEWER: ['NATIONAL', 'REGION', 'DEPARTMENT'],
};
const LEVEL_LABELS = { NATIONAL: 'National', REGION: 'Région', DEPARTMENT: 'Département', COMMUNE: 'Commune' };

// ===================================================================== Liste

export async function render(el) {
  el.innerHTML = pageHeader('Utilisateurs', can('user.manage') ? '<button class="btn btn-brand" data-action="create"><i class="bi bi-person-plus me-1"></i>Nouvel utilisateur</button>' : '')
    + `<div class="card mb-3"><div class="card-body filters d-flex flex-wrap gap-2">
        <input class="form-control" id="f-q" placeholder="Matricule, nom, téléphone…" aria-label="Recherche">
        <select class="form-select" id="f-role" aria-label="Rôle"><option value="">Tous les rôles</option>${Object.entries(ROLE_LABELS).map(([k, v]) => `<option value="${k}">${h(v)}</option>`).join('')}</select>
        <select class="form-select" id="f-status" aria-label="Statut"><option value="">Tous les statuts</option><option value="ACTIF">Actifs</option><option value="INACTIF">Inactifs</option></select>
        <select class="form-select" id="f-region" aria-label="Région"></select>
      </div></div>
      <div class="card"><div class="card-body"><table class="table table-sm table-hover w-100" id="users-table"><thead><tr>
        <th>Matricule</th><th>Nom</th><th>Rôle</th><th>Périmètre</th><th>Superviseur</th><th>Statut</th><th>Dernière connexion</th><th></th>
      </tr></thead></table></div></div>`;
  const t = await getTerritory();
  el.querySelector('#f-region').innerHTML = options(t.regions, '', { empty: 'Toutes les régions' });

  const table = serverTable(el.querySelector('#users-table'), [
    { data: null, render: (u) => `<code>${h(u.matricule)}</code>` },
    { data: null, render: (u) => `${h(u.full_name)}${u.locked ? ' <span class="badge text-bg-warning">verrouillé</span>' : ''}<div class="small text-muted">${h(u.phone || '')}</div>` },
    { data: null, render: (u) => h(ROLE_LABELS[u.role] || u.role) },
    { data: null, render: (u) => u.scopes.map((s) => h(s.label)).join('<br>') },
    { data: null, render: (u) => h(u.supervisor?.name || '') },
    { data: null, render: (u) => badge(u.status) + (u.must_change_password ? ' <span class="badge text-bg-light">mot de passe provisoire</span>' : '') },
    { data: null, render: (u) => fmtDate(u.last_login_at, true) },
    { data: null, render: (u) => can('user.manage') ? `<div class="btn-group btn-group-sm">
        <button class="btn btn-outline-secondary" data-action="edit" data-uuid="${h(u.uuid)}" title="Modifier" aria-label="Modifier"><i class="bi bi-pencil"></i></button>
        <button class="btn btn-outline-secondary" data-action="status" data-uuid="${h(u.uuid)}" data-status="${h(u.status)}" title="${u.status === 'ACTIF' ? 'Désactiver' : 'Activer'}" aria-label="${u.status === 'ACTIF' ? 'Désactiver' : 'Activer'}"><i class="bi bi-${u.status === 'ACTIF' ? 'person-slash' : 'person-check'}"></i></button>
        <button class="btn btn-outline-secondary" data-action="reset" data-uuid="${h(u.uuid)}" title="Réinitialiser le mot de passe" aria-label="Réinitialiser le mot de passe"><i class="bi bi-key"></i></button>
        <button class="btn btn-outline-secondary" data-action="devices" data-uuid="${h(u.uuid)}" title="Tablettes" aria-label="Tablettes"><i class="bi bi-tablet"></i></button></div>` : '' },
  ], (p) => get('/users', { ...p, q: el.querySelector('#f-q').value.trim(), role: el.querySelector('#f-role').value,
    status: el.querySelector('#f-status').value, region_id: el.querySelector('#f-region').value }));

  let timer;
  const reload = () => table.ajax.reload();
  el.querySelector('#f-q').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(reload, 350); });
  ['#f-role', '#f-status', '#f-region'].forEach((s) => el.querySelector(s).addEventListener('change', reload));

  el.onclick = async (ev) => {
    const b = ev.target.closest('[data-action]');
    if (!b) return;
    const uuid = b.dataset.uuid;
    try {
      if (b.dataset.action === 'create') await userForm(null, reload);
      if (b.dataset.action === 'edit') await userForm((await get(`/users/${uuid}`)).data, reload);
      if (b.dataset.action === 'status') {
        const active = b.dataset.status === 'ACTIF';
        const reason = await confirmBox({ title: active ? 'Désactiver le compte' : 'Réactiver le compte',
          message: active ? 'Toutes les sessions (web et tablettes) seront fermées immédiatement.' : 'Le compte pourra de nouveau se connecter.',
          reason: active, danger: active, confirmLabel: active ? 'Désactiver' : 'Réactiver' });
        if (reason === null) return;
        await post(`/users/${uuid}/${active ? 'deactivate' : 'activate'}`, active ? { reason } : {});
        toast(active ? 'Compte désactivé.' : 'Compte réactivé.');
        reload();
      }
      if (b.dataset.action === 'reset') {
        if (await confirmBox({ title: 'Réinitialiser le mot de passe', message: 'Un nouveau mot de passe provisoire sera généré ; les sessions en cours seront fermées.', confirmLabel: 'Réinitialiser' }) === null) return;
        const r = (await post(`/users/${uuid}/reset-password`)).data;
        secretBox('Mot de passe provisoire', `Transmettez ce mot de passe à ${r.user.full_name} (${r.user.matricule}). Il devra le changer à la connexion.`, r.temporary_password);
        reload();
      }
      if (b.dataset.action === 'devices') await devicesModal(uuid);
    } catch (e) {
      toastError(e);
    }
  };
}

// ===================================================================== Formulaire

async function userForm(user, onSaved) {
  const t = await getTerritory();
  const grantable = session().roles.includes('ADMIN') ? Object.keys(ROLE_LEVELS) : Object.keys(ROLE_LEVELS).filter((r) => !['ADMIN', 'SUP_REG'].includes(r));
  const supervisors = (await get('/users', { status: 'ACTIF', per_page: 200, role: 'SUP_DEP' })).data
    .concat((await get('/users', { status: 'ACTIF', per_page: 200, role: 'SUP_REG' })).data);
  let scopes = (user?.scopes || []).map((s) => ({ level: s.level, region_id: s.region_id, department_id: s.department_id, commune_id: s.commune_id, label: s.label }));

  const m = modal({
    title: user ? `Modifier ${user.full_name}` : 'Nouvel utilisateur',
    size: 'modal-lg',
    body: `<form autocomplete="off">
      <p class="small text-muted mb-3">Les champs marqués <span class="required-mark" aria-hidden="true">*</span> sont obligatoires.</p>
      <fieldset class="form-section"><legend>1. Identité</legend>
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label" for="u-mat">Matricule${REQ}</label>
            <input class="form-control" id="u-mat" name="matricule" value="${h(user?.matricule)}" ${user ? 'readonly' : 'required'} maxlength="40"
              pattern="[A-Za-z0-9][A-Za-z0-9._\\/\\-]{1,39}" data-error="2 à 40 caractères : lettres, chiffres, . _ / -" aria-describedby="u-mat-hint">
            <small class="form-hint" id="u-mat-hint">${user ? 'Identifiant de connexion : il ne peut plus être modifié.' : 'Identifiant de connexion, non modifiable ensuite.'}</small></div>
          <div class="col-md-4"><label class="form-label" for="u-ln">Nom${REQ}</label><input class="form-control" id="u-ln" name="last_name" value="${h(user?.last_name)}" required maxlength="100"></div>
          <div class="col-md-4"><label class="form-label" for="u-fn">Prénom${REQ}</label><input class="form-control" id="u-fn" name="first_name" value="${h(user?.first_name)}" required maxlength="100"></div>
          <div class="col-md-4"><label class="form-label" for="u-sex">Sexe${REQ}</label><select class="form-select" id="u-sex" name="sex" required>${options([{ id: 'M', name: 'Masculin' }, { id: 'F', name: 'Féminin' }], user?.sex, { empty: 'Choisir…' })}</select></div>
          <div class="col-md-4"><label class="form-label" for="u-tel">Téléphone</label>
            <input class="form-control" id="u-tel" name="phone" type="tel" inputmode="tel" value="${h(user?.phone)}" placeholder="90 12 34 56"
              pattern="\\+?[0-9 ]{8,16}" data-error="8 chiffres, avec ou sans l'indicatif +227." aria-describedby="u-tel-hint">
            <small class="form-hint" id="u-tel-hint">Sert aussi d'identifiant de connexion sur la tablette.</small></div>
          <div class="col-md-4"><label class="form-label" for="u-mail">E-mail</label><input class="form-control" id="u-mail" type="email" name="email" value="${h(user?.email)}" maxlength="190"></div>
        </div></fieldset>
      <fieldset class="form-section"><legend>2. Rôle et rattachement</legend>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label" for="u-role">Rôle${REQ}</label><select class="form-select" id="u-role" name="role" required aria-describedby="u-role-hint">${options(grantable.map((r) => ({ id: r, name: ROLE_LABELS[r] })), user?.role, { empty: 'Choisir…' })}</select>
            <small class="form-hint" id="u-role-hint">Changer de rôle réinitialise le périmètre ci-dessous.</small></div>
          <div class="col-md-6"><label class="form-label" for="u-sup">Superviseur</label><select class="form-select" id="u-sup" name="supervisor_uuid" aria-describedby="u-sup-hint">${options(
            supervisors.filter((s) => s.uuid !== user?.uuid).map((s) => ({ id: s.uuid, name: `${s.full_name} (${s.matricule}) — ${ROLE_LABELS[s.role]}` })), user?.supervisor?.uuid, { empty: 'Aucun' })}</select>
            <small class="form-hint" id="u-sup-hint">Pour un agent : superviseur dont le périmètre couvre le sien.</small></div>
        </div></fieldset>
      <fieldset class="form-section" aria-describedby="sc-intro"><legend>3. Périmètre territorial${REQ}</legend>
        <p class="form-section-intro" id="sc-intro">Zones que la personne peut voir et traiter. Choisir le niveau, l'unité, puis « Ajouter ».</p>
        <div id="scope-list" class="mb-2" aria-live="polite"></div>
        <input type="hidden" name="scopes" data-focus-target="sc-level">
        <div class="row g-2 align-items-end">
          <div class="col-sm-6 col-lg-3"><label class="form-label small" for="sc-level">Niveau</label><select class="form-select form-select-sm" id="sc-level"></select></div>
          <div class="col-sm-6 col-lg-3"><label class="form-label small" for="sc-region">Région</label><select class="form-select form-select-sm" id="sc-region">${options(t.regions, '', { empty: '—' })}</select></div>
          <div class="col-sm-6 col-lg-3"><label class="form-label small" for="sc-dept">Département</label><select class="form-select form-select-sm" id="sc-dept"></select></div>
          <div class="col-sm-6 col-lg-3"><label class="form-label small" for="sc-commune">Commune</label><select class="form-select form-select-sm" id="sc-commune"></select></div>
          <div class="col-12"><button type="button" class="btn btn-outline-success" id="sc-add"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Ajouter ce périmètre</button></div>
        </div></fieldset>
    </form>`,
    buttons: [{
      label: 'Enregistrer',
      action: async (mm) => {
        if (!check()) return false;
        if (scopes.length === 0) {
          showFormError(mm.body, { message: 'Certaines informations sont à corriger.', details: { errors: [{ field: 'scopes', message: 'Ajouter au moins un périmètre territorial.' }] } });
          return false;
        }
        const v = formValues(mm.body.querySelector('form'));
        const body = { ...v, scopes: scopes.map(({ label, ...s }) => s) };
        if (user) {
          delete body.matricule;
          const r = (await patch(`/users/${user.uuid}`, body)).data;
          toast(`${r.full_name} : modifications enregistrées.`);
        } else {
          const r = (await post('/users', body)).data;
          secretBox('Compte créé', `Mot de passe provisoire de ${r.user.full_name} (identifiant : ${r.user.matricule}) :`, r.temporary_password);
        }
        onSaved();
      },
    }],
  });

  const q = (s) => m.body.querySelector(s);
  const renderScopes = () => {
    q('#scope-list').innerHTML = scopes.length ? scopes.map((s, i) => `<span class="scope-chip"><i class="bi bi-geo-alt" aria-hidden="true"></i>${h(s.label)}
      <button type="button" class="btn btn-sm btn-link text-danger" data-remove="${i}" aria-label="Retirer ${h(s.label)}"><i class="bi bi-x-lg" aria-hidden="true"></i></button></span>`).join('')
      : '<span class="text-muted small">Aucun périmètre ajouté.</span>';
    if (scopes.length) setFieldError(q('[name="scopes"]'), null);
  };
  const check = enhanceForm(q('form'));
  const refreshGeo = () => {
    const level = q('#sc-level').value;
    const did = q('#sc-dept').value;
    q('#sc-region').disabled = level === 'NATIONAL' || !level;
    q('#sc-dept').disabled = !['DEPARTMENT', 'COMMUNE'].includes(level);
    q('#sc-commune').disabled = level !== 'COMMUNE';
    q('#sc-dept').innerHTML = options(t.departments.filter((d) => String(d.region_id) === q('#sc-region').value), did, { empty: '—' });
    q('#sc-commune').innerHTML = options(t.communes.filter((c) => String(c.department_id) === q('#sc-dept').value), '', { empty: '—' });
  };
  const refreshLevels = () => {
    q('#sc-level').innerHTML = options((ROLE_LEVELS[q('[name="role"]').value] || []).map((l) => ({ id: l, name: LEVEL_LABELS[l] })), '');
    refreshGeo();
  };
  q('[name="role"]').addEventListener('change', () => { scopes = []; renderScopes(); refreshLevels(); });
  ['#sc-level', '#sc-region', '#sc-dept'].forEach((s) => q(s).addEventListener('change', refreshGeo));
  q('#scope-list').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-remove]');
    if (b) { scopes.splice(Number(b.dataset.remove), 1); renderScopes(); }
  });
  q('#sc-add').addEventListener('click', () => {
    const level = q('#sc-level').value;
    const name = (list, id) => list.find((x) => String(x.id) === String(id))?.name;
    let s = null;
    if (level === 'NATIONAL') s = { level, label: 'National' };
    if (level === 'REGION' && q('#sc-region').value) s = { level, region_id: Number(q('#sc-region').value), label: `Région ${name(t.regions, q('#sc-region').value)}` };
    if (level === 'DEPARTMENT' && q('#sc-dept').value) s = { level, department_id: Number(q('#sc-dept').value), label: `Département ${name(t.departments, q('#sc-dept').value)}` };
    if (level === 'COMMUNE' && q('#sc-commune').value) s = { level, commune_id: Number(q('#sc-commune').value), label: `Commune ${name(t.communes, q('#sc-commune').value)}` };
    if (!s) { showFormError(m.body, { message: "Choisir l'unité territoriale correspondant au niveau." }); return; }
    if (!scopes.some((x) => x.label === s.label)) scopes.push(s);
    renderScopes();
  });
  renderScopes();
  refreshLevels();
}

async function devicesModal(uuid) {
  const list = (await get(`/users/${uuid}/devices`)).data;
  const m = modal({
    title: 'Tablettes du compte',
    size: 'modal-lg',
    body: list.length === 0 ? '<p class="text-muted">Aucune tablette enregistrée.</p>' : `<table class="table table-sm"><thead><tr>
      <th>Modèle</th><th>Android</th><th>Application</th><th>Dernière activité</th><th>État</th><th></th></tr></thead><tbody>${list.map((d) => `<tr>
      <td>${h(d.model)}</td><td>${h(d.os_version)}</td><td>${h(d.app_version)}</td><td>${fmtDate(d.last_seen_at, true)}</td>
      <td>${d.revoked_at ? `<span class="badge text-bg-danger">révoquée le ${fmtDate(d.revoked_at)}</span>` : '<span class="badge text-bg-success">active</span>'}</td>
      <td>${d.revoked_at ? '' : `<button class="btn btn-sm btn-outline-danger" data-revoke="${h(d.id)}">Révoquer</button>`}</td></tr>`).join('')}</tbody></table>`,
  });
  m.body.addEventListener('click', async (ev) => {
    const b = ev.target.closest('[data-revoke]');
    if (!b) return;
    const reason = await confirmBox({ title: 'Révoquer la tablette', message: "La tablette ne pourra plus se connecter (perte, vol, départ de l'agent).", reason: true, danger: true, confirmLabel: 'Révoquer' });
    if (reason === null) return;
    try {
      await post(`/devices/${b.dataset.revoke}/revoke`, { reason });
      toast('Tablette révoquée.');
      m.close();
    } catch (e) { toastError(e); }
  });
}

// ===================================================================== Import Excel des agents

export async function importPage(el) {
  el.innerHTML = pageHeader('Import des agents et superviseurs')
    + `<div class="card mb-3"><div class="card-body">
        <ol class="mb-3">
          <li>Télécharger le modèle, le remplir (une ligne par compte), sans modifier la ligne d'en-tête.
            Sexe, rôle, région, département, commune et superviseur se <strong>choisissent dans des listes déroulantes</strong>
            (région d'abord : seuls ses départements, puis leurs communes, sont proposés). Les codes sont ajoutés automatiquement.</li>
          <li>Envoyer le fichier : il est entièrement contrôlé, <strong>sans rien créer</strong>.</li>
          <li>Si aucune anomalie n'est signalée, valider : tous les comptes sont créés en une fois, ou aucun.</li>
        </ol>
        <div class="row g-3 align-items-end">
          <div class="col-md-auto"><button class="btn btn-outline-success" id="tpl"><i class="bi bi-download me-1" aria-hidden="true"></i>1. Télécharger le modèle</button></div>
          <div class="col-md"><label class="form-label" for="file">2. Fichier rempli${REQ}</label>
            <input class="form-control" type="file" id="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" aria-describedby="file-hint">
            <small class="form-hint" id="file-hint">Classeur Excel .xlsx, 5 Mo et 5 000 lignes au plus.</small></div>
          <div class="col-md-auto"><button class="btn btn-brand" id="send"><i class="bi bi-upload me-1" aria-hidden="true"></i>3. Contrôler le fichier</button></div>
        </div></div></div>
      <div id="result" aria-live="polite"></div>`;
  const input = el.querySelector('#file');
  const fileProblem = (f) => {
    if (!f) return 'Choisir le fichier à contrôler.';
    if (!/\.xlsx$/i.test(f.name)) return 'Le fichier doit être un classeur Excel .xlsx (enregistrer le modèle au format .xlsx).';
    if (f.size > 5 * 1024 * 1024) return `Fichier trop volumineux (${(f.size / 1048576).toFixed(1)} Mo ; 5 Mo au plus).`;
    return null;
  };
  input.addEventListener('change', () => setFieldError(input, input.files[0] ? fileProblem(input.files[0]) : null));
  el.querySelector('#tpl').addEventListener('click', () => download('/imports/users/template', 'modele-import-agents.xlsx').catch(toastError));
  el.querySelector('#send').addEventListener('click', async (ev) => {
    const btn = ev.currentTarget;
    const f = input.files[0];
    const problem = fileProblem(f);
    setFieldError(input, problem);
    if (problem) { input.focus(); return; }
    const fd = new FormData();
    fd.append('file', f);
    const label = btn.innerHTML;
    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Contrôle en cours…';
    try {
      showPreview(el.querySelector('#result'), (await api('POST', '/imports/users', fd)).data);
    } catch (e) { toastError(e); } finally {
      btn.disabled = false;
      btn.removeAttribute('aria-busy');
      btn.innerHTML = label;
    }
  });
}

function showPreview(box, imp) {
  box.innerHTML = `<div class="card"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div><h2 class="h5 mb-1">${h(imp.file_name)} ${badge(imp.status)}</h2>
        <div class="small">${h(imp.total_rows)} ligne(s) — <span class="text-success">${h(imp.valid_rows)} valide(s)</span> — <span class="text-danger">${h(imp.error_rows)} en erreur</span>
        ${Object.entries(imp.by_role || {}).map(([r, n]) => ` — ${h(n)} ${h(ROLE_LABELS[r] || r)}`).join('')}</div></div>
      <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary" data-act="report"><i class="bi bi-file-earmark-spreadsheet me-1"></i>${imp.status === 'IMPORTE' ? 'Archive des identifiants' : 'Rapport de contrôle'}</button>
        ${imp.status === 'PREVISUALISE' ? '<button class="btn btn-outline-danger" data-act="cancel">Abandonner</button>' : ''}
        ${imp.can_commit ? `<button class="btn btn-green" data-act="commit"><i class="bi bi-check2-circle me-1"></i>Créer les ${h(imp.total_rows)} comptes</button>` : ''}
      </div></div>
    ${(imp.fatal_errors || []).map((f) => `<div class="alert alert-danger">${h(f)}</div>`).join('')}
    ${!imp.can_commit && imp.status === 'PREVISUALISE' && !(imp.fatal_errors || []).length ? "<div class=\"alert alert-warning\">Corriger les lignes en erreur dans le fichier, puis le renvoyer. L'import est tout-ou-rien.</div>" : ''}
    <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Ligne</th><th>Matricule</th><th>Nom</th><th>Rôle</th><th>Périmètre</th><th>Superviseur</th><th>Contrôle</th></tr></thead><tbody>
    ${(imp.rows || []).map((r) => `<tr class="${r.errors.length ? 'table-danger' : ''}"><td>${h(r.line)}</td><td><code>${h(r.matricule)}</code></td><td>${h(r.name)}</td>
      <td>${h(ROLE_LABELS[r.role] || r.role || '')}</td><td>${h(r.scope)}</td><td>${h(r.supervisor || '')}</td>
      <td>${r.errors.length ? `<ul class="mb-0 ps-3 small">${r.errors.map((e) => `<li>${h(e)}</li>`).join('')}</ul>` : '<span class="text-success">OK</span>'}</td></tr>`).join('')}
    </tbody></table></div>
    ${imp.rows_truncated ? '<p class="small text-muted">Affichage limité : la totalité figure dans le rapport de contrôle.</p>' : ''}
  </div></div>`;
  // Un seul gestionnaire actif (remplacé à chaque affichage).
  box.onclick = async (ev) => {
    const b = ev.target.closest('[data-act]');
    if (!b) return;
    try {
      if (b.dataset.act === 'report') await download(`/imports/${imp.id}/report`, imp.status === 'IMPORTE' ? 'identifiants-agents.zip' : 'rapport.xlsx');
      if (b.dataset.act === 'cancel') showPreview(box, { ...imp, ...(await post(`/imports/${imp.id}/cancel`)).data, rows: imp.rows });
      if (b.dataset.act === 'commit') {
        if (await confirmBox({ title: 'Créer les comptes', message: `${imp.total_rows} comptes vont être créés avec un mot de passe provisoire.`, confirmLabel: 'Créer' }) === null) return;
        b.disabled = true;
        const r = (await post(`/imports/${imp.id}/commit`)).data;
        const m = secretBox(`${r.created} comptes créés`, "Les identifiants et mots de passe provisoires sont dans une archive ZIP chiffrée (AES-256, à ouvrir avec 7-Zip). Mot de passe de l'archive :",
          r.archive_password, "<button class=\"btn btn-green mt-3\" data-zip><i class=\"bi bi-file-zip me-1\"></i>Télécharger l'archive des identifiants</button>");
        m.body.querySelector('[data-zip]').addEventListener('click', () => download(`/imports/${imp.id}/report`, 'identifiants-agents.zip').catch(toastError));
        showPreview(box, { ...imp, ...r.import, rows: imp.rows, can_commit: false });
      }
    } catch (e) {
      toastError(e);
      b.disabled = false;
    }
  };
}
