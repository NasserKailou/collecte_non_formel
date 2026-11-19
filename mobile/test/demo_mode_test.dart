// Mode Démo : pack embarqué valide, parcours de bout en bout (centre fictif, nouveau centre, Terminer, envoi simulé)
// et séparation stricte avec la base de la collecte réelle.

import 'dart:convert';
import 'dart:io';

import 'package:eftp_nf_collecte/src/data/local_db.dart';
import 'package:eftp_nf_collecte/src/demo/demo_mode.dart';
import 'package:eftp_nf_collecte/src/services/collection_service.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  late Directory dir;
  const key = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';
  final pack = jsonDecode(File('assets/demo/demo_pack.json').readAsStringSync()) as Map<String, Object?>;

  setUp(() => dir = Directory.systemTemp.createTempSync('eftp_demo_'));
  tearDown(() {
    try {
      dir.deleteSync(recursive: true);
    } catch (_) {}
  });

  test('le pack embarqué contient questionnaire, référentiels et centres fictifs « DÉMO »', () {
    expect(pack['questionnaire_version'], isNotEmpty);
    expect((pack['questionnaire'] as Map)['sections'], isNotEmpty);
    expect(((pack['territory'] as Map)['communes'] as List).length, greaterThan(100));
    expect(((pack['trades'] as Map)['trades'] as List), isNotEmpty);
    final structures = (pack['structures'] as List).cast<Map>();
    expect(structures, isNotEmpty);
    expect(structures.every((s) => '${s['name']}'.startsWith('DÉMO')), isTrue);
  });

  test('parcours complet hors ligne, sans toucher la base réelle', () {
    final real = LocalDb.open('${dir.path}/eftp_nf.db', key);
    real.setKv('marqueur', 'collecte réelle');

    final demoPath = '${dir.path}/${DemoMode.fileName}';
    final db = LocalDb.open(demoPath, key);
    DemoMode.seed(db, pack);
    final svc = CollectionService(db);
    expect(db.assignmentsWithState().length, (pack['structures'] as List).length);
    expect(db.allowedCommunes(), isNotEmpty, reason: 'périmètre national : création de centres partout');

    // 1. Collecte d'un centre fictif, terminée (saisie non bloquante : les questions vides sont des avertissements).
    final col = svc.openOrCreate(1);
    final r1 = svc.finish(col['uuid'] as String);
    expect(r1.valid, isTrue, reason: '${r1.errors.map((e) => e.message).toList()}');
    expect(db.collection(col['uuid'] as String)!['local_status'], LocalStatus.terminee);

    // 2. Nouveau centre créé « sur le terrain », puis terminé.
    final communeId = db.allowedCommunes().first['id'] as int;
    final type = db.structureTypes().firstWhere((t) => t['is_other'] == 0)['code'] as String;
    final created = svc.createFieldCentre(campaignId: DemoMode.campaignId, name: 'Atelier de formation test', typeCode: type,
        legalStatus: 'PRIVE', zone: 'URBAINE', communeId: communeId);
    expect(created['campaign_structure_id'] as int, lessThan(0), reason: 'identifiant provisoire avant « envoi »');
    expect(svc.finish(created['uuid'] as String).valid, isTrue);

    // 3. Envoi simulé : tout passe à « Envoyée », le centre reçoit un identifiant définitif fictif.
    final report = DemoMode.simulateSync(db);
    expect(report.sent, 2);
    expect(report.centresCreated, 1);
    expect(db.collection(col['uuid'] as String)!['local_status'], LocalStatus.soumise);
    final after = db.collection(created['uuid'] as String)!;
    expect(after['local_status'], LocalStatus.soumise);
    expect(after['campaign_structure_id'] as int, greaterThan(0));
    expect(db.outboxCounts()[OutboxState.enAttente], isNull);

    // Séparation : la base réelle n'a reçu aucune donnée de démonstration.
    expect(real.kv('marqueur'), 'collecte réelle');
    expect(real.select('SELECT * FROM collections'), isEmpty);
    expect(real.select('SELECT * FROM assignments'), isEmpty);
    expect(real.account(), isNull);

    // Réinitialisation : le fichier de démo disparaît, la base réelle reste.
    db.close();
    DemoMode.delete(demoPath);
    expect(File(demoPath).existsSync(), isFalse);
    expect(real.kv('marqueur'), 'collecte réelle');
    real.close();
  });
}
