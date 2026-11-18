// Formulaire de collecte généré à partir de la définition versionnée du questionnaire : une page par section,
// contrôle immédiat par le moteur partagé (erreurs bloquantes en rouge, avertissements en orange), sauvegarde
// automatique à chaque modification, « Terminer » puis envoi. Lecture seule une fois la collecte terminée.

import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:geolocator/geolocator.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

import '../app_state.dart';
import '../data/local_db.dart';
import '../rules/json_logic.dart';
import '../rules/questionnaire_engine.dart';
import '../services/collection_service.dart';
import 'common.dart';

typedef Item = Map<String, Object?>;

class FormScreen extends StatefulWidget {
  const FormScreen({super.key, required this.app, required this.uuid});
  final AppState app;
  final String uuid;

  @override
  State<FormScreen> createState() => _FormScreenState();
}

class _FormScreenState extends State<FormScreen> with SingleTickerProviderStateMixin {
  late Map<String, Object?> _col;
  late Map<String, Object?> _data;
  late QuestionnaireEngine _engine;
  late ValidationResult _result;
  late TabController _tabs;
  Map<String, List<Issue>> _issues = {};
  int _version = 0; // incrémenté quand les données sont remplacées (recrée les champs)
  Timer? _debounce;
  String? _loadError;

  CollectionService get _svc => widget.app.collections;
  bool get _editable => CollectionService.isEditable(_col);

  @override
  void initState() {
    super.initState();
    try {
      _load();
      _tabs = TabController(length: _engine.sections.length, vsync: this)
        ..addListener(() {
          if (!_tabs.indexIsChanging && mounted) setState(() {}); // met à jour Précédent / Suivant après un balayage
        });
      if (_editable && _data['GPS'] == null) WidgetsBinding.instance.addPostFrameCallback((_) => _autoLocate());
    } catch (e) {
      _loadError = e.toString();
      _tabs = TabController(length: 1, vsync: this);
    }
  }

  bool _locating = false;

  /// Relevé GPS automatique à l'ouverture d'une collecte sur place (l'agent peut le refaire ou déclarer la position
  /// indisponible). Un échec n'interrompt pas la saisie.
  Future<void> _autoLocate() async {
    if (_locating) return;
    setState(() => _locating = true);
    try {
      if (!await Geolocator.isLocationServiceEnabled()) throw 'activer la localisation (GPS) de la tablette';
      var perm = await Geolocator.checkPermission();
      if (perm == LocationPermission.denied) perm = await Geolocator.requestPermission();
      if (perm == LocationPermission.denied || perm == LocationPermission.deniedForever) throw 'autorisation de localisation refusée';
      final pos = await Geolocator.getCurrentPosition(locationSettings: const LocationSettings(accuracy: LocationAccuracy.best, timeLimit: Duration(seconds: 60)));
      if (!mounted || _data['GPS'] != null) return; // relevé manuel pendant l'attente : il prévaut
      _set(['GPS'], {
        'lat': double.parse(pos.latitude.toStringAsFixed(6)),
        'lng': double.parse(pos.longitude.toStringAsFixed(6)),
        'accuracy_m': double.parse(pos.accuracy.toStringAsFixed(1)),
        'captured_at': DateTime.now().toUtc().toIso8601String(),
        'status': 'OK',
        'note': null,
      });
      _flush();
      setState(() => _version++);
      if (mounted) showMessage(context, 'Position GPS relevée automatiquement (± ${pos.accuracy.toStringAsFixed(0)} m).');
    } catch (e) {
      if (mounted) showMessage(context, 'Relevé GPS automatique impossible : $e. Le relever dans la section I ou déclarer la position indisponible.');
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  void _load() {
    _col = widget.app.db.collection(widget.uuid)!;
    _engine = _svc.engine(_col['questionnaire_version'] as String);
    _data = _svc.dataOf(_col);
    _revalidate();
    _version++;
  }

  void _revalidate() {
    _result = _svc.validate(_col, _data);
    final map = <String, List<Issue>>{};
    for (final i in [..._result.errors, ..._result.warnings]) {
      map.putIfAbsent(i.target, () => []).add(i);
    }
    _issues = map;
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _flush();
    _tabs.dispose();
    super.dispose();
  }

  /// Écrit une valeur et planifie la sauvegarde (regroupe les frappes rapprochées).
  void _set(List<String> path, Object? value) {
    if (!_editable) return;
    if (value == null || (value is String && value.isEmpty)) {
      QuestionnaireEngine.unsetPath(_data, path);
    } else {
      QuestionnaireEngine.setPath(_data, path, value);
    }
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 300), () {
      _flush();
      if (mounted) setState(() {});
    });
  }

  /// Redessine le formulaire (utilisé par les champs composés : tableaux de lignes).
  void refresh({bool rebuildFields = false, bool revalidate = false}) => setState(() {
        if (revalidate) _revalidate();
        if (rebuildFields) _version++;
      });

  void _flush() {
    if (_loadError != null || !_editable) return;
    try {
      _svc.save(_col, _data);
      _revalidate();
    } catch (_) {}
  }

  /// Données utilisées pour la visibilité : données nettoyées + variables calculées + contexte (comme le moteur).
  Map<String, Object?> get _visCtx => {..._result.data, '_ctx': _svc.context(_col, _data)};

  List<Issue> _issuesUnder(String prefix) => [
        for (final e in _issues.entries)
          if (e.key == prefix || e.key.startsWith('$prefix.') || e.key.startsWith('$prefix[')) ...e.value
      ];

  // --------------------------------------------------------------------- Actions

