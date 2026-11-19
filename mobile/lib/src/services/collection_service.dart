// Cycle de vie local d'une collecte : création pré-remplie depuis le registre, sauvegarde automatique, contrôle par
// le moteur partagé, « Terminer » (mise en file d'envoi avec empreinte), correction après invalidation.

import 'dart:convert';
import 'dart:math';

import 'package:crypto/crypto.dart';
import 'package:uuid/uuid.dart';

import '../data/local_db.dart';
import '../rules/questionnaire_engine.dart';

class CollectionException implements Exception {
  CollectionException(this.message);
  final String message;
  @override
  String toString() => message;
}

/// JSON canonique (clés triées récursivement), comme SubmissionService::canonicalJson du serveur. L'empreinte locale
/// sert au suivi de la file ; l'idempotence est garantie par le serveur, qui recalcule sa propre empreinte.
String canonicalJson(Object? v) {
  Object? sort(Object? x) {
    if (x is Map) {
      final keys = x.keys.map((k) => k.toString()).toList()..sort();
      return {for (final k in keys) k: sort(x[k])};
    }
    if (x is List) return x.map(sort).toList();
    return x;
  }

  return jsonEncode(sort(v));
}

String sha256Hex(String s) => sha256.convert(utf8.encode(s)).toString();

class CollectionService {
  CollectionService(this.db, {DateTime Function()? clock}) : _clock = clock ?? DateTime.now;

  final LocalDb db;
  final DateTime Function() _clock;
  final Map<String, QuestionnaireEngine> _engines = {};

  QuestionnaireEngine engine(String version) {
    final cached = _engines[version];
    if (cached != null) return cached;
    final json = db.questionnaire(version);
    if (json == null) throw CollectionException('Questionnaire $version absent de la tablette : synchroniser avant de commencer.');
    return _engines[version] = QuestionnaireEngine.fromJson(json);
  }

  /// Date du jour à l'heure du Niger (UTC+1, sans heure d'été), comme le serveur.
  String today() => _clock().toUtc().add(const Duration(hours: 1)).toIso8601String().substring(0, 10);

  Map<String, Object?> structureOf(int csId) {
    final a = db.assignment(csId) ?? (throw CollectionException('Affectation inconnue.'));
    return jsonDecode(a['structure_json'] as String) as Map<String, Object?>;
  }

  /// Collecte existante de l'affectation, ou nouvelle collecte pré-remplie depuis le registre.
  Map<String, Object?> openOrCreate(int csId) {
    final existing = db.collectionByCs(csId);
    if (existing != null) return existing;
    final a = db.assignment(csId) ?? (throw CollectionException('Affectation inconnue.'));
    final campaign = db.campaign(a['campaign_id'] as int) ?? (throw CollectionException('Campagne inconnue : synchroniser.'));
    if (campaign['collection_mode'] == 'PAPIER') {
      throw CollectionException('Cette campagne se fait sur questionnaire papier : les fiches sont saisies dans le back-office web (« Ma saisie »).');
    }
    if (!canCollect(campaign)) throw CollectionException("La campagne « ${campaign['name']} » est clôturée : plus de nouvelle collecte (${campaign['status']}).");
    final version = campaign['questionnaire_version'] as String;
    final data = prefill(engine(version), structureOf(csId));
    final uuid = (a['collection_uuid'] as String?) ?? const Uuid().v4();
    db.transaction(() {
      db.insertCollection(uuid: uuid, csId: csId, campaignId: a['campaign_id'] as int, version: version, data: data);
      db.event(uuid, 'CREATION');
    });
    return db.collection(uuid)!;
  }

  // ----------------------------------------------------------- Centre découvert sur le terrain

  /// Campagnes où l'agent peut créer un centre : ouvertes et collectées sur tablette (pas en mode papier).
  /// Collecte possible sur la tablette tant que la campagne n'est pas clôturée : ouverte, ou suspendue (saisie
  /// conservée sur la tablette, envoi à la réouverture), hors campagnes « papier ».
  static bool canCollect(Map<String, Object?> c) =>
      (c['accepts_submissions'] == 1 || c['status'] == 'OUVERTE' || c['status'] == 'SUSPENDUE') && c['collection_mode'] != 'PAPIER';

  List<Map<String, Object?>> fieldCampaigns() => db.campaigns().where(canCollect).toList();

  /// Campagne affichée sous le nom de l'agent : la plus récente où il peut collecter, sinon la plus récente connue.
  Map<String, Object?>? currentCampaign() {
    final all = db.campaigns();
    if (all.isEmpty) return null;
    return all.firstWhere(canCollect, orElse: () => all.first);
  }

