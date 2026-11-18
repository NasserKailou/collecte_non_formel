// Point d'entrée du back-office : session, changement de mot de passe obligatoire, menu par permissions, navigation.
import { get, post, setCsrf, on } from './api.js';
import { h, errorText, ROLE_LABELS } from './ui.js';
import { setSession, can } from './session.js';
import * as dashboard from './pages/dashboard.js';
import * as collections from './pages/collections.js';
import * as structures from './pages/structures.js';
import * as campaigns from './pages/campaigns.js';
import * as users from './pages/users.js';
import * as admin from './pages/admin.js';
import * as stats from './pages/stats.js';
import * as entry from './pages/entry.js';
import * as mapPage from './pages/map.js';
import * as releases from './pages/releases.js';

let me = null;

const ROUTES = [
  { path: '/', title: 'Tableau de bord', icon: 'speedometer2', perm: null, render: dashboard.render },
  { path: '/ma-saisie', title: 'Ma saisie', icon: 'pencil-square', perm: 'collection.submit', render: entry.list },
  { path: '/ma-saisie/:cs', title: 'Saisie', perm: 'collection.submit', render: entry.form, hidden: true },
  { path: '/collectes', title: 'Collectes', icon: 'clipboard-check', perm: 'collection.view', render: collections.list },
  { path: '/collectes/:uuid', perm: 'collection.view', render: collections.detail, hidden: true },
  { path: '/statistiques', title: 'Statistiques', icon: 'bar-chart-line', perm: 'stats.view', render: stats.render },
  { path: '/carte', title: 'Carte des centres', icon: 'geo-alt', perm: 'stats.view', render: mapPage.render },
  { path: '/exports', title: 'Exports', icon: 'download', perm: 'export.create', render: stats.exportsPage },
  { path: '/structures', title: 'Structures', icon: 'building', perm: 'structure.view', render: structures.render },
  { path: '/campagnes', title: 'Campagnes', icon: 'calendar3', perm: 'campaign.manage', render: campaigns.render },
  { path: '/affectations', title: 'Affectations', icon: 'diagram-3', perm: 'assignment.manage', render: campaigns.assignments },
  { path: '/utilisateurs', title: 'Utilisateurs', icon: 'people', perm: 'user.view', render: users.render },
  { path: '/import-agents', title: 'Import des agents', icon: 'file-earmark-arrow-up', perm: 'user.import', render: users.importPage },
  { path: '/parametres', title: 'Paramètres', icon: 'sliders', perm: 'settings.manage', render: admin.settings },
  { path: '/application-mobile', title: 'Application mobile', icon: 'phone', perm: 'settings.manage', render: releases.render },
  { path: '/journaux', title: 'Journaux', icon: 'journal-text', perm: 'audit.view', render: admin.logs },
];

const $ = (id) => document.getElementById(id);

// ------------------------------------------------------------------ Démarrage

async function boot() {
  try {
    const r = await get('/me');
    me = r.data;
    setSession(me);
    setCsrf(r.data.csrf_token ?? null);
    if (me.must_change_password) showPasswordForm();
    else showApp();
  } catch {
    showLogin();
  }
}

function showLogin(message = '') {
  me = null;
  setSession(null);
  setCsrf(null);
  $('app-view').classList.add('d-none');
  // Aucune donnée de la session précédente ne reste dans la page.
  $('content').innerHTML = '';
  $('nav').innerHTML = '';
  $('user-label').textContent = '';
  document.querySelectorAll('.modal').forEach((m) => bootstrap.Modal.getInstance(m)?.hide());
  $('login-view').classList.remove('d-none');
  $('login-form').classList.remove('d-none');
  $('password-form').classList.add('d-none');
  const err = $('login-error');
  err.textContent = message;
  err.classList.toggle('d-none', message === '');
  $('login-id').focus();
}

function showPasswordForm() {
  $('app-view').classList.add('d-none');
  $('login-view').classList.remove('d-none');
  $('login-form').classList.add('d-none');
  $('password-form').classList.remove('d-none');
  $('pw-current').focus();
}

