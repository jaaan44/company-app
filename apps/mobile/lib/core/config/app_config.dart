/// Build-time configuration for the Company App staff mobile application.
///
/// Values are supplied via `--dart-define` at build/run time — never
/// hard-coded here — so the same codebase can point at a local, staging,
/// or production API without a source change. Example:
///
///   flutter run --dart-define=API_BASE_URL=https://staging.example.com/api/v1
///
/// With no `--dart-define` supplied, [apiBaseUrl] defaults to the local
/// backend started via `php artisan serve` (see apps/api's README).
class AppConfig {
  const AppConfig._();

  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://localhost:8000/api/v1',
  );
}
