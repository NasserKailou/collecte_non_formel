// Mise à jour de l'application SANS quitter l'application : téléchargement de l'APK publié (lien signé du serveur)
// avec progression, contrôle d'intégrité (SHA-256 annoncé), puis ouverture de l'installeur Android.

import 'dart:async';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:http/http.dart' as http;
import 'package:open_filex/open_filex.dart';

class UpdateException implements Exception {
  UpdateException(this.message);
  final String message;
  @override
  String toString() => message;
}

class AppUpdater {
  AppUpdater({http.Client? client}) : _client = client ?? http.Client();
  final http.Client _client;
  bool _cancelled = false;

  /// Comparaison de versions « 1.2.10 » > « 1.2.9 » (les parties manquantes valent 0).
  static int compareVersions(String a, String b) {
    List<int> parts(String v) => v.split(RegExp(r'[.+-]')).map((p) => int.tryParse(p) ?? 0).toList();
    final x = parts(a), y = parts(b);
    for (var i = 0; i < (x.length > y.length ? x.length : y.length); i++) {
      final d = (i < x.length ? x[i] : 0) - (i < y.length ? y[i] : 0);
      if (d != 0) return d.sign;
    }
    return 0;
  }

  void cancel() => _cancelled = true;

  /// Télécharge [url] vers [dest] (fichier temporaire puis renommage) en signalant la progression. Un fichier déjà
  /// téléchargé et intègre est réutilisé.
  Future<File> download(Uri url, File dest, {String? sha256Hex, required void Function(int received, int? total) onProgress}) async {
    if (await dest.exists() && (sha256Hex == null || await _sha(dest) == sha256Hex.toLowerCase())) {
      final n = await dest.length();
      onProgress(n, n);
      return dest;
    }
    await dest.parent.create(recursive: true);
    final part = File('${dest.path}.part');
    final response = await _client.send(http.Request('GET', url)).timeout(const Duration(seconds: 30));
    if (response.statusCode == 403) throw UpdateException('Lien de téléchargement expiré : réessayez (un nouveau lien est demandé au serveur).');
    if (response.statusCode != 200) throw UpdateException('Téléchargement refusé par le serveur (HTTP ${response.statusCode}).');
    final total = response.contentLength;
    final sink = part.openWrite();
    var received = 0;
    try {
      await for (final chunk in response.stream.timeout(const Duration(seconds: 60))) {
        if (_cancelled) throw UpdateException('Téléchargement annulé.');
        sink.add(chunk);
        received += chunk.length;
        onProgress(received, total);
      }
      await sink.flush();
    } on TimeoutException {
      throw UpdateException('Connexion interrompue pendant le téléchargement : réessayez avec un meilleur réseau.');
    } finally {
      await sink.close();
    }
    if (sha256Hex != null && await _sha(part) != sha256Hex.toLowerCase()) {
      await part.delete();
      throw UpdateException('Fichier téléchargé incomplet ou altéré : réessayez.');
    }
    if (await dest.exists()) await dest.delete();
    return part.rename(dest.path);
  }

  /// Ouvre l'installeur Android (la première fois, Android demande d'autoriser l'installation depuis cette application).
  static Future<void> install(File apk) async {
    final r = await OpenFilex.open(apk.path, type: 'application/vnd.android.package-archive');
    if (r.type != ResultType.done) {
      throw UpdateException(r.type == ResultType.permissionDenied
          ? "Autorisez l'installation depuis « Collecte EFTP » (Paramètres Android › Applications › Collecte EFTP › Installer des applis inconnues), puis touchez de nouveau « Mettre à jour »."
          : "Impossible d'ouvrir l'installeur : ${r.message}");
    }
  }

  static Future<String> _sha(File f) async => (await sha256.bind(f.openRead()).first).toString();
}
