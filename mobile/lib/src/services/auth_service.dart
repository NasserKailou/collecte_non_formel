// Connexion de l'agent : en ligne (jetons du serveur) puis déverrouillage hors ligne (vérificateur Argon2id du mot
// de passe, jamais le mot de passe lui-même). Règles : docs/mobile/README.md §2 et docs/api/README.md §2.

import 'dart:convert';

import 'package:cryptography/cryptography.dart';
import 'package:flutter/foundation.dart';

import '../data/local_db.dart';
import '../data/secure_store.dart';
import '../net/api_client.dart';

class AuthException implements Exception {
  AuthException(this.message, {this.code = 'AUTH'});
  final String message;
  final String code;
  @override
  String toString() => message;
}

class Session {
  Session({required this.profile, required this.online});
  final Map<String, Object?> profile;

  /// Vrai si la session a été ouverte avec le serveur (jetons valides au moment de la connexion).
  final bool online;

  String get fullName => (profile['full_name'] ?? '').toString();
  String get matricule => (profile['matricule'] ?? '').toString();
  bool get mustChangePassword => profile['must_change_password'] == true;
}

/// Informations envoyées à la connexion (modèle, version d'Android).
class DeviceInfo {
  const DeviceInfo({required this.model, required this.osVersion});
  final String model;
  final String osVersion;
}

class SecretTokenProvider implements TokenProvider {
  SecretTokenProvider(this._secrets);
  final Secrets _secrets;
  @override
  Future<String?> accessToken() async => (await _secrets.tokens()).access;
  @override
  Future<String?> refreshToken() async => (await _secrets.tokens()).refresh;
  @override
  Future<void> store(String? access, String? refresh) => _secrets.saveTokens(access, refresh);
}

class AuthService {
  AuthService({required this.db, required this.api, required this.secrets, required this.deviceUid, required this.device});

  final LocalDb db;
  final ApiClient api;
  final Secrets secrets;
  final String deviceUid;
  final DeviceInfo device;

  static const _requireOnline = 'auth.require_online';
  static const _locked = 'auth.locked';

  /// Compte connu de l'appareil (pour proposer le déverrouillage hors ligne).
  Map<String, Object?>? get knownAccount => db.account();

  bool get offlineUnlockAllowed {
    final a = db.account();
    if (a == null || db.kv(_requireOnline) == '1') return false;
    return offlineDaysLeft(a) > 0;
  }

  /// Jours restants avant reconnexion obligatoire (max_offline_days depuis la dernière connexion en ligne).
  static int offlineDaysLeft(Map<String, Object?> account) {
    final max = (_policy(account)['max_offline_days'] as num?)?.toInt() ?? 30;
    final last = DateTime.parse(account['last_online_login_at'] as String);
    return max - DateTime.now().toUtc().difference(last).inDays;
  }

  /// Connexion en ligne. Un autre compte ne peut se connecter que si rien n'est en attente d'envoi sur l'appareil.
  Future<Session> loginOnline(String identifier, String password) async {
    final existing = db.account();
    final r = await api.post('/auth/login', {
      'identifier': identifier.trim(),
      'password': password,
      'device': {'uid': deviceUid, 'model': device.model, 'os_version': device.osVersion},
    }, auth: false);
    final d = r.data as Map<String, Object?>;
    final profile = Map<String, Object?>.from(d['user'] as Map);
    final roles = (profile['roles'] as List? ?? const []).cast<String>();
    final perms = (profile['permissions'] as List? ?? const []).cast<String>();
    await secrets.saveTokens(d['access_token'] as String?, d['refresh_token'] as String?);
    if (!perms.contains('collection.submit')) {
      await _revokeQuietly(); // jetons inutilisables ici : révoqués aussitôt
      throw AuthException("Ce compte (${roles.join(', ')}) n'est pas un compte d'agent recenseur : utiliser le back-office web.", code: 'NOT_AGENT');
    }
    if (existing != null && existing['user_uuid'] != profile['uuid']) {
      if (db.pendingWorkCount() > 0) {
        await _revokeQuietly();
        throw AuthException(
            "Cette tablette contient des collectes de ${existing['full_name']} qui n'ont pas encore été envoyées. "
            "Elles doivent être synchronisées par ce compte avant tout changement d'utilisateur.",
            code: 'PENDING_DATA');
      }
      db.wipeUserData();
    }
    final salt = Secrets.randomHex(16);
    db.saveAccount(
      profile: profile,
      verifier: await deriveVerifier(password, salt),
      salt: salt,
      offlinePolicy: Map<String, Object?>.from((d['offline_policy'] as Map?) ?? const {'max_offline_days': 30, 'max_unlock_failures': 10}),
    );
    db.setKv(_requireOnline, null);
    db.setKv(_locked, null);
    return Session(profile: profile, online: true);
  }

