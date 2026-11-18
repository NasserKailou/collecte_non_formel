// État global de l'application : ouverture de la base chiffrée, session, synchronisation (manuelle, au retour du
// réseau et périodique tant que l'application est ouverte).

import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:url_launcher/url_launcher.dart';

import 'app_config.dart';
import 'data/local_db.dart';
import 'data/secure_store.dart';
import 'net/api_client.dart';
import 'services/auth_service.dart';
import 'services/collection_service.dart';
import 'services/sync_service.dart';

class AppState extends ChangeNotifier {
  late final LocalDb db;
  late final AuthService auth;
  late final SyncService sync;
  late final CollectionService collections;

  Session? session;
  bool ready = false;
  String? fatalError;

  bool syncing = false;
  String? lastSyncMessage;
  bool lastSyncOk = true;

  StreamSubscription<List<ConnectivityResult>>? _connectivity;
  Timer? _timer;

  Future<void> init() async {
    try {
      final secrets = Secrets(KeystoreSecretStore());
      final dir = await getApplicationSupportDirectory();
      db = LocalDb.open(p.join(dir.path, 'eftp_nf.db'), await secrets.databaseKey());
      final deviceUid = await secrets.deviceUid();
      final api = ApiClient(tokens: SecretTokenProvider(secrets), deviceUid: deviceUid);
      auth = AuthService(db: db, api: api, secrets: secrets, deviceUid: deviceUid, device: await _deviceInfo());
      sync = SyncService(db: db, api: api);
      collections = CollectionService(db);
      ready = true;
      // Connexion mémorisée : l'agent déjà connecté une fois arrive directement sur ses structures.
      final remembered = auth.restoreRemembered();
      if (remembered != null) {
        session = remembered;
        _startAutoSync();
        unawaited(synchronize()); // en ligne si le réseau le permet (jetons rafraîchis) ; sinon nouvel essai automatique
      }
    } catch (e) {
      fatalError = 'Démarrage impossible : $e';
    }
    notifyListeners();
  }

  static Future<DeviceInfo> _deviceInfo() async {
    try {
      if (Platform.isAndroid) {
        final a = await DeviceInfoPlugin().androidInfo;
        return DeviceInfo(model: '${a.manufacturer} ${a.model}', osVersion: 'Android ${a.version.release}');
      }
    } catch (_) {}
    return DeviceInfo(model: Platform.operatingSystem, osVersion: Platform.operatingSystemVersion);
  }

  void signedIn(Session s) {
    session = s;
    _startAutoSync();
    notifyListeners();
    if (s.online) synchronize();
  }

  Future<void> logout() async {
    _stopAutoSync();
    await auth.logout();
    session = null;
    notifyListeners();
  }

  /// Verrouillage (retour à l'écran de mot de passe) sans révoquer la session serveur.
  void lock() {
    auth.markLocked();
    _stopAutoSync();
    session = null;
    notifyListeners();
  }

  Future<SyncReport?> synchronize({bool force = false}) async {
    if (session == null || syncing) return null;
    syncing = true;
    notifyListeners();
    try {
      final r = await sync.synchronize(force: force);
      lastSyncOk = r.ok;
      lastSyncMessage = r.summary();
      return r;
    } catch (e) {
      lastSyncOk = false;
      lastSyncMessage = 'Erreur de synchronisation : $e';
      return null;
    } finally {
      syncing = false;
      notifyListeners();
    }
  }

  /// Après une modification locale (Terminer, conflit résolu) : rafraîchit l'affichage et tente l'envoi.
  void changed({bool trySend = false}) {
    notifyListeners();
    if (trySend) synchronize();
  }

  /// Nouvelle version de l'application annoncée par le serveur (null si à jour).
  Map<String, Object?>? get appUpdate {
    final v = ready ? db.kv('app.update') : null;
    return v == null ? null : Map<String, Object?>.from(jsonDecode(v) as Map);
  }

  /// Ouvre le lien de téléchargement signé dans le navigateur : Android télécharge l'APK puis propose l'installation.
  Future<bool> openAppUpdate() async {
    final u = appUpdate;
    final path = u?['download_path'] as String?;
    if (path == null) return false;
    return launchUrl(Uri.parse('${AppConfig.apiBaseUrl}$path'), mode: LaunchMode.externalApplication);
  }

  DateTime? get lastSuccess {
    final v = ready ? db.kv('sync.last_success') : null;
    return v == null ? null : DateTime.parse(v).toLocal();
  }

  void _startAutoSync() {
    _connectivity ??= Connectivity().onConnectivityChanged.listen((results) {
      if (results.any((r) => r != ConnectivityResult.none)) synchronize();
    });
    _timer ??= Timer.periodic(const Duration(minutes: 10), (_) => synchronize());
  }

  void _stopAutoSync() {
    _connectivity?.cancel();
    _connectivity = null;
    _timer?.cancel();
    _timer = null;
  }

  @override
  void dispose() {
    _stopAutoSync();
    if (ready) db.close();
    super.dispose();
  }
}