  /// Nom simplifié pour comparer (minuscules, sans accents ni ponctuation, mots génériques retirés).
  static String normalizeName(String s) {
    const accents = {'à': 'a', 'â': 'a', 'ä': 'a', 'é': 'e', 'è': 'e', 'ê': 'e', 'ë': 'e', 'î': 'i', 'ï': 'i', 'ô': 'o', 'ö': 'o', 'ù': 'u', 'û': 'u', 'ü': 'u', 'ç': 'c'};
    final lower = s.toLowerCase().split('').map((ch) => accents[ch] ?? ch).join();
    const generic = {'atelier', 'centre', 'de', 'du', 'des', 'la', 'le', 'les', 'l', 'd', 'et', 'formation', 'groupement', 'garage'};
    return lower.replaceAll(RegExp('[^a-z0-9]+'), ' ').split(' ').where((w) => w.isNotEmpty && !generic.contains(w)).join(' ');
  }

  /// Centres déjà présents sur la tablette, dans la même commune, au nom proche (risque de doublon).
  List<Map<String, Object?>> similarCentres(int communeId, String name) {
    final target = normalizeName(name);
    if (target.length < 3) return const [];
    final words = target.split(' ').toSet();
    final out = <Map<String, Object?>>[];
    for (final a in db.select('SELECT campaign_structure_id, structure_json FROM assignments')) {
      final s = jsonDecode(a['structure_json'] as String) as Map<String, Object?>;
      if (s['commune_id'] != communeId) continue;
      final other = normalizeName('${s['name']}');
      if (other.isEmpty) continue;
      final common = other.split(' ').toSet().intersection(words).length;
      if (other == target || other.contains(target) || target.contains(other) || common / words.length >= 0.6) {
        out.add({'campaign_structure_id': a['campaign_structure_id'], 'name': s['name'], 'locality_label': s['locality_label']});
      }
    }
    return out;
  }

  /// Crée un centre découvert sur le terrain et ouvre aussitôt sa collecte (hors ligne). Le centre est envoyé au
  /// serveur à la prochaine synchronisation, avant sa collecte ; il reste « proposé » jusqu'à la décision du superviseur.
  Map<String, Object?> createFieldCentre({
    required int campaignId,
    required String name,
    required String typeCode,
    String? otherTypeLabel,
    required String legalStatus, // PUBLIC | PRIVE
    required String zone, // URBAINE | RURALE
    required int communeId,
    String? locality,
    Map<String, Object?>? gps, // {lat, lng, accuracy_m, captured_at, status: OK}
  }) {
    final cleanName = name.trim().replaceAll(RegExp(r'\s+'), ' ');
    if (cleanName.length < 3 || cleanName.length > 150) throw CollectionException('Nom du centre : 3 à 150 caractères.');
    final type = db.one('SELECT code, is_other FROM structure_types WHERE code = ?', [typeCode]) ?? (throw CollectionException('Type de structure inconnu : synchroniser.'));
    final isOther = type['is_other'] == 1;
    if (isOther && (otherTypeLabel ?? '').trim().length < 2) throw CollectionException('Préciser le type de structure.');
    if (!['PUBLIC', 'PRIVE'].contains(legalStatus)) throw CollectionException('Choisir le statut : public ou privé.');
    if (!['URBAINE', 'RURALE'].contains(zone)) throw CollectionException('Choisir la zone : urbaine ou rurale.');
    final commune = db.one('SELECT c.id, c.department_id, d.region_id FROM communes c JOIN departments d ON d.id = c.department_id WHERE c.id = ?', [communeId])
        ?? (throw CollectionException('Commune inconnue : synchroniser.'));
    final campaign = fieldCampaigns().where((c) => c['id'] == campaignId).firstOrNull ?? (throw CollectionException("Cette campagne n'accepte pas de nouveau centre (clôturée)."));
    // Questionnaire présent sur la tablette AVANT toute écriture (sinon le centre serait créé sans pouvoir être saisi).
    final version = campaign['questionnaire_version'] as String;
    if (db.questionnaire(version) == null) {
      throw CollectionException("Le questionnaire $version de la campagne n'est pas encore sur la tablette : synchroniser (connexion internet), puis réessayer.");
    }

    final uuid = const Uuid().v4();
    final tempCs = db.nextTempCsId();
    final ok = gps != null && gps['status'] == 'OK' && gps['lat'] is num && gps['lng'] is num;
    final structure = <String, Object?>{
      'uuid': uuid, 'official_code': null, 'name': cleanName, 'status': 'PROPOSEE',
      'region_id': commune['region_id'], 'department_id': commune['department_id'], 'commune_id': communeId,
      'type_code': typeCode, 'zone': zone == 'URBAINE' ? 'a' : 'b', 'legal_status': legalStatus == 'PUBLIC' ? 'a' : 'b',
      'locality_label': (locality ?? '').trim().isEmpty ? null : locality!.trim(),
      'latitude': ok ? gps['lat'] : null, 'longitude': ok ? gps['lng'] : null,
    };
    final payload = <String, Object?>{
      'uuid': uuid, 'name': cleanName, 'type_code': typeCode, 'other_type_label': isOther ? otherTypeLabel!.trim() : null,
      'legal_status': legalStatus, 'zone': zone, 'commune_id': communeId, 'locality_label': structure['locality_label'],
      if (ok) ...{'latitude': gps['lat'], 'longitude': gps['lng'], 'gps_accuracy_m': gps['accuracy_m']},
    };
    db.transaction(() {
      db.upsertAssignment({'campaign_structure_id': tempCs, 'campaign_id': campaignId, 'collection_uuid': null, 'structure': structure});
      db.addPendingStructure(uuid: uuid, campaignId: campaignId, tempCsId: tempCs, payload: payload);
    });
    final col = openOrCreate(tempCs);
    final data = dataOf(col);
    if (ok) data['GPS'] = {'lat': gps['lat'], 'lng': gps['lng'], 'accuracy_m': gps['accuracy_m'], 'captured_at': gps['captured_at'], 'status': 'OK', 'note': null};
    if (isOther) data['Q1_9_autre'] = otherTypeLabel!.trim();
    save(col, data);
    db.event(col['uuid'] as String, 'CENTRE_CREE_SUR_LE_TERRAIN', cleanName);
    return db.collection(col['uuid'] as String)!;
  }

