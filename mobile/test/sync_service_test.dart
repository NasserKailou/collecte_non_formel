// Cycle complet sur la tablette avec un faux serveur : Terminer → envoi accepté / rejoué / réseau coupé / refusé /
// en conflit, puis invalidation reçue. Base chiffrée réelle et définition 2025.1 réelle ; données fictives.

import 'dart:convert';
import 'dart:io';

import 'package:eftp_nf_collecte/src/data/local_db.dart';
import 'package:eftp_nf_collecte/src/net/api_client.dart';
import 'package:eftp_nf_collecte/src/services/collection_service.dart';
import 'package:eftp_nf_collecte/src/services/sync_service.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

class _Tokens implements TokenProvider {
  @override
  Future<String?> accessToken() async => 'jeton-test';
  @override
  Future<String?> refreshToken() async => null;
  @override
  Future<void> store(String? a, String? r) async {}
}

final _shared = '${Directory.current.path}/../shared';
const _key = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

void main() {
  late Directory dir;
  late LocalDb db;
  late CollectionService collections;
  late Map<String, Object?> base;
  late List<http.Request> requests;
  late http.Response Function(http.Request) server;

  SyncService sync() => SyncService(
      db: db,
      api: ApiClient(
          tokens: _Tokens(),
          deviceUid: 'appareil-test-0001',
          baseUrl: 'https://serveur.test/api/v1',
          client: MockClient((req) async {
            requests.add(req);
            return server(req);
          })));

  http.Response json(int status, Object body) => http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});

  setUp(() {
    dir = Directory.systemTemp.createTempSync('eftp_sync_');
    db = LocalDb.open('${dir.path}/s.db', _key);
    requests = [];
    final fixture = jsonDecode(File('$_shared/rules-fixtures/eftp-nf-2025.1.json').readAsStringSync()) as Map<String, Object?>;
    base = Map<String, Object?>.from(fixture['base'] as Map)..remove('_ctx');
    db.saveQuestionnaire('2025.1', File('$_shared/questionnaire/eftp-nf-2025.1.json').readAsStringSync(), null);
    db.setKv('sync.context', jsonEncode((fixture['base'] as Map)['_ctx']));
    db.upsertCampaign({'id': 7, 'code': 'TEST', 'name': 'Campagne de test', 'status': 'OUVERTE', 'accepts_submissions': true,
      'opens_on': '2026-01-05', 'closes_on': '2026-03-31', 'validation_levels': 2, 'questionnaire_version': '2025.1'});
    db.upsertAssignment({'campaign_structure_id': 812, 'campaign_id': 7, 'collection_uuid': null, 'structure': {
      'uuid': 's-1', 'official_code': base['Q1_2'], 'name': base['Q1_1'], 'region_id': 1, 'department_id': 101, 'commune_id': 10101,
      'type_code': base['Q1_9'], 'zone': base['Q1_7'], 'legal_status': base['Q1_8'], 'locality_label': base['Q1_6_4']}});
    collections = CollectionService(db, clock: () => DateTime.utc(2026, 2, 15, 10));
  });

  tearDown(() {
    db.close();
    try {
      dir.deleteSync(recursive: true);
    } catch (_) {}
  });

  /// Crée la collecte, saisit la collecte fictive de référence (sans anomalie) et la termine.
  String finishedCollection() {
    final col = collections.openOrCreate(812);
    final data = {...collections.dataOf(col), ...base};
    collections.save(col, data);
    final r = collections.finish(col['uuid'] as String);
    expect(r.valid, isTrue, reason: r.errors.map((e) => '${e.code} @ ${e.target}').join(', '));
    return col['uuid'] as String;
  }

  test('pré-remplissage depuis le registre', () {
    final col = collections.openOrCreate(812);
    final data = collections.dataOf(col);
    expect(data['Q1_1'], base['Q1_1']);
    expect(data['Q1_6_3'], 10101);
    expect(data['Q5_DATE'], '2026-02-15', reason: "date du jour à l'heure du Niger");
  });

  test('envoi accepté : la collecte est « Soumise » seulement après l\'accusé', () async {
    final uuid = finishedCollection();
    expect(db.collection(uuid)!['local_status'], LocalStatus.terminee);
    final payload = jsonDecode(db.nextOutbox()!['payload_json'] as String) as Map;
    expect(payload['base_revision'], 0);
    expect((payload['data'] as Map).containsKey('APPRENTICES_TOTAL'), isFalse, reason: 'variables calculées non envoyées');

    server = (req) => req.url.path.endsWith('/submissions')
        ? json(201, {'data': {'uuid': uuid, 'status': 'SOUMISE', 'revision': 1, 'warnings': []}})
        : json(500, {'error': {'code': 'X', 'message': 'non prévu'}});
    final report = SyncReport();
    await sync().pushOutbox(report);
    expect(report.sent, 1);
    final col = db.collection(uuid)!;
    expect(col['local_status'], LocalStatus.soumise);
    expect(col['server_revision'], 1);
    expect(requests.single.headers['X-Device-Id'], 'appareil-test-0001');
    expect(requests.single.headers['Authorization'], 'Bearer jeton-test');
    expect(db.nextOutbox(ignoreSchedule: true), isNull);
  });

  test('réseau coupé : rien n\'est perdu, nouvel essai programmé', () async {
    final uuid = finishedCollection();
    server = (req) => throw const SocketException('réseau absent');
    final report = await sync().synchronize();
    expect(report.ok, isFalse);
    expect(report.retryLater, 1);
    expect(db.collection(uuid)!['local_status'], LocalStatus.terminee);
    final item = db.nextOutbox(ignoreSchedule: true)!;
    expect(item['state'], OutboxState.enAttente);
    expect(item['attempts'], 1);
    expect(db.nextOutbox(), isNull, reason: 'temporisation : pas de renvoi immédiat');
  });

  test('refus 422 : la collecte redevient modifiable avec les anomalies du serveur', () async {
    final uuid = finishedCollection();
    server = (req) => json(422, {'error': {'code': 'VALIDATION_FAILED', 'message': 'Données invalides.', 'details': {'errors': [
      {'code': 'REQUIRED', 'severity': 'error', 'target': 'Q1_4', 'message': 'Réponse obligatoire.'}]}}});
    final report = SyncReport();
    await sync().pushOutbox(report);
    expect(report.rejected, 1);
    final col = db.collection(uuid)!;
    expect(col['local_status'], LocalStatus.brouillon);
    expect(jsonDecode(col['server_issues_json'] as String)[0]['target'], 'Q1_4');
  });

  test('conflit 409 : copie locale conservée intacte, puis reprise comme correction', () async {
    final uuid = finishedCollection();
    final before = db.collection(uuid)!['data_json'];
    server = (req) => json(409, {'error': {'code': 'REVISION_CONFLICT', 'message': 'La collecte a changé sur le serveur.', 'details': {'server_revision': 2, 'status': 'INVALIDEE'}}});
    final report = SyncReport();
    await sync().pushOutbox(report);
    expect(report.conflicts, 1);
    var col = db.collection(uuid)!;
    expect(col['local_status'], LocalStatus.enConflit);
    expect(col['data_json'], before, reason: 'jamais d\'écrasement silencieux');

    sync().keepLocalVersion(uuid);
    col = db.collection(uuid)!;
    expect(col['local_status'], LocalStatus.invalidee);
    expect(col['server_revision'], 2);
    final again = collections.finish(uuid);
    expect(again.valid, isTrue);
    expect(jsonDecode(db.nextOutbox()!['payload_json'] as String)['base_revision'], 2);
  });

  test('invalidation reçue au téléchargement : données du serveur, motif, modifiable', () async {
    final uuid = finishedCollection();
    server = (req) => json(201, {'data': {'uuid': uuid, 'status': 'SOUMISE', 'revision': 1}});
    await sync().pushOutbox(SyncReport());

    final corrected = {...base, 'Q1_6_4': 'Quartier corrigé par le serveur'};
    final report = SyncReport();
    sync().applyPull({
      'cursor': '2026-02-16T08:00:00+01:00',
      'campaigns': [],
      'assignments': [],
      'removed_assignments': [],
      'collections': [
        {'uuid': uuid, 'campaign_structure_id': 812, 'campaign_id': 7, 'status': 'INVALIDEE', 'revision': 1, 'editable': true,
          'review': {'reason': 'Effectifs incohérents', 'comment': 'Revoir la section IV'}, 'data': corrected}
      ],
    }, report);
    expect(report.corrections, 1);
    final col = db.collection(uuid)!;
    expect(col['local_status'], LocalStatus.invalidee);
    expect(collections.dataOf(col)['Q1_6_4'], 'Quartier corrigé par le serveur');
    expect(jsonDecode(col['review_json'] as String)['reason'], 'Effectifs incohérents');
    expect(db.kv('sync.cursor'), '2026-02-16T08:00:00+01:00');
    expect(CollectionService.isEditable(col), isTrue);
  });

  test('affectation retirée : une collecte non envoyée reste visible et conservée', () {
    final col = collections.openOrCreate(812);
    sync().applyPull({'cursor': 'c', 'removed_assignments': [812]}, SyncReport());
    expect(db.collection(col['uuid'] as String), isNotNull);
    expect(db.assignmentsWithState().map((a) => a['campaign_structure_id']), contains(812));
  });

  group('centre créé sur le terrain', () {
    late String typeCode;

    setUp(() {
      db.replaceTerritory({
        'regions': [{'id': 1, 'code': '01', 'name': 'Région test'}],
        'departments': [{'id': 101, 'region_id': 1, 'code': '0101', 'name': 'Département test'}],
        'communes': [{'id': 10101, 'department_id': 101, 'code': '010101', 'name': 'Commune test', 'centroid_lat': 13.5, 'centroid_lng': 2.1}],
      });
      typeCode = '${base['Q1_9']}';
      db.replaceStructureTypes([{'code': typeCode, 'label': 'Atelier', 'is_other': false}]);
    });

    Map<String, Object?> create() => collections.createFieldCentre(
          campaignId: 7, name: '  Atelier   Soudure Espoir ', typeCode: typeCode, legalStatus: 'PRIVE', zone: 'URBAINE', communeId: 10101,
          locality: 'Quartier Nord', gps: {'lat': 13.51, 'lng': 2.11, 'accuracy_m': 6.0, 'captured_at': '2026-02-15T10:00:00Z', 'status': 'OK'});

    test('hors ligne : questionnaire ouvert aussitôt, puis centre envoyé avant la collecte', () async {
      final col = create();
      final temp = col['campaign_structure_id'] as int;
      expect(temp, lessThan(0), reason: 'identifiant provisoire');
      final data = collections.dataOf(col);
      expect(data['Q1_1'], 'Atelier Soudure Espoir', reason: 'nom nettoyé et pré-rempli');
      expect((data['GPS'] as Map)['status'], 'OK');
      expect(collections.similarCentres(10101, 'atelier soudure espoir'), isNotEmpty, reason: 'détection de doublon');

      collections.save(col, {...collections.dataOf(col), ...base, 'Q1_1': 'Atelier Soudure Espoir'});
      collections.finish(col['uuid'] as String);
      expect(db.nextOutbox(ignoreSchedule: true), isNull, reason: 'la collecte attend que le centre soit enregistré');

      server = (req) {
        if (req.url.path.endsWith('/campaigns/7/field-structures')) {
          final b = jsonDecode(req.body) as Map;
          expect(b['name'], 'Atelier Soudure Espoir');
          expect(b['legal_status'], 'PRIVE');
          expect(b['latitude'], 13.51);
          return json(201, {'data': {'campaign_id': 7, 'campaign_structure_id': 900, 'replayed': false, 'possible_duplicates': [{'uuid': 'x'}],
            'structure': {'uuid': b['uuid'], 'official_code': null, 'name': b['name'], 'status': 'PROPOSEE', 'region_id': 1, 'department_id': 101,
              'commune_id': 10101, 'type_code': typeCode, 'zone': 'a', 'legal_status': 'b', 'locality_label': 'Quartier Nord', 'latitude': 13.51, 'longitude': 2.11}}});
        }
        if (req.url.path.endsWith('/submissions')) return json(201, {'data': {'status': 'SOUMISE', 'revision': 1, 'warnings': []}});
        return json(500, {'error': {'code': 'X', 'message': 'non prévu'}});
      };
      final report = SyncReport();
      final s = sync();
      await s.pushStructures(report);
      expect(report.centresCreated, 1);
      expect(report.duplicatesFlagged, 1);
      expect(db.collection(col['uuid'] as String)!['campaign_structure_id'], 900, reason: 'identifiant définitif');
      expect(db.assignment(temp), isNull);
      final item = db.nextOutbox(ignoreSchedule: true)!;
      final payload = jsonDecode(item['payload_json'] as String) as Map;
      if (payload.containsKey('campaign_structure_id')) expect(payload['campaign_structure_id'], 900);

      await s.pushOutbox(report);
      expect(report.sent, 1);
      expect(db.collection(col['uuid'] as String)!['local_status'], LocalStatus.soumise);
      expect(requests.first.url.path, endsWith('/field-structures'), reason: 'le centre part avant sa collecte');
      expect(db.pendingStructures(), isEmpty);
      final row = db.assignmentsWithState().firstWhere((a) => a['campaign_structure_id'] == 900);
      expect(row['centre_duplicates'], isNotNull);
    });

    test('centre refusé (hors zone) : saisie conservée, collecte non envoyée, nouvel essai possible', () async {
      final col = create();
      server = (req) => json(403, {'error': {'code': 'OUT_OF_SCOPE', 'message': 'Commune hors de votre zone.'}});
      final report = SyncReport();
      await sync().pushStructures(report);
      expect(report.centresFailed, 1);
      expect(report.ok, isTrue);
      expect(db.collection(col['uuid'] as String), isNotNull);
      expect(db.pendingStructures().single['state'], OutboxState.echec);
      final row = db.assignmentsWithState().firstWhere((a) => a['campaign_structure_id'] == col['campaign_structure_id']);
      expect(row['centre_error'], 'Commune hors de votre zone.');
    });

    test('validation : nom trop court et campagne fermée refusés', () {
      expect(() => collections.createFieldCentre(campaignId: 7, name: 'ab', typeCode: typeCode, legalStatus: 'PUBLIC', zone: 'RURALE', communeId: 10101),
          throwsA(isA<CollectionException>()));
      expect(() => collections.createFieldCentre(campaignId: 99, name: 'Centre test', typeCode: typeCode, legalStatus: 'PUBLIC', zone: 'RURALE', communeId: 10101),
          throwsA(isA<CollectionException>()));
    });
  });

  test('nouvelle version du questionnaire pour la campagne : collecte non envoyée basculée, données conservées', () {
    final uuid = finishedCollection();
    final before = collections.dataOf(db.collection(uuid)!);
    final report = SyncReport();
    sync().applyPull({
      'cursor': 'c2',
      'campaigns': [
        {'id': 7, 'code': 'TEST', 'name': 'Campagne de test', 'status': 'OUVERTE', 'accepts_submissions': true,
          'opens_on': '2026-01-05', 'closes_on': '2026-03-31', 'validation_levels': 2, 'questionnaire_version': '2025.2'}
      ],
    }, report);
    expect(report.migratedToNewVersion, 1);
    expect(report.questionnaireUpdates, contains('2025.2'));
    final col = db.collection(uuid)!;
    expect(col['questionnaire_version'], '2025.2');
    expect(col['local_status'], LocalStatus.brouillon, reason: 'à vérifier puis terminer de nouveau');
    expect(db.nextOutbox(ignoreSchedule: true), isNull, reason: "l'envoi de l'ancienne version est annulé");
    expect(collections.dataOf(col)['Q1_1'], before['Q1_1'], reason: 'aucune donnée perdue');
  });

  group('test de connexion au serveur', () {
    test('serveur joignable, base disponible, session valide', () async {
      server = (req) => req.url.path.endsWith('/health')
          ? json(200, {'data': {'status': 'ok', 'database': true}})
          : json(200, {'data': {'uuid': 'u-1'}});
      final c = await sync().checkServer(signedIn: true);
      expect(c.reachable, isTrue);
      expect(c.database, isTrue);
      expect(c.session, 'OK');
      expect(c.allGood, isTrue);
      expect(c.latencyMs, isNotNull);
      expect(requests.first.headers['Authorization'], isNull, reason: 'le test réseau ne demande pas de jeton');
    });

    test('serveur injoignable : aucune donnée envoyée, conseil réseau', () async {
      server = (req) => throw const SocketException('réseau absent');
      final c = await sync().checkServer(signedIn: true);
      expect(c.reachable, isFalse);
      expect(c.session, isNull, reason: 'session non testée sans serveur');
      expect(c.allGood, isFalse);
    });

    test('session expirée détectée', () async {
      server = (req) => req.url.path.endsWith('/health')
          ? json(200, {'data': {'status': 'ok', 'database': true}})
          : json(401, {'error': {'code': 'UNAUTHENTICATED', 'message': 'Authentification requise ou session expirée.'}});
      final c = await sync().checkServer(signedIn: true);
      expect(c.reachable, isTrue);
      expect(c.session, 'EXPIREE');
      expect(c.allGood, isFalse);
    });
  });
}
