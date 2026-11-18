// Base locale chiffrée (SQLCipher) — schéma de docs/mobile/README.md §1.
//
// La clé (256 bits, aléatoire) est conservée dans le Keystore Android (voir secure_store.dart) et passée ici en
// hexadécimal. L'ouverture échoue si la bibliothèque chargée n'est pas SQLCipher : une base en clair n'est jamais créée.

import 'dart:convert';

import 'package:sqlite3/sqlite3.dart';

class LocalDbException implements Exception {
  LocalDbException(this.message);
  final String message;
  @override
  String toString() => message;
}

/// États locaux d'une collecte (docs/mobile/README.md §1).
abstract final class LocalStatus {
  static const brouillon = 'BROUILLON';
  static const terminee = 'TERMINEE'; // contrôlée, dans la file d'envoi
  static const soumise = 'SOUMISE'; // accusé reçu du serveur
  static const invalidee = 'INVALIDEE'; // renvoyée pour correction
  static const enConflit = 'EN_CONFLIT';
  static const validee = 'VALIDEE';
}

/// États d'un élément de la file d'envoi (affichés à l'agent).
abstract final class OutboxState {
  static const enAttente = 'EN_ATTENTE';
  static const enCours = 'EN_COURS';
  static const synchronise = 'SYNCHRONISE';
  static const echec = 'ECHEC'; // refus définitif (422) : correction nécessaire
  static const conflit = 'CONFLIT'; // 409/423 : décision humaine
}

class LocalDb {
  LocalDb._(this._db);

  final Database _db;

  static const schemaVersion = 2;

  /// Ouvre (ou crée) la base chiffrée. [keyHex] : 64 caractères hexadécimaux.
  static LocalDb open(String path, String keyHex) {
    if (!RegExp(r'^[0-9a-f]{64}$').hasMatch(keyHex)) throw LocalDbException('Clé de chiffrement invalide.');
    final db = sqlite3.open(path);
    try {
      final cipher = db.select('PRAGMA cipher_version');
      if (cipher.isEmpty || (cipher.first.values.first?.toString() ?? '').isEmpty) {
        throw LocalDbException('SQLCipher indisponible : la base locale ne peut pas être chiffrée.');
      }
      db.execute("PRAGMA key = \"x'$keyHex'\"");
      // Vérifie la clé (une mauvaise clé fait échouer la première lecture).
      db.select('SELECT count(*) FROM sqlite_master');
      db.execute('PRAGMA foreign_keys = ON');
      db.execute('PRAGMA journal_mode = WAL');
      final local = LocalDb._(db).._migrate();
      return local;
    } catch (e) {
      db.close();
      if (e is LocalDbException) rethrow;
      throw LocalDbException('Base locale illisible (clé incorrecte ou fichier endommagé) : $e');
    }
  }

  void close() => _db.close();

