// Nouveau centre découvert sur le terrain : une seule page, étapes numérotées, gros boutons, position GPS relevée
// automatiquement. Le questionnaire démarre aussitôt (hors ligne) ; le centre part au serveur à la synchronisation.

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';

import '../app_state.dart';
import '../services/collection_service.dart';
import 'common.dart';
import 'form_screen.dart';

class NewCentreScreen extends StatefulWidget {
  const NewCentreScreen({super.key, required this.app});
  final AppState app;

  @override
  State<NewCentreScreen> createState() => _NewCentreScreenState();
}

class _NewCentreScreenState extends State<NewCentreScreen> {
  final _name = TextEditingController();
  final _otherType = TextEditingController();
  final _locality = TextEditingController();
  final _scroll = ScrollController();

  late List<Map<String, Object?>> _campaigns;
  late List<Map<String, Object?>> _communes;
  late List<Map<String, Object?>> _types;

  int? _campaignId;
  int? _communeId;
  String? _typeCode;
  String? _legal; // PUBLIC | PRIVE
  String? _zone; // URBAINE | RURALE
  bool _tried = false; // erreurs affichées seulement après un premier essai
  bool _creating = false;
  List<Map<String, Object?>> _similar = const [];
  Timer? _debounce;

  // Position GPS
  bool _locating = false;
  Map<String, Object?>? _gps;
  String? _gpsError;

  AppState get app => widget.app;
  CollectionService get _svc => app.collections;

  @override
  void initState() {
    super.initState();
    _campaigns = _svc.fieldCampaigns();
    _communes = app.db.allowedCommunes();
    _types = app.db.structureTypes();
    if (_campaigns.length == 1) _campaignId = _campaigns.first['id'] as int;
    if (_communes.length == 1) _communeId = _communes.first['id'] as int;
    WidgetsBinding.instance.addPostFrameCallback((_) => _locate());
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _name.dispose();
    _otherType.dispose();
    _locality.dispose();
    _scroll.dispose();
    super.dispose();
  }

  bool get _isOther => _types.any((t) => t['code'] == _typeCode && t['is_other'] == 1);

  Future<void> _locate() async {
    if (_locating || !mounted) return;
    setState(() {
      _locating = true;
      _gpsError = null;
    });
    try {
      if (!await Geolocator.isLocationServiceEnabled()) throw 'Activer la localisation (GPS) de la tablette.';
      var perm = await Geolocator.checkPermission();
      if (perm == LocationPermission.denied) perm = await Geolocator.requestPermission();
      if (perm == LocationPermission.denied || perm == LocationPermission.deniedForever) throw 'Autorisation de localisation refusée.';
      final pos = await Geolocator.getCurrentPosition(locationSettings: const LocationSettings(accuracy: LocationAccuracy.best, timeLimit: Duration(seconds: 60)));
      if (!mounted) return;
      setState(() => _gps = {
            'lat': double.parse(pos.latitude.toStringAsFixed(6)),
            'lng': double.parse(pos.longitude.toStringAsFixed(6)),
            'accuracy_m': double.parse(pos.accuracy.toStringAsFixed(1)),
            'captured_at': DateTime.now().toUtc().toIso8601String(),
            'status': 'OK',
          });
    } on TimeoutException {
      if (mounted) setState(() => _gpsError = 'Signal GPS trop faible. Sortir à découvert puis réessayer.');
    } catch (e) {
      if (mounted) setState(() => _gpsError = e.toString());
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  void _checkDuplicates() {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 400), () {
      if (!mounted) return;
      setState(() => _similar = _communeId == null ? const [] : _svc.similarCentres(_communeId!, _name.text));
    });
  }

  Map<String, String> _errors() => {
        if (_campaignId == null) 'campaign': 'Choisir la campagne.',
        if (_name.text.trim().length < 3) 'name': 'Écrire le nom du centre (au moins 3 lettres).',
        if (_typeCode == null) 'type': 'Choisir le type de centre.',
        if (_isOther && _otherType.text.trim().length < 2) 'other': 'Préciser le type.',
        if (_legal == null) 'legal': 'Choisir : public ou privé.',
        if (_communeId == null) 'commune': 'Choisir la commune.',
        if (_zone == null) 'zone': 'Choisir : urbaine ou rurale.',
      };

