// Utilisateur connecté (profil /me) et contrôle des permissions côté interface.
// Rappel : l'interface masque seulement les actions ; le serveur vérifie toujours droits et périmètre.
let current = null;

export function setSession(profile) { current = profile; }
export function session() { return current; }
export function can(permission) { return Boolean(current?.permissions?.includes(permission)); }
export function hasRole(role) { return Boolean(current?.roles?.includes(role)); }
