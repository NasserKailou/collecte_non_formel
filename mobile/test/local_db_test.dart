// Base locale : chiffrement effectif (SQLCipher), refus d'une mauvaise clé, schéma et file d'envoi.

import 'dart:io';

import 'package:eftp_nf_collecte/src/data/local_db.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  late Directory dir;
  const key = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';
  const otherKey = 'ffeeddccbbaa99887766554433221100ffeeddccbbaa99887766554433221100';

  setUp(() => dir = Directory.systemTemp.createTempSync('eftp_db_'));
  tearDown(() {
    try {
      dir.deleteSync(recursive: true);
    } catch (_) {}
  });

  test('le fichier est chiffré et illisible sans la clé', () {
    final path = '${dir.path}/t.db';
    final db = LocalDb.open(path, key);
    db.setKv('secret', 'Atelier de couture Al Baraka');
    db.execute('PRAGMA wal_checkpoint(TRUNCATE)');
    db.close();

    final bytes = File(path).readAsBytesSync();
    expect(String.fromCharCodes(bytes.take(15)), isNot('SQLite format 3'), reason: 'en-tête SQLite en clair : fichier non chiffré');
    expect(String.fromCharCodes(bytes).contains('Al Baraka'), isFalse, reason: 'donnée lisible dans le fichier');

    expect(() => LocalDb.open(path, otherKey), throwsA(isA<LocalDbException>()));
    final again = LocalDb.open(path, key);
    expect(again.kv('secret'), 'Atelier de couture Al Baraka');
    again.close();
  });

  test('file d\'envoi : ordre, échéance et comptage', () {
    final db = LocalDb.open('${dir.path}/o.db', key);
    db.enqueue('u1', 0, '{}', 'h1');
    db.enqueue('u2', 0, '{}', 'h2');
    final first = db.nextOutbox()!;
    expect(first['collection_uuid'], 'u1');
    db.outboxUpdate(first['id'] as int, OutboxState.enAttente, nextAttempt: DateTime.now().add(const Duration(minutes: 5)), incrementAttempts: true);
    expect(db.nextOutbox()!['collection_uuid'], 'u2', reason: 'un élément reporté ne bloque pas les suivants');
    expect(db.nextOutbox(ignoreSchedule: true)!['collection_uuid'], 'u1');
    expect(db.outboxCounts()[OutboxState.enAttente], 2);
    expect(db.pendingWorkCount(), 2);
    db.close();
  });
}
