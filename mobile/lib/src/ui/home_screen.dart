// Accueil de l'agent : structures affectées par campagne, état de chaque collecte, synchronisation et journal.

import 'dart:convert';

import 'package:flutter/material.dart';

import '../app_state.dart';
import '../data/local_db.dart';
import '../services/collection_service.dart';
import 'common.dart';
import 'form_screen.dart';
import 'new_centre_screen.dart';
import 'server_check_dialog.dart';
import 'update_dialog.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key, required this.app});
  final AppState app;

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  String _filter = 'TOUTES';
  final _search = TextEditingController();

  AppState get app => widget.app;

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _sync() async {
    final r = await app.synchronize(force: true);
    if (mounted && r != null) showMessage(context, r.summary(), error: !r.ok);
  }

  Future<void> _open(Map<String, Object?> a) async {
    try {
      final col = app.collections.openOrCreate(a['campaign_structure_id'] as int);
      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => FormScreen(app: app, uuid: col['uuid'] as String)));
      app.changed();
    } on CollectionException catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    }
  }

  Future<void> _newCentre() async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => NewCentreScreen(app: app)));
    app.changed();
  }

  static bool _isNew(Map<String, Object?> a, Map<String, Object?> s) => a['centre_state'] != null || s['status'] == 'PROPOSEE';

  bool _matches(Map<String, Object?> a, Map<String, Object?> s) {
    final q = _search.text.trim().toLowerCase();
    if (q.isNotEmpty && !('${s['name']} ${s['official_code']} ${s['locality_label']}'.toLowerCase().contains(q))) return false;
    final local = a['local_status'] as String?;
    return switch (_filter) {
      'A_FAIRE' => local == null || local == LocalStatus.brouillon,
      'A_CORRIGER' => local == LocalStatus.invalidee || local == LocalStatus.enConflit || a['outbox_state'] == OutboxState.echec,
      'EN_ATTENTE' => local == LocalStatus.terminee,
      'ENVOYEES' => local == LocalStatus.soumise || local == LocalStatus.validee,
      'NOUVEAUX' => _isNew(a, s),
      _ => true,
    };
  }

  Future<void> _menu(String v) async {
    if (v == 'demo_exit') {
      await app.exitDemo();
    } else if (v == 'demo_reset') {
      final ok = await confirm(context, 'Réinitialiser la démonstration',
          'Toutes les saisies de démonstration de cette tablette seront effacées (les centres fictifs reviennent à « À collecter »). '
              "La collecte réelle n'est pas concernée.",
          ok: 'Réinitialiser');
      if (ok) await app.resetDemo();
    } else if (v == 'check') {
      await showServerCheck(context, app);
      app.changed();
    } else if (v == 'log') {
      Navigator.of(context).push(MaterialPageRoute(builder: (_) => SyncLogScreen(app: app)));
    } else if (v == 'lock') {
      app.lock();
    } else if (v == 'logout') {
      final n = app.db.pendingWorkCount();
      final ok = await confirm(
          context,
          'Se déconnecter',
          n > 0
              ? "$n élément(s) ne sont pas encore envoyés. Ils restent sur la tablette (chiffrés) ; une connexion en ligne sera nécessaire pour reprendre."
              : 'Une connexion en ligne sera nécessaire pour reprendre.',
          ok: 'Se déconnecter');
      if (ok) await app.logout();
    }
  }

  /// Mentions propres aux centres créés sur le terrain (envoi du centre, refus, doublon, attente de validation).
  List<Widget> _centreNotes(Map<String, Object?> a, Map<String, Object?> s) {
    Widget note(IconData icon, String text, Color color) => Padding(
          padding: const EdgeInsets.only(top: 2),
          child: Row(children: [
            Icon(icon, size: 16, color: color),
            const SizedBox(width: 4),
            Expanded(child: Text(text, style: TextStyle(color: color, fontSize: 12.5))),
          ]),
        );
    final state = a['centre_state'] as String?;
    return [
      if (state == OutboxState.enAttente) note(Icons.fiber_new, 'Nouveau centre : sera envoyé à la prochaine synchronisation.', Colors.blue.shade800),
      if (state == OutboxState.echec)
        note(Icons.error, 'Centre refusé par le serveur : ${a['centre_error'] ?? ''} La saisie est conservée ; prévenir le superviseur.', Colors.red.shade800),
      if (a['centre_duplicates'] != null) note(Icons.content_copy, 'Doublon possible signalé au superviseur.', Colors.amber.shade900),
      if (state != OutboxState.enAttente && state != OutboxState.echec && s['status'] == 'PROPOSEE')
        note(Icons.hourglass_top, 'Centre proposé : en attente de validation par le superviseur.', Colors.grey.shade700),
    ];
  }

  @override
  Widget build(BuildContext context) => ListenableBuilder(
        listenable: app,
        builder: (context, _) {
          final rows = app.db.assignmentsWithState();
          final campaigns = {for (final c in app.db.campaigns()) c['id']: c};
          final counts = app.db.outboxCounts();
          final pending = (counts[OutboxState.enAttente] ?? 0) + (counts[OutboxState.enCours] ?? 0);
          final todo = rows.where((a) => a['local_status'] == null || a['local_status'] == LocalStatus.brouillon).length;
          final fix = rows.where((a) => a['local_status'] == LocalStatus.invalidee || a['local_status'] == LocalStatus.enConflit).length;
          final visible = rows.where((a) => _matches(a, jsonDecode(a['structure_json'] as String) as Map<String, Object?>)).toList();

          final newCount = rows.where((a) => _isNew(a, jsonDecode(a['structure_json'] as String) as Map<String, Object?>)).length;

          return Scaffold(
            floatingActionButton: FloatingActionButton.extended(
              onPressed: _newCentre,
              backgroundColor: brandOrange,
              foregroundColor: Colors.white,
              icon: const Icon(Icons.add_business),
              label: const Text('Nouveau centre', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
              tooltip: 'Créer un centre trouvé sur le terrain et commencer le questionnaire',
            ),
            appBar: AppBar(
              title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const Text('Mes structures'),
                Text(app.session?.fullName ?? '', style: const TextStyle(fontSize: 13)),
                if (_campaignLine(app) case final line?)
                  Text(line, style: const TextStyle(fontSize: 12, color: Colors.white70), overflow: TextOverflow.ellipsis),
              ]),
              toolbarHeight: 72,
              actions: [
                IconButton(
                  tooltip: 'Synchroniser',
                  onPressed: app.syncing ? null : _sync,
                  icon: app.syncing
                      ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                      : const Icon(Icons.sync),
                ),
                PopupMenuButton<String>(
                  tooltip: 'Menu',
                  onSelected: _menu,
                  itemBuilder: (_) => app.demo
                      ? const [
                          PopupMenuItem(value: 'log', child: Text('Journal (envois simulés)')),
                          PopupMenuItem(value: 'demo_reset', child: Text('Réinitialiser la démo')),
                          PopupMenuItem(value: 'demo_exit', child: Text('Quitter la démo')),
                        ]
                      : const [
                          PopupMenuItem(value: 'check', child: Text('Tester la connexion au serveur')),
                          PopupMenuItem(value: 'log', child: Text('Journal de synchronisation')),
                          PopupMenuItem(value: 'lock', child: Text('Verrouiller')),
                          PopupMenuItem(value: 'logout', child: Text('Se déconnecter')),
                        ],
                ),
              ],
            ),
            body: Column(children: [
              if (app.demo) _DemoBanner(onExit: () => _menu('demo_exit')),
              if (app.appUpdate != null) _UpdateBanner(app: app, update: app.appUpdate!),
              _SyncBanner(app: app, pending: pending + app.db.pendingStructures().length, onSend: app.syncing ? null : _sync),
              Padding(
                padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
                child: TextField(
                  controller: _search,
                  decoration: const InputDecoration(prefixIcon: Icon(Icons.search), labelText: 'Rechercher (nom, code, quartier)', isDense: true),
                  onChanged: (_) => setState(() {}),
                ),
              ),
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                child: Row(children: [
                  for (final f in [
                    ('TOUTES', 'Toutes (${rows.length})'),
                    ('A_FAIRE', 'À faire ($todo)'),
                    ('A_CORRIGER', 'À corriger ($fix)'),
                    ('EN_ATTENTE', "En attente d'envoi"),
                    ('ENVOYEES', 'Envoyées'),
                    if (newCount > 0) ('NOUVEAUX', 'Créés sur le terrain ($newCount)'),
                  ])
                    Padding(
                      padding: const EdgeInsets.only(right: 8),
                      child: ChoiceChip(label: Text(f.$2), selected: _filter == f.$1, onSelected: (_) => setState(() => _filter = f.$1)),
                    ),
                ]),
              ),
              Expanded(
                child: RefreshIndicator(
                  onRefresh: _sync,
                  child: visible.isEmpty
                      ? ListView(children: [
                          Padding(
                            padding: const EdgeInsets.all(32),
                            child: rows.isEmpty
                                ? Column(children: [
                                    Icon(Icons.add_business, size: 56, color: Colors.grey.shade600),
                                    const SizedBox(height: 12),
                                    const Text('Aucun centre sur cette tablette pour le moment.', textAlign: TextAlign.center, style: TextStyle(fontWeight: FontWeight.w600)),
                                    const SizedBox(height: 8),
                                    const Text(
                                        'Devant un centre de formation, appuyez sur « Nouveau centre » : quelques informations, puis le questionnaire s\'ouvre. '
                                        'Les centres déjà connus arrivent par la synchronisation.',
                                        textAlign: TextAlign.center),
                                    const SizedBox(height: 16),
                                    FilledButton.icon(onPressed: _newCentre, icon: const Icon(Icons.add_business), label: const Text('Nouveau centre')),
                                  ])
                                : const Text('Aucune structure pour ce filtre.', textAlign: TextAlign.center),
                          ),
                        ])
                      : ListView.separated(
                          padding: const EdgeInsets.only(bottom: 96), // la dernière ligne reste visible au-dessus du bouton « Nouveau centre »
                          itemCount: visible.length,
                          separatorBuilder: (_, _) => const Divider(height: 1),
                          itemBuilder: (context, i) {
                            final a = visible[i];
                            final s = jsonDecode(a['structure_json'] as String) as Map<String, Object?>;
                            final c = campaigns[a['campaign_id']];
                            final errors = a['errors_count'] as int? ?? 0;
                            return ListTile(
                              minVerticalPadding: 12,
                              title: Text((s['name'] ?? '').toString(), style: const TextStyle(fontWeight: FontWeight.w600)),
                              subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                Text('${s['official_code'] ?? 'non codifiée'} · ${app.db.communeName(s['commune_id'] as int?) ?? ''}'
                                    '${s['locality_label'] != null ? ' · ${s['locality_label']}' : ''}'),
                                if (c != null) Text('${c['name']}', style: Theme.of(context).textTheme.bodySmall),
                                const SizedBox(height: 4),
                                StatusChip(a),
                                ..._centreNotes(a, s),
                                if (a['local_status'] == LocalStatus.brouillon && errors > 0)
                                  Text('$errors point(s) à compléter', style: Theme.of(context).textTheme.bodySmall),
                                if (a['active'] == 0)
                                  Text("Affectation retirée : collecte conservée jusqu'à son envoi.", style: TextStyle(color: Colors.red.shade800, fontSize: 12)),
                              ]),
                              trailing: const Icon(Icons.chevron_right),
                              onTap: () => _open(a),
                            );
                          },
                        ),
                ),
              ),
            ]),
          );
        },
      );
}