  /// Connexion mémorisée : après une première connexion en ligne, l'application s'ouvre directement sur le compte
  /// de la tablette, sans ressaisir les identifiants. Le mot de passe n'est redemandé qu'après « Verrouiller »,
  /// « Se déconnecter », un délai hors ligne dépassé (max_offline_days) ou un changement de mot de passe exigé.
  /// La base reste chiffrée (clé du Keystore Android) ; la protection de la tablette repose sur son verrouillage d'écran.
  Session? restoreRemembered() {
    final a = db.account();
    if (a == null || db.kv(_requireOnline) == '1' || db.kv(_locked) == '1' || offlineDaysLeft(a) <= 0) return null;
    final profile = jsonDecode(a['profile_json'] as String) as Map<String, Object?>;
    if (profile['must_change_password'] == true) return null;
    return Session(profile: profile, online: false);
  }

  /// Verrouillage volontaire : le mot de passe sera demandé à la prochaine ouverture.
  void markLocked() => db.setKv(_locked, '1');

  /// Déverrouillage hors ligne avec le mot de passe de la dernière connexion en ligne.
  Future<Session> unlockOffline(String password) async {
    final a = db.account();
    if (a == null) throw AuthException('Aucun compte sur cette tablette : connexion en ligne nécessaire.');
    if (db.kv(_requireOnline) == '1') throw AuthException('Connexion en ligne nécessaire.');
    if (offlineDaysLeft(a) <= 0) {
      throw AuthException(
          'Plus de ${_policy(a)['max_offline_days']} jours sans connexion au serveur : connectez-vous en ligne (vos collectes restent sur la tablette).');
    }
    final ok = await deriveVerifier(password, a['verifier_salt'] as String) == a['offline_verifier'];
    if (!ok) {
      final failures = (a['unlock_failures'] as int) + 1;
      final max = (_policy(a)['max_unlock_failures'] as num?)?.toInt() ?? 10;
      db.execute('UPDATE account SET unlock_failures = ?', [failures]);
      if (failures >= max) {
        // Trop d'échecs : connexion en ligne exigée ; les données déjà acceptées par le serveur sont effacées.
        db.setKv(_requireOnline, '1');
        _purgeSynchronized();
        throw AuthException("Trop d'essais incorrects : connexion en ligne obligatoire. Les collectes non envoyées sont conservées.", code: 'LOCKED');
      }
      throw AuthException('Mot de passe incorrect (${max - failures} essai(s) restant(s)).');
    }
    db.execute('UPDATE account SET unlock_failures = 0');
    db.setKv(_locked, null);
    return Session(profile: jsonDecode(a['profile_json'] as String) as Map<String, Object?>, online: false);
  }

  Future<void> changePassword(String current, String next) async {
    await api.post('/auth/password', {'current_password': current, 'new_password': next});
    final salt = Secrets.randomHex(16);
    final verifier = await deriveVerifier(next, salt);
    db.execute("UPDATE account SET offline_verifier = ?, verifier_salt = ?, profile_json = json_set(profile_json, '\$.must_change_password', json('false'))",
        [verifier, salt]);
  }

  /// Déconnexion : jetons révoqués et effacés ; les collectes restent sur la tablette (chiffrées).
  Future<void> logout() async {
    await _revokeQuietly();
    db.setKv(_requireOnline, '1');
  }

  Future<void> _revokeQuietly() async {
    try {
      await api.post('/auth/logout', {});
    } catch (_) {
      // hors ligne : les jetons expireront d'eux-mêmes ; ils sont effacés de la tablette dans tous les cas
    }
    await secrets.saveTokens(null, null);
  }

  void _purgeSynchronized() {
    db.transaction(() {
      db.execute("DELETE FROM collections WHERE local_status IN ('SOUMISE', 'VALIDEE')");
      db.execute("DELETE FROM outbox WHERE state IN ('SYNCHRONISE', 'ANNULE')");
    });
  }

  static Map<String, Object?> _policy(Map<String, Object?> a) => jsonDecode(a['offline_policy_json'] as String) as Map<String, Object?>;

  /// Vérificateur hors ligne : Argon2id (8 Mio, 2 passes), calculé hors du fil de l'interface.
  static Future<String> deriveVerifier(String password, String saltHex) => compute(_argon2, [password, saltHex]);

  static Future<String> _argon2(List<String> args) async {
    final algorithm = Argon2id(parallelism: 1, memory: 8192, iterations: 2, hashLength: 32);
    final key = await algorithm.deriveKey(secretKey: SecretKey(utf8.encode(args[0])), nonce: _hexToBytes(args[1]));
    final bytes = await key.extractBytes();
    return bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  }

  static List<int> _hexToBytes(String hex) => [for (var i = 0; i < hex.length; i += 2) int.parse(hex.substring(i, i + 2), radix: 16)];
}
