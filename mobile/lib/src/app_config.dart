// Configuration de l'application, fixée à la compilation.
//
// Production (serveur https://eftpnonformel.ins.ne, racine web = backend/public) : l'adresse par défaut ci-dessous
// est la bonne, aucun paramètre n'est nécessaire :
//   flutter build apk --release
// (équivalent explicite : --dart-define=API_BASE_URL=https://eftpnonformel.ins.ne/api/v1)
//
// Développement (XAMPP vu depuis l'émulateur Android, build de débogage uniquement) :
//   flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8085/non_formel/backend/public/api/v1
//
// En version release, seul le HTTPS est accepté (android/app/src/main/res/xml/network_security_config.xml) ;
// le HTTP vers 10.0.2.2 / localhost n'est autorisé que dans le build de débogage (src/debug/res/xml/).

abstract final class AppConfig {
  /// Adresse publique du serveur de production.
  static const productionHost = 'https://eftpnonformel.ins.ne';

  /// Adresse de l'API (sans « / » final). Par défaut : API de production.
  static const apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',

    defaultValue: '$productionHost/api/v1',
  );

  /// Version envoyée dans X-App-Version (contrôlée par le serveur : 426 si trop ancienne). Garder alignée sur pubspec.yaml.
  /// Remplacée au démarrage par la version réelle de l'APK installé (AppState.init) : après une mise à jour,
  /// l'application annonce la bonne version et le bandeau « nouvelle version » disparaît.
  static String appVersion = '1.0.1';

  static const requestTimeout = Duration(seconds: 30);

  /// Temporisation exponentielle des envois : 30 s, 1 min, 2 min… plafonnée à 30 min (docs/mobile/README.md §2).
  static Duration backoff(int attempts) {
    final seconds = 30 * (1 << (attempts.clamp(0, 6)));
    return Duration(seconds: seconds > 1800 ? 1800 : seconds);
  }
}
