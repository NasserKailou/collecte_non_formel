// Mise à jour dans l'application : comparaison des versions (le bandeau disparaît une fois la version installée),
// téléchargement avec progression et contrôle d'intégrité.

import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:eftp_nf_collecte/src/services/app_updater.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('comparaison de versions', () {
    expect(AppUpdater.compareVersions('1.0.1', '1.0.0'), 1);
    expect(AppUpdater.compareVersions('1.0.0', '1.0.0'), 0);
    expect(AppUpdater.compareVersions('1.0', '1.0.0'), 0);
    expect(AppUpdater.compareVersions('1.2.9', '1.2.10'), -1);
    expect(AppUpdater.compareVersions('2.0.0', '1.9.9'), 1);
  });

  group('téléchargement', () {
    late Directory dir;
    final apk = List<int>.generate(300000, (i) => i % 251);
    final sha = sha256.convert(apk).toString();
    setUp(() => dir = Directory.systemTemp.createTempSync('eftp_upd_'));
    tearDown(() => dir.deleteSync(recursive: true));

    AppUpdater updater({int status = 200, List<int>? body}) {
      final bytes = body ?? apk;
      return AppUpdater(
          client: MockClient.streaming((req, _) async => http.StreamedResponse(
              Stream.fromIterable([for (var i = 0; i < bytes.length; i += 65536) bytes.sublist(i, (i + 65536).clamp(0, bytes.length))]),
              status,
              contentLength: bytes.length)));
    }

    test('progression puis fichier intègre', () async {
      final seen = <int>[];
      final f = await updater().download(Uri.parse('https://serveur.test/app/releases/1/download'), File('${dir.path}/a.apk'),
          sha256Hex: sha, onProgress: (r, t) => seen.add(r));
      expect(await f.length(), apk.length);
      expect(seen.last, apk.length);
      expect(seen.length, greaterThan(1));
      // Déjà téléchargé et intègre : réutilisé sans nouveau téléchargement.
      final again = await updater(status: 500).download(Uri.parse('https://x'), File('${dir.path}/a.apk'), sha256Hex: sha, onProgress: (_, _) {});
      expect(again.path, f.path);
    });

    test('fichier altéré : refusé et supprimé', () async {
      await expectLater(
          updater(body: [1, 2, 3]).download(Uri.parse('https://x'), File('${dir.path}/b.apk'), sha256Hex: sha, onProgress: (_, _) {}),
          throwsA(isA<UpdateException>()));
      expect(File('${dir.path}/b.apk').existsSync(), isFalse);
      expect(File('${dir.path}/b.apk.part').existsSync(), isFalse);
    });

    test('lien expiré : message explicite', () async {
      await expectLater(updater(status: 403).download(Uri.parse('https://x'), File('${dir.path}/c.apk'), onProgress: (_, _) {}),
          throwsA(isA<UpdateException>().having((e) => e.message, 'message', contains('expiré'))));
    });
  });
}
