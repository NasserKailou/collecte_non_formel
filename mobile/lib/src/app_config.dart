// Configuration de l'application, fixée à la compilation :
//   flutter build apk --dart-define=API_BASE_URL=https://collecte.exemple.ne/non_formel/backend/public/api/v1
// En production, l'adresse doit être en HTTPS (le trafic en clair est refusé par la configuration réseau Android,
// sauf pour les adresses de développement déclarées dans network_security_config.xml).

abstract final class AppConfig {
  /// Adresse de l'API (sans « / » final). Valeur par défaut : serveur XAMPP vu depuis l'émulateur Android.
  static const apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://eftpnonformel.ins.ne/api/v1',
  );

  /// Version envoyée dans X-App-Version (contrôlée par le serveur : 426 si trop ancienne). Garder alignée sur pubspec.yaml.
  static const appVersion = '1.0.0';

  static const requestTimeout = Duration(seconds: 30);

  /// Temporisation exponentielle des envois : 30 s, 1 min, 2 min… plafonnée à 30 min (docs/mobile/README.md §2).
  static Duration backoff(int attempts) {
    final seconds = 30 * (1 << (attempts.clamp(0, 6)));
    return Duration(seconds: seconds > 1800 ? 1800 : seconds);
  }
}