/// Bandeau permanent du mode Démo : impossible de confondre avec la collecte réelle.
class _DemoBanner extends StatelessWidget {
  const _DemoBanner({required this.onExit});
  final VoidCallback onExit;

  @override
  Widget build(BuildContext context) => Semantics(
        container: true,
        label: 'Mode démonstration',
        child: Container(
          width: double.infinity,
          color: brandOrange,
          padding: const EdgeInsets.fromLTRB(16, 8, 8, 8),
          child: Row(children: [
            const Icon(Icons.school, color: Colors.white),
            const SizedBox(width: 10),
            const Expanded(
              child: Text('MODE DÉMO — formation. Données fictives, jamais envoyées au serveur.',
                  style: TextStyle(color: Colors.white, fontWeight: FontWeight.w700)),
            ),
            TextButton(
              onPressed: onExit,
              style: TextButton.styleFrom(foregroundColor: Colors.white),
              child: const Text('Quitter'),
            ),
          ]),
        ),
      );
}

/// Nouvelle version de l'application : téléchargement dans le navigateur, puis installation proposée par Android.
/// Les collectes restent sur la tablette (la base locale est conservée par la mise à jour).
class _UpdateBanner extends StatelessWidget {
  const _UpdateBanner({required this.app, required this.update});
  final AppState app;
  final Map<String, Object?> update;

