// Synchronisation : envoi de la file (un élément à la fois, idempotent), pièces jointes, référentiels (ETag),
// téléchargement incrémental. Règles : docs/mobile/README.md §2 — jamais d'écrasement silencieux, dans aucun sens.

import 'dart:convert';
import 'dart:io';

import '../app_config.dart';
import '../data/local_db.dart';
import '../net/api_client.dart';

class SyncReport {
  int sent = 0;
  int replayed = 0;
  int rejected = 0;
  int conflicts = 0;
  int retryLater = 0;
  int attachmentsSent = 0;
  int assignmentsReceived = 0;
  int corrections = 0;
  int centresCreated = 0;
  int centresFailed = 0;
  int duplicatesFlagged = 0;
  int migratedToNewVersion = 0;
  bool pulled = false;

  /// Versions de questionnaire nouvellement reçues (une campagne a changé de version, ou nouvelle campagne).
  final List<String> questionnaireUpdates = [];

  /// Nouvelle version de l'application annoncée par le serveur (null si à jour ou inconnu).
  Map<String, Object?>? appUpdate;

  /// Arrêt global (session expirée, mise à jour obligatoire, réseau absent).
  String? stoppedBecause;

  bool get ok => stoppedBecause == null;

  String summary() {
    final parts = <String>[
      if (sent > 0) '$sent collecte(s) envoyée(s)',
      if (replayed > 0) '$replayed déjà reçue(s) par le serveur',
      if (rejected > 0) '$rejected refusée(s) : à corriger',
      if (conflicts > 0) '$conflicts en conflit',
      if (retryLater > 0) '$retryLater en attente de nouvel essai',
      if (attachmentsSent > 0) '$attachmentsSent photo(s) envoyée(s)',
      if (corrections > 0) '$corrections collecte(s) renvoyée(s) pour correction',
      if (centresCreated > 0) '$centresCreated nouveau(x) centre(s) enregistré(s)',
      if (duplicatesFlagged > 0) '$duplicatesFlagged doublon(s) possible(s) signalé(s) au superviseur',
      if (centresFailed > 0) "$centresFailed centre(s) refusé(s) : voir l'accueil",
      if (pulled) '$assignmentsReceived affectation(s) mise(s) à jour',
      for (final v in questionnaireUpdates) 'questionnaire $v téléchargé',
      if (migratedToNewVersion > 0) '$migratedToNewVersion collecte(s) passée(s) au nouveau questionnaire : vérifier puis terminer de nouveau',
      if (appUpdate != null) "nouvelle version de l'application disponible (${appUpdate!['version']})",
    ];
    if (stoppedBecause != null) parts.add(stoppedBecause!);
    return parts.isEmpty ? 'Rien à synchroniser.' : parts.join(' · ');
  }
}

/// Résultat du test de connexion lancé par l'agent (accueil ou écran de connexion).
class ServerCheck {
  ServerCheck({required this.address});
  final String address;
  bool reachable = false;
  int? latencyMs;
  bool? database;
  /// null : pas de session à tester (écran de connexion) ; sinon 'OK', 'EXPIREE', 'MISE_A_JOUR', 'REVOQUEE' ou 'ERREUR'.
  String? session;
  String? detail;

  bool get allGood => reachable && database == true && (session == null || session == 'OK');
}

class SyncService {
  SyncService({required this.db, required this.api});

  final LocalDb db;
  final ApiClient api;
  bool _running = false;

  bool get running => _running;

  /// Synchronisation complète : d'abord l'envoi (le serveur reflète alors l'état de la tablette), puis le téléchargement.
  Future<SyncReport> synchronize({bool force = false}) async {
    final report = SyncReport();
    if (_running) {
      report.stoppedBecause = 'Synchronisation déjà en cours.';
      return report;
    }
    _running = true;
    try {
      await pushStructures(report);
      if (report.ok) await pushOutbox(report, force: force);
      if (report.ok) await pushAttachments(report);
      if (report.ok) await pull(report);
    } on ApiException catch (e) {
      _stop(report, e);
    } finally {
      _running = false;
    }
    await checkAppUpdate(report);
    return report;
  }

