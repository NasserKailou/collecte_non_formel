// Moteur de validation d'une collecte — portage fidèle de backend/src/Questionnaire/QuestionnaireEngine.php.
//
// Spécification : docs/architecture/questionnaire-et-regles.md §3. Ordre : visibilité + nettoyage + contrôles
// génériques → variables calculées → règles. Une règle n'est pas évaluée si l'une de ses cibles porte déjà une
// erreur générique. Vérifié contre le serveur par shared/rules-fixtures/eftp-nf-2025.1.json.

import 'dart:convert';

import 'json_logic.dart';

class Issue {
  const Issue(this.code, this.severity, this.target, this.message);
  final String code;
  final String severity; // error | warning
  final String target;
  final String message;

  bool get isError => severity == 'error';
  Map<String, Object?> toJson() => {'code': code, 'severity': severity, 'target': target, 'message': message};

  factory Issue.fromJson(Map<String, Object?> j) =>
      Issue(j['code'].toString(), (j['severity'] ?? 'error').toString(), (j['target'] ?? '').toString(), (j['message'] ?? '').toString());
}

class ValidationResult {
  const ValidationResult({required this.errors, required this.warnings, required this.cleared, required this.computed, required this.data});
  final List<Issue> errors;
  final List<Issue> warnings;
  final List<String> cleared;
  final Map<String, Object?> computed;

  /// Données nettoyées (valeurs masquées supprimées, variables calculées ajoutées), sans `_ctx`.
  final Map<String, Object?> data;

  bool get valid => errors.isEmpty;
}

class QuestionnaireEngine {
  QuestionnaireEngine(this.definition) {
    if (definition['schema'] != 1) {
      throw ArgumentError('Définition de questionnaire : schéma non pris en charge.');
    }
  }

  factory QuestionnaireEngine.fromJson(String json) => QuestionnaireEngine(jsonDecode(json) as Map<String, Object?>);

  final Map<String, Object?> definition;

  static const _skippedTypes = {'computed', 'computed_grid', 'auto', 'geo', 'note'};

  /// Question attendue mais non renseignée : simple avertissement, jamais bloquant (textes identiques au serveur).
  static const msgRequired = 'Non renseigné : cette question devrait normalement être remplie.';
  static const msgRequiredGps = "Position GPS non relevée : la relever devant le centre, ou indiquer pourquoi c'est impossible.";

  /// Contrôles génériques qui restent bloquants (valeur impossible ou ligne en double) ; les autres sont des avertissements.
  static const blockingCodes = {'TYPE', 'OPTION', 'RANGE', 'DATE', 'DUPLICATE'};
  static const _latMin = 11.0, _latMax = 24.0, _lngMin = 0.0, _lngMax = 16.5;

  late Map<String, Object?> _data;
  final List<Issue> _issues = [];
  final List<String> _cleared = [];
  final Set<String> _errorTargets = {};
  final List<(Map<String, Object?>, String)> _rosters = [];

  String get version => definition['version'].toString();

  List<Map<String, Object?>> get sections => (definition['sections'] as List).cast<Map<String, Object?>>();

  /// [data] : données de la collecte, avec `_ctx` (contexte d'évaluation). Les données d'entrée ne sont pas modifiées.
  ValidationResult validate(Map<String, Object?> data) {
    _data = deepCopy(data) as Map<String, Object?>;
    _issues.clear();
    _cleared.clear();
    _errorTargets.clear();
    _rosters.clear();

    for (final section in sections) {
      for (final item in (section['items'] as List).cast<Map<String, Object?>>()) {
        _processItem(item);
      }
    }

    final computed = <String, Object?>{};
    for (final c in ((definition['computed'] as List?) ?? const []).cast<Map<String, Object?>>()) {
      final value = JsonLogic.apply(c['expr'], _data);
      final segments = (c['code'] as String).split('.');
      setPath(_data, segments, value);
      setPath(computed, segments, value);
    }

    for (final rule in ((definition['rules'] as List?) ?? const []).cast<Map<String, Object?>>()) {
      _evaluateRule(rule, _data, (t) => t);
    }
    for (final (item, code) in _rosters) {
      final rows = JsonLogic.varPath(code, _data);
      final list = rows is List ? rows : const [];
      for (final rule in ((item['row_rules'] as List?) ?? const []).cast<Map<String, Object?>>()) {
        for (var i = 0; i < list.length; i++) {
          final row = _asRow(list[i]);
          if (row == null) continue;
          _evaluateRule(rule, _rowContext(row, i), (t) => '$code[$i].$t');
        }
      }
    }

    final clean = Map<String, Object?>.of(_data)..remove('_ctx');
    return ValidationResult(
      errors: _issues.where((i) => i.isError).toList(),
      warnings: _issues.where((i) => !i.isError).toList(),
      cleared: List.of(_cleared),
      computed: computed,
      data: clean,
    );
  }

