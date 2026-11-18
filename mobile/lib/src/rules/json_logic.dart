// Évaluateur d'un sous-ensemble strict de JsonLogic — portage fidèle de backend/src/Rules/JsonLogic.php.
//
// Sémantique : docs/architecture/questionnaire-et-regles.md §4. Toute divergence avec le serveur est un
// défaut : les deux implémentations rejouent shared/rules-fixtures/jsonlogic.json.
//
// Différence de modèle de données à garder en tête : en PHP, un objet JSON vide et un tableau vide sont le même
// « [] ». Ici, une Map vide est donc traitée comme une liste vide partout où PHP ne peut pas les distinguer.

class JsonLogicException implements Exception {
  JsonLogicException(this.message);
  final String message;
  @override
  String toString() => 'JsonLogicException: $message';
}

abstract final class JsonLogic {
  static const _arrayOps = {'map', 'filter', 'reduce', 'all', 'some', 'none'};
  static final _phpIntKey = RegExp(r'^(0|-?[1-9][0-9]*)$');

  /// Évalue [logic] (expression ou littéral) sur [data].
  static Object? apply(Object? logic, [Object? data]) {
    if (logic is List) return logic.map((l) => apply(l, data)).toList();
    if (logic is Map && logic.isEmpty) return <Object?>[];
    if (!_isOperation(logic)) return logic;

    final map = logic as Map;
    final op = map.keys.first as String;
    final raw = map[op];
    final List<Object?> args = raw is List ? raw : (raw is Map && raw.isEmpty ? const [] : [raw]);

    switch (op) {
      case 'var':
        final def = args.length > 1 ? apply(args[1], data) : null;
        return varPath(apply(args.isNotEmpty ? args[0] : '', data), data, def);
      case 'if':
        return _opIf(args, data);
      case 'and':
        Object? last = true;
        for (final a in args) {
          last = apply(a, data);
          if (!truthy(last)) return last;
        }
        return last;
      case 'or':
        Object? last = false;
        for (final a in args) {
          last = apply(a, data);
          if (truthy(last)) return last;
        }
        return last;
    }
    if (_arrayOps.contains(op)) return _arrayOp(op, args, data);

    final v = args.map((a) => apply(a, data)).toList();
    Object? at(int i) => i < v.length ? v[i] : null;
    switch (op) {
      case '==':
        return equals(at(0), at(1));
      case '!=':
        return !equals(at(0), at(1));
      case '<':
        return _compareChain(v, (c) => c < 0);
      case '<=':
        return _compareChain(v, (c) => c <= 0);
      case '>':
        return _compare(at(0), at(1), (c) => c > 0);
      case '>=':
        return _compare(at(0), at(1), (c) => c >= 0);
      case '!':
        return !truthy(at(0));
      case '!!':
        return truthy(at(0));
      case 'in':
        return _in(at(0), at(1));
      case '+':
        return _add(v);
      case '-':
        return _subtract(v);
      case '*':
        return _multiply(v);
      case '/':
        return _divide(at(0), at(1));
      case 'max':
        return _extremum(v, true);
      case 'min':
        return _extremum(v, false);
      default:
        throw JsonLogicException('Opérateur non autorisé : « $op »');
    }
  }

  /// Vérité JsonLogic : false, null, 0, "" et [] (ou {}) sont faux.
  static bool truthy(Object? v) {
    if (v is List) return v.isNotEmpty;
    if (v is Map) return v.isNotEmpty;
    return !(v == null || v == false || v == '' || (v is num && v == 0));
  }

  /// Lecture d'un chemin pointé (« a.b.0.c ») dans des données imbriquées.
  static Object? varPath(Object? path, Object? data, [Object? def]) {
    if (path == null || path == '') return data;
    Object? cur = data;
    for (final seg in phpString(path).split('.')) {
      final next = child(cur, seg);
      if (identical(next, _missing)) return def;
      cur = next;
    }
    return cur ?? def;
  }

  static final Object _missing = Object();

  /// Enfant [seg] d'une Map ou d'une liste (indice numérique) ; [_missing] s'il n'existe pas.
  static Object? child(Object? cur, String seg) {
    if (cur is Map) return cur.containsKey(seg) ? cur[seg] : _missing;
    if (cur is List) {
      final i = int.tryParse(seg);
      if (i == null || !_phpIntKey.hasMatch(seg) || i < 0 || i >= cur.length) return _missing;
      return cur[i];
    }
    return _missing;
  }

  static bool hasChild(Object? cur, String seg) => !identical(child(cur, seg), _missing);

  /// Conversion en chaîne à la manière de PHP (`(string) $v`).
  static String phpString(Object? v) {
    if (v == null) return '';
    if (v is bool) return v ? '1' : '';
    if (v is double) {
      if (v.isFinite && v == v.truncateToDouble() && v.abs() < 1e15) return v.toInt().toString();
      return v.toString();
    }
    return v.toString();
  }