$('login-form').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const btn = ev.submitter;
  if (btn) btn.disabled = true;
  try {
    const r = await post('/auth/login', { identifier: $('login-id').value.trim(), password: $('login-pw').value, session: true });
    setCsrf(r.data.csrf_token);
    me = r.data.user;
    setSession(me);
    $('login-pw').value = '';
    if (r.data.must_change_password) showPasswordForm();
    else showApp();
  } catch (e) {
    showLogin(errorText(e));
  } finally {
    if (btn) btn.disabled = false;
  }
});

$('password-form').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const err = $('password-error');
  const next = $('pw-new').value;
  if (next !== $('pw-confirm').value) {
    err.textContent = 'Les deux saisies du nouveau mot de passe ne correspondent pas.';
    err.classList.remove('d-none');
    return;
  }
  try {
    await post('/auth/password', { current_password: $('pw-current').value, new_password: next });
    ['pw-current', 'pw-new', 'pw-confirm'].forEach((id) => { $(id).value = ''; });
    err.classList.add('d-none');
    await boot();
  } catch (e) {
    err.textContent = errorText(e);
    err.classList.remove('d-none');
  }
});

$('logout-btn').addEventListener('click', async () => {
  try { await post('/auth/logout'); } catch { /* session déjà expirée */ }
  window.location.hash = '#/';
  showLogin();
});

$('menu-toggle').addEventListener('click', () => $('sidebar').classList.toggle('open'));
on('unauthenticated', () => showLogin('Votre session a expiré. Reconnectez-vous.'));

// ------------------------------------------------------------------ Application

function showApp() {
  $('login-view').classList.add('d-none');
  $('app-view').classList.remove('d-none');
  const roles = me.roles.map((r) => ROLE_LABELS[r] || r).join(', ');
  $('user-label').innerHTML = `<i class="bi bi-person-circle me-1"></i>${h(me.full_name)} <span class="opacity-75">— ${h(roles)}</span>`;
  $('nav').innerHTML = ROUTES.filter((r) => !r.hidden && (r.perm === null || can(r.perm)))
    .map((r) => `<li class="nav-item"><a class="nav-link" href="#${h(r.path)}" data-path="${h(r.path)}"><i class="bi bi-${h(r.icon)}"></i>${h(r.title)}</a></li>`)
    .join('');
  route();
}

function match(pattern, path) {
  const p = pattern.split('/');
  const s = path.split('/');
  if (p.length !== s.length) return null;
  const params = {};
  for (let i = 0; i < p.length; i++) {
    if (p[i].startsWith(':')) params[p[i].slice(1)] = decodeURIComponent(s[i]);
    else if (p[i] !== s[i]) return null;
  }
  return params;
}

async function route() {
  if (!me || me.must_change_password) return;
  const path = (window.location.hash.replace(/^#/, '') || '/').split('?')[0];
  let found = null;
  let params = {};
  for (const r of ROUTES) {
    const m = match(r.path, path);
    if (m) { found = r; params = m; break; }
  }
  const content = $('content');
  document.querySelectorAll('#nav .nav-link').forEach((a) => {
    const p = a.dataset.path;
    a.classList.toggle('active', p === '/' ? path === '/' : path === p || path.startsWith(p + '/'));
  });
  $('sidebar').classList.remove('open');
  if (!found || (found.perm && !can(found.perm))) {
    content.innerHTML = '<div class="alert alert-warning">Page introuvable ou accès non autorisé.</div>';
    return;
  }
  content.onclick = null; // les gestionnaires délégués d'une page ne doivent pas survivre à la navigation
  content.onchange = null;
  content.innerHTML = '<div class="text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Chargement…</div>';
  document.title = `${found.title || 'Collecte'} — EFTP non formel`;
  try {
    await found.render(content, params);
  } catch (e) {
    content.innerHTML = `<div class="alert alert-danger">${h(errorText(e))}</div>`;
  }
  content.focus();
}

window.addEventListener('hashchange', route);
boot();