  /// Test de connexion : serveur joignable (et en combien de temps), base de données, puis session de l'agent.
  /// N'envoie aucune donnée de collecte.
  Future<ServerCheck> checkServer({required bool signedIn}) async {
    final check = ServerCheck(address: api.baseUrl);
    final watch = Stopwatch()..start();
    try {
      final r = await api.get('/health', auth: false);
      check.latencyMs = watch.elapsedMilliseconds;
      check.reachable = true;
      check.database = (r.data as Map?)?['database'] == true;
    } on ApiException catch (e) {
      check.latencyMs = watch.elapsedMilliseconds;
      if (e.isNetwork) {
        check.detail = e.message;
        return check;
      }
      check.reachable = true; // le serveur a répondu, mais avec une erreur
      check.database = e.status == 503 ? false : null;
      check.detail = '${e.status} ${e.code}';
    }
    db.log('TEST', '/health', check.reachable ? 200 : null, check.reachable ? 'JOIGNABLE' : 'INJOIGNABLE');
    if (check.reachable && signedIn) {
      try {
        await api.get('/me');
        check.session = 'OK';
      } on ApiException catch (e) {
        check.session = switch (e.code) {
          'SESSION_EXPIRED' || 'UNAUTHENTICATED' => 'EXPIREE',
          'UPGRADE_REQUIRED' => 'MISE_A_JOUR',
          'DEVICE_REVOKED' => 'REVOQUEE',
          _ => 'ERREUR',
        };
        check.detail ??= e.message;
      }
    }
    return check;
  }

  /// Recherche d'une nouvelle version de l'application (accessible même quand le serveur refuse la version installée).
  Future<void> checkAppUpdate(SyncReport report) async {
    try {
      final r = await api.get('/app/releases/latest');
      final d = r.data;
      if (d is Map && d['update_available'] == true) {
        report.appUpdate = Map<String, Object?>.from(d);
        db.setKv('app.update', jsonEncode(d));
      } else {
        db.setKv('app.update', null);
      }
    } on ApiException {
      // pas de réseau ou serveur indisponible : l'annonce précédente est conservée
    }
  }

  void _stop(SyncReport report, ApiException e) {
    report.stoppedBecause = switch (e.code) {
      'SESSION_EXPIRED' => 'Session expirée : se reconnecter en ligne pour synchroniser.',
      'UPGRADE_REQUIRED' => "Version de l'application trop ancienne : installer la mise à jour.",
      'DEVICE_REVOKED' => "Tablette révoquée par l'administrateur.",
      _ when e.isNetwork => 'Serveur injoignable : nouvel essai plus tard.',
      _ => e.message,
    };
  }

  // ------------------------------------------------------------------- Envoi

  /// Centres créés sur le terrain : envoyés avant leurs collectes (idempotent par l'UUID du centre).
  Future<void> pushStructures(SyncReport report) async {
    for (final p in db.pendingStructures()) {
      final uuid = p['uuid'] as String;
      final path = '/campaigns/${p['campaign_id']}/field-structures';
      try {
        final r = await api.post(path, jsonDecode(p['payload_json'] as String));
        final d = r.data as Map<String, Object?>;
        final duplicates = (d['possible_duplicates'] as List?) ?? const [];
        db.markStructureSynced(uuid, (d['campaign_structure_id'] as num).toInt(), Map<String, Object?>.from(d['structure'] as Map), duplicates);
        final col = db.collectionByCs((d['campaign_structure_id'] as num).toInt());
        if (col != null) {
          db.event(col['uuid'] as String, 'CENTRE_ENREGISTRE', duplicates.isEmpty ? null : '${duplicates.length} doublon(s) possible(s) signalé(s) au superviseur');
        }
        db.log('ENVOI', path, r.status, 'CENTRE');
        report.centresCreated++;
        if (duplicates.isNotEmpty) report.duplicatesFlagged++;
      } on ApiException catch (e) {
        db.log('ENVOI', path, e.status == 0 ? null : e.status, e.code);
        if (e.isNetwork || e.code == 'SESSION_EXPIRED' || e.code == 'UPGRADE_REQUIRED' || e.code == 'DEVICE_REVOKED') {
          _stop(report, e);
          return;
        }
        if (e.isTransient) continue; // nouvel essai à la prochaine synchronisation
        db.markStructureFailed(uuid, e.message); // 403 hors zone, 422 données, 409 déjà affecté, 423 campagne fermée
        report.centresFailed++;
      }
    }
  }

