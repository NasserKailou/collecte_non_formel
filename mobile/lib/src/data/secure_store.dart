// Secrets de l'appareil, conservés dans le Keystore Android (flutter_secure_storage) et jamais dans la base :
// clé de chiffrement de la base locale, jetons d'accès, identifiant d'appareil.

import 'dart:math';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Abstraction pour pouvoir tester sans Keystore.
abstract class SecretStore {
  Future<String?> read(String key);
  Future<void> write(String key, String? value);
}

class KeystoreSecretStore implements SecretStore {
  final _storage = const FlutterSecureStorage();

  @override
  Future<String?> read(String key) => _storage.read(key: key);

  @override
  Future<void> write(String key, String? value) => value == null ? _storage.delete(key: key) : _storage.write(key: key, value: value);
}

class MemorySecretStore implements SecretStore {
  final Map<String, String> values = {};
  @override
  Future<String?> read(String key) async => values[key];
  @override
  Future<void> write(String key, String? value) async => value == null ? values.remove(key) : values[key] = value;
}

class Secrets {
  Secrets(this._store);
  final SecretStore _store;

  static const _dbKey = 'db_key';
  static const _access = 'access_token';
  static const _refresh = 'refresh_token';
  static const _deviceUid = 'device_uid';

  static String randomHex(int bytes) {
    final r = Random.secure();
    return List.generate(bytes, (_) => r.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
  }

  /// Clé de la base (créée au premier lancement, 256 bits).
  Future<String> databaseKey() async {
    var k = await _store.read(_dbKey);
    if (k == null) {
      k = randomHex(32);
      await _store.write(_dbKey, k);
    }
    return k;
  }

  /// Identifiant stable de l'installation (envoyé dans X-Device-Id et à la connexion).
  Future<String> deviceUid() async {
    var id = await _store.read(_deviceUid);
    if (id == null) {
      id = randomHex(16);
      await _store.write(_deviceUid, id);
    }
    return id;
  }

  Future<({String? access, String? refresh})> tokens() async => (access: await _store.read(_access), refresh: await _store.read(_refresh));

  Future<void> saveTokens(String? access, String? refresh) async {
    await _store.write(_access, access);
    await _store.write(_refresh, refresh);
  }
}
