// Profil superviseur sur tablette : un compte qui contrôle (collection.review) sans collecter peut se connecter et
// obtient l'adresse d'ouverture du back-office (ticket à usage unique) ; un compte sans aucun des deux est refusé.

import 'dart:convert';
import 'dart:io';

import 'package:eftp_nf_collecte/src/data/local_db.dart';
import 'package:eftp_nf_collecte/src/data/secure_store.dart';
import 'package:eftp_nf_collecte/src/net/api_client.dart';
import 'package:eftp_nf_collecte/src/services/auth_service.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

const _key = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

void main() {
  late Directory dir;
  late LocalDb db;
  late List<String> perms;
  late List<http.Request> requests;

  AuthService auth() {
    final secrets = Secrets(MemorySecretStore());
    final api = ApiClient(
      tokens: SecretTokenProvider(secrets),
      deviceUid: 'tablette-sup-01',
      baseUrl: 'https://serveur.test/api/v1',
      client: MockClient((req) async {
        requests.add(req);
        final json = {'content-type': 'application/json'};
        if (req.url.path.endsWith('/auth/login')) {
          return http.Response(
              jsonEncode({
                'data': {
                  'access_token': 'a' * 64,
                  'refresh_token': 'r' * 64,
                  'user': {'uuid': 'u-sup', 'full_name': 'Superviseur Test', 'roles': ['SUP_REG'], 'permissions': perms},
                  'must_change_password': false,
                  'offline_policy': {'max_offline_days': 30, 'max_unlock_failures': 10},
                }
              }),
              200,
              headers: json);
        }
        if (req.url.path.endsWith('/auth/web-ticket')) {
          return http.Response(jsonEncode({'data': {'ticket': 't' * 64, 'expires_in': 60}}), 200, headers: json);
        }
        return http.Response('', 204);
      }),
    );
    return AuthService(db: db, api: api, secrets: secrets, deviceUid: 'tablette-sup-01', device: const DeviceInfo(model: 'T', osVersion: 'A14'));
  }

  setUp(() {
    dir = Directory.systemTemp.createTempSync('eftp_sup_');
    db = LocalDb.open('${dir.path}/s.db', _key);
    requests = [];
  });
  tearDown(() {
    db.close();
    dir.deleteSync(recursive: true);
  });

  test('un superviseur régional se connecte et ouvre la supervision', () async {
    perms = ['collection.view', 'collection.review', 'stats.view'];
    final a = auth();
    final s = await a.loginOnline('SUP001', 'motdepasse');
    expect(s.isSupervisor, isTrue);
    final url = await a.webSessionUrl();
    expect(url.toString(), 'https://serveur.test/api/v1/auth/web-session?ticket=${'t' * 64}');
    expect(requests.last.headers['authorization'], 'Bearer ${'a' * 64}');
  });

  test("un agent recenseur reste un agent (pas l'écran de supervision)", () async {
    perms = ['collection.submit', 'collection.view'];
    expect((await auth().loginOnline('AG001', 'motdepasse')).isSupervisor, isFalse);
  });

  test('un compte sans collecte ni contrôle est refusé sur la tablette', () async {
    perms = ['stats.view'];
    await expectLater(auth().loginOnline('VIEW01', 'motdepasse'), throwsA(isA<AuthException>().having((e) => e.code, 'code', 'NOT_AGENT')));
  });
}
