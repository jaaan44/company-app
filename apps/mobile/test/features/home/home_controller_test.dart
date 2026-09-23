import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/home/state/home_controller.dart';

import '../../support/fake_backend.dart';
import '../../support/home_fixtures.dart';

/// Phase 27 Gate 3 — [HomeController] over the real Gate 2 `ApiClient`
/// (spec §7–§8): loading, loaded, error, retry, refresh, and no duplicate
/// requests.
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late HomeController home;
  late int homeRequests;

  setUp(() async {
    backend = FakeBackend();
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));
    await auth.bootstrap();
    homeRequests = 0;
    backend.onApi = (request) async {
      expect(request.url.path, endsWith('/me/home'));
      homeRequests++;

      return homeResponse();
    };
    home = HomeController(homeClientFor(backend, auth));
  });

  tearDown(() => home.dispose());

  test('starts in the loading state before anything is fetched', () {
    expect(home.status, HomeStatus.loading);
    expect(home.summary, isNull);
    expect(homeRequests, 0);
  });

  test('a successful load exposes the summary', () async {
    await home.load();

    expect(home.status, HomeStatus.loaded);
    expect(home.summary!.staff!.greetingName, 'Ada');
    expect(home.errorMessage, isNull);
    expect(homeRequests, 1);
  });

  test('a no-profile response is a valid loaded state, not an error', () async {
    backend.onApi = (request) async => homeResponse(homeDataJson(staff: null));

    await home.load();

    expect(home.status, HomeStatus.loaded);
    expect(home.summary!.hasEmployeeProfile, isFalse);
    expect(auth.status, AuthStatus.authenticated);
  });

  for (final (label, response, message)
      in <(String, Future<http.Response> Function(http.Request), String)>[
        (
          'a network failure',
          (r) async => throw const NetworkFailure(),
          "Couldn't load your Home. Check your connection.",
        ),
        (
          'a server failure',
          (r) async => jsonError(500),
          'Something went wrong loading your Home.',
        ),
        (
          'an unexpected response shape',
          (r) async => http.Response('{"data": {"user": 1}}', 200),
          'Something went wrong loading your Home.',
        ),
        (
          'an ordinary 403 (session still valid)',
          (r) async => jsonError(403, 'Not allowed here.'),
          'Not allowed here.',
        ),
      ]) {
    test(
      '$label becomes the error state with a safe message and keeps the session',
      () async {
        backend.onApi = response;

        await home.load();

        expect(home.status, HomeStatus.error);
        expect(home.errorMessage, message);
        expect(home.summary, isNull);
        expect(auth.status, AuthStatus.authenticated);
      },
    );
  }

  test(
    'Try again (load) after an error issues a new request and recovers',
    () async {
      backend.onApi = (request) async {
        homeRequests++;

        return homeRequests == 1 ? jsonError(503) : homeResponse();
      };

      await home.load();
      expect(home.status, HomeStatus.error);

      final states = <HomeStatus>[];
      home.addListener(() => states.add(home.status));
      await home.load();

      expect(homeRequests, 2);
      expect(states.first, HomeStatus.loading);
      expect(home.status, HomeStatus.loaded);
    },
  );

  test(
    'a successful refresh replaces the data without returning to loading',
    () async {
      await home.load();
      backend.onApi = (request) async =>
          homeResponse(homeDataJson(unreadMessages: 9));
      final states = <HomeStatus>[];
      home.addListener(() => states.add(home.status));

      expect(await home.refresh(), isTrue);

      expect(states, isNot(contains(HomeStatus.loading)));
      expect(home.summary!.unreadMessages, 9);
      expect(home.isRefreshing, isFalse);
    },
  );

  test(
    'a failed refresh keeps the earlier data and reports the failure',
    () async {
      await home.load();
      backend.onApi = (request) async => throw const NetworkFailure();

      expect(await home.refresh(), isFalse);

      expect(home.status, HomeStatus.loaded);
      expect(home.summary!.unreadMessages, 3);
      expect(home.isRefreshing, isFalse);
    },
  );

  test('isRefreshing is true only while a refresh is in flight', () async {
    await home.load();
    final gate = Completer<http.Response>();
    backend.onApi = (request) => gate.future;

    final refresh = home.refresh();
    expect(home.isRefreshing, isTrue);
    gate.complete(homeResponse());
    await refresh;

    expect(home.isRefreshing, isFalse);
  });

  test('overlapping load/refresh calls share one request', () async {
    final gate = Completer<http.Response>();
    backend.onApi = (request) {
      homeRequests++;

      return gate.future;
    };

    final a = home.load();
    final b = home.load();
    final c = home.refresh();
    await pumpEventQueue();
    gate.complete(homeResponse());
    await Future.wait([a, b, c]);

    expect(homeRequests, 1);
    expect(home.status, HomeStatus.loaded);
  });

  test(
    'a 401 ends the session through Gate 2 and Home does not report an error',
    () async {
      backend.onApi = (request) async => jsonError(401);

      await home.load();

      expect(auth.status, AuthStatus.unauthenticated);
      expect(auth.sessionEndedMessage, AuthController.sessionEndedNotice);
      expect(home.status, isNot(HomeStatus.error));
    },
  );

  test('a response arriving after dispose is ignored safely', () async {
    final gate = Completer<http.Response>();
    backend.onApi = (request) => gate.future;
    final disposable = HomeController(homeClientFor(backend, auth));

    final load = disposable.load();
    disposable.dispose();
    gate.complete(homeResponse());

    await expectLater(load, completes);
  });
}
