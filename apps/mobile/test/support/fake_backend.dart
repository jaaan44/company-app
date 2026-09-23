import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/features/auth/data/auth_api_client.dart';
import 'package:mobile/features/auth/data/token_storage.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';

const fakeUserJson = {
  'public_id': '01ARZ3NDEKTSV4RRFFQ69G5FAV',
  'name': 'Ada Lovelace',
  'email': 'ada@example.com',
  'status': 'active',
};

const testBaseUrl = 'https://api.test/api/v1';

/// Stands in for a network failure without depending on `dart:io`.
class NetworkFailure implements Exception {
  const NetworkFailure();
}

/// A scriptable in-memory `/api/v1`: the auth endpoints behave like the real
/// API (and are counted), and every other path is answered by [onApi].
/// One [http.Client] serves both `AuthApiClient` and `ApiClient`, exactly as
/// the app's two clients share one backend.
class FakeBackend {
  /// Status `/auth/me` answers with (200 → the user; otherwise an error).
  int meStatus = 200;

  /// When true, `/auth/me` fails at the transport level.
  bool meNetworkFailure = false;

  /// Held open until completed, when set — for `/auth/logout` races.
  Completer<void>? logoutGate;

  /// Token the next `/auth/login` issues.
  String nextLoginToken = 'token-b';

  int meCalls = 0;
  int logoutCalls = 0;
  final List<String?> meTokens = [];

  /// Answers every non-auth request.
  Future<http.Response> Function(http.Request request) onApi = (
    request,
  ) async => http.Response(jsonEncode({'data': <String, dynamic>{}}), 200);

  late final http.Client client = MockClient(_handle);

  Future<http.Response> _handle(http.Request request) async {
    final path = request.url.path;

    if (path.endsWith('/auth/login')) {
      return _json({
        'data': {'user': fakeUserJson, 'token': nextLoginToken},
      }, 200);
    }

    if (path.endsWith('/auth/me')) {
      meCalls++;
      meTokens.add(request.headers['Authorization']);
      if (meNetworkFailure) {
        throw const NetworkFailure();
      }

      return meStatus == 200
          ? _json({'data': fakeUserJson}, 200)
          : _json({'message': 'status $meStatus'}, meStatus);
    }

    if (path.endsWith('/auth/logout')) {
      logoutCalls++;
      await logoutGate?.future;

      return _json({
        'data': {'message': 'Logged out successfully.'},
      }, 200);
    }

    return onApi(request);
  }

  AuthController controller(TokenStorage storage) => AuthController(
    apiClient: AuthApiClient(httpClient: client, baseUrl: testBaseUrl),
    tokenStorage: storage,
  );

  ApiClient apiClient(AuthController session) =>
      ApiClient(session: session, httpClient: client, baseUrl: testBaseUrl);
}

http.Response _json(Object body, int status) =>
    http.Response(jsonEncode(body), status);

/// JSON error body helper for [FakeBackend.onApi].
http.Response jsonError(int status, [String message = 'Denied.']) =>
    _json({'message': message}, status);

/// A [TokenStorage] that counts deletions and can hold or fail them.
class RecordingTokenStorage implements TokenStorage {
  RecordingTokenStorage({String? initialToken}) : _token = initialToken;

  String? _token;
  int deleteCalls = 0;
  bool failDeletes = false;
  Completer<void>? deleteGate;

  @override
  Future<String?> readToken() async => _token;

  @override
  Future<void> saveToken(String token) async {
    _token = token;
  }

  @override
  Future<void> deleteToken() async {
    deleteCalls++;
    await deleteGate?.future;
    if (failDeletes) {
      throw StateError('keystore unavailable');
    }
    _token = null;
  }
}