  Future<void> _finish() async {
    _debounce?.cancel();
    _flush();
    setState(_revalidate);
    // Questions non renseignées : simple rappel, l'agent peut terminer quand même.
    final missing = _result.errors.isEmpty ? _result.warnings.where((i) => i.code == 'REQUIRED').length : 0;
    if (missing > 0) {
      final choice = await showDialog<String>(
        context: context,
        builder: (c) => AlertDialog(
          icon: Icon(Icons.warning_amber, color: Colors.orange.shade800, size: 36),
          title: Text('$missing question(s) non renseignée(s)'),
          content: const Text('Elles devraient normalement être remplies. Vous pouvez les compléter maintenant, ou terminer quand même si le centre ne peut pas répondre.'),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, 'list'), child: const Text('Voir la liste')),
            FilledButton(onPressed: () => Navigator.pop(c, 'finish'), child: const Text('Terminer quand même')),
          ],
        ),
      );
      if (!mounted) return;
      if (choice == 'list') _showIssues();
      if (choice != 'finish') return;
    }
    try {
      final r = _svc.finish(widget.uuid);
      if (!r.valid) {
        setState(_revalidate);
        _showIssues(onlyErrors: true);
        return;
      }
      setState(_load);
      widget.app.changed(trySend: true);
      if (mounted) {
        showMessage(
            context,
            r.warnings.isEmpty
                ? 'Collecte terminée : elle sera envoyée dès que le réseau le permet.'
                : 'Collecte terminée avec ${r.warnings.length} avertissement(s) : elle sera envoyée dès que possible.');
      }
    } on CollectionException catch (e) {
      showMessage(context, e.message, error: true);
    }
  }

  Future<void> _reopenLocal() async {
    if (!await confirm(context, 'Reprendre la saisie', "L'envoi en attente est annulé ; il faudra de nouveau « Terminer » la collecte.", ok: 'Reprendre')) return;
    try {
      _svc.reopenLocal(widget.uuid);
      setState(_load);
      widget.app.changed();
    } on CollectionException catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    }
  }

  Future<void> _resolve(bool keepLocal) async {
    final ok = await confirm(
      context,
      keepLocal ? 'Garder ma version' : 'Prendre la version du serveur',
      keepLocal
          ? 'Votre saisie est conservée et pourra être renvoyée comme correction. Le serveur la refusera si la collecte est en contrôle ou validée.'
          : 'Votre saisie locale est remplacée. Elle est archivée dans le journal de la collecte sur la tablette.',
    );
    if (!ok) return;
    try {
      keepLocal ? widget.app.sync.keepLocalVersion(widget.uuid) : widget.app.sync.takeServerVersion(widget.uuid);
      setState(_load);
      widget.app.changed();
    } catch (e) {
      if (mounted) showMessage(context, '$e', error: true);
    }
  }

  Future<void> _takePhoto() async {
    try {
      final shot = await ImagePicker().pickImage(source: ImageSource.camera, imageQuality: 70, maxWidth: 2000);
      if (shot == null) return;
      final dir = await getApplicationSupportDirectory();
      final dest = p.join(dir.path, 'photos', '${widget.uuid}_${DateTime.now().millisecondsSinceEpoch}.jpg');
      await Directory(p.dirname(dest)).create(recursive: true);
      await File(shot.path).copy(dest);
      _svc.addSignedFormPhoto(widget.uuid, dest);
      setState(() {});
      widget.app.changed(trySend: true);
      if (mounted) showMessage(context, 'Photo enregistrée : elle sera envoyée après la collecte.');
    } catch (e) {
      if (mounted) showMessage(context, 'Photo impossible : $e', error: true);
    }
  }

  void _showIssues({bool onlyErrors = false}) {
    final list = [..._result.errors, if (!onlyErrors) ..._result.warnings];
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (c) => DraggableScrollableSheet(
        expand: false,
        initialChildSize: 0.6,
        builder: (c, scroll) => ListView(controller: scroll, padding: const EdgeInsets.all(16), children: [
          Text(
            onlyErrors
                ? '${_result.errors.length} point(s) à corriger avant de terminer'
                : '${_result.errors.length} erreur(s), ${_result.warnings.length} avertissement(s)',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 8),
          if (list.isEmpty) const Text('Aucun point à vérifier.'),
          for (final i in list)
            ListTile(
              leading: Icon(i.isError ? Icons.error : Icons.warning_amber, color: i.isError ? Colors.red.shade800 : Colors.orange.shade800),
              title: Text(i.message),
              subtitle: Text(_labelOf(i.target)),
              onTap: () {
                Navigator.pop(c);
                final s = _sectionOf(i.target);
                if (s != null) _tabs.animateTo(s);
              },
            ),
        ]),
      ),
    );
  }

  Item? _itemOf(String target) {
    for (final s in _engine.sections) {
      for (final item in (s['items'] as List).cast<Item>()) {
        final code = item['code'] as String;
        if (target == code || target.startsWith('$code.') || target.startsWith('$code[')) return item;
      }
    }
    return null;
  }

  int? _sectionOf(String target) {
    final sections = _engine.sections;
    for (var s = 0; s < sections.length; s++) {
      for (final item in (sections[s]['items'] as List).cast<Item>()) {
        final code = item['code'] as String;
        if (target == code || target.startsWith('$code.') || target.startsWith('$code[')) return s;
      }
    }
    return null;
  }

  String _labelOf(String target) {
    final item = _itemOf(target);
    if (item == null) return target;
    final row = RegExp(r'\[(\d+)\]').firstMatch(target);
    return '${item['number'] ?? ''} ${item['label']}${row != null ? ' — ligne ${int.parse(row[1]!) + 1}' : ''}';
  }

  // ---------------------------------------------------------------------- Rendu

  @override
  Widget build(BuildContext context) {
    if (_loadError != null) {
      return Scaffold(appBar: AppBar(title: const Text('Collecte')), body: Padding(padding: const EdgeInsets.all(24), child: Text(_loadError!)));
    }
    final structure = _svc.structureOf(_col['campaign_structure_id'] as int);
    final sections = _engine.sections;
    return Scaffold(
      appBar: AppBar(
        title: Text((structure['name'] ?? 'Collecte').toString(), overflow: TextOverflow.ellipsis),
        actions: [
          IconButton(
            tooltip: 'Liste des points à vérifier',
            onPressed: _showIssues,
            icon: Badge(
              isLabelVisible: _result.errors.isNotEmpty || _result.warnings.isNotEmpty,
              label: Text('${_result.errors.length + _result.warnings.length}'),
              child: const Icon(Icons.checklist),
            ),
          ),
        ],
        bottom: TabBar(
          controller: _tabs,
          isScrollable: true,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white70,
          indicatorColor: brandOrange,
          tabs: [
            for (final s in sections)
              Tab(
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  Text('${s['number']}. ${_short(s['title'] as String)}'),
                  if (_sectionErrors(s) > 0) ...[const SizedBox(width: 6), Badge(label: Text('${_sectionErrors(s)}'), backgroundColor: Colors.red.shade700)],
                ]),
              ),
          ],
        ),
      ),
      body: Column(children: [
        _StatusBanner(state: this),
        Expanded(
          child: TabBarView(controller: _tabs, children: [
            for (var s = 0; s < sections.length; s++)
              ListView(
                key: PageStorageKey('section-$s-$_version'),
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 96),
                children: [
                  Text('${sections[s]['number']}. ${sections[s]['title']}', style: Theme.of(context).textTheme.titleLarge),
                  const SizedBox(height: 8),
                  ..._sectionItems(sections[s]),
                  if (s == sections.length - 1) _finishPanel(),
                ],
              ),
          ]),
        ),
      ]),
      bottomNavigationBar: _navBar(sections.length),
    );
  }

  void _goTo(int index) {
    FocusScope.of(context).unfocus(); // valide la saisie en cours avant de changer de section
    _tabs.animateTo(index);
    setState(() {});
  }

  /// Barre du bas : Précédent / Suivant (en plus du balayage), position dans le questionnaire, Terminer en dernière section.
  Widget _navBar(int count) {
    final i = _tabs.index;
    final last = i >= count - 1;
    final warnings = _result.warnings.length;
    final status = _result.errors.isNotEmpty
        ? ('${_result.errors.length} point(s) à corriger', Colors.red.shade800)
        : warnings > 0
            ? ('$warnings point(s) à vérifier', Colors.orange.shade900)
            : ('Tout est renseigné', brandGreenDark);
    return SafeArea(
      child: Material(
        elevation: 8,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 6, 12, 8),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Row(children: [
              Text('Section ${i + 1} sur $count', style: Theme.of(context).textTheme.bodySmall),
              const Spacer(),
              if (_editable)
                InkWell(
                  onTap: _showIssues,
                  child: Text(status.$1, style: TextStyle(color: status.$2, fontWeight: FontWeight.w600, fontSize: 13)),
                ),
            ]),
            const SizedBox(height: 6),
            Row(children: [
              Expanded(
                child: OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                  onPressed: i > 0 ? () => _goTo(i - 1) : null,
                  icon: const Icon(Icons.chevron_left),
                  label: const Text('Précédent'),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: last
                    ? (_editable
                        ? FilledButton.icon(
                            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52), backgroundColor: brandGreenDark),
                            onPressed: _finish,
                            icon: const Icon(Icons.check_circle),
                            label: const Text('Terminer'),
                          )
                        : const SizedBox.shrink())
                    : FilledButton(
                        style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                        onPressed: () => _goTo(i + 1),
                        child: const Row(mainAxisSize: MainAxisSize.min, children: [Text('Suivant'), SizedBox(width: 4), Icon(Icons.chevron_right)]),
                      ),
              ),
            ]),
          ]),
        ),
      ),
    );
  }

  static String _short(String title) => title.length > 28 ? '${title.substring(0, 26)}…' : title;

  int _sectionErrors(Item section) {
    var n = 0;
    for (final item in (section['items'] as List).cast<Item>()) {
      n += _issuesUnder(item['code'] as String).where((i) => i.isError).length;
    }
    return n;
  }

  List<Widget> _sectionItems(Item section) {
    final vis = _visCtx;
    final out = <Widget>[];
    String? group;
    for (final item in (section['items'] as List).cast<Item>()) {
      if (!_engine.isVisible(item, vis)) continue;
      final g = item['group'] as String?;
      if (g != null && g != group) {
        out.add(Padding(
            padding: const EdgeInsets.only(top: 16, bottom: 4),
            child: Text(g, style: Theme.of(context).textTheme.titleMedium?.copyWith(color: brandGreenDark))));
      }
      group = g;
      out.add(Padding(padding: const EdgeInsets.symmetric(vertical: 8), child: _item(item)));
    }
    return out;
  }

  Widget _item(Item item) {
    final code = item['code'] as String;
    final path = code.split('.');
    final type = item['type'] as String;
    final label = '${item['number'] != null ? '${item['number']} ' : ''}${item['label']}';
    switch (type) {
      case 'roster':
        return _RosterField(state: this, item: item, label: label);
      case 'grid':
        return _GridField(state: this, item: item, label: label);
      case 'computed':
      case 'computed_grid':
        return _ComputedField(item: item, label: label, value: JsonLogic.varPath(item['source'] ?? code, _result.computed));
      case 'geo':
        final id = QuestionnaireEngine.getPath(_data, path);
        final db = widget.app.db;
        final n = id is int ? id : null;
        final name = switch (item['level']) { 'region' => db.regionName(n), 'department' => db.departmentName(n), _ => db.communeName(n) };
        return _ReadOnly(label: label, value: name ?? '—', help: 'Repris du registre des structures (non modifiable).');
      case 'auto':
        final v = item['source'] == 'agent.full_name' ? widget.app.session?.fullName : null;
        return _ReadOnly(label: label, value: v ?? 'Renseigné par le serveur', help: 'Rempli automatiquement.');
      default:
        if (item['locked'] == true) {
          return _ReadOnly(label: label, value: _display(item, QuestionnaireEngine.getPath(_data, path)), help: 'Repris du registre des structures (non modifiable).');
        }
        return _ValueField(
          key: ValueKey('$code#$_version'),
          state: this,
          item: item,
          label: label,
          value: QuestionnaireEngine.getPath(_data, path),
          issues: _issues[code] ?? const [],
          onChanged: (v) {
            _set(path, v);
            // la visibilité d'autres questions peut dépendre de cette réponse
            if (type == 'yesno' || type == 'single' || type == 'multi') setState(_revalidate);
          },
        );
    }
  }

  String _display(Item item, Object? v) {
    if (v == null) return '—';
    if (v is bool) return v ? 'Oui' : 'Non';
    final options = _options(item);
    if (options != null) {
      if (v is List) return v.map((x) => options.firstWhere((o) => o.$1 == x, orElse: () => (x as Object, '$x')).$2).join(' ; ');
      return options.firstWhere((o) => o.$1 == v, orElse: () => (v, '$v')).$2;
    }
    return '$v';
  }

  /// Modalités d'une question : liste fixe, ou métiers du secteur (référentiel local) + « Autre ».
  List<(Object, String)>? _options(Item item) {
    final opts = item['options'];
    if (opts is List) return [for (final o in opts.cast<Map>()) (o['code'] as Object, '${o['label']}')];
    final src = item['options_source'] as String?;
    if (src != null && src.startsWith('trades.')) {
      final sector = int.parse(src.substring(7));
      return [for (final t in widget.app.db.tradesOfSector(sector)) (t['id'] as Object, '${t['label']}'), ('AUTRE', 'Autre (préciser)')];
    }
    return null;
  }

  Widget _finishPanel() {
    final atts = widget.app.db.attachmentsOf(widget.uuid);
    return Card(
      margin: const EdgeInsets.only(top: 24),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('Fiche papier signée', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 4),
          const Text('Photo facultative de la fiche papier signée et cachetée, envoyée après acceptation de la collecte.'),
          const SizedBox(height: 8),
          for (final a in atts)
            Text('• Photo du ${formatDateTime(DateTime.parse(a['created_at'] as String).toLocal())} — '
                '${a['state'] == OutboxState.synchronise ? 'envoyée' : a['state'] == OutboxState.echec ? 'échec : ${a['last_error']}' : "en attente d'envoi"}'),
          const SizedBox(height: 8),
          OutlinedButton.icon(onPressed: _takePhoto, icon: const Icon(Icons.photo_camera), label: const Text('Photographier la fiche')),
        ]),
      ),
    );
  }
}