  /// Visibilité d'un élément (ou d'une colonne) évaluée sur [ctx].
  bool isVisible(Map<String, Object?> item, Map<String, Object?> ctx) =>
      !item.containsKey('visible_if') || JsonLogic.truthy(JsonLogic.apply(item['visible_if'], ctx));

  // ------------------------------------------------------------------- Éléments

  void _processItem(Map<String, Object?> item) {
    final type = item['type'] as String;
    if (_skippedTypes.contains(type)) return;
    final code = item['code'] as String;
    final segments = code.split('.');

    if (!isVisible(item, _data)) {
      if (!isEmptyValue(getPath(_data, segments))) _cleared.add(code);
      unsetPath(_data, segments);
      return;
    }
    switch (type) {
      case 'roster':
        _processRoster(item, segments, code);
      case 'grid':
        _processGrid(item, segments, code);
      default:
        _checkValue(item, getPath(_data, segments), code);
    }
  }

  /// Ligne de tableau sous forme de Map (une liste vide vaut une ligne vide, comme en PHP) ; null si invalide.
  static Map<String, Object?>? _asRow(Object? row) {
    if (row is Map) return Map<String, Object?>.from(row);
    if (row is List && row.isEmpty) return <String, Object?>{};
    return null;
  }

  /// Contexte d'une ligne : union PHP « $row + ['_line' => …, '_root' => …] » (les clés de la ligne l'emportent).
  Map<String, Object?> _rowContext(Map<String, Object?> row, int i) => Map<String, Object?>.of(row)
    ..putIfAbsent('_line', () => i + 1)
    ..putIfAbsent('_root', () => _data);

  void _processRoster(Map<String, Object?> item, List<String> segments, String code) {
    var raw = getPath(_data, segments) ?? <Object?>[];
    if (raw is Map && raw.isEmpty) raw = <Object?>[];
    if (raw is! List) {
      _addIssue('TYPE', 'error', code, 'Tableau invalide.');
      return;
    }
    final rows = List<Object?>.of(raw);
    final n = rows.length;
    final minRows = item['min_rows'] as num?;
    final maxRows = item['max_rows'] as num?;
    if (minRows != null && n < minRows) _addIssue('ROWS', 'error', code, 'Au moins ${JsonLogic.phpString(minRows)} ligne(s) attendue(s).');
    if (maxRows != null && n > maxRows) _addIssue('ROWS', 'error', code, 'Au plus ${JsonLogic.phpString(maxRows)} ligne(s) autorisée(s).');

    final columns = (item['columns'] as List).cast<Map<String, Object?>>();
    for (var i = 0; i < n; i++) {
      final row = _asRow(rows[i]);
      if (row == null) {
        _addIssue('TYPE', 'error', '$code[$i]', 'Ligne invalide.');
        continue;
      }
      for (final col in columns) {
        final colCode = col['code'] as String;
        final target = '$code[$i].$colCode';
        if (!isVisible(col, _rowContext(row, i))) {
          if (row.containsKey(colCode) && !isEmptyValue(row[colCode])) _cleared.add(target);
          row.remove(colCode);
          continue;
        }
        _checkValue(col, row[colCode], target);
      }
      rows[i] = row;
    }
    setPath(_data, segments, rows);

    final unique = (item['unique'] as List?)?.cast<String>();
    if (unique != null && unique.isNotEmpty) {
      final seen = <String, int>{};
      for (var i = 0; i < rows.length; i++) {
        final row = rows[i];
        if (row is! Map) continue;
        final parts = unique.map((c) {
          final v = row[c];
          return (v is String || v is num || v is bool) ? JsonLogic.phpString(v) : '';
        }).toList();
        if (parts.join() == '') continue;
        final key = parts.join('\u001F');
        final prev = seen[key];
        if (prev != null) {
          _addIssue('DUPLICATE', 'error', '$code[$i].${unique[0]}', 'Ligne ${i + 1} : doublon de la ligne ${prev + 1}.');
        } else {
          seen[key] = i;
        }
      }
    }
    _rosters.add((item, code));
  }