  Future<void> _start() async {
    final errors = _errors();
    if (errors.isNotEmpty) {
      setState(() => _tried = true);
      showMessage(context, 'À compléter : ${errors.values.join(' ')}', error: true);
      _scroll.animateTo(0, duration: const Duration(milliseconds: 300), curve: Curves.easeOut);
      return;
    }
    if (_gps == null) {
      final go = await confirm(context, 'Continuer sans position GPS ?',
          'La position pourra être relevée plus tard dans le questionnaire (section I). Il vaut mieux la relever maintenant, devant le centre.',
          ok: 'Continuer sans GPS');
      if (!go || !mounted) return;
    }
    if (_similar.isNotEmpty) {
      final go = await confirm(context, 'Créer quand même ?',
          "Un centre au nom proche existe déjà dans cette commune. Créez-le seulement si c'est bien un autre centre.",
          ok: "C'est un autre centre");
      if (!go || !mounted) return;
    }
    setState(() => _creating = true);
    try {
      final col = _svc.createFieldCentre(
        campaignId: _campaignId!,
        name: _name.text,
        typeCode: _typeCode!,
        otherTypeLabel: _isOther ? _otherType.text : null,
        legalStatus: _legal!,
        zone: _zone!,
        communeId: _communeId!,
        locality: _locality.text,
        gps: _gps,
      );
      app.changed();
      if (!mounted) return;
      await Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => FormScreen(app: app, uuid: col['uuid'] as String)));
    } on CollectionException catch (e) {
      if (mounted) await _explain(e.message);
    } catch (e) {
      // Toute autre erreur est affichée (auparavant le bouton semblait « ne rien faire »).
      if (mounted) await _explain('Le questionnaire ne peut pas être ouvert : $e\n\nSynchronisez la tablette puis réessayez ; si le problème persiste, signalez-le au superviseur.');
    } finally {
      if (mounted) setState(() => _creating = false);
    }
  }

  Future<void> _explain(String message) => showDialog<void>(
        context: context,
        builder: (c) => AlertDialog(
          icon: Icon(Icons.error_outline, color: Colors.red.shade800, size: 36),
          title: const Text('Impossible de commencer'),
          content: Text(message),
          actions: [FilledButton(onPressed: () => Navigator.pop(c), child: const Text('Compris'))],
        ),
      );

  bool _syncing = false;

  /// Aucune campagne utilisable : on explique pourquoi (campagnes connues et leur état) et on propose de synchroniser.
  Widget _noCampaign() {
    final known = app.db.campaigns();
    String state(Map<String, Object?> c) => switch (c['status']) {
          'PREPARATION' => "en préparation : pas encore ouverte par l'administrateur",
          'CLOTUREE' || 'ARCHIVEE' => 'clôturée',
          _ when c['collection_mode'] == 'PAPIER' => 'collecte sur papier (saisie au back-office)',
          final s => '$s',
        };
    return Scaffold(
      appBar: AppBar(title: const Text('Nouveau centre')),
      body: ListView(padding: const EdgeInsets.all(24), children: [
        Icon(Icons.event_busy, size: 56, color: Colors.grey.shade600),
        const SizedBox(height: 12),
        const Text("Aucune campagne ouverte n'est encore arrivée sur cette tablette.", textAlign: TextAlign.center, style: TextStyle(fontSize: 17, fontWeight: FontWeight.w600)),
        const SizedBox(height: 12),
        if (known.isEmpty)
          const Text(
              "Le serveur n'a envoyé aucune campagne à ce compte. Vérifiez avec l'administrateur que la campagne est bien OUVERTE "
              '(back-office › Campagnes › Ouvrir), puis synchronisez.',
              textAlign: TextAlign.center)
        else ...[
          const Text('Campagnes connues de la tablette :', textAlign: TextAlign.center),
          for (final c in known) Text('• ${c['name']} — ${state(c)}', textAlign: TextAlign.center),
          const SizedBox(height: 8),
          const Text("Dès que l'administrateur ouvre la campagne, synchronisez : la création de centres sera possible jusqu'à sa clôture.",
              textAlign: TextAlign.center),
        ],
        const SizedBox(height: 20),
        FilledButton.icon(
          onPressed: _syncing
              ? null
              : () async {
                  setState(() => _syncing = true);
                  await app.synchronize(force: true);
                  if (!mounted) return;
                  setState(() {
                    _syncing = false;
                    _campaigns = _svc.fieldCampaigns();
                    _communes = app.db.allowedCommunes();
                    _types = app.db.structureTypes();
                    if (_campaigns.length == 1) _campaignId = _campaigns.first['id'] as int;
                    if (_communes.length == 1) _communeId = _communes.first['id'] as int;
                  });
                  if (_campaigns.isEmpty) showMessage(context, app.lastSyncMessage ?? 'Toujours aucune campagne ouverte.', error: !app.lastSyncOk);
                },
          icon: _syncing
              ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Icon(Icons.sync),
          label: Text(_syncing ? 'Synchronisation…' : 'Synchroniser maintenant'),
        ),
      ]),
    );
  }

  Future<void> _openExisting(Map<String, Object?> s) async {
    try {
      final col = _svc.openOrCreate(s['campaign_structure_id'] as int);
      await Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => FormScreen(app: app, uuid: col['uuid'] as String)));
    } on CollectionException catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final err = _tried ? _errors() : const <String, String>{};
    if (_campaigns.isEmpty) return _noCampaign();
    return Scaffold(
      appBar: AppBar(title: const Text('Nouveau centre')),
      body: SafeArea(
        child: ListView(
          controller: _scroll,
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
          children: [
            Text("Vous êtes devant un centre qui n'est pas dans votre liste ? Remplissez ces quelques informations, puis le questionnaire s'ouvre.",
                style: Theme.of(context).textTheme.bodyMedium),
            const SizedBox(height: 12),
            if (_campaigns.length > 1)
              _Step(
                n: 0,
                title: 'Campagne',
                error: err['campaign'],
                child: Wrap(spacing: 8, runSpacing: 8, children: [
                  for (final c in _campaigns)
                    ChoiceChip(label: Text('${c['name']}'), selected: _campaignId == c['id'], onSelected: (_) => setState(() => _campaignId = c['id'] as int)),
                ]),
              ),
            _Step(n: 1, title: 'Position GPS', child: _gpsBlock()),
            _Step(
              n: 2,
              title: 'Nom du centre',
              help: "Le nom écrit sur l'enseigne, ou celui donné par le responsable.",
              showError: false,
              child: TextField(
                controller: _name,
                textCapitalization: TextCapitalization.words,
                maxLength: 150,
                decoration: InputDecoration(labelText: 'Nom du centre', errorText: err['name'], counterText: ''),
                onChanged: (_) {
                  _checkDuplicates();
                  if (_tried) setState(() {});
                },
              ),
            ),
            if (_similar.isNotEmpty) _duplicateCard(),
            _Step(
              n: 3,
              title: 'Type de centre',
              error: err['type'],
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                if (_types.isEmpty) const Text('Liste des types absente : synchroniser la tablette.'),
                Wrap(spacing: 8, runSpacing: 8, children: [
                  for (final t in _types)
                    ChoiceChip(label: Text('${t['label']}'), selected: _typeCode == t['code'], onSelected: (_) => setState(() => _typeCode = t['code'] as String)),
                ]),
                if (_isOther) ...[
                  const SizedBox(height: 12),
                  TextField(
                    controller: _otherType,
                    decoration: InputDecoration(labelText: 'Préciser le type', errorText: err['other']),
                    onChanged: (_) {
                      if (_tried) setState(() {});
                    },
                  ),
                ],
              ]),
            ),
            _Step(
              n: 4,
              title: 'Public ou privé ?',
              error: err['legal'],
              child: _BigChoice(
                value: _legal,
                options: const [('PUBLIC', 'Public', Icons.account_balance), ('PRIVE', 'Privé', Icons.storefront)],
                onChanged: (v) => setState(() => _legal = v),
              ),
            ),
            _Step(
              n: 5,
              title: 'Commune et quartier',
              error: err['commune'],
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                if (_communes.isEmpty)
                  const Text('Aucune commune dans votre zone de travail sur la tablette : synchroniser, ou contacter votre superviseur.')
                else if (_communes.length == 1)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: const Icon(Icons.place),
                    title: Text('${_communes.first['name']}'),
                    subtitle: Text('Département : ${_communes.first['department']}'),
                  )
                else
                  DropdownMenu<int>(
                    initialSelection: _communeId,
                    expandedInsets: EdgeInsets.zero,
                    enableFilter: true,
                    requestFocusOnTap: true,
                    menuHeight: 320,
                    label: const Text('Commune (taper pour chercher)'),
                    dropdownMenuEntries: [
                      for (final c in _communes)
                        DropdownMenuEntry(value: c['id'] as int, label: '${c['name']}', labelWidget: Text('${c['name']} — ${c['department']}')),
                    ],
                    onSelected: (v) {
                      setState(() => _communeId = v);
                      _checkDuplicates();
                    },
                  ),
                const SizedBox(height: 12),
                TextField(
                  controller: _locality,
                  textCapitalization: TextCapitalization.words,
                  decoration: const InputDecoration(labelText: 'Quartier ou village (facultatif)'),
                ),
              ]),
            ),
            _Step(
              n: 6,
              title: 'Zone',
              error: err['zone'],
              child: _BigChoice(
                value: _zone,
                options: const [('URBAINE', 'Urbaine (ville)', Icons.location_city), ('RURALE', 'Rurale (village)', Icons.agriculture)],
                onChanged: (v) => setState(() => _zone = v),
              ),
            ),
          ],
        ),
      ),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
          child: FilledButton.icon(
            style: FilledButton.styleFrom(
                minimumSize: const Size.fromHeight(56), textStyle: Theme.of(context).textTheme.titleMedium?.copyWith(fontSize: 18, fontWeight: FontWeight.w600)),
            onPressed: _creating ? null : _start,
            icon: _creating
                ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Icon(Icons.play_arrow),
            label: const Text('Commencer le questionnaire'),
          ),
        ),
      ),
    );
  }

  Widget _gpsBlock() {
    if (_locating) {
      return const Row(children: [
        SizedBox(width: 24, height: 24, child: CircularProgressIndicator(strokeWidth: 3)),
        SizedBox(width: 12),
        Expanded(child: Text('Recherche de la position… Restez devant le centre, à découvert.')),
      ]);
    }
    if (_gps != null) {
      final acc = (_gps!['accuracy_m'] as num).toDouble();
      final weak = acc > 50;
      return Row(children: [
        Icon(weak ? Icons.gps_not_fixed : Icons.gps_fixed, color: weak ? brandOrange : brandGreenDark, size: 28),
        const SizedBox(width: 12),
        Expanded(
          child: Text(
            weak
                ? 'Position relevée, mais peu précise (± ${acc.toStringAsFixed(0)} m). Réessayer à découvert si possible.'
                : 'Position relevée (± ${acc.toStringAsFixed(0)} m).',
            style: TextStyle(color: weak ? brandOrange : brandGreenDark, fontWeight: FontWeight.w600),
          ),
        ),
        TextButton(onPressed: _locate, child: const Text('Refaire')),
      ]);
    }
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Row(children: [
        Icon(Icons.gps_off, color: Colors.red.shade800, size: 28),
        const SizedBox(width: 12),
        Expanded(child: Text(_gpsError ?? 'Position non relevée.', style: TextStyle(color: Colors.red.shade800))),
      ]),
      const SizedBox(height: 8),
      OutlinedButton.icon(onPressed: _locate, icon: const Icon(Icons.my_location), label: const Text('Réessayer')),
    ]);
  }

  Widget _duplicateCard() => Card(
        color: Colors.amber.shade50,
        margin: const EdgeInsets.only(bottom: 16),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Icon(Icons.warning_amber, color: Colors.amber.shade900),
              const SizedBox(width: 8),
              const Expanded(child: Text('Ce centre est peut-être déjà dans votre liste', style: TextStyle(fontWeight: FontWeight.w700))),
            ]),
            const SizedBox(height: 4),
            const Text("Si c'est le même, ouvrez-le au lieu d'en créer un nouveau."),
            for (final s in _similar.take(3))
              ListTile(
                contentPadding: EdgeInsets.zero,
                title: Text('${s['name']}'),
                subtitle: s['locality_label'] == null ? null : Text('${s['locality_label']}'),
                trailing: OutlinedButton(onPressed: () => _openExisting(s), child: const Text('Ouvrir')),
              ),
          ]),
        ),
      );
}

