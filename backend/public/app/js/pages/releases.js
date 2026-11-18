// Application mobile : publication d'une nouvelle version (APK) et suivi des versions. Les tablettes vérifient la
// dernière version à chaque synchronisation ; une version « obligatoire » bloque les anciennes jusqu'à la mise à jour.

import { api, get, post } from '../api.js';
import { h, toast, toastError, pageHeader, confirmBox, enhanceForm, showFormError, REQ, fmtDate } from '../ui.js';

const size = (b) => `${(b / 1048576).toFixed(1)} Mo`;

export async function render(el) {
  el.innerHTML = pageHeader('Application mobile')
    + `<div class="row g-3"><div class="col-lg-5"><div class="card"><div class="card-body">
        <h2 class="h6">Publier une nouvelle version</h2>
        <form id="rel-form" autocomplete="off">
          <div class="mb-3"><label class="form-label" for="rel-file">Fichier APK${REQ}</label>
            <input class="form-control" type="file" id="rel-file" name="file" accept=".apk,application/vnd.android.package-archive" required aria-describedby="rel-file-hint">
            <small class="form-hint" id="rel-file-hint">APK signé produit par <code>flutter build apk --release</code> (300 Mo au plus).</small></div>
          <div class="mb-3"><label class="form-label" for="rel-version">Version${REQ}</label>
            <input class="form-control" id="rel-version" name="version" required pattern="[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,4}" placeholder="1.1.0"
              data-error="Format X.Y.Z, identique à la version de l'application (pubspec.yaml)." aria-describedby="rel-version-hint">
            <small class="form-hint" id="rel-version-hint">Doit être supérieure à la dernière version publiée.</small></div>
          <div class="mb-3"><label class="form-label" for="rel-notes">Nouveautés (affichées aux agents)</label>
            <textarea class="form-control" id="rel-notes" name="notes" rows="3" maxlength="5000"></textarea></div>
          <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="rel-mandatory" name="mandatory" value="1" aria-describedby="rel-mandatory-hint">
            <label class="form-check-label" for="rel-mandatory">Mise à jour obligatoire</label>
            <div class="form-hint" id="rel-mandatory-hint">Les tablettes en version antérieure ne pourront plus synchroniser avant d'avoir installé celle-ci. Leurs collectes restent conservées.</div></div>
          <button class="btn btn-brand" id="rel-submit" type="submit"><i class="bi bi-upload me-1" aria-hidden="true"></i>Publier</button>
        </form></div></div></div>
      <div class="col-lg-7"><div class="card"><div class="card-body"><h2 class="h6">Versions publiées</h2><div id="rel-list" class="table-responsive"></div>
        <p class="small text-muted mt-2 mb-0">Questionnaire : une nouvelle version du questionnaire attribuée à une campagne est téléchargée automatiquement par les tablettes à la synchronisation (aucune mise à jour de l'application n'est nécessaire).</p>
      </div></div></div></div>`;

  const listBox = el.querySelector('#rel-list');
  const load = async () => {
    const rows = (await get('/app/releases')).data;
    listBox.innerHTML = rows.length === 0 ? '<p class="text-muted">Aucune version publiée.</p>'
      : `<table class="table table-sm align-middle"><thead><tr><th>Version</th><th>Publiée</th><th>Taille</th><th>Type</th><th>État</th><th></th></tr></thead><tbody>
        ${rows.map((r, i) => `<tr><td><strong>${h(r.version)}</strong>${i === 0 && r.status === 'PUBLIEE' ? ' <span class="badge text-bg-success">actuelle</span>' : ''}
            ${r.notes ? `<div class="small text-muted">${h(r.notes)}</div>` : ''}<div class="small text-muted font-monospace" title="Empreinte SHA-256">${h(r.sha256.slice(0, 16))}…</div></td>
          <td class="small">${fmtDate(r.published_at, true)}<div class="text-muted">${h(r.published_by || '')}</div></td><td class="small">${size(r.size_bytes)}</td>
          <td>${r.mandatory ? '<span class="badge st-INVALIDEE">obligatoire</span>' : '<span class="badge text-bg-secondary">facultative</span>'}</td>
          <td>${r.status === 'PUBLIEE' ? '<span class="badge st-VALIDEE">publiée</span>' : `<span class="badge text-bg-secondary">retirée</span><div class="small text-muted">${fmtDate(r.retired_at)}</div>`}</td>
          <td class="text-end">${r.status === 'PUBLIEE' ? `<button class="btn btn-sm btn-outline-danger" data-retire="${h(r.id)}" data-version="${h(r.version)}">Retirer</button>` : ''}</td></tr>`).join('')}
        </tbody></table>`;
  };

  const form = el.querySelector('#rel-form');
  const check = enhanceForm(form);
  form.addEventListener('submit', async () => {
    if (!check()) return;
    const file = form.querySelector('#rel-file').files[0];
    if (!/\.apk$/i.test(file?.name || '')) {
      showFormError(form, { message: 'Le fichier doit être un APK (.apk).' });
      return;
    }
    const fd = new FormData(form);
    if (!form.querySelector('#rel-mandatory').checked) fd.set('mandatory', '0');
    const btn = el.querySelector('#rel-submit');
    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');
    btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Envoi (${size(file.size)})…`;
    try {
      const r = (await api('POST', '/app/releases', fd)).data;
      toast(`Version ${r.version} publiée : les tablettes la proposeront à la prochaine synchronisation.`);
      form.reset();
      await load();
    } catch (e) {
      showFormError(form, e);
    } finally {
      btn.disabled = false;
      btn.removeAttribute('aria-busy');
      btn.innerHTML = '<i class="bi bi-upload me-1" aria-hidden="true"></i>Publier';
    }
  });

  el.onclick = async (ev) => {
    const b = ev.target.closest('[data-retire]');
    if (!b) return;
    if (await confirmBox({ title: `Retirer la version ${b.dataset.version}`, message: "Elle ne sera plus proposée aux tablettes (celles qui l'ont installée continuent de fonctionner, sauf version obligatoire plus récente).", confirmLabel: 'Retirer', danger: true }) === null) return;
    try {
      await post(`/app/releases/${b.dataset.retire}/retire`);
      toast('Version retirée.');
      await load();
    } catch (e) { toastError(e); }
  };
  await load();
}