  Future<void> pushOutbox(SyncReport report, {bool force = false}) async {
    // Un élément resté « EN_COURS » (application fermée pendant l'envoi) est simplement renvoyé : le serveur
    // reconnaît le rejeu (même empreinte) et renvoie l'accusé initial.
    for (var guard = 0; guard < 500; guard++) {
      final item = db.nextOutbox(ignoreSchedule: force);
      if (item == null) return;
      if (await _sendOne(item, report)) return;
    }
  }

  /// Envoie un élément ; renvoie vrai s'il faut arrêter la synchronisation.
  Future<bool> _sendOne(Map<String, Object?> item, SyncReport report) async {
    final id = item['id'] as int;
    final uuid = item['collection_uuid'] as String;
    final path = '/collections/$uuid/submissions';
    db.outboxUpdate(id, OutboxState.enCours);
    try {
      final r = await api.post(path, jsonDecode(item['payload_json'] as String), headers: {'Idempotency-Key': '$uuid:${item['payload_sha256']}'});
      final d = r.data as Map<String, Object?>;
      final replayed = d['replayed'] == true || r.status == 200;
      db.transaction(() {
        db.outboxUpdate(id, OutboxState.synchronise, acked: true);
        db.setCollectionStatus(uuid, LocalStatus.soumise,
            serverStatus: (d['status'] ?? 'SOUMISE').toString(), serverRevision: (d['revision'] as num?)?.toInt(), serverIssues: d['warnings']);
        db.event(uuid, replayed ? 'ACCUSE_REJEU' : 'ACCUSE', 'révision ${d['revision']}');
        db.log('ENVOI', path, r.status, replayed ? 'REJEU' : 'ACCEPTEE');
      });
      replayed ? report.replayed++ : report.sent++;
      return false;
    } on ApiException catch (e) {
      db.log('ENVOI', path, e.status == 0 ? null : e.status, e.code);
      if (e.code == 'SESSION_EXPIRED' || e.code == 'UPGRADE_REQUIRED' || e.code == 'DEVICE_REVOKED') {
        db.outboxUpdate(id, OutboxState.enAttente, error: e.message, nextAttempt: DateTime.now());
        _stop(report, e);
        return true;
      }
      if (e.isTransient || e.code == 'CAMPAIGN_SUSPENDED' || e.code == 'CAMPAIGN_NOT_OPEN') {
        // Temporaire (réseau, serveur, campagne suspendue) : nouvel essai avec temporisation exponentielle.
        final attempts = item['attempts'] as int;
        db.outboxUpdate(id, OutboxState.enAttente, error: e.message, nextAttempt: DateTime.now().add(AppConfig.backoff(attempts)), incrementAttempts: true);
        report.retryLater++;
        if (e.isNetwork) {
          _stop(report, e);
          return true;
        }
        return false;
      }
      if (e.status == 422 || e.status == 413) {
        // Refus : la collecte redevient modifiable, avec les anomalies du serveur.
        final col = db.collection(uuid);
        final issues = (e.details is Map ? (e.details as Map)['errors'] : null) ??
            [
              {'code': e.code, 'severity': 'error', 'target': '', 'message': e.message}
            ];
        db.transaction(() {
          db.outboxUpdate(id, OutboxState.echec, error: e.message, incrementAttempts: true);
          db.setCollectionStatus(uuid, (col?['server_revision'] as int? ?? 0) > 0 ? LocalStatus.invalidee : LocalStatus.brouillon, serverIssues: issues);
          db.event(uuid, 'REFUS_SERVEUR', e.message);
        });
        report.rejected++;
        return false;
      }
      // 403 / 404 / 409 / 423 : conflit. La copie locale reste intacte et signalée ; décision de l'agent ou du superviseur.
      db.transaction(() {
        db.outboxUpdate(id, OutboxState.conflit, error: e.message, incrementAttempts: true);
        db.setCollectionStatus(uuid, LocalStatus.enConflit, conflict: {'code': e.code, 'status': e.status, 'message': e.message, 'details': e.details});
        db.event(uuid, 'CONFLIT', '${e.code} : ${e.message}');
      });
      report.conflicts++;
      return false;
    }
  }