  /// Pré-remplissage déclaré dans la définition (`prefill`) : identité de la structure, date du jour.
  Map<String, Object?> prefill(QuestionnaireEngine engine, Map<String, Object?> structure) {
    final data = <String, Object?>{};
    for (final s in engine.sections) {
      for (final item in (s['items'] as List).cast<Map<String, Object?>>()) {
        final src = item['prefill'] as String?;
        if (src == null) continue;
        Object? v;
        if (src.startsWith('structure.')) v = structure[src.substring(10)];
        if (src == 'ctx.today') v = today();
        if (v != null && v != '') QuestionnaireEngine.setPath(data, (item['code'] as String).split('.'), v);
      }
    }
    return data;
  }

  /// Contexte d'évaluation `_ctx` : date, campagne, seuils et métiers admis transmis par le serveur (/sync/pull).
  Map<String, Object?> context(Map<String, Object?> collection, Map<String, Object?> data) {
    final campaign = db.campaign(collection['campaign_id'] as int);
    final shared = db.kvJson('sync.context') ?? const {};
    final t = today();
    Map<String, Object?>? options = (shared['options'] as Map?)?.cast<String, Object?>();
    if (options == null) {
      // Repli (contexte pas encore reçu) : métiers du référentiel local ; le serveur reste l'arbitre final.
      options = {};
      for (final s in db.select('SELECT id FROM sectors')) {
        options['trades.${s['id']}'] = [...db.tradesOfSector(s['id'] as int).map((t) => t['id']), 'AUTRE'];
      }
    }
    return {
      'today': t,
      'current_year': int.parse(t.substring(0, 4)),
      'campaign_opens_on': campaign?['opens_on'],
      'gps_distance_km': _gpsDistanceKm(collection, data),
      'gps_max_km': (shared['gps_max_km'] as num?)?.toDouble() ?? 15.0,
      'max_apprentices_per_trainer': (shared['max_apprentices_per_trainer'] as num?)?.toInt() ?? 60,
      'options': options,
    };
  }

  double? _gpsDistanceKm(Map<String, Object?> collection, Map<String, Object?> data) {
    final gps = data['GPS'];
    if (gps is! Map || !(gps['status'] == 'OK' || gps['status'] == 'MANUEL') || gps['lat'] is! num || gps['lng'] is! num) return null;
    final structure = structureOf(collection['campaign_structure_id'] as int);
    final c = db.one('SELECT centroid_lat, centroid_lng FROM communes WHERE id = ?', [structure['commune_id']]);
    if (c == null || c['centroid_lat'] == null || c['centroid_lng'] == null) return null; // centroïdes absents du RENACOM
    const r = 6371.0;
    double rad(num d) => d * pi / 180;
    final lat1 = rad(gps['lat'] as num), lat2 = rad(c['centroid_lat'] as num);
    final dLat = lat2 - lat1, dLng = rad(c['centroid_lng'] as num) - rad(gps['lng'] as num);
    final h = pow(sin(dLat / 2), 2) + cos(lat1) * cos(lat2) * pow(sin(dLng / 2), 2);
    final km = 2 * r * asin(sqrt(h));
    return (km * 100).round() / 100;
  }

