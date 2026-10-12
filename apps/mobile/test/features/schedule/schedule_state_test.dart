import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/core/network/api_exception.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/people/state/resource_controller.dart';
import 'package:mobile/features/schedule/state/my_schedule_controller.dart';
import 'package:mobile/features/schedule/state/schedule_entry_controller.dart';

import '../../support/fake_backend.dart';
import '../../support/people_fixtures.dart' show jsonResponse;
import '../../support/schedule_fixtures.dart';

/// Phase 30A Gate 2 — [ScheduleApiClient], [MyScheduleController] and
/// [ScheduleEntryController] over the real `ApiClient` (spec §5.2, R-8,
/// R-10): exact requests, the company week learnt from the server (never
/// the device clock), navigation, stale responses, refresh, no profile and
/// errors.
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late List<Uri> requests;

  setUp(() async {
    backend = FakeBackend();
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));
    await auth.bootstrap();
    requests = [];
  });

  void answer(Future<http.Response> Function(Uri url) respond) {
    backend.onApi = (request) {
      requests.add(request.url);

      return respond(request.url);
    };
  }

  /// A server whose company today is [today]; it echoes the asked range and
  /// returns one task on each of [taskDates].
  Future<http.Response> Function(Uri) server({
    String today = '2026-10-14',
    List<String> taskDates = const ['2026-10-14', '2026-10-20', '2026-10-21'],
    bool noProfile = false,
  }) => (url) async {
    final from = url.queryParameters['from']!;
    final to = url.queryParameters['to']!;

    return jsonResponse(
      myScheduleJson(
        noProfile
            ? null
            : [
                for (final d in taskDates)
                  if (d.compareTo(from) >= 0 && d.compareTo(to) <= 0)
                    manilaTaskJson(d, title: 'Task $d', id: 'T$d'),
              ],
        from: from,
        to: to,
        companyDay: {...manilaDay, 'date': today},
      ),
    );
  };

  MyScheduleController controller(DateTime device) => MyScheduleController(
    scheduleClientFor(backend, auth),
    clock: () => device,
  );

  List<Map<String, String>> asked() => [
    for (final u in requests.where((u) => u.path.endsWith('/me/schedule')))
      u.queryParameters,
  ];

  group('ScheduleApiClient', () {
    test('GETs /me/schedule with exactly from and to', () async {
      answer(server());

      final s = await scheduleClientFor(
        backend,
        auth,
      ).fetchMySchedule(from: '2026-10-14', to: '2026-10-20');

      expect(requests.single.path, '/api/v1/me/schedule');
      expect(requests.single.queryParameters, {
        'from': '2026-10-14',
        'to': '2026-10-20',
      });
      expect(s.items, hasLength(2));
    });

    test(
      'GETs an entry by its encoded id; a bad shape is an ApiRequestException',
      () async {
        answer((url) async => jsonResponse({'data': scheduleEntryJson()}));
        final e = await scheduleClientFor(backend, auth).fetchEntry('A/B');
        expect(requests.single.path, '/api/v1/schedule-entries/A%2FB');
        expect(e.title, 'Site visit');

        answer((url) async => jsonResponse({'data': 'nope'}));
        expect(
          scheduleClientFor(backend, auth).fetchEntry('X'),
          throwsA(isA<ApiRequestException>()),
        );
      },
    );
  });

  group('MyScheduleController', () {
    test('first load: one padded request around the device date, then the '
        'week from the server\'s company today', () async {
      answer(server());
      // Device: 13 Oct, 22:00 local — already 14 Oct in Manila.
      final c = controller(DateTime(2026, 10, 13, 22));
      addTearDown(c.dispose);

      await c.load();

      expect(asked(), [
        {'from': '2026-10-12', 'to': '2026-10-20'},
      ]);
      expect(c.status, ScheduleStatus.loaded);
      expect(c.weekStart, '2026-10-14');
      expect(c.isCurrentWeek, isTrue);
      expect(c.days.map((d) => d.date), [
        '2026-10-14',
        '2026-10-15',
        '2026-10-16',
        '2026-10-17',
        '2026-10-18',
        '2026-10-19',
        '2026-10-20',
      ]);
      expect(c.days.first.items.single.title, 'Task 2026-10-14');
      expect(c.days.last.items.single.title, 'Task 2026-10-20');
      expect(c.days[1].items, isEmpty);
    });

    test('a device clock days off still shows the company week, with one '
        'exact follow-up request', () async {
      answer(server());
      final c = controller(DateTime(2026, 10, 1));
      addTearDown(c.dispose);

      await c.load();

      expect(asked(), [
        {'from': '2026-09-30', 'to': '2026-10-08'},
        {'from': '2026-10-14', 'to': '2026-10-20'},
      ]);
      expect(c.weekStart, '2026-10-14');
      expect(c.days.first.items.single.title, 'Task 2026-10-14');
    });

    test('next, previous and today move by exact weeks', () async {
      answer(server());
      final c = controller(DateTime(2026, 10, 14, 9));
      addTearDown(c.dispose);
      await c.load();

      await c.nextWeek();
      expect(c.weekStart, '2026-10-21');
      expect(c.isCurrentWeek, isFalse);
      expect(c.days.first.items.single.title, 'Task 2026-10-21');
      await c.previousWeek();
      await c.previousWeek();
      expect(c.weekStart, '2026-10-07');
      await c.today();
      expect(c.weekStart, '2026-10-14');

      expect(asked().skip(1).toList(), [
        {'from': '2026-10-21', 'to': '2026-10-27'},
        {'from': '2026-10-14', 'to': '2026-10-20'},
        {'from': '2026-10-07', 'to': '2026-10-13'},
        {'from': '2026-10-14', 'to': '2026-10-20'},
      ]);
    });

    test('a slow response overtaken by a newer request is dropped', () async {
      final slow = Completer<http.Response>();
      final normal = server();
      var asks = 0;
      answer((url) {
        // Only the first request for 21 Oct is slow.
        if (url.queryParameters['from'] == '2026-10-21' && asks++ == 0) {
          return slow.future;
        }

        return normal(url);
      });
      final c = controller(DateTime(2026, 10, 14, 9));
      addTearDown(c.dispose);
      await c.load();

      final first = c.nextWeek(); // slow
      await c.today(); // overtakes it: back to 14 Oct
      expect(c.weekStart, '2026-10-14');

      // The slow week now arrives, with different content: ignored.
      slow.complete(
        jsonResponse(
          myScheduleJson(
            [manilaTaskJson('2026-10-21', title: 'Stale', id: 'TS')],
            from: '2026-10-21',
            to: '2026-10-27',
          ),
        ),
      );
      await first;

      expect(c.weekStart, '2026-10-14');
      expect(
        c.days.expand((d) => d.items).map((i) => i.title),
        isNot(contains('Stale')),
      );
      expect(c.status, ScheduleStatus.loaded);
    });

    test('a failed refresh keeps the week; a failed load is an error with a '
        'message; Try again recovers', () async {
      answer(server());
      final c = controller(DateTime(2026, 10, 14, 9));
      addTearDown(c.dispose);
      await c.load();

      answer((url) async => throw const NetworkFailure());
      expect(await c.refresh(), isFalse);
      expect(c.status, ScheduleStatus.loaded);
      expect(c.days, hasLength(7));

      await c.nextWeek();
      expect(c.status, ScheduleStatus.error);
      expect(
        c.errorMessage,
        "Couldn't load your schedule. Check your connection.",
      );

      answer(server());
      await c.load();
      expect(c.status, ScheduleStatus.loaded);
    });

    test(
      'Try again reloads the week that failed, not the one shown before',
      () async {
        answer(server());
        final c = controller(DateTime(2026, 10, 14, 9));
        addTearDown(c.dispose);
        await c.load();

        answer((url) async => throw const NetworkFailure());
        await c.nextWeek();
        expect(c.status, ScheduleStatus.error);

        answer(server());
        requests.clear();
        await c.retry();
        expect(asked(), [
          {'from': '2026-10-21', 'to': '2026-10-27'},
        ]);
        expect(c.weekStart, '2026-10-21');
        expect(c.status, ScheduleStatus.loaded);

        // A failed first load retries the company today's week.
        final first = controller(DateTime(2026, 10, 14, 9));
        addTearDown(first.dispose);
        answer((url) async => throw const NetworkFailure());
        await first.load();
        answer(server());
        requests.clear();
        await first.retry();
        expect(first.weekStart, '2026-10-14');
        expect(first.status, ScheduleStatus.loaded);
      },
    );

    test('no linked profile is the noProfile state', () async {
      answer(server(noProfile: true));
      final c = controller(DateTime(2026, 10, 14, 9));
      addTearDown(c.dispose);

      await c.load();

      expect(c.status, ScheduleStatus.noProfile);
      expect(c.days, isEmpty);
    });

    test('a 403 is "no access" and keeps the session', () async {
      answer((url) async => jsonError(403));
      final c = controller(DateTime(2026, 10, 14, 9));
      addTearDown(c.dispose);

      await c.load();

      expect(c.errorMessage, "You don't have access to the schedule.");
      expect(auth.status, AuthStatus.authenticated);
    });
  });

  group('ScheduleEntryController', () {
    test(
      'loads the entry; a 404 is "This item is no longer available."',
      () async {
        answer((url) async => jsonResponse({'data': scheduleEntryJson()}));
        final ok = ScheduleEntryController(
          scheduleClientFor(backend, auth),
          'E1',
        );
        addTearDown(ok.dispose);
        await ok.load();
        expect(ok.status, ResourceStatus.loaded);
        expect(ok.data!.participants.single.displayName, 'Grace Hopper');

        answer((url) async => jsonError(404, 'Not found.'));
        final gone = ScheduleEntryController(
          scheduleClientFor(backend, auth),
          'E1',
        );
        addTearDown(gone.dispose);
        await gone.load();
        expect(gone.errorMessage, 'This item is no longer available.');
      },
    );
  });
}
