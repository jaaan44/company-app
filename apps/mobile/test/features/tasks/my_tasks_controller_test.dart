import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/tasks/data/tasks_api_client.dart';
import 'package:mobile/features/tasks/domain/task_item.dart';
import 'package:mobile/features/tasks/state/my_tasks_controller.dart';
import 'package:mobile/features/tasks/state/task_changes.dart';

import '../../support/fake_backend.dart';
import '../../support/task_fixtures.dart';

/// Phase 29A Gate 2 — [MyTasksController] over the real `ApiClient` (spec
/// §5.3): segments, paging, no duplicates, stale responses, the no-profile
/// state, errors, and applying confirmed task changes.
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late TasksApiClient client;
  late TaskChanges changes;
  late List<Uri> requests;
  late MyTasksController open;

  setUp(() async {
    backend = FakeBackend();
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));
    await auth.bootstrap();
    requests = [];
    client = tasksClientFor(backend, auth);
    changes = TaskChanges();
    open = MyTasksController(
      client,
      state: TaskListState.open,
      changes: changes,
    );
  });

  tearDown(() => open.dispose());

  void answer(Future<http.Response> Function(Uri url) respond) {
    backend.onApi = (request) {
      requests.add(request.url);

      return respond(request.url);
    };
  }

  /// Three pages of two tasks each, ids `P<page>-<n>`.
  Future<http.Response> threePages(Uri url) async {
    final page = int.parse(url.queryParameters['page']!);

    return jsonResponse(
      myTasksBody(
        [myTaskJson('P$page-1'), myTaskJson('P$page-2')],
        page: page,
        lastPage: 3,
        total: 6,
      ),
    );
  }

  List<String> ids(MyTasksController c) =>
      c.items.map((t) => t.publicId).toList();

  test('starts loading; the first load shows page 1 of its segment', () async {
    answer(threePages);
    expect(open.status, MyTasksStatus.loading);

    await open.load();

    expect(open.status, MyTasksStatus.loaded);
    expect(ids(open), ['P1-1', 'P1-2']);
    expect(open.hasMore, isTrue);
    expect(open.companyDay!.date, '2026-09-24');
    expect(requests.single.queryParameters['state'], 'open');
  });

  test('the Done segment requests state=closed', () async {
    answer((_) async => jsonResponse(myTasksBody([])));
    final done = MyTasksController(client, state: TaskListState.closed);
    addTearDown(done.dispose);

    await done.load();

    expect(requests.single.queryParameters['state'], 'closed');
    expect(done.status, MyTasksStatus.loaded);
    expect(done.items, isEmpty);
  });

  test('load more appends pages until the last, then stops', () async {
    answer(threePages);
    await open.load();

    await open.loadMore();
    await open.loadMore();
    await open.loadMore();

    expect(ids(open), ['P1-1', 'P1-2', 'P2-1', 'P2-2', 'P3-1', 'P3-2']);
    expect(open.hasMore, isFalse);
    expect(requests, hasLength(3));
  });

  test('a task repeated across pages is shown once', () async {
    answer((url) async {
      final page = int.parse(url.queryParameters['page']!);

      return jsonResponse(
        myTasksBody(
          page == 1
              ? [myTaskJson('A'), myTaskJson('B')]
              : [myTaskJson('B'), myTaskJson('C')],
          page: page,
          lastPage: 2,
        ),
      );
    });
    await open.load();
    await open.loadMore();

    expect(ids(open), ['A', 'B', 'C']);
  });

  test('a failed load more keeps the list and can be retried', () async {
    answer(threePages);
    await open.load();
    answer((_) async => throw const NetworkFailure());

    await open.loadMore();

    expect(open.loadMoreFailed, isTrue);
    expect(ids(open), ['P1-1', 'P1-2']);

    answer(threePages);
    await open.loadMore();

    expect(open.loadMoreFailed, isFalse);
    expect(ids(open), hasLength(4));
  });

  test('a load more overtaken by a refresh is dropped', () async {
    answer(threePages);
    await open.load();
    final slow = Completer<http.Response>();
    answer(
      (url) =>
          url.queryParameters['page'] == '2' ? slow.future : threePages(url),
    );

    final more = open.loadMore();
    await pumpEventQueue();
    await open.refresh();
    slow.complete(await threePages(Uri.parse('x?page=2')));
    await more;

    expect(ids(open), ['P1-1', 'P1-2']);
    expect(open.isLoadingMore, isFalse);
  });

  test('concurrent loads share one request', () async {
    answer(threePages);

    await Future.wait([open.load(), open.load(), open.refresh()]);

    expect(requests, hasLength(1));
  });

  test('no profile is its own state, not an empty list', () async {
    answer((_) async => jsonResponse(myTasksBody(null)));

    await open.load();

    expect(open.status, MyTasksStatus.noProfile);
    expect(open.items, isEmpty);
    expect(open.hasMore, isFalse);
    expect(open.companyDay!.timezone, 'Asia/Manila');
  });

  test('a first-load failure is the error state with a message', () async {
    answer((_) async => throw const NetworkFailure());

    await open.load();

    expect(open.status, MyTasksStatus.error);
    expect(
      open.errorMessage,
      "Couldn't load your tasks. Check your connection.",
    );

    answer(threePages);
    await open.load();
    expect(open.status, MyTasksStatus.loaded);
    expect(open.errorMessage, isNull);
  });

  test('a failed refresh keeps the earlier list and reports false', () async {
    answer(threePages);
    await open.load();
    answer((_) async => jsonError(500));

    expect(await open.refresh(), isFalse);

    expect(open.status, MyTasksStatus.loaded);
    expect(ids(open), ['P1-1', 'P1-2']);
    expect(open.isRefreshing, isFalse);
  });

  test('a session expiry leaves the state alone', () async {
    answer((_) async => jsonError(401));

    await open.load();

    expect(open.status, MyTasksStatus.loading);
    expect(auth.status, AuthStatus.unauthenticated);
  });

  group('task changes', () {
    setUp(() async {
      answer(
        (_) async => jsonResponse(
          myTasksBody([
            myTaskJson('A', dueDate: '2026-09-01', isOverdue: true),
            myTaskJson('B'),
          ]),
        ),
      );
      await open.load();
      expect(open.isStale, isFalse);
    });

    TaskItem server(String id, String status, {String? due = '2026-09-01'}) =>
        TaskItem.fromJson(taskJson(publicId: id, status: status, dueDate: due));

    test('a task that stays open is replaced in place, keeping its flags', () {
      changes.record(TaskChange.updated(server('A', 'in_progress')));

      expect(ids(open), ['A', 'B']);
      expect(open.items.first.status, TaskStatus.inProgress);
      expect(open.items.first.isOverdue, isTrue);
      expect(open.isStale, isTrue);
    });

    test('a task completed leaves the Open segment at once', () {
      changes.record(TaskChange.updated(server('A', 'completed')));

      expect(ids(open), ['B']);
      expect(open.isStale, isTrue);
    });

    test('a task no longer mine is removed', () {
      changes.record(const TaskChange.removed('B'));

      expect(ids(open), ['A']);
    });

    test('a change to a task not in the list only marks it stale', () {
      changes.record(TaskChange.updated(server('Z', 'todo')));

      expect(ids(open), ['A', 'B']);
      expect(open.isStale, isTrue);
    });

    test('the Done segment drops a reopened task', () async {
      answer(
        (_) async =>
            jsonResponse(myTasksBody([myTaskJson('D', status: 'completed')])),
      );
      final done = MyTasksController(
        client,
        state: TaskListState.closed,
        changes: changes,
      );
      addTearDown(done.dispose);
      await done.load();

      changes.record(TaskChange.updated(server('D', 'todo')));

      expect(done.items, isEmpty);
    });

    test('refreshIfStale refreshes once, then the list is current', () async {
      answer(
        (_) async =>
            jsonResponse(myTasksBody([myTaskJson('B'), myTaskJson('C')])),
      );
      requests.clear();

      await open.refreshIfStale();
      expect(requests, isEmpty, reason: 'not stale yet');

      changes.record(TaskChange.updated(server('A', 'completed')));
      await open.refreshIfStale();

      expect(requests, hasLength(1));
      expect(ids(open), ['B', 'C']);
      expect(open.isStale, isFalse);

      await open.refreshIfStale();
      expect(requests, hasLength(1));
    });

    test('a change during a refresh keeps the list stale', () async {
      final gate = Completer<http.Response>();
      answer((_) => gate.future);

      final refreshing = open.refresh();
      await pumpEventQueue();
      changes.record(TaskChange.updated(server('B', 'blocked')));
      gate.complete(jsonResponse(myTasksBody([myTaskJson('B')])));
      await refreshing;

      expect(open.isStale, isTrue);
    });

    test('a disposed controller stops listening', () {
      final counting = _CountingTaskChanges();
      final c = MyTasksController(
        client,
        state: TaskListState.open,
        changes: counting,
      );
      expect(counting.listeners, 1);

      c.dispose();

      expect(counting.listeners, 0);
    });
  });
}

class _CountingTaskChanges extends TaskChanges {
  int listeners = 0;

  @override
  void addListener(VoidCallback listener) {
    listeners++;
    super.addListener(listener);
  }

  @override
  void removeListener(VoidCallback listener) {
    listeners--;
    super.removeListener(listener);
  }
}
