import 'package:mobile/features/auth/data/token_storage.dart';

/// In-memory [TokenStorage] fake — tests never touch the real platform
/// keychain/keystore.
class FakeTokenStorage implements TokenStorage {
  FakeTokenStorage({String? initialToken}) : _token = initialToken;

  String? _token;

  @override
  Future<String?> readToken() async => _token;

  @override
  Future<void> saveToken(String token) async {
    _token = token;
  }

  @override
  Future<void> deleteToken() async {
    _token = null;
  }
}