  Future<void> pushAttachments(SyncReport report) async {
    for (final a in db.pendingAttachments()) {
      final uuid = a['collection_uuid'] as String;
      final file = File(a['local_path'] as String);
      final path = '/collections/$uuid/attachments';
      if (!file.existsSync()) {
        db.attachmentUpdate(a['id'] as int, OutboxState.echec, error: 'Photo introuvable sur la tablette.');
        continue;
      }
      try {
        final r = await api.uploadFile(path, file, query: {'kind': a['kind']});
        db.attachmentUpdate(a['id'] as int, OutboxState.synchronise);
        db.log('ENVOI', path, r.status, 'PHOTO');
        report.attachmentsSent++;
      } on ApiException catch (e) {
        db.log('ENVOI', path, e.status == 0 ? null : e.status, e.code);
        if (e.isNetwork || e.code == 'SESSION_EXPIRED') {
          _stop(report, e);
          return;
        }
        if (e.isTransient) continue;
        db.attachmentUpdate(a['id'] as int, OutboxState.echec, error: e.message);
      }
    }
  }

  // -------------------------------------------------------------- Téléchargement

  Future<void> pull(SyncReport report) async {
    await _referential('/referentials/territory', 'territory', (d) => db.replaceTerritory(d as Map<String, Object?>));
    await _referential('/referentials/trades', 'trades', (d) => db.replaceTrades(d as Map<String, Object?>));
    await _referential('/referentials/structure-types', 'structure_types', (d) => db.replaceStructureTypes(d as List));

    final since = db.kv('sync.cursor');
    ApiResponse r;
    try {
      r = await api.get('/sync/pull', query: {'since': since});
    } on ApiException catch (e) {
      if (e.status != 422 || since == null) rethrow;
      db.setKv('sync.cursor', null); // curseur illisible : synchronisation complète
      r = await api.get('/sync/pull');
    }
    applyPull(r.data as Map<String, Object?>, report);
    db.log('RECEPTION', '/sync/pull', r.status, 'OK');

    // Définitions de questionnaire manquantes (immuables : téléchargées une fois).
    for (final c in db.campaigns()) {
      final v = c['questionnaire_version'] as String;
      if (db.questionnaire(v) == null) {
        final q = await api.get('/questionnaires/$v');
        db.saveQuestionnaire(v, jsonEncode(q.data), q.headers['etag']);
      }
    }
    report.pulled = true;
    db.setKv('sync.last_success', DateTime.now().toUtc().toIso8601String());
  }

  Future<void> _referential(String path, String key, void Function(Object? data) save) async {
    final r = await api.get(path, etag: db.kv('sync.etag.$key'));
    if (r.status == 304) return;
    save(r.data);
    db.setKv('sync.etag.$key', r.headers['etag']);
  }