// ============================================================================== Bandeau d'état

class _StatusBanner extends StatelessWidget {
  const _StatusBanner({required this.state});
  final _FormScreenState state;

  @override
  Widget build(BuildContext context) {
    final col = state._col;
    final status = col['local_status'] as String;
    final review = col['review_json'] == null ? null : jsonDecode(col['review_json'] as String) as Map;
    final conflict = col['conflict_json'] == null ? null : jsonDecode(col['conflict_json'] as String) as Map;
    final serverIssues = col['server_issues_json'] == null ? null : jsonDecode(col['server_issues_json'] as String);
    final children = <Widget>[];
    Color color = Colors.transparent;

    switch (status) {
      case LocalStatus.invalidee:
        color = Colors.red.shade50;
        children.add(Text('Renvoyée pour correction${review?['reason'] != null ? ' : ${review!['reason']}' : ''}', style: const TextStyle(fontWeight: FontWeight.w600)));
        if (review?['comment'] != null) children.add(Text('${review!['comment']}'));
      case LocalStatus.terminee:
        color = Colors.blue.shade50;
        children.addAll([
          const Text("Terminée — en attente d'envoi. La collecte n'est plus modifiable.", style: TextStyle(fontWeight: FontWeight.w600)),
          Align(alignment: Alignment.centerLeft, child: TextButton(onPressed: state._reopenLocal, child: const Text('Reprendre la saisie'))),
        ]);
      case LocalStatus.soumise:
        color = Colors.green.shade50;
        children.add(Text('Envoyée (révision ${col['server_revision']}) — en contrôle par le superviseur. Lecture seule.', style: const TextStyle(fontWeight: FontWeight.w600)));
      case LocalStatus.validee:
        color = Colors.green.shade50;
        children.add(const Text('Validée. Lecture seule.', style: TextStyle(fontWeight: FontWeight.w600)));
      case LocalStatus.enConflit:
        color = Colors.purple.shade50;
        final hasServerData = conflict?['details'] is Map && (conflict!['details'] as Map)['data'] is Map;
        children.addAll([
          Text('Conflit : ${conflict?['message'] ?? 'état différent sur le serveur'}', style: const TextStyle(fontWeight: FontWeight.w600)),
          const Text('Votre saisie est conservée intacte. Choisissez la suite, ou contactez votre superviseur.'),
          Wrap(spacing: 8, children: [
            TextButton(onPressed: () => state._resolve(true), child: const Text('Garder ma version')),
            if (hasServerData) TextButton(onPressed: () => state._resolve(false), child: const Text('Prendre la version du serveur')),
          ]),
        ]);
    }
    if (serverIssues is List && serverIssues.isNotEmpty) {
      if (status == LocalStatus.brouillon) {
        color = Colors.red.shade50;
        children.add(const Text('Refusée par le serveur : corriger puis terminer de nouveau.', style: TextStyle(fontWeight: FontWeight.w600)));
      }
      for (final i in serverIssues.cast<Map>().take(5)) {
        children.add(Text('• ${i['message']}', style: TextStyle(color: i['severity'] == 'warning' ? Colors.orange.shade900 : Colors.red.shade900)));
      }
    }
    if (children.isEmpty) return const SizedBox.shrink();
    return Semantics(
      liveRegion: true,
      child: Container(
          width: double.infinity,
          color: color,
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 6),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: children)),
    );
  }
}