  Map<String, Object?> dataOf(Map<String, Object?> collection) => jsonDecode(collection['data_json'] as String) as Map<String, Object?>;

  ValidationResult validate(Map<String, Object?> collection, Map<String, Object?> data) {
    final e = engine(collection['questionnaire_version'] as String);
    return e.validate({...data, '_ctx': context(collection, data)});
  }

  static bool isEditable(Map<String, Object?> collection) =>
      collection['local_status'] == LocalStatus.brouillon || collection['local_status'] == LocalStatus.invalidee;

  /// Sauvegarde automatique (appelée à chaque modification de champ).
  ValidationResult save(Map<String, Object?> collection, Map<String, Object?> data) {
    if (!isEditable(collection)) throw CollectionException("Cette collecte n'est plus modifiable sur la tablette.");
    final r = validate(collection, data);
    db.saveDraft(collection['uuid'] as String, data, r.errors.length, r.warnings.length);
    return r;
  }

  /// « Terminer » : aucune erreur bloquante, puis mise en file d'envoi (la collecte n'est plus modifiable).
  ValidationResult finish(String uuid) {
    final col = db.collection(uuid) ?? (throw CollectionException('Collecte inconnue.'));
    if (!isEditable(col)) throw CollectionException('Cette collecte a déjà été terminée.');
    final campaign = db.campaign(col['campaign_id'] as int);
    if (campaign?['accepts_submissions'] != 1) {
      throw CollectionException(campaign?['status'] == 'SUSPENDUE'
          ? 'La campagne est suspendue : la collecte reste enregistrée sur la tablette. Terminez-la dès que la campagne sera rouverte.'
          : "La campagne n'accepte plus de soumission (${campaign?['status'] ?? 'inconnue'}). La collecte reste enregistrée sur la tablette.");
    }
    final data = dataOf(col);
    final r = validate(col, data);
    if (!r.valid) return r;

    final clean = Map<String, Object?>.of(r.data);
    final e = engine(col['questionnaire_version'] as String);
    for (final c in ((e.definition['computed'] as List?) ?? const []).cast<Map>()) {
      clean.remove((c['code'] as String).split('.').first); // recalculées par le serveur
    }
    final payload = {
      'campaign_structure_id': col['campaign_structure_id'],
      'questionnaire_version': col['questionnaire_version'],
      'base_revision': col['server_revision'],
      'device_started_at': DateTime.parse(col['started_at'] as String).toUtc().toIso8601String(),
      'device_completed_at': _clock().toUtc().toIso8601String(),
      'data': clean,
    };
    final hash = sha256Hex(canonicalJson({'campaign_structure_id': payload['campaign_structure_id'], 'questionnaire_version': payload['questionnaire_version'], 'data': clean}));
    db.transaction(() {
      db.cancelPendingOutbox(uuid);
      db.replaceData(uuid, clean);
      db.enqueue(uuid, col['server_revision'] as int, jsonEncode(payload), hash);
      db.setCollectionStatus(uuid, LocalStatus.terminee, completed: true);
      db.event(uuid, 'TERMINEE', '${r.warnings.length} avertissement(s)');
    });
    return r;
  }

  /// Reprendre une collecte terminée mais pas encore envoyée (retour en brouillon, envoi annulé).
  void reopenLocal(String uuid) {
    final col = db.collection(uuid) ?? (throw CollectionException('Collecte inconnue.'));
    final inFlight = db.one("SELECT id FROM outbox WHERE collection_uuid = ? AND state = 'EN_COURS'", [uuid]);
    if (col['local_status'] != LocalStatus.terminee || inFlight != null) {
      throw CollectionException('Envoi en cours ou déjà effectué : la collecte ne peut plus être reprise.');
    }
    db.transaction(() {
      db.cancelPendingOutbox(uuid);
      db.setCollectionStatus(uuid, (col['server_revision'] as int) > 0 ? LocalStatus.invalidee : LocalStatus.brouillon);
      db.event(uuid, 'REPRISE_LOCALE');
    });
  }

  /// Ajoute la photo de la fiche papier signée (envoyée après l'acceptation de la collecte).
  void addSignedFormPhoto(String uuid, String path) {
    db.addAttachment(uuid, path, 'FICHE_SIGNEE');
    db.event(uuid, 'PHOTO_AJOUTEE');
  }
}