  void _processGrid(Map<String, Object?> item, List<String> segments, String code) {
    final grid = getPath(_data, segments);
    if (grid != null && grid is! Map && grid is! List) {
      _addIssue('TYPE', 'error', code, 'Grille invalide.');
      return;
    }
    // Union PHP « $cell + ['required' => …] » : la clé de la cellule l'emporte.
    final cell = Map<String, Object?>.of(item['cell'] as Map<String, Object?>)..putIfAbsent('required', () => _truthyFlag(item['required']));
    for (final r in (item['rows'] as List).cast<Map<String, Object?>>()) {
      for (final c in (item['columns'] as List).cast<Map<String, Object?>>()) {
        final cellSegments = [...segments, r['code'] as String, ...(c['code'] as String).split('.')];
        _checkValue(cell, getPath(_data, cellSegments), '$code.${r['code']}.${c['code']}');
      }
    }
  }

  // ----------------------------------------------------------- Contrôles génériques

  void _checkValue(Map<String, Object?> item, Object? value, String target) {
    final type = item['type'] as String;
    if (type == 'gps') {
      _checkGps(item, value, target);
      return;
    }
    if (isEmptyValue(value)) {
      if (_truthyFlag(item['required'])) _addIssue('REQUIRED', 'warning', target, msgRequired);
      return;
    }
    switch (type) {
      case 'text':
        if (value is! String) {
          _addIssue('TYPE', 'error', target, 'Texte attendu.');
          return;
        }
        final len = phpTrim(value).runes.length;
        final minL = item['min_length'] as num?;
        final maxL = item['max_length'] as num?;
        if ((minL != null && len < minL) || (maxL != null && len > maxL)) {
          _addIssue('LENGTH', 'error', target, 'Longueur attendue : ${(minL ?? 0).toInt()} à ${(maxL ?? 9999).toInt()} caractères.');
          return;
        }
        final pattern = item['pattern'] as String?;
        if (pattern != null && !RegExp(pattern, unicode: true).hasMatch(value)) {
          _addIssue('PATTERN', 'error', target, 'Format invalide.');
        }
      case 'phone':
        if (value is! String || !RegExp(r'^[0-9]{8}$').hasMatch(value)) {
          _addIssue('PATTERN', 'error', target, 'Numéro de téléphone invalide : 8 chiffres attendus.');
        }
      case 'integer':
        if (value is! int) {
          _addIssue('TYPE', 'error', target, 'Nombre entier attendu.');
          return;
        }
        final min = item['min'] as num?;
        final max = item['max'] as num?;
        if ((min != null && value < min) || (max != null && value > max)) {
          _addIssue('RANGE', 'error', target,
              'Valeur attendue entre ${min == null ? '−∞' : JsonLogic.phpString(min)} et ${max == null ? '+∞' : JsonLogic.phpString(max)}.');
        }
      case 'date':
        final partial = _truthyFlag(item['partial']);
        if (value is! String || !isValidDate(value, partial)) {
          _addIssue('DATE', 'error', target,
              partial ? 'Date invalide (formats acceptés : AAAA-MM-JJ, AAAA-MM ou AAAA).' : 'Date invalide (format attendu : AAAA-MM-JJ).');
        }
      case 'yesno':
        if (value is! bool) _addIssue('TYPE', 'error', target, 'Réponse Oui/Non attendue.');
      case 'single':
        if (value is! String && value is! int) {
          _addIssue('TYPE', 'error', target, 'Choix invalide.');
          return;
        }
        final allowed = _allowedCodes(item);
        if (allowed != null && !allowed.contains(JsonLogic.phpString(value))) _addIssue('OPTION', 'error', target, 'Modalité inconnue.');
      case 'multi':
        if (value is! List) {
          _addIssue('TYPE', 'error', target, 'Liste de choix attendue.');
          return;
        }
        final asStrings = value.map((v) => (v is String || v is num || v is bool) ? JsonLogic.phpString(v) : '').toList();
        if (asStrings.toSet().length != asStrings.length) {
          _addIssue('DUPLICATE', 'error', target, 'Une modalité est cochée plusieurs fois.');
          return;
        }
        final allowed = _allowedCodes(item);
        for (final v in value) {
          if (v is! String || (allowed != null && !allowed.contains(v))) {
            _addIssue('OPTION', 'error', target, 'Modalité inconnue.');
            return;
          }
        }
      default:
        throw StateError("Type d'élément inconnu : « $type »");
    }
  }

