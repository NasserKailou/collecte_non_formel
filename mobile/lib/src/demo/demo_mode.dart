// Mode Démo (formation des agents) : utilisable sans compte ni réseau, de bout en bout (centres fictifs, création
// d'un centre, questionnaire complet, « Terminer », envoi simulé).
//
// Séparation stricte avec la collecte réelle :
//  - base locale DISTINCTE (fichier eftp_nf_demo.db, chiffrée comme la base réelle), jamais ouverte en mode normal ;
//  - aucun appel au serveur : la synchronisation est simulée sur la tablette (AppState.synchronize ne contacte pas
//    l'API en démo) ; aucun jeton n'est lu ni écrit ;
//  - référentiels et questionnaire embarqués dans l'application (assets/demo/demo_pack.json, produit par
//    backend/bin/export-demo-pack.php) ; campagne et centres fictifs, identifiés « DÉMO ».
// « Réinitialiser la démo » efface ce fichier ; la base réelle n'est jamais touchée.

import 'dart:convert';
import 'dart:io';

import 'package:flutter/services.dart' show rootBundle;

import '../data/local_db.dart';
import '../services/auth_service.dart';
import '../services/sync_service.dart';

abstract final class DemoMode {
  static const fileName = 'eftp_nf_demo.db';
  static const asset = 'assets/demo/demo_pack.json';
  static const campaignId = 1;

  static const profile = <String, Object?>{
    'uuid': 'demo-agent',
    'matricule': 'DEMO',
    'full_name': 'Agent de démonstration',
    'roles': ['AGENT'],
    'permissions': ['collection.submit'],
    'scopes': [
      {'level': 'NATIONAL'}
    ],
    'must_change_password': false,
    'demo': true,
  };

  static Session session() => Session(profile: Map<String, Object?>.of(profile), online: false);

  /// Ouvre la base de démonstration (créée et remplie au premier usage).
  static Future<LocalDb> open(String path, String keyHex) async {
    final db = LocalDb.open(path, keyHex);
    if (db.kv('demo.seeded') == null) {
      try {
        seed(db, jsonDecode(await rootBundle.loadString(asset)) as Map<String, Object?>);
      } catch (_) {
        db.close();
        rethrow;
      }
    }
    return db;
  }

  /// Supprime la base de démonstration (fichier, journal WAL et mémoire partagée).
  static void delete(String path) {
    for (final p in [path, '$path-wal', '$path-shm', '$path-journal']) {
      final f = File(p);
      if (f.existsSync()) f.deleteSync();
    }
  }

  /// Remplit une base vide avec le pack (public pour les tests).
  static void seed(LocalDb db, Map<String, Object?> pack) {
    final version = pack['questionnaire_version'] as String;
    db.transaction(() {
      db.replaceTerritory(Map<String, Object?>.from(pack['territory'] as Map));
      db.replaceTrades(Map<String, Object?>.from(pack['trades'] as Map));
      db.replaceStructureTypes(pack['structure_types'] as List);
      db.saveQuestionnaire(version, jsonEncode(pack['questionnaire']), null);
      final today = DateTime.now().toUtc();
      String day(DateTime d) => d.toIso8601String().substring(0, 10);
      db.upsertCampaign({
        'id': campaignId,
        'code': 'DEMO-FORMATION',
        'name': 'Formation — démonstration (données fictives)',
        'status': 'OUVERTE',
        'accepts_submissions': true,
        'opens_on': day(today.subtract(const Duration(days: 30))),
        'closes_on': day(today.add(const Duration(days: 3650))),
        'validation_levels': 2,
        'collection_mode': 'TABLETTE',
        'instructions': 'Mode démonstration : les saisies restent sur cette tablette et ne sont jamais envoyées au serveur.',
        'questionnaire_version': version,
      });
      var cs = 1;
      for (final s in (pack['structures'] as List).cast<Map>()) {
        db.upsertAssignment({'campaign_structure_id': cs++, 'campaign_id': campaignId, 'collection_uuid': null, 'structure': Map<String, Object?>.from(s)});
      }
      // Compte local fictif : sert seulement au périmètre (création de centres dans toutes les communes). Sans mot de passe.
      db.saveAccount(profile: Map<String, Object?>.of(profile), verifier: 'demo', salt: 'demo', offlinePolicy: const {'max_offline_days': 36500, 'max_unlock_failures': 10});
      db.setKv('demo.seeded', LocalDb.now());
      db.setKv('demo.pack_generated_at', '${pack['generated_at']}');
    });
  }

  /// « Synchronisation » de démonstration, entièrement locale : les centres créés reçoivent un identifiant
  /// définitif fictif, les collectes terminées passent à « Envoyée — en contrôle ». Aucune requête réseau.
  static SyncReport simulateSync(LocalDb db) {
    final report = SyncReport();
    db.transaction(() {
      for (final p in db.pendingStructures()) {
        final temp = p['temp_cs_id'] as int;
        final max = (db.one('SELECT MAX(campaign_structure_id) AS m FROM assignments')?['m'] as int?) ?? 0;
        final a = db.assignment(temp);
        final structure = a == null ? <String, Object?>{} : Map<String, Object?>.from(jsonDecode(a['structure_json'] as String) as Map);
        structure['status'] = 'PROPOSEE';
        db.markStructureSynced(p['uuid'] as String, max < 1 ? 1 : max + 1, structure, const []);
        db.log('ENVOI', 'DÉMO — centre', 201, 'SIMULE');
        report.centresCreated++;
      }
      for (final o in db.select("SELECT o.id, o.collection_uuid, c.server_revision FROM outbox o JOIN collections c ON c.uuid = o.collection_uuid "
          "WHERE o.state IN ('EN_ATTENTE', 'EN_COURS') AND c.campaign_structure_id > 0 ORDER BY o.id")) {
        final uuid = o['collection_uuid'] as String;
        db.outboxUpdate(o['id'] as int, OutboxState.synchronise, acked: true);
        db.setCollectionStatus(uuid, LocalStatus.soumise, serverStatus: 'SOUMISE', serverRevision: (o['server_revision'] as int) + 1);
        db.event(uuid, 'ENVOI_SIMULE', "mode démonstration : rien n'a été envoyé au serveur");
        db.log('ENVOI', 'DÉMO — collecte', 201, 'SIMULE');
        report.sent++;
      }
      db.execute("UPDATE attachments SET state = 'SYNCHRONISE' WHERE state <> 'SYNCHRONISE'");
      db.setKv('sync.last_success', LocalDb.now());
    });
    return report;
  }
}
