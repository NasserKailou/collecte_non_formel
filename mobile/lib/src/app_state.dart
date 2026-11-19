// État global de l'application : ouverture de la base chiffrée, session, synchronisation (manuelle, au retour du
// réseau et périodique tant que l'application est ouverte).

import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:path/path.dart' as p;
import 'package:package_info_plus/package_info_plus.dart';
import 'package:path_provider/path_provider.dart';

import 'app_config.dart';
import 'data/local_db.dart';
import 'data/secure_store.dart';
import 'demo/demo_mode.dart';
import 'net/api_client.dart';
import 'services/app_updater.dart';
import 'services/auth_service.dart';
import 'services/collection_service.dart';
import 'services/sync_service.dart';

class AppState extends ChangeNotifier {
  /// Base et service de collecte EN COURS D'USAGE : ceux de la collecte réelle, ou ceux du mode Démo.
  late LocalDb db;
  late CollectionService collections;
  late final AuthService auth; // toujours sur la base réelle
  late final SyncService sync; // toujours sur la base réelle ; jamais appelé en mode Démo

  late final LocalDb _realDb;
  late final CollectionService _realCollections;
  late final String _dataDir;
  late final String _dbKey;
  LocalDb? _demoDb;

  /// Mode Démo (formation) : base séparée, aucun envoi au serveur. Voir demo/demo_mode.dart.
  bool demo = false;

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
      // Version réelle de l'APK installé (et non une constante) : envoyée au serveur, comparée aux annonces.
      try {
        AppConfig.appVersion = (await PackageInfo.fromPlatform()).version;
      } catch (_) {}
      final secrets = Secrets(KeystoreSecretStore());
      final dir = await getApplicationSupportDirectory();
      _dataDir = dir.path;
      _dbKey = await secrets.databaseKey();
      _realDb = db = LocalDb.open(p.join(dir.path, 'eftp_nf.db'), _dbKey);
      final deviceUid = await secrets.deviceUid();
      final api = ApiClient(tokens: SecretTokenProvider(secrets), deviceUid: deviceUid);
      auth = AuthService(db: _realDb, api: api, secrets: secrets, deviceUid: deviceUid, device: await _deviceInfo());
      sync = SyncService(db: _realDb, api: api);
      _realCollections = collections = CollectionService(_realDb);
      ready = true;
      // Connexion mémorisée : l'agent déjà connecté une fois arrive directement sur ses structures.
      final remembered = auth.restoreRemembered();
      if (remembered != null) {
        session = remembered;
        if (!remembered.isSupervisor) {
          // (superviseur : aucune collecte à synchroniser)
          _startAutoSync();
          unawaited(synchronize()); // en ligne si le réseau le permet (jetons rafraîchis) ; sinon nouvel essai automatique
        }
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
    if (s.isSupervisor) {
      notifyListeners(); // superviseur : écran de supervision, aucune synchronisation de collecte
      return;
    }
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

  // ------------------------------------------------------------- Mode Démo

  String get _demoPath => p.join(_dataDir, DemoMode.fileName);

  /// Entrée en mode Démo depuis l'écran de connexion : aucun compte, aucun réseau. La base réelle reste intacte.
  Future<void> enterDemo() async {
    if (demo || !ready) return;
    _demoDb ??= await DemoMode.open(_demoPath, _dbKey);
    db = _demoDb!;
    collections = CollectionService(_demoDb!);
    demo = true;
    session = DemoMode.session();
    lastSyncOk = true;
    lastSyncMessage = "Mode démonstration : les saisies restent sur cette tablette et ne sont jamais envoyées au serveur.";
    notifyListeners();
  }

  /// Retour à l'écran de connexion et à la base réelle. Les saisies de démonstration sont conservées pour la
  /// séance suivante, sauf [reset].
  Future<void> exitDemo({bool reset = false}) async {
    if (!demo) return;
    demo = false;
    session = null;
    db = _realDb;
    collections = _realCollections;
    lastSyncMessage = null;
    lastSyncOk = true;
    notifyListeners();
    if (reset) _dropDemoDb();
  }

  /// Efface toutes les saisies de démonstration et repart du jeu initial (centres fictifs).
  Future<void> resetDemo() async {
    if (!demo) return;
    ready = false; // écran d'attente : aucune vue ne lit la base pendant son remplacement
    notifyListeners();
    try {
      _dropDemoDb();
      _demoDb = await DemoMode.open(_demoPath, _dbKey);
      db = _demoDb!;
      collections = CollectionService(_demoDb!);
      lastSyncMessage = 'Démonstration réinitialisée.';
    } finally {
      ready = true;
      notifyListeners();
    }
  }

  void _dropDemoDb() {
    _demoDb?.close();
    _demoDb = null;
    DemoMode.delete(_demoPath);
  }

  Future<SyncReport?> synchronize({bool force = false}) async {
    if (session == null || syncing || session!.isSupervisor) return null;
    if (demo) {
      // Démo : envoi simulé sur la tablette, JAMAIS de requête vers le serveur.
      final r = DemoMode.simulateSync(db);
      lastSyncOk = true;
      lastSyncMessage = '${r.summary()} — simulation : mode démonstration, rien n\'est envoyé au serveur.';
      notifyListeners();
      return r;
    }
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
  /// Annonce ignorée si la version annoncée est déjà installée : le bandeau disparaît après la mise à jour.
  Map<String, Object?>? get appUpdate {
    final v = ready ? db.kv('app.update') : null;
    if (v == null) return null;
    final u = Map<String, Object?>.from(jsonDecode(v) as Map);
    if (AppUpdater.compareVersions('${u['version'] ?? ''}', AppConfig.appVersion) <= 0) {
      db.setKv('app.update', null);
      return null;
    }
    return u;
  }

  /// Adresse de téléchargement (lien signé, à durée limitée) de la version annoncée.
  Uri? get appUpdateUrl {
    final path = appUpdate?['download_path'] as String?;
    return path == null ? null : Uri.parse('${AppConfig.apiBaseUrl}$path');
  }

  /// Redemande l'annonce au serveur (nouveau lien signé) ; sans réseau, l'annonce précédente est gardée.
  Future<void> refreshAppUpdate() async {
    if (demo) return;
    await sync.checkAppUpdate(SyncReport());
    notifyListeners();
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
    _demoDb?.close();
    if (ready) _realDb.close();
    super.dispose();
  }
}
