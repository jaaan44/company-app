import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';

import '../../support/fake_backend.dart';

/// Phase 29A Gate 2 — [ApiClient]'s write verbs (docs/phases/
/// V1_PHASE_29_DEFINITION.md §5.2): JSON bodies, `204`, `422` field errors,
/// and the same 401/403 session rules as reads, with a write never sent
/// twice.
void main() {
  late FakeBackend backend;
  late RecordingTokenStorage storage;
  late AuthController auth;
  late ApiClient api;
  late List<http.Request> writes;

  setUp(() async {
    backend = FakeBackend();
    storage = RecordingTokenStorage(initialToken: 'token-a');
    auth = backend.controller(storage);
    api = backend.apiClient(auth);
    await auth.bootstrap();
    expect(auth.status, AuthStatus.authenticated);
    backend.meCalls = 0;
    writes = [];
  });

  /// Answers every non-auth request with [response], recording it.
  void respond(http.Response Function() response) {
    backend.onApi = (request) async {
      writes.add(request);

      return response();
    };
  }

  void expectSessionKept() {
    expect(auth.status, AuthStatus.authenticated);
    expect(auth.currentToken, 'token-a');
    expect(auth.sessionEndedMessage, isNull);
    expect(storage.deleteCalls, 0);
  }

  void expectSessionExpired() {
    expect(auth.status, AuthStatus.unauthenticated);
    expect(auth.currentToken, isNull);
    expect(auth.sessionEndedMessage, AuthController.sessionEndedNotice);
  }

  group('requests', () {
    test('PATCH sends a JSON body with the bearer token', () async {
      respond(
        () => http.Response(
          jsonEncode({
            'data': {'status': 'in_progress'},
          }),
          200,
        ),
      );

      final body = await api.patchJson('/tasks/T1', {'status': 'in_progress'});

      expect(body!['data']['status'], 'in_progress');
      final request = writes.single;
      expect(request.method, 'PATCH');
      expect(request.url.toString(), '$testBaseUrl/tasks/T1');
      expect(request.headers['Authorization'], 'Bearer token-a');
      expect(request.headers['Accept'], 'application/json');
      expect(request.headers['Content-Type'], startsWith('application/json'));
      expect(jsonDecode(request.body), {'status': 'in_progress'});
    });

    test('POST without a body sends an empty JSON object', () async {
      respond(() => http.Response(jsonEncode({'data': {}}), 201));

      await api.postJson('/things');

      expect(writes.single.method, 'POST');
      expect(jsonDecode(writes.single.body), <String, dynamic>{});
    });

    test('DELETE sends no body', () async {
      respond(() => http.Response('', 204));

      await api.delete('/things/1');

      expect(writes.single.method, 'DELETE');
      expect(writes.single.body, isEmpty);
      expect(writes.single.headers['Content-Type'], isNull);
    });

    test('GET still sends no body', () async {
      respond(() => http.Response(jsonEncode({'data': {}}), 200));

      await api.getJson('/me/home');

      expect(writes.single.method, 'GET');
      expect(writes.single.body, isEmpty);
    });
  });

  group('204', () {
    test('a write answered with 204 succeeds with no body', () async {
      respond(() => http.Response('', 204));

      expect(await api.patchJson('/tasks/T1', {'status': 'todo'}), isNull);
      expect(await api.postJson('/things'), isNull);
      expectSessionKept();
    });

    test('DELETE accepts 200 with any body', () async {
      respond(() => http.Response('ok', 200));

      await api.delete('/things/1');

      expectSessionKept();
    });

    test('a read answered with 204 is a request failure', () async {
      respond(() => http.Response('', 204));

      await expectLater(
        api.getJson('/me/home'),
        throwsA(isA<ApiRequestException>()),
      );
    });

    test(
      'a successful write whose body is not a JSON object is a request failure',
      () async {
        respond(() => http.Response('<html>oops</html>', 200));

        await expectLater(
          api.patchJson('/tasks/T1', {'status': 'todo'}),
          throwsA(isA<ApiRequestException>()),
        );
        expectSessionKept();
      },
    );
  });

  group('422', () {
    test('parses the message and per-field errors', () async {
      respond(
        () => http.Response(
          jsonEncode({
            'message': 'The selected status is invalid.',
            'errors': {
              'status': ['The selected status is invalid.'],
              'title': ['The title field is required.', 'Too short.'],
            },
          }),
          422,
        ),
      );

      await expectLater(
        api.patchJson('/tasks/T1', {'status': 'nope'}),
        throwsA(
          isA<ApiValidationException>()
              .having(
                (e) => e.message,
                'message',
                'The selected status is invalid.',
              )
              .having((e) => e.statusCode, 'statusCode', 422)
              .having((e) => e.fieldErrors, 'fieldErrors', {
                'status': ['The selected status is invalid.'],
                'title': ['The title field is required.', 'Too short.'],
              })
              .having(
                (e) => e.firstErrorFor('title'),
                'firstErrorFor(title)',
                'The title field is required.',
              )
              .having(
                (e) => e.firstErrorFor('other'),
                'firstErrorFor(other)',
                isNull,
              ),
        ),
      );
      expectSessionKept();
      expect(backend.meCalls, 0);
    });

    test('is still an ApiRequestException for generic handlers', () async {
      respond(() => jsonError(422, 'Invalid.'));

      await expectLater(
        api.patchJson('/tasks/T1', {'status': 'x'}),
        throwsA(isA<ApiRequestException>()),
      );
    });

    test('tolerates a missing message and a malformed errors object', () async {
      respond(
        () => http.Response(
          jsonEncode({
            'errors': {
              'status': 'not a list',
              'title': ['ok', 3],
            },
          }),
          422,
        ),
      );

      await expectLater(
        api.postJson('/things'),
        throwsA(
          isA<ApiValidationException>()
              .having(
                (e) => e.message,
                'message',
                'Please check the details and try again.',
              )
              .having((e) => e.fieldErrors, 'fieldErrors', {
                'title': ['ok'],
              }),
        ),
      );
    });

    test('a non-JSON 422 has no field errors', () async {
      respond(() => http.Response('Unprocessable', 422));

      await expectLater(
        api.postJson('/things'),
        throwsA(
          isA<ApiValidationException>().having(
            (e) => e.fieldErrors,
            'fieldErrors',
            isEmpty,
          ),
        ),
      );
    });

    test('a GET 422 is a validation failure too', () async {
      respond(() => jsonError(422, 'The selected state is invalid.'));

      await expectLater(
        api.getJson('/me/tasks?state=all'),
        throwsA(isA<ApiValidationException>()),
      );
    });
  });

  group('session rules on writes', () {
    test(
      'a 401 mid-write ends the session, and the write is sent once',
      () async {
        respond(() => jsonError(401, 'Unauthenticated.'));

        await expectLater(
          api.patchJson('/tasks/T1', {'status': 'completed'}),
          throwsA(isA<ApiSessionExpiredException>()),
        );

        expectSessionExpired();
        expect(writes, hasLength(1));
        expect(backend.logoutCalls, 0);
        expect(backend.meCalls, 0);
      },
    );

    test('a 403 is re-checked once; a valid session keeps it and reports the refusal without resending', () async {
      respond(
        () => jsonError(
          403,
          'You may only update the status of a task assigned to you.',
        ),
      );

      await expectLater(
        api.patchJson('/tasks/T1', {'status': 'completed'}),
        throwsA(
          isA<ApiForbiddenException>().having(
            (e) => e.message,
            'message',
            'You may only update the status of a task assigned to you.',
          ),
        ),
      );

      expectSessionKept();
      expect(backend.meCalls, 1);
      expect(writes, hasLength(1));
    });

    test('a 403 whose /auth/me re-check is 401 ends the session', () async {
      respond(() => jsonError(403, 'This account is not currently active.'));
      backend.meStatus = 401;

      await expectLater(
        api.delete('/things/1'),
        throwsA(isA<ApiSessionExpiredException>()),
      );

      expectSessionExpired();
      expect(backend.meCalls, 1);
      expect(writes, hasLength(1));
    });

    test('a network failure keeps the session and is never retried', () async {
      backend.onApi = (request) async {
        writes.add(request);
        throw const NetworkFailure();
      };

      await expectLater(
        api.patchJson('/tasks/T1', {'status': 'completed'}),
        throwsA(isA<ApiNetworkException>()),
      );

      expectSessionKept();
      expect(writes, hasLength(1));
    });

    test('a 5xx keeps the session', () async {
      respond(() => jsonError(500, 'Server Error'));

      await expectLater(
        api.postJson('/things'),
        throwsA(isA<ApiServerException>()),
      );
      expectSessionKept();
    });

    test(
      'a write and a read failing with 401 together end the session once',
      () async {
        final first = Completer<http.Response>();
        final second = Completer<http.Response>();
        var n = 0;
        backend.onApi = (request) => (n++ == 0 ? first : second).future;

        final a = expectLater(
          api.patchJson('/tasks/T1', {'status': 'blocked'}),
          throwsA(isA<ApiSessionExpiredException>()),
        );
        final b = expectLater(
          api.getJson('/me/tasks'),
          throwsA(isA<ApiSessionExpiredException>()),
        );
        await pumpEventQueue();
        first.complete(jsonError(401));
        second.complete(jsonError(401));
        await a;
        await b;

        expectSessionExpired();
        expect(storage.deleteCalls, 1);
        expect(backend.logoutCalls, 0);
      },
    );

    test('no write is sent once there is no current session token', () async {
      await auth.logout();
      respond(() => http.Response('{}', 200));

      await expectLater(
        api.patchJson('/tasks/T1', {'status': 'todo'}),
        throwsA(isA<ApiSessionExpiredException>()),
      );
      expect(writes, isEmpty);
    });
  });
}