// ================================================================================= Champs

class _FieldShell extends StatelessWidget {
  const _FieldShell({required this.label, required this.child, this.help, this.issues = const [], this.required = false});
  final String label;
  final String? help;
  final Widget child;
  final List<Issue> issues;
  final bool required;

  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text.rich(TextSpan(children: [
          TextSpan(text: label, style: const TextStyle(fontWeight: FontWeight.w600)),
          // Repère discret « à renseigner » (orange) : une question vide n'est jamais bloquante.
          if (required) TextSpan(text: ' •', style: TextStyle(color: Colors.orange.shade800, fontWeight: FontWeight.w900), semanticsLabel: ' à renseigner'),
        ])),
        if (help != null) Padding(padding: const EdgeInsets.only(top: 2), child: Text(help!, style: Theme.of(context).textTheme.bodySmall)),
        const SizedBox(height: 6),
        child,
        for (final i in issues)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Icon(i.isError ? Icons.error : Icons.warning_amber, size: 16, color: i.isError ? Colors.red.shade800 : Colors.orange.shade800),
              const SizedBox(width: 4),
              Expanded(child: Text(i.message, style: TextStyle(color: i.isError ? Colors.red.shade800 : Colors.orange.shade900, fontSize: 13))),
            ]),
          ),
      ]);
}

