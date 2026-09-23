import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'package:mobile/core/config/app_config.dart';
import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';

import '../../support/fake_backend.dart';

/// [ApiClient]'s request/response behavior for everything that does not
/// end a session (Phase 27 Gate 2). Session-ending behavior (401/403) is
/// covered in test/features/auth/session_lifecycle_test.dart.
void main() {
  late FakeBackend backend;
  late RecordingTokenStorage storage;
  late AuthController session;

  setUp(() async {
    backend = FakeBackend();
    storage = RecordingTokenStorage(initialToken: 'token-a');
    session = backend.controller(storage);
    await session.bootstrap();
    expect(session.status, AuthStatus.authenticated);
  });

  test(
    'attaches the current bearer token and uses the configured base URL',
    () async {
      http.Request? seen;
      backend.onApi = (request) async {
        seen = request;

        return http.Response(jsonEncode({'data': {}}), 200);
      };

      await backend.apiClient(session).getJson('/me/home');

      expect(seen!.method, 'GET');
      expect(seen!.url.toString(), '$testBaseUrl/me/home');
      expect(seen!.headers['Authorization'], 'Bearer token-a');
      expect(seen!.headers['Accept'], 'application/json');
    },
  );

  test('defaults to AppConfig.apiBaseUrl when no base URL is given', () async {
    Uri? seen;
    final client = ApiClient(
      session: session,
      httpClient: MockClient((request) async {
        seen = request.url;

        return http.Response(jsonEncode({'data': {}}), 200);
      }),
    );

    await client.getJson('/me/home');

    expect(seen.toString(), '${AppConfig.apiBaseUrl}/me/home');
  });

  test('returns the decoded JSON body of a successful response', () async {
    backend.onApi = (request) async => http.Response(
      jsonEncode({
        'data': {
          'notifications': {'unread_count': 3},
        },
      }),
      200,
    );

    final body = await backend.apiClient(session).getJson('/me/home');

    expect(body['data']['notifications']['unread_count'], 3);
  });

  test(
    'a successful status with a non-JSON-object body is a request failure',
    () async {
      backend.onApi = (request) async =>
          http.Response('<html>oops</html>', 200);

      await expectLater(
        backend.apiClient(session).getJson('/me/home'),
        throwsA(isA<ApiRequestException>()),
      );
      expect(session.status, AuthStatus.authenticated);
    },
  );

  test('a 4xx other than 401/403 propagates the server message and keeps the session', () async {
    backend.onApi = (request) async => jsonError(404, 'Not found.');

    await expectLater(
      backend.apiClient(session).getJson('/me/nothing'),
      throwsA(
        isA<ApiRequestException>()
            .having((e) => e.statusCode, 'statusCode', 404)
            .having((e) => e.message, 'message', 'Not found.'),
      ),
    );
    expect(session.status, AuthStatus.authenticated);
    expect(session.currentToken, 'token-a');
    expect(storage.deleteCalls, 0);
    expect(backend.meCalls, 1); // bootstrap only
  });

  test('a 5xx is a server failure and keeps the session', () async {
    backend.onApi = (request) async => jsonError(503, 'Down for maintenance');

    await expectLater(
      backend.apiClient(session).getJson('/me/home'),
      throwsA(
        isA<ApiServerException>().having(
          (e) => e.statusCode,
          'statusCode',
          503,
        ),
      ),
    );
    expect(session.status, AuthStatus.authenticated);
    expect(storage.deleteCalls, 0);
  });

  test(
    'a transport failure is a network failure and keeps the session',
    () async {
      backend.onApi = (request) async => throw const NetworkFailure();

      await expectLater(
        backend.apiClient(session).getJson('/me/home'),
        throwsA(isA<ApiNetworkException>()),
      );
      expect(session.status, AuthStatus.authenticated);
      expect(session.sessionEndedMessage, isNull);
      expect(storage.deleteCalls, 0);
    },
  );

  test('no request is sent once there is no current session token', () async {
    await session.logout();
    var apiCalls = 0;
    backend.onApi = (request) async {
      apiCalls++;

      return http.Response('{}', 200);
    };

    await expectLater(
      backend.apiClient(session).getJson('/me/home'),
      throwsA(isA<ApiSessionExpiredException>()),
    );
    expect(apiCalls, 0);
    // Already signed out manually — no session-ended notice appears.
    expect(session.sessionEndedMessage, isNull);
  });

  test('no failure message ever contains the token', () async {
    for (final status in [401, 403, 404, 500]) {
      final fresh = backend.controller(
        RecordingTokenStorage(initialToken: 'token-a'),
      );
      await fresh.bootstrap();
      backend.meStatus = 200;
      backend.onApi = (request) async => jsonError(status, 'Nope.');

      try {
        await backend.apiClient(fresh).getJson('/me/home');
        fail('expected a failure for $status');
      } on ApiException catch (e) {
        expect(e.toString(), isNot(contains('token-a')));
        expect(e.message, isNot(contains('token-a')));
      }
    }
  });
}