  void _migrate() {
    final v = _db.userVersion;
    if (v > schemaVersion) throw LocalDbException('Base locale créée par une version plus récente de l\'application.');
    if (v < 1) {
      transaction(() {
        _db.execute('''
          CREATE TABLE account (
            user_uuid TEXT PRIMARY KEY, matricule TEXT NOT NULL, full_name TEXT NOT NULL, profile_json TEXT NOT NULL,
            offline_verifier TEXT NOT NULL, verifier_salt TEXT NOT NULL, offline_policy_json TEXT NOT NULL,
            last_online_login_at TEXT NOT NULL, unlock_failures INTEGER NOT NULL DEFAULT 0);
          CREATE TABLE kv (key TEXT PRIMARY KEY, value TEXT);
          CREATE TABLE regions (id INTEGER PRIMARY KEY, code TEXT, name TEXT NOT NULL);
          CREATE TABLE departments (id INTEGER PRIMARY KEY, region_id INTEGER NOT NULL, code TEXT, name TEXT NOT NULL);
          CREATE TABLE communes (id INTEGER PRIMARY KEY, department_id INTEGER NOT NULL, code TEXT, name TEXT NOT NULL,
            centroid_lat REAL, centroid_lng REAL);
          CREATE TABLE sectors (id INTEGER PRIMARY KEY, code TEXT, label TEXT NOT NULL);
          CREATE TABLE trades (id INTEGER PRIMARY KEY, sector_id INTEGER NOT NULL, label TEXT NOT NULL);
          CREATE TABLE structure_types (code TEXT PRIMARY KEY, label TEXT NOT NULL, is_other INTEGER NOT NULL DEFAULT 0);
          CREATE TABLE questionnaires (version TEXT PRIMARY KEY, definition_json TEXT NOT NULL, etag TEXT);
          CREATE TABLE campaigns (
            id INTEGER PRIMARY KEY, code TEXT NOT NULL, name TEXT NOT NULL, status TEXT NOT NULL, accepts_submissions INTEGER NOT NULL,
            opens_on TEXT, closes_on TEXT, validation_levels INTEGER, collection_mode TEXT NOT NULL DEFAULT 'TABLETTE', instructions TEXT, questionnaire_version TEXT NOT NULL);
          CREATE TABLE assignments (
            campaign_structure_id INTEGER PRIMARY KEY, campaign_id INTEGER NOT NULL, structure_uuid TEXT NOT NULL,
            structure_json TEXT NOT NULL, collection_uuid TEXT, active INTEGER NOT NULL DEFAULT 1);
          CREATE TABLE collections (
            uuid TEXT PRIMARY KEY, campaign_structure_id INTEGER NOT NULL UNIQUE, campaign_id INTEGER NOT NULL,
            questionnaire_version TEXT NOT NULL, local_status TEXT NOT NULL, server_status TEXT, server_revision INTEGER NOT NULL DEFAULT 0,
            data_json TEXT NOT NULL, errors_count INTEGER NOT NULL DEFAULT 0, warnings_count INTEGER NOT NULL DEFAULT 0,
            review_json TEXT, server_issues_json TEXT, conflict_json TEXT,
            started_at TEXT NOT NULL, completed_at TEXT, updated_at TEXT NOT NULL);
          CREATE TABLE collection_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT, collection_uuid TEXT NOT NULL, event TEXT NOT NULL, detail TEXT, at TEXT NOT NULL);
          CREATE TABLE outbox (
            id INTEGER PRIMARY KEY AUTOINCREMENT, collection_uuid TEXT NOT NULL, base_revision INTEGER NOT NULL,
            payload_json TEXT NOT NULL, payload_sha256 TEXT NOT NULL, state TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
            next_attempt_at TEXT, last_error TEXT, created_at TEXT NOT NULL, acked_at TEXT);
          CREATE INDEX ix_outbox_state ON outbox (state, next_attempt_at);
          CREATE TABLE attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT, collection_uuid TEXT NOT NULL, local_path TEXT NOT NULL, kind TEXT NOT NULL,
            state TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, last_error TEXT, created_at TEXT NOT NULL);
          CREATE TABLE sync_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT, direction TEXT NOT NULL, endpoint TEXT NOT NULL, http_status INTEGER,
            outcome TEXT NOT NULL, at TEXT NOT NULL);
        ''');
        _db.userVersion = 1;
      });
    }
    if (_db.userVersion < 2) {
      // v2 : centres découverts sur le terrain. Créés hors ligne avec un identifiant d'inscription provisoire (négatif),
      // envoyés au serveur avant leur collecte ; l'identifiant définitif remplace alors le provisoire partout.
      transaction(() {
        _db.execute('''
          CREATE TABLE pending_structures (
            uuid TEXT PRIMARY KEY, campaign_id INTEGER NOT NULL, temp_cs_id INTEGER NOT NULL UNIQUE, payload_json TEXT NOT NULL,
            state TEXT NOT NULL, server_cs_id INTEGER, last_error TEXT, duplicates_json TEXT, created_at TEXT NOT NULL);
        ''');
        _db.userVersion = 2;
      });
    }
  }

