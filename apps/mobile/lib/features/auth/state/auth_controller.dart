import 'package:flutter/foundation.dart';

import 'package:mobile/features/auth/data/auth_api_client.dart';
import 'package:mobile/features/auth/data/token_storage.dart';
import 'package:mobile/features/auth/domain/auth_user.dart';

enum AuthStatus {
  /// Stored-token check hasn't completed yet — show a loading state, not
  /// the login screen, to avoid a flash of the wrong UI (Phase 4 flow).
  unknown,
  authenticating,
  authenticated,
  unauthenticated,
}

/// Owns authentication state for the whole app. A plain [ChangeNotifier] —
/// no Provider/Riverpod/Bloc — is a deliberate, minimal choice for this
/// phase's single piece of shared state; see DEC-024.
class AuthController extends ChangeNotifier {
  // Not initializing formals (`this._apiClient`): the fields are private,
  // and a private named parameter can't be supplied by callers outside
  // this library, such as app.dart or the tests.
  AuthController({
    required AuthApiClient apiClient,
    required TokenStorage tokenStorage,
  }) : _apiClient = apiClient, // ignore: prefer_initializing_formals
       _tokenStorage = tokenStorage; // ignore: prefer_initializing_formals

  final AuthApiClient _apiClient;
  final TokenStorage _tokenStorage;

  AuthStatus _status = AuthStatus.unknown;
  AuthUser? _user;
  String? _errorMessage;

  AuthStatus get status => _status;
  AuthUser? get user => _user;
  String? get errorMessage => _errorMessage;

  /// Restores state on app start: no stored token → login; a stored token
  /// is validated against `/auth/me` and cleared if it no longer works.
  Future<void> bootstrap() async {
    String? token;
    try {
      token = await _tokenStorage.readToken();
    } catch (_) {
      token = null;
    }

    if (token == null) {
      _status = AuthStatus.unauthenticated;
      notifyListeners();

      return;
    }

    try {
      _user = await _apiClient.me(token);
      _status = AuthStatus.authenticated;
    } on AuthApiException {
      await _tokenStorage.deleteToken();
      _status = AuthStatus.unauthenticated;
    }

    notifyListeners();
  }

  Future<bool> login(String email, String password) async {
    _status = AuthStatus.authenticating;
    _errorMessage = null;
    notifyListeners();

    try {
      final result = await _apiClient.login(email: email, password: password);
      await _tokenStorage.saveToken(result.token);

      _user = result.user;
      _status = AuthStatus.authenticated;
      notifyListeners();

      return true;
    } on AuthApiException catch (e) {
      _errorMessage = e.message;
      _status = AuthStatus.unauthenticated;
      notifyListeners();

      return false;
    }
  }

  Future<void> logout() async {
    final token = await _tokenStorage.readToken();

    if (token != null) {
      try {
        await _apiClient.logout(token);
      } on AuthApiException {
        // The token is being discarded locally regardless — a failed
        // revoke call server-side shouldn't strand the user signed in on
        // this device.
      }
    }

    await _tokenStorage.deleteToken();
    _user = null;
    _errorMessage = null;
    _status = AuthStatus.unauthenticated;
    notifyListeners();
  }
}