/// Bloc d'étape numéroté : repère visuel simple pour suivre l'ordre.
class _Step extends StatelessWidget {
  const _Step({required this.n, required this.title, required this.child, this.help, this.error, this.showError = true});
  final int n;
  final String title;
  final String? help;
  final String? error;
  final bool showError; // faux quand le champ affiche déjà l'erreur lui-même
  final Widget child;

  @override
  Widget build(BuildContext context) => Card(
        margin: const EdgeInsets.only(bottom: 16),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: BorderSide(color: error != null ? Colors.red.shade800 : Colors.grey.shade300, width: error != null ? 2 : 1),
        ),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              if (n > 0) ...[
                CircleAvatar(radius: 14, backgroundColor: brandGreenDark, child: Text('$n', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700))),
                const SizedBox(width: 10),
              ],
              Expanded(child: Text(title, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700))),
            ]),
            if (help != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(help!, style: Theme.of(context).textTheme.bodySmall)),
            const SizedBox(height: 12),
            child,
            if (error != null && showError)
              Padding(padding: const EdgeInsets.only(top: 8), child: Text(error!, style: TextStyle(color: Colors.red.shade800, fontWeight: FontWeight.w600))),
          ]),
        ),
      );
}

/// Deux grands boutons côte à côte (cible tactile ≥ 56 dp, icône + texte).
class _BigChoice extends StatelessWidget {
  const _BigChoice({required this.value, required this.options, required this.onChanged});
  final String? value;
  final List<(String, String, IconData)> options;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) => Row(children: [
        for (final (i, o) in options.indexed) ...[
          if (i > 0) const SizedBox(width: 12),
          Expanded(
            child: Semantics(
              selected: value == o.$1,
              button: true,
              child: value == o.$1
                  ? FilledButton.icon(
                      style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56), backgroundColor: brandGreenDark),
                      onPressed: () => onChanged(o.$1),
                      icon: const Icon(Icons.check_circle),
                      label: Text(o.$2),
                    )
                  : OutlinedButton.icon(
                      style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(56)),
                      onPressed: () => onChanged(o.$1),
                      icon: Icon(o.$3),
                      label: Text(o.$2),
                    ),
            ),
          ),
        ],
      ]);
}