  // ----------------------------------------------------------------- Outils

  int _txDepth = 0;

  /// Transaction ; un appel imbriqué s'exécute dans la transaction englobante (tout ou rien).
  T transaction<T>(T Function() body) {
    if (_txDepth > 0) return body();
    _db.execute('BEGIN IMMEDIATE');
    _txDepth++;
    try {
      final r = body();
      _db.execute('COMMIT');
      return r;
    } catch (_) {
      _db.execute('ROLLBACK');
      rethrow;
    } finally {
      _txDepth--;
    }
  }

  List<Map<String, Object?>> select(String sql, [List<Object?> params = const []]) =>
      _db.select(sql, params).map((r) => Map<String, Object?>.from(r)).toList();

  Map<String, Object?>? one(String sql, [List<Object?> params = const []]) {
    final r = select(sql, params);
    return r.isEmpty ? null : r.first;
  }

  void execute(String sql, [List<Object?> params = const []]) => _db.execute(sql, params);

  int get lastInsertId => _db.lastInsertRowId;

  static String now() => DateTime.now().toUtc().toIso8601String();

  // --------------------------------------------------------------------- kv

  String? kv(String key) => one('SELECT value FROM kv WHERE key = ?', [key])?['value'] as String?;

  void setKv(String key, String? value) {
    if (value == null) {
      execute('DELETE FROM kv WHERE key = ?', [key]);
    } else {
      execute('INSERT INTO kv (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [key, value]);
    }
  }

  Map<String, Object?>? kvJson(String key) {
    final v = kv(key);
    return v == null ? null : jsonDecode(v) as Map<String, Object?>;
  }

  // ----------------------------------------------------------------- Compte

  Map<String, Object?>? account() => one('SELECT * FROM account LIMIT 1');

  void saveAccount({
    required Map<String, Object?> profile,
    required String verifier,
    required String salt,
    required Map<String, Object?> offlinePolicy,
  }) {
    transaction(() {
      execute('DELETE FROM account');
      execute(
          'INSERT INTO account (user_uuid, matricule, full_name, profile_json, offline_verifier, verifier_salt, offline_policy_json, last_online_login_at, unlock_failures) '
          'VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)',
          [profile['uuid'], profile['matricule'], profile['full_name'], jsonEncode(profile), verifier, salt, jsonEncode(offlinePolicy), now()]);
    });
  }

  /// Données locales non encore acceptées par le serveur (brouillons, file d'envoi, conflits, pièces jointes).
  int pendingWorkCount() {
    final c = one("SELECT count(*) AS n FROM collections WHERE local_status IN ('BROUILLON', 'TERMINEE', 'INVALIDEE', 'EN_CONFLIT')")!['n'] as int;
    final o = one("SELECT count(*) AS n FROM outbox WHERE state NOT IN ('SYNCHRONISE', 'ANNULE')")!['n'] as int;
    final a = one("SELECT count(*) AS n FROM attachments WHERE state <> 'SYNCHRONISE'")!['n'] as int;
    return c + o + a;
  }

  /// Efface les données d'un autre compte (uniquement si rien n'est en attente : vérifié par l'appelant).
  void wipeUserData() {
    transaction(() {
      for (final t in ['account', 'campaigns', 'assignments', 'collections', 'collection_events', 'outbox', 'attachments', 'sync_log', 'pending_structures']) {
        execute('DELETE FROM $t');
      }
      execute("DELETE FROM kv WHERE key LIKE 'sync.%'");
    });
  }

  // ------------------------------------------------------------ Référentiels

  void replaceTerritory(Map<String, Object?> t) => transaction(() {
        execute('DELETE FROM communes');
        execute('DELETE FROM departments');
        execute('DELETE FROM regions');
        for (final r in (t['regions'] as List).cast<Map>()) {
          execute('INSERT INTO regions (id, code, name) VALUES (?, ?, ?)', [r['id'], r['code'], r['name']]);
        }
        for (final d in (t['departments'] as List).cast<Map>()) {
          execute('INSERT INTO departments (id, region_id, code, name) VALUES (?, ?, ?, ?)', [d['id'], d['region_id'], d['code'], d['name']]);
        }
        for (final c in (t['communes'] as List).cast<Map>()) {
          execute('INSERT INTO communes (id, department_id, code, name, centroid_lat, centroid_lng) VALUES (?, ?, ?, ?, ?, ?)',
              [c['id'], c['department_id'], c['code'], c['name'], _num(c['centroid_lat']), _num(c['centroid_lng'])]);
        }
      });

  void replaceTrades(Map<String, Object?> t) => transaction(() {
        execute('DELETE FROM trades');
        execute('DELETE FROM sectors');
        for (final s in (t['sectors'] as List).cast<Map>()) {
          execute('INSERT INTO sectors (id, code, label) VALUES (?, ?, ?)', [s['id'], s['code'], s['label']]);
        }
        for (final tr in (t['trades'] as List).cast<Map>()) {
          execute('INSERT INTO trades (id, sector_id, label) VALUES (?, ?, ?)', [tr['id'], tr['sector_id'], tr['label']]);
        }
      });

  void replaceStructureTypes(List t) => transaction(() {
        execute('DELETE FROM structure_types');
        for (final s in t.cast<Map>()) {
          execute('INSERT INTO structure_types (code, label, is_other) VALUES (?, ?, ?)', [s['code'], s['label'], s['is_other'] == true || s['is_other'] == 1 ? 1 : 0]);
        }
      });

  static double? _num(Object? v) => v == null ? null : (v is num ? v.toDouble() : double.tryParse(v.toString()));

  String? regionName(int? id) => id == null ? null : one('SELECT name FROM regions WHERE id = ?', [id])?['name'] as String?;
  String? departmentName(int? id) => id == null ? null : one('SELECT name FROM departments WHERE id = ?', [id])?['name'] as String?;
  String? communeName(int? id) => id == null ? null : one('SELECT name FROM communes WHERE id = ?', [id])?['name'] as String?;

  List<Map<String, Object?>> tradesOfSector(int sectorId) => select('SELECT id, label FROM trades WHERE sector_id = ? ORDER BY label', [sectorId]);

  // ---------------------------------------------------------- Questionnaires

  String? questionnaire(String version) => one('SELECT definition_json FROM questionnaires WHERE version = ?', [version])?['definition_json'] as String?;

  void saveQuestionnaire(String version, String json, String? etag) => execute(
      'INSERT INTO questionnaires (version, definition_json, etag) VALUES (?, ?, ?) '
      'ON CONFLICT(version) DO UPDATE SET definition_json = excluded.definition_json, etag = excluded.etag',
      [version, json, etag]);

  // ------------------------------------------------- Campagnes / affectations

  void upsertCampaign(Map c) => execute(
      'INSERT INTO campaigns (id, code, name, status, accepts_submissions, opens_on, closes_on, validation_levels, collection_mode, instructions, questionnaire_version) '
      'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(id) DO UPDATE SET code = excluded.code, name = excluded.name, status = excluded.status, '
      'accepts_submissions = excluded.accepts_submissions, opens_on = excluded.opens_on, closes_on = excluded.closes_on, '
      'validation_levels = excluded.validation_levels, collection_mode = excluded.collection_mode, instructions = excluded.instructions, '
      'questionnaire_version = excluded.questionnaire_version',
      [c['id'], c['code'], c['name'], c['status'], c['accepts_submissions'] == true ? 1 : 0, c['opens_on'], c['closes_on'], c['validation_levels'],
        c['collection_mode'] ?? 'TABLETTE', c['instructions'], c['questionnaire_version']]);

  List<Map<String, Object?>> campaigns() => select('SELECT * FROM campaigns ORDER BY opens_on DESC');

  Map<String, Object?>? campaign(int id) => one('SELECT * FROM campaigns WHERE id = ?', [id]);

  void upsertAssignment(Map a) {
    final s = a['structure'] as Map;
    execute(
        'INSERT INTO assignments (campaign_structure_id, campaign_id, structure_uuid, structure_json, collection_uuid, active) VALUES (?, ?, ?, ?, ?, 1) '
        'ON CONFLICT(campaign_structure_id) DO UPDATE SET campaign_id = excluded.campaign_id, structure_uuid = excluded.structure_uuid, '
        'structure_json = excluded.structure_json, collection_uuid = COALESCE(excluded.collection_uuid, assignments.collection_uuid), active = 1',
        [a['campaign_structure_id'], a['campaign_id'], s['uuid'], jsonEncode(s), a['collection_uuid']]);
  }

  /// Affectation retirée : masquée, mais une collecte locale non synchronisée n'est jamais supprimée.
  void deactivateAssignment(int csId) => execute('UPDATE assignments SET active = 0 WHERE campaign_structure_id = ?', [csId]);

  /// Affectations actives (ou portant une collecte locale à conserver), avec l'état de la collecte et de la file.
  List<Map<String, Object?>> assignmentsWithState() => select('''
      SELECT a.*, c.uuid AS col_uuid, c.local_status, c.server_status, c.errors_count, c.warnings_count, c.updated_at AS col_updated_at,
             (SELECT o.state FROM outbox o WHERE o.collection_uuid = c.uuid AND o.state <> 'ANNULE' ORDER BY o.id DESC LIMIT 1) AS outbox_state,
             (SELECT o.last_error FROM outbox o WHERE o.collection_uuid = c.uuid AND o.state <> 'ANNULE' ORDER BY o.id DESC LIMIT 1) AS outbox_error,
             ps.state AS centre_state, ps.last_error AS centre_error, ps.duplicates_json AS centre_duplicates
        FROM assignments a LEFT JOIN collections c ON c.campaign_structure_id = a.campaign_structure_id
        LEFT JOIN pending_structures ps ON ps.temp_cs_id = a.campaign_structure_id OR ps.server_cs_id = a.campaign_structure_id
       WHERE a.active = 1 OR (c.uuid IS NOT NULL AND c.local_status IN ('BROUILLON', 'TERMINEE', 'INVALIDEE', 'EN_CONFLIT'))
       ORDER BY a.campaign_id DESC, json_extract(a.structure_json, '\$.name')''');

  /// Types de structure du référentiel (pour la création d'un centre sur le terrain).
  List<Map<String, Object?>> structureTypes() => select('SELECT code, label, is_other FROM structure_types ORDER BY is_other, label');

  Map<String, Object?>? assignment(int csId) => one('SELECT * FROM assignments WHERE campaign_structure_id = ?', [csId]);

  // -------------------------------------------------------------- Collectes

  Map<String, Object?>? collectionByCs(int csId) => one('SELECT * FROM collections WHERE campaign_structure_id = ?', [csId]);

  Map<String, Object?>? collection(String uuid) => one('SELECT * FROM collections WHERE uuid = ?', [uuid]);

  void insertCollection({
    required String uuid,
    required int csId,
    required int campaignId,
    required String version,
    required Map<String, Object?> data,
    String status = LocalStatus.brouillon,
    String? serverStatus,
    int serverRevision = 0,
    Map<String, Object?>? review,
  }) {
    final t = now();
    execute(
        'INSERT INTO collections (uuid, campaign_structure_id, campaign_id, questionnaire_version, local_status, server_status, server_revision, data_json, review_json, started_at, updated_at) '
        'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [uuid, csId, campaignId, version, status, serverStatus, serverRevision, jsonEncode(data), review == null ? null : jsonEncode(review), t, t]);
    execute('UPDATE assignments SET collection_uuid = ? WHERE campaign_structure_id = ?', [uuid, csId]);
  }

  /// Sauvegarde automatique d'une saisie (une écriture par modification ; seules les collectes modifiables sont touchées).
  void saveDraft(String uuid, Map<String, Object?> data, int errors, int warnings) => execute(
      'UPDATE collections SET data_json = ?, errors_count = ?, warnings_count = ?, updated_at = ? WHERE uuid = ? AND local_status IN (?, ?)',
      [jsonEncode(data), errors, warnings, now(), uuid, LocalStatus.brouillon, LocalStatus.invalidee]);

  void setCollectionStatus(String uuid, String status, {String? serverStatus, int? serverRevision, Object? serverIssues, Object? conflict, Object? review, bool completed = false}) {
    execute(
        'UPDATE collections SET local_status = ?, server_status = COALESCE(?, server_status), server_revision = COALESCE(?, server_revision), '
        'server_issues_json = ?, conflict_json = ?, review_json = COALESCE(?, review_json), completed_at = CASE WHEN ? THEN ? ELSE completed_at END, updated_at = ? WHERE uuid = ?',
        [status, serverStatus, serverRevision, serverIssues == null ? null : jsonEncode(serverIssues), conflict == null ? null : jsonEncode(conflict),
          review == null ? null : jsonEncode(review), completed ? 1 : 0, now(), now(), uuid]);
  }

  /// Remplace les données locales (collecte renvoyée pour correction depuis le serveur).
  void replaceData(String uuid, Map<String, Object?> data) =>
      execute('UPDATE collections SET data_json = ?, updated_at = ? WHERE uuid = ?', [jsonEncode(data), now(), uuid]);

  void event(String uuid, String event, [String? detail]) =>
      execute('INSERT INTO collection_events (collection_uuid, event, detail, at) VALUES (?, ?, ?, ?)', [uuid, event, detail, now()]);

  List<Map<String, Object?>> events(String uuid) => select('SELECT * FROM collection_events WHERE collection_uuid = ? ORDER BY id DESC', [uuid]);

  // ---------------------------------------------------------- File d'envoi

  void enqueue(String uuid, int baseRevision, String payloadJson, String sha256) => execute(
      'INSERT INTO outbox (collection_uuid, base_revision, payload_json, payload_sha256, state, created_at, next_attempt_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
      [uuid, baseRevision, payloadJson, sha256, OutboxState.enAttente, now(), now()]);

  /// Prochain élément à envoyer (le plus ancien dont l'échéance est passée), ou null.
  /// Une collecte d'un centre créé sur le terrain attend que le centre soit enregistré (identifiant définitif > 0).
  Map<String, Object?>? nextOutbox({bool ignoreSchedule = false}) => one(
      "SELECT * FROM outbox WHERE state IN ('EN_ATTENTE', 'EN_COURS') ${ignoreSchedule ? '' : 'AND (next_attempt_at IS NULL OR next_attempt_at <= ?)'} "
      'AND collection_uuid NOT IN (SELECT uuid FROM collections WHERE campaign_structure_id < 0) ORDER BY id LIMIT 1',
      ignoreSchedule ? const [] : [now()]);

  // ------------------------------------------------- Centres créés sur le terrain

  /// La campagne a changé de version de questionnaire (aucune collecte encore reçue par le serveur) : les collectes
  /// locales jamais acceptées basculent sur la nouvelle version ; une collecte terminée mais non envoyée redevient un
  /// brouillon à vérifier puis terminer de nouveau. Les données saisies sont conservées. Renvoie le nombre de collectes.
  int migrateUnsentToVersion(int campaignId, String version) {
    final rows = select("SELECT uuid, local_status FROM collections WHERE campaign_id = ? AND questionnaire_version <> ? AND server_revision = 0 "
        "AND local_status IN ('BROUILLON', 'TERMINEE', 'EN_CONFLIT')", [campaignId, version]);
    transaction(() {
      for (final r in rows) {
        final uuid = r['uuid'] as String;
        cancelPendingOutbox(uuid);
        execute('UPDATE collections SET questionnaire_version = ?, local_status = ?, conflict_json = NULL WHERE uuid = ?', [version, LocalStatus.brouillon, uuid]);
        event(uuid, 'QUESTIONNAIRE_MIS_A_JOUR', 'version $version : vérifier puis terminer de nouveau');
      }
    });
    return rows.length;
  }

  /// Identifiant d'inscription provisoire (négatif, unique sur la tablette).
  int nextTempCsId() => ((one('SELECT MIN(campaign_structure_id) AS m FROM assignments')?['m'] as int?) ?? 0).clamp(-1 << 40, 0) - 1;

  void addPendingStructure({required String uuid, required int campaignId, required int tempCsId, required Map<String, Object?> payload}) => execute(
      'INSERT INTO pending_structures (uuid, campaign_id, temp_cs_id, payload_json, state, created_at) VALUES (?, ?, ?, ?, ?, ?)',
      [uuid, campaignId, tempCsId, jsonEncode(payload), OutboxState.enAttente, now()]);

  List<Map<String, Object?>> pendingStructures() =>
      select("SELECT * FROM pending_structures WHERE state IN ('EN_ATTENTE', 'ECHEC') ORDER BY created_at");

  Map<String, Object?>? pendingStructureByCs(int csId) => one('SELECT * FROM pending_structures WHERE temp_cs_id = ? OR server_cs_id = ?', [csId, csId]);

  /// Centre enregistré par le serveur : l'identifiant définitif remplace le provisoire (affectation, collecte, file d'envoi).
  void markStructureSynced(String uuid, int serverCsId, Map<String, Object?> structure, List<Object?> duplicates) {
    final p = one('SELECT * FROM pending_structures WHERE uuid = ?', [uuid]);
    if (p == null) return;
    final temp = p['temp_cs_id'] as int;
    transaction(() {
      final already = one('SELECT campaign_structure_id FROM assignments WHERE campaign_structure_id = ?', [serverCsId]);
      if (already != null && serverCsId != temp) execute('DELETE FROM assignments WHERE campaign_structure_id = ?', [serverCsId]); // reçu entre-temps par /sync/pull
      execute('UPDATE assignments SET campaign_structure_id = ?, structure_json = ? WHERE campaign_structure_id = ?', [serverCsId, jsonEncode(structure), temp]);
      execute('UPDATE collections SET campaign_structure_id = ? WHERE campaign_structure_id = ?', [serverCsId, temp]);
      execute(r"UPDATE outbox SET payload_json = json_set(payload_json, '$.campaign_structure_id', ?) WHERE collection_uuid IN (SELECT uuid FROM collections WHERE campaign_structure_id = ?)",
          [serverCsId, serverCsId]);
      execute('UPDATE pending_structures SET state = ?, server_cs_id = ?, last_error = NULL, duplicates_json = ? WHERE uuid = ?',
          [OutboxState.synchronise, serverCsId, duplicates.isEmpty ? null : jsonEncode(duplicates), uuid]);
    });
  }

  void markStructureFailed(String uuid, String error) =>
      execute('UPDATE pending_structures SET state = ?, last_error = ? WHERE uuid = ?', [OutboxState.echec, error, uuid]);

  /// Communes où l'agent peut créer un centre : celles de son périmètre (commune, département ou région).
  List<Map<String, Object?>> allowedCommunes() {
    final a = account();
    if (a == null) return const [];
    final scopes = ((jsonDecode(a['profile_json'] as String) as Map)['scopes'] as List? ?? const []).cast<Map>();
    final ids = <int>{};
    for (final s in scopes) {
      if (s['level'] == 'NATIONAL') {
        return select('SELECT c.id, c.name, d.name AS department FROM communes c JOIN departments d ON d.id = c.department_id ORDER BY c.name');
      }
      final rows = switch (s['level']) {
        'COMMUNE' => select('SELECT id FROM communes WHERE id = ?', [s['commune_id']]),
        'DEPARTMENT' => select('SELECT id FROM communes WHERE department_id = ?', [s['department_id']]),
        'REGION' => select('SELECT c.id FROM communes c JOIN departments d ON d.id = c.department_id WHERE d.region_id = ?', [s['region_id']]),
        _ => const <Map<String, Object?>>[],
      };
      ids.addAll(rows.map((r) => r['id'] as int));
    }
    if (ids.isEmpty) return const [];
    return select('SELECT c.id, c.name, d.name AS department FROM communes c JOIN departments d ON d.id = c.department_id '
        'WHERE c.id IN (${List.filled(ids.length, '?').join(',')}) ORDER BY c.name', ids.toList());
  }

  void outboxUpdate(int id, String state, {String? error, DateTime? nextAttempt, bool incrementAttempts = false, bool acked = false}) => execute(
      'UPDATE outbox SET state = ?, last_error = ?, next_attempt_at = ?, attempts = attempts + ?, acked_at = CASE WHEN ? THEN ? ELSE acked_at END WHERE id = ?',
      [state, error, nextAttempt?.toUtc().toIso8601String(), incrementAttempts ? 1 : 0, acked ? 1 : 0, now(), id]);

  /// Annule les envois non aboutis d'une collecte (remplacés par une nouvelle version).
  void cancelPendingOutbox(String uuid) =>
      execute("UPDATE outbox SET state = 'ANNULE' WHERE collection_uuid = ? AND state IN ('EN_ATTENTE', 'ECHEC', 'CONFLIT')", [uuid]);

  Map<String, int> outboxCounts() {
    final out = <String, int>{};
    for (final r in select("SELECT state, count(*) AS n FROM outbox WHERE state <> 'ANNULE' GROUP BY state")) {
      out[r['state'] as String] = r['n'] as int;
    }
    return out;
  }

  // ----------------------------------------------------------- Pièces jointes

  void addAttachment(String uuid, String path, String kind) => execute(
      'INSERT INTO attachments (collection_uuid, local_path, kind, state, created_at) VALUES (?, ?, ?, ?, ?)', [uuid, path, kind, OutboxState.enAttente, now()]);

  /// Pièces jointes prêtes : la collecte doit avoir été acceptée par le serveur.
  List<Map<String, Object?>> pendingAttachments() => select('''
      SELECT a.* FROM attachments a JOIN collections c ON c.uuid = a.collection_uuid
       WHERE a.state IN ('EN_ATTENTE', 'EN_COURS') AND c.server_revision > 0 ORDER BY a.id''');

  List<Map<String, Object?>> attachmentsOf(String uuid) => select('SELECT * FROM attachments WHERE collection_uuid = ? ORDER BY id', [uuid]);

  void attachmentUpdate(int id, String state, {String? error}) =>
      execute('UPDATE attachments SET state = ?, last_error = ?, attempts = attempts + 1 WHERE id = ?', [state, error, id]);

  // ---------------------------------------------------------------- Journal

  void log(String direction, String endpoint, int? status, String outcome) {
    execute('INSERT INTO sync_log (direction, endpoint, http_status, outcome, at) VALUES (?, ?, ?, ?, ?)', [direction, endpoint, status, outcome, now()]);
    execute('DELETE FROM sync_log WHERE id NOT IN (SELECT id FROM sync_log ORDER BY id DESC LIMIT 500)');
  }

  List<Map<String, Object?>> syncLog({int limit = 100}) => select('SELECT * FROM sync_log ORDER BY id DESC LIMIT ?', [limit]);
}
