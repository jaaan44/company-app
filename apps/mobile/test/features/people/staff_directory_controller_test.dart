import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/people/state/staff_directory_controller.dart';

import '../../support/fake_backend.dart';
import '../../support/people_fixtures.dart';

/// Phase 28 Gate 2 — [StaffDirectoryController] over the real `ApiClient`
/// (spec §7–§8): first page, load more, no duplicates, debounced search,
/// stale-response dropping, refresh, and errors.
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late StaffDirectoryController directory;
  late List<Uri> requests;

  setUp(() async {
    backend = FakeBackend();
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));
    await auth.bootstrap();
    requests = [];
    directory = StaffDirectoryController(
      peopleClientFor(backend, auth),
      debounce: const Duration(milliseconds: 20),
    );
  });

  tearDown(() => directory.dispose());

  /// Answers every request through [respond], recording its URL.
  void answer(Future<http.Response> Function(Uri url) respond) {
    backend.onApi = (request) {
      requests.add(request.url);

      return respond(request.url);
    };
  }

  /// A 3-page directory of 2 people per page.
  Future<http.Response> threePages(Uri url) async {
    final page = int.parse(url.queryParameters['page']!);

    return jsonResponse(
      directoryPageJson(
        staffList(2, prefix: 'Page$page'),
        page: page,
        lastPage: 3,
      ),
    );
  }

  /// Waits for the debounce plus the request to settle.
  Future<void> settle() =>
      Future<void>.delayed(const Duration(milliseconds: 60));

  test('starts loading; the first load shows page 1 of active staff', () async {
    answer(threePages);
    expect(directory.status, DirectoryStatus.loading);

    await directory.load();

    expect(directory.status, DirectoryStatus.loaded);
    expect(directory.items, hasLength(2));
    expect(directory.hasMore, isTrue);
    expect(requests.single.queryParameters['status'], 'active');
    expect(requests.single.queryParameters['page'], '1');
  });

  test('load more appends the following pages until the last', () async {
    answer(threePages);
    await directory.load();

    await directory.loadMore();
    await directory.loadMore();

    expect(directory.items.map((m) => m.firstName), [
      'Page1', 'Page1', 'Page2', 'Page2', 'Page3', 'Page3', //
    ]);
    expect(directory.hasMore, isFalse);
    expect(requests.map((u) => u.queryParameters['page']), ['1', '2', '3']);

    await directory.loadMore();
    expect(requests, hasLength(3), reason: 'no request past the last page');
  });

  test('overlapping load-more calls make one request', () async {
    answer(threePages);
    await directory.load();
    final gate = Completer<http.Response>();
    answer((url) => gate.future);

    final a = directory.loadMore();
    final b = directory.loadMore();
    expect(directory.isLoadingMore, isTrue);
    gate.complete(await threePages(Uri.parse('x?page=2')));
    await Future.wait([a, b]);

    expect(
      requests.where((u) => u.queryParameters['page'] == '2'),
      hasLength(1),
    );
    expect(directory.items, hasLength(4));
  });

  test('the same person is never listed twice', () async {
    final repeated = staffList(1, prefix: 'Same');
    answer(
      (url) async => jsonResponse(
        directoryPageJson(
          repeated,
          page: int.parse(url.queryParameters['page']!),
          lastPage: 2,
        ),
      ),
    );

    await directory.load();
    await directory.loadMore();

    expect(directory.items, hasLength(1));
    expect(directory.hasMore, isFalse);
  });

  test('a failed load more keeps the list and can be retried', () async {
    answer(threePages);
    await directory.load();
    answer((_) async => throw const NetworkFailure());

    await directory.loadMore();

    expect(directory.loadMoreFailed, isTrue);
    expect(directory.isLoadingMore, isFalse);
    expect(directory.status, DirectoryStatus.loaded);
    expect(directory.items, hasLength(2));

    answer(threePages);
    await directory.loadMore();

    expect(directory.loadMoreFailed, isFalse);
    expect(directory.items, hasLength(4));
  });

  test('search is debounced: only the last text is sent', () async {
    answer(threePages);
    await directory.load();
    requests.clear();

    directory.setQuery('A');
    directory.setQuery('Ad');
    directory.setQuery('  Ada ');
    await settle();

    expect(requests, hasLength(1));
    expect(requests.single.queryParameters['q'], 'Ada');
    expect(requests.single.queryParameters['page'], '1');
    expect(directory.query, 'Ada');
  });

  test('setting the query already shown sends nothing', () async {
    answer(threePages);
    await directory.load();
    requests.clear();

    directory.setQuery('   ');
    await settle();

    expect(requests, isEmpty);
  });

  test(
    'a search replaces the list; load more then pages that search',
    () async {
      answer(threePages);
      await directory.load();

      directory.setQuery('Grace');
      await settle();
      await directory.loadMore();

      expect(requests.last.queryParameters['q'], 'Grace');
      expect(requests.last.queryParameters['page'], '2');
      expect(directory.items.map((m) => m.firstName), [
        'Page1', 'Page1', 'Page2', 'Page2', //
      ]);
    },
  );

  test('a slow response for an earlier search is dropped', () async {
    answer(threePages);
    await directory.load();

    final slow = Completer<http.Response>();
    answer((url) {
      if (url.queryParameters['q'] == 'old') {
        return slow.future;
      }

      return Future.value(
        jsonResponse(directoryPageJson(staffList(1, prefix: 'New'))),
      );
    });

    directory.setQuery('old');
    await settle(); // "old" is now in flight
    directory.setQuery('new');
    await settle(); // "new" has answered
    slow.complete(jsonResponse(directoryPageJson(staffList(3, prefix: 'Old'))));
    await settle();

    expect(directory.query, 'new');
    expect(directory.items.map((m) => m.firstName), ['New']);
    expect(directory.status, DirectoryStatus.loaded);
  });

  test('a load more overtaken by a refresh is dropped', () async {
    answer(threePages);
    await directory.load();

    final slowPage2 = Completer<http.Response>();
    answer((url) {
      if (url.queryParameters['page'] == '2') {
        return slowPage2.future;
      }

      return Future.value(
        jsonResponse(
          directoryPageJson(staffList(2, prefix: 'Fresh'), lastPage: 3),
        ),
      );
    });

    final more = directory.loadMore();
    await directory.refresh();
    slowPage2.complete(await threePages(Uri.parse('x?page=2')));
    await more;

    expect(directory.items.map((m) => m.firstName), ['Fresh', 'Fresh']);
    expect(directory.isLoadingMore, isFalse);
  });

  test('refresh keeps the list on screen and replaces it on success', () async {
    answer(threePages);
    await directory.load();
    await directory.loadMore();
    expect(directory.items, hasLength(4));

    final gate = Completer<http.Response>();
    answer((_) => gate.future);
    final refreshing = directory.refresh();
    expect(directory.isRefreshing, isTrue);
    expect(directory.items, hasLength(4), reason: 'kept while refreshing');

    gate.complete(jsonResponse(directoryPageJson(staffList(1, prefix: 'R'))));
    expect(await refreshing, isTrue);
    expect(directory.items.map((m) => m.firstName), ['R']);
    expect(directory.hasMore, isFalse);
    expect(directory.isRefreshing, isFalse);
  });

  test('a failed refresh keeps the earlier list and reports false', () async {
    answer(threePages);
    await directory.load();
    answer((_) async => throw const NetworkFailure());

    expect(await directory.refresh(), isFalse);
    expect(directory.status, DirectoryStatus.loaded);
    expect(directory.items, hasLength(2));
  });

  test('an empty result is loaded, not an error', () async {
    answer((_) async => jsonResponse(directoryPageJson([])));

    await directory.load();

    expect(directory.status, DirectoryStatus.loaded);
    expect(directory.items, isEmpty);
    expect(directory.hasMore, isFalse);
  });

  for (final (label, respond, message)
      in <(String, Future<http.Response> Function(Uri), String)>[
        (
          'a network failure',
          (_) async => throw const NetworkFailure(),
          "Couldn't load the staff directory. Check your connection.",
        ),
        (
          'a 403 with a valid session (no staff.view)',
          (_) async => jsonError(403, 'This action is unauthorized.'),
          "You don't have access to the staff directory.",
        ),
        (
          'a server failure',
          (_) async => jsonError(500),
          'Something went wrong loading the staff directory.',
        ),
      ]) {
    test('$label on the first page is the error state', () async {
      answer(respond);

      await directory.load();

      expect(directory.status, DirectoryStatus.error);
      expect(directory.errorMessage, message);
      expect(auth.status, AuthStatus.authenticated);
    });
  }

  test('"Try again" after an error recovers', () async {
    answer((_) async => throw const NetworkFailure());
    await directory.load();

    answer(threePages);
    await directory.load();

    expect(directory.status, DirectoryStatus.loaded);
    expect(directory.errorMessage, isNull);
  });

  test('401 ends the session without an error state', () async {
    answer((_) async => jsonError(401));

    await directory.load();

    expect(auth.status, AuthStatus.unauthenticated);
    expect(directory.status, isNot(DirectoryStatus.error));
  });

  test('a pending search does not fire after dispose', () async {
    answer(threePages);
    final local = StaffDirectoryController(
      peopleClientFor(backend, auth),
      debounce: const Duration(milliseconds: 20),
    );

    local.setQuery('Ada');
    local.dispose();
    await settle();

    expect(requests, isEmpty);
  });
}