  static bool _isOperation(Object? logic) =>
      logic is Map && logic.length == 1 && logic.keys.first is String && !_phpIntKey.hasMatch(logic.keys.first as String);

  static bool _isNumber(Object? v) => v is num;

  /// Égalité sans conversion de type, sauf entre nombres ; égalité profonde pour les tableaux (=== de PHP).
  static bool equals(Object? a, Object? b) {
    if (_isNumber(a) && _isNumber(b)) return a == b;
    return deepEquals(a, b);
  }

  /// Identité stricte PHP : types identiques (1 ≠ 1.0), tableaux comparés en profondeur ; [] et {} vides égaux.
  static bool deepEquals(Object? a, Object? b) {
    final ea = (a is List && a.isEmpty) || (a is Map && a.isEmpty);
    final eb = (b is List && b.isEmpty) || (b is Map && b.isEmpty);
    if (ea || eb) return ea && eb;
    if (a is List && b is List) {
      if (a.length != b.length) return false;
      for (var i = 0; i < a.length; i++) {
        if (!deepEquals(a[i], b[i])) return false;
      }
      return true;
    }
    if (a is Map && b is Map) {
      if (a.length != b.length) return false;
      for (final k in a.keys) {
        if (!b.containsKey(k) || !deepEquals(a[k], b[k])) return false;
      }
      return true;
    }
    if (a is int && b is double || a is double && b is int) return false;
    return a == b;
  }

  static bool _compare(Object? a, Object? b, bool Function(int) test) {
    if (a is num && b is num) return test(a.compareTo(b).sign);
    if (a is String && b is String) return test(a.compareTo(b).sign);
    return false;
  }

  /// Forme « entre » : {"<": [a, b, c]} ⇔ a < b < c.
  static bool _compareChain(List<Object?> v, bool Function(int) test) {
    if (v.length < 2) return false;
    for (var i = 0; i < v.length - 1; i++) {
      if (!_compare(v[i], v[i + 1], test)) return false;
    }
    return true;
  }

  static Object? _opIf(List<Object?> args, Object? data) {
    final n = args.length;
    for (var i = 0; i + 1 < n; i += 2) {
      if (truthy(apply(args[i], data))) return apply(args[i + 1], data);
    }
    return n.isOdd ? apply(args[n - 1], data) : null;
  }

  static bool _in(Object? needle, Object? haystack) {
    if (haystack is List) return haystack.any((h) => equals(needle, h));
    if (haystack is Map) return haystack.values.any((h) => equals(needle, h));
    if (haystack is String && needle is String) return haystack.contains(needle);
    return false;
  }

  /// null vaut 0 ; toute autre valeur non numérique rend le résultat null.
  static num? _add(List<Object?> v) {
    num sum = 0;
    for (final x in v) {
      if (x == null) continue;
      if (x is! num) return null;
      sum += x;
    }
    return sum;
  }

  static num? _subtract(List<Object?> v) {
    for (final x in v) {
      if (x != null && x is! num) return null;
    }
    num at(int i) => i < v.length && v[i] != null ? v[i] as num : 0;
    if (v.length == 1) return -at(0);
    return at(0) - at(1);
  }

  static num? _multiply(List<Object?> v) {
    if (v.isEmpty) return null;
    num p = 1;
    for (final x in v) {
      if (x is! num) return null;
      p *= x;
    }
    return p;
  }

  static num? _divide(Object? a, Object? b) {
    if (a is! num || b is! num || b == 0) return null;
    final r = a / b;
    return (r.isFinite && r == r.truncateToDouble() && r.abs() < 9.2e18) ? r.toInt() : r;
  }

  static num? _extremum(List<Object?> v, bool max) {
    if (v.isEmpty) return null;
    if (v.any((x) => x is! num)) return null;
    var best = v.first as num;
    for (final x in v.skip(1).cast<num>()) {
      if (max ? x > best : x < best) best = x;
    }
    return best;
  }

  static Object? _arrayOp(String op, List<Object?> args, Object? data) {
    final raw = apply(args.isNotEmpty ? args[0] : null, data);
    final List<Object?> items = raw is List ? raw : (raw is Map ? raw.values.toList() : const []);
    final logic = args.length > 1 ? args[1] : null;
    switch (op) {
      case 'map':
        return items.map((it) => apply(logic, it)).toList();
      case 'filter':
        return items.where((it) => truthy(apply(logic, it))).toList();
      case 'reduce':
        var acc = apply(args.length > 2 ? args[2] : null, data);
        for (final it in items) {
          acc = apply(logic, {'current': it, 'accumulator': acc});
        }
        return acc;
      case 'all':
        if (items.isEmpty) return false;
        return items.every((it) => truthy(apply(logic, it)));
      case 'some':
        return items.any((it) => truthy(apply(logic, it)));
      default: // none
        return !items.any((it) => truthy(apply(logic, it)));
    }
  }
}