  void _checkGps(Map<String, Object?> item, Object? value, String target) {
    if (isEmptyValue(value)) {
      if (_truthyFlag(item['required'])) _addIssue('REQUIRED', 'warning', target, msgRequiredGps);
      return;
    }
    if (value is! Map && value is! List) {
      _addIssue('TYPE', 'error', target, 'Coordonnées GPS invalides.');
      return;
    }
    final m = value is Map ? value : const {};
    final status = m['status'];
    if (status == 'OK' || status == 'MANUEL') {
      final lat = m['lat'];
      final lng = m['lng'];
      if (lat is! num || lng is! num) {
        _addIssue('GPS', 'error', target, 'Latitude et longitude numériques attendues.');
      } else if (lat < _latMin || lat > _latMax || lng < _lngMin || lng > _lngMax) {
        _addIssue('GPS', 'error', target, 'Position hors du territoire du Niger.');
      }
      return;
    }
    if (status == 'INDISPONIBLE' || status == 'REFUSE') {
      final note = m['note'];
      if (note is! String || phpTrim(note).runes.length < 3) {
        _addIssue('GPS', 'error', target, "Indiquer pourquoi la position GPS n'a pas pu être relevée.");
      }
      return;
    }
    _addIssue('GPS', 'error', target, 'Statut GPS inconnu.');
  }

  /// Codes admis ; null si la liste de référence n'est pas fournie (contrôle ignoré).
  List<String>? _allowedCodes(Map<String, Object?> item) {
    final options = item['options'];
    if (options is List) return options.map((o) => JsonLogic.phpString((o as Map)['code'])).toList();
    final source = item['options_source'];
    if (source is String) {
      final list = JsonLogic.varPath('_ctx.options', _data);
      final l = list is Map ? list[source] : null;
      if (l is List) return l.map(JsonLogic.phpString).toList();
      if (l is Map) return l.values.map(JsonLogic.phpString).toList();
    }
    return null;
  }

  // ---------------------------------------------------------------------- Règles

  void _evaluateRule(Map<String, Object?> rule, Map<String, Object?> ctx, String Function(String) resolve) {
    final targets = ((rule['targets'] as List?) ?? const []).cast<String>().map(resolve).toList();
    if (targets.any(_errorTargets.contains)) return;
    if (rule.containsKey('when') && !JsonLogic.truthy(JsonLogic.apply(rule['when'], ctx))) return;
    if (!JsonLogic.truthy(JsonLogic.apply(rule['assert'], ctx))) {
      _addIssue(rule['code'] as String, rule['severity'] as String, targets.isEmpty ? '' : targets.first,
          interpolate(rule['message'] as String, ctx),
          generic: false);
    }
  }

  static final _marker = RegExp(r'\{([A-Za-z0-9_.]+)\}');

  /// Remplace les marqueurs `{chemin}` (format identique au serveur : nombres à la française, Oui/Non).
  static String interpolate(String message, Map<String, Object?> ctx) => message.replaceAllMapped(_marker, (m) {
        final v = JsonLogic.varPath(m[1], ctx);
        if (v == null) return '—';
        if (v is bool) return v ? 'Oui' : 'Non';
        if (v is double) {
          final s = formatFr(v, 2);
          return s.contains(',') ? s.replaceFirst(RegExp(r'0+$'), '').replaceFirst(RegExp(r',$'), '') : s;
        }
        if (v is List || v is Map) return jsonEncode(v);
        return v.toString();
      });

