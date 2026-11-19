// Profil superviseur sur tablette : le back-office web de supervision (tableau de bord, collectes de la région,
// contrôle, commentaire et renvoi à l'agent, « Ouvrir et corriger », carte des centres, statistiques) s'ouvre DANS
// l'application, sans ressaisir le mot de passe : ticket à usage unique (POST /auth/web-ticket) échangé par le
// serveur contre une session web (GET /auth/web-session → cookie HttpOnly, redirection vers /app/).
// Mêmes écrans et mêmes droits que sur ordinateur ; connexion Internet nécessaire (les collectes sont sur le serveur).

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:webview_flutter/webview_flutter.dart';

import '../app_state.dart';
import 'common.dart';
import 'server_check_dialog.dart';

class SupervisionScreen extends StatefulWidget {
  const SupervisionScreen({super.key, required this.app});
  final AppState app;

  @override
  State<SupervisionScreen> createState() => _SupervisionScreenState();
}

/// Raccourcis de la barre du bas : routes du back-office (backend/public/app/js/main.js).
const _sections = [
  (hash: '#/', label: 'Tableau de bord', icon: Icons.dashboard_outlined),
  (hash: '#/collectes', label: 'Collectes', icon: Icons.fact_check_outlined),
  (hash: '#/carte', label: 'Carte', icon: Icons.map_outlined),
  (hash: '#/statistiques', label: 'Statistiques', icon: Icons.bar_chart),
];

class _SupervisionScreenState extends State<SupervisionScreen> {
  late final WebViewController _web;
  late final Uri _appUrl; // …/app/ (même serveur que l'API)
  bool _opening = true;
  int _progress = 0;
  String? _error;
  int _section = 0;

  @override
  void initState() {
    super.initState();
    final api = Uri.parse(widget.app.auth.api.baseUrl);
    _appUrl = api.replace(path: api.path.replaceFirst(RegExp(r'/api/v1/?$'), '/app/'));
    _web = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setBackgroundColor(Colors.white)
      ..setNavigationDelegate(NavigationDelegate(
        onProgress: (p) => mounted ? setState(() => _progress = p) : null,
        onPageFinished: (_) => mounted ? setState(() => _progress = 100) : null,
        onUrlChange: (c) => _syncSection(c.url),
        onWebResourceError: (e) {
          if ((e.isForMainFrame ?? true) && mounted) {
            setState(() => _error = 'Le serveur ne répond pas (${e.description}). Vérifiez la connexion Internet puis réessayez.');
          }
        },
        // Liens vers un autre site : ouverts dans le navigateur de la tablette.
        onNavigationRequest: (r) {
          final u = Uri.tryParse(r.url);
          if (u == null || u.host == _appUrl.host || u.scheme == 'about' || u.scheme == 'data' || u.scheme == 'blob') {
            return NavigationDecision.navigate;
          }
          launchUrl(u, mode: LaunchMode.externalApplication);
          return NavigationDecision.prevent;
        },
      ));
    _open();
  }

  /// Ouvre (ou rouvre) la session de supervision : nouveau ticket, puis chargement du back-office.
  Future<void> _open() async {
    setState(() {
      _opening = true;
      _error = null;
    });
    try {
      final url = await widget.app.auth.webSessionUrl();
      await _web.loadRequest(url);
    } catch (e) {
      _error = "Impossible d'ouvrir la supervision : $e";
    } finally {
      if (mounted) setState(() => _opening = false);
    }
  }

  void _syncSection(String? url) {
    if (url == null || !mounted) return;
    final frag = Uri.tryParse(url)?.fragment ?? '';
    final hash = '#${frag.isEmpty ? '/' : frag}';
    final i = _sections.lastIndexWhere((s) => s.hash == '#/' ? hash == '#/' : hash.startsWith(s.hash));
    if (i >= 0 && i != _section) setState(() => _section = i);
  }

  Future<void> _goto(int i) async {
    setState(() => _section = i);
    if (_error != null) return _open();
    // Navigation interne du back-office (sans rechargement) ; repli sur un chargement complet.
    try {
      await _web.runJavaScript("location.hash = '${_sections[i].hash}';");
    } catch (_) {
      await _web.loadRequest(_appUrl.replace(fragment: _sections[i].hash.substring(1)));
    }
  }

  Future<void> _menu(String v) async {
    final app = widget.app;
    if (v == 'reload') {
      await _open();
    } else if (v == 'check') {
      await showServerCheck(context, app);
    } else if (v == 'lock') {
      await WebViewCookieManager().clearCookies();
      app.lock();
    } else if (v == 'logout') {
      final ok = await confirm(context, 'Se déconnecter', 'Une connexion en ligne sera nécessaire pour reprendre.', ok: 'Se déconnecter');
      if (!ok) return;
      await WebViewCookieManager().clearCookies();
      await app.logout();
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = widget.app.session!;
    return PopScope(
      canPop: false,
      // Bouton « retour » d'Android : page précédente du back-office, pas de sortie de l'application.
      onPopInvokedWithResult: (didPop, _) async {
        if (!didPop && await _web.canGoBack()) await _web.goBack();
      },
      child: Scaffold(
        appBar: AppBar(
          title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            const Text('Supervision'),
            Text('${s.fullName} · ${s.roles.join(', ')}', style: const TextStyle(fontSize: 12, color: Colors.white70)),
          ]),
          actions: [
            IconButton(tooltip: 'Actualiser', icon: const Icon(Icons.refresh), onPressed: () => _error != null ? _open() : _web.reload()),
            PopupMenuButton<String>(
              onSelected: _menu,
              itemBuilder: (_) => const [
                PopupMenuItem(value: 'reload', child: Text('Rouvrir la session de supervision')),
                PopupMenuItem(value: 'check', child: Text('Tester la connexion au serveur')),
                PopupMenuItem(value: 'lock', child: Text('Verrouiller')),
                PopupMenuItem(value: 'logout', child: Text('Se déconnecter')),
              ],
            ),
          ],
          bottom: _progress < 100 || _opening
              ? PreferredSize(
                  preferredSize: const Size.fromHeight(3),
                  child: LinearProgressIndicator(value: _opening ? null : _progress / 100, minHeight: 3, color: brandOrange))
              : null,
        ),
        body: _error != null ? _ErrorPanel(message: _error!, onRetry: _open) : WebViewWidget(controller: _web),
        bottomNavigationBar: NavigationBar(
          selectedIndex: _section,
          onDestinationSelected: _goto,
          destinations: [for (final x in _sections) NavigationDestination(icon: Icon(x.icon), label: x.label)],
        ),
      ),
    );
  }
}

class _ErrorPanel extends StatelessWidget {
  const _ErrorPanel({required this.message, required this.onRetry});
  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Icon(Icons.cloud_off, size: 56, color: Colors.grey.shade600),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center),
            const SizedBox(height: 8),
            Text('La supervision se fait en ligne : les collectes des agents sont sur le serveur.',
                textAlign: TextAlign.center, style: TextStyle(color: Colors.grey.shade700, fontSize: 13)),
            const SizedBox(height: 16),
            FilledButton.icon(onPressed: onRetry, icon: const Icon(Icons.refresh), label: const Text('Réessayer')),
          ]),
        ),
      );
}
