// « Tester la connexion au serveur » : réseau et serveur, base de données, session de l'agent, avec un conseil clair.
// Accessible depuis l'accueil (menu ⋮) et depuis l'écran de connexion. N'envoie aucune donnée de collecte.

import 'package:flutter/material.dart';

import '../app_state.dart';
import '../data/local_db.dart';
import '../services/sync_service.dart';
import 'common.dart';

Future<void> showServerCheck(BuildContext context, AppState app) =>
    showDialog<void>(context: context, builder: (_) => _ServerCheckDialog(app: app));

class _ServerCheckDialog extends StatefulWidget {
  const _ServerCheckDialog({required this.app});
  final AppState app;

  @override
  State<_ServerCheckDialog> createState() => _ServerCheckDialogState();
}

class _ServerCheckDialogState extends State<_ServerCheckDialog> {
  ServerCheck? _result;
  bool _running = false;

  AppState get app => widget.app;

  @override
  void initState() {
    super.initState();
    _run();
  }

  Future<void> _run() async {
    setState(() {
      _running = true;
      _result = null;
    });
    final r = await app.sync.checkServer(signedIn: app.session != null);
    if (mounted) {
      setState(() {
        _result = r;
        _running = false;
      });
    }
  }

  Widget _line(bool? ok, String title, String text) {
    final color = ok == null ? Colors.grey.shade700 : (ok ? brandGreenDark : Colors.red.shade800);
    final icon = ok == null ? Icons.remove_circle_outline : (ok ? Icons.check_circle : Icons.cancel);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(icon, color: color, size: 26),
        const SizedBox(width: 10),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
            Text(text),
          ]),
        ),
      ]),
    );
  }

  String _advice(ServerCheck r) {
    if (!r.reachable) {
      return "Vérifiez que les données mobiles ou le Wi-Fi sont activés et qu'une page internet s'ouvre. "
          'Vos collectes restent sur la tablette : elles partiront dès que le serveur sera joignable.';
    }
    if (r.database != true) return 'Le serveur répond mais ne peut pas enregistrer pour le moment. Réessayez plus tard ; prévenez votre superviseur si cela dure.';
    return switch (r.session) {
      'EXPIREE' => 'Votre session a expiré : déconnectez-vous puis reconnectez-vous en ligne (menu ⋮ › Se déconnecter). Vos collectes sont conservées.',
      'MISE_A_JOUR' => "Cette version de l'application n'est plus acceptée : installez la mise à jour proposée.",
      'REVOQUEE' => "Cette tablette a été retirée par l'administrateur : contactez votre superviseur.",
      'ERREUR' => 'Le serveur a refusé la vérification de votre compte. Prévenez votre superviseur.',
      _ => 'Tout fonctionne : vos collectes peuvent être envoyées.',
    };
  }

  @override
  Widget build(BuildContext context) {
    final r = _result;
    // Même compte que le bandeau de l'accueil : collectes terminées pas encore envoyées, et nouveaux centres à envoyer.
    final counts = app.db.outboxCounts();
    final pendingCollections = (counts[OutboxState.enAttente] ?? 0) + (counts[OutboxState.enCours] ?? 0);
    final pendingCentres = app.db.pendingStructures().length;
    final pending = pendingCollections + pendingCentres;
    final last = app.lastSuccess;
    return AlertDialog(
      title: const Text('Connexion au serveur'),
      content: SingleChildScrollView(
        child: _running || r == null
            ? const Padding(
                padding: EdgeInsets.symmetric(vertical: 24),
                child: Row(children: [
                  SizedBox(width: 28, height: 28, child: CircularProgressIndicator(strokeWidth: 3)),
                  SizedBox(width: 16),
                  Expanded(child: Text('Test en cours…')),
                ]),
              )
            : Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
                _line(r.reachable, 'Réseau et serveur',
                    r.reachable ? 'Serveur joignable (réponse en ${r.latencyMs} ms).' : 'Aucune réponse du serveur après ${((r.latencyMs ?? 0) / 1000).toStringAsFixed(0)} s.'),
                _line(r.reachable ? r.database : null, 'Base de données',
                    !r.reachable ? 'Non testée.' : (r.database == true ? 'Disponible.' : 'Indisponible pour le moment.')),
                if (app.session != null)
                  _line(r.session == null ? null : r.session == 'OK', 'Votre session',
                      switch (r.session) { null => 'Non testée.', 'OK' => 'Votre compte est reconnu par le serveur.', _ => 'À renouveler (voir le conseil ci-dessous).' }),
                const SizedBox(height: 8),
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: r.allGood ? Colors.green.shade50 : Colors.orange.shade50,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(_advice(r)),
                ),
                const SizedBox(height: 12),
                Text('Collectes en attente d\'envoi : $pendingCollections'
                    '${pendingCentres > 0 ? ' · nouveaux centres à envoyer : $pendingCentres' : ''}', style: Theme.of(context).textTheme.bodySmall),
                Text('Dernière synchronisation réussie : ${formatDateTime(last)}', style: Theme.of(context).textTheme.bodySmall),
                SelectableText('Adresse du serveur : ${r.address}', style: Theme.of(context).textTheme.bodySmall),
              ]),
      ),
      actions: [
        TextButton(onPressed: _running ? null : _run, child: const Text('Refaire le test')),
        if (r != null && r.allGood && app.session != null && pending > 0)
          FilledButton(
            onPressed: () async {
              Navigator.pop(context);
              final s = await app.synchronize(force: true);
              if (context.mounted && s != null) showMessage(context, s.summary(), error: !s.ok);
            },
            child: const Text('Envoyer maintenant'),
          )
        else
          FilledButton(onPressed: () => Navigator.pop(context), child: const Text('Fermer')),
      ],
    );
  }
}
