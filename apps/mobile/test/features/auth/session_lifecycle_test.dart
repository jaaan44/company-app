import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/core/network/api_client.dart';
import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';

import '../../support/fake_backend.dart';

/// Phase 27 Gate 2 — the authenticated session lifecycle through the real
/// [AuthController] + [ApiClient] pair: 401 expiry, the 403 → `/auth/me`
/// re-check, and the concurrency/stale-token guarantees of the shared
/// session-ending routine (docs/phases/V1_PHASE_27_DEFINITION.md §7.1–7.2).
void main() {
  late FakeBackend backend;
  late RecordingTokenStorage storage;
  late AuthController auth;
  late ApiClient api;
  late List<AuthStatus> transitions;

  setUp(() async {
    backend = FakeBackend();
    storage = RecordingTokenStorage(initialToken: 'token-a');
    auth = backend.controller(storage);
    api = backend.apiClient(auth);
    await auth.bootstrap();
    expect(auth.status, AuthStatus.authenticated);
    backend.meCalls = 0;
    backend.meTokens.clear();
    transitions = [];
    auth.addListener(() => transitions.add(auth.status));
  });

  void expectSessionExpired() {
    expect(auth.status, AuthStatus.unauthenticated);
    expect(auth.user, isNull);
    expect(auth.currentToken, isNull);
    expect(auth.sessionEndedMessage, AuthController.sessionEndedNotice);
  }

  void expectSessionKept() {
    expect(auth.status, AuthStatus.authenticated);
    expect(auth.currentToken, 'token-a');
    expect(auth.user?.name, 'Ada Lovelace');
    expect(auth.sessionEndedMessage, isNull);
    expect(storage.deleteCalls, 0);
    expect(transitions, isEmpty);
  }

  group('401', () {
    test(
      'ends the session locally, clears the token, and sets the notice',
      () async {
        backend.onApi = (request) async => jsonError(401, 'Unauthenticated.');

        await expectLater(
          api.getJson('/me/home'),
          throwsA(isA<ApiSessionExpiredException>()),
        );

        expectSessionExpired();
        expect(await storage.readToken(), isNull);
        expect(storage.deleteCalls, 1);
        expect(transitions, [AuthStatus.unauthenticated]);
      },
    );

    test('never calls /auth/logout and never re-checks /auth/me', () async {
      backend.onApi = (request) async => jsonError(401);

      await expectLater(
        api.getJson('/me/home'),
        throwsA(isA<ApiSessionExpiredException>()),
      );

      expect(backend.logoutCalls, 0);
      expect(backend.meCalls, 0);
    });

    test('two simultaneous 401s end the session exactly once', () async {
      final first = Completer<http.Response>();
      final second = Completer<http.Response>();
      var n = 0;
      backend.onApi = (request) => (n++ == 0 ? first : second).future;

      final a = expectLater(
        api.getJson('/me/home'),
        throwsA(isA<ApiSessionExpiredException>()),
      );
      final b = expectLater(
        api.getJson('/me/home'),
        throwsA(isA<ApiSessionExpiredException>()),
      );
      await pumpEventQueue();
      first.complete(jsonError(401));
      second.complete(jsonError(401));

      await a;
      await b;

      expectSessionExpired();
      expect(storage.deleteCalls, 1);
      expect(transitions, [AuthStatus.unauthenticated]);
      expect(backend.logoutCalls, 0);
    });

    test('a second 401 arriving while the first is still clearing storage joins it', () async {
      storage.deleteGate = Completer<void>();
      backend.onApi = (request) async => jsonError(401);

      final a = expectLater(
        api.getJson('/me/home'),
        throwsA(isA<ApiSessionExpiredException>()),
      );
      await pumpEventQueue();
      expect(storage.deleteCalls, 1); // first expiry is mid-deletion
      final b = expectLater(
        api.getJson('/me/home'),
        throwsA(isA<ApiSessionExpiredException>()),
      );
      await pumpEventQueue();

      storage.deleteGate!.complete();
      await a;
      await b;

      expect(storage.deleteCalls, 1);
      expect(transitions, [AuthStatus.unauthenticated]);
    });
  });

  group('403', () {
    test(
      '/auth/me 200 keeps the session and reports an ordinary refusal',
      () async {
        backend.onApi = (request) async => jsonError(403, 'Not allowed here.');

        await expectLater(
          api.getJson('/me/home'),
          throwsA(
            isA<ApiForbiddenException>().having(
              (e) => e.message,
              'message',
              'Not allowed here.',
            ),
          ),
        );

        expectSessionKept();
        expect(backend.meCalls, 1);
        expect(backend.meTokens, ['Bearer token-a']);
      },
    );

    test(
      '/auth/me 401 ends the session with the notice, without /auth/logout',
      () async {
        backend.onApi = (request) async =>
            jsonError(403, 'This account is not currently active.');
        backend.meStatus = 401;

        await expectLater(
          api.getJson('/me/home'),
          throwsA(isA<ApiSessionExpiredException>()),
        );

        expectSessionExpired();
        expect(backend.meCalls, 1);
        expect(backend.logoutCalls, 0);
        expect(storage.deleteCalls, 1);
      },
    );

    for (final (label, configure) in <(String, void Function(FakeBackend))>[
      ('/auth/me 403', (b) => b.meStatus = 403),
      ('/auth/me 500', (b) => b.meStatus = 500),
      ('/auth/me 503', (b) => b.meStatus = 503),
      ('/auth/me network failure', (b) => b.meNetworkFailure = true),
    ]) {
      test(
        '$label is inconclusive: session kept, original 403 reported, one re-check only',
        () async {
          backend.onApi = (request) async =>
              jsonError(403, 'Not allowed here.');
          configure(backend);

          await expectLater(
            api.getJson('/me/home'),
            throwsA(
              isA<ApiForbiddenException>().having(
                (e) => e.message,
                'message',
                'Not allowed here.',
              ),
            ),
          );

          expectSessionKept();
          expect(
            backend.meCalls,
            1,
            reason: 'exactly one /auth/me re-check — never recursive',
          );
          expect(backend.logoutCalls, 0);
        },
      );
    }

    test('every 403 gets exactly one re-check, even when repeated', () async {
      backend.onApi = (request) async => jsonError(403);
      backend.meStatus = 403;

      for (var i = 0; i < 3; i++) {
        await expectLater(
          api.getJson('/me/home'),
          throwsA(isA<ApiForbiddenException>()),
        );
      }

      expect(backend.meCalls, 3);
      expectSessionKept();
    });
  });

  group('manual logout', () {
    test(
      'revokes server-side once, clears the token, and shows no notice',
      () async {
        await auth.logout();

        expect(auth.status, AuthStatus.unauthenticated);
        expect(auth.sessionEndedMessage, isNull);
        expect(backend.logoutCalls, 1);
        expect(storage.deleteCalls, 1);
        expect(await storage.readToken(), isNull);
      },
    );

    test('a 401 arriving while manual logout is in flight joins it: one logout request, no notice', () async {
      backend.logoutGate = Completer<void>();
      final apiResponse = Completer<http.Response>();
      backend.onApi = (request) => apiResponse.future;

      final request = expectLater(
        api.getJson('/me/home'),
        throwsA(isA<ApiSessionExpiredException>()),
      );
      await pumpEventQueue();
      final logout = auth.logout();
      await pumpEventQueue();
      expect(backend.logoutCalls, 1);

      apiResponse.complete(jsonError(401));
      await pumpEventQueue();
      backend.logoutGate!.complete();

      await logout;
      await request;

      expect(auth.status, AuthStatus.unauthenticated);
      expect(auth.sessionEndedMessage, isNull);
      expect(backend.logoutCalls, 1);
      expect(storage.deleteCalls, 1);
      expect(transitions, [AuthStatus.unauthenticated]);
    });

    test('manual logout while an expiry is in flight joins it: no server logout request', () async {
      storage.deleteGate = Completer<void>();
      backend.onApi = (request) async => jsonError(401);

      final request = expectLater(
        api.getJson('/me/home'),
        throwsA(isA<ApiSessionExpiredException>()),
      );
      await pumpEventQueue();
      final logout = auth.logout();
      await pumpEventQueue();

      storage.deleteGate!.complete();
      await logout;
      await request;

      expect(backend.logoutCalls, 0);
      expect(storage.deleteCalls, 1);
      expect(transitions, [AuthStatus.unauthenticated]);
      expect(auth.sessionEndedMessage, AuthController.sessionEndedNotice);
    });

    test('logging out again after already signing out is harmless', () async {
      await auth.logout();
      transitions.clear();

      await auth.logout();

      expect(auth.status, AuthStatus.unauthenticated);
      expect(auth.sessionEndedMessage, isNull);
    });
  });

  group('stale responses never end a newer session', () {
    Future<void> reLoginAsTokenB() async {
      await auth.logout();
      backend.nextLoginToken = 'token-b';
      expect(await auth.login('ada@example.com', 'password'), isTrue);
      expect(auth.currentToken, 'token-b');
      transitions.clear();
    }

    test('a token-A 401 arriving after token B is current leaves token B signed in', () async {
      final late = Completer<http.Response>();
      backend.onApi = (request) => late.future;

      final stale = api.getJson('/me/home'); // sent with token A
      await pumpEventQueue();
      await reLoginAsTokenB();
      final deletesBefore = storage.deleteCalls;

      late.complete(jsonError(401));
      await expectLater(stale, throwsA(isA<ApiSessionExpiredException>()));

      expect(auth.status, AuthStatus.authenticated);
      expect(auth.currentToken, 'token-b');
      expect(await storage.readToken(), 'token-b');
      expect(auth.sessionEndedMessage, isNull);
      expect(storage.deleteCalls, deletesBefore);
      expect(transitions, isEmpty);
    });

    test(
      'a token-A 403 whose re-check returns 401 leaves token B signed in',
      () async {
        final late = Completer<http.Response>();
        backend.onApi = (request) => late.future;

        final stale = api.getJson('/me/home'); // sent with token A
        await pumpEventQueue();
        await reLoginAsTokenB();
        backend.meStatus = 401; // token A is indeed dead
        backend.meTokens.clear();

        late.complete(jsonError(403));
        await expectLater(stale, throwsA(isA<ApiSessionExpiredException>()));

        expect(backend.meTokens, [
          'Bearer token-a',
        ], reason: 're-checks the token the request used');
        expect(auth.status, AuthStatus.authenticated);
        expect(auth.currentToken, 'token-b');
        expect(await storage.readToken(), 'token-b');
        expect(transitions, isEmpty);
      },
    );

    test('expiring with a token that is not current is a no-op', () async {
      await auth.expireSession(tokenUsed: 'some-other-token');

      expectSessionKept();
    });

    test(
      'expiring again after the session already ended does nothing',
      () async {
        backend.onApi = (request) async => jsonError(401);
        await expectLater(
          api.getJson('/me/home'),
          throwsA(isA<ApiSessionExpiredException>()),
        );
        transitions.clear();

        await auth.expireSession(tokenUsed: 'token-a');
        await auth.expireSession(tokenUsed: null);

        expect(storage.deleteCalls, 1);
        expect(transitions, isEmpty);
      },
    );

    test('a new login clears the previous session-ended notice', () async {
      backend.onApi = (request) async => jsonError(401);
      await expectLater(
        api.getJson('/me/home'),
        throwsA(isA<ApiSessionExpiredException>()),
      );
      expect(auth.sessionEndedMessage, AuthController.sessionEndedNotice);

      await auth.login('ada@example.com', 'password');

      expect(auth.sessionEndedMessage, isNull);
      expect(auth.status, AuthStatus.authenticated);
    });
  });

  group('token storage failure', () {
    test('a failed token deletion still ends the session in memory', () async {
      storage.failDeletes = true;
      backend.onApi = (request) async => jsonError(401);

      await expectLater(
        api.getJson('/me/home'),
        throwsA(isA<ApiSessionExpiredException>()),
      );

      expectSessionExpired();
      expect(transitions, [AuthStatus.unauthenticated]);

      // Nothing can use the old token afterwards.
      var apiCalls = 0;
      backend.onApi = (request) async {
        apiCalls++;

        return jsonError(500);
      };
      await expectLater(
        api.getJson('/me/home'),
        throwsA(isA<ApiSessionExpiredException>()),
      );
      expect(apiCalls, 0);
    });

    test('a failed deletion during manual logout still signs out', () async {
      storage.failDeletes = true;

      await auth.logout();

      expect(auth.status, AuthStatus.unauthenticated);
      expect(auth.currentToken, isNull);
      expect(auth.sessionEndedMessage, isNull);
    });
  });
}
