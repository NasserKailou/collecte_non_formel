// Application de collecte EFTP non formel (agents recenseurs) — hors ligne d'abord.

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'src/app_state.dart';
import 'src/ui/auth_screens.dart';
import 'src/ui/common.dart';
import 'src/ui/home_screen.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  final app = AppState();
  runApp(CollecteApp(app: app));
  app.init();
}

class CollecteApp extends StatefulWidget {
  const CollecteApp({super.key, required this.app});
  final AppState app;

  @override
  State<CollecteApp> createState() => _CollecteAppState();
}

class _CollecteAppState extends State<CollecteApp> with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// Retour au premier plan : synchronisation. Pas de verrouillage automatique (connexion mémorisée, décision du
  /// 14/11/2026) : la tablette est protégée par son verrouillage d'écran ; l'agent peut « Verrouiller » depuis le menu.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) widget.app.synchronize();
  }

  @override
  Widget build(BuildContext context) => MaterialApp(
        title: 'Collecte EFTP non formel',
        theme: appTheme(),
        locale: const Locale('fr'),
        supportedLocales: const [Locale('fr')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: ListenableBuilder(
          listenable: widget.app,
          builder: (context, _) {
            final app = widget.app;
            if (app.fatalError != null) {
              return Scaffold(body: Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(app.fatalError!, textAlign: TextAlign.center))));
            }
            if (!app.ready) return const Scaffold(body: Center(child: CircularProgressIndicator()));
            return app.session == null ? LoginScreen(app: app) : HomeScreen(app: app);
          },
        ),
      );
}
