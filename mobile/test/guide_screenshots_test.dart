// Captures d'écran de l'application pour le guide de formation (docs/formation). Les vrais écrans sont dessinés par
// le moteur de test avec les polices Roboto et Material du SDK, sur une base locale chiffrée remplie de données
// FICTIVES. Ignoré par défaut ; pour régénérer :
//   GUIDE_SHOTS_DIR=../docs/formation/img/mobile flutter test test/guide_screenshots_test.dart

import 'dart:convert';
import 'dart:io';
import 'dart:ui' as ui;

import 'package:eftp_nf_collecte/src/app_state.dart';
import 'package:eftp_nf_collecte/src/data/local_db.dart';
import 'package:eftp_nf_collecte/src/data/secure_store.dart';
import 'package:eftp_nf_collecte/src/net/api_client.dart';
import 'package:eftp_nf_collecte/src/services/auth_service.dart';
import 'package:eftp_nf_collecte/src/services/collection_service.dart';
import 'package:eftp_nf_collecte/src/services/sync_service.dart';
import 'package:eftp_nf_collecte/src/ui/auth_screens.dart';
import 'package:eftp_nf_collecte/src/ui/common.dart';
import 'package:eftp_nf_collecte/src/ui/form_screen.dart';
import 'package:eftp_nf_collecte/src/ui/home_screen.dart';
import 'package:eftp_nf_collecte/src/ui/new_centre_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator_platform_interface/geolocator_platform_interface.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:plugin_platform_interface/plugin_platform_interface.dart';

final _out = Platform.environment['GUIDE_SHOTS_DIR'];
final _shared = '${Directory.current.path}/../shared';
const _key = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';
const _frame = Key('cadre');

/// Position GPS fictive (Niamey), sans capteur.
class _FakeGeo extends GeolocatorPlatform with MockPlatformInterfaceMixin {
  @override
  Future<bool> isLocationServiceEnabled() async => true;
  @override
  Future<LocationPermission> checkPermission() async => LocationPermission.whileInUse;
  @override
  Future<LocationPermission> requestPermission() async => LocationPermission.whileInUse;
  @override
  Future<Position> getCurrentPosition({LocationSettings? locationSettings}) async => Position(
      latitude: 13.512345, longitude: 2.112345, accuracy: 6, altitude: 210, altitudeAccuracy: 3, heading: 0, headingAccuracy: 0,
      speed: 0, speedAccuracy: 0, timestamp: DateTime.utc(2026, 11, 15, 9));
}

class _Tokens implements TokenProvider {
  @override
  Future<String?> accessToken() async => null;
  @override
  Future<String?> refreshToken() async => null;
  @override
  Future<void> store(String? a, String? r) async {}
}

Future<void> _loadFonts() async {
  final fonts = '${Platform.environment['FLUTTER_ROOT'] ?? '${Platform.environment['USERPROFILE']}/development/flutter'}/bin/cache/artifacts/material_fonts';
  Future<ByteData> f(String n) async => ByteData.view(File('$fonts/$n').readAsBytesSync().buffer);
  await (FontLoader('Roboto')
        ..addFont(f('roboto-regular.ttf'))
        ..addFont(f('roboto-medium.ttf'))
        ..addFont(f('roboto-bold.ttf')))
      .load();
  await (FontLoader('MaterialIcons')..addFont(f('materialicons-regular.otf'))).load();
}