  @override
  Widget build(BuildContext context) {
    final required = update['required'] == true;
    final mb = ((update['size_bytes'] as num? ?? 0) / 1048576).toStringAsFixed(1);
    return Semantics(
      liveRegion: true,
      child: Container(
        width: double.infinity,
        color: required ? Colors.red.shade50 : Colors.blue.shade50,
        padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(
            required
                ? "Mise à jour obligatoire : version ${update['version']}. L'envoi des collectes reprendra après l'installation."
                : 'Nouvelle version disponible : ${update['version']} ($mb Mo).',
            style: const TextStyle(fontWeight: FontWeight.w600),
          ),
          if ((update['notes'] as String?)?.isNotEmpty == true) Text('${update['notes']}', style: Theme.of(context).textTheme.bodySmall),
          const SizedBox(height: 6),
          FilledButton.icon(
            // Téléchargement dans l'application (progression), puis installation proposée à la fin.
            onPressed: () => showUpdateDialog(context, app),
            icon: const Icon(Icons.system_update),
            label: const Text('Télécharger et installer'),
          ),
          Text('Vos collectes restent enregistrées sur la tablette pendant la mise à jour.', style: Theme.of(context).textTheme.bodySmall),
        ]),
      ),
    );
  }
}

class _SyncBanner extends StatelessWidget {
  const _SyncBanner({required this.app, required this.pending, required this.onSend});
  final AppState app;
  final int pending;