  /// Application idempotente d'une réponse /sync/pull (un élément peut revenir deux fois : remplacement par clé).
  void applyPull(Map<String, Object?> d, SyncReport report) {
    db.transaction(() {
      for (final c in ((d['campaigns'] as List?) ?? const []).cast<Map>()) {
        final before = db.campaign((c['id'] as num).toInt())?['questionnaire_version'];
        if (before != c['questionnaire_version'] && db.questionnaire('${c['questionnaire_version']}') == null
            && !report.questionnaireUpdates.contains('${c['questionnaire_version']}')) {
          report.questionnaireUpdates.add('${c['questionnaire_version']}'); // la définition est téléchargée juste après
        }
        db.upsertCampaign(c);
        if (before != null && before != c['questionnaire_version']) {
          report.migratedToNewVersion += db.migrateUnsentToVersion((c['id'] as num).toInt(), '${c['questionnaire_version']}');
        }
      }
      if (d['context'] is Map) db.setKv('sync.context', jsonEncode(d['context']));
      for (final a in ((d['assignments'] as List?) ?? const []).cast<Map>()) {
        db.upsertAssignment(a);
        report.assignmentsReceived++;
      }
      for (final cs in ((d['removed_assignments'] as List?) ?? const [])) {
        db.deactivateAssignment((cs as num).toInt());
      }
      for (final c in ((d['collections'] as List?) ?? const []).cast<Map>()) {
        _applyServerCollection(Map<String, Object?>.from(c), report);
      }
      db.setKv('sync.cursor', d['cursor'] as String?);
    });
  }

  void _applyServerCollection(Map<String, Object?> s, SyncReport report) {
    final uuid = s['uuid'] as String;
    final csId = (s['campaign_structure_id'] as num).toInt();
    final status = s['status'] as String;
    final revision = (s['revision'] as num).toInt();
    final editable = s['editable'] == true;
    final review = s['review'] is Map ? Map<String, Object?>.from(s['review'] as Map) : null;
    final serverData = s['data'] is Map ? Map<String, Object?>.from(s['data'] as Map) : null;

    final local = db.collection(uuid);
    final other = db.collectionByCs(csId);
    if (local == null && other != null) {
      // Une autre collecte locale existe pour la même structure : aucune n'est supprimée ; la locale est signalée.
      if (other['local_status'] != LocalStatus.enConflit) {
        db.setCollectionStatus(other['uuid'] as String, LocalStatus.enConflit, conflict: {
          'code': 'COLLECTION_EXISTS',
          'message': 'Une collecte existe déjà sur le serveur pour cette structure.',
          'details': {'uuid': uuid, 'status': status, 'revision': revision, 'data': serverData},
        });
        db.event(other['uuid'] as String, 'CONFLIT', 'COLLECTION_EXISTS ($uuid)');
        report.conflicts++;
      }
      return;
    }
    if (local == null) {
      db.insertCollection(
        uuid: uuid,
        csId: csId,
        campaignId: (s['campaign_id'] as num).toInt(),
        version: _versionOf((s['campaign_id'] as num).toInt()),
        data: serverData ?? const {},
        status: editable ? LocalStatus.invalidee : _mirror(status),
        serverStatus: status,
        serverRevision: revision,
        review: review,
      );
      db.event(uuid, 'RECU_DU_SERVEUR', status);
      if (editable) report.corrections++;
      return;
    }

    final localStatus = local['local_status'] as String;
    final localRevision = local['server_revision'] as int;
    switch (localStatus) {
      case LocalStatus.soumise:
      case LocalStatus.validee:
        if (editable && serverData != null) {
          // Invalidation ou réouverture reçue : la collecte redevient modifiable, avec le motif.
          db.replaceData(uuid, serverData);
          db.setCollectionStatus(uuid, LocalStatus.invalidee, serverStatus: status, serverRevision: revision, review: review);
          db.event(uuid, status == 'REOUVERTE' ? 'REOUVERTURE_RECUE' : 'INVALIDATION_RECUE', review?['reason']?.toString());
          report.corrections++;
        } else {
          db.setCollectionStatus(uuid, status == 'VALIDEE' ? LocalStatus.validee : LocalStatus.soumise,
              serverStatus: status, serverRevision: revision, review: review, serverIssues: _issuesOf(local));
        }
      case LocalStatus.brouillon:
      case LocalStatus.invalidee:
        if (revision > localRevision) {
          // Version plus récente reçue par le serveur (autre tablette) pendant la saisie : conflit, saisie conservée.
          db.setCollectionStatus(uuid, LocalStatus.enConflit, serverStatus: status, conflict: {
            'code': 'SERVER_NEWER',
            'message': 'Une version plus récente (révision $revision) a été reçue par le serveur pendant votre saisie.',
            'details': {'revision': revision, 'status': status, 'data': serverData},
          });
          db.event(uuid, 'CONFLIT', 'SERVER_NEWER révision $revision');
          report.conflicts++;
        } else {
          db.setCollectionStatus(uuid, localStatus, serverStatus: status, review: review, serverIssues: _issuesOf(local));
        }
      default:
        // TERMINEE (envoi en attente) ou EN_CONFLIT : seul le miroir de l'état serveur est mis à jour.
        db.execute('UPDATE collections SET server_status = ?, review_json = COALESCE(?, review_json) WHERE uuid = ?',
            [status, review == null ? null : jsonEncode(review), uuid]);
    }
  }