void main() {
  if (_out == null) {
    test('captures du guide (GUIDE_SHOTS_DIR non défini : ignoré)', () {}, skip: true);
    return;
  }
  late Directory dir;
  late AppState app;
  late Map<String, Object?> base;

  setUpAll(_loadFonts);

  setUp(() {
    Directory(_out!).createSync(recursive: true);
    GeolocatorPlatform.instance = _FakeGeo();
    dir = Directory.systemTemp.createTempSync('eftp_guide_');
    final db = LocalDb.open('${dir.path}/g.db', _key);
    final api = ApiClient(tokens: _Tokens(), deviceUid: 'tablette-formation', baseUrl: 'https://serveur.test/api/v1',
        client: MockClient((req) async => req.url.path.endsWith('/health')
            ? http.Response('{"data":{"status":"ok","database":true}}', 200, headers: {'content-type': 'application/json'})
            : req.url.path.endsWith('/me')
                ? http.Response('{"data":{"uuid":"u-1"}}', 200, headers: {'content-type': 'application/json'})
                : http.Response('{"error":{"code":"OFFLINE","message":"Hors ligne"}}', 503)));
    app = AppState()
      ..db = db
      ..collections = CollectionService(db, clock: () => DateTime.utc(2026, 11, 15, 9))
      ..sync = SyncService(db: db, api: api)
      ..auth = AuthService(db: db, api: api, secrets: Secrets(MemorySecretStore()), deviceUid: 'tablette-formation',
          device: DeviceInfo(model: 'Tablette', osVersion: 'Android 14'))
      ..ready = true;

    final fixture = jsonDecode(File('$_shared/rules-fixtures/eftp-nf-2025.3.json').readAsStringSync()) as Map<String, Object?>;
    base = Map<String, Object?>.from(fixture['base'] as Map)..remove('_ctx');
    db.saveQuestionnaire('2025.3', File('$_shared/questionnaire/eftp-nf-2025.3.json').readAsStringSync(), null);
    db.setKv('sync.context', jsonEncode((fixture['base'] as Map)['_ctx']));
    db.setKv('sync.last_success', DateTime.now().toUtc().subtract(const Duration(minutes: 12)).toIso8601String());
    db.replaceTerritory({
      'regions': [{'id': 8, 'code': '8', 'name': 'NIAMEY'}],
      'departments': [{'id': 801, 'region_id': 8, 'code': '801', 'name': 'VILLE DE NIAMEY'}],
      'communes': [
        {'id': 10101, 'department_id': 801, 'code': '80101', 'name': 'NIAMEY IV', 'centroid_lat': 13.51, 'centroid_lng': 2.11},
        {'id': 10102, 'department_id': 801, 'code': '80102', 'name': 'NIAMEY V', 'centroid_lat': 13.49, 'centroid_lng': 2.13},
      ],
    });
    db.replaceStructureTypes([
      {'code': 'a', 'label': 'Atelier', 'is_other': false},
      {'code': 'b', 'label': 'Centre de formation', 'is_other': false},
      {'code': 'c', 'label': 'Groupement', 'is_other': false},
      {'code': 'z', 'label': 'Autre', 'is_other': true},
    ]);
    db.saveAccount(
      profile: {'uuid': 'u-1', 'matricule': 'AG-0042', 'full_name': 'Aïchatou MOUSSA', 'roles': ['AGENT'],
        'scopes': [{'level': 'DEPARTMENT', 'department_id': 801}]},
      verifier: 'x', salt: '00', offlinePolicy: {'max_offline_days': 30, 'max_unlock_failures': 10});
    db.upsertCampaign({'id': 7, 'code': 'EFTPNF-2027', 'name': 'Recensement EFTP non formel 2027', 'status': 'OUVERTE', 'accepts_submissions': true,
      'collection_mode': 'TABLETTE', 'opens_on': '2026-11-13', 'closes_on': '2026-12-31', 'validation_levels': 2, 'questionnaire_version': '2025.3',
      'academic_year': {'code': '2026-2027', 'label': 'Année 2026-2027'}});
    var cs = 900;
    for (final s in ['Atelier de couture Al Baraka', 'Garage Espoir', 'Menuiserie Nassara', 'Centre de formation Tagazar', 'Salon de coiffure Bonheur']) {
      db.upsertAssignment({'campaign_structure_id': ++cs, 'campaign_id': 7, 'collection_uuid': null, 'structure': {
        'uuid': 's-$cs', 'official_code': '80101${cs.toString().padLeft(5, '0')}', 'name': s, 'region_id': 8, 'department_id': 801, 'commune_id': 10101,
        'type_code': 'a', 'zone': 'a', 'legal_status': 'b', 'locality_label': cs.isEven ? 'Talladjé' : 'Lazaret'}});
    }
    app.session = Session(profile: {'full_name': 'Aïchatou MOUSSA', 'matricule': 'AG-0042'}, online: false);
  });

  tearDown(() {
    app.db.close();
    try {
      dir.deleteSync(recursive: true);
    } catch (_) {}
  });

  Future<void> show(WidgetTester tester, Widget home, {Size size = const Size(900, 1440)}) async {
    tester.view.physicalSize = size; // par défaut tablette 7 pouces (600 × 960 dp)
    tester.view.devicePixelRatio = 1.5;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(RepaintBoundary(
      key: _frame,
      child: MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: appTheme().copyWith(textTheme: appTheme().textTheme.apply(fontFamily: 'Roboto')),
        locale: const Locale('fr'),
        supportedLocales: const [Locale('fr')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: home,
      ),
    ));
    await settle(tester);
  }

  Future<void> shot(WidgetTester tester, String name) async {
    await settle(tester);
    final boundary = tester.renderObject<RenderRepaintBoundary>(find.byKey(_frame));
    await tester.runAsync(() async {
      final img = await boundary.toImage(pixelRatio: 1.5);
      final bytes = await img.toByteData(format: ui.ImageByteFormat.png);
      File('$_out/$name.png').writeAsBytesSync(bytes!.buffer.asUint8List());
    });
  }

  /// États variés pour la liste : brouillon, terminée (en attente d'envoi), envoyée, à corriger, nouveau centre.
  void seedStates() {
    final c = app.collections;
    final draft = c.openOrCreate(902);
    c.save(draft, {...c.dataOf(draft), 'Q1_3': true});
    final done = c.openOrCreate(903);
    c.save(done, {...c.dataOf(done), ...base});
    c.finish(done['uuid'] as String);
    app.db.insertCollection(uuid: 'col-904', csId: 904, campaignId: 7, version: '2025.3', data: base, status: LocalStatus.soumise,
        serverStatus: 'SOUMISE', serverRevision: 1);
    app.db.insertCollection(uuid: 'col-905', csId: 905, campaignId: 7, version: '2025.3', data: base, status: LocalStatus.invalidee,
        serverStatus: 'INVALIDEE', serverRevision: 1, review: {'reason': 'Effectifs incohérents', 'comment': 'Revoir la section IV'});
    c.createFieldCentre(campaignId: 7, name: 'Atelier de soudure Dan Kassa', typeCode: 'a', legalStatus: 'PRIVE', zone: 'URBAINE', communeId: 10102,
        locality: 'Koira Tégui', gps: {'lat': 13.49, 'lng': 2.13, 'accuracy_m': 5.0, 'captured_at': '2026-11-15T09:00:00Z', 'status': 'OK'});
  }

  testWidgets('m01 — première connexion', (tester) async {
    app.db.execute('DELETE FROM account');
    app.session = null;
    await show(tester, LoginScreen(app: app));
    await shot(tester, 'm01-connexion');
  });

  testWidgets('m02 — accueil : mes structures', (tester) async {
    seedStates();
    await show(tester, HomeScreen(app: app));
    await shot(tester, 'm02-accueil');
  });

  testWidgets('m07 — tester la connexion au serveur', (tester) async {
    seedStates();
    await show(tester, HomeScreen(app: app));
    await tester.tap(find.byTooltip('Menu'));
    await settle(tester);
    await tester.tap(find.text('Tester la connexion au serveur'));
    await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 300)));
    await settle(tester);
    await shot(tester, 'm07-test-connexion');
  });

  testWidgets('m03 — nouveau centre', (tester) async {
    await show(tester, NewCentreScreen(app: app));
    await shot(tester, 'm03-nouveau-centre-vide');
    await tester.enterText(find.byType(TextField).first, 'Atelier de soudure Dan Kassa');
    await tester.tap(find.text('Atelier'));
    await tester.tap(find.text('Privé'));
    await settle(tester);
    await tester.drag(find.byType(ListView).first, const Offset(0, -500));
    await settle(tester);
    await tester.tap(find.text('Urbaine (ville)'));
    await settle(tester);
    await shot(tester, 'm03b-nouveau-centre-rempli');
  });

  testWidgets('m03c — alerte de doublon', (tester) async {
    await show(tester, NewCentreScreen(app: app));
    await tester.tap(find.byType(DropdownMenu<int>));
    await settle(tester);
    await tester.tap(find.text('NIAMEY IV — VILLE DE NIAMEY').last);
    await settle(tester);
    await tester.enterText(find.byType(TextField).first, 'Garage Espoir');
    await tester.pump(const Duration(milliseconds: 600));
    await settle(tester);
    await tester.drag(find.byType(ListView).first, const Offset(0, -260));
    await shot(tester, 'm03c-doublon');
  });

  Future<String> draftWith(Map<String, Object?> patch, {List<String> unset = const []}) async {
    final c = app.collections;
    final col = c.openOrCreate(902);
    final data = {...c.dataOf(col), ...base, ...patch};
    for (final k in unset) {
      data.remove(k);
    }
    c.save(col, data);
    return col['uuid'] as String;
  }

  testWidgets('m04 — questionnaire, navigation Précédent / Suivant', (tester) async {
    final uuid = await draftWith({});
    await show(tester, FormScreen(app: app, uuid: uuid));
    await shot(tester, 'm04-questionnaire-section1');
  });

  testWidgets('m05 — 2.1 saisi librement, incohérence signalée', (tester) async {
    final grid = jsonDecode(jsonEncode(base['Q2_1'])) as Map<String, Object?>;
    ((grid['M4_9'] as Map)['s3'] as Map)['F'] = 18;
    final uuid = await draftWith({'Q2_1': grid});
    await show(tester, FormScreen(app: app, uuid: uuid));
    await tester.tap(find.text('Suivant'));
    await settle(tester);
    final warning = find.textContaining('Incohérence 2.1');
    final list = find.byType(ListView).last;
    await tester.scrollUntilVisible(warning, 300, scrollable: find.descendant(of: list, matching: find.byType(Scrollable)).first);
    Scrollable.ensureVisible(tester.element(warning), alignment: 0.55);
    await settle(tester);
    await settle(tester);
    await shot(tester, 'm05-coherence-2-1');
  });

  testWidgets('m08 — partie IV : ajouter un secteur', (tester) async {
    final uuid = await draftWith({}, unset: ['sectors']);
    await show(tester, FormScreen(app: app, uuid: uuid));
    for (var i = 0; i < 3; i++) {
      await tester.tap(find.text('Suivant'));
      await settle(tester);
    }
    await shot(tester, 'm08-partie4-vide');
    await tester.tap(find.text('Ajouter un secteur'));
    await settle(tester);
    await shot(tester, 'm08b-choix-secteur');
    await tester.tap(find.textContaining('Secondaire'));
    await settle(tester);
    await shot(tester, 'm08c-secteur-ajoute');
  });

  testWidgets('m09 — partie IV renseignée (secteurs repliables)', (tester) async {
    final uuid = await draftWith({});
    await show(tester, FormScreen(app: app, uuid: uuid));
    for (var i = 0; i < 3; i++) {
      await tester.tap(find.text('Suivant'));
      await settle(tester);
    }
    await shot(tester, 'm09-partie4-remplie');
  });

  // Téléphone (360 × 760 dp) : matrices de la partie II et grille des vulnérables de la partie IV.
  const phone = Size(540, 1140);

  testWidgets('m10 — téléphone : matrices 2.1 et 2.2', (tester) async {
    final uuid = await draftWith({});
    await show(tester, FormScreen(app: app, uuid: uuid), size: phone);
    await tester.tap(find.text('Suivant'));
    await settle(tester);
    await shot(tester, 'm10-tel-2-1');
    final list = find.byType(ListView).last;
    await tester.scrollUntilVisible(find.textContaining('2.2 '), 300, scrollable: find.descendant(of: list, matching: find.byType(Scrollable)).first);
    await settle(tester);
    await shot(tester, 'm10b-tel-2-2');
  });

  testWidgets('m11 — téléphone : vulnérables partie IV', (tester) async {
    final uuid = await draftWith({});
    await show(tester, FormScreen(app: app, uuid: uuid), size: phone);
    for (var i = 0; i < 3; i++) {
      await tester.tap(find.text('Suivant'));
      await settle(tester);
    }
    final list = find.byType(ListView).last;
    await tester.tap(find.text('Secteur Tertiaire/Service'));
    await settle(tester);
    final vul = find.text('Dont apprentis vulnérables', skipOffstage: false).last;
    await tester.scrollUntilVisible(vul, 300, scrollable: find.descendant(of: list, matching: find.byType(Scrollable)).first);
    await tester.tap(vul);
    await settle(tester);
    await tester.drag(find.descendant(of: list, matching: find.byType(Scrollable)).first, const Offset(0, -300));
    await settle(tester);
    await shot(tester, 'm11-tel-vulnerables');
  });

  testWidgets('m06 — questions vides : Terminer quand même', (tester) async {
    final uuid = await draftWith({}, unset: ['Q2_3', 'Q2_4']);
    await show(tester, FormScreen(app: app, uuid: uuid));
    for (var i = 0; i < 4; i++) {
      await tester.tap(find.text('Suivant'));
      await settle(tester);
    }
    await shot(tester, 'm06a-derniere-section');
    await tester.tap(find.text('Terminer'));
    await settle(tester);
    await shot(tester, 'm06b-terminer-quand-meme');
  });
}

Future<void> settle(WidgetTester tester) async {
  for (var i = 0; i < 8; i++) {
    await tester.pump(const Duration(milliseconds: 150));
  }
}