class _ReadOnly extends StatelessWidget {
  const _ReadOnly({required this.label, required this.value, this.help});
  final String label;
  final String value;
  final String? help;

  @override
  Widget build(BuildContext context) => _FieldShell(
        label: label,
        help: help,
        child: Container(
          width: double.infinity,
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(color: Colors.grey.shade100, borderRadius: BorderRadius.circular(4)),
          child: Text(value),
        ),
      );
}

class _ComputedField extends StatelessWidget {
  const _ComputedField({required this.item, required this.label, required this.value});
  final Item item;
  final String label;
  final Object? value;

  @override
  Widget build(BuildContext context) {
    Widget body;
    if (item['type'] == 'computed_grid' && item['rows'] is List) {
      final rows = (item['rows'] as List).cast<Map>();
      final cols = (item['columns'] as List).cast<Map>();
      body = SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: DataTable(
          headingRowHeight: 40,
          columns: [
            const DataColumn(label: Text('')),
            for (final c in cols) DataColumn(label: Text('${c['label']}'.replaceAll('Secteur ', ''), style: const TextStyle(fontSize: 12)), numeric: true),
          ],
          rows: [
            for (final r in rows)
              DataRow(cells: [
                DataCell(Text('${r['label']}')),
                for (final c in cols) DataCell(Text('${JsonLogic.varPath('${r['code']}.${c['code']}', value) ?? 0}')),
              ]),
          ],
        ),
      );
    } else if (value is Map) {
      final m = value as Map;
      const names = {'M': 'Hommes', 'F': 'Femmes', 'T': 'Total'};
      body = Text(['M', 'F', 'T'].where(m.containsKey).map((k) => '${names[k]} : ${m[k]}').join(' · '));
    } else {
      body = Text('${value ?? 0}');
    }
    return _FieldShell(label: label, help: (item['help'] as String?) ?? 'Calculé automatiquement.', child: body);
  }
}

/// Champ de saisie d'une valeur simple (texte, téléphone, entier, date, Oui/Non, choix unique ou multiple, GPS).
class _ValueField extends StatefulWidget {
  const _ValueField({super.key, required this.state, required this.item, required this.label, required this.value, required this.issues, required this.onChanged});
  final _FormScreenState state;
  final Item item;
  final String label;
  final Object? value;
  final List<Issue> issues;
  final ValueChanged<Object?> onChanged;

  @override
  State<_ValueField> createState() => _ValueFieldState();
}

class _ValueFieldState extends State<_ValueField> {
  TextEditingController? _text;
  TextEditingController? _note;
  Object? _value;
  bool _locating = false;

  Item get item => widget.item;
  bool get _editable => widget.state._editable;

  @override
  void initState() {
    super.initState();
    _value = widget.value;
    if (const {'text', 'phone', 'integer', 'date'}.contains(item['type'])) _text = TextEditingController(text: _value == null ? '' : '$_value');
    if (item['type'] == 'gps') _note = TextEditingController(text: (_value is Map ? (_value as Map)['note'] ?? '' : '').toString());
  }

  @override
  void dispose() {
    _text?.dispose();
    _note?.dispose();
    super.dispose();
  }

  void _change(Object? v) {
    setState(() => _value = v);
    widget.onChanged(v);
  }

