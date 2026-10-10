import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/work_logs/domain/work_log.dart';
import 'package:mobile/features/work_logs/state/my_work_logs_controller.dart';
import 'package:mobile/features/work_logs/state/work_log_changes.dart';

import '../../support/fake_backend.dart';
import '../../support/work_log_fixtures.dart';

/// Phase 29B Gate 2 — [MyWorkLogsController] over the real `ApiClient`:
/// paging, grouping by date across pages, the no-profile state (R-13),
/// errors, stale responses, and applying confirmed changes.
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late WorkLogChanges changes;
  late MyWorkLogsController list;
  late List<Uri> requests;

  setUp(() async {
    backend = FakeBackend();
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));
    await auth.bootstrap();
    changes = WorkLogChanges();
    requests = [];
    list = MyWorkLogsController(
      workLogsClientFor(backend, auth),
      changes: changes,
    );
  });

  tearDown(() => list.dispose());

  void answer(Future<http.Response> Function(Uri url) respond) {
    backend.onApi = (request) {
      requests.add(request.url);

      return respond(request.url);
    };
  }

  /// Page 1: two logs on 10 Oct, one on 9 Oct. Page 2: one more on 9 Oct
  /// (the date spans the page boundary) and one on 7 Oct.
  Future<http.Response> twoPages(Uri url) async {
    final page = int.parse(url.queryParameters['page']!);

    return jsonResponse(
      page == 1
          ? workLogsBody([
              workLogJson('A', workDate: '2026-10-10'),
              workLogJson('B', workDate: '2026-10-10'),
              workLogJson('C', workDate: '2026-10-09'),
            ], lastPage: 2)
          : workLogsBody(
              [
                workLogJson('D', workDate: '2026-10-09'),
                workLogJson('E', workDate: '2026-10-07'),
              ],
              page: 2,
              lastPage: 2,
            ),
    );
  }

  List<String> ids() => list.items.map((l) => l.publicId).toList();

  test('loads page 1 with the company day', () async {
    answer(twoPages);
    expect(list.status, MyWorkLogsStatus.loading);

    await list.load();

    expect(list.status, MyWorkLogsStatus.loaded);
    expect(ids(), ['A', 'B', 'C']);
    expect(list.companyDay!.date, '2026-10-10');
    expect(list.hasMore, isTrue);
    expect(requests.single.queryParameters['per_page'], '25');
  });

  test('groups by date, merging a date that spans two pages', () async {
    answer(twoPages);
    await list.load();

    expect(list.days.map((d) => (d.date, d.logs.length)), [
      ('2026-10-10', 2),
      ('2026-10-09', 1),
    ]);

    await list.loadMore();

    // Records compare a list inside them by identity, so compare text.
    expect(
      list.days.map(
        (d) => '${d.date}: ${d.logs.map((l) => l.publicId).join(',')}',
      ),
      ['2026-10-10: A,B', '2026-10-09: C,D', '2026-10-07: E'],
    );
    expect(list.hasMore, isFalse);
  });

  test('a repeated log is shown once', () async {
    answer((url) async {
      final page = int.parse(url.queryParameters['page']!);

      return jsonResponse(
        workLogsBody(
          page == 1
              ? [workLogJson('A'), workLogJson('B')]
              : [workLogJson('B'), workLogJson('C')],
          page: page,
          lastPage: 2,
        ),
      );
    });
    await list.load();
    await list.loadMore();

    expect(ids(), ['A', 'B', 'C']);
  });

  test('a 403 is the no-profile state (R-13), not an error', () async {
    answer(
      (_) async => jsonError(403, 'No staff record is linked to this account.'),
    );

    await list.load();

    expect(list.status, MyWorkLogsStatus.noProfile);
    expect(list.items, isEmpty);
    expect(list.errorMessage, isNull);
    expect(auth.status, AuthStatus.authenticated);
  });

  test(
    'a first-load failure is the error state, and Try again recovers',
    () async {
      answer((_) async => throw const NetworkFailure());

      await list.load();

      expect(list.status, MyWorkLogsStatus.error);
      expect(
        list.errorMessage,
        "Couldn't load your work logs. Check your connection.",
      );

      answer(twoPages);
      await list.load();
      expect(list.status, MyWorkLogsStatus.loaded);
    },
  );

  test(
    'a failed refresh keeps the list; a failed load more can be retried',
    () async {
      answer(twoPages);
      await list.load();
      answer((_) async => jsonError(500));

      expect(await list.refresh(), isFalse);
      expect(ids(), ['A', 'B', 'C']);

      await list.loadMore();
      expect(list.loadMoreFailed, isTrue);

      answer(twoPages);
      await list.loadMore();
      expect(ids(), ['A', 'B', 'C', 'D', 'E']);
    },
  );

  test('a load more overtaken by a refresh is dropped', () async {
    answer(twoPages);
    await list.load();
    final slow = Completer<http.Response>();
    answer(
      (url) => url.queryParameters['page'] == '2' ? slow.future : twoPages(url),
    );

    final more = list.loadMore();
    await pumpEventQueue();
    await list.refresh();
    slow.complete(await twoPages(Uri.parse('x?page=2')));
    await more;

    expect(ids(), ['A', 'B', 'C']);
  });

  test('concurrent loads share one request', () async {
    answer(twoPages);

    await Future.wait([list.load(), list.load(), list.refresh()]);

    expect(requests, hasLength(1));
  });

  group('changes', () {
    setUp(() async {
      answer(twoPages);
      await list.load();
      requests.clear();
    });

    WorkLog log(String id, {String date = '2026-10-10', int minutes = 90}) =>
        WorkLog.fromJson(workLogJson(id, workDate: date, minutes: minutes));

    test('a deletion leaves the list at once', () {
      changes.record(const WorkLogChange.deleted('B'));

      expect(ids(), ['A', 'C']);
      expect(list.isStale, isTrue);
    });

    test('an edit on the same date replaces its row', () {
      changes.record(WorkLogChange.saved(log('A', minutes: 15)));

      expect(ids(), ['A', 'B', 'C']);
      expect(list.items.first.durationMinutes, 15);
    });

    test('an edit that changes the date waits for the refresh', () {
      changes.record(WorkLogChange.saved(log('A', date: '2026-10-01')));

      expect(list.items.first.workDate, '2026-10-10');
      expect(list.isStale, isTrue);
    });

    test(
      'a new log marks the list stale; refreshIfStale refreshes once',
      () async {
        changes.record(WorkLogChange.saved(log('NEW')));
        expect(ids(), ['A', 'B', 'C']);

        answer(
          (_) async => jsonResponse(
            workLogsBody([workLogJson('NEW'), workLogJson('A')]),
          ),
        );
        await list.refreshIfStale();
        await list.refreshIfStale();

        expect(requests, hasLength(1));
        expect(ids(), ['NEW', 'A']);
        expect(list.isStale, isFalse);
      },
    );

    test('dispose stops listening', () {
      final c = WorkLogChanges();
      final other = MyWorkLogsController(
        workLogsClientFor(backend, auth),
        changes: c,
      );
      other.dispose();

      expect(() => c.record(const WorkLogChange.deleted('A')), returnsNormally);
      // ignore: invalid_use_of_protected_member, invalid_use_of_visible_for_testing_member
      expect(c.hasListeners, isFalse);
    });
  });
}
