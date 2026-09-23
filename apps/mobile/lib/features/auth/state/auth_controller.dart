import 'package:flutter/foundation.dart';

import 'package:mobile/core/network/api_client.dart';
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
///
/// As of Phase 27 it is also the [ApiSession] behind every authenticated
/// [ApiClient] request, and owns the one routine that ends a session
/// (docs/phases/V1_PHASE_27_DEFINITION.md §7.2): manual [logout] and
/// automatic [expireSession] both go through it, it runs at most once at a
/// time, and concurrent callers share the same in-flight operation.
class AuthController extends ChangeNotifier implements ApiSession {
  // Not initializing formals (`this._apiClient`): the fields are private,
  // and a private named parameter can't be supplied by callers outside
  // this library, such as app.dart or the tests.
  AuthController({
    required AuthApiClient apiClient,
    required TokenStorage tokenStorage,
  }) : _apiClient = apiClient, // ignore: prefer_initializing_formals
       _tokenStorage = tokenStorage; // ignore: prefer_initializing_formals

  /// Shown on the Login screen when it was reached because an
  /// authenticated session ended on its own (never after manual logout).
  static const sessionEndedNotice =
      'Your session has ended. Please sign in again.';

  final AuthApiClient _apiClient;
  final TokenStorage _tokenStorage;

  AuthStatus _status = AuthStatus.unknown;
  AuthUser? _user;
  String? _errorMessage;
  String? _sessionEndedMessage;

  /// In-memory copy of the current session's token (also persisted in
  /// [TokenStorage]). Comparing against it is synchronous, so a stale
  /// response can be recognized without an async gap a second
  /// session-ending call could slip through.
  String? _token;

  Future<void>? _endingSession;

  AuthStatus get status => _status;
  AuthUser? get user => _user;
  String? get errorMessage => _errorMessage;

  /// [sessionEndedNotice] when the last session ended automatically;
  /// otherwise null. Cleared by the next [login] attempt.
  String? get sessionEndedMessage => _sessionEndedMessage;

  @override
  String? get currentToken => _token;

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
      _token = token;
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
    _sessionEndedMessage = null;
    notifyListeners();

    try {
      final result = await _apiClient.login(email: email, password: password);
      await _tokenStorage.saveToken(result.token);

      _user = result.user;
      _token = result.token;
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

  /// Manual logout: revokes the token server-side (best effort), then ends
  /// the local session. Never shows [sessionEndedNotice].
  Future<void> logout() =>
      _endSession(revokeServerSide: true, sessionEndedMessage: null);

  /// Automatic session expiry (a 401, or a 403 confirmed by `/auth/me`):
  /// local invalidation only — no `/auth/logout` call, since the token is
  /// already unusable.
  ///
  /// A no-op unless [tokenUsed] is the current session's token and the
  /// session is still authenticated — so a late 401 for an old token can
  /// never sign out a newer login, and repeated calls after signing out
  /// are harmless. If a session end is already in progress, callers simply
  /// await it.
  @override
  Future<void> expireSession({required String? tokenUsed}) {
    final inFlight = _endingSession;
    if (inFlight != null) {
      return inFlight;
    }

    if (_status != AuthStatus.authenticated || tokenUsed != _token) {
      return Future.value();
    }

    return _endSession(
      revokeServerSide: false,
      sessionEndedMessage: sessionEndedNotice,
    );
  }

  /// One `GET /auth/me` through [AuthApiClient] — deliberately not through
  /// [ApiClient], so this check can never trigger another check. Only a
  /// 401 counts as proof the token is gone; everything else (403, 5xx,
  /// network failure) is inconclusive and keeps the session.
  @override
  Future<SessionValidity> verifySession(String token) async {
    try {
      await _apiClient.me(token);

      return SessionValidity.valid;
    } on AuthApiException catch (e) {
      return e.statusCode == 401
          ? SessionValidity.invalid
          : SessionValidity.undetermined;
    }
  }

  /// The single session-ending routine behind [logout] and
  /// [expireSession]. While one is in flight, every further call awaits it
  /// rather than starting another (so there is only ever one server
  /// logout request and one token deletion per session).
  Future<void> _endSession({
    required bool revokeServerSide,
    required String? sessionEndedMessage,
  }) {
    final inFlight = _endingSession;
    if (inFlight != null) {
      return inFlight;
    }

    final operation = _performEndSession(
      revokeServerSide: revokeServerSide,
      sessionEndedMessage: sessionEndedMessage,
    );
    _endingSession = operation;

    return operation.whenComplete(() {
      if (identical(_endingSession, operation)) {
        _endingSession = null;
      }
    });
  }

  Future<void> _performEndSession({
    required bool revokeServerSide,
    required String? sessionEndedMessage,
  }) async {
    // Detach the token first (synchronously) so no new request can use it
    // while the rest of this runs.
    var token = _token;
    _token = null;

    if (revokeServerSide) {
      if (token == null) {
        try {
          token = await _tokenStorage.readToken();
        } catch (_) {
          token = null;
        }
      }

      if (token != null) {
        try {
          await _apiClient.logout(token);
        } on AuthApiException {
          // The token is being discarded locally regardless — a failed
          // revoke call server-side shouldn't strand the user signed in on
          // this device.
        }
      }
    }

    try {
      await _tokenStorage.deleteToken();
    } catch (_) {
      // A storage failure must never leave the app believing it is still
      // signed in. The in-memory session is cleared below regardless; a
      // token left on disk is revalidated (and cleared) by the next
      // bootstrap's `/auth/me` check.
    }

    _user = null;
    _errorMessage = null;
    _sessionEndedMessage = sessionEndedMessage;
    _status = AuthStatus.unauthenticated;
    notifyListeners();
  }
}