  @override
  Widget build(BuildContext context) {
    final type = item['type'] as String;
    final hasError = widget.issues.any((i) => i.isError);
    final decoration = InputDecoration(
      enabledBorder: hasError ? OutlineInputBorder(borderSide: BorderSide(color: Colors.red.shade800, width: 2)) : null,
    );
    Widget input;
    switch (type) {
      case 'text':
        input = TextField(
          controller: _text,
          enabled: _editable,
          maxLines: item['multiline'] == true ? 4 : 1,
          maxLength: (item['max_length'] as num?)?.toInt(),
          textCapitalization: TextCapitalization.sentences,
          decoration: decoration,
          onChanged: _change,
        );
      case 'phone':
        input = TextField(
          controller: _text,
          enabled: _editable,
          keyboardType: TextInputType.phone,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(8)],
          decoration: decoration.copyWith(hintText: '8 chiffres, ex. 90123456'),
          onChanged: _change,
        );
      case 'integer':
        input = TextField(
          controller: _text,
          enabled: _editable,
          keyboardType: TextInputType.number,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(6)],
          decoration: decoration,
          onChanged: (v) => _change(v.isEmpty ? null : int.tryParse(v)),
        );
      case 'date':
        final partial = item['partial'] == true;
        input = TextField(
          controller: _text,
          enabled: _editable,
          keyboardType: TextInputType.datetime,
          inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9-]')), LengthLimitingTextInputFormatter(10)],
          decoration: decoration.copyWith(
            hintText: partial ? 'AAAA-MM-JJ, AAAA-MM ou AAAA' : 'AAAA-MM-JJ',
            suffixIcon: IconButton(
              tooltip: 'Choisir dans le calendrier',
              icon: const Icon(Icons.calendar_month),
              onPressed: !_editable
                  ? null
                  : () async {
                      final d = await showDatePicker(
                          context: context, firstDate: DateTime(1950), lastDate: DateTime.now().add(const Duration(days: 1)), initialDate: DateTime.now());
                      if (d != null) {
                        final s = d.toIso8601String().substring(0, 10);
                        _text!.text = s;
                        _change(s);
                      }
                    },
            ),
          ),
          onChanged: _change,
        );
      case 'yesno':
        input = SegmentedButton<bool>(
          emptySelectionAllowed: true,
          segments: const [ButtonSegment(value: true, label: Text('Oui')), ButtonSegment(value: false, label: Text('Non'))],
          selected: _value is bool ? {_value as bool} : <bool>{},
          onSelectionChanged: _editable ? (s) => _change(s.isEmpty ? null : s.first) : null,
        );
      case 'single':
        final options = widget.state._options(item) ?? const [];
        if (options.length <= 5) {
          input = RadioGroup<Object>(
            groupValue: _value,
            onChanged: (v) {
              if (_editable) _change(v);
            },
            child: Column(children: [
              for (final o in options)
                RadioListTile<Object>(contentPadding: EdgeInsets.zero, dense: true, title: Text(o.$2), value: o.$1, enabled: _editable),
            ]),
          );
        } else {
          input = DropdownButtonFormField<Object>(
            isExpanded: true,
            initialValue: options.any((o) => o.$1 == _value) ? _value : null,
            decoration: decoration.copyWith(hintText: 'Choisir…'),
            items: [for (final o in options) DropdownMenuItem(value: o.$1, child: Text(o.$2, overflow: TextOverflow.ellipsis))],
            onChanged: _editable ? _change : null,
          );
        }
      case 'multi':
        final options = widget.state._options(item) ?? const [];
        final selected = (_value is List ? (_value as List) : const []).toList();
        input = Column(children: [
          for (final o in options)
            CheckboxListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              controlAffinity: ListTileControlAffinity.leading,
              title: Text(o.$2),
              value: selected.contains(o.$1),
              onChanged: !_editable
                  ? null
                  : (on) {
                      final next = [...selected]..remove(o.$1);
                      if (on == true) next.add(o.$1);
                      // ordre de la liste des modalités (réponse stable)
                      next.sort((a, b) => options.indexWhere((x) => x.$1 == a).compareTo(options.indexWhere((x) => x.$1 == b)));
                      _change(next.isEmpty ? null : next);
                    },
            ),
        ]);
      case 'gps':
        input = _gps();
      default:
        input = Text('Type de question non pris en charge : $type');
    }
    return _FieldShell(label: widget.label, help: item['help'] as String?, required: item['required'] == true, issues: widget.issues, child: input);
  }

  Widget _gps() {
    final g = _value is Map ? Map<String, Object?>.from(_value as Map) : <String, Object?>{};
    final status = g['status'] as String?;
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      if (status == 'OK' || status == 'MANUEL')
        Text('Latitude ${g['lat']} · Longitude ${g['lng']}${g['accuracy_m'] != null ? ' · précision ± ${g['accuracy_m']} m' : ''}'),
      if (status == 'INDISPONIBLE' || status == 'REFUSE') Text(status == 'REFUSE' ? 'Relevé refusé sur place' : 'Position indisponible'),
      const SizedBox(height: 8),
      Wrap(spacing: 8, runSpacing: 8, children: [
        FilledButton.icon(
          onPressed: !_editable || _locating ? null : _locate,
          icon: _locating
              ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Icon(Icons.my_location),
          label: Text(status == 'OK' ? 'Relever de nouveau' : 'Relever la position'),
        ),
        OutlinedButton(
          onPressed: !_editable ? null : () => _change({'lat': null, 'lng': null, 'accuracy_m': null, 'captured_at': null, 'status': 'INDISPONIBLE', 'note': _note!.text}),
          child: const Text('Position indisponible'),
        ),
      ]),
      if (status == 'INDISPONIBLE' || status == 'REFUSE')
        Padding(
          padding: const EdgeInsets.only(top: 8),
          child: TextField(
            controller: _note,
            enabled: _editable,
            decoration: const InputDecoration(labelText: 'Motif (obligatoire)'),
            onChanged: (v) {
              _value = {...g, 'note': v};
              widget.onChanged(_value);
            },
          ),
        ),
    ]);
  }

  Future<void> _locate() async {
    setState(() => _locating = true);
    try {
      if (!await Geolocator.isLocationServiceEnabled()) throw 'Activer la localisation (GPS) de la tablette.';
      var perm = await Geolocator.checkPermission();
      if (perm == LocationPermission.denied) perm = await Geolocator.requestPermission();
      if (perm == LocationPermission.denied || perm == LocationPermission.deniedForever) throw 'Autorisation de localisation refusée.';
      final pos = await Geolocator.getCurrentPosition(locationSettings: const LocationSettings(accuracy: LocationAccuracy.best, timeLimit: Duration(seconds: 60)));
      _change({
        'lat': double.parse(pos.latitude.toStringAsFixed(6)),
        'lng': double.parse(pos.longitude.toStringAsFixed(6)),
        'accuracy_m': double.parse(pos.accuracy.toStringAsFixed(1)),
        'captured_at': DateTime.now().toUtc().toIso8601String(),
        'status': 'OK',
        'note': null,
      });
    } catch (e) {
      if (mounted) showMessage(context, 'Relevé GPS impossible : $e', error: true);
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }
}

/// Grille d'effectifs (lignes × colonnes d'entiers), présentée ligne par ligne pour le téléphone.
class _GridField extends StatelessWidget {
  const _GridField({required this.state, required this.item, required this.label});
  final _FormScreenState state;
  final Item item;
  final String label;

  static const _rowLabelWidth = 104.0;

  List<String> _path(String code, Map r, Map c) => [...code.split('.'), '${r['code']}', ...'${c['code']}'.split('.')];

