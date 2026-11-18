// Client de l'API /api/v1 (jeton porteur) : en-têtes obligatoires du mobile, rafraîchissement automatique du jeton
// d'accès (une fois par requête), erreurs normalisées. Contrat : docs/api/README.md.

import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import '../app_config.dart';

class ApiException implements Exception {
  ApiException(this.status, this.code, this.message, [this.details]);

  /// 0 : pas de réponse du serveur (réseau, délai dépassé).
  final int status;
  final String code;
  final String message;
  final Object? details;

  bool get isNetwork => status == 0;

  /// Erreur temporaire : l'envoi sera retenté (réseau, serveur indisponible, limitation).
  bool get isTransient => status == 0 || status == 429 || status == 503 || status >= 500;

  @override
  String toString() => '$message ($code${status > 0 ? ', HTTP $status' : ''})';
}

class ApiResponse {
  ApiResponse(this.status, this.body, this.headers);
  final int status;
  final Map<String, Object?>? body;
  final Map<String, String> headers;

  Object? get data => body?['data'];
}

/// Fournisseur de jetons (lecture/écriture dans le Keystore, voir secure_store.dart).
abstract class TokenProvider {
  Future<String?> accessToken();
  Future<String?> refreshToken();
  Future<void> store(String? access, String? refresh);
}

class ApiClient {
  ApiClient({required this.tokens, required this.deviceUid, String? baseUrl, http.Client? client})
      : baseUrl = (baseUrl ?? AppConfig.apiBaseUrl).replaceFirst(RegExp(r'/$'), ''),
        _http = client ?? http.Client();

  final String baseUrl;
  final TokenProvider tokens;
  final String deviceUid;
  final http.Client _http;
  Future<bool>? _refreshing;

  Map<String, String> _headers(String? token, {bool json = true}) => {
        'Accept': 'application/json',
        if (json) 'Content-Type': 'application/json; charset=utf-8',
        'X-App-Version': AppConfig.appVersion,
        'X-Device-Id': deviceUid,
        if (token != null) 'Authorization': 'Bearer $token',
      };

  Uri _uri(String path, [Map<String, Object?>? query]) {
    final q = <String, String>{};
    query?.forEach((k, v) {
      if (v != null && v != '') q[k] = v.toString();
    });
    return Uri.parse('$baseUrl$path').replace(queryParameters: q.isEmpty ? null : q);
  }

  Future<ApiResponse> get(String path, {Map<String, Object?>? query, String? etag, bool auth = true}) =>
      _send(() async => http.Request('GET', _uri(path, query))..headers.addAll({..._headers(await _token(auth), json: false), if (etag != null) 'If-None-Match': etag}),
          auth: auth);

  Future<ApiResponse> post(String path, Object? body, {bool auth = true, Map<String, String>? headers}) =>
      _send(() async => http.Request('POST', _uri(path))
        ..headers.addAll({..._headers(await _token(auth)), ...?headers})
        ..body = jsonEncode(body ?? {}), auth: auth);

  Future<ApiResponse> uploadFile(String path, File file, {Map<String, Object?>? query, String field = 'file'}) => _send(() async {
        final req = http.MultipartRequest('POST', _uri(path, query))..headers.addAll(_headers(await _token(true), json: false));
        req.files.add(await http.MultipartFile.fromPath(field, file.path));
        return req;
      }, auth: true);

  Future<String?> _token(bool auth) async => auth ? await tokens.accessToken() : null;

  Future<ApiResponse> _send(Future<http.BaseRequest> Function() build, {required bool auth, bool retried = false}) async {
    http.StreamedResponse streamed;
    String text;
    try {
      streamed = await _http.send(await build()).timeout(AppConfig.requestTimeout);
      text = await streamed.stream.bytesToString().timeout(AppConfig.requestTimeout);
    } on TimeoutException {
      throw ApiException(0, 'TIMEOUT', 'Le serveur ne répond pas (délai dépassé).');
    } on SocketException catch (e) {
      final cause = e.osError?.message ?? e.message;
      final dns = cause.contains('host lookup') || cause.contains('No address associated');
      throw ApiException(0, 'NETWORK', dns
          ? 'Adresse du serveur introuvable (DNS) : ${Uri.parse(baseUrl).host}.'
          : 'Pas de connexion au serveur ($cause).');
    } on HandshakeException catch (e) {
      throw ApiException(0, 'TLS', 'Connexion sécurisée refusée (certificat du serveur invalide) : ${e.message}');
    } on http.ClientException catch (e) {
      throw ApiException(0, 'NETWORK', 'Connexion au serveur impossible : ${e.message}');
    }

    Map<String, Object?>? body;
    if (text.isNotEmpty) {
      try {
        final decoded = jsonDecode(text);
        if (decoded is Map<String, Object?>) body = decoded;
      } catch (_) {
        if (streamed.statusCode < 400) throw ApiException(streamed.statusCode, 'BAD_RESPONSE', 'Réponse du serveur illisible.');
      }
    }
    final status = streamed.statusCode;
    if (status < 400) return ApiResponse(status, body, streamed.headers);

    final err = body?['error'] is Map ? body!['error'] as Map : const {};
    final code = (err['code'] ?? 'HTTP_$status').toString();
    if (status == 401 && auth && !retried && code == 'UNAUTHENTICATED' && await _refresh()) {
      return _send(build, auth: auth, retried: true);
    }
    throw ApiException(status, status == 401 && auth ? 'SESSION_EXPIRED' : code,
        (err['message'] ?? 'Erreur du serveur (HTTP $status).').toString(), err['details']);
  }

  /// Rafraîchit la paire de jetons (une seule requête même si plusieurs appels échouent en même temps).
  Future<bool> _refresh() => _refreshing ??= () async {
        try {
          final refresh = await tokens.refreshToken();
          if (refresh == null) return false;
          final r = await post('/auth/refresh', {'refresh_token': refresh}, auth: false);
          final d = r.data as Map<String, Object?>;
          await tokens.store(d['access_token'] as String?, d['refresh_token'] as String?);
          return true;
        } on ApiException catch (e) {
          if (!e.isNetwork) await tokens.store(null, null); // jeton refusé : reconnexion en ligne nécessaire
          return false;
        } finally {
          _refreshing = null;
        }
      }();
}
