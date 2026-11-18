// Carte des centres : position relevée à la collecte (GPS tablette ou coordonnées reportées d'une fiche papier),
// sinon position du registre. Couleur = statut juridique (public, privé) ; remplissage = état de la collecte ;
// filtres par type, zone, état et source de la position. Rendu en canvas (plusieurs milliers de points).

import { get } from '../api.js';
import { h, pageHeader, options, fmtDate } from '../ui.js';
import { can } from '../session.js';

const LEGAL = { PUBLIC: ['Public', '#128a3e', 'lg-public'], PRIVE: ['Privé', '#d35400', 'lg-prive'], INCONNU: ['Statut non renseigné', '#6c757d', 'lg-inconnu'] };
const STATE = {
  VALIDEE: ['Validée', 1, false],
  SOUMISE: ['Soumise — à contrôler', 0.55, false],
  EN_CONTROLE: ['En contrôle', 0.55, false],
  INVALIDEE: ['À corriger', 0.25, true],
  REOUVERTE: ['Rouverte', 0.25, true],
  NON_COLLECTEE: ['Non collectée (position du registre)', 0, false],
};
const SOURCE = { GPS: 'GPS de la tablette', SAISIE: 'Coordonnées saisies (fiche ou bureau)', REGISTRE: 'Position du registre' };
const CHANNEL = { MOBILE: 'tablette', WEB: 'saisie web', PAPIER: 'fiche papier' };
const NIGER = [[11.6, 0.1], [23.6, 16.1]];

let leafletLoading = null;
/** Leaflet est hébergé localement (vendor/leaflet) et chargé à la première ouverture de la carte. */
function loadLeaflet() {
  if (window.L) return Promise.resolve(window.L);
  leafletLoading ??= new Promise((resolve, reject) => {
    const css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = 'vendor/leaflet/leaflet.css';
    document.head.appendChild(css);
    const js = document.createElement('script');
    js.src = 'vendor/leaflet/leaflet.js';
    js.onload = () => resolve(window.L);
    js.onerror = () => reject(new Error('Bibliothèque cartographique introuvable (vendor/leaflet).'));
    document.head.appendChild(js);
  });
  return leafletLoading;
}