  @override
  Widget build(BuildContext context) {
    final code = item['code'] as String;
    final rows = (item['rows'] as List).cast<Map>();
    final cols = (item['columns'] as List).cast<Map>();
    final cell = Map<String, Object?>.from(item['cell'] as Map);
    final cellTargets = {for (final r in rows) for (final c in cols) '$code.${r['code']}.${c['code']}'};
    final cellIssues = [for (final t in cellTargets) ...?state._issues[t]];
    return _FieldShell(
      label: label,
      help: item['help'] as String?,
      required: item['required'] == true,
      // anomalies de la grille entière ; celles des cases sont listées sous le tableau (vue compacte) ou sous la case
      issues: [for (final i in state._issuesUnder(code)) if (!cellTargets.contains(i.target)) i],
      child: LayoutBuilder(builder: (context, box) {
        final compact = box.maxWidth >= 120 + cols.length * 64;
        return compact ? _table(context, box.maxWidth, code, rows, cols, cellIssues) : _cards(code, rows, cols, cell);
      }),
    );
  }

  /// Vue tablette : un vrai tableau (durées en lignes, colonnes regroupées par en-tête), totaux en direct.
  Widget _table(BuildContext context, double width, String code, List<Map> rows, List<Map> cols, List<Issue> cellIssues) {
    final cellW = (width - _rowLabelWidth) / cols.length;
    // En-têtes : « Secteur Primaire/Agricole — M » → groupe « Secteur Primaire/Agricole », colonne « M ».
    (String, String) split(Map c) {
      final l = '${c['label']}';
      final i = l.lastIndexOf(' — ');
      if (i < 0) return ('', l);
      final sub = l.substring(i + 3);
      return (l.substring(0, i), switch (sub) { 'Masculin' => 'M', 'Féminin' => 'F', 'Total' => 'T', _ => sub });
    }
    final groups = <(String, int)>[];
    for (final c in cols) {
      final g = split(c).$1;
      if (groups.isNotEmpty && groups.last.$1 == g) {
        groups[groups.length - 1] = (g, groups.last.$2 + 1);
      } else {
        groups.add((g, 1));
      }
    }
    final head = Theme.of(context).textTheme.labelMedium?.copyWith(fontWeight: FontWeight.w700);
    Widget headCell(String text, double w, {Color? bg}) => Container(
          width: w,
          padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 6),
          decoration: BoxDecoration(color: bg, border: Border.all(color: Colors.grey.shade300, width: .5)),
          alignment: Alignment.center,
          child: Text(text, textAlign: TextAlign.center, style: head),
        );
    num total(Map c) {
      num t = 0;
      for (final r in rows) {
        final v = QuestionnaireEngine.getPath(state._data, _path(code, r, c));
        if (v is num) t += v;
      }
      return t;
    }
    final band = brandGreen.withValues(alpha: .08);
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      if (groups.any((g) => g.$1.isNotEmpty))
        Row(children: [
          const SizedBox(width: _rowLabelWidth),
          for (final g in groups) headCell(g.$1, cellW * g.$2, bg: band),
        ]),
      Row(children: [
        headCell('Durée', _rowLabelWidth, bg: band),
        for (final c in cols) headCell(split(c).$2, cellW, bg: band),
      ]),
      for (final r in rows)
        Row(children: [
          Container(
            width: _rowLabelWidth,
            height: 52,
            padding: const EdgeInsets.symmetric(horizontal: 6),
            alignment: Alignment.centerLeft,
            decoration: BoxDecoration(border: Border.all(color: Colors.grey.shade300, width: .5)),
            child: Text('${r['label']}', style: const TextStyle(fontWeight: FontWeight.w600)),
          ),
          for (final c in cols)
            SizedBox(
              width: cellW,
              height: 52,
              child: _GridCell(
                key: ValueKey('$code.${r['code']}.${c['code']}#${state._version}'),
                state: state,
                path: _path(code, r, c),
                issues: state._issues['$code.${r['code']}.${c['code']}'] ?? const [],
                semantics: '${r['label']}, ${c['label']}',
              ),
            ),
        ]),
      if (item['totals'] == true)
        Row(children: [
          headCell('Total', _rowLabelWidth),
          for (final c in cols) headCell('${total(c)}', cellW),
        ]),
      for (final i in cellIssues)
        Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Icon(i.isError ? Icons.error : Icons.warning_amber, size: 16, color: i.isError ? Colors.red.shade800 : Colors.orange.shade800),
            const SizedBox(width: 4),
            Expanded(child: Text(i.message, style: TextStyle(color: i.isError ? Colors.red.shade800 : Colors.orange.shade900, fontSize: 13))),
          ]),
        ),
    ]);
  }

  /// Vue étroite (téléphone) : une carte par ligne, comme avant.
  Widget _cards(String code, List<Map> rows, List<Map> cols, Map<String, Object?> cell) => Column(children: [
        for (final r in rows)
          Card(
            margin: const EdgeInsets.only(bottom: 8),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${r['label']}', style: const TextStyle(fontWeight: FontWeight.w600)),
                const SizedBox(height: 8),
                Wrap(spacing: 12, runSpacing: 12, children: [
                  for (final c in cols)
                    SizedBox(
                      width: 150,
                      child: _ValueField(
                        key: ValueKey('$code.${r['code']}.${c['code']}#${state._version}'),
                        state: state,
                        item: {...cell, 'code': '$code.${r['code']}.${c['code']}', 'required': item['required']},
                        label: '${c['label']}',
                        value: QuestionnaireEngine.getPath(state._data, _path(code, r, c)),
                        issues: state._issues['$code.${r['code']}.${c['code']}'] ?? const [],
                        onChanged: (v) => state._set(_path(code, r, c), v),
                      ),
                    ),
                ]),
              ]),
            ),
          ),
      ]);
}

