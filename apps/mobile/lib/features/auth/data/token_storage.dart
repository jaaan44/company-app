import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Persists the Sanctum bearer token on-device. Abstracted behind an
/// interface so tests can substitute an in-memory fake instead of touching
/// the platform keychain/keystore (see docs/05_SECURITY_MODEL.md).
abstract class TokenStorage {
  Future<String?> readToken();

  Future<void> saveToken(String token);

  Future<void> deleteToken();
}

/// Backed by `flutter_secure_storage`, which uses the platform Keychain on
/// iOS and EncryptedSharedPreferences/Keystore on Android — never plain
/// SharedPreferences, source code, or an unencrypted file (CLAUDE.md's
/// Phase 4 instructions, item 15).
class SecureTokenStorage implements TokenStorage {
  SecureTokenStorage({FlutterSecureStorage? storage})
    : _storage = storage ?? const FlutterSecureStorage();

  static const _tokenKey = 'auth_token';

  final FlutterSecureStorage _storage;

  @override
  Future<String?> readToken() => _storage.read(key: _tokenKey);

  @override
  Future<void> saveToken(String token) =>
      _storage.write(key: _tokenKey, value: token);

  @override
  Future<void> deleteToken() => _storage.delete(key: _tokenKey);
}