  /// number_format($v, $dec, ',', ' ') de PHP.
  static String formatFr(double v, int decimals) {
    final fixed = v.abs().toStringAsFixed(decimals);
    final parts = fixed.split('.');
    final intPart = parts[0].replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => ' ');
    final sign = v < 0 && double.parse(fixed) != 0 ? '-' : '';
    return parts.length > 1 ? '$sign$intPart,${parts[1]}' : '$sign$intPart';
  }

  void _addIssue(String code, String severity, String target, String message, {bool generic = true}) {
    // Saisie sans blocage (comme le serveur) : seuls les contrôles d'intégrité restent bloquants.
    final shown = generic && severity == 'error' && !blockingCodes.contains(code) ? 'warning' : severity;
    _issues.add(Issue(code, shown, target, message));
    // Une erreur générique ou une réponse manquante suspend les règles qui portent sur la même cible (comme le serveur).
    if (generic && (severity == 'error' || code == 'REQUIRED')) _errorTargets.add(target);
  }

  // ------------------------------------------------------------------- Utilitaires

  /// empty() de PHP, inversé.
  static bool _truthyFlag(Object? v) => !(v == null || v == false || v == 0 || v == '' || v == '0' || (v is List && v.isEmpty) || (v is Map && v.isEmpty));

  /// trim() de PHP : espaces, tabulations, retours, NUL et tabulation verticale seulement.
  static String phpTrim(String s) => s.replaceAll(RegExp('^[ \t\n\r\u0000\u000B]+|[ \t\n\r\u0000\u000B]+\$'), '');

  static bool isEmptyValue(Object? v) =>
      v == null || (v is List && v.isEmpty) || (v is Map && v.isEmpty) || (v is String && phpTrim(v).isEmpty);

  static bool isValidDate(String v, bool partial) {
    var m = RegExp(r'^(\d{4})-(\d{2})-(\d{2})$').firstMatch(v);
    if (m != null) {
      final y = int.parse(m[1]!), mo = int.parse(m[2]!), d = int.parse(m[3]!);
      if (mo < 1 || mo > 12 || d < 1 || y < 1) return false;
      return d <= DateTime.utc(y, mo + 1, 0).day;
    }
    if (!partial) return false;
    m = RegExp(r'^(\d{4})-(\d{2})$').firstMatch(v);
    if (m != null) {
      final mo = int.parse(m[2]!);
      return mo >= 1 && mo <= 12;
    }
    return RegExp(r'^\d{4}$').hasMatch(v);
  }

  static Object? getPath(Object? data, List<String> segments) {
    Object? cur = data;
    for (final s in segments) {
      if (!JsonLogic.hasChild(cur, s)) return null;
      cur = JsonLogic.child(cur, s);
    }
    return cur;
  }

  /// Écrit [value] au chemin, en créant les niveaux manquants (comme setPath du serveur).
  static void setPath(Map<String, Object?> data, List<String> segments, Object? value) {
    Object container = data;
    for (var k = 0; k < segments.length; k++) {
      final s = segments[k];
      final last = k == segments.length - 1;
      if (container is Map) {
        if (last) {
          container[s] = value;
          return;
        }
        final next = container[s];
        if (next is Map || (next is List && next.isNotEmpty)) {
          container = next!;
        } else {
          final created = <String, Object?>{};
          container[s] = created;
          container = created;
        }
      } else if (container is List) {
        final i = int.tryParse(s);
        if (i == null || i < 0 || i >= container.length) return; // hors du modèle : ignoré
        if (last) {
          container[i] = value;
          return;
        }
        final next = container[i];
        if (next is Map || (next is List && next.isNotEmpty)) {
          container = next!;
        } else {
          final created = <String, Object?>{};
          container[i] = created;
          container = created;
        }
      }
    }
  }

  static void unsetPath(Map<String, Object?> data, List<String> segments) {
    Object? cur = data;
    for (var k = 0; k < segments.length - 1; k++) {
      if (!JsonLogic.hasChild(cur, segments[k])) return;
      cur = JsonLogic.child(cur, segments[k]);
      if (cur is! Map && cur is! List) return;
    }
    final last = segments.last;
    if (cur is Map) {
      cur.remove(last);
    } else if (cur is List) {
      final i = int.tryParse(last);
      if (i != null && i >= 0 && i < cur.length) cur.removeAt(i);
    }
  }

  /// Copie profonde et modifiable (Map<String, Object?> / List<Object?>).
  static Object? deepCopy(Object? v) {
    if (v is Map) return <String, Object?>{for (final e in v.entries) e.key.toString(): deepCopy(e.value)};
    if (v is List) return v.map(deepCopy).toList();
    return v;
  }
}