/// Case numérique compacte du tableau (chiffres seulement ; vide = non renseigné). Bordure orange ou rouge si un point
/// concerne la case.
class _GridCell extends StatefulWidget {
  const _GridCell({super.key, required this.state, required this.path, required this.issues, required this.semantics});
  final _FormScreenState state;
  final List<String> path;
  final List<Issue> issues;
  final String semantics;

  @override
  State<_GridCell> createState() => _GridCellState();
}

class _GridCellState extends State<_GridCell> {
  late final TextEditingController _ctrl;

  @override
  void initState() {
    super.initState();
    final v = QuestionnaireEngine.getPath(widget.state._data, widget.path);
    _ctrl = TextEditingController(text: v == null ? '' : '$v');
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final error = widget.issues.any((i) => i.isError);
    final warn = !error && widget.issues.isNotEmpty;
    final color = error ? Colors.red.shade800 : (warn ? Colors.orange.shade800 : Colors.grey.shade400);
    return Container(
      decoration: BoxDecoration(
        color: warn ? Colors.orange.shade50 : (error ? Colors.red.shade50 : null),
        border: Border.all(color: color, width: error || warn ? 1.5 : .5),
      ),
      child: Semantics(
        label: widget.semantics,
        textField: true,
        child: TextField(
          controller: _ctrl,
          enabled: widget.state._editable,
          textAlign: TextAlign.center,
          keyboardType: TextInputType.number,
          textInputAction: TextInputAction.next,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(5)],
          style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w600),
          decoration: const InputDecoration(border: InputBorder.none, isDense: true, contentPadding: EdgeInsets.symmetric(vertical: 14)),
          onChanged: (v) => widget.state._set(widget.path, v.isEmpty ? null : int.tryParse(v)),
        ),
      ),
    );
  }
}

/// Tableau de lignes (formateurs, métiers) : une carte par ligne, ajout et suppression.
class _RosterField extends StatelessWidget {
  const _RosterField({required this.state, required this.item, required this.label});
  final _FormScreenState state;
  final Item item;
  final String label;

  @override
  Widget build(BuildContext context) {
    final code = item['code'] as String;
    final path = code.split('.');
    final raw = QuestionnaireEngine.getPath(state._data, path);
    final rows = raw is List ? raw : const [];
    final columns = (item['columns'] as List).cast<Item>();
    final maxRows = (item['max_rows'] as num?)?.toInt() ?? 99;
    final rowLabel = (item['row_label'] as String?) ?? 'Ligne {_line}';
    final vis = state._visCtx;

    return _FieldShell(
      label: label,
      help: item['help'] as String?,
      issues: state._issues[code] ?? const [],
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (rows.isEmpty) const Padding(padding: EdgeInsets.only(bottom: 8), child: Text('Aucune ligne.')),
        for (var i = 0; i < rows.length; i++)
          _row(context, code, path, i, rows[i] is Map ? Map<String, Object?>.from(rows[i] as Map) : <String, Object?>{}, columns, rowLabel, vis),
        if (state._editable && rows.length < maxRows)
          OutlinedButton.icon(
            onPressed: () {
              QuestionnaireEngine.setPath(state._data, path, [...rows, <String, Object?>{}]);
              state._set(path, QuestionnaireEngine.getPath(state._data, path));
              state.refresh(rebuildFields: true);
            },
            icon: const Icon(Icons.add),
            label: Text('Ajouter : ${rowLabel.replaceAll('{_line}', '${rows.length + 1}')}'),
          ),
      ]),
    );
  }

  Widget _row(BuildContext context, String code, List<String> path, int i, Map<String, Object?> row, List<Item> columns, String rowLabel, Map<String, Object?> vis) {
    final ctx = {...row, '_line': i + 1, '_root': vis};
    final errors = state._issuesUnder('$code[$i]').where((x) => x.isError).length;
    final title = rowLabel.replaceAll('{_line}', '${i + 1}');
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ExpansionTile(
        key: PageStorageKey('$code[$i]#${state._version}'),
        initiallyExpanded: row.isEmpty || errors > 0,
        title: Text(title),
        subtitle: Text(_summary(row, columns), maxLines: 1, overflow: TextOverflow.ellipsis),
        trailing: Row(mainAxisSize: MainAxisSize.min, children: [
          if (errors > 0) Badge(label: Text('$errors'), backgroundColor: Colors.red.shade700),
          if (state._editable)
            IconButton(
              tooltip: 'Supprimer la ligne ${i + 1}',
              icon: const Icon(Icons.delete_outline),
              onPressed: () async {
                if (!await confirm(context, 'Supprimer la ligne', 'Supprimer « $title » ?', ok: 'Supprimer')) return;
                final current = QuestionnaireEngine.getPath(state._data, path);
                final list = [...(current is List ? current : const [])]..removeAt(i);
                QuestionnaireEngine.setPath(state._data, path, list);
                state._set(path, list.isEmpty ? null : list);
                if (list.isEmpty) QuestionnaireEngine.setPath(state._data, path, <Object?>[]);
                state.refresh(rebuildFields: true);
              },
            ),
        ]),
        childrenPadding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
        children: [
          for (final col in columns)
            if (state._engine.isVisible(col, ctx))
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: _ValueField(
                  key: ValueKey('$code[$i].${col['code']}#${state._version}'),
                  state: state,
                  item: col,
                  label: '${col['label']}',
                  value: row[col['code']],
                  issues: state._issues['$code[$i].${col['code']}'] ?? const [],
                  onChanged: (v) {
                    state._set([...path, '$i', col['code'] as String], v);
                    // la visibilité des autres colonnes peut dépendre de cette réponse
                    if (col['type'] == 'yesno' || col['type'] == 'single') state.refresh(revalidate: true);
                  },
                ),
              ),
        ],
      ),
    );
  }

  String _summary(Map<String, Object?> row, List<Item> columns) => columns
      .where((c) => c['type'] == 'text' || c['type'] == 'single')
      .map((c) => state._display(c, row[c['code']]))
      .where((s) => s != '—')
      .take(3)
      .join(' · ');
}