  /// « Envoyer au serveur » : envoie les collectes terminées (et les nouveaux centres) puis reçoit les mises à jour.
  final VoidCallback? onSend;

  @override
  Widget build(BuildContext context) {
    final offline = app.session?.online == false;
    final color = !app.lastSyncOk ? Colors.red.shade50 : (pending > 0 ? Colors.orange.shade50 : Colors.green.shade50);
    return Semantics(
      liveRegion: true,
      child: Container(
        width: double.infinity,
        color: color,
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(pending > 0 ? "$pending collecte(s) en attente d'envoi" : "Aucune collecte en attente d'envoi", style: const TextStyle(fontWeight: FontWeight.w600)),
          Text('Dernière synchronisation réussie : ${formatDateTime(app.lastSuccess)}${offline ? ' · ouverture hors ligne' : ''}',
              style: Theme.of(context).textTheme.bodySmall),
          if (app.lastSyncMessage != null) Text(app.lastSyncMessage!, style: Theme.of(context).textTheme.bodySmall),
          const SizedBox(height: 8),
          SizedBox(
            width: double.infinity,
            child: FilledButton.icon(
              onPressed: onSend,
              style: FilledButton.styleFrom(backgroundColor: pending > 0 ? brandOrange : brandGreenDark, minimumSize: const Size(64, 48)),
              icon: app.syncing
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.cloud_upload),
              label: Text(
                app.syncing
                    ? 'Envoi en cours…'
                    : '${app.demo ? 'Envoyer (simulation)' : 'Envoyer au serveur'}${pending > 0 ? ' ($pending)' : ''}',
                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
              ),
            ),
          ),
        ]),
      ),
    );
  }
}

class SyncLogScreen extends StatelessWidget {
  const SyncLogScreen({super.key, required this.app});
  final AppState app;

  @override
  Widget build(BuildContext context) {
    final log = app.db.syncLog();
    return Scaffold(
      appBar: AppBar(title: const Text('Journal de synchronisation')),
      body: log.isEmpty
          ? const Center(child: Text('Aucune synchronisation enregistrée.'))
          : ListView.separated(
              itemCount: log.length,
              separatorBuilder: (_, _) => const Divider(height: 1),
              itemBuilder: (_, i) {
                final l = log[i];
                return ListTile(
                  dense: true,
                  leading: Icon(l['direction'] == 'ENVOI' ? Icons.upload : Icons.download),
                  title: Text('${l['outcome']}${l['http_status'] != null ? ' (HTTP ${l['http_status']})' : ''}'),
                  subtitle: Text('${formatDateTime(DateTime.parse(l['at'] as String).toLocal())} · ${l['endpoint']}'),
                );
              },
            ),
    );
  }
}

/// Ligne affichée sous le nom de l'agent : année statistique et campagne en cours (ex. « 2026-2027 · Recensement EFTP »).
String? _campaignLine(AppState app) {
  final c = app.collections.currentCampaign();
  if (c == null) return null;
  final year = (c['academic_year_label'] ?? c['academic_year_code']) as String?;
  final suffix = CollectionService.canCollect(c) ? '' : ' (${'${c['status']}'.toLowerCase()})';
  return [if (year != null && year.isNotEmpty) year, '${c['name']}$suffix'].join(' · ');
}
