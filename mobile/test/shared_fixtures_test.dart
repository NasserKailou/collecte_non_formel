// Rejoue les vecteurs de test partagés avec le serveur (shared/rules-fixtures), comme
// backend/tests/Unit/SharedFixturesTest.php. Un échec ici signifie que le mobile et le serveur divergent.

import 'dart:convert';
import 'dart:io';

import 'package:eftp_nf_collecte/src/rules/json_logic.dart';
import 'package:eftp_nf_collecte/src/rules/questionnaire_engine.dart';
import 'package:flutter_test/flutter_test.dart';

final _shared = '${Directory.current.path}/../shared';

Object? _readJson(String rel) => jsonDecode(File('$_shared/$rel').readAsStringSync());

void main() {
  group('JsonLogic (vecteurs partagés)', () {
    for (final c in (_readJson('rules-fixtures/jsonlogic.json') as List).cast<Map<String, Object?>>()) {
      test(c['name'] as String, () {
        if (c['error'] == true) {
          expect(() => JsonLogic.apply(c['logic'], c['data']), throwsA(isA<JsonLogicException>()));
          return;
        }
        final got = JsonLogic.apply(c['logic'], c['data']);
        expect(_same(got, c['expected']), isTrue, reason: 'obtenu ${jsonEncode(got)} (${got.runtimeType}), attendu ${jsonEncode(c['expected'])}');
      });
    }
  });

  // Un fichier de vecteurs par version du questionnaire (mêmes fichiers que le serveur).
  final fixtureFiles = Directory(
    '$_shared/rules-fixtures',
  ).listSync().whereType<File>().where((f) => RegExp(r'eftp-nf-[\d.]+\.json$').hasMatch(f.path)).toList()..sort((a, b) => a.path.compareTo(b.path));
  for (final file in fixtureFiles) {
    final version = RegExp(r'eftp-nf-([\d.]+)\.json$').firstMatch(file.path)![1];
    group('Questionnaire $version (vecteurs partagés)', () {
      final fixture = jsonDecode(file.readAsStringSync()) as Map<String, Object?>;
      final engine = QuestionnaireEngine.fromJson(File('$_shared/${(fixture['definition'] as String).substring('shared/'.length)}').readAsStringSync());
      final base = fixture['base'] as Map<String, Object?>;

      for (final c in (fixture['cases'] as List).cast<Map<String, Object?>>()) {
        test(c['name'] as String, () {
          final data = QuestionnaireEngine.deepCopy(base) as Map<String, Object?>;
          for (final op in (c['patch'] as List).cast<Map<String, Object?>>()) {
            _applyPatch(data, op);
          }
          final r = engine.validate(data);
          final expect_ = c['expect'] as Map<String, Object?>;
          String describe(List<Issue> l) => l.map((i) => '${i.code} @ ${i.target} — ${i.message}').join('\n');
          final ctx = '\nErreurs :\n${describe(r.errors)}\nAvertissements :\n${describe(r.warnings)}';

          expect(_issuePairs(r.errors), _pairs(expect_['errors'] as List), reason: 'Erreurs inattendues.$ctx');
          expect(_issuePairs(r.warnings), _pairs(expect_['warnings'] as List), reason: 'Avertissements inattendus.$ctx');
          expect(r.valid, expect_['valid'], reason: 'Statut de validité inattendu.$ctx');
          expect([...r.cleared]..sort(), [...(expect_['cleared'] as List).cast<String>()]..sort(), reason: 'Valeurs effacées inattendues.');
          for (final e in ((expect_['computed'] as Map?) ?? const {}).entries) {
            final got = JsonLogic.varPath(e.key, r.computed);
            expect(_same(got, e.value), isTrue, reason: 'Variable calculée « ${e.key} » : obtenu ${jsonEncode(got)}, attendu ${jsonEncode(e.value)}');
          }
        });
      }
    });
  }
}

/// assertSame de PHPUnit : types identiques (1 ≠ 1.0), tableaux comparés en profondeur.
bool _same(Object? a, Object? b) => JsonLogic.deepEquals(a, b);

List<String> _pairs(List expected) => expected.map((e) => '${(e as List)[0]} @ ${e[1]}').toList()..sort();

List<String> _issuePairs(List<Issue> issues) => issues.map((i) => '${i.code} @ ${i.target}').toList()..sort();

void _applyPatch(Map<String, Object?> data, Map<String, Object?> op) {
  final segments = (op['path'] as String).split('.');
  final last = segments.removeLast();
  Object ref = data;
  for (final s in segments) {
    final next = ref is Map ? ref[s] : (ref as List)[int.parse(s)];
    if (next is Map || next is List) {
      ref = next!;
    } else {
      final created = <String, Object?>{};
      if (ref is Map) {
        ref[s] = created;
      } else {
        (ref as List)[int.parse(s)] = created;
      }
      ref = created;
    }
  }
  switch (op['op']) {
    case 'set':
      if (ref is Map) {
        ref[last] = op['value'];
      } else {
        final l = ref as List;
        final i = int.parse(last);
        if (i == l.length) {
          l.add(op['value']);
        } else {
          l[i] = op['value'];
        }
      }
    case 'unset':
      if (ref is Map) {
        ref.remove(last);
      } else {
        (ref as List).removeAt(int.parse(last));
      }
    default:
      throw ArgumentError('Opération de patch inconnue : ${op['op']}');
  }
}
