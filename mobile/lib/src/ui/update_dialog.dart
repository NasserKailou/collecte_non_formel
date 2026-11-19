// Fenêtre de mise à jour : l'agent reste dans l'application pendant le téléchargement (progression en Mo et %),
// puis « Mettre à jour maintenant » ouvre l'installeur Android. Les collectes restent sur la tablette.

import 'dart:io';

import 'package:flutter/material.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

import '../app_state.dart';
import '../services/app_updater.dart';
import 'common.dart';

Future<void> showUpdateDialog(BuildContext context, AppState app) =>
    showDialog<void>(context: context, barrierDismissible: false, builder: (_) => _UpdateDialog(app: app));

class _UpdateDialog extends StatefulWidget {
  const _UpdateDialog({required this.app});
  final AppState app;

  @override
  State<_UpdateDialog> createState() => _UpdateDialogState();
}

enum _Step { preparing, downloading, ready, installing, failed }

class _UpdateDialogState extends State<_UpdateDialog> {
  final _updater = AppUpdater();
  _Step _step = _Step.preparing;
  int _received = 0;
  int? _total;
  String? _error;
  File? _apk;
  late final String _version = '${widget.app.appUpdate?['version'] ?? ''}';

  @override
  void initState() {
    super.initState();
    _start();
  }

  Future<void> _start() async {
    setState(() {
      _step = _Step.preparing;
      _error = null;
      _received = 0;
      _total = null;
    });
    try {
      await widget.app.refreshAppUpdate(); // lien signé récent (il expire au bout de quelques minutes)
      final update = widget.app.appUpdate;
      final url = widget.app.appUpdateUrl;
      if (update == null || url == null) throw UpdateException("Aucune mise à jour à télécharger : l'application est déjà à jour.");
      final dir = await getTemporaryDirectory();
      final dest = File(p.join(dir.path, 'updates', 'eftp-collecte-${update['version']}.apk'));
      if (!mounted) return;
      setState(() {
        _step = _Step.downloading;
        _total = (update['size_bytes'] as num?)?.toInt();
      });
      _apk = await _updater.download(url, dest, sha256Hex: update['sha256'] as String?, onProgress: (r, t) {
        if (mounted) {
          setState(() {
            _received = r;
            _total = t ?? _total;
          });
        }
      });
      if (mounted) setState(() => _step = _Step.ready);
    } catch (e) {
      if (mounted) {
        setState(() {
          _step = _Step.failed;
          _error = e is UpdateException ? e.message : 'Téléchargement impossible : $e';
        });
      }
    }
  }

  Future<void> _install() async {
    setState(() {
      _step = _Step.installing;
      _error = null;
    });
    try {
      await AppUpdater.install(_apk!);
      // Android affiche l'écran d'installation ; l'application redémarre sur la nouvelle version.
      if (mounted) setState(() => _step = _Step.ready);
    } catch (e) {
      if (mounted) {
        setState(() {
          _step = _Step.ready;
          _error = e is UpdateException ? e.message : '$e';
        });
      }
    }
  }

  static String _mb(int b) => (b / 1048576).toStringAsFixed(1);

  @override
  Widget build(BuildContext context) {
    final pct = _total != null && _total! > 0 ? (_received / _total!).clamp(0.0, 1.0) : null;
    final Widget body = switch (_step) {
      _Step.preparing => const Row(children: [
          SizedBox(width: 24, height: 24, child: CircularProgressIndicator(strokeWidth: 3)),
          SizedBox(width: 12),
          Expanded(child: Text('Préparation du téléchargement…')),
        ]),
      _Step.downloading => Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          LinearProgressIndicator(value: pct, minHeight: 10, borderRadius: BorderRadius.circular(5), color: brandOrange),
          const SizedBox(height: 10),
          Text(pct == null ? '${_mb(_received)} Mo reçus' : '${(pct * 100).toStringAsFixed(0)} %  ·  ${_mb(_received)} / ${_mb(_total!)} Mo',
              textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w600)),
          const SizedBox(height: 6),
          const Text("Restez dans l'application pendant le téléchargement.", textAlign: TextAlign.center),
        ]),
      _Step.ready || _Step.installing => Column(mainAxisSize: MainAxisSize.min, children: [
          const Icon(Icons.check_circle, color: brandGreenDark, size: 48),
          const SizedBox(height: 8),
          Text('Version $_version téléchargée.', style: const TextStyle(fontWeight: FontWeight.w600)),
          const SizedBox(height: 4),
          const Text("Touchez « Mettre à jour maintenant » puis « Installer » sur l'écran d'Android. Vos collectes sont conservées.",
              textAlign: TextAlign.center),
          if (_error != null) ...[const SizedBox(height: 8), Text(_error!, style: TextStyle(color: Colors.red.shade800))],
        ]),
      _Step.failed => Text(_error ?? 'Téléchargement impossible.', style: TextStyle(color: Colors.red.shade800)),
    };
    return PopScope(
      canPop: false,
      child: AlertDialog(
        icon: const Icon(Icons.system_update, size: 36),
        title: Text('Mise à jour $_version'),
        content: body,
        actions: switch (_step) {
          _Step.preparing || _Step.downloading => [
              TextButton(
                  onPressed: () {
                    _updater.cancel();
                    Navigator.pop(context);
                  },
                  child: const Text('Annuler')),
            ],
          _Step.ready || _Step.installing => [
              TextButton(onPressed: () => Navigator.pop(context), child: const Text('Plus tard')),
              FilledButton.icon(
                style: FilledButton.styleFrom(backgroundColor: brandGreenDark),
                onPressed: _step == _Step.installing ? null : _install,
                icon: const Icon(Icons.install_mobile),
                label: const Text('Mettre à jour maintenant'),
              ),
            ],
          _Step.failed => [
              TextButton(onPressed: () => Navigator.pop(context), child: const Text('Fermer')),
              FilledButton(onPressed: _start, child: const Text('Réessayer')),
            ],
        },
      ),
    );
  }
}