  Object? _issuesOf(Map<String, Object?> local) => local['server_issues_json'] == null ? null : jsonDecode(local['server_issues_json'] as String);

  String _versionOf(int campaignId) => (db.campaign(campaignId)?['questionnaire_version'] as String?) ?? '2025.1';

  static String _mirror(String serverStatus) => switch (serverStatus) {
        'VALIDEE' => LocalStatus.validee,
        'INVALIDEE' || 'REOUVERTE' => LocalStatus.invalidee,
        _ => LocalStatus.soumise,
      };

  // ------------------------------------------------------- Résolution des conflits

  Map<String, Object?> _conflictDetails(Map<String, Object?> col) {
    final c = col['conflict_json'] == null ? null : jsonDecode(col['conflict_json'] as String) as Map<String, Object?>;
    return c?['details'] is Map ? Map<String, Object?>.from(c!['details'] as Map) : {};
  }

  /// Remplacer la copie locale par la version du serveur (la copie locale est archivée dans le journal de la collecte).
  void takeServerVersion(String uuid) {
    final col = db.collection(uuid) ?? (throw StateError('Collecte inconnue'));
    final details = _conflictDetails(col);
    final data = details['data'];
    if (data is! Map) throw StateError("La version du serveur n'est pas disponible sur la tablette (collecte non modifiable côté serveur).");
    db.transaction(() {
      db.event(uuid, 'COPIE_LOCALE_ARCHIVEE', col['data_json'] as String);
      db.cancelPendingOutbox(uuid);
      db.replaceData(uuid, Map<String, Object?>.from(data));
      final status = (details['status'] ?? 'SOUMISE').toString();
      db.setCollectionStatus(uuid, _mirror(status), serverStatus: status, serverRevision: (details['revision'] as num?)?.toInt());
    });
  }

  /// Garder la copie locale et la reprendre comme correction de la révision serveur (le serveur décidera à l'envoi).
  void keepLocalVersion(String uuid) {
    final col = db.collection(uuid) ?? (throw StateError('Collecte inconnue'));
    final details = _conflictDetails(col);
    final serverRevision = (details['server_revision'] as num?)?.toInt() ?? (details['revision'] as num?)?.toInt() ?? col['server_revision'] as int;
    db.transaction(() {
      db.cancelPendingOutbox(uuid);
      db.setCollectionStatus(uuid, serverRevision > 0 ? LocalStatus.invalidee : LocalStatus.brouillon, serverRevision: serverRevision);
      db.event(uuid, 'CONFLIT_COPIE_LOCALE_GARDEE', 'révision de base $serverRevision');
    });
  }
}