export async function render(el) {
  const campaigns = (await get('/campaigns')).data.filter((c) => c.status !== 'PREPARATION');
  if (campaigns.length === 0) {
    el.innerHTML = pageHeader('Carte des centres') + '<div class="alert alert-secondary">Aucune campagne ouverte ou terminée.</div>';
    return;
  }
  const current = campaigns.find((c) => c.status === 'OUVERTE') || campaigns[0];
  el.innerHTML = pageHeader('Carte des centres')
    + `<div class="card mb-3"><div class="card-body filters d-flex flex-wrap gap-2 align-items-center">
        <select class="form-select w-auto" id="m-camp" aria-label="Campagne">${options(campaigns.map((c) => ({ id: c.id, name: `${c.code} — ${c.name}` })), current.id)}</select>
        <select class="form-select w-auto" id="m-legal" aria-label="Statut juridique"><option value="">Public et privé</option>${Object.entries(LEGAL).map(([k, [l]]) => `<option value="${k}">${h(l)}</option>`).join('')}</select>
        <select class="form-select w-auto" id="m-type" aria-label="Type de structure"><option value="">Tous les types</option></select>
        <select class="form-select w-auto" id="m-zone" aria-label="Zone"><option value="">Urbaine et rurale</option><option value="URBAINE">Urbaine</option><option value="RURALE">Rurale</option></select>
        <select class="form-select w-auto" id="m-state" aria-label="État de la collecte"><option value="">Tous les états</option>${Object.entries(STATE).map(([k, [l]]) => `<option value="${k}">${h(l)}</option>`).join('')}</select>
        <select class="form-select w-auto" id="m-source" aria-label="Source de la position"><option value="">Toutes les positions</option>${Object.entries(SOURCE).map(([k, l]) => `<option value="${k}">${h(l)}</option>`).join('')}</select>
        <div class="form-check ms-2"><input class="form-check-input" type="checkbox" id="m-size"><label class="form-check-label" for="m-size">Taille selon les apprentis</label></div>
      </div></div>
      <div class="row g-3"><div class="col-xl-9"><div class="card"><div class="card-body p-0"><div id="m-map" class="map-box" role="region" aria-label="Carte des centres"></div></div></div></div>
        <div class="col-xl-3"><div class="card mb-3"><div class="card-body"><h2 class="h6">Légende</h2><div id="m-legend" class="small"></div></div></div>
          <div class="card"><div class="card-body"><h2 class="h6">Centres affichés</h2><div id="m-stats" class="small" aria-live="polite"></div></div></div></div></div>`;

  const L = await loadLeaflet();
  const map = L.map(el.querySelector('#m-map'), { preferCanvas: true }).fitBounds(NIGER);
  // Fonds de carte : plan (OpenStreetMap), satellite et hybride (imagerie Esri + limites, noms de lieux et routes).
  const ref = { referrerPolicy: 'strict-origin-when-cross-origin' }; // les fournisseurs de tuiles exigent un en-tête Referer
  const esri = 'https://server.arcgisonline.com/ArcGIS/rest/services';
  const esriAttr = 'Imagerie © Esri, Maxar, Earthstar Geographics et la communauté des utilisateurs SIG';
  const imagery = () => L.tileLayer(`${esri}/World_Imagery/MapServer/tile/{z}/{y}/{x}`, { ...ref, maxZoom: 18, attribution: esriAttr });
  const bases = {
    Plan: L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      ...ref, maxZoom: 18, attribution: '© contributeurs <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }),
    Satellite: imagery(),
    Hybride: L.layerGroup([
      imagery(),
      L.tileLayer(`${esri}/Reference/World_Transportation/MapServer/tile/{z}/{y}/{x}`, { ...ref, maxZoom: 18 }),
      L.tileLayer(`${esri}/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}`, { ...ref, maxZoom: 18, attribution: esriAttr }),
    ]),
  };
  let baseName = 'Plan';
  try { baseName = localStorage.getItem('eftp.map.base') || 'Plan'; } catch { /* stockage indisponible */ }
  (bases[baseName] || bases.Plan).addTo(map);
  L.control.layers(bases, null, { position: 'topright', collapsed: false }).addTo(map);
  map.on('baselayerchange', (e) => { try { localStorage.setItem('eftp.map.base', e.name); } catch { /* sans effet */ } });
  const layer = L.layerGroup().addTo(map);
  let data = { points: [], unplaced: { collected: 0, not_collected: 0 }, total: 0, types: [] };

  el.querySelector('#m-legend').innerHTML = `<div class="fw-semibold mb-1">Couleur : statut juridique</div>
      ${Object.values(LEGAL).map(([l, , cls]) => `<div><span class="lg-dot ${cls}" aria-hidden="true"></span>${h(l)}</div>`).join('')}
      <div class="fw-semibold mt-2 mb-1">Remplissage : état de la collecte</div>
      <div><span class="lg-dot lg-full" aria-hidden="true"></span>Validée</div>
      <div><span class="lg-dot lg-half" aria-hidden="true"></span>Soumise ou en contrôle</div>
      <div><span class="lg-dot lg-alert" aria-hidden="true"></span>À corriger (contour rouge)</div>
      <div><span class="lg-dot lg-empty" aria-hidden="true"></span>Non collectée (position du registre)</div>
      <div class="text-muted mt-2">Cliquer sur un point pour le détail.</div>`;

  const radius = (p) => (el.querySelector('#m-size').checked && p.apprentices ? Math.min(16, 4 + Math.sqrt(p.apprentices)) : 6);

  function popup(p, stateLabel) {
    return `<div class="small"><div class="fw-semibold">${h(p.name)}</div><div class="text-muted">${h(p.code || 'non codifiée')} · ${h(p.type_label)}</div>
      <div>${h((LEGAL[p.legal] || LEGAL.INCONNU)[0])} · ${h({ URBAINE: 'urbaine', RURALE: 'rurale' }[p.zone] || 'zone non renseignée')}</div>
      <div>${h(p.commune)} (${h(p.department)}, ${h(p.region)})${p.locality ? ` — ${h(p.locality)}` : ''}</div>
      <div class="mt-1"><strong>${h(stateLabel)}</strong>${p.channel ? ` · ${h(CHANNEL[p.channel] || p.channel)}` : ''}${p.collected_at ? ` · ${h(fmtDate(p.collected_at))}` : ''}</div>
      ${p.apprentices !== null ? `<div>Apprentis : ${h(p.apprentices)} · Formateurs : ${h(p.trainers)}</div>` : ''}
      <div class="text-muted">Position : ${h(SOURCE[p.source])}${p.accuracy_m ? ` (± ${h(p.accuracy_m)} m)` : ''}</div>
      ${p.collection_uuid && can('collection.view') ? `<a href="#/collectes/${h(p.collection_uuid)}">Ouvrir la collecte</a>` : ''}</div>`;
  }

  function draw() {
    const f = (id) => el.querySelector(id).value;
    const shown = data.points.filter((p) => (!f('#m-legal') || p.legal === f('#m-legal')) && (!f('#m-type') || p.type === f('#m-type'))
      && (!f('#m-zone') || p.zone === f('#m-zone')) && (!f('#m-state') || p.status === f('#m-state')) && (!f('#m-source') || p.source === f('#m-source')));
    layer.clearLayers();
    for (const p of shown) {
      const color = (LEGAL[p.legal] || LEGAL.INCONNU)[1];
      const [stateLabel, fill, alert] = STATE[p.status] || [p.status, 0.5, false];
      L.circleMarker([p.lat, p.lng], {
        radius: radius(p), color: alert ? '#b02a37' : color, weight: alert ? 3 : 2, fillColor: color, fillOpacity: fill,
        dashArray: p.status === 'NON_COLLECTEE' ? '3 3' : null,
      }).bindPopup(() => popup(p, stateLabel)).addTo(layer);
    }
    el.querySelector('#m-stats').innerHTML = `<div class="mb-2"><strong>${shown.length}</strong> point(s) affiché(s) sur ${data.points.length} placé(s)
        (${data.total} centre(s) ciblé(s) dans votre périmètre).</div>
      <ul class="list-unstyled mb-2">${Object.entries(LEGAL).map(([k, [l, , cls]]) => `<li><span class="lg-dot ${cls}" aria-hidden="true"></span>${h(l)} : ${shown.filter((p) => p.legal === k).length}</li>`).join('')}</ul>
      ${data.unplaced.collected + data.unplaced.not_collected > 0 ? `<div class="text-warning-emphasis">Non placés faute de coordonnées : ${data.unplaced.collected} collecté(s),
        ${data.unplaced.not_collected} non collecté(s).</div>` : ''}`;
  }

  async function load() {
    el.querySelector('#m-stats').textContent = 'Chargement…';
    data = (await get('/stats/map', { campaign_id: el.querySelector('#m-camp').value })).data;
    el.querySelector('#m-type').innerHTML = `<option value="">Tous les types</option>${data.types.map((t) => `<option value="${h(t.code)}">${h(t.code)}. ${h(t.label)}</option>`).join('')}`;
    draw();
    if (data.points.length) map.fitBounds(L.latLngBounds(data.points.map((p) => [p.lat, p.lng])).pad(0.1));
  }

  el.onchange = (ev) => {
    if (ev.target.id === 'm-camp') load();
    else if (ev.target.closest('.filters')) draw();
  };
  await load();
  setTimeout(() => map.invalidateSize(), 50);
}
